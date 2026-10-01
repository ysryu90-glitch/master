import EventKit
import Foundation
import UserNotifications

/// 생활 알림 설정 (출퇴근 · 부모님 · 수면 코치)
struct LifeAlertSettings: Codable, Equatable {
    // 출퇴근
    var commuteEnabled = false
    var leaveHomeHour = 8
    var leaveHomeMinute = 0
    var leaveWorkHour = 18
    var leaveWorkMinute = 30
    /// Calendar weekday (1 = 일요일 … 7 = 토요일)
    var workdays: [Int] = [2, 3, 4, 5, 6]

    // 부모님
    var parentsWeatherEnabled = false
    var parentsCallEnabled = false
    var callWeekday = 1
    var callHour = 19
    var callMinute = 0

    // 수면 코치
    var sleepCoachEnabled = false
    var sleepNeedHours = 7.5
    var wakeHour = 7
    var wakeMinute = 0
    var windDownMinutes = 30
    var caffeineReminder = false

    private static let key = "lifeAlertSettings"

    static func load() -> LifeAlertSettings {
        guard let data = UserDefaults.standard.data(forKey: key),
              let settings = try? JSONDecoder().decode(LifeAlertSettings.self, from: data) else { return LifeAlertSettings() }
        return settings
    }

    func save() {
        if let data = try? JSONEncoder().encode(self) {
            UserDefaults.standard.set(data, forKey: Self.key)
        }
    }
}

/// 수면 코치: 내일 첫 일정과 필요한 수면 시간으로 오늘 밤 권장 취침 시각을 정한다.
struct SleepPlan {
    let bedtime: Date
    let wake: Date
    /// 예: "내일 09:00 '주간 회의'"
    let reason: String?
}

@MainActor
enum LifeAlerts {
    private static let prefixes = ["commute-", "parents-weather-", "parents-call", "sleep-"]
    private static let parentsSentKey = "parentsAlertScheduledDays"

    // MARK: - 수면 계획

    /// `night` 날 밤에 잘 때의 계획 (기상은 다음 날)
    static func sleepPlan(for night: Date, settings: LifeAlertSettings = .load()) -> SleepPlan? {
        let calendar = Calendar.current
        guard let nextDay = calendar.date(byAdding: .day, value: 1, to: calendar.startOfDay(for: night)),
              var wake = calendar.date(bySettingHour: settings.wakeHour, minute: settings.wakeMinute, second: 0, of: nextDay)
        else { return nil }

        var reason: String?
        // 다음 날 오전 첫 일정이 있으면 준비 시간 90분을 두고 기상 시각을 당긴다.
        if let first = firstMorningEvent(on: nextDay),
           let start = first.startDate,
           let needed = calendar.date(byAdding: .minute, value: -90, to: start),
           needed < wake {
            wake = needed
            reason = "내일 \(start.formatted(date: .omitted, time: .shortened)) '\(first.title ?? "일정")'"
        }
        let bedtime = wake.addingTimeInterval(-(settings.sleepNeedHours * 3600) - 15 * 60)
        return SleepPlan(bedtime: bedtime, wake: wake, reason: reason)
    }

    private static func firstMorningEvent(on day: Date) -> EKEvent? {
        let store = CalendarStore.shared
        guard store.hasAccess,
              let noon = Calendar.current.date(bySettingHour: 11, minute: 0, second: 0, of: day) else { return nil }
        let predicate = store.eventStore.predicateForEvents(withStart: Calendar.current.startOfDay(for: day), end: noon, calendars: nil)
        return store.eventStore.events(matching: predicate)
            .filter { !$0.isAllDay && !($0.title ?? "").hasPrefix(PlannedMeal.titlePrefix) }
            .min { CalendarStore.start(of: $0) < CalendarStore.start(of: $1) }
    }

    // MARK: - 예약

