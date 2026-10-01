import SwiftUI

struct SleepSummaryCard: View {
    let night: SleepNight?
    let mindfulMinutes: Double

    var body: some View {
        VStack(alignment: .leading, spacing: 14) {
            HStack {
                SectionHeader(title: "수면", symbol: "bed.double.fill", tint: .indigo)
                Spacer()
                Image(systemName: "chevron.right")
                    .font(.footnote.weight(.semibold))
                    .foregroundStyle(.tertiary)
            }

            if let night {
                HStack(alignment: .firstTextBaseline) {
                    Text(night.asleep.hoursMinutesText)
                        .font(.title.weight(.semibold).monospacedDigit())
                    Spacer()
                    if let bedtime = night.bedtime, let wake = night.wakeTime {
                        Text("\(bedtime.formatted(date: .omitted, time: .shortened)) – \(wake.formatted(date: .omitted, time: .shortened))")
                            .font(.subheadline.monospacedDigit())
                            .foregroundStyle(.secondary)
                    }
                }
                SleepStageBar(night: night)
                    .frame(height: 14)
                SleepStageLegend(night: night)
            } else {
                Text("지난밤 수면 기록이 없습니다. 애플워치를 착용하고 잠들면 수면 단계가 기록됩니다.")
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
            }

            if LifeAlertSettings.load().sleepCoachEnabled, let plan = LifeAlerts.sleepPlan(for: .now) {
                Label {
                    Text("오늘 권장 취침 \(plan.bedtime.formatted(date: .omitted, time: .shortened))"
                         + (plan.reason.map { " · \($0)" } ?? ""))
                } icon: {
                    Image(systemName: "moon.zzz.fill")
                        .foregroundStyle(.indigo)
                }
                .font(.subheadline)
            }

            Divider()

            Label {
                Text("오늘 마음챙김 \(Int(mindfulMinutes))분")
            } icon: {
                Image(systemName: "brain.head.profile")
                    .foregroundStyle(.teal)
            }
            .font(.subheadline)
        }
        .card()
    }
}

/// 단계별 비율을 한 줄 막대로 표시
struct SleepStageBar: View {
    let night: SleepNight

    var body: some View {
        let stages = night.displayStages.filter { night.duration($0) > 0 }
        let total = stages.reduce(0) { $0 + night.duration($1) }

        GeometryReader { proxy in
            HStack(spacing: 2) {
                ForEach(stages) { stage in
                    stage.color
                        .frame(width: max(0, (proxy.size.width - CGFloat(stages.count - 1) * 2) * night.duration(stage) / max(total, 1)))
                }
            }
            .clipShape(Capsule())
        }
    }
}

struct SleepStageLegend: View {
    let night: SleepNight

    var body: some View {
        HStack(spacing: 12) {
            ForEach(night.displayStages) { stage in
                VStack(alignment: .leading, spacing: 2) {
                    HStack(spacing: 4) {
                        Circle().fill(stage.color).frame(width: 8, height: 8)
                        Text(stage.title)
                    }
                    .font(.caption2)
                    .foregroundStyle(.secondary)
                    Text(night.duration(stage).hoursMinutesText)
                        .font(.caption.weight(.semibold).monospacedDigit())
                }
                .frame(maxWidth: .infinity, alignment: .leading)
            }
        }
    }
}
