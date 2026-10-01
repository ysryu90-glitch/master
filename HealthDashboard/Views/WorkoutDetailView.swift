import Charts
import CoreLocation
import MapKit
import SwiftUI

/// 운동 상세: 심박수 그래프 · 심박존 · 경로 지도
struct WorkoutDetailView: View {
    let workout: WorkoutItem

    @State private var heartRates: [(date: Date, bpm: Double)] = []
    @State private var route: [CLLocationCoordinate2D] = []
    @State private var maxHeartRate: Double = 185
    @State private var isLoading = true

    private let service = HealthKitService()

    private struct Zone: Identifiable {
        let index: Int
        let range: ClosedRange<Double>
        let seconds: Double
        var id: Int { index }

        var title: String {
            ["", "1 회복", "2 지방 연소", "3 유산소", "4 젖산 역치", "5 최대"][index]
        }

        var color: Color {
            [.gray, .blue, .green, .yellow, .orange, .red][index]
        }
    }

    private var zones: [Zone] {
        let bounds: [Double] = [0.5, 0.6, 0.7, 0.8, 0.9, 1.01]
        var seconds = Array(repeating: 0.0, count: 6)
        for (index, sample) in heartRates.enumerated() {
            let next = index + 1 < heartRates.count ? heartRates[index + 1].date : sample.date.addingTimeInterval(5)
            let duration = min(next.timeIntervalSince(sample.date), 60)
            let ratio = sample.bpm / maxHeartRate
            if let zone = (1...5).first(where: { ratio >= bounds[$0 - 1] && ratio < bounds[$0] }) {
                seconds[zone] += duration
            }
        }
        return (1...5).map { Zone(index: $0, range: bounds[$0 - 1]...bounds[$0], seconds: seconds[$0]) }
    }

    var body: some View {
        ScrollView {
            VStack(spacing: 16) {
                summary

                if !route.isEmpty {
                    Map {
                        MapPolyline(coordinates: route)
                            .stroke(.orange, lineWidth: 4)
                    }
                    .frame(height: 240)
                    .clipShape(RoundedRectangle(cornerRadius: 22, style: .continuous))
                }

                if !heartRates.isEmpty {
                    heartRateChart
                    zoneCard
                } else if !isLoading {
                    Text("이 운동의 심박수 기록이 없어요.")
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                        .card()
                }
            }
            .padding()
        }
        .background(Color(.systemGroupedBackground))
        .navigationTitle(workout.activityType.displayName)
        .navigationBarTitleDisplayMode(.inline)
        .overlay { if isLoading { ProgressView() } }
        .task {
            if let age = service.age() { maxHeartRate = Double(220 - age) }
            let end = workout.start.addingTimeInterval(workout.duration)
            heartRates = await service.heartRates(from: workout.start, to: end)
            route = await service.route(forWorkout: workout.id).map(\.coordinate)
            isLoading = false
        }
    }

    private var summary: some View {
        HStack {
            stat("시간", workout.duration.hoursMinutesText)
            if let distance = workout.distanceKm {
                stat("거리", "\(distance.formatted(.number.precision(.fractionLength(2))))km")
                if distance > 0.5 {
                    stat("페이스", pace(seconds: workout.duration / distance))
                }
            }
            if let energy = workout.energy { stat("칼로리", "\(Int(energy))kcal") }
            if let heartRate = workout.averageHeartRate { stat("평균 심박", "\(Int(heartRate))") }
        }
        .card()
    }

    private func stat(_ title: String, _ value: String) -> some View {
        VStack(spacing: 4) {
            Text(title)
                .font(.caption)
                .foregroundStyle(.secondary)
            Text(value)
                .font(.headline.monospacedDigit())
                .lineLimit(1)
                .minimumScaleFactor(0.7)
        }
        .frame(maxWidth: .infinity)
    }

    private var heartRateChart: some View {
        VStack(alignment: .leading, spacing: 8) {
            Text("심박수")
                .font(.headline)
            Chart {
                ForEach(Array(heartRates.enumerated()), id: \.offset) { _, sample in
                    LineMark(x: .value("시각", sample.date), y: .value("BPM", sample.bpm))
                        .foregroundStyle(.red)
                        .interpolationMethod(.catmullRom)
                }
            }
            .chartYScale(domain: .automatic(includesZero: false))
            .frame(height: 180)
            Text("최고 \(Int(heartRates.map(\.bpm).max() ?? 0)) · 최대 심박 기준 \(Int(maxHeartRate))BPM (220 − 나이)")
                .font(.caption)
                .foregroundStyle(.secondary)
        }
        .card()
    }

