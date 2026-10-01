import EventKit
import Observation
import SwiftUI

/// 애플 기본 캘린더(EventKit)와 연동되는 가족 캘린더.
/// 애플 '가족 공유'를 설정하면 iCloud에 가족 구성원 모두가 함께 쓰는 '가족' 캘린더가 자동으로 생긴다.
/// 이 앱은 그 캘린더에 일정을 읽고 쓰므로, 추가한 일정은 가족 모두의 캘린더 앱에 자동으로 나타난다.
@MainActor
@Observable
final class CalendarStore {
    static let shared = CalendarStore()

    let eventStore = EKEventStore()

    private(set) var authorization: EKAuthorizationStatus = EKEventStore.authorizationStatus(for: .event)
    /// 이번 달(표시 중인 달)의 일정: "yyyy-MM-dd" → 일정 목록
    private(set) var eventsByDay: [String: [EKEvent]] = [:]
    private(set) var familyCalendar: EKCalendar?
    private(set) var writableCalendars: [EKCalendar] = []
    private(set) var displayedMonth = Calendar.current.startOfMonth(for: .now)

    /// true면 가족 캘린더만, false면 모든 캘린더 표시
    var familyOnly: Bool {
        didSet {
            UserDefaults.standard.set(familyOnly, forKey: Keys.familyOnly)
            reload()
        }
    }

    private enum Keys {
        static let familyOnly = "calendarFamilyOnly"
        static let calendarID = "familyCalendarID"
    }

    @ObservationIgnored private var observer: NSObjectProtocol?

    init() {
        familyOnly = UserDefaults.standard.object(forKey: Keys.familyOnly) as? Bool ?? false
        // 다른 기기·캘린더 앱에서 일정이 바뀌면 다시 불러온다.
        observer = NotificationCenter.default.addObserver(
            forName: .EKEventStoreChanged, object: eventStore, queue: .main
        ) { [weak self] _ in
            Task { @MainActor in self?.reload() }
        }
        if hasAccess { reload() }
    }

    var hasAccess: Bool { authorization == .fullAccess }

    func requestAccess() async {
        _ = try? await eventStore.requestFullAccessToEvents()
        authorization = EKEventStore.authorizationStatus(for: .event)
        reload()
    }

    // MARK: - 캘린더 선택

    /// 가족 캘린더: 사용자가 고른 캘린더 → 이름이 '가족'/'Family'인 캘린더 순으로 찾는다.
    private func findFamilyCalendar() -> EKCalendar? {
        if let id = UserDefaults.standard.string(forKey: Keys.calendarID),
           let saved = eventStore.calendar(withIdentifier: id), saved.allowsContentModifications {
            return saved
        }
        return writableCalendars.first { ["가족", "family", "우리 가족"].contains($0.title.lowercased()) }
    }

    func selectFamilyCalendar(_ calendar: EKCalendar) {
        UserDefaults.standard.set(calendar.calendarIdentifier, forKey: Keys.calendarID)
        reload()
    }

    /// '가족' 캘린더가 없을 때 iCloud에 새로 만든다. (가족 공유가 아니면 캘린더 앱에서 가족을 초대해야 함)
    func createFamilyCalendar() throws {
        let calendar = EKCalendar(for: .event, eventStore: eventStore)
        calendar.title = "가족"
        calendar.cgColor = UIColor.systemPink.cgColor
        calendar.source = eventStore.sources.first { $0.sourceType == .calDAV && $0.title.lowercased().contains("icloud") }
            ?? eventStore.defaultCalendarForNewEvents?.source
        try eventStore.saveCalendar(calendar, commit: true)
        selectFamilyCalendar(calendar)
    }

    // MARK: - 일정 불러오기

    func show(month: Date) {
        displayedMonth = Calendar.current.startOfMonth(for: month)
        reload()
    }

