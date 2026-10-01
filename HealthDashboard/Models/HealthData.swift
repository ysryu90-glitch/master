import HealthKit
import SwiftUI

/// 대시보드 카드에 표시할 현재 값
struct MetricValue: Sendable {
    let value: Double
    /// 누적 지표는 조회 시각, 측정 지표는 마지막 측정 시각
    let date: Date
}

/// 추세 차트의 하루치 데이터
struct TrendPoint: Identifiable, Sendable {
    let date: Date
    let value: Double
    var min: Double?
    var max: Double?

    var id: Date { date }
}

/// 하루치 활동 링 (움직이기 / 운동하기 / 일어서기)
struct DailyActivity: Identifiable {
    let date: Date
    let summary: HKActivitySummary

    var id: Date { date }

    var move: Double { summary.activeEnergyBurned.doubleValue(for: .kilocalorie()) }
    var moveGoal: Double { summary.activeEnergyBurnedGoal.doubleValue(for: .kilocalorie()) }

    var exercise: Double { summary.appleExerciseTime.doubleValue(for: .minute()) }
    var exerciseGoal: Double {
        let goal: HKQuantity? = summary.exerciseTimeGoal
        return goal?.doubleValue(for: .minute()) ?? 30
    }

    var stand: Double { summary.appleStandHours.doubleValue(for: .count()) }
    var standGoal: Double {
        let goal: HKQuantity? = summary.standHoursGoal
        return goal?.doubleValue(for: .count()) ?? 12
    }
}

enum SleepStage: String, CaseIterable, Identifiable {
    case awake, rem, core, deep, unspecified

    var id: String { rawValue }

    var title: String {
        switch self {
        case .awake: "깨어 있음"
        case .rem: "렘 수면"
        case .core: "코어 수면"
        case .deep: "깊은 수면"
        case .unspecified: "수면"
        }
    }

    var color: Color {
        switch self {
        case .awake: .orange
        case .rem: .cyan
        case .core: .blue
        case .deep: .indigo
        case .unspecified: .teal
        }
    }
}

struct SleepSegment: Identifiable {
    let start: Date
    let end: Date
    let stage: SleepStage
    let id = UUID()
}

/// 하룻밤의 수면 요약
struct SleepNight: Identifiable {
    /// 잠든 날(저녁 기준) 00:00
    let night: Date
    var inBed: TimeInterval = 0
    var durations: [SleepStage: TimeInterval] = [:]
    var bedtime: Date?
    var wakeTime: Date?
    /// 밤새 단계 흐름 (수면 흐름 그래프용)
    var segments: [SleepSegment] = []

    var id: Date { night }

    /// 일어난 날짜 (건강 앱과 같은 기준)
    var wakeDate: Date { Calendar.current.date(byAdding: .day, value: 1, to: night) ?? night }

    func duration(_ stage: SleepStage) -> TimeInterval { durations[stage, default: 0] }

    var asleep: TimeInterval {
        SleepStage.allCases.filter { $0 != .awake }.reduce(0) { $0 + duration($1) }
    }

    var hasStages: Bool { duration(.core) + duration(.deep) + duration(.rem) > 0 }

    /// 차트/막대에 그릴 단계 (단계 정보가 없으면 '수면' 하나로 표시)
    var displayStages: [SleepStage] {
        hasStages ? [.deep, .core, .rem, .awake] : [.unspecified, .awake]
    }
}

struct WorkoutItem: Identifiable {
    let id: UUID
    let activityType: HKWorkoutActivityType
    let start: Date
    let duration: TimeInterval
    var energy: Double?
    var distanceKm: Double?
    var averageHeartRate: Double?
}

extension WorkoutItem {
    init(_ workout: HKWorkout) {
        let distanceTypes: [HKQuantityTypeIdentifier] = [
            .distanceWalkingRunning, .distanceCycling, .distanceSwimming,
            .distanceWheelchair, .distanceDownhillSnowSports,
        ]
        self.init(
            id: workout.uuid,
            activityType: workout.workoutActivityType,
            start: workout.startDate,
            duration: workout.duration,
            energy: workout.statistics(for: HKQuantityType(.activeEnergyBurned))?
                .sumQuantity()?.doubleValue(for: .kilocalorie()),
            distanceKm: distanceTypes.lazy
                .compactMap { workout.statistics(for: HKQuantityType($0))?.sumQuantity()?.doubleValue(for: .kilometer) }
                .first,
            averageHeartRate: workout.statistics(for: HKQuantityType(.heartRate))?
                .averageQuantity()?.doubleValue(for: .beatsPerMinute)
        )
    }
}

extension HKWorkoutActivityType {
    var displayName: String {
        switch self {
        case .running: "달리기"
        case .walking: "걷기"
        case .cycling: "사이클"
        case .swimming: "수영"
        case .hiking: "하이킹"
        case .yoga: "요가"
        case .pilates: "필라테스"
        case .functionalStrengthTraining: "기능성 근력 운동"
        case .traditionalStrengthTraining: "전통적인 근력 운동"
        case .highIntensityIntervalTraining: "고강도 인터벌 트레이닝"
        case .coreTraining: "코어 트레이닝"
        case .elliptical: "일립티컬"
        case .rowing: "로잉"
        case .stairClimbing: "계단 오르기"
        case .dance: "댄스"
        case .soccer: "축구"
        case .basketball: "농구"
        case .tennis: "테니스"
        case .golf: "골프"
        case .badminton: "배드민턴"
        case .tableTennis: "탁구"
        case .mindAndBody: "마음과 몸"
        case .cooldown: "쿨다운"
        case .mixedCardio: "혼합 유산소"
        case .jumpRope: "줄넘기"
        default: "기타 운동"
        }
    }

    var symbol: String {
        switch self {
        case .running: "figure.run"
        case .walking: "figure.walk"
        case .cycling: "figure.outdoor.cycle"
        case .swimming: "figure.pool.swim"
        case .hiking: "figure.hiking"
        case .yoga: "figure.yoga"
        case .pilates: "figure.pilates"
        case .functionalStrengthTraining: "figure.strengthtraining.functional"
        case .traditionalStrengthTraining: "figure.strengthtraining.traditional"
        case .highIntensityIntervalTraining: "figure.highintensity.intervaltraining"
        case .coreTraining: "figure.core.training"
        case .elliptical: "figure.elliptical"
        case .rowing: "figure.rower"
        case .stairClimbing: "figure.stair.stepper"
        case .dance: "figure.dance"
        case .soccer: "figure.soccer"
        case .basketball: "figure.basketball"
        case .tennis: "figure.tennis"
        case .golf: "figure.golf"
        case .badminton: "figure.badminton"
        case .tableTennis: "figure.table.tennis"
        case .mindAndBody: "figure.mind.and.body"
        case .cooldown: "figure.cooldown"
        case .jumpRope: "figure.jumprope"
        default: "figure.mixed.cardio"
        }
    }
}
