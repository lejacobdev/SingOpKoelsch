import SwiftUI

// Design tokens shared by all watch views
extension Color {
    static let sok          = Color(red: 0.86, green: 0.15, blue: 0.15)   // #dc2626
    static let sokSecondary = Color(red: 0.60, green: 0.72, blue: 0.90)   // blue-ish accent
    static let sokRow       = Color(red: 0.08, green: 0.10, blue: 0.14)   // dark row bg
    static let sokBg        = Color(red: 0.05, green: 0.07, blue: 0.09)   // #0d1117
}

struct ContentView: View {
    var body: some View {
        TabView {
            SongListView()
            RandomSongView()
        }
    }
}
