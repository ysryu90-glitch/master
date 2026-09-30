import SwiftUI

struct WelcomeView: View {
    @Environment(DashboardModel.self) private var model

    var body: some View {
        VStack(spacing: 28) {
            Spacer()

            Image(systemName: "heart.text.square.fill")
                .font(.system(size: 88))
                .foregroundStyle(.red.gradient)

            VStack(spacing: 12) {
                Text("내 건강, 한눈에")
                    .font(.largeTitle.bold())
                Text("애플워치와 아이폰이 기록한 활동, 심박수, 수면, 운동, 신체 정보를 한 화면에서 확인하세요.")
                    .font(.body)
                    .foregroundStyle(.secondary)
                    .multilineTextAlignment(.center)
            }

            VStack(alignment: .leading, spacing: 14) {
                FeatureRow(symbol: "circle.circle", tint: .orange, text: "활동 링과 오늘의 움직임")
                FeatureRow(symbol: "heart.fill", tint: .red, text: "심박수 · 심박 변이 · 혈중 산소")
                FeatureRow(symbol: "bed.double.fill", tint: .indigo, text: "수면 단계와 수면 시간")
                FeatureRow(symbol: "chart.xyaxis.line", tint: .green, text: "지표별 7 · 30 · 90일 추세")
            }
            .padding()
            .background(.background.secondary, in: RoundedRectangle(cornerRadius: 16))

            Spacer()

            VStack(spacing: 12) {
                Button {
                    Task { await model.connect() }
                } label: {
                    Text("건강 데이터 연결하기")
                        .font(.headline)
                        .frame(maxWidth: .infinity)
                        .padding(.vertical, 6)
                }
                .buttonStyle(.borderedProminent)
                .disabled(!model.isHealthDataAvailable)

                Button("샘플 데이터로 둘러보기") {
                    model.demoMode = true
                }

                Text("건강 데이터는 기기 밖으로 전송하지 않습니다. 직접 기록한 식단만 건강 앱에 저장해요.")
                    .font(.footnote)
                    .foregroundStyle(.secondary)
                    .multilineTextAlignment(.center)
            }
        }
        .padding(24)
    }
}

private struct FeatureRow: View {
    let symbol: String
    let tint: Color
    let text: String

    var body: some View {
        Label {
            Text(text)
        } icon: {
            Image(systemName: symbol)
                .foregroundStyle(tint)
                .frame(width: 28)
        }
    }
}
