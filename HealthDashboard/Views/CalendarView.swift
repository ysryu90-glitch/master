import EventKit
import EventKitUI
import SwiftUI

/// 가족 캘린더 탭
struct CalendarView: View {
    @Environment(CalendarStore.self) private var store
    @Environment(\.openURL) private var openURL
    @State private var selectedDate = Calendar.current.startOfDay(for: .now)
    @State private var editing: EditTarget?
    @State private var errorMessage: String?

    var body: some View {
        NavigationStack {
            Group {
                if store.hasAccess {
                    content
                } else {
                    accessView
                }
            }
            .background(Color(.systemGroupedBackground))
            .navigationTitle("가족 캘린더")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                if store.hasAccess {
                    ToolbarItem(placement: .topBarLeading) { optionsMenu }
                    ToolbarItem(placement: .topBarTrailing) {
                        Button {
                            editing = .new(selectedDate)
                        } label: {
                            Image(systemName: "plus.circle.fill")
                                .font(.title3)
                        }
                    }
                }
            }
            .sheet(item: $editing) { target in
                EventEditView(
                    eventStore: store.eventStore,
                    target: target,
                    defaultCalendar: store.familyCalendar
                ) {
                    editing = nil
                    store.reload()
                }
                .ignoresSafeArea()
            }
            .alert("캘린더를 만들지 못했어요", isPresented: Binding(
                get: { errorMessage != nil }, set: { if !$0 { errorMessage = nil } }
            )) {
                Button("확인", role: .cancel) {}
            } message: {
                Text(errorMessage ?? "")
            }
            .onAppear { store.reload() }
        }
    }

    // MARK: - 권한 요청

    private var accessView: some View {
        VStack(spacing: 20) {
            Spacer()
            Image(systemName: "calendar.badge.plus")
                .font(.system(size: 64))
                .foregroundStyle(.pink.gradient)
            Text("가족과 일정을 함께 봐요")
                .font(.title2.bold())
            Text("애플 캘린더와 연동돼요. 가족 공유의 '가족' 캘린더에 일정을 추가하면 가족 모두의 아이폰 캘린더에 바로 나타나요.")
                .multilineTextAlignment(.center)
                .foregroundStyle(.secondary)
            if store.authorization == .denied || store.authorization == .restricted {
                Button("설정에서 캘린더 접근 허용하기") {
                    if let url = URL(string: UIApplication.openSettingsURLString) { openURL(url) }
                }
                .buttonStyle(.borderedProminent)
            } else {
                Button("캘린더 연결하기") {
                    Task { await store.requestAccess() }
                }
                .buttonStyle(.borderedProminent)
            }
            Spacer()
        }
        .padding(32)
    }

    // MARK: - 달력

    private var content: some View {
        ScrollView {
            VStack(spacing: 16) {
                familyCalendarStatus
                MonthGrid(selectedDate: $selectedDate)
                    .card()
                dayEvents
            }
            .padding()
        }
    }

    @ViewBuilder
    private var familyCalendarStatus: some View {
        if let family = store.familyCalendar {
            HStack(spacing: 10) {
                Circle()
                    .fill(Color(cgColor: family.cgColor))
                    .frame(width: 12, height: 12)
                Text("가족 캘린더: \(family.title)")
                    .font(.subheadline.weight(.semibold))
                Text(family.source.title)
                    .font(.caption)
                    .foregroundStyle(.secondary)
                Spacer()
                Text(store.familyOnly ? "가족 일정만" : "모든 일정")
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }
            .padding(.horizontal, 4)
        } else {
            VStack(alignment: .leading, spacing: 10) {
                Label("가족 캘린더를 찾지 못했어요", systemImage: "person.3.fill")
                    .font(.headline)
                Text("아이폰 설정 › [내 이름] › 가족 공유를 설정하면 가족 모두에게 '가족' 캘린더가 자동으로 생겨요. 이미 쓰는 공유 캘린더가 있다면 왼쪽 위 메뉴에서 고를 수 있어요.")
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
                Button("iCloud에 '가족' 캘린더 만들기") {
                    do { try store.createFamilyCalendar() } catch { errorMessage = error.localizedDescription }
                }
                .buttonStyle(.bordered)
                Text("직접 만든 캘린더는 캘린더 앱 › 캘린더 › ⓘ › '사람 추가'로 가족을 초대해야 공유돼요.")
                    .font(.caption)
                    .foregroundStyle(.tertiary)
            }
            .card()
        }
    }

    private var dayEvents: some View {
        let events = store.events(on: selectedDate)
        return VStack(alignment: .leading, spacing: 12) {
            HStack {
                Text(selectedDate.formatted(.dateTime.month().day().weekday(.wide)))
                    .font(.headline)
                Spacer()
                Button {
                    editing = .new(selectedDate)
                } label: {
                    Label("일정 추가", systemImage: "plus")
                        .font(.subheadline)
                }
            }

            if events.isEmpty {
                Text("일정이 없어요")
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
                    .padding(.vertical, 8)
            }

            ForEach(events, id: \.eventIdentifier) { event in
                Button {
                    editing = .existing(event)
                } label: {
                    EventRow(event: event)
                }
                .buttonStyle(.plain)
                .contextMenu {
                    Button("수정", systemImage: "pencil") { editing = .existing(event) }
                    Button("삭제", systemImage: "trash", role: .destructive) { store.delete(event) }
                }
            }
        }
        .card()
    }

    private var optionsMenu: some View {
        @Bindable var store = store
        return Menu {
            Toggle("가족 캘린더만 보기", isOn: $store.familyOnly)
            Menu("가족 캘린더 선택") {
                ForEach(store.writableCalendars, id: \.calendarIdentifier) { calendar in
                    Button {
                        store.selectFamilyCalendar(calendar)
                    } label: {
                        if calendar.calendarIdentifier == store.familyCalendar?.calendarIdentifier {
                            Label(calendar.title, systemImage: "checkmark")
                        } else {
                            Text(calendar.title)
                        }
                    }
                }
            }
            Button("오늘로 이동", systemImage: "calendar") {
                selectedDate = Calendar.current.startOfDay(for: .now)
                store.show(month: .now)
            }
        } label: {
            Image(systemName: "line.3.horizontal.decrease.circle")
        }
    }
}

