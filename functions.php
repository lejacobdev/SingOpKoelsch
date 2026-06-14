<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/vendor/autoload.php';

// Child-friendly: reject names that contain blatant profanity or slurs.
// Heuristic blocklist — German/English common bad words. Substring match on normalized name.
function isInappropriateName(string $name): bool {
    $n = mb_strtolower($name, 'UTF-8');
    $n = strtr($n, ['ä'=>'a','ö'=>'o','ü'=>'u','ß'=>'ss','0'=>'o','1'=>'i','3'=>'e','4'=>'a','5'=>'s','7'=>'t','@'=>'a','$'=>'s']);
    $n = preg_replace('/[^a-z]+/', '', $n);
    if ($n === '') return false;

    $bad = [
        // German vulgarities
        'arsch','arschloch','fick','ficker','fickdich','scheisse','scheiss','wichser','wixer','schlampe',
        'hure','hurensohn','fotze','muschi','nigger','neger','schwuchtel','missgeburt','penner','spast',
        'kacke','kackbratze','idiot','vollidiot','dumm','dummkopf',
        // English vulgarities
        'fuck','fucker','fucking','shit','bitch','cunt','dick','pussy','asshole','bastard',
        'nigga','faggot','retard','slut','whore','cock','rape','porn','sex','nazi','hitler',
        // Self-harm / drugs / extremism (kid-safe blocklist)
        'kill','suicide','heroin','cocaine','meth',
    ];
    foreach ($bad as $w) {
        if (strpos($n, $w) !== false) return true;
    }
    return false;
}

// Centralised mail helper — replaces duplicated sendVerificationEmail / sendChangeRequestMail
/**
 * Sendet eine Mail. Wenn $opts['bypass_preference'] nicht gesetzt ist und der
 * Empfänger Mails deaktiviert hat, wird die Mail still verworfen (return true,
 * damit Aufrufer-Logik gleich bleibt). $opts['html'] wird als HTML-Body genutzt;
 * $body bleibt als Plaintext-Alternative für Spamfilter-Score und Text-Clients.
 */
function sendMail(string $to_email, string $to_name, string $subject, string $body, array $opts = []): bool {
    $bypass = !empty($opts['bypass_preference']);
    if (!$bypass && !Database::userAllowsEmail($to_email)) {
        return true; // user opted out — silently skip
    }

    // Frequency limiting for non-bypass emails
    if (!$bypass && $to_email !== '') {
        $conn = Database::getConnection();
        $stmt = $conn->prepare("SELECT user_id FROM singopkoelsch_users WHERE email = ? LIMIT 1");
        $stmt->bind_param("s", $to_email);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();
        if ($user) {
            $userId = (int)$user['user_id'];
            $prefs = Database::getUserPreferences($userId);
            $limit = (int)$prefs['email_limit'];
            $unit = $prefs['email_unit'];
            $nextReset = $prefs['email_next_reset'] ? new DateTime($prefs['email_next_reset']) : null;
            $count = (int)$prefs['email_count'];
            $now = new DateTime();

            // Check if day has passed — always reset daily at midnight
            if ($nextReset && $now >= $nextReset) {
                $count = 0;
                $nextReset = (new DateTime('tomorrow midnight'));
                Database::setUserPreference($userId, 'email_count', $count);
                Database::setUserPreference($userId, 'email_next_reset', $nextReset->format('Y-m-d H:i:s'));
            }

            // Check if over limit
            if ($count >= $limit) {
                // Skip sending due to frequency limit
                return true;
            }

            // We will send, so prepare to increment after success
            $shouldIncrement = true;
        } else {
            // Not a registered user, no frequency limiting
            $shouldIncrement = false;
        }
    } else {
        $shouldIncrement = false;
    }

    // Tausche den Platzhalter im HTML/Body gegen einen echten Unsubscribe-Link aus
    // (falls Empfänger registriert ist). Nicht-Empfänger sehen den Footer-Hinweis nicht.
    $unsubUrl = Database::unsubscribeLinkFor($to_email);
    if (isset($opts['html'])) {
        $opts['html'] = str_replace('{{UNSUBSCRIBE_URL}}', $unsubUrl, $opts['html']);
    }
    if ($unsubUrl !== '') {
        $body .= "\n\nMails abbestellen: $unsubUrl";
    }

    $html = $opts['html'] ?? null;

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';
        $mail->Encoding   = 'base64';
        $mail->setFrom(SMTP_USER, 'Sing op Kölsch');
        $mail->addReplyTo(SMTP_USER, 'Sing op Kölsch');
        $mail->addAddress($to_email, $to_name);
        if ($html !== null) {
            $mail->isHTML(true);
            $mail->Body    = $html;
            $mail->AltBody = $body;
        } else {
            $mail->isHTML(false);
            $mail->Body    = $body;
        }
        $mail->Subject = $subject;
        $mail->XMailer = 'Sing op Kölsch Mailer';
        $mail->send();
        if (isset($shouldIncrement) && $shouldIncrement) {
            // Increment count and update
            $conn = Database::getConnection();
            $stmt = $conn->prepare("UPDATE singopkoelsch_user_preferences SET email_count = email_count + 1 WHERE user_id = ?");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $stmt->close();
        }
        return true;
    } catch (\PHPMailer\PHPMailer\Exception $e) {
        error_log('Mail error to ' . $to_email . ': ' . $mail->ErrorInfo);
        return false;
    }
}

/**
 * Baut einen Mail-HTML-Body im Look der App auf (weiße Karte, Köln-Rot-Akzent,
 * Systemschrift, mobil-freundlich). $segments akzeptiert ein Array mit:
 *   greeting     – z.B. "Hallo Anna,"
 *   intro        – Einleitungs-Absatz (HTML-escaped vom Aufrufer oder reiner Text)
 *   detail_html  – optionales HTML (z.B. Vorschau eines Vorschlags) — Aufrufer ist verantwortlich
 *   cta_label    – Button-Beschriftung (optional)
 *   cta_url      – Button-Ziel (optional)
 *   outro        – Schluss-Absatz (optional)
 *   footer_note  – Footer-Hinweis (optional; z.B. "Du erhältst diese Mail …")
 */
/** Holt einen Spotify-App-Token via Client-Credentials. Cacht im statischen $tok. */
function spotify_token(): ?string {
    static $tok = null;
    if ($tok !== null) return $tok;
    if (!defined('SPOTIFY_CLIENT_ID') || !defined('SPOTIFY_CLIENT_SECRET')) return null;
    $ch = curl_init('https://accounts.spotify.com/api/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
        CURLOPT_HTTPHEADER     => [
            'Authorization: Basic ' . base64_encode(SPOTIFY_CLIENT_ID . ':' . SPOTIFY_CLIENT_SECRET),
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
    ]);
    $out = curl_exec($ch);
    curl_close($ch);
    if ($out === false) return null;
    $j = json_decode($out, true);
    if (empty($j['access_token'])) return null;
    return $tok = $j['access_token'];
}

/** Parsed eine Spotify-URL → ['type'=>'track'|'album', 'id'=>...] oder null. */
function parse_spotify_url(string $url): ?array {
    if (!preg_match('#open\.spotify\.com/(intl-[a-z]+/)?(track|album)/([A-Za-z0-9]+)#', $url, $m)) {
        return null;
    }
    return ['type' => $m[2], 'id' => $m[3]];
}

/**
 * Holt Track-/Album-Daten von Spotify und liefert ein normalisiertes Array
 * mit album, cover_url, spotify_link, release_year, artist.
 * Cached pro URL im Static, damit eine Listen-Anzeige nicht N-fach abruft.
 */
function spotify_lookup(string $url): ?array {
    static $cache = [];
    if (isset($cache[$url])) return $cache[$url];
    $cache[$url] = null;

    $parsed = parse_spotify_url($url);
    if (!$parsed) return null;
    $token  = spotify_token();
    if (!$token) return null;

    $endpoint = $parsed['type'] === 'album'
        ? 'https://api.spotify.com/v1/albums/' . $parsed['id']
        : 'https://api.spotify.com/v1/tracks/' . $parsed['id'];

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
    ]);
    $out  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || $out === false) return null;
    $j = json_decode($out, true);
    if (!is_array($j)) return null;

    if ($parsed['type'] === 'album') {
        $imgs = $j['images'] ?? [];
        $cover = $imgs[0]['url'] ?? null;
        $year  = !empty($j['release_date']) ? (int)substr($j['release_date'], 0, 4) : null;
        $artist = $j['artists'][0]['name'] ?? null;
        $data = [
            'album'        => $j['name'] ?? null,
            'cover_url'    => $cover,
            'spotify_link' => $j['external_urls']['spotify'] ?? $url,
            'release_year' => $year,
            'artist'       => $artist,
        ];
    } else {
        $album = $j['album'] ?? [];
        $imgs  = $album['images'] ?? [];
        $cover = $imgs[0]['url'] ?? null;
        $year  = !empty($album['release_date']) ? (int)substr($album['release_date'], 0, 4) : null;
        $artist = $j['artists'][0]['name'] ?? null;
        $data = [
            'album'        => $album['name'] ?? null,
            'cover_url'    => $cover,
            'spotify_link' => $j['external_urls']['spotify'] ?? $url,
            'release_year' => $year,
            'artist'       => $artist,
        ];
    }
    return $cache[$url] = $data;
}

/**
 * Zeilen-basierter Diff (LCS). Liefert ein <div> mit jeder Zeile farbig markiert:
 *   "+" = neu (grün), "-" = gelöscht (rot), " " = unverändert (gedämpft).
 */
