<?php
/**
 * MusicBrainz enrichment for text_autor_id / musik_autor_id
 *
 *   php scripts/musicbrainz_enrich.php           # dry-run (default)
 *   php scripts/musicbrainz_enrich.php --apply
 *   php scripts/musicbrainz_enrich.php --limit=20
 *
 * Conservative: only writes fields currently NULL/0.
 *
 * MB rate limit is 1 request/second. Each song needs ~2 requests
 * (recording lookup + work-relationships), so ~530 sec for 266 songs.
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

$UA = 'SingOpKoelsch/1.0 ( ' . SITE_URL . ' )';
$lastCall = 0.0;

function mb_get(string $path, array $params = [], string $ua = ''): ?array {
    global $lastCall;
    // throttle to ≥1.1 sec between calls
    $dt = microtime(true) - $lastCall;
    if ($dt < 1.1) usleep((int)((1.1 - $dt) * 1_000_000));
    $lastCall = microtime(true);

    $params['fmt'] = 'json';
    $url = 'https://musicbrainz.org/ws/2/' . ltrim($path, '/') . '?' . http_build_query($params);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['User-Agent: ' . $ua, 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $out  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 503 || $code === 429) { sleep(5); return mb_get($path, $params, $ua); }
    if ($code !== 200) return null;
    return json_decode($out, true);
}

function norm(string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, ['ä'=>'a','ö'=>'o','ü'=>'u','ß'=>'ss','’'=>"'", '–'=>'-']);
    return trim(preg_replace('/[^a-z0-9 ]+/', ' ', $s));
}

function similar_score(string $a, string $b): float {
    $a = norm($a); $b = norm($b);
    if ($a === '' || $b === '') return 0.0;
    if ($a === $b) return 1.0;
    similar_text($a, $b, $pct);
    return $pct / 100.0;
}

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

/* ── Pick rows ──────────────────────────────────────────────── */
$where = "(l.text_autor_id IS NULL OR l.text_autor_id = 0
        OR l.musik_autor_id IS NULL OR l.musik_autor_id = 0)";
if ($only) $where = "l.id = $only";

$sql = "SELECT l.id, l.title, l.text_autor_id, l.musik_autor_id, l.band_id,
               b.band_name
        FROM singopkoelsch_lyrics l
        LEFT JOIN singopkoelsch_bands b ON b.band_id = l.band_id
        WHERE $where
        ORDER BY l.id";
if ($limit > 0) $sql .= " LIMIT $limit";

$rows = $mysqli->query($sql)->fetch_all(MYSQLI_ASSOC);
$N    = count($rows);

echo "── MusicBrainz enrichment ──\n";
echo "Mode  : " . ($apply ? 'APPLY (writes to DB)' : 'DRY-RUN') . "\n";
echo "Songs : $N\n";
echo "ETA   : ~" . ceil($N * 2.3) . " sec (1.1s/req, ~2 req/song)\n\n";

if (!$N) { echo "Nothing to do.\n"; exit; }

$matched = 0; $skipped = 0; $written = 0; $noWork = 0; $noWriters = 0;
$samples = [];

foreach ($rows as $i => $r) {
    // Step 1: search recordings
    $q = 'recording:"' . str_replace('"','',$r['title']) . '"';
    if ($r['band_name']) $q .= ' AND artist:"' . str_replace('"','',$r['band_name']) . '"';

    $res = mb_get('recording', ['query' => $q, 'limit' => 3], $UA);
    $items = $res['recordings'] ?? [];
    if (!$items) { $skipped++; if ($verb) echo "  no recording: #{$r['id']} {$r['title']}\n"; continue; }

    // Best by title sim
    $best = null; $bestScore = 0.0;
    foreach ($items as $rec) {
        $titleScore = similar_score($r['title'], $rec['title'] ?? '');
        if ($titleScore > $bestScore) { $bestScore = $titleScore; $best = $rec; }
    }
    if (!$best || $bestScore < 0.7) { $skipped++; if ($verb) echo "  low title-conf ($bestScore): #{$r['id']}\n"; continue; }

    $matched++;

    // Step 2: find related Work
    $workId = null;
    foreach ($best['relations'] ?? [] as $rel) {
        if (($rel['type'] ?? '') === 'performance' && isset($rel['work']['id'])) {
            $workId = $rel['work']['id']; break;
        }
    }
    // If first-search response didn't include relations, fetch recording with inc=work-rels
    if (!$workId) {
        $rec2 = mb_get('recording/' . $best['id'], ['inc' => 'work-rels'], $UA);
        foreach ($rec2['relations'] ?? [] as $rel) {
            if (($rel['type'] ?? '') === 'performance' && isset($rel['work']['id'])) {
                $workId = $rel['work']['id']; break;
            }
        }
    }
    if (!$workId) { $noWork++; if ($verb) echo "  no work: #{$r['id']}\n"; continue; }

    // Step 3: fetch work relations for writers
    $work = mb_get('work/' . $workId, ['inc' => 'artist-rels'], $UA);
    $lyricist = null; $composer = null;
    foreach ($work['relations'] ?? [] as $rel) {
        $type = $rel['type'] ?? '';
        $name = $rel['artist']['name'] ?? null;
        if (!$name) continue;
        if (in_array($type, ['lyricist','writer'])     && !$lyricist) $lyricist = $name;
        if (in_array($type, ['composer','writer'])     && !$composer) $composer = $name;
    }
    if (!$lyricist && !$composer) { $noWriters++; continue; }

    // Plan
    $plan = [];
    if (empty($r['text_autor_id'])  && $lyricist) $plan['text_autor']  = $lyricist;
    if (empty($r['musik_autor_id']) && $composer) $plan['musik_autor'] = $composer;
    if (!$plan) { $skipped++; continue; }

    if (count($samples) < 12) {
        $samples[] = sprintf("#%-5d %-30s  T:%s  M:%s",
            $r['id'],
            mb_substr($r['title'], 0, 30),
            $plan['text_autor']  ?? '—',
            $plan['musik_autor'] ?? '—'
        );
    }

    if (!$apply) continue;

    $sets = []; $types = ''; $vals = [];
    if (isset($plan['text_autor'])) {
        $bid = ensure_band($mysqli, $plan['text_autor']);
        if ($bid > 0) { $sets[] = 'text_autor_id = ?';  $types .= 'i'; $vals[] = $bid; }
    }
    if (isset($plan['musik_autor'])) {
        $bid = ensure_band($mysqli, $plan['musik_autor']);
        if ($bid > 0) { $sets[] = 'musik_autor_id = ?'; $types .= 'i'; $vals[] = $bid; }
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

    if (($i+1) % 20 === 0) fwrite(STDERR, "  …processed " . ($i+1) . "/$N\n");
}

echo "\n── Sample matches ──\n";
foreach ($samples as $line) echo $line, "\n";

echo "\n── Stats ──\n";
echo "Total scanned : $N\n";
echo "Matched (rec) : $matched\n";
echo "No recording  : $skipped (skipped)\n";
echo "No work       : $noWork\n";
echo "No writers    : $noWriters\n";
echo "Written       : $written" . ($apply ? '' : ' (dry-run)') . "\n";
echo "\n";
echo $apply
    ? "Done.\n"
    : "Re-run with --apply to write these changes.\n";
