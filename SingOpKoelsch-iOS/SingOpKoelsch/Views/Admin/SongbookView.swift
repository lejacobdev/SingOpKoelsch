import SwiftUI
import UniformTypeIdentifiers

@MainActor
final class SongbookViewModel: ObservableObject {
    @Published var allSongs: [Song] = []
    @Published var selectedIds: Set<Int> = []
    @Published var search = ""
    @Published var isLoadingSongs = false
    @Published var isExporting = false
    @Published var exportedFileURL: URL?
    @Published var error: String?

    private let api = APIClient.shared

    var filtered: [Song] {
        if search.isEmpty { return allSongs }
        return allSongs.filter {
            $0.title.localizedCaseInsensitiveContains(search) ||
            $0.bandName.localizedCaseInsensitiveContains(search)
        }
    }

    func loadSongs() async {
        isLoadingSongs = true
        do {
            var all: [Song] = []
            var page = 1
            while true {
                let page_result = try await api.songs(page: page)
                all += page_result.songs
                if page >= page_result.pages { break }
                page += 1
            }
            allSongs = all
        } catch { self.error = error.localizedDescription }
        isLoadingSongs = false
    }

    func export() async {
        guard !selectedIds.isEmpty else { return }
        isExporting = true; error = nil
        do {
            let result = try await api.exportSongbook(songIds: Array(selectedIds))
            guard let data = Data(base64Encoded: result.content) else {
                error = "Dekodierungsfehler"; isExporting = false; return
            }
            let tmp = FileManager.default.temporaryDirectory.appendingPathComponent(result.filename)
            try data.write(to: tmp)
            exportedFileURL = tmp
        } catch { self.error = error.localizedDescription }
        isExporting = false
    }

    func toggleAll() {
        if selectedIds.count == allSongs.count {
            selectedIds.removeAll()
        } else {
            selectedIds = Set(allSongs.map(\.id))
        }
    }
}

struct SongbookView: View {
    @StateObject private var vm = SongbookViewModel()
    @State private var showShareSheet = false

    var body: some View {
        NavigationStack {
            Group {
                if vm.isLoadingSongs {
                    ProgressView("Lade Lieder…").tint(Theme.koelschRed)
                        .frame(maxWidth: .infinity, maxHeight: .infinity)
                } else {
                    List {
                        if let err = vm.error { ErrorBanner(message: err).listRowBackground(Color.clear).listRowInsets(.init()) }

                        Section {
                            HStack {
                                Text("\(vm.selectedIds.count) ausgewaehlt")
                                    .font(.subheadline)
                                    .foregroundStyle(.secondary)
                                Spacer()
                                Button(vm.selectedIds.count == vm.allSongs.count ? "Alle abwaehlen" : "Alle auswaehlen") {
                                    vm.toggleAll()
                                }
                                .font(.subheadline)
                                .foregroundStyle(Theme.koelschRed)
                            }
                        }

                        ForEach(vm.filtered) { song in
                            Button {
                                if vm.selectedIds.contains(song.id) {
                                    vm.selectedIds.remove(song.id)
                                } else {
                                    vm.selectedIds.insert(song.id)
                                }
                            } label: {
                                HStack {
                                    Image(systemName: vm.selectedIds.contains(song.id) ? "checkmark.circle.fill" : "circle")
                                        .foregroundStyle(vm.selectedIds.contains(song.id) ? Theme.koelschRed : .secondary)
                                    VStack(alignment: .leading, spacing: 2) {
                                        Text(song.title).font(.subheadline.bold()).foregroundStyle(.primary)
                                        Text(song.bandName).font(.caption).foregroundStyle(.secondary)
                                    }
                                }
                            }
                        }
                    }
                    .listStyle(.insetGrouped)
                    .searchable(text: $vm.search, prompt: "Suchen…")
                }
            }
            .navigationTitle("Liederbuch")
            .toolbar {
                ToolbarItem(placement: .topBarTrailing) {
                    Button {
                        Task { await vm.export() }
                    } label: {
                        if vm.isExporting {
                            ProgressView().tint(Theme.koelschRed)
                        } else {
                            Label("Exportieren", systemImage: "square.and.arrow.up")
                        }
                    }
                    .disabled(vm.selectedIds.isEmpty || vm.isExporting)
                }
            }
            .onChange(of: vm.exportedFileURL) { _, url in
                if url != nil { showShareSheet = true }
            }
            .sheet(isPresented: $showShareSheet, onDismiss: { vm.exportedFileURL = nil }) {
                if let url = vm.exportedFileURL {
                    ShareSheet(url: url)
                }
            }
        }
        .task { await vm.loadSongs() }
    }
}

struct ShareSheet: UIViewControllerRepresentable {
    let url: URL
    func makeUIViewController(context: Context) -> UIActivityViewController {
        UIActivityViewController(activityItems: [url], applicationActivities: nil)
    }
    func updateUIViewController(_ uiViewController: UIActivityViewController, context: Context) {}
}
