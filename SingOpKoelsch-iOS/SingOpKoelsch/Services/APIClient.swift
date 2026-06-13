import Foundation

// Change this to your server's URL
let API_BASE = "https://singopkoelsch.de/api"

enum APIError: LocalizedError {
    case network(Error)
    case server(String)
    case decode(Error)
    case unauthorized

    var errorDescription: String? {
        switch self {
        case .network(let e): return "Netzwerkfehler: \(e.localizedDescription)"
        case .server(let m):  return m
        case .decode(let e):  return "Dekodierungsfehler: \(e.localizedDescription)"
        case .unauthorized:   return "Nicht angemeldet"
        }
    }
}

@MainActor
final class APIClient: ObservableObject {
    static let shared = APIClient()

    private var token: String? {
        Keychain.load(key: "auth_token")
    }

    private func request<T: Decodable>(
        _ path: String,
        method: String = "GET",
        body: Encodable? = nil
    ) async throws -> T {
        guard let url = URL(string: "\(API_BASE)/\(path)") else {
            throw APIError.server("Invalid URL")
        }
        var req = URLRequest(url: url)
        req.httpMethod = method
        req.setValue("application/json", forHTTPHeaderField: "Content-Type")
        if let tok = token {
            req.setValue("Bearer \(tok)", forHTTPHeaderField: "Authorization")
        }
        if let body {
            req.httpBody = try JSONEncoder().encode(body)
        }

        let (data, response) = try await URLSession.shared.data(for: req)
        let http = response as! HTTPURLResponse

        if http.statusCode == 401 {
            NotificationCenter.default.post(name: .sessionExpired, object: nil)
            throw APIError.unauthorized
        }

        let envelope = try JSONDecoder().decode(APIResponse<T>.self, from: data)
        if let err = envelope.error, !envelope.ok { throw APIError.server(err) }
        guard let payload = envelope.data else { throw APIError.server("Empty response") }
        return payload
    }

    // MARK: Auth

    func login(email: String, password: String) async throws -> LoginResponse {
        try await request("auth/login", method: "POST",
                          body: ["email": email, "password": password])
    }

    @discardableResult
    func register(name: String, email: String, password: String) async throws -> MessageResponse {
        try await request("auth/register", method: "POST",
                          body: ["name": name, "email": email, "password": password])
    }

    func logout() async throws {
        let _: MessageResponse = try await request("auth/logout", method: "POST")
    }

    func me() async throws -> AppUser {
        try await request("auth/me")
    }

    @discardableResult
    func forgotPassword(email: String) async throws -> MessageResponse {
        try await request("auth/forgot", method: "POST", body: ["email": email])
    }

    // MARK: Songs

    func songs(page: Int = 1, query: String = "", bandId: Int? = nil) async throws -> SongsPage {
        var path = "songs?page=\(page)&per_page=20"
        if !query.isEmpty { path += "&q=\(query.addingPercentEncoding(withAllowedCharacters: .urlQueryAllowed) ?? query)" }
        if let b = bandId { path += "&band_id=\(b)" }
        return try await request(path)
    }

    func songDetail(id: Int) async throws -> SongDetail {
        try await request("songs/\(id)")
    }

    @discardableResult
    func proposeChange(songId: Int, lyrics: String) async throws -> MessageResponse {
        try await request("songs/\(songId)/propose", method: "POST", body: ["lyrics": lyrics])
    }

    // MARK: Bands

    func bands() async throws -> [Band] {
        try await request("bands")
    }

    func bandDetail(id: Int) async throws -> Band {
        try await request("bands/\(id)")
    }

    // MARK: My Proposals

    func myProposals(status: String? = nil) async throws -> [Proposal] {
        var path = "proposals"
        if let s = status { path += "?status=\(s)" }
        return try await request(path)
    }

    // MARK: Profile

    func profile() async throws -> AppUser {
        try await request("profile")
    }

    @discardableResult
    func updateName(_ name: String) async throws -> MessageResponse {
        try await request("profile", method: "PUT", body: ["name": name])
    }

    @discardableResult
    func changePassword(current: String, new: String) async throws -> MessageResponse {
        try await request("profile/password", method: "POST",
                          body: ["current_password": current, "new_password": new])
    }

    @discardableResult
    func updatePreferences(_ prefs: [String: Any]) async throws -> MessageResponse {
        struct AnyEncodable: Encodable {
            let dict: [String: Any]
            func encode(to encoder: Encoder) throws {
                var c = encoder.container(keyedBy: AnyCodingKey.self)
                for (k, v) in dict {
                    let key = AnyCodingKey(k)
                    if let b = v as? Bool   { try c.encode(b, forKey: key) }
                    else if let i = v as? Int    { try c.encode(i, forKey: key) }
                    else if let s = v as? String { try c.encode(s, forKey: key) }
                }
            }
        }
        struct AnyCodingKey: CodingKey {
            var stringValue: String
            var intValue: Int? { nil }
            init(_ s: String) { stringValue = s }
            init?(stringValue: String) { self.stringValue = stringValue }
            init?(intValue: Int) { return nil }
        }
        return try await request("profile/preferences", method: "PUT",
                                 body: AnyEncodable(dict: prefs))
    }

    // MARK: Notifications

