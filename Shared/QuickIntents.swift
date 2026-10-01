import AppIntents
import Foundation
import WidgetKit

/// "시리야, 건강 대시보드에 물 한 잔 기록해" · 위젯 버튼 · 제어 센터
struct LogWaterIntent: AppIntent {
    static var title: LocalizedStringResource = "물 한 잔 기록"
    static var description = IntentDescription("마신 물을 건강 기록에 추가합니다.")

    @Parameter(title: "양 (ml)", default: 250)
    var amount: Int

    init() {}

    init(amount: Int) {
        self.amount = amount
    }

    func perform() async throws -> some IntentResult & ProvidesDialog {
        WaterLog.add(ml: Double(amount))
        WidgetCenter.shared.reloadAllTimelines()
        let total = Int(WaterLog.todayTotal())
        return .result(dialog: "물 \(amount)ml를 기록했어요. 오늘 \(total)ml 마셨어요.")
    }
}

/// "시리야, 건강 대시보드 약 먹었어" · 위젯 버튼 · 제어 센터
struct MarkMedicationTakenIntent: AppIntent {
    static var title: LocalizedStringResource = "약 복용 완료"
    static var description = IntentDescription("오늘 먹어야 할 약을 모두 복용 완료로 기록합니다.")

    init() {}

    func perform() async throws -> some IntentResult & ProvidesDialog {
        let names = MedicationLog.markAllTakenToday()
        WidgetCenter.shared.reloadAllTimelines()
        if names.isEmpty {
            return .result(dialog: "오늘 먹을 약은 이미 모두 기록돼 있어요.")
        }
        return .result(dialog: "\(names.joined(separator: ", ")) 복용을 기록했어요. 👍")
    }
}

/// "시리야, 건강 대시보드 오늘 컨디션"
struct ConditionIntent: AppIntent {
    static var title: LocalizedStringResource = "오늘 컨디션"
    static var description = IntentDescription("오늘 준비 점수와 지난밤 수면을 알려줍니다.")

    init() {}

    func perform() async throws -> some IntentResult & ProvidesDialog {
        guard let snapshot = SharedStore.healthSnapshot else {
            return .result(dialog: "앱을 한 번 열어 건강 데이터를 불러와 주세요.")
        }
        var parts: [String] = []
        if let readiness = snapshot.readiness(on: .now) {
            parts.append("오늘 준비 점수는 \(readiness.score.formatted(.number.precision(.fractionLength(1))))점, \(readiness.level.title)이에요.")
            parts.append(readiness.level.advice)
        } else {
            parts.append("오늘 준비 점수는 아직 계산되지 않았어요. 앱을 열면 계산해요.")
        }
        if let sleep = snapshot.sleepSummary {
            parts.append("지난밤 \(sleep) 주무셨어요.")
        }
        return .result(dialog: "\(parts.joined(separator: " "))")
    }
}

/// "시리야, 건강 대시보드 오늘 저녁 메뉴"
struct TonightMenuIntent: AppIntent {
    static var title: LocalizedStringResource = "오늘 저녁 메뉴"
    static var description = IntentDescription("가족 식탁에 계획한 오늘 저녁 메뉴를 알려줍니다.")

    init() {}

    func perform() async throws -> some IntentResult & ProvidesDialog {
        if let dish = SharedStore.healthSnapshot?.tonightDish {
            return .result(dialog: "오늘 저녁은 \(dish)예요. 🍽")
        }
        return .result(dialog: "오늘 저녁 메뉴는 아직 정하지 않았어요. 앱의 가족 식탁에서 추천받아 보세요.")
    }
}
