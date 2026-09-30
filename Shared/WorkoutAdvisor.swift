import Foundation

/// 준비 점수(컨디션) + 날씨 + 미세먼지를 합쳐 오늘의 운동을 추천한다.
struct WorkoutRecommendation {
    let title: String
    let symbol: String
    let isOutdoor: Bool
    /// 추천 이유, 우산 등 참고 사항
    let notes: [String]
}

enum WorkoutAdvisor {
    static func recommend(
        readiness: ReadinessLevel?,
        forecast: WeatherReport.Day?,
        airGrade: AirQuality.Grade?
    ) -> WorkoutRecommendation {
        var notes: [String] = []
        var outdoorOK = true

        if let forecast {
            let rainy = [51, 53, 55, 56, 57, 61, 63, 65, 66, 67, 71, 73, 75, 77, 80, 81, 82, 85, 86, 95, 96, 99]
                .contains(forecast.condition.code)
            let probability = forecast.precipitationProbability ?? 0
            if rainy || probability >= 60 {
                outdoorOK = false
                notes.append("\(forecast.condition.description) 소식(강수 \(Int(probability))%)이 있어 실내 운동을 추천해요.")
            }
            if probability >= 40 {
                notes.append("☔ 우산을 챙기세요.")
            }
            if forecast.high >= 32 {
                outdoorOK = false
                notes.append("낮 최고 \(forecast.high.degreesText)로 더워요. 야외 운동은 이른 아침이나 저녁에 하세요.")
            } else if forecast.low <= -8 {
                outdoorOK = false
                notes.append("최저 \(forecast.low.degreesText)로 추워요. 실내 운동이 안전해요.")
            }
        }

        if let airGrade, airGrade == .bad || airGrade == .veryBad {
            outdoorOK = false
            notes.append("미세먼지가 \(airGrade.title)이라 야외 운동은 피하세요.")
        }

        if readiness == nil {
            notes.append("준비 점수가 아직 없어 날씨만 반영했어요.")
        }

        let choice: (title: String, symbol: String) = switch (readiness ?? .ready, outdoorOK) {
        case (.recover, true): ("휴식 · 가벼운 산책", "figure.walk")
        case (.recover, false): ("휴식 · 스트레칭", "figure.flexibility")
        case (.paceYourself, true): ("가벼운 걷기 30분", "figure.walk")
        case (.paceYourself, false): ("실내 요가 · 스트레칭", "figure.yoga")
        case (.ready, true): ("야외 러닝 · 자전거", "figure.run")
        case (.ready, false): ("실내 근력 운동 · 실내 자전거", "figure.indoor.cycle")
        case (.goForIt, true): ("인터벌 러닝 · 고강도 야외 운동", "figure.run")
        case (.goForIt, false): ("고강도 인터벌(HIIT) · 근력 운동", "figure.highintensity.intervaltraining")
        }

        return WorkoutRecommendation(title: choice.title, symbol: choice.symbol, isOutdoor: outdoorOK, notes: notes)
    }
}
