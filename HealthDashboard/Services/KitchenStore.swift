import EventKit
import Observation
import UIKit

/// 가족 식탁: 저녁 메뉴 계획(가족 캘린더)과 장보기 목록(미리알림 공유 목록).
/// 둘 다 애플 기본 앱(iCloud)으로 배우자와 공유되므로 무료 개발자 계정으로도 동작한다.
@MainActor
@Observable
final class KitchenStore {
    static let shared = KitchenStore()

    private(set) var remindersAuthorization = EKEventStore.authorizationStatus(for: .reminder)
    private(set) var shoppingList: EKCalendar?
    private(set) var reminderLists: [EKCalendar] = []
    private(set) var shoppingItems: [EKReminder] = []
    /// 오늘부터 14일간의 저녁 계획
    private(set) var plannedMeals: [PlannedMeal] = []

    var profile = FamilyProfile.load() {
        didSet { profile.save() }
    }

    private enum Keys {
        static let listID = "shoppingListID"
    }

    private var eventStore: EKEventStore { CalendarStore.shared.eventStore }
    @ObservationIgnored private var observer: NSObjectProtocol?

    init() {
        observer = NotificationCenter.default.addObserver(
            forName: .EKEventStoreChanged, object: CalendarStore.shared.eventStore, queue: .main
        ) { [weak self] _ in
            Task { @MainActor in await self?.reload() }
        }
    }

    var hasRemindersAccess: Bool { remindersAuthorization == .fullAccess }

    func requestRemindersAccess() async {
        _ = try? await eventStore.requestFullAccessToReminders()
        remindersAuthorization = EKEventStore.authorizationStatus(for: .reminder)
        await reload()
    }

    func reload() async {
        remindersAuthorization = EKEventStore.authorizationStatus(for: .reminder)
        loadPlans()
        guard hasRemindersAccess else { return }

        reminderLists = eventStore.calendars(for: .reminder)
            .filter(\.allowsContentModifications)
            .sorted { $0.title < $1.title }
        shoppingList = findShoppingList()
        guard let list = shoppingList else {
            shoppingItems = []
            return
        }
        let predicate = eventStore.predicateForReminders(in: [list])
        let reminders: [EKReminder] = await withCheckedContinuation { continuation in
            eventStore.fetchReminders(matching: predicate) { continuation.resume(returning: $0 ?? []) }
        }
        // 안 산 것 먼저, 그다음 최근에 추가한 순
        shoppingItems = reminders.sorted { lhs, rhs in
            if lhs.isCompleted != rhs.isCompleted { return !lhs.isCompleted }
            return (lhs.creationDate ?? .distantPast) > (rhs.creationDate ?? .distantPast)
        }
    }

    // MARK: - 장보기 목록

    private func findShoppingList() -> EKCalendar? {
        if let id = UserDefaults.standard.string(forKey: Keys.listID),
           let saved = eventStore.calendar(withIdentifier: id) {
            return saved
        }
        return reminderLists.first { ["장보기", "장보기 목록", "shopping", "grocery", "groceries"].contains($0.title.lowercased()) }
    }

    func selectShoppingList(_ list: EKCalendar) {
        UserDefaults.standard.set(list.calendarIdentifier, forKey: Keys.listID)
        Task { await reload() }
    }

    /// iCloud에 '장보기' 목록을 만든다. 배우자와는 미리알림 앱에서 목록 공유로 연결한다.
    func createShoppingList() throws {
        let list = EKCalendar(for: .reminder, eventStore: eventStore)
        list.title = "장보기"
        list.cgColor = UIColor.systemGreen.cgColor
        list.source = eventStore.sources.first { $0.sourceType == .calDAV && $0.title.lowercased().contains("icloud") }
            ?? eventStore.defaultCalendarForNewReminders()?.source
        try eventStore.saveCalendar(list, commit: true)
        selectShoppingList(list)
    }

