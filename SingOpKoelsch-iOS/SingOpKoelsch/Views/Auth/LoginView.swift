import SwiftUI

struct LoginView: View {
    let onRegister: () -> Void
    let onForgot: () -> Void

    @EnvironmentObject var auth: AuthManager
    @State private var email    = ""
    @State private var password = ""
    @State private var error    = ""
    @State private var loading  = false
    @FocusState private var focused: Field?

    enum Field { case email, password }

    var body: some View {
        VStack(spacing: 20) {
            Text("Anmelden")
                .font(.title2.bold())
                .frame(maxWidth: .infinity, alignment: .leading)
                .padding(.top, 28)

            VStack(spacing: 12) {
                TextField("E-Mail", text: $email)
                    .keyboardType(.emailAddress)
                    .textContentType(.emailAddress)
                    .autocapitalization(.none)
                    .autocorrectionDisabled()
                    .focused($focused, equals: .email)
                    .submitLabel(.next)
                    .onSubmit { focused = .password }
                    .styledInput()

                SecureField("Passwort", text: $password)
                    .textContentType(.password)
                    .focused($focused, equals: .password)
                    .submitLabel(.go)
                    .onSubmit { Task { await doLogin() } }
                    .styledInput()
            }

            if !error.isEmpty {
                Text(error).errorText()
            }

            Button("Anmelden") { Task { await doLogin() } }
                .buttonStyle(PrimaryButtonStyle(isLoading: loading))
                .disabled(loading)

            Button("Passwort vergessen?") { onForgot() }
                .font(.subheadline)
                .foregroundStyle(Theme.primary)

            Divider()

            Button {
                onRegister()
            } label: {
                Text("Noch kein Konto? ") + Text("Registrieren").bold()
            }
            .font(.subheadline)
            .foregroundStyle(Theme.primary)
            .padding(.bottom, 28)
        }
        .padding(.horizontal, 24)
    }

    private func doLogin() async {
        guard !email.isEmpty, !password.isEmpty else {
            error = "Bitte alle Felder ausfüllen."; return
        }
        loading = true; error = ""
        do {
            try await auth.login(email: email, password: password)
        } catch {
            UINotificationFeedbackGenerator().notificationOccurred(.error)
            self.error = error.localizedDescription
        }
        loading = false
    }
}

extension View {
    func styledInput() -> some View {
        self
            .padding(14)
            .background(Theme.bgAlt)
            .clipShape(RoundedRectangle(cornerRadius: Theme.radius, style: .continuous))
            .overlay(
                RoundedRectangle(cornerRadius: Theme.radius, style: .continuous)
                    .strokeBorder(Theme.border, lineWidth: 1.5)
            )
    }
}
