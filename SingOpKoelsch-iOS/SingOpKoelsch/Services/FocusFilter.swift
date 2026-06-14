// SingOpKoelsch/Services/FocusFilter.swift
// #37 Focus Filter — disables push notifications when Focus mode is active

import AppIntents

struct SingOpKoelschFocusFilter: SetFocusFilterIntent {
    static var title: LocalizedStringResource = "Sing op Kölsch"
    static var description: IntentDescription? = IntentDescription(
        "Legt fest, ob Sing op Kölsch Benachrichtigungen senden darf, wenn ein Fokus-Modus aktiv ist."
    )

    var displayRepresentation: DisplayRepresentation {
        DisplayRepresentation(title: "Sing op Kölsch")
    }

    /// When false, the app should suppress push notifications
    @Parameter(title: "Benachrichtigungen senden", default: true)
    var sendNotifications: Bool

    func perform() async throws -> some IntentResult {
        // Persist the focus-filter preference so NotificationManager can read it
        UserDefaults.standard.set(sendNotifications, forKey: "focus_filter_send_notifications")
        NotificationCenter.default.post(
            name: .focusFilterChanged,
            object: sendNotifications
        )
        return .result()
    }
}

// focusFilterChanged is declared in Notifications.swift
