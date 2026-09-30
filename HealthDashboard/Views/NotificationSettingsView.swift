import SwiftUI
import UIKit
import UserNotifications

/// 알림 설정: 컨디션 리포트 + 복약 알림
struct NotificationSettingsView: View {
    @Environment(WeatherModel.self) private var weather
    @Environment(MedicationStore.self) private var medications
    @Environment(\.openURL) private var openURL

    @AppStorage(SharedStore.briefingEnabledKey, store: SharedStore.defaults) private var reportEnabled = false
    @AppStorage(SharedStore.briefingHourKey, store: SharedStore.defaults) private var reportHour = 7
    @AppStorage(SharedStore.briefingMinuteKey, store: SharedStore.defaults) private var reportMinute = 0
    @AppStorage(SharedStore.reportIncludeWeatherKey, store: SharedStore.defaults) private var includeWeather = true
    @AppStorage(SharedStore.primaryLocationKey, store: SharedStore.defaults)
    private var primaryLocationID = SharedStore.autoLocationToken
    @AppStorage(SharedStore.autoLocationKey, store: SharedStore.defaults) private var autoLocationID: String?
    @AppStorage(SharedStore.earlyWarningEnabledKey, store: SharedStore.defaults) private var earlyWarningEnabled = true
    @Environment(HabitStore.self) private var habits

    /// '자동'이면 현재 위치 기준으로 바꾼 실제 지역
    private var resolvedLocationID: String {
        SharedStore.resolveLocation(id: primaryLocationID, autoID: autoLocationID).id
    }

    @State private var authorization: UNAuthorizationStatus = .notDetermined
    @State private var scheduled: [ScheduledNotification] = []
    @State private var editing: MedicationReminder?
    @State private var previewSent = false

    private var notificationDenied: Bool { authorization == .denied }

    var body: some View {
        Form {
            statusSection

            if previewSent {
                Section {
                    Label("5초 뒤 미리보기 알림이 와요. 홈 화면으로 나가서 기다려 보세요.", systemImage: "checkmark.circle.fill")
                        .foregroundStyle(.green)
                }
            }

            reportSection
            medicationSection
            healthAlertSection
        }
        .navigationTitle("알림")
        .navigationBarTitleDisplayMode(.inline)
        .sheet(item: $editing) { reminder in
            MedicationEditView(reminder: reminder) { updated in
                if let index = medications.reminders.firstIndex(where: { $0.id == updated.id }) {
                    medications.reminders[index] = updated
                } else {
                    medications.reminders.append(updated)
                }
                enableNotifications()
            }
        }
        .onChange(of: reportEnabled) { _, enabled in
            if enabled { enableNotifications() }
            rescheduleReport()
        }
        .onChange(of: reportHour) { _, _ in rescheduleReport() }
        .onChange(of: reportMinute) { _, _ in rescheduleReport() }
        .onChange(of: includeWeather) { _, _ in rescheduleReport() }
        .onChange(of: primaryLocationID) { _, _ in
            Task {
                await LocationService.shared.refreshIfNeeded()
                rescheduleReport()
            }
        }
        .task { await refreshStatus() }
    }

    // MARK: - 알림 상태 (문제 확인용)

