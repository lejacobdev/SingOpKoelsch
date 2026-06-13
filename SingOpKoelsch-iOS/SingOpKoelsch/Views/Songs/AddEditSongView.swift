import SwiftUI

struct AddEditSongView: View {
    enum Mode { case add; case edit(SongDetail) }
    let mode: Mode
    var onSaved: (() -> Void)?

    @Environment(\.dismiss) var dismiss
    @State private var title       = ""
    @State private var lyrics      = ""
    @State private var album       = ""
    @State private var spotifyLink = ""
    @State private var videoLink   = ""
    @State private var releaseYear = ""
    @State private var bands: [Band] = []
    @State private var selectedBandId: Int? = nil
    @State private var newBandName  = ""
    @State private var addingBand   = false
    @State private var loading      = false
    @State private var error        = ""

    private let api = APIClient.shared

    var isEditing: Bool { if case .edit = mode { return true }; return false }

    var body: some View {
        NavigationStack {
            Form {
                Section("Titel & Kuenstler") {
                    TextField("Titel", text: $title)
                    Picker("Band", selection: $selectedBandId) {
                        Text("– Keine Band –").tag(nil as Int?)
                        ForEach(bands) { b in
                            Text(b.bandName).tag(b.bandId as Int?)
                        }
                    }
                    if addingBand {
                        HStack {
                            TextField("Neuer Bandname", text: $newBandName)
                            Button("Hinzufuegen") { addingBand = false }
                                .foregroundStyle(Theme.koelschRed)
                        }
                    } else {
                        Button("+ Neue Band anlegen") { addingBand = true }
                            .font(.subheadline)
                            .foregroundStyle(Theme.koelschRed)
                    }
                }

                Section("Album & Jahr") {
                    TextField("Album", text: $album)
                    TextField("Erscheinungsjahr", text: $releaseYear)
                        .keyboardType(.numberPad)
                }

                Section("Links") {
                    TextField("Spotify-Link", text: $spotifyLink)
                        .keyboardType(.URL)
                        .autocapitalization(.none)
                    TextField("Video-Link", text: $videoLink)
                        .keyboardType(.URL)
                        .autocapitalization(.none)
                }

                Section("Text") {
                    TextEditor(text: $lyrics)
                        .font(Theme.lyricsFont)
                        .frame(minHeight: 200)
                }

                if !error.isEmpty {
                    Section {
                        Text(error).foregroundStyle(.red).font(.subheadline)
                    }
                }
            }
            .navigationTitle(isEditing ? "Song bearbeiten" : "Song hinzufuegen")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .topBarLeading) {
                    Button("Abbrechen") { dismiss() }
                        .foregroundStyle(Theme.koelschRed)
                }
                ToolbarItem(placement: .topBarTrailing) {
                    Button(isEditing ? "Speichern" : "Hinzufuegen") {
                        Task { await save() }
                    }
                    .fontWeight(.semibold)
                    .foregroundStyle(Theme.koelschRed)
                    .disabled(loading || title.trimmingCharacters(in: .whitespaces).isEmpty)
                }
            }
            .task { await loadBands() }
        }
        .onAppear { prefill() }
    }

    private func prefill() {
        if case .edit(let song) = mode {
            title       = song.title
            lyrics      = song.lyrics
            album       = song.album
            spotifyLink = song.spotifyLink
            videoLink   = song.videoLink
            releaseYear = song.releaseYear > 0 ? "\(song.releaseYear)" : ""
            selectedBandId = song.bandId
        }
    }

    private func loadBands() async {
        bands = (try? await api.bands()) ?? []
    }

    private func save() async {
        loading = true; error = ""
        var body: [String: String] = [
            "title":        title,
            "lyrics":       lyrics,
            "album":        album,
            "spotify_link": spotifyLink,
            "video_link":   videoLink,
            "release_year": releaseYear,
        ]
        if let bid = selectedBandId { body["band_id"] = "\(bid)" }

        do {
            switch mode {
            case .add:
                try await api.createSong(body)
            case .edit(let song):
                try await api.updateSong(id: song.id, body)
            }
            UINotificationFeedbackGenerator().notificationOccurred(.success)
            onSaved?()
            dismiss()
        } catch {
            UINotificationFeedbackGenerator().notificationOccurred(.error)
            self.error = error.localizedDescription
        }
        loading = false
    }
}
