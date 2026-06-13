import Foundation

struct WatchSong: Identifiable, Decodable, Hashable {
    let id: Int
    let title: String
    let bandName: String

    enum CodingKeys: String, CodingKey {
        case id, title
        case bandName = "band_name"
    }
}

struct WatchSongDetail: Identifiable, Decodable {
    let id: Int
    let title: String
    let bandName: String
    let lyrics: String

    enum CodingKeys: String, CodingKey {
        case id, title, lyrics
        case bandName = "band_name"
    }

    // Strip any stray HTML tags the server might include
    var cleanLyrics: String {
        let stripped = lyrics
            .replacingOccurrences(of: "<br>",  with: "\n", options: .caseInsensitive)
            .replacingOccurrences(of: "<br/>", with: "\n", options: .caseInsensitive)
            .replacingOccurrences(of: "<br />", with: "\n", options: .caseInsensitive)
        // Remove remaining tags
        guard let regex = try? NSRegularExpression(pattern: "<[^>]+>") else { return stripped }
        let range = NSRange(stripped.startIndex..., in: stripped)
        return regex.stringByReplacingMatches(in: stripped, range: range, withTemplate: "")
    }
}

struct WatchSongsPage: Decodable {
    let songs: [WatchSong]
    let total: Int
    let pages: Int
}

struct WAPIResponse<T: Decodable>: Decodable {
    let ok: Bool
    let data: T?
    let error: String?
}