    func reload() {
        authorization = EKEventStore.authorizationStatus(for: .event)
        guard hasAccess else { return }

        writableCalendars = eventStore.calendars(for: .event)
            .filter(\.allowsContentModifications)
            .sorted { $0.title < $1.title }
        familyCalendar = findFamilyCalendar()

        let calendar = Calendar.current
        // 달력 앞뒤로 보이는 날까지 넉넉히 불러온다.
        guard let start = calendar.date(byAdding: .day, value: -7, to: displayedMonth),
              let end = calendar.date(byAdding: .day, value: 45, to: displayedMonth) else { return }

        let calendars: [EKCalendar]? = familyOnly ? familyCalendar.map { [$0] } ?? [] : nil
        if calendars?.isEmpty == true {
            eventsByDay = [:]
            return
        }
        let predicate = eventStore.predicateForEvents(withStart: start, end: end, calendars: calendars)
        var grouped: [String: [EKEvent]] = [:]
        for event in eventStore.events(matching: predicate) {
            // EventKit의 날짜는 Optional로 들어오므로 먼저 꺼낸다.
            guard let startDate = event.startDate, let endDate = event.endDate else { continue }
            // 여러 날에 걸친 일정은 해당하는 날마다 넣는다.
            var day = calendar.startOfDay(for: startDate)
            let last: Date = event.isAllDay ? endDate.addingTimeInterval(-1) : endDate
            while day <= last {
                grouped[HabitStore.dayKey(day), default: []].append(event)
                guard let next = calendar.date(byAdding: .day, value: 1, to: day) else { break }
                day = next
            }
        }
        for key in grouped.keys {
            // 종일 일정 먼저, 그다음 시작 시각 순
            grouped[key]?.sort { lhs, rhs in
                if lhs.isAllDay != rhs.isAllDay { return lhs.isAllDay }
                return Self.start(of: lhs) < Self.start(of: rhs)
            }
        }
        eventsByDay = grouped
    }

    func events(on date: Date) -> [EKEvent] {
        eventsByDay[HabitStore.dayKey(date)] ?? []
    }

    func delete(_ event: EKEvent) {
        try? eventStore.remove(event, span: .thisEvent, commit: true)
        reload()
    }

    // MARK: - AI 코치용

    /// 표시 중인 달과 상관없이 오늘 일정
    func todayEvents() -> [EKEvent] {
        guard hasAccess else { return [] }
        _ = eventsByDay // 일정이 다시 불러와지면 화면도 갱신되도록 관찰
        return eventsFor(days: 1)
    }

    func todayEventTitles() -> [String] {
        guard hasAccess else { return [] }
        return eventsFor(days: 1).map(Self.describe)
    }

    func upcomingSummary(days: Int) -> String {
        guard hasAccess else { return "캘린더 접근 권한이 없어요." }
        let events = eventsFor(days: days)
        guard !events.isEmpty else { return "앞으로 \(days)일간 일정 없음" }
        return events.prefix(40).map { event in
            "\(Self.start(of: event).formatted(.dateTime.month(.defaultDigits).day().weekday(.abbreviated))) \(Self.describe(event))"
        }.joined(separator: "\n")
    }

    /// 오늘부터 `days`일간의 일정 (시작 시각순)
    func eventsFor(days: Int) -> [EKEvent] {
        let calendar = Calendar.current
        let start = calendar.startOfDay(for: .now)
        guard let end = calendar.date(byAdding: .day, value: days, to: start) else { return [] }
        let calendars: [EKCalendar]? = familyOnly ? familyCalendar.map { [$0] } : nil
        let predicate = eventStore.predicateForEvents(withStart: start, end: end, calendars: calendars)
        return eventStore.events(matching: predicate).sorted { Self.start(of: $0) < Self.start(of: $1) }
    }

    /// EventKit 날짜는 Optional이라 안전하게 꺼내 쓴다.
    static func start(of event: EKEvent) -> Date { event.startDate ?? .distantPast }
    static func end(of event: EKEvent) -> Date { event.endDate ?? start(of: event) }

    private static func describe(_ event: EKEvent) -> String {
        let time = event.isAllDay ? "종일" : start(of: event).formatted(date: .omitted, time: .shortened)
        return "\(time) \(event.title ?? "일정")"
    }
}

extension Calendar {
    func startOfMonth(for date: Date) -> Date {
        self.date(from: dateComponents([.year, .month], from: date)) ?? startOfDay(for: date)
    }
}
