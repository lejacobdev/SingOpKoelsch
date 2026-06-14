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
$like   = '%' . $q . '%';
$prefix = $q . '%';

// Normalize for fuzzy: lowercase → umlaut → German digraph → collapse spaces.
// Maps both "ö" and "oe" to "o" so typo-variants compare equal.
function suggest_norm(string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, [
        'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss',
        'é' => 'e', 'è' => 'e', 'ê' => 'e',
        'á' => 'a', 'à' => 'a', 'â' => 'a',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u',
        'í' => 'i', 'ì' => 'i', 'î' => 'i',
    ]);
    $s = str_replace(["\xe2\x80\x98", "\xe2\x80\x99", "\xe2\x80\x93", "\xe2\x80\x94"], ["'", "'", '-', '-'], $s);
    // Collapse German digraphs so "Foeess"/"Foss"/"Fööss" all → "foss"
    $s = str_replace(['oe', 'ae', 'ue'], ['o', 'a', 'u'], $s);
    return preg_replace('/\s+/', ' ', trim($s));
}

$qNorm   = suggest_norm($q);
$nPrefix = $qNorm . '%';
$qTokens = array_values(array_filter(explode(' ', $qNorm), fn($t) => strlen($t) > 0));

// Phase 1 — exact/prefix/LIKE on raw query, ranked by match quality
$sql = "SELECT l.id, l.title, l.cover_url, l.album, b.band_name,
               CASE
                 WHEN LOWER(l.title)     = LOWER(?) THEN 1
                 WHEN LOWER(b.band_name) = LOWER(?) THEN 2
                 WHEN l.title LIKE ?                THEN 3
                 WHEN b.band_name LIKE ?            THEN 4
                 WHEN l.album LIKE ?                THEN 5
                 WHEN l.title LIKE ?                THEN 6
                 WHEN b.band_name LIKE ?            THEN 7
                 ELSE 8
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
$res  = $stmt->get_result();
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

// Phase 2 — fuzzy fallback against all songs not already returned
if (count($hits) < $LIMIT && mb_strlen($q) >= 2) {
    $haveIds = array_column($hits, 'id');
    $excl    = $haveIds
        ? 'AND l.id NOT IN (' . implode(',', array_map('intval', $haveIds)) . ')'
        : '';

    $rows = $db->query("
        SELECT l.id, l.title, l.cover_url, l.album,
               b.band_name,
               ta.band_name AS text_autor_name,
               ma.band_name AS musik_autor_name
        FROM singopkoelsch_lyrics l
        LEFT JOIN singopkoelsch_bands b  ON b.band_id  = l.band_id
        LEFT JOIN singopkoelsch_bands ta ON ta.band_id = l.text_autor_id
        LEFT JOIN singopkoelsch_bands ma ON ma.band_id = l.musik_autor_id
        WHERE 1=1 $excl
        ORDER BY l.title
    ");

    if (!$rows) {
        echo json_encode(['results' => $hits, 'q' => $q], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $rows = $rows->fetch_all(MYSQLI_ASSOC);

    $qLen    = strlen($qNorm);
    $maxDist = $qLen <= 3 ? 1 : ($qLen <= 6 ? 2 : ($qLen <= 10 ? 3 : 4));
    $isMulti = count($qTokens) > 1;

    // Field priority: lower index = better
    $fieldDefs = [
        ['title',           0],
        ['band_name',       1],
        ['album',           2],
        ['text_autor_name', 3],
        ['musik_autor_name',3],
    ];

    $scored = [];
    foreach ($rows as $r) {
        $bestScore  = PHP_INT_MAX;
        $bestWeight = PHP_INT_MAX;

        foreach ($fieldDefs as [$field, $weight]) {
            $val = $r[$field] ?? '';
            if ($val === '') continue;

            $v       = suggest_norm($val);
            $vTokens = array_values(array_filter(explode(' ', $v), fn($t) => strlen($t) > 0));

            // Substring: normalized query appears anywhere in normalized value
            if (strpos($v, $qNorm) !== false) {
                $score = 0;
            } elseif ($isMulti) {
                // Multi-word query: score by how well each query token matches value tokens
                $tokenDists = [];
                foreach ($qTokens as $qt) {
                    $best = PHP_INT_MAX;
                    foreach ($vTokens as $vt) {
                        if (strpos($vt, $qt) !== false) { $best = 0; break; }
                        $d = levenshtein(substr($qt, 0, 24), substr($vt, 0, 24));
                        if ($d < $best) $best = $d;
                    }
                    $tokenDists[] = $best;
                }
                $score = max($tokenDists);
                // If any single token is far off, reject
                foreach ($tokenDists as $td) {
                    if ($td > $maxDist + 1) { $score = PHP_INT_MAX; break; }
                }
            } else {
                // Single-word query: try whole string vs whole field, and vs each value token
                $score = levenshtein(substr($qNorm, 0, 32), substr($v, 0, 32));
                foreach ($vTokens as $vt) {
                    if (strpos($vt, $qNorm) !== false) { $score = 0; break; }
                    $d = levenshtein(substr($qNorm, 0, 24), substr($vt, 0, 24));
                    if ($d < $score) $score = $d;
                }
            }

            if ($score < $bestScore || ($score === $bestScore && $weight < $bestWeight)) {
                $bestScore  = $score;
                $bestWeight = $weight;
            }
        }

        if ($bestScore <= $maxDist) {
            $id = (int)$r['id'];
            // Combined sort key: dist * 10 + field priority
            $sortKey = $bestScore * 10 + $bestWeight;
            if (!isset($scored[$id]) || $scored[$id]['_sort'] > $sortKey) {
                $scored[$id] = [
                    'id'        => $id,
                    'title'     => $r['title'],
                    'band'      => $r['band_name'] ?? '',
                    'album'     => $r['album'] ?? '',
                    'cover_url' => $r['cover_url'] ?? '',
                    'kind'      => 'fuzzy',
                    '_sort'     => $sortKey,
                ];
            }
        }
    }

    usort($scored, fn($a, $b) => $a['_sort'] <=> $b['_sort'] ?: strcmp($a['title'], $b['title']));
    foreach (array_slice($scored, 0, $LIMIT - count($hits)) as $row) {
        unset($row['_sort']);
        $hits[] = $row;
    }
}

echo json_encode(['results' => $hits, 'q' => $q], JSON_UNESCAPED_UNICODE);
