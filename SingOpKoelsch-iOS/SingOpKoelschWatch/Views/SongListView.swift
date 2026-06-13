import SwiftUI

@MainActor
final class SongListVM: ObservableObject {
    @Published var songs: [WatchSong] = []
    @Published var isLoading = false
    @Published var errorMessage: String?
    private var currentPage = 1
    private(set) var hasMore = false

    func load() async {
        guard !isLoading else { return }
        isLoading = true
        errorMessage = nil
        do {
            let page = try await WatchAPI.shared.songs(page: 1, perPage: 40)
            songs = page.songs
            currentPage = 1
            hasMore = page.pages > 1
        } catch {
            errorMessage = error.localizedDescription
        }
        isLoading = false
    }

    func loadMore() async {
        guard hasMore, !isLoading else { return }
        isLoading = true
        do {
            let page = try await WatchAPI.shared.songs(page: currentPage + 1, perPage: 40)
            songs.append(contentsOf: page.songs)
            currentPage += 1
            hasMore = currentPage < page.pages
        } catch {}
        isLoading = false
    }
}

struct SongListView: View {
    @StateObject private var vm = SongListVM()

    var body: some View {
        NavigationStack {
            Group {
                if vm.isLoading && vm.songs.isEmpty {
                    ProgressView().tint(Color.sok)
                } else if let err = vm.errorMessage, vm.songs.isEmpty {
                    errorView(err)
                } else {
                    songList
                }
            }
            .navigationTitle("Lieder")
        }
        .task { await vm.load() }
    }

    private var songList: some View {
        List {
            ForEach(vm.songs) { song in
                NavigationLink(destination: SongDetailView(songId: song.id, title: song.title)) {
                    VStack(alignment: .leading, spacing: 2) {
                        Text(song.title)
                            .font(.system(size: 13, weight: .semibold))
                            .foregroundStyle(.white)
                            .lineLimit(2)
                        Text(song.bandName)
                            .font(.system(size: 11))
                            .foregroundStyle(Color.sokSecondary)
                    }
                    .padding(.vertical, 1)
                }
                .listRowBackground(Color.sokRow)
                .onAppear {
                    if song.id == vm.songs.last?.id {
                        Task { await vm.loadMore() }
                    }
                }
            }
            if vm.isLoading {
                HStack { Spacer(); ProgressView().tint(Color.sok); Spacer() }
                    .listRowBackground(Color.sokRow)
            }
        }
    }

    private func errorView(_ message: String) -> some View {
        VStack(spacing: 8) {
            Image(systemName: "wifi.slash")
                .font(.title2)
                .foregroundStyle(Color.sok)
            Text(message)
                .font(.caption2)
                .foregroundStyle(.secondary)
                .multilineTextAlignment(.center)
            Button("Erneut") { Task { await vm.load() } }
                .foregroundStyle(Color.sok)
                .font(.caption)
        }
        .padding()
    }
}
