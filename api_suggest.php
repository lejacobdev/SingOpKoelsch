<?php
// Search-suggestion endpoint with typo tolerance.
// GET ?q=<query> → JSON {results: [{id,title,band,cover_url,kind}], q}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/config.php';

$q = trim($_GET['q'] ?? '');
if (mb_strlen($q) < 1) { echo json_encode(['results' => [], 'q' => '']); exit; }

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($db->connect_errno) { http_response_code(500); echo '{"error":"db"}'; exit; }
$db->set_charset('utf8mb4');

$LIMIT = 8;
$like  = '%' . $q . '%';
$prefix = $q . '%';

// Phase 1 — LIKE search, ranked
$sql = "SELECT l.id, l.title, l.cover_url, l.album, b.band_name,
               CASE
                 WHEN LOWER(l.title)     = LOWER(?) THEN 1
                 WHEN LOWER(b.band_name) = LOWER(?) THEN 2
                 WHEN l.title LIKE ?                THEN 3
                 WHEN b.band_name LIKE ?            THEN 4
                 WHEN l.album LIKE ?                THEN 5
                 WHEN l.title LIKE ?                THEN 6
                 WHEN b.band_name LIKE ?            THEN 7
                 ELSE 99
               END AS rank
        FROM singopkoelsch_lyrics l
        LEFT JOIN singopkoelsch_bands b ON b.band_id = l.band_id
        WHERE l.title LIKE ? OR b.band_name LIKE ? OR l.album LIKE ?
        ORDER BY rank, l.title
        LIMIT $LIMIT";

$stmt = $db->prepare($sql);
$stmt->bind_param('ssssssssss',
    $q, $q,
    $prefix, $prefix, $prefix,
    $like, $like,
    $like, $like, $like
);
$stmt->execute();
$res = $stmt->get_result();
$hits = [];
while ($r = $res->fetch_assoc()) {
    $hits[] = [
        'id'        => (int)$r['id'],
        'title'     => $r['title'],
        'band'      => $r['band_name'] ?? '',
        'album'     => $r['album'] ?? '',
        'cover_url' => $r['cover_url'] ?? '',
        'kind'      => 'match',
    ];
}
$stmt->close();

// Normalize text for fuzzy matching: lowercase + drop diacritics + collapse whitespace
function suggest_norm(string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, [
        'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss',
        'é' => 'e', 'è' => 'e', 'ê' => 'e',
        'á' => 'a', 'à' => 'a', 'â' => 'a',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u',
        'í' => 'i', 'ì' => 'i', 'î' => 'i',
        '’' => "'", '‘' => "'", '–' => '-', '—' => '-',
    ]);
    $s = preg_replace('/\s+/', ' ', trim($s));
    return $s;
}

// Phase 2 — fuzzy fallback if we have few results and the query is long enough
if (count($hits) < $LIMIT && mb_strlen($q) >= 3) {
    $haveIds = array_column($hits, 'id');
    $excl = $haveIds ? 'WHERE l.id NOT IN (' . implode(',', $haveIds) . ')' : '';

    // Pull a candidate pool — title, band, album, authors. Capped at 600 rows.
    $rows = $db->query("SELECT l.id, l.title, l.cover_url, l.album,
                               b.band_name,
                               ta.band_name AS text_autor_name,
                               ma.band_name AS musik_autor_name
                        FROM singopkoelsch_lyrics l
                        LEFT JOIN singopkoelsch_bands b  ON b.band_id  = l.band_id
                        LEFT JOIN singopkoelsch_bands ta ON ta.band_id = l.text_autor_id
                        LEFT JOIN singopkoelsch_bands ma ON ma.band_id = l.musik_autor_id
                        $excl
                        ORDER BY l.id
                        LIMIT 600")->fetch_all(MYSQLI_ASSOC);

    $qNorm = suggest_norm($q);
    $qLen  = strlen($qNorm); // ASCII after normalization, so byte len == char len
    $maxDist = $qLen <= 5 ? 1 : ($qLen <= 9 ? 2 : 3);

    // Per-token query for multi-word inputs ("blak foss" → ["blak","foss"])
    $qTokens = array_values(array_filter(explode(' ', $qNorm), fn($t) => $t !== ''));

    $scored = [];
    foreach ($rows as $r) {
        foreach ([
            'title' => $r['title'],
            'band'  => $r['band_name'] ?? '',
            'album' => $r['album'] ?? '',
            'text'  => $r['text_autor_name'] ?? '',
            'music' => $r['musik_autor_name'] ?? '',
        ] as $field => $val) {
            if ($val === '' || $val === null) continue;
            $v       = suggest_norm($val);
            $vTokens = explode(' ', $v);

            $best = PHP_INT_MAX;
            // Whole-string distance
            $whole = levenshtein(substr($qNorm, 0, 32), substr($v, 0, 32));
            if ($whole < $best) $best = $whole;
            // Token-against-token distance (best query-token to value-token match)
            foreach ($qTokens as $qt) {
                $tokBest = PHP_INT_MAX;
                foreach ($vTokens as $vt) {
                    if ($vt === '') continue;
                    $d = levenshtein(substr($qt, 0, 24), substr($vt, 0, 24));
                    if ($d < $tokBest) $tokBest = $d;
                }
                if ($tokBest < $best) $best = $tokBest;
            }

            if ($best <= $maxDist) {
                $key = (int)$r['id'];
                if (!isset($scored[$key]) || $scored[$key]['dist'] > $best) {
                    $scored[$key] = [
                        'id'        => (int)$r['id'],
                        'title'     => $r['title'],
                        'band'      => $r['band_name'] ?? '',
                        'album'     => $r['album'] ?? '',
                        'cover_url' => $r['cover_url'] ?? '',
                        'kind'      => 'fuzzy',
                        'dist'      => $best,
                    ];
                }
            }
        }
    }
    usort($scored, fn($a, $b) => $a['dist'] <=> $b['dist'] ?: strcmp($a['title'], $b['title']));
    foreach (array_slice($scored, 0, $LIMIT - count($hits)) as $row) {
        unset($row['dist']);
        $hits[] = $row;
    }
}

echo json_encode(['results' => $hits, 'q' => $q], JSON_UNESCAPED_UNICODE);
