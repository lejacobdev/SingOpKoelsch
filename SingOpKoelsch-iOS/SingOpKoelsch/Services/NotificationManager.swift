import Foundation
import UserNotifications
import UIKit

@MainActor
final class NotificationManager: NSObject, ObservableObject {
    static let shared = NotificationManager()
    private let api = APIClient.shared

    @Published var permissionGranted: Bool = false

    override init() {
        super.init()
        UNUserNotificationCenter.current().delegate = self
        checkPermission()
    }

    func requestPermission() async {
        do {
            let granted = try await UNUserNotificationCenter.current()
                .requestAuthorization(options: [.alert, .badge, .sound])
            permissionGranted = granted
            if granted {
                await MainActor.run {
                    UIApplication.shared.registerForRemoteNotifications()
                }
            }
        } catch {
            permissionGranted = false
        }
    }

    func checkPermission() {
        UNUserNotificationCenter.current().getNotificationSettings { settings in
            Task { @MainActor in
                self.permissionGranted = settings.authorizationStatus == .authorized
            }
        }
    }

    func handleDeviceToken(_ tokenData: Data) async {
        let token = tokenData.map { String(format: "%02.2hhx", $0) }.joined()
        Keychain.save(token, key: "device_token")
        do {
            // Use sandbox = true while in development; change to false for production
            try await api.registerDeviceToken(token, sandbox: true)
        } catch {
            print("Failed to register device token: \(error)")
        }
    }

    func handleRemoteNotification(_ userInfo: [AnyHashable: Any]) {
        // Badge reset
        UNUserNotificationCenter.current().setBadgeCount(0) { _ in }

        // Route to correct screen based on notification type
        if let extra = userInfo["extra"] as? [String: Any],
           let type = extra["type"] as? String {
            NotificationCenter.default.post(
                name: .pushNotificationReceived,
                object: nil,
                userInfo: ["type": type, "extra": extra]
            )
        }
    }
}

extension NotificationManager: @preconcurrency UNUserNotificationCenterDelegate {
    func userNotificationCenter(
        _ center: UNUserNotificationCenter,
        willPresent notification: UNNotification,
        withCompletionHandler completionHandler: @escaping (UNNotificationPresentationOptions) -> Void
    ) {
        completionHandler([.banner, .sound, .badge])
    }

    func userNotificationCenter(
        _ center: UNUserNotificationCenter,
        didReceive response: UNNotificationResponse,
        withCompletionHandler completionHandler: @escaping () -> Void
    ) {
        handleRemoteNotification(response.notification.request.content.userInfo)
        completionHandler()
    }
}

extension Notification.Name {
    static let pushNotificationReceived = Notification.Name("pushNotificationReceived")
}
