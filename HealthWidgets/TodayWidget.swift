import SwiftUI
import WidgetKit

struct TodayEntry: TimelineEntry {
    let date: Date
    let snapshot: HealthSnapshot?
    let location: WeatherLocation
    let report: WeatherReport?

    var recommendation: WorkoutRecommendation {
        WorkoutAdvisor.recommend(
            readiness: snapshot?.todayReadiness?.level,
            forecast: report?.today,
            airGrade: report?.airQuality?.overallGrade
        )
    }
}

struct TodayProvider: TimelineProvider {
    func placeholder(in context: Context) -> TodayEntry {
        TodayEntry(date: .now, snapshot: .placeholder, location: WeatherLocation.all[0], report: nil)
    }

    func getSnapshot(in context: Context, completion: @escaping (TodayEntry) -> Void) {
        if context.isPreview {
            completion(placeholder(in: context))
            return
        }
        Task { completion(await makeEntry()) }
    }

    func getTimeline(in context: Context, completion: @escaping (Timeline<TodayEntry>) -> Void) {
        Task {
            let entry = await makeEntry()
            completion(Timeline(entries: [entry], policy: .after(.now.addingTimeInterval(30 * 60))))
        }
    }

    private func makeEntry() async -> TodayEntry {
        let location = SharedStore.primaryLocation
        let report = try? await WeatherService().report(for: location)
        return TodayEntry(date: .now, snapshot: SharedStore.healthSnapshot, location: location, report: report)
    }
}

struct TodayWidget: Widget {
    var body: some WidgetConfiguration {
        StaticConfiguration(kind: "TodayWidget", provider: TodayProvider()) { entry in
            TodayWidgetView(entry: entry)
                .containerBackground(for: .widget) { Color(.systemBackground) }
        }
        .configurationDisplayName("오늘 한눈에")
        .description("준비 점수, 운동 추천, 날씨를 한 번에 보여줍니다.")
        .supportedFamilies([.systemSmall, .systemMedium, .accessoryCircular, .accessoryRectangular, .accessoryInline])
    }
}

struct TodayWidgetView: View {
    @Environment(\.widgetFamily) private var family
    let entry: TodayEntry

    private var readiness: (score: Double, level: ReadinessLevel)? { entry.snapshot?.todayReadiness }
    private var levelColor: Color { readiness?.level.color ?? .gray }

    var body: some View {
        switch family {
        case .accessoryCircular:
            Gauge(value: readiness?.score ?? 0, in: 0...10) {
                Text("준비")
            } currentValueLabel: {
                Text(readiness.map { $0.score.formatted(.number.precision(.fractionLength(1))) } ?? "–")
            }
            .gaugeStyle(.accessoryCircular)

        case .accessoryRectangular:
            VStack(alignment: .leading, spacing: 1) {
                Text(readiness.map { "준비 \($0.score.formatted(.number.precision(.fractionLength(1)))) · \($0.level.title)" } ?? "준비 점수 –")
                    .font(.headline)
                if let report = entry.report {
                    Text("\(entry.location.name) \(report.current.temperature.degreesText) \(report.current.condition.description)")
                }
                Text(entry.recommendation.title)
                    .foregroundStyle(.secondary)
            }
            .font(.caption)
            .lineLimit(1)

        case .accessoryInline:
            if let report = entry.report {
                Text("준비 \(readiness.map { $0.score.formatted(.number.precision(.fractionLength(1))) } ?? "–") · \(entry.location.name) \(report.current.temperature.degreesText)")
            } else {
                Text("준비 점수 \(readiness.map { $0.score.formatted(.number.precision(.fractionLength(1))) } ?? "–")")
            }

        case .systemMedium:
            medium

        default:
            small
        }
    }

    private var small: some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack {
                Text("준비 점수")
                    .font(.caption.weight(.semibold))
                    .foregroundStyle(levelColor)
                Spacer()
                if let report = entry.report {
                    Image(systemName: report.current.condition.symbol(isDay: report.current.isDay))
                        .symbolRenderingMode(.multicolor)
                }
            }
            ReadinessRing(score: readiness?.score, color: levelColor, lineWidth: 9)
                .frame(maxWidth: .infinity, maxHeight: .infinity)
            Text(readiness?.level.title ?? "앱을 열어 계산")
                .font(.caption.weight(.semibold))
                .foregroundStyle(levelColor)
                .frame(maxWidth: .infinity)
        }
    }

    private var medium: some View {
        HStack(spacing: 14) {
            VStack(spacing: 6) {
                ReadinessRing(score: readiness?.score, color: levelColor, lineWidth: 9)
                    .frame(width: 84, height: 84)
                Text(readiness?.level.title ?? "준비 점수 –")
                    .font(.caption.weight(.semibold))
                    .foregroundStyle(levelColor)
            }

            VStack(alignment: .leading, spacing: 8) {
                Label(entry.recommendation.title, systemImage: entry.recommendation.symbol)
                    .font(.subheadline.weight(.semibold))
                    .lineLimit(2)

                if let report = entry.report {
                    HStack(spacing: 4) {
                        Image(systemName: report.current.condition.symbol(isDay: report.current.isDay))
                            .symbolRenderingMode(.multicolor)
                        Text("\(entry.location.name) \(report.current.temperature.degreesText)")
                        if let grade = report.airQuality?.overallGrade {
                            Text("· 먼지 \(grade.title)")
                                .foregroundStyle(grade.color)
                        }
                    }
                    .font(.caption)
                }

                HStack(spacing: 6) {
                    RingsView(snapshot: entry.snapshot, lineWidth: 3.5)
                        .frame(width: 26, height: 26)
                    if let steps = entry.snapshot?.steps {
                        Text("\(Int(steps).formatted()) 걸음")
                            .font(.caption.monospacedDigit())
                    }
                }

                if let updated = entry.snapshot?.updatedAt {
                    Text("\(updated, style: .relative) 전 갱신")
                        .font(.caption2)
                        .foregroundStyle(.secondary)
                }
            }
            Spacer(minLength: 0)
        }
    }
}
