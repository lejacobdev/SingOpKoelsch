import SwiftUI

// MARK: - Hex → Color helpers (mirror the website's CSS tokens, adaptive light/dark)

private extension UIColor {
    convenience init(hex: UInt) {
        self.init(
            red:   CGFloat((hex >> 16) & 0xFF) / 255,
            green: CGFloat((hex >> 8)  & 0xFF) / 255,
            blue:  CGFloat( hex        & 0xFF) / 255,
            alpha: 1
        )
    }
}

/// Adaptive color that resolves to `light` in light mode and `dark` in dark mode,
/// exactly like the website's `:root` vs `html.dark` CSS variables.
private func dyn(_ light: UInt, _ dark: UInt) -> Color {
    Color(uiColor: UIColor { tc in
        UIColor(hex: tc.userInterfaceStyle == .dark ? dark : light)
    })
}

// MARK: - Theme (1:1 with style.css design tokens)

enum Theme {
    // ── Primary (web --primary; blue) ──
    static let primary      = dyn(0x2563eb, 0x3b82f6)
    static let primaryHover = dyn(0x1d4ed8, 0x60a5fa)

    // ── Accent (web --accent; Kölsch red) ──
    static let accent       = dyn(0xdc2626, 0xf87171)
    static let accentHover  = dyn(0xb91c1c, 0xef4444)
    /// Backward-compat alias — still the Kölsch red accent.
    static let koelschRed   = dyn(0xdc2626, 0xf87171)
    static let koelschGold  = dyn(0xb58c45, 0xd4af6a)
    static let koelschCream = dyn(0xf7f2e5, 0x1c2128)

    // ── Semantic ──
    static let danger      = dyn(0xdc2626, 0xf87171)
    static let success     = dyn(0x15803d, 0x4ade80)
    static let warning     = dyn(0xd97706, 0xfbbf24)

    // ── Surfaces ──
    static let bg          = dyn(0xeef1f6, 0x0d1117)
    static let bgAlt       = dyn(0xe4e8ef, 0x161b22)
    static let card        = dyn(0xffffff, 0x1c2128)
    static let cardHover   = dyn(0xf3f6fb, 0x252d3a)
    static let border      = dyn(0xdde2eb, 0x334155)
    static let navBg       = dyn(0x1e3a8a, 0x0d1117)

    // ── Text ──
    static let text        = dyn(0x0f172a, 0xf1f5f9)
    static let text2       = dyn(0x475569, 0x94a3b8)
    static let text3       = dyn(0x94a3b8, 0x64748b)

    // ── Alert / badge token sets ──
    static let successBg = dyn(0xecfdf5, 0x052e16), successBorder = dyn(0x6ee7b7, 0x166534), successText = dyn(0x047857, 0x4ade80)
    static let errorBg   = dyn(0xfef2f2, 0x450a0a), errorBorder   = dyn(0xfca5a5, 0x7f1d1d), errorText   = dyn(0xb91c1c, 0xfca5a5)
    static let infoBg    = dyn(0xeff6ff, 0x0c1a2e), infoBorder    = dyn(0x93c5fd, 0x1e40af), infoText    = dyn(0x1d4ed8, 0x93c5fd)
    static let warnBg    = dyn(0xfffbeb, 0x1c1506), warnBorder    = dyn(0xfcd34d, 0x78350f), warnText    = dyn(0x92400e, 0xfde68a)

    // ── Typography — system sans-serif (web uses -apple-system) ──
    static let titleFont    = Font.system(.largeTitle, design: .default).weight(.bold)
    static let headlineFont = Font.system(.headline,   design: .default)
    static let bodyFont     = Font.system(.body,       design: .default)
    static let lyricsFont   = Font.system(.body,       design: .monospaced)
    static let captionFont  = Font.system(.caption,    design: .default)

    // ── Radii (web --radius 10 / --radius-sm 6 / --radius-lg 16) ──
    static let radius:   CGFloat = 10
    static let radiusSm: CGFloat = 6
    static let radiusLg: CGFloat = 16
    static let cornerRadius: CGFloat = 10   // back-compat

    // ── Spacing ──
    static let padding:   CGFloat = 16
    static let paddingSm: CGFloat = 8
    static let paddingLg: CGFloat = 24
    static let cardShadow = Color.black.opacity(0.18)
}

// MARK: - Global UIKit bar appearance (mirror the website's navy nav chrome)

