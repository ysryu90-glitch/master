import Foundation
import HealthKit
import Observation
import UIKit

/// 식단 기록 저장소.
/// - 앱 안(Documents/meals.json + 사진 폴더)에 계속 보관하는 것이 원본이다.
/// - 저장할 때 애플 건강 앱에도 '음식' 기록으로 함께 저장한다(건강 앱 백업 · 다른 앱과 공유).
/// - AI 코치, 주간 리포트, 습관 분석, 컨디션 리포트가 모두 이 기록을 읽는다.
@MainActor
@Observable
final class MealStore {
    static let shared = MealStore()

    /// 시간순(오래된 → 최신)
    private(set) var meals: [MealEntry] = []

    @ObservationIgnored private let service = HealthKitService()

    private static var documents: URL {
        FileManager.default.urls(for: .documentDirectory, in: .userDomainMask)[0]
    }
    private static var fileURL: URL { documents.appendingPathComponent("meals.json") }
    private static var photoDirectory: URL { documents.appendingPathComponent("MealPhotos", isDirectory: true) }

    init() {
        load()
    }

    // MARK: - 조회

    func meals(on date: Date) -> [MealEntry] {
        meals.filter { Calendar.current.isDate($0.date, inSameDayAs: date) }
    }

    func totals(on date: Date) -> NutritionTotals {
        meals(on: date).reduce(.zero) { $0 + $1.totals }
    }

    /// 오늘부터 거슬러 `days`일 중 기록이 있는 날 (오래된 → 최신)
    func dailyNutrition(days: Int) -> [DayNutrition] {
        let calendar = Calendar.current
        let today = calendar.startOfDay(for: .now)
        return (0..<days).reversed().compactMap { offset in
            guard let date = calendar.date(byAdding: .day, value: -offset, to: today) else { return nil }
            let dayMeals = meals(on: date)
            guard !dayMeals.isEmpty else { return nil }
            return DayNutrition(date: date, totals: dayMeals.reduce(.zero) { $0 + $1.totals }, mealCount: dayMeals.count)
        }
    }

    /// 자주 먹는 음식 (최근 60일, 많이 먹은 순)
    var frequentItems: [FoodItem] {
        let since = Calendar.current.date(byAdding: .day, value: -60, to: .now) ?? .distantPast
        var counts: [String: (count: Int, item: FoodItem)] = [:]
        for meal in meals where meal.date >= since {
            for item in meal.items where !item.name.isEmpty {
                let existing = counts[item.name]
                counts[item.name] = ((existing?.count ?? 0) + 1, item)
            }
        }
        return counts.values
            .sorted { $0.count > $1.count }
            .prefix(12)
            .map { var item = $0.item; item.id = UUID(); item.servings = 1; return item }
    }

    /// 하루 단백질 목표: 체중 1kg당 1.2g, 운동한 날은 1.6g (체중 기록이 없으면 60g)
    func proteinTarget(weightKg: Double?, workoutDay: Bool) -> Double {
        guard let weightKg, weightKg > 0 else { return 60 }
        return (weightKg * (workoutDay ? 1.6 : 1.2)).rounded()
    }

    func photo(for meal: MealEntry) -> UIImage? {
        guard let name = meal.photoFileName else { return nil }
        return UIImage(contentsOfFile: Self.photoDirectory.appendingPathComponent(name).path)
    }

    // MARK: - 저장 · 삭제

    /// 새 기록 추가 또는 기존 기록 수정
    func save(_ meal: MealEntry, photo: UIImage?) async {
        var meal = meal
        if let photo, meal.photoFileName == nil {
            meal.photoFileName = Self.storePhoto(photo, id: meal.id)
        }
        let isUpdate = meals.contains { $0.id == meal.id }

        meals.removeAll { $0.id == meal.id }
        meals.append(meal)
        meals.sort { $0.date < $1.date }
        persist()
        autoTagHabits(meal)

        // 건강 앱에도 저장 (수정이면 예전 기록을 지우고 다시 저장)
        if isUpdate { await service.deleteMeal(id: meal.id) }
        do {
            try await service.requestAuthorization()
            try await service.saveMeal(meal)
            setSynced(meal.id, true)
        } catch {
            // 건강 앱 쓰기 권한이 없어도 앱 기록은 그대로 남는다.
            setSynced(meal.id, false)
        }
    }

    func delete(_ meal: MealEntry) async {
        meals.removeAll { $0.id == meal.id }
        persist()
        if let name = meal.photoFileName {
            try? FileManager.default.removeItem(at: Self.photoDirectory.appendingPathComponent(name))
        }
        await service.deleteMeal(id: meal.id)
    }

    private func setSynced(_ id: UUID, _ synced: Bool) {
        guard let index = meals.firstIndex(where: { $0.id == id }) else { return }
        meals[index].syncedToHealth = synced
        persist()
    }

    /// 야식 · 술은 습관 기록에 자동으로 표시한다. (새벽 5시 전 식사는 전날 습관으로)
    private func autoTagHabits(_ meal: MealEntry) {
        let calendar = Calendar.current
        let day = calendar.component(.hour, from: meal.date) < 5
            ? calendar.date(byAdding: .day, value: -1, to: meal.date) ?? meal.date
            : meal.date
        let habits = HabitStore.shared
        if meal.type == .lateNight { habits.add(.lateMeal, on: day) }
        if meal.items.contains(where: \.isAlcohol) { habits.add(.alcohol, on: day) }
    }

    // MARK: - 파일

    private func load() {
        guard let data = try? Data(contentsOf: Self.fileURL),
              let saved = try? JSONDecoder().decode([MealEntry].self, from: data) else { return }
        meals = saved.sorted { $0.date < $1.date }
    }

    private func persist() {
        guard let data = try? JSONEncoder().encode(meals) else { return }
        // 파일 보호: 기기가 잠겨 있을 때는 읽을 수 없도록 (건강 기록 수준)
        try? data.write(to: Self.fileURL, options: [.atomic, .completeFileProtectionUntilFirstUserAuthentication])
    }

    private static func storePhoto(_ image: UIImage, id: UUID) -> String? {
        try? FileManager.default.createDirectory(at: photoDirectory, withIntermediateDirectories: true)
        let name = "\(id.uuidString).jpg"
        guard let data = image.resized(maxDimension: 1280).jpegData(compressionQuality: 0.75) else { return nil }
        do {
            try data.write(to: photoDirectory.appendingPathComponent(name), options: .atomic)
            return name
        } catch {
            return nil
        }
    }
}

extension UIImage {
    /// 긴 변이 `maxDimension`을 넘지 않도록 줄인다.
    func resized(maxDimension: CGFloat) -> UIImage {
        let longest = max(size.width, size.height)
        guard longest > maxDimension else { return self }
        let scale = maxDimension / longest
        let newSize = CGSize(width: size.width * scale, height: size.height * scale)
        return UIGraphicsImageRenderer(size: newSize).image { _ in
            draw(in: CGRect(origin: .zero, size: newSize))
        }
    }
}
