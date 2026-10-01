import EventKit
import Foundation
import Observation
import Security
import UIKit

/// 가족 전광판: 시놀로지 NAS(Web Station + PHP + MariaDB)로 오늘의 요약을 보낸다.
/// - 앱이 새로고침될 때마다(최대 10분에 한 번, 내용이 바뀌면 바로) `board/api/ingest.php`로 POST
/// - NAS는 최신 요약을 전광판에 보여주고, 날마다의 건강 요약 · 저녁 계획 · 식단 기록을 DB에 쌓는다.
@MainActor
@Observable
final class BoardPublisher {
    static let shared = BoardPublisher()

    enum Keys {
        static let serverURL = "board.serverURL"
        static let member = "board.member"
        static let name = "board.name"
        static let showReadiness = "board.showReadiness"
        static let showSteps = "board.showSteps"
        static let showSleep = "board.showSleep"
        static let showWater = "board.showWater"
        static let sendMeals = "board.sendMeals"
        static let lastSent = "board.lastSent"
    }

    enum Member: String, CaseIterable, Identifiable {
        case dad, mom
        var id: String { rawValue }
        var title: String { self == .dad ? "아빠" : "엄마" }
        var emoji: String { self == .dad ? "👨" : "👩" }
    }

    private(set) var isSending = false
    private(set) var lastError: String?
    private(set) var lastSent: Date? = UserDefaults.standard.object(forKey: Keys.lastSent) as? Date

    @ObservationIgnored private var lastFingerprint: Int?

    private var defaults: UserDefaults { .standard }

    var serverURL: String { defaults.string(forKey: Keys.serverURL) ?? "" }
    var isConfigured: Bool { !serverURL.isEmpty && !(token ?? "").isEmpty }

    /// 아이패드 사파리에서 열 전광판 주소
    var boardAddress: String? {
        normalizedBase.map { $0 + "/board/" }
    }

    private var normalizedBase: String? {
        var text = serverURL.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !text.isEmpty else { return nil }
        if !text.hasPrefix("http://") && !text.hasPrefix("https://") { text = "http://" + text }
        while text.hasSuffix("/") { text.removeLast() }
        return text
    }

    // MARK: - 보내기

    /// 앱 새로고침 끝에 호출: 설정되어 있고, 10분이 지났거나 내용이 바뀌었을 때만 보낸다.
    func publishIfDue() async {
        guard isConfigured, !isSending else { return }
        let payload = await makePayload()
        let fingerprint = payload.fingerprint
        let recentlySent = lastSent.map { Date.now.timeIntervalSince($0) < 10 * 60 } ?? false
        if recentlySent && fingerprint == lastFingerprint { return }
        await send(payload)
    }

    /// 설정 화면의 '지금 보내기'
    func publishNow() async {
        guard isConfigured else {
            lastError = "NAS 주소와 토큰을 먼저 입력해 주세요."
            return
        }
        await send(await makePayload())
    }

    private func send(_ payload: BoardPayload) async {
        guard let base = normalizedBase, let url = URL(string: base + "/board/api/ingest.php") else {
            lastError = "NAS 주소 형식이 올바르지 않아요."
            return
        }
        isSending = true
        defer { isSending = false }

        let encoder = JSONEncoder()
        encoder.dateEncodingStrategy = .iso8601
        var request = URLRequest(url: url, timeoutInterval: 10)
        request.httpMethod = "POST"
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        request.setValue(token ?? "", forHTTPHeaderField: "X-Board-Token")

        do {
            request.httpBody = try encoder.encode(payload)
            let (data, response) = try await URLSession.shared.data(for: request)
            let status = (response as? HTTPURLResponse)?.statusCode ?? 0
            guard status == 200 else {
                let message = (try? JSONDecoder().decode([String: String].self, from: data))?["error"]
                lastError = "NAS 응답 \(status): " + (message ?? String(decoding: data.prefix(200), as: UTF8.self))
                return
            }
            lastError = nil
            lastSent = .now
            lastFingerprint = payload.fingerprint
            defaults.set(lastSent, forKey: Keys.lastSent)
        } catch let error as URLError where error.code == .timedOut || error.code == .cannotConnectToHost
            || error.code == .notConnectedToInternet || error.code == .cannotFindHost {
            // 집 밖이라 NAS에 닿지 않는 경우: 다음에 다시 보낸다.
            lastError = "NAS에 연결할 수 없어요. 집 와이파이인지, 주소가 맞는지 확인해 주세요."
        } catch {
            lastError = error.localizedDescription
        }
    }

