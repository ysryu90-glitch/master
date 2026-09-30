import SwiftUI

struct ReadinessComponent: Identifiable {
    enum Kind: String {
        case hrv, restingHeartRate, sleep, trainingLoad, vitals
    }

    let kind: Kind
    /// 0...100
    let score: Double
    let weight: Double
    let detail: String

    var id: Kind { kind }

    var title: String {
        switch kind {
        case .hrv: "심박 변이 (HRV)"
        case .restingHeartRate: "안정 시 심박수"
        case .sleep: "수면"
        case .trainingLoad: "운동 부하"
        case .vitals: "야간 활력 징후"
        }
    }

    var symbol: String {
        switch kind {
        case .hrv: "waveform.path.ecg"
        case .restingHeartRate: "heart.fill"
        case .sleep: "bed.double.fill"
        case .trainingLoad: "flame.fill"
        case .vitals: "thermometer.medium"
        }
    }

    var status: String {
        switch score {
        case ..<40: "낮음"
        case ..<70: "보통"
        default: "좋음"
        }
    }

    var statusColor: Color {
        switch score {
        case ..<40: .red
        case ..<70: .orange
        default: .green
        }
    }
}

struct ReadinessScore: Identifiable {
    let date: Date
    /// 0...10
    let score: Double
    let components: [ReadinessComponent]

    var id: Date { date }
    var level: ReadinessLevel { ReadinessLevel(score: score) }
    var scoreText: String { score.formatted(.number.precision(.fractionLength(1))) }
}

/// 점수 계산에 필요한 일별 데이터
struct ReadinessInputs {
    var hrv: [TrendPoint] = []
    var restingHeartRate: [TrendPoint] = []
    var activeEnergy: [TrendPoint] = []
    var wristTemperature: [TrendPoint] = []
    var respiratoryRate: [TrendPoint] = []
    var sleepNights: [SleepNight] = []

    /// 오늘을 포함해 필요한 조회 기간 (기준선 30일 + 기록 7일)
    static let lookbackDays = 38
}

/// 애플의 공식 준비 점수 알고리즘은 공개되지 않았고 HealthKit으로도 제공되지 않는다.
/// 같은 입력(HRV, 안정 시 심박수, 수면, 운동 부하, 야간 활력 징후)을
/// 최근 30일 개인 기준선과 비교해 0~10점으로 추정한다.
enum ReadinessCalculator {
    private static let baselineDays = 30

    static func history(days: Int, inputs: ReadinessInputs) -> [ReadinessScore] {
        let calendar = Calendar.current
        let today = calendar.startOfDay(for: .now)
        return (0..<days).reversed().compactMap { offset in
            calendar.date(byAdding: .day, value: -offset, to: today).flatMap { score(on: $0, inputs: inputs) }
        }
    }

