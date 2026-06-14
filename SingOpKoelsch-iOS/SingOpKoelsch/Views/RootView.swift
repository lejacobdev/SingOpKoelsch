// SingOpKoelsch/Views/RootView.swift
import SwiftUI
import WidgetKit
import UIKit
import WebKit

/// The whole app is just the live website, 1:1 — including its own top navbar.
struct RootView: View {
    @State private var deepLinkURL: URL? = nil

    // #12 Dunkelmodus-Toggle sync
    @Environment(\.colorScheme) private var colorScheme

    var body: some View {
        WebViewWithSchemeSync(
            url: URL(string: "https://singopkoelsch.de/")!,
            navigateTo: $deepLinkURL,
            colorScheme: colorScheme
        )
        .background(Color(red: 13/255, green: 17/255, blue: 23/255).ignoresSafeArea())
        .preferredColorScheme(.dark)
        .onOpenURL { url in
            handleDeepLink(url)
        }
        .onReceive(NotificationCenter.default.publisher(for: .siriOpenSong)) { note in
            if let songId = note.object as? Int {
                deepLinkURL = URL(string: "https://singopkoelsch.de/detail.php?lyrics=\(songId)")
            } else {
                deepLinkURL = URL(string: "https://singopkoelsch.de/")
            }
        }
        .onReceive(NotificationCenter.default.publisher(for: .quickActionReceived)) { note in
            if let urlStr = note.object as? String {
                handleQuickActionURL(urlStr)
            }
        }
    }

    // MARK: - Deep Link Handling (#36)

    private func handleDeepLink(_ url: URL) {
        guard url.scheme == "singopkoelsch" else { return }
        switch url.host {
        case "song":
            if let idStr = url.pathComponents.dropFirst().first {
                deepLinkURL = URL(string: "https://singopkoelsch.de/detail.php?lyrics=\(idStr)")
                WidgetCenter.shared.reloadAllTimelines()
            }
        case "random":
            deepLinkURL = URL(string: "https://singopkoelsch.de/api/songs/random-redirect")
                ?? URL(string: "https://singopkoelsch.de/")
        case "favorites":
            deepLinkURL = URL(string: "https://singopkoelsch.de/favoriten.php")
                ?? URL(string: "https://singopkoelsch.de/")
        case "search":
            deepLinkURL = URL(string: "https://singopkoelsch.de/?search=1")
                ?? URL(string: "https://singopkoelsch.de/")
        default:
            break
        }
    }

    private func handleQuickActionURL(_ urlStr: String) {
        if let url = URL(string: urlStr) {
            handleDeepLink(url)
        }
    }

}

// MARK: - WebView with color scheme sync (#12)

struct WebViewWithSchemeSync: UIViewRepresentable {
    let url: URL
    var navigateTo: Binding<URL?>
    let colorScheme: ColorScheme

    func makeCoordinator() -> Coordinator { Coordinator() }

