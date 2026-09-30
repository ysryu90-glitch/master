import HealthKit
import SwiftUI

/// 대시보드에서 지표를 묶어 보여주는 카테고리 (건강 앱의 분류를 따름)
enum MetricCategory: String, CaseIterable, Identifiable {
    case activity, heart, respiratory, vitals, body, mobility, hearing, nutrition

    var id: String { rawValue }

    var title: String {
        switch self {
        case .activity: "활동"
        case .heart: "심장"
        case .respiratory: "호흡"
        case .vitals: "활력 징후"
        case .body: "신체 측정"
        case .mobility: "이동성"
        case .hearing: "청각"
        case .nutrition: "영양"
        }
    }

    var symbol: String {
        switch self {
        case .activity: "flame.fill"
        case .heart: "heart.fill"
        case .respiratory: "lungs.fill"
        case .vitals: "waveform.path.ecg"
        case .body: "figure"
        case .mobility: "figure.walk"
        case .hearing: "ear"
        case .nutrition: "fork.knife"
        }
    }

    var tint: Color {
        switch self {
        case .activity: .orange
        case .heart: .red
        case .respiratory: .cyan
        case .vitals: .pink
        case .body: .purple
        case .mobility: .yellow
        case .hearing: .indigo
        case .nutrition: .green
        }
    }
}

/// HealthKit 수량(Quantity) 지표 하나에 대한 정의
struct HealthMetric: Identifiable, Hashable, @unchecked Sendable {
    enum Aggregation {
        /// 하루 합계로 보는 값 (걸음 수, 칼로리 등)
        case cumulative
        /// 측정 시점의 값으로 보는 값 (심박수, 체중 등)
        case discrete
    }

    let id: HKQuantityTypeIdentifier
    let title: String
    let symbol: String
    let category: MetricCategory
    let unit: HKUnit
    let unitLabel: String
    let aggregation: Aggregation
    var fractionDigits: Int = 0
    /// 표시용 배율 (예: HealthKit 퍼센트 0.97 → 97%)
    var multiplier: Double = 1

    var type: HKQuantityType { HKQuantityType(id) }
    var tint: Color { category.tint }

    func displayValue(_ quantity: HKQuantity) -> Double {
        quantity.doubleValue(for: unit) * multiplier
    }

    func formatted(_ value: Double) -> String {
        value.formatted(.number.precision(.fractionLength(fractionDigits)))
    }

    static func == (lhs: HealthMetric, rhs: HealthMetric) -> Bool { lhs.id == rhs.id }
    func hash(into hasher: inout Hasher) { hasher.combine(id) }
}

extension HKUnit {
    static let beatsPerMinute = HKUnit.count().unitDivided(by: .minute())
    static let kilometer = HKUnit.meterUnit(with: .kilo)
    static let kilometerPerHour = HKUnit.meterUnit(with: .kilo).unitDivided(by: .hour())
    static let vo2Max = HKUnit(from: "ml/kg*min")
    static let milligramsPerDeciliter = HKUnit(from: "mg/dL")
}

