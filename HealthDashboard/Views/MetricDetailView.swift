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
                        chart.frame(height: 270)
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

    /// 평소 범위 (평균 ± 표준편차) — 측정형 지표, 7일 이상 기록이 있을 때
    private var baseline: (low: Double, high: Double)? {
        guard metric.aggregation == .discrete, points.count >= 7 else { return nil }
        let values = points.map(\.value)
        let mean = values.reduce(0, +) / Double(values.count)
        let deviation = (values.reduce(0) { $0 + pow($1 - mean, 2) } / Double(values.count)).squareRoot()
        guard deviation > 0 else { return nil }
        return (mean - deviation, mean + deviation)
    }

    private struct DayMarker: Identifiable {
        let date: Date
        let label: String
        let color: Color
        var id: String { "\(label)-\(date.timeIntervalSince1970)" }
    }

    /// 운동한 날 · 술 마신 날 표시
    private var markers: [DayMarker] {
        guard let first = points.first?.date else { return [] }
        let calendar = Calendar.current
        let workoutDays = Set(model.workouts.map { calendar.startOfDay(for: $0.start) }).filter { $0 >= first }
        var result = workoutDays.map { DayMarker(date: $0, label: "운동", color: .green) }
        let habits = HabitStore.shared
        for offset in 0..<range {
            guard let day = calendar.date(byAdding: .day, value: -offset, to: calendar.startOfDay(for: .now)),
                  habits.tags(on: day).contains(.alcohol) else { continue }
            result.append(DayMarker(date: day, label: "술", color: .red))
        }
        return result
    }

    private var chart: some View {
        VStack(alignment: .leading, spacing: 8) {
            Chart {
                if let baseline, let first = points.first?.date, let last = points.last?.date {
                    RectangleMark(
                        xStart: .value("시작", first, unit: .day),
                        xEnd: .value("끝", last, unit: .day),
                        yStart: .value("평소 하한", baseline.low),
                        yEnd: .value("평소 상한", baseline.high)
                    )
                    .foregroundStyle(metric.tint.opacity(0.08))
                }

                ForEach(markers) { marker in
                    RuleMark(x: .value("날짜", marker.date, unit: .day))
                        .foregroundStyle(marker.color.opacity(0.35))
                        .lineStyle(StrokeStyle(lineWidth: 2, dash: [3, 3]))
                }

                ForEach(points) { point in
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
            }
            .chartYScale(domain: .automatic(includesZero: metric.aggregation == .cumulative))
            .chartXAxis {
                AxisMarks(values: .stride(by: .day, count: range == 7 ? 1 : range == 30 ? 7 : 30)) { _ in
                    AxisGridLine()
                    AxisValueLabel(format: .dateTime.month(.defaultDigits).day())
                }
            }

            HStack(spacing: 12) {
                if let baseline {
                    legend(color: metric.tint.opacity(0.3),
                           text: "평소 범위 \(metric.formatted(baseline.low))~\(metric.formatted(baseline.high))")
                }
                if markers.contains(where: { $0.label == "운동" }) { legend(color: .green, text: "운동한 날") }
                if markers.contains(where: { $0.label == "술" }) { legend(color: .red, text: "술 마신 날") }
            }
            .font(.caption2)
            .foregroundStyle(.secondary)
        }
    }

    private func legend(color: Color, text: String) -> some View {
        HStack(spacing: 4) {
            RoundedRectangle(cornerRadius: 2).fill(color).frame(width: 10, height: 10)
            Text(text)
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
