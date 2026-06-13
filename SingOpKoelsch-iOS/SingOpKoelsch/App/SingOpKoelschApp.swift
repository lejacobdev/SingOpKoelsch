import SwiftUI

@main
struct SingOpKoelschApp: App {
    @UIApplicationDelegateAdaptor(AppDelegate.self) var delegate
    @StateObject private var auth = AuthManager.shared
    @StateObject private var notifications = NotificationManager.shared

    var body: some Scene {
        WindowGroup {
            RootView()
                .environmentObject(auth)
                .environmentObject(notifications)
                .tint(Theme.primary)
        }
    }
}
