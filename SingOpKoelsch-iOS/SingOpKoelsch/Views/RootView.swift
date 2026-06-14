import SwiftUI
import WidgetKit

/// The whole app is just the live website, 1:1 — including its own top navbar.
struct RootView: View {
    @State private var deepLinkURL: URL? = nil
    @State private var showRecognize  = false

    var body: some View {
        ZStack(alignment: .bottomTrailing) {
            WebView(url: URL(string: "https://singopkoelsch.de/")!, navigateTo: $deepLinkURL)
                .background(Color(red: 13/255, green: 17/255, blue: 23/255).ignoresSafeArea())
                .preferredColorScheme(.dark)
                .onOpenURL { url in
                    guard url.scheme == "singopkoelsch",
                          url.host == "song",
                          let idStr = url.pathComponents.dropFirst().first else { return }
                    deepLinkURL = URL(string: "https://singopkoelsch.de/detail.php?lyrics=\(idStr)")
                    WidgetCenter.shared.reloadAllTimelines()
                }

            Button { showRecognize = true } label: {
                Image(systemName: "shazam.logo.fill")
                    .font(.system(size: 22))
                    .foregroundStyle(.white)
                    .frame(width: 52, height: 52)
                    .background(Color(red: 220/255, green: 38/255, blue: 38/255))
                    .clipShape(Circle())
                    .shadow(color: .black.opacity(0.35), radius: 8, x: 0, y: 4)
            }
            .padding(.trailing, 16)
            .padding(.bottom, 96)
        }
        .sheet(isPresented: $showRecognize) {
            RecognizeView { songId in
                deepLinkURL = URL(string: "https://singopkoelsch.de/detail.php?lyrics=\(songId)")
            }
        }
        .onReceive(NotificationCenter.default.publisher(for: .siriOpenSong)) { note in
            if let songId = note.object as? Int {
                deepLinkURL = URL(string: "https://singopkoelsch.de/detail.php?lyrics=\(songId)")
            } else {
                deepLinkURL = URL(string: "https://singopkoelsch.de/")
            }
        }
    }
}
