import Foundation

enum MealType: String, Codable, CaseIterable, Identifiable {
    case breakfast, lunch, dinner, snack, lateNight

    var id: String { rawValue }

    var title: String {
        switch self {
        case .breakfast: "아침"
        case .lunch: "점심"
        case .dinner: "저녁"
        case .snack: "간식"
        case .lateNight: "야식"
        }
    }

    var symbol: String {
        switch self {
        case .breakfast: "sunrise.fill"
        case .lunch: "sun.max.fill"
        case .dinner: "sunset.fill"
        case .snack: "cup.and.saucer.fill"
        case .lateNight: "moon.stars.fill"
        }
    }

    /// 시간대로 식사 종류를 추측 (저녁 9시 이후 · 새벽은 야식)
    static func suggested(for date: Date) -> MealType {
        switch Calendar.current.component(.hour, from: date) {
        case 4..<10: .breakfast
        case 10..<15: .lunch
        case 15..<17: .snack
        case 17..<21: .dinner
        default: .lateNight
        }
    }
}

struct NutritionTotals: Codable, Equatable {
    var calories = 0.0
    var carbohydrates = 0.0
    var protein = 0.0
    var fat = 0.0
    var sugar = 0.0
    /// mg
    var sodium = 0.0

    static let zero = NutritionTotals()

    static func + (lhs: NutritionTotals, rhs: NutritionTotals) -> NutritionTotals {
        NutritionTotals(
            calories: lhs.calories + rhs.calories,
            carbohydrates: lhs.carbohydrates + rhs.carbohydrates,
            protein: lhs.protein + rhs.protein,
            fat: lhs.fat + rhs.fat,
            sugar: lhs.sugar + rhs.sugar,
            sodium: lhs.sodium + rhs.sodium
        )
    }

    /// 예: "1,250kcal · 탄 150g · 단 55g · 지 40g"
    var summary: String {
        "\(Int(calories).formatted())kcal · 탄 \(Int(carbohydrates))g · 단 \(Int(protein))g · 지 \(Int(fat))g"
    }
}

/// 음식 하나. 영양값은 1회 제공량 기준이고 `servings`를 곱해 실제 섭취량을 계산한다.
struct FoodItem: Codable, Identifiable, Hashable {
    var id = UUID()
    var name: String
    var amount: String
    var servings: Double = 1
    var calories: Double
    var carbohydrates: Double
    var protein: Double
    var fat: Double
    var sugar: Double = 0
    var sodium: Double = 0

    var nutrition: NutritionTotals {
        NutritionTotals(
            calories: calories * servings,
            carbohydrates: carbohydrates * servings,
            protein: protein * servings,
            fat: fat * servings,
            sugar: sugar * servings,
            sodium: sodium * servings
        )
    }

    /// 매번 새 ID로 만든다 (목록에 여러 개 추가해도 겹치지 않게)
    static var empty: FoodItem { FoodItem(name: "", amount: "1인분", calories: 0, carbohydrates: 0, protein: 0, fat: 0) }

    /// 술 종류인지 (습관 자동 기록용)
    var isAlcohol: Bool {
        let keywords = ["소주", "맥주", "와인", "막걸리", "위스키", "하이볼", "칵테일", "사케", "고량주", "술", "beer", "wine", "soju"]
        let lower = name.lowercased()
        return keywords.contains { lower.contains($0) }
    }
}

enum MealSource: String, Codable {
    case photo, label, text, manual
}

/// 한 끼 기록. 앱에 영구 저장되고, 애플 건강 앱에도 음식 기록으로 저장된다.
struct MealEntry: Codable, Identifiable {
    var id = UUID()
    var date: Date
    var type: MealType
    var items: [FoodItem]
    var photoFileName: String?
    var note: String = ""
    var source: MealSource
    var syncedToHealth = false

    var totals: NutritionTotals { items.reduce(.zero) { $0 + $1.nutrition } }

    var title: String {
        let names = items.map(\.name).filter { !$0.isEmpty }
        guard !names.isEmpty else { return type.title }
        return names.prefix(3).joined(separator: ", ") + (names.count > 3 ? " 외 \(names.count - 3)개" : "")
    }
}

/// 하루 식단 요약 (분석 · AI용)
struct DayNutrition: Identifiable {
    let date: Date
    let totals: NutritionTotals
    let mealCount: Int
    var id: Date { date }
}
