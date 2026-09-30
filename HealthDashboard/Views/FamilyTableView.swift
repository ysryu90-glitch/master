import EventKit
import PhotosUI
import SwiftUI

/// 식단 탭의 '가족 식탁': 이번 주 저녁 계획 · AI 메뉴 추천 · 냉장고 추천 · 장보기 목록
struct FamilyTableView: View {
    @Environment(KitchenStore.self) private var kitchen
    @Environment(CalendarStore.self) private var calendarStore
    @Environment(WeatherModel.self) private var weather

    /// 식단 기록 화면 열기 (계획한 메뉴를 먹었을 때)
    let onLogMeal: (String) -> Void

    @State private var showPlanner = false
    @State private var showFridge = false
    @State private var showProfile = false
    @State private var selectedPlan: PlannedMeal?
    @State private var newItem = ""
    @State private var errorMessage: String?

    private var nextSevenDays: [Date] {
        let calendar = Calendar.current
        let today = calendar.startOfDay(for: .now)
        return (0..<7).compactMap { calendar.date(byAdding: .day, value: $0, to: today) }
    }

    var body: some View {
        VStack(spacing: 16) {
            if !calendarStore.hasAccess || !kitchen.hasRemindersAccess {
                accessCard
            }
            weekPlanCard
            actionButtons
            shoppingCard
            Button {
                showProfile = true
            } label: {
                Label("가족 식탁 설정 (\(kitchen.profile.spouseName) 정보 · 저녁 시간)", systemImage: "person.2.fill")
                    .font(.subheadline)
            }
            .padding(.top, 4)
        }
        .task { await kitchen.reload() }
        .sheet(isPresented: $showPlanner) { MenuPlannerSheet() }
        .sheet(isPresented: $showFridge) { FridgeSheet() }
        .sheet(isPresented: $showProfile) { FamilyProfileSheet() }
        .sheet(item: $selectedPlan) { plan in
            PlanDetailSheet(plan: plan) { onLogMeal($0) }
        }
        .alert("알림", isPresented: Binding(get: { errorMessage != nil }, set: { if !$0 { errorMessage = nil } })) {
            Button("확인", role: .cancel) {}
        } message: {
            Text(errorMessage ?? "")
        }
    }

    // MARK: - 권한

    private var accessCard: some View {
        VStack(alignment: .leading, spacing: 10) {
            Label("캘린더와 미리알림을 연결해 주세요", systemImage: "link")
                .font(.headline)
            Text("저녁 메뉴는 가족 캘린더에, 장보기는 미리알림 목록에 저장돼요. 둘 다 배우자와 iCloud로 공유되어 함께 볼 수 있어요.")
                .font(.subheadline)
                .foregroundStyle(.secondary)
            HStack {
                if !calendarStore.hasAccess {
                    Button("캘린더 연결") { Task { await calendarStore.requestAccess(); await kitchen.reload() } }
                        .buttonStyle(.bordered)
                }
                if !kitchen.hasRemindersAccess {
                    Button("미리알림 연결") { Task { await kitchen.requestRemindersAccess() } }
                        .buttonStyle(.bordered)
                }
            }
        }
        .card()
    }

    // MARK: - 이번 주 저녁

