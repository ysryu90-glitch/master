import SwiftUI

/// 오늘 먹어야 할 약과 복용 체크, 최근 7일 기록
struct MedicationCard: View {
    @Environment(MedicationStore.self) private var medications
    let onOpenSettings: () -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            HStack {
                SectionHeader(title: "오늘의 복약", symbol: "pills.fill", tint: .purple)
                Spacer()
                Button("알림 설정", action: onOpenSettings)
                    .font(.subheadline)
            }

            if medications.reminders.isEmpty {
                Text("알림 설정에서 약을 추가하면 매일 복용 여부를 체크할 수 있어요.")
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
            }

            ForEach(medications.reminders) { reminder in
                let taken = medications.isTaken(reminder)
                HStack(spacing: 12) {
                    Button {
                        withAnimation(.snappy) {
                            medications.setTaken(!taken, id: reminder.id)
                        }
                    } label: {
                        Image(systemName: taken ? "checkmark.circle.fill" : "circle")
                            .font(.title)
                            .foregroundStyle(taken ? .green : .secondary)
                    }
                    .buttonStyle(.plain)

                    VStack(alignment: .leading, spacing: 2) {
                        Text(reminder.name)
                            .font(.headline)
                            .strikethrough(taken, color: .secondary)
                        Text(taken ? "오늘 복용 완료" : (reminder.isEnabled ? "매일 \(reminder.timeText) 알림" : "알림 꺼짐"))
                            .font(.caption)
                            .foregroundStyle(taken ? .green : .secondary)
                    }

                    Spacer()

                    // 최근 7일 (왼쪽이 6일 전, 오른쪽이 오늘)
                    HStack(spacing: 4) {
                        ForEach(medications.weekHistory(reminder)) { day in
                            Circle()
                                .fill(day.taken ? Color.green : Color.secondary.opacity(0.25))
                                .frame(width: 8, height: 8)
                        }
                    }
                    .accessibilityLabel("최근 7일 중 \(medications.weekHistory(reminder).filter(\.taken).count)일 복용")
                }
            }
        }
        .card()
    }
}
