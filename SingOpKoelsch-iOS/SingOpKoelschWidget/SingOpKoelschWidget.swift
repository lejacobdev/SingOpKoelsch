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
    let coverImage: UIImage?
}

// MARK: - Provider

struct SongProvider: TimelineProvider {
    func placeholder(in context: Context) -> SongEntry {
        SongEntry(date: .now, song: RandomSong(id: 0, title: "Kölsche Klassiker", bandName: "Bläck Fööss", coverUrl: nil), coverImage: nil)
    }

    func getSnapshot(in context: Context, completion: @escaping (SongEntry) -> Void) {
        fetchRandom { completion($0) }
    }

    func getTimeline(in context: Context, completion: @escaping (Timeline<SongEntry>) -> Void) {
        fetchRandom { entry in
            // Refresh every minute
            let next = Calendar.current.date(byAdding: .minute, value: 1, to: .now)!
            completion(Timeline(entries: [entry], policy: .after(next)))
        }
    }

    private func fetchRandom(completion: @escaping (SongEntry) -> Void) {
        guard let url = URL(string: "https://singopkoelsch.de/api/songs/random") else {
            completion(SongEntry(date: .now, song: nil, coverImage: nil)); return
        }
        let cfg = URLSessionConfiguration.ephemeral
        cfg.timeoutIntervalForRequest = 20
        let session = URLSession(configuration: cfg)

        session.dataTask(with: url) { data, _, _ in
            var song: RandomSong? = nil
            if let data {
                struct Envelope: Decodable { let ok: Bool; let data: RandomSong? }
                song = (try? JSONDecoder().decode(Envelope.self, from: data))?.data
            }

            // Fetch cover image if available
            if let coverUrlStr = song?.coverUrl,
               let coverUrl = URL(string: coverUrlStr) {
                session.dataTask(with: coverUrl) { imgData, _, _ in
                    let image = imgData.flatMap { UIImage(data: $0) }
                    completion(SongEntry(date: .now, song: song, coverImage: image))
                }.resume()
            } else {
                completion(SongEntry(date: .now, song: song, coverImage: nil))
            }
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
            case .systemSmall:  SmallView(song: song, coverImage: entry.coverImage)
            case .systemMedium: MediumView(song: song, coverImage: entry.coverImage)
            default:            SmallView(song: song, coverImage: entry.coverImage)
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

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            Spacer(minLength: 0)
            VStack(alignment: .leading, spacing: 3) {
                Text("Zufallslied")
                    .font(.system(size: 10, weight: .semibold))
                    .foregroundStyle(.white.opacity(0.75))
                    .textCase(.uppercase)
                    .kerning(0.5)
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

    private let appBg      = Color(red: 13/255,  green: 17/255,  blue: 23/255)   // #0d1117
    private let surface    = Color(red: 28/255,  green: 33/255,  blue: 40/255)   // #1c2128
    private let border     = Color(red: 51/255,  green: 65/255,  blue: 85/255)   // #334155
    private let red        = Color(red: 220/255, green: 38/255,  blue: 38/255)   // #dc2626
    private let textMuted  = Color(red: 148/255, green: 163/255, blue: 184/255)  // #94a3b8
    private let textFaint  = Color(red: 100/255, green: 116/255, blue: 139/255)  // #64748b

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
        StaticConfiguration(kind: kind, provider: SongProvider()) { entry in
            SongWidgetEntryView(entry: entry)
        }
        .configurationDisplayName("Sing op Kölsch")
        .description("Zeigt täglich einen zufälligen kölschen Song.")
        .supportedFamilies([.systemSmall, .systemMedium])
        .contentMarginsDisabled()
    }
}
