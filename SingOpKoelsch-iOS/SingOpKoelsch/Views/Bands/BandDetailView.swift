import SwiftUI

@MainActor
final class BandDetailViewModel: ObservableObject {
    @Published var band: Band?
    @Published var isLoading = false
    @Published var error: String?
    private let api = APIClient.shared

    func load(id: Int) async {
        isLoading = true; error = nil
        do { band = try await api.bandDetail(id: id) }
        catch { self.error = error.localizedDescription }
        isLoading = false
    }
}

struct BandDetailView: View {
    let bandId: Int
    let bandName: String
    @StateObject private var vm = BandDetailViewModel()

    var body: some View {
        Group {
            if vm.isLoading {
                ProgressView().tint(Theme.primary)
                    .frame(maxWidth: .infinity, maxHeight: .infinity)
            } else if let band = vm.band {
                List {
                    Section {
                        HStack {
                            Spacer()
                            VStack(spacing: 8) {
                                ZStack {
                                    Circle().fill(Theme.koelschRed.opacity(0.1)).frame(width: 72, height: 72)
                                    Text(String(band.bandName.prefix(1)).uppercased())
                                        .font(.system(.largeTitle, design: .default).bold())
                                        .foregroundStyle(Theme.koelschRed)
                                }
                                Text("\(band.songCount) Lieder")
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                            }
                            .padding(.vertical, 8)
                            Spacer()
                        }
                    }
                    .listRowBackground(Color.clear)

                    if let songs = band.songs, !songs.isEmpty {
                        Section("Lieder") {
                            ForEach(songs) { song in
                                NavigationLink(destination: SongDetailView(songId: song.id, title: song.title)) {
                                    HStack(spacing: 10) {
                                        AsyncImage(url: coverURL(song)) { phase in
                                            switch phase {
                                            case .success(let img): img.resizable().aspectRatio(contentMode: .fill)
                                            default: Theme.koelschRed.opacity(0.08)
                                            }
                                        }
                                        .frame(width: 40, height: 40)
                                        .clipShape(RoundedRectangle(cornerRadius: 6))

                                        VStack(alignment: .leading, spacing: 2) {
                                            Text(song.title).font(.subheadline.bold()).lineLimit(1)
                                            if !song.album.isEmpty {
                                                Text(song.album + (song.releaseYear > 0 ? " · \(song.releaseYear)" : ""))
                                                    .font(.caption).foregroundStyle(.secondary).lineLimit(1)
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
                .listStyle(.insetGrouped)
                .scrollContentBackground(.hidden)
                .listRowBackground(Theme.card)
            } else if let err = vm.error {
                ErrorBanner(message: err)
            }
        }
        .background(Theme.bg.ignoresSafeArea())
        .navigationTitle(bandName)
        .task { await vm.load(id: bandId) }
    }

    private func coverURL(_ song: Song) -> URL? {
        guard !song.coverUrl.isEmpty else { return nil }
        let s = song.coverUrl.hasPrefix("http") ? song.coverUrl : "https://singopkoelsch.de/\(song.coverUrl)"
        return URL(string: s)
    }
}
