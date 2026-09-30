import Foundation

extension TimeInterval {
    /// 예: "7시간 12분"
    var hoursMinutesText: String {
        let totalMinutes = Int((self / 60).rounded())
        let hours = totalMinutes / 60
        let minutes = totalMinutes % 60
        if hours == 0 { return "\(minutes)분" }
        return minutes == 0 ? "\(hours)시간" : "\(hours)시간 \(minutes)분"
    }
}
