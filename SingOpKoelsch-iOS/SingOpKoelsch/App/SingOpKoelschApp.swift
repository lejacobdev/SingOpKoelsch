import SwiftUI

@main
struct SingOpKoelschApp: App {
    @UIApplicationDelegateAdaptor(AppDelegate.self) var delegate
    @StateObject private var auth = AuthManager.shared
    @StateObject private var notifications = NotificationManager.shared

    init() {
        // #45 Switch to Karneval app icon during Karneval season
        AppIconManager.updateAppIconIfNeeded()
    }

    var body: some Scene {
        WindowGroup {
            RootView()
                .environmentObject(auth)
                .environmentObject(notifications)
                .tint(Theme.primary)
        }
    }
}
