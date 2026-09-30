import SwiftUI
import WidgetKit

/// 위젯에서는 HKActivityRingView(UIKit)를 쓸 수 없어 직접 그린다.
struct RingsView: View {
    let snapshot: HealthSnapshot?
    var lineWidth: CGFloat = 8

    private var rings: [(progress: Double, color: Color)] {
        [
            (ratio(snapshot?.move, snapshot?.moveGoal), .red),
            (ratio(snapshot?.exercise, snapshot?.exerciseGoal), .green),
            (ratio(snapshot?.stand, snapshot?.standGoal), .cyan),
        ]
    }

    var body: some View {
        GeometryReader { proxy in
            let size = min(proxy.size.width, proxy.size.height)
            ZStack {
                ForEach(0..<3, id: \.self) { index in
                    let inset = CGFloat(index) * (lineWidth + 2) + lineWidth / 2
                    let ring = rings[index]
                    Circle()
                        .inset(by: inset)
                        .stroke(ring.color.opacity(0.22), lineWidth: lineWidth)
                    Circle()
                        .inset(by: inset)
                        .trim(from: 0, to: min(ring.progress, 1))
                        .stroke(ring.color, style: StrokeStyle(lineWidth: lineWidth, lineCap: .round))
                        .rotationEffect(.degrees(-90))
                }
            }
            .frame(width: size, height: size)
            .frame(maxWidth: .infinity, maxHeight: .infinity)
        }
    }

    private func ratio(_ value: Double?, _ goal: Double?) -> Double {
        guard let value, let goal, goal > 0 else { return 0 }
        return value / goal
    }
}

struct ReadinessRing: View {
    let score: Double?
    let color: Color
    var lineWidth: CGFloat = 8

    var body: some View {
        ZStack {
            Circle().stroke(color.opacity(0.2), lineWidth: lineWidth)
            Circle()
                .trim(from: 0, to: (score ?? 0) / 10)
                .stroke(color, style: StrokeStyle(lineWidth: lineWidth, lineCap: .round))
                .rotationEffect(.degrees(-90))
            Text(score.map { $0.formatted(.number.precision(.fractionLength(1))) } ?? "–")
                .font(.system(size: lineWidth * 2.6, weight: .bold, design: .rounded))
                .minimumScaleFactor(0.5)
        }
        .padding(lineWidth / 2)
    }
}

extension HealthSnapshot {
    var todayReadiness: (score: Double, level: ReadinessLevel)? { readiness(on: .now) }

    /// 오늘 저장된 요약인지 (자정이 지나면 활동 링 값은 0부터 다시 시작)
    var isFromToday: Bool { Calendar.current.isDateInToday(updatedAt) }
}

struct NoDataView: View {
    var body: some View {
        VStack(spacing: 6) {
            Image(systemName: "heart.text.square")
                .font(.title2)
                .foregroundStyle(.red)
            Text("앱을 한 번 열어 주세요")
                .font(.caption)
                .multilineTextAlignment(.center)
                .foregroundStyle(.secondary)
        }
    }
}