    /// 앱이 열리거나 백그라운드에서 깨어날 때마다 앞으로 며칠치를 최신 예보로 다시 예약한다.
    static func reschedule() async {
        let center = UNUserNotificationCenter.current()
        let pending = await center.pendingNotificationRequests()
        center.removePendingNotificationRequests(withIdentifiers: pending.map(\.identifier).filter { id in
            prefixes.contains { id.hasPrefix($0) } && !id.hasPrefix("parents-weather-")
        })

        let settings = LifeAlertSettings.load()
        guard settings.commuteEnabled || settings.parentsWeatherEnabled || settings.parentsCallEnabled
                || settings.sleepCoachEnabled || settings.caffeineReminder else { return }
        guard await center.notificationSettings().authorizationStatus == .authorized else { return }

        let service = WeatherService()
        let home = SharedStore.location(for: .home)
        let work = SharedStore.location(for: .work)
        let parents = SharedStore.location(for: .parents)
        async let homeReport = try? service.report(for: home)
        async let workReport = try? service.report(for: work)
        async let parentsReport = try? service.report(for: parents)
        let reports = (home: await homeReport, work: await workReport, parents: await parentsReport)

        if settings.commuteEnabled {
            await scheduleCommute(settings, home: home, work: work, homeReport: reports.home, workReport: reports.work)
        }
        if settings.parentsWeatherEnabled {
            await scheduleParentsWeather(parents: parents, report: reports.parents)
        }
        if settings.parentsCallEnabled {
            await scheduleParentsCall(settings, parents: parents)
        }
        if settings.sleepCoachEnabled {
            await scheduleSleepCoach(settings)
        }
        if settings.caffeineReminder {
            await add(id: "sleep-caffeine", title: "☕ 카페인 마감 시간",
                      body: "지금 이후 카페인은 오늘 밤 수면을 방해할 수 있어요. 디카페인이나 물 어때요?",
                      components: DateComponents(hour: 14, minute: 0), repeats: true)
        }
    }

    // MARK: 출퇴근

    private static func scheduleCommute(_ settings: LifeAlertSettings, home: WeatherLocation, work: WeatherLocation,
                                        homeReport: WeatherReport?, workReport: WeatherReport?) async {
        let calendar = Calendar.current
        var scheduledDays = 0
        for offset in 0..<7 where scheduledDays < 3 {
            guard let day = calendar.date(byAdding: .day, value: offset, to: calendar.startOfDay(for: .now)),
                  settings.workdays.contains(calendar.component(.weekday, from: day)),
                  let leaveHome = calendar.date(bySettingHour: settings.leaveHomeHour, minute: settings.leaveHomeMinute, second: 0, of: day),
                  let leaveWork = calendar.date(bySettingHour: settings.leaveWorkHour, minute: settings.leaveWorkMinute, second: 0, of: day)
            else { continue }
            scheduledDays += 1
            let key = MedicationLog.dayKey(day)

            // 출근 30분 전
            let morning = leaveHome.addingTimeInterval(-30 * 60)
            if morning > .now {
                var lines: [String] = []
                if let line = forecastLine(homeReport, at: leaveHome, label: "\(home.name)(집) 출근길") { lines.append(line) }
                if let line = forecastLine(workReport, at: leaveWork, label: "\(work.name)(회사) 퇴근 무렵") { lines.append(line) }
                lines += advice([(homeReport, leaveHome), (workReport, leaveWork)], includeAir: offset == 0)
                if !lines.isEmpty {
                    await add(id: "commute-am-\(key)", title: "🚇 출근길 날씨", body: lines.joined(separator: "\n"), at: morning)
                }
            }

            // 퇴근 1시간 전
            let evening = leaveWork.addingTimeInterval(-60 * 60)
            if evening > .now {
                let arrive = leaveWork.addingTimeInterval(60 * 60)
                var lines: [String] = []
                if let line = forecastLine(workReport, at: leaveWork, label: "\(work.name)(회사) 퇴근길") { lines.append(line) }
                if let line = forecastLine(homeReport, at: arrive, label: "\(home.name)(집) 도착 무렵") { lines.append(line) }
                lines += advice([(workReport, leaveWork), (homeReport, arrive)], includeAir: false)
                if !lines.isEmpty {
                    await add(id: "commute-pm-\(key)", title: "🏠 퇴근길 날씨", body: lines.joined(separator: "\n"), at: evening)
                }
            }
        }
    }

