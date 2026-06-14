// SingOpKoelsch/Views/SettingsView.swift
// #40 Font size preference sheet

import SwiftUI

struct SettingsView: View {
    @Environment(\.dismiss) private var dismiss

    private let fontSizes = [14, 17, 20, 24]
    private let fontSizeLabels = ["Klein", "Normal", "Groß", "Sehr groß"]

    @AppStorage("lyrics_font_size") private var selectedFontSize: Int = 17

    var body: some View {
        NavigationStack {
            Form {
                Section {
                    ForEach(Array(zip(fontSizes, fontSizeLabels)), id: \.0) { size, label in
                        HStack {
                            Text(label)
                                .font(.system(size: CGFloat(size)))
                            Spacer()
                            if selectedFontSize == size {
                                Image(systemName: "checkmark")
                                    .foregroundStyle(Theme.koelschRed)
                            }
                        }
                        .contentShape(Rectangle())
                        .onTapGesture {
                            selectedFontSize = size
                        }
                    }
                } header: {
                    Text("Schriftgröße im Liedtext")
                } footer: {
                    Text("Die Schriftgröße wird sofort in der Website angewendet (CSS-Variable --lyrics-font-size).")
                        .font(.caption)
                }
            }
            .navigationTitle("Einstellungen")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .topBarTrailing) {
                    Button("Fertig") { dismiss() }
                        .foregroundStyle(Theme.koelschRed)
                }
            }
        }
    }
}
