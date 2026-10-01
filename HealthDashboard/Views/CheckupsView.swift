import Charts
import EventKit
import PhotosUI
import SwiftUI
#if canImport(FoundationModels)
import FoundationModels
#endif

/// 대시보드 카드: 다가오는 검진 · 접종과 최근 검진의 주의 항목
struct CheckupSummaryCard: View {
    @Environment(CheckupStore.self) private var store

    var body: some View {
        NavigationLink {
            CheckupsView()
        } label: {
            VStack(alignment: .leading, spacing: 10) {
                HStack {
                    SectionHeader(title: "건강검진 · 접종", symbol: "stethoscope", tint: .blue)
                    Spacer()
                    Image(systemName: "chevron.right")
                        .font(.footnote.weight(.semibold))
                        .foregroundStyle(.tertiary)
                }
                let due = store.schedules.filter(\.isDue)
                if !due.isEmpty {
                    ForEach(due) { schedule in
                        Label(
                            schedule.nextDue.map { "\(schedule.title) · \($0.formatted(.dateTime.year().month()))까지" }
                                ?? "\(schedule.title) · 기록 없음",
                            systemImage: schedule.symbol
                        )
                        .font(.subheadline)
                    }
                }
                if let latest = store.records.first {
                    let flagged = latest.items.filter(\.isOutOfRange)
                    Text(flagged.isEmpty
                         ? "최근 검진(\(latest.date.formatted(.dateTime.year().month()))) 모든 항목 참고 범위 안"
                         : "최근 검진 주의: " + flagged.map(\.name).joined(separator: ", "))
                        .font(.caption)
                        .foregroundStyle(flagged.isEmpty ? Color.secondary : Color.orange)
                } else if due.isEmpty {
                    Text("검진 결과를 기록하면 해마다 비교해 드려요.")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
            }
            .card()
        }
        .buttonStyle(.plain)
    }
}

struct CheckupsView: View {
    @Environment(CheckupStore.self) private var store
    @State private var editing: CheckupRecord?
    @State private var message: String?

    var body: some View {
        List {
            Section {
                ForEach(store.schedules) { schedule in
                    ScheduleRow(schedule: schedule) { message = $0 }
                }
            } header: {
                Text("정기 검진 · 예방접종")
            } footer: {
                Text("'했어요'를 누르면 다음 예정일이 계산되고, '캘린더에'는 가족 캘린더에 일정을 넣어요.")
            }

            Section {
                Button {
                    editing = CheckupRecord(date: .now, title: "국가건강검진", items: [])
                } label: {
                    Label("검진 결과 기록하기", systemImage: "plus.circle.fill")
                }
                ForEach(store.records) { record in
                    Button {
                        editing = record
                    } label: {
                        HStack {
                            VStack(alignment: .leading, spacing: 2) {
                                Text(record.title)
                                    .foregroundStyle(.primary)
                                Text(record.date.formatted(date: .long, time: .omitted))
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                            }
                            Spacer()
                            let flagged = record.items.filter(\.isOutOfRange).count
                            Text(flagged == 0 ? "모두 정상 범위" : "주의 \(flagged)개")
                                .font(.caption.weight(.semibold))
                                .foregroundStyle(flagged == 0 ? .green : .orange)
                        }
                    }
                }
                .onDelete { offsets in
                    offsets.map { store.records[$0] }.forEach(store.delete)
                }
            } header: {
                Text("검진 결과")
            }

            if !store.itemNames.isEmpty {
                Section("항목별 추이") {
                    ForEach(store.itemNames, id: \.self) { name in
                        NavigationLink(name) { CheckupItemTrendView(name: name) }
                    }
                }
            }

            if let message {
                Section { Text(message).font(.caption).foregroundStyle(.secondary) }
            }
        }
        .navigationTitle("건강검진 · 접종")
        .navigationBarTitleDisplayMode(.inline)
        .sheet(item: $editing) { CheckupEditView(record: $0) }
    }
}

private struct ScheduleRow: View {
    @Environment(CheckupStore.self) private var store
    let schedule: CareSchedule
    let onMessage: (String) -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack {
                Label(schedule.title, systemImage: schedule.symbol)
                Spacer()
                Text("\(schedule.intervalMonths)개월마다")
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }
            Text(schedule.lastDate.map { "마지막: \($0.formatted(date: .abbreviated, time: .omitted))" } ?? "마지막 기록 없음")
                .font(.caption)
                .foregroundStyle(.secondary)
            if let due = schedule.nextDue {
                Text("다음 예정: \(due.formatted(.dateTime.year().month()))" + (schedule.isDue ? " ⏰" : ""))
                    .font(.caption)
                    .foregroundStyle(schedule.isDue ? Color.orange : Color.secondary)
            }
            HStack {
                Button("오늘 했어요") {
                    if let index = store.schedules.firstIndex(where: { $0.id == schedule.id }) {
                        store.schedules[index].lastDate = .now
                    }
                }
                .buttonStyle(.bordered)
                Button("캘린더에") { addToCalendar() }
                    .buttonStyle(.bordered)
            }
            .font(.caption)
        }
        .padding(.vertical, 4)
    }

    private func addToCalendar() {
        let calendarStore = CalendarStore.shared
        guard calendarStore.hasAccess else {
            onMessage("캘린더 탭에서 먼저 캘린더를 연결해 주세요.")
            return
        }
        let event = EKEvent(eventStore: calendarStore.eventStore)
        event.calendar = calendarStore.familyCalendar ?? calendarStore.eventStore.defaultCalendarForNewEvents
        event.title = "🏥 \(schedule.title)"
        event.isAllDay = true
        let date = max(schedule.nextDue ?? .now, .now)
        event.startDate = date
        event.endDate = date
        event.notes = "건강 대시보드: \(schedule.intervalMonths)개월 주기 일정"
        do {
            try calendarStore.eventStore.save(event, span: .thisEvent, commit: true)
            onMessage("\(schedule.title) 일정을 \(date.formatted(date: .abbreviated, time: .omitted))에 넣었어요. 날짜는 캘린더에서 바꿀 수 있어요.")
        } catch {
            onMessage("캘린더에 넣지 못했어요: \(error.localizedDescription)")
        }
    }
}

