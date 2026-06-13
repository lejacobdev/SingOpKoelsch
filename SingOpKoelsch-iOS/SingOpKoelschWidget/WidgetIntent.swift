import AppIntents
import WidgetKit

enum WidgetSongMode: String, AppEnum {
    case all       = "all"
    case favorites = "favorites"

    static var typeDisplayRepresentation = TypeDisplayRepresentation(name: "Modus")
    static var caseDisplayRepresentations: [WidgetSongMode: DisplayRepresentation] = [
        .all:       DisplayRepresentation(
                        title: "Alle Songs",
                        subtitle: "Zufälliger Song aus dem gesamten Liederbuch"),
        .favorites: DisplayRepresentation(
                        title: "Lieblingslieder",
                        subtitle: "Zufälliger Song aus deinen Favoriten")
    ]
}

struct SongWidgetIntent: WidgetConfigurationIntent {
    static var title: LocalizedStringResource = "Sing op Kölsch"
    static var description = IntentDescription("Wähle, welche Songs im Widget angezeigt werden.")

    @Parameter(title: "Anzeigen", default: .all)
    var mode: WidgetSongMode
}
