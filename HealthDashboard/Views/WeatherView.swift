import SwiftUI

struct WeatherView: View {
    @State private var model = WeatherModel()
    @Environment(\.scenePhase) private var scenePhase

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(spacing: 14) {
                    ForEach(model.locations) { location in
                        NavigationLink {
                            WeatherDetailView(location: location, report: model.reports[location.id])
                        } label: {
                            LocationWeatherCard(location: location, report: model.reports[location.id])
                        }
                        .buttonStyle(.plain)
                    }

                    if let error = model.errorMessage {
                        Label(error, systemImage: "wifi.exclamationmark")
                            .font(.footnote)
                            .foregroundStyle(.secondary)
                    }

                    Text("날씨 데이터: Open-Meteo · 미세먼지는 모델 추정값으로 에어코리아 측정값과 다를 수 있습니다.")
                        .font(.caption2)
                        .foregroundStyle(.tertiary)
                        .multilineTextAlignment(.center)
                        .padding(.top, 8)
                }
                .padding()
            }
            .background(Color(.systemGroupedBackground))
            .navigationTitle("날씨")
            .refreshable { await model.refresh(force: true) }
            .task { await model.refresh() }
            .onChange(of: scenePhase) { _, phase in
                if phase == .active { Task { await model.refresh() } }
            }
            .overlay {
                if model.isLoading && model.reports.isEmpty {
                    ProgressView("날씨를 불러오는 중…")
                }
            }
        }
    }
}

private struct LocationWeatherCard: View {
    let location: WeatherLocation
    let report: WeatherReport?

    var body: some View {
        HStack(alignment: .center, spacing: 16) {
            VStack(alignment: .leading, spacing: 6) {
                Text(location.name)
                    .font(.title2.bold())
                Text(location.address)
                    .font(.caption)
                    .foregroundStyle(.secondary)
                if let report {
                    Text(report.current.condition.description)
                        .font(.subheadline)
                    HStack(spacing: 8) {
                        if let today = report.today {
                            Text("최고 \(today.high.degreesText) 최저 \(today.low.degreesText)")
                        }
                        if let grade = report.airQuality?.overallGrade {
                            Text("미세먼지 \(grade.title)")
                                .foregroundStyle(grade.color)
                        }
                    }
                    .font(.caption.weight(.medium))
                    .foregroundStyle(.secondary)
                }
            }

            Spacer(minLength: 0)

            if let report {
                VStack(alignment: .trailing, spacing: 4) {
                    Image(systemName: report.current.condition.symbol(isDay: report.current.isDay))
                        .symbolRenderingMode(.multicolor)
                        .font(.system(size: 34))
                    Text(report.current.temperature.degreesText)
                        .font(.system(size: 44, weight: .light, design: .rounded))
                }
            } else {
                ProgressView()
            }
        }
        .card()
    }
}

struct WeatherDetailView: View {
    let location: WeatherLocation
    let report: WeatherReport?

    var body: some View {
        ScrollView {
            if let report {
                VStack(spacing: 16) {
                    header(report)
                    hourly(report)
                    daily(report)
                    details(report)
                }
                .padding()
            } else {
                ContentUnavailableView("날씨 정보를 불러오지 못했습니다", systemImage: "cloud.slash")
                    .padding(.top, 80)
            }
        }
        .background(Color(.systemGroupedBackground))
        .navigationTitle(location.name)
        .navigationBarTitleDisplayMode(.inline)
    }

    private func header(_ report: WeatherReport) -> some View {
        VStack(spacing: 6) {
            Text(location.address)
                .font(.subheadline)
                .foregroundStyle(.secondary)
            Image(systemName: report.current.condition.symbol(isDay: report.current.isDay))
                .symbolRenderingMode(.multicolor)
                .font(.system(size: 56))
                .padding(.vertical, 4)
            Text(report.current.temperature.degreesText)
                .font(.system(size: 72, weight: .thin, design: .rounded))
            Text(report.current.condition.description)
                .font(.title3)
            if let today = report.today {
                Text("최고 \(today.high.degreesText) · 최저 \(today.low.degreesText) · 체감 \(report.current.apparentTemperature.degreesText)")
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
            }
        }
        .frame(maxWidth: .infinity)
        .card()
    }

