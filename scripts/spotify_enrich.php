<?php
/**
 * Spotify enrichment for singopkoelsch_lyrics
 *
 *   php scripts/spotify_enrich.php           # dry-run (default)
 *   php scripts/spotify_enrich.php --apply   # write to DB
 *   php scripts/spotify_enrich.php --limit=20
 *   php scripts/spotify_enrich.php --id=123  # single song
 *
 * Conservative: only writes fields that are currently NULL/empty.
 * Existing values are never overwritten.
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }

require_once __DIR__ . '/../config.php';

$opts  = getopt('', ['apply', 'limit::', 'id::', 'verbose']);
$apply = isset($opts['apply']);
$limit = isset($opts['limit']) ? (int)$opts['limit'] : 0;
$only  = isset($opts['id']) ? (int)$opts['id'] : 0;
$verb  = isset($opts['verbose']);

$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($mysqli->connect_errno) { fwrite(STDERR, "DB connect: {$mysqli->connect_error}\n"); exit(1); }
$mysqli->set_charset('utf8mb4');

/* ── Spotify OAuth (client-credentials) ─────────────────────── */
function spotify_token(): string {
    $ch = curl_init('https://accounts.spotify.com/api/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
        CURLOPT_HTTPHEADER     => [
            'Authorization: Basic ' . base64_encode(SPOTIFY_CLIENT_ID . ':' . SPOTIFY_CLIENT_SECRET),
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $out = curl_exec($ch);
    if ($out === false) { fwrite(STDERR, 'Spotify auth: ' . curl_error($ch) . "\n"); exit(1); }
    curl_close($ch);
    $j = json_decode($out, true);
    if (empty($j['access_token'])) { fwrite(STDERR, "Spotify auth failed: $out\n"); exit(1); }
    return $j['access_token'];
}

function spotify_search(string $token, string $title, ?string $artist): ?array {
    // Strip parentheticals like "(Kölsch Rundfunkleed)" to widen match
    $cleanTitle = trim(preg_replace('/\s*\([^)]*\)/u', '', $title));
    if ($cleanTitle === '') $cleanTitle = $title;

    $q = 'track:"' . str_replace('"', '', $cleanTitle) . '"';
    if ($artist) $q .= ' artist:"' . str_replace('"', '', $artist) . '"';

    $url = 'https://api.spotify.com/v1/search?type=track&limit=3&market=DE&q=' . rawurlencode($q);
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
    ]);
    $out  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 429) { sleep(3); return spotify_search($token, $title, $artist); }
    if ($code !== 200) return null;
    $j = json_decode($out, true);
    return $j['tracks']['items'] ?? null;
}

function norm(string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    $map = ['ä'=>'a','ö'=>'o','ü'=>'u','ß'=>'ss','é'=>'e','è'=>'e','à'=>'a','ç'=>'c',
            '’'=>"'", '‘'=>"'", '“'=>'"', '”'=>'"', '–'=>'-', '—'=>'-'];
    $s = strtr($s, $map);
    return trim(preg_replace('/[^a-z0-9 ]+/', ' ', $s));
}

function similar_score(string $a, string $b): float {
    $a = norm($a); $b = norm($b);
    if ($a === '' || $b === '') return 0.0;
    if ($a === $b) return 1.0;
    similar_text($a, $b, $pct);
    return $pct / 100.0;
}

/* ── Pick rows to enrich ────────────────────────────────────── */
$where = "(l.spotify_link IS NULL OR l.spotify_link = ''
        OR l.cover_url    IS NULL OR l.cover_url    = ''
        OR l.album        IS NULL OR l.album        = ''
        OR l.release_year IS NULL OR l.release_year = 0
        OR l.band_id      IS NULL OR l.band_id      = 0)";
if ($only) $where = "l.id = $only";

$sql = "SELECT l.id, l.title, l.spotify_link, l.cover_url, l.album, l.release_year, l.band_id,
               b.band_name
        FROM singopkoelsch_lyrics l
        LEFT JOIN singopkoelsch_bands b ON b.band_id = l.band_id
        WHERE $where
        ORDER BY l.id";
if ($limit > 0) $sql .= " LIMIT $limit";

$rows = $mysqli->query($sql)->fetch_all(MYSQLI_ASSOC);
$N    = count($rows);

echo "── Spotify enrichment ──\n";
echo "Mode  : " . ($apply ? 'APPLY (writes to DB)' : 'DRY-RUN') . "\n";
echo "Songs : $N\n\n";

if (!$N) { echo "Nothing to do.\n"; exit; }

$token = spotify_token();

/* ── Band-name resolver (insert-or-find) ────────────────────── */
function ensure_band(mysqli $db, string $name): int {
    $name = trim($name);
    if ($name === '') return 0;
    $stmt = $db->prepare("SELECT band_id FROM singopkoelsch_bands WHERE band_name = ? LIMIT 1");
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) return (int)$row['band_id'];
    $stmt = $db->prepare("INSERT INTO singopkoelsch_bands (band_name) VALUES (?)");
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $id = $stmt->insert_id;
    $stmt->close();
    return $id;
}