/// 검진 결과 입력 (템플릿 항목 · 결과지 사진 읽기)
private struct CheckupEditView: View {
    @Environment(CheckupStore.self) private var store
    @Environment(\.dismiss) private var dismiss
    @State private var record: CheckupRecord
    @State private var photoItem: PhotosPickerItem?
    @State private var isReading = false
    @State private var message: String?

    init(record: CheckupRecord) {
        _record = State(initialValue: record)
    }

    private var unusedTemplates: [CheckupItem] {
        CheckupStore.templates.filter { template in !record.items.contains { $0.name == template.name } }
    }

    var body: some View {
        NavigationStack {
            Form {
                Section {
                    TextField("이름 (예: 국가건강검진)", text: $record.title)
                    DatePicker("검진일", selection: $record.date, displayedComponents: .date)
                }

                Section {
                    PhotosPicker(selection: $photoItem, matching: .images) {
                        HStack {
                            Label("결과지 사진으로 채우기", systemImage: "doc.text.viewfinder")
                            Spacer()
                            if isReading { ProgressView() }
                        }
                    }
                    .disabled(isReading)
                } footer: {
                    Text(FoodAnalyzer.supportsPhotos
                         ? "결과지 사진에서 수치를 읽어 채워요. 저장 전에 꼭 확인해 주세요."
                         : "결과지 사진 읽기는 iOS 27 + Xcode 27 이상에서 돼요. 아래에서 직접 입력해 주세요.")
                }

                Section("결과") {
                    ForEach($record.items) { $item in
                        HStack {
                            VStack(alignment: .leading, spacing: 2) {
                                Text(item.name)
                                if !item.rangeText.isEmpty {
                                    Text("참고 \(item.rangeText) \(item.unit)")
                                        .font(.caption2)
                                        .foregroundStyle(.secondary)
                                }
                            }
                            Spacer()
                            TextField("값", value: $item.value, format: .number)
                                .keyboardType(.decimalPad)
                                .multilineTextAlignment(.trailing)
                                .frame(width: 70)
                                .foregroundStyle(item.isOutOfRange ? Color.orange : Color.primary)
                            Text(item.unit)
                                .font(.caption)
                                .foregroundStyle(.secondary)
                                .frame(width: 48, alignment: .leading)
                        }
                    }
                    .onDelete { record.items.remove(atOffsets: $0) }

                    if !unusedTemplates.isEmpty {
                        Menu {
                            Button("주요 항목 전부 추가") { record.items += unusedTemplates }
                            ForEach(unusedTemplates) { template in
                                Button(template.name) { record.items.append(template) }
                            }
                        } label: {
                            Label("항목 추가", systemImage: "plus")
                        }
                    }
                }

                Section("메모") {
                    TextField("의사 소견 등", text: $record.note, axis: .vertical)
                }

                if let message {
                    Section { Text(message).font(.caption).foregroundStyle(.orange) }
                }
            }
            .navigationTitle("검진 결과")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) { Button("취소") { dismiss() } }
                ToolbarItem(placement: .confirmationAction) {
                    Button("저장") {
                        record.items.removeAll { $0.value == 0 }
                        store.save(record)
                        dismiss()
                    }
                }
            }
            .onChange(of: photoItem) { _, item in
                guard let item else { return }
                Task {
                    isReading = true
                    defer { isReading = false; photoItem = nil }
                    guard let data = try? await item.loadTransferable(type: Data.self), let image = UIImage(data: data) else { return }
                    do {
                        let scanned = try await CheckupScanner.read(image)
                        for item in scanned {
                            if let index = record.items.firstIndex(where: { $0.name == item.name }) {
                                record.items[index].value = item.value
                            } else {
                                record.items.append(item)
                            }
                        }
                        message = "\(scanned.count)개 항목을 읽었어요. 숫자를 꼭 확인해 주세요."
                    } catch {
                        message = error.localizedDescription
                    }
                }
            }
        }
    }
}