    /// 이미 목록에 있는(안 산) 재료는 다시 넣지 않는다. 추가한 개수를 돌려준다.
    @discardableResult
    func addShoppingItems(_ names: [String], note: String? = nil) async -> Int {
        guard let list = shoppingList else { return 0 }
        let existing = Set(shoppingItems.filter { !$0.isCompleted }.compactMap { $0.title?.trimmingCharacters(in: .whitespaces) })
        var added = 0
        for name in names.map({ $0.trimmingCharacters(in: .whitespaces) }) where !name.isEmpty && !existing.contains(name) {
            let reminder = EKReminder(eventStore: eventStore)
            reminder.title = name
            reminder.notes = note
            reminder.calendar = list
            if (try? eventStore.save(reminder, commit: false)) != nil { added += 1 }
        }
        try? eventStore.commit()
        await reload()
        return added
    }

    func toggle(_ item: EKReminder) async {
        item.isCompleted.toggle()
        try? eventStore.save(item, commit: true)
        await reload()
    }

    func delete(_ item: EKReminder) async {
        try? eventStore.remove(item, commit: true)
        await reload()
    }

    func clearCompleted() async {
        for item in shoppingItems where item.isCompleted {
            try? eventStore.remove(item, commit: false)
        }
        try? eventStore.commit()
        await reload()
    }

    var openItemNames: [String] {
        shoppingItems.filter { !$0.isCompleted }.compactMap(\.title)
    }

    // MARK: - 저녁 메뉴 계획 (가족 캘린더)

    /// 계획을 저장할 캘린더: 가족 캘린더 → 기본 캘린더
    private var planCalendar: EKCalendar? {
        CalendarStore.shared.familyCalendar ?? eventStore.defaultCalendarForNewEvents
    }

    var hasCalendarAccess: Bool { CalendarStore.shared.hasAccess }

    private func loadPlans() {
        guard hasCalendarAccess else {
            plannedMeals = []
            return
        }
        let calendar = Calendar.current
        let start = calendar.startOfDay(for: .now)
        guard let end = calendar.date(byAdding: .day, value: 14, to: start) else { return }
        let predicate = eventStore.predicateForEvents(withStart: start, end: end, calendars: nil)
        plannedMeals = eventStore.events(matching: predicate)
            .compactMap { PlannedMeal(id: $0.eventIdentifier ?? UUID().uuidString, title: $0.title, date: $0.startDate, notes: $0.notes) }
            .sorted { $0.date < $1.date }
    }

    func plan(on date: Date) -> PlannedMeal? {
        plannedMeals.first { Calendar.current.isDate($0.date, inSameDayAs: date) }
    }

    /// 그날 저녁 계획을 저장한다 (이미 있으면 바꾼다).
    func setPlan(on date: Date, dish: String, ingredients: [String], reason: String) throws {
        if let existing = plan(on: date) { removePlan(existing) }
        guard let calendarForPlan = planCalendar else { return }

        let event = EKEvent(eventStore: eventStore)
        event.calendar = calendarForPlan
        event.title = PlannedMeal.titlePrefix + dish
        event.notes = PlannedMeal.notes(ingredients: ingredients, reason: reason)
        let start = Calendar.current.date(
            bySettingHour: profile.dinnerHour, minute: profile.dinnerMinute, second: 0, of: date
        ) ?? date
        event.startDate = start
        event.endDate = start.addingTimeInterval(3600)
        try eventStore.save(event, span: .thisEvent, commit: true)
        loadPlans()
    }

    func removePlan(_ meal: PlannedMeal) {
        if let event = eventStore.event(withIdentifier: meal.id) {
            try? eventStore.remove(event, span: .thisEvent, commit: true)
        }
        loadPlans()
    }

    /// 저녁 시간대(17~21시)에 잡힌 다른 일정 (회식 · 약속 등) — 메뉴 추천에서 빼기 위해
    func dinnerConflicts(on date: Date) -> [String] {
        guard hasCalendarAccess else { return [] }
        let calendar = Calendar.current
        guard let start = calendar.date(bySettingHour: 17, minute: 0, second: 0, of: date),
              let end = calendar.date(bySettingHour: 21, minute: 0, second: 0, of: date) else { return [] }
        let predicate = eventStore.predicateForEvents(withStart: start, end: end, calendars: nil)
        return eventStore.events(matching: predicate)
            .filter { !$0.isAllDay && !($0.title ?? "").hasPrefix(PlannedMeal.titlePrefix) }
            .compactMap(\.title)
    }
}
