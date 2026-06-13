import WidgetKit
import SwiftUI
import AppIntents
import Security

// MARK: - Model

struct RandomSong: Codable {
    let id: Int
    let title: String
    let bandName: String
    let coverUrl: String?

    enum CodingKeys: String, CodingKey {
        case id, title
        case bandName = "band_name"
        case coverUrl = "cover_url"
    }
}

// MARK: - Timeline Entry

struct SongEntry: TimelineEntry {
    let date: Date
    let song: RandomSong?
    let coverImage: UIImage?
    let mode: WidgetSongMode
}

// MARK: - Provider

struct SongProvider: AppIntentTimelineProvider {
    private let appGroup = "group.de.singopkoelsch.app"
    private let tokenKey = "widget_auth_token"

    // Read token from App Group UserDefaults, then fall back to the main app's Keychain
    private func loadToken() -> String? {
        if let t = UserDefaults(suiteName: appGroup)?.string(forKey: tokenKey), !t.isEmpty {
            return t
        }
        // Fallback: read directly from the shared Keychain (same service/account as AuthManager)
        let query: [CFString: Any] = [
            kSecClass:       kSecClassGenericPassword,
            kSecAttrService: "de.singopkoelsch.app",
            kSecAttrAccount: "auth_token",
            kSecReturnData:  true,
            kSecMatchLimit:  kSecMatchLimitOne
        ]
        var result: AnyObject?
        guard SecItemCopyMatching(query as CFDictionary, &result) == errSecSuccess,
              let data = result as? Data else { return nil }
        return String(data: data, encoding: .utf8)
    }

    func placeholder(in context: Context) -> SongEntry {
        SongEntry(date: .now,
                  song: RandomSong(id: 0, title: "Kölsche Klassiker", bandName: "Bläck Fööss", coverUrl: nil),
                  coverImage: nil, mode: .all)
    }

    func snapshot(for configuration: SongWidgetIntent, in context: Context) async -> SongEntry {
        await fetch(mode: configuration.mode)
    }

    func timeline(for configuration: SongWidgetIntent, in context: Context) async -> Timeline<SongEntry> {
        let entry = await fetch(mode: configuration.mode)
        let next = Calendar.current.date(byAdding: .minute, value: 1, to: .now)!
        return Timeline(entries: [entry], policy: .after(next))
    }

    private func fetch(mode: WidgetSongMode) async -> SongEntry {
        var request: URLRequest

        if mode == .favorites,
           let token = loadToken() {
            request = URLRequest(url: URL(string: "https://singopkoelsch.de/api/songs/random/favorite")!)
            request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        } else {
            request = URLRequest(url: URL(string: "https://singopkoelsch.de/api/songs/random")!)
        }
        request.timeoutInterval = 20

        do {
            let cfg = URLSessionConfiguration.ephemeral
            let session = URLSession(configuration: cfg)
            let (data, _) = try await session.data(for: request)
            struct Envelope: Decodable { let ok: Bool; let data: RandomSong? }
            let song = (try? JSONDecoder().decode(Envelope.self, from: data))?.data

            var coverImage: UIImage? = nil
            if let coverUrlStr = song?.coverUrl, let coverUrl = URL(string: coverUrlStr) {
                let (imgData, _) = try await session.data(from: coverUrl)
                coverImage = UIImage(data: imgData)
            }
            return SongEntry(date: .now, song: song, coverImage: coverImage, mode: mode)
        } catch {
            return SongEntry(date: .now, song: nil, coverImage: nil, mode: mode)
        }
    }
}

// MARK: - Widget Views

struct SongWidgetEntryView: View {
    let entry: SongEntry
    @Environment(\.widgetFamily) var family

    var body: some View {
        if let song = entry.song {
            switch family {
            case .systemSmall:  SmallView(song: song, coverImage: entry.coverImage, mode: entry.mode)
            case .systemMedium: MediumView(song: song, coverImage: entry.coverImage, mode: entry.mode)
            default:            SmallView(song: song, coverImage: entry.coverImage, mode: entry.mode)
            }
        } else {
            placeholderView
        }
    }

