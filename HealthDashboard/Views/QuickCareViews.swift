import SwiftUI
import UIKit

/// 오늘 물 섭취 카드: 한 번 눌러 기록
struct WaterCard: View {
    @Environment(DashboardModel.self) private var model
    @State private var isSaving = false

    var body: some View {
        let total = model.waterToday
        let goal = model.waterGoal
        let progress = min(total / max(goal, 1), 1)

        VStack(alignment: .leading, spacing: 12) {
            HStack {
                SectionHeader(title: "물 마시기", symbol: "drop.fill", tint: .cyan)
                Spacer()
                Text("\(Int(total).formatted()) / \(Int(goal).formatted())ml")
                    .font(.subheadline.weight(.semibold).monospacedDigit())
                    .foregroundStyle(.cyan)
            }

            GeometryReader { proxy in
                ZStack(alignment: .leading) {
                    Capsule().fill(Color.cyan.opacity(0.15))
                    Capsule()
                        .fill(LinearGradient(colors: [.cyan, .blue], startPoint: .leading, endPoint: .trailing))
                        .frame(width: max(proxy.size.width * progress, 8))
                        .animation(.snappy, value: progress)
                }
            }
            .frame(height: 12)

            HStack(spacing: 8) {
                ForEach([150, 250, 500], id: \.self) { amount in
                    Button {
                        Task {
                            isSaving = true
                            UIImpactFeedbackGenerator(style: .light).impactOccurred()
                            await model.addWater(ml: Double(amount))
                            isSaving = false
                        }
                    } label: {
                        Text("+\(amount)ml")
                            .font(.subheadline.weight(.semibold))
                            .frame(maxWidth: .infinity)
                    }
                    .buttonStyle(.bordered)
                    .tint(.cyan)
                    .disabled(isSaving)
                }
            }

            Text(goalNote(total: total, goal: goal))
                .font(.caption)
                .foregroundStyle(.secondary)
        }
        .card()
    }

    private func goalNote(total: Double, goal: Double) -> String {
        if total >= goal { return "오늘 목표를 채웠어요! 💧" }
        var reasons: [String] = []
        if goal >= 2500 { reasons.append("운동한 날") }
        if Int(goal) % 500 == 300 { reasons.append("더운 날") }
        let left = Int(goal - total)
        return "\(left.formatted())ml 남았어요" + (reasons.isEmpty ? "" : " (\(reasons.joined(separator: " · ")) 목표 상향)")
    }
}

/// 1분 호흡 추천 카드: 스트레스를 기록했거나 HRV가 낮은 날 강조
struct BreathingCard: View {
    @Environment(DashboardModel.self) private var model
    @Environment(HabitStore.self) private var habits
    @State private var showBreathing = false

    private var reason: String? {
        if habits.tags(on: HabitStore.loggingDate).contains(.stress) { return "오늘 스트레스를 기록했어요." }
        if model.todayReadiness?.components.first(where: { $0.kind == .hrv })?.status == "낮음" {
            return "오늘 심박 변이(HRV)가 평소보다 낮아요."
        }
        if model.earlyWarning != nil { return "컨디션 이상 신호가 있어요." }
        return nil
    }

    var body: some View {
        Button {
            showBreathing = true
        } label: {
            HStack(spacing: 14) {
                Image(systemName: "wind")
                    .font(.title2)
                    .foregroundStyle(.white)
                    .frame(width: 48, height: 48)
                    .background(LinearGradient(colors: [.teal, .mint], startPoint: .topLeading, endPoint: .bottomTrailing),
                                in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                VStack(alignment: .leading, spacing: 3) {
                    Text("1분 호흡")
                        .font(.headline)
                    Text(reason.map { "\($0) 잠깐 숨 고르기를 해 보세요." } ?? "천천히 따라 숨 쉬며 긴장을 풀어요.")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                        .multilineTextAlignment(.leading)
                }
                Spacer()
                Image(systemName: "play.circle.fill")
                    .font(.title)
                    .foregroundStyle(.teal)
            }
            .card()
            .overlay(RoundedRectangle(cornerRadius: 22).stroke(reason != nil ? Color.teal.opacity(0.6) : .clear, lineWidth: 1.5))
        }
        .buttonStyle(.plain)
        .fullScreenCover(isPresented: $showBreathing) { BreathingView() }
    }
}

/// 1분 호흡 가이드: 4초 들이쉬고 6초 내쉬기 × 6번. 끝나면 건강 앱 '마음챙김'으로 저장
struct BreathingView: View {
    @Environment(DashboardModel.self) private var model
    @Environment(\.dismiss) private var dismiss

    private enum Phase { case ready, inhale, exhale, done }

    @State private var phase: Phase = .ready
    @State private var scale: CGFloat = 0.55
    @State private var cycle = 0
    @State private var startDate = Date.now
    @State private var task: Task<Void, Never>?

    private let cycles = 6
    private let inhaleSeconds = 4.0
    private let exhaleSeconds = 6.0

