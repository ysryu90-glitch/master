import Foundation
import UIKit
#if canImport(FoundationModels)
import FoundationModels
#endif

/// 아이폰 안의 AI로 음식 사진 · 영양성분표 · 문장을 분석해 음식 목록으로 바꾼다.
/// - 문장 분석: iOS 26 이상
/// - 사진 분석: iOS 27 이상 + Xcode 27 이상으로 빌드 (온디바이스 모델의 이미지 입력)
@MainActor
enum FoodAnalyzer {
    enum PhotoKind {
        /// 음식 사진 (한상차림 포함)
        case meal
        /// 가공식품 영양성분표
        case label
    }

    enum AnalyzerError: LocalizedError {
        case unavailable(String)
        case photoNeedsNewerSystem
        case nothingFound
        case failed

        var errorDescription: String? {
            switch self {
            case .unavailable(let reason): reason
            case .photoNeedsNewerSystem: "사진 분석은 iOS 27 이상(그리고 Xcode 27 이상으로 빌드한 앱)에서 사용할 수 있어요. 아래에서 직접 입력해 주세요."
            case .nothingFound: "음식을 찾지 못했어요. 다시 찍거나 직접 입력해 주세요."
            case .failed: "분석하지 못했어요. 잠시 후 다시 시도하거나 직접 입력해 주세요."
            }
        }
    }

    private static let instructions = """
    당신은 한국 음식에 익숙한 영양사입니다. 사용자가 먹은 음식을 보고 음식별로 나눠 이름, 양, 영양성분을 추정합니다.
    - 밥, 국/찌개, 반찬, 음료는 각각 따로 나눕니다.
    - 양이 분명하지 않으면 한국 성인 보통 1인분으로 가정합니다.
    - 영양성분은 한국 식품영양성분 데이터베이스 수준의 현실적인 값으로, 적힌 양 전체 기준입니다.
    - 음식 이름은 한국어로 씁니다.
    """

    private static let labelInstructions = """
    사진은 가공식품 포장의 영양성분표입니다. 표에 적힌 숫자를 그대로 읽습니다.
    - 총 내용량 기준 값이 있으면 그 값을, 없으면 1회 제공량 기준 값을 씁니다. amount에 어느 기준인지 적습니다.
    - 제품명이 보이면 name에, 보이지 않으면 '가공식품'이라고 씁니다.
    - 표에 없는 값은 0으로 둡니다.
    """

    static var supportsPhotos: Bool {
        #if compiler(>=6.3) && canImport(FoundationModels)
        if #available(iOS 27.0, *) { return CoachModel.availabilityProblem() == nil }
        #endif
        return false
    }

    /// "점심에 김치찌개랑 밥 반 공기" 같은 문장 분석
    static func analyze(text: String) async throws -> [FoodItem] {
        if let problem = CoachModel.availabilityProblem() { throw AnalyzerError.unavailable(problem) }
        #if canImport(FoundationModels)
        if #available(iOS 26.0, *) {
            let session = LanguageModelSession(instructions: instructions)
            do {
                let response = try await session.respond(
                    to: "다음은 사용자가 먹은 음식 설명입니다: \(text)",
                    generating: FoodAnalysis.self
                )
                return try convert(response.content)
            } catch let error as AnalyzerError {
                throw error
            } catch {
                throw AnalyzerError.failed
            }
        }
        #endif
        throw AnalyzerError.failed
    }

    /// 음식 사진 또는 영양성분표 사진 분석
    static func analyze(image: UIImage, kind: PhotoKind, hint: String = "") async throws -> [FoodItem] {
        if let problem = CoachModel.availabilityProblem() { throw AnalyzerError.unavailable(problem) }
        #if compiler(>=6.3) && canImport(FoundationModels)
        if #available(iOS 27.0, *) {
            let small = image.resized(maxDimension: 1024)
            let session = LanguageModelSession(instructions: kind == .label ? labelInstructions : instructions)
            let question = kind == .label
                ? "이 영양성분표를 읽어 주세요."
                : "이 사진 속 음식을 분석해 주세요." + (hint.isEmpty ? "" : " 참고: \(hint)")
            do {
                let response = try await session.respond(generating: FoodAnalysis.self) {
                    question
                    Attachment(small)
                }
                return try convert(response.content)
            } catch let error as AnalyzerError {
                throw error
            } catch {
                throw AnalyzerError.failed
            }
        }
        #endif
        throw AnalyzerError.photoNeedsNewerSystem
    }

    #if canImport(FoundationModels)
    @available(iOS 26.0, *)
    private static func convert(_ analysis: FoodAnalysis) throws -> [FoodItem] {
        let items = analysis.items
            .filter { !$0.name.trimmingCharacters(in: .whitespaces).isEmpty }
            .map { guess in
                FoodItem(
                    name: guess.name,
                    amount: guess.amount.isEmpty ? "1인분" : guess.amount,
                    calories: Double(max(guess.calories, 0)),
                    carbohydrates: Double(max(guess.carbohydrates, 0)),
                    protein: Double(max(guess.protein, 0)),
                    fat: Double(max(guess.fat, 0)),
                    sugar: Double(max(guess.sugar, 0)),
                    sodium: Double(max(guess.sodium, 0))
                )
            }
        guard !items.isEmpty else { throw AnalyzerError.nothingFound }
        return items
    }
    #endif
}

#if canImport(FoundationModels)
@available(iOS 26.0, *)
@Generable
struct FoodAnalysis {
    @Guide(description: "먹은 음식 목록. 음식이 없으면 빈 배열")
    var items: [FoodGuess]
}

@available(iOS 26.0, *)
@Generable
struct FoodGuess {
    @Guide(description: "음식 이름 (한국어, 예: 김치찌개, 공기밥, 아메리카노)")
    var name: String
    @Guide(description: "먹은 양 (예: 1인분, 1공기, 3조각, 355ml)")
    var amount: String
    @Guide(description: "칼로리 (kcal)")
    var calories: Int
    @Guide(description: "탄수화물 (g)")
    var carbohydrates: Int
    @Guide(description: "단백질 (g)")
    var protein: Int
    @Guide(description: "지방 (g)")
    var fat: Int
    @Guide(description: "당류 (g)")
    var sugar: Int
    @Guide(description: "나트륨 (mg)")
    var sodium: Int
}
#endif
