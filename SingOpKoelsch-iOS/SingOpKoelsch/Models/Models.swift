import Foundation

// MARK: - User

struct AppUser: Codable {
    let userId: Int
    let name: String
    let email: String
    let role: String
    let profilePicture: String?
    var preferences: UserPreferences

    var isAdmin: Bool { role == "admin" }

    enum CodingKeys: String, CodingKey {
        case name, email, role, preferences
        case userId         = "user_id"
        case profilePicture = "profile_picture"
    }
}

struct UserPreferences: Codable {
    var darkMode: Bool
    var notifications: Bool
    var emailMaxPerDay: Int
    var lang: String

    enum CodingKeys: String, CodingKey {
        case lang, notifications
        case darkMode       = "dark_mode"
        case emailMaxPerDay = "email_max_per_day"
    }
}

struct LoginResponse: Codable {
    let token: String
    let userId: Int
    let name: String
    let email: String
    let role: String
    let profilePicture: String?

    var isAdmin: Bool { role == "admin" }

    enum CodingKeys: String, CodingKey {
        case token, name, email, role
        case userId         = "user_id"
        case profilePicture = "profile_picture"
    }
}

// MARK: - Band

struct Band: Identifiable, Codable, Hashable {
    let bandId: Int
    let bandName: String
    let songCount: Int
    var songs: [Song]?

    var id: Int { bandId }

    enum CodingKeys: String, CodingKey {
        case songs
        case bandId    = "band_id"
        case bandName  = "band_name"
        case songCount = "song_count"
    }
}

// MARK: - Proposal

struct Proposal: Identifiable, Codable {
    let id: Int
    let lyricsId: Int
    let status: String
    let createdAt: String
    let resolvedAt: String?
    let songTitle: String
    let bandName: String?
    let proposedLyrics: String?
    let currentLyrics: String?
    let userName: String?
    let userId: Int?

    var statusEmoji: String {
        switch status {
        case "approved": return "✅"
        case "rejected": return "❌"
        default:         return "⏳"
        }
    }

    enum CodingKeys: String, CodingKey {
        case id, status
        case lyricsId      = "lyrics_id"
        case createdAt     = "created_at"
        case resolvedAt    = "resolved_at"
        case songTitle     = "song_title"
        case bandName      = "band_name"
        case proposedLyrics = "proposed_lyrics"
        case currentLyrics  = "current_lyrics"
        case userName      = "user_name"
        case userId        = "user_id"
    }
}

// MARK: - Admin Stats

struct AdminStats: Codable {
    let stats: StatsData
    let topBands: [TopBand]

    enum CodingKeys: String, CodingKey {
        case stats
        case topBands = "top_bands"
    }
}

struct StatsData: Codable {
    let totalSongs: Int
    let totalUsers: Int
    let totalBands: Int
    let pendingChanges: Int
    let approvedChanges: Int
    let rejectedChanges: Int

    enum CodingKeys: String, CodingKey {
        case totalSongs      = "total_songs"
        case totalUsers      = "total_users"
        case totalBands      = "total_bands"
        case pendingChanges  = "pending_changes"
        case approvedChanges = "approved_changes"
        case rejectedChanges = "rejected_changes"
    }
}

struct TopBand: Codable, Identifiable {
    let bandName: String
    let songCount: Int

    var id: String { bandName }

    enum CodingKeys: String, CodingKey {
        case bandName  = "band_name"
        case songCount = "song_count"
    }
}

// MARK: - API Envelope

struct APIResponse<T: Decodable>: Decodable {
    let ok: Bool
    let data: T?
    let error: String?
}

struct MessageResponse: Decodable {
    let message: String
}
