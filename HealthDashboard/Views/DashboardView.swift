import SwiftUI

struct DashboardView: View {
    @Environment(DashboardModel.self) private var model
    @Environment(\.scenePhase) private var scenePhase
    @State private var showSettings = false

    private let columns = [GridItem(.flexible(), spacing: 12), GridItem(.flexible(), spacing: 12)]

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 24) {
                    header

                    ActivitySummaryCard(today: model.today, week: model.activity)

                    NavigationLink {
                        SleepDetailView()
                    } label: {
                        SleepSummaryCard(night: model.lastNight, mindfulMinutes: model.mindfulMinutesToday)
                    }
                    .buttonStyle(.plain)

                    ForEach(MetricCategory.allCases) { category in
                        let metrics = model.visibleMetrics(in: category)
                        if !metrics.isEmpty {
                            VStack(alignment: .leading, spacing: 12) {
                                SectionHeader(title: category.title, symbol: category.symbol, tint: category.tint)
                                LazyVGrid(columns: columns, spacing: 12) {
                                    ForEach(metrics) { metric in
                                        NavigationLink(value: metric) {
                                            MetricCard(metric: metric, value: model.values[metric.id])
                                        }
                                        .buttonStyle(.plain)
                                    }
                                }
                            }
                        }
                    }

                    WorkoutsSection(workouts: model.workouts)

                    if model.values.isEmpty && !model.isLoading {
                        EmptyStateView()
                    }
                }
                .padding()
            }
            .background(Color(.systemGroupedBackground))
            .navigationTitle("요약")
            .navigationDestination(for: HealthMetric.self) { MetricDetailView(metric: $0) }
            .toolbar {
                ToolbarItem(placement: .topBarTrailing) {
                    Button {
                        showSettings = true
                    } label: {
                        Image(systemName: "gearshape")
                    }
                }
            }
            .refreshable { await model.refresh() }
            .sheet(isPresented: $showSettings) { SettingsView() }
            .task { await model.refresh() }
            .onChange(of: scenePhase) { _, phase in
                if phase == .active { Task { await model.refresh() } }
            }
            .alert("오류", isPresented: Binding(
                get: { model.errorMessage != nil },
                set: { if !$0 { model.errorMessage = nil } }
            )) {
                Button("확인", role: .cancel) {}
            } message: {
                Text(model.errorMessage ?? "")
            }
        }
    }

    private var header: some View {
        VStack(alignment: .leading, spacing: 4) {
            Text(Date.now.formatted(.dateTime.month().day().weekday(.wide)))
                .font(.subheadline.weight(.semibold))
                .foregroundStyle(.secondary)
            HStack(spacing: 6) {
                if model.isLoading {
                    ProgressView().controlSize(.mini)
                    Text("불러오는 중…")
                } else if let updated = model.lastUpdated {
                    Text("업데이트: \(updated.formatted(date: .omitted, time: .shortened))")
                }
                if model.demoMode {
                    Text("샘플 데이터")
                        .padding(.horizontal, 6)
                        .padding(.vertical, 2)
                        .background(.yellow.opacity(0.3), in: Capsule())
                }
            }
            .font(.caption)
            .foregroundStyle(.secondary)
        }
    }
}

private struct EmptyStateView: View {
    var body: some View {
        ContentUnavailableView {
            Label("표시할 데이터가 없습니다", systemImage: "heart.slash")
        } description: {
            Text("설정 앱 › 건강 › 데이터 접근 및 기기에서 이 앱의 읽기 권한을 확인하세요.")
        }
    }
}
