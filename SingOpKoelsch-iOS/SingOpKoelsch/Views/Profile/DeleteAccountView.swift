import SwiftUI

struct DeleteAccountView: View {
    @EnvironmentObject var auth: AuthManager
    @Environment(\.dismiss) var dismiss

    @State private var password   = ""
    @State private var confirmed  = ""
    @State private var loading    = false
    @State private var error      = ""
    @State private var showAlert  = false

    private let api = APIClient.shared
    private let confirmWord = "LOSCHEN"

    var body: some View {
        NavigationStack {
            Form {
                Section {
                    VStack(spacing: 12) {
                        Image(systemName: "exclamationmark.triangle.fill")
                            .font(.system(size: 44))
                            .foregroundStyle(.red)
                        Text("Konto unwiderruflich loeschen")
                            .font(.title3.bold())
                            .multilineTextAlignment(.center)
                        Text("Alle deine Daten, Vorschlaege und Einstellungen werden dauerhaft geloescht. Diese Aktion kann nicht rueckgaengig gemacht werden.")
                            .font(.subheadline)
                            .foregroundStyle(.secondary)
                            .multilineTextAlignment(.center)
                    }
                    .padding(.vertical, 8)
                    .frame(maxWidth: .infinity)
                }
                .listRowBackground(Color.clear)

                Section("Bestaetigung") {
                    SecureField("Aktuelles Passwort", text: $password)
                    TextField("Tippe \"\(confirmWord)\" zum Bestaetigen", text: $confirmed)
                        .autocapitalization(.allCharacters)
                        .autocorrectionDisabled()
                }

                if !error.isEmpty {
                    Section {
                        Text(error).foregroundStyle(.red).font(.subheadline)
                    }
                }

                Section {
                    Button(role: .destructive) {
                        showAlert = true
                    } label: {
                        HStack {
                            Spacer()
                            if loading {
                                ProgressView().tint(.red)
                            } else {
                                Text("Konto jetzt loeschen")
                                    .fontWeight(.semibold)
                            }
                            Spacer()
                        }
                    }
                    .disabled(loading || password.isEmpty || confirmed.uppercased() != confirmWord)
                }
            }
            .navigationTitle("Konto loeschen")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .topBarLeading) {
                    Button("Abbrechen") { dismiss() }
                        .foregroundStyle(Theme.koelschRed)
                }
            }
            .alert("Wirklich loeschen?", isPresented: $showAlert) {
                Button("Abbrechen", role: .cancel) {}
                Button("Loeschen", role: .destructive) { Task { await doDelete() } }
            } message: {
                Text("Dein Konto und alle Daten werden dauerhaft geloescht.")
            }
        }
    }

    private func doDelete() async {
        loading = true; error = ""
        do {
            try await api.deleteAccount(password: password)
            UINotificationFeedbackGenerator().notificationOccurred(.success)
            auth.forceLogout()
            dismiss()
        } catch {
            UINotificationFeedbackGenerator().notificationOccurred(.error)
            self.error = error.localizedDescription
        }
        loading = false
    }
}