    // MARK: - 보낼 내용

    private func makePayload() async -> BoardPayload {
        let member = Member(rawValue: defaults.string(forKey: Keys.member) ?? "") ?? .dad
        let name = (defaults.string(forKey: Keys.name)).flatMap { $0.isEmpty ? nil : $0 } ?? member.title
        let kitchen = KitchenStore.shared
        await kitchen.reload()

        let calendar = Calendar.current
        let today = calendar.startOfDay(for: .now)
        let weekEnd = calendar.date(byAdding: .day, value: 7, to: today) ?? today

        // 일정 (저녁 메뉴 계획 일정은 '저녁' 칸에 따로 보여주므로 뺀다)
        var events: [BoardPayload.Event] = []
        if CalendarStore.shared.hasAccess {
            events = CalendarStore.shared.eventsFor(days: 7)
                .filter { !($0.title ?? "").hasPrefix(PlannedMeal.titlePrefix) }
                .prefix(60)
                .map { event in
                    BoardPayload.Event(
                        title: event.title ?? "일정",
                        start: CalendarStore.start(of: event),
                        end: CalendarStore.end(of: event),
                        allDay: event.isAllDay,
                        color: Self.hex(event.calendar?.cgColor)
                    )
                }
        }

        let dinners = kitchen.plannedMeals
            .filter { $0.date >= today && $0.date < weekEnd }
            .map { BoardPayload.Dinner(date: Self.dayString($0.date), dish: $0.dish, ingredients: $0.ingredients) }

        let shopping: BoardPayload.Shopping? = kitchen.hasRemindersAccess && kitchen.shoppingList != nil
            ? BoardPayload.Shopping(remaining: kitchen.openItemNames.count, items: Array(kitchen.openItemNames.prefix(12)))
            : nil

        let profile = kitchen.profile
        let locations = SharedStore.LocationRole.allCases.map { role in
            let location = SharedStore.location(for: role)
            return BoardPayload.Location(role: role.rawValue, title: role.title, name: location.name,
                                         lat: location.latitude, lon: location.longitude)
        }

        // 식단: 최근 7일을 보내고, NAS는 그 기간을 앱과 똑같이 맞춘다.
        var meals: [BoardPayload.Meal]?
        var mealsSince: Date?
        if defaults.object(forKey: Keys.sendMeals) as? Bool ?? true {
            let since = calendar.date(byAdding: .day, value: -7, to: today) ?? today
            mealsSince = since
            meals = MealStore.shared.meals.filter { $0.date >= since }.map { meal in
                let totals = meal.totals
                return BoardPayload.Meal(
                    id: meal.id.uuidString, eatenAt: meal.date, type: meal.type.rawValue, title: meal.title,
                    calories: totals.calories, carbs: totals.carbohydrates, protein: totals.protein,
                    fat: totals.fat, sodium: totals.sodium, items: meal.items.map(\.name).filter { !$0.isEmpty }
                )
            }
        }

        return BoardPayload(
            member: member.rawValue,
            name: name,
            emoji: member.emoji,
            updatedAt: .now,
            dinnerTime: String(format: "%02d:%02d", profile.dinnerHour, profile.dinnerMinute),
            events: events,
            dinners: dinners,
            shopping: shopping,
            health: makeHealth(),
            locations: locations,
            meals: meals,
            mealsSince: mealsSince
        )
    }

