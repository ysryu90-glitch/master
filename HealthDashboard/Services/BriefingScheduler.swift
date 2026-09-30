import BackgroundTasks
import UserNotifications

/// 아침 브리핑 알림.
/// iOS는 정해진 시각에 앱을 실행해 준다는 보장이 없으므로, 앱이 실행될 때마다(포그라운드/백그라운드)
/// 앞으로 7일치 알림을 '그 시점에 알고 있는 최신 정보'로 다시 예약해 둔다.
@MainActor
enum BriefingScheduler {
    static let backgroundTaskID = "healthdashboard.refresh"
    private static let idPrefix = "morning-briefing-"
    private static let daysAhead = 7

    static func requestAuthorization() async -> Bool {
        (try? await UNUserNotificationCenter.current().requestAuthorization(options: [.alert, .sound])) ?? false
    }

    /// 최신 건강 요약과 날씨로 알림을 다시 예약한다.
    static func reschedule(report: WeatherReport? = nil) async {
        let center = UNUserNotificationCenter.current()
        center.removePendingNotificationRequests(withIdentifiers: (0..<daysAhead).map { idPrefix + String($0) })

        guard SharedStore.briefingEnabled else { return }
        let settings = await center.notificationSettings()
        guard settings.authorizationStatus == .authorized || settings.authorizationStatus == .provisional else { return }

        let location = SharedStore.primaryLocation
        var weather = report
        if weather == nil {
            weather = try? await WeatherService().report(for: location)
        }
        let snapshot = SharedStore.healthSnapshot

        let calendar = Calendar.current
        guard let first = nextFireDate() else { return }
        for offset in 0..<daysAhead {
            guard let date = calendar.date(byAdding: .day, value: offset, to: first) else { continue }
            let content = makeContent(for: date, location: location, weather: weather, snapshot: snapshot)
            let components = calendar.dateComponents([.year, .month, .day, .hour, .minute], from: date)
            let request = UNNotificationRequest(
                identifier: idPrefix + String(offset),
                content: content,
                trigger: UNCalendarNotificationTrigger(dateMatching: components, repeats: false)
            )
            try? await center.add(request)
        }

        scheduleBackgroundRefresh(before: first)
    }

    /// 설정 화면의 '미리보기' 버튼: 5초 뒤 지금 정보로 알림을 보낸다.
    static func sendPreview(report: WeatherReport?) async {
        let content = makeContent(
            for: .now, location: SharedStore.primaryLocation,
            weather: report, snapshot: SharedStore.healthSnapshot
        )
        let request = UNNotificationRequest(
            identifier: "briefing-preview",
            content: content,
            trigger: UNTimeIntervalNotificationTrigger(timeInterval: 5, repeats: false)
        )
        try? await UNUserNotificationCenter.current().add(request)
    }

    // MARK: - 알림 내용

    static func makeContent(
        for date: Date,
        location: WeatherLocation,
        weather: WeatherReport?,
        snapshot: HealthSnapshot?
    ) -> UNMutableNotificationContent {
        let calendar = Calendar.current
        let forecast = weather?.days.first { calendar.isDate($0.date, inSameDayAs: date) }
        // 대기질은 현재 값이라 알림 시각이 가까울 때만 사용
        let air = date.timeIntervalSince(weather?.fetchedAt ?? .distantPast) < 12 * 3600 ? weather?.airQuality : nil
        let readiness = snapshot?.readiness(on: date)

        var lines: [String] = []
        if let readiness {
            let score = readiness.score.formatted(.number.precision(.fractionLength(1)))
            lines.append("💪 준비 점수 \(score) · \(readiness.level.title)")
        } else {
            lines.append("💪 앱을 열면 오늘의 준비 점수를 계산해요.")
        }

        if let forecast {
            var weatherLine = "\(location.name) \(forecast.condition.description) · \(forecast.low.degreesText) / \(forecast.high.degreesText)"
            if let probability = forecast.precipitationProbability, probability >= 20 {
                weatherLine += " · 강수 \(Int(probability))%"
            }
            lines.append("🌤 " + weatherLine)
        }
        if let grade = air?.overallGrade {
            lines.append("😷 미세먼지 \(grade.title)")
        }

        let recommendation = WorkoutAdvisor.recommend(
            readiness: readiness?.level, forecast: forecast, airGrade: air?.overallGrade
        )
        lines.append("🏃 추천: \(recommendation.title)")
        if recommendation.notes.contains(where: { $0.hasPrefix("☔") }) {
            lines.append("☔ 우산을 챙기세요.")
        }

        let content = UNMutableNotificationContent()
        content.title = "좋은 아침이에요! 오늘의 브리핑"
        content.body = lines.joined(separator: "\n")
        content.sound = .default
        return content
    }

    private static func nextFireDate() -> Date? {
        let time = SharedStore.briefingTime
        return Calendar.current.nextDate(
            after: .now,
            matching: DateComponents(hour: time.hour, minute: time.minute),
            matchingPolicy: .nextTime
        )
    }

    // MARK: - 백그라운드 새로고침

    /// 브리핑 40분 전쯤 앱을 깨워 정보를 갱신하도록 요청한다. (실행 시각은 iOS가 결정)
    private static func scheduleBackgroundRefresh(before fireDate: Date) {
        let request = BGAppRefreshTaskRequest(identifier: backgroundTaskID)
        let preferred = fireDate.addingTimeInterval(-40 * 60)
        request.earliestBeginDate = preferred > .now.addingTimeInterval(15 * 60) ? preferred : .now.addingTimeInterval(3600)
        try? BGTaskScheduler.shared.submit(request)
    }
}

/// 앱을 보고 있는 중에도 브리핑 알림(미리보기 포함)이 배너로 보이게 한다.
final class NotificationDelegate: NSObject, UNUserNotificationCenterDelegate {
    static let shared = NotificationDelegate()

    func userNotificationCenter(
        _ center: UNUserNotificationCenter,
        willPresent notification: UNNotification
    ) async -> UNNotificationPresentationOptions {
        [.banner, .list, .sound]
    }
}
