import HealthKit
import SwiftUI

enum DashboardSection: Hashable, Codable, Identifiable {
    case steps, earlyWarning, readiness, medication, water, schedule, diet, recommendation, breathing, habits, weight, checkups, favorites, activityRings, sleep, workouts
    case metrics(MetricCategory)

    static let defaultOrder: [DashboardSection] =
        [.steps, .earlyWarning, .readiness, .medication, .water, .schedule, .diet, .recommendation, .breathing, .habits, .weight, .checkups, .favorites, .activityRings, .sleep]
        + MetricCategory.allCases.map { .metrics($0) }
        + [.workouts]

    var id: String {
        switch self {
        case .steps: "steps"
        case .earlyWarning: "earlyWarning"
        case .readiness: "readiness"
        case .habits: "habits"
        case .schedule: "schedule"
        case .diet: "diet"
        case .water: "water"
        case .weight: "weight"
        case .checkups: "checkups"
        case .breathing: "breathing"
        case .medication: "medication"
        case .recommendation: "recommendation"
        case .favorites: "favorites"
        case .activityRings: "activityRings"
        case .sleep: "sleep"
        case .workouts: "workouts"
        case .metrics(let category): "metrics.\(category.rawValue)"
        }
    }

    var title: String {
        switch self {
        case .steps: "오늘 걸음 (아이폰 모드)"
        case .earlyWarning: "컨디션 이상 경보 (있을 때만)"
        case .readiness: "준비 점수"
        case .habits: "오늘의 습관"
        case .schedule: "오늘의 가족 일정"
        case .diet: "오늘의 식단"
        case .water: "물 마시기"
        case .weight: "체중 목표"
        case .checkups: "건강검진 · 접종"
        case .breathing: "1분 호흡"
        case .medication: "오늘의 복약"
        case .recommendation: "오늘의 운동 추천"
        case .favorites: "즐겨찾기"
        case .activityRings: "활동 링"
        case .sleep: "수면 · 마음챙김"
        case .workouts: "운동 기록"
        case .metrics(let category): category.title
        }
    }

    var symbol: String {
        switch self {
        case .steps: "shoeprints.fill"
        case .earlyWarning: "exclamationmark.triangle.fill"
        case .readiness: "gauge.with.needle.fill"
        case .habits: "list.bullet.clipboard.fill"
        case .schedule: "calendar"
        case .diet: "fork.knife"
        case .water: "drop.fill"
        case .weight: "scalemass.fill"
        case .checkups: "stethoscope"
        case .breathing: "wind"
        case .medication: "pills.fill"
        case .recommendation: "figure.run.circle.fill"
        case .favorites: "star.fill"
        case .activityRings: "circle.circle"
        case .sleep: "bed.double.fill"
        case .workouts: "figure.run"
        case .metrics(let category): category.symbol
        }
    }

    var tint: Color {
        switch self {
        case .steps: .orange
        case .earlyWarning: .red
        case .readiness: .mint
        case .habits: .teal
        case .schedule: .pink
        case .diet: .orange
        case .water: .cyan
        case .weight: .purple
        case .checkups: .blue
        case .breathing: .teal
        case .medication: .purple
        case .recommendation: .green
        case .favorites: .yellow
        case .activityRings: .red
        case .sleep: .indigo
        case .workouts: .green
        case .metrics(let category): category.tint
        }
    }
}

/// 사용자가 정한 대시보드 순서 / 숨김 / 즐겨찾기
struct DashboardLayout: Codable, Equatable {
    var order: [DashboardSection] = DashboardSection.defaultOrder
    var hidden: Set<DashboardSection> = []
    var favorites: [String] = [
        HKQuantityTypeIdentifier.stepCount.rawValue,
        HKQuantityTypeIdentifier.restingHeartRate.rawValue,
        HKQuantityTypeIdentifier.heartRateVariabilitySDNN.rawValue,
        HKQuantityTypeIdentifier.oxygenSaturation.rawValue,
    ]

    private static let key = "dashboardLayout"

    static func load() -> DashboardLayout {
        guard let data = UserDefaults.standard.data(forKey: key),
              var layout = try? JSONDecoder().decode(DashboardLayout.self, from: data) else {
            return DashboardLayout()
        }
        // 앱 업데이트로 새로 생긴 섹션은 기본 순서의 자리에 끼워 넣는다.
        for (index, section) in DashboardSection.defaultOrder.enumerated() where !layout.order.contains(section) {
            layout.order.insert(section, at: min(index, layout.order.count))
        }
        layout.order.removeAll { !DashboardSection.defaultOrder.contains($0) }
        return layout
    }

    func save() {
        if let data = try? JSONEncoder().encode(self) {
            UserDefaults.standard.set(data, forKey: Self.key)
        }
    }

    var visibleSections: [DashboardSection] { order.filter { !hidden.contains($0) } }

    var favoriteMetrics: [HealthMetric] {
        favorites.compactMap { id in HealthMetric.all.first { $0.id.rawValue == id } }
    }

    func isFavorite(_ metric: HealthMetric) -> Bool { favorites.contains(metric.id.rawValue) }

    mutating func toggleFavorite(_ metric: HealthMetric) {
        if let index = favorites.firstIndex(of: metric.id.rawValue) {
            favorites.remove(at: index)
        } else {
            favorites.append(metric.id.rawValue)
        }
    }
}
