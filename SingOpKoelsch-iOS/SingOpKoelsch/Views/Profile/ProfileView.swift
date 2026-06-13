import SwiftUI

struct ProfileView: View {
    @EnvironmentObject var auth: AuthManager
    @EnvironmentObject var notifications: NotificationManager
    @State private var showDeleteAccount = false
    @State private var editingName = false
    @State private var editingPassword = false
    @State private var newName = ""
    @State private var currentPw = ""
    @State private var newPw = ""
    @State private var confirmPw = ""
    @State private var error = ""
    @State private var successMsg = ""
    @State private var loading = false

    private let api = APIClient.shared

    var body: some View {
        NavigationStack {
            List {
                // Avatar / identity
                Section {
                    HStack(spacing: 16) {
                        avatarView
                        VStack(alignment: .leading, spacing: 4) {
                            Text(auth.currentUser?.name ?? "")
                                .font(.title3.bold())
                            Text(auth.currentUser?.email ?? "")
                                .font(.subheadline)
                                .foregroundStyle(.secondary)
                            if auth.currentUser?.isAdmin == true {
                                Text("Admin")
                                    .font(.caption.bold())
                                    .padding(.horizontal, 8).padding(.vertical, 2)
                                    .background(Theme.koelschRed.opacity(0.12))
                                    .foregroundStyle(Theme.koelschRed)
                                    .clipShape(Capsule())
                            }
                        }
                    }
                    .padding(.vertical, 8)
                }

                // Messages
                if !successMsg.isEmpty {
                    Section {
                        HStack {
                            Image(systemName: "checkmark.circle.fill").foregroundStyle(.green)
                            Text(successMsg)
                        }
                    }
                }
                if !error.isEmpty {
                    Section {
                        HStack {
                            Image(systemName: "exclamationmark.circle.fill").foregroundStyle(.red)
                            Text(error)
                        }
                    }
                }

                // Display name
                Section("Anzeigename") {
                    if editingName {
                        VStack(alignment: .leading, spacing: 8) {
                            TextField("Name", text: $newName)
                            HStack {
                                Button("Abbrechen") {
                                    withAnimation { editingName = false }
                                    newName = auth.currentUser?.name ?? ""
                                }
                                .foregroundStyle(.secondary)
                                Spacer()
                                Button("Speichern") { Task { await saveName() } }
                                    .foregroundStyle(Theme.primary)
                                    .disabled(loading || newName.count < 2)
                            }
                        }
                    } else {
                        Button {
                            newName = auth.currentUser?.name ?? ""
                            withAnimation { editingName = true }
                        } label: {
                            HStack {
                                Text(auth.currentUser?.name ?? "–")
                                Spacer()
                                Image(systemName: "pencil")
                                    .foregroundStyle(Theme.primary)
                            }
                        }
                        .foregroundStyle(.primary)
                    }
                }

                // Password
                Section("Passwort") {
                    if editingPassword {
                        VStack(alignment: .leading, spacing: 8) {
                            SecureField("Aktuelles Passwort", text: $currentPw)
                            SecureField("Neues Passwort", text: $newPw)
                            SecureField("Bestätigen", text: $confirmPw)
                            HStack {
                                Button("Abbrechen") {
                                    withAnimation { editingPassword = false }
                                    currentPw = ""; newPw = ""; confirmPw = ""
                                }
                                .foregroundStyle(.secondary)
                                Spacer()
                                Button("Speichern") { Task { await savePassword() } }
                                    .foregroundStyle(Theme.primary)
                                    .disabled(loading)
                            }
                        }
                    } else {
                        Button {
                            withAnimation { editingPassword = true }
                        } label: {
                            HStack {
                                Text("Passwort ändern")
                                Spacer()
                                Image(systemName: "chevron.right")
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                            }
                        }
                        .foregroundStyle(.primary)
                    }
                }

                // Notification settings
                Section("Benachrichtigungen") {
                    if let prefs = auth.currentUser?.preferences {
                        Toggle(isOn: .init(
                            get: { prefs.notifications },
                            set: { _ in Task { await toggleNotifications() } }
                        )) {
                            Label("Push-Benachrichtigungen", systemImage: "bell.badge")
                        }
                        .tint(Theme.primary)

                        if !notifications.permissionGranted && prefs.notifications {
                            Button {
                                Task { await notifications.requestPermission() }
                            } label: {
                                Label("Systemberechtigung erteilen", systemImage: "bell.badge.fill")
                                    .foregroundStyle(Theme.koelschRed)
                            }
                        }

                        Stepper("Max. \(prefs.emailMaxPerDay)×/Tag", value: .init(
                            get: { prefs.emailMaxPerDay },
                            set: { val in Task { await updateMaxPerDay(val) } }
                        ), in: 1...24)
                        .disabled(!prefs.notifications)
                    }
                }

                // Language
                Section("Sprache") {
                    if let prefs = auth.currentUser?.preferences {
                        Picker("Sprache", selection: .init(
                            get: { prefs.lang },
                            set: { val in Task { await updateLang(val) } }
                        )) {
                            Text("Deutsch").tag("de")
                            Text("English").tag("en")
                            Text("Kölsch").tag("koelsch")
                        }
                        .pickerStyle(.segmented)
                    }
                }

                // Logout
                Section {
                    Button(role: .destructive) {
                        Task { await auth.logout() }
                    } label: {
                        HStack {
                            Spacer()
                            Label("Abmelden", systemImage: "rectangle.portrait.and.arrow.right")
                            Spacer()
                        }
                    }
                    Button(role: .destructive) {
                        showDeleteAccount = true
                    } label: {
                        HStack {
                            Spacer()
                            Label("Konto loeschen", systemImage: "person.crop.circle.badge.minus")
                            Spacer()
                        }
                    }
                }
                .sheet(isPresented: $showDeleteAccount) {
                    DeleteAccountView()
                }
            }
            .listStyle(.insetGrouped)
            .scrollContentBackground(.hidden)
            .listRowBackground(Theme.card)
            .background(Theme.bg.ignoresSafeArea())
            .navigationTitle("Profil")
            .refreshable { await auth.refreshUser() }
        }
    }