/// 항목 하나의 연도별 추이
private struct CheckupItemTrendView: View {
    @Environment(CheckupStore.self) private var store
    let name: String

    var body: some View {
        let history = store.history(of: name)
        let template = CheckupStore.templates.first { $0.name == name }
        List {
            Section {
                Chart {
                    ForEach(Array(history.enumerated()), id: \.offset) { _, entry in
                        LineMark(x: .value("날짜", entry.date), y: .value(name, entry.value))
                        PointMark(x: .value("날짜", entry.date), y: .value(name, entry.value))
                    }
                    if let upper = template?.upper {
                        RuleMark(y: .value("상한", upper))
                            .lineStyle(StrokeStyle(lineWidth: 1, dash: [4, 3]))
                            .foregroundStyle(.orange)
                    }
                    if let lower = template?.lower {
                        RuleMark(y: .value("하한", lower))
                            .lineStyle(StrokeStyle(lineWidth: 1, dash: [4, 3]))
                            .foregroundStyle(.orange)
                    }
                }
                .chartYScale(domain: .automatic(includesZero: false))
                .frame(height: 200)
            }
            Section("기록") {
                ForEach(Array(history.reversed().enumerated()), id: \.offset) { _, entry in
                    LabeledContent(entry.date.formatted(date: .abbreviated, time: .omitted),
                                   value: CheckupItem.format(entry.value) + " " + (template?.unit ?? ""))
                }
            }
        }
        .navigationTitle(name)
        .navigationBarTitleDisplayMode(.inline)
    }
}

/// 결과지 사진 → 항목 (iOS 27 온디바이스 모델)
@MainActor
enum CheckupScanner {
    static func read(_ image: UIImage) async throws -> [CheckupItem] {
        if let problem = CoachModel.availabilityProblem() { throw FoodAnalyzer.AnalyzerError.unavailable(problem) }
        #if IOS27_SDK && canImport(FoundationModels)
        if #available(iOS 27.0, *) {
            let names = CheckupStore.templates.map(\.name).joined(separator: ", ")
            let session = LanguageModelSession(instructions: """
            사진은 한국 건강검진 결과지입니다. 표에 적힌 수치를 그대로 읽습니다.
            항목 이름은 가능하면 다음 이름 중 하나로 맞춥니다: \(names)
            숫자가 보이지 않는 항목은 넣지 않습니다.
            """)
            do {
                let response = try await session.respond(generating: CheckupScan.self) {
                    "이 결과지의 검사 수치를 읽어 주세요."
                    Attachment(image.resized(maxDimension: 1600))
                }
                return response.content.items.map { scanned in
                    let template = CheckupStore.templates.first { $0.name == scanned.name }
                    return CheckupItem(name: scanned.name, value: scanned.value,
                                       unit: template?.unit ?? scanned.unit,
                                       lower: template?.lower, upper: template?.upper)
                }
            } catch {
                throw FoodAnalyzer.AnalyzerError.failed
            }
        }
        #endif
        throw FoodAnalyzer.AnalyzerError.photoNeedsNewerSystem
    }
}

#if canImport(FoundationModels)
@available(iOS 26.0, *)
@Generable
struct CheckupScan {
    @Guide(description: "결과지에서 읽은 검사 항목들")
    var items: [CheckupScanItem]
}

@available(iOS 26.0, *)
@Generable
struct CheckupScanItem {
    @Guide(description: "검사 항목 이름")
    var name: String
    @Guide(description: "측정값 숫자")
    var value: Double
    @Guide(description: "단위 (예: mg/dL)")
    var unit: String
}
#endif
