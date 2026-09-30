import SwiftUI

/// 오늘의 운동 추천 (준비 점수 + 기본 지역 날씨)
struct RecommendationCard: View {
    let readiness: ReadinessLevel?
    let location: WeatherLocation
    let report: WeatherReport?

    var body: some View {
        let recommendation = WorkoutAdvisor.recommend(
            readiness: readiness, forecast: report?.today, airGrade: report?.airQuality?.overallGrade
        )

        VStack(alignment: .leading, spacing: 12) {
            SectionHeader(title: "오늘의 운동 추천", symbol: "figure.run.circle.fill", tint: .green)

            HStack(spacing: 14) {
                Image(systemName: recommendation.symbol)
                    .font(.title)
                    .foregroundStyle(.white)
                    .frame(width: 56, height: 56)
                    .background((readiness?.color ?? .green).gradient, in: RoundedRectangle(cornerRadius: 14))

                VStack(alignment: .leading, spacing: 4) {
                    Text(recommendation.title)
                        .font(.headline)
                    HStack(spacing: 6) {
                        Label(recommendation.isOutdoor ? "야외" : "실내",
                              systemImage: recommendation.isOutdoor ? "sun.max" : "house")
                        if let report {
                            Text("· \(location.name) \(report.current.temperature.degreesText) \(report.current.condition.description)")
                        }
                    }
                    .font(.caption)
                    .foregroundStyle(.secondary)
                }
            }

            ForEach(recommendation.notes, id: \.self) { note in
                Text(note)
                    .font(.footnote)
                    .foregroundStyle(.secondary)
            }

            if report == nil {
                Text("날씨를 불러오는 중이거나 인터넷에 연결되어 있지 않아요.")
                    .font(.footnote)
                    .foregroundStyle(.tertiary)
            }
        }
        .card()
    }
}

/// 대시보드 섹션 순서 · 표시 여부 · 즐겨찾기 지표 편집
struct DashboardEditorView: View {
    @Environment(DashboardModel.self) private var model
    @Environment(\.dismiss) private var dismiss

    var body: some View {
        NavigationStack {
            List {
                Section {
                    ForEach(model.layout.order) { section in
                        HStack(spacing: 12) {
                            Image(systemName: section.symbol)
                                .foregroundStyle(section.tint)
                                .frame(width: 26)
                            Text(section.title)
                                .foregroundStyle(model.layout.hidden.contains(section) ? .secondary : .primary)
                            Spacer()
                            Button {
                                toggleHidden(section)
                            } label: {
                                Image(systemName: model.layout.hidden.contains(section) ? "eye.slash" : "eye")
                                    .foregroundStyle(model.layout.hidden.contains(section) ? Color.secondary : Color.accentColor)
                            }
                            .buttonStyle(.borderless)
                        }
                    }
                    .onMove { from, to in
                        model.layout.order.move(fromOffsets: from, toOffset: to)
                    }
                } header: {
                    Text("섹션 순서")
                } footer: {
                    Text("≡ 를 끌어 순서를 바꾸고, 눈 아이콘으로 숨기거나 보이게 할 수 있어요.")
                }

                Section {
                    ForEach(model.layout.favoriteMetrics) { metric in
                        Label(metric.title, systemImage: metric.symbol)
                            .foregroundStyle(metric.tint)
                    }
                    .onMove { from, to in
                        model.layout.favorites.move(fromOffsets: from, toOffset: to)
                    }
                    .onDelete { offsets in
                        let ids = offsets.map { model.layout.favoriteMetrics[$0].id.rawValue }
                        model.layout.favorites.removeAll { ids.contains($0) }
                    }
                } header: {
                    Text("즐겨찾기 순서")
                } footer: {
                    Text("대시보드에서 지표 카드를 길게 눌러도 즐겨찾기에 추가하거나 뺄 수 있어요.")
                }

                ForEach(MetricCategory.allCases) { category in
                    Section(category.title) {
                        ForEach(HealthMetric.metrics(in: category)) { metric in
                            Button {
                                model.layout.toggleFavorite(metric)
                            } label: {
                                HStack {
                                    Label(metric.title, systemImage: metric.symbol)
                                        .foregroundStyle(.primary)
                                    Spacer()
                                    Image(systemName: model.layout.isFavorite(metric) ? "star.fill" : "star")
                                        .foregroundStyle(.yellow)
                                }
                            }
                        }
                    }
                }

                Section {
                    Button("기본 배치로 되돌리기", role: .destructive) {
                        model.layout = DashboardLayout()
                    }
                }
            }
            .environment(\.editMode, .constant(.active))
            .navigationTitle("대시보드 편집")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .confirmationAction) {
                    Button("완료") { dismiss() }
                }
            }
        }
    }

    private func toggleHidden(_ section: DashboardSection) {
        if model.layout.hidden.contains(section) {
            model.layout.hidden.remove(section)
        } else {
            model.layout.hidden.insert(section)
        }
    }
}
