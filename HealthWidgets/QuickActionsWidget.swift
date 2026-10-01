import AppIntents
import SwiftUI
import WidgetKit

struct QuickActionsEntry: TimelineEntry {
    let date: Date
    let medications: [(name: String, taken: Bool)]
    let water: Double
    let waterGoal: Double
}

struct QuickActionsProvider: TimelineProvider {
    func placeholder(in context: Context) -> QuickActionsEntry {
        QuickActionsEntry(date: .now, medications: [("탈모약", false)], water: 1250, waterGoal: 2000)
    }

    func getSnapshot(in context: Context, completion: @escaping (QuickActionsEntry) -> Void) {
        completion(context.isPreview ? placeholder(in: context) : makeEntry())
    }

    func getTimeline(in context: Context, completion: @escaping (Timeline<QuickActionsEntry>) -> Void) {
        // 자정에 복용 상태 · 물이 초기화되도록 다음 날 0시에 다시 그린다.
        let midnight = Calendar.current.nextDate(after: .now, matching: DateComponents(hour: 0), matchingPolicy: .nextTime)
            ?? .now.addingTimeInterval(3600)
        completion(Timeline(entries: [makeEntry()], policy: .after(min(midnight, .now.addingTimeInterval(30 * 60)))))
    }

    private func makeEntry() -> QuickActionsEntry {
        QuickActionsEntry(
            date: .now,
            medications: MedicationLog.todayStatus(),
            water: WaterLog.todayTotal(),
            waterGoal: WaterLog.todayGoal()
        )
    }
}

/// 홈 화면에서 바로 누르는 '빠른 기록' 위젯: 💊 복용 완료 · 💧 물 한 잔
struct QuickActionsWidget: Widget {
    var body: some WidgetConfiguration {
        StaticConfiguration(kind: "QuickActionsWidget", provider: QuickActionsProvider()) { entry in
            QuickActionsView(entry: entry)
                .containerBackground(for: .widget) { Color(.systemBackground) }
        }
        .configurationDisplayName("빠른 기록")
        .description("앱을 열지 않고 약 복용과 물 마시기를 기록해요.")
        .supportedFamilies([.systemSmall, .systemMedium])
    }
}

struct QuickActionsView: View {
    @Environment(\.widgetFamily) private var family
    let entry: QuickActionsEntry

    private var allTaken: Bool { !entry.medications.isEmpty && entry.medications.allSatisfy { $0.taken } }

    var body: some View {
        if family == .systemMedium {
            HStack(spacing: 12) {
                medicationButton
                waterButton
            }
        } else {
            VStack(spacing: 8) {
                medicationButton
                waterButton
            }
        }
    }

    private var medicationButton: some View {
        Button(intent: MarkMedicationTakenIntent()) {
            HStack(spacing: 8) {
                Image(systemName: allTaken ? "checkmark.circle.fill" : "pills.fill")
                    .font(.title3)
                    .foregroundStyle(allTaken ? .green : .purple)
                VStack(alignment: .leading, spacing: 1) {
                    Text(allTaken ? "복용 완료" : "약 먹었어요")
                        .font(.caption.weight(.semibold))
                    Text(entry.medications.isEmpty ? "약 없음" : entry.medications.map { $0.name }.joined(separator: ", "))
                        .font(.caption2)
                        .foregroundStyle(.secondary)
                        .lineLimit(1)
                }
                Spacer(minLength: 0)
            }
            .padding(10)
            .frame(maxWidth: .infinity, maxHeight: .infinity)
            .background((allTaken ? Color.green : Color.purple).opacity(0.12), in: RoundedRectangle(cornerRadius: 14))
        }
        .buttonStyle(.plain)
        .disabled(entry.medications.isEmpty || allTaken)
    }

    private var waterButton: some View {
        Button(intent: LogWaterIntent(amount: 250)) {
            HStack(spacing: 8) {
                Image(systemName: "drop.fill")
                    .font(.title3)
                    .foregroundStyle(.cyan)
                VStack(alignment: .leading, spacing: 1) {
                    Text("+250ml")
                        .font(.caption.weight(.semibold))
                    Text("\(Int(entry.water).formatted())/\(Int(entry.waterGoal).formatted())")
                        .font(.caption2.monospacedDigit())
                        .foregroundStyle(.secondary)
                }
                Spacer(minLength: 0)
            }
            .padding(10)
            .frame(maxWidth: .infinity, maxHeight: .infinity)
            .background(Color.cyan.opacity(0.12), in: RoundedRectangle(cornerRadius: 14))
        }
        .buttonStyle(.plain)
    }
}

// MARK: - 제어 센터 · 잠금 화면 버튼 (iOS 18+)

struct WaterControl: ControlWidget {
    var body: some ControlWidgetConfiguration {
        StaticControlConfiguration(kind: "WaterControl") {
            ControlWidgetButton(action: LogWaterIntent(amount: 250)) {
                Label("물 한 잔", systemImage: "drop.fill")
            }
        }
        .displayName("물 한 잔 기록")
        .description("물 250ml를 기록해요.")
    }
}

struct MedicationControl: ControlWidget {
    var body: some ControlWidgetConfiguration {
        StaticControlConfiguration(kind: "MedicationControl") {
            ControlWidgetButton(action: MarkMedicationTakenIntent()) {
                Label("약 먹었어요", systemImage: "pills.fill")
            }
        }
        .displayName("약 복용 완료")
        .description("오늘 먹을 약을 복용 완료로 기록해요.")
    }
}
