// SingOpKoelsch/Services/RecognitionActivity.swift
// #33 Dynamic Island / Live Activity support for song recognition

import ActivityKit
import Foundation

// MARK: - ActivityAttributes

struct SongRecognitionAttributes: ActivityAttributes {
    public struct ContentState: Codable, Hashable {
        var statusText: String
        var songTitle: String
        var artist: String
        var isMatched: Bool
    }

    var appName: String = "Sing op Kölsch"
}

// MARK: - Manager

@MainActor
final class RecognitionActivityManager {
    static let shared = RecognitionActivityManager()
    private init() {}

    private var activity: Activity<SongRecognitionAttributes>?

    func startActivity() {
        guard ActivityAuthorizationInfo().areActivitiesEnabled else { return }

        let attrs = SongRecognitionAttributes(appName: "Sing op Kölsch")
        let state = SongRecognitionAttributes.ContentState(
            statusText: "Erkenne Song…",
            songTitle: "",
            artist: "",
            isMatched: false
        )

        do {
            let content = ActivityContent(state: state, staleDate: Date().addingTimeInterval(60))
            activity = try Activity<SongRecognitionAttributes>.request(
                attributes: attrs,
                content: content,
                pushType: nil
            )
        } catch {
            // Live Activities not supported in this context — silently ignore
        }
    }

    func updateWithMatch(title: String, artist: String) {
        guard let activity else { return }
        let state = SongRecognitionAttributes.ContentState(
            statusText: "Gefunden!",
            songTitle: title,
            artist: artist,
            isMatched: true
        )
        Task {
            await activity.update(ActivityContent(state: state, staleDate: Date().addingTimeInterval(30)))
        }
    }

    func endActivity() {
        guard let activity else { return }
        let finalState = SongRecognitionAttributes.ContentState(
            statusText: "Fertig",
            songTitle: activity.content.state.songTitle,
            artist: activity.content.state.artist,
            isMatched: activity.content.state.isMatched
        )
        Task {
            await activity.end(
                ActivityContent(state: finalState, staleDate: nil),
                dismissalPolicy: .immediate
            )
            self.activity = nil
        }
    }
}
