import SwiftUI

struct RegisterView: View {
    let onBack: () -> Void

    @State private var name     = ""
    @State private var email    = ""
    @State private var password = ""
    @State private var confirm  = ""
    @State private var error    = ""
    @State private var loading  = false
    @State private var success  = false
    @FocusState private var focused: Field?

    private let api = APIClient.shared

    enum Field { case name, email, password, confirm }

    var body: some View {
        VStack(spacing: 20) {
            HStack {
                Button(action: onBack) {
                    Image(systemName: "chevron.left")
                        .fontWeight(.semibold)
                        .foregroundStyle(Theme.primary)
                }
                Text("Registrieren")
                    .font(.title2.bold())
                Spacer()
            }
            .padding(.top, 28)

            if success {
                VStack(spacing: 12) {
                    Image(systemName: "envelope.badge.fill")
                        .font(.system(size: 48))
                        .foregroundStyle(Theme.koelschRed)
                    Text("Bestätigungs-E-Mail gesendet")
                        .font(.headline)
                    Text("Bitte prüfe dein Postfach und bestätige deine E-Mail-Adresse.")
                        .multilineTextAlignment(.center)
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                    Button("Zurück zur Anmeldung", action: onBack)
                        .buttonStyle(PrimaryButtonStyle())
                }
                .padding(.vertical, 20)
            } else {
                VStack(spacing: 12) {
                    TextField("Anzeigename", text: $name)
                        .textContentType(.name)
                        .focused($focused, equals: .name)
                        .submitLabel(.next)
                        .onSubmit { focused = .email }
                        .styledInput()

                    TextField("E-Mail", text: $email)
                        .keyboardType(.emailAddress)
                        .textContentType(.emailAddress)
                        .autocapitalization(.none)
                        .autocorrectionDisabled()
                        .focused($focused, equals: .email)
                        .submitLabel(.next)
                        .onSubmit { focused = .password }
                        .styledInput()

                    SecureField("Passwort (min. 6 Zeichen)", text: $password)
                        .textContentType(.newPassword)
                        .focused($focused, equals: .password)
                        .submitLabel(.next)
                        .onSubmit { focused = .confirm }
                        .styledInput()

                    SecureField("Passwort bestätigen", text: $confirm)
                        .textContentType(.newPassword)
                        .focused($focused, equals: .confirm)
                        .submitLabel(.go)
                        .onSubmit { Task { await doRegister() } }
                        .styledInput()
                }

                if !error.isEmpty { Text(error).errorText() }

                Button("Konto erstellen") { Task { await doRegister() } }
                    .buttonStyle(PrimaryButtonStyle(isLoading: loading))
                    .disabled(loading)
            }

            Spacer().frame(height: 28)
        }
        .padding(.horizontal, 24)
    }

    private func doRegister() async {
        guard !name.isEmpty, !email.isEmpty, !password.isEmpty else { error = "Bitte alle Felder ausfüllen."; return }
        guard password == confirm else { error = "Passwörter stimmen nicht überein."; return }
        guard password.count >= 6  else { error = "Passwort muss mindestens 6 Zeichen lang sein."; return }

        loading = true; error = ""
        do {
            try await api.register(name: name, email: email, password: password)
            UINotificationFeedbackGenerator().notificationOccurred(.success)
            withAnimation { success = true }
        } catch {
            UINotificationFeedbackGenerator().notificationOccurred(.error)
            self.error = error.localizedDescription
        }
        loading = false
    }
}
