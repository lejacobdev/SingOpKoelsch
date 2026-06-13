import Foundation

struct Song: Identifiable, Codable, Hashable {
    let id: Int
    let title: String
    let bandName: String
    let bandId: Int
    let album: String
    let releaseYear: Int
    let coverUrl: String
    let hasLyrics: Bool
    let hasSpotify: Bool
    let hasVideo: Bool

    enum CodingKeys: String, CodingKey {
        case id, title, album
        case bandName    = "band_name"
        case bandId      = "band_id"
        case releaseYear = "release_year"
        case coverUrl    = "cover_url"
        case hasLyrics   = "has_lyrics"
        case hasSpotify  = "has_spotify"
        case hasVideo    = "has_video"
    }
}

struct SongDetail: Identifiable, Codable {
    let id: Int
    let title: String
    let bandId: Int
    let bandName: String
    let album: String
    let releaseYear: Int
    let coverUrl: String
    let lyrics: String
    let spotifyLink: String
    let videoLink: String
    var myProposals: [MyProposal]

    enum CodingKeys: String, CodingKey {
        case id, title, album, lyrics
        case bandId      = "band_id"
        case bandName    = "band_name"
        case releaseYear = "release_year"
        case coverUrl    = "cover_url"
        case spotifyLink = "spotify_link"
        case videoLink   = "video_link"
        case myProposals = "my_proposals"
    }
}

struct MyProposal: Identifiable, Codable {
    let id: Int
    let status: String
    let createdAt: String
    let resolvedAt: String?

    enum CodingKeys: String, CodingKey {
        case id, status
        case createdAt  = "created_at"
        case resolvedAt = "resolved_at"
    }

    var statusColor: String {
        switch status {
        case "approved": return "green"
        case "rejected": return "red"
        default:         return "orange"
        }
    }
}

struct SongsPage: Codable {
    let songs: [Song]
    let total: Int
    let page: Int
    let perPage: Int
    let pages: Int

    enum CodingKeys: String, CodingKey {
        case songs, total, page, pages
        case perPage = "per_page"
    }
}
