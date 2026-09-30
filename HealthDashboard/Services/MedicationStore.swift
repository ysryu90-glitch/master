import Foundation
import Observation
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

/// 복약 알림과 복용 기록.
/// 알림은 '매일 반복'으로 등록하므로 앱을 열지 않아도 계속 울린다.
@MainActor
@Observable
final class MedicationStore {
    static let shared = MedicationStore()

    static let categoryID = "MEDICATION"
    static let takenActionID = "MED_TAKEN"
    static let snoozeActionID = "MED_SNOOZE"
    static let userInfoKey = "medicationID"
    private static let idPrefix = "medication-"

    private enum Keys {
        static let reminders = "medicationReminders"
        static let log = "medicationTakenLog"
    }

    var reminders: [MedicationReminder] {
        didSet {
            save()
            Task { await reschedule() }
        }
    }

    /// 약 ID → 복용한 날짜("yyyy-MM-dd") 목록
    private(set) var takenLog: [String: [String]]

    init() {
        let defaults = UserDefaults.standard
        if let data = defaults.data(forKey: Keys.reminders),
           let saved = try? JSONDecoder().decode([MedicationReminder].self, from: data) {
            reminders = saved
        } else {
            // 처음 실행: 탈모약 오후 9시 (알림 권한을 받은 뒤 켜도록 꺼 둔 상태로 시작)
            reminders = [MedicationReminder(name: "탈모약", hour: 21, minute: 0, isEnabled: false)]
        }
        takenLog = (defaults.dictionary(forKey: Keys.log) as? [String: [String]]) ?? [:]
    }

    // MARK: - 복용 기록

    func isTaken(_ reminder: MedicationReminder, on date: Date = .now) -> Bool {
        takenLog[reminder.id.uuidString, default: []].contains(Self.dayKey(date))
    }

    func setTaken(_ taken: Bool, id: UUID, on date: Date = .now) {
        var days = takenLog[id.uuidString, default: []]
        let key = Self.dayKey(date)
        if taken {
            if !days.contains(key) { days.append(key) }
            // 오늘 이미 먹었으면 '30분 뒤 다시 알림'은 취소
            UNUserNotificationCenter.current().removePendingNotificationRequests(withIdentifiers: [Self.snoozeID(id)])
        } else {
            days.removeAll { $0 == key }
        }
        // 최근 90일만 보관
        takenLog[id.uuidString] = Array(days.sorted().suffix(90))
        UserDefaults.standard.set(takenLog, forKey: Keys.log)
    }

    struct DayRecord: Identifiable {
        let date: Date
        let taken: Bool
        var id: Date { date }
    }

    /// 최근 7일 복용 여부 (오래된 날 → 오늘)
    func weekHistory(_ reminder: MedicationReminder) -> [DayRecord] {
        let calendar = Calendar.current
        let today = calendar.startOfDay(for: .now)
        return (0..<7).reversed().compactMap { offset in
            calendar.date(byAdding: .day, value: -offset, to: today)
                .map { DayRecord(date: $0, taken: isTaken(reminder, on: $0)) }
        }
    }

    // MARK: - 알림

    func reschedule() async {
        let center = UNUserNotificationCenter.current()
        let pending = await center.pendingNotificationRequests()
        center.removePendingNotificationRequests(
            withIdentifiers: pending.map(\.identifier).filter { $0.hasPrefix(Self.idPrefix) && !$0.contains("snooze") }
        )

        for reminder in reminders where reminder.isEnabled {
            let request = UNNotificationRequest(
                identifier: Self.idPrefix + reminder.id.uuidString,
                content: content(for: reminder),
                trigger: UNCalendarNotificationTrigger(
                    dateMatching: DateComponents(hour: reminder.hour, minute: reminder.minute),
                    repeats: true
                )
            )
            try? await center.add(request)
        }
    }

    func snooze(id: UUID, minutes: Double = 30) async {
        guard let reminder = reminders.first(where: { $0.id == id }) else { return }
        let request = UNNotificationRequest(
            identifier: Self.snoozeID(id),
            content: content(for: reminder),
            trigger: UNTimeIntervalNotificationTrigger(timeInterval: minutes * 60, repeats: false)
        )
        try? await UNUserNotificationCenter.current().add(request)
    }

    func sendPreview(_ reminder: MedicationReminder) async {
        let request = UNNotificationRequest(
            // 'medication-' 접두어를 쓰면 reschedule()이 지워버리므로 다른 이름을 쓴다.
            identifier: "preview-medication",
            content: content(for: reminder),
            trigger: UNTimeIntervalNotificationTrigger(timeInterval: 5, repeats: false)
        )
        try? await UNUserNotificationCenter.current().add(request)
    }

    private func content(for reminder: MedicationReminder) -> UNMutableNotificationContent {
        let content = UNMutableNotificationContent()
        content.title = "💊 \(reminder.name) 먹을 시간이에요"
        content.body = "복용했다면 알림을 길게 눌러 '복용 완료'를 선택하세요."
        content.sound = .default
        content.categoryIdentifier = Self.categoryID
        content.userInfo = [Self.userInfoKey: reminder.id.uuidString]
        return content
    }

    // MARK: - 저장

    private func save() {
        if let data = try? JSONEncoder().encode(reminders) {
            UserDefaults.standard.set(data, forKey: Keys.reminders)
        }
    }

    private static func snoozeID(_ id: UUID) -> String { idPrefix + "snooze-" + id.uuidString }

    private static func dayKey(_ date: Date) -> String {
        let components = Calendar.current.dateComponents([.year, .month, .day], from: date)
        return String(format: "%04d-%02d-%02d", components.year ?? 0, components.month ?? 0, components.day ?? 0)
    }
}