extension HealthMetric {
    /// 애플워치 / 아이폰이 기록하는 주요 수량 지표 전체 목록
    static let all: [HealthMetric] = [
        // 활동
        HealthMetric(id: .stepCount, title: "걸음 수", symbol: "shoeprints.fill", category: .activity,
                     unit: .count(), unitLabel: "걸음", aggregation: .cumulative),
        HealthMetric(id: .distanceWalkingRunning, title: "걷기 + 달리기 거리", symbol: "figure.walk", category: .activity,
                     unit: .kilometer, unitLabel: "km", aggregation: .cumulative, fractionDigits: 2),
        HealthMetric(id: .activeEnergyBurned, title: "활동 에너지", symbol: "flame.fill", category: .activity,
                     unit: .kilocalorie(), unitLabel: "kcal", aggregation: .cumulative),
        HealthMetric(id: .basalEnergyBurned, title: "휴식 에너지", symbol: "bed.double.fill", category: .activity,
                     unit: .kilocalorie(), unitLabel: "kcal", aggregation: .cumulative),
        HealthMetric(id: .appleExerciseTime, title: "운동하기 시간", symbol: "figure.run", category: .activity,
                     unit: .minute(), unitLabel: "분", aggregation: .cumulative),
        HealthMetric(id: .appleStandTime, title: "일어서기 시간", symbol: "figure.stand", category: .activity,
                     unit: .minute(), unitLabel: "분", aggregation: .cumulative),
        HealthMetric(id: .flightsClimbed, title: "오른 층수", symbol: "stairs", category: .activity,
                     unit: .count(), unitLabel: "층", aggregation: .cumulative),
        HealthMetric(id: .distanceCycling, title: "사이클 거리", symbol: "bicycle", category: .activity,
                     unit: .kilometer, unitLabel: "km", aggregation: .cumulative, fractionDigits: 1),
        HealthMetric(id: .timeInDaylight, title: "햇빛 노출 시간", symbol: "sun.max.fill", category: .activity,
                     unit: .minute(), unitLabel: "분", aggregation: .cumulative),

        // 심장
        HealthMetric(id: .heartRate, title: "심박수", symbol: "heart.fill", category: .heart,
                     unit: .beatsPerMinute, unitLabel: "BPM", aggregation: .discrete),
        HealthMetric(id: .restingHeartRate, title: "안정 시 심박수", symbol: "heart.circle", category: .heart,
                     unit: .beatsPerMinute, unitLabel: "BPM", aggregation: .discrete),
        HealthMetric(id: .walkingHeartRateAverage, title: "걷기 평균 심박수", symbol: "figure.walk.motion", category: .heart,
                     unit: .beatsPerMinute, unitLabel: "BPM", aggregation: .discrete),
        HealthMetric(id: .heartRateVariabilitySDNN, title: "심박 변이", symbol: "waveform.path.ecg", category: .heart,
                     unit: .secondUnit(with: .milli), unitLabel: "ms", aggregation: .discrete),
        HealthMetric(id: .heartRateRecoveryOneMinute, title: "심박 회복", symbol: "arrow.down.heart", category: .heart,
                     unit: .beatsPerMinute, unitLabel: "BPM", aggregation: .discrete),
        HealthMetric(id: .vo2Max, title: "심폐 체력 (VO₂ max)", symbol: "lungs", category: .heart,
                     unit: .vo2Max, unitLabel: "mL/kg·min", aggregation: .discrete, fractionDigits: 1),

        // 호흡
        HealthMetric(id: .oxygenSaturation, title: "혈중 산소", symbol: "drop.fill", category: .respiratory,
                     unit: .percent(), unitLabel: "%", aggregation: .discrete, multiplier: 100),
        HealthMetric(id: .respiratoryRate, title: "호흡수", symbol: "wind", category: .respiratory,
                     unit: .beatsPerMinute, unitLabel: "회/분", aggregation: .discrete, fractionDigits: 1),

        // 활력 징후
        HealthMetric(id: .appleSleepingWristTemperature, title: "수면 중 손목 온도", symbol: "thermometer.medium", category: .vitals,
                     unit: .degreeCelsius(), unitLabel: "°C", aggregation: .discrete, fractionDigits: 2),
        HealthMetric(id: .bodyTemperature, title: "체온", symbol: "thermometer", category: .vitals,
                     unit: .degreeCelsius(), unitLabel: "°C", aggregation: .discrete, fractionDigits: 1),
        HealthMetric(id: .bloodPressureSystolic, title: "수축기 혈압", symbol: "heart.text.square", category: .vitals,
                     unit: .millimeterOfMercury(), unitLabel: "mmHg", aggregation: .discrete),
        HealthMetric(id: .bloodPressureDiastolic, title: "이완기 혈압", symbol: "heart.text.square", category: .vitals,
                     unit: .millimeterOfMercury(), unitLabel: "mmHg", aggregation: .discrete),
        HealthMetric(id: .bloodGlucose, title: "혈당", symbol: "syringe", category: .vitals,
                     unit: .milligramsPerDeciliter, unitLabel: "mg/dL", aggregation: .discrete),

        // 신체 측정
        HealthMetric(id: .bodyMass, title: "체중", symbol: "scalemass.fill", category: .body,
                     unit: .gramUnit(with: .kilo), unitLabel: "kg", aggregation: .discrete, fractionDigits: 1),
        HealthMetric(id: .bodyMassIndex, title: "체질량 지수", symbol: "person.fill", category: .body,
                     unit: .count(), unitLabel: "BMI", aggregation: .discrete, fractionDigits: 1),
        HealthMetric(id: .bodyFatPercentage, title: "체지방률", symbol: "percent", category: .body,
                     unit: .percent(), unitLabel: "%", aggregation: .discrete, fractionDigits: 1, multiplier: 100),
        HealthMetric(id: .leanBodyMass, title: "제지방량", symbol: "figure.arms.open", category: .body,
                     unit: .gramUnit(with: .kilo), unitLabel: "kg", aggregation: .discrete, fractionDigits: 1),
        HealthMetric(id: .height, title: "신장", symbol: "ruler", category: .body,
                     unit: .meterUnit(with: .centi), unitLabel: "cm", aggregation: .discrete, fractionDigits: 1),

        // 이동성
        HealthMetric(id: .walkingSpeed, title: "보행 속도", symbol: "speedometer", category: .mobility,
                     unit: .kilometerPerHour, unitLabel: "km/h", aggregation: .discrete, fractionDigits: 1),
        HealthMetric(id: .walkingStepLength, title: "보폭", symbol: "ruler.fill", category: .mobility,
                     unit: .meterUnit(with: .centi), unitLabel: "cm", aggregation: .discrete),
        HealthMetric(id: .walkingAsymmetryPercentage, title: "보행 비대칭성", symbol: "arrow.left.and.right", category: .mobility,
                     unit: .percent(), unitLabel: "%", aggregation: .discrete, fractionDigits: 1, multiplier: 100),
        HealthMetric(id: .walkingDoubleSupportPercentage, title: "양발 지지 시간", symbol: "shoeprints.fill", category: .mobility,
                     unit: .percent(), unitLabel: "%", aggregation: .discrete, fractionDigits: 1, multiplier: 100),
        HealthMetric(id: .appleWalkingSteadiness, title: "보행 안정성", symbol: "figure.walk.circle", category: .mobility,
                     unit: .percent(), unitLabel: "%", aggregation: .discrete, multiplier: 100),
        HealthMetric(id: .sixMinuteWalkTestDistance, title: "6분 걷기 거리", symbol: "stopwatch", category: .mobility,
                     unit: .meter(), unitLabel: "m", aggregation: .discrete),

        // 청각
        HealthMetric(id: .environmentalAudioExposure, title: "환경 소음", symbol: "ear.badge.waveform", category: .hearing,
                     unit: .decibelAWeightedSoundPressureLevel(), unitLabel: "dB", aggregation: .discrete),
        HealthMetric(id: .headphoneAudioExposure, title: "헤드폰 오디오", symbol: "headphones", category: .hearing,
                     unit: .decibelAWeightedSoundPressureLevel(), unitLabel: "dB", aggregation: .discrete),

        // 영양
        HealthMetric(id: .dietaryWater, title: "수분 섭취", symbol: "waterbottle.fill", category: .nutrition,
                     unit: .liter(), unitLabel: "L", aggregation: .cumulative, fractionDigits: 2),
        HealthMetric(id: .dietaryEnergyConsumed, title: "섭취 칼로리", symbol: "fork.knife", category: .nutrition,
                     unit: .kilocalorie(), unitLabel: "kcal", aggregation: .cumulative),
        HealthMetric(id: .dietaryCaffeine, title: "카페인", symbol: "cup.and.saucer.fill", category: .nutrition,
                     unit: .gramUnit(with: .milli), unitLabel: "mg", aggregation: .cumulative),
    ]

    static func metrics(in category: MetricCategory) -> [HealthMetric] {
        all.filter { $0.category == category }
    }
}
