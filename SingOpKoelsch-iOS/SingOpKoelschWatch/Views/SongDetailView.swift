import SwiftUI

@MainActor
final class SongDetailVM: ObservableObject {
    @Published var detail: WatchSongDetail?
    @Published var isLoading = true
    @Published var errorMessage: String?

    func load(id: Int) async {
        isLoading = true
        errorMessage = nil
        do {
            detail = try await WatchAPI.shared.songDetail(id: id)
        } catch {
            errorMessage = error.localizedDescription
        }
        isLoading = false
    }
}

struct SongDetailView: View {
    let songId: Int
    let title: String
    @StateObject private var vm = SongDetailVM()

    var body: some View {
        Group {
            if vm.isLoading {
                ProgressView().tint(Color.sok)
            } else if let err = vm.errorMessage {
                VStack(spacing: 6) {
                    Image(systemName: "exclamationmark.triangle")
                        .foregroundStyle(Color.sok)
                    Text(err)
                        .font(.caption2)
                        .foregroundStyle(.secondary)
                        .multilineTextAlignment(.center)
                }
            } else if let d = vm.detail {
                ScrollView {
                    VStack(alignment: .leading, spacing: 8) {
                        Text(d.bandName)
                            .font(.system(size: 11, weight: .medium))
                            .foregroundStyle(Color.sokSecondary)

                        Divider()

                        let text = d.cleanLyrics.isEmpty
                            ? "Kein Liedtext verfügbar."
                            : d.cleanLyrics
                        Text(text)
                            .font(.system(size: 12))
                            .foregroundStyle(.white.opacity(0.9))
                            .frame(maxWidth: .infinity, alignment: .leading)
                    }
                    .padding(.horizontal, 2)
                    .padding(.bottom, 20)
                }
            }
        }
        .navigationTitle(title)
        .navigationBarTitleDisplayMode(.inline)
        .task { await vm.load(id: songId) }
    }
}