function renderLineDiff(string $oldText, string $newText): string {
    $oldLines = preg_split('/\r\n|\r|\n/', $oldText);
    $newLines = preg_split('/\r\n|\r|\n/', $newText);
    $m = count($oldLines);
    $n = count($newLines);
    $lcs = array_fill(0, $m + 1, array_fill(0, $n + 1, 0));
    for ($i = $m - 1; $i >= 0; $i--) {
        for ($j = $n - 1; $j >= 0; $j--) {
            $lcs[$i][$j] = ($oldLines[$i] === $newLines[$j])
                ? $lcs[$i + 1][$j + 1] + 1
                : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
        }
    }
    $rows = [];
    $i = 0; $j = 0;
    while ($i < $m && $j < $n) {
        if ($oldLines[$i] === $newLines[$j]) {
            $rows[] = [' ', $oldLines[$i]];
            $i++; $j++;
        } elseif ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) {
            $rows[] = ['-', $oldLines[$i++]];
        } else {
            $rows[] = ['+', $newLines[$j++]];
        }
    }
    while ($i < $m) $rows[] = ['-', $oldLines[$i++]];
    while ($j < $n) $rows[] = ['+', $newLines[$j++]];

    $html = '<div class="diff-block">';
    foreach ($rows as [$mark, $line]) {
        $cls = $mark === '+' ? 'diff-add'
            : ($mark === '-' ? 'diff-del' : 'diff-eq');
        $html .= '<div class="' . $cls . '"><span class="diff-mark">' . $mark . '</span>'
              . htmlspecialchars($line === '' ? ' ' : $line) . '</div>';
    }
    $html .= '</div>';
    return $html;
}

function renderEmailHtml(string $headline, array $segments): string {
    $h  = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $brand   = 'Sing op Kölsch';
    $accent  = '#E30613';
    $bg      = '#f5f3ef';
    $card    = '#ffffff';
    $text    = '#1c1c1c';
    $muted   = '#6b6b6b';
    $border  = '#e7e2d8';
    $year    = date('Y');

    $greet    = !empty($segments['greeting'])   ? '<p style="margin:0 0 14px 0;font-size:16px;line-height:1.5;color:' . $text . ';">' . $h($segments['greeting']) . '</p>' : '';
    $intro    = !empty($segments['intro'])      ? '<p style="margin:0 0 16px 0;font-size:15px;line-height:1.55;color:' . $text . ';">' . $h($segments['intro']) . '</p>' : '';
    $detail   = !empty($segments['detail_html'])? '<div style="margin:0 0 18px 0;padding:14px 16px;background:#faf7f1;border:1px solid ' . $border . ';border-radius:10px;font-size:14px;line-height:1.5;color:' . $text . ';">' . $segments['detail_html'] . '</div>' : '';
    $cta      = '';
    if (!empty($segments['cta_label']) && !empty($segments['cta_url'])) {
        $cta = '<div style="margin:22px 0 6px 0;">'
             . '<a href="' . $h($segments['cta_url']) . '" '
             . 'style="display:inline-block;padding:11px 22px;background:' . $accent . ';color:#fff;text-decoration:none;'
             . 'font-weight:600;font-size:15px;border-radius:9px;">' . $h($segments['cta_label']) . '</a>'
             . '</div>';
    }
    $outro    = !empty($segments['outro'])      ? '<p style="margin:18px 0 0 0;font-size:14px;line-height:1.55;color:' . $muted . ';">' . $h($segments['outro']) . '</p>' : '';
    $footerNote = !empty($segments['footer_note']) ? '<p style="margin:0 0 6px 0;font-size:12px;line-height:1.5;color:' . $muted . ';">' . $h($segments['footer_note']) . '</p>' : '';

    return '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8">'
         . '<meta name="viewport" content="width=device-width,initial-scale=1">'
         . '<title>' . $h($headline) . '</title></head>'
         . '<body style="margin:0;padding:0;background:' . $bg . ';font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;">'
         . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:' . $bg . ';padding:28px 12px;">'
         .   '<tr><td align="center">'
         .     '<table role="presentation" width="560" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;width:100%;background:' . $card . ';border-radius:14px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.06);">'
         // Header
         .       '<tr><td style="background:' . $accent . ';padding:18px 24px;">'
         .         '<div style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;color:#fff;font-size:18px;font-weight:700;letter-spacing:0.2px;">'
         .           $brand
         .         '</div>'
         .       '</td></tr>'
         // Body
         .       '<tr><td style="padding:26px 28px 22px 28px;">'
         .         '<h1 style="margin:0 0 16px 0;font-size:21px;line-height:1.3;color:' . $text . ';font-weight:700;">' . $h($headline) . '</h1>'
         .         $greet . $intro . $detail . $cta . $outro
         .       '</td></tr>'
         // Footer
         .       '<tr><td style="padding:16px 28px 22px 28px;border-top:1px solid ' . $border . ';background:#fafafa;">'
         .         $footerNote
         .         '<p style="margin:0;font-size:12px;line-height:1.5;color:' . $muted . ';">'
         .           '© ' . $year . ' ' . $brand . ' · '
         .           '<a href="' . $h(SITE_URL) . '/profile.php" style="color:' . $muted . ';text-decoration:underline;">E-Mail-Einstellungen</a> · '
         .           '<a href="{{UNSUBSCRIBE_URL}}" style="color:' . $muted . ';text-decoration:underline;">Mails abbestellen</a>'
         .         '</p>'
         .       '</td></tr>'
         .     '</table>'
         .   '</td></tr>'
         . '</table></body></html>';
}

class Database {
    private static $conn = null;

    public static function getConnection(): mysqli {
        if (self::$conn === null) {
            self::$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
            if (self::$conn->connect_error) {
                die("Datenbank-Verbindung fehlgeschlagen.");
            }
            self::$conn->set_charset("utf8mb4");
        }
        return self::$conn;
    }

    // Resolve a band select value to an ID.
    // Handles "new:Bandname" (create if needed), numeric IDs, and empty values.
    public static function processNewBandEntries($values): array {
        if ($values === null) return [];
        if (!is_array($values)) $values = [$values];
        $ids = [];
        foreach ($values as $v) {
            $id = self::processNewBandEntry((string)$v);
            if ($id !== null) $ids[] = $id;
        }
        return array_values(array_unique($ids));
    }

    public static function processNewBandEntry(?string $value): ?int {
        if ($value === null || $value === '') return null;

        if (substr($value, 0, 4) === 'new:') {
            $name = trim(substr($value, 4));
            if ($name === '') return null;
            return self::getOrCreateBand($name);
        }

        if (is_numeric($value)) {
            return (int)$value;
        }

        return null;
    }

    private static function getOrCreateBand(string $name): ?int {
        $conn = self::getConnection();
        $stmt = $conn->prepare("SELECT band_id FROM singopkoelsch_bands WHERE band_name = ?");
        $stmt->bind_param("s", $name);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $stmt->bind_result($id);
            $stmt->fetch();
            $stmt->close();
            return (int)$id;
        }
        $stmt->close();

