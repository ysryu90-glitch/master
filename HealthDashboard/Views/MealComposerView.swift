import SwiftUI
import UIKit

/// 식단 기록 화면을 여는 요청
struct ComposerRequest: Identifiable {
    let id = UUID()
    var source: MealSource
    var image: UIImage?
    var existing: MealEntry?
    var date: Date = .now
    /// 말로 입력 칸에 미리 채울 내용 (예: 계획한 저녁 메뉴)
    var prefillText: String?
}

/// 한 끼 기록 추가 · 수정. 사진/문장이면 AI가 먼저 채우고, 사용자가 확인·수정 후 저장한다.
struct MealComposerView: View {
    @Environment(MealStore.self) private var store
    @Environment(\.dismiss) private var dismiss

    let request: ComposerRequest

    @State private var items: [FoodItem]
    @State private var type: MealType
    @State private var date: Date
    @State private var note: String
    @State private var text = ""
    @State private var isAnalyzing = false
    @State private var analyzed = false
    @State private var errorMessage: String?
    @State private var isSaving = false

    init(request: ComposerRequest) {
        self.request = request
        let existing = request.existing
        let date = existing?.date ?? Self.defaultDate(for: request.date)
        _items = State(initialValue: existing?.items ?? (request.source == .manual ? [.empty] : []))
        _type = State(initialValue: existing?.type ?? MealType.suggested(for: date))
        _date = State(initialValue: date)
        _note = State(initialValue: existing?.note ?? "")
        _text = State(initialValue: request.prefillText ?? "")
    }

    /// 오늘이면 지금 시각, 다른 날이면 그날 정오
    private static func defaultDate(for day: Date) -> Date {
        Calendar.current.isDateInToday(day)
            ? .now
            : Calendar.current.date(bySettingHour: 12, minute: 0, second: 0, of: day) ?? day
    }

    private var photo: UIImage? {
        request.image ?? request.existing.flatMap { store.photo(for: $0) }
    }

    private var totals: NutritionTotals { items.reduce(.zero) { $0 + $1.nutrition } }

