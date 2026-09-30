import Foundation

/// 가족 식탁 설정: 메뉴 추천에 쓰는 가족 정보 (이 기기에만 저장)
struct FamilyProfile: Codable, Equatable {
    var spouseName = "배우자"
    /// 예: 다이어트 중, 저염, 단백질 챙기기
    var spouseGoal = ""
    /// 싫어하는 음식 · 알레르기
    var spouseDislikes = ""
    var myDislikes = ""
    /// 평일 저녁 조리 시간 (분)
    var cookingMinutes = 30
    var dinnerHour = 18
    var dinnerMinute = 30
    /// 가족 공통 메모 (예: 매운 음식 좋아함, 주말엔 외식)
    var notes = ""

    private static let key = "familyProfile"

    static func load() -> FamilyProfile {
        guard let data = UserDefaults.standard.data(forKey: key),
              let profile = try? JSONDecoder().decode(FamilyProfile.self, from: data) else { return FamilyProfile() }
        return profile
    }

    func save() {
        if let data = try? JSONEncoder().encode(self) {
            UserDefaults.standard.set(data, forKey: Self.key)
        }
    }
}

/// 가족 캘린더에 저장된 저녁 메뉴 계획 (캘린더 일정 하나 = 계획 하나)
struct PlannedMeal: Identifiable {
    let id: String
    let date: Date
    let dish: String
    let ingredients: [String]
    let reason: String

    static let titlePrefix = "🍽 "
    static let marker = "— 건강 대시보드 가족 식탁"

    /// 캘린더 메모에 저장할 내용
    static func notes(ingredients: [String], reason: String) -> String {
        var lines: [String] = []
        if !ingredients.isEmpty { lines.append("재료: " + ingredients.joined(separator: ", ")) }
        if !reason.isEmpty { lines.append("추천 이유: " + reason) }
        lines.append(marker)
        return lines.joined(separator: "\n")
    }

    /// 캘린더 일정 → 계획 (제목이 🍽 로 시작하는 일정만)
    init?(id: String, title: String?, date: Date?, notes: String?) {
        guard let title, title.hasPrefix(Self.titlePrefix), let date else { return nil }
        self.id = id
        self.date = date
        self.dish = String(title.dropFirst(Self.titlePrefix.count))
        let lines = (notes ?? "").components(separatedBy: "\n")
        self.ingredients = lines.first { $0.hasPrefix("재료: ") }
            .map { String($0.dropFirst(4)).components(separatedBy: ", ").filter { !$0.isEmpty } } ?? []
        self.reason = lines.first { $0.hasPrefix("추천 이유: ") }.map { String($0.dropFirst(7)) } ?? ""
    }
}

/// AI가 추천한 저녁 메뉴 (검토 화면용)
struct DinnerSuggestion: Identifiable {
    let id = UUID()
    var date: Date
    var dish: String
    var sides: [String]
    var ingredients: [String]
    var reason: String
    var minutes: Int
    var include = true
    /// 장보기에 넣을 재료
    var selectedIngredients: Set<String>

    var fullDish: String {
        sides.isEmpty ? dish : dish + " + " + sides.joined(separator: ", ")
    }
}

/// 냉장고 재료로 만들 수 있는 메뉴
struct FridgeIdea: Identifiable {
    let id = UUID()
    let dish: String
    let uses: [String]
    let missing: [String]
    let minutes: Int
    let reason: String
}
