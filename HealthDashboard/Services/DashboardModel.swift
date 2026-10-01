import HealthKit
import Observation
import UIKit
import UserNotifications
import WidgetKit

@MainActor
@Observable
final class DashboardModel {
    /// 백그라운드 갱신(HealthKit 백그라운드 전달)에서도 같은 인스턴스를 쓰기 위해 하나만 만든다.
    static let shared = DashboardModel()

    private enum Keys {
        static let demoMode = "demoMode"
        static let requested = "hasRequestedAuthorization"
        static let showEmpty = "showEmptyMetrics"
    }

    private let service = HealthKitService()

    private(set) var values: [HKQuantityTypeIdentifier: MetricValue] = [:]
    private(set) var activity: [DailyActivity] = []
    /// 준비 점수 기준선 · 습관 분석을 위해 45일치를 보관한다.
    private(set) var sleepNights: [SleepNight] = []
    /// 최근 30일 준비 점수 (오래된 날 → 오늘)
    private(set) var readiness: [ReadinessScore] = []
    /// 준비 점수 계산에 쓴 원본 데이터 (조기 경보 · 습관 분석 · AI 코치용)
    private(set) var readinessInputs: ReadinessInputs?
    private(set) var earlyWarning: EarlyWarning?
    private(set) var mindfulMinutesToday: Double = 0
    private(set) var workouts: [WorkoutItem] = []
    private(set) var yesterdaySteps: Double?
    private(set) var isLoading = false
    private(set) var lastUpdated: Date?
    var errorMessage: String?
    private var backgroundDeliveryStarted = false

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

    var recentReadiness: [ReadinessScore] { Array(readiness.suffix(7)) }

    /// 최근 30일 습관 분석
    func habitInsights(_ habits: HabitStore) -> [HabitInsight] {
        HabitAnalyzer.insights(
            habits: habits,
            readiness: readiness,
            nights: sleepNights,
            hrv: readinessInputs?.hrv ?? [],
            workouts: workouts
        )
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
        startBackgroundUpdates()
        await refresh()
    }

    /// 앱 실행 시 한 번 호출. 워치 데이터가 들어올 때마다 위젯·알림 내용을 갱신한다.
    func startBackgroundUpdates() {
        guard hasRequestedAuthorization, !demoMode, !backgroundDeliveryStarted else { return }
        backgroundDeliveryStarted = true
        service.startBackgroundDelivery { [weak self] in
            await self?.backgroundUpdate()
        }
    }

    /// 여러 종류의 데이터가 한꺼번에 들어와도 5분에 한 번만 새로고침한다.
    /// (새 수면 · HRV 데이터일 수 있으므로 무거운 계산까지 다시 한다)
    private func backgroundUpdate() async {
        if let lastUpdated, Date.now.timeIntervalSince(lastUpdated) < 5 * 60 { return }
        await refresh(force: true)
        await WeeklyReportStore.shared.generateIfNeeded()
    }

    /// 무거운 계산(45일 수면 · 준비 점수 · 운동 기록)을 마지막으로 한 시각
    @ObservationIgnored private var lastHeavyRefresh: Date?
    /// 공식 점수 보정 전 준비 점수
    @ObservationIgnored private var rawReadiness: [ReadinessScore] = []

    /// 오늘 값(걸음 · 칼로리 · 활동 링)은 매번, 무거운 계산은 15분에 한 번만 다시 한다.
    /// 당겨서 새로고침하거나 워치 데이터가 새로 들어오면 `force`로 전부 다시 계산한다.
    func refresh(force: Bool = false) async {
        guard !isLoading else { return }
        isLoading = true
        defer { isLoading = false }

        if demoMode {
            values = DemoData.values()
            activity = DemoData.dailyActivity(days: 7)
            sleepNights = DemoData.sleepNights(days: ReadinessInputs.lookbackDays)
            let inputs = DemoData.readinessInputs()
            readinessInputs = inputs
            rawReadiness = ReadinessCalculator.history(days: 30, inputs: inputs)
            readiness = ReadinessCalibration.apply(rawReadiness)
            earlyWarning = nil
            mindfulMinutesToday = 10
            workouts = DemoData.workouts()
            yesterdaySteps = 7_920
        } else {
            // 기기가 잠겨 있으면 건강 데이터를 읽을 수 없다. 빈 값으로 덮어쓰지 않고 알림만 갱신한다.
            guard UIApplication.shared.isProtectedDataAvailable else {
                await BriefingScheduler.reschedule()
                return
            }

            await flushPendingWater()

            let heavyDue = force
                || readinessInputs == nil
                || lastHeavyRefresh.map { Date.now.timeIntervalSince($0) > 15 * 60 || !Calendar.current.isDateInToday($0) } ?? true

            let service = self.service
            async let values = Self.fetchValues(service)
            async let activity = try? service.dailyActivity(days: 7)
            async let mindful = try? service.mindfulMinutesToday()
            async let steps = Self.stepsByDay(service)

            self.values = await values
            self.activity = await activity ?? []
            self.mindfulMinutesToday = await mindful ?? 0
            self.yesterdaySteps = await steps.first { Calendar.current.isDateInYesterday($0.date) }?.value

            if heavyDue {
                async let sleep = try? service.sleepNights(days: ReadinessInputs.lookbackDays)
                async let workouts = try? service.recentWorkouts(limit: 60)
                self.sleepNights = await sleep ?? []
                self.workouts = await workouts ?? []

                let inputs = await service.readinessInputs(sleepNights: sleepNights)
                readinessInputs = inputs
                rawReadiness = ReadinessCalculator.history(days: 30, inputs: inputs)
                readiness = ReadinessCalibration.apply(rawReadiness)
                earlyWarning = EarlyWarningDetector.evaluate(inputs: inputs)
                lastHeavyRefresh = .now
                await notifyEarlyWarningIfNeeded()
            }
        }

        lastUpdated = .now
        publishSnapshot()
        await BriefingScheduler.reschedule()
        await MedicationStore.shared.reschedule()
        await LifeAlerts.reschedule()
    }

