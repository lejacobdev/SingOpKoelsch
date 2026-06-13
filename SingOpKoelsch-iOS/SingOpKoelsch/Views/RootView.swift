import SwiftUI

/// The whole app is just the live website, 1:1 — including its own top navbar.
struct RootView: View {
    var body: some View {
        WebView(url: URL(string: "https://singopkoelsch.de/")!)
            .background(Color(red: 13/255, green: 17/255, blue: 23/255).ignoresSafeArea())
            .preferredColorScheme(.dark)   // light status-bar text over the dark site
    }
}
