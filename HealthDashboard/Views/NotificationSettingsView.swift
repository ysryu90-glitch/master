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
    private var primaryLocationID = WeatherLocation.all[0].id

    @State private var notificationDenied = false
    @State private var editing: MedicationReminder?
    @State private var previewSent = false

    var body: some View {
        Form {
            if notificationDenied {
                Section {
                    Label("알림이 꺼져 있어요", systemImage: "bell.slash.fill")
                        .foregroundStyle(.red)
                    Button("설정 앱에서 알림 허용하기") {
                        if let url = URL(string: UIApplication.openNotificationSettingsURLString) { openURL(url) }
                    }
                }
            }

            reportSection
            medicationSection

            if previewSent {
                Section {
                    Label("5초 뒤 미리보기 알림이 와요", systemImage: "checkmark.circle.fill")
                        .foregroundStyle(.green)
                }
            }
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
        .onChange(of: primaryLocationID) { _, _ in rescheduleReport() }
        .task { await checkPermission() }
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
                        ForEach(WeatherLocation.all) { location in
                            Text(location.name).tag(location.id)
                        }
                    }
                }
                Button("미리보기 보내기") {
                    Task {
                        await BriefingScheduler.sendPreview(report: weather.reports[primaryLocationID])
                        previewSent = true
                    }
                }
            }
        } header: {
            Text("매일 컨디션 요약")
        } footer: {
            Text("정한 시각에 준비 점수, 요소별 상태(HRV·수면 중 심박·수면), 지난밤 수면, 어제 활동을 요약해서 알려드려요. 준비 점수는 그날 앱이 한 번이라도 갱신된 뒤에 정확해요. 워치 데이터가 들어오면 앱이 백그라운드에서 자동으로 갱신을 시도합니다.")
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
                    Spacer()
                    Toggle("", isOn: enabledBinding(for: reminder))
                        .labelsHidden()
                }
                .swipeActions {
                    Button("삭제", role: .destructive) {
                        medications.reminders.removeAll { $0.id == reminder.id }
                    }
                    Button("미리보기") {
                        Task {
                            await medications.sendPreview(reminder)
                            previewSent = true
                        }
                    }
                    .tint(.blue)
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
            Text("매일 같은 시각에 울립니다. 알림을 길게 누르면 '복용 완료' 또는 '30분 뒤 다시 알림'을 고를 수 있어요. 이름을 누르면 수정, 왼쪽으로 밀면 삭제 · 미리보기.")
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
            notificationDenied = !(await BriefingScheduler.requestAuthorization())
            await medications.reschedule()
            await BriefingScheduler.reschedule(report: weather.reports[primaryLocationID])
        }
    }

    private func rescheduleReport() {
        Task { await BriefingScheduler.reschedule(report: weather.reports[primaryLocationID]) }
    }

    private func checkPermission() async {
        let settings = await UNUserNotificationCenter.current().notificationSettings()
        notificationDenied = settings.authorizationStatus == .denied
    }
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