    private var weekPlanCard: some View {
        VStack(alignment: .leading, spacing: 12) {
            SectionHeader(title: "이번 주 저녁", symbol: "fork.knife.circle.fill", tint: .orange)
            ForEach(nextSevenDays, id: \.self) { day in
                let plan = kitchen.plan(on: day)
                let conflicts = kitchen.dinnerConflicts(on: day)
                Button {
                    if let plan { selectedPlan = plan }
                } label: {
                    HStack(spacing: 12) {
                        VStack(spacing: 0) {
                            Text(day.formatted(.dateTime.weekday(.abbreviated)))
                                .font(.caption2.weight(.semibold))
                                .foregroundStyle(Calendar.current.isDateInToday(day) ? Color.orange : .secondary)
                            Text(day.formatted(.dateTime.day()))
                                .font(.headline.monospacedDigit())
                        }
                        .frame(width: 36)

                        if let plan {
                            VStack(alignment: .leading, spacing: 2) {
                                Text(plan.dish)
                                    .font(.subheadline.weight(.semibold))
                                    .foregroundStyle(.primary)
                                if !plan.reason.isEmpty {
                                    Text(plan.reason)
                                        .font(.caption)
                                        .foregroundStyle(.secondary)
                                        .lineLimit(1)
                                }
                            }
                        } else if !conflicts.isEmpty {
                            Label(conflicts.joined(separator: ", "), systemImage: "calendar.badge.clock")
                                .font(.subheadline)
                                .foregroundStyle(.secondary)
                                .lineLimit(1)
                        } else {
                            Text("아직 정하지 않았어요")
                                .font(.subheadline)
                                .foregroundStyle(.tertiary)
                        }
                        Spacer()
                        if plan != nil {
                            Image(systemName: "chevron.right")
                                .font(.caption.weight(.semibold))
                                .foregroundStyle(.tertiary)
                        }
                    }
                    .contentShape(Rectangle())
                }
                .buttonStyle(.plain)
                if day != nextSevenDays.last { Divider() }
            }
        }
        .card()
    }

    private var actionButtons: some View {
        HStack(spacing: 10) {
            Button {
                showPlanner = true
            } label: {
                Label("AI로 메뉴 짜기", systemImage: "sparkles")
                    .frame(maxWidth: .infinity)
            }
            .buttonStyle(.borderedProminent)
            .tint(.orange)

            Button {
                showFridge = true
            } label: {
                Label("냉장고로 추천", systemImage: "refrigerator.fill")
                    .frame(maxWidth: .infinity)
            }
            .buttonStyle(.bordered)
            .tint(.teal)
        }
        .disabled(!calendarStore.hasAccess)
    }

    // MARK: - 장보기

    @ViewBuilder
    private var shoppingCard: some View {
        VStack(alignment: .leading, spacing: 12) {
            HStack {
                SectionHeader(title: "장보기", symbol: "cart.fill", tint: .green)
                Spacer()
                if kitchen.shoppingItems.contains(where: \.isCompleted) {
                    Button("산 것 지우기") { Task { await kitchen.clearCompleted() } }
                        .font(.caption)
                }
            }

            if !kitchen.hasRemindersAccess {
                Text("미리알림을 연결하면 배우자와 함께 쓰는 장보기 목록이 여기에 나와요.")
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
            } else if kitchen.shoppingList == nil {
                VStack(alignment: .leading, spacing: 8) {
                    Text("'장보기' 목록이 없어요.")
                        .font(.subheadline)
                    Button("iCloud에 '장보기' 목록 만들기") {
                        do { try kitchen.createShoppingList() } catch { errorMessage = error.localizedDescription }
                    }
                    .buttonStyle(.bordered)
                    if !kitchen.reminderLists.isEmpty {
                        Menu("이미 있는 목록 고르기") {
                            ForEach(kitchen.reminderLists, id: \.calendarIdentifier) { list in
                                Button(list.title) { kitchen.selectShoppingList(list) }
                            }
                        }
                        .font(.subheadline)
                    }
                }
            } else {
                HStack {
                    TextField("추가할 재료 (예: 두부)", text: $newItem)
                        .submitLabel(.done)
                        .onSubmit(addItem)
                    Button(action: addItem) {
                        Image(systemName: "plus.circle.fill")
                            .font(.title3)
                    }
                    .disabled(newItem.trimmingCharacters(in: .whitespaces).isEmpty)
                }
                .padding(10)
                .background(Color(.tertiarySystemFill), in: RoundedRectangle(cornerRadius: 10))

                if kitchen.shoppingItems.isEmpty {
                    Text("살 것이 없어요 🛒")
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                }
                ForEach(kitchen.shoppingItems, id: \.calendarItemIdentifier) { item in
                    HStack(spacing: 10) {
                        Button {
                            Task { await kitchen.toggle(item) }
                        } label: {
                            Image(systemName: item.isCompleted ? "checkmark.circle.fill" : "circle")
                                .font(.title3)
                                .foregroundStyle(item.isCompleted ? .green : .secondary)
                        }
                        .buttonStyle(.plain)
                        VStack(alignment: .leading, spacing: 1) {
                            Text(item.title ?? "")
                                .strikethrough(item.isCompleted)
                                .foregroundStyle(item.isCompleted ? .secondary : .primary)
                            if let note = item.notes, !note.isEmpty {
                                Text(note)
                                    .font(.caption2)
                                    .foregroundStyle(.tertiary)
                            }
                        }
                        Spacer()
                    }
                    .contextMenu {
                        Button("삭제", systemImage: "trash", role: .destructive) {
                            Task { await kitchen.delete(item) }
                        }
                    }
                }

                Text("'\(kitchen.shoppingList?.title ?? "장보기")' 목록을 미리알림 앱에서 \(kitchen.profile.spouseName)와 공유하면 실시간으로 같이 체크할 수 있어요.")
                    .font(.caption2)
                    .foregroundStyle(.tertiary)
            }
        }
        .card()
    }

