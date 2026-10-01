import Foundation
import Observation

/// 건강검진 결과 항목 하나 (예: LDL 콜레스테롤 128 mg/dL)
struct CheckupItem: Codable, Identifiable, Hashable {
    var id = UUID()
    var name: String
    var value: Double
    var unit: String
    /// 참고 범위 (없으면 nil)
    var lower: Double?
    var upper: Double?

    var isOutOfRange: Bool {
        if let lower, value < lower { return true }
        if let upper, value > upper { return true }
        return false
    }

    var rangeText: String {
        switch (lower, upper) {
        case let (lower?, upper?): "\(Self.format(lower))~\(Self.format(upper))"
        case let (lower?, nil): "\(Self.format(lower)) 이상"
        case let (nil, upper?): "\(Self.format(upper)) 이하"
        default: ""
        }
    }

    static func format(_ value: Double) -> String {
        value.formatted(.number.precision(.fractionLength(0...1)))
    }
}

/// 검진 한 번의 결과
struct CheckupRecord: Codable, Identifiable {
    var id = UUID()
    var date: Date
    var title: String
    var items: [CheckupItem]
    var note = ""
}

/// 정기 검진 · 예방접종 주기
struct CareSchedule: Codable, Identifiable {
    var id = UUID()
    var title: String
    var symbol: String
    var intervalMonths: Int
    var lastDate: Date?

    var nextDue: Date? {
        lastDate.flatMap { Calendar.current.date(byAdding: .month, value: intervalMonths, to: $0) }
    }

    var isDue: Bool {
        guard let nextDue else { return true }
        return nextDue <= Calendar.current.date(byAdding: .month, value: 1, to: .now) ?? .now
    }
}

/// 건강검진 기록 · 정기 검진 일정 (앱 문서 폴더에 저장 → 백업에 포함)
@MainActor
@Observable
final class CheckupStore {
    static let shared = CheckupStore()

    private(set) var records: [CheckupRecord] = []
    var schedules: [CareSchedule] = [] {
        didSet { persist() }
    }

    private struct FileContents: Codable {
        var records: [CheckupRecord]
        var schedules: [CareSchedule]
    }

    private static var fileURL: URL {
        FileManager.default.urls(for: .documentDirectory, in: .userDomainMask)[0].appendingPathComponent("checkups.json")
    }

    /// 국가건강검진 결과지에 나오는 주요 항목 (참고 범위는 일반적인 성인 기준)
    static let templates: [CheckupItem] = [
        CheckupItem(name: "수축기 혈압", value: 0, unit: "mmHg", upper: 119),
        CheckupItem(name: "이완기 혈압", value: 0, unit: "mmHg", upper: 79),
        CheckupItem(name: "공복혈당", value: 0, unit: "mg/dL", upper: 99),
        CheckupItem(name: "총콜레스테롤", value: 0, unit: "mg/dL", upper: 199),
        CheckupItem(name: "HDL 콜레스테롤", value: 0, unit: "mg/dL", lower: 60),
        CheckupItem(name: "LDL 콜레스테롤", value: 0, unit: "mg/dL", upper: 129),
        CheckupItem(name: "중성지방", value: 0, unit: "mg/dL", upper: 149),
        CheckupItem(name: "AST", value: 0, unit: "U/L", upper: 40),
        CheckupItem(name: "ALT", value: 0, unit: "U/L", upper: 35),
        CheckupItem(name: "감마GTP", value: 0, unit: "U/L", upper: 63),
        CheckupItem(name: "혈청 크레아티닌", value: 0, unit: "mg/dL", upper: 1.5),
        CheckupItem(name: "헤모글로빈", value: 0, unit: "g/dL", lower: 13, upper: 16.5),
        CheckupItem(name: "체질량지수", value: 0, unit: "kg/m²", lower: 18.5, upper: 24.9),
        CheckupItem(name: "허리둘레", value: 0, unit: "cm", upper: 89),
    ]

    private static let defaultSchedules: [CareSchedule] = [
        CareSchedule(title: "국가건강검진", symbol: "stethoscope", intervalMonths: 24),
        CareSchedule(title: "치과 스케일링", symbol: "mouth", intervalMonths: 12),
        CareSchedule(title: "독감 예방접종", symbol: "syringe", intervalMonths: 12),
    ]

    init() {
        load()
    }

    func reloadFromDisk() {
        load()
    }

    private func load() {
        guard let data = try? Data(contentsOf: Self.fileURL),
              let contents = try? JSONDecoder().decode(FileContents.self, from: data) else {
            records = []
            schedules = Self.defaultSchedules
            return
        }
        records = contents.records.sorted { $0.date > $1.date }
        schedules = contents.schedules
    }

    private func persist() {
        guard let data = try? JSONEncoder().encode(FileContents(records: records, schedules: schedules)) else { return }
        try? data.write(to: Self.fileURL, options: [.atomic, .completeFileProtectionUntilFirstUserAuthentication])
    }

    // MARK: - 검진 결과

    func save(_ record: CheckupRecord) {
        records.removeAll { $0.id == record.id }
        records.append(record)
        records.sort { $0.date > $1.date }
        persist()
        // 국가건강검진 결과를 넣으면 주기 일정의 마지막 날짜도 갱신
        if let index = schedules.firstIndex(where: { $0.title == "국가건강검진" }),
           record.title.contains("검진"), (schedules[index].lastDate ?? .distantPast) < record.date {
            schedules[index].lastDate = record.date
        }
    }

    func delete(_ record: CheckupRecord) {
        records.removeAll { $0.id == record.id }
        persist()
    }

    /// 항목별 기록 (오래된 → 최근)
    func history(of itemName: String) -> [(date: Date, value: Double)] {
        records
            .compactMap { record in record.items.first { $0.name == itemName }.map { (record.date, $0.value) } }
            .sorted { $0.date < $1.date }
    }

    /// 최근 검진에서 지난 검진 대비 변화
    func change(of item: CheckupItem, in record: CheckupRecord) -> Double? {
        guard let previous = records
            .filter({ $0.date < record.date })
            .sorted(by: { $0.date > $1.date })
            .first?.items.first(where: { $0.name == item.name }) else { return nil }
        return item.value - previous.value
    }

    /// 모든 항목 이름 (기록에 나온 순)
    var itemNames: [String] {
        var seen = Set<String>()
        return records.flatMap { $0.items.map(\.name) }.filter { seen.insert($0).inserted }
    }
}