    private func makeHealth() -> BoardPayload.Health? {
        guard let snapshot = SharedStore.healthSnapshot, !snapshot.isDemo else { return nil }
        func enabled(_ key: String) -> Bool { defaults.object(forKey: key) as? Bool ?? true }
        let readiness = snapshot.readiness(on: .now)
        let health = BoardPayload.Health(
            day: Self.dayString(.now),
            readiness: enabled(Keys.showReadiness) ? readiness.map { ($0.score * 10).rounded() / 10 } : nil,
            readinessLevel: enabled(Keys.showReadiness) ? readiness?.level.title : nil,
            steps: enabled(Keys.showSteps) ? snapshot.steps : nil,
            sleepHours: enabled(Keys.showSleep) ? snapshot.sleepSeconds.map { ($0 / 360).rounded() / 10 } : nil,
            water: enabled(Keys.showWater) ? snapshot.waterMl : nil
        )
        return health
    }

    private static func dayString(_ date: Date) -> String {
        let c = Calendar.current.dateComponents([.year, .month, .day], from: date)
        return String(format: "%04d-%02d-%02d", c.year ?? 0, c.month ?? 0, c.day ?? 0)
    }

    private static func hex(_ color: CGColor?) -> String {
        guard let color else { return "#4da3ff" }
        var r: CGFloat = 0, g: CGFloat = 0, b: CGFloat = 0, a: CGFloat = 0
        UIColor(cgColor: color).getRed(&r, green: &g, blue: &b, alpha: &a)
        return String(format: "#%02x%02x%02x", Int(r * 255), Int(g * 255), Int(b * 255))
    }

    // MARK: - 토큰 (키체인)

    private static let keychainService = "healthdashboard.board"

    var token: String? {
        get {
            let query: [String: Any] = [
                kSecClass as String: kSecClassGenericPassword,
                kSecAttrService as String: Self.keychainService,
                kSecReturnData as String: true,
                kSecMatchLimit as String: kSecMatchLimitOne,
            ]
            var item: CFTypeRef?
            guard SecItemCopyMatching(query as CFDictionary, &item) == errSecSuccess,
                  let data = item as? Data else { return nil }
            return String(data: data, encoding: .utf8)
        }
        set {
            let query: [String: Any] = [
                kSecClass as String: kSecClassGenericPassword,
                kSecAttrService as String: Self.keychainService,
            ]
            SecItemDelete(query as CFDictionary)
            guard let newValue, !newValue.isEmpty else { return }
            var attributes = query
            attributes[kSecValueData as String] = Data(newValue.utf8)
            // 백그라운드 새로고침 중에도 읽을 수 있게 (첫 잠금 해제 이후)
            attributes[kSecAttrAccessible as String] = kSecAttrAccessibleAfterFirstUnlock
            SecItemAdd(attributes as CFDictionary, nil)
        }
    }
}

/// NAS로 보내는 내용 (board/api/ingest.php 와 board.js 가 읽는 형식)
struct BoardPayload: Encodable {
    struct Event: Encodable, Hashable {
        let title: String
        let start: Date
        let end: Date
        let allDay: Bool
        let color: String
    }

    struct Dinner: Encodable, Hashable {
        let date: String
        let dish: String
        let ingredients: [String]
    }

    struct Shopping: Encodable, Hashable {
        let remaining: Int
        let items: [String]
    }

    struct Health: Encodable, Hashable {
        let day: String
        let readiness: Double?
        let readinessLevel: String?
        let steps: Double?
        let sleepHours: Double?
        let water: Double?
    }

    struct Location: Encodable, Hashable {
        let role: String
        let title: String
        let name: String
        let lat: Double
        let lon: Double
    }

    struct Meal: Encodable, Hashable {
        let id: String
        let eatenAt: Date
        let type: String
        let title: String
        let calories: Double
        let carbs: Double
        let protein: Double
        let fat: Double
        let sodium: Double
        let items: [String]
    }

    var version = 1
    let member: String
    let name: String
    let emoji: String
    let updatedAt: Date
    let dinnerTime: String
    let events: [Event]
    let dinners: [Dinner]
    let shopping: Shopping?
    let health: Health?
    let locations: [Location]
    let meals: [Meal]?
    let mealsSince: Date?

    /// 시각을 뺀 내용이 바뀌었는지 비교용
    var fingerprint: Int {
        var hasher = Hasher()
        hasher.combine(name)
        hasher.combine(events)
        hasher.combine(dinners)
        hasher.combine(shopping)
        hasher.combine(health)
        hasher.combine(locations)
        hasher.combine(meals)
        return hasher.finalize()
    }
}
