// SingOpKoelsch/Services/SiriIntents.swift
import AppIntents
import WidgetKit

// MARK: - Intents

struct OpenRandomSongIntent: AppIntent {
    static var title: LocalizedStringResource = "Zufälligen Kölsch-Song öffnen"
    static var description = IntentDescription("Öffnet einen zufälligen Song aus dem Sing op Kölsch Liederbuch.")
    static var openAppWhenRun: Bool = true

    func perform() async throws -> some IntentResult {
        if let data = try? await URLSession.shared.data(from: URL(string: "https://singopkoelsch.de/api/songs/random")!).0 {
            struct Env: Decodable { let ok: Bool; let data: S? }
            struct S: Decodable { let id: Int }
            if let songId = (try? JSONDecoder().decode(Env.self, from: data))?.data?.id {
                await MainActor.run {
                    NotificationCenter.default.post(name: .siriOpenSong, object: songId)
                }
            }
        }
        return .result()
    }
}

struct OpenFavoriteSongIntent: AppIntent {
    static var title: LocalizedStringResource = "Lieblingssong öffnen"
    static var description = IntentDescription("Öffnet einen zufälligen Lieblingssong aus Sing op Kölsch.")
    static var openAppWhenRun: Bool = true

    func perform() async throws -> some IntentResult {
        await MainActor.run {
            NotificationCenter.default.post(name: .siriOpenSong, object: nil)
        }
        return .result()
    }
}

// MARK: - App Shortcuts

struct SingOpKoelschShortcuts: AppShortcutsProvider {
    static var appShortcuts: [AppShortcut] {
        AppShortcut(
            intent: OpenRandomSongIntent(),
            phrases: [
                "Zufälligen Song in \(.applicationName)",
                "\(.applicationName) Song",
                "Kölsch Song in \(.applicationName)"
            ],
            shortTitle: "Zufälliger Song",
            systemImageName: "music.note"
        )
        AppShortcut(
            intent: OpenFavoriteSongIntent(),
            phrases: [
                "Lieblingssong in \(.applicationName)",
                "Favorit in \(.applicationName)"
            ],
            shortTitle: "Lieblingssong",
            systemImageName: "heart.fill"
        )
    }
}

// MARK: - Notification Names

extension Notification.Name {
    static let siriOpenSong = Notification.Name("siriOpenSong")
}
