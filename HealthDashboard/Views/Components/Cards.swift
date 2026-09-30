import SwiftUI

struct SectionHeader: View {
    let title: String
    let symbol: String
    let tint: Color

    var body: some View {
        HStack(spacing: 8) {
            Image(systemName: symbol)
                .font(.system(size: 13, weight: .bold))
                .foregroundStyle(.white)
                .frame(width: 26, height: 26)
                .background(tint.gradient, in: RoundedRectangle(cornerRadius: 8, style: .continuous))
            Text(title)
                .font(.headline)
                .foregroundStyle(.primary)
        }
    }
}

/// 카드 공통 배경: 둥근 모서리 + 라이트 모드에서는 부드러운 그림자, 다크 모드에서는 얇은 테두리
struct CardBackground: ViewModifier {
    @Environment(\.colorScheme) private var colorScheme

    func body(content: Content) -> some View {
        let shape = RoundedRectangle(cornerRadius: 22, style: .continuous)
        content
            .padding(16)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background {
                shape
                    .fill(Color(.secondarySystemGroupedBackground))
                    .shadow(color: .black.opacity(colorScheme == .dark ? 0 : 0.06), radius: 14, x: 0, y: 6)
            }
            .overlay {
                shape.strokeBorder(Color.white.opacity(colorScheme == .dark ? 0.08 : 0), lineWidth: 1)
            }
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
            HStack {
                Image(systemName: metric.symbol)
                    .font(.system(size: 13, weight: .semibold))
                    .foregroundStyle(metric.tint)
                    .frame(width: 30, height: 30)
                    .background(metric.tint.opacity(0.15), in: Circle())
                Spacer()
                if let value {
                    Text(caption(for: value))
                        .font(.caption2)
                        .foregroundStyle(.secondary)
                        .lineLimit(1)
                }
            }

            Text(metric.title)
                .font(.caption.weight(.medium))
                .foregroundStyle(.secondary)
                .lineLimit(1)

            if let value {
                HStack(alignment: .firstTextBaseline, spacing: 3) {
                    Text(metric.formatted(value.value))
                        .font(.system(size: 26, weight: .bold, design: .rounded).monospacedDigit())
                        .minimumScaleFactor(0.6)
                        .lineLimit(1)
                    Text(metric.unitLabel)
                        .font(.caption.weight(.medium))
                        .foregroundStyle(.secondary)
                }
            } else {
                Text("데이터 없음")
                    .font(.system(size: 20, weight: .semibold, design: .rounded))
                    .foregroundStyle(.tertiary)
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