    func registerDeviceToken(_ token: String, sandbox: Bool = false) async throws {
        let env = sandbox ? "sandbox" : "production"
        let _: MessageResponse = try await request("notifications/register", method: "POST",
                                                   body: ["device_token": token, "environment": env])
    }

    func unregisterDeviceToken(_ token: String) async throws {
        let _: MessageResponse = try await request("notifications/token", method: "DELETE",
                                                   body: ["device_token": token])
    }

    // MARK: Admin

    func adminStats() async throws -> AdminStats {
        try await request("admin/stats")
    }

    func adminProposals(status: String = "pending") async throws -> [Proposal] {
        try await request("admin/proposals?status=\(status)")
    }

    @discardableResult
    func approveProposal(id: Int) async throws -> MessageResponse {
        try await request("admin/proposals/\(id)/approve", method: "POST")
    }

    @discardableResult
    func rejectProposal(id: Int) async throws -> MessageResponse {
        try await request("admin/proposals/\(id)/reject", method: "POST")
    }

    // MARK: Song CRUD (admin / trusted)

    @discardableResult
    func createSong(_ body: [String: String]) async throws -> MessageResponse {
        try await request("songs", method: "POST", body: body)
    }

    @discardableResult
    func updateSong(id: Int, _ body: [String: String]) async throws -> MessageResponse {
        try await request("songs/\(id)", method: "PUT", body: body)
    }

    @discardableResult
    func deleteSong(id: Int) async throws -> MessageResponse {
        try await request("songs/\(id)", method: "DELETE")
    }

    // MARK: Account

    @discardableResult
    func deleteAccount(password: String) async throws -> MessageResponse {
        try await request("account/delete", method: "POST", body: ["password": password])
    }

    // MARK: Songbook

    struct SongbookResponse: Decodable {
        let filename: String
        let mime: String
        let content: String   // base64
    }

    func exportSongbook(songIds: [Int]) async throws -> SongbookResponse {
        struct Body: Encodable { let songIds: [Int]; enum CodingKeys: String, CodingKey { case songIds = "song_ids" } }
        return try await request("liederbuch/export", method: "POST", body: Body(songIds: songIds))
    }

    // MARK: Audio recognition

    struct RecognizeResponse: Decodable {
        let shazamTitle:  String
        let shazamArtist: String
        let shazamUrl:    String
        let dbMatch:      RecognizeMatch?
        enum CodingKeys: String, CodingKey {
            case shazamTitle  = "shazam_title"
            case shazamArtist = "shazam_artist"
            case shazamUrl    = "shazam_url"
            case dbMatch      = "db_match"
        }
    }
    struct RecognizeMatch: Decodable {
        let id: Int; let title: String; let bandName: String?
        enum CodingKeys: String, CodingKey { case id, title; case bandName = "band_name" }
    }

    func recognizeAudio(fileURL: URL) async throws -> RecognizeResponse {
        guard let token else { throw APIError.unauthorized }
        guard let url = URL(string: "\(API_BASE)/songs/recognize") else { throw APIError.server("Bad URL") }
        var req = URLRequest(url: url)
        req.httpMethod = "POST"
        req.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        let boundary = UUID().uuidString
        req.setValue("multipart/form-data; boundary=\(boundary)", forHTTPHeaderField: "Content-Type")
        let audioData = try Data(contentsOf: fileURL)
        var body = Data()
        body.append("--\(boundary)\r\n".data(using: .utf8)!)
        body.append("Content-Disposition: form-data; name=\"audio\"; filename=\"recording.m4a\"\r\n".data(using: .utf8)!)
        body.append("Content-Type: audio/mp4\r\n\r\n".data(using: .utf8)!)
        body.append(audioData)
        body.append("\r\n--\(boundary)--\r\n".data(using: .utf8)!)
        req.httpBody = body
        let (data, _) = try await URLSession.shared.data(for: req)
        let envelope = try JSONDecoder().decode(APIResponse<RecognizeResponse>.self, from: data)
        if let err = envelope.error, !envelope.ok { throw APIError.server(err) }
        guard let payload = envelope.data else { throw APIError.server("Empty response") }
        return payload
    }

    // MARK: Admin cover proposals

    struct CoverProposal: Identifiable, Decodable {
        let id: Int
        let lyricsId: Int
        let spotifyUrl: String
        let note: String?
        let status: String
        let createdAt: String
        let songTitle: String
        let userName: String
        let bandName: String?
        enum CodingKeys: String, CodingKey {
            case id, note, status
            case lyricsId  = "lyrics_id"
            case spotifyUrl = "spotify_url"
            case createdAt = "created_at"
            case songTitle = "song_title"
            case userName  = "user_name"
            case bandName  = "band_name"
        }
    }

    func adminCoverProposals(status: String = "pending") async throws -> [CoverProposal] {
        try await request("admin/cover-proposals?status=\(status)")
    }

    @discardableResult
    func approveCoverProposal(id: Int) async throws -> MessageResponse {
        try await request("admin/cover-proposals/\(id)/approve", method: "POST")
    }

    @discardableResult
    func rejectCoverProposal(id: Int) async throws -> MessageResponse {
        try await request("admin/cover-proposals/\(id)/reject", method: "POST")
    }
}

extension Notification.Name {
    static let sessionExpired = Notification.Name("sessionExpired")
}
