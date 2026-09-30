import HealthKit

/// 시뮬레이터나 데이터가 없는 기기에서 화면을 확인하기 위한 샘플 데이터
enum DemoData {
    private static let baseValues: [HKQuantityTypeIdentifier: Double] = [
        .stepCount: 8_420, .distanceWalkingRunning: 6.12, .activeEnergyBurned: 486,
        .basalEnergyBurned: 1_620, .appleExerciseTime: 38, .appleStandTime: 142,
        .flightsClimbed: 9, .distanceCycling: 4.3, .timeInDaylight: 64,
        .heartRate: 72, .restingHeartRate: 58, .walkingHeartRateAverage: 96,
        .heartRateVariabilitySDNN: 46, .heartRateRecoveryOneMinute: 28, .vo2Max: 41.2,
        .oxygenSaturation: 97, .respiratoryRate: 14.6,
        .appleSleepingWristTemperature: 35.42, .bodyTemperature: 36.5,
        .bloodPressureSystolic: 118, .bloodPressureDiastolic: 76, .bloodGlucose: 94,
        .bodyMass: 71.4, .bodyMassIndex: 23.1, .bodyFatPercentage: 18.2,
        .leanBodyMass: 58.4, .height: 176,
        .walkingSpeed: 4.9, .walkingStepLength: 71, .walkingAsymmetryPercentage: 1.8,
        .walkingDoubleSupportPercentage: 27.4, .appleWalkingSteadiness: 92, .sixMinuteWalkTestDistance: 560,
        .environmentalAudioExposure: 64, .headphoneAudioExposure: 71,
        .dietaryWater: 1.6, .dietaryEnergyConsumed: 2_050, .dietaryCaffeine: 190,
    ]

    /// 날짜별로 조금씩 달라지지만 매번 같은 값이 나오도록 하는 변동폭
    private static func wobble(_ seed: Int, _ day: Int, amount: Double) -> Double {
        1 + amount * sin(Double(day) * 1.7 + Double(seed % 97))
    }

    private static func variation(for metric: HealthMetric) -> Double {
        switch metric.category {
        case .activity, .nutrition: 0.3
        case .body: 0.01
        default: 0.06
        }
    }

    static func values() -> [HKQuantityTypeIdentifier: MetricValue] {
        var result: [HKQuantityTypeIdentifier: MetricValue] = [:]
        for (index, metric) in HealthMetric.all.enumerated() {
            guard let base = baseValues[metric.id] else { continue }
            let date = metric.aggregation == .cumulative ? Date.now : Date.now.addingTimeInterval(-Double(index * 900))
            result[metric.id] = MetricValue(value: base, date: date)
        }
        return result
    }

    static func trend(for metric: HealthMetric, days: Int) -> [TrendPoint] {
        guard let base = baseValues[metric.id] else { return [] }
        let calendar = Calendar.current
        let today = calendar.startOfDay(for: .now)
        let seed = metric.id.rawValue.unicodeScalars.reduce(0) { $0 + Int($1.value) }
        let amount = variation(for: metric)

        return (0..<days).reversed().compactMap { offset in
            guard let date = calendar.date(byAdding: .day, value: -offset, to: today) else { return nil }
            let value = base * wobble(seed, offset, amount: amount)
            switch metric.aggregation {
            case .cumulative:
                return TrendPoint(date: date, value: value)
            case .discrete:
                let spread = metric.id == .heartRate ? 0.35 : amount
                return TrendPoint(date: date, value: value, min: value * (1 - spread), max: value * (1 + spread))
            }
        }
    }

    static func dailyActivity(days: Int) -> [DailyActivity] {
        let calendar = Calendar.current
        let today = calendar.startOfDay(for: .now)
        return (0..<days).reversed().compactMap { offset in
            guard let date = calendar.date(byAdding: .day, value: -offset, to: today) else { return nil }
            let summary = HKActivitySummary()
            summary.activeEnergyBurned = HKQuantity(unit: .kilocalorie(), doubleValue: 486 * wobble(11, offset, amount: 0.35))
            summary.activeEnergyBurnedGoal = HKQuantity(unit: .kilocalorie(), doubleValue: 500)
            summary.appleExerciseTime = HKQuantity(unit: .minute(), doubleValue: 38 * wobble(23, offset, amount: 0.5))
            summary.exerciseTimeGoal = HKQuantity(unit: .minute(), doubleValue: 30)
            summary.appleStandHours = HKQuantity(unit: .count(), doubleValue: (10 * wobble(5, offset, amount: 0.25)).rounded())
            summary.standHoursGoal = HKQuantity(unit: .count(), doubleValue: 12)
            return DailyActivity(date: date, summary: summary)
        }
    }

    static func sleepNights(days: Int) -> [SleepNight] {
        let calendar = Calendar.current
        let today = calendar.startOfDay(for: .now)
        return (1...days).reversed().compactMap { offset in
            guard let night = calendar.date(byAdding: .day, value: -offset, to: today) else { return nil }
            let factor = wobble(3, offset, amount: 0.12)
            var result = SleepNight(night: night)
            result.durations = [
                .core: 3.6 * 3600 * factor,
                .deep: 1.0 * 3600 * factor,
                .rem: 1.6 * 3600 * factor,
                .awake: 0.3 * 3600,
            ]
            result.inBed = result.asleep + result.duration(.awake)
            result.bedtime = calendar.date(bySettingHour: 23, minute: 20, second: 0, of: night)
            result.wakeTime = result.bedtime?.addingTimeInterval(result.inBed)
            return result
        }
    }

    static func workouts() -> [WorkoutItem] {
        let now = Date.now
        return [
            WorkoutItem(id: UUID(), activityType: .running, start: now.addingTimeInterval(-3600 * 5),
                        duration: 32 * 60, energy: 342, distanceKm: 5.2, averageHeartRate: 152),
            WorkoutItem(id: UUID(), activityType: .traditionalStrengthTraining, start: now.addingTimeInterval(-3600 * 29),
                        duration: 48 * 60, energy: 260, averageHeartRate: 118),
            WorkoutItem(id: UUID(), activityType: .walking, start: now.addingTimeInterval(-3600 * 52),
                        duration: 41 * 60, energy: 168, distanceKm: 3.4, averageHeartRate: 104),
            WorkoutItem(id: UUID(), activityType: .cycling, start: now.addingTimeInterval(-3600 * 77),
                        duration: 55 * 60, energy: 410, distanceKm: 18.6, averageHeartRate: 138),
            WorkoutItem(id: UUID(), activityType: .yoga, start: now.addingTimeInterval(-3600 * 100),
                        duration: 30 * 60, energy: 95, averageHeartRate: 88),
        ]
    }
}
