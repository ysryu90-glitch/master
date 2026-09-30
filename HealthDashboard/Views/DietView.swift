import Charts
import PhotosUI
import SwiftUI

/// 식단 탭
struct DietView: View {
    @Environment(MealStore.self) private var store
    @Environment(DashboardModel.self) private var dashboard

    @State private var day = Calendar.current.startOfDay(for: .now)
    @State private var composer: ComposerRequest?
    @State private var cameraKind: MealSource?
    @State private var capturedImage: UIImage?
    @State private var showPhotoPicker = false
    @State private var pickerItem: PhotosPickerItem?

    private var isToday: Bool { Calendar.current.isDateInToday(day) }

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(spacing: 18) {
                    dateSwitcher
                    DietSummaryCard(day: day)
                    addButtons
                    ForEach(MealType.allCases) { type in
                        mealSection(type)
                    }
                    weekChart
                }
                .padding()
            }
            .background(Color(.systemGroupedBackground))
            .navigationTitle("식단")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .topBarTrailing) { addMenu }
            }
            .sheet(item: $composer) { MealComposerView(request: $0) }
            .fullScreenCover(item: $cameraKind, onDismiss: openComposerForCapturedImage) { kind in
                CameraPicker { image in
                    capturedImage = image
                    cameraKind = nil
                } onCancel: {
                    cameraKind = nil
                }
                .ignoresSafeArea()
                .id(kind)
            }
            .photosPicker(isPresented: $showPhotoPicker, selection: $pickerItem, matching: .images)
            .onChange(of: pickerItem) { _, item in
                guard let item else { return }
                Task {
                    if let data = try? await item.loadTransferable(type: Data.self), let image = UIImage(data: data) {
                        composer = ComposerRequest(source: .photo, image: image, date: day)
                    }
                    pickerItem = nil
                }
            }
        }
    }

    private func openComposerForCapturedImage() {
        guard let image = capturedImage else { return }
        capturedImage = nil
        composer = ComposerRequest(source: lastCameraKind, image: image, date: day)
    }

    @State private var lastCameraKind: MealSource = .photo

    private func openCamera(_ kind: MealSource) {
        lastCameraKind = kind
        cameraKind = kind
    }

    // MARK: - 날짜

    private var dateSwitcher: some View {
        HStack {
            Button { moveDay(-1) } label: { Image(systemName: "chevron.left") }
            Spacer()
            VStack(spacing: 2) {
                Text(isToday ? "오늘" : day.formatted(.dateTime.month().day()))
                    .font(.title3.bold())
                Text(day.formatted(.dateTime.weekday(.wide)))
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }
            Spacer()
            Button { moveDay(1) } label: { Image(systemName: "chevron.right") }
                .disabled(isToday)
        }
        .font(.headline)
        .padding(.horizontal, 8)
    }

    private func moveDay(_ offset: Int) {
        guard let next = Calendar.current.date(byAdding: .day, value: offset, to: day), next <= .now else { return }
        withAnimation(.snappy) { day = next }
    }

    // MARK: - 기록 버튼

    private var addButtons: some View {
        HStack(spacing: 10) {
            AddButton(title: "사진", symbol: "camera.fill", color: .orange) { openCamera(.photo) }
            AddButton(title: "앨범", symbol: "photo.on.rectangle", color: .pink) { showPhotoPicker = true }
            AddButton(title: "말로 입력", symbol: "text.bubble.fill", color: .purple) {
                composer = ComposerRequest(source: .text, date: day)
            }
            AddButton(title: "성분표", symbol: "barcode.viewfinder", color: .teal) { openCamera(.label) }
        }
    }

    private var addMenu: some View {
        Menu {
            Button("사진 찍기", systemImage: "camera") { openCamera(.photo) }
            Button("앨범에서 고르기", systemImage: "photo") { showPhotoPicker = true }
            Button("말로 입력", systemImage: "text.bubble") { composer = ComposerRequest(source: .text, date: day) }
            Button("영양성분표 찍기", systemImage: "barcode.viewfinder") { openCamera(.label) }
            Button("직접 입력", systemImage: "square.and.pencil") { composer = ComposerRequest(source: .manual, date: day) }
        } label: {
            Image(systemName: "plus.circle.fill")
                .font(.title3)
        }
    }

    // MARK: - 끼니별

    @ViewBuilder
    private func mealSection(_ type: MealType) -> some View {
        let meals = store.meals(on: day).filter { $0.type == type }
        if !meals.isEmpty {
            VStack(alignment: .leading, spacing: 10) {
                HStack {
                    Label(type.title, systemImage: type.symbol)
                        .font(.headline)
                    Spacer()
                    Text("\(Int(meals.reduce(0) { $0 + $1.totals.calories }).formatted())kcal")
                        .font(.subheadline.weight(.semibold).monospacedDigit())
                        .foregroundStyle(.orange)
                }
                ForEach(meals) { meal in
                    Button {
                        composer = ComposerRequest(source: meal.source, existing: meal)
                    } label: {
                        MealRow(meal: meal, photo: store.photo(for: meal))
                    }
                    .buttonStyle(.plain)
                    .contextMenu {
                        Button("수정", systemImage: "pencil") {
                            composer = ComposerRequest(source: meal.source, existing: meal)
                        }
                        Button("삭제", systemImage: "trash", role: .destructive) {
                            Task { await store.delete(meal) }
                        }
                    }
                }
            }
            .card()
        }
    }

    // MARK: - 최근 7일

    @ViewBuilder
    private var weekChart: some View {
        let days = store.dailyNutrition(days: 7)
        if days.count >= 2 {
            let average = days.map(\.totals.calories).reduce(0, +) / Double(days.count)
            VStack(alignment: .leading, spacing: 10) {
                SectionHeader(title: "최근 7일 섭취", symbol: "chart.bar.fill", tint: .orange)
                Chart {
                    ForEach(days) { day in
                        BarMark(
                            x: .value("날짜", day.date, unit: .day),
                            y: .value("칼로리", day.totals.calories)
                        )
                        .foregroundStyle(Color.orange.gradient)
                        .cornerRadius(4)
                    }
                    RuleMark(y: .value("평균", average))
                        .lineStyle(StrokeStyle(lineWidth: 1, dash: [4, 3]))
                        .foregroundStyle(.secondary)
                        .annotation(position: .top, alignment: .trailing) {
                            Text("평균 \(Int(average).formatted())kcal")
                                .font(.caption2)
                                .foregroundStyle(.secondary)
                        }
                }
                .chartXAxis {
                    AxisMarks(values: .stride(by: .day)) { _ in
                        AxisValueLabel(format: .dateTime.weekday(.narrow))
                    }
                }
                .frame(height: 160)
                Text("기록한 날 기준 · 단백질 평균 \(Int(days.map(\.totals.protein).reduce(0, +) / Double(days.count)))g")
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }
            .card()
        }
    }
}

