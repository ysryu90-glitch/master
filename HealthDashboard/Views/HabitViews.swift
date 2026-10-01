import SwiftUI

/// 컨디션 이상 조기 경보 카드 (경보가 있을 때만 대시보드에 보인다)
struct EarlyWarningCard: View {
    let warning: EarlyWarning

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            Label(warning.title, systemImage: "exclamationmark.triangle.fill")
                .font(.title3.bold())
                .foregroundStyle(warning.color)
            ForEach(warning.signals, id: \.self) { signal in
                Label(signal, systemImage: "arrow.up.right")
                    .font(.subheadline)
            }
            Text(warning.message)
                .font(.subheadline)
                .foregroundStyle(.secondary)
            Text("평소 기준선과 비교한 참고용 신호이며 의료적 진단이 아닙니다.")
                .font(.caption2)
                .foregroundStyle(.tertiary)
        }
        .card()
        .overlay(RoundedRectangle(cornerRadius: 16).stroke(warning.color.opacity(0.5), lineWidth: 1.5))
    }
}

/// 오늘의 습관 기록 카드
struct HabitCard: View {
    @Environment(DashboardModel.self) private var model
    @Environment(HabitStore.self) private var habits

    private let columns = [GridItem(.adaptive(minimum: 96), spacing: 8)]

    var body: some View {
        let date = HabitStore.loggingDate
        let selected = habits.tags(on: date)
        let logged = habits.isLogged(date)

        VStack(alignment: .leading, spacing: 12) {
            HStack {
                SectionHeader(title: "오늘의 습관", symbol: "list.bullet.clipboard.fill", tint: .teal)
                Spacer()
                NavigationLink("분석 보기") { HabitInsightsView() }
                    .font(.subheadline)
            }

            Text(logged ? "기록했어요. 필요하면 수정할 수 있어요." : "오늘 해당하는 것을 눌러 주세요. 쌓일수록 분석이 정확해져요.")
                .font(.caption)
                .foregroundStyle(.secondary)

            LazyVGrid(columns: columns, alignment: .leading, spacing: 8) {
                ForEach(HabitTag.allCases) { tag in
                    let isOn = selected.contains(tag)
                    Button {
                        withAnimation(.snappy) { habits.toggle(tag, on: date) }
                    } label: {
                        Text("\(tag.emoji) \(tag.title)")
                            .font(.subheadline)
                            .lineLimit(1)
                            .minimumScaleFactor(0.8)
                            .padding(.horizontal, 10)
                            .padding(.vertical, 8)
                            .frame(maxWidth: .infinity)
                            .background(isOn ? Color.teal.opacity(0.25) : Color(.tertiarySystemFill), in: Capsule())
                            .overlay(Capsule().stroke(isOn ? Color.teal : .clear, lineWidth: 1.5))
                    }
                    .buttonStyle(.plain)
                }

                Button {
                    withAnimation(.snappy) { habits.markNothing(on: date) }
                } label: {
                    Text("✅ 특별한 일 없음")
                        .font(.subheadline)
                        .lineLimit(1)
                        .minimumScaleFactor(0.8)
                        .padding(.horizontal, 10)
                        .padding(.vertical, 8)
                        .frame(maxWidth: .infinity)
                        .background(logged && selected.isEmpty ? Color.green.opacity(0.25) : Color(.tertiarySystemFill), in: Capsule())
                }
                .buttonStyle(.plain)
            }

            if let top = model.habitInsights(habits).first(where: { $0.confidence != .weak }), let headline = top.headline {
                Label(headline, systemImage: "lightbulb.fill")
                    .font(.footnote)
                    .foregroundStyle(.secondary)
            }
        }
        .card()
    }
}

/// 습관 분석 화면
struct HabitInsightsView: View {
    @Environment(DashboardModel.self) private var model
    @Environment(HabitStore.self) private var habits

    var body: some View {
        let insights = model.habitInsights(habits)
        let loggedDays = (1...30).filter { offset in
            Calendar.current.date(byAdding: .day, value: -offset, to: .now).map(habits.isLogged) ?? false
        }.count

        List {
            Section {
                VStack(alignment: .leading, spacing: 6) {
                    Text("최근 30일 중 \(loggedDays)일 기록")
                        .font(.headline)
                    Text("습관이 있던 날과 없던 날의 다음 날 준비 점수, 그날 밤 수면, 다음 날 아침 HRV를 비교해요. 각각 3일 이상 쌓여야 결과가 나와요. 취침 시각과 운동은 자동으로 분석해요.")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
            }

            if insights.isEmpty {
                Section {
                    ContentUnavailableView(
                        "아직 분석할 데이터가 부족해요",
                        systemImage: "chart.bar.doc.horizontal",
                        description: Text("대시보드의 '오늘의 습관'을 1~2주 기록하면 나에게 어떤 습관이 영향을 주는지 보여드릴게요.")
                    )
                }
            }

            ForEach(insights) { insight in
                Section {
                    VStack(alignment: .leading, spacing: 10) {
                        HStack {
                            Text("\(insight.emoji) \(insight.title)")
                                .font(.headline)
                            Spacer()
                            Text(insight.confidence.rawValue)
                                .font(.caption2.weight(.semibold))
                                .padding(.horizontal, 6)
                                .padding(.vertical, 2)
                                .background(confidenceColor(insight.confidence).opacity(0.18), in: Capsule())
                                .foregroundStyle(confidenceColor(insight.confidence))
                            Text("\(insight.count)일 vs \(insight.comparisonCount)일")
                                .font(.caption)
                                .foregroundStyle(.secondary)
                        }
                        if let headline = insight.headline {
                            Text(headline)
                                .font(.subheadline)
                        }
                        ForEach(insight.effects, id: \.label) { effect in
                            HStack {
                                Text(effect.label)
                                    .foregroundStyle(.secondary)
                                Spacer()
                                Text(effect.text.replacingOccurrences(of: effect.label + " ", with: ""))
                                    .font(.subheadline.weight(.semibold).monospacedDigit())
                                    .foregroundStyle(abs(effect.difference) < 0.05 ? Color.secondary : (effect.isGood ? Color.green : Color.red))
                            }
                            .font(.subheadline)
                        }
                    }
                    .padding(.vertical, 4)
                }
            }

            Section {
                Text("상관관계일 뿐 원인이라고 단정할 수는 없어요. 기록이 많을수록 믿을 만해져요.")
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }
        }
        .navigationTitle("습관 분석")
        .navigationBarTitleDisplayMode(.inline)
    }

    private func confidenceColor(_ confidence: HabitInsight.Confidence) -> Color {
        switch confidence {
        case .weak: .gray
        case .moderate: .orange
        case .strong: .green
        }
    }
}
