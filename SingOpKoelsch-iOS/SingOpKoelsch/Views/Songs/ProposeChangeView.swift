import SwiftUI

struct ProposeChangeView: View {
    let song: SongDetail
    @Environment(\.dismiss) var dismiss

    @State private var proposed: String
    @State private var loading = false
    @State private var error   = ""
    @State private var success = false

    private let api = APIClient.shared

    init(song: SongDetail) {
        self.song = song
        _proposed = State(initialValue: song.lyrics)
    }

    var body: some View {
        NavigationStack {
            VStack(spacing: 0) {
                if success {
                    VStack(spacing: 20) {
                        Spacer()
                        Image(systemName: "checkmark.circle.fill")
                            .font(.system(size: 64))
                            .foregroundStyle(Theme.success)
                        Text("Vorschlag eingereicht!")
                            .font(.title2.bold())
                        Text("Ein Admin wird deinen Vorschlag prüfen. Du erhältst eine Benachrichtigung, sobald er entschieden wurde.")
                            .multilineTextAlignment(.center)
                            .foregroundStyle(.secondary)
                            .padding(.horizontal)
                        Spacer()
                        Button("Schließen") { dismiss() }
                            .buttonStyle(PrimaryButtonStyle())
                            .padding()
                    }
                } else {
                    // Split diff view
                    VStack(alignment: .leading, spacing: 0) {
                        // Header info
                        VStack(alignment: .leading, spacing: 4) {
                            Text(song.title).font(.headline)
                            Text(song.bandName).font(.subheadline).foregroundStyle(.secondary)
                        }
                        .padding()
                        .frame(maxWidth: .infinity, alignment: .leading)
                        .background(Theme.bgAlt)

                        Divider()

                        Text("Bearbeiteter Text:")
                            .font(.caption.bold())
                            .foregroundStyle(.secondary)
                            .padding(.horizontal, Theme.padding)
                            .padding(.top, 12)

                        TextEditor(text: $proposed)
                            .font(Theme.lyricsFont)
                            .lineSpacing(4)
                            .padding(.horizontal, Theme.padding - 4)
                            .frame(maxHeight: .infinity)
                            .scrollContentBackground(.hidden)
                    }

                    if !error.isEmpty {
                        ErrorBanner(message: error)
                    }

                    VStack(spacing: 12) {
                        Text("Hinweis: Du kannst nur den Liedtext bearbeiten. Bitte überprüfe deine Änderungen sorgfältig.")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                            .multilineTextAlignment(.center)

                        Button("Vorschlag einreichen") { Task { await submit() } }
                            .buttonStyle(PrimaryButtonStyle(isLoading: loading))
                            .disabled(loading || proposed.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty)
                    }
                    .padding()
                    .background(Theme.card)
                }
            }
            .background(Theme.bg.ignoresSafeArea())
            .navigationTitle("Textvorschlag")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .topBarLeading) {
                    Button("Abbrechen") { dismiss() }
                        .foregroundStyle(Theme.koelschRed)
                        .disabled(loading)
                }
            }
        }
    }

    private func submit() async {
        let text = proposed.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !text.isEmpty else { error = "Text darf nicht leer sein."; return }
        loading = true; error = ""
        do {
            try await api.proposeChange(songId: song.id, lyrics: text)
            UINotificationFeedbackGenerator().notificationOccurred(.success)
            withAnimation { success = true }
        } catch {
            UINotificationFeedbackGenerator().notificationOccurred(.error)
            self.error = error.localizedDescription
        }
        loading = false
    }
}
