import EventKit
import Foundation
import HealthKit
#if canImport(FoundationModels)
import FoundationModels
#endif

/// AI 코치가 대화 중에 필요한 기록을 직접 찾아볼 수 있게 해 주는 도구.
/// 모델은 한 번에 읽을 수 있는 양이 적어서, 14일이 넘는 기간은 주 단위 평균으로 요약해 돌려준다.
#if canImport(FoundationModels)
@available(iOS 26.0, *)
struct HealthHistoryTool: Tool {
    let name = "getHealthHistory"
    let description = """
    사용자의 과거 건강 기록과 일정을 조회합니다. '지난달', '3주 전과 비교', '이번 주 일정'처럼 \
    기간이 들어간 질문이나 요약에 없는 기록이 필요할 때 사용하세요.
    """

    @Generable
    struct Arguments {
        @Guide(description: """
        조회할 항목 하나: sleep(수면), readiness(준비 점수), steps(걸음 수), activeEnergy(활동 에너지), \
        exercise(운동 시간), restingHeartRate(안정 시 심박수), hrv(심박 변이), weight(체중), \
        workouts(운동 기록), medication(복약 기록), habits(습관 기록), calendar(앞으로의 가족 일정)
        """)
        var item: String

        @Guide(description: "조회할 일 수 (1~90). calendar는 앞으로 며칠, 나머지는 오늘부터 거슬러 올라가는 일 수")
        var days: Int
    }

    func call(arguments: Arguments) async throws -> String {
        await HealthHistoryProvider.history(item: arguments.item, days: min(max(arguments.days, 1), 90))
    }
}
#endif

/// 도구와 주간 리포트가 함께 쓰는 기록 조회
@MainActor
enum HealthHistoryProvider {
    private static let service = HealthKitService()

    static func history(item: String, days: Int) async -> String {
        let key = item.lowercased().trimmingCharacters(in: .whitespaces)
        let dashboard = DashboardModel.shared
        let calendar = Calendar.current
        let start = calendar.date(byAdding: .day, value: -(days - 1), to: calendar.startOfDay(for: .now)) ?? .now

        switch key {
        case "sleep", "수면":
            let nights = dashboard.sleepNights.filter { $0.wakeDate >= start }
            guard !nights.isEmpty else { return "수면 기록 없음 (앱은 최근 45일까지 보관)" }
            let points = nights.map { TrendPoint(date: $0.wakeDate, value: $0.asleep / 3600) }
            if days <= 14 {
                return nights.map { night in
                    let bedtime = night.bedtime.map { $0.formatted(date: .omitted, time: .shortened) } ?? "?"
                    return "\(label(night.wakeDate)): \(night.asleep.hoursMinutesText) (취침 \(bedtime))"
                }.joined(separator: "\n")
            }
            return weekly(points, unit: "시간", digits: 1)

        case "readiness", "준비 점수", "준비점수":
            let scores = dashboard.readiness.filter { $0.date >= start }
            guard !scores.isEmpty else { return "준비 점수 기록 없음 (최근 30일까지 계산)" }
            return format(scores.map { TrendPoint(date: $0.date, value: $0.score) }, days: days, unit: "점", digits: 1)

        case "workouts", "운동 기록", "운동":
            let items = dashboard.workouts.filter { $0.start >= start }
            guard !items.isEmpty else { return "해당 기간 운동 기록 없음" }
            return items.map {
                "\(label($0.start)): \($0.activityType.displayName) \(Int($0.duration / 60))분"
                    + ($0.energy.map { " \(Int($0))kcal" } ?? "")
                    + ($0.distanceKm.map { " \($0.formatted(.number.precision(.fractionLength(1))))km" } ?? "")
            }.joined(separator: "\n")

        case "medication", "복약":
            let store = MedicationStore.shared
            guard !store.reminders.isEmpty else { return "등록된 약 없음" }
            return store.reminders.map { reminder in
                let taken = (0..<days).filter { offset in
                    calendar.date(byAdding: .day, value: -offset, to: .now).map { store.isTaken(reminder, on: $0) } ?? false
                }.count
                return "\(reminder.name): 최근 \(days)일 중 \(taken)일 복용 기록"
            }.joined(separator: "\n")

        case "habits", "습관":
            let store = HabitStore.shared
            let lines = (0..<days).compactMap { offset -> String? in
                guard let date = calendar.date(byAdding: .day, value: -offset, to: .now), store.isLogged(date) else { return nil }
                let tags = store.tags(on: date)
                return "\(label(date)): " + (tags.isEmpty ? "특별한 일 없음" : tags.map(\.title).sorted().joined(separator: ", "))
            }
            return lines.isEmpty ? "습관 기록 없음" : lines.joined(separator: "\n")

        case "calendar", "일정", "캘린더":
            return CalendarStore.shared.upcomingSummary(days: days)

        default:
            let mapping: [String: HKQuantityTypeIdentifier] = [
                "steps": .stepCount, "걸음": .stepCount, "걸음 수": .stepCount,
                "activeenergy": .activeEnergyBurned, "활동 에너지": .activeEnergyBurned,
                "exercise": .appleExerciseTime, "운동 시간": .appleExerciseTime,
                "restingheartrate": .restingHeartRate, "안정 시 심박수": .restingHeartRate,
                "hrv": .heartRateVariabilitySDNN, "심박 변이": .heartRateVariabilitySDNN,
                "weight": .bodyMass, "체중": .bodyMass,
            ]
            guard let id = mapping[key], let metric = HealthMetric.metric(id) else {
                return "알 수 없는 항목입니다. sleep, readiness, steps, activeEnergy, exercise, restingHeartRate, hrv, weight, workouts, medication, habits, calendar 중 하나를 쓰세요."
            }
            let points: [TrendPoint]
            if dashboard.demoMode {
                points = DemoData.trend(for: metric, days: days)
            } else {
                points = (try? await service.dailyTrend(for: metric, days: days)) ?? []
            }
            guard !points.isEmpty else { return "\(metric.title) 기록 없음" }
            return "\(metric.title)\n" + format(points, days: days, unit: metric.unitLabel, digits: metric.fractionDigits)
        }
    }

    // MARK: - 형식

    private static func label(_ date: Date) -> String {
        date.formatted(.dateTime.month(.defaultDigits).day().weekday(.abbreviated))
    }

    private static func format(_ points: [TrendPoint], days: Int, unit: String, digits: Int) -> String {
        guard days > 14 else {
            return points.map { "\(label($0.date)): \(number($0.value, digits))\(unit)" }.joined(separator: "\n")
        }
        return weekly(points, unit: unit, digits: digits)
    }

    /// 주(월요일 시작)별 평균
    private static func weekly(_ points: [TrendPoint], unit: String, digits: Int) -> String {
        var calendar = Calendar.current
        calendar.firstWeekday = 2
        let groups = Dictionary(grouping: points) {
            calendar.dateInterval(of: .weekOfYear, for: $0.date)?.start ?? $0.date
        }
        return groups.keys.sorted().map { weekStart in
            let values = groups[weekStart]!.map(\.value)
            let average = values.reduce(0, +) / Double(values.count)
            return "\(weekStart.formatted(.dateTime.month(.defaultDigits).day()))~ 주 평균: \(number(average, digits))\(unit) (\(values.count)일)"
        }.joined(separator: "\n")
    }

    private static func number(_ value: Double, _ digits: Int) -> String {
        value.formatted(.number.precision(.fractionLength(digits)))
    }
}