    var body: some View {
        NavigationStack {
            Form {
                if let photo {
                    Section {
                        Image(uiImage: photo)
                            .resizable()
                            .scaledToFill()
                            .frame(maxWidth: .infinity)
                            .frame(height: 220)
                            .clipped()
                            .listRowInsets(EdgeInsets())
                    }
                }

                if request.existing == nil && (request.source == .text || errorMessage != nil) {
                    textSection
                }

                if isAnalyzing {
                    Section {
                        HStack(spacing: 12) {
                            ProgressView()
                            Text(request.source == .label ? "영양성분표를 읽는 중…" : "AI가 음식을 분석하는 중…")
                                .foregroundStyle(.secondary)
                        }
                    }
                }

                if let errorMessage {
                    Section {
                        Label(errorMessage, systemImage: "exclamationmark.triangle")
                            .foregroundStyle(.orange)
                            .font(.subheadline)
                    }
                }

                if !items.isEmpty || analyzed || request.source == .manual || request.existing != nil {
                    itemsSection
                    totalSection
                }

                frequentSection

                Section("식사 정보") {
                    Picker("식사", selection: $type) {
                        ForEach(MealType.allCases) { Label($0.title, systemImage: $0.symbol).tag($0) }
                    }
                    DatePicker("시간", selection: $date)
                    TextField("메모 (예: 회식, 외식)", text: $note)
                }

                if analyzed {
                    Section {
                        Text("AI 추정값이라 실제와 20~30% 차이가 날 수 있어요. 양(×)을 조절하거나 숫자를 고쳐 주세요.")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    }
                }
            }
            .navigationTitle(request.existing == nil ? "식단 기록" : "식단 수정")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("취소") { dismiss() }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button("저장") { save() }
                        .disabled(validItems.isEmpty || isSaving || isAnalyzing)
                }
            }
            .task { await analyzePhotoIfNeeded() }
        }
    }

    private var validItems: [FoodItem] {
        items.filter { !$0.name.trimmingCharacters(in: .whitespaces).isEmpty }
    }

    // MARK: - 섹션

    private var textSection: some View {
        Section {
            TextField("예: 점심에 김치찌개 1인분이랑 밥 반 공기, 계란말이 조금", text: $text, axis: .vertical)
                .lineLimit(2...5)
            Button {
                Task { await analyzeText() }
            } label: {
                Label("AI로 분석하기", systemImage: "sparkles")
            }
            .disabled(text.trimmingCharacters(in: .whitespaces).isEmpty || isAnalyzing)
        } header: {
            Text("무엇을 드셨나요?")
        } footer: {
            Text("말하듯 적으면 AI가 음식과 영양성분으로 정리해요.")
        }
    }

    private var itemsSection: some View {
        Section {
            ForEach($items) { $item in
                FoodItemEditor(item: $item)
            }
            .onDelete { items.remove(atOffsets: $0) }

            Button {
                items.append(.empty)
            } label: {
                Label("음식 직접 추가", systemImage: "plus.circle")
            }
        } header: {
            Text("음식 \(items.count)개")
        }
    }

    private var totalSection: some View {
        Section("합계") {
            HStack {
                NutrientPill(label: "칼로리", value: "\(Int(totals.calories).formatted())", unit: "kcal", color: .orange)
                NutrientPill(label: "탄수화물", value: "\(Int(totals.carbohydrates))", unit: "g", color: .yellow)
                NutrientPill(label: "단백질", value: "\(Int(totals.protein))", unit: "g", color: .red)
                NutrientPill(label: "지방", value: "\(Int(totals.fat))", unit: "g", color: .purple)
            }
            if totals.sodium > 0 || totals.sugar > 0 {
                Text("당류 \(Int(totals.sugar))g · 나트륨 \(Int(totals.sodium).formatted())mg")
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }
        }
    }

    @ViewBuilder
    private var frequentSection: some View {
        let quick = store.quickFoods
        if !quick.isEmpty {
            Section {
                ScrollView(.horizontal, showsIndicators: false) {
                    HStack(spacing: 8) {
                        ForEach(quick) { item in
                            let pinned = store.isPinned(item)
                            Button {
                                var copy = item
                                copy.id = UUID()
                                items.append(copy)
                            } label: {
                                Text((pinned ? "★ " : "+ ") + item.name)
                                    .font(.subheadline)
                                    .padding(.horizontal, 12)
                                    .padding(.vertical, 7)
                                    .background(pinned ? Color.yellow.opacity(0.25) : Color(.tertiarySystemFill), in: Capsule())
                            }
                            .buttonStyle(.plain)
                            .contextMenu {
                                Button(pinned ? "고정 해제" : "즐겨찾기로 고정", systemImage: pinned ? "star.slash" : "star") {
                                    store.togglePin(item)
                                }
                            }
                        }
                    }
                    .padding(.vertical, 4)
                }
            } header: {
                Text("즐겨찾기 · 자주 먹는 음식")
            } footer: {
                Text("음식을 길게 누르면 즐겨찾기로 고정할 수 있어요.")
            }
        }
    }

    // MARK: - 분석 · 저장

    private func analyzePhotoIfNeeded() async {
        guard request.existing == nil, let image = request.image, !analyzed else { return }
        isAnalyzing = true
        defer { isAnalyzing = false }
        do {
            items = try await FoodAnalyzer.analyze(image: image, kind: request.source == .label ? .label : .meal)
            analyzed = true
            errorMessage = nil
        } catch {
            errorMessage = error.localizedDescription
            if items.isEmpty { items = [.empty] }
        }
    }

    private func analyzeText() async {
        isAnalyzing = true
        defer { isAnalyzing = false }
        do {
            items += try await FoodAnalyzer.analyze(text: text)
            items.removeAll { $0.name.isEmpty && $0.calories == 0 }
            analyzed = true
            errorMessage = nil
            text = ""
        } catch {
            errorMessage = error.localizedDescription
            if items.isEmpty { items = [.empty] }
        }
    }

    private func save() {
        isSaving = true
        var meal = request.existing ?? MealEntry(date: date, type: type, items: [], source: request.source)
        meal.date = date
        meal.type = type
        meal.items = validItems
        meal.note = note
        let image = request.existing == nil ? request.image : nil
        Task {
            await store.save(meal, photo: image)
            dismiss()
        }
    }
}

