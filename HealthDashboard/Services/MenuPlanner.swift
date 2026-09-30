import Foundation
import UIKit
#if canImport(FoundationModels)
import FoundationModels
#endif

/// 가족 식탁 AI: 두 사람 모두에게 맞는 저녁 메뉴를 추천한다. (아이폰 안에서 처리)
@MainActor
enum MenuPlanner {
    private static let instructions = """
    당신은 부부의 건강을 챙기는 한국 가정식 메뉴 플래너입니다.
    - 두 사람의 건강 목표와 싫어하는 음식/알레르기를 반드시 지킵니다.
    - 평일에는 주어진 조리 시간 안에 만들 수 있는 집밥 위주로, 날마다 다른 메뉴로 다양하게 짭니다.
    - 같은 재료를 여러 날에 나눠 쓰도록 해 장보기를 줄입니다.
    - 날씨(추우면 국물, 더우면 가벼운 음식)와 컨디션(피곤하면 소화 잘 되는 음식)을 반영합니다.
    - 재료는 장보기용으로 양념(소금, 간장, 설탕, 기름 등)은 빼고 적습니다.
    - 추천 이유는 누구에게 왜 좋은지 한 문장으로 씁니다.
    """

    // MARK: - 주간 메뉴

    static func weeklyMenu(for dates: [Date], weather: WeatherReport?) async throws -> [DinnerSuggestion] {
        if let problem = CoachModel.availabilityProblem() { throw FoodAnalyzer.AnalyzerError.unavailable(problem) }
        guard !dates.isEmpty else { return [] }
        let dateList = dates.map { label($0) }.joined(separator: ", ")
        let prompt = """
        [요청] 다음 \(dates.count)일의 저녁 메뉴를 날짜 순서대로 하나씩 추천해 주세요: \(dateList)

        \(context(weather: weather, dates: dates))
        """

        #if canImport(FoundationModels)
        if #available(iOS 26.0, *) {
            do {
                let session = LanguageModelSession(instructions: instructions)
                let response = try await session.respond(to: prompt, generating: WeeklyMenuPlan.self)
                return zip(dates, response.content.days).map { date, day in
                    DinnerSuggestion(
                        date: date,
                        dish: day.dish,
                        sides: day.sides,
                        ingredients: day.ingredients,
                        reason: day.reason,
                        minutes: day.minutes,
                        selectedIngredients: Set(day.ingredients)
                    )
                }
            } catch {
                throw FoodAnalyzer.AnalyzerError.failed
            }
        }
        #endif
        throw FoodAnalyzer.AnalyzerError.failed
    }

    // MARK: - 냉장고 재료로 추천

    static func fridgeIdeas(ingredients text: String) async throws -> [FridgeIdea] {
        if let problem = CoachModel.availabilityProblem() { throw FoodAnalyzer.AnalyzerError.unavailable(problem) }
        let prompt = """
        [요청] 냉장고에 있는 재료로 오늘 저녁에 만들 수 있는 메뉴 3가지를 추천해 주세요.
        냉장고 재료: \(text)

        \(context(weather: nil, dates: [.now]))
        """
        #if canImport(FoundationModels)
        if #available(iOS 26.0, *) {
            do {
                let session = LanguageModelSession(instructions: instructions)
                let response = try await session.respond(to: prompt, generating: FridgeRecommendation.self)
                return convert(response.content)
            } catch {
                throw FoodAnalyzer.AnalyzerError.failed
            }
        }
        #endif
        throw FoodAnalyzer.AnalyzerError.failed
    }

    /// 냉장고 사진으로 추천 (iOS 27 SDK로 빌드했을 때만)
    static func fridgeIdeas(photo: UIImage) async throws -> [FridgeIdea] {
        if let problem = CoachModel.availabilityProblem() { throw FoodAnalyzer.AnalyzerError.unavailable(problem) }
        #if IOS27_SDK && canImport(FoundationModels)
        if #available(iOS 27.0, *) {
            let small = photo.resized(maxDimension: 1024)
            let question = """
            [요청] 사진 속 냉장고에 보이는 재료로 오늘 저녁에 만들 수 있는 메뉴 3가지를 추천해 주세요.

            \(context(weather: nil, dates: [.now]))
            """
            do {
                let session = LanguageModelSession(instructions: instructions)
                let response = try await session.respond(generating: FridgeRecommendation.self) {
                    question
                    Attachment(small)
                }
                return convert(response.content)
            } catch {
                throw FoodAnalyzer.AnalyzerError.failed
            }
        }
        #endif
        throw FoodAnalyzer.AnalyzerError.photoNeedsNewerSystem
    }

    #if canImport(FoundationModels)
    @available(iOS 26.0, *)
    private static func convert(_ recommendation: FridgeRecommendation) -> [FridgeIdea] {
        recommendation.ideas.map {
            FridgeIdea(dish: $0.dish, uses: $0.uses, missing: $0.missing, minutes: $0.minutes, reason: $0.reason)
        }
    }
    #endif

    // MARK: - AI에게 줄 가족 정보

    private static func label(_ date: Date) -> String {
        date.formatted(.dateTime.month(.defaultDigits).day().weekday(.abbreviated))
    }

    private static func context(weather: WeatherReport?, dates: [Date]) -> String {
        let profile = KitchenStore.shared.profile
        let dashboard = DashboardModel.shared
        let meals = MealStore.shared
        var lines: [String] = []

        // 나
        let proteinTarget = meals.proteinTarget(weightKg: dashboard.values[.bodyMass]?.value, workoutDay: false)
        var me = "[나] 하루 단백질 목표 \(Int(proteinTarget))g"
        let recent = meals.dailyNutrition(days: 7)
        if !recent.isEmpty {
            let average = { (key: KeyPath<NutritionTotals, Double>) in
                recent.map { $0.totals[keyPath: key] }.reduce(0, +) / Double(recent.count)
            }
            me += ", 최근 7일 하루 평균 \(Int(average(\.calories)))kcal · 단백질 \(Int(average(\.protein)))g · 나트륨 \(Int(average(\.sodium)))mg"
        }
        if let readiness = dashboard.todayReadiness {
            me += ", 오늘 컨디션 \(readiness.level.title)"
        }
        if !profile.myDislikes.isEmpty { me += ", 싫어하는 음식/알레르기: \(profile.myDislikes)" }
        lines.append(me)

        // 배우자
        var spouse = "[\(profile.spouseName)]"
        spouse += profile.spouseGoal.isEmpty ? " 특별한 목표 없음" : " 목표: \(profile.spouseGoal)"
        if !profile.spouseDislikes.isEmpty { spouse += ", 싫어하는 음식/알레르기: \(profile.spouseDislikes)" }
        lines.append(spouse)

        // 공통
        var common = "[공통] 평일 조리 시간 \(profile.cookingMinutes)분 이내"
        if !profile.notes.isEmpty { common += ", \(profile.notes)" }
        lines.append(common)

        // 최근 저녁 (반복 피하기)
        let calendar = Calendar.current
        let recentDinners = meals.meals
            .filter { ($0.type == .dinner || $0.type == .lateNight) && $0.date > (calendar.date(byAdding: .day, value: -7, to: .now) ?? .now) }
            .map(\.title)
        let planned = KitchenStore.shared.plannedMeals.map(\.dish)
        if !(recentDinners + planned).isEmpty {
            lines.append("[최근 먹었거나 이미 계획한 메뉴, 겹치지 않게] " + (recentDinners + planned).joined(separator: ", "))
        }

        // 날씨
        if let weather {
            let forecasts = dates.compactMap { date -> String? in
                guard let day = weather.days.first(where: { calendar.isDate($0.date, inSameDayAs: date) }) else { return nil }
                return "\(label(date)) \(day.condition.description) \(day.low.degreesText)~\(day.high.degreesText)"
            }
            if !forecasts.isEmpty { lines.append("[날씨] " + forecasts.joined(separator: ", ")) }
        }

        // 이미 장보기 목록에 있는 재료 (활용하기)
        let shopping = KitchenStore.shared.openItemNames
        if !shopping.isEmpty {
            lines.append("[장보기 목록에 이미 있는 재료, 활용 가능] " + shopping.prefix(20).joined(separator: ", "))
        }

        return lines.joined(separator: "\n")
    }
}

