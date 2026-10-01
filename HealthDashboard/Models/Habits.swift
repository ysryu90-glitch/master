import Foundation
import Observation
import UserNotifications

enum HabitTag: String, CaseIterable, Codable, Identifiable {
    case alcohol, lateCaffeine, lateMeal, overtime, stress, lateScreen, nap

    var id: String { rawValue }

    var emoji: String {
        switch self {
        case .alcohol: "🍺"
        case .lateCaffeine: "☕"
        case .lateMeal: "🍜"
        case .overtime: "💼"
        case .stress: "😰"
        case .lateScreen: "📱"
        case .nap: "😴"
        }
    }

    var title: String {
        switch self {
        case .alcohol: "술"
        case .lateCaffeine: "오후 카페인"
        case .lateMeal: "야식"
        case .overtime: "야근"
        case .stress: "스트레스"
        case .lateScreen: "늦은 스마트폰"
        case .nap: "낮잠"
        }
    }

    /// 분석 문장용. 예: "술 마신 날"
    var dayPhrase: String {
        switch self {
        case .alcohol: "술 마신 날"
        case .lateCaffeine: "오후에 카페인을 마신 날"
        case .lateMeal: "야식 먹은 날"
        case .overtime: "야근한 날"
        case .stress: "스트레스가 컸던 날"
        case .lateScreen: "늦게까지 스마트폰을 본 날"
        case .nap: "낮잠 잔 날"
        }
    }
}

/// 하루 습관 기록. 기록한 날(태그 없음 포함)만 분석에 쓴다.
@MainActor
@Observable
final class HabitStore {
    static let shared = HabitStore()

    private enum Keys {
        static let log = "habitLog"
        static let reminderEnabled = "habitReminderEnabled"
        static let reminderHour = "habitReminderHour"
        static let reminderMinute = "habitReminderMinute"
    }

    static let reminderID = "habit-reminder"

    /// "yyyy-MM-dd" → 태그 목록 (빈 배열 = '특별한 일 없음'으로 기록한 날)
    private(set) var log: [String: [String]]

    var reminderEnabled: Bool {
        didSet { saveReminder() }
    }
    var reminderHour: Int {
        didSet { saveReminder() }
    }
    var reminderMinute: Int {
        didSet { saveReminder() }
    }

    init() {
        let defaults = UserDefaults.standard
        log = (defaults.dictionary(forKey: Keys.log) as? [String: [String]]) ?? [:]
        reminderEnabled = defaults.bool(forKey: Keys.reminderEnabled)
        reminderHour = defaults.object(forKey: Keys.reminderHour) as? Int ?? 22
        reminderMinute = defaults.object(forKey: Keys.reminderMinute) as? Int ?? 30
    }

    /// 백업에서 복원한 뒤 다시 읽기
    func reloadFromDefaults() {
        let defaults = UserDefaults.standard
        log = (defaults.dictionary(forKey: Keys.log) as? [String: [String]]) ?? [:]
        reminderEnabled = defaults.bool(forKey: Keys.reminderEnabled)
        reminderHour = defaults.object(forKey: Keys.reminderHour) as? Int ?? 22
        reminderMinute = defaults.object(forKey: Keys.reminderMinute) as? Int ?? 30
    }

    /// 새벽 5시 전에는 '어제' 기록으로 본다. (자기 전에 기록하는 경우)
    static var loggingDate: Date {
        let now = Date.now
        let calendar = Calendar.current
        return calendar.component(.hour, from: now) < 5
            ? calendar.date(byAdding: .day, value: -1, to: now) ?? now
            : now
    }

    func tags(on date: Date) -> Set<HabitTag> {
        Set((log[Self.dayKey(date)] ?? []).compactMap(HabitTag.init(rawValue:)))
    }

    func isLogged(_ date: Date) -> Bool { log[Self.dayKey(date)] != nil }

    func toggle(_ tag: HabitTag, on date: Date) {
        var tags = tags(on: date)
        if tags.contains(tag) { tags.remove(tag) } else { tags.insert(tag) }
        set(tags, on: date)
    }

    /// 자동 기록용: 이미 있으면 그대로 둔다.
    func add(_ tag: HabitTag, on date: Date) {
        var tags = tags(on: date)
        guard !tags.contains(tag) else { return }
        tags.insert(tag)
        set(tags, on: date)
    }

    /// '특별한 일 없음' 기록
    func markNothing(on date: Date) { set([], on: date) }

    func clear(on date: Date) {
        log[Self.dayKey(date)] = nil
        persist()
    }

    private func set(_ tags: Set<HabitTag>, on date: Date) {
        log[Self.dayKey(date)] = tags.map(\.rawValue).sorted()
        // 최근 180일만 보관
        if log.count > 180 {
            for key in log.keys.sorted().prefix(log.count - 180) { log[key] = nil }
        }
        persist()
    }

