import SwiftUI
import UniformTypeIdentifiers

struct SettingsView: View {
    @Environment(DashboardModel.self) private var model
    @Environment(\.dismiss) private var dismiss
    @Environment(\.openURL) private var openURL
    @State private var backup = BackupManager.shared
    @State private var importMode: ImportMode?
    /// 파일 선택 창이 닫힌 뒤에도 어떤 용도였는지 기억
    @State private var activeImport: ImportMode = .folder
    @State private var pendingRestore: URL?

    private enum ImportMode: Identifiable {
        case folder, restore
        var id: Self { self }
    }

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

                backupSection

                Section {
                    Picker("기기 모드", selection: $model.deviceMode) {
                        ForEach(DashboardModel.DeviceMode.allCases) { Text($0.title).tag($0) }
                    }
                    Toggle("기록 없는 항목도 표시", isOn: $model.showEmptyMetrics)
                    Toggle("샘플 데이터 사용", isOn: $model.demoMode)
                        .disabled(!model.isHealthDataAvailable)
                } footer: {
                    Text("기기 모드 '자동'은 애플워치 기록이 없으면 걸음 수 중심의 아이폰 모드로 보여줘요. 샘플 데이터는 시뮬레이터처럼 건강 기록이 없는 환경에서 화면을 확인할 때 사용합니다.")
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
                    if AppExpiry.expirationDate != nil {
                        LabeledContent("앱 사용 기한", value: AppExpiry.summary)
                    }
                    if let updated = SharedStore.healthSnapshot?.updatedAt {
                        LabeledContent("마지막 갱신", value: updated.formatted(date: .abbreviated, time: .shortened))
                    }
                }
            }
            .fileImporter(
                isPresented: Binding(get: { importMode != nil }, set: { if !$0 { importMode = nil } }),
                allowedContentTypes: activeImport == .folder ? [.folder] : [.json]
            ) { result in
                guard case .success(let url) = result else { return }
                if activeImport == .folder {
                    backup.setFolder(url)
                    Task { await backup.backupNow() }
                } else {
                    pendingRestore = url
                }
                importMode = nil
            }
            .alert("이 백업으로 복원할까요?", isPresented: Binding(
                get: { pendingRestore != nil }, set: { if !$0 { pendingRestore = nil } }
            )) {
                Button("복원", role: .destructive) {
                    if let url = pendingRestore {
                        Task { await backup.restore(from: url) }
                    }
                    pendingRestore = nil
                }
                Button("취소", role: .cancel) { pendingRestore = nil }
            } message: {
                Text("지금 앱에 있는 식단 · 복약 · 습관 기록과 설정이 백업 내용으로 바뀌어요.")
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

    private var backupSection: some View {
        Section {
            Button {
                activeImport = .folder
                importMode = .folder
            } label: {
                LabeledContent("자동 백업 폴더", value: backup.folderName ?? "선택 안 함")
            }
            Button {
                Task { await backup.backupNow() }
            } label: {
                HStack {
                    Label("지금 백업하기", systemImage: "externaldrive.badge.icloud")
                    Spacer()
                    if backup.isWorking { ProgressView() }
                }
            }
            .disabled(!backup.hasFolder || backup.isWorking)
            Button {
                activeImport = .restore
                importMode = .restore
            } label: {
                Label("백업에서 복원하기", systemImage: "arrow.counterclockwise.icloud")
            }
            .disabled(backup.isWorking)
            if let date = backup.lastBackupDate {
                LabeledContent("마지막 백업", value: date.formatted(date: .abbreviated, time: .shortened))
            }
            if let message = backup.message {
                Text(message)
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }
        } header: {
            Text("백업 · 복원")
        } footer: {
            Text("파일 앱에서 iCloud Drive 안의 폴더(예: '건강대시보드')를 고르면, 앱을 열 때 하루 한 번 자동으로 백업하고 최근 7개를 보관해요. 앱을 지웠다 다시 깔거나 아이폰을 바꿔도 '백업에서 복원하기'로 식단(사진 포함) · 복약 · 습관 · 리포트 · 설정을 되살릴 수 있어요.")
        }
    }
}
