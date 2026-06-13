import WidgetKit
import SwiftUI

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
}

// MARK: - Provider

struct SongProvider: TimelineProvider {
    func placeholder(in context: Context) -> SongEntry {
        SongEntry(date: .now, song: RandomSong(id: 0, title: "Kölsche Klassiker", bandName: "Bläck Fööss", coverUrl: nil))
    }

    func getSnapshot(in context: Context, completion: @escaping (SongEntry) -> Void) {
        fetchRandom { completion($0) }
    }

    func getTimeline(in context: Context, completion: @escaping (Timeline<SongEntry>) -> Void) {
        fetchRandom { entry in
            // Refresh every hour
            let next = Calendar.current.date(byAdding: .hour, value: 1, to: .now)!
            completion(Timeline(entries: [entry], policy: .after(next)))
        }
    }

    private func fetchRandom(completion: @escaping (SongEntry) -> Void) {
        guard let url = URL(string: "https://singopkoelsch.de/api/songs/random") else {
            completion(SongEntry(date: .now, song: nil)); return
        }
        let cfg = URLSessionConfiguration.ephemeral
        cfg.timeoutIntervalForRequest = 20
        URLSession(configuration: cfg).dataTask(with: url) { data, _, _ in
            var song: RandomSong? = nil
            if let data {
                struct Envelope: Decodable { let ok: Bool; let data: RandomSong? }
                song = (try? JSONDecoder().decode(Envelope.self, from: data))?.data
            }
            completion(SongEntry(date: .now, song: song))
        }.resume()
    }
}

// MARK: - Widget Views

struct SongWidgetEntryView: View {
    let entry: SongEntry
    @Environment(\.widgetFamily) var family

    var body: some View {
        if let song = entry.song {
            switch family {
            case .systemSmall:  SmallView(song: song)
            case .systemMedium: MediumView(song: song)
            default:            SmallView(song: song)
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

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            Spacer(minLength: 0)
            VStack(alignment: .leading, spacing: 3) {
                Text("Zufallslied")
                    .font(.system(size: 10, weight: .semibold))
                    .foregroundStyle(.secondary)
                    .textCase(.uppercase)
                    .kerning(0.5)
                Text(song.title)
                    .font(.system(size: 14, weight: .bold))
                    .lineLimit(2)
                    .minimumScaleFactor(0.85)
                Text(song.bandName)
                    .font(.system(size: 11))
                    .foregroundStyle(.secondary)
                    .lineLimit(1)
            }
        }
        .padding(14)
        .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .bottomLeading)
        .containerBackground(
            LinearGradient(colors: [Color(red: 0.12, green: 0.22, blue: 0.54),
                                    Color(red: 0.15, green: 0.27, blue: 0.74)],
                           startPoint: .topLeading, endPoint: .bottomTrailing),
            for: .widget
        )
        .foregroundStyle(.white)
        .widgetURL(URL(string: "singopkoelsch://song/\(song.id)"))
    }
}

private struct MediumView: View {
    let song: RandomSong

    var body: some View {
        HStack(spacing: 14) {
            // Left: cover placeholder
            ZStack {
                RoundedRectangle(cornerRadius: 10)
                    .fill(Color.white.opacity(0.15))
                Image(systemName: "music.note")
                    .font(.system(size: 28))
                    .foregroundStyle(.white.opacity(0.6))
            }
            .frame(width: 72, height: 72)

            VStack(alignment: .leading, spacing: 4) {
                Text("Zufallslied")
                    .font(.system(size: 10, weight: .semibold))
                    .foregroundStyle(.white.opacity(0.6))
                    .textCase(.uppercase)
                    .kerning(0.5)
                Text(song.title)
                    .font(.system(size: 16, weight: .bold))
                    .lineLimit(2)
                    .minimumScaleFactor(0.85)
                Text(song.bandName)
                    .font(.system(size: 12))
                    .foregroundStyle(.white.opacity(0.75))
                    .lineLimit(1)
                Spacer(minLength: 4)
                HStack(spacing: 4) {
                    Image(systemName: "arrow.right.circle.fill")
                        .font(.system(size: 11))
                    Text("Song öffnen")
                        .font(.system(size: 11, weight: .medium))
                }
                .foregroundStyle(.white.opacity(0.55))
            }
            Spacer(minLength: 0)
        }
        .padding(16)
        .containerBackground(
            LinearGradient(colors: [Color(red: 0.12, green: 0.22, blue: 0.54),
                                    Color(red: 0.15, green: 0.27, blue: 0.74)],
                           startPoint: .topLeading, endPoint: .bottomTrailing),
            for: .widget
        )
        .foregroundStyle(.white)
        .widgetURL(URL(string: "singopkoelsch://song/\(song.id)"))
    }
}

// MARK: - Widget Declaration

struct SingOpKoelschWidget: Widget {
    let kind = "SingOpKoelschWidget"

    var body: some WidgetConfiguration {
        StaticConfiguration(kind: kind, provider: SongProvider()) { entry in
            SongWidgetEntryView(entry: entry)
        }
        .configurationDisplayName("Sing op Kölsch")
        .description("Zeigt täglich einen zufälligen kölschen Song.")
        .supportedFamilies([.systemSmall, .systemMedium])
        .contentMarginsDisabled()
    }
}