// MARK: - 월 달력

private struct MonthGrid: View {
    @Environment(CalendarStore.self) private var store
    @Binding var selectedDate: Date

    private let calendar = Calendar.current
    private let columns = Array(repeating: GridItem(.flexible(), spacing: 0), count: 7)

    private var days: [Date?] {
        let month = store.displayedMonth
        guard let range = calendar.range(of: .day, in: .month, for: month) else { return [] }
        let weekday = calendar.component(.weekday, from: month)
        let leading = (weekday - calendar.firstWeekday + 7) % 7
        let dates = range.compactMap { calendar.date(byAdding: .day, value: $0 - 1, to: month) }
        return Array(repeating: nil, count: leading) + dates.map { Optional($0) }
    }

    private var weekdaySymbols: [String] {
        let symbols = calendar.veryShortWeekdaySymbols
        let first = calendar.firstWeekday - 1
        return Array(symbols[first...] + symbols[..<first])
    }

    var body: some View {
        VStack(spacing: 12) {
            HStack {
                Button { move(-1) } label: { Image(systemName: "chevron.left") }
                Spacer()
                Text(store.displayedMonth.formatted(.dateTime.year().month(.wide)))
                    .font(.title3.bold())
                Spacer()
                Button { move(1) } label: { Image(systemName: "chevron.right") }
            }
            .font(.headline)

            LazyVGrid(columns: columns, spacing: 6) {
                ForEach(Array(weekdaySymbols.enumerated()), id: \.offset) { index, symbol in
                    Text(symbol)
                        .font(.caption.weight(.semibold))
                        .foregroundStyle(weekdayColor(index))
                }
                ForEach(Array(days.enumerated()), id: \.offset) { _, date in
                    if let date {
                        dayCell(date)
                    } else {
                        Color.clear.frame(height: 44)
                    }
                }
            }
        }
        .gesture(
            DragGesture(minimumDistance: 30).onEnded { value in
                if value.translation.width < -50 { move(1) }
                if value.translation.width > 50 { move(-1) }
            }
        )
    }

    private func dayCell(_ date: Date) -> some View {
        let isSelected = calendar.isDate(date, inSameDayAs: selectedDate)
        let isToday = calendar.isDateInToday(date)
        let events = store.events(on: date)

        return Button {
            selectedDate = date
        } label: {
            VStack(spacing: 3) {
                Text("\(calendar.component(.day, from: date))")
                    .font(.subheadline.weight(isToday || isSelected ? .bold : .regular))
                    .foregroundStyle(isSelected ? Color.white : (isToday ? Color.accentColor : dayColor(date)))
                    .frame(width: 32, height: 32)
                    .background {
                        if isSelected {
                            Circle().fill(Color.accentColor)
                        } else if isToday {
                            Circle().stroke(Color.accentColor, lineWidth: 1.5)
                        }
                    }
                HStack(spacing: 2) {
                    ForEach(Array(events.prefix(3).enumerated()), id: \.offset) { _, event in
                        Circle()
                            .fill(Color(cgColor: event.calendar.cgColor))
                            .frame(width: 5, height: 5)
                    }
                }
                .frame(height: 5)
            }
            .frame(maxWidth: .infinity, minHeight: 44)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }

    private func move(_ months: Int) {
        guard let month = calendar.date(byAdding: .month, value: months, to: store.displayedMonth) else { return }
        withAnimation(.snappy) { store.show(month: month) }
        selectedDate = calendar.isDate(month, equalTo: .now, toGranularity: .month)
            ? calendar.startOfDay(for: .now) : month
    }

    private func weekdayColor(_ index: Int) -> Color {
        let weekday = (calendar.firstWeekday - 1 + index) % 7 + 1
        return weekday == 1 ? .red : (weekday == 7 ? .blue : .secondary)
    }

    private func dayColor(_ date: Date) -> Color {
        switch calendar.component(.weekday, from: date) {
        case 1: .red
        case 7: .blue
        default: .primary
        }
    }
}

struct EventRow: View {
    let event: EKEvent

