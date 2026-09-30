import SwiftUI
import WidgetKit

struct ActivityEntry: TimelineEntry {
    let date: Date
    let snapshot: HealthSnapshot?
}

struct ActivityProvider: TimelineProvider {
    func placeholder(in context: Context) -> ActivityEntry {
        ActivityEntry(date: .now, snapshot: .placeholder)
    }

    func getSnapshot(in context: Context, completion: @escaping (ActivityEntry) -> Void) {
        completion(context.isPreview ? placeholder(in: context) : ActivityEntry(date: .now, snapshot: SharedStore.healthSnapshot))
    }

    func getTimeline(in context: Context, completion: @escaping (Timeline<ActivityEntry>) -> Void) {
        let entry = ActivityEntry(date: .now, snapshot: SharedStore.healthSnapshot)
        completion(Timeline(entries: [entry], policy: .after(.now.addingTimeInterval(30 * 60))))
    }
}

struct ActivityWidget: Widget {
    var body: some WidgetConfiguration {
        StaticConfiguration(kind: "ActivityWidget", provider: ActivityProvider()) { entry in
            ActivityWidgetView(entry: entry)
                .containerBackground(for: .widget) { Color(.systemBackground) }
        }
        .configurationDisplayName("활동 링")
        .description("움직이기 · 운동하기 · 일어서기와 걸음 수를 보여줍니다.")
        .supportedFamilies([.systemSmall, .accessoryCircular])
    }
}

struct ActivityWidgetView: View {
    @Environment(\.widgetFamily) private var family
    let entry: ActivityEntry

    /// 날짜가 바뀐 뒤 앱이 아직 갱신하지 않았으면 어제 값을 보여주지 않는다.
    private var snapshot: HealthSnapshot? {
        entry.snapshot.flatMap { $0.isFromToday ? $0 : nil }
    }

    var body: some View {
        switch family {
        case .accessoryCircular:
            RingsView(snapshot: snapshot, lineWidth: 5)
        default:
            if entry.snapshot == nil {
                NoDataView()
            } else {
                HStack(spacing: 10) {
                    RingsView(snapshot: snapshot, lineWidth: 8)
                        .frame(width: 70, height: 70)
                    VStack(alignment: .leading, spacing: 4) {
                        stat(snapshot?.move, unit: "kcal", color: .red)
                        stat(snapshot?.exercise, unit: "분", color: .green)
                        stat(snapshot?.stand, unit: "시간", color: .cyan)
                    }
                }
                .frame(maxHeight: .infinity)
                .overlay(alignment: .bottom) {
                    VStack(spacing: 0) {
                        if let steps = snapshot?.steps {
                            Text("\(Int(steps).formatted()) 걸음")
                                .font(.caption2.monospacedDigit())
                        }
                        if let updated = entry.snapshot?.updatedAt {
                            Text("\(updated, style: .relative) 전")
                                .font(.system(size: 9))
                        }
                    }
                    .foregroundStyle(.secondary)
                }
            }
        }
    }

    private func stat(_ value: Double?, unit: String, color: Color) -> some View {
        Text("\(Int(value ?? 0))\(unit)")
            .font(.caption.weight(.semibold).monospacedDigit())
            .foregroundStyle(color)
    }
}
