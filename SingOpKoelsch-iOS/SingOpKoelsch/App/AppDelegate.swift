// SingOpKoelsch/App/AppDelegate.swift
import UIKit

class AppDelegate: NSObject, UIApplicationDelegate {
    func application(
        _ application: UIApplication,
        didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]? = nil
    ) -> Bool {
        // #36 Register Home Screen Quick Actions
        application.shortcutItems = [
            UIApplicationShortcutItem(
                type: "singopkoelsch.action.random",
                localizedTitle: "Zufälliger Song",
                localizedSubtitle: nil,
                icon: UIApplicationShortcutIcon(systemImageName: "shuffle"),
                userInfo: nil
            ),
            UIApplicationShortcutItem(
                type: "singopkoelsch.action.favorites",
                localizedTitle: "Favoriten",
                localizedSubtitle: nil,
                icon: UIApplicationShortcutIcon(systemImageName: "heart.fill"),
                userInfo: nil
            ),
            UIApplicationShortcutItem(
                type: "singopkoelsch.action.search",
                localizedTitle: "Suchen",
                localizedSubtitle: nil,
                icon: UIApplicationShortcutIcon(systemImageName: "magnifyingglass"),
                userInfo: nil
            )
        ]
        return true
    }

    // #36 Handle Quick Action selection
    func application(
        _ application: UIApplication,
        performActionFor shortcutItem: UIApplicationShortcutItem,
        completionHandler: @escaping (Bool) -> Void
    ) {
        let urlStr: String
        switch shortcutItem.type {
        case "singopkoelsch.action.random":
            urlStr = "singopkoelsch://random"
        case "singopkoelsch.action.favorites":
            urlStr = "singopkoelsch://favorites"
        case "singopkoelsch.action.search":
            urlStr = "singopkoelsch://search"
        default:
            completionHandler(false)
            return
        }
        NotificationCenter.default.post(name: .quickActionReceived, object: urlStr)
        completionHandler(true)
    }

    func application(
        _ application: UIApplication,
        didRegisterForRemoteNotificationsWithDeviceToken deviceToken: Data
    ) {
        Task {
            await NotificationManager.shared.handleDeviceToken(deviceToken)
        }
    }

    func application(
        _ application: UIApplication,
        didFailToRegisterForRemoteNotificationsWithError error: Error
    ) {
        print("APNs registration failed: \(error)")
    }

    func application(
        _ application: UIApplication,
        didReceiveRemoteNotification userInfo: [AnyHashable: Any],
        fetchCompletionHandler completionHandler: @escaping (UIBackgroundFetchResult) -> Void
    ) {
        NotificationManager.shared.handleRemoteNotification(userInfo)
        completionHandler(.newData)
    }
}
