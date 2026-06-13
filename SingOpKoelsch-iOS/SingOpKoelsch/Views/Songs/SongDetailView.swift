import SwiftUI

@MainActor
final class SongDetailViewModel: ObservableObject {
    @Published var song: SongDetail?
    @Published var isLoading = false
    @Published var error: String?

    private let api = APIClient.shared

    func load(id: Int) async {
        isLoading = true; error = nil
        do { song = try await api.songDetail(id: id) }
        catch { self.error = error.localizedDescription }
        isLoading = false
    }
}

struct SongDetailView: View {
    let songId: Int
    let title: String

    @StateObject private var vm = SongDetailViewModel()
    @State private var showPropose  = false
    @State private var showEdit     = false
    @State private var showDelete   = false
    @EnvironmentObject var auth: AuthManager
    @Environment(\.dismiss) var dismiss
    private let api = APIClient.shared

    var body: some View {
        ScrollView {
            if vm.isLoading {
                ProgressView().tint(Theme.primary)
                    .padding(60)
                    .frame(maxWidth: .infinity)
            } else if let song = vm.song {
                VStack(alignment: .leading, spacing: 0) {
                    // Hero cover
                    HeroHeader(song: song)

                    // Meta
                    VStack(alignment: .leading, spacing: 16) {
                        // Band / album info
                        VStack(alignment: .leading, spacing: 4) {
                            Text(song.bandName)
                                .font(.title3.bold())
                                .foregroundStyle(Theme.koelschRed)
                            if !song.album.isEmpty {
                                HStack(spacing: 6) {
                                    Image(systemName: "opticaldisc")
                                        .font(.caption)
                                        .foregroundStyle(.secondary)
                                    Text(song.album + (song.releaseYear > 0 ? " (\(song.releaseYear))" : ""))
                                        .font(.subheadline)
                                        .foregroundStyle(.secondary)
                                }
                            }
                        }

                        // Links
                        if !song.spotifyLink.isEmpty || !song.videoLink.isEmpty {
                            HStack(spacing: 12) {
                                if let url = URL(string: song.spotifyLink), !song.spotifyLink.isEmpty {
                                    Link(destination: url) {
                                        Label("Spotify", systemImage: "music.note")
                                            .font(.subheadline.bold())
                                            .padding(.horizontal, 14)
                                            .padding(.vertical, 8)
                                            .background(Color.green.opacity(0.15))
                                            .foregroundStyle(.green)
                                            .clipShape(Capsule())
                                    }
                                }
                                if let url = URL(string: song.videoLink), !song.videoLink.isEmpty {
                                    Link(destination: url) {
                                        Label("Video", systemImage: "play.circle")
                                            .font(.subheadline.bold())
                                            .padding(.horizontal, 14)
                                            .padding(.vertical, 8)
                                            .background(Color.red.opacity(0.12))
                                            .foregroundStyle(.red)
                                            .clipShape(Capsule())
                                    }
                                }
                            }
                        }

                        Divider()

                        // Lyrics
                        if song.lyrics.isEmpty {
                            HStack {
                                Image(systemName: "text.badge.xmark")
                                    .foregroundStyle(.secondary)
                                Text("Kein Text vorhanden")
                                    .foregroundStyle(.secondary)
                            }
                            .font(.subheadline)
                        } else {
                            Text(song.lyrics)
                                .font(Theme.lyricsFont)
                                .lineSpacing(6)
                                .textSelection(.enabled)
                        }

                        // My proposals
                        if !song.myProposals.isEmpty {
                            Divider()
                            VStack(alignment: .leading, spacing: 8) {
                                Text("Meine Vorschläge").font(.headline)
                                ForEach(song.myProposals) { p in
                                    HStack {
                                        StatusBadge(status: p.status)
                                        Text(p.createdAt.prefix(10))
                                            .font(.caption)
                                            .foregroundStyle(.secondary)
                                    }
                                }
                            }
                        }
                    }
                    .padding(Theme.padding)
                    .cardStyle()
                    .padding(.horizontal)
                    .padding(.vertical, 12)
                }
            } else if let err = vm.error {
                ErrorBanner(message: err).padding(.top, 40)
            }
        }
        .background(Theme.bg.ignoresSafeArea())
        .navigationTitle(title)
        .navigationBarTitleDisplayMode(.inline)
        .toolbar {
            ToolbarItem(placement: .topBarTrailing) {
                Menu {
                    Button { showPropose = true } label: {
                        Label("Textvorschlag", systemImage: "square.and.pencil")
                    }
                    .disabled(vm.song == nil)

                    if auth.currentUser?.role == "admin" || auth.currentUser?.role == "trusted" {
                        Divider()
                        Button { showEdit = true } label: {
                            Label("Bearbeiten", systemImage: "pencil")
                        }
                    }
                    if auth.currentUser?.isAdmin == true {
                        Button(role: .destructive) { showDelete = true } label: {
                            Label("Loeschen", systemImage: "trash")
                        }
                    }
                } label: {
                    Image(systemName: "ellipsis.circle")
                }
                .disabled(vm.song == nil)
            }
        }
        .sheet(isPresented: $showPropose, onDismiss: { Task { await vm.load(id: songId) } }) {
            if let song = vm.song { ProposeChangeView(song: song) }
        }
        .sheet(isPresented: $showEdit, onDismiss: { Task { await vm.load(id: songId) } }) {
            if let song = vm.song { AddEditSongView(mode: .edit(song)) }
        }
        .alert("Song loeschen?", isPresented: $showDelete) {
            Button("Abbrechen", role: .cancel) {}
            Button("Loeschen", role: .destructive) {
                Task {
                    _ = try? await api.deleteSong(id: songId)
                    dismiss()
                }
            }
        } message: {
            Text("Dieser Song wird dauerhaft geloescht.")
        }
        .task { await vm.load(id: songId) }
    }
}

private struct HeroHeader: View {
    let song: SongDetail

    var body: some View {
        ZStack(alignment: .bottomLeading) {
            AsyncImage(url: coverURL) { phase in
                switch phase {
                case .success(let img):
                    img.resizable().aspectRatio(contentMode: .fill)
                default:
                    Theme.koelschRed.opacity(0.12)
                }
            }
            .frame(maxWidth: .infinity)
            .frame(height: 220)
            .clipped()

            LinearGradient(
                colors: [.clear, Theme.bg],
                startPoint: .top, endPoint: .bottom
            )
            .frame(height: 100)
            .frame(maxWidth: .infinity)
            .padding(.top, 120)

            Text(song.title)
                .font(.system(.title, design: .default).bold())
                .padding(.horizontal, Theme.padding)
                .padding(.bottom, 12)
        }
    }

    private var coverURL: URL? {
        guard !song.coverUrl.isEmpty else { return nil }
        let s = song.coverUrl.hasPrefix("http") ? song.coverUrl : "https://singopkoelsch.de/\(song.coverUrl)"
        return URL(string: s)
    }
}