    // MARK: - 물 · 호흡

    /// 오늘 물 목표: 기본 2L, 운동한 날 +0.5L, 낮 최고 28° 이상이면 +0.3L
    var waterGoal: Double {
        var goal = 2000.0
        if workouts.contains(where: { Calendar.current.isDateInToday($0.start) }) { goal += 500 }
        if let high = SharedStore.defaults.object(forKey: SharedStore.todayHighKey) as? Double, high >= 28 { goal += 300 }
        return goal
    }

    /// 오늘 마신 물 (건강 앱 + 아직 옮기지 않은 위젯 기록, ml)
    var waterToday: Double {
        (values[.dietaryWater]?.value ?? 0) * 1000
            + WaterLog.pending.filter { Calendar.current.isDateInToday($0.date) }.reduce(0) { $0 + $1.ml }
    }

    func addWater(ml: Double) async {
        if demoMode { return }
        do {
            try await service.requestAuthorization()
            try await service.saveWater(ml: ml, at: .now)
        } catch {
            // 저장하지 못하면 나중에 다시 시도하도록 남겨 둔다.
            WaterLog.add(ml: ml)
        }
        await refresh()
    }

    /// 위젯 · Siri로 기록한 물을 건강 앱에 옮겨 저장한다.
    private func flushPendingWater() async {
        let entries = WaterLog.takePending()
        guard !entries.isEmpty else { return }
        var failed: [WaterLog.Entry] = []
        for entry in entries {
            do {
                try await service.saveWater(ml: entry.ml, at: entry.date)
            } catch {
                failed.append(entry)
            }
        }
        if !failed.isEmpty { WaterLog.pending = failed + WaterLog.pending }
    }

    func saveBreathing(start: Date, end: Date) async {
        guard !demoMode else { return }
        try? await service.requestAuthorization()
        try? await service.saveMindfulSession(start: start, end: end)
        await refresh()
    }

    // MARK: - 준비 점수 보정

    /// 보정에 쓰인 공식 점수 개수
    var calibrationCount: Int { ReadinessCalibration.pairCount(raw: rawReadiness) }

    /// 애플워치 공식 준비 점수를 입력(또는 삭제)하고 바로 다시 보정한다.
    func setOfficialReadiness(_ score: Double?, on date: Date = .now) {
        ReadinessCalibration.setOfficial(score, on: date)
        readiness = ReadinessCalibration.apply(rawReadiness)
        publishSnapshot()
    }

    /// 경고가 새로 생기면 하루 한 번만 알림을 보낸다.
    private func notifyEarlyWarningIfNeeded() async {
        guard let warning = earlyWarning, SharedStore.earlyWarningEnabled else { return }
        let key = "earlyWarningNotifiedDay"
        let today = HabitStore.dayKey(.now)
        guard UserDefaults.standard.string(forKey: key) != today else { return }
        // 알림 권한이 없으면 대시보드 카드로만 보여준다.
        guard await UNUserNotificationCenter.current().notificationSettings().authorizationStatus == .authorized else { return }

        let content = UNMutableNotificationContent()
        content.title = "⚠️ \(warning.title)"
        content.body = (warning.signals + [warning.message]).joined(separator: "\n")
        content.sound = .default
        let request = UNNotificationRequest(identifier: "early-warning-\(today)", content: content, trigger: nil)
        if (try? await UNUserNotificationCenter.current().add(request)) != nil {
            UserDefaults.standard.set(today, forKey: key)
        }
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
            isDemo: demoMode,
            highlights: readiness?.highlights,
            yesterdaySteps: yesterdaySteps,
            yesterdayMove: yesterday?.move,
            yesterdayMoveGoal: yesterday?.moveGoal,
            yesterdayCalories: yesterdayNutrition?.calories,
            yesterdayProtein: yesterdayNutrition?.protein,
            waterMl: (values[.dietaryWater]?.value ?? 0) * 1000,
            waterGoal: waterGoal,
            tonightDish: KitchenStore.shared.plan(on: .now)?.dish,
            sleepSummary: lastNight.flatMap { Calendar.current.isDateInToday($0.wakeDate) ? $0.asleep.hoursMinutesText : nil }
        )
        WidgetCenter.shared.reloadAllTimelines()
    }

    private var yesterdayNutrition: NutritionTotals? {
        guard let date = Calendar.current.date(byAdding: .day, value: -1, to: .now),
              !MealStore.shared.meals(on: date).isEmpty else { return nil }
        return MealStore.shared.totals(on: date)
    }

    private var yesterday: DailyActivity? {
        activity.first { Calendar.current.isDateInYesterday($0.date) }
    }

    private nonisolated static func stepsByDay(_ service: HealthKitService) async -> [TrendPoint] {
        guard let metric = HealthMetric.metric(.stepCount) else { return [] }
        return (try? await service.dailyTrend(for: metric, days: 2)) ?? []
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
