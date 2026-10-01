import Foundation
import Observation

/// Open-Meteo 무료 API (API 키 불필요)
struct WeatherService {
    private let session = URLSession.shared

    func report(for location: WeatherLocation) async throws -> WeatherReport {
        async let forecast = fetchForecast(location)
        async let air = try? fetchAirQuality(location)
        var report = try await forecast
        report.airQuality = await air
        return report
    }

    private func fetchForecast(_ location: WeatherLocation) async throws -> WeatherReport {
        var components = URLComponents(string: "https://api.open-meteo.com/v1/forecast")!
        components.queryItems = [
            URLQueryItem(name: "latitude", value: String(location.latitude)),
            URLQueryItem(name: "longitude", value: String(location.longitude)),
            URLQueryItem(name: "current", value: "temperature_2m,apparent_temperature,relative_humidity_2m,weather_code,wind_speed_10m,precipitation,is_day"),
            URLQueryItem(name: "hourly", value: "temperature_2m,weather_code,precipitation_probability,is_day"),
            URLQueryItem(name: "daily", value: "weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max,uv_index_max,sunrise,sunset"),
            URLQueryItem(name: "timezone", value: "Asia/Seoul"),
            URLQueryItem(name: "timeformat", value: "unixtime"),
            URLQueryItem(name: "wind_speed_unit", value: "ms"),
            URLQueryItem(name: "forecast_days", value: "7"),
        ]
        let response: ForecastResponse = try await get(components.url!)
        return response.report()
    }

    private func fetchAirQuality(_ location: WeatherLocation) async throws -> AirQuality {
        var components = URLComponents(string: "https://air-quality-api.open-meteo.com/v1/air-quality")!
        components.queryItems = [
            URLQueryItem(name: "latitude", value: String(location.latitude)),
            URLQueryItem(name: "longitude", value: String(location.longitude)),
            URLQueryItem(name: "current", value: "pm10,pm2_5"),
            URLQueryItem(name: "timezone", value: "Asia/Seoul"),
            URLQueryItem(name: "timeformat", value: "unixtime"),
        ]
        let response: AirQualityResponse = try await get(components.url!)
        return AirQuality(pm10: response.current.pm10, pm25: response.current.pm2_5)
    }

    private func get<T: Decodable>(_ url: URL) async throws -> T {
        let (data, response) = try await session.data(from: url)
        guard let http = response as? HTTPURLResponse, (200..<300).contains(http.statusCode) else {
            throw URLError(.badServerResponse)
        }
        return try JSONDecoder().decode(T.self, from: data)
    }
}

// MARK: - API 응답 (필드 이름은 JSON 키와 동일)

private struct ForecastResponse: Decodable {
    struct Current: Decodable {
        let temperature_2m: Double
        let apparent_temperature: Double
        let relative_humidity_2m: Double
        let weather_code: Int
        let wind_speed_10m: Double
        let precipitation: Double?
        let is_day: Int
    }

    struct Hourly: Decodable {
        let time: [TimeInterval]
        let temperature_2m: [Double?]
        let weather_code: [Int?]
        let precipitation_probability: [Double?]?
        let is_day: [Int?]
    }

    struct Daily: Decodable {
        let time: [TimeInterval]
        let weather_code: [Int?]
        let temperature_2m_max: [Double?]
        let temperature_2m_min: [Double?]
        let precipitation_probability_max: [Double?]?
        let uv_index_max: [Double?]?
        let sunrise: [TimeInterval?]?
        let sunset: [TimeInterval?]?
    }

    let current: Current
    let hourly: Hourly
    let daily: Daily

    func report() -> WeatherReport {
        let now = Date.now
        let hours: [WeatherReport.Hour] = hourly.time.indices.compactMap { i in
            let date = Date(timeIntervalSince1970: hourly.time[i])
            guard date > now.addingTimeInterval(-3600),
                  let temperature = hourly.temperature_2m[i],
                  let code = hourly.weather_code[i] else { return nil }
            return WeatherReport.Hour(
                date: date,
                temperature: temperature,
                condition: WeatherCondition(code: code),
                precipitationProbability: hourly.precipitation_probability?[i],
                isDay: (hourly.is_day[i] ?? 1) == 1
            )
        }

        let days: [WeatherReport.Day] = daily.time.indices.compactMap { i in
            guard let high = daily.temperature_2m_max[i],
                  let low = daily.temperature_2m_min[i],
                  let code = daily.weather_code[i] else { return nil }
            return WeatherReport.Day(
                date: Date(timeIntervalSince1970: daily.time[i]),
                condition: WeatherCondition(code: code),
                high: high,
                low: low,
                precipitationProbability: daily.precipitation_probability_max?[i],
                uvIndex: daily.uv_index_max?[i],
                sunrise: daily.sunrise?[i].map(Date.init(timeIntervalSince1970:)),
                sunset: daily.sunset?[i].map(Date.init(timeIntervalSince1970:))
            )
        }

        return WeatherReport(
            current: WeatherReport.Current(
                temperature: current.temperature_2m,
                apparentTemperature: current.apparent_temperature,
                humidity: current.relative_humidity_2m,
                windSpeed: current.wind_speed_10m,
                precipitation: current.precipitation ?? 0,
                condition: WeatherCondition(code: current.weather_code),
                isDay: current.is_day == 1
            ),
            hours: Array(hours.prefix(24)),
            days: days,
            airQuality: nil,
            fetchedAt: now
        )
    }
}

private struct AirQualityResponse: Decodable {
    struct Current: Decodable {
        let pm10: Double?
        let pm2_5: Double?
    }

    let current: Current
}

// MARK: - 화면 상태

@MainActor
@Observable
final class WeatherModel {
    private let service = WeatherService()

    private(set) var reports: [WeatherLocation.ID: WeatherReport] = [:]
    private(set) var isLoading = false
    private(set) var errorMessage: String?

    let locations = WeatherLocation.all

    /// 10분 이내에 받은 데이터가 있으면 다시 받지 않는다.
    func refresh(force: Bool = false) async {
        guard !isLoading else { return }
        if !force, let oldest = reports.values.map(\.fetchedAt).min(),
           reports.count == locations.count, Date.now.timeIntervalSince(oldest) < 600 {
            return
        }

        isLoading = true
        defer { isLoading = false }
        errorMessage = nil

        let service = self.service
        let results = await withTaskGroup(of: (String, WeatherReport?).self) { group in
            for location in locations {
                group.addTask { (location.id, try? await service.report(for: location)) }
            }
            var results: [String: WeatherReport] = [:]
            for await (id, report) in group {
                results[id] = report
            }
            return results
        }

        reports.merge(results) { _, new in new }
        if let high = reports[SharedStore.primaryLocation.id]?.today?.high {
            SharedStore.defaults.set(high, forKey: SharedStore.todayHighKey)
        }
        if results.count < locations.count {
            errorMessage = "일부 지역의 날씨를 불러오지 못했습니다. 인터넷 연결을 확인해 주세요."
        }
    }
}
