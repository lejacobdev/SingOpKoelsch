import SwiftUI

@MainActor
final class SongsViewModel: ObservableObject {
    @Published var songs: [Song] = []
    @Published var isLoading = false
    @Published var error: String?
    @Published var searchText = ""
    @Published var selectedBandId: Int?
    @Published var page = 1
    @Published var hasMore = true

    private let api = APIClient.shared
    private var searchTask: Task<Void, Never>?

    func load(reset: Bool = false) async {
        if reset { page = 1; songs = []; hasMore = true }
        guard hasMore, !isLoading else { return }
        isLoading = true; error = nil
        do {
            let result = try await api.songs(page: page, query: searchText, bandId: selectedBandId)
            if reset { songs = result.songs } else { songs += result.songs }
            hasMore = page < result.pages
            page += 1
        } catch {
            self.error = error.localizedDescription
        }
        isLoading = false
    }

    func onSearchChange() {
        searchTask?.cancel()
        searchTask = Task {
            try? await Task.sleep(for: .milliseconds(350))
            guard !Task.isCancelled else { return }
            await load(reset: true)
        }
    }
}

struct SongsView: View {
    @StateObject private var vm = SongsViewModel()
    @State private var showAddSong = false
    @EnvironmentObject var auth: AuthManager

    var body: some View {
        NavigationStack {
            content
                .background(Theme.bg.ignoresSafeArea())
                .navigationTitle("Lieder")
                .searchable(text: $vm.searchText, prompt: "Titel, Band, Text …")
                .onChange(of: vm.searchText) { vm.onSearchChange() }
                .toolbar {
                    ToolbarItem(placement: .topBarLeading) { KoelschLogo(size: 28) }
                    if auth.currentUser?.role == "admin" || auth.currentUser?.role == "trusted" {
                        ToolbarItem(placement: .topBarTrailing) {
                            Button { showAddSong = true } label: { Image(systemName: "plus") }
                        }
                    }
                }
                .sheet(isPresented: $showAddSong, onDismiss: { Task { await vm.load(reset: true) } }) {
                    AddEditSongView(mode: .add)
                }
        }
        .task { await vm.load() }
    }

    @ViewBuilder
    private var content: some View {
        if vm.songs.isEmpty && !vm.isLoading {
            EmptyStateView(
                icon: "music.note.list",
                title: vm.searchText.isEmpty ? "Keine Lieder" : "Keine Treffer",
                subtitle: vm.searchText.isEmpty ? "Bitte später erneut versuchen." : "Versuch einen anderen Suchbegriff."
            )
        } else {
            songList
        }
    }

    private var songList: some View {
        List {
            if let err = vm.error {
                ErrorBanner(message: err)
                    .listRowBackground(Color.clear)
                    .listRowInsets(.init())
            }
            ForEach(vm.songs) { song in
                NavigationLink(destination: SongDetailView(songId: song.id, title: song.title)) {
                    SongRowView(song: song)
                }
                .listRowInsets(EdgeInsets(top: 6, leading: 16, bottom: 6, trailing: 16))
                .listRowBackground(Theme.card)
                .onAppear {
                    if song.id == vm.songs.last?.id { Task { await vm.load() } }
                }
            }
            if vm.isLoading { LoadingRow() }
        }
        .listStyle(.plain)
        .scrollContentBackground(.hidden)
    }
}

struct SongRowView: View {
    let song: Song

    var body: some View {
        HStack(spacing: 12) {
            // Album art / placeholder
            AsyncImage(url: coverURL) { phase in
                switch phase {
                case .success(let img):
                    img.resizable().aspectRatio(contentMode: .fill)
                default:
                    ZStack {
                        Theme.koelschRed.opacity(0.1)
                        Image(systemName: "music.note")
                            .foregroundStyle(Theme.koelschRed.opacity(0.5))
                    }
                }
            }
            .frame(width: 52, height: 52)
            .clipShape(RoundedRectangle(cornerRadius: 8))

            VStack(alignment: .leading, spacing: 3) {
                Text(song.title)
                    .font(.headline)
                    .lineLimit(1)
                HStack(spacing: 4) {
                    Text(song.bandName).font(.subheadline).foregroundStyle(.secondary).lineLimit(1)
                    if !song.album.isEmpty {
                        Text("·").foregroundStyle(.secondary)
                        Text(song.album).font(.subheadline).foregroundStyle(.secondary).lineLimit(1)
                    }
                }
            }

            Spacer()

            HStack(spacing: 6) {
                if song.hasSpotify {
                    Image(systemName: "music.note.list")
                        .font(.caption2)
                        .foregroundStyle(.green)
                }
                if song.hasVideo {
                    Image(systemName: "play.rectangle.fill")
                        .font(.caption2)
                        .foregroundStyle(.red)
                }
                if !song.hasLyrics {
                    Image(systemName: "text.badge.xmark")
                        .font(.caption2)
                        .foregroundStyle(.secondary)
                }
            }
        }
        .padding(.vertical, 4)
    }

    private var coverURL: URL? {
        guard !song.coverUrl.isEmpty else { return nil }
        let s = song.coverUrl.hasPrefix("http") ? song.coverUrl : "https://singopkoelsch.de/\(song.coverUrl)"
        return URL(string: s)
    }
}
