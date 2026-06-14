// SingOpKoelschWidget/SingOpKoelschWidgetBundle.swift
import WidgetKit
import SwiftUI

@main
struct SingOpKoelschWidgetBundle: WidgetBundle {
    var body: some Widget {
        SingOpKoelschWidget()          // Random song / favorites
        RecentlyViewedWidget()          // #43 Recently viewed songs
        KarnevalWidget()                // #44 Karneval countdown
    }
}
