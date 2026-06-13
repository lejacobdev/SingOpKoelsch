import SwiftUI

@MainActor
final class BandsViewModel: ObservableObject {
    @Published var bands: [Band] = []
    @Published var isLoading = false
    @Published var error: String?
    private let api = APIClient.shared

    func load() async {
        isLoading = true; error = nil
        do { bands = try await api.bands() }
        catch { self.error = error.localizedDescription }
        isLoading = false
    }
}

struct BandsView: View {
    @StateObject private var vm = BandsViewModel()
    @State private var search = ""

    var filtered: [Band] {
        if search.isEmpty { return vm.bands }
        return vm.bands.filter { $0.bandName.localizedCaseInsensitiveContains(search) }
    }

    let columns = [GridItem(.adaptive(minimum: 160), spacing: 12)]

    var body: some View {
        NavigationStack {
            Group {
                if vm.isLoading {
                    ProgressView().tint(Theme.primary)
                        .frame(maxWidth: .infinity, maxHeight: .infinity)
                } else if filtered.isEmpty {
                    EmptyStateView(icon: "person.3", title: "Keine Bands", subtitle: "")
                } else {
                    ScrollView {
                        if let err = vm.error { ErrorBanner(message: err).padding(.top) }
                        LazyVGrid(columns: columns, spacing: 12) {
                            ForEach(filtered) { band in
                                NavigationLink(destination: BandDetailView(bandId: band.bandId, bandName: band.bandName)) {
                                    BandCard(band: band)
                                }
                                .buttonStyle(.plain)
                            }
                        }
                        .padding()
                    }
                }
            }
            .background(Theme.bg.ignoresSafeArea())
            .navigationTitle("Bands")
            .searchable(text: $search, prompt: "Bandname …")
        }
        .task { await vm.load() }
    }
}

struct BandCard: View {
    let band: Band
    var body: some View {
        VStack(spacing: 10) {
            ZStack {
                Circle()
                    .fill(Theme.koelschRed.opacity(0.1))
                    .frame(width: 60, height: 60)
                Text(String(band.bandName.prefix(1)).uppercased())
                    .font(.system(.title, design: .default).bold())
                    .foregroundStyle(Theme.koelschRed)
            }
            Text(band.bandName)
                .font(.subheadline.bold())
                .lineLimit(2)
                .multilineTextAlignment(.center)
            Text("\(band.songCount) Lieder")
                .font(.caption)
                .foregroundStyle(.secondary)
        }
        .frame(maxWidth: .infinity)
        .padding(.vertical, 20)
        .cardStyle()
    }
}
