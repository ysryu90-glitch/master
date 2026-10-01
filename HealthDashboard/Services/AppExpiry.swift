import Foundation
import UserNotifications

/// 무료 개발자 계정으로 설치한 앱의 사용 기한(7일) 확인.
/// 앱 안에 들어 있는 서명 허가증(embedded.mobileprovision)의 만료일을 읽어
/// 만료 하루 전 저녁에 '다시 설치해 주세요' 알림을 예약한다.
/// (앱스토어 · TestFlight 설치본에는 이 파일이 없으므로 아무 일도 하지 않는다)
enum AppExpiry {
    private static let notificationID = "app-expiry-reminder"

    /// 서명 허가증의 만료 시각
    static let expirationDate: Date? = {
        guard let url = Bundle.main.url(forResource: "embedded", withExtension: "mobileprovision"),
              let data = try? Data(contentsOf: url),
              let start = data.range(of: Data("<?xml".utf8)),
              let end = data.range(of: Data("</plist>".utf8), in: start.lowerBound..<data.endIndex)
        else { return nil }
        let plistData = data.subdata(in: start.lowerBound..<end.upperBound)
        guard let plist = try? PropertyListSerialization.propertyList(from: plistData, format: nil) as? [String: Any]
        else { return nil }
        return plist["ExpirationDate"] as? Date
    }()

    /// 남은 날 수 (오늘 만료면 0, 이미 지났으면 음수)
    static var daysLeft: Int? {
        guard let expirationDate else { return nil }
        let calendar = Calendar.current
        return calendar.dateComponents([.day], from: calendar.startOfDay(for: .now),
                                       to: calendar.startOfDay(for: expirationDate)).day
    }

    /// 대시보드에 안내를 띄울 만큼 임박했는지 (2일 이하)
    static var isExpiringSoon: Bool {
        guard let daysLeft else { return false }
        return daysLeft <= 2
    }

    static var summary: String {
        guard let expirationDate, let daysLeft else { return "기한 없음" }
        let date = expirationDate.formatted(.dateTime.month().day().weekday(.abbreviated).hour().minute())
        if daysLeft <= 0 { return "오늘 \(expirationDate.formatted(date: .omitted, time: .shortened))까지" }
        return "\(date)까지 (\(daysLeft)일 남음)"
    }

    /// 만료 하루 전 오후 8시(이미 지났으면 만료 3시간 전)에 알림 예약
    static func reschedule() async {
        let center = UNUserNotificationCenter.current()
        center.removePendingNotificationRequests(withIdentifiers: [notificationID])
        guard let expirationDate else { return }

        let calendar = Calendar.current
        let dayBefore = calendar.date(byAdding: .day, value: -1, to: expirationDate) ?? expirationDate
        var fireDate = calendar.date(bySettingHour: 20, minute: 0, second: 0, of: dayBefore) ?? dayBefore
        if fireDate <= .now { fireDate = expirationDate.addingTimeInterval(-3 * 3600) }
        guard fireDate > .now else { return }

        let content = UNMutableNotificationContent()
        content.title = "건강 대시보드 사용 기한이 곧 끝나요"
        content.body = "\(expirationDate.formatted(.dateTime.month().day().hour().minute()))에 만료돼요. Mac에서 다시 설치하면 7일 연장되고, 기록은 그대로 남아요."
        content.sound = .default

        let components = calendar.dateComponents([.year, .month, .day, .hour, .minute], from: fireDate)
        let request = UNNotificationRequest(
            identifier: notificationID,
            content: content,
            trigger: UNCalendarNotificationTrigger(dateMatching: components, repeats: false)
        )
        try? await center.add(request)
    }
}
