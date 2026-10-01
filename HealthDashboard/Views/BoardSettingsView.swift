import SwiftUI

/// 설정 › 가족 전광판 (시놀로지 NAS)
struct BoardSettingsView: View {
    @State private var publisher = BoardPublisher.shared
    @State private var token = BoardPublisher.shared.token ?? ""
    @State private var copied = false

    @AppStorage(BoardPublisher.Keys.serverURL) private var serverURL = ""
    @AppStorage(BoardPublisher.Keys.member) private var member = BoardPublisher.Member.dad.rawValue
    @AppStorage(BoardPublisher.Keys.name) private var name = ""
    @AppStorage(BoardPublisher.Keys.showReadiness) private var showReadiness = true
    @AppStorage(BoardPublisher.Keys.showSteps) private var showSteps = true
    @AppStorage(BoardPublisher.Keys.showSleep) private var showSleep = true
    @AppStorage(BoardPublisher.Keys.showWater) private var showWater = true
    @AppStorage(BoardPublisher.Keys.sendMeals) private var sendMeals = true

    var body: some View {
        Form {
            Section {
                TextField("예: 192.168.0.10", text: $serverURL)
                    .keyboardType(.URL)
                    .textInputAutocapitalization(.never)
                    .autocorrectionDisabled()
                SecureField("전광판 토큰 (config.php 의 token)", text: $token)
                    .textInputAutocapitalization(.never)
                    .autocorrectionDisabled()
                    .onChange(of: token) { _, value in publisher.token = value }
            } header: {
                Text("NAS 연결")
            } footer: {
                Text("집 안에서 쓰는 NAS 주소(IP)를 적어 주세요. 집 와이파이에 있을 때 자동으로 보내고, 밖에 있을 때는 집에 오면 보내요.")
            }

            Section {
                Picker("이 아이폰은", selection: $member) {
                    ForEach(BoardPublisher.Member.allCases) { Text("\($0.emoji) \($0.title)").tag($0.rawValue) }
                }
                TextField("전광판에 보일 이름 (비우면 아빠/엄마)", text: $name)
            } header: {
                Text("누구의 아이폰인가요")
            } footer: {
                Text("부부가 각자 다르게 골라야 전광판에 두 사람 정보가 함께 보여요.")
            }

            Section {
                Toggle("준비 점수", isOn: $showReadiness)
                Toggle("걸음 수", isOn: $showSteps)
                Toggle("수면 시간", isOn: $showSleep)
                Toggle("물 섭취", isOn: $showWater)
                Toggle("식단 기록을 NAS DB에 저장", isOn: $sendMeals)
            } header: {
                Text("보낼 정보")
            } footer: {
                Text("일정 · 저녁 계획 · 장보기 · 지역은 항상 보내요. 건강 정보는 켠 것만 전광판에 보이고 DB(daily_health)에 날짜별로 쌓여요. 식단은 최근 7일을 앱과 똑같이 맞춰 저장해요.")
            }

            Section {
                Button {
                    Task { await publisher.publishNow() }
                } label: {
                    HStack {
                        Label("지금 보내기 (연결 테스트)", systemImage: "paperplane.fill")
                        Spacer()
                        if publisher.isSending { ProgressView() }
                    }
                }
                .disabled(publisher.isSending || serverURL.isEmpty || token.isEmpty)

                if let error = publisher.lastError {
                    Label(error, systemImage: "exclamationmark.triangle.fill")
                        .font(.caption)
                        .foregroundStyle(.orange)
                } else if let sent = publisher.lastSent {
                    Label("마지막 전송 \(sent.formatted(date: .abbreviated, time: .shortened))", systemImage: "checkmark.circle.fill")
                        .font(.caption)
                        .foregroundStyle(.green)
                }
            }

            if let address = publisher.boardAddress {
                Section {
                    HStack {
                        Text(address)
                            .font(.callout.monospaced())
                            .textSelection(.enabled)
                        Spacer()
                        Button(copied ? "복사됨" : "복사") {
                            UIPasteboard.general.string = address
                            copied = true
                        }
                    }
                } header: {
                    Text("아이패드에서 열 주소")
                } footer: {
                    Text("아이패드 사파리에서 이 주소를 열고 공유 › 홈 화면에 추가를 누르면 전체 화면 전광판이 돼요. 설정 › 디스플레이 › 자동 잠금 › 안 함으로 두세요.")
                }
            }
        }
        .navigationTitle("가족 전광판")
        .navigationBarTitleDisplayMode(.inline)
    }
}
