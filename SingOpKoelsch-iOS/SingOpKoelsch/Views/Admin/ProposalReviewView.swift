import SwiftUI

struct ProposalReviewView: View {
    let proposal: Proposal
    let onDecision: () -> Void

    @Environment(\.dismiss) var dismiss
    @EnvironmentObject var auth: AuthManager
    @State private var decided = false
    @State private var decision = ""
    @State private var loading = false
    @State private var error = ""
    private let api = APIClient.shared

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 20) {
                // Header
                VStack(alignment: .leading, spacing: 6) {
                    HStack {
                        Text(proposal.songTitle)
                            .font(.title2.bold())
                        Spacer()
                        StatusBadge(status: proposal.status)
                    }
                    if let band = proposal.bandName {
                        Text(band).font(.subheadline).foregroundStyle(.secondary)
                    }
                    HStack(spacing: 12) {
                        if let name = proposal.userName {
                            Label(name, systemImage: "person")
                                .font(.caption)
                                .foregroundStyle(.secondary)
                        }
                        Label(proposal.createdAt.prefix(10), systemImage: "calendar")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    }
                }
                .padding()
                .frame(maxWidth: .infinity, alignment: .leading)
                .background(Theme.bgAlt)
                .clipShape(RoundedRectangle(cornerRadius: 12))

                if decided {
                    // Success state
                    VStack(spacing: 12) {
                        Image(systemName: decision == "approved" ? "checkmark.circle.fill" : "xmark.circle.fill")
                            .font(.system(size: 52))
                            .foregroundStyle(decision == "approved" ? Color.green : Color.red)
                        Text(decision == "approved" ? "Angenommen!" : "Abgelehnt!")
                            .font(.title3.bold())
                        Text("Der Nutzer wurde per Push-Benachrichtigung informiert.")
                            .font(.subheadline)
                            .foregroundStyle(.secondary)
                            .multilineTextAlignment(.center)
                    }
                    .frame(maxWidth: .infinity)
                    .padding(.vertical, 40)
                } else {
                    // Current lyrics vs proposed
                    if let current = proposal.currentLyrics, !current.isEmpty,
                       let proposed = proposal.proposedLyrics, !proposed.isEmpty {
                        LyricsDiffView(current: current, proposed: proposed)
                    } else if let proposed = proposal.proposedLyrics {
                        VStack(alignment: .leading, spacing: 8) {
                            Text("Vorgeschlagener Text").font(.headline)
                            Text(proposed)
                                .font(Theme.lyricsFont)
                                .lineSpacing(4)
                                .textSelection(.enabled)
                        }
                        .padding()
                        .background(Theme.bgAlt)
                        .clipShape(RoundedRectangle(cornerRadius: 12))
                    }

                    if !error.isEmpty { ErrorBanner(message: error) }

                    // Action buttons (only for pending)
                    if proposal.status == "pending" {
                        HStack(spacing: 12) {
                            Button {
                                Task { await decide(approve: false) }
                            } label: {
                                Label("Ablehnen", systemImage: "xmark.circle")
                            }
                            .buttonStyle(SecondaryButtonStyle())
                            .foregroundStyle(.red)
                            .disabled(loading)

                            Button {
                                Task { await decide(approve: true) }
                            } label: {
                                if loading {
                                    ProgressView().tint(.white)
                                } else {
                                    Label("Annehmen", systemImage: "checkmark.circle")
                                }
                            }
                            .buttonStyle(PrimaryButtonStyle())
                            .disabled(loading)
                        }
                        .padding(.horizontal)
                    }
                }
            }
            .padding()
        }
        .background(Theme.bg.ignoresSafeArea())
        .navigationTitle("Vorschlag prüfen")
        .navigationBarTitleDisplayMode(.inline)
    }

    private func decide(approve: Bool) async {
        loading = true; error = ""
        do {
            if approve {
                try await api.approveProposal(id: proposal.id)
                UINotificationFeedbackGenerator().notificationOccurred(.success)
            } else {
                try await api.rejectProposal(id: proposal.id)
                UINotificationFeedbackGenerator().notificationOccurred(.warning)
            }
            withAnimation {
                decision = approve ? "approved" : "rejected"
                decided = true
            }
            onDecision()
        } catch {
            UINotificationFeedbackGenerator().notificationOccurred(.error)
            self.error = error.localizedDescription
        }
        loading = false
    }
}

// Naive diff: show current + proposed side by side on iPad, stacked on iPhone
private struct LyricsDiffView: View {
    let current: String
    let proposed: String
    @Environment(\.horizontalSizeClass) var hSizeClass

    var body: some View {
        Group {
            if hSizeClass == .regular {
                HStack(alignment: .top, spacing: 12) {
                    pane(label: "Aktuell", text: current, tint: .secondary)
                    pane(label: "Vorschlag", text: proposed, tint: Theme.koelschRed)
                }
            } else {
                VStack(alignment: .leading, spacing: 12) {
                    pane(label: "Aktuell", text: current, tint: .secondary)
                    pane(label: "Vorschlag", text: proposed, tint: Theme.koelschRed)
                }
            }
        }
    }

    private func pane(label: String, text: String, tint: Color) -> some View {
        VStack(alignment: .leading, spacing: 6) {
            Text(label)
                .font(.caption.bold())
                .foregroundStyle(tint)
            Text(text)
                .font(Theme.lyricsFont)
                .lineSpacing(4)
                .textSelection(.enabled)
        }
        .padding()
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(tint.opacity(0.06))
        .clipShape(RoundedRectangle(cornerRadius: 10))
    }
}
