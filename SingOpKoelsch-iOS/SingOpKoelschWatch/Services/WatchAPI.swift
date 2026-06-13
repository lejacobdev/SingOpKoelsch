import Foundation

final class WatchAPI {
    static let shared = WatchAPI()
    private init() {}

    private let base = "https://singopkoelsch.de/api"

    func songs(page: Int = 1, perPage: Int = 40) async throws -> WatchSongsPage {
        try await fetch("songs?page=\(page)&per_page=\(perPage)")
    }

    func songDetail(id: Int) async throws -> WatchSongDetail {
        try await fetch("songs/\(id)")
    }

    private func fetch<T: Decodable>(_ path: String) async throws -> T {
        guard let url = URL(string: "\(base)/\(path)") else { throw URLError(.badURL) }
        var request = URLRequest(url: url, timeoutInterval: 15)
        request.setValue("SingOpKoelschWatch/1.0", forHTTPHeaderField: "User-Agent")
        let (data, _) = try await URLSession.shared.data(for: request)
        let envelope = try JSONDecoder().decode(WAPIResponse<T>.self, from: data)
        guard let result = envelope.data else { throw URLError(.badServerResponse) }
        return result
    }
}