#if canImport(FoundationModels)
@available(iOS 26.0, *)
@Generable
struct WeeklyMenuPlan {
    @Guide(description: "요청한 날짜 순서대로 하루에 저녁 한 끼씩")
    var days: [PlannedDinnerGuess]
}

@available(iOS 26.0, *)
@Generable
struct PlannedDinnerGuess {
    @Guide(description: "메인 요리 이름 (예: 된장찌개, 닭가슴살 샐러드)")
    var dish: String
    @Guide(description: "곁들일 반찬 0~2개")
    var sides: [String]
    @Guide(description: "장보기용 재료 4~8개 (양념 제외, 예: 두부, 애호박, 돼지고기 앞다리살)")
    var ingredients: [String]
    @Guide(description: "누구에게 왜 좋은지 한 문장")
    var reason: String
    @Guide(description: "조리 시간 (분)")
    var minutes: Int
}

@available(iOS 26.0, *)
@Generable
struct FridgeRecommendation {
    @Guide(description: "추천 메뉴 3개")
    var ideas: [FridgeIdeaGuess]
}

@available(iOS 26.0, *)
@Generable
struct FridgeIdeaGuess {
    @Guide(description: "요리 이름")
    var dish: String
    @Guide(description: "냉장고 재료 중 사용하는 것")
    var uses: [String]
    @Guide(description: "더 사야 하는 재료 (없으면 빈 배열, 양념 제외)")
    var missing: [String]
    @Guide(description: "조리 시간 (분)")
    var minutes: Int
    @Guide(description: "누구에게 왜 좋은지 한 문장")
    var reason: String
}
#endif
