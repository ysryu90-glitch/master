import SwiftUI

struct DashboardView: View {
    @Environment(DashboardModel.self) private var model
    @Environment(\.scenePhase) private var scenePhase
    @Environment(WeatherModel.self) private var weather
    @Environment(AppRouter.self) private var router
    @State private var showSettings = false
    @State private var showEditor = false
    @State private var showNotifications = false
    @AppStorage(SharedStore.primaryLocationKey, store: SharedStore.defaults)
    private var primaryLocationID = SharedStore.autoLocationToken
    @AppStorage(SharedStore.autoLocationKey, store: SharedStore.defaults)
    private var autoLocationID: String?

    private var primaryLocation: WeatherLocation {
        SharedStore.resolveLocation(id: primaryLocationID, autoID: autoLocationID)
    }

    private let columns = [GridItem(.flexible(), spacing: 12), GridItem(.flexible(), spacing: 12)]

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 22) {
                    header

                    ForEach(model.layout.visibleSections) { section in
                        sectionView(section)
                    }

                    if model.values.isEmpty && !model.isLoading {
                        EmptyStateView()
                    }
                }
                .padding()
            }
            .background {
                // 위쪽에 오늘 컨디션 색이 은은하게 번지는 배경
                ZStack(alignment: .top) {
                    Color(.systemGroupedBackground)
                    LinearGradient(
                        colors: [(model.todayReadiness?.level.gradient.first ?? .pink).opacity(0.22), .clear],
                        startPoint: .top, endPoint: .bottom
                    )
                    .frame(height: 420)
                }
                .ignoresSafeArea()
            }
            .navigationTitle("건강")
            .navigationBarTitleDisplayMode(.inline)
            .navigationDestination(for: HealthMetric.self) { MetricDetailView(metric: $0) }
            .toolbar {
                ToolbarItemGroup(placement: .topBarTrailing) {
                    Button {
                        showNotifications = true
                    } label: {
                        Image(systemName: "bell")
                    }
                    Button {
                        showEditor = true
                    } label: {
                        Image(systemName: "slider.horizontal.3")
                    }
                    Button {
                        showSettings = true
                    } label: {
                        Image(systemName: "gearshape")
                    }
                }
            }
            .refreshable { await model.refresh() }
            .sheet(isPresented: $showSettings) { SettingsView() }
            .sheet(isPresented: $showEditor) { DashboardEditorView() }
            .sheet(isPresented: $showNotifications) {
                NavigationStack {
                    NotificationSettingsView()
                        .toolbar {
                            ToolbarItem(placement: .confirmationAction) {
                                Button("완료") { showNotifications = false }
                            }
                        }
                }
            }
            .task {
                await LocationService.shared.refreshIfNeeded()
                await model.refresh()
                await weather.refresh()
                await WeeklyReportStore.shared.generateIfNeeded()
                await KitchenStore.shared.reload()
            }
            .onChange(of: scenePhase) { _, phase in
                if phase == .active {
                    Task {
                        await LocationService.shared.refreshIfNeeded()
                        await model.refresh()
                        await weather.refresh()
                    }
                }
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

    @ViewBuilder
    private func sectionView(_ section: DashboardSection) -> some View {
        switch section {
        case .readiness:
            NavigationLink {
                ReadinessDetailView()
            } label: {
                ReadinessCard(score: model.todayReadiness)
            }
            .buttonStyle(.plain)

        case .medication:
            MedicationCard { showNotifications = true }

        case .earlyWarning:
            if let warning = model.earlyWarning {
                EarlyWarningCard(warning: warning)
            }

        case .habits:
            HabitCard()

        case .schedule:
            TodayScheduleCard()

        case .diet:
            Button {
                router.tab = .diet
            } label: {
                DietSummaryCard(showHeader: true)
            }
            .buttonStyle(.plain)

        case .recommendation:
            RecommendationCard(
                readiness: model.todayReadiness?.level,
                location: primaryLocation,
                report: weather.reports[primaryLocation.id]
            )

        case .favorites:
            let metrics = model.layout.favoriteMetrics
            if !metrics.isEmpty {
                metricGrid(title: section.title, symbol: section.symbol, tint: section.tint, metrics: metrics)
            }

        case .activityRings:
            ActivitySummaryCard(today: model.today, week: model.activity)

        case .sleep:
            NavigationLink {
                SleepDetailView()
            } label: {
                SleepSummaryCard(night: model.lastNight, mindfulMinutes: model.mindfulMinutesToday)
            }
            .buttonStyle(.plain)

        case .metrics(let category):
            let metrics = model.visibleMetrics(in: category)
            if !metrics.isEmpty {
                metricGrid(title: category.title, symbol: category.symbol, tint: category.tint, metrics: metrics)
            }

        case .workouts:
            WorkoutsSection(workouts: model.workouts)
        }
    }

    private func metricGrid(title: String, symbol: String, tint: Color, metrics: [HealthMetric]) -> some View {
        VStack(alignment: .leading, spacing: 12) {
            SectionHeader(title: title, symbol: symbol, tint: tint)
            LazyVGrid(columns: columns, spacing: 12) {
                ForEach(metrics) { metric in
                    NavigationLink(value: metric) {
                        MetricCard(metric: metric, value: model.values[metric.id])
                    }
                    .buttonStyle(.plain)
                    .contextMenu {
                        Button {
                            model.layout.toggleFavorite(metric)
                        } label: {
                            if model.layout.isFavorite(metric) {
                                Label("즐겨찾기에서 제거", systemImage: "star.slash")
                            } else {
                                Label("즐겨찾기에 추가", systemImage: "star")
                            }
                        }
                    }
                }
            }
        }
    }

    private var greeting: String {
        switch Calendar.current.component(.hour, from: .now) {
        case 5..<12: "좋은 아침이에요"
        case 12..<18: "좋은 오후예요"
        case 18..<23: "좋은 저녁이에요"
        default: "편안한 밤 되세요"
        }
    }

    private var header: some View {
        VStack(alignment: .leading, spacing: 6) {
            Text(Date.now.formatted(.dateTime.month().day().weekday(.wide)))
                .font(.subheadline.weight(.semibold))
                .foregroundStyle(.secondary)
            Text(greeting)
                .font(.system(size: 32, weight: .bold, design: .rounded))
            HStack(spacing: 6) {
                if model.isLoading {
                    ProgressView().controlSize(.mini)
                    Text("불러오는 중…")
                } else if let updated = model.lastUpdated {
                    Image(systemName: "arrow.clockwise")
                    Text("\(updated.formatted(date: .omitted, time: .shortened)) 업데이트")
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
        .padding(.top, 4)
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
