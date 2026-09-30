import HealthKit
import SwiftUI

enum DashboardSection: Hashable, Codable, Identifiable {
    case readiness, recommendation, favorites, activityRings, sleep, workouts
    case metrics(MetricCategory)

    static let defaultOrder: [DashboardSection] =
        [.readiness, .recommendation, .favorites, .activityRings, .sleep]
        + MetricCategory.allCases.map { .metrics($0) }
        + [.workouts]

    var id: String {
        switch self {
        case .readiness: "readiness"
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
        case .readiness: "준비 점수"
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
        case .readiness: "gauge.with.needle.fill"
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
        case .readiness: .mint
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
        // 앱 업데이트로 새로 생긴 섹션은 뒤에 붙인다.
        for section in DashboardSection.defaultOrder where !layout.order.contains(section) {
            layout.order.append(section)
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
