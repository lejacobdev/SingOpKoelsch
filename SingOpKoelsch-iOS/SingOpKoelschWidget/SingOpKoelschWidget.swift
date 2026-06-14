// SingOpKoelschWidget/SingOpKoelschWidget.swift
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

    private func loadToken() -> String? {
        if let t = UserDefaults(suiteName: appGroup)?.string(forKey: tokenKey), !t.isEmpty {
            return t
        }
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

        if mode == .favorites, let token = loadToken() {
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
            case .systemSmall:          SmallView(song: song, coverImage: entry.coverImage, mode: entry.mode)
            case .systemMedium:         MediumView(song: song, coverImage: entry.coverImage, mode: entry.mode)
            case .systemExtraLarge:     StandByView(song: song, coverImage: entry.coverImage)
            case .accessoryRectangular: LockRectView(song: song)
            case .accessoryCircular:    LockCircleView(song: song)
            default:                    SmallView(song: song, coverImage: entry.coverImage, mode: entry.mode)
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

// MARK: - #39 StandBy / ExtraLarge View

private struct StandByView: View {
    let song: RandomSong
    let coverImage: UIImage?

    private let appBg  = Color(red: 13/255, green: 17/255, blue: 23/255)
    private let red    = Color(red: 220/255, green: 38/255, blue: 38/255)

    var body: some View {
        HStack(spacing: 24) {
            // Large cover art
            ZStack {
                RoundedRectangle(cornerRadius: 20, style: .continuous)
                    .fill(Color(red: 28/255, green: 33/255, blue: 40/255))
                if let img = coverImage {
                    Image(uiImage: img)
                        .resizable()
                        .aspectRatio(contentMode: .fill)
                        .clipShape(RoundedRectangle(cornerRadius: 20, style: .continuous))
                } else {
                    Image(systemName: "music.note")
                        .font(.system(size: 60, weight: .thin))
                        .foregroundStyle(.white.opacity(0.3))
                }
            }
            .frame(width: 200, height: 200)

            VStack(alignment: .leading, spacing: 12) {
                // Branding
                HStack(spacing: 8) {
                    ZStack {
                        RoundedRectangle(cornerRadius: 6, style: .continuous)
                            .fill(red)
                            .frame(width: 28, height: 28)
                        Text("S")
                            .font(.system(size: 16, weight: .black))
                            .foregroundStyle(.white)
                    }
                    Text("Sing op Kölsch")
                        .font(.system(size: 14, weight: .semibold))
                        .foregroundStyle(.white.opacity(0.6))
                }

                Spacer()

                Text(song.title)
                    .font(.system(size: 28, weight: .bold))
                    .foregroundStyle(.white)
                    .lineLimit(3)
                    .minimumScaleFactor(0.7)

                Text(song.bandName)
                    .font(.system(size: 18))
                    .foregroundStyle(.white.opacity(0.65))
                    .lineLimit(2)

                Spacer()

                HStack(spacing: 6) {
                    Image(systemName: "arrow.right.circle.fill")
                        .foregroundStyle(red)
                    Text("Liedtext öffnen")
                        .font(.system(size: 13, weight: .medium))
                        .foregroundStyle(.white.opacity(0.5))
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
        }
        .padding(24)
        .containerBackground(appBg, for: .widget)
        .widgetURL(URL(string: "singopkoelsch://song/\(song.id)"))
    }
}

// MARK: - Lock Screen Views

private struct LockRectView: View {
    let song: RandomSong
    var body: some View {
        HStack(spacing: 6) {
            Image(systemName: "music.note")
                .font(.system(size: 13, weight: .semibold))
                .unredacted()
            VStack(alignment: .leading, spacing: 1) {
                Text(song.title)
                    .font(.system(size: 13, weight: .bold))
                    .lineLimit(1)
                Text(song.bandName)
                    .font(.system(size: 11))
                    .lineLimit(1)
                    .foregroundStyle(.secondary)
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .containerBackground(.clear, for: .widget)
        .widgetURL(URL(string: "singopkoelsch://song/\(song.id)"))
    }
}

private struct LockCircleView: View {
    let song: RandomSong
    var body: some View {
        VStack(spacing: 2) {
            Image(systemName: "music.note")
                .font(.system(size: 16, weight: .semibold))
            Text(String(song.title.prefix(3)))
                .font(.system(size: 9, weight: .bold))
                .lineLimit(1)
        }
        .containerBackground(.clear, for: .widget)
        .widgetURL(URL(string: "singopkoelsch://song/\(song.id)"))
    }
}

// MARK: - #43 Recently Viewed Widget

struct RecentlyViewedEntry: TimelineEntry {
    let date: Date
    let songs: [RandomSong]
}

struct RecentlyViewedProvider: TimelineProvider {
    private let appGroup = "group.de.singopkoelsch.app"

    func placeholder(in context: Context) -> RecentlyViewedEntry {
        RecentlyViewedEntry(date: .now, songs: [
            RandomSong(id: 1, title: "Et jitt kei Wood", bandName: "Bläck Fööss", coverUrl: nil)
        ])
    }

    func getSnapshot(in context: Context, completion: @escaping (RecentlyViewedEntry) -> Void) {
        Task {
            let songs = await fetchRecentSongs()
            completion(RecentlyViewedEntry(date: .now, songs: songs))
        }
    }

    func getTimeline(in context: Context, completion: @escaping (Timeline<RecentlyViewedEntry>) -> Void) {
        Task {
            let songs = await fetchRecentSongs()
            let entry = RecentlyViewedEntry(date: .now, songs: songs)
            let nextUpdate = Calendar.current.date(byAdding: .minute, value: 15, to: .now)!
            completion(Timeline(entries: [entry], policy: .after(nextUpdate)))
        }
    }

    private func fetchRecentSongs() async -> [RandomSong] {
        let defaults = UserDefaults(suiteName: appGroup)
        let ids = defaults?.array(forKey: "recently_viewed") as? [Int] ?? []
        guard !ids.isEmpty else { return [] }

        let session = URLSession(configuration: .ephemeral)
        var songs: [RandomSong] = []
        for id in ids.prefix(5) {
            if let url = URL(string: "https://singopkoelsch.de/api/songs/\(id)"),
               let (data, _) = try? await session.data(from: url) {
                struct Envelope: Decodable { let ok: Bool; let data: RandomSong? }
                if let song = (try? JSONDecoder().decode(Envelope.self, from: data))?.data {
                    songs.append(song)
                }
            }
        }
        return songs
    }
}

struct RecentlyViewedEntryView: View {
    let entry: RecentlyViewedEntry
    private let red = Color(red: 220/255, green: 38/255, blue: 38/255)
    private let appBg = Color(red: 13/255, green: 17/255, blue: 23/255)

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            HStack(spacing: 6) {
                Image(systemName: "clock.fill")
                    .font(.system(size: 10))
                    .foregroundStyle(red)
                Text("Zuletzt angesehen")
                    .font(.system(size: 11, weight: .semibold))
                    .foregroundStyle(.white.opacity(0.6))
                    .textCase(.uppercase)
                    .kerning(0.4)
            }
            .padding(.bottom, 8)

            if entry.songs.isEmpty {
                Spacer()
                Text("Noch keine Songs angesehen")
                    .font(.caption)
                    .foregroundStyle(.white.opacity(0.4))
                Spacer()
            } else {
                ForEach(entry.songs.prefix(4), id: \.id) { song in
                    Link(destination: URL(string: "singopkoelsch://song/\(song.id)")!) {
                        HStack(spacing: 6) {
                            Image(systemName: "music.note")
                                .font(.system(size: 10))
                                .foregroundStyle(red.opacity(0.8))
                                .frame(width: 14)
                            VStack(alignment: .leading, spacing: 1) {
                                Text(song.title)
                                    .font(.system(size: 12, weight: .semibold))
                                    .foregroundStyle(.white)
                                    .lineLimit(1)
                                Text(song.bandName)
                                    .font(.system(size: 10))
                                    .foregroundStyle(.white.opacity(0.5))
                                    .lineLimit(1)
                            }
                        }
                        .padding(.vertical, 3)
                    }
                }
            }
        }
        .padding(14)
        .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .topLeading)
        .containerBackground(appBg, for: .widget)
    }
}

struct RecentlyViewedWidget: Widget {
    let kind = "RecentlyViewedWidget"

    var body: some WidgetConfiguration {
        StaticConfiguration(kind: kind, provider: RecentlyViewedProvider()) { entry in
            RecentlyViewedEntryView(entry: entry)
        }
        .configurationDisplayName("Zuletzt angesehen")
        .description("Zeigt die zuletzt angesehenen Songs.")
        .supportedFamilies([.systemSmall, .systemMedium])
        .contentMarginsDisabled()
    }
}

// MARK: - #44 Karneval Countdown Widget

struct KarnevalEntry: TimelineEntry {
    let date: Date
    let daysUntil: Int
    let isKarneval: Bool
}

struct KarnevalProvider: TimelineProvider {
    func placeholder(in context: Context) -> KarnevalEntry {
        KarnevalEntry(date: .now, daysUntil: 42, isKarneval: false)
    }

    func getSnapshot(in context: Context, completion: @escaping (KarnevalEntry) -> Void) {
        completion(makeEntry())
    }

    func getTimeline(in context: Context, completion: @escaping (Timeline<KarnevalEntry>) -> Void) {
        let entry = makeEntry()
        // Refresh daily at midnight
        let nextMidnight = Calendar.current.startOfDay(for: Calendar.current.date(byAdding: .day, value: 1, to: .now)!)
        completion(Timeline(entries: [entry], policy: .after(nextMidnight)))
    }

    private func makeEntry() -> KarnevalEntry {
        let today = Date()
        let year = Calendar.current.component(.year, from: today)
        let (days, isKarneval) = karnevalCountdown(from: today, year: year)
        return KarnevalEntry(date: today, daysUntil: days, isKarneval: isKarneval)
    }

    private func karnevalCountdown(from today: Date, year: Int) -> (Int, Bool) {
        let weiberfastnacht = easterBasedDate(year: year, offsetFromEaster: -52) // Thursday before Ash Wednesday
        let ashWednesday = easterBasedDate(year: year, offsetFromEaster: -46)

        let cal = Calendar.current
        let todayStart = cal.startOfDay(for: today)

        // Check if currently in Karneval
        if todayStart >= cal.startOfDay(for: weiberfastnacht) &&
           todayStart <= cal.startOfDay(for: ashWednesday) {
            return (0, true)
        }

        // Calculate days until Weiberfastnacht this year or next year
        if weiberfastnacht > todayStart {
            let days = cal.dateComponents([.day], from: todayStart, to: cal.startOfDay(for: weiberfastnacht)).day ?? 0
            return (days, false)
        } else {
            // Look to next year
            let nextWeiberfastnacht = easterBasedDate(year: year + 1, offsetFromEaster: -52)
            let days = cal.dateComponents([.day], from: todayStart, to: cal.startOfDay(for: nextWeiberfastnacht)).day ?? 0
            return (days, false)
        }
    }

    /// Returns the date that is `offsetFromEaster` days relative to Easter Sunday for the given year.
    private func easterBasedDate(year: Int, offsetFromEaster: Int) -> Date {
        let easter = easterDate(year: year)
        return Calendar.current.date(byAdding: .day, value: offsetFromEaster, to: easter)!
    }

    private func easterDate(year: Int) -> Date {
        let a = year % 19
        let b = year / 100
        let c = year % 100
        let d = b / 4
        let e = b % 4
        let f = (b + 8) / 25
        let g = (b - f + 1) / 3
        let h = (19 * a + b - d - g + 15) % 30
        let i = c / 4
        let k = c % 4
        let l = (32 + 2 * e + 2 * i - h - k) % 7
        let m = (a + 11 * h + 22 * l) / 451
        let month = (h + l - 7 * m + 114) / 31
        let day   = ((h + l - 7 * m + 114) % 31) + 1
        var comps = DateComponents()
        comps.year = year; comps.month = month; comps.day = day
        return Calendar.current.date(from: comps)!
    }
}

struct KarnevalWidgetView: View {
    let entry: KarnevalEntry
    @Environment(\.widgetFamily) var family

    private let red   = Color(red: 220/255, green: 38/255, blue: 38/255)
    private let appBg = Color(red: 13/255, green: 17/255, blue: 23/255)

    var body: some View {
        switch family {
        case .accessoryRectangular:
            lockScreenView
        default:
            smallView
        }
    }

    private var smallView: some View {
        VStack(spacing: 8) {
            Text("🎭")
                .font(.system(size: 36))

            if entry.isKarneval {
                Text("Alaaf!")
                    .font(.system(size: 20, weight: .black))
                    .foregroundStyle(red)
                Text("Karneval!")
                    .font(.system(size: 13))
                    .foregroundStyle(.white.opacity(0.7))
            } else {
                Text("\(entry.daysUntil)")
                    .font(.system(size: 36, weight: .black))
                    .foregroundStyle(.white)
                Text("Tage bis Karneval")
                    .font(.system(size: 11, weight: .semibold))
                    .foregroundStyle(.white.opacity(0.65))
                    .multilineTextAlignment(.center)
            }
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .containerBackground(appBg, for: .widget)
    }

    private var lockScreenView: some View {
        HStack(spacing: 8) {
            Text("🎭")
                .font(.system(size: 20))
            if entry.isKarneval {
                Text("Karneval – Alaaf!")
                    .font(.system(size: 13, weight: .bold))
                    .lineLimit(1)
            } else {
                VStack(alignment: .leading, spacing: 1) {
                    Text("\(entry.daysUntil) Tage bis Karneval")
                        .font(.system(size: 13, weight: .bold))
                        .lineLimit(1)
                    Text("Sing op Kölsch")
                        .font(.system(size: 10))
                        .foregroundStyle(.secondary)
                }
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .containerBackground(.clear, for: .widget)
    }
}

struct KarnevalWidget: Widget {
    let kind = "KarnevalWidget"

    var body: some WidgetConfiguration {
        StaticConfiguration(kind: kind, provider: KarnevalProvider()) { entry in
            KarnevalWidgetView(entry: entry)
        }
        .configurationDisplayName("Karneval-Countdown")
        .description("Zeigt die Tage bis zum nächsten Kölner Karneval (Weiberfastnacht).")
        .supportedFamilies([.systemSmall, .accessoryRectangular])
        .contentMarginsDisabled()
    }
}

// MARK: - Main Widget Declaration

struct SingOpKoelschWidget: Widget {
    let kind = "SingOpKoelschWidget"

    var body: some WidgetConfiguration {
        AppIntentConfiguration(kind: kind, intent: SongWidgetIntent.self, provider: SongProvider()) { entry in
            SongWidgetEntryView(entry: entry)
        }
        .configurationDisplayName("Sing op Kölsch")
        .description("Zufälliger Song oder Lieblingslied auf dem Homescreen.")
        .supportedFamilies([
            .systemSmall,
            .systemMedium,
            .systemExtraLarge,   // #39 StandBy
            .accessoryRectangular,
            .accessoryCircular
        ])
        .contentMarginsDisabled()
    }
}
