import CoreLocation
import WidgetKit

/// '자동(현재 위치)' 지역을 위해 앱이 열릴 때 현재 위치를 한 번 확인한다.
/// 위치는 기기 안에서 가장 가까운 지역(은평구·소공동·평택)을 고르는 데만 쓰고 저장하지 않는다.
@MainActor
final class LocationService: NSObject, CLLocationManagerDelegate {
    static let shared = LocationService()

    private let manager = CLLocationManager()
    private var locationContinuation: CheckedContinuation<CLLocation?, Never>?
    private var authorizationContinuation: CheckedContinuation<Void, Never>?

    override init() {
        super.init()
        manager.delegate = self
        manager.desiredAccuracy = kCLLocationAccuracyKilometer
    }

    var isDenied: Bool {
        manager.authorizationStatus == .denied || manager.authorizationStatus == .restricted
    }

    /// 자동 모드일 때 가장 가까운 지역을 갱신한다. 지역이 바뀌면 위젯과 알림도 갱신한다.
    @discardableResult
    func refreshIfNeeded() async -> Bool {
        guard SharedStore.isAutoLocation, let location = await currentLocation() else { return false }
        let nearest = WeatherLocation.nearest(to: location.coordinate)
        guard nearest.id != SharedStore.autoLocationID else { return false }

        SharedStore.autoLocationID = nearest.id
        WidgetCenter.shared.reloadAllTimelines()
        await BriefingScheduler.reschedule()
        return true
    }

    private func currentLocation() async -> CLLocation? {
        if manager.authorizationStatus == .notDetermined {
            await withCheckedContinuation { continuation in
                authorizationContinuation = continuation
                manager.requestWhenInUseAuthorization()
            }
        }
        guard manager.authorizationStatus == .authorizedWhenInUse || manager.authorizationStatus == .authorizedAlways else {
            return nil
        }

        // 10분 이내에 받은 위치가 있으면 그대로 사용
        if let cached = manager.location, cached.timestamp > .now.addingTimeInterval(-600) {
            return cached
        }
        // 이미 요청 중이면 겹쳐서 요청하지 않는다.
        guard locationContinuation == nil else { return manager.location }
        return await withCheckedContinuation { continuation in
            locationContinuation = continuation
            manager.requestLocation()
        }
    }

    private func finishLocation(_ location: CLLocation?) {
        locationContinuation?.resume(returning: location)
        locationContinuation = nil
    }

    // MARK: - CLLocationManagerDelegate

    nonisolated func locationManager(_ manager: CLLocationManager, didUpdateLocations locations: [CLLocation]) {
        let location = locations.last
        Task { @MainActor in self.finishLocation(location) }
    }

    nonisolated func locationManager(_ manager: CLLocationManager, didFailWithError error: Error) {
        Task { @MainActor in self.finishLocation(nil) }
    }

    nonisolated func locationManagerDidChangeAuthorization(_ manager: CLLocationManager) {
        Task { @MainActor in
            guard self.manager.authorizationStatus != .notDetermined else { return }
            self.authorizationContinuation?.resume()
            self.authorizationContinuation = nil
        }
    }
}
