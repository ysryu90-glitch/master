import Charts
import SwiftUI

struct MetricDetailView: View {
    @Environment(DashboardModel.self) private var model
    let metric: HealthMetric

    @State private var range = 7
    @State private var points: [TrendPoint] = []
    @State private var isLoading = false

    private let ranges = [7, 30, 90]

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 20) {
                MetricCard(metric: metric, value: model.values[metric.id])

                Picker("기간", selection: $range) {
                    ForEach(ranges, id: \.self) { Text("\($0)일").tag($0) }
                }
                .pickerStyle(.segmented)

                VStack(alignment: .leading, spacing: 12) {
                    Text(metric.aggregation == .cumulative ? "일별 합계" : "일별 평균 (최저–최고)")
                        .font(.subheadline.weight(.semibold))
                        .foregroundStyle(.secondary)

                    if points.isEmpty {
                        ContentUnavailableView(
                            isLoading ? "불러오는 중…" : "기간 내 기록이 없습니다",
                            systemImage: metric.symbol
                        )
                        .frame(height: 240)
                    } else {
                        chart.frame(height: 240)
                    }
                }
                .card()

                if !points.isEmpty {
                    statistics
                }
            }
            .padding()
        }
        .background(Color(.systemGroupedBackground))
        .navigationTitle(metric.title)
        .navigationBarTitleDisplayMode(.inline)
        .task(id: range) {
            isLoading = true
            points = await model.trend(for: metric, days: range)
            isLoading = false
        }
    }

    private var chart: some View {
        Chart(points) { point in
            switch metric.aggregation {
            case .cumulative:
                BarMark(
                    x: .value("날짜", point.date, unit: .day),
                    y: .value(metric.title, point.value)
                )
                .foregroundStyle(metric.tint.gradient)
                .cornerRadius(3)

            case .discrete:
                if let low = point.min, let high = point.max, high > low {
                    RuleMark(
                        x: .value("날짜", point.date, unit: .day),
                        yStart: .value("최저", low),
                        yEnd: .value("최고", high)
                    )
                    .lineStyle(StrokeStyle(lineWidth: range > 30 ? 2 : 6, lineCap: .round))
                    .foregroundStyle(metric.tint.opacity(0.25))
                }
                LineMark(
                    x: .value("날짜", point.date, unit: .day),
                    y: .value(metric.title, point.value)
                )
                .interpolationMethod(.catmullRom)
                .foregroundStyle(metric.tint)
                PointMark(
                    x: .value("날짜", point.date, unit: .day),
                    y: .value(metric.title, point.value)
                )
                .symbolSize(range > 30 ? 10 : 30)
                .foregroundStyle(metric.tint)
            }
        }
        .chartYScale(domain: .automatic(includesZero: metric.aggregation == .cumulative))
        .chartXAxis {
            AxisMarks(values: .stride(by: .day, count: range == 7 ? 1 : range == 30 ? 7 : 30)) { _ in
                AxisGridLine()
                AxisValueLabel(format: .dateTime.month(.defaultDigits).day())
            }
        }
    }

    private var statistics: some View {
        let values = points.map(\.value)
        let average = values.reduce(0, +) / Double(values.count)
        let low = points.map { $0.min ?? $0.value }.min() ?? 0
        let high = points.map { $0.max ?? $0.value }.max() ?? 0

        return HStack {
            StatBlock(title: "평균", text: metric.formatted(average), unit: metric.unitLabel)
            Divider()
            StatBlock(title: "최저", text: metric.formatted(low), unit: metric.unitLabel)
            Divider()
            StatBlock(title: "최고", text: metric.formatted(high), unit: metric.unitLabel)
        }
        .card()
    }
}

private struct StatBlock: View {
    let title: String
    let text: String
    let unit: String

    var body: some View {
        VStack(spacing: 4) {
            Text(title)
                .font(.caption)
                .foregroundStyle(.secondary)
            Text(text)
                .font(.title3.weight(.semibold).monospacedDigit())
                .lineLimit(1)
                .minimumScaleFactor(0.6)
            Text(unit)
                .font(.caption2)
                .foregroundStyle(.secondary)
        }
        .frame(maxWidth: .infinity)
    }
}
