import SwiftUI

@main
struct HealthDashboardApp: App {
    @State private var model = DashboardModel()

    var body: some Scene {
        WindowGroup {
            RootView()
                .environment(model)
        }
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
