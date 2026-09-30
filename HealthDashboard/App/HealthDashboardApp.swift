import SwiftUI
import UserNotifications

@main
struct HealthDashboardApp: App {
    @State private var model = DashboardModel.shared
    @State private var weather = WeatherModel()
    @State private var medications = MedicationStore.shared
    @State private var habits = HabitStore.shared

    init() {
        UNUserNotificationCenter.current().delegate = NotificationDelegate.shared
        NotificationDelegate.registerCategories()
        Self.migrateToAutoLocation()
        // 앱이 백그라운드로 깨어났을 때도 HealthKit 관찰을 다시 등록해야 새 데이터를 받을 수 있다.
        DashboardModel.shared.startBackgroundUpdates()
    }

    var body: some Scene {
        WindowGroup {
            RootView()
                .environment(model)
                .environment(weather)
                .environment(medications)
                .environment(habits)
        }
        // 아침 브리핑 전에 iOS가 앱을 깨우면 날씨·건강 요약과 알림을 갱신한다.
        .backgroundTask(.appRefresh(BriefingScheduler.backgroundTaskID)) {
            await handleBackgroundRefresh()
        }
    }

    @MainActor
    private func handleBackgroundRefresh() async {
        await weather.refresh(force: true)
        await model.refresh()
    }

    /// 이번 업데이트부터 기본 지역을 '자동(현재 위치)'으로 한 번 바꿔 둔다. (설정에서 다시 고를 수 있음)
    private static func migrateToAutoLocation() {
        let flag = "didMigrateToAutoLocation"
        guard !UserDefaults.standard.bool(forKey: flag) else { return }
        SharedStore.defaults.set(SharedStore.autoLocationToken, forKey: SharedStore.primaryLocationKey)
        UserDefaults.standard.set(true, forKey: flag)
    }
}

struct RootView: View {
    @Environment(DashboardModel.self) private var model

    var body: some View {
        if model.hasRequestedAuthorization || model.demoMode {
            TabView {
                DashboardView()
                    .tabItem { Label("건강", systemImage: "heart.text.square.fill") }
                CoachView()
                    .tabItem { Label("AI 코치", systemImage: "sparkles") }
                WeatherView()
                    .tabItem { Label("날씨", systemImage: "cloud.sun.fill") }
            }
        } else {
            WelcomeView()
        }
    }
}
