// SingOpKoelschShare/ShareViewController.swift
// #34 Share Extension — extracts singopkoelsch.de URLs and opens them in the app

import UIKit
import Social
import UniformTypeIdentifiers
import MobileCoreServices

class ShareViewController: UIViewController {

    override func viewDidLoad() {
        super.viewDidLoad()
        extractAndOpen()
    }

    private func extractAndOpen() {
        guard let extensionItem = extensionContext?.inputItems.first as? NSExtensionItem else {
            finish()
            return
        }

        let providers = extensionItem.attachments ?? []

        // Try URL type first
        for provider in providers {
            if provider.hasItemConformingToTypeIdentifier(UTType.url.identifier) {
                provider.loadItem(forTypeIdentifier: UTType.url.identifier) { [weak self] item, _ in
                    if let url = item as? URL {
                        self?.handleURL(url)
                    } else {
                        self?.tryPlainText(providers: providers)
                    }
                }
                return
            }
        }

        // Fall back to plain text
        tryPlainText(providers: providers)
    }

    private func tryPlainText(providers: [NSItemProvider]) {
        for provider in providers {
            if provider.hasItemConformingToTypeIdentifier(UTType.plainText.identifier) {
                provider.loadItem(forTypeIdentifier: UTType.plainText.identifier) { [weak self] item, _ in
                    if let text = item as? String, let url = URL(string: text) {
                        self?.handleURL(url)
                    } else {
                        self?.finish()
                    }
                }
                return
            }
        }
        finish()
    }

    private func handleURL(_ url: URL) {
        guard let host = url.host,
              host.contains("singopkoelsch.de") else {
            // Not a Sing op Kölsch URL — open in browser
            finish()
            return
        }

        // Extract song id from /detail.php?lyrics=X
        if let components = URLComponents(url: url, resolvingAgainstBaseURL: false),
           components.path.contains("detail.php"),
           let songId = components.queryItems?.first(where: { $0.name == "lyrics" })?.value {
            openDeepLink(URL(string: "singopkoelsch://song/\(songId)"))
        } else {
            // Generic singopkoelsch.de URL — just open the app
            openDeepLink(URL(string: "singopkoelsch://open"))
        }
    }

    private func openDeepLink(_ url: URL?) {
        guard let url else { finish(); return }
        // Open the main app via deep link
        var responder: UIResponder? = self
        while responder != nil {
            if let application = responder as? UIApplication {
                application.open(url, options: [:], completionHandler: nil)
                break
            }
            responder = responder?.next
        }
        finish()
    }

    private func finish() {
        DispatchQueue.main.async {
            self.extensionContext?.completeRequest(returningItems: [], completionHandler: nil)
        }
    }
}
