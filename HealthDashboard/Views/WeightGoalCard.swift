import Charts
import HealthKit
import SwiftUI

/// 체중 목표 · 추세 예측
/// - 최근 60일 체중의 직선 추세로 목표 도달 예상일을 계산
/// - 식단을 기록한 날이 있으면 '먹은 칼로리 − 쓴 칼로리'로 주간 예상 변화도 함께 보여준다 (7,700kcal ≈ 1kg)
struct WeightGoalCard: View {
    @Environment(DashboardModel.self) private var model
    @Environment(MealStore.self) private var meals

    @AppStorage("weightGoalKg") private var goal: Double = 0
    @State private var points: [TrendPoint] = []
    @State private var burnByDay: [Date: Double] = [:]
    @State private var showGoalEditor = false

    private var current: Double? { points.last?.value ?? model.values[.bodyMass]?.value }

    /// 하루당 체중 변화 (kg)
    private var slopePerDay: Double? {
        let recent = points.suffix(60)
        guard recent.count >= 5, let first = recent.first?.date else { return nil }
        let xs = recent.map { $0.date.timeIntervalSince(first) / 86_400 }
        let ys = recent.map(\.value)
        let meanX = xs.reduce(0, +) / Double(xs.count)
        let meanY = ys.reduce(0, +) / Double(ys.count)
        let numerator = zip(xs, ys).reduce(0) { $0 + ($1.0 - meanX) * ($1.1 - meanY) }
        let denominator = xs.reduce(0) { $0 + pow($1 - meanX, 2) }
        return denominator > 0 ? numerator / denominator : nil
    }

    private var forecastDate: Date? {
        guard goal > 0, let current, let slope = slopePerDay, abs(slope) > 0.005 else { return nil }
        let days = (goal - current) / slope
        guard days > 0, days < 730 else { return nil }
        return Calendar.current.date(byAdding: .day, value: Int(days.rounded()), to: .now)
    }

    /// 식단 기록 기준 주간 예상 변화 (kg)
    private var energyBalancePerWeek: Double? {
        let logged = meals.dailyNutrition(days: 14).filter { $0.mealCount >= 2 }
        let balances = logged.compactMap { day -> Double? in
            guard let burned = burnByDay[Calendar.current.startOfDay(for: day.date)], burned > 0 else { return nil }
            return day.totals.calories - burned
        }
        guard balances.count >= 3 else { return nil }
        return balances.reduce(0, +) / Double(balances.count) * 7 / 7700
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            HStack {
                SectionHeader(title: "체중 목표", symbol: "scalemass.fill", tint: .purple)
                Spacer()
                Button(goal > 0 ? "목표 수정" : "목표 정하기") { showGoalEditor = true }
                    .font(.subheadline)
            }

            if let current {
                HStack(alignment: .firstTextBaseline) {
                    Text("\(current.formatted(.number.precision(.fractionLength(1))))kg")
                        .font(.system(size: 30, weight: .bold, design: .rounded))
                    if goal > 0 {
                        Text("→ 목표 \(goal.formatted(.number.precision(.fractionLength(1))))kg")
                            .font(.subheadline)
                            .foregroundStyle(.secondary)
                    }
                    Spacer()
                    if let slope = slopePerDay {
                        let weekly = slope * 7
                        Text("\(weekly >= 0 ? "+" : "")\(weekly.formatted(.number.precision(.fractionLength(2))))kg/주")
                            .font(.subheadline.weight(.semibold).monospacedDigit())
                            .foregroundStyle(trendColor(weekly))
                    }
                }

                if points.count >= 2 {
                    Chart {
                        ForEach(points) { point in
                            LineMark(x: .value("날짜", point.date, unit: .day), y: .value("체중", point.value))
                                .foregroundStyle(.purple)
                                .interpolationMethod(.catmullRom)
                        }
                        if goal > 0 {
                            RuleMark(y: .value("목표", goal))
                                .lineStyle(StrokeStyle(lineWidth: 1, dash: [4, 3]))
                                .foregroundStyle(.green)
                        }
                    }
                    .chartYScale(domain: .automatic(includesZero: false))
                    .frame(height: 120)
                }

                VStack(alignment: .leading, spacing: 4) {
                    if let forecastDate {
                        Label("지금 추세면 \(forecastDate.formatted(.dateTime.year().month().day()))쯤 목표에 도달해요",
                              systemImage: "flag.checkered")
                    } else if goal > 0, slopePerDay != nil {
                        Label("지금 추세로는 목표에 가까워지지 않고 있어요", systemImage: "arrow.uturn.right")
                    }
                    if let balance = energyBalancePerWeek {
                        Label("식단 기록 기준 예상: 주 \(balance >= 0 ? "+" : "")\(balance.formatted(.number.precision(.fractionLength(2))))kg",
                              systemImage: "fork.knife")
                    }
                }
                .font(.caption)
                .foregroundStyle(.secondary)
            } else {
                Text("체중 기록이 없어요. 체중계 앱이나 건강 앱에 기록하면 추세와 목표 도달일을 알려드려요.")
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
            }
        }
        .card()
        .task {
            guard let weight = HealthMetric.metric(.bodyMass) else { return }
            points = await model.trend(for: weight, days: 90)
            if let active = HealthMetric.metric(.activeEnergyBurned), let basal = HealthMetric.metric(.basalEnergyBurned) {
                let activePoints = await model.trend(for: active, days: 14)
                let basalPoints = await model.trend(for: basal, days: 14)
                var burns: [Date: Double] = [:]
                for point in activePoints + basalPoints {
                    burns[Calendar.current.startOfDay(for: point.date), default: 0] += point.value
                }
                burnByDay = burns
            }
        }
        .sheet(isPresented: $showGoalEditor) {
            GoalEditor(goal: $goal, current: current)
        }
    }

    private func trendColor(_ weekly: Double) -> Color {
        guard goal > 0, let current else { return .secondary }
        let towardGoal = (goal < current && weekly < 0) || (goal > current && weekly > 0)
        return towardGoal ? .green : .orange
    }
}

private struct GoalEditor: View {
    @Binding var goal: Double
    let current: Double?
    @Environment(\.dismiss) private var dismiss
    @State private var draft: Double = 70

    var body: some View {
        NavigationStack {
            Form {
                Stepper(value: $draft, in: 30...200, step: 0.5) {
                    HStack {
                        Text("목표 체중")
                        Spacer()
                        Text("\(draft.formatted(.number.precision(.fractionLength(1))))kg")
                            .font(.headline.monospacedDigit())
                    }
                }
                if goal > 0 {
                    Button("목표 지우기", role: .destructive) {
                        goal = 0
                        dismiss()
                    }
                }
            }
            .navigationTitle("체중 목표")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) { Button("취소") { dismiss() } }
                ToolbarItem(placement: .confirmationAction) {
                    Button("저장") {
                        goal = draft
                        dismiss()
                    }
                }
            }
            .onAppear { draft = goal > 0 ? goal : ((current ?? 70) - 3).rounded() }
        }
        .presentationDetents([.medium])
    }
}