    // MARK: - Computed

    @ViewBuilder
    private var avatarView: some View {
        ZStack {
            Circle()
                .fill(Theme.koelschRed.opacity(0.15))
                .frame(width: 64, height: 64)
            Text(String((auth.currentUser?.name ?? "U").prefix(1)).uppercased())
                .font(.system(.title, design: .default).bold())
                .foregroundStyle(Theme.koelschRed)
        }
    }

    // MARK: - Actions

    private func saveName() async {
        loading = true; error = ""; successMsg = ""
        do {
            try await api.updateName(newName)
            await auth.refreshUser()
            UINotificationFeedbackGenerator().notificationOccurred(.success)
            successMsg = "Name gespeichert."
            withAnimation { editingName = false }
        } catch {
            UINotificationFeedbackGenerator().notificationOccurred(.error)
            self.error = error.localizedDescription
        }
        loading = false
    }

    private func savePassword() async {
        guard newPw == confirmPw else { error = "Passwörter stimmen nicht überein."; return }
        guard newPw.count >= 6 else { error = "Mindestens 6 Zeichen."; return }
        loading = true; error = ""; successMsg = ""
        do {
            try await api.changePassword(current: currentPw, new: newPw)
            UINotificationFeedbackGenerator().notificationOccurred(.success)
            successMsg = "Passwort geändert."
            withAnimation { editingPassword = false }
            currentPw = ""; newPw = ""; confirmPw = ""
        } catch {
            UINotificationFeedbackGenerator().notificationOccurred(.error)
            self.error = error.localizedDescription
        }
        loading = false
    }

    private func toggleNotifications() async {
        guard let prefs = auth.currentUser?.preferences else { return }
        let newVal = !prefs.notifications
        _ = try? await api.updatePreferences(["notifications": newVal])
        await auth.refreshUser()
    }

    private func updateMaxPerDay(_ val: Int) async {
        _ = try? await api.updatePreferences(["email_max_per_day": val])
        await auth.refreshUser()
    }

    private func updateLang(_ lang: String) async {
        _ = try? await api.updatePreferences(["lang": lang])
        await auth.refreshUser()
    }
}