    private func persist() {
        UserDefaults.standard.set(log, forKey: Keys.log)
    }

    // MARK: - 기록 알림

    private func saveReminder() {
        let defaults = UserDefaults.standard
        defaults.set(reminderEnabled, forKey: Keys.reminderEnabled)
        defaults.set(reminderHour, forKey: Keys.reminderHour)
        defaults.set(reminderMinute, forKey: Keys.reminderMinute)
        Task { await rescheduleReminder() }
    }

    func rescheduleReminder() async {
        let center = UNUserNotificationCenter.current()
        center.removePendingNotificationRequests(withIdentifiers: [Self.reminderID])
        guard reminderEnabled else { return }
        let content = UNMutableNotificationContent()
        content.title = "📝 오늘 하루 어땠나요?"
        content.body = "술·카페인·야근 같은 습관을 기록하면 내 컨디션에 뭐가 영향을 주는지 분석해 드려요."
        content.sound = .default
        let request = UNNotificationRequest(
            identifier: Self.reminderID,
            content: content,
            trigger: UNCalendarNotificationTrigger(
                dateMatching: DateComponents(hour: reminderHour, minute: reminderMinute), repeats: true
            )
        )
        try? await center.add(request)
    }

    static func dayKey(_ date: Date) -> String {
        let components = Calendar.current.dateComponents([.year, .month, .day], from: date)
        return String(format: "%04d-%02d-%02d", components.year ?? 0, components.month ?? 0, components.day ?? 0)
    }
}

// MARK: - 분석

struct HabitInsight: Identifiable {
    struct Effect {
        let label: String
        let difference: Double
        let unit: String
        /// 값이 클수록 좋은 지표인지 (준비 점수, 수면, HRV 모두 true)
        var higherIsBetter = true

        var isGood: Bool { higherIsBetter ? difference > 0 : difference < 0 }

        var text: String {
            let sign = difference > 0 ? "+" : ""
            let digits = unit == "점" ? 1 : 0
            return "\(label) \(sign)\(difference.formatted(.number.precision(.fractionLength(digits))))\(unit)"
        }
    }

    let id: String
    let emoji: String
    let title: String
    let phrase: String
    /// 해당 습관이 있었던 날 수
    let count: Int
    /// 비교한 '없었던 날' 수
    var comparisonCount = 0
    let effects: [Effect]

    enum Confidence: String {
        case weak = "근거 약함"
        case moderate = "근거 보통"
        case strong = "근거 강함"
    }

    /// 표본 수와 차이 크기로 정하는 믿을 만한 정도
    var confidence: Confidence {
        let samples = min(count, comparisonCount)
        let effect = strength
        if samples >= 10 && effect >= 0.7 { return .strong }
        if samples >= 5 && effect >= 0.4 { return .moderate }
        return .weak
    }

    /// 가장 큰 영향(준비 점수 기준)
    var headline: String? {
        guard let readiness = effects.first(where: { $0.unit == "점" }) else { return effects.first.map { "\(phrase) \($0.text)" } }
        let direction = readiness.difference >= 0 ? "높아요" : "낮아요"
        return "\(phrase) 다음 날 준비 점수가 평균 \(abs(readiness.difference).formatted(.number.precision(.fractionLength(1))))점 \(direction)"
    }

    var strength: Double {
        effects.first(where: { $0.unit == "점" }).map { abs($0.difference) } ?? 0
    }
}

/// 습관이 있던 날과 없던 날의 '다음 날 준비 점수 · 그날 밤 수면 · 다음 날 아침 HRV'를 비교한다.
enum HabitAnalyzer {
    private static let minimumDays = 3

    struct DayData {
        let date: Date
        var nextReadiness: Double?
        var sleepMinutes: Double?
        var nextHRV: Double?
    }

