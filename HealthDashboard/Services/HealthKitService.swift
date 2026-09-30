import HealthKit

/// HealthKit 조회를 담당. 앱은 데이터를 읽기만 하고 쓰지 않는다.
final class HealthKitService: @unchecked Sendable {
    private let store = HKHealthStore()
    private let calendar = Calendar.current

    static var isAvailable: Bool { HKHealthStore.isHealthDataAvailable() }

    private var readTypes: Set<HKObjectType> {
        var types = Set<HKObjectType>(HealthMetric.all.map { $0.type as HKObjectType })
        types.insert(HKCategoryType(.sleepAnalysis))
        types.insert(HKCategoryType(.mindfulSession))
        types.insert(HKObjectType.workoutType())
        types.insert(HKObjectType.activitySummaryType())
        return types
    }

    /// 아직 허용 여부를 묻지 않은 항목이 있을 때만 시스템 권한 화면이 나타난다.
    func requestAuthorization() async throws {
        try await store.requestAuthorization(toShare: [], read: readTypes)
    }

    // MARK: - 수량 지표

    /// 누적 지표는 오늘 합계, 측정 지표는 가장 최근 측정값
    func currentValue(for metric: HealthMetric) async throws -> MetricValue? {
        switch metric.aggregation {
        case .cumulative:
            let now = Date.now
            let predicate = HKQuery.predicateForSamples(withStart: calendar.startOfDay(for: now), end: now)
            let descriptor = HKStatisticsQueryDescriptor(
                predicate: .quantitySample(type: metric.type, predicate: predicate),
                options: .cumulativeSum
            )
            guard let sum = try await descriptor.result(for: store)?.sumQuantity() else { return nil }
            return MetricValue(value: metric.displayValue(sum), date: now)

        case .discrete:
            let descriptor = HKSampleQueryDescriptor(
                predicates: [.quantitySample(type: metric.type)],
                sortDescriptors: [SortDescriptor(\.endDate, order: .reverse)],
                limit: 1
            )
            guard let sample = try await descriptor.result(for: store).first else { return nil }
            return MetricValue(value: metric.displayValue(sample.quantity), date: sample.endDate)
        }
    }

    /// 최근 `days`일 동안의 일별 합계(누적) 또는 평균/최저/최고(측정)
    func dailyTrend(for metric: HealthMetric, days: Int) async throws -> [TrendPoint] {
        let end = Date.now
        let start = calendar.date(byAdding: .day, value: -(days - 1), to: calendar.startOfDay(for: end))!
        let predicate = HKQuery.predicateForSamples(withStart: start, end: end)
        let options: HKStatisticsOptions = metric.aggregation == .cumulative
            ? .cumulativeSum
            : [.discreteAverage, .discreteMin, .discreteMax]

        let descriptor = HKStatisticsCollectionQueryDescriptor(
            predicate: .quantitySample(type: metric.type, predicate: predicate),
            options: options,
            anchorDate: start,
            intervalComponents: DateComponents(day: 1)
        )
        let collection = try await descriptor.result(for: store)

        var points: [TrendPoint] = []
        collection.enumerateStatistics(from: start, to: end) { statistics, _ in
            switch metric.aggregation {
            case .cumulative:
                guard let sum = statistics.sumQuantity() else { return }
                points.append(TrendPoint(date: statistics.startDate, value: metric.displayValue(sum)))
            case .discrete:
                guard let average = statistics.averageQuantity() else { return }
                points.append(TrendPoint(
                    date: statistics.startDate,
                    value: metric.displayValue(average),
                    min: statistics.minimumQuantity().map(metric.displayValue),
                    max: statistics.maximumQuantity().map(metric.displayValue)
                ))
            }
        }
        return points
    }

    // MARK: - 활동 링

    func dailyActivity(days: Int) async throws -> [DailyActivity] {
        let end = Date.now
        let start = calendar.date(byAdding: .day, value: -(days - 1), to: calendar.startOfDay(for: end))!
        let predicate = HKQuery.predicate(
            forActivitySummariesBetweenStart: dateComponents(for: start),
            end: dateComponents(for: end)
        )

        let summaries: [HKActivitySummary] = try await withCheckedThrowingContinuation { continuation in
            let query = HKActivitySummaryQuery(predicate: predicate) { _, summaries, error in
                if let error {
                    continuation.resume(throwing: error)
                } else {
                    continuation.resume(returning: summaries ?? [])
                }
            }
            store.execute(query)
        }

        return summaries
            .compactMap { summary in
                calendar.date(from: summary.dateComponents(for: calendar))
                    .map { DailyActivity(date: $0, summary: summary) }
            }
            .sorted { $0.date < $1.date }
    }

