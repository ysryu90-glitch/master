import Foundation
import UserNotifications

struct MedicationReminder: Codable, Identifiable, Equatable {
    var id = UUID()
    var name: String
    var hour: Int
    var minute: Int
    var isEnabled = true

    var timeText: String {
        let date = Calendar.current.date(bySettingHour: hour, minute: minute, second: 0, of: .now) ?? .now
        return date.formatted(date: .omitted, time: .shortened)
    }
}

/// 복약 설정 · 복용 기록 저장소 (앱 · 위젯 · Siri가 함께 쓰도록 App Group에 저장)
enum MedicationLog {
    static let remindersKey = "medicationReminders"
    static let logKey = "medicationTakenLog"
    static let notificationPrefix = "medication-"

    private static var defaults: UserDefaults { SharedStore.defaults }

    static func loadReminders() -> [MedicationReminder]? {
        // 예전 버전(앱 전용 저장소)에서 옮겨 오기
        if defaults.data(forKey: remindersKey) == nil,
           defaults !== UserDefaults.standard,
           let old = UserDefaults.standard.data(forKey: remindersKey) {
            defaults.set(old, forKey: remindersKey)
            if let oldLog = UserDefaults.standard.dictionary(forKey: logKey) {
                defaults.set(oldLog, forKey: logKey)
            }
        }
        guard let data = defaults.data(forKey: remindersKey) else { return nil }
        return try? JSONDecoder().decode([MedicationReminder].self, from: data)
    }

    static func saveReminders(_ reminders: [MedicationReminder]) {
        defaults.set(try? JSONEncoder().encode(reminders), forKey: remindersKey)
    }

    static func loadLog() -> [String: [String]] {
        (defaults.dictionary(forKey: logKey) as? [String: [String]]) ?? [:]
    }

    static func saveLog(_ log: [String: [String]]) {
        defaults.set(log, forKey: logKey)
    }

    static func dayKey(_ date: Date) -> String {
        let components = Calendar.current.dateComponents([.year, .month, .day], from: date)
        return String(format: "%04d-%02d-%02d", components.year ?? 0, components.month ?? 0, components.day ?? 0)
    }

    static func notificationID(_ id: UUID, day: Date) -> String {
        notificationPrefix + id.uuidString + "-" + dayKey(day)
    }

    static func snoozeID(_ id: UUID) -> String {
        notificationPrefix + "snooze-" + id.uuidString
    }

    static func isTaken(_ id: UUID, on date: Date, log: [String: [String]]) -> Bool {
        log[id.uuidString, default: []].contains(dayKey(date))
    }

    /// 복용 기록을 바꾸고, 먹었으면 그날 남은 알림을 지운다.
    static func setTaken(_ taken: Bool, id: UUID, on date: Date = .now) {
        var log = loadLog()
        var days = log[id.uuidString, default: []]
        let key = dayKey(date)
        if taken {
            if !days.contains(key) { days.append(key) }
            UNUserNotificationCenter.current().removePendingNotificationRequests(
                withIdentifiers: [notificationID(id, day: date), snoozeID(id)]
            )
        } else {
            days.removeAll { $0 == key }
        }
        // 최근 400일 보관 (1년 넘는 복용률 계산용)
        log[id.uuidString] = Array(days.sorted().suffix(400))
        saveLog(log)
    }

    /// 위젯 · Siri: 오늘 켜져 있는 약을 모두 복용 완료로. 기록한 약 이름을 돌려준다.
    @discardableResult
    static func markAllTakenToday() -> [String] {
        let reminders = (loadReminders() ?? []).filter(\.isEnabled)
        let log = loadLog()
        let pending = reminders.filter { !isTaken($0.id, on: .now, log: log) }
        for reminder in pending {
            setTaken(true, id: reminder.id)
        }
        return pending.map(\.name)
    }

    /// 위젯 표시용: (이름, 오늘 복용 여부)
    static func todayStatus() -> [(name: String, taken: Bool)] {
        let log = loadLog()
        return (loadReminders() ?? []).filter(\.isEnabled).map { ($0.name, isTaken($0.id, on: .now, log: log)) }
    }
}
