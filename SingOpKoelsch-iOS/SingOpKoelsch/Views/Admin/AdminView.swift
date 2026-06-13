import SwiftUI

@MainActor
final class AdminViewModel: ObservableObject {
    @Published var stats: AdminStats?
    @Published var proposals: [Proposal] = []
    @Published var statusFilter = "pending"
    @Published var isLoading = false
    @Published var error: String?

    private let api = APIClient.shared

    func load() async {
        isLoading = true; error = nil
        async let statsResult  = api.adminStats()
        async let propsResult  = api.adminProposals(status: statusFilter)
        do {
            stats     = try await statsResult
            proposals = try await propsResult
        } catch {
            self.error = error.localizedDescription
        }
        isLoading = false
    }

    func loadProposals() async {
        do { proposals = try await api.adminProposals(status: statusFilter) }
        catch { self.error = error.localizedDescription }
    }

    func approve(id: Int) async {
        do {
            try await api.approveProposal(id: id)
            UINotificationFeedbackGenerator().notificationOccurred(.success)
            proposals.removeAll { $0.id == id }
            if let s = stats {
                stats = AdminStats(
                    stats: StatsData(
                        totalSongs: s.stats.totalSongs,
                        totalUsers: s.stats.totalUsers,
                        totalBands: s.stats.totalBands,
                        pendingChanges: max(0, s.stats.pendingChanges - 1),
                        approvedChanges: s.stats.approvedChanges + 1,
                        rejectedChanges: s.stats.rejectedChanges
                    ),
                    topBands: s.topBands
                )
            }
        } catch {
            self.error = error.localizedDescription
        }
    }

    func reject(id: Int) async {
        do {
            try await api.rejectProposal(id: id)
            UINotificationFeedbackGenerator().notificationOccurred(.warning)
            proposals.removeAll { $0.id == id }
            if let s = stats {
                stats = AdminStats(
                    stats: StatsData(
                        totalSongs: s.stats.totalSongs,
                        totalUsers: s.stats.totalUsers,
                        totalBands: s.stats.totalBands,
                        pendingChanges: max(0, s.stats.pendingChanges - 1),
                        approvedChanges: s.stats.approvedChanges,
                        rejectedChanges: s.stats.rejectedChanges + 1
                    ),
                    topBands: s.topBands
                )
            }
        } catch {
            self.error = error.localizedDescription
        }
    }
}

struct AdminView: View {
    @StateObject private var vm = AdminViewModel()
    @State private var adminTab = 0

    var body: some View {
        NavigationStack {
            VStack(spacing: 0) {
                Picker("", selection: $adminTab) {
                    Text("Vorschlaege").tag(0)
                    Text("Cover").tag(1)
                    Text("Liederbuch").tag(2)
                }
                .pickerStyle(.segmented)
                .padding(.horizontal)
                .padding(.vertical, 8)
                .background(Theme.card)

                if adminTab == 1 {
                    CoverProposalsView()
                } else if adminTab == 2 {
                    SongbookView()
                } else {
                    proposalsList
                }
            }
            .background(Theme.bg.ignoresSafeArea())
            .navigationTitle("Admin")
            .refreshable { if adminTab == 0 { await vm.load() } }
        }
        .task { await vm.load() }
    }

