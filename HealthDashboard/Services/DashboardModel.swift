import HealthKit
import Observation
import UIKit
import WidgetKit

@MainActor
@Observable
final class DashboardModel {
    private enum Keys {
        static let demoMode = "demoMode"
        static let requested = "hasRequestedAuthorization"
        static let showEmpty = "showEmptyMetrics"
    }

    private let service = HealthKitService()

    private(set) var values: [HKQuantityTypeIdentifier: MetricValue] = [:]
    private(set) var activity: [DailyActivity] = []
    /// 준비 점수 기준선 계산을 위해 38일치를 보관한다.
    private(set) var sleepNights: [SleepNight] = []
    /// 최근 7일 준비 점수 (오래된 날 → 오늘)
    private(set) var readiness: [ReadinessScore] = []
    private(set) var mindfulMinutesToday: Double = 0
    private(set) var workouts: [WorkoutItem] = []
    private(set) var isLoading = false
    private(set) var lastUpdated: Date?
    var errorMessage: String?

    var demoMode: Bool {
        didSet {
            UserDefaults.standard.set(demoMode, forKey: Keys.demoMode)
            Task { await refresh() }
        }
    }

    var hasRequestedAuthorization: Bool {
        didSet { UserDefaults.standard.set(hasRequestedAuthorization, forKey: Keys.requested) }
    }

    /// 기록이 없는 지표 카드도 표시할지 여부
    var showEmptyMetrics: Bool {
        didSet { UserDefaults.standard.set(showEmptyMetrics, forKey: Keys.showEmpty) }
    }

    /// 대시보드 순서 / 숨김 / 즐겨찾기
    var layout = DashboardLayout.load() {
        didSet { layout.save() }
    }

    let isHealthDataAvailable = HealthKitService.isAvailable

    init() {
        let defaults = UserDefaults.standard
        demoMode = defaults.bool(forKey: Keys.demoMode) || !HealthKitService.isAvailable
        hasRequestedAuthorization = defaults.bool(forKey: Keys.requested)
        showEmptyMetrics = defaults.bool(forKey: Keys.showEmpty)
    }

    var today: DailyActivity? {
        activity.last.flatMap { Calendar.current.isDateInToday($0.date) ? $0 : nil }
    }

    var lastNight: SleepNight? { sleepNights.last }

    var recentSleepNights: [SleepNight] { Array(sleepNights.suffix(14)) }

    var todayReadiness: ReadinessScore? {
        readiness.last.flatMap { Calendar.current.isDateInToday($0.date) ? $0 : nil }
    }

    func visibleMetrics(in category: MetricCategory) -> [HealthMetric] {
        HealthMetric.metrics(in: category).filter { showEmptyMetrics || values[$0.id] != nil }
    }

    // MARK: - 불러오기

    func connect() async {
        do {
            try await service.requestAuthorization()
        } catch {
            errorMessage = "건강 데이터 권한을 요청하지 못했습니다: \(error.localizedDescription)"
        }
        hasRequestedAuthorization = true
        await refresh()
    }

    func refresh() async {
        guard !isLoading else { return }
        isLoading = true
        defer { isLoading = false }

        if demoMode {
            values = DemoData.values()
            activity = DemoData.dailyActivity(days: 7)
            sleepNights = DemoData.sleepNights(days: ReadinessInputs.lookbackDays)
            readiness = ReadinessCalculator.history(days: 7, inputs: DemoData.readinessInputs())
            mindfulMinutesToday = 10
            workouts = DemoData.workouts()
        } else {
            // 기기가 잠겨 있으면 건강 데이터를 읽을 수 없다. 빈 값으로 덮어쓰지 않고 알림만 갱신한다.
            guard UIApplication.shared.isProtectedDataAvailable else {
                await BriefingScheduler.reschedule()
                return
            }

            let service = self.service
            async let values = Self.fetchValues(service)
            async let activity = try? service.dailyActivity(days: 7)
            async let sleep = try? service.sleepNights(days: ReadinessInputs.lookbackDays)
            async let mindful = try? service.mindfulMinutesToday()
            async let workouts = try? service.recentWorkouts(limit: 30)

            self.values = await values
            self.activity = await activity ?? []
            self.sleepNights = await sleep ?? []
            self.mindfulMinutesToday = await mindful ?? 0
            self.workouts = await workouts ?? []

            let inputs = await service.readinessInputs(sleepNights: sleepNights)
            readiness = ReadinessCalculator.history(days: 7, inputs: inputs)
        }

        lastUpdated = .now
        publishSnapshot()
        await BriefingScheduler.reschedule()
    }

    /// 위젯과 아침 브리핑이 읽을 수 있도록 오늘 요약을 공유 저장소에 저장한다.
    private func publishSnapshot() {
        let today = self.today
        let readiness = todayReadiness
        SharedStore.healthSnapshot = HealthSnapshot(
            updatedAt: .now,
            readinessDate: readiness?.date,
            readinessScore: readiness?.score,
            readinessLevel: readiness?.level,
            move: today?.move,
            moveGoal: today?.moveGoal,
            exercise: today?.exercise,
            exerciseGoal: today?.exerciseGoal,
            stand: today?.stand,
            standGoal: today?.standGoal,
            steps: values[.stepCount]?.value,
            sleepSeconds: lastNight.flatMap { Calendar.current.isDateInToday($0.wakeDate) ? $0.asleep : nil },
            restingHeartRate: values[.restingHeartRate]?.value,
            isDemo: demoMode
        )
        WidgetCenter.shared.reloadAllTimelines()
    }

    private nonisolated static func fetchValues(_ service: HealthKitService) async -> [HKQuantityTypeIdentifier: MetricValue] {
        await withTaskGroup(of: (HKQuantityTypeIdentifier, MetricValue?).self) { group in
            for metric in HealthMetric.all {
                group.addTask { (metric.id, try? await service.currentValue(for: metric)) }
            }
            var result: [HKQuantityTypeIdentifier: MetricValue] = [:]
            for await (id, value) in group {
                result[id] = value
            }
            return result
        }
    }

    func trend(for metric: HealthMetric, days: Int) async -> [TrendPoint] {
        if demoMode { return DemoData.trend(for: metric, days: days) }
        return (try? await service.dailyTrend(for: metric, days: days)) ?? []
    }
}
