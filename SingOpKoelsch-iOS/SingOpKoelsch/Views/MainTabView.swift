import SwiftUI

/// Live website base. Every tab loads a real page → pixel-identical to the web app.
private let kBase = "https://singopkoelsch.de"

/// Native bottom tab bar (Liquid Glass on iOS 26) mirroring the website's navbar,
/// each tab showing the real web page in a `WebView`.
struct MainTabView: View {
    @State private var selection = 1   // start on "Lieder"

    var body: some View {
        TabView(selection: $selection) {
            WebTab(path: "/")
                .tabItem { Label("Start", systemImage: "house.fill") }.tag(0)
            WebTab(path: "/lieder.php")
                .tabItem { Label("Lieder", systemImage: "music.note.list") }.tag(1)
            WebTab(path: "/liederbuch.php")
                .tabItem { Label("Liederbuch", systemImage: "book.fill") }.tag(2)
            WebTab(path: "/recognize.php")
                .tabItem { Label("Erkennen", systemImage: "waveform") }.tag(3)
            WebTab(path: "/profile.php")
                .tabItem { Label("Profil", systemImage: "person.crop.circle") }.tag(4)
        }
        .tint(Theme.primary)
    }
}

private struct WebTab: View {
    let path: String
    var body: some View {
        WebView(url: URL(string: kBase + path)!)
    }
}
