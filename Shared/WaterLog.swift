import Foundation

/// 물 섭취: 위젯 · Siri에서 누른 기록은 여기 쌓아 두고, 앱이 열리면 건강 앱에 옮겨 저장한다.
/// (위젯에서 건강 앱에 직접 쓰지 않고 앱이 대신 저장)
enum WaterLog {
    struct Entry: Codable {
        let date: Date
        let ml: Double
    }

    private static let pendingKey = "pendingWaterEntries"
    private static var defaults: UserDefaults { SharedStore.defaults }

    static var pending: [Entry] {
        get {
            guard let data = defaults.data(forKey: pendingKey) else { return [] }
            return (try? JSONDecoder().decode([Entry].self, from: data)) ?? []
        }
        set {
            defaults.set(try? JSONEncoder().encode(newValue), forKey: pendingKey)
        }
    }

    static func add(ml: Double, at date: Date = .now) {
        pending.append(Entry(date: date, ml: ml))
    }

    /// 앱이 건강 앱에 옮겨 저장할 기록을 꺼낸다.
    static func takePending() -> [Entry] {
        let entries = pending
        pending = []
        return entries
    }

    /// 오늘 마신 양: 건강 앱 기록(앱이 마지막으로 저장한 요약) + 아직 옮기지 않은 기록
    static func todayTotal() -> Double {
        let snapshot = SharedStore.healthSnapshot
        let saved = (snapshot?.isFromTodayOrNil ?? false) ? (snapshot?.waterMl ?? 0) : 0
        let unsynced = pending.filter { Calendar.current.isDateInToday($0.date) }.reduce(0) { $0 + $1.ml }
        return saved + unsynced
    }

    static func todayGoal() -> Double {
        SharedStore.healthSnapshot?.waterGoal ?? 2000
    }
}

extension HealthSnapshot {
    var isFromTodayOrNil: Bool { Calendar.current.isDateInToday(updatedAt) }
}
