import CoreLocation
import SwiftUI

struct WeatherLocation: Identifiable, Hashable {
    let id: String
    let name: String
    let address: String
    let latitude: Double
    let longitude: Double

    static let all: [WeatherLocation] = [
        WeatherLocation(id: "eunpyeong", name: "은평구", address: "서울특별시 은평구",
                        latitude: 37.6027, longitude: 126.9291),
        WeatherLocation(id: "sogong", name: "소공동", address: "서울특별시 중구 소공동",
                        latitude: 37.5638, longitude: 126.9797),
        WeatherLocation(id: "pyeongtaek", name: "평택", address: "경기도 평택시",
                        latitude: 36.9921, longitude: 127.1128),
    ]

    /// 현재 위치에서 가장 가까운 지역
    static func nearest(to coordinate: CLLocationCoordinate2D) -> WeatherLocation {
        let here = CLLocation(latitude: coordinate.latitude, longitude: coordinate.longitude)
        return all.min {
            here.distance(from: CLLocation(latitude: $0.latitude, longitude: $0.longitude))
                < here.distance(from: CLLocation(latitude: $1.latitude, longitude: $1.longitude))
        } ?? all[0]
    }
}

struct WeatherReport {
    struct Current {
        let temperature: Double
        let apparentTemperature: Double
        let humidity: Double
        let windSpeed: Double
        let precipitation: Double
        let condition: WeatherCondition
        let isDay: Bool
    }

    struct Hour: Identifiable {
        let date: Date
        let temperature: Double
        let condition: WeatherCondition
        let precipitationProbability: Double?
        let isDay: Bool
        var id: Date { date }
    }

    struct Day: Identifiable {
        let date: Date
        let condition: WeatherCondition
        let high: Double
        let low: Double
        let precipitationProbability: Double?
        let uvIndex: Double?
        let sunrise: Date?
        let sunset: Date?
        var id: Date { date }
    }

    let current: Current
    let hours: [Hour]
    let days: [Day]
    var airQuality: AirQuality?
    let fetchedAt: Date

    var today: Day? { days.first }
}

struct AirQuality {
    let pm10: Double?
    let pm25: Double?

    /// 환경부 미세먼지 예보 등급 기준
    enum Grade {
        case good, moderate, bad, veryBad

        var title: String {
            switch self {
            case .good: "좋음"
            case .moderate: "보통"
            case .bad: "나쁨"
            case .veryBad: "매우 나쁨"
            }
        }

        var color: Color {
            switch self {
            case .good: .blue
            case .moderate: .green
            case .bad: .orange
            case .veryBad: .red
            }
        }
    }

    static func pm10Grade(_ value: Double) -> Grade {
        switch value {
        case ...30: .good
        case ...80: .moderate
        case ...150: .bad
        default: .veryBad
        }
    }

    static func pm25Grade(_ value: Double) -> Grade {
        switch value {
        case ...15: .good
        case ...35: .moderate
        case ...75: .bad
        default: .veryBad
        }
    }

    /// 두 값 중 더 나쁜 등급
    var overallGrade: Grade? {
        let grades = [pm10.map(Self.pm10Grade), pm25.map(Self.pm25Grade)].compactMap { $0 }
        let order: [Grade] = [.good, .moderate, .bad, .veryBad]
        return grades.max { order.firstIndex(of: $0)! < order.firstIndex(of: $1)! }
    }
}

/// WMO 날씨 코드 (Open-Meteo)
struct WeatherCondition {
    let code: Int

    var description: String {
        switch code {
        case 0: "맑음"
        case 1: "대체로 맑음"
        case 2: "구름 조금"
        case 3: "흐림"
        case 45, 48: "안개"
        case 51, 53, 55: "이슬비"
        case 56, 57: "어는 이슬비"
        case 61: "약한 비"
        case 63: "비"
        case 65: "강한 비"
        case 66, 67: "어는 비"
        case 71: "약한 눈"
        case 73: "눈"
        case 75: "많은 눈"
        case 77: "싸락눈"
        case 80, 81: "소나기"
        case 82: "강한 소나기"
        case 85, 86: "소낙눈"
        case 95: "뇌우"
        case 96, 99: "우박을 동반한 뇌우"
        default: "알 수 없음"
        }
    }

    /// 날씨에 어울리는 배경 그라데이션 (흰 글씨용)
    func gradient(isDay: Bool = true) -> [Color] {
        switch code {
        case 0, 1, 2:
            isDay
                ? [Color(red: 0.33, green: 0.66, blue: 0.98), Color(red: 0.14, green: 0.42, blue: 0.86)]
                : [Color(red: 0.12, green: 0.15, blue: 0.36), Color(red: 0.27, green: 0.21, blue: 0.52)]
        case 3, 45, 48:
            [Color(red: 0.55, green: 0.62, blue: 0.72), Color(red: 0.36, green: 0.43, blue: 0.55)]
        case 71, 73, 75, 77, 85, 86:
            [Color(red: 0.60, green: 0.71, blue: 0.86), Color(red: 0.40, green: 0.51, blue: 0.72)]
        case 95, 96, 99:
            [Color(red: 0.27, green: 0.24, blue: 0.42), Color(red: 0.12, green: 0.12, blue: 0.24)]
        default: // 비 · 이슬비 · 소나기
            [Color(red: 0.31, green: 0.41, blue: 0.58), Color(red: 0.18, green: 0.25, blue: 0.40)]
        }
    }

    func symbol(isDay: Bool = true) -> String {
        switch code {
        case 0: isDay ? "sun.max.fill" : "moon.stars.fill"
        case 1: isDay ? "sun.max.fill" : "moon.fill"
        case 2: isDay ? "cloud.sun.fill" : "cloud.moon.fill"
        case 3: "cloud.fill"
        case 45, 48: "cloud.fog.fill"
        case 51, 53, 55: "cloud.drizzle.fill"
        case 56, 57, 66, 67: "cloud.sleet.fill"
        case 61, 63: "cloud.rain.fill"
        case 65, 82: "cloud.heavyrain.fill"
        case 71, 73, 75, 77, 85, 86: "cloud.snow.fill"
        case 80, 81: isDay ? "cloud.sun.rain.fill" : "cloud.moon.rain.fill"
        case 95, 96, 99: "cloud.bolt.rain.fill"
        default: "questionmark.circle"
        }
    }
}

extension Double {
    /// 예: "23°"
    var degreesText: String { "\(Int(rounded()))°" }
}
