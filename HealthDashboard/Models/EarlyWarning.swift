import SwiftUI

/// 수면 중 심박수↑, HRV↓, 손목 온도↑, 호흡수↑ 같은 신호가 겹치면 컨디션 저하(감기 초기 등) 가능성을 알린다.
/// 의료 진단이 아닌 참고용이다.
struct EarlyWarning {
    enum Level {
        /// 오늘 신호 2개
        case watch
        /// 오늘 신호 3개 이상, 또는 이틀 연속 2개 이상
        case alert
    }

    let level: Level
    let date: Date
    let signals: [String]

    var title: String {
        switch level {
        case .watch: "컨디션 주의"
        case .alert: "컨디션 이상 신호"
        }
    }

    var message: String {
        switch level {
        case .watch:
            "평소와 다른 신호가 있어요. 오늘은 운동 강도를 낮추고 컨디션을 지켜보세요."
        case .alert:
            "여러 신체 신호가 평소와 달라요. 감기·몸살 초기일 수 있으니 오늘은 충분히 쉬세요. 증상이 계속되면 진료를 받아 보세요."
        }
    }

    var color: Color { level == .alert ? .red : .orange }
}

enum EarlyWarningDetector {
    /// 개인 기준선을 믿을 수 있으려면 최소 1주일 기록이 필요하다.
    private static let minimumBaseline = 7

    static func evaluate(inputs: ReadinessInputs, on date: Date = .now) -> EarlyWarning? {
        let calendar = Calendar.current
        let today = calendar.startOfDay(for: date)
        guard let yesterday = calendar.date(byAdding: .day, value: -1, to: today) else { return nil }

        let todaySignals = signals(inputs: inputs, on: today)
        let yesterdaySignals = signals(inputs: inputs, on: yesterday)

        if todaySignals.count >= 3 || (todaySignals.count >= 2 && yesterdaySignals.count >= 2) {
            return EarlyWarning(level: .alert, date: today, signals: todaySignals)
        }
        if todaySignals.count >= 2 {
            return EarlyWarning(level: .watch, date: today, signals: todaySignals)
        }
        return nil
    }

    static func signals(inputs: ReadinessInputs, on day: Date) -> [String] {
        typealias Calc = ReadinessCalculator
        var result: [String] = []

        if let heartRate = Calc.split(inputs.restingHeartRate, on: day), heartRate.baseline.count >= minimumBaseline {
            let difference = heartRate.today - Calc.mean(heartRate.baseline)
            if Calc.zScore(heartRate.today, heartRate.baseline) >= 1.5, difference >= 3 {
                let label = inputs.heartRateIsSleeping ? "수면 중 심박수" : "안정 시 심박수"
                result.append("\(label) 평소보다 +\(Int(difference.rounded()))BPM")
            }
        }

        if let hrv = Calc.split(inputs.hrv, on: day), hrv.baseline.count >= minimumBaseline {
            let average = Calc.mean(hrv.baseline)
            let percent = average > 0 ? (hrv.today / average - 1) * 100 : 0
            if Calc.zScore(hrv.today, hrv.baseline) <= -1.5, percent <= -10 {
                result.append("심박 변이(HRV) 평소보다 \(Int(percent.rounded()))%")
            }
        }

        if let temperature = Calc.split(inputs.wristTemperature, on: day, todayWindow: 1),
           temperature.baseline.count >= minimumBaseline {
            let deviation = temperature.today - Calc.mean(temperature.baseline)
            if deviation >= 0.4 {
                result.append("손목 온도 평소보다 +\(deviation.formatted(.number.precision(.fractionLength(1))))°C")
            }
        }

        if let respiratory = Calc.split(inputs.respiratoryRate, on: day, todayWindow: 1),
           respiratory.baseline.count >= minimumBaseline {
            let deviation = respiratory.today - Calc.mean(respiratory.baseline)
            if deviation >= 1.5 {
                result.append("수면 중 호흡수 평소보다 +\(deviation.formatted(.number.precision(.fractionLength(1))))회/분")
            }
        }

        return result
    }
}
