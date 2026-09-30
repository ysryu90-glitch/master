import SwiftUI

struct SectionHeader: View {
    let title: String
    let symbol: String
    let tint: Color

    var body: some View {
        Label(title, systemImage: symbol)
            .font(.title3.bold())
            .foregroundStyle(tint)
    }
}

/// 카드 공통 배경
struct CardBackground: ViewModifier {
    func body(content: Content) -> some View {
        content
            .padding()
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(Color(.secondarySystemGroupedBackground), in: RoundedRectangle(cornerRadius: 16))
    }
}

extension View {
    func card() -> some View { modifier(CardBackground()) }
}

struct MetricCard: View {
    let metric: HealthMetric
    let value: MetricValue?

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            Label(metric.title, systemImage: metric.symbol)
                .font(.caption.weight(.semibold))
                .foregroundStyle(metric.tint)
                .lineLimit(1)

            if let value {
                HStack(alignment: .firstTextBaseline, spacing: 3) {
                    Text(metric.formatted(value.value))
                        .font(.title2.weight(.semibold).monospacedDigit())
                        .minimumScaleFactor(0.6)
                        .lineLimit(1)
                    Text(metric.unitLabel)
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
                Text(caption(for: value))
                    .font(.caption2)
                    .foregroundStyle(.secondary)
            } else {
                Text("데이터 없음")
                    .font(.title3)
                    .foregroundStyle(.tertiary)
                Text(" ").font(.caption2)
            }
        }
        .card()
    }

    private func caption(for value: MetricValue) -> String {
        switch metric.aggregation {
        case .cumulative: "오늘"
        case .discrete: value.date.formatted(.relative(presentation: .named))
        }
    }
}

struct WorkoutsSection: View {
    let workouts: [WorkoutItem]

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            HStack {
                SectionHeader(title: "운동", symbol: "figure.run", tint: .green)
                Spacer()
                if !workouts.isEmpty {
                    NavigationLink("모두 보기") { WorkoutListView(workouts: workouts) }
                        .font(.subheadline)
                }
            }

            if workouts.isEmpty {
                Text("최근 운동 기록이 없습니다.")
                    .foregroundStyle(.secondary)
                    .card()
            } else {
                VStack(spacing: 0) {
                    ForEach(workouts.prefix(3)) { workout in
                        WorkoutRow(workout: workout)
                            .padding(.vertical, 10)
                        if workout.id != workouts.prefix(3).last?.id {
                            Divider()
                        }
                    }
                }
                .card()
            }
        }
    }
}

struct WorkoutRow: View {
    let workout: WorkoutItem

    var body: some View {
        HStack(spacing: 14) {
            Image(systemName: workout.activityType.symbol)
                .font(.title2)
                .foregroundStyle(.green)
                .frame(width: 44, height: 44)
                .background(.green.opacity(0.15), in: Circle())

            VStack(alignment: .leading, spacing: 4) {
                Text(workout.activityType.displayName)
                    .font(.headline)
                Text(details)
                    .font(.subheadline.monospacedDigit())
                    .foregroundStyle(.secondary)
                    .lineLimit(1)
                Text(workout.start.formatted(.relative(presentation: .named)))
                    .font(.caption)
                    .foregroundStyle(.tertiary)
            }
            Spacer(minLength: 0)
        }
    }

    private var details: String {
        var parts = [workout.duration.hoursMinutesText]
        if let distance = workout.distanceKm {
            parts.append("\(distance.formatted(.number.precision(.fractionLength(2))))km")
        }
        if let energy = workout.energy {
            parts.append("\(Int(energy))kcal")
        }
        if let heartRate = workout.averageHeartRate {
            parts.append("♥︎ \(Int(heartRate))")
        }
        return parts.joined(separator: " · ")
    }
}

struct WorkoutListView: View {
    let workouts: [WorkoutItem]

    var body: some View {
        List(workouts) { workout in
            WorkoutRow(workout: workout)
        }
        .navigationTitle("운동 기록")
    }
}