    private func hourly(_ report: WeatherReport) -> some View {
        VStack(alignment: .leading, spacing: 10) {
            Label("시간별 예보", systemImage: "clock")
                .font(.caption.weight(.semibold))
                .foregroundStyle(.secondary)
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 18) {
                    ForEach(Array(report.hours.enumerated()), id: \.element.id) { index, hour in
                        VStack(spacing: 8) {
                            Text(index == 0 ? "지금" : hour.date.formatted(.dateTime.hour()))
                                .font(.caption)
                            Image(systemName: hour.condition.symbol(isDay: hour.isDay))
                                .symbolRenderingMode(.multicolor)
                                .font(.title3)
                                .frame(height: 24)
                            if let probability = hour.precipitationProbability, probability >= 20 {
                                Text("\(Int(probability))%")
                                    .font(.caption2.weight(.semibold))
                                    .foregroundStyle(.cyan)
                            } else {
                                Text(" ").font(.caption2)
                            }
                            Text(hour.temperature.degreesText)
                                .font(.callout.weight(.medium))
                        }
                    }
                }
            }
        }
        .card()
    }

    private func daily(_ report: WeatherReport) -> some View {
        let low = report.days.map(\.low).min() ?? 0
        let high = report.days.map(\.high).max() ?? 1

        return VStack(alignment: .leading, spacing: 10) {
            Label("7일 예보", systemImage: "calendar")
                .font(.caption.weight(.semibold))
                .foregroundStyle(.secondary)
            ForEach(Array(report.days.enumerated()), id: \.element.id) { index, day in
                HStack(spacing: 10) {
                    Text(index == 0 ? "오늘" : day.date.formatted(.dateTime.weekday(.abbreviated)))
                        .frame(width: 36, alignment: .leading)
                    Image(systemName: day.condition.symbol())
                        .symbolRenderingMode(.multicolor)
                        .frame(width: 28)
                    Text(day.precipitationProbability.map { $0 >= 20 ? "\(Int($0))%" : "" } ?? "")
                        .font(.caption2.weight(.semibold))
                        .foregroundStyle(.cyan)
                        .frame(width: 34, alignment: .leading)
                    Text(day.low.degreesText)
                        .foregroundStyle(.secondary)
                        .frame(width: 36, alignment: .trailing)
                    TemperatureBar(low: day.low, high: day.high, rangeLow: low, rangeHigh: high)
                        .frame(height: 5)
                    Text(day.high.degreesText)
                        .frame(width: 36, alignment: .trailing)
                }
                .font(.callout.monospacedDigit())
                if index < report.days.count - 1 { Divider() }
            }
        }
        .card()
    }

    private func details(_ report: WeatherReport) -> some View {
        let columns = [GridItem(.flexible(), spacing: 12), GridItem(.flexible(), spacing: 12)]
        return LazyVGrid(columns: columns, spacing: 12) {
            if let air = report.airQuality {
                if let pm10 = air.pm10 {
                    DetailTile(title: "미세먼지 (PM10)", symbol: "aqi.medium",
                               value: "\(Int(pm10))㎍/㎥", note: AirQuality.pm10Grade(pm10).title,
                               noteColor: AirQuality.pm10Grade(pm10).color)
                }
                if let pm25 = air.pm25 {
                    DetailTile(title: "초미세먼지 (PM2.5)", symbol: "aqi.high",
                               value: "\(Int(pm25))㎍/㎥", note: AirQuality.pm25Grade(pm25).title,
                               noteColor: AirQuality.pm25Grade(pm25).color)
                }
            }
            DetailTile(title: "습도", symbol: "humidity.fill",
                       value: "\(Int(report.current.humidity))%")
            DetailTile(title: "바람", symbol: "wind",
                       value: "\(report.current.windSpeed.formatted(.number.precision(.fractionLength(1))))m/s")
            if let uv = report.today?.uvIndex {
                DetailTile(title: "자외선 지수", symbol: "sun.max.trianglebadge.exclamationmark",
                           value: uv.formatted(.number.precision(.fractionLength(0))), note: uvLevel(uv))
            }
            DetailTile(title: "강수량 (현재)", symbol: "drop.fill",
                       value: "\(report.current.precipitation.formatted(.number.precision(.fractionLength(1))))mm")
            if let sunrise = report.today?.sunrise {
                DetailTile(title: "일출", symbol: "sunrise.fill",
                           value: sunrise.formatted(date: .omitted, time: .shortened))
            }
            if let sunset = report.today?.sunset {
                DetailTile(title: "일몰", symbol: "sunset.fill",
                           value: sunset.formatted(date: .omitted, time: .shortened))
            }
        }
    }

    private func uvLevel(_ uv: Double) -> String {
        switch uv {
        case ..<3: "낮음"
        case ..<6: "보통"
        case ..<8: "높음"
        case ..<11: "매우 높음"
        default: "위험"
        }
    }
}

private struct TemperatureBar: View {
    let low: Double
    let high: Double
    let rangeLow: Double
    let rangeHigh: Double

    var body: some View {
        GeometryReader { proxy in
            let span = max(rangeHigh - rangeLow, 1)
            let start = (low - rangeLow) / span * proxy.size.width
            let width = max((high - low) / span * proxy.size.width, 4)
            ZStack(alignment: .leading) {
                Capsule().fill(.quaternary)
                Capsule()
                    .fill(LinearGradient(colors: [.cyan, .yellow, .orange], startPoint: .leading, endPoint: .trailing))
                    .frame(width: width)
                    .offset(x: start)
            }
        }
    }
}

private struct DetailTile: View {
    let title: String
    let symbol: String
    let value: String
    var note: String?
    var noteColor: Color = .secondary

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            Label(title, systemImage: symbol)
                .font(.caption.weight(.semibold))
                .foregroundStyle(.secondary)
                .lineLimit(1)
            Text(value)
                .font(.title3.weight(.semibold).monospacedDigit())
            Text(note ?? " ")
                .font(.caption.weight(.semibold))
                .foregroundStyle(noteColor)
        }
        .card()
    }
}