/// 음식 하나 편집: 이름, 양, 몇 인분(×), 영양성분
private struct FoodItemEditor: View {
    @Binding var item: FoodItem

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            HStack {
                TextField("음식 이름", text: $item.name)
                    .font(.headline)
                TextField("양", text: $item.amount)
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
                    .multilineTextAlignment(.trailing)
                    .frame(maxWidth: 90)
            }
            Stepper(value: $item.servings, in: 0.25...10, step: 0.25) {
                HStack {
                    Text("× \(item.servings.formatted(.number.precision(.fractionLength(0...2))))")
                        .font(.subheadline.monospacedDigit())
                    Spacer()
                    Text("\(Int(item.nutrition.calories))kcal")
                        .font(.subheadline.weight(.semibold).monospacedDigit())
                        .foregroundStyle(.orange)
                }
            }
            HStack(spacing: 6) {
                NumberField(label: "kcal", value: $item.calories)
                NumberField(label: "탄", value: $item.carbohydrates)
                NumberField(label: "단", value: $item.protein)
                NumberField(label: "지", value: $item.fat)
            }
            Text("숫자는 1회(× 1) 기준이에요")
                .font(.caption2)
                .foregroundStyle(.tertiary)
        }
        .padding(.vertical, 4)
    }
}

private struct NumberField: View {
    let label: String
    @Binding var value: Double

    var body: some View {
        VStack(spacing: 2) {
            TextField(label, value: $value, format: .number.precision(.fractionLength(0...1)))
                .keyboardType(.decimalPad)
                .multilineTextAlignment(.center)
                .font(.subheadline.monospacedDigit())
                .padding(.vertical, 5)
                .background(Color(.tertiarySystemFill), in: RoundedRectangle(cornerRadius: 8))
            Text(label)
                .font(.caption2)
                .foregroundStyle(.secondary)
        }
    }
}

struct NutrientPill: View {
    let label: String
    let value: String
    let unit: String
    let color: Color

    var body: some View {
        VStack(spacing: 2) {
            Text(label)
                .font(.caption2)
                .foregroundStyle(.secondary)
            HStack(alignment: .firstTextBaseline, spacing: 1) {
                Text(value)
                    .font(.headline.monospacedDigit())
                    .foregroundStyle(color)
                Text(unit)
                    .font(.caption2)
                    .foregroundStyle(.secondary)
            }
        }
        .frame(maxWidth: .infinity)
    }
}

/// 카메라로 사진 찍기
struct CameraPicker: UIViewControllerRepresentable {
    let onImage: (UIImage) -> Void
    let onCancel: () -> Void

    func makeUIViewController(context: Context) -> UIImagePickerController {
        let picker = UIImagePickerController()
        picker.sourceType = UIImagePickerController.isSourceTypeAvailable(.camera) ? .camera : .photoLibrary
        picker.delegate = context.coordinator
        return picker
    }

    func updateUIViewController(_ picker: UIImagePickerController, context: Context) {}

    func makeCoordinator() -> Coordinator { Coordinator(onImage: onImage, onCancel: onCancel) }

    final class Coordinator: NSObject, UIImagePickerControllerDelegate, UINavigationControllerDelegate {
        let onImage: (UIImage) -> Void
        let onCancel: () -> Void

        init(onImage: @escaping (UIImage) -> Void, onCancel: @escaping () -> Void) {
            self.onImage = onImage
            self.onCancel = onCancel
        }

        func imagePickerController(_ picker: UIImagePickerController,
                                   didFinishPickingMediaWithInfo info: [UIImagePickerController.InfoKey: Any]) {
            if let image = info[.originalImage] as? UIImage {
                onImage(image)
            } else {
                onCancel()
            }
        }

        func imagePickerControllerDidCancel(_ picker: UIImagePickerController) {
            onCancel()
        }
    }
}