/* ── Main loop ──────────────────────────────────────────────── */
$matched = 0; $skipped = 0; $written = 0; $lowConf = 0;
$samples = [];

foreach ($rows as $i => $r) {
    $candidates = spotify_search($token, $r['title'], $r['band_name'] ?: null);
    if (!$candidates) {
        if ($verb) echo "  no result: #{$r['id']} {$r['title']}\n";
        $skipped++; continue;
    }

    // Pick best by combined title + artist similarity
    $best = null; $bestScore = 0.0;
    foreach ($candidates as $t) {
        $titleScore  = similar_score($r['title'], $t['name']);
        $artistScore = 0.5; // neutral if we don't know the band yet
        if ($r['band_name']) {
            $artistNames = array_map(fn($a) => $a['name'], $t['artists']);
            $artistScore = 0.0;
            foreach ($artistNames as $an) {
                $artistScore = max($artistScore, similar_score($r['band_name'], $an));
            }
        }
        $combined = 0.6 * $titleScore + 0.4 * $artistScore;
        if ($combined > $bestScore) { $bestScore = $combined; $best = $t; }
    }

    if (!$best || $bestScore < 0.55) {
        if ($verb) echo "  low conf ($bestScore): #{$r['id']} {$r['title']}\n";
        $lowConf++; continue;
    }
    $matched++;

    // Plan the fill — only fields currently empty
    $plan = [];
    if (empty($r['spotify_link']))                $plan['spotify_link'] = $best['external_urls']['spotify'] ?? null;
    if (empty($r['album']))                       $plan['album']        = $best['album']['name'] ?? null;
    if (empty($r['release_year']))                $plan['release_year'] = isset($best['album']['release_date'])
                                                                          ? (int)substr($best['album']['release_date'], 0, 4)
                                                                          : null;
    if (empty($r['cover_url'])) {
        $imgs = $best['album']['images'] ?? [];
        if ($imgs) $plan['cover_url'] = $imgs[1]['url'] ?? $imgs[0]['url'];
    }
    if (empty($r['band_id']) && !empty($best['artists'][0]['name'])) {
        $plan['_artist_name'] = $best['artists'][0]['name'];
    }
    $plan = array_filter($plan, fn($v) => !empty($v));

    if (!$plan) { $skipped++; continue; }

    if (count($samples) < 10) {
        $samples[] = sprintf("#%-5d  %-35s → %-35s  (conf %.2f) %s",
            $r['id'],
            mb_substr($r['title'], 0, 35),
            mb_substr($best['name'], 0, 35),
            $bestScore,
            json_encode(array_keys($plan), JSON_UNESCAPED_UNICODE)
        );
    }

    if (!$apply) continue;

    // Apply
    $sets = []; $types = ''; $vals = [];
    if (isset($plan['spotify_link'])) { $sets[] = 'spotify_link = ?'; $types .= 's'; $vals[] = $plan['spotify_link']; }
    if (isset($plan['cover_url']))    { $sets[] = 'cover_url = ?';    $types .= 's'; $vals[] = $plan['cover_url']; }
    if (isset($plan['album']))        { $sets[] = 'album = ?';        $types .= 's'; $vals[] = $plan['album']; }
    if (isset($plan['release_year'])) { $sets[] = 'release_year = ?'; $types .= 'i'; $vals[] = $plan['release_year']; }
    if (isset($plan['_artist_name'])) {
        $bid = ensure_band($mysqli, $plan['_artist_name']);
        if ($bid > 0) { $sets[] = 'band_id = ?'; $types .= 'i'; $vals[] = $bid; }
    }
    if ($sets) {
        $sql = "UPDATE singopkoelsch_lyrics SET " . implode(', ', $sets) . " WHERE id = ?";
        $types .= 'i'; $vals[] = (int)$r['id'];
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param($types, ...$vals);
        $stmt->execute();
        $stmt->close();
        $written++;
    }

    // tiny breather to be nice (Spotify allows ~180/min)
    usleep(120000);

    if (($i+1) % 25 === 0) fwrite(STDERR, "  …processed " . ($i+1) . "/$N\n");
}

echo "\n── Sample matches ──\n";
foreach ($samples as $line) echo $line, "\n";

echo "\n── Stats ──\n";
echo "Total scanned : $N\n";
echo "Matched       : $matched\n";
echo "Low-confidence: $lowConf (skipped)\n";
echo "No result     : $skipped\n";
echo "Written       : $written" . ($apply ? '' : ' (dry-run)') . "\n";
echo "\n";
echo $apply
    ? "Done. Verify on the site.\n"
    : "Re-run with --apply to write these changes.\n";
