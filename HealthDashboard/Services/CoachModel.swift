import Foundation
import HealthKit
import Observation
#if canImport(FoundationModels)
import FoundationModels
#endif

/// 아이폰 안의 Apple Intelligence 모델(Foundation Models)로 내 건강 데이터에 대해 대답한다.
/// 데이터와 대화는 기기 밖으로 나가지 않는다.
@MainActor
@Observable
final class CoachModel {
    struct Message: Identifiable {
        enum Role { case user, coach }
        let id = UUID()
        let role: Role
        let text: String
    }

    private(set) var messages: [Message] = []
    private(set) var isResponding = false
    /// nil이면 사용 가능. 값이 있으면 사용할 수 없는 이유
    private(set) var unavailableReason: String?

    /// 대화 세션 (iOS 26 이상에서만 LanguageModelSession이 들어간다)
    private var session: AnyObject?

    static let suggestions = [
        "오늘 컨디션 요약해줘",
        "요즘 왜 피곤할까?",
        "오늘 운동해도 될까?",
        "수면을 개선하려면?",
        "내 습관 중 뭐가 제일 안 좋아?",
    ]

    private static let instructions = """
    당신은 사용자의 개인 건강 코치입니다. 아래 [내 건강 데이터]만 근거로 한국어 존댓말로 답하세요.
    - 5문장 이내로 짧고 구체적으로, 가능하면 데이터의 숫자를 인용하세요.
    - 오늘 바로 할 수 있는 행동을 1~2개 제안하세요.
    - 데이터에 없는 내용은 추측하지 말고 모른다고 말하세요.
    - 의학적 진단이나 약 용량 조언은 하지 말고, 이상이 계속되면 전문의 상담을 권하세요.
    """

    func checkAvailability() {
        #if canImport(FoundationModels)
        if #available(iOS 26.0, *) {
            switch SystemLanguageModel.default.availability {
            case .available:
                unavailableReason = nil
            case .unavailable(let reason):
                unavailableReason = switch reason {
                case .deviceNotEligible:
                    "이 기기는 Apple Intelligence를 지원하지 않아요."
                case .appleIntelligenceNotEnabled:
                    "설정 › Apple Intelligence 및 Siri에서 Apple Intelligence를 켜 주세요."
                case .modelNotReady:
                    "AI 모델을 준비(다운로드) 중이에요. 와이파이에 연결된 상태로 잠시 후 다시 시도해 주세요."
                @unknown default:
                    "지금은 AI 모델을 사용할 수 없어요."
                }
            }
        } else {
            unavailableReason = "AI 코치는 iOS 26 이상에서 사용할 수 있어요."
        }
        #else
        unavailableReason = "AI 코치를 쓰려면 Xcode 26 이상으로 빌드해야 해요."
        #endif
    }

    /// 새 대화 시작 (최신 건강 데이터로 다시 시작)
    func reset() {
        session = nil
        messages = []
    }

    func send(_ text: String, context: String) async {
        let question = text.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !question.isEmpty, !isResponding else { return }
        messages.append(Message(role: .user, text: question))
        isResponding = true
        defer { isResponding = false }

        #if canImport(FoundationModels)
        if #available(iOS 26.0, *) {
            do {
                let session = currentSession(context: context)
                let response = try await session.respond(to: question)
                messages.append(Message(role: .coach, text: response.content))
            } catch {
                // 대화가 길어져 한도를 넘었거나 안전 필터에 걸린 경우: 새 세션으로 다시 시작
                self.session = nil
                messages.append(Message(
                    role: .coach,
                    text: "죄송해요, 답변을 만들지 못했어요. 질문을 조금 바꿔서 다시 물어봐 주세요. (대화가 길어지면 '새 대화'를 눌러 주세요)"
                ))
            }
            return
        }
        #endif
        messages.append(Message(role: .coach, text: unavailableReason ?? "지금은 AI 코치를 사용할 수 없어요."))
    }

    #if canImport(FoundationModels)
    @available(iOS 26.0, *)
    private func currentSession(context: String) -> LanguageModelSession {
        if let existing = session as? LanguageModelSession { return existing }
        let created = LanguageModelSession(instructions: Self.instructions + "\n\n[내 건강 데이터]\n" + context)
        session = created
        return created
    }
    #endif
}

// MARK: - AI에게 줄 건강 데이터 요약

