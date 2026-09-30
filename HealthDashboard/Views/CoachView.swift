import SwiftUI

/// AI 건강 코치 탭: 내 건강 데이터를 바탕으로 질문에 답한다.
struct CoachView: View {
    @Environment(DashboardModel.self) private var dashboard
    @Environment(MedicationStore.self) private var medications
    @Environment(WeatherModel.self) private var weather
    @State private var coach = CoachModel()
    @State private var input = ""
    @FocusState private var inputFocused: Bool

    var body: some View {
        NavigationStack {
            Group {
                if let reason = coach.unavailableReason {
                    ContentUnavailableView {
                        Label("AI 코치를 사용할 수 없어요", systemImage: "sparkles")
                    } description: {
                        Text(reason)
                    } actions: {
                        Button("다시 확인") { coach.checkAvailability() }
                    }
                } else {
                    chat
                }
            }
            .navigationTitle("AI 코치")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .topBarLeading) {
                    NavigationLink {
                        WeeklyReportView()
                    } label: {
                        Label("주간 리포트", systemImage: "chart.bar.doc.horizontal")
                    }
                }
                ToolbarItem(placement: .topBarTrailing) {
                    Button {
                        coach.reset()
                    } label: {
                        Label("새 대화", systemImage: "square.and.pencil")
                    }
                    .disabled(coach.messages.isEmpty || coach.isResponding)
                }
            }
            .task { coach.checkAvailability() }
        }
    }

    private var chat: some View {
        VStack(spacing: 0) {
            ScrollViewReader { proxy in
                ScrollView {
                    LazyVStack(alignment: .leading, spacing: 14) {
                        if coach.messages.isEmpty {
                            intro
                        }
                        ForEach(coach.messages) { message in
                            MessageBubble(message: message)
                                .id(message.id)
                        }
                        if coach.isResponding {
                            HStack(spacing: 8) {
                                ProgressView()
                                Text("데이터를 살펴보는 중…")
                                    .font(.subheadline)
                                    .foregroundStyle(.secondary)
                            }
                            .id("typing")
                        }
                    }
                    .padding()
                }
                .scrollDismissesKeyboard(.interactively)
                .onChange(of: coach.messages.count) { _, _ in
                    if let last = coach.messages.last?.id {
                        withAnimation { proxy.scrollTo(last, anchor: .bottom) }
                    }
                }
                .onChange(of: coach.isResponding) { _, responding in
                    if responding { withAnimation { proxy.scrollTo("typing", anchor: .bottom) } }
                }
            }

            if !coach.messages.isEmpty {
                suggestionRow
            }
            inputBar
        }
        .background(Color(.systemGroupedBackground))
    }

    private var intro: some View {
        VStack(alignment: .leading, spacing: 14) {
            Image(systemName: "sparkles")
                .font(.largeTitle)
                .foregroundStyle(.purple.gradient)
            Text("무엇이든 물어보세요")
                .font(.title2.bold())
            Text("준비 점수, 수면, 심박, 활동, 복약, 습관 기록, 날씨를 바탕으로 답해 드려요. 모든 처리는 아이폰 안의 Apple Intelligence에서 이뤄지고 데이터는 밖으로 나가지 않아요.")
                .font(.subheadline)
                .foregroundStyle(.secondary)

            VStack(alignment: .leading, spacing: 8) {
                ForEach(CoachModel.suggestions, id: \.self) { suggestion in
                    Button {
                        ask(suggestion)
                    } label: {
                        Label(suggestion, systemImage: "bubble.left")
                            .padding(.horizontal, 14)
                            .padding(.vertical, 10)
                            .frame(maxWidth: .infinity, alignment: .leading)
                            .background(Color(.secondarySystemGroupedBackground), in: RoundedRectangle(cornerRadius: 12))
                    }
                    .buttonStyle(.plain)
                }
            }

            Text("AI 답변은 참고용이며 의료적 진단이 아닙니다.")
                .font(.caption)
                .foregroundStyle(.tertiary)
        }
        .padding(.top, 8)
    }

    private var suggestionRow: some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 8) {
                ForEach(CoachModel.suggestions, id: \.self) { suggestion in
                    Button(suggestion) { ask(suggestion) }
                        .font(.caption)
                        .buttonStyle(.bordered)
                        .disabled(coach.isResponding)
                }
            }
            .padding(.horizontal)
            .padding(.vertical, 6)
        }
    }

    private var inputBar: some View {
        HStack(spacing: 10) {
            TextField("건강에 대해 물어보세요", text: $input, axis: .vertical)
                .lineLimit(1...4)
                .padding(.horizontal, 14)
                .padding(.vertical, 10)
                .background(Color(.secondarySystemGroupedBackground), in: RoundedRectangle(cornerRadius: 20))
                .focused($inputFocused)
                .submitLabel(.send)
                .onSubmit { ask(input) }

            Button {
                ask(input)
            } label: {
                Image(systemName: "arrow.up.circle.fill")
                    .font(.system(size: 32))
            }
            .disabled(input.trimmingCharacters(in: .whitespaces).isEmpty || coach.isResponding)
        }
        .padding(.horizontal)
        .padding(.vertical, 8)
        .background(.bar)
    }

    private func ask(_ text: String) {
        let location = SharedStore.primaryLocation
        let context = CoachContext.build(
            dashboard: dashboard,
            medications: medications,
            habits: HabitStore.shared,
            location: location,
            weather: weather.reports[location.id]
        )
        input = ""
        inputFocused = false
        Task { await coach.send(text, context: context) }
    }
}

private struct MessageBubble: View {
    let message: CoachModel.Message

    var body: some View {
        HStack(alignment: .top, spacing: 8) {
            if message.role == .user {
                Spacer(minLength: 40)
                Text(message.text)
                    .padding(12)
                    .foregroundStyle(.white)
                    .background(
                        LinearGradient(colors: [.purple, .pink], startPoint: .topLeading, endPoint: .bottomTrailing),
                        in: RoundedRectangle(cornerRadius: 18, style: .continuous)
                    )
            } else {
                Image(systemName: "sparkles")
                    .font(.system(size: 13, weight: .bold))
                    .foregroundStyle(.white)
                    .frame(width: 28, height: 28)
                    .background(
                        LinearGradient(colors: [.purple, .pink], startPoint: .topLeading, endPoint: .bottomTrailing),
                        in: Circle()
                    )
                    .padding(.top, 4)
                Text(message.text)
                    .padding(12)
                    .background(Color(.secondarySystemGroupedBackground), in: RoundedRectangle(cornerRadius: 18, style: .continuous))
                    .textSelection(.enabled)
                Spacer(minLength: 24)
            }
        }
    }
}