    private func addItem() {
        let name = newItem
        newItem = ""
        Task { await kitchen.addShoppingItems([name]) }
    }
}

// MARK: - AI 주간 메뉴

private struct MenuPlannerSheet: View {
    @Environment(KitchenStore.self) private var kitchen
    @Environment(WeatherModel.self) private var weather
    @Environment(\.dismiss) private var dismiss

    @State private var selectedDates: Set<Date> = []
    @State private var suggestions: [DinnerSuggestion] = []
    @State private var isLoading = false
    @State private var errorMessage: String?
    @State private var isSaving = false

    private var days: [Date] {
        let calendar = Calendar.current
        let today = calendar.startOfDay(for: .now)
        return (0..<7).compactMap { calendar.date(byAdding: .day, value: $0, to: today) }
    }

    var body: some View {
        NavigationStack {
            Form {
                if suggestions.isEmpty {
                    Section {
                        ForEach(days, id: \.self) { day in
                            let conflicts = kitchen.dinnerConflicts(on: day)
                            let planned = kitchen.plan(on: day)
                            Toggle(isOn: Binding(
                                get: { selectedDates.contains(day) },
                                set: { if $0 { selectedDates.insert(day) } else { selectedDates.remove(day) } }
                            )) {
                                VStack(alignment: .leading, spacing: 2) {
                                    Text(day.formatted(.dateTime.month(.defaultDigits).day().weekday(.wide)))
                                    if let planned {
                                        Text("계획: \(planned.dish) (바꾸려면 켜기)")
                                            .font(.caption)
                                            .foregroundStyle(.secondary)
                                    } else if !conflicts.isEmpty {
                                        Text("저녁 일정: \(conflicts.joined(separator: ", "))")
                                            .font(.caption)
                                            .foregroundStyle(.orange)
                                    }
                                }
                            }
                        }
                    } header: {
                        Text("메뉴를 짤 날")
                    } footer: {
                        Text("두 사람의 목표·싫어하는 음식, 최근 식단, 컨디션, 날씨를 고려해서 추천해요. 저녁 약속이 있는 날은 빼 두었어요.")
                    }

                    Section {
                        Button {
                            Task { await generate() }
                        } label: {
                            HStack {
                                Label("\(selectedDates.count)일 메뉴 추천받기", systemImage: "sparkles")
                                Spacer()
                                if isLoading { ProgressView() }
                            }
                        }
                        .disabled(selectedDates.isEmpty || isLoading)
                    }
                } else {
                    ForEach($suggestions) { $suggestion in
                        Section {
                            Toggle(isOn: $suggestion.include) {
                                VStack(alignment: .leading, spacing: 4) {
                                    Text(suggestion.fullDish)
                                        .font(.headline)
                                    Text("\(suggestion.minutes)분 · \(suggestion.reason)")
                                        .font(.caption)
                                        .foregroundStyle(.secondary)
                                }
                            }
                            if suggestion.include && !suggestion.ingredients.isEmpty {
                                IngredientChips(ingredients: suggestion.ingredients, selected: $suggestion.selectedIngredients)
                            }
                        } header: {
                            Text(suggestion.date.formatted(.dateTime.month(.defaultDigits).day().weekday(.wide)))
                        }
                    }
                    Section {
                        Button("다시 추천받기") {
                            Task { await generate() }
                        }
                        .disabled(isLoading)
                    } footer: {
                        Text("색이 칠해진 재료는 장보기 목록에 들어가요. 집에 있는 재료는 눌러서 빼 주세요.")
                    }
                }

                if let errorMessage {
                    Section {
                        Text(errorMessage)
                            .foregroundStyle(.orange)
                    }
                }
            }
            .navigationTitle("AI 저녁 메뉴")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("취소") { dismiss() }
                }
                if !suggestions.isEmpty {
                    ToolbarItem(placement: .confirmationAction) {
                        Button("저장") { Task { await save() } }
                            .disabled(isSaving || !suggestions.contains(where: \.include))
                    }
                }
            }
            .overlay {
                if isLoading && suggestions.isEmpty {
                    ProgressView("두 사람에게 맞는 메뉴를 고르는 중…")
                        .padding()
                        .background(.regularMaterial, in: RoundedRectangle(cornerRadius: 14))
                }
            }
            .onAppear {
                // 계획도 약속도 없는 날을 기본으로 선택
                selectedDates = Set(days.filter { kitchen.plan(on: $0) == nil && kitchen.dinnerConflicts(on: $0).isEmpty })
            }
        }
    }

    private func generate() async {
        isLoading = true
        defer { isLoading = false }
        errorMessage = nil
        do {
            let location = SharedStore.primaryLocation
            suggestions = try await MenuPlanner.weeklyMenu(
                for: selectedDates.sorted(), weather: weather.reports[location.id]
            )
        } catch {
            errorMessage = error.localizedDescription
        }
    }

    private func save() async {
        isSaving = true
        var shopping: [String] = []
        for suggestion in suggestions where suggestion.include {
            try? kitchen.setPlan(on: suggestion.date, dish: suggestion.fullDish,
                                 ingredients: suggestion.ingredients, reason: suggestion.reason)
            shopping += suggestion.ingredients.filter { suggestion.selectedIngredients.contains($0) }
        }
        if !shopping.isEmpty {
            // 여러 메뉴에 같은 재료가 있으면 한 번만
            var seen = Set<String>()
            let unique = shopping.filter { seen.insert($0).inserted }
            await kitchen.addShoppingItems(unique, note: "가족 식탁 메뉴")
        }
        dismiss()
    }
}