extension MealSource: Identifiable {
    var id: String { rawValue }
}

private struct AddButton: View {
    let title: String
    let symbol: String
    let color: Color
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            VStack(spacing: 6) {
                Image(systemName: symbol)
                    .font(.title3)
                    .foregroundStyle(.white)
                    .frame(width: 44, height: 44)
                    .background(color.gradient, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                Text(title)
                    .font(.caption.weight(.medium))
                    .foregroundStyle(.primary)
            }
            .frame(maxWidth: .infinity)
        }
        .buttonStyle(.plain)
    }
}

private struct MealRow: View {
    let meal: MealEntry
    let photo: UIImage?

    var body: some View {
        HStack(spacing: 12) {
            Group {
                if let photo {
                    Image(uiImage: photo)
                        .resizable()
                        .scaledToFill()
                } else {
                    Image(systemName: meal.type.symbol)
                        .font(.title3)
                        .foregroundStyle(.orange)
                        .frame(maxWidth: .infinity, maxHeight: .infinity)
                        .background(Color.orange.opacity(0.12))
                }
            }
            .frame(width: 54, height: 54)
            .clipShape(RoundedRectangle(cornerRadius: 12, style: .continuous))

            VStack(alignment: .leading, spacing: 3) {
                Text(meal.title)
                    .font(.subheadline.weight(.semibold))
                    .lineLimit(2)
                Text("\(meal.date.formatted(date: .omitted, time: .shortened)) · 탄 \(Int(meal.totals.carbohydrates))g 단 \(Int(meal.totals.protein))g 지 \(Int(meal.totals.fat))g")
                    .font(.caption)
                    .foregroundStyle(.secondary)
                if !meal.note.isEmpty {
                    Text(meal.note)
                        .font(.caption)
                        .foregroundStyle(.tertiary)
                }
            }
            Spacer()
            VStack(alignment: .trailing, spacing: 2) {
                Text("\(Int(meal.totals.calories).formatted())")
                    .font(.headline.monospacedDigit())
                Text("kcal")
                    .font(.caption2)
                    .foregroundStyle(.secondary)
                if meal.syncedToHealth {
                    Image(systemName: "heart.fill")
                        .font(.system(size: 9))
                        .foregroundStyle(.pink)
                        .accessibilityLabel("건강 앱에 저장됨")
                }
            }
        }
    }
}

