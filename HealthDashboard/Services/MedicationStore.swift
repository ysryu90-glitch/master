import Foundation
import Observation
import UserNotifications

/// 복약 알림과 복용 기록.
/// 알림은 앞으로 7일치를 날짜별로 예약하고, 이미 먹은 날은 건너뛴다.
/// (앱이 열리거나 워치 데이터로 백그라운드에서 깨어날 때마다 다시 채워진다)
@MainActor
@Observable
final class MedicationStore {
    static let shared = MedicationStore()

    static let categoryID = "MEDICATION"
    static let takenActionID = "MED_TAKEN"
    static let snoozeActionID = "MED_SNOOZE"
    static let userInfoKey = "medicationID"
    private static let daysAhead = 7

    var reminders: [MedicationReminder] {
        didSet {
            MedicationLog.saveReminders(reminders)
            Task { await reschedule() }
        }
    }

    /// 약 ID → 복용한 날짜("yyyy-MM-dd") 목록
    private(set) var takenLog: [String: [String]]

    init() {
        // 처음 실행: 탈모약 오후 9시 (알림 권한을 받은 뒤 켜도록 꺼 둔 상태로 시작)
        reminders = MedicationLog.loadReminders()
            ?? [MedicationReminder(name: "탈모약", hour: 21, minute: 0, isEnabled: false)]
        takenLog = MedicationLog.loadLog()
    }

    /// 백업 복원 · 위젯/Siri에서 기록한 뒤 다시 읽기
    func reloadFromDefaults() {
        if let saved = MedicationLog.loadReminders() { reminders = saved }
        takenLog = MedicationLog.loadLog()
    }

    /// 위젯 · Siri가 기록했을 수 있으니 앱이 앞으로 올 때 복용 기록만 다시 읽는다.
    func reloadLog() {
        takenLog = MedicationLog.loadLog()
    }

    // MARK: - 복용 기록

    func isTaken(_ reminder: MedicationReminder, on date: Date = .now) -> Bool {
        MedicationLog.isTaken(reminder.id, on: date, log: takenLog)
    }

    func setTaken(_ taken: Bool, id: UUID, on date: Date = .now) {
        MedicationLog.setTaken(taken, id: id, on: date)
        takenLog = MedicationLog.loadLog()
        // 체크를 취소했으면 오늘 알림을 다시 살린다.
        if !taken { Task { await reschedule() } }
    }

    /// 최근 `days`일 복용률 (0~1)
    func adherence(_ reminder: MedicationReminder, days: Int) -> Double {
        let calendar = Calendar.current
        let taken = (0..<days).filter { offset in
            calendar.date(byAdding: .day, value: -offset, to: .now).map { isTaken(reminder, on: $0) } ?? false
        }.count
        return Double(taken) / Double(max(days, 1))
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
            withIdentifiers: pending.map(\.identifier).filter {
                $0.hasPrefix(MedicationLog.notificationPrefix) && !$0.contains("snooze")
            }
        )

        let calendar = Calendar.current
        let today = calendar.startOfDay(for: .now)
        for reminder in reminders where reminder.isEnabled {
            for offset in 0..<Self.daysAhead {
                guard let day = calendar.date(byAdding: .day, value: offset, to: today),
                      let fire = calendar.date(bySettingHour: reminder.hour, minute: reminder.minute, second: 0, of: day),
                      fire > .now,
                      !isTaken(reminder, on: day) else { continue }
                let request = UNNotificationRequest(
                    identifier: MedicationLog.notificationID(reminder.id, day: day),
                    content: content(for: reminder),
                    trigger: UNCalendarNotificationTrigger(
                        dateMatching: calendar.dateComponents([.year, .month, .day, .hour, .minute], from: fire),
                        repeats: false
                    )
                )
                try? await center.add(request)
            }
        }
    }

    func snooze(id: UUID, minutes: Double = 30) async {
        guard let reminder = reminders.first(where: { $0.id == id }) else { return }
        let request = UNNotificationRequest(
            identifier: MedicationLog.snoozeID(id),
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
}
