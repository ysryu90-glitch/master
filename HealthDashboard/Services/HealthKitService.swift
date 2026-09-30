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
        for type in Self.dietaryWriteTypes { types.insert(HKQuantityType(type)) }
        return types
    }

    /// 식단 기록을 건강 앱에 저장할 때 쓰는 항목 (이 앱이 '쓰는' 유일한 데이터)
    static let dietaryWriteTypes: [HKQuantityTypeIdentifier] = [
        .dietaryEnergyConsumed, .dietaryCarbohydrates, .dietaryProtein,
        .dietaryFatTotal, .dietarySugar, .dietarySodium,
    ]

    private var shareTypes: Set<HKSampleType> {
        Set(Self.dietaryWriteTypes.map { HKQuantityType($0) as HKSampleType })
    }

    /// 아직 허용 여부를 묻지 않은 항목이 있을 때만 시스템 권한 화면이 나타난다.
    func requestAuthorization() async throws {
        try await store.requestAuthorization(toShare: shareTypes, read: readTypes)
    }

    // MARK: - 식단 저장

    /// 앱 기록과 건강 앱 기록을 연결하는 메타데이터 키
    static let mealIDKey = "HealthDashboardMealID"

    /// 한 끼를 건강 앱에 '음식' 기록(영양소 묶음)으로 저장한다.
    func saveMeal(_ meal: MealEntry) async throws {
        let totals = meal.totals
        let metadata: [String: Any] = [HKMetadataKeyFoodType: meal.title, Self.mealIDKey: meal.id.uuidString]
        let values: [(HKQuantityTypeIdentifier, Double, HKUnit)] = [
            (.dietaryEnergyConsumed, totals.calories, .kilocalorie()),
            (.dietaryCarbohydrates, totals.carbohydrates, .gram()),
            (.dietaryProtein, totals.protein, .gram()),
            (.dietaryFatTotal, totals.fat, .gram()),
            (.dietarySugar, totals.sugar, .gram()),
            (.dietarySodium, totals.sodium, .gramUnit(with: .milli)),
        ]
        let samples: Set<HKSample> = Set(values.compactMap { (id, value, unit) -> HKSample? in
            guard value > 0 else { return nil }
            return HKQuantitySample(
                type: HKQuantityType(id),
                quantity: HKQuantity(unit: unit, doubleValue: value),
                start: meal.date, end: meal.date, metadata: metadata
            )
        })
        guard !samples.isEmpty else { return }
        let food = HKCorrelation(
            type: HKCorrelationType(.food), start: meal.date, end: meal.date,
            objects: samples, metadata: metadata
        )
        try await store.save(food)
    }

    /// 이 앱이 저장한 해당 끼니 기록을 건강 앱에서 지운다.
    func deleteMeal(id: UUID) async {
        let predicate = HKQuery.predicateForObjects(withMetadataKey: Self.mealIDKey, allowedValues: [id.uuidString])
        _ = try? await store.deleteObjects(of: HKCorrelationType(.food), predicate: predicate)
        for type in Self.dietaryWriteTypes {
            _ = try? await store.deleteObjects(of: HKQuantityType(type), predicate: predicate)
        }
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

    // MARK: - 준비 점수 입력

    /// 애플 준비 점수처럼 '잠자는 동안'의 HRV와 심박수를 우선 사용한다.
    /// 수면 기록이 1주일 미만이면 하루 평균 HRV와 안정 시 심박수로 대신한다.
    func readinessInputs(sleepNights: [SleepNight]) async -> ReadinessInputs {
        let days = ReadinessInputs.lookbackDays
        async let overnightRMSSD = overnightAverages(.hrvRMSSD, nights: sleepNights)
        async let overnightSDNN = overnightAverages(.heartRateVariabilitySDNN, nights: sleepNights)
        async let sleepingHeartRate = overnightAverages(.heartRate, nights: sleepNights)
        async let dailyHRV = hrvTrend(days: days)
        async let restingHeartRate = trend(.restingHeartRate, days: days)
        async let activeEnergy = trend(.activeEnergyBurned, days: days)
        async let wristTemperature = trend(.appleSleepingWristTemperature, days: days)
        async let respiratoryRate = trend(.respiratoryRate, days: days)

        var inputs = ReadinessInputs(
            activeEnergy: await activeEnergy,
            wristTemperature: await wristTemperature,
            respiratoryRate: await respiratoryRate,
            sleepNights: sleepNights
        )

        let rmssd = await overnightRMSSD
        let sdnn = await overnightSDNN
        if rmssd.count >= 7 {
            inputs.hrv = rmssd
            inputs.hrvIsOvernight = true
        } else if sdnn.count >= 7 {
            inputs.hrv = sdnn
            inputs.hrvIsOvernight = true
        } else {
            inputs.hrv = await dailyHRV
        }

        let sleepingHR = await sleepingHeartRate
        if sleepingHR.count >= 7 {
            inputs.restingHeartRate = sleepingHR
            inputs.heartRateIsSleeping = true
        } else {
            inputs.restingHeartRate = await restingHeartRate
        }
        return inputs
    }

    /// 밤마다 잠든 시각~깬 시각 사이의 평균값. 날짜는 일어난 날로 기록한다.
    private func overnightAverages(_ id: HKQuantityTypeIdentifier, nights: [SleepNight]) async -> [TrendPoint] {
        guard let metric = HealthMetric.metric(id) else { return [] }
        let store = self.store
        return await withTaskGroup(of: TrendPoint?.self) { group in
            for night in nights {
                guard let start = night.bedtime, let end = night.wakeTime, end > start else { continue }
                let wakeDay = night.wakeDate
                group.addTask {
                    let descriptor = HKStatisticsQueryDescriptor(
                        predicate: .quantitySample(
                            type: metric.type,
                            predicate: HKQuery.predicateForSamples(withStart: start, end: end)
                        ),
                        options: .discreteAverage
                    )
                    guard let average = try? await descriptor.result(for: store)?.averageQuantity() else { return nil }
                    return TrendPoint(date: wakeDay, value: metric.displayValue(average))
                }
            }
            var points: [TrendPoint] = []
            for await point in group {
                if let point { points.append(point) }
            }
            return points.sorted { $0.date < $1.date }
        }
    }

    // MARK: - 백그라운드 갱신

    /// 워치에서 새 데이터가 들어오면 iOS가 앱을 백그라운드로 깨워 `onUpdate`를 호출한다.
    /// (iOS 정책상 대부분 최대 1시간에 한 번)
    func startBackgroundDelivery(onUpdate: @escaping @Sendable () async -> Void) {
        let types: [HKSampleType] = [
            HKQuantityType(.stepCount),
            HKQuantityType(.activeEnergyBurned),
            HKQuantityType(.appleExerciseTime),
            HKQuantityType(.appleStandTime),
            HKQuantityType(.restingHeartRate),
            HKQuantityType(.heartRateVariabilitySDNN),
            HKCategoryType(.sleepAnalysis),
        ]
        for type in types {
            let query = HKObserverQuery(sampleType: type, predicate: nil) { _, completion, error in
                guard error == nil else {
                    completion()
                    return
                }
                Task {
                    await onUpdate()
                    completion()
                }
            }
            store.execute(query)
            store.enableBackgroundDelivery(for: type, frequency: .hourly) { _, _ in }
        }
    }

    /// iOS 27 + 애플워치 Series 12의 RMSSD 기록이 충분하면 그것을, 아니면 기존 SDNN을 사용
    private func hrvTrend(days: Int) async -> [TrendPoint] {
        let rmssd = await trend(.hrvRMSSD, days: days)
        if rmssd.count >= 7 { return rmssd }
        return await trend(.heartRateVariabilitySDNN, days: days)
    }

    private func trend(_ id: HKQuantityTypeIdentifier, days: Int) async -> [TrendPoint] {
        guard let metric = HealthMetric.metric(id) else { return [] }
        return (try? await dailyTrend(for: metric, days: days)) ?? []
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
