import AppIntents

/// Siri · 단축어 앱에 자동으로 나타나는 문구
struct HealthShortcuts: AppShortcutsProvider {
    static var appShortcuts: [AppShortcut] {
        AppShortcut(
            intent: LogWaterIntent(),
            phrases: [
                "\(.applicationName)에 물 한 잔 기록해",
                "\(.applicationName) 물 마셨어",
                "\(.applicationName)에 물 기록",
            ],
            shortTitle: "물 한 잔",
            systemImageName: "drop.fill"
        )
        AppShortcut(
            intent: MarkMedicationTakenIntent(),
            phrases: [
                "\(.applicationName) 약 먹었어",
                "\(.applicationName)에 복약 기록해",
            ],
            shortTitle: "약 복용 완료",
            systemImageName: "pills.fill"
        )
        AppShortcut(
            intent: ConditionIntent(),
            phrases: [
                "\(.applicationName) 오늘 컨디션 어때",
                "\(.applicationName) 준비 점수 알려줘",
            ],
            shortTitle: "오늘 컨디션",
            systemImageName: "gauge.with.needle.fill"
        )
        AppShortcut(
            intent: TonightMenuIntent(),
            phrases: [
                "\(.applicationName) 오늘 저녁 메뉴 뭐야",
                "\(.applicationName) 저녁 메뉴 알려줘",
            ],
            shortTitle: "오늘 저녁 메뉴",
            systemImageName: "fork.knife"
        )
    }
}
