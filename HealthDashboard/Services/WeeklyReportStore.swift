import Foundation
import Observation
import UserNotifications

struct WeeklyReport: Codable, Identifiable {
    /// 리포트 대상 주의 월요일
    let weekStart: Date
    let text: String
    let stats: String
    let generatedAt: Date

    var id: Date { weekStart }

    var title: String {
        let end = Calendar.current.date(byAdding: .day, value: 6, to: weekStart) ?? weekStart
        return "\(weekStart.formatted(.dateTime.month(.defaultDigits).day())) – \(end.formatted(.dateTime.month(.defaultDigits).day()))"
    }
}

/// 매주 월요일, 지난주(월~일) 기록을 지지난주와 비교해 AI가 리포트를 쓴다.
/// 앱이 열리거나 백그라운드에서 깨어날 때 아직 이번 주 리포트가 없으면 만든다.
@MainActor
@Observable
final class WeeklyReportStore {
    static let shared = WeeklyReportStore()

    static let enabledKey = "weeklyReportEnabled"
    private static let reportsKey = "weeklyReports"

    private(set) var reports: [WeeklyReport] = []
    private(set) var isGenerating = false

    var enabled: Bool {
        get { UserDefaults.standard.object(forKey: Self.enabledKey) as? Bool ?? true }
        set { UserDefaults.standard.set(newValue, forKey: Self.enabledKey) }
    }

    init() {
        if let data = UserDefaults.standard.data(forKey: Self.reportsKey),
           let saved = try? JSONDecoder().decode([WeeklyReport].self, from: data) {
            reports = saved
        }
    }

    private static var mondayCalendar: Calendar {
        var calendar = Calendar.current
        calendar.firstWeekday = 2
        return calendar
    }

    /// 지난주 월요일
    static var lastWeekStart: Date? {
        let calendar = mondayCalendar
        guard let thisWeek = calendar.dateInterval(of: .weekOfYear, for: .now)?.start else { return nil }
        return calendar.date(byAdding: .day, value: -7, to: thisWeek)
    }

    /// 지난주 리포트가 아직 없으면 만들고 알림을 보낸다.
    func generateIfNeeded() async {
        guard enabled, let weekStart = Self.lastWeekStart,
              !reports.contains(where: { Calendar.current.isDate($0.weekStart, inSameDayAs: weekStart) }) else { return }
        guard let report = await generate(weekStart: weekStart) else { return }
        await notify(report)
    }

    /// 화면의 '다시 만들기' 버튼
    func regenerateLastWeek() async {
        guard let weekStart = Self.lastWeekStart else { return }
        _ = await generate(weekStart: weekStart)
    }

    private func generate(weekStart: Date) async -> WeeklyReport? {
        guard !isGenerating else { return nil }
        let dashboard = DashboardModel.shared
        // 데이터가 아직 없으면(앱을 막 켠 직후 등) 만들지 않는다.
        guard !dashboard.readiness.isEmpty || !dashboard.sleepNights.isEmpty else { return nil }

        isGenerating = true
        defer { isGenerating = false }

        let stats = await Self.weekStats(weekStart: weekStart)
        let instructions = """
        당신은 사용자의 개인 건강 코치입니다. 주어진 주간 기록만 근거로 한국어 존댓말로 주간 리포트를 씁니다.
        형식:
        한 줄 총평 (이모지 1개)
        👍 잘한 점: 1~2개 (숫자 인용)
        👀 아쉬운 점: 1~2개 (숫자 인용)
        🎯 이번 주 목표: 구체적인 행동 2개
        전체 8문장 이내. 의학적 진단은 하지 마세요.
        """
        guard let text = await CoachModel.generate(instructions: instructions, prompt: stats) else { return nil }

        let report = WeeklyReport(weekStart: weekStart, text: text, stats: stats, generatedAt: .now)
        reports.removeAll { Calendar.current.isDate($0.weekStart, inSameDayAs: weekStart) }
        reports.insert(report, at: 0)
        reports = Array(reports.prefix(12))
        if let data = try? JSONEncoder().encode(reports) {
            UserDefaults.standard.set(data, forKey: Self.reportsKey)
        }
        return report
    }

    private func notify(_ report: WeeklyReport) async {
        let center = UNUserNotificationCenter.current()
        guard await center.notificationSettings().authorizationStatus == .authorized else { return }
        let content = UNMutableNotificationContent()
        content.title = "📊 지난주 건강 리포트 (\(report.title))"
        content.body = report.text
        content.sound = .default

        // 월요일 아침 8시 전에 만들어졌으면 8시에, 이후면 바로 보낸다.
        let calendar = Calendar.current
        let eight = calendar.date(bySettingHour: 8, minute: 0, second: 0, of: .now) ?? .now
        let trigger: UNNotificationTrigger? = Date.now < eight
            ? UNCalendarNotificationTrigger(
                dateMatching: calendar.dateComponents([.year, .month, .day, .hour, .minute], from: eight), repeats: false)
            : nil
        let request = UNNotificationRequest(identifier: "weekly-report-\(HabitStore.dayKey(report.weekStart))",
                                            content: content, trigger: trigger)
        try? await center.add(request)
    }