/// 하루 섭취 요약: 먹은 칼로리 vs 쓴 칼로리, 탄단지, 단백질 목표 (식단 탭 · 대시보드 공용)
struct DietSummaryCard: View {
    @Environment(MealStore.self) private var store
    @Environment(DashboardModel.self) private var dashboard
    var day: Date = .now
    var showHeader = false

    var body: some View {
        let totals = store.totals(on: day)
        let isToday = Calendar.current.isDateInToday(day)
        let burned = isToday
            ? (dashboard.values[.activeEnergyBurned]?.value ?? 0) + (dashboard.values[.basalEnergyBurned]?.value ?? 0)
            : 0
        let workoutDay = dashboard.workouts.contains { Calendar.current.isDate($0.start, inSameDayAs: day) }
        let proteinTarget = store.proteinTarget(weightKg: dashboard.values[.bodyMass]?.value, workoutDay: workoutDay)
        let macroCalories = max(totals.carbohydrates * 4 + totals.protein * 4 + totals.fat * 9, 1)

        VStack(alignment: .leading, spacing: 14) {
            if showHeader {
                SectionHeader(title: "오늘의 식단", symbol: "fork.knife", tint: .orange)
            }

            HStack(alignment: .firstTextBaseline) {
                VStack(alignment: .leading, spacing: 2) {
                    Text("먹은 칼로리")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                    HStack(alignment: .firstTextBaseline, spacing: 3) {
                        Text("\(Int(totals.calories).formatted())")
                            .font(.system(size: 34, weight: .bold, design: .rounded).monospacedDigit())
                        Text("kcal")
                            .font(.subheadline)
                            .foregroundStyle(.secondary)
                    }
                }
                Spacer()
                if burned > 0 {
                    VStack(alignment: .trailing, spacing: 2) {
                        Text("쓴 칼로리")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                        Text("\(Int(burned).formatted())kcal")
                            .font(.headline.monospacedDigit())
                        let balance = totals.calories - burned
                        Text(balance >= 0 ? "+\(Int(balance).formatted())" : "\(Int(balance).formatted())")
                            .font(.caption.weight(.semibold).monospacedDigit())
                            .foregroundStyle(balance > 300 ? .orange : .green)
                    }
                }
            }

            // 탄단지 비율 막대
            GeometryReader { proxy in
                HStack(spacing: 2) {
                    Color.yellow.frame(width: proxy.size.width * totals.carbohydrates * 4 / macroCalories)
                    Color.red.frame(width: proxy.size.width * totals.protein * 4 / macroCalories)
                    Color.purple.frame(width: proxy.size.width * totals.fat * 9 / macroCalories)
                }
                .clipShape(Capsule())
                .background(Capsule().fill(Color(.tertiarySystemFill)))
            }
            .frame(height: 8)

            HStack {
                NutrientPill(label: "탄수화물", value: "\(Int(totals.carbohydrates))", unit: "g", color: .yellow)
                NutrientPill(label: "단백질", value: "\(Int(totals.protein))/\(Int(proteinTarget))", unit: "g", color: .red)
                NutrientPill(label: "지방", value: "\(Int(totals.fat))", unit: "g", color: .purple)
            }

            if totals.calories == 0 {
                Text(isToday ? "아직 기록이 없어요. 사진 한 장으로 시작해 보세요." : "이날은 기록이 없어요.")
                    .font(.caption)
                    .foregroundStyle(.secondary)
            } else if totals.protein < proteinTarget * 0.7 && isToday {
                Label("단백질이 부족해요. 목표 \(Int(proteinTarget))g\(workoutDay ? " (운동한 날)" : "")", systemImage: "info.circle")
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }
        }
        .card()
    }
}