    @MainActor
    static func insights(
        habits: HabitStore,
        readiness: [ReadinessScore],
        nights: [SleepNight],
        hrv: [TrendPoint],
        workouts: [WorkoutItem],
        days: Int = 30
    ) -> [HabitInsight] {
        let calendar = Calendar.current
        let today = calendar.startOfDay(for: .now)
        let dayData: [DayData] = (1...days).compactMap { offset in
            guard let date = calendar.date(byAdding: .day, value: -offset, to: today),
                  let next = calendar.date(byAdding: .day, value: 1, to: date) else { return nil }
            return DayData(
                date: date,
                nextReadiness: readiness.first { calendar.isDate($0.date, inSameDayAs: next) }?.score,
                sleepMinutes: nights.first { calendar.isDate($0.night, inSameDayAs: date) }.map { $0.asleep / 60 },
                nextHRV: hrv.first { calendar.isDate($0.date, inSameDayAs: next) }?.value
            )
        }

        var results: [HabitInsight] = []

        // 직접 기록한 습관: 기록한 날끼리만 비교
        let logged = dayData.filter { habits.isLogged($0.date) }
        for tag in HabitTag.allCases {
            let with = logged.filter { habits.tags(on: $0.date).contains(tag) }
            let without = logged.filter { !habits.tags(on: $0.date).contains(tag) }
            if let insight = compare(id: tag.rawValue, emoji: tag.emoji, title: tag.title,
                                     phrase: tag.dayPhrase, with: with, without: without) {
                results.append(insight)
            }
        }

        // 자동으로 알 수 있는 습관: 11시 전 취침, 운동한 날
        let earlyBed = dayData.filter { day in
            guard let bedtime = nights.first(where: { calendar.isDate($0.night, inSameDayAs: day.date) })?.bedtime else { return false }
            let hour = calendar.component(.hour, from: bedtime)
            return hour >= 18 && hour < 23
        }
        let lateBed = dayData.filter { day in
            guard let bedtime = nights.first(where: { calendar.isDate($0.night, inSameDayAs: day.date) })?.bedtime else { return false }
            let hour = calendar.component(.hour, from: bedtime)
            return hour >= 23 || hour < 6
        }
        if let insight = compare(id: "earlyBed", emoji: "🌙", title: "11시 전 취침",
                                 phrase: "11시 전에 잔 날", with: earlyBed, without: lateBed) {
            results.append(insight)
        }

        // 식단 기록 기반: 2끼 이상 기록한 날끼리 비교
        let mealStore = MealStore.shared
        let mealDays = dayData.filter { mealStore.meals(on: $0.date).count >= 2 }
        if !mealDays.isEmpty {
            let averageCalories = ReadinessCalculator.mean(mealDays.map { mealStore.totals(on: $0.date).calories })
            let bigDays = mealDays.filter { mealStore.totals(on: $0.date).calories > averageCalories * 1.2 }
            if let insight = compare(
                id: "overeating", emoji: "🍽", title: "많이 먹은 날",
                phrase: "평소보다 20% 이상 많이 먹은 날",
                with: bigDays,
                without: mealDays.filter { day in !bigDays.contains { $0.date == day.date } }
            ) {
                results.append(insight)
            }

            let proteinDays = mealDays.filter { mealStore.totals(on: $0.date).protein >= 80 }
            if let insight = compare(
                id: "protein", emoji: "🍗", title: "단백질 충분",
                phrase: "단백질을 80g 이상 먹은 날",
                with: proteinDays,
                without: mealDays.filter { day in !proteinDays.contains { $0.date == day.date } }
            ) {
                results.append(insight)
            }
        }

        let workoutDays = Set(workouts.map { calendar.startOfDay(for: $0.start) })
        if let insight = compare(
            id: "workout", emoji: "🏃", title: "운동",
            phrase: "운동한 날",
            with: dayData.filter { workoutDays.contains($0.date) },
            without: dayData.filter { !workoutDays.contains($0.date) }
        ) {
            results.append(insight)
        }

        return results.sorted { $0.strength > $1.strength }
    }

    private static func compare(
        id: String, emoji: String, title: String, phrase: String,
        with: [DayData], without: [DayData]
    ) -> HabitInsight? {
        var effects: [HabitInsight.Effect] = []

        if let difference = difference(with.compactMap(\.nextReadiness), without.compactMap(\.nextReadiness)) {
            effects.append(.init(label: "다음 날 준비 점수", difference: difference, unit: "점"))
        }
        if let difference = difference(with.compactMap(\.sleepMinutes), without.compactMap(\.sleepMinutes)) {
            effects.append(.init(label: "그날 밤 수면", difference: difference, unit: "분"))
        }
        let withHRV = with.compactMap(\.nextHRV)
        let withoutHRV = without.compactMap(\.nextHRV)
        if let difference = difference(withHRV, withoutHRV) {
            let base = ReadinessCalculator.mean(withoutHRV)
            if base > 0 {
                effects.append(.init(label: "다음 날 HRV", difference: difference / base * 100, unit: "%"))
            }
        }

        guard !effects.isEmpty else { return nil }
        return HabitInsight(id: id, emoji: emoji, title: title, phrase: phrase,
                            count: with.count, comparisonCount: without.count, effects: effects)
    }

    private static func difference(_ with: [Double], _ without: [Double]) -> Double? {
        guard with.count >= minimumDays, without.count >= minimumDays else { return nil }
        return ReadinessCalculator.mean(with) - ReadinessCalculator.mean(without)
    }
}
