import HealthKit
import HealthKitUI
import SwiftUI

/// 애플 기본 활동 링 (HealthKitUI)
struct ActivityRingView: UIViewRepresentable {
    let summary: HKActivitySummary?

    func makeUIView(context: Context) -> HKActivityRingView {
        HKActivityRingView()
    }

    func updateUIView(_ view: HKActivityRingView, context: Context) {
        view.setActivitySummary(summary, animated: true)
    }
}

struct ActivitySummaryCard: View {
    let today: DailyActivity?
    let week: [DailyActivity]

    var body: some View {
        VStack(alignment: .leading, spacing: 16) {
            SectionHeader(title: "활동 링", symbol: "circle.circle", tint: .red)

            HStack(spacing: 20) {
                ActivityRingView(summary: today?.summary)
                    .frame(width: 110, height: 110)

                VStack(alignment: .leading, spacing: 10) {
                    RingStat(title: "움직이기", tint: .red, value: today?.move, goal: today?.moveGoal, unit: "kcal")
                    RingStat(title: "운동하기", tint: .green, value: today?.exercise, goal: today?.exerciseGoal, unit: "분")
                    RingStat(title: "일어서기", tint: .cyan, value: today?.stand, goal: today?.standGoal, unit: "시간")
                }
            }

            if week.count > 1 {
                Divider()
                HStack {
                    ForEach(week) { day in
                        VStack(spacing: 4) {
                            ActivityRingView(summary: day.summary)
                                .frame(width: 34, height: 34)
                            Text(day.date.formatted(.dateTime.weekday(.narrow)))
                                .font(.caption2)
                                .foregroundStyle(Calendar.current.isDateInToday(day.date) ? .primary : .secondary)
                        }
                        .frame(maxWidth: .infinity)
                    }
                }
            }
        }
        .card()
    }
}

private struct RingStat: View {
    let title: String
    let tint: Color
    let value: Double?
    let goal: Double?
    let unit: String

    var body: some View {
        VStack(alignment: .leading, spacing: 2) {
            Text(title)
                .font(.caption.weight(.semibold))
                .foregroundStyle(tint)
            if let value, let goal {
                Text("\(Int(value))/\(Int(goal))\(unit)")
                    .font(.headline.monospacedDigit())
            } else {
                Text("—")
                    .font(.headline)
                    .foregroundStyle(.tertiary)
            }
        }
    }
}