    // MARK: - 주간 통계 (지난주 vs 지지난주)

    private static func weekStats(weekStart: Date) async -> String {
        let calendar = Calendar.current
        let dashboard = DashboardModel.shared
        guard let weekEnd = calendar.date(byAdding: .day, value: 7, to: weekStart),
              let previousStart = calendar.date(byAdding: .day, value: -7, to: weekStart) else { return "" }

        func inWeek(_ date: Date, _ start: Date, _ end: Date) -> Bool { date >= start && date < end }
        func average(_ values: [Double]) -> Double? { values.isEmpty ? nil : values.reduce(0, +) / Double(values.count) }
        func compare(_ label: String, _ now: Double?, _ before: Double?, unit: String, digits: Int = 0) -> String? {
            guard let now else { return nil }
            let value = now.formatted(.number.precision(.fractionLength(digits)))
            guard let before else { return "\(label): \(value)\(unit)" }
            let diff = now - before
            let sign = diff >= 0 ? "+" : ""
            return "\(label): \(value)\(unit) (지지난주 대비 \(sign)\(diff.formatted(.number.precision(.fractionLength(digits))))\(unit))"
        }

        var lines = ["기간: \(weekStart.formatted(.dateTime.month().day())) ~ \(calendar.date(byAdding: .day, value: 6, to: weekStart)!.formatted(.dateTime.month().day()))"]

        let readiness = dashboard.readiness
        lines += [compare("평균 준비 점수",
                          average(readiness.filter { inWeek($0.date, weekStart, weekEnd) }.map(\.score)),
                          average(readiness.filter { inWeek($0.date, previousStart, weekStart) }.map(\.score)),
                          unit: "점", digits: 1)].compactMap { $0 }

        let nights = dashboard.sleepNights
        let sleepNow = nights.filter { inWeek($0.wakeDate, weekStart, weekEnd) }
        lines += [compare("평균 수면",
                          average(sleepNow.map { $0.asleep / 3600 }),
                          average(nights.filter { inWeek($0.wakeDate, previousStart, weekStart) }.map { $0.asleep / 3600 }),
                          unit: "시간", digits: 1)].compactMap { $0 }
        let lateNights = sleepNow.filter { night in
            guard let bedtime = night.bedtime else { return false }
            let hour = calendar.component(.hour, from: bedtime)
            return hour >= 0 && hour < 6
        }.count
        if !sleepNow.isEmpty {
            lines.append("자정 넘어 잔 날: \(lateNights)일")
        }

        if let inputs = dashboard.readinessInputs {
            lines += [
                compare("수면 중 HRV",
                        average(inputs.hrv.filter { inWeek($0.date, weekStart, weekEnd) }.map(\.value)),
                        average(inputs.hrv.filter { inWeek($0.date, previousStart, weekStart) }.map(\.value)),
                        unit: "ms"),
                compare(inputs.heartRateIsSleeping ? "수면 중 심박수" : "안정 시 심박수",
                        average(inputs.restingHeartRate.filter { inWeek($0.date, weekStart, weekEnd) }.map(\.value)),
                        average(inputs.restingHeartRate.filter { inWeek($0.date, previousStart, weekStart) }.map(\.value)),
                        unit: "BPM"),
                compare("하루 평균 활동 에너지",
                        average(inputs.activeEnergy.filter { inWeek($0.date, weekStart, weekEnd) }.map(\.value)),
                        average(inputs.activeEnergy.filter { inWeek($0.date, previousStart, weekStart) }.map(\.value)),
                        unit: "kcal"),
            ].compactMap { $0 }
        }

        let steps = await HealthHistoryProvider.history(item: "steps", days: 14)
        lines.append("최근 14일 걸음 수:\n" + steps)

        let workouts = dashboard.workouts.filter { inWeek($0.start, weekStart, weekEnd) }
        lines.append("운동: \(workouts.count)회" + (workouts.isEmpty ? "" : " (" + workouts.map {
            "\($0.activityType.displayName) \(Int($0.duration / 60))분"
        }.joined(separator: ", ") + ")"))

        let medications = MedicationStore.shared
        for reminder in medications.reminders {
            let taken = (0..<7).filter { offset in
                calendar.date(byAdding: .day, value: offset, to: weekStart).map { medications.isTaken(reminder, on: $0) } ?? false
            }.count
            lines.append("\(reminder.name) 복용: 7일 중 \(taken)일")
        }

        let habits = HabitStore.shared
        var tagCounts: [HabitTag: Int] = [:]
        for offset in 0..<7 {
            guard let date = calendar.date(byAdding: .day, value: offset, to: weekStart) else { continue }
            for tag in habits.tags(on: date) { tagCounts[tag, default: 0] += 1 }
        }
        if !tagCounts.isEmpty {
            lines.append("습관 기록: " + tagCounts.sorted { $0.value > $1.value }.map { "\($0.key.title) \($0.value)일" }.joined(separator: ", "))
        }

        return lines.joined(separator: "\n")
    }
}