    private var placeholderView: some View {
        VStack(spacing: 6) {
            Image(systemName: "music.note")
                .font(.title2)
                .foregroundStyle(.secondary)
            Text("Kein Song verfügbar")
                .font(.caption)
                .foregroundStyle(.secondary)
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .containerBackground(Color(.systemBackground), for: .widget)
    }
}

private struct SmallView: View {
    let song: RandomSong
    let coverImage: UIImage?
    let mode: WidgetSongMode

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            Spacer(minLength: 0)
            VStack(alignment: .leading, spacing: 3) {
                HStack(spacing: 4) {
                    if mode == .favorites {
                        Image(systemName: "heart.fill")
                            .font(.system(size: 8))
                            .foregroundStyle(.white.opacity(0.75))
                    }
                    Text(mode == .favorites ? "Lieblingslied" : "Zufallslied")
                        .font(.system(size: 10, weight: .semibold))
                        .foregroundStyle(.white.opacity(0.75))
                        .textCase(.uppercase)
                        .kerning(0.5)
                }
                Text(song.title)
                    .font(.system(size: 14, weight: .bold))
                    .foregroundStyle(.white)
                    .lineLimit(2)
                    .minimumScaleFactor(0.85)
                Text(song.bandName)
                    .font(.system(size: 11))
                    .foregroundStyle(.white.opacity(0.75))
                    .lineLimit(1)
            }
        }
        .padding(14)
        .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .bottomLeading)
        .containerBackground(for: .widget) {
            if let img = coverImage {
                Image(uiImage: img)
                    .resizable()
                    .aspectRatio(contentMode: .fill)
                    .overlay(Color.black.opacity(0.5))
            } else {
                ZStack(alignment: .topTrailing) {
                    LinearGradient(
                        colors: [Color(red: 0.12, green: 0.22, blue: 0.54),
                                 Color(red: 0.15, green: 0.27, blue: 0.74)],
                        startPoint: .topLeading,
                        endPoint: .bottomTrailing
                    )
                    Image(systemName: "music.note")
                        .font(.system(size: 80, weight: .thin))
                        .foregroundStyle(.white.opacity(0.07))
                        .offset(x: 18, y: -18)
                }
            }
        }
        .widgetURL(URL(string: "singopkoelsch://song/\(song.id)"))
    }
}

private struct MediumView: View {
    let song: RandomSong
    let coverImage: UIImage?
    let mode: WidgetSongMode

    private let appBg      = Color(red: 13/255,  green: 17/255,  blue: 23/255)
    private let surface    = Color(red: 28/255,  green: 33/255,  blue: 40/255)
    private let border     = Color(red: 51/255,  green: 65/255,  blue: 85/255)
    private let red        = Color(red: 220/255, green: 38/255,  blue: 38/255)
    private let textMuted  = Color(red: 148/255, green: 163/255, blue: 184/255)
    private let textFaint  = Color(red: 100/255, green: 116/255, blue: 139/255)

    var body: some View {
        HStack(spacing: 14) {
            // Album cover / placeholder
            ZStack {
                RoundedRectangle(cornerRadius: 12, style: .continuous)
                    .fill(surface)
                if let img = coverImage {
                    Image(uiImage: img)
                        .resizable()
                        .aspectRatio(contentMode: .fill)
                        .clipShape(RoundedRectangle(cornerRadius: 12, style: .continuous))
                } else {
                    Image(systemName: "music.note")
                        .font(.system(size: 28, weight: .medium))
                        .foregroundStyle(textMuted)
                }
            }
            .frame(width: 86, height: 86)
            .overlay(
                RoundedRectangle(cornerRadius: 12, style: .continuous)
                    .strokeBorder(border, lineWidth: 1)
            )

            VStack(alignment: .leading, spacing: 0) {
                // Branding row
                HStack(spacing: 5) {
                    ZStack {
                        RoundedRectangle(cornerRadius: 4, style: .continuous)
                            .fill(red)
                            .frame(width: 16, height: 16)
                        Text("S")
                            .font(.system(size: 9, weight: .black))
                            .foregroundStyle(.white)
                    }
                    Text("Sing op Kölsch")
                        .font(.system(size: 10, weight: .semibold))
                        .foregroundStyle(textMuted)
                    Spacer(minLength: 0)
                    if mode == .favorites {
                        Image(systemName: "heart.fill")
                            .font(.system(size: 10))
                            .foregroundStyle(red)
                    }
                }

                Spacer(minLength: 6)

                Text(song.title)
                    .font(.system(size: 15, weight: .bold))
                    .foregroundStyle(.white)
                    .lineLimit(2)
                    .minimumScaleFactor(0.8)

                Text(song.bandName)
                    .font(.system(size: 12))
                    .foregroundStyle(textMuted)
                    .lineLimit(1)
                    .padding(.top, 2)

                Spacer(minLength: 0)

                HStack(spacing: 4) {
                    Image(systemName: "arrow.right.circle.fill")
                        .font(.system(size: 10))
                        .foregroundStyle(red)
                    Text("Liedtext öffnen")
                        .font(.system(size: 10, weight: .medium))
                        .foregroundStyle(textFaint)
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
        }
        .padding(14)
        .containerBackground(appBg, for: .widget)
        .widgetURL(URL(string: "singopkoelsch://song/\(song.id)"))
    }
}

// MARK: - Widget Declaration

struct SingOpKoelschWidget: Widget {
    let kind = "SingOpKoelschWidget"

    var body: some WidgetConfiguration {
        AppIntentConfiguration(kind: kind, intent: SongWidgetIntent.self, provider: SongProvider()) { entry in
            SongWidgetEntryView(entry: entry)
        }
        .configurationDisplayName("Sing op Kölsch")
        .description("Zufälliger Song oder Lieblingslied auf dem Homescreen.")
        .supportedFamilies([.systemSmall, .systemMedium])
        .contentMarginsDisabled()
    }
}
