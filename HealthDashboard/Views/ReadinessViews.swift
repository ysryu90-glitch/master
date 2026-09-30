import Charts
import SwiftUI

/// 0~10점 원형 게이지
struct ReadinessGauge: View {
    let score: ReadinessScore?
    var lineWidth: CGFloat = 12

    var body: some View {
        let color = score?.level.color ?? .gray
        ZStack {
            Circle()
                .stroke(color.opacity(0.18), lineWidth: lineWidth)
            Circle()
                .trim(from: 0, to: (score?.score ?? 0) / 10)
                .stroke(color.gradient, style: StrokeStyle(lineWidth: lineWidth, lineCap: .round))
                .rotationEffect(.degrees(-90))
                .animation(.easeOut(duration: 0.8), value: score?.score)
            VStack(spacing: 0) {
                Text(score?.scoreText ?? "–")
                    .font(.system(size: lineWidth * 2.6, weight: .bold, design: .rounded).monospacedDigit())
                Text("/ 10")
                    .font(.caption2)
                    .foregroundStyle(.secondary)
            }
        }
    }
}

struct ReadinessCard: View {
    let score: ReadinessScore?

    var body: some View {
        VStack(alignment: .leading, spacing: 14) {
            HStack {
                SectionHeader(title: "준비 점수", symbol: "gauge.with.needle.fill", tint: score?.level.color ?? .mint)
                Spacer()
                Image(systemName: "chevron.right")
                    .font(.footnote.weight(.semibold))
                    .foregroundStyle(.tertiary)
            }

            HStack(spacing: 18) {
                ReadinessGauge(score: score)
                    .frame(width: 104, height: 104)

                VStack(alignment: .leading, spacing: 6) {
                    if let score {
                        Label(score.level.title, systemImage: score.level.symbol)
                            .font(.title3.bold())
                            .foregroundStyle(score.level.color)
                        Text(score.level.advice)
                            .font(.subheadline)
                            .foregroundStyle(.secondary)
                            .fixedSize(horizontal: false, vertical: true)
                    } else {
                        Text("데이터 수집 중")
                            .font(.title3.bold())
                        Text("애플워치를 차고 며칠 잠들면 심박 변이·수면 기준선이 만들어져 점수가 계산됩니다.")
                            .font(.subheadline)
                            .foregroundStyle(.secondary)
                            .fixedSize(horizontal: false, vertical: true)
                    }
                }
            }
        }
        .card()
    }
}

struct ReadinessDetailView: View {
    @Environment(DashboardModel.self) private var model
    @Environment(\.openURL) private var openURL

    var body: some View {
        let today = model.todayReadiness

        ScrollView {
            VStack(alignment: .leading, spacing: 20) {
                VStack(spacing: 14) {
                    ReadinessGauge(score: today, lineWidth: 18)
                        .frame(width: 170, height: 170)
                    if let today {
                        Label(today.level.title, systemImage: today.level.symbol)
                            .font(.title2.bold())
                            .foregroundStyle(today.level.color)
                        Text(today.level.advice)
                            .multilineTextAlignment(.center)
                            .foregroundStyle(.secondary)
                    } else {
                        Text("오늘 점수를 계산할 데이터가 아직 부족합니다.")
                            .foregroundStyle(.secondary)
                    }
                }
                .frame(maxWidth: .infinity)
                .card()

                if let today {
                    VStack(alignment: .leading, spacing: 14) {
                        Text("점수 구성")
                            .font(.headline)
                        ForEach(today.components) { component in
                            ComponentRow(component: component)
                        }
                    }
                    .card()
                }

                if model.recentReadiness.count > 1 {
                    VStack(alignment: .leading, spacing: 12) {
                        Text("최근 7일")
                            .font(.headline)
                        Chart(model.recentReadiness) { day in
                            BarMark(
                                x: .value("날짜", day.date, unit: .day),
                                y: .value("점수", day.score)
                            )
                            .foregroundStyle(day.level.color.gradient)
                            .cornerRadius(4)
                            .annotation(position: .top) {
                                Text(day.scoreText)
                                    .font(.caption2.monospacedDigit())
                                    .foregroundStyle(.secondary)
                            }
                        }
                        .chartYScale(domain: 0...10)
                        .chartXAxis {
                            AxisMarks(values: .stride(by: .day)) { _ in
                                AxisValueLabel(format: .dateTime.weekday(.abbreviated))
                            }
                        }
                        .frame(height: 180)
                    }
                    .card()
                }

                VStack(alignment: .leading, spacing: 10) {
                    Label("이 점수에 대해", systemImage: "info.circle")
                        .font(.headline)
                    Text("애플워치 Series 12의 공식 '준비 점수'는 애플이 다른 앱에 제공하지 않습니다. 이 화면의 점수는 같은 원리(잠자는 동안의 심박 변이·심박수, 수면, 운동 부하, 야간 손목 온도·호흡수)를 최근 30일 내 기준선과 비교해 이 앱이 직접 계산한 추정치라, 공식 점수와 조금 다를 수 있습니다. 수면 기록이 1주일 미만이면 하루 평균 HRV와 안정 시 심박수로 대신 계산합니다.")
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                    Text("의료적 판단이 아닌 참고용 지표입니다.")
                        .font(.footnote)
                        .foregroundStyle(.tertiary)
                    Button {
                        if let url = URL(string: "x-apple-health://") { openURL(url) }
                    } label: {
                        Label("건강 앱에서 공식 준비 점수 보기", systemImage: "arrow.up.forward.app")
                    }
                    .padding(.top, 4)
                }
                .card()
            }
            .padding()
        }
        .background(Color(.systemGroupedBackground))
        .navigationTitle("준비 점수")
        .navigationBarTitleDisplayMode(.inline)
    }
}

private struct ComponentRow: View {
    let component: ReadinessComponent

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack {
                Label(component.title, systemImage: component.symbol)
                    .font(.subheadline.weight(.semibold))
                Spacer()
                Text(component.status)
                    .font(.caption.weight(.semibold))
                    .padding(.horizontal, 8)
                    .padding(.vertical, 2)
                    .background(component.statusColor.opacity(0.18), in: Capsule())
                    .foregroundStyle(component.statusColor)
            }
            ProgressView(value: component.score, total: 100)
                .tint(component.statusColor)
            Text(component.detail)
                .font(.caption)
                .foregroundStyle(.secondary)
        }
    }
}
