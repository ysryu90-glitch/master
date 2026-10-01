import SwiftUI

/// 생활 알림 설정: 지역 역할 · 출퇴근 날씨 · 부모님 안부 · 수면 코치
struct LifeSettingsView: View {
    @State private var settings = LifeAlertSettings.load()
    @State private var roles: [SharedStore.LocationRole: String] = Dictionary(
        uniqueKeysWithValues: SharedStore.LocationRole.allCases.map { ($0, SharedStore.location(for: $0).id) }
    )
    @State private var previewSent = false

    private let weekdayNames = ["일", "월", "화", "수", "목", "금", "토"]

    var body: some View {
        Form {
            Section {
                ForEach(SharedStore.LocationRole.allCases) { role in
                    Picker(selection: Binding(
                        get: { roles[role] ?? "" },
                        set: { roles[role] = $0; SharedStore.setLocation($0, for: role) }
                    )) {
                        ForEach(WeatherLocation.all) { Text($0.name).tag($0.id) }
                    } label: {
                        Label(role.title, systemImage: role.symbol)
                    }
                }
            } header: {
                Text("지역 역할")
            } footer: {
                Text("출퇴근 알림은 집 · 회사, 안부 알림은 부모님 댁 날씨를 기준으로 해요.")
            }

            Section {
                Toggle(isOn: $settings.commuteEnabled) {
                    Label("출퇴근 날씨 알림", systemImage: "tram.fill")
                }
                if settings.commuteEnabled {
                    DatePicker("집에서 나서는 시각", selection: time(\.leaveHomeHour, \.leaveHomeMinute), displayedComponents: .hourAndMinute)
                    DatePicker("회사에서 나서는 시각", selection: time(\.leaveWorkHour, \.leaveWorkMinute), displayedComponents: .hourAndMinute)
                    HStack {
                        ForEach(1...7, id: \.self) { weekday in
                            let isOn = settings.workdays.contains(weekday)
                            Button(weekdayNames[weekday - 1]) {
                                if isOn { settings.workdays.removeAll { $0 == weekday } } else { settings.workdays.append(weekday) }
                            }
                            .font(.subheadline.weight(.semibold))
                            .frame(maxWidth: .infinity)
                            .padding(.vertical, 6)
                            .background(isOn ? Color.accentColor.opacity(0.2) : Color(.tertiarySystemFill), in: Circle())
                            .buttonStyle(.plain)
                        }
                    }
                    Button("출근길 알림 미리보기 (5초 뒤)") {
                        Task {
                            await LifeAlerts.previewCommute()
                            previewSent = true
                        }
                    }
                }
            } header: {
                Text("출퇴근")
            } footer: {
                Text("출근 30분 전에 집 날씨와 퇴근 무렵 회사 날씨를, 퇴근 1시간 전에 퇴근길 날씨를 알려드려요. 비 · 한파 · 미세먼지면 우산 · 옷차림 · 마스크도 챙겨 드려요.")
            }

            Section {
                Toggle(isOn: $settings.parentsWeatherEnabled) {
                    Label("부모님 댁 날씨 안부 알림", systemImage: "thermometer.snowflake")
                }
                Toggle(isOn: $settings.parentsCallEnabled) {
                    Label("정기 안부 전화 알림", systemImage: "phone.fill")
                }
                if settings.parentsCallEnabled {
                    Picker("요일", selection: $settings.callWeekday) {
                        ForEach(1...7, id: \.self) { Text(weekdayNames[$0 - 1] + "요일").tag($0) }
                    }
                    DatePicker("시각", selection: time(\.callHour, \.callMinute), displayedComponents: .hourAndMinute)
                }
            } header: {
                Text("부모님")
            } footer: {
                Text("부모님 댁에 한파(-10° 이하), 폭염(33° 이상), 폭우 · 폭설 · 뇌우, 미세먼지 매우 나쁨이 예보되면 그날 아침 알려드려요.")
            }

            Section {
                Toggle(isOn: $settings.sleepCoachEnabled) {
                    Label("수면 코치", systemImage: "bed.double.fill")
                }
                if settings.sleepCoachEnabled {
                    Stepper("필요한 수면 \(settings.sleepNeedHours.formatted())시간", value: $settings.sleepNeedHours, in: 5...10, step: 0.5)
                    DatePicker("평소 기상 시각", selection: time(\.wakeHour, \.wakeMinute), displayedComponents: .hourAndMinute)
                    Stepper("잘 준비 알림: 취침 \(settings.windDownMinutes)분 전", value: $settings.windDownMinutes, in: 10...90, step: 5)
                    if let plan = LifeAlerts.sleepPlan(for: .now, settings: settings) {
                        LabeledContent("오늘 권장 취침", value: plan.bedtime.formatted(date: .omitted, time: .shortened))
                        if let reason = plan.reason {
                            Text(reason + " 일정에 맞춰 계산했어요.")
                                .font(.caption)
                                .foregroundStyle(.secondary)
                        }
                    }
                }
                Toggle(isOn: $settings.caffeineReminder) {
                    Label("오후 2시 카페인 마감 알림", systemImage: "cup.and.saucer.fill")
                }
            } header: {
                Text("수면")
            } footer: {
                Text("내일 오전 캘린더 첫 일정(준비 90분 포함)과 평소 기상 시각 중 이른 쪽에 맞춰 오늘 밤 취침 시각을 정하고, 그 전에 알려드려요.")
            }

            if previewSent {
                Section {
                    Label("5초 뒤 미리보기 알림이 와요.", systemImage: "checkmark.circle.fill")
                        .foregroundStyle(.green)
                }
            }
        }
        .navigationTitle("생활 알림")
        .navigationBarTitleDisplayMode(.inline)
        .onChange(of: settings) { _, new in
            new.save()
            Task { await LifeAlerts.reschedule() }
        }
        .onDisappear {
            Task { await LifeAlerts.reschedule() }
        }
    }

    private func time(_ hour: WritableKeyPath<LifeAlertSettings, Int>,
                      _ minute: WritableKeyPath<LifeAlertSettings, Int>) -> Binding<Date> {
        Binding {
            Calendar.current.date(bySettingHour: settings[keyPath: hour], minute: settings[keyPath: minute], second: 0, of: .now) ?? .now
        } set: { date in
            let components = Calendar.current.dateComponents([.hour, .minute], from: date)
            settings[keyPath: hour] = components.hour ?? 0
            settings[keyPath: minute] = components.minute ?? 0
        }
    }
}
