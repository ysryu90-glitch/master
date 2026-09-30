import SwiftUI
import UserNotifications

@main
struct HealthDashboardApp: App {
    @State private var model = DashboardModel()
    @State private var weather = WeatherModel()

    init() {
        UNUserNotificationCenter.current().delegate = NotificationDelegate.shared
    }

    var body: some Scene {
        WindowGroup {
            RootView()
                .environment(model)
                .environment(weather)
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
}

struct RootView: View {
    @Environment(DashboardModel.self) private var model

    var body: some View {
        if model.hasRequestedAuthorization || model.demoMode {
            TabView {
                DashboardView()
                    .tabItem { Label("건강", systemImage: "heart.text.square.fill") }
                WeatherView()
                    .tabItem { Label("날씨", systemImage: "cloud.sun.fill") }
            }
        } else {
            WelcomeView()
        }
    }
}