    var proposalsList: some View {
        List {
                // Stats cards
                if let stats = vm.stats?.stats {
                    Section("Übersicht") {
                        LazyVGrid(columns: [GridItem(.flexible()), GridItem(.flexible()), GridItem(.flexible())], spacing: 8) {
                            StatCell(label: "Lieder",    value: stats.totalSongs,      icon: "music.note",       color: Theme.koelschRed)
                            StatCell(label: "Nutzer",    value: stats.totalUsers,      icon: "person.2",         color: .blue)
                            StatCell(label: "Bands",     value: stats.totalBands,      icon: "person.3",         color: .purple)
                            StatCell(label: "Ausstehend",value: stats.pendingChanges,  icon: "clock.badge",      color: .orange)
                            StatCell(label: "Angenommen",value: stats.approvedChanges, icon: "checkmark.circle", color: .green)
                            StatCell(label: "Abgelehnt", value: stats.rejectedChanges, icon: "xmark.circle",     color: .red)
                        }
                        .listRowInsets(EdgeInsets(top: 8, leading: 0, bottom: 8, trailing: 0))
                        .listRowBackground(Color.clear)
                    }
                }

                // Top bands
                if let topBands = vm.stats?.topBands, !topBands.isEmpty {
                    Section("Top Bands") {
                        ForEach(topBands.prefix(5)) { b in
                            HStack {
                                Text(b.bandName).font(.subheadline)
                                Spacer()
                                Text("\(b.songCount) Lieder")
                                    .font(.caption.bold())
                                    .foregroundStyle(Theme.koelschRed)
                            }
                        }
                    }
                }

                // Error
                if let err = vm.error {
                    Section { ErrorBanner(message: err).listRowBackground(Color.clear).listRowInsets(.init()) }
                }

                // Proposals
                Section {
                    Picker("Status", selection: $vm.statusFilter) {
                        Text("Ausstehend").tag("pending")
                        Text("Angenommen").tag("approved")
                        Text("Abgelehnt").tag("rejected")
                    }
                    .pickerStyle(.segmented)
                    .listRowBackground(Color.clear)
                    .listRowInsets(EdgeInsets(top: 4, leading: 0, bottom: 4, trailing: 0))
                    .onChange(of: vm.statusFilter) { Task { await vm.loadProposals() } }
                } header: {
                    Text("Vorschläge").font(.headline).foregroundStyle(.primary)
                }

                if vm.isLoading {
                    LoadingRow()
                } else if vm.proposals.isEmpty {
                    Section {
                        EmptyStateView(
                            icon: "checkmark.seal",
                            title: "Alles erledigt",
                            subtitle: "Keine Vorschläge in diesem Status."
                        )
                        .listRowBackground(Color.clear)
                    }
                } else {
                    ForEach(vm.proposals) { proposal in
                        NavigationLink(destination: ProposalReviewView(proposal: proposal, onDecision: {
                            Task { await vm.loadProposals() }
                        })) {
                            AdminProposalRow(proposal: proposal)
                        }
                        .swipeActions(edge: .trailing, allowsFullSwipe: true) {
                            Button(role: .destructive) {
                                Task { await vm.reject(id: proposal.id) }
                            } label: {
                                Label("Ablehnen", systemImage: "xmark")
                            }
                        }
                        .swipeActions(edge: .leading, allowsFullSwipe: true) {
                            Button {
                                Task { await vm.approve(id: proposal.id) }
                            } label: {
                                Label("Annehmen", systemImage: "checkmark")
                            }
                            .tint(.green)
                        }
                    }
                }
            }
            .listStyle(.insetGrouped)
            .scrollContentBackground(.hidden)
            .listRowBackground(Theme.card)
        }
    }

private struct StatCell: View {
    let label: String
    let value: Int
    let icon: String
    let color: Color

    var body: some View {
        VStack(spacing: 6) {
            Image(systemName: icon)
                .font(.title3)
                .foregroundStyle(color)
            Text("\(value)")
                .font(.title2.bold())
            Text(label)
                .font(.caption2)
                .foregroundStyle(.secondary)
                .lineLimit(1)
        }
        .frame(maxWidth: .infinity)
        .padding(.vertical, 12)
        .background(color.opacity(0.08))
        .clipShape(RoundedRectangle(cornerRadius: 10))
    }
}

private struct AdminProposalRow: View {
    let proposal: Proposal
    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            HStack {
                Text(proposal.songTitle).font(.headline).lineLimit(1)
                Spacer()
                StatusBadge(status: proposal.status)
            }
            HStack(spacing: 6) {
                if let band = proposal.bandName {
                    Text(band).font(.caption).foregroundStyle(.secondary)
                    Text("·").foregroundStyle(.secondary)
                }
                Text("von \(proposal.userName ?? "–")").font(.caption).foregroundStyle(.secondary)
                Spacer()
                Text(proposal.createdAt.prefix(10)).font(.caption2).foregroundStyle(.secondary)
            }
        }
        .padding(.vertical, 4)
    }
}
