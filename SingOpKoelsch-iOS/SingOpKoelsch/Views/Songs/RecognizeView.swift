import SwiftUI
import ShazamKit
import AVFoundation

@MainActor
final class RecognizeViewModel: NSObject, ObservableObject, SHSessionDelegate {
    @Published var state: RecognizeState = .idle
    @Published var match: SHMediaItem?
    @Published var dbMatch: APIClient.RecognizeMatch?

    private var session    = SHSession()
    private var audioEngine = AVAudioEngine()
    private var inputNode: AVAudioInputNode { audioEngine.inputNode }

    enum RecognizeState: Equatable { case idle, listening, matched, noMatch, error(String) }

    override init() {
        super.init()
        session.delegate = self
    }

    func startListening() {
        guard state == .idle else { return }
        AVAudioApplication.requestRecordPermission { [weak self] granted in
            Task { @MainActor [weak self] in
                guard let self else { return }
                guard granted else { self.state = .error("Mikrofonzugriff verweigert"); return }
                self.beginCapture()
            }
        }
    }

    func stop() {
        audioEngine.stop()
        inputNode.removeTap(onBus: 0)
        state = .idle
    }

    private func beginCapture() {
        state = .listening
        let format = inputNode.outputFormat(forBus: 0)
        inputNode.installTap(onBus: 0, bufferSize: 8192, format: format) { [weak self] buffer, time in
            self?.session.matchStreamingBuffer(buffer, at: time)
        }
        do {
            try AVAudioSession.sharedInstance().setCategory(.record)
            try AVAudioSession.sharedInstance().setActive(true)
            try audioEngine.start()
        } catch {
            state = .error(error.localizedDescription)
        }
    }

    // MARK: SHSessionDelegate

    nonisolated func session(_ session: SHSession, didFind match: SHMatch) {
        Task { @MainActor [weak self] in
            guard let self, let item = match.mediaItems.first else { return }
            self.stop()
            self.match = item
            self.state = .matched
            // Try to find in local DB
            if let title = item.title {
                do {
                    let result = try await APIClient.shared.songs(query: title)
                    self.dbMatch = result.songs.first.map {
                        APIClient.RecognizeMatch(id: $0.id, title: $0.title, bandName: $0.bandName)
                    }
                } catch {}
            }
        }
    }

    nonisolated func session(_ session: SHSession, didNotFindMatchFor signature: SHSignature, error: Error?) {
        Task { @MainActor [weak self] in
            self?.stop()
            self?.state = .noMatch
        }
    }
}

struct RecognizeView: View {
    var onNavigate: ((Int) -> Void)? = nil
    @StateObject private var vm = RecognizeViewModel()
    @Environment(\.dismiss) var dismiss
    @State private var navigateToSong: (id: Int, title: String)?

    var body: some View {
        NavigationStack {
            VStack(spacing: 0) {
                Spacer()

                // Animated circle
                ZStack {
                    ForEach(0..<3, id: \.self) { i in
                        Circle()
                            .stroke(Theme.koelschRed.opacity(isListening ? 0.3 - Double(i)*0.08 : 0), lineWidth: 1.5)
                            .frame(width: CGFloat(100 + i*40), height: CGFloat(100 + i*40))
                            .scaleEffect(isListening ? 1.0 : 0.8)
                            .animation(
                                isListening ? .easeInOut(duration: 1.2).repeatForever().delay(Double(i)*0.3) : .default,
                                value: isListening
                            )
                    }
                    Button(action: handleTap) {
                        ZStack {
                            Circle()
                                .fill(isListening ? Theme.koelschRed : Theme.bgAlt)
                                .frame(width: 100, height: 100)
                            Image(systemName: isListening ? "waveform" : "shazam.logo.fill")
                                .font(.system(size: 40))
                                .foregroundStyle(isListening ? .white : Theme.koelschRed)
                                .symbolEffect(.variableColor.iterative, isActive: isListening)
                        }
                    }
                    .buttonStyle(.plain)
                }
                .frame(height: 240)

                Spacer().frame(height: 32)

                // Status
                Group {
                    switch vm.state {
                    case .idle:
                        VStack(spacing: 8) {
                            Text("Song erkennen")
                                .font(.title2.bold())
                            Text("Tippe und halte das Mikrofon neben die Musik.")
                                .font(.subheadline)
                                .foregroundStyle(.secondary)
                                .multilineTextAlignment(.center)
                        }

                    case .listening:
                        VStack(spacing: 8) {
                            Text("Hore zu …")
                                .font(.title2.bold())
                            Text("Halte das Geraet naher an die Musikquelle.")
                                .font(.subheadline)
                                .foregroundStyle(.secondary)
                                .multilineTextAlignment(.center)
                            Button("Abbrechen") { vm.stop() }
                                .foregroundStyle(Theme.koelschRed)
                                .padding(.top, 8)
                        }

                    case .matched:
                        if let item = vm.match {
                            VStack(spacing: 12) {
                                if let art = item.artworkURL {
                                    AsyncImage(url: art) { img in img.resizable().aspectRatio(contentMode: .fill) }
                                placeholder: { Color.gray.opacity(0.2) }
                                    .frame(width: 100, height: 100)
                                    .clipShape(RoundedRectangle(cornerRadius: 12))
                            }
                                Text(item.title ?? "Unbekannter Titel").font(.title3.bold())
                                Text(item.artist ?? "").font(.subheadline).foregroundStyle(.secondary)

                                if let db = vm.dbMatch {
                                    Button {
                                        dismiss()
                                        onNavigate?(db.id)
                                    } label: {
                                        Label("In Sing op Kölsch öffnen", systemImage: "arrow.right.circle.fill")
                                            .font(.subheadline.bold())
                                    }
                                    .buttonStyle(PrimaryButtonStyle())
                                    .padding(.horizontal, 40)
                                } else {
                                    Text("Nicht in der Datenbank")
                                        .font(.caption)
                                        .foregroundStyle(.secondary)
                                }

                                Button("Erneut suchen") { vm.state = .idle }
                                    .foregroundStyle(Theme.koelschRed)
                                    .padding(.top, 4)
                            }
                        }

                    case .noMatch:
                        VStack(spacing: 8) {
                            Image(systemName: "questionmark.circle")
                                .font(.system(size: 44))
                                .foregroundStyle(.secondary)
                            Text("Kein Treffer")
                                .font(.title3.bold())
                            Text("Versuch es nochmal mit mehr Musik.")
                                .font(.subheadline)
                                .foregroundStyle(.secondary)
                            Button("Nochmal") { vm.state = .idle }
                                .foregroundStyle(Theme.koelschRed)
                                .padding(.top, 8)
                        }

                    case .error(let msg):
                        VStack(spacing: 8) {
                            Image(systemName: "exclamationmark.circle")
                                .font(.system(size: 44))
                                .foregroundStyle(.red)
                            Text(msg)
                                .multilineTextAlignment(.center)
                            Button("Zurueck") { vm.state = .idle }
                                .foregroundStyle(Theme.koelschRed)
                        }
                    }
                }
                .padding(.horizontal, 32)

                Spacer()
            }
            .navigationTitle("Erkennung")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .topBarTrailing) {
                    Button("Fertig") { dismiss() }
                        .foregroundStyle(Theme.koelschRed)
                }
            }
        }
    }

    private var isListening: Bool {
        if case .listening = vm.state { return true }
        return false
    }

    private func handleTap() {
        switch vm.state {
        case .idle:    vm.startListening()
        case .matched, .noMatch, .error: vm.state = .idle
        default: break
        }
    }
}
