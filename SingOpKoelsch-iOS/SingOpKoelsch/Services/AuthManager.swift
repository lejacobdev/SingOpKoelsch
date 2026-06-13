import Foundation
import Combine

@MainActor
final class AuthManager: ObservableObject {
    static let shared = AuthManager()

    @Published var isLoggedIn: Bool = false
    @Published var currentUser: AppUser?
    @Published var isLoading: Bool = false

    private var cancellables = Set<AnyCancellable>()
    private let api = APIClient.shared

    init() {
        isLoggedIn = Keychain.load(key: "auth_token") != nil

        NotificationCenter.default.publisher(for: .sessionExpired)
            .receive(on: RunLoop.main)
            .sink { [weak self] _ in self?.forceLogout() }
            .store(in: &cancellables)

        if isLoggedIn {
            Task { await loadCurrentUser() }
        }
    }

    func login(email: String, password: String) async throws {
        isLoading = true
        defer { isLoading = false }
        let resp = try await api.login(email: email, password: password)
        Keychain.save(resp.token, key: "auth_token")
        isLoggedIn = true
        currentUser = AppUser(
            userId: resp.userId,
            name: resp.name,
            email: resp.email,
            role: resp.role,
            profilePicture: resp.profilePicture,
            preferences: UserPreferences(darkMode: false, notifications: true, emailMaxPerDay: 1, lang: "de")
        )
        await loadCurrentUser()
    }

    func logout() async {
        try? await api.logout()
        forceLogout()
    }

    func forceLogout() {
        if let tok = Keychain.load(key: "device_token") {
            Task { try? await api.unregisterDeviceToken(tok) }
        }
        Keychain.delete(key: "auth_token")
        Keychain.delete(key: "device_token")
        currentUser = nil
        isLoggedIn = false
    }

    func loadCurrentUser() async {
        do {
            currentUser = try await api.me()
        } catch {
            // silent — token may have expired
        }
    }

    func refreshUser() async {
        await loadCurrentUser()
    }
}