    static func score(on date: Date, inputs: ReadinessInputs) -> ReadinessScore? {
        let calendar = Calendar.current
        let day = calendar.startOfDay(for: date)
        var components: [ReadinessComponent] = []

        // 심박 변이: 평소보다 높을수록 회복이 잘 된 상태
        if let hrv = split(inputs.hrv, on: day), hrv.baseline.count >= 5 {
            let z = zScore(hrv.today, hrv.baseline)
            components.append(ReadinessComponent(
                kind: .hrv, score: clamp(65 + z * 20), weight: 0.30,
                detail: "오늘 \(format(hrv.today))ms · 평소 \(format(mean(hrv.baseline)))ms"
            ))
        }

        // 안정 시 심박수: 평소보다 낮을수록 좋음
        if let rhr = split(inputs.restingHeartRate, on: day), rhr.baseline.count >= 5 {
            let z = zScore(rhr.today, rhr.baseline)
            components.append(ReadinessComponent(
                kind: .restingHeartRate, score: clamp(65 - z * 20), weight: 0.20,
                detail: "오늘 \(format(rhr.today))BPM · 평소 \(format(mean(rhr.baseline)))BPM"
            ))
        }

        // 수면: 전날 밤 수면 시간 (4시간 → 0점, 8시간 이상 → 100점), 깊은+렘 수면 비율 반영
        if let night = inputs.sleepNights.first(where: { calendar.isDate($0.wakeDate, inSameDayAs: day) }),
           night.asleep > 0 {
            let hours = night.asleep / 3600
            var sleepScore = clamp((hours - 4) / 4 * 100)
            if night.hasStages {
                let restorative = (night.duration(.deep) + night.duration(.rem)) / night.asleep
                if restorative < 0.25 { sleepScore -= 10 }
            }
            components.append(ReadinessComponent(
                kind: .sleep, score: clamp(sleepScore), weight: 0.30,
                detail: "지난밤 \(night.asleep.hoursMinutesText) 수면"
            ))
        }

        // 운동 부하: 최근 7일 평균 활동 에너지 ÷ 4주 평균 (급성:만성 부하 비율)
        let load = inputs.activeEnergy.filter { $0.date < day }
        let acute = load.filter { $0.date >= calendar.date(byAdding: .day, value: -7, to: day)! }.map(\.value)
        let chronic = load.filter { $0.date >= calendar.date(byAdding: .day, value: -28, to: day)! }.map(\.value)
        if acute.count >= 3, chronic.count >= 10, mean(chronic) > 0 {
            let ratio = mean(acute) / mean(chronic)
            var loadScore: Double = switch ratio {
            case ..<0.8: 90
            case ..<1.3: 80
            case ..<1.5: 55
            default: 30
            }
            if let yesterday = load.last, yesterday.value > mean(chronic) * 1.8 { loadScore -= 15 }
            components.append(ReadinessComponent(
                kind: .trainingLoad, score: clamp(loadScore), weight: 0.10,
                detail: "최근 7일 \(format(mean(acute)))kcal/일 · 4주 평균 \(format(mean(chronic)))kcal/일"
            ))
        }

        // 야간 활력 징후: 손목 온도와 호흡수가 평소 범위를 벗어나면 감점
        var vitalsScore: Double = 100
        var vitalsDetails: [String] = []
        if let temp = split(inputs.wristTemperature, on: day, todayWindow: 1), temp.baseline.count >= 5 {
            let deviation = temp.today - mean(temp.baseline)
            if abs(deviation) > 1.0 { vitalsScore -= 50 } else if abs(deviation) > 0.5 { vitalsScore -= 25 }
            vitalsDetails.append("손목 온도 \(deviation >= 0 ? "+" : "")\(deviation.formatted(.number.precision(.fractionLength(1))))°C")
        }
        if let respiratory = split(inputs.respiratoryRate, on: day, todayWindow: 1), respiratory.baseline.count >= 5 {
            let deviation = respiratory.today - mean(respiratory.baseline)
            if deviation > 2 { vitalsScore -= 30 } else if deviation > 1 { vitalsScore -= 15 }
            vitalsDetails.append(deviation > 1 ? "호흡수 평소보다 높음" : "호흡수 평소 수준")
        }
        if !vitalsDetails.isEmpty {
            components.append(ReadinessComponent(
                kind: .vitals, score: clamp(vitalsScore), weight: 0.10,
                detail: vitalsDetails.joined(separator: " · ")
            ))
        }

        // 수면이나 HRV 중 하나는 있어야 의미 있는 점수로 본다.
        guard components.count >= 2,
              components.contains(where: { $0.kind == .sleep || $0.kind == .hrv }) else { return nil }

        let totalWeight = components.reduce(0) { $0 + $1.weight }
        let weighted = components.reduce(0) { $0 + $1.score * $1.weight } / totalWeight
        let score = weighted.rounded() / 10
        return ReadinessScore(date: day, score: min(10, max(0, score)), components: components)
    }

    // MARK: - 계산 도우미

    /// `day`의 값과 그 이전 30일 기준선. `todayWindow`일 전까지의 값도 '오늘'로 인정한다.
    private static func split(_ points: [TrendPoint], on day: Date, todayWindow: Int = 0)
        -> (today: Double, baseline: [Double])? {
        let calendar = Calendar.current
        let earliestToday = calendar.date(byAdding: .day, value: -todayWindow, to: day)!
        guard let today = points.last(where: { $0.date >= earliestToday && $0.date <= day }) else { return nil }
        let baselineStart = calendar.date(byAdding: .day, value: -baselineDays, to: today.date)!
        let baseline = points.filter { $0.date >= baselineStart && $0.date < today.date }.map(\.value)
        return (today.value, baseline)
    }

    private static func mean(_ values: [Double]) -> Double {
        values.isEmpty ? 0 : values.reduce(0, +) / Double(values.count)
    }

    private static func zScore(_ value: Double, _ baseline: [Double]) -> Double {
        let average = mean(baseline)
        let variance = baseline.reduce(0) { $0 + pow($1 - average, 2) } / Double(max(baseline.count - 1, 1))
        // 기록이 너무 일정하면 작은 변화도 크게 보이므로 최소 편차를 둔다.
        let deviation = max(variance.squareRoot(), abs(average) * 0.05, 0.001)
        return max(-2.5, min(2.5, (value - average) / deviation))
    }

    private static func clamp(_ value: Double) -> Double { min(100, max(0, value)) }

    private static func format(_ value: Double) -> String {
        value.formatted(.number.precision(.fractionLength(0)))
    }
}