/// 재료 칩: 눌러서 장보기에 넣을지 고른다
private struct IngredientChips: View {
    let ingredients: [String]
    @Binding var selected: Set<String>

    var body: some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 6) {
                ForEach(ingredients, id: \.self) { item in
                    let isOn = selected.contains(item)
                    Button {
                        if isOn { selected.remove(item) } else { selected.insert(item) }
                    } label: {
                        Label(item, systemImage: isOn ? "cart.fill.badge.plus" : "house")
                            .font(.caption)
                            .padding(.horizontal, 10)
                            .padding(.vertical, 6)
                            .background(isOn ? Color.green.opacity(0.2) : Color(.tertiarySystemFill), in: Capsule())
                    }
                    .buttonStyle(.plain)
                }
            }
        }
    }
}

// MARK: - 냉장고로 추천

private struct FridgeSheet: View {
    @Environment(KitchenStore.self) private var kitchen
    @Environment(\.dismiss) private var dismiss

    @State private var text = ""
    @State private var ideas: [FridgeIdea] = []
    @State private var isLoading = false
    @State private var errorMessage: String?
    @State private var photoItem: PhotosPickerItem?
    @State private var showCamera = false
    @State private var planDate = Calendar.current.startOfDay(for: .now)

    var body: some View {
        NavigationStack {
            Form {
                Section {
                    TextField("예: 두부, 애호박, 계란, 김치, 돼지고기 조금", text: $text, axis: .vertical)
                        .lineLimit(2...4)
                    Button {
                        Task { await run { try await MenuPlanner.fridgeIdeas(ingredients: text) } }
                    } label: {
                        Label("재료로 추천받기", systemImage: "sparkles")
                    }
                    .disabled(text.trimmingCharacters(in: .whitespaces).isEmpty || isLoading)
                } header: {
                    Text("냉장고에 뭐가 있나요?")
                }

                Section {
                    Button {
                        showCamera = true
                    } label: {
                        Label("냉장고 사진 찍기", systemImage: "camera.fill")
                    }
                    PhotosPicker(selection: $photoItem, matching: .images) {
                        Label("앨범에서 고르기", systemImage: "photo")
                    }
                } footer: {
                    Text(FoodAnalyzer.supportsPhotos
                         ? "사진 속 재료를 알아보고 메뉴를 추천해요."
                         : "사진 추천은 iOS 27 + Xcode 27 이상에서 돼요. 지금은 위에 재료를 적어 주세요.")
                }
                .disabled(isLoading)

                if isLoading {
                    Section { ProgressView("메뉴를 고르는 중…") }
                }
                if let errorMessage {
                    Section { Text(errorMessage).foregroundStyle(.orange) }
                }

                if !ideas.isEmpty {
                    Section {
                        DatePicker("언제 먹을까요?", selection: $planDate, in: Date.now.addingTimeInterval(-86_400)..., displayedComponents: .date)
                    }
                    ForEach(ideas) { idea in
                        Section {
                            VStack(alignment: .leading, spacing: 6) {
                                Text(idea.dish)
                                    .font(.headline)
                                Text("\(idea.minutes)분 · \(idea.reason)")
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                                if !idea.uses.isEmpty {
                                    Text("사용: " + idea.uses.joined(separator: ", "))
                                        .font(.caption)
                                }
                                if !idea.missing.isEmpty {
                                    Text("더 필요: " + idea.missing.joined(separator: ", "))
                                        .font(.caption)
                                        .foregroundStyle(.orange)
                                }
                            }
                            Button("이 메뉴로 정하기" + (idea.missing.isEmpty ? "" : " (부족한 재료는 장보기에)")) {
                                Task {
                                    try? kitchen.setPlan(on: planDate, dish: idea.dish,
                                                         ingredients: idea.uses + idea.missing, reason: idea.reason)
                                    if !idea.missing.isEmpty {
                                        await kitchen.addShoppingItems(idea.missing, note: idea.dish)
                                    }
                                    dismiss()
                                }
                            }
                        }
                    }
                }
            }
            .navigationTitle("냉장고로 추천")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("닫기") { dismiss() }
                }
            }
            .fullScreenCover(isPresented: $showCamera) {
                CameraPicker { image in
                    showCamera = false
                    Task { await run { try await MenuPlanner.fridgeIdeas(photo: image) } }
                } onCancel: {
                    showCamera = false
                }
                .ignoresSafeArea()
            }
            .onChange(of: photoItem) { _, item in
                guard let item else { return }
                Task {
                    if let data = try? await item.loadTransferable(type: Data.self), let image = UIImage(data: data) {
                        await run { try await MenuPlanner.fridgeIdeas(photo: image) }
                    }
                    photoItem = nil
                }
            }
        }
    }

    private func run(_ work: () async throws -> [FridgeIdea]) async {
        isLoading = true
        defer { isLoading = false }
        errorMessage = nil
        do {
            ideas = try await work()
        } catch {
            errorMessage = error.localizedDescription
        }
    }
}