    var body: some View {
        HStack(spacing: 12) {
            RoundedRectangle(cornerRadius: 2)
                .fill(Color(cgColor: event.calendar.cgColor))
                .frame(width: 4)
            VStack(alignment: .leading, spacing: 3) {
                Text(event.title ?? "일정")
                    .font(.subheadline.weight(.semibold))
                    .foregroundStyle(.primary)
                HStack(spacing: 6) {
                    Text(timeText)
                    Text("· \(event.calendar.title)")
                }
                .font(.caption)
                .foregroundStyle(.secondary)
                if let location = event.location, !location.isEmpty {
                    Label(location, systemImage: "mappin.and.ellipse")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                        .lineLimit(1)
                }
            }
            Spacer()
            Image(systemName: "chevron.right")
                .font(.caption.weight(.semibold))
                .foregroundStyle(.tertiary)
        }
        .padding(.vertical, 4)
        .fixedSize(horizontal: false, vertical: true)
    }

    private var timeText: String {
        if event.isAllDay { return "종일" }
        let start = CalendarStore.start(of: event).formatted(date: .omitted, time: .shortened)
        let end = CalendarStore.end(of: event).formatted(date: .omitted, time: .shortened)
        return "\(start) – \(end)"
    }
}

// MARK: - 애플 기본 일정 편집 화면

enum EditTarget: Identifiable {
    case new(Date)
    case existing(EKEvent)

    var id: String {
        switch self {
        case .new(let date): "new-\(date.timeIntervalSince1970)"
        case .existing(let event): event.eventIdentifier ?? UUID().uuidString
        }
    }
}

/// 애플 캘린더와 같은 일정 편집 화면 (반복, 알림, 초대, 위치 등 모두 지원)
struct EventEditView: UIViewControllerRepresentable {
    let eventStore: EKEventStore
    let target: EditTarget
    let defaultCalendar: EKCalendar?
    let onDone: () -> Void

    func makeUIViewController(context: Context) -> EKEventEditViewController {
        let controller = EKEventEditViewController()
        controller.eventStore = eventStore
        controller.editViewDelegate = context.coordinator

        switch target {
        case .existing(let event):
            controller.event = event
        case .new(let date):
            let event = EKEvent(eventStore: eventStore)
            event.calendar = defaultCalendar ?? eventStore.defaultCalendarForNewEvents
            let calendar = Calendar.current
            // 오늘이면 다음 정각, 다른 날이면 오전 9시로 시작
            let start: Date
            if calendar.isDateInToday(date) {
                let nextHour = calendar.nextDate(after: .now, matching: DateComponents(minute: 0), matchingPolicy: .nextTime)
                start = nextHour ?? .now
            } else {
                start = calendar.date(bySettingHour: 9, minute: 0, second: 0, of: date) ?? date
            }
            event.startDate = start
            event.endDate = start.addingTimeInterval(3600)
            controller.event = event
        }
        return controller
    }

    func updateUIViewController(_ controller: EKEventEditViewController, context: Context) {}

    func makeCoordinator() -> Coordinator { Coordinator(onDone: onDone) }

    final class Coordinator: NSObject, EKEventEditViewDelegate {
        let onDone: () -> Void

        init(onDone: @escaping () -> Void) {
            self.onDone = onDone
        }

        func eventEditViewController(_ controller: EKEventEditViewController, didCompleteWith action: EKEventEditViewAction) {
            onDone()
        }
    }
}

// MARK: - 대시보드: 오늘의 가족 일정

struct TodayScheduleCard: View {
    @Environment(CalendarStore.self) private var store

    var body: some View {
        let events = store.todayEvents()

        VStack(alignment: .leading, spacing: 10) {
            SectionHeader(title: "오늘의 가족 일정", symbol: "calendar", tint: .pink)

            if !store.hasAccess {
                Text("캘린더 탭에서 캘린더를 연결하면 오늘 일정이 여기에 보여요.")
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
            } else if events.isEmpty {
                Text("오늘은 일정이 없어요 🙌")
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
            } else {
                ForEach(events.prefix(4), id: \.eventIdentifier) { event in
                    EventRow(event: event)
                }
                if events.count > 4 {
                    Text("외 \(events.count - 4)개")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
            }
        }
        .card()
        .onAppear { if store.hasAccess { store.reload() } }
    }
}
