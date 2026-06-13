<?php
/**
 * Backfill cover_url from Spotify for every song with a spotify_link.
 *
 *   php scripts/spotify_covers.php             # dry-run (default)
 *   php scripts/spotify_covers.php --apply     # write to DB
 *   php scripts/spotify_covers.php --limit=20
 *   php scripts/spotify_covers.php --apply --force   # also re-fetch songs that already have cover_url
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }

require_once __DIR__ . '/../config.php';

$opts  = getopt('', ['apply', 'limit::', 'force']);
$apply = isset($opts['apply']);
$limit = isset($opts['limit']) ? (int)$opts['limit'] : 0;
$force = isset($opts['force']);

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($db->connect_errno) { fwrite(STDERR, "DB: {$db->connect_error}\n"); exit(1); }
$db->set_charset('utf8mb4');

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
    curl_close($ch);
    return json_decode($out, true)['access_token'] ?? '';
}

function tracks_batch(string $token, array $ids): array {
    if (!$ids) return [];
    $url = 'https://api.spotify.com/v1/tracks?ids=' . urlencode(implode(',', $ids));
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $out  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 429) { sleep(3); return tracks_batch($token, $ids); }
    if ($code !== 200) return [];
    $j = json_decode($out, true);
    return $j['tracks'] ?? [];
}

function track_id_from_url(string $url): ?string {
    if (preg_match('~spotify\.com/track/([A-Za-z0-9]+)~', $url, $m)) return $m[1];
    return null;
}

/* ── Select target rows ────────────────────────────────────── */
$where = "spotify_link IS NOT NULL AND spotify_link != ''";
if (!$force) $where .= " AND (cover_url IS NULL OR cover_url = '' OR track_number IS NULL)";

$sql = "SELECT id, title, spotify_link, cover_url, track_number FROM singopkoelsch_lyrics WHERE $where ORDER BY id";
if ($limit > 0) $sql .= " LIMIT $limit";

$rows = $db->query($sql)->fetch_all(MYSQLI_ASSOC);
$N    = count($rows);

echo "── Spotify covers ──\n";
echo "Mode  : " . ($apply ? 'APPLY (writes to DB)' : 'DRY-RUN') . "\n";
echo "Force : " . ($force ? 'YES (overwrite existing)' : 'no') . "\n";
echo "Songs : $N\n\n";

if (!$N) { echo "Nothing to do.\n"; exit; }

$token = spotify_token();
if (!$token) { fwrite(STDERR, "Spotify auth failed\n"); exit(1); }

/* ── Map: db_id → spotify track_id ─────────────────────────── */
$idMap    = [];   // spotifyTrackId => dbId
$haveMap  = [];   // dbId => ['cover' => bool, 'track' => bool]
$samples  = [];
$written  = 0;
$missing  = 0;

foreach ($rows as $r) {
    $tid = track_id_from_url($r['spotify_link']);
    if (!$tid) continue;
    $idMap[$tid] = (int)$r['id'];
    $haveMap[(int)$r['id']] = [
        'cover' => !empty($r['cover_url']),
        'track' => $r['track_number'] !== null,
    ];
}

/* ── Batch fetch (50 IDs per call) ─────────────────────────── */
$ids = array_keys($idMap);
$chunks = array_chunk($ids, 50);

foreach ($chunks as $i => $chunk) {
    $tracks = tracks_batch($token, $chunk);
    foreach ($tracks as $t) {
        if (!$t) { $missing++; continue; }
        $dbId = $idMap[$t['id']] ?? null;
        if (!$dbId) { $missing++; continue; }

        // What's missing on this row?
        $needCover = $force || empty($haveMap[$dbId]['cover']);
        $needTrack = $force || empty($haveMap[$dbId]['track']);

        $sets = []; $types = ''; $vals = [];
        $coverUrl = null;
        if ($needCover) {
            $images = $t['album']['images'] ?? [];
            if ($images) {
                $coverUrl = $images[1]['url'] ?? $images[0]['url'];
                $sets[] = 'cover_url = ?'; $types .= 's'; $vals[] = $coverUrl;
            }
        }
        if ($needTrack && isset($t['track_number']) && (int)$t['track_number'] > 0) {
            $tn = (int)$t['track_number'];
            $sets[] = 'track_number = ?'; $types .= 'i'; $vals[] = $tn;
        }

        if (!$sets) { $missing++; continue; }

        if (count($samples) < 8) {
            $samples[] = sprintf("#%-5d  fields=%s  cover=%s",
                $dbId,
                json_encode(array_map(fn($s)=>explode(' ',$s)[0], $sets)),
                $coverUrl ? substr($coverUrl, -24) : '–'
            );
        }
        if ($apply) {
            $sql = "UPDATE singopkoelsch_lyrics SET " . implode(', ', $sets) . " WHERE id = ?";
            $types .= 'i'; $vals[] = $dbId;
            $u = $db->prepare($sql);
            $u->bind_param($types, ...$vals);
            $u->execute();
            $u->close();
        }
        $written++;
    }
    if ($i + 1 < count($chunks)) usleep(150_000);
}

echo "── Sample ──\n";
foreach ($samples as $line) echo $line, "\n";

echo "\n── Stats ──\n";
echo "Scanned : $N\n";
echo "Missing : $missing\n";
echo "Written : $written" . ($apply ? '' : ' (dry-run)') . "\n";

echo $apply ? "\nDone.\n" : "\nRe-run with --apply.\n";