extension Theme {
    /// Configures the navigation bar and tab bar to look like the website's
    /// navy `.navbar` (`--nav-bg` #1e3a8a light / #0d1117 dark, white text,
    /// white-highlight active). Call once at app launch.
    static func applyAppearance() {
        let navy = UIColor { tc in
            UIColor(hex: tc.userInterfaceStyle == .dark ? 0x0d1117 : 0x1e3a8a)
        }
        let navBorder = UIColor.white.withAlphaComponent(0.12)   // web --nav-border
        let white     = UIColor.white
        let whiteDim  = UIColor.white.withAlphaComponent(0.55)

        // ── Navigation bar (top) ──
        let nav = UINavigationBarAppearance()
        nav.configureWithOpaqueBackground()
        nav.backgroundColor = navy
        nav.shadowColor = navBorder
        nav.titleTextAttributes      = [.foregroundColor: white]
        nav.largeTitleTextAttributes = [.foregroundColor: white]
        let navButtons = UIBarButtonItemAppearance(style: .plain)
        navButtons.normal.titleTextAttributes = [.foregroundColor: white]
        nav.buttonAppearance     = navButtons
        nav.doneButtonAppearance = navButtons
        nav.backButtonAppearance = navButtons
        UINavigationBar.appearance().standardAppearance   = nav
        UINavigationBar.appearance().scrollEdgeAppearance = nav
        UINavigationBar.appearance().compactAppearance    = nav
        UINavigationBar.appearance().tintColor = white

        // ── Tab bar (bottom) — same navy chrome, white-highlight active ──
        let tab = UITabBarAppearance()
        tab.configureWithOpaqueBackground()
        tab.backgroundColor = navy
        tab.shadowColor = navBorder
        let item = UITabBarItemAppearance(style: .stacked)
        item.normal.iconColor   = whiteDim
        item.normal.titleTextAttributes   = [.foregroundColor: whiteDim]
        item.selected.iconColor = white
        item.selected.titleTextAttributes = [.foregroundColor: white]
        tab.stackedLayoutAppearance       = item
        tab.inlineLayoutAppearance        = item
        tab.compactInlineLayoutAppearance = item
        UITabBar.appearance().standardAppearance   = tab
        UITabBar.appearance().scrollEdgeAppearance = tab
    }
}

// MARK: - Reusable modifiers

/// Web `.card`: surface + 1px border + small radius + subtle shadow.
struct CardStyle: ViewModifier {
    func body(content: Content) -> some View {
        content
            .background(Theme.card)
            .clipShape(RoundedRectangle(cornerRadius: Theme.radius, style: .continuous))
            .overlay(
                RoundedRectangle(cornerRadius: Theme.radius, style: .continuous)
                    .strokeBorder(Theme.border, lineWidth: 1)
            )
            .shadow(color: Theme.cardShadow, radius: 5, y: 2)
    }
}

/// Web `.btn.btn-primary` — blue, weight 600, 6px radius, press = translateY(1px).
struct PrimaryButtonStyle: ButtonStyle {
    var isLoading: Bool = false
    func makeBody(configuration: Configuration) -> some View {
        HStack(spacing: 8) {
            if isLoading {
                ProgressView().tint(.white).scaleEffect(0.8)
            }
            configuration.label
        }
        .font(.system(size: 16, weight: .semibold))
        .frame(maxWidth: .infinity)
        .padding(.vertical, 13)
        .background(configuration.isPressed ? Theme.primaryHover : Theme.primary)
        .foregroundStyle(.white)
        .clipShape(RoundedRectangle(cornerRadius: Theme.radiusSm, style: .continuous))
        .offset(y: configuration.isPressed ? 1 : 0)
    }
}

/// Web `.btn.btn-accent` / `.btn-mic` — Kölsch red.
struct AccentButtonStyle: ButtonStyle {
    func makeBody(configuration: Configuration) -> some View {
        configuration.label
            .font(.system(size: 16, weight: .semibold))
            .frame(maxWidth: .infinity)
            .padding(.vertical, 13)
            .background(configuration.isPressed ? Theme.accentHover : Theme.accent)
            .foregroundStyle(.white)
            .clipShape(RoundedRectangle(cornerRadius: Theme.radiusSm, style: .continuous))
            .offset(y: configuration.isPressed ? 1 : 0)
    }
}

/// Web `.btn.btn-secondary` — bg-alt fill + 1px border, primary text.
struct SecondaryButtonStyle: ButtonStyle {
    func makeBody(configuration: Configuration) -> some View {
        configuration.label
            .font(.system(size: 16, weight: .semibold))
            .frame(maxWidth: .infinity)
            .padding(.vertical, 13)
            .background(Theme.bgAlt)
            .foregroundStyle(Theme.text)
            .clipShape(RoundedRectangle(cornerRadius: Theme.radiusSm, style: .continuous))
            .overlay(
                RoundedRectangle(cornerRadius: Theme.radiusSm, style: .continuous)
                    .strokeBorder(Theme.border, lineWidth: 1)
            )
            .opacity(configuration.isPressed ? 0.7 : 1)
    }
}

