import HealthKit
import Observation

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
    private(set) var sleepNights: [SleepNight] = []
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
            sleepNights = DemoData.sleepNights(days: 14)
            mindfulMinutesToday = 10
            workouts = DemoData.workouts()
            lastUpdated = .now
            return
        }

        let service = self.service
        async let values = Self.fetchValues(service)
        async let activity = try? service.dailyActivity(days: 7)
        async let sleep = try? service.sleepNights(days: 14)
        async let mindful = try? service.mindfulMinutesToday()
        async let workouts = try? service.recentWorkouts(limit: 30)

        self.values = await values
        self.activity = await activity ?? []
        self.sleepNights = await sleep ?? []
        self.mindfulMinutesToday = await mindful ?? 0
        self.workouts = await workouts ?? []
        lastUpdated = .now
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
