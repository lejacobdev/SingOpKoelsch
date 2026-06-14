// SingOpKoelsch/Services/AppIconManager.swift
// #45 Animated / alternate app icon during Karneval season

import UIKit

enum AppIconManager {

    // MARK: - Karneval date calculation

    /// Returns the Weiberfastnacht date for a given Gregorian year.
    /// Weiberfastnacht = Thursday before Ash Wednesday.
    static func weiberfastnacht(year: Int) -> Date {
        // Ash Wednesday = 46 days before Easter
        let easter = easterDate(year: year)
        let ashWednesday = Calendar.current.date(byAdding: .day, value: -46, to: easter)!
        // Thursday before Ash Wednesday = ashWednesday - 6 days
        return Calendar.current.date(byAdding: .day, value: -6, to: ashWednesday)!
    }

    /// Returns the Aschermittwoch (Ash Wednesday) for a given year.
    static func ashWednesday(year: Int) -> Date {
        let easter = easterDate(year: year)
        return Calendar.current.date(byAdding: .day, value: -46, to: easter)!
    }

    /// Checks whether today falls during Karneval (Weiberfastnacht through Aschermittwoch inclusive).
    static func isKarnevalSeason() -> Bool {
        let today = Date()
        let year = Calendar.current.component(.year, from: today)
        let start = weiberfastnacht(year: year)
        let end = ashWednesday(year: year)
        return today >= start && today <= end
    }

    // MARK: - Icon switching

    static func updateAppIconIfNeeded() {
        guard UIApplication.shared.supportsAlternateIcons else { return }

        if isKarnevalSeason() {
            // Switch to Karneval icon if not already set
            if UIApplication.shared.alternateIconName != "AppIconKarneval" {
                UIApplication.shared.setAlternateIconName("AppIconKarneval") { error in
                    if let error { print("AppIconManager: Karneval icon error: \(error)") }
                }
            }
        } else {
            // Restore default icon
            if UIApplication.shared.alternateIconName != nil {
                UIApplication.shared.setAlternateIconName(nil) { error in
                    if let error { print("AppIconManager: Default icon restore error: \(error)") }
                }
            }
        }
    }

    // MARK: - Anonymous Gregorian Easter (Butcher/Jones/Conway algorithm)

    private static func easterDate(year: Int) -> Date {
        // Anonymous Gregorian algorithm
        let a = year % 19
        let b = year / 100
        let c = year % 100
        let d = b / 4
        let e = b % 4
        let f = (b + 8) / 25
        let g = (b - f + 1) / 3
        let h = (19 * a + b - d - g + 15) % 30
        let i = c / 4
        let k = c % 4
        let l = (32 + 2 * e + 2 * i - h - k) % 7
        let m = (a + 11 * h + 22 * l) / 451
        let month = (h + l - 7 * m + 114) / 31
        let day   = ((h + l - 7 * m + 114) % 31) + 1

        var components = DateComponents()
        components.year  = year
        components.month = month
        components.day   = day
        return Calendar.current.date(from: components)!
    }
}