    private func dateComponents(for date: Date) -> DateComponents {
        var components = calendar.dateComponents([.era, .year, .month, .day], from: date)
        components.calendar = calendar
        return components
    }

    // MARK: - 수면

    func sleepNights(days: Int) async throws -> [SleepNight] {
        // 전날 저녁에 잠든 기록까지 포함하도록 정오부터 조회
        let start = calendar.date(byAdding: .hour, value: -(days * 24 + 12), to: calendar.startOfDay(for: .now))!
        let descriptor = HKSampleQueryDescriptor(
            predicates: [.categorySample(
                type: HKCategoryType(.sleepAnalysis),
                predicate: HKQuery.predicateForSamples(withStart: start, end: .now)
            )],
            sortDescriptors: [SortDescriptor(\.startDate)]
        )
        let samples = try await descriptor.result(for: store)

        // 정오 기준으로 '어느 날 밤'의 수면인지 묶는다.
        let byNight = Dictionary(grouping: samples) {
            calendar.startOfDay(for: $0.startDate.addingTimeInterval(-12 * 3600))
        }
        return byNight
            .map { night, samples in makeNight(night: night, samples: preferredSource(samples)) }
            .filter { $0.asleep > 0 || $0.inBed > 0 }
            .sorted { $0.night < $1.night }
    }

    /// 아이폰과 애플워치가 같은 밤을 중복 기록하는 경우가 있어,
    /// 수면 단계가 있는(=애플워치) 소스를 우선으로 한 소스만 사용한다.
    private func preferredSource(_ samples: [HKCategorySample]) -> [HKCategorySample] {
        let bySource = Dictionary(grouping: samples) { $0.sourceRevision.source.bundleIdentifier }
        let best = bySource.values.max { lhs, rhs in
            let l = makeNight(night: .now, samples: lhs)
            let r = makeNight(night: .now, samples: rhs)
            return (l.hasStages ? 1 : 0, l.asleep, l.inBed) < (r.hasStages ? 1 : 0, r.asleep, r.inBed)
        }
        return best ?? samples
    }

    private func makeNight(night: Date, samples: [HKCategorySample]) -> SleepNight {
        var result = SleepNight(night: night)
        for sample in samples {
            let duration = sample.endDate.timeIntervalSince(sample.startDate)
            guard let value = HKCategoryValueSleepAnalysis(rawValue: sample.value) else { continue }

            let stage: SleepStage?
            switch value {
            case .inBed:
                result.inBed += duration
                stage = nil
            case .awake: stage = .awake
            case .asleepCore: stage = .core
            case .asleepDeep: stage = .deep
            case .asleepREM: stage = .rem
            case .asleepUnspecified: stage = .unspecified
            @unknown default: stage = nil
            }

            if let stage {
                result.durations[stage, default: 0] += duration
                if stage != .awake {
                    result.bedtime = min(result.bedtime ?? sample.startDate, sample.startDate)
                    result.wakeTime = max(result.wakeTime ?? sample.endDate, sample.endDate)
                }
            }
        }
        return result
    }

    // MARK: - 마음챙김 / 운동

    func mindfulMinutesToday() async throws -> Double {
        let predicate = HKQuery.predicateForSamples(withStart: calendar.startOfDay(for: .now), end: .now)
        let descriptor = HKSampleQueryDescriptor(
            predicates: [.categorySample(type: HKCategoryType(.mindfulSession), predicate: predicate)],
            sortDescriptors: []
        )
        let samples = try await descriptor.result(for: store)
        return samples.reduce(0) { $0 + $1.endDate.timeIntervalSince($1.startDate) } / 60
    }

    func recentWorkouts(limit: Int) async throws -> [WorkoutItem] {
        let descriptor = HKSampleQueryDescriptor(
            predicates: [.workout()],
            sortDescriptors: [SortDescriptor(\.endDate, order: .reverse)],
            limit: limit
        )
        return try await descriptor.result(for: store).map(WorkoutItem.init)
    }
}
