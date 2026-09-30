import AppIntents
import SwiftUI
import WidgetKit

enum LocationOption: String, AppEnum {
    case auto, eunpyeong, sogong, pyeongtaek

    static var typeDisplayRepresentation: TypeDisplayRepresentation = "지역"

    static var caseDisplayRepresentations: [LocationOption: DisplayRepresentation] = [
        .auto: "📍 자동 (현재 위치)",
        .eunpyeong: "은평구",
        .sogong: "소공동",
        .pyeongtaek: "평택",
    ]

    /// 자동이면 앱이 마지막으로 확인한 현재 위치에서 가장 가까운 지역
    var location: WeatherLocation {
        self == .auto
            ? SharedStore.currentLocation
            : WeatherLocation.all.first { $0.id == rawValue } ?? WeatherLocation.all[0]
    }
}

struct SelectLocationIntent: WidgetConfigurationIntent {
    static var title: LocalizedStringResource = "지역 선택"
    static var description = IntentDescription("위젯에 표시할 지역을 고르세요.")

    @Parameter(title: "지역", default: .auto)
    var location: LocationOption

    init() {}
}

struct WeatherEntry: TimelineEntry {
    let date: Date
    let location: WeatherLocation
    let report: WeatherReport?
    /// 중간 크기 위젯용: 세 지역 모두
    var all: [WeatherLocation.ID: WeatherReport] = [:]
    /// 현재 위치에서 가장 가까운 지역 (표시용)
    var currentID: String? = SharedStore.autoLocationID
}

struct WeatherProvider: AppIntentTimelineProvider {
    func placeholder(in context: Context) -> WeatherEntry {
        WeatherEntry(date: .now, location: WeatherLocation.all[0], report: nil)
    }

    func snapshot(for configuration: SelectLocationIntent, in context: Context) async -> WeatherEntry {
        await makeEntry(configuration, family: context.family)
    }

    func timeline(for configuration: SelectLocationIntent, in context: Context) async -> Timeline<WeatherEntry> {
        let entry = await makeEntry(configuration, family: context.family)
        return Timeline(entries: [entry], policy: .after(.now.addingTimeInterval(30 * 60)))
    }

    private func makeEntry(_ configuration: SelectLocationIntent, family: WidgetFamily) async -> WeatherEntry {
        let service = WeatherService()
        let selected = configuration.location.location

        guard family == .systemMedium else {
            return WeatherEntry(date: .now, location: selected, report: try? await service.report(for: selected))
        }

        let all = await withTaskGroup(of: (String, WeatherReport?).self) { group in
            for location in WeatherLocation.all {
                group.addTask { (location.id, try? await service.report(for: location)) }
            }
            var result: [String: WeatherReport] = [:]
            for await (id, report) in group {
                result[id] = report
            }
            return result
        }
        return WeatherEntry(date: .now, location: selected, report: all[selected.id], all: all)
    }
}

struct WeatherWidget: Widget {
    var body: some WidgetConfiguration {
        AppIntentConfiguration(kind: "WeatherWidget", intent: SelectLocationIntent.self, provider: WeatherProvider()) { entry in
            WeatherWidgetView(entry: entry)
                .containerBackground(for: .widget) {
                    LinearGradient(
                        colors: entry.report.map { $0.current.condition.gradient(isDay: $0.current.isDay) }
                            ?? [Color.blue.opacity(0.85), Color.cyan.opacity(0.7)],
                        startPoint: .topLeading, endPoint: .bottomTrailing
                    )
                }
        }
        .configurationDisplayName("날씨")
        .description("은평구 · 소공동 · 평택의 날씨와 미세먼지. 중간 크기는 세 지역을 모두 보여줍니다.")
        .supportedFamilies([.systemSmall, .systemMedium, .accessoryCircular, .accessoryRectangular])
    }
}

struct WeatherWidgetView: View {
    @Environment(\.widgetFamily) private var family
    let entry: WeatherEntry

    var body: some View {
        switch family {
        case .accessoryCircular:
            if let report = entry.report {
                VStack(spacing: 0) {
                    Image(systemName: report.current.condition.symbol(isDay: report.current.isDay))
                    Text(report.current.temperature.degreesText)
                        .font(.headline)
                }
            } else {
                Image(systemName: "cloud")
            }

        case .accessoryRectangular:
            if let report = entry.report {
                VStack(alignment: .leading, spacing: 1) {
                    Text("\(entry.location.name) \(report.current.temperature.degreesText)")
                        .font(.headline)
                    Text(report.current.condition.description)
                    if let grade = report.airQuality?.overallGrade {
                        Text("미세먼지 \(grade.title)")
                    }
                }
                .font(.caption)
            } else {
                Text("\(entry.location.name) 날씨 없음")
            }

        case .systemMedium:
            HStack(spacing: 0) {
                ForEach(WeatherLocation.all) { location in
                    column(location, report: entry.all[location.id])
                        .frame(maxWidth: .infinity)
                }
            }
            .foregroundStyle(.white)

        default:
            small
                .foregroundStyle(.white)
        }
    }

    private var small: some View {
        VStack(alignment: .leading, spacing: 4) {
            Text(entry.location.name)
                .font(.headline)
            if let report = entry.report {
                Text(report.current.temperature.degreesText)
                    .font(.system(size: 40, weight: .light, design: .rounded))
                Spacer(minLength: 0)
                Image(systemName: report.current.condition.symbol(isDay: report.current.isDay))
                    .symbolRenderingMode(.multicolor)
                Text(report.current.condition.description)
                    .font(.caption.weight(.semibold))
                if let today = report.today {
                    Text("최고 \(today.high.degreesText) 최저 \(today.low.degreesText)")
                        .font(.caption2)
                }
                if let grade = report.airQuality?.overallGrade {
                    Text("미세먼지 \(grade.title)")
                        .font(.caption2.weight(.semibold))
                }
            } else {
                Spacer()
                Text("날씨를 불러올 수 없어요")
                    .font(.caption)
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
    }

    private func column(_ location: WeatherLocation, report: WeatherReport?) -> some View {
        VStack(spacing: 5) {
            HStack(spacing: 2) {
                if location.id == entry.currentID {
                    Image(systemName: "location.fill")
                        .font(.system(size: 8))
                }
                Text(location.name)
            }
            .font(.caption.weight(.semibold))
            if let report {
                Image(systemName: report.current.condition.symbol(isDay: report.current.isDay))
                    .symbolRenderingMode(.multicolor)
                    .font(.title2)
                    .frame(height: 30)
                Text(report.current.temperature.degreesText)
                    .font(.title2.weight(.medium))
                if let today = report.today {
                    Text("\(today.low.degreesText)/\(today.high.degreesText)")
                        .font(.caption2)
                }
                Text(report.airQuality?.overallGrade.map { "먼지 \($0.title)" } ?? " ")
                    .font(.caption2.weight(.semibold))
            } else {
                Image(systemName: "cloud.slash")
                    .font(.title2)
            }
        }
    }
}
