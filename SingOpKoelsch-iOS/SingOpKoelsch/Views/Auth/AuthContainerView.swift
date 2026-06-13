import SwiftUI

struct AuthContainerView: View {
    @State private var showRegister = false
    @State private var showForgot   = false

    var body: some View {
        NavigationStack {
            ZStack {
                // Background gradient
                LinearGradient(
                    colors: [Theme.navBg, Theme.navBg.opacity(0.85), Theme.bg],
                    startPoint: .top, endPoint: .bottom
                )
                .ignoresSafeArea()

                VStack(spacing: 0) {
                    // Header
                    VStack(spacing: 12) {
                        KoelschLogo(size: 72)
                            .padding(.top, 60)
                        Text("Sing op Kölsch")
                            .font(.system(.largeTitle, design: .default).bold())
                            .foregroundStyle(.white)
                        Text("Dein Kölsch-Liederbuch")
                            .font(.subheadline)
                            .foregroundStyle(.white.opacity(0.85))
                    }
                    .padding(.bottom, 40)

                    // Auth card
                    VStack(spacing: 0) {
                        if showRegister {
                            RegisterView(onBack: { withAnimation { showRegister = false } })
                                .transition(.asymmetric(
                                    insertion: .move(edge: .trailing),
                                    removal: .move(edge: .leading)
                                ))
                        } else {
                            LoginView(
                                onRegister: { withAnimation { showRegister = true } },
                                onForgot:   { showForgot = true }
                            )
                            .transition(.asymmetric(
                                insertion: .move(edge: .leading),
                                removal: .move(edge: .trailing)
                            ))
                        }
                    }
                    .animation(.spring(duration: 0.35), value: showRegister)
                    .background(Theme.card)
                    .clipShape(RoundedRectangle(cornerRadius: 24, style: .continuous))
                    .shadow(color: .black.opacity(0.15), radius: 20, y: 8)
                    .padding(.horizontal, 20)

                    Spacer()
                }
            }
        }
        .sheet(isPresented: $showForgot) {
            ForgotPasswordView()
        }
    }
}
