import SwiftUI

/// 애플워치 준비 점수와 같은 4단계 구분
enum ReadinessLevel: String, Codable {
    case recover, paceYourself, ready, goForIt

    init(score: Double) {
        switch score {
        case ..<4: self = .recover
        case ..<6: self = .paceYourself
        case ..<8: self = .ready
        default: self = .goForIt
        }
    }

    var title: String {
        switch self {
        case .recover: "회복 필요"
        case .paceYourself: "페이스 조절"
        case .ready: "준비 완료"
        case .goForIt: "최상의 컨디션"
        }
    }

    var advice: String {
        switch self {
        case .recover: "몸이 회복을 원하고 있어요. 가벼운 산책이나 스트레칭 정도로 쉬어 가세요."
        case .paceYourself: "무리하지 않는 선에서 가볍거나 중간 강도의 운동이 좋아요."
        case .ready: "평소처럼 운동하기 좋은 상태예요."
        case .goForIt: "컨디션이 아주 좋아요. 강도 높은 운동에 도전해 보세요!"
        }
    }

    var color: Color {
        switch self {
        case .recover: .red
        case .paceYourself: .orange
        case .ready: .green
        case .goForIt: .mint
        }
    }

    /// 카드 · 위젯 배경용 그라데이션 (흰 글씨가 잘 보이는 진한 톤)
    var gradient: [Color] {
        switch self {
        case .recover: [Color(red: 0.94, green: 0.33, blue: 0.40), Color(red: 0.74, green: 0.17, blue: 0.45)]
        case .paceYourself: [Color(red: 1.00, green: 0.62, blue: 0.24), Color(red: 0.93, green: 0.35, blue: 0.25)]
        case .ready: [Color(red: 0.20, green: 0.75, blue: 0.52), Color(red: 0.05, green: 0.52, blue: 0.56)]
        case .goForIt: [Color(red: 0.19, green: 0.78, blue: 0.86), Color(red: 0.27, green: 0.42, blue: 0.95)]
        }
    }

    var symbol: String {
        switch self {
        case .recover: "bed.double.fill"
        case .paceYourself: "tortoise.fill"
        case .ready: "checkmark.circle.fill"
        case .goForIt: "bolt.heart.fill"
        }
    }
}
