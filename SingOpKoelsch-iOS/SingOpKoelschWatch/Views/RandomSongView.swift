import SwiftUI

@MainActor
final class RandomSongVM: ObservableObject {
    @Published var song: WatchSongDetail?
    @Published var isLoading = false
    @Published var errorMessage: String?
    private var allIds: [Int] = []

    func loadRandom() async {
        guard !isLoading else { return }
        isLoading = true
        errorMessage = nil
        do {
            if allIds.isEmpty {
                let page = try await WatchAPI.shared.songs(page: 1, perPage: 100)
                allIds = page.songs.map { $0.id }
            }
            if let id = allIds.randomElement() {
                song = try await WatchAPI.shared.songDetail(id: id)
            }
        } catch {
            errorMessage = error.localizedDescription
        }
        isLoading = false
    }
}

struct RandomSongView: View {
    @StateObject private var vm = RandomSongVM()

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 10) {
                    if vm.isLoading {
                        HStack { Spacer(); ProgressView().tint(Color.sok); Spacer() }
                            .padding()
                    } else if let song = vm.song {
                        VStack(alignment: .leading, spacing: 4) {
                            Text(song.title)
                                .font(.system(size: 14, weight: .bold))
                                .foregroundStyle(.white)
                            Text(song.bandName)
                                .font(.system(size: 11))
                                .foregroundStyle(Color.sokSecondary)
                            Divider()
                            let text = song.cleanLyrics.isEmpty
                                ? "Kein Text verfügbar."
                                : song.cleanLyrics
                            Text(text)
                                .font(.system(size: 11))
                                .foregroundStyle(.white.opacity(0.85))
                                .frame(maxWidth: .infinity, alignment: .leading)
                        }
                    } else if let err = vm.errorMessage {
                        Text(err)
                            .font(.caption2)
                            .foregroundStyle(.secondary)
                            .multilineTextAlignment(.center)
                            .frame(maxWidth: .infinity)
                    } else {
                        Text("Neues Lied zufällig laden")
                            .font(.system(size: 12))
                            .foregroundStyle(.secondary)
                            .multilineTextAlignment(.center)
                            .frame(maxWidth: .infinity)
                            .padding()
                    }

                    Button {
                        Task { await vm.loadRandom() }
                    } label: {
                        Label("Zufallslied", systemImage: "shuffle")
                            .font(.system(size: 13, weight: .semibold))
                            .frame(maxWidth: .infinity)
                    }
                    .buttonStyle(.borderedProminent)
                    .tint(Color.sok)
                    .disabled(vm.isLoading)
                }
                .padding(.horizontal, 2)
            }
            .navigationTitle("Zufall")
        }
        .task { await vm.loadRandom() }
    }
}