    private var statusSection: some View {
        Section {
            HStack {
                Label("알림 권한", systemImage: notificationDenied ? "bell.slash.fill" : "bell.fill")
                Spacer()
                Text(authorizationText)
                    .foregroundStyle(authorization == .authorized ? .green : .red)
            }
            switch authorization {
            case .notDetermined:
                Button("알림 권한 요청하기") {
                    Task { _ = await ensurePermission() }
                }
            case .denied:
                Button("설정 앱에서 알림 허용하기") {
                    if let url = URL(string: UIApplication.openNotificationSettingsURLString) { openURL(url) }
                }
            default:
                EmptyView()
            }

            LabeledContent("예약된 알림", value: "\(scheduled.count)개")
            ForEach(scheduled.prefix(5)) { item in
                VStack(alignment: .leading, spacing: 2) {
                    Text(item.title)
                        .font(.subheadline)
                    Text(item.date.formatted(date: .abbreviated, time: .shortened))
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
            }

            Button("모든 알림 다시 예약하기") {
                Task {
                    guard await ensurePermission() else { return }
                    await medications.reschedule()
                    await habits.rescheduleReminder()
                    await BriefingScheduler.reschedule(report: weather.reports[resolvedLocationID])
                    await refreshStatus()
                }
            }
        } header: {
            Text("알림 상태")
        } footer: {
            Text("알림이 안 오면: ① 권한이 '허용됨'인지 ② 예약된 알림이 있는지 ③ 아이폰의 집중 모드(방해금지·수면)가 꺼져 있는지 확인하세요.")
        }
    }

    private var authorizationText: String {
        switch authorization {
        case .authorized: "허용됨"
        case .denied: "거부됨"
        case .provisional, .ephemeral: "임시 허용"
        case .notDetermined: "아직 요청 안 함"
        @unknown default: "알 수 없음"
        }
    }

    /// 권한이 없으면 요청하고, 허용 여부를 돌려준다.
    private func ensurePermission() async -> Bool {
        let granted = await BriefingScheduler.requestAuthorization()
        await refreshStatus()
        return granted
    }

    private func refreshStatus() async {
        let center = UNUserNotificationCenter.current()
        authorization = await center.notificationSettings().authorizationStatus
        scheduled = await center.pendingNotificationRequests()
            .compactMap { request -> ScheduledNotification? in
                let date = (request.trigger as? UNCalendarNotificationTrigger)?.nextTriggerDate()
                    ?? (request.trigger as? UNTimeIntervalNotificationTrigger)?.nextTriggerDate()
                return date.map { ScheduledNotification(id: request.identifier, title: request.content.title, date: $0) }
            }
            .sorted { $0.date < $1.date }
    }

    private func sendPreview(_ send: @escaping () async -> Void) {
        Task {
            guard await ensurePermission() else { return }
            await send()
            previewSent = true
            await refreshStatus()
        }
    }

    // MARK: - 컨디션 리포트

    private var reportSection: some View {
        Section {
            Toggle(isOn: $reportEnabled) {
                Label("컨디션 리포트", systemImage: "heart.text.square.fill")
            }
            if reportEnabled {
                DatePicker("알림 시각", selection: timeBinding(hour: $reportHour, minute: $reportMinute),
                           displayedComponents: .hourAndMinute)
                Toggle("날씨 · 운동 추천 포함", isOn: $includeWeather)
                if includeWeather {
                    Picker("날씨 지역", selection: $primaryLocationID) {
                        Text("📍 자동 (현재 위치)").tag(SharedStore.autoLocationToken)
                        ForEach(WeatherLocation.all) { location in
                            Text(location.name).tag(location.id)
                        }
                    }
                }
                Button("미리보기 보내기") {
                    let report = weather.reports[resolvedLocationID]
                    sendPreview { await BriefingScheduler.sendPreview(report: report) }
                }
            }
        } header: {
            Text("매일 컨디션 요약")
        } footer: {
            Text("정한 시각에 준비 점수, 요소별 상태(HRV·수면 중 심박·수면), 지난밤 수면, 어제 활동을 요약해서 알려드려요. 준비 점수는 그날 앱이 한 번이라도 갱신된 뒤에 정확해요. 워치 데이터가 들어오면 앱이 백그라운드에서 자동으로 갱신을 시도합니다.")
        }
    }

    // MARK: - 컨디션 이상 경보 · 습관 기록 알림

    private var healthAlertSection: some View {
        @Bindable var habits = habits
        return Section {
            Toggle(isOn: $earlyWarningEnabled) {
                Label("컨디션 이상 경보", systemImage: "exclamationmark.triangle.fill")
            }
            Toggle(isOn: $habits.reminderEnabled) {
                Label("습관 기록 알림", systemImage: "list.bullet.clipboard.fill")
            }
            .onChange(of: habits.reminderEnabled) { _, enabled in
                if enabled { enableNotifications() }
            }
            if habits.reminderEnabled {
                DatePicker("기록 알림 시각", selection: timeBinding(hour: $habits.reminderHour, minute: $habits.reminderMinute),
                           displayedComponents: .hourAndMinute)
            }
        } header: {
            Text("건강 알림")
        } footer: {
            Text("컨디션 이상 경보: 수면 중 심박수↑ · HRV↓ · 손목 온도↑ · 호흡수↑ 중 여러 신호가 겹치면 하루 한 번 알려드려요. 습관 기록 알림: 매일 밤 오늘의 습관(술·카페인·야근 등)을 기록하라고 알려드려요.")
        }
    }

    // MARK: - 복약 알림

    private var medicationSection: some View {
        Section {
            ForEach(medications.reminders) { reminder in
                HStack {
                    Button {
                        editing = reminder
                    } label: {
                        VStack(alignment: .leading, spacing: 2) {
                            Text(reminder.name)
                                .foregroundStyle(.primary)
                            Text("매일 \(reminder.timeText)")
                                .font(.caption)
                                .foregroundStyle(.secondary)
                        }
                    }
                    .buttonStyle(.borderless)
                    Spacer()
                    Button {
                        sendPreview { await medications.sendPreview(reminder) }
                    } label: {
                        Image(systemName: "bell.badge")
                    }
                    .buttonStyle(.borderless)
                    .accessibilityLabel("미리보기 알림 보내기")
                    Toggle("", isOn: enabledBinding(for: reminder))
                        .labelsHidden()
                }
                .swipeActions {
                    Button("삭제", role: .destructive) {
                        medications.reminders.removeAll { $0.id == reminder.id }
                    }
                }
            }

            Button {
                editing = MedicationReminder(name: "", hour: 21, minute: 0)
            } label: {
                Label("약 추가", systemImage: "plus.circle.fill")
            }
        } header: {
            Text("복약 알림")
        } footer: {
            Text("매일 같은 시각에 울립니다. 알림을 길게 누르면 '복용 완료' 또는 '30분 뒤 다시 알림'을 고를 수 있어요. 이름을 누르면 수정, 🔔 버튼은 5초 뒤 미리보기, 왼쪽으로 밀면 삭제.")
        }
    }

    // MARK: - 도우미

    private func enabledBinding(for reminder: MedicationReminder) -> Binding<Bool> {
        Binding {
            medications.reminders.first { $0.id == reminder.id }?.isEnabled ?? false
        } set: { enabled in
            guard let index = medications.reminders.firstIndex(where: { $0.id == reminder.id }) else { return }
            medications.reminders[index].isEnabled = enabled
            if enabled { enableNotifications() }
        }
    }

    private func timeBinding(hour: Binding<Int>, minute: Binding<Int>) -> Binding<Date> {
        Binding {
            Calendar.current.date(bySettingHour: hour.wrappedValue, minute: minute.wrappedValue, second: 0, of: .now) ?? .now
        } set: { date in
            let components = Calendar.current.dateComponents([.hour, .minute], from: date)
            hour.wrappedValue = components.hour ?? 0
            minute.wrappedValue = components.minute ?? 0
        }
    }

    /// 알림 권한을 요청하고, 허용되면 모든 알림을 다시 예약한다.
    private func enableNotifications() {
        Task {
            guard await ensurePermission() else { return }
            await medications.reschedule()
            await habits.rescheduleReminder()
            await BriefingScheduler.reschedule(report: weather.reports[resolvedLocationID])
            await refreshStatus()
        }
    }

    private func rescheduleReport() {
        Task {
            await BriefingScheduler.reschedule(report: weather.reports[resolvedLocationID])
            await refreshStatus()
        }
    }
}

private struct ScheduledNotification: Identifiable {
    let id: String
    let title: String
    let date: Date
}

/// 약 이름 · 시각 편집
private struct MedicationEditView: View {
    @Environment(\.dismiss) private var dismiss
    @State private var draft: MedicationReminder
    let onSave: (MedicationReminder) -> Void

    init(reminder: MedicationReminder, onSave: @escaping (MedicationReminder) -> Void) {
        _draft = State(initialValue: reminder)
        self.onSave = onSave
    }

    private var time: Binding<Date> {
        Binding {
            Calendar.current.date(bySettingHour: draft.hour, minute: draft.minute, second: 0, of: .now) ?? .now
        } set: { date in
            let components = Calendar.current.dateComponents([.hour, .minute], from: date)
            draft.hour = components.hour ?? 21
            draft.minute = components.minute ?? 0
        }
    }

    var body: some View {
        NavigationStack {
            Form {
                TextField("약 이름 (예: 탈모약)", text: $draft.name)
                DatePicker("알림 시각", selection: time, displayedComponents: .hourAndMinute)
                    .datePickerStyle(.wheel)
                Toggle("알림 켜기", isOn: $draft.isEnabled)
            }
            .navigationTitle(draft.name.isEmpty ? "약 추가" : draft.name)
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("취소") { dismiss() }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button("저장") {
                        onSave(draft)
                        dismiss()
                    }
                    .disabled(draft.name.trimmingCharacters(in: .whitespaces).isEmpty)
                }
            }
        }
        .presentationDetents([.medium, .large])
    }
}