    func makeUIView(context: Context) -> WKWebView {
        let cfg = WKWebViewConfiguration()
        cfg.processPool = WebEnv.processPool
        cfg.websiteDataStore = .default()
        cfg.allowsInlineMediaPlayback = true
        cfg.mediaTypesRequiringUserActionForPlayback = []
        cfg.applicationNameForUserAgent = "SingOpKoelschApp"
        cfg.limitsNavigationsToAppBoundDomains = true

        // Lock zoom
        let noZoom = """
        var v = document.querySelector('meta[name=viewport]');
        if (!v) { v = document.createElement('meta'); v.name = 'viewport'; document.head.appendChild(v); }
        v.setAttribute('content', 'width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover');
        """
        cfg.userContentController.addUserScript(
            WKUserScript(source: noZoom, injectionTime: .atDocumentEnd, forMainFrameOnly: true)
        )

        // #40 Font size injection script (runs on every page load)
        let fontSize = UserDefaults.standard.integer(forKey: "lyrics_font_size")
        let sizeVal = fontSize > 0 ? fontSize : 17
        let fontScript = "document.documentElement.style.setProperty('--lyrics-font-size', '\(sizeVal)px');"
        cfg.userContentController.addUserScript(
            WKUserScript(source: fontScript, injectionTime: .atDocumentEnd, forMainFrameOnly: true)
        )

        let web = WKWebView(frame: .zero, configuration: cfg)
        web.navigationDelegate = context.coordinator
        web.uiDelegate = context.coordinator
        web.allowsBackForwardNavigationGestures = true
        web.scrollView.bouncesZoom = false
        web.scrollView.pinchGestureRecognizer?.isEnabled = false
        web.isOpaque = false
        let dark = UIColor(red: 13/255, green: 17/255, blue: 23/255, alpha: 1)
        web.backgroundColor = dark
        web.scrollView.backgroundColor = dark
        web.load(URLRequest(url: url))

        // #41 Swipe gestures for navigating between favorites
        let swipeLeft = UISwipeGestureRecognizer(target: context.coordinator, action: #selector(Coordinator.handleSwipeLeft(_:)))
        swipeLeft.direction = .left
        let swipeRight = UISwipeGestureRecognizer(target: context.coordinator, action: #selector(Coordinator.handleSwipeRight(_:)))
        swipeRight.direction = .right
        web.scrollView.addGestureRecognizer(swipeLeft)
        web.scrollView.addGestureRecognizer(swipeRight)

        return web
    }

    func updateUIView(_ web: WKWebView, context: Context) {
        // #12 Sync dark/light mode to web
        let isDark = colorScheme == .dark
        let js = "document.documentElement.classList.toggle('dark', \(isDark)); localStorage.setItem('home_dark', '\(isDark ? "1" : "0")');"
        web.evaluateJavaScript(js, completionHandler: nil)

        // #40 Inject current font size preference
        let fontSize = UserDefaults.standard.integer(forKey: "lyrics_font_size")
        let sizeVal = fontSize > 0 ? fontSize : 17
        let fontJs = "document.documentElement.style.setProperty('--lyrics-font-size', '\(sizeVal)px');"
        web.evaluateJavaScript(fontJs, completionHandler: nil)

        // Handle pending navigation
        if let pending = navigateTo.wrappedValue {
            web.load(URLRequest(url: pending))
            DispatchQueue.main.async { self.navigateTo.wrappedValue = nil }
        }
    }

    // MARK: - Coordinator

    final class Coordinator: NSObject, WKNavigationDelegate, WKUIDelegate {

        func webView(_ webView: WKWebView,
                     requestMediaCapturePermissionFor origin: WKSecurityOrigin,
                     initiatedByFrame frame: WKFrameInfo,
                     type: WKMediaCaptureType,
                     decisionHandler: @escaping (WKPermissionDecision) -> Void) {
            decisionHandler(.grant)
        }

        func webView(_ webView: WKWebView, didFinish navigation: WKNavigation!) {
            webView.scrollView.pinchGestureRecognizer?.isEnabled = false
            webView.scrollView.maximumZoomScale = 1
            webView.scrollView.minimumZoomScale = 1

            // #43 Track recently viewed songs
            if let urlStr = webView.url?.absoluteString,
               urlStr.contains("detail.php"),
               let components = URLComponents(string: urlStr),
               let songIdStr = components.queryItems?.first(where: { $0.name == "lyrics" })?.value,
               let songId = Int(songIdStr) {
                trackRecentlyViewed(songId: songId)
            }
        }

        func webView(_ webView: WKWebView,
                     decidePolicyFor navigationAction: WKNavigationAction,
                     decisionHandler: @escaping (WKNavigationActionPolicy) -> Void) {
            if navigationAction.navigationType == .linkActivated,
               let url = navigationAction.request.url,
               let host = url.host, !host.contains(WebEnv.host) {
                UIApplication.shared.open(url)
                decisionHandler(.cancel)
                return
            }
            decisionHandler(.allow)
        }

        func webView(_ webView: WKWebView,
                     createWebViewWith configuration: WKWebViewConfiguration,
                     for navigationAction: WKNavigationAction,
                     windowFeatures: WKWindowFeatures) -> WKWebView? {
            if let url = navigationAction.request.url {
                if let host = url.host, !host.contains(WebEnv.host) {
                    UIApplication.shared.open(url)
                } else {
                    webView.load(navigationAction.request)
                }
            }
            return nil
        }

        // #43 Recently viewed tracking
        private func trackRecentlyViewed(songId: Int) {
            let defaults = UserDefaults(suiteName: "group.de.singopkoelsch.app")
            var recent = defaults?.array(forKey: "recently_viewed") as? [Int] ?? []
            recent.removeAll { $0 == songId }
            recent.insert(songId, at: 0)
            if recent.count > 5 { recent = Array(recent.prefix(5)) }
            defaults?.set(recent, forKey: "recently_viewed")
        }

        // #41 Swipe gestures between favorites
        @objc func handleSwipeLeft(_ gesture: UISwipeGestureRecognizer) {
            navigateFavoriteFromGesture(gesture, direction: "next")
        }

        @objc func handleSwipeRight(_ gesture: UISwipeGestureRecognizer) {
            navigateFavoriteFromGesture(gesture, direction: "prev")
        }

        private func navigateFavoriteFromGesture(_ gesture: UISwipeGestureRecognizer, direction: String) {
            // The gesture is on the scrollView; its superview is the WKWebView
            guard let scrollView = gesture.view as? UIScrollView,
                  let webView = scrollView.superview as? WKWebView else { return }
            navigateFavoriteInWebView(webView, direction: direction)
        }

        private func navigateFavoriteInWebView(_ webView: WKWebView, direction: String) {
            guard let urlStr = webView.url?.absoluteString,
                  urlStr.contains("detail.php"),
                  let components = URLComponents(string: urlStr),
                  let songIdStr = components.queryItems?.first(where: { $0.name == "lyrics" })?.value else { return }

            Task {
                do {
                    // Load auth token from Keychain
                    let token = Keychain.load(key: "auth_token")
                    var req = URLRequest(url: URL(string: "https://singopkoelsch.de/api/favorites")!)
                    if let t = token { req.setValue("Bearer \(t)", forHTTPHeaderField: "Authorization") }
                    let (data, _) = try await URLSession.shared.data(for: req)
                    struct FavResponse: Decodable { let ok: Bool; let data: [FavSong]? }
                    struct FavSong: Decodable { let id: Int }
                    if let resp = try? JSONDecoder().decode(FavResponse.self, from: data),
                       let favs = resp.data, !favs.isEmpty {
                        let ids = favs.map { $0.id }
                        guard let currentId = Int(songIdStr),
                              let idx = ids.firstIndex(of: currentId) else { return }
                        let nextIdx: Int
                        if direction == "next" {
                            nextIdx = (idx + 1) % ids.count
                        } else {
                            nextIdx = (idx - 1 + ids.count) % ids.count
                        }
                        let nextId = ids[nextIdx]
                        await MainActor.run {
                            if let url = URL(string: "https://singopkoelsch.de/detail.php?lyrics=\(nextId)") {
                                webView.load(URLRequest(url: url))
                            }
                        }
                    }
                } catch {}
            }
        }
    }
}

// Notification names are defined in SingOpKoelsch/Services/Notifications.swift
