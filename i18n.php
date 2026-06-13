<?php
// ════════════════════════════════════════════════════════════════════
//  i18n.php — translation helper
//
//  t($key)             → translated string for $_SESSION['lang']
//  t($key, ['x'=>'y']) → with placeholder substitution ({x} → y)
//  currentLang()       → 'de' | 'en' | 'koelsch'
//  availableLangs()    → ['de'=>..., 'en'=>..., 'koelsch'=>...]
//
//  Default: de. Falls back to de when key is missing.
// ════════════════════════════════════════════════════════════════════

// Each lang gets a clean inline-SVG flag (white/red Köln flag for Kölsch).
// Use I18N_LANGS[$code]['svg'] when rendering. The 'flag' key keeps a short
// text fallback for non-HTML contexts.
const I18N_LANGS = [
    'de'      => [
        'label' => 'Deutsch',
        'short' => 'DE',
        'flag'  => 'DE',
        'svg'   => '<svg viewBox="0 0 18 12" width="18" height="12" aria-hidden="true" style="display:block;border-radius:2px;flex:0 0 18px;"><rect width="18" height="4" y="0" fill="#000"/><rect width="18" height="4" y="4" fill="#DD0000"/><rect width="18" height="4" y="8" fill="#FFCE00"/></svg>',
    ],
    'en'      => [
        'label' => 'English',
        'short' => 'EN',
        'flag'  => 'EN',
        'svg'   => '<svg viewBox="0 0 18 12" width="18" height="12" aria-hidden="true" style="display:block;border-radius:2px;flex:0 0 18px;"><rect width="18" height="12" fill="#012169"/><path d="M0,0 L18,12 M18,0 L0,12" stroke="#fff" stroke-width="2.4"/><path d="M0,0 L18,12 M18,0 L0,12" stroke="#C8102E" stroke-width="1.2"/><rect x="7.5" width="3" height="12" fill="#fff"/><rect y="4.5" width="18" height="3" fill="#fff"/><rect x="8.25" width="1.5" height="12" fill="#C8102E"/><rect y="5.25" width="18" height="1.5" fill="#C8102E"/></svg>',
    ],
    'koelsch' => [
        'label' => 'Kölsch',
        'short' => 'KÖ',
        'flag'  => 'KÖ',
        // Offizielle Köln-Flagge nach Wikimedia Commons (Koeln_Flagge.gif): ROT oben, WEISS unten, Stadtwappen mittig.
        // Wappen: rote Schildspitze mit 3 goldenen Kronen (Heilige Drei Könige) + silbernes Feld mit 11 schwarzen Hermelinschwänzen / Flammen (Hl. Ursula, Pattern 4-4-3).
        'svg'   => '<svg viewBox="0 0 60 40" width="20" height="14" aria-hidden="true" style="display:block;border-radius:2px;">'
                 . '<rect width="60" height="20" y="0" fill="#E30613"/>'
                 . '<rect width="60" height="20" y="20" fill="#fff"/>'
                 . '<g transform="translate(22,4)">'
                 // Schildkörper (rechteckig oben, abgerundet unten)
                 .   '<path d="M0,0 H16 V22 Q16,30 8,30 Q0,30 0,22 Z" fill="#fff" stroke="#000" stroke-width="0.7" stroke-linejoin="round"/>'
                 // Rote Schildspitze (oberes Drittel)
                 .   '<path d="M0,0 H16 V10 H0 Z" fill="#E30613"/>'
                 .   '<line x1="0" y1="10" x2="16" y2="10" stroke="#000" stroke-width="0.7"/>'
                 // Drei goldene Kronen (Reichskronen-Stil: Reif + 3 Zacken + 3 Perlen)
                 .   '<g fill="#FFCC00" stroke="#000" stroke-width="0.3" stroke-linejoin="round">'
                 // Linke Krone (Zentrum x=2.75)
                 .     '<path d="M0.5,8.5 H5 V6.5 L4.5,3 L3.5,6 L2.75,2.5 L2,6 L1,3 L0.5,6.5 Z"/>'
                 .     '<circle cx="1" cy="2.6" r="0.55"/><circle cx="2.75" cy="2.1" r="0.55"/><circle cx="4.5" cy="2.6" r="0.55"/>'
                 // Mittlere Krone (Zentrum x=8)
                 .     '<path d="M5.75,8.5 H10.25 V6.5 L9.75,3 L8.75,6 L8,2.5 L7.25,6 L6.25,3 L5.75,6.5 Z"/>'
                 .     '<circle cx="6.25" cy="2.6" r="0.55"/><circle cx="8" cy="2.1" r="0.55"/><circle cx="9.75" cy="2.6" r="0.55"/>'
                 // Rechte Krone (Zentrum x=13.25)
                 .     '<path d="M11,8.5 H15.5 V6.5 L15,3 L14,6 L13.25,2.5 L12.5,6 L11.5,3 L11,6.5 Z"/>'
                 .     '<circle cx="11.5" cy="2.6" r="0.55"/><circle cx="13.25" cy="2.1" r="0.55"/><circle cx="15" cy="2.6" r="0.55"/>'
                 .   '</g>'
                 // 11 schwarze Flammen / Hermelinschwänze (Tropfen: spitze Spitze oben, runde Wölbung unten), Anordnung 4-4-3
                 .   '<g fill="#000">'
                 // Reihe 1 (4 Flammen, y=12.4 – 14.6)
                 .     '<path d="M2.6,12.4 C3.35,12.6 3.45,13.95 2.6,14.6 C1.75,13.95 1.85,12.6 2.6,12.4 Z"/>'
                 .     '<path d="M6,12.4 C6.75,12.6 6.85,13.95 6,14.6 C5.15,13.95 5.25,12.6 6,12.4 Z"/>'
                 .     '<path d="M10,12.4 C10.75,12.6 10.85,13.95 10,14.6 C9.15,13.95 9.25,12.6 10,12.4 Z"/>'
                 .     '<path d="M13.4,12.4 C14.15,12.6 14.25,13.95 13.4,14.6 C12.55,13.95 12.65,12.6 13.4,12.4 Z"/>'
                 // Reihe 2 (4 Flammen, leicht versetzt zu Reihe 1)
                 .     '<path d="M3.6,16.4 C4.35,16.6 4.45,17.95 3.6,18.6 C2.75,17.95 2.85,16.6 3.6,16.4 Z"/>'
                 .     '<path d="M7,16.4 C7.75,16.6 7.85,17.95 7,18.6 C6.15,17.95 6.25,16.6 7,16.4 Z"/>'
                 .     '<path d="M9,16.4 C9.75,16.6 9.85,17.95 9,18.6 C8.15,17.95 8.25,16.6 9,16.4 Z"/>'
                 .     '<path d="M12.4,16.4 C13.15,16.6 13.25,17.95 12.4,18.6 C11.55,17.95 11.65,16.6 12.4,16.4 Z"/>'
                 // Reihe 3 (3 Flammen, mittig)
                 .     '<path d="M5,20.4 C5.75,20.6 5.85,21.95 5,22.6 C4.15,21.95 4.25,20.6 5,20.4 Z"/>'
                 .     '<path d="M8,20.4 C8.75,20.6 8.85,21.95 8,22.6 C7.15,21.95 7.25,20.6 8,20.4 Z"/>'
                 .     '<path d="M11,20.4 C11.75,20.6 11.85,21.95 11,22.6 C10.15,21.95 10.25,20.6 11,20.4 Z"/>'
                 .   '</g>'
                 . '</g>'
                 . '</svg>',
    ],
];