        $stmt = $conn->prepare("INSERT INTO singopkoelsch_bands (band_name) VALUES (?) ON DUPLICATE KEY UPDATE band_name=band_name");
        $stmt->bind_param("s", $name);
        $stmt->execute();
        $id = $stmt->insert_id;
        $stmt->close();
        return $id ?: null;
    }

    public static function queryData(): array {
        $conn = self::getConnection();
        $result = $conn->query("SELECT id, title, lyrics, band_id, spotify_link, video_link, album, release_year FROM singopkoelsch_lyrics ORDER BY title");
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }

    /**
     * Rich filtered query — combines text search + filters + sort.
     * $filters: ['band_id' => int, 'event_id' => int, 'year_from' => int, 'year_to' => int,
     *            'has_lyrics' => bool, 'has_spotify' => bool, 'has_video' => bool]
     * $sort:    'title' | 'title_desc' | 'year' | 'year_desc' | 'band'
     */
    public static function queryFiltered(string $search, array $filters = [], string $sort = 'title', ?int $limit = null, int $offset = 0): array {
        $conn  = self::getConnection();
        $where = [];
        $types = '';
        $vals  = [];

        // Text search (ranked)
        $hasSearch = $search !== '';
        if ($hasSearch) {
            $like = "%$search%";
            $where[] = "(l.title LIKE ? OR b.band_name LIKE ? OR l.lyrics LIKE ?
                       OR l.album LIKE ? OR l.release_year LIKE ?
                       OR ta.band_name LIKE ? OR ma.band_name LIKE ?)";
            $types .= str_repeat('s', 7);
            for ($i = 0; $i < 7; $i++) $vals[] = $like;
        }

        // Filter: interpret (band)
        if (!empty($filters['band_id'])) {
            $where[] = 'l.band_id = ?';
            $types  .= 'i';
            $vals[]  = (int)$filters['band_id'];
        }

        // Filter: text author
        if (!empty($filters['text_autor_id'])) {
            $where[] = 'l.text_autor_id = ?';
            $types  .= 'i';
            $vals[]  = (int)$filters['text_autor_id'];
        }

        // Filter: music author
        if (!empty($filters['musik_autor_id'])) {
            $where[] = 'l.musik_autor_id = ?';
            $types  .= 'i';
            $vals[]  = (int)$filters['musik_autor_id'];
        }

        // Filter: event (song played at event)
        if (!empty($filters['event_id'])) {
            $where[] = 'EXISTS (SELECT 1 FROM singopkoelsch_song_events se WHERE se.song_id = l.id AND se.event_id = ?)';
            $types  .= 'i';
            $vals[]  = (int)$filters['event_id'];
        }

        // Filter: year range
        if (!empty($filters['year_from'])) {
            $where[] = 'l.release_year >= ?';
            $types  .= 'i';
            $vals[]  = (int)$filters['year_from'];
        }
        if (!empty($filters['year_to'])) {
            $where[] = 'l.release_year <= ?';
            $types  .= 'i';
            $vals[]  = (int)$filters['year_to'];
        }
        // #51 Filter: decade
        if (!empty($filters['decade'])) {
            $d = (int)$filters['decade'];
            $where[] = 'l.release_year >= ? AND l.release_year < ?';
            $types  .= 'ii';
            $vals[]  = $d;
            $vals[]  = $d + 10;
        }

        // Filter: has lyrics / Spotify / video
        if (!empty($filters['has_lyrics'])) {
            $where[] = 'LENGTH(COALESCE(l.lyrics, "")) > 50';
        }
        if (!empty($filters['has_spotify'])) {
            $where[] = "(l.spotify_link IS NOT NULL AND l.spotify_link != '')";
        }
        if (!empty($filters['has_video'])) {
            $where[] = "(l.video_link IS NOT NULL AND l.video_link != '')";
        }

        // Filter: incomplete (missing album/year/authors/cover/spotify)
        if (!empty($filters['incomplete'])) {
            $where[] = "(
                l.album IS NULL OR l.album = ''
                OR l.release_year IS NULL OR l.release_year = 0
                OR l.text_autor_id IS NULL
                OR l.musik_autor_id IS NULL
                OR l.cover_url IS NULL OR l.cover_url = ''
                OR l.spotify_link IS NULL OR l.spotify_link = ''
                OR LENGTH(COALESCE(l.lyrics, '')) < 50
            )";
        }

        // Filter: flagged as suspect
        if (!empty($filters['flagged'])) {
            $where[] = 'l.flagged = 1';
        }

        // Build search-rank column for sorting when searching
        $rankCol = $hasSearch
            ? "CASE
                WHEN LOWER(b.band_name)  = LOWER(?) THEN 1
                WHEN b.band_name  LIKE ?            THEN 2
                WHEN LOWER(l.title)      = LOWER(?) THEN 3
                WHEN l.title      LIKE ?            THEN 4
                WHEN ta.band_name LIKE ?            THEN 5
                WHEN ma.band_name LIKE ?            THEN 5
                WHEN l.album      LIKE ?            THEN 5
                WHEN l.release_year LIKE ?          THEN 5
                WHEN l.lyrics     LIKE ?            THEN 6
                ELSE 99 END"
            : '0';

        if ($hasSearch) {
            $like = "%$search%";
            // Prepend the rank params (search, like × 8) to the front of params for the CASE
            $rankTypes = 'sssssssss';
            $rankVals  = [$search, $like, $search, $like, $like, $like, $like, $like, $like];
            $types = $rankTypes . $types;
            $vals  = array_merge($rankVals, $vals);
        }

        // Sort
        switch ($sort) {
            case 'title_desc': $orderBy = 'l.title DESC'; break;
            case 'year':      $orderBy = 'l.release_year ASC, l.title ASC'; break;
            case 'year_desc': $orderBy = 'l.release_year DESC, l.title ASC'; break;
            case 'band':      $orderBy = 'b.band_name ASC, l.title ASC'; break;
            default:          $orderBy = 'l.title ASC'; break;
        }
        if ($hasSearch) $orderBy = 'rank ASC, ' . $orderBy;

        $whereSql = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);

        $sql = "SELECT l.id, l.title, l.lyrics, l.band_id, l.spotify_link, l.video_link,
                       l.album, l.release_year, l.cover_url, l.flagged, l.flag_reason,
                       b.band_name,
                       ta.band_name AS text_autor_name,
                       ma.band_name AS musik_autor_name,
                       $rankCol AS rank
                FROM singopkoelsch_lyrics l
                LEFT JOIN singopkoelsch_bands b  ON l.band_id       = b.band_id
                LEFT JOIN singopkoelsch_bands ta ON l.text_autor_id = ta.band_id
                LEFT JOIN singopkoelsch_bands ma ON l.musik_autor_id= ma.band_id
                $whereSql
                ORDER BY $orderBy";

        if ($limit !== null) {
            $sql .= ' LIMIT ? OFFSET ?';
            $types .= 'ii';
            $vals[] = $limit;
            $vals[] = $offset;
        }

        $stmt = $conn->prepare($sql);
        if ($types !== '') $stmt->bind_param($types, ...$vals);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $data;
    }

    public static function queryFilteredCount(string $search, array $filters = []): int {
        $conn  = self::getConnection();
        $where = [];
        $types = '';
        $vals  = [];

        $hasSearch = $search !== '';
        if ($hasSearch) {
            $like = "%$search%";
            $where[] = "(l.title LIKE ? OR b.band_name LIKE ? OR l.lyrics LIKE ?
                       OR l.album LIKE ? OR l.release_year LIKE ?
                       OR ta.band_name LIKE ? OR ma.band_name LIKE ?)";
            $types .= str_repeat('s', 7);
            for ($i = 0; $i < 7; $i++) $vals[] = $like;
        }

        if (!empty($filters['band_id'])) {
            $where[] = 'l.band_id = ?'; $types .= 'i'; $vals[] = (int)$filters['band_id'];
        }
        if (!empty($filters['text_autor_id'])) {
            $where[] = 'l.text_autor_id = ?'; $types .= 'i'; $vals[] = (int)$filters['text_autor_id'];
        }
        if (!empty($filters['musik_autor_id'])) {
            $where[] = 'l.musik_autor_id = ?'; $types .= 'i'; $vals[] = (int)$filters['musik_autor_id'];
        }
        if (!empty($filters['event_id'])) {
            $where[] = 'EXISTS (SELECT 1 FROM singopkoelsch_song_events se WHERE se.song_id = l.id AND se.event_id = ?)';
            $types .= 'i'; $vals[] = (int)$filters['event_id'];
        }
        if (!empty($filters['year_from'])) {
            $where[] = 'l.release_year >= ?'; $types .= 'i'; $vals[] = (int)$filters['year_from'];
        }
        if (!empty($filters['year_to'])) {
            $where[] = 'l.release_year <= ?'; $types .= 'i'; $vals[] = (int)$filters['year_to'];
        }
        if (!empty($filters['decade'])) {
            $d = (int)$filters['decade'];
            $where[] = 'l.release_year >= ? AND l.release_year < ?';
            $types .= 'ii'; $vals[] = $d; $vals[] = $d + 10;
        }
        if (!empty($filters['has_lyrics']))  $where[] = 'LENGTH(COALESCE(l.lyrics, "")) > 50';
        if (!empty($filters['has_spotify'])) $where[] = "(l.spotify_link IS NOT NULL AND l.spotify_link != '')";
        if (!empty($filters['has_video']))   $where[] = "(l.video_link IS NOT NULL AND l.video_link != '')";
        if (!empty($filters['incomplete'])) {
            $where[] = "(l.album IS NULL OR l.album = '' OR l.release_year IS NULL OR l.release_year = 0
                OR l.text_autor_id IS NULL OR l.musik_autor_id IS NULL
                OR l.cover_url IS NULL OR l.cover_url = ''
                OR l.spotify_link IS NULL OR l.spotify_link = ''
                OR LENGTH(COALESCE(l.lyrics, '')) < 50)";
        }
        if (!empty($filters['flagged'])) $where[] = 'l.flagged = 1';

        $whereSql = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);

        $sql = "SELECT COUNT(*) FROM singopkoelsch_lyrics l
                LEFT JOIN singopkoelsch_bands b  ON l.band_id       = b.band_id
                LEFT JOIN singopkoelsch_bands ta ON l.text_autor_id = ta.band_id
                LEFT JOIN singopkoelsch_bands ma ON l.musik_autor_id= ma.band_id
                $whereSql";

        $stmt = $conn->prepare($sql);
        if ($types !== '') $stmt->bind_param($types, ...$vals);
        $stmt->execute();
        $count = (int)$stmt->get_result()->fetch_row()[0];
        $stmt->close();
        return $count;
    }

    public static function queryDataBySearch(string $search): array {
        $conn = self::getConnection();
        // Rank: 1 = band exact, 2 = band partial, 3 = title exact, 4 = title partial,
        //       5 = album/author/year, 6 = lyrics
        $stmt = $conn->prepare(
            "SELECT l.id, l.title, l.lyrics, l.band_id, l.spotify_link, l.video_link, l.album, l.release_year,
                    b.band_name, ta.band_name AS text_autor_name, ma.band_name AS musik_autor_name,
                    CASE
                        WHEN LOWER(b.band_name)  = LOWER(?) THEN 1
                        WHEN b.band_name  LIKE ?            THEN 2
                        WHEN LOWER(l.title)      = LOWER(?) THEN 3
                        WHEN l.title      LIKE ?            THEN 4
                        WHEN ta.band_name LIKE ?            THEN 5
                        WHEN ma.band_name LIKE ?            THEN 5
                        WHEN l.album      LIKE ?            THEN 5
                        WHEN l.release_year LIKE ?          THEN 5
                        WHEN l.lyrics     LIKE ?            THEN 6
                        ELSE 99
                    END AS rank
             FROM singopkoelsch_lyrics l
             LEFT JOIN singopkoelsch_bands b  ON l.band_id       = b.band_id
             LEFT JOIN singopkoelsch_bands ta ON l.text_autor_id = ta.band_id
             LEFT JOIN singopkoelsch_bands ma ON l.musik_autor_id= ma.band_id
             WHERE l.title LIKE ? OR b.band_name LIKE ? OR l.lyrics LIKE ?
                OR l.album LIKE ? OR l.release_year LIKE ? OR ta.band_name LIKE ? OR ma.band_name LIKE ?
             ORDER BY rank ASC, l.title ASC"
        );
        $like = "%$search%";
        // Bind params: 2x exact (band/title), 7x LIKE for CASE, 7x LIKE for WHERE
        $stmt->bind_param(
            "ssssssssssssssss",
            $search, $like,           // band exact, band partial
            $search, $like,           // title exact, title partial
            $like, $like, $like, $like, // text_autor, musik_autor, album, year
            $like,                    // lyrics
            $like, $like, $like, $like, $like, $like, $like  // WHERE clauses
        );
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $data;
    }

    public static function queryDataById(int $id): ?array {
        $conn = self::getConnection();
        $stmt = $conn->prepare("SELECT id, title, band_id, text_autor_id, musik_autor_id, album, spotify_link, cover_url, video_link, release_year, lyrics, flagged, flag_reason FROM singopkoelsch_lyrics WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$data) return null;

        // Fetch all artists from junction table
        $ar = $conn->prepare("SELECT band_id, role FROM singopkoelsch_song_artists WHERE lyric_id = ? ORDER BY sort_order, id");
        $ar->bind_param("i", $id);
        $ar->execute();
        $artists = $ar->get_result()->fetch_all(MYSQLI_ASSOC);
        $ar->close();

        $data['performer_ids'] = array_values(array_map('intval', array_column(array_filter($artists, fn($a) => $a['role'] === 'performer'), 'band_id')));
        $data['text_ids']      = array_values(array_map('intval', array_column(array_filter($artists, fn($a) => $a['role'] === 'text'),      'band_id')));
        $data['music_ids']     = array_values(array_map('intval', array_column(array_filter($artists, fn($a) => $a['role'] === 'music'),     'band_id')));

        // Fallback to FK columns if junction table has no data yet
        if (empty($data['performer_ids']) && !empty($data['band_id']))       $data['performer_ids'] = [(int)$data['band_id']];
        if (empty($data['text_ids'])      && !empty($data['text_autor_id'])) $data['text_ids']      = [(int)$data['text_autor_id']];
        if (empty($data['music_ids'])     && !empty($data['musik_autor_id'])) $data['music_ids']    = [(int)$data['musik_autor_id']];

        return $data;
    }

    public static function updateSongArtists(int $lyricId, array $performerIds, array $textIds, array $musicIds): void {
        $conn = self::getConnection();
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("DELETE FROM singopkoelsch_song_artists WHERE lyric_id = ?");
            $stmt->bind_param("i", $lyricId); $stmt->execute(); $stmt->close();
            foreach ([['performer', $performerIds], ['text', $textIds], ['music', $musicIds]] as [$role, $ids]) {
                foreach ($ids as $i => $bid) {
                    $bid = (int)$bid;
                    if ($bid <= 0) continue;
                    $s = $conn->prepare("INSERT IGNORE INTO singopkoelsch_song_artists (lyric_id, band_id, role, sort_order) VALUES (?, ?, ?, ?)");
                    $s->bind_param("isis", $lyricId, $bid, $role, $i);
                    $s->execute(); $s->close();
                }
            }
            $conn->commit();
        } catch (Exception $e) {
            $conn->rollback();
            error_log('updateSongArtists: ' . $e->getMessage());
        }
    }

    public static function getBandMap(): array {
        $conn = self::getConnection();
        $result = $conn->query("SELECT band_id, band_name FROM singopkoelsch_bands ORDER BY band_name");
        $map = [];
        while ($row = $result->fetch_assoc()) {
            $map[$row['band_id']] = $row['band_name'];
        }
        return $map;
    }

    public static function getBandList(): array {
        $conn = self::getConnection();
        $result = $conn->query("SELECT band_id, band_name FROM singopkoelsch_bands ORDER BY band_name");
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }

    public static function updateData(int $id, string $title, string $lyrics, $bandIds, $textAutorIds, $musikAutorIds, string $album, string $spotify, string $video, $year): bool {
        $conn = self::getConnection();

        if (empty($title)) return false;

        // Normalize to arrays
        $performerIds = is_array($bandIds)       ? $bandIds       : ($bandIds       !== null ? [$bandIds]       : []);
        $textIds      = is_array($textAutorIds)  ? $textAutorIds  : ($textAutorIds  !== null ? [$textAutorIds]  : []);
        $musicIds     = is_array($musikAutorIds) ? $musikAutorIds : ($musikAutorIds !== null ? [$musikAutorIds] : []);

        // First value goes into legacy FK columns for backward compat
        $primaryBand  = !empty($performerIds) ? (int)$performerIds[0] : null;
        $primaryText  = !empty($textIds)      ? (int)$textIds[0]      : null;
        $primaryMusic = !empty($musicIds)     ? (int)$musicIds[0]     : null;

        $stmt = $conn->prepare(
            "UPDATE singopkoelsch_lyrics SET
                title = ?, lyrics = ?, band_id = ?, text_autor_id = ?, musik_autor_id = ?,
                album = ?, spotify_link = ?, video_link = ?, release_year = ?
             WHERE id = ?"
        );
        if (!$stmt) return false;

        $stmt->bind_param("ssiiisssii", $title, $lyrics, $primaryBand, $primaryText, $primaryMusic, $album, $spotify, $video, $year, $id);
        $result = $stmt->execute();
        $stmt->close();

        if ($result) self::updateSongArtists($id, $performerIds, $textIds, $musicIds);
        return $result;
    }

    public static function deleteData(int $id): bool {
        $conn = self::getConnection();
        $stmt = $conn->prepare("DELETE FROM singopkoelsch_lyrics WHERE id = ?");
        if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
        $stmt->bind_param("i", $id);
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    }

    // Kept as before — reads $_POST directly (used by add.php and bulk import)
    private static function getBandIdByName($conn, $bandName) {
        if (!$bandName) return null;

        if (substr($bandName, 0, 4) === "new:") {
            $bandName = substr($bandName, 4);
        }

        if (is_numeric($bandName)) {
            return (int)$bandName;
        }

        $stmt = $conn->prepare("INSERT INTO singopkoelsch_bands (band_name) VALUES (?) ON DUPLICATE KEY UPDATE band_name=band_name");
        $stmt->bind_param("s", $bandName);
        $stmt->execute();
        $bandId = $stmt->insert_id ?: $conn->insert_id;
        $stmt->close();
        return $bandId;
    }

    public static function insertData(): bool {
        if ($_SERVER["REQUEST_METHOD"] !== "POST") return false;

        $title        = trim($_POST["title"] ?? "");
        $album        = $_POST["album"] ?? "";
        $spotify      = $_POST["spotify_link"] ?? "";
        $video        = $_POST["video_link"] ?? "";
        $year         = $_POST["release_year"] ?? "";
        $lyrics       = trim($_POST["lyrics"] ?? "");

        // Multi-artist: accept arrays from multi-select or single values
        $bandRaw      = $_POST["band_id"] ?? [];
        $textRaw      = $_POST["text_autor_id"] ?? [];
        $musikRaw     = $_POST["musik_autor_id"] ?? [];

        if (empty($title)) {
            $_POST["message"] = "Es wird ein Titel benötigt.";
            return false;
        }

        $conn = self::getConnection();

        // Resolve all artist values to band IDs
        $performerIds = [];
        foreach ((array)$bandRaw as $v)  { $id = self::getBandIdByName($conn, $v);  if ($id) $performerIds[] = $id; }
        $textIds      = [];
        foreach ((array)$textRaw as $v)  { $id = self::getBandIdByName($conn, $v);  if ($id) $textIds[]      = $id; }
        $musicIds     = [];
        foreach ((array)$musikRaw as $v) { $id = self::getBandIdByName($conn, $v);  if ($id) $musicIds[]     = $id; }

        $primaryBand  = !empty($performerIds) ? $performerIds[0] : null;
        $primaryText  = !empty($textIds)      ? $textIds[0]      : null;
        $primaryMusic = !empty($musicIds)     ? $musicIds[0]     : null;

        $stmtCheck = $conn->prepare("SELECT id FROM singopkoelsch_lyrics WHERE title = ? AND band_id = ?");
        $stmtCheck->bind_param("si", $title, $primaryBand);
        $stmtCheck->execute();
        $existing = $stmtCheck->get_result()->fetch_assoc();
        $stmtCheck->close();

        if ($existing) {
            $stmtUpdate = $conn->prepare(
                "UPDATE singopkoelsch_lyrics SET
                    text_autor_id  = COALESCE(text_autor_id, ?),
                    musik_autor_id = COALESCE(musik_autor_id, ?)
                 WHERE id = ?"
            );
            $stmtUpdate->bind_param("iii", $primaryText, $primaryMusic, $existing['id']);
            $stmtUpdate->execute();
            $stmtUpdate->close();
            self::updateSongArtists((int)$existing['id'], $performerIds, $textIds, $musicIds);
            $_POST["message"] = "Lied bereits vorhanden, Autoren ggf. verknüpft.";
            return false;
        }

        $stmt = $conn->prepare("INSERT INTO singopkoelsch_lyrics (title, band_id, text_autor_id, musik_autor_id, lyrics, spotify_link, video_link, release_year, album) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) {
            $_POST["message"] = "Fehler beim Vorbereiten des Statements.";
            return false;
        }

        $year = $year ?: null;
        $stmt->bind_param("siissssss", $title, $primaryBand, $primaryText, $primaryMusic, $lyrics, $spotify, $video, $year, $album);
        $success = $stmt->execute();
        if ($success) {
            $newId = (int)$conn->insert_id;
            self::updateSongArtists($newId, $performerIds, $textIds, $musicIds);
        }
        $_POST["message"] = $success ? "Lied erfolgreich hinzugefügt." : "Fehler beim Hinzufügen des Liedes.";
        $stmt->close();
        return $success;
    }

    public static function insertChangeRequest(int $lyricsId, int $userId, string $proposedLyrics): bool {
        $conn = self::getConnection();
        $stmt = $conn->prepare("INSERT INTO singopkoelsch_change_requests (lyrics_id, user_id, proposed_lyrics) VALUES (?, ?, ?)");
        if (!$stmt) return false;
        $stmt->bind_param("iis", $lyricsId, $userId, $proposedLyrics);
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    }

    public static function insertAndApplyChangeRequest(int $lyricsId, int $userId, string $proposedLyrics): bool {
        $conn = self::getConnection();
        $conn->begin_transaction();
        try {
            $ins = $conn->prepare("INSERT INTO singopkoelsch_change_requests (lyrics_id, user_id, proposed_lyrics, status) VALUES (?, ?, ?, 'approved')");
            $ins->bind_param("iis", $lyricsId, $userId, $proposedLyrics);
            $ins->execute();
            $ins->close();

            $upd = $conn->prepare("UPDATE singopkoelsch_lyrics SET lyrics = ? WHERE id = ?");
            $upd->bind_param("si", $proposedLyrics, $lyricsId);
            $upd->execute();
            $upd->close();

            $conn->commit();
            return true;
        } catch (Exception $e) {
            $conn->rollback();
            return false;
        }
    }

    // Full-record change request (all fields). proposed_changes is JSON.
    public static function insertFullChangeRequest(int $lyricsId, int $userId, array $changes): bool {
        $conn = self::getConnection();
        $json = json_encode($changes, JSON_UNESCAPED_UNICODE);
        $stub = (string)($changes['lyrics'] ?? '');
        $stmt = $conn->prepare("INSERT INTO singopkoelsch_change_requests (lyrics_id, user_id, proposed_lyrics, proposed_changes) VALUES (?, ?, ?, ?)");
        if (!$stmt) return false;
        $stmt->bind_param("iiss", $lyricsId, $userId, $stub, $json);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    // Approve a partial change-request: build an UPDATE that only touches the
    // fields actually present in $changes, so unchanged columns are preserved.
    public static function approveFullChangeRequest(int $crId, int $lyricsId, array $changes): bool {
        $conn = self::getConnection();

        // Extract multi-artist arrays (handled separately via junction table)
        $performerIds = isset($changes['performer_ids']) && is_array($changes['performer_ids']) ? $changes['performer_ids'] : null;
        $textIds      = isset($changes['text_ids'])      && is_array($changes['text_ids'])      ? $changes['text_ids']      : null;
        $musicIds     = isset($changes['music_ids'])     && is_array($changes['music_ids'])     ? $changes['music_ids']     : null;

        // Whitelist of allowed columns and their bind types.
        $cols = [
            'title'          => 's',
            'lyrics'         => 's',
            'band_id'        => 'i',
            'text_autor_id'  => 'i',
            'musik_autor_id' => 'i',
            'album'          => 's',
            'spotify_link'   => 's',
            'video_link'     => 's',
            'release_year'   => 'i',
        ];
        $set = [];
        $types = '';
        $values = [];
        foreach ($cols as $col => $type) {
            if (!array_key_exists($col, $changes)) continue;
            $val = $changes[$col];
            if ($type === 'i') {
                $val = ($val === null || $val === '') ? null : (int)$val;
            } else {
                $val = $val === null ? '' : (string)$val;
            }
            $set[] = "$col = ?";
            $types .= $type;
            $values[] = $val;
        }

        // If multi-artist arrays present but no primary FK in changes, sync FK from first array value
        if ($performerIds !== null && !array_key_exists('band_id', $changes)) {
            $v = !empty($performerIds) ? (int)$performerIds[0] : null;
            $set[] = "band_id = ?"; $types .= 'i'; $values[] = $v;
        }
        if ($textIds !== null && !array_key_exists('text_autor_id', $changes)) {
            $v = !empty($textIds) ? (int)$textIds[0] : null;
            $set[] = "text_autor_id = ?"; $types .= 'i'; $values[] = $v;
        }
        if ($musicIds !== null && !array_key_exists('musik_autor_id', $changes)) {
            $v = !empty($musicIds) ? (int)$musicIds[0] : null;
            $set[] = "musik_autor_id = ?"; $types .= 'i'; $values[] = $v;
        }

        if (empty($set) && $performerIds === null && $textIds === null && $musicIds === null) {
            // Nothing to apply, just mark approved.
            $stmt = $conn->prepare("UPDATE singopkoelsch_change_requests SET status='approved' WHERE id = ?");
            $stmt->bind_param("i", $crId);
            $ok = $stmt->execute();
            $stmt->close();
            return $ok;
        }

        $conn->begin_transaction();
        try {
            if (!empty($set)) {
                $sql = "UPDATE singopkoelsch_lyrics SET " . implode(', ', $set) . " WHERE id = ?";
                $types .= 'i';
                $values[] = $lyricsId;
                $stmt = $conn->prepare($sql);
                if (!$stmt) throw new Exception('prepare failed: ' . $conn->error);
                $stmt->bind_param($types, ...$values);
                if (!$stmt->execute()) throw new Exception('execute failed: ' . $stmt->error);
                $stmt->close();
            }

            // Apply multi-artist arrays to junction table
            if ($performerIds !== null || $textIds !== null || $musicIds !== null) {
                $current = self::queryDataById($lyricsId);
                $newPerf  = $performerIds ?? ($current['performer_ids'] ?? []);
                $newText  = $textIds      ?? ($current['text_ids']      ?? []);
                $newMusic = $musicIds     ?? ($current['music_ids']     ?? []);
                self::updateSongArtists($lyricsId, $newPerf, $newText, $newMusic);
            }

            $stmt = $conn->prepare("UPDATE singopkoelsch_change_requests SET status='approved' WHERE id = ?");
            $stmt->bind_param("i", $crId);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
            return true;
        } catch (Exception $e) {
            $conn->rollback();
            error_log('approveFullChangeRequest: ' . $e->getMessage());
            return false;
        }
    }

    public static function getAdmins(): array {
        $conn = self::getConnection();
        $result = $conn->query("SELECT user_id, name, email FROM singopkoelsch_users WHERE role = 'admin'");
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }

    public static function getUserById(int $userId): ?array {
        $conn = self::getConnection();
        $stmt = $conn->prepare("SELECT name FROM singopkoelsch_users WHERE user_id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $data ?: null;
    }

    /**
     * Returns true if the user with $email allows non-essential notifications.
     * Defaults to true when no user or no preference row exists (so guests and
     * admins without an explicit preference still get mail).
     */
    public static function userAllowsEmail(string $email): bool {
        if ($email === '') return true;
        $conn = self::getConnection();
        self::ensurePreferencesTable();
        $stmt = $conn->prepare(
            "SELECT COALESCE(p.email_notifications, 1) AS en
               FROM singopkoelsch_users u
          LEFT JOIN singopkoelsch_user_preferences p ON p.user_id = u.user_id
              WHERE u.email = ?
              LIMIT 1"
        );
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) return true; // not a registered user
        return (int)$row['en'] === 1;
    }

    /** Stellt sicher, dass der User einen unsubscribe_token hat und gibt den fertigen Link zurück.
     *  Liefert '' wenn keine Email-Adresse zu einem User gehört (Vorschau, Tests). */
    public static function unsubscribeLinkFor(string $email): string {
        if ($email === '') return '';
        $conn = self::getConnection();
        $stmt = $conn->prepare("SELECT user_id, unsubscribe_token FROM singopkoelsch_users WHERE email = ? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) return '';
        $token = $row['unsubscribe_token'];
        if (!$token) {
            $token = bin2hex(random_bytes(32));
            $upd = $conn->prepare("UPDATE singopkoelsch_users SET unsubscribe_token = ? WHERE user_id = ?");
            $upd->bind_param("si", $token, $row['user_id']);
            $upd->execute();
            $upd->close();
        }
        return SITE_URL . '/unsubscribe.php?token=' . $token;
    }

    /** Setzt email_notifications=0 für den User mit diesem Token. */
    public static function unsubscribeByToken(string $token): ?array {
        if ($token === '') return null;
        $conn = self::getConnection();
        $stmt = $conn->prepare("SELECT user_id, name, email FROM singopkoelsch_users WHERE unsubscribe_token = ? LIMIT 1");
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$user) return null;
        self::ensurePreferencesTable();
        // upsert preferences with email_notifications=0
        $prefs = self::getUserPreferences((int)$user['user_id']);
        self::saveAllPreferences((int)$user['user_id'], (int)$prefs['dark_mode'], 0);
        return $user;
    }

    /** Löscht einen User und alle abhängigen Datensätze. */
    public static function deleteUser(int $userId): bool {
        if ($userId <= 0) return false;
        $conn = self::getConnection();
        $conn->begin_transaction();
        try {
            foreach ([
                'DELETE FROM singopkoelsch_user_preferences WHERE user_id = ?',
                'DELETE FROM singopkoelsch_change_requests WHERE user_id = ?',
                'DELETE FROM singopkoelsch_cover_proposals WHERE user_id = ?',
                'DELETE FROM singopkoelsch_photos WHERE user_id = ?',
                'DELETE FROM singopkoelsch_users WHERE user_id = ?',
            ] as $sql) {
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $userId);
                $stmt->execute();
                $stmt->close();
            }
            $conn->commit();
            return true;
        } catch (\Throwable $e) {
            $conn->rollback();
            error_log('deleteUser failed: ' . $e->getMessage());
            return false;
        }
    }

    /** Findet einen nicht-verifizierten User per E-Mail und stellt sicher, dass er
     *  einen verify_token hat. Gibt User + Token zurück, oder null wenn:
     *   - keine Adresse gefunden, oder
     *   - bereits verifiziert.
     *  Verhindert Auskunft über Existenz der Adresse: Aufrufer prüft das nicht. */
    public static function ensureVerificationTokenFor(string $email): ?array {
        if ($email === '') return null;
        $conn = self::getConnection();
        $stmt = $conn->prepare(
            "SELECT user_id, name, email_verified, verify_token
               FROM singopkoelsch_users
              WHERE email = ?
              LIMIT 1"
        );
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) return null;
        if ((int)$row['email_verified'] === 1) return null;
        $token = $row['verify_token'];
        if (!$token) {
            $token = bin2hex(random_bytes(32));
            $upd = $conn->prepare("UPDATE singopkoelsch_users SET verify_token = ? WHERE user_id = ?");
            $upd->bind_param("si", $token, $row['user_id']);
            $upd->execute();
            $upd->close();
        }
        return ['user_id' => (int)$row['user_id'], 'name' => $row['name'] ?? '', 'token' => $token];
    }

    /** Erzeugt und speichert einen Passwort-Reset-Token (60 min Gültigkeit). */
    public static function createPasswordResetToken(string $email): ?array {
        if ($email === '') return null;
        $conn = self::getConnection();
        $stmt = $conn->prepare("SELECT user_id, name FROM singopkoelsch_users WHERE email = ? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$user) return null;
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + 3600);
        $upd = $conn->prepare("UPDATE singopkoelsch_users SET reset_token = ?, reset_expires = ? WHERE user_id = ?");
        $upd->bind_param("ssi", $token, $expires, $user['user_id']);
        $upd->execute();
        $upd->close();
        return ['user_id' => (int)$user['user_id'], 'name' => $user['name'], 'token' => $token];
    }

    /** Sucht User für gültigen, nicht abgelaufenen Reset-Token. */
    public static function getUserByValidResetToken(string $token): ?array {
        if ($token === '') return null;
        $conn = self::getConnection();
        $stmt = $conn->prepare(
            "SELECT user_id, name, email FROM singopkoelsch_users
              WHERE reset_token = ? AND reset_expires IS NOT NULL AND reset_expires > NOW()
              LIMIT 1"
        );
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $user ?: null;
    }

    /** Setzt ein neues Passwort und invalidiert Reset-Token. */
    public static function applyPasswordReset(int $userId, string $newHash): bool {
        $conn = self::getConnection();
        $stmt = $conn->prepare(
            "UPDATE singopkoelsch_users
                SET password = ?, reset_token = NULL, reset_expires = NULL
              WHERE user_id = ?"
        );
        $stmt->bind_param("si", $newHash, $userId);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public static function getPendingChangeRequests(int $lyricsId): array {
        $conn = self::getConnection();
        $stmt = $conn->prepare(
            "SELECT cr.id, cr.proposed_lyrics, cr.proposed_changes, cr.created_at, u.name as user_name
             FROM singopkoelsch_change_requests cr
             JOIN singopkoelsch_users u ON cr.user_id = u.user_id
             WHERE cr.lyrics_id = ? AND cr.status = 'pending'
             ORDER BY cr.created_at DESC"
        );
        $stmt->bind_param("i", $lyricsId);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $data;
    }

    public static function approveChangeRequest(int $id, int $lyricsId, string $newLyrics): bool {
        $conn = self::getConnection();
        $conn->begin_transaction();
        try {
            $stmt1 = $conn->prepare("UPDATE singopkoelsch_lyrics SET lyrics = ? WHERE id = ?");
            $stmt1->bind_param("si", $newLyrics, $lyricsId);
            $stmt1->execute();
            $stmt1->close();

            $stmt2 = $conn->prepare("UPDATE singopkoelsch_change_requests SET status = 'approved', resolved_at = NOW() WHERE id = ?");
            $stmt2->bind_param("i", $id);
            $stmt2->execute();
            $stmt2->close();

            $conn->commit();
            return true;
        } catch (Exception $e) {
            $conn->rollback();
            return false;
        }
    }

    public static function rejectChangeRequest(int $id): bool {
        $conn = self::getConnection();
        $stmt = $conn->prepare("UPDATE singopkoelsch_change_requests SET status = 'rejected', resolved_at = NOW() WHERE id = ?");
        $stmt->bind_param("i", $id);
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    }

    public static function getChangeRequestById(int $changeId): ?array {
        $conn = self::getConnection();
        $stmt = $conn->prepare(
            "SELECT cr.*, u.name as user_name, u.email as user_email, l.title
             FROM singopkoelsch_change_requests cr
             JOIN singopkoelsch_users u ON cr.user_id = u.user_id
             JOIN singopkoelsch_lyrics l ON cr.lyrics_id = l.id
             WHERE cr.id = ?"
        );
        $stmt->bind_param("i", $changeId);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $data ?: null;
    }

    // ── Events ───────────────────────────────────────────────

    public static function getUserChangeRequestCount(int $userId): int {
        $conn = self::getConnection();
        $stmt = $conn->prepare("SELECT COUNT(*) as c FROM singopkoelsch_change_requests WHERE user_id=?");
        $stmt->bind_param("i", $userId); $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
        return (int)($row['c'] ?? 0);
    }

    // ── User preferences ─────────────────────────────────────

    public static function ensurePreferencesTable(): void {
        $conn = self::getConnection();
        $conn->query(
            "CREATE TABLE IF NOT EXISTS singopkoelsch_user_preferences (
                user_id INT PRIMARY KEY,
                dark_mode TINYINT(1) NOT NULL DEFAULT 0,
                email_notifications TINYINT(1) NOT NULL DEFAULT 1,
                lang VARCHAR(16) NOT NULL DEFAULT 'de',
                email_limit INT NOT NULL DEFAULT 1,
                email_unit VARCHAR(10) NOT NULL DEFAULT 'week'
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        // Add lang column to existing tables (idempotent)
        $r = $conn->query("SHOW COLUMNS FROM singopkoelsch_user_preferences LIKE 'lang'");
        if ($r && $r->num_rows === 0) {
            $conn->query("ALTER TABLE singopkoelsch_user_preferences ADD COLUMN lang VARCHAR(16) NOT NULL DEFAULT 'de'");
        }
        // Add email_limit column if not exists
        $r = $conn->query("SHOW COLUMNS FROM singopkoelsch_user_preferences LIKE 'email_limit'");
        if ($r && $r->num_rows === 0) {
            $conn->query("ALTER TABLE singopkoelsch_user_preferences ADD COLUMN email_limit INT NOT NULL DEFAULT 1");
        }
        // Add email_unit column if not exists
        $r = $conn->query("SHOW COLUMNS FROM singopkoelsch_user_preferences LIKE 'email_unit'");
        if ($r && $r->num_rows === 0) {
            $conn->query("ALTER TABLE singopkoelsch_user_preferences ADD COLUMN email_unit VARCHAR(10) NOT NULL DEFAULT 'week'");
        }
        // Add resolved_at to change_requests to track when a proposal was answered
        $r = $conn->query("SHOW COLUMNS FROM singopkoelsch_change_requests LIKE 'resolved_at'");
        if ($r && $r->num_rows === 0) {
            $conn->query("ALTER TABLE singopkoelsch_change_requests ADD COLUMN resolved_at TIMESTAMP NULL DEFAULT NULL");
        }
        // Multi-artist junction table
        $conn->query(
            "CREATE TABLE IF NOT EXISTS singopkoelsch_song_artists (
                id INT AUTO_INCREMENT PRIMARY KEY,
                lyric_id INT NOT NULL,
                band_id INT NOT NULL,
                role ENUM('performer','text','music') NOT NULL,
                sort_order TINYINT NOT NULL DEFAULT 0,
                UNIQUE KEY uniq_artist (lyric_id, band_id, role),
                INDEX idx_lyric (lyric_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        // Migrate existing FK data (one-time, IGNORE skips duplicates)
        $conn->query("INSERT IGNORE INTO singopkoelsch_song_artists (lyric_id, band_id, role, sort_order) SELECT id, band_id, 'performer', 0 FROM singopkoelsch_lyrics WHERE band_id IS NOT NULL");
        $conn->query("INSERT IGNORE INTO singopkoelsch_song_artists (lyric_id, band_id, role, sort_order) SELECT id, text_autor_id, 'text', 0 FROM singopkoelsch_lyrics WHERE text_autor_id IS NOT NULL");
        $conn->query("INSERT IGNORE INTO singopkoelsch_song_artists (lyric_id, band_id, role, sort_order) SELECT id, musik_autor_id, 'music', 0 FROM singopkoelsch_lyrics WHERE musik_autor_id IS NOT NULL");
    }

    // #71 Points + #72 Badges schema + helpers
    public static function ensurePointsSystem(): void {
        $conn = self::getConnection();
        $r = $conn->query("SHOW COLUMNS FROM singopkoelsch_users LIKE 'points'");
        if ($r && $r->num_rows === 0) {
            $conn->query("ALTER TABLE singopkoelsch_users ADD COLUMN points INT NOT NULL DEFAULT 0");
        }
        $conn->query(
            "CREATE TABLE IF NOT EXISTS singopkoelsch_user_badges (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                badge_key VARCHAR(64) NOT NULL,
                awarded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_badge (user_id, badge_key),
                INDEX idx_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public static function awardPoints(int $userId, int $points): void {
        $conn = self::getConnection();
        $stmt = $conn->prepare("UPDATE singopkoelsch_users SET points = points + ? WHERE user_id = ?");
        $stmt->bind_param("ii", $points, $userId);
        $stmt->execute(); $stmt->close();
        self::checkAndAwardBadges($userId);
    }

    public static function awardBadge(int $userId, string $badgeKey): void {
        $conn = self::getConnection();
        $stmt = $conn->prepare("INSERT IGNORE INTO singopkoelsch_user_badges (user_id, badge_key) VALUES (?, ?)");
        $stmt->bind_param("is", $userId, $badgeKey);
        $stmt->execute(); $stmt->close();
    }

    public static function checkAndAwardBadges(int $userId): void {
        $conn = self::getConnection();
        $stmt = $conn->prepare("SELECT points FROM singopkoelsch_users WHERE user_id = ?");
        $stmt->bind_param("i", $userId); $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
        $pts = (int)($row['points'] ?? 0);

        $stmt2 = $conn->prepare("SELECT COUNT(*) as c FROM singopkoelsch_change_requests WHERE user_id = ?");
        $stmt2->bind_param("i", $userId); $stmt2->execute();
        $cr = (int)$stmt2->get_result()->fetch_assoc()['c']; $stmt2->close();

        $stmt3 = $conn->prepare("SELECT COUNT(*) as c FROM singopkoelsch_change_requests WHERE user_id = ? AND status = 'approved'");
        $stmt3->bind_param("i", $userId); $stmt3->execute();
        $approved = (int)$stmt3->get_result()->fetch_assoc()['c']; $stmt3->close();

        if ($cr >= 1)   self::awardBadge($userId, 'first_proposal');
        if ($approved >= 1)  self::awardBadge($userId, 'first_approved');
        if ($approved >= 10) self::awardBadge($userId, 'contributor_10');
        if ($approved >= 50) self::awardBadge($userId, 'contributor_50');
        if ($pts >= 100) self::awardBadge($userId, 'points_100');
        if ($pts >= 500) self::awardBadge($userId, 'points_500');
    }

    public static function getUserBadges(int $userId): array {
        $conn = self::getConnection();
        $stmt = $conn->prepare("SELECT badge_key, awarded_at FROM singopkoelsch_user_badges WHERE user_id = ? ORDER BY awarded_at ASC");
        $stmt->bind_param("i", $userId); $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public static function getUserPreferences(int $userId): array {
        $conn = self::getConnection();
        $stmt = $conn->prepare(
            "SELECT up.dark_mode, up.email_notifications, up.lang, up.email_limit, up.email_unit, up.email_next_reset, up.email_count, up.last_summary_sent, u.email, u.name
             FROM singopkoelsch_user_preferences up
             INNER JOIN singopkoelsch_users u ON u.user_id = up.user_id
             WHERE up.user_id = ?"
        );
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?? ['dark_mode' => 0, 'email_notifications' => 1, 'lang' => 'de', 'email_limit' => 1, 'email_unit' => 'week', 'email_next_reset' => null, 'email_count' => 0, 'last_summary_sent' => null, 'email' => null, 'name' => null];
    }

    public static function setUserPreference(int $userId, string $key, $value): bool {
        $allowed = [
            'dark_mode' => 'i',
            'email_notifications' => 'i',
            'lang' => 's',
            'email_limit' => 'i',
            'email_unit' => 's'
        ];
        if (!isset($allowed[$key])) return false;
        $conn = self::getConnection();
        if ($allowed[$key] === 'i') $value = (int)$value;
        $stmt = $conn->prepare(
            "INSERT INTO singopkoelsch_user_preferences (user_id, $key) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE $key = VALUES($key)"
        );
        $stmt->bind_param('i' . $allowed[$key], $userId, $value);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public static function saveUserPreference(int $userId, string $key, int $value): bool {
        $allowed = ['dark_mode', 'email_notifications'];
        if (!in_array($key, $allowed)) return false;
        $conn = self::getConnection();
        $stmt = $conn->prepare(
            "INSERT INTO singopkoelsch_user_preferences (user_id, dark_mode, email_notifications)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE $key = VALUES($key)"
        );
        $defaults = self::getUserPreferences($userId);
        $dm = ($key === 'dark_mode') ? $value : (int)$defaults['dark_mode'];
        $en = ($key === 'email_notifications') ? $value : (int)$defaults['email_notifications'];
        $stmt->bind_param("iii", $userId, $dm, $en);
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    }

    public static function saveAllPreferences(int $userId, int $darkMode, int $emailNotif): bool {
        $conn = self::getConnection();
        $stmt = $conn->prepare(
            "INSERT INTO singopkoelsch_user_preferences (user_id, dark_mode, email_notifications)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE dark_mode = VALUES(dark_mode), email_notifications = VALUES(email_notifications)"
        );
        $stmt->bind_param("iii", $userId, $darkMode, $emailNotif);
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    }

    // ── Admin stats ───────────────────────────────────────────

    public static function getStats(): array {
        $conn = self::getConnection();
        $stats = [];

        $r = $conn->query("SELECT COUNT(*) as c FROM singopkoelsch_lyrics");
        $stats['total_lyrics'] = (int)$r->fetch_assoc()['c'];

        $r = $conn->query("SELECT COUNT(*) as c FROM singopkoelsch_users");
        $stats['total_users'] = (int)$r->fetch_assoc()['c'];

        $r = $conn->query("SELECT COUNT(*) as c FROM singopkoelsch_bands");
        $stats['total_bands'] = (int)$r->fetch_assoc()['c'];

        $r = $conn->query("SELECT COUNT(*) as c FROM singopkoelsch_change_requests WHERE status='pending'");
        $stats['pending_changes'] = (int)$r->fetch_assoc()['c'];

        $r = $conn->query("SELECT COUNT(*) as c FROM singopkoelsch_change_requests WHERE status='approved'");
        $stats['approved_changes'] = (int)$r->fetch_assoc()['c'];

        $r = $conn->query("SELECT COUNT(*) as c FROM singopkoelsch_change_requests WHERE status='rejected'");
        $stats['rejected_changes'] = (int)$r->fetch_assoc()['c'];

        $r = $conn->query("SELECT COUNT(*) as c FROM singopkoelsch_cover_proposals WHERE status='pending'");
        $stats['pending_covers'] = (int)($r ? $r->fetch_assoc()['c'] : 0);

        return $stats;
    }

    public static function getTopBands(int $limit = 10): array {
        $conn = self::getConnection();
        $stmt = $conn->prepare(
            "SELECT b.band_name, COUNT(l.id) as song_count
             FROM singopkoelsch_lyrics l
             JOIN singopkoelsch_bands b ON l.band_id = b.band_id
             GROUP BY l.band_id
             ORDER BY song_count DESC
             LIMIT ?"
        );
        $stmt->bind_param("i", $limit);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $data;
    }

    public static function getAllUsers(): array {
        $conn = self::getConnection();
        $result = $conn->query(
            "SELECT user_id, name, email, email_verified, role, profile_picture
             FROM singopkoelsch_users ORDER BY user_id ASC"
        );
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }

    public static function getUserRoleFlags(int $userId): array {
        $conn = self::getConnection();
        $stmt = $conn->prepare(
            "SELECT role FROM singopkoelsch_users WHERE user_id = ?"
        );
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return ['role' => $row['role'] ?? 'user'];
    }

    public static function getRecentChangeRequests(int $limit = 10): array {
        $conn = self::getConnection();
        $stmt = $conn->prepare(
            "SELECT cr.id, cr.lyrics_id, cr.status, cr.created_at, l.title, u.name as user_name
             FROM singopkoelsch_change_requests cr
             JOIN singopkoelsch_lyrics l ON cr.lyrics_id = l.id
             JOIN singopkoelsch_users u ON cr.user_id = u.user_id
             ORDER BY cr.created_at DESC LIMIT ?"
        );
        $stmt->bind_param("i", $limit);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $data;
    }

    public static function getUserAvatarFilename(int $userId): ?string {
        $conn = self::getConnection();
        $stmt = $conn->prepare("SELECT profile_picture FROM singopkoelsch_users WHERE user_id = ?");
        $stmt->bind_param("i", $userId); $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
        return $row['profile_picture'] ?? null;
    }

    public static function updateUserAvatar(int $userId, ?string $filename): bool {
        $conn = self::getConnection();
        $stmt = $conn->prepare("UPDATE singopkoelsch_users SET profile_picture = ? WHERE user_id = ?");
        $stmt->bind_param("si", $filename, $userId);
        $r = $stmt->execute(); $stmt->close(); return $r;
    }

    public static function updateUserProfile(int $userId, string $name): bool {
        $conn = self::getConnection();
        $stmt = $conn->prepare("UPDATE singopkoelsch_users SET name = ? WHERE user_id = ?");
        $stmt->bind_param("si", $name, $userId);
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    }

    public static function updateUserPassword(int $userId, string $newHash): bool {
        $conn = self::getConnection();
        $stmt = $conn->prepare("UPDATE singopkoelsch_users SET password = ? WHERE user_id = ?");
        $stmt->bind_param("si", $newHash, $userId);
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    }

    public static function getUserPasswordHash(int $userId): ?string {
        $conn = self::getConnection();
        $stmt = $conn->prepare("SELECT password FROM singopkoelsch_users WHERE user_id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row['password'] ?? null;
    }

    public static function flagSong(int $lyricsId, int $adminId, string $reason): bool {
        $conn = self::getConnection();
        $stmt = $conn->prepare(
            "UPDATE singopkoelsch_lyrics
             SET flagged = 1, flag_reason = ?, flagged_by = ?, flagged_at = NOW()
             WHERE id = ?"
        );
        $stmt->bind_param("sii", $reason, $adminId, $lyricsId);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public static function unflagSong(int $lyricsId): bool {
        $conn = self::getConnection();
        $stmt = $conn->prepare(
            "UPDATE singopkoelsch_lyrics
             SET flagged = 0, flag_reason = NULL, flagged_by = NULL, flagged_at = NULL
             WHERE id = ?"
        );
        $stmt->bind_param("i", $lyricsId);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public static function getMalformedBands(): array {
        $conn = self::getConnection();
        $sql = "SELECT band_id, band_name FROM singopkoelsch_bands
                WHERE band_name REGEXP '( und | & |, | and | feat\\\\. | feat | mit )'
                ORDER BY band_name";
        $r = $conn->query($sql);
        return $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
    }

    public static function getFullUserById(int $userId): ?array {
        $conn = self::getConnection();
        $stmt = $conn->prepare(
            "SELECT user_id, name, email, email_verified, role
             FROM singopkoelsch_users WHERE user_id = ?"
        );
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $data ?: null;
    }

    /**
     * Sends an email summary of recent activity to administrators
     * @param int $hoursBack How many hours back to look for activity (default 24)
     * @return bool True if email was sent, false otherwise
     */
    public static function sendActivitySummaryEmail(int $hoursBack = 24): bool {
        $conn = self::getConnection();
        $since = (new DateTime())->modify("-{$hoursBack} hours")->format('Y-m-d H:i:s');

        // Get administrator emails
        $adminEmails = self::getAdministratorEmails();
        if (empty($adminEmails)) {
            error_log('No administrator emails found for activity summary');
            return false;
        }

        // Get statistics
        $stats = self::getStats();

        // Get recent activity
        $newSongs = self::getRecentSongs($since);
        $recentChanges = self::getRecentChangeRequests(50); // Get more to filter by time
        $recentUsers = self::getRecentUsers($since);
        $recentComments = self::getRecentComments($since); // If we have a comments system

        // Filter change requests and users by time
        $recentChanges = array_filter($recentChanges, fn($change) =>
            strtotime($change['created_at']) >= strtotime($since)
        );
        $recentUsers = array_filter($recentUsers, fn($user) =>
            strtotime($user['created_at']) >= strtotime($since)
        );

        // Only send if there's activity
        $hasActivity = !empty($newSongs) ||
                      !empty($recentChanges) ||
                      !empty($recentUsers) ||
                      $stats['pending_changes'] > 0 ||
                      $stats['pending_covers'] > 0;

        if (!$hasActivity) {
            return true; // No activity to report, but not an error
        }

        // Prepare email content
        $subject = sprintf(
            "Sing op Kölsch Activity Summary - %s",
            (new DateTime())->format('Y-m-d H:i')
        );

        $body = self::generateActivitySummaryBody(
            $stats, $newSongs, $recentChanges, $recentUsers, $hoursBack
        );

        // Send to each admin
        $success = true;
        foreach ($adminEmails as $adminEmail) {
            // Get admin name from email
            $adminName = self::getAdminNameFromEmail($adminEmail, $conn);

            $result = sendMail(
                $adminEmail,
                $adminName,
                $subject,
                $body,
                ['html' => null] // Send as plain text for simplicity
            );

            if (!$result) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Calculate the interval in seconds for summary emails based on user preferences
     * @param int $emailLimit Max number of emails per time unit
     * @param string $emailUnit Time unit (day, week, month)
     * @return int Interval in seconds
     */
    public static function calculateSummaryInterval(int $emailLimit, string $emailUnit = 'day'): int {
        // Always distribute emails equally across 24 hours
        // interval = 24h / max_per_day, minimum 1 hour
        $interval = max(3600, (int)((24 * 3600) / max(1, $emailLimit)));
        return $interval;
    }

    /**
     * Send an activity summary email to a specific user if it's time based on their preferences
     * @param int $userId The user ID to send the summary to
     * @param int $hoursBack How many hours back to look for activity (default 24)
     * @return bool True if email was sent (or no need to send), false on error
     */
    public static function sendUserActivitySummaryIfTime(int $userId, int $hoursBack = 24): bool {
        $conn = self::getConnection();

        // Get user preferences
        $prefs = self::getUserPreferences($userId);
        if (empty($prefs)) {
            error_log("Could not get preferences for user $userId");
            return false;
        }

        // Check if user wants email notifications at all
        if (!empty($prefs['email_notifications']) && $prefs['email_notifications'] == 0) {
            return true; // User opted out, not an error
        }

        $emailLimit = (int)$prefs['email_limit'];
        $emailUnit = $prefs['email_unit'];
        $lastSummarySent = $prefs['last_summary_sent'] ? new DateTime($prefs['last_summary_sent']) : null;
        $now = new DateTime();

        // Calculate the interval based on user preferences
        $intervalSeconds = self::calculateSummaryInterval($emailLimit, $emailUnit);

        // Determine if it's time to send a summary
        $shouldSend = false;

        if ($lastSummarySent === null) {
            // First time - always send
            $shouldSend = true;
        } else {
            // Check if enough time has passed since last summary
            $timeSinceLast = $now->getTimestamp() - $lastSummarySent->getTimestamp();
            $shouldSend = $timeSinceLast >= $intervalSeconds;
        }

        if (!$shouldSend) {
            return true; // Not time yet, not an error
        }

        // It's time to send - get activity since last summary or since a reasonable time back
        $since = $lastSummarySent !== null
            ? $lastSummarySent->format('Y-m-d H:i:s')
            : (new DateTime())->modify("-{$hoursBack} hours")->format('Y-m-d H:i:s');

        // Get statistics
        $stats = self::getStats();

        // Get recent activity
        $newSongs = self::getRecentSongs($since);
        $recentChanges = self::getRecentChangeRequests(50); // Get more to filter by time
        $recentUsers = self::getRecentUsers($since);
        $recentComments = self::getRecentComments($since); // If we have a comments system

        // Filter change requests and users by time
        $recentChanges = array_filter($recentChanges, fn($change) =>
            strtotime($change['created_at']) >= strtotime($since)
        );
        $recentUsers = array_filter($recentUsers, fn($user) =>
            strtotime($user['created_at']) >= strtotime($since)
        );

        // Only send if there's activity
        $hasActivity = !empty($newSongs) ||
                      !empty($recentChanges) ||
                      !empty($recentUsers) ||
                      $stats['pending_changes'] > 0 ||
                      $stats['pending_covers'] > 0;

        if (!$hasActivity) {
            // Update last_summary_sent time even if no activity, to maintain schedule
            $stmt = $conn->prepare(
                "UPDATE singopkoelsch_user_preferences SET last_summary_sent = ? WHERE user_id = ?"
            );
            $stmt->bind_param("si", $now->format('Y-m-d H:i:s'), $userId);
            $stmt->execute();
            $stmt->close();
            return true; // No activity to report, but not an error
        }

        // Prepare email content
        $subject = sprintf(
            "Sing op Kölsch Activity Summary - %s",
            $now->format('Y-m-d H:i')
        );

        $body = self::generateActivitySummaryBody(
            $stats, $newSongs, $recentChanges, $recentUsers, $hoursBack
        );

        // Get user name for personalization
        $userName = self::getAdminNameFromEmail($prefs['email'] ?? '', $conn);
        if (empty($userName)) {
            // Fallback to getting name from user ID
            $userData = self::getFullUserById($userId);
            $userName = $userData['name'] ?? 'User';
        }

        // Send the email
        $result = sendMail(
            $prefs['email'] ?? '',
            $userName,
            $subject,
            $body,
            ['html' => null] // Send as plain text for simplicity
        );

        if ($result) {
            // Update last_summary_sent time
            $stmt = $conn->prepare(
                "UPDATE singopkoelsch_user_preferences SET last_summary_sent = ? WHERE user_id = ?"
            );
            $stmt->bind_param("si", $now->format('Y-m-d H:i:s'), $userId);
            $stmt->execute();
            $stmt->close();
        }

        return $result;
    }

    /**
     * Get administrator email addresses
     * @return array List of administrator email addresses
     */
    private static function getAdministratorEmails(): array {
        $conn = self::getConnection();
        $stmt = $conn->prepare(
            "SELECT email FROM singopkoelsch_users WHERE role = 'admin' AND email IS NOT NULL AND email != ''"
        );
        $stmt->execute();
        $result = $stmt->get_result();
        $emails = [];
        while ($row = $result->fetch_assoc()) {
            $emails[] = $row['email'];
        }
        $stmt->close();
        return $emails;
    }

    /**
     * Get songs added since a specific time
     */
    public static function getRecentSongs(string $since): array {
        $conn = self::getConnection();
        $stmt = $conn->prepare(
            "SELECT l.title, l.album, b.band_name, l.created_at
             FROM singopkoelsch_lyrics l
             JOIN singopkoelsch_bands b ON l.band_id = b.band_id
             WHERE l.created_at >= ?
             ORDER BY l.created_at DESC"
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param("s", $since);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $data;
    }

    /**
     * Get users registered since a specific time
     */
    private static function getRecentUsers(string $since): array {
        $conn = self::getConnection();
        $stmt = $conn->prepare(
            "SELECT user_id, name, email, role, created_at
             FROM singopkoelsch_users
             WHERE created_at >= ?
             ORDER BY created_at DESC"
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param("s", $since);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $data;
    }

    /**
     * Get comments added since a specific time
     * (Placeholder - implement if comments system exists)
     */
    private static function getRecentComments(string $since): array {
        // This would be implemented if there's a comments/system for feedback
        return [];
    }

    /**
     * Get admin name from email
     */
    private static function getAdminNameFromEmail(string $email, mysqli $conn): string {
        $stmt = $conn->prepare(
            "SELECT name FROM singopkoelsch_users WHERE email = ? AND role = 'admin' LIMIT 1"
        );
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        return $user['name'] ?? substr($email, 0, strpos($email, '@'));
    }

    /**
     * Generate the body of the activity summary email
     */
    private static function generateActivitySummaryBody(
        array $stats,
        array $newSongs,
        array $recentChanges,
        array $recentUsers,
        int $hoursBack
    ): string {
        $lines = [];
        $lines[] = "Sing op Kölsch Activity Summary";
        $lines[] = "================================";
        $lines[] = "";
        $lines[] = sprintf("Generated: %s", (new DateTime())->format('Y-m-d H:i:s'));
        $lines[] = sprintf("Period: Last %d hours", $hoursBack);
        $lines[] = "";

        $lines[] = "=== Overall Statistics ===";
        $lines[] = sprintf("Total Songs: %d", $stats['total_lyrics'] ?? 0);
        $lines[] = sprintf("Total Users: %d", $stats['total_users'] ?? 0);
        $lines[] = sprintf("Total Bands/Artists: %d", $stats['total_bands'] ?? 0);
        $lines[] = "";

        $lines[] = sprintf("=== Activity in Last %d Hours ===", $hoursBack);
        $lines[] = sprintf("New Songs Added: %d", count($newSongs));
        $lines[] = sprintf("New Users Registered: %d", count($recentUsers));
        $lines[] = sprintf("Change Requests Submitted: %d", count($recentChanges));
        $lines[] = "";

        $lines[] = "=== Pending Moderation ===";
        $lines[] = sprintf("Pending Change Requests: %d", $stats['pending_changes'] ?? 0);
        $lines[] = sprintf("Pending Cover Proposals: %d", $stats['pending_covers'] ?? 0);
        $lines[] = "";

        if (!empty($newSongs)) {
            $lines[] = "=== New Songs Added ===";
            foreach ($newSongs as $song) {
                $lines[] = sprintf(
                    "• %s by %s (%s)",
                    $song['title'],
                    $song['band_name'] ?? 'Unknown Artist',
                    $song['album'] ?? 'Unknown Album'
                );
                $lines[] = "";
            }
        }

        if (!empty($recentUsers)) {
            $lines[] = "=== New Users Registered ===";
            foreach ($recentUsers as $user) {
                $lines[] = sprintf(
                    "• %s (%s) - %s",
                    $user['name'],
                    $user['email'],
                    ucfirst($user['role'])
                );
            }
            $lines[] = "";
        }

        if (!empty($recentChanges)) {
            $lines[] = "=== Recent Change Requests ===";
            foreach ($recentChanges as $change) {
                $lines[] = sprintf(
                    "• %s - %s (Status: %s)",
                    $change['title'],
                    $change['user_name'],
                    ucfirst($change['status'])
                );
            }
            $lines[] = "";
        }

        $lines[] = "";
        $lines[] = "---";
        $lines[] = "This is an automated message from Sing op Kölsch.";
        $lines[] = "To modify notification preferences, visit your account settings.";

        return implode("\n", $lines);
    }
}
