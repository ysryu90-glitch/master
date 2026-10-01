import Charts
import SwiftUI

struct SleepDetailView: View {
    @Environment(DashboardModel.self) private var model

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 20) {
                if let night = model.lastNight {
                    VStack(alignment: .leading, spacing: 12) {
                        Text("지난밤 · \(night.wakeDate.formatted(.dateTime.month().day()))")
                            .font(.subheadline.weight(.semibold))
                            .foregroundStyle(.secondary)
                        Text(night.asleep.hoursMinutesText)
                            .font(.largeTitle.weight(.semibold).monospacedDigit())
                        SleepStageBar(night: night)
                            .frame(height: 16)
                        SleepStageLegend(night: night)
                        if night.inBed > 0 {
                            Text("침대에 있던 시간 \(night.inBed.hoursMinutesText)")
                                .font(.footnote)
                                .foregroundStyle(.secondary)
                        }
                    }
                    .card()
                }

                if let night = model.lastNight, !night.segments.isEmpty {
                    hypnogram(night)
                }

                consistencyCard

                VStack(alignment: .leading, spacing: 12) {
                    Text("최근 \(model.recentSleepNights.count)일 수면")
                        .font(.subheadline.weight(.semibold))
                        .foregroundStyle(.secondary)

                    if model.recentSleepNights.isEmpty {
                        ContentUnavailableView("수면 기록이 없습니다", systemImage: "bed.double")
                            .frame(height: 240)
                    } else {
                        chart.frame(height: 260)
                        averageRow
                    }
                }
                .card()
            }
            .padding()
        }
        .background(Color(.systemGroupedBackground))
        .navigationTitle("수면")
        .navigationBarTitleDisplayMode(.inline)
    }

    private var chart: some View {
        let stages: [SleepStage] = [.deep, .core, .rem, .unspecified, .awake]
        return Chart {
            ForEach(model.recentSleepNights) { night in
                ForEach(stages) { stage in
                    BarMark(
                        x: .value("날짜", night.wakeDate, unit: .day),
                        y: .value("시간", night.duration(stage) / 3600)
                    )
                    .foregroundStyle(by: .value("단계", stage.title))
                }
            }
        }
        .chartForegroundStyleScale(domain: stages.map(\.title), range: stages.map(\.color))
        .chartYAxis {
            AxisMarks { value in
                AxisGridLine()
                AxisValueLabel {
                    if let hours = value.as(Double.self) { Text("\(Int(hours))시간") }
                }
            }
        }
        .chartXAxis {
            AxisMarks(values: .stride(by: .day, count: 2)) { _ in
                AxisValueLabel(format: .dateTime.month(.defaultDigits).day())
            }
        }
    }

    private var averageRow: some View {
        let nights = model.recentSleepNights
        let average = nights.reduce(0) { $0 + $1.asleep } / Double(max(nights.count, 1))
        return HStack {
            Text("평균 수면 시간")
                .foregroundStyle(.secondary)
            Spacer()
            Text(average.hoursMinutesText)
                .font(.headline.monospacedDigit())
        }
        .font(.subheadline)
    }

    // MARK: - 수면 흐름

    private func hypnogram(_ night: SleepNight) -> some View {
        let order: [SleepStage] = [.awake, .rem, .core, .deep, .unspecified]
        let segments = night.segments.sorted { $0.start < $1.start }
        return VStack(alignment: .leading, spacing: 10) {
            Text("지난밤 수면 흐름")
                .font(.headline)
            Chart(segments) { segment in
                RectangleMark(
                    xStart: .value("시작", segment.start),
                    xEnd: .value("끝", segment.end),
                    y: .value("단계", segment.stage.title)
                )
                .foregroundStyle(segment.stage.color)
                .cornerRadius(2)
            }
            .chartYScale(domain: order.filter { stage in segments.contains { $0.stage == stage } }.map(\.title))
            .chartXAxis {
                AxisMarks(values: .stride(by: .hour, count: 2)) { _ in
                    AxisGridLine()
                    AxisValueLabel(format: .dateTime.hour())
                }
            }
            .frame(height: 170)
            Text("깊은 수면은 주로 잠든 뒤 처음 몇 시간에, 렘 수면은 새벽에 많아요.")
                .font(.caption)
                .foregroundStyle(.secondary)
        }
        .card()
    }

    // MARK: - 취침 규칙성

    /// 최근 14일 잠든 시각의 흩어진 정도 → 0~100점 (표준편차 15분 이하 100점, 90분 이상 0점)
    private var consistency: (score: Int, average: Date, spread: Int)? {
        let calendar = Calendar.current
        let bedtimes = model.recentSleepNights.compactMap(\.bedtime)
        guard bedtimes.count >= 5 else { return nil }
        // 오후 6시 기준 분 (자정을 넘겨도 이어지게)
        let minutes = bedtimes.map { date -> Double in
            let hour = calendar.component(.hour, from: date)
            let minute = calendar.component(.minute, from: date)
            let value = Double(hour * 60 + minute)
            return value < 18 * 60 ? value + 24 * 60 - 18 * 60 : value - 18 * 60
        }
        let mean = minutes.reduce(0, +) / Double(minutes.count)
        let spread = (minutes.reduce(0) { $0 + pow($1 - mean, 2) } / Double(minutes.count)).squareRoot()
        let score = Int(max(0, min(100, 100 - (spread - 15) * 100 / 75)).rounded())
        let averageDate = calendar.date(bySettingHour: 18, minute: 0, second: 0, of: .now)!.addingTimeInterval(mean * 60)
        return (score, averageDate, Int(spread.rounded()))
    }

    @ViewBuilder
    private var consistencyCard: some View {
        if let consistency {
            HStack(spacing: 16) {
                ZStack {
                    Circle().stroke(Color.indigo.opacity(0.2), lineWidth: 8)
                    Circle()
                        .trim(from: 0, to: Double(consistency.score) / 100)
                        .stroke(Color.indigo, style: StrokeStyle(lineWidth: 8, lineCap: .round))
                        .rotationEffect(.degrees(-90))
                    Text("\(consistency.score)")
                        .font(.title3.bold().monospacedDigit())
                }
                .frame(width: 64, height: 64)
                VStack(alignment: .leading, spacing: 4) {
                    Text("취침 시각 규칙성")
                        .font(.headline)
                    Text("평균 \(consistency.average.formatted(date: .omitted, time: .shortened)) ± \(consistency.spread)분 (최근 14일)")
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                    Text(consistency.score >= 70 ? "일정하게 잘 지키고 있어요 👍" : "잠드는 시각이 들쭉날쭉해요. 30분 안으로 맞춰 보세요.")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
            }
            .card()
        }
    }
}
