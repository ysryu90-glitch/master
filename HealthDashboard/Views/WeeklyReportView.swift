import SwiftUI

/// AI 주간 리포트 목록 (AI 코치 탭에서 열기)
struct WeeklyReportView: View {
    @Environment(WeeklyReportStore.self) private var store
    @State private var showStats: WeeklyReport.ID?

    var body: some View {
        List {
            Section {
                Button {
                    Task { await store.regenerateLastWeek() }
                } label: {
                    HStack {
                        Label(store.reports.isEmpty ? "지난주 리포트 만들기" : "지난주 리포트 다시 만들기", systemImage: "sparkles")
                        Spacer()
                        if store.isGenerating { ProgressView() }
                    }
                }
                .disabled(store.isGenerating)
            } footer: {
                Text("매주 월요일, 지난주(월~일) 기록을 그 전주와 비교해 AI가 리포트를 써서 알림으로 보내 드려요. 월요일에 앱을 한 번 열거나 워치 데이터로 앱이 깨어날 때 만들어져요.")
            }

            if store.reports.isEmpty && !store.isGenerating {
                Section {
                    ContentUnavailableView("아직 리포트가 없어요", systemImage: "doc.text.magnifyingglass",
                                           description: Text("위 버튼을 누르면 지난주 리포트를 바로 만들어요."))
                }
            }

            ForEach(store.reports) { report in
                Section {
                    VStack(alignment: .leading, spacing: 10) {
                        Text(report.text)
                            .font(.body)
                            .textSelection(.enabled)
                        DisclosureGroup("근거 데이터 보기", isExpanded: Binding(
                            get: { showStats == report.id },
                            set: { showStats = $0 ? report.id : nil }
                        )) {
                            Text(report.stats)
                                .font(.caption.monospacedDigit())
                                .foregroundStyle(.secondary)
                                .textSelection(.enabled)
                        }
                        .font(.caption)
                    }
                    .padding(.vertical, 4)
                } header: {
                    Text("📊 \(report.title)")
                } footer: {
                    Text("\(report.generatedAt.formatted(date: .abbreviated, time: .shortened)) 작성 · AI 리포트는 참고용이에요")
                }
            }
        }
        .navigationTitle("주간 리포트")
        .navigationBarTitleDisplayMode(.inline)
    }
}
