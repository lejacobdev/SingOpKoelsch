import SwiftUI

@MainActor
final class CoverProposalsViewModel: ObservableObject {
    @Published var proposals: [APIClient.CoverProposal] = []
    @Published var statusFilter = "pending"
    @Published var isLoading = false
    @Published var error: String?
    private let api = APIClient.shared

    func load() async {
        isLoading = true; error = nil
        do { proposals = try await api.adminCoverProposals(status: statusFilter) }
        catch { self.error = error.localizedDescription }
        isLoading = false
    }

    func approve(id: Int) async {
        do {
            try await api.approveCoverProposal(id: id)
            UINotificationFeedbackGenerator().notificationOccurred(.success)
            proposals.removeAll { $0.id == id }
        } catch { self.error = error.localizedDescription }
    }

    func reject(id: Int) async {
        do {
            try await api.rejectCoverProposal(id: id)
            UINotificationFeedbackGenerator().notificationOccurred(.warning)
            proposals.removeAll { $0.id == id }
        } catch { self.error = error.localizedDescription }
    }
}

struct CoverProposalsView: View {
    @StateObject private var vm = CoverProposalsViewModel()

    var body: some View {
        Group {
            if vm.isLoading {
                ProgressView().tint(Theme.koelschRed)
                    .frame(maxWidth: .infinity, maxHeight: .infinity)
            } else if vm.proposals.isEmpty && vm.error == nil {
                EmptyStateView(icon: "photo.badge.checkmark", title: "Keine Covervorschlaege",
                               subtitle: "Alles geprueft.")
            } else {
                List {
                    if let err = vm.error { ErrorBanner(message: err).listRowBackground(Color.clear).listRowInsets(.init()) }

                    Picker("Status", selection: $vm.statusFilter) {
                        Text("Ausstehend").tag("pending")
                        Text("Genehmigt").tag("approved")
                        Text("Abgelehnt").tag("rejected")
                    }
                    .pickerStyle(.segmented)
                    .listRowBackground(Color.clear)
                    .listRowInsets(EdgeInsets(top: 4, leading: 0, bottom: 4, trailing: 0))
                    .onChange(of: vm.statusFilter) { Task { await vm.load() } }

                    ForEach(vm.proposals) { p in
                        CoverProposalRow(proposal: p)
                            .swipeActions(edge: .trailing) {
                                Button(role: .destructive) { Task { await vm.reject(id: p.id) } }
                                label: { Label("Ablehnen", systemImage: "xmark") }
                            }
                            .swipeActions(edge: .leading) {
                                Button { Task { await vm.approve(id: p.id) } }
                                label: { Label("Annehmen", systemImage: "checkmark") }
                                    .tint(.green)
                            }
                    }
                }
                .listStyle(.insetGrouped)
            }
        }
        .navigationTitle("Covervorschlaege")
        .refreshable { await vm.load() }
        .task { await vm.load() }
    }
}

private struct CoverProposalRow: View {
    let proposal: APIClient.CoverProposal
    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack {
                VStack(alignment: .leading, spacing: 2) {
                    Text(proposal.songTitle).font(.headline).lineLimit(1)
                    if let band = proposal.bandName {
                        Text(band).font(.caption).foregroundStyle(.secondary)
                    }
                }
                Spacer()
                StatusBadge(status: proposal.status)
            }
            if let url = URL(string: proposal.spotifyUrl), !proposal.spotifyUrl.isEmpty {
                Link(destination: url) {
                    Label(proposal.spotifyUrl, systemImage: "link")
                        .font(.caption)
                        .lineLimit(1)
                }
            }
            HStack(spacing: 6) {
                Text("von \(proposal.userName)").font(.caption2).foregroundStyle(.secondary)
                Text("·").foregroundStyle(.secondary)
                Text(proposal.createdAt.prefix(10)).font(.caption2).foregroundStyle(.secondary)
            }
            if let note = proposal.note, !note.isEmpty {
                Text(note).font(.caption).italic().foregroundStyle(.secondary)
            }
        }
        .padding(.vertical, 4)
    }
}