    /// 그 시각의 예보 한 줄 (24시간 이내면 시간별, 아니면 하루 예보)
    private static func forecastLine(_ report: WeatherReport?, at date: Date, label: String) -> String? {
        guard let report else { return nil }
        let time = date.formatted(.dateTime.hour())
        if let hour = hourly(report, at: date) {
            var line = "\(label) \(time) \(hour.temperature.degreesText) \(hour.condition.description)"
            if let probability = hour.precipitationProbability, probability >= 20 { line += " · 비 \(Int(probability))%" }
            return line
        }
        guard let day = report.days.first(where: { Calendar.current.isDate($0.date, inSameDayAs: date) }) else { return nil }
        var line = "\(label) \(day.condition.description) \(day.low.degreesText)~\(day.high.degreesText)"
        if let probability = day.precipitationProbability, probability >= 20 { line += " · 비 \(Int(probability))%" }
        return line
    }

    private static func hourly(_ report: WeatherReport, at date: Date) -> WeatherReport.Hour? {
        report.hours.min { abs($0.date.timeIntervalSince(date)) < abs($1.date.timeIntervalSince(date)) }
            .flatMap { abs($0.date.timeIntervalSince(date)) <= 45 * 60 ? $0 : nil }
    }

    private static func advice(_ points: [(WeatherReport?, Date)], includeAir: Bool) -> [String] {
        var lines: [String] = []
        let rainy = points.contains { report, date in
            guard let report else { return false }
            if let hour = hourly(report, at: date) { return (hour.precipitationProbability ?? 0) >= 50 }
            return (report.days.first { Calendar.current.isDate($0.date, inSameDayAs: date) }?.precipitationProbability ?? 0) >= 60
        }
        if rainy { lines.append("☔ 우산 챙기세요") }
        let cold = points.contains { report, date in
            report.flatMap { hourly($0, at: date) }.map { $0.temperature <= -5 } ?? false
        }
        if cold { lines.append("🧣 많이 추워요. 따뜻하게 입으세요") }
        if includeAir, let grade = points.compactMap({ $0.0?.airQuality?.overallGrade }).first,
           grade == .bad || grade == .veryBad {
            lines.append("😷 미세먼지 \(grade.title) — 마스크 챙기세요")
        }
        return lines
    }

    // MARK: 부모님

    private static func scheduleParentsWeather(parents: WeatherLocation, report: WeatherReport?) async {
        guard let report else { return }
        let calendar = Calendar.current
        var sent = Set(UserDefaults.standard.stringArray(forKey: parentsSentKey) ?? [])

        for day in report.days.prefix(3) {
            let key = MedicationLog.dayKey(day.date)
            guard !sent.contains(key) else { continue }
            var warnings: [String] = []
            if day.low <= -10 { warnings.append("최저 \(day.low.degreesText) 한파") }
            if day.high >= 33 { warnings.append("최고 \(day.high.degreesText) 폭염") }
            if [65, 75, 82, 86, 95, 96, 99].contains(day.condition.code) { warnings.append(day.condition.description) }
            if calendar.isDateInToday(day.date), report.airQuality?.overallGrade == .veryBad {
                warnings.append("미세먼지 매우 나쁨")
            }
            guard !warnings.isEmpty else { continue }

            // 그날 아침 9시 (이미 지났으면 곧바로)
            let nine = calendar.date(bySettingHour: 9, minute: 0, second: 0, of: day.date) ?? day.date
            let when = nine > .now ? nine : Date.now.addingTimeInterval(10)
            let dayWord = calendar.isDateInToday(day.date) ? "오늘" : (calendar.isDateInTomorrow(day.date) ? "내일" : day.date.formatted(.dateTime.month().day()))
            await add(
                id: "parents-weather-\(key)",
                title: "👨‍👩‍👦 \(parents.name)(부모님 댁) 날씨",
                body: "\(dayWord) \(warnings.joined(separator: ", ")) 예보예요. 부모님께 안부 전화 어때요?",
                at: when
            )
            sent.insert(key)
        }
        UserDefaults.standard.set(Array(sent.sorted().suffix(30)), forKey: parentsSentKey)
    }

