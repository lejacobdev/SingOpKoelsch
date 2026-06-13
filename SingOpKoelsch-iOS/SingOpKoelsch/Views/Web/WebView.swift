import SwiftUI
import WebKit

/// Shared web environment so every tab shares the same cookies / login session.
enum WebEnv {
    static let processPool = WKProcessPool()
    static let host = "singopkoelsch.de"
}

/// A WKWebView wrapper that renders the real website 1:1, grants the microphone
/// (for the web Shazam recorder), opens external links in Safari, and injects CSS
/// to hide the site's top navbar.
struct WebView: UIViewRepresentable {
    let url: URL

    func makeCoordinator() -> Coordinator { Coordinator() }

    func makeUIView(context: Context) -> WKWebView {
        let cfg = WKWebViewConfiguration()
        cfg.processPool = WebEnv.processPool
        cfg.websiteDataStore = .default()                 // persistent, shared cookies
        cfg.allowsInlineMediaPlayback = true
        cfg.mediaTypesRequiringUserActionForPlayback = []
        cfg.applicationNameForUserAgent = "SingOpKoelschApp"   // UA marker → site registers the offline service worker
        cfg.limitsNavigationsToAppBoundDomains = true          // required for WKWebView service workers (offline cache)

        // Lock zoom for a native app feel (belt-and-suspenders to the server viewport).
        let noZoom = """
        var v = document.querySelector('meta[name=viewport]');
        if (!v) { v = document.createElement('meta'); v.name = 'viewport'; document.head.appendChild(v); }
        v.setAttribute('content', 'width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover');
        """
        cfg.userContentController.addUserScript(
            WKUserScript(source: noZoom, injectionTime: .atDocumentEnd, forMainFrameOnly: true)
        )

        let web = WKWebView(frame: .zero, configuration: cfg)
        web.navigationDelegate = context.coordinator
        web.uiDelegate = context.coordinator
        web.allowsBackForwardNavigationGestures = true
        web.scrollView.bouncesZoom = false
        web.scrollView.pinchGestureRecognizer?.isEnabled = false
        web.isOpaque = false
        let dark = UIColor(red: 13/255, green: 17/255, blue: 23/255, alpha: 1)  // #0d1117
        web.backgroundColor = dark
        web.scrollView.backgroundColor = dark
        web.load(URLRequest(url: url))
        return web
    }

    func updateUIView(_ web: WKWebView, context: Context) {}

    final class Coordinator: NSObject, WKNavigationDelegate, WKUIDelegate {
        // Grant the microphone for the web Shazam recorder (getUserMedia).
        func webView(_ webView: WKWebView,
                     requestMediaCapturePermissionFor origin: WKSecurityOrigin,
                     initiatedByFrame frame: WKFrameInfo,
                     type: WKMediaCaptureType,
                     decisionHandler: @escaping (WKPermissionDecision) -> Void) {
            decisionHandler(.grant)
        }

        // Keep zoom disabled after every navigation (WKWebView can re-enable the gesture).
        func webView(_ webView: WKWebView, didFinish navigation: WKNavigation!) {
            webView.scrollView.pinchGestureRecognizer?.isEnabled = false
            webView.scrollView.maximumZoomScale = 1
            webView.scrollView.minimumZoomScale = 1
        }

        // External links (Spotify, YouTube, …) open in Safari / their app.
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

        // target="_blank" links: keep same-site in the web view, others in Safari.
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
    }
}
