import SwiftUI

struct ForgotPasswordView: View {
    @Environment(\.dismiss) var dismiss
    @State private var email   = ""
    @State private var sent    = false
    @State private var error   = ""
    @State private var loading = false
    private let api = APIClient.shared

    var body: some View {
        NavigationStack {
            VStack(spacing: 24) {
                Spacer()
                Image(systemName: "lock.rotation")
                    .font(.system(size: 56))
                    .foregroundStyle(Theme.koelschRed)

                VStack(spacing: 8) {
                    Text("Passwort zurücksetzen")
                        .font(.title2.bold())
                    Text("Wir schicken dir einen Link zum Zurücksetzen per E-Mail.")
                        .multilineTextAlignment(.center)
                        .foregroundStyle(.secondary)
                }

                if sent {
                    HStack(spacing: 10) {
                        Image(systemName: "checkmark.circle.fill").foregroundStyle(.green)
                        Text("E-Mail wurde gesendet!").font(.subheadline)
                    }
                    .padding()
                    .background(Color.green.opacity(0.1))
                    .clipShape(RoundedRectangle(cornerRadius: 10))
                } else {
                    VStack(spacing: 12) {
                        TextField("E-Mail-Adresse", text: $email)
                            .keyboardType(.emailAddress)
                            .textContentType(.emailAddress)
                            .autocapitalization(.none)
                            .styledInput()

                        if !error.isEmpty { Text(error).errorText() }

                        Button("Link senden") { Task { await doSend() } }
                            .buttonStyle(PrimaryButtonStyle(isLoading: loading))
                            .disabled(loading || email.isEmpty)
                    }
                }

                Spacer()
            }
            .padding(.horizontal, 28)
            .navigationTitle("Passwort vergessen")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .topBarTrailing) {
                    Button("Fertig") { dismiss() }
                        .foregroundStyle(Theme.koelschRed)
                }
            }
        }
    }

    private func doSend() async {
        loading = true; error = ""
        do {
            try await api.forgotPassword(email: email)
            withAnimation { sent = true }
        } catch {
            self.error = error.localizedDescription
        }
        loading = false
    }
}