// MARK: - 계획 상세

private struct PlanDetailSheet: View {
    @Environment(KitchenStore.self) private var kitchen
    @Environment(\.dismiss) private var dismiss
    let plan: PlannedMeal
    let onLogMeal: (String) -> Void

    var body: some View {
        NavigationStack {
            List {
                Section {
                    Text(plan.dish)
                        .font(.title3.bold())
                    Text(plan.date.formatted(.dateTime.month().day().weekday(.wide).hour().minute()))
                        .foregroundStyle(.secondary)
                    if !plan.reason.isEmpty {
                        Label(plan.reason, systemImage: "heart.text.square")
                            .font(.subheadline)
                    }
                }
                if !plan.ingredients.isEmpty {
                    Section("재료") {
                        ForEach(plan.ingredients, id: \.self) { Text($0) }
                        Button("재료 전부 장보기에 추가") {
                            Task {
                                await kitchen.addShoppingItems(plan.ingredients, note: plan.dish)
                                dismiss()
                            }
                        }
                    }
                }
                Section {
                    Button {
                        dismiss()
                        onLogMeal("저녁에 \(plan.dish) 먹었어")
                    } label: {
                        Label("먹었어요 → 식단 기록하기", systemImage: "checkmark.circle")
                    }
                    Button("계획 삭제", role: .destructive) {
                        kitchen.removePlan(plan)
                        dismiss()
                    }
                }
            }
            .navigationTitle("저녁 계획")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .confirmationAction) {
                    Button("닫기") { dismiss() }
                }
            }
        }
        .presentationDetents([.medium, .large])
    }
}