/// Web `.btn.btn-danger`.
struct DangerButtonStyle: ButtonStyle {
    func makeBody(configuration: Configuration) -> some View {
        configuration.label
            .font(.system(size: 16, weight: .semibold))
            .frame(maxWidth: .infinity)
            .padding(.vertical, 13)
            .background(configuration.isPressed ? Theme.accentHover : Theme.danger)
            .foregroundStyle(.white)
            .clipShape(RoundedRectangle(cornerRadius: Theme.radiusSm, style: .continuous))
            .offset(y: configuration.isPressed ? 1 : 0)
    }
}

extension View {
    func cardStyle() -> some View { modifier(CardStyle()) }

    /// Paints the web page background (`--bg`) behind a scrollable screen and
    /// hides the default grouped-list backdrop.
    func webBackground() -> some View {
        self.scrollContentBackground(.hidden)
            .background(Theme.bg.ignoresSafeArea())
    }

    func errorText() -> some View {
        self.font(Theme.captionFont)
            .foregroundStyle(Theme.danger)
            .frame(maxWidth: .infinity, alignment: .leading)
    }
}

// MARK: - Shared components

/// Web `.badge` — pill with soft bg + 1px border + colored text.
struct StatusBadge: View {
    let status: String
    var body: some View {
        Text(label)
            .font(.system(size: 12, weight: .semibold))
            .padding(.horizontal, 9)
            .padding(.vertical, 3)
            .background(bg)
            .foregroundStyle(fg)
            .overlay(Capsule().strokeBorder(border, lineWidth: 1))
            .clipShape(Capsule())
    }
    private var label: String {
        switch status {
        case "approved": return "Angenommen"
        case "rejected": return "Abgelehnt"
        default:         return "Ausstehend"
        }
    }
    private var bg: Color {
        switch status {
        case "approved": return Theme.successBg
        case "rejected": return Theme.errorBg
        default:         return Theme.warnBg
        }
    }
    private var border: Color {
        switch status {
        case "approved": return Theme.successBorder
        case "rejected": return Theme.errorBorder
        default:         return Theme.warnBorder
        }
    }
    private var fg: Color {
        switch status {
        case "approved": return Theme.successText
        case "rejected": return Theme.errorText
        default:         return Theme.warnText
        }
    }
}

/// In-app brand mark (Kölsch red, mirrors the site's red identity).
struct KoelschLogo: View {
    var size: CGFloat = 32
    var body: some View {
        ZStack {
            RoundedRectangle(cornerRadius: size * 0.28, style: .continuous)
                .fill(Theme.accent)
                .frame(width: size, height: size)
            Text("S")
                .font(.system(size: size * 0.56, weight: .black, design: .default))
                .foregroundStyle(.white)
        }
    }
}

struct LoadingRow: View {
    var body: some View {
        HStack {
            Spacer()
            ProgressView().tint(Theme.primary)
            Spacer()
        }
        .padding()
        .listRowBackground(Color.clear)
    }
}

/// Web `.alert.alert-error`.
struct ErrorBanner: View {
    let message: String
    var body: some View {
        HStack(spacing: 8) {
            Image(systemName: "exclamationmark.triangle.fill")
                .foregroundStyle(Theme.errorText)
            Text(message)
                .font(.system(size: 14))
                .foregroundStyle(Theme.errorText)
        }
        .padding(.horizontal, 14)
        .padding(.vertical, 11)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(Theme.errorBg)
        .clipShape(RoundedRectangle(cornerRadius: Theme.radiusSm, style: .continuous))
        .overlay(
            RoundedRectangle(cornerRadius: Theme.radiusSm, style: .continuous)
                .strokeBorder(Theme.errorBorder, lineWidth: 1)
        )
        .padding(.horizontal)
    }
}

struct EmptyStateView: View {
    let icon: String
    let title: String
    let subtitle: String
    var body: some View {
        VStack(spacing: 16) {
            Image(systemName: icon)
                .font(.system(size: 46))
                .foregroundStyle(Theme.text3)
            Text(title).font(.headline).foregroundStyle(Theme.text)
            Text(subtitle).font(.subheadline).foregroundStyle(Theme.text2).multilineTextAlignment(.center)
        }
        .padding(40)
        .frame(maxWidth: .infinity, maxHeight: .infinity)
    }
}