enum CoachContext {
    /// 모델이 한 번에 읽을 수 있는 양이 제한적이라 핵심만 짧게 정리한다.
    @MainActor
    static func build(
        dashboard: DashboardModel,
        medications: MedicationStore,
        habits: HabitStore,
        location: WeatherLocation,
        weather: WeatherReport?
    ) -> String {
        var lines: [String] = []
        let calendar = Calendar.current
        lines.append("오늘: \(Date.now.formatted(.dateTime.year().month().day().weekday(.wide)))")

        // 준비 점수
        if let today = dashboard.todayReadiness {
            lines.append("오늘 준비 점수: \(today.scoreText)/10 (\(today.level.title))")
            for component in today.components {
                lines.append("- \(component.title): \(component.status) (\(component.detail))")
            }
        } else {
            lines.append("오늘 준비 점수: 아직 계산 안 됨")
        }
        let recent = dashboard.recentReadiness
            .map { "\($0.date.formatted(.dateTime.month(.defaultDigits).day())) \($0.scoreText)" }
        if !recent.isEmpty {
            lines.append("최근 준비 점수: " + recent.joined(separator: ", "))
        }

        // 조기 경보
        if let warning = dashboard.earlyWarning {
            lines.append("⚠️ \(warning.title): " + warning.signals.joined(separator: ", "))
        }

        // 수면 (최근 7일)
        let nights = dashboard.sleepNights.suffix(7).map { night -> String in
            let bedtime = night.bedtime.map { $0.formatted(date: .omitted, time: .shortened) } ?? "?"
            return "\(night.wakeDate.formatted(.dateTime.month(.defaultDigits).day())) \(night.asleep.hoursMinutesText)(취침 \(bedtime))"
        }
        if !nights.isEmpty {
            lines.append("최근 수면: " + nights.joined(separator: ", "))
        }

        // 활동
        if let today = dashboard.today {
            lines.append("오늘 활동: 움직이기 \(Int(today.move))/\(Int(today.moveGoal))kcal, 운동 \(Int(today.exercise))분, 일어서기 \(Int(today.stand))시간")
        }
        if let steps = dashboard.values[.stepCount]?.value {
            lines.append("오늘 걸음: \(Int(steps))")
        }
        if let yesterday = dashboard.yesterdaySteps {
            lines.append("어제 걸음: \(Int(yesterday))")
        }
        let weekWorkouts = dashboard.workouts.filter { $0.start > calendar.date(byAdding: .day, value: -7, to: .now) ?? .now }
        if !weekWorkouts.isEmpty {
            lines.append("최근 7일 운동: " + weekWorkouts.prefix(6).map {
                "\($0.activityType.displayName) \(Int($0.duration / 60))분"
            }.joined(separator: ", "))
        }

        // 주요 측정값
        let keyMetrics: [HKQuantityTypeIdentifier] = [
            .restingHeartRate, .heartRateVariabilitySDNN, .oxygenSaturation, .respiratoryRate, .vo2Max, .bodyMass,
        ]
        let measured = keyMetrics.compactMap { id -> String? in
            guard let metric = HealthMetric.metric(id), let value = dashboard.values[id] else { return nil }
            return "\(metric.title) \(metric.formatted(value.value))\(metric.unitLabel)"
        }
        if !measured.isEmpty {
            lines.append("최근 측정: " + measured.joined(separator: ", "))
        }

        // 복약
        for reminder in medications.reminders {
            let week = medications.weekHistory(reminder)
            lines.append("복약 '\(reminder.name)': 최근 7일 중 \(week.filter(\.taken).count)일 복용, 오늘 \(medications.isTaken(reminder) ? "복용함" : "아직 안 먹음")")
        }

        // 습관 분석
        let todayTags = habits.tags(on: HabitStore.loggingDate)
        if !todayTags.isEmpty {
            lines.append("오늘 기록한 습관: " + todayTags.map(\.title).joined(separator: ", "))
        }
        for insight in dashboard.habitInsights(habits).prefix(4) {
            lines.append("습관 분석 - \(insight.title)(\(insight.count)일): " + insight.effects.map(\.text).joined(separator: ", "))
        }

        // 날씨
        if let weather {
            var line = "\(location.name) 날씨: \(weather.current.condition.description) \(weather.current.temperature.degreesText)"
            if let today = weather.today {
                line += ", 최저 \(today.low.degreesText) 최고 \(today.high.degreesText)"
            }
            if let grade = weather.airQuality?.overallGrade {
                line += ", 미세먼지 \(grade.title)"
            }
            lines.append(line)
        }

        return lines.joined(separator: "\n")
    }
}
