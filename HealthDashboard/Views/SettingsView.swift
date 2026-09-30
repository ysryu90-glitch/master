import SwiftUI

struct SettingsView: View {
    @Environment(DashboardModel.self) private var model
    @Environment(\.dismiss) private var dismiss
    @Environment(\.openURL) private var openURL

    var body: some View {
        @Bindable var model = model

        NavigationStack {
            Form {
                Section {
                    NavigationLink {
                        NotificationSettingsView()
                    } label: {
                        Label("알림 (컨디션 리포트 · 복약)", systemImage: "bell.badge.fill")
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
                    LabeledContent("데이터 저장", value: "기기 안 · 식단만 건강 앱에 저장")
                    LabeledContent("식단 기록", value: "\(MealStore.shared.meals.count)끼")
                    if let updated = SharedStore.healthSnapshot?.updatedAt {
                        LabeledContent("마지막 갱신", value: updated.formatted(date: .abbreviated, time: .shortened))
                    }
                }
            }
            .navigationTitle("설정")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .confirmationAction) {
                    Button("완료") { dismiss() }
                }
            }
        }
    }
}
