import SwiftUI

@MainActor
final class MyProposalsViewModel: ObservableObject {
    @Published var proposals: [Proposal] = []
    @Published var isLoading = false
    @Published var error: String?
    @Published var filter: String? = nil   // nil = all

    private let api = APIClient.shared

    func load() async {
        isLoading = true; error = nil
        do { proposals = try await api.myProposals(status: filter) }
        catch { self.error = error.localizedDescription }
        isLoading = false
    }
}

struct MyProposalsView: View {
    @StateObject private var vm = MyProposalsViewModel()

    var body: some View {
        NavigationStack {
            Group {
                if vm.isLoading {
                    ProgressView().tint(Theme.primary)
                        .frame(maxWidth: .infinity, maxHeight: .infinity)
                } else if vm.proposals.isEmpty {
                    EmptyStateView(
                        icon: "square.and.pencil",
                        title: "Keine Vorschläge",
                        subtitle: "Schlage Textänderungen in einem Lied vor und sie erscheinen hier."
                    )
                } else {
                    List {
                        if let err = vm.error {
                            ErrorBanner(message: err).listRowBackground(Color.clear).listRowInsets(.init())
                        }
                        ForEach(vm.proposals) { p in
                            ProposalRow(proposal: p)
                        }
                    }
                    .listStyle(.insetGrouped)
                    .scrollContentBackground(.hidden)
                    .listRowBackground(Theme.card)
                }
            }
            .background(Theme.bg.ignoresSafeArea())
            .navigationTitle("Meine Vorschläge")
            .toolbar {
                ToolbarItem(placement: .topBarTrailing) {
                    Menu {
                        Button("Alle")        { vm.filter = nil;        Task { await vm.load() } }
                        Button("Ausstehend")  { vm.filter = "pending";  Task { await vm.load() } }
                        Button("Angenommen") { vm.filter = "approved"; Task { await vm.load() } }
                        Button("Abgelehnt")  { vm.filter = "rejected"; Task { await vm.load() } }
                    } label: {
                        Image(systemName: "line.3.horizontal.decrease.circle")
                    }
                }
            }
            .refreshable { await vm.load() }
        }
        .task { await vm.load() }
    }
}

struct ProposalRow: View {
    let proposal: Proposal
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
            HStack(spacing: 6) {
                Image(systemName: "calendar")
                    .font(.caption2)
                    .foregroundStyle(.secondary)
                Text(proposal.createdAt.prefix(10))
                    .font(.caption)
                    .foregroundStyle(.secondary)
                if let resolved = proposal.resolvedAt {
                    Text("→")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                    Text(resolved.prefix(10))
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
            }
        }
        .padding(.vertical, 4)
    }
}
