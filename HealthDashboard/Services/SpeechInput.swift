import AVFoundation
import Observation
import Speech

/// 말로 질문하기: 한국어 음성을 실시간으로 글자로 바꾼다. (지원되면 기기 안에서만 처리)
@MainActor
@Observable
final class SpeechInput {
    private(set) var transcript = ""
    private(set) var isRecording = false
    var errorMessage: String?

    @ObservationIgnored private let recognizer = SFSpeechRecognizer(locale: Locale(identifier: "ko-KR"))
    @ObservationIgnored private let engine = AVAudioEngine()
    @ObservationIgnored private var request: SFSpeechAudioBufferRecognitionRequest?
    @ObservationIgnored private var task: SFSpeechRecognitionTask?

    func toggle() async {
        if isRecording {
            stop()
        } else {
            await start()
        }
    }

    func start() async {
        errorMessage = nil
        let status = await withCheckedContinuation { continuation in
            SFSpeechRecognizer.requestAuthorization { continuation.resume(returning: $0) }
        }
        guard status == .authorized else {
            errorMessage = "설정 › 개인정보 보호 › 음성 인식에서 허용해 주세요."
            return
        }
        guard await AVAudioApplication.requestRecordPermission() else {
            errorMessage = "설정에서 마이크 사용을 허용해 주세요."
            return
        }
        guard let recognizer, recognizer.isAvailable else {
            errorMessage = "지금은 음성 인식을 사용할 수 없어요."
            return
        }

        do {
            let session = AVAudioSession.sharedInstance()
            try session.setCategory(.record, mode: .measurement, options: .duckOthers)
            try session.setActive(true, options: .notifyOthersOnDeactivation)

            let request = SFSpeechAudioBufferRecognitionRequest()
            request.shouldReportPartialResults = true
            request.requiresOnDeviceRecognition = recognizer.supportsOnDeviceRecognition
            self.request = request

            let input = engine.inputNode
            input.removeTap(onBus: 0)
            input.installTap(onBus: 0, bufferSize: 1024, format: input.outputFormat(forBus: 0)) { buffer, _ in
                request.append(buffer)
            }
            engine.prepare()
            try engine.start()

            transcript = ""
            isRecording = true
            task = recognizer.recognitionTask(with: request) { [weak self] result, error in
                let text = result?.bestTranscription.formattedString
                let isFinal = result?.isFinal ?? false
                Task { @MainActor in
                    guard let self else { return }
                    if let text { self.transcript = text }
                    if error != nil || isFinal { self.stop() }
                }
            }
        } catch {
            errorMessage = "음성 인식을 시작하지 못했어요."
            stop()
        }
    }

    func stop() {
        guard isRecording || engine.isRunning else { return }
        engine.stop()
        engine.inputNode.removeTap(onBus: 0)
        request?.endAudio()
        task?.finish()
        request = nil
        task = nil
        isRecording = false
        try? AVAudioSession.sharedInstance().setActive(false, options: .notifyOthersOnDeactivation)
    }
}