function availableLangs(): array {
    return I18N_LANGS;
}

function currentLang(): string {
    $lang = $_SESSION['lang'] ?? 'de';
    return isset(I18N_LANGS[$lang]) ? $lang : 'de';
}

function setLang(string $lang): void {
    if (!isset(I18N_LANGS[$lang])) return;
    $_SESSION['lang'] = $lang;
    if (isset($_SESSION['user_id']) && class_exists('Database')) {
        Database::ensurePreferencesTable();
        Database::setUserPreference((int)$_SESSION['user_id'], 'lang', $lang);
    }
}

function t(string $key, array $params = []): string {
    static $cache = [];
    $lang = currentLang();
    foreach ([$lang, 'de'] as $try) {
        if (!array_key_exists($try, $cache)) {
            $file = __DIR__ . "/i18n/$try.php";
            $cache[$try] = file_exists($file) ? (require $file) : [];
        }
        if (array_key_exists($key, $cache[$try])) {
            $val = $cache[$try][$key];
            foreach ($params as $k => $v) {
                $val = str_replace('{' . $k . '}', (string)$v, $val);
            }
            return $val;
        }
    }
    return $key;
}

// e: echo translated + html-escaped
function e(string $key, array $params = []): void {
    echo htmlspecialchars(t($key, $params), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