    var body: some View {
        ZStack {
            LinearGradient(colors: [Color(red: 0.05, green: 0.25, blue: 0.3), Color(red: 0.1, green: 0.45, blue: 0.45)],
                           startPoint: .top, endPoint: .bottom)
                .ignoresSafeArea()

            VStack(spacing: 40) {
                HStack {
                    Spacer()
                    Button {
                        task?.cancel()
                        dismiss()
                    } label: {
                        Image(systemName: "xmark.circle.fill")
                            .font(.title)
                            .foregroundStyle(.white.opacity(0.7))
                    }
                }
                .padding(.horizontal)

                Spacer()

                ZStack {
                    Circle()
                        .fill(.white.opacity(0.12))
                        .frame(width: 280, height: 280)
                    Circle()
                        .fill(RadialGradient(colors: [.mint, .teal.opacity(0.6)], center: .center, startRadius: 10, endRadius: 150))
                        .frame(width: 280, height: 280)
                        .scaleEffect(scale)
                        .shadow(color: .mint.opacity(0.5), radius: 30)
                    Text(phaseText)
                        .font(.title.bold())
                        .foregroundStyle(.white)
                }

                Text(subtitle)
                    .font(.headline)
                    .foregroundStyle(.white.opacity(0.8))

                Spacer()

                if phase == .ready || phase == .done {
                    Button {
                        if phase == .done { dismiss() } else { start() }
                    } label: {
                        Text(phase == .done ? "완료" : "시작하기")
                            .font(.headline)
                            .frame(maxWidth: .infinity)
                            .padding(.vertical, 6)
                    }
                    .buttonStyle(.borderedProminent)
                    .tint(.white.opacity(0.25))
                    .padding(.horizontal, 40)
                }
            }
            .padding(.vertical, 30)
        }
        .onDisappear { task?.cancel() }
    }

    private var phaseText: String {
        switch phase {
        case .ready: "준비"
        case .inhale: "들이쉬기"
        case .exhale: "내쉬기"
        case .done: "잘했어요"
        }
    }

    private var subtitle: String {
        switch phase {
        case .ready: "편하게 앉아 어깨 힘을 빼세요"
        case .inhale, .exhale: "\(cycle) / \(cycles)"
        case .done: "건강 앱에 마음챙김 1분으로 기록했어요"
        }
    }

    private func start() {
        startDate = .now
        task = Task { @MainActor in
            for index in 1...cycles {
                cycle = index
                phase = .inhale
                UIImpactFeedbackGenerator(style: .soft).impactOccurred()
                withAnimation(.easeInOut(duration: inhaleSeconds)) { scale = 1 }
                try? await Task.sleep(for: .seconds(inhaleSeconds))
                if Task.isCancelled { return }

                phase = .exhale
                UIImpactFeedbackGenerator(style: .light).impactOccurred()
                withAnimation(.easeInOut(duration: exhaleSeconds)) { scale = 0.55 }
                try? await Task.sleep(for: .seconds(exhaleSeconds))
                if Task.isCancelled { return }
            }
            phase = .done
            UINotificationFeedbackGenerator().notificationOccurred(.success)
            await model.saveBreathing(start: startDate, end: .now)
        }
    }
}

/// 아이폰 모드(애플워치 없음)의 대표 카드: 오늘 걸음 · 거리 · 층수 · 7일 추이
struct StepsHeroCard: View {
    @Environment(DashboardModel.self) private var model
    @State private var week: [TrendPoint] = []

    var body: some View {
        let steps = model.values[.stepCount]?.value ?? 0
        let goal = 8000.0

        VStack(alignment: .leading, spacing: 14) {
            HStack {
                Label("오늘 걸음", systemImage: "shoeprints.fill")
                    .font(.subheadline.weight(.semibold))
                    .opacity(0.9)
                Spacer()
                Text("목표 \(Int(goal).formatted())")
                    .font(.caption)
                    .opacity(0.8)
            }

            Text("\(Int(steps).formatted())")
                .font(.system(size: 48, weight: .bold, design: .rounded).monospacedDigit())

            GeometryReader { proxy in
                ZStack(alignment: .leading) {
                    Capsule().fill(.white.opacity(0.25))
                    Capsule().fill(.white)
                        .frame(width: max(proxy.size.width * min(steps / goal, 1), 6))
                }
            }
            .frame(height: 8)

            HStack(spacing: 18) {
                if let distance = model.values[.distanceWalkingRunning]?.value {
                    Label("\(distance.formatted(.number.precision(.fractionLength(1))))km", systemImage: "figure.walk")
                }
                if let flights = model.values[.flightsClimbed]?.value {
                    Label("\(Int(flights))층", systemImage: "stairs")
                }
                if let steadiness = model.values[.appleWalkingSteadiness]?.value {
                    Label("보행 안정성 \(Int(steadiness))%", systemImage: "figure.walk.motion")
                }
            }
            .font(.caption.weight(.medium))

            if week.count >= 2 {
                HStack(alignment: .bottom, spacing: 6) {
                    let maxValue = max(week.map(\.value).max() ?? 1, goal)
                    ForEach(week) { day in
                        VStack(spacing: 3) {
                            RoundedRectangle(cornerRadius: 3)
                                .fill(.white.opacity(day.value >= goal ? 1 : 0.5))
                                .frame(height: max(36 * day.value / maxValue, 3))
                            Text(day.date.formatted(.dateTime.weekday(.narrow)))
                                .font(.system(size: 9))
                                .opacity(0.8)
                        }
                        .frame(maxWidth: .infinity)
                    }
                }
                .frame(height: 52)
            }

            Text("애플워치를 연결하면 준비 점수 · 수면 단계 · 심박 분석이 자동으로 켜져요.")
                .font(.caption2)
                .opacity(0.8)
        }
        .foregroundStyle(.white)
        .padding(20)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(
            LinearGradient(colors: [Color(red: 1, green: 0.6, blue: 0.25), Color(red: 0.95, green: 0.35, blue: 0.35)],
                           startPoint: .topLeading, endPoint: .bottomTrailing),
            in: RoundedRectangle(cornerRadius: 28, style: .continuous)
        )
        .task {
            if let metric = HealthMetric.metric(.stepCount) {
                week = await model.trend(for: metric, days: 7)
            }
        }
    }
}