// MARK: - 가족 식탁 설정

private struct FamilyProfileSheet: View {
    @Environment(KitchenStore.self) private var kitchen
    @Environment(\.dismiss) private var dismiss
    @State private var profile = FamilyProfile()

    private var dinnerTime: Binding<Date> {
        Binding {
            Calendar.current.date(bySettingHour: profile.dinnerHour, minute: profile.dinnerMinute, second: 0, of: .now) ?? .now
        } set: { date in
            let components = Calendar.current.dateComponents([.hour, .minute], from: date)
            profile.dinnerHour = components.hour ?? 18
            profile.dinnerMinute = components.minute ?? 30
        }
    }

    var body: some View {
        NavigationStack {
            Form {
                Section("배우자") {
                    TextField("부를 이름 (예: 여보, 지은)", text: $profile.spouseName)
                    TextField("건강 목표 (예: 다이어트, 저염, 단백질)", text: $profile.spouseGoal)
                    TextField("싫어하는 음식 · 알레르기", text: $profile.spouseDislikes)
                }
                Section("나") {
                    TextField("싫어하는 음식 · 알레르기", text: $profile.myDislikes)
                }
                Section {
                    Stepper("평일 조리 시간 \(profile.cookingMinutes)분 이내", value: $profile.cookingMinutes, in: 10...120, step: 5)
                    DatePicker("저녁 시간", selection: dinnerTime, displayedComponents: .hourAndMinute)
                    TextField("공통 메모 (예: 매운 음식 좋아함, 주말엔 외식)", text: $profile.notes, axis: .vertical)
                } header: {
                    Text("공통")
                } footer: {
                    Text("이 정보는 이 아이폰에만 저장되고, AI 메뉴 추천에만 쓰여요.")
                }
            }
            .navigationTitle("가족 식탁 설정")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("취소") { dismiss() }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button("저장") {
                        kitchen.profile = profile
                        dismiss()
                    }
                }
            }
            .onAppear { profile = kitchen.profile }
        }
    }
}

extension PlannedMeal: Hashable {
    static func == (lhs: PlannedMeal, rhs: PlannedMeal) -> Bool { lhs.id == rhs.id }
    func hash(into hasher: inout Hasher) { hasher.combine(id) }
}