    private var zoneCard: some View {
        let zones = self.zones
        let total = max(zones.reduce(0) { $0 + $1.seconds }, 1)
        return VStack(alignment: .leading, spacing: 10) {
            Text("심박존")
                .font(.headline)
            ForEach(zones.reversed()) { zone in
                HStack(spacing: 8) {
                    Text("존 \(zone.title)")
                        .font(.caption)
                        .frame(width: 92, alignment: .leading)
                    GeometryReader { proxy in
                        Capsule()
                            .fill(zone.color.gradient)
                            .frame(width: max(proxy.size.width * zone.seconds / total, zone.seconds > 0 ? 4 : 0))
                    }
                    .frame(height: 10)
                    Text(zone.seconds.hoursMinutesText)
                        .font(.caption.monospacedDigit())
                        .frame(width: 64, alignment: .trailing)
                }
            }
            Text("유산소 체력은 존 2, 심폐 강화는 존 4 시간이 쌓일수록 좋아져요.")
                .font(.caption2)
                .foregroundStyle(.tertiary)
        }
        .card()
    }

    private func pace(seconds perKm: Double) -> String {
        let total = Int(perKm.rounded())
        return "\(total / 60)'\(String(format: "%02d", total % 60))\"/km"
    }
}

/// 최근 1년 개인 기록
struct PersonalRecordsCard: View {
    @State private var records: [(title: String, value: String, date: Date)] = []
    private let service = HealthKitService()

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            Label("개인 기록 (최근 1년)", systemImage: "trophy.fill")
                .font(.headline)
                .foregroundStyle(.yellow)
            if records.isEmpty {
                Text("운동 기록이 쌓이면 나와요.")
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
            }
            ForEach(Array(records.enumerated()), id: \.offset) { _, record in
                HStack {
                    Text(record.title)
                        .font(.subheadline)
                    Spacer()
                    VStack(alignment: .trailing, spacing: 1) {
                        Text(record.value)
                            .font(.subheadline.weight(.semibold).monospacedDigit())
                        Text(record.date.formatted(date: .abbreviated, time: .omitted))
                            .font(.caption2)
                            .foregroundStyle(.secondary)
                    }
                }
            }
        }
        .card()
        .task {
            let since = Calendar.current.date(byAdding: .year, value: -1, to: .now) ?? .now
            let workouts = await service.workouts(since: since)
            records = Self.compute(workouts)
        }
    }

    private static func compute(_ workouts: [WorkoutItem]) -> [(title: String, value: String, date: Date)] {
        var result: [(title: String, value: String, date: Date)] = []
        let runs = workouts.filter { $0.activityType == .running }
        if let longest = runs.max(by: { ($0.distanceKm ?? 0) < ($1.distanceKm ?? 0) }), let distance = longest.distanceKm {
            result.append(("🏃 가장 긴 달리기", "\(distance.formatted(.number.precision(.fractionLength(2))))km", longest.start))
        }
        let paced = runs.filter { ($0.distanceKm ?? 0) >= 3 }
        if let fastest = paced.min(by: { $0.duration / ($0.distanceKm ?? 1) < $1.duration / ($1.distanceKm ?? 1) }),
           let distance = fastest.distanceKm {
            let perKm = Int((fastest.duration / distance).rounded())
            result.append(("⚡️ 가장 빠른 페이스 (3km+)", "\(perKm / 60)'\(String(format: "%02d", perKm % 60))\"/km", fastest.start))
        }
        if let ride = workouts.filter({ $0.activityType == .cycling }).max(by: { ($0.distanceKm ?? 0) < ($1.distanceKm ?? 0) }),
           let distance = ride.distanceKm {
            result.append(("🚴 가장 긴 라이딩", "\(distance.formatted(.number.precision(.fractionLength(1))))km", ride.start))
        }
        if let longest = workouts.max(by: { $0.duration < $1.duration }) {
            result.append(("⏱ 가장 긴 운동", "\(longest.activityType.displayName) \(longest.duration.hoursMinutesText)", longest.start))
        }
        if let burn = workouts.max(by: { ($0.energy ?? 0) < ($1.energy ?? 0) }), let energy = burn.energy {
            result.append(("🔥 최대 소모 칼로리", "\(Int(energy))kcal", burn.start))
        }
        return result
    }
}
