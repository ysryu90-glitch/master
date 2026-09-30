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

                VStack(alignment: .leading, spacing: 12) {
                    Text("최근 \(model.sleepNights.count)일 수면")
                        .font(.subheadline.weight(.semibold))
                        .foregroundStyle(.secondary)

                    if model.sleepNights.isEmpty {
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
            ForEach(model.sleepNights) { night in
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
        let nights = model.sleepNights
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
}