    private static func scheduleParentsCall(_ settings: LifeAlertSettings, parents: WeatherLocation) async {
        await add(
            id: "parents-call",
            title: "📞 부모님께 안부 전화",
            body: "이번 주도 부모님 목소리 들어 볼까요? (\(parents.name))",
            components: DateComponents(hour: settings.callHour, minute: settings.callMinute, weekday: settings.callWeekday),
            repeats: true
        )
    }

    // MARK: 수면 코치

    private static func scheduleSleepCoach(_ settings: LifeAlertSettings) async {
        let calendar = Calendar.current
        for offset in 0..<2 {
            guard let night = calendar.date(byAdding: .day, value: offset, to: calendar.startOfDay(for: .now)),
                  let plan = sleepPlan(for: night, settings: settings) else { continue }
            let windDown = plan.bedtime.addingTimeInterval(-Double(settings.windDownMinutes) * 60)
            guard windDown > .now else { continue }
            let bed = plan.bedtime.formatted(date: .omitted, time: .shortened)
            let wake = plan.wake.formatted(date: .omitted, time: .shortened)
            var body = "\(bed)에 잠들면 \(wake)까지 \(settings.sleepNeedHours.formatted())시간 잘 수 있어요."
            if let reason = plan.reason { body = "\(reason) 일정이 있어요. " + body }
            body += " 화면 밝기를 줄이고 잘 준비를 시작해요 🌙"
            await add(id: "sleep-winddown-\(MedicationLog.dayKey(night))", title: "😴 잘 준비할 시간", body: body, at: windDown)
        }
    }

    // MARK: 알림 추가

    private static func add(id: String, title: String, body: String, at date: Date) async {
        let components = Calendar.current.dateComponents([.year, .month, .day, .hour, .minute], from: date)
        await add(id: id, title: title, body: body, components: components, repeats: false)
    }

    private static func add(id: String, title: String, body: String, components: DateComponents, repeats: Bool) async {
        let content = UNMutableNotificationContent()
        content.title = title
        content.body = body
        content.sound = .default
        let request = UNNotificationRequest(
            identifier: id, content: content,
            trigger: UNCalendarNotificationTrigger(dateMatching: components, repeats: repeats)
        )
        try? await UNUserNotificationCenter.current().add(request)
    }

    /// 설정 화면 미리보기: 내일 출근길 알림 내용을 5초 뒤 보낸다.
    static func previewCommute() async {
        let settings = LifeAlertSettings.load()
        let service = WeatherService()
        let home = SharedStore.location(for: .home)
        let work = SharedStore.location(for: .work)
        let homeReport = try? await service.report(for: home)
        let workReport = try? await service.report(for: work)
        let calendar = Calendar.current
        let today = calendar.startOfDay(for: .now)
        let leaveHome = calendar.date(bySettingHour: settings.leaveHomeHour, minute: settings.leaveHomeMinute, second: 0, of: today) ?? .now
        let leaveWork = calendar.date(bySettingHour: settings.leaveWorkHour, minute: settings.leaveWorkMinute, second: 0, of: today) ?? .now
        var lines = [
            forecastLine(homeReport, at: leaveHome, label: "\(home.name)(집) 출근길"),
            forecastLine(workReport, at: leaveWork, label: "\(work.name)(회사) 퇴근 무렵"),
        ].compactMap { $0 }
        lines += advice([(homeReport, leaveHome), (workReport, leaveWork)], includeAir: true)
        let content = UNMutableNotificationContent()
        content.title = "🚇 출근길 날씨 (미리보기)"
        content.body = lines.isEmpty ? "날씨를 불러오지 못했어요." : lines.joined(separator: "\n")
        content.sound = .default
        try? await UNUserNotificationCenter.current().add(
            UNNotificationRequest(identifier: "preview-commute", content: content,
                                  trigger: UNTimeIntervalNotificationTrigger(timeInterval: 5, repeats: false))
        )
    }
}
