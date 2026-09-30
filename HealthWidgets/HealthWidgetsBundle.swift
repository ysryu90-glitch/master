import SwiftUI
import WidgetKit

@main
struct HealthWidgetsBundle: WidgetBundle {
    var body: some Widget {
        TodayWidget()
        ActivityWidget()
        WeatherWidget()
    }
}
