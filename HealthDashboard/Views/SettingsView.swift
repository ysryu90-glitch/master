import SwiftUI

struct SettingsView: View {
    @Environment(DashboardModel.self) private var model
    @Environment(\.dismiss) private var dismiss
    @Environment(\.openURL) private var openURL
    @Environment(WeatherModel.self) private var weather

    @AppStorage(SharedStore.briefingEnabledKey, store: SharedStore.defaults) private var briefingEnabled = false
    @AppStorage(SharedStore.briefingHourKey, store: SharedStore.defaults) private var briefingHour = 7
    @AppStorage(SharedStore.briefingMinuteKey, store: SharedStore.defaults) private var briefingMinute = 0
    @AppStorage(SharedStore.primaryLocationKey, store: SharedStore.defaults)
    private var primaryLocationID = WeatherLocation.all[0].id
    @State private var notificationDenied = false

    private var briefingTime: Binding<Date> {
        Binding {
            Calendar.current.date(bySettingHour: briefingHour, minute: briefingMinute, second: 0, of: .now) ?? .now
        } set: { date in
            let components = Calendar.current.dateComponents([.hour, .minute], from: date)
            briefingHour = components.hour ?? 7
            briefingMinute = components.minute ?? 0
        }
    }

    var body: some View {
        @Bindable var model = model

        NavigationStack {
            Form {
                Section {
                    Toggle("아침 브리핑 알림", isOn: $briefingEnabled)
                    if briefingEnabled {
                        DatePicker("알림 시각", selection: briefingTime, displayedComponents: .hourAndMinute)
                        Button("지금 미리보기 보내기 (5초 뒤)") {
                            Task {
                                await BriefingScheduler.sendPreview(report: weather.reports[primaryLocationID])
                            }
                        }
                    }
                    Picker("기본 지역", selection: $primaryLocationID) {
                        ForEach(WeatherLocation.all) { location in
                            Text(location.name).tag(location.id)
                        }
                    }
                } header: {
                    Text("아침 브리핑 · 운동 추천")
                } footer: {
                    if notificationDenied {
                        Text("알림이 꺼져 있어요. 설정 앱 › 알림 › 건강 대시보드에서 허용해 주세요.")
                            .foregroundStyle(.red)
                    } else {
                        Text("준비 점수, 기본 지역 날씨·미세먼지, 오늘의 운동 추천을 매일 아침 알려드려요. iOS가 백그라운드 실행 시점을 정하기 때문에, 준비 점수는 그날 한 번이라도 앱을 열었거나 잠금이 풀린 상태에서 갱신됐을 때 정확해요.")
                    }
                }

                Section {
                    Toggle("기록 없는 항목도 표시", isOn: $model.showEmptyMetrics)
                    Toggle("샘플 데이터 사용", isOn: $model.demoMode)
                        .disabled(!model.isHealthDataAvailable)
                } footer: {
                    Text("샘플 데이터는 시뮬레이터처럼 건강 기록이 없는 환경에서 화면을 확인할 때 사용합니다.")
                }

                Section {
                    Button("건강 데이터 권한 다시 요청") {
                        Task { await model.connect() }
                    }
                    .disabled(!model.isHealthDataAvailable)
                    Button("건강 앱 열기") {
                        if let url = URL(string: "x-apple-health://") { openURL(url) }
                    }
                } header: {
                    Text("건강 데이터")
                } footer: {
                    Text("이미 거부한 항목은 설정 앱 › 건강 › 데이터 접근 및 기기 › 건강 대시보드에서 다시 허용할 수 있습니다.")
                }

                Section("정보") {
                    LabeledContent("표시 가능한 지표", value: "\(HealthMetric.all.count)개 + 활동 링 · 수면 · 운동")
                    LabeledContent("데이터 저장", value: "기기 내에서만 읽기")
                }
            }
            .onChange(of: briefingEnabled) { _, enabled in
                Task {
                    if enabled {
                        notificationDenied = !(await BriefingScheduler.requestAuthorization())
                    }
                    await BriefingScheduler.reschedule(report: weather.reports[primaryLocationID])
                }
            }
            .onChange(of: briefingHour) { _, _ in rescheduleBriefing() }
            .onChange(of: briefingMinute) { _, _ in rescheduleBriefing() }
            .onChange(of: primaryLocationID) { _, _ in rescheduleBriefing() }
            .navigationTitle("설정")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .confirmationAction) {
                    Button("완료") { dismiss() }
                }
            }
        }
    }

    private func rescheduleBriefing() {
        Task { await BriefingScheduler.reschedule(report: weather.reports[primaryLocationID]) }
    }
}
