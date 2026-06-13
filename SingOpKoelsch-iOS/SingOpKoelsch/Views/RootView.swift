import SwiftUI
import WidgetKit

/// The whole app is just the live website, 1:1 — including its own top navbar.
struct RootView: View {
    @State private var deepLinkURL: URL? = nil

    var body: some View {
        WebView(url: URL(string: "https://singopkoelsch.de/")!, navigateTo: $deepLinkURL)
            .background(Color(red: 13/255, green: 17/255, blue: 23/255).ignoresSafeArea())
            .preferredColorScheme(.dark)
            .onOpenURL { url in
                guard url.scheme == "singopkoelsch",
                      url.host == "song",
                      let idStr = url.pathComponents.dropFirst().first else { return }
                deepLinkURL = URL(string: "https://singopkoelsch.de/detail.php?lyrics=\(idStr)")
                // Reload widget immediately so a new random song appears after this one was tapped
                WidgetCenter.shared.reloadAllTimelines()
            }
    }
}
