import Foundation

/// 위젯과 아침 브리핑에 쓰는 오늘의 건강 요약. 앱이 새로고침할 때마다 저장한다.
struct HealthSnapshot: Codable {
    var updatedAt: Date
    var readinessDate: Date?
    var readinessScore: Double?
    var readinessLevel: ReadinessLevel?
    var move: Double?
    var moveGoal: Double?
    var exercise: Double?
    var exerciseGoal: Double?
    var stand: Double?
    var standGoal: Double?
    var steps: Double?
    var sleepSeconds: Double?
    var restingHeartRate: Double?
    var isDemo = false
    /// 컨디션 리포트용: 준비 점수 요소별 상태 ("심박 변이 좋음" 등)
    var highlights: [String]?
    var yesterdaySteps: Double?
    var yesterdayMove: Double?
    var yesterdayMoveGoal: Double?

    /// `date`와 같은 날의 준비 점수가 있을 때만 반환
    func readiness(on date: Date) -> (score: Double, level: ReadinessLevel)? {
        guard let readinessDate, let readinessScore, let readinessLevel,
              Calendar.current.isDate(readinessDate, inSameDayAs: date) else { return nil }
        return (readinessScore, readinessLevel)
    }

    var readinessScoreText: String? {
        readinessScore?.formatted(.number.precision(.fractionLength(1)))
    }

    static let placeholder = HealthSnapshot(
        updatedAt: .now, readinessDate: .now, readinessScore: 7.6, readinessLevel: .ready,
        move: 420, moveGoal: 500, exercise: 26, exerciseGoal: 30, stand: 9, standGoal: 12,
        steps: 8_420, sleepSeconds: 7.1 * 3600, restingHeartRate: 58
    )
}

/// 앱과 위젯이 함께 쓰는 저장소 (App Group)
enum SharedStore {
    static let appGroupID = Bundle.main.object(forInfoDictionaryKey: "AppGroupID") as? String

    static let defaults: UserDefaults = appGroupID.flatMap { UserDefaults(suiteName: $0) } ?? .standard

    private enum Keys {
        static let snapshot = "healthSnapshot"
        static let primaryLocation = "primaryLocationID"
        static let briefingEnabled = "briefingEnabled"
        static let briefingHour = "briefingHour"
        static let briefingMinute = "briefingMinute"
    }

    static var healthSnapshot: HealthSnapshot? {
        get {
            guard let data = defaults.data(forKey: Keys.snapshot) else { return nil }
            return try? JSONDecoder().decode(HealthSnapshot.self, from: data)
        }
        set {
            defaults.set(newValue.flatMap { try? JSONEncoder().encode($0) }, forKey: Keys.snapshot)
        }
    }

    // 설정 화면의 @AppStorage와 같은 키를 사용한다.
    static let primaryLocationKey = Keys.primaryLocation
    static let briefingEnabledKey = Keys.briefingEnabled
    static let briefingHourKey = Keys.briefingHour
    static let briefingMinuteKey = Keys.briefingMinute

    /// 기본 지역 설정값이 이 값이면 '현재 위치에서 가장 가까운 지역'을 쓴다.
    static let autoLocationToken = "auto"
    /// 앱이 마지막으로 확인한 '현재 위치에서 가장 가까운 지역' ID
    static let autoLocationKey = "autoLocationID"

    static var isAutoLocation: Bool {
        (defaults.string(forKey: Keys.primaryLocation) ?? autoLocationToken) == autoLocationToken
    }

    static var autoLocationID: String? {
        get { defaults.string(forKey: autoLocationKey) }
        set { defaults.set(newValue, forKey: autoLocationKey) }
    }

    /// 설정값(지역 ID 또는 "auto")을 실제 지역으로 바꾼다.
    static func resolveLocation(id: String?, autoID: String?) -> WeatherLocation {
        let resolvedID = (id ?? autoLocationToken) == autoLocationToken ? autoID : id
        return WeatherLocation.all.first { $0.id == resolvedID } ?? WeatherLocation.all[0]
    }

    /// 추천·알림·위젯에 쓰는 지역 (자동이면 현재 위치 기준)
    static var primaryLocation: WeatherLocation {
        resolveLocation(id: defaults.string(forKey: Keys.primaryLocation), autoID: autoLocationID)
    }

    /// 지금 있는 곳과 가장 가까운 지역 (위치를 모르면 기본 지역)
    static var currentLocation: WeatherLocation {
        autoLocationID.flatMap { id in WeatherLocation.all.first { $0.id == id } } ?? primaryLocation
    }

    static var briefingEnabled: Bool { defaults.bool(forKey: Keys.briefingEnabled) }

    static let earlyWarningEnabledKey = "earlyWarningEnabled"

    /// 컨디션 이상 경보 알림 (기본: 켬)
    static var earlyWarningEnabled: Bool {
        defaults.object(forKey: earlyWarningEnabledKey) as? Bool ?? true
    }

    static let reportIncludeWeatherKey = "reportIncludeWeather"

    /// 컨디션 리포트에 날씨·운동 추천을 넣을지 (기본: 넣음)
    static var reportIncludeWeather: Bool {
        defaults.object(forKey: reportIncludeWeatherKey) as? Bool ?? true
    }

    static var briefingTime: DateComponents {
        let hour = defaults.object(forKey: Keys.briefingHour) as? Int ?? 7
        let minute = defaults.object(forKey: Keys.briefingMinute) as? Int ?? 0
        return DateComponents(hour: hour, minute: minute)
    }
}
