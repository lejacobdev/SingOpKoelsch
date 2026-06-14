<?php
require_once "protect.php";
require_once "functions.php";

Database::getConnection();
Database::ensurePreferencesTable();

// ── Input ──────────────────────────────────────────────────
$query    = trim($_GET["lyrics"] ?? "");
$bandId   = (int)($_GET["band"]  ?? 0);
$sort     = $_GET["sort"] ?? 'title';
$decade   = (int)($_GET["decade"] ?? 0);  // #51 Jahrzehnt-Filter
$hasFlags = [
    'has_lyrics'  => !empty($_GET["lyrics_only"]),
    'has_spotify' => !empty($_GET["spotify_only"]),
    'has_video'   => !empty($_GET["video_only"]),
    'incomplete'  => !empty($_GET["incomplete_only"]),
    'flagged'     => !empty($_GET["flagged_only"]),
];

$filters = array_merge([
    'band_id'  => $bandId ?: null,
    'decade'   => $decade ?: null,
], $hasFlags);

$_isAdmin = function_exists('isAdmin') && isAdmin();

// ── Fetch (#61 Lazy Loading) ───────────────────────────────
$_limit  = 100;
$_offset = max(0, (int)($_GET['offset'] ?? 0));
$lyrics  = Database::queryFiltered($query, $filters, $sort, $_limit, $_offset);
$_totalCount = Database::queryFilteredCount($query, $filters);
$bandMap = Database::getBandMap();
$songEvents = []; // legacy placeholder — events feature removed

// All bands (for filter dropdown)
$allBands = Database::getBandList();

// Active filter count
$activeFilterCount = 0;
if ($bandId) $activeFilterCount++;
if ($decade) $activeFilterCount++;
foreach ($hasFlags as $f) if ($f) $activeFilterCount++;
if ($sort !== 'title') $activeFilterCount++;
$isSearch = $query !== "";
$isFiltered = $bandId || $hasFlags['has_lyrics'] || $hasFlags['has_spotify'] || $hasFlags['has_video'] || $hasFlags['incomplete'] || $hasFlags['flagged'];

// ── Render a single song card — minimal, just title + band ──
// When $showAuthors is true (e.g. filtering by a specific artist), the band line
// is replaced by Text/Musik author credits since the band would be repetitive.
function _renderSongCard(array $lyric, array $bandMap, array $events = [], bool $showAuthors = false): string {
    if ($showAuthors) {
        $textAutor  = $lyric['text_autor_name']  ?? ($bandMap[$lyric['text_autor_id']  ?? 0] ?? null);
        $musikAutor = $lyric['musik_autor_name'] ?? ($bandMap[$lyric['musik_autor_id'] ?? 0] ?? null);
        $parts = [];
        if ($textAutor && $musikAutor && $textAutor === $musikAutor) {
            $parts[] = 'Text &amp; Musik: ' . htmlspecialchars($textAutor);
        } else {
            if ($textAutor)  $parts[] = 'Text: '   . htmlspecialchars($textAutor);
            if ($musikAutor) $parts[] = 'Musik: ' . htmlspecialchars($musikAutor);
        }
        $sub = $parts
            ? implode(' · ', $parts)
            : htmlspecialchars($bandMap[$lyric["band_id"]] ?? '–');
    } else {
        $sub = htmlspecialchars($bandMap[$lyric["band_id"]] ?? '–');
    }

    $cover = !empty($lyric['cover_url'])
        ? '<img class="song-card-cover" src="' . htmlspecialchars($lyric['cover_url']) . '" alt="" loading="lazy">'
        : '<span class="song-card-cover song-card-cover-empty" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg></span>';

    $flagBadge = !empty($lyric['flagged'])
        ? '<span class="song-card-flag" title="' . htmlspecialchars($lyric['flag_reason'] ?? t('detail.flagged_strong')) . '">⚠ ' . htmlspecialchars(t('detail.flag_unflag')) . '</span>'
        : '';

    return '<a href="detail.php?lyrics=' . htmlspecialchars($lyric["id"]) . '" class="song-card">'
       .   $cover
       .   '<div class="song-card-body">'
       .     '<div class="song-card-title">' . htmlspecialchars($lyric["title"]) . ' ' . $flagBadge . '</div>'
       .     '<div class="song-card-band">' . $sub . '</div>'
       .   '</div>'
       .   '<svg class="song-card-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>'
       . '</a>';
}

// ── Render full list (search mode = grouped by rank; browse = grouped by sort) ──
function _renderSongList(array $lyrics, array $bandMap, array $songEvents, bool $isSearch, string $sort = 'title', bool $showAuthors = false): string {
    if (empty($lyrics)) {
        return '<div class="songs-empty"><div style="font-size:2.5rem;margin-bottom:0.5rem;">🎵</div><h3 style="margin:0 0 0.4rem;">' . htmlspecialchars(t('list.empty')) . '</h3><p class="text-muted text-sm" style="margin:0;">' . htmlspecialchars(t('list.empty_hint')) . '</p></div>';
    }
    $html = '';

    if ($isSearch) {
        // Search mode: keep sections by rank
        $sections = [
            'band'   => ['label' => t('list.group_artist'), 'items' => []],
            'title'  => ['label' => t('list.group_title'),  'items' => []],
            'meta'   => ['label' => t('list.group_meta'),   'items' => []],
            'lyrics' => ['label' => '📝 ' . t('detail.lyrics'), 'items' => []],
        ];
        foreach ($lyrics as $lyric) {
            $r = (int)($lyric['rank'] ?? 99);
            if ($r <= 2)      $sections['band']['items'][]   = $lyric;
            elseif ($r <= 4)  $sections['title']['items'][]  = $lyric;
            elseif ($r == 5)  $sections['meta']['items'][]   = $lyric;
            else              $sections['lyrics']['items'][] = $lyric;
        }
        foreach ($sections as $sec) {
            if (empty($sec['items'])) continue;
            $html .= '<div class="song-section-header"><span>' . htmlspecialchars($sec['label']) . '</span><span class="section-count">' . count($sec['items']) . '</span></div>';
            foreach ($sec['items'] as $lyric) {
                $html .= _renderSongCard($lyric, $bandMap, $songEvents[$lyric['id']] ?? [], $showAuthors);
            }
        }
        return $html;
    }

    // Browse mode: group depends on sort
    // Note: songs already come sorted by the SQL ORDER BY — we just need group headers
    $groupFn = null; // (lyric → group key, group label)

    switch ($sort) {
        case 'band':
            $groupFn = function ($lyric) use ($bandMap) {
                $bid = $lyric['band_id'] ?? null;
                $name = $bid && isset($bandMap[$bid]) ? $bandMap[$bid] : '–';
                return ['key' => '🎸 ' . $name, 'label' => '🎸 ' . $name];
            };
            break;
        case 'year':
        case 'year_desc':
            $groupFn = function ($lyric) {
                $y = (int)($lyric['release_year'] ?? 0);
                if (!$y) return ['key' => 'unknown', 'label' => t('detail.year') . ' –'];
                $decade = (int)(floor($y / 10) * 10);
                return ['key' => (string)$decade, 'label' => $decade . 's'];
            };
            break;
        case 'title':
        case 'title_desc':
        default:
            $groupFn = function ($lyric) {
                $letter = strtoupper(mb_substr(trim($lyric['title']), 0, 1));
                if (!preg_match('/[A-Z]/', $letter)) $letter = '#';
                return ['key' => $letter, 'label' => $letter];
            };
            break;
    }

    // Preserve the SQL order. Only emit a header when the group key changes.
    $currentKey = null;
    $currentCount = 0;
    $bufferedHeader = '';
    $bufferedItems = '';

    $flush = function () use (&$bufferedHeader, &$bufferedItems, &$currentCount, &$html) {
        if ($bufferedHeader !== '') {
            // Inject the count into the placeholder
            $html .= str_replace('{{COUNT}}', (string)$currentCount, $bufferedHeader) . $bufferedItems;
        }
        $bufferedHeader = '';
        $bufferedItems = '';
        $currentCount = 0;
    };

    foreach ($lyrics as $lyric) {
        $g = $groupFn($lyric);
        if ($g['key'] !== $currentKey) {
            $flush();
            $currentKey = $g['key'];
            $bufferedHeader = '<div class="song-section-header"><span>' . htmlspecialchars($g['label']) . '</span><span class="section-count">{{COUNT}}</span></div>';
        }
        $bufferedItems .= _renderSongCard($lyric, $bandMap, $songEvents[$lyric['id']] ?? [], $showAuthors);
        $currentCount++;
    }
    $flush();

    return $html;
}

// Show author credits instead of band name when narrowing to a single artist
$showAuthors = !empty($bandId);

// ── AJAX endpoint ──────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    if (isset($_GET['offset'])) {
        // Load-more response: return JSON with HTML + hasMore flag
        header('Content-Type: application/json');
        echo json_encode([
            'html'    => _renderSongList($lyrics, $bandMap, $songEvents, $isSearch, $sort, $showAuthors),
            'hasMore' => ($_offset + $_limit) < $_totalCount,
            'offset'  => $_offset + count($lyrics),
        ]);
    } else {
        // Filter response: return JSON with HTML + total count
        header('Content-Type: application/json');
        echo json_encode([
            'html'  => _renderSongList($lyrics, $bandMap, $songEvents, $isSearch, $sort, $showAuthors),
            'total' => $_totalCount,
        ]);
    }
    exit();
}

$pageTitle = t('list.title') . ' – Sing op Kölsch';
require_once "partials/head.php";
require_once "partials/nav.php";
?>

<style>
/* ── Song list page ───────────────────────────────────── */
.songs-toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 0.65rem;
  margin-bottom: 1rem;
  align-items: center;
}

/* ── Cohesive design with home page: rounded pill ──── */
.songs-search {
  flex: 1;
  min-width: 240px;
  display: flex;
  gap: 0.45rem;
  align-items: center;
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: 28px;
  padding: 0.45rem 0.45rem 0.45rem 1.05rem;
  box-shadow:
    0 1px 6px rgba(15,23,42,0.06),
    0 6px 24px rgba(15,23,42,0.06);
  transition: box-shadow 0.2s, border-color 0.2s;
}

.songs-search:focus-within {
  border-color: var(--primary-light);
  box-shadow:
    0 1px 6px rgba(15,23,42,0.06),
    0 6px 24px rgba(37,99,235,0.18);
}

html.dark .songs-search {
  background: rgba(28,33,40,0.6);
  border-color: rgba(255,255,255,0.1);
  box-shadow:
    0 1px 6px rgba(0,0,0,0.3),
    0 6px 24px rgba(0,0,0,0.4);
}

html.dark .songs-search:focus-within {
  border-color: rgba(96,165,250,0.4);
}

.songs-search-wrap {
  position: relative;
  flex: 1;
  display: flex;
  align-items: center;
}

.songs-search-icon {
  width: 18px;
  height: 18px;
  color: var(--text-3);
  flex-shrink: 0;
  margin-right: 0.5rem;
  transition: color 0.15s;
}

html.dark .songs-search-icon { color: rgba(255,255,255,0.5); }

.songs-search-wrap:focus-within .songs-search-icon { color: var(--primary); }

.songs-search input {
  flex: 1;
  min-width: 0;
  padding: 0.55rem 0.4rem !important;
  height: auto;
  font-size: 1rem !important;
  background: transparent !important;
  border: none !important;
  outline: none;
  box-shadow: none !important;
  color: var(--text) !important;
}

.songs-search input::placeholder { color: var(--text-3); }

.songs-search-clear {
  background: none !important;
  border: none !important;
  width: 28px; height: 28px;
  padding: 0 !important;
  border-radius: 50% !important;
  color: var(--text-3) !important;
  cursor: pointer;
  opacity: 0;
  pointer-events: none;
  transition: opacity 0.15s, background 0.15s, color 0.15s;
  display: flex !important; align-items: center; justify-content: center;
  flex-shrink: 0;
  box-shadow: none !important;
  margin-right: 0.25rem;
}

.songs-search-clear.visible {
  opacity: 1;
  pointer-events: auto;
}

.songs-search-clear:hover {
  background: var(--bg-alt) !important;
  color: var(--text) !important;
}

/* Mic button — Köln red gradient circle, matches home page */
.songs-search .btn-mic {
  flex-shrink: 0;
  width: 38px; height: 38px;
  background: linear-gradient(135deg, #ef4444, #dc2626) !important;
  color: #fff !important;
  border: none !important;
  cursor: pointer;
  padding: 0 !important;
  border-radius: 50% !important;
  display: inline-flex !important;
  align-items: center;
  justify-content: center;
  box-shadow:
    0 1px 0 rgba(255,255,255,0.22) inset,
    0 4px 14px rgba(220,38,38,0.35) !important;
  transition: background 0.18s, transform 0.12s, box-shadow 0.18s;
}

.songs-search .btn-mic:hover {
  background: linear-gradient(135deg, #f87171, #b91c1c) !important;
  transform: translateY(-1px);
  box-shadow:
    0 1px 0 rgba(255,255,255,0.25) inset,
    0 6px 18px rgba(220,38,38,0.5) !important;
}

.songs-search .btn-mic .mic-label { display: none; }

.songs-search .btn-mic.recording {
  background: linear-gradient(135deg, #b91c1c, #7f1d1d) !important;
  animation: micPulse 1.2s ease-in-out infinite !important;
}

@keyframes micPulse {
  0%, 100% { box-shadow: 0 1px 0 rgba(255,255,255,0.22) inset, 0 4px 16px rgba(220,38,38,0.5), 0 0 0 0 rgba(220,38,38,0.4); }
  50%      { box-shadow: 0 1px 0 rgba(255,255,255,0.22) inset, 0 4px 20px rgba(220,38,38,0.7), 0 0 0 14px rgba(220,38,38,0); }
}

/* Filter toggle button */
.filter-toggle-btn {
  display: inline-flex !important;
  align-items: center;
  gap: 0.45rem;
  height: 42px;
  padding: 0 1.1rem !important;
  position: relative;
  white-space: nowrap;
  border-radius: 28px !important;
  font-size: 0.88rem !important;
  font-weight: 600;
  background: var(--card) !important;
  border: 1px solid var(--border) !important;
  color: var(--text) !important;
  box-shadow:
    0 1px 6px rgba(15,23,42,0.06),
    0 6px 24px rgba(15,23,42,0.06) !important;
  transition: border-color 0.18s, box-shadow 0.18s, color 0.18s;
}

.filter-toggle-btn:hover {
  border-color: var(--accent) !important;
  color: var(--accent) !important;
  box-shadow:
    0 1px 6px rgba(15,23,42,0.06),
    0 6px 24px rgba(220,38,38,0.15) !important;
}

html.dark .filter-toggle-btn {
  background: rgba(28,33,40,0.6) !important;
  border-color: rgba(255,255,255,0.1) !important;
  color: rgba(255,255,255,0.92) !important;
  box-shadow:
    0 1px 6px rgba(0,0,0,0.3),
    0 6px 24px rgba(0,0,0,0.4) !important;
}

html.dark .filter-toggle-btn:hover {
  border-color: rgba(248,113,113,0.5) !important;
  color: #fca5a5 !important;
}

.filter-toggle-btn .filter-count {
  background: var(--accent);
  color: #fff;
  font-size: 0.68rem;
  font-weight: 700;
  border-radius: 9999px;
  min-width: 18px;
  height: 18px;
  padding: 0 5px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

/* Result count */
.songs-meta {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.85rem;
  color: var(--text-2);
  margin-bottom: 0.85rem;
  flex-wrap: wrap;
}

.songs-meta strong { color: var(--text); }

.active-filter-chip {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.22em 0.6em 0.22em 0.7em;
  background: var(--primary-soft);
  color: var(--primary-hover);
  border: 1px solid rgba(59,130,246,0.25);
  border-radius: 9999px;
  font-size: 0.78rem;
  font-weight: 600;
}

html.dark .active-filter-chip {
  background: rgba(96,165,250,0.18);
  color: #93c5fd;
  border-color: rgba(96,165,250,0.3);
}

.active-filter-chip button {
  background: none !important;
  border: none !important;
  padding: 0 !important;
  width: 16px; height: 16px;
  border-radius: 50% !important;
  display: inline-flex !important;
  align-items: center; justify-content: center;
  color: inherit !important;
  cursor: pointer;
  font-size: 0.85rem;
  line-height: 1;
  opacity: 0.7;
}

.active-filter-chip button:hover { opacity: 1; }

/* Filter drawer */
.filter-drawer {
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: 16px;
  padding: 1rem 1.1rem;
  margin-bottom: 1rem;
  display: none;
  flex-direction: column;
  gap: 0.85rem;
  animation: fadeInDown 0.25s var(--ease-out) both;
  box-shadow: var(--shadow-sm);
}

.filter-drawer.open { display: flex; }

.filter-row {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 0.7rem;
}

.filter-group {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.filter-group label {
  font-size: 0.74rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--text-3);
  margin: 0;
}

.filter-group select {
  height: 40px;
  width: 100%;
  padding: 0 0.7rem !important;
  font-size: 0.9rem !important;
}

.filter-checkboxes {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.filter-chip {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.45em 0.85em;
  background: var(--bg-alt);
  border: 1px solid var(--border);
  border-radius: 9999px;
  font-size: 0.85rem;
  cursor: pointer;
  user-select: none;
  transition: background 0.15s, border-color 0.15s, color 0.15s;
}

.filter-chip input { display: none; }

.filter-chip:hover {
  background: var(--card-hover);
  border-color: var(--primary-light);
}

.filter-chip.active {
  background: var(--primary-soft);
  border-color: var(--primary);
  color: var(--primary-hover);
}

html.dark .filter-chip {
  background: rgba(255,255,255,0.04);
  border-color: rgba(255,255,255,0.10);
}

html.dark .filter-chip.active {
  background: rgba(96,165,250,0.18);
  border-color: rgba(96,165,250,0.4);
  color: #93c5fd;
}

.filter-actions {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  margin-top: 0.25rem;
  padding-top: 0.85rem;
  border-top: 1px solid var(--border);
}

.filter-live-hint {
  font-size: 0.78rem;
  color: var(--text-3);
  font-weight: 500;
}

/* Pulse on the list while loading */
.songs-loading #song-list {
  opacity: 0.5;
  transition: opacity 0.15s;
}

/* ── Minimal song cards ──────────────────────────────── */
.song-grid {
  display: grid !important;
  grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)) !important;
  gap: 0.5rem !important;
}

.song-card {
  display: flex !important;
  align-items: center !important;
  justify-content: space-between !important;
  gap: 0.75rem !important;
  padding: 0.85rem 1rem !important;
  border-radius: 12px !important;
  background: transparent !important;
  border: 1px solid transparent !important;
  transition:
    background 0.18s var(--ease-out),
    border-color 0.18s var(--ease-out),
    transform 0.16s var(--ease-out) !important;
  text-decoration: none !important;
}

.song-card:hover {
  background: var(--card) !important;
  border-color: var(--border) !important;
  transform: translateX(2px) !important;
}

.song-card-body { min-width: 0; flex: 1; }

.song-card-cover {
  width: 38px; height: 38px;
  flex-shrink: 0;
  border-radius: 6px;
  object-fit: cover;
  background: var(--bg-alt);
}

.song-card-cover-empty {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, rgba(239,68,68,0.15), rgba(220,38,38,0.08));
  color: rgba(220,38,38,0.55);
}

.song-card-flag {
  display: inline-block;
  font-size: 0.62rem;
  font-weight: 700;
  padding: 0.1em 0.5em;
  border-radius: 9999px;
  background: rgba(245,158,11,0.18);
  color: #b45309;
  vertical-align: middle;
  margin-left: 0.35rem;
  letter-spacing: 0.02em;
}

html.dark .song-card-flag {
  background: rgba(251,191,36,0.18);
  color: #fbbf24;
}

@media (max-width: 480px) {
  .song-card-cover { width: 34px; height: 34px; }
}

/* ── Autocomplete suggest dropdown ──────────────────── */
.songs-suggest {
  position: absolute;
  top: calc(100% + 6px);
  left: 0;
  right: 0;
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: 18px;
  list-style: none;
  margin: 0;
  padding: 0.4rem;
  box-shadow:
    0 1px 6px rgba(15,23,42,0.08),
    0 18px 48px rgba(15,23,42,0.18);
  z-index: 50;
  max-height: 60vh;
  overflow-y: auto;
  text-align: left;
}
html.dark .songs-suggest {
  background: rgba(28,33,40,0.96);
  border-color: rgba(255,255,255,0.10);
  box-shadow:
    0 1px 6px rgba(0,0,0,0.4),
    0 18px 48px rgba(0,0,0,0.55);
}
.songs-suggest[hidden] { display: none; }
.songs-suggest .suggest-item {
  display: flex;
  align-items: center;
  gap: 0.7rem;
  padding: 0.55rem 0.7rem;
  border-radius: 12px;
  cursor: pointer;
  color: var(--text);
  text-decoration: none;
}
.songs-suggest .suggest-item:hover,
.songs-suggest .suggest-item.is-active {
  background: var(--bg-alt);
}
html.dark .songs-suggest .suggest-item:hover,
html.dark .songs-suggest .suggest-item.is-active {
  background: rgba(255,255,255,0.06);
}
.songs-suggest .suggest-cover,
.songs-suggest .suggest-cover-placeholder {
  width: 38px; height: 38px;
  flex-shrink: 0;
  border-radius: 6px;
  object-fit: cover;
  background: var(--bg-alt);
}
.songs-suggest .suggest-cover-placeholder {
  display: inline-flex; align-items: center; justify-content: center;
  background: linear-gradient(135deg, rgba(239,68,68,0.15), rgba(220,38,38,0.08));
  color: rgba(220,38,38,0.55);
}
.songs-suggest .suggest-text {
  min-width: 0; display: flex; flex-direction: column; line-height: 1.25;
}
.songs-suggest .suggest-title {
  font-weight: 600; font-size: 0.95rem;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  color: var(--text);
}
.songs-suggest .suggest-band {
  font-size: 0.78rem; color: var(--text-3);
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.songs-suggest .suggest-kind {
  margin-left: auto; font-size: 0.62rem;
  text-transform: uppercase; letter-spacing: 0.06em;
  color: var(--text-3); flex-shrink: 0; padding-left: 0.5rem;
}
.songs-suggest .suggest-empty {
  padding: 0.7rem 0.8rem; color: var(--text-3); font-size: 0.88rem;
}

.song-card-title {
  font-size: 0.95rem !important;
  font-weight: 600 !important;
  line-height: 1.3;
  color: var(--text) !important;
  margin: 0 0 0.12rem !important;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.song-card-band {
  font-size: 0.82rem !important;
  font-weight: 400;
  color: var(--text-3) !important;
  margin: 0 !important;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.song-card-chevron {
  color: var(--text-3);
  opacity: 0;
  flex-shrink: 0;
  transition: opacity 0.18s var(--ease-out), transform 0.18s var(--ease-out);
}

.song-card:hover .song-card-chevron {
  opacity: 1;
  transform: translateX(3px);
  color: var(--primary);
}

/* Section header — refined */
.song-section-header {
  display: flex !important;
  justify-content: space-between !important;
  align-items: center !important;
  padding: 0.85rem 0 0.4rem !important;
  border-bottom: 1px solid var(--border) !important;
  font-weight: 700 !important;
  letter-spacing: 0.02em !important;
  font-size: 0.85rem !important;
  text-transform: none !important;
  color: var(--text-2) !important;
}

.section-count {
  font-size: 0.72rem;
  background: var(--bg-alt);
  color: var(--text-3);
  padding: 0.1em 0.5em;
  border-radius: 9999px;
  font-weight: 700;
  letter-spacing: 0;
}

html.dark .section-count {
  background: rgba(255,255,255,0.06);
}

/* Empty state */
.songs-empty {
  grid-column: 1 / -1;
  text-align: center;
  padding: 4rem 1rem;
  color: var(--text-2);
}

@media (max-width: 680px) {
  .song-card { padding: 0.7rem 0.85rem !important; }
  .song-card-icon { width: 38px !important; height: 38px !important; }
  .songs-toolbar { gap: 0.5rem; }
  .filter-toggle-btn span:not(.filter-count) { display: none; }
}
</style>

<main class="content">

  <div style="display:flex;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;gap:0.5rem;margin-bottom:1.25rem;">
    <div>
      <h1 style="margin-bottom:0.15rem;"><?= htmlspecialchars(t('list.heading_prefix')) ?><span class="accent"><?= htmlspecialchars(t('list.heading_accent')) ?></span></h1>
      <p style="color:var(--text-2);margin:0;font-size:0.9rem;">
        <?= htmlspecialchars(t('list.subtitle', ['n' => count($lyrics)])) ?>
      </p>
    </div>
  </div>

  <!-- ── Toolbar: search + mic + filter toggle ───────────── -->
  <form class="songs-toolbar" method="get" id="search-form" autocomplete="off"
        aria-autocomplete="list" aria-haspopup="listbox" aria-expanded="false">
    <div class="songs-search" style="position:relative;">
      <div class="songs-search-wrap">
        <svg class="songs-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><line x1="20" y1="20" x2="16.65" y2="16.65"/></svg>
        <input type="search" name="lyrics" id="lyrics-search"
               placeholder="<?= htmlspecialchars(t('list.search_ph2')) ?>"
               value="<?= htmlspecialchars($query) ?>"
               role="combobox" aria-controls="songs-suggest" aria-activedescendant=""
               autocomplete="off" />
        <button type="button" class="songs-search-clear <?= $query ? 'visible' : '' ?>" id="search-clear" title="<?= htmlspecialchars(t('list.clear_search')) ?>">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>
      <button type="button" id="mic-button" class="btn-mic" title="<?= htmlspecialchars(t('list.mic_title')) ?>">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>
        <span class="mic-label"><?= htmlspecialchars(t('list.mic_label')) ?></span>
      </button>
      <ul id="songs-suggest" class="songs-suggest" role="listbox" hidden></ul>
    </div>

    <button type="button" id="filter-toggle" class="btn-secondary filter-toggle-btn" title="<?= htmlspecialchars(t('list.filter_toggle')) ?>">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
      <span><?= htmlspecialchars(t('list.filter')) ?></span>
      <span class="filter-count" id="filter-count" style="display:<?= $activeFilterCount ? 'inline-flex' : 'none' ?>;"><?= $activeFilterCount ?></span>
    </button>
  </form>

  <!-- ── Filter drawer ─────────────────────────────────── -->
  <?php $openDrawer = false; ?>
  <form class="filter-drawer <?= $openDrawer ? 'open' : '' ?>" method="get" id="filter-form">
    <input type="hidden" name="lyrics" value="<?= htmlspecialchars($query) ?>">
    <?php if ($bandId): ?>
      <!-- #50 Follow Band button when viewing by band -->
      <?php $fbName = htmlspecialchars($bandMap[$bandId] ?? ''); ?>
      <?php if (!empty($_SESSION['user_id'])): ?>
        <?php
          $fbConn = Database::getConnection();
          $fbConn->query("CREATE TABLE IF NOT EXISTS singopkoelsch_band_follows (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, band_id INT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uniq_follow (user_id, band_id), INDEX idx_user (user_id), INDEX idx_band (band_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
          $fbStmt = $fbConn->prepare("SELECT 1 FROM singopkoelsch_band_follows WHERE user_id=? AND band_id=?");
          $fbStmt->bind_param("ii", $_SESSION['user_id'], $bandId); $fbStmt->execute();
          $fbFollowing = (bool)$fbStmt->get_result()->fetch_assoc(); $fbStmt->close();
          if (isset($_GET['follow_band'])) {
              if ($_GET['follow_band'] === '1') {
                  $fi = $fbConn->prepare("INSERT IGNORE INTO singopkoelsch_band_follows (user_id, band_id) VALUES (?,?)");
                  $fi->bind_param("ii", $_SESSION['user_id'], $bandId); $fi->execute(); $fi->close();
              } else {
                  $fd = $fbConn->prepare("DELETE FROM singopkoelsch_band_follows WHERE user_id=? AND band_id=?");
                  $fd->bind_param("ii", $_SESSION['user_id'], $bandId); $fd->execute(); $fd->close();
              }
              header("Location: /lieder.php?band=$bandId"); exit;
          }
        ?>
        <div style="display:flex;align-items:center;gap:0.5rem;padding:0.5rem 0;font-size:0.85rem;color:var(--text-2);">
          🎸 <?= $fbName ?>
          <a href="/lieder.php?band=<?= $bandId ?>&follow_band=<?= $fbFollowing ? '0' : '1' ?>"
             class="btn btn-sm <?= $fbFollowing ? 'btn-danger' : 'btn-ghost' ?>"
             style="font-size:0.78rem;padding:0.25rem 0.65rem;">
            <?= $fbFollowing ? '♥ Gefolgt' : '♡ Band folgen' ?>
          </a>
        </div>
      <?php endif; ?>
    <?php endif; ?>

    <div class="filter-row">
      <div class="filter-group">
        <label for="filter-band"><?= htmlspecialchars(t('list.filter_artist')) ?></label>
        <select name="band" id="filter-band">
          <option value=""><?= htmlspecialchars(t('list.all_artists')) ?></option>
          <?php foreach ($allBands as $b): ?>
            <option value="<?= $b['band_id'] ?>" <?= $bandId === (int)$b['band_id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($b['band_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="filter-group">
        <label for="filter-sort"><?= htmlspecialchars(t('list.sort_by')) ?></label>
        <select name="sort" id="filter-sort">
          <option value="title"      <?= $sort === 'title'      ? 'selected' : '' ?>><?= htmlspecialchars(t('list.sort_title_asc')) ?></option>
          <option value="title_desc" <?= $sort === 'title_desc' ? 'selected' : '' ?>><?= htmlspecialchars(t('list.sort_title_desc')) ?></option>
          <option value="band"       <?= $sort === 'band'       ? 'selected' : '' ?>><?= htmlspecialchars(t('list.sort_band')) ?></option>
          <option value="year"       <?= $sort === 'year'       ? 'selected' : '' ?>><?= htmlspecialchars(t('list.sort_year_asc')) ?></option>
          <option value="year_desc"  <?= $sort === 'year_desc'  ? 'selected' : '' ?>><?= htmlspecialchars(t('list.sort_year_desc')) ?></option>
        </select>
      </div>

      <!-- #51 Jahrzehnt-Filter -->
      <div class="filter-group">
        <label for="filter-decade">Jahrzehnt</label>
        <select name="decade" id="filter-decade">
          <option value="">Alle Jahrzehnte</option>
          <?php foreach ([1950,1960,1970,1980,1990,2000,2010,2020] as $d): ?>
            <option value="<?= $d ?>" <?= $decade === $d ? 'selected' : '' ?>><?= $d ?>er</option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="filter-group">
      <label><?= htmlspecialchars(t('list.quick_filters')) ?></label>
      <div class="filter-checkboxes">
        <label class="filter-chip <?= $hasFlags['has_lyrics'] ? 'active' : '' ?>">
          <input type="checkbox" name="lyrics_only" value="1" <?= $hasFlags['has_lyrics'] ? 'checked' : '' ?>>
          <?= htmlspecialchars(t('list.chip_lyrics')) ?>
        </label>
        <label class="filter-chip <?= $hasFlags['has_spotify'] ? 'active' : '' ?>">
          <input type="checkbox" name="spotify_only" value="1" <?= $hasFlags['has_spotify'] ? 'checked' : '' ?>>
          <?= htmlspecialchars(t('list.chip_spotify')) ?>
        </label>
        <label class="filter-chip <?= $hasFlags['has_video'] ? 'active' : '' ?>">
          <input type="checkbox" name="video_only" value="1" <?= $hasFlags['has_video'] ? 'checked' : '' ?>>
          <?= htmlspecialchars(t('list.chip_video')) ?>
        </label>
        <label class="filter-chip <?= $hasFlags['incomplete'] ? 'active' : '' ?>">
          <input type="checkbox" name="incomplete_only" value="1" <?= $hasFlags['incomplete'] ? 'checked' : '' ?>>
          <?= htmlspecialchars(t('list.chip_incomplete')) ?>
        </label>
        <label class="filter-chip <?= $hasFlags['flagged'] ? 'active' : '' ?>">
          <input type="checkbox" name="flagged_only" value="1" <?= $hasFlags['flagged'] ? 'checked' : '' ?>>
          <?= htmlspecialchars(t('list.chip_flagged')) ?>
        </label>
      </div>
    </div>

    <div class="filter-actions">
      <span class="filter-live-hint"><?= htmlspecialchars(t('list.live_hint')) ?></span>
      <button type="button" id="filter-reset-btn" class="btn btn-ghost btn-sm"><?= htmlspecialchars(t('list.reset_all')) ?></button>
    </div>
  </form>

  <!-- ── Active filter summary ───────────────────────────── -->
  <div class="songs-meta" id="songs-meta">
    <strong id="result-count"><?= $_totalCount ?></strong> <span id="result-label"><?= htmlspecialchars($_totalCount === 1 ? t('list.result') : t('list.results')) ?></span>
    <?php if ($query): ?>
      <span class="active-filter-chip" data-chip="lyrics">
        🔍 "<?= htmlspecialchars($query) ?>"
        <button type="button" onclick="clearFilter('lyrics')" title="<?= htmlspecialchars(t('list.remove_filter')) ?>">×</button>
      </span>
    <?php endif; ?>
    <?php if ($bandId && isset($bandMap[$bandId])): ?>
      <span class="active-filter-chip" data-chip="band">
        🎸 <?= htmlspecialchars($bandMap[$bandId]) ?>
        <button type="button" onclick="clearFilter('band')" title="<?= htmlspecialchars(t('list.remove_filter')) ?>">×</button>
      </span>
    <?php endif; ?>
    <?php foreach ([
        'lyrics_only'     => [t('list.chip_lyrics'),     'has_lyrics'],
        'spotify_only'    => ['♫ Spotify',               'has_spotify'],
        'video_only'      => ['▶ Video',                 'has_video'],
        'incomplete_only' => [t('list.chip_incomplete'), 'incomplete'],
        'flagged_only'    => ['⚠ ' . t('detail.flag_unflag'), 'flagged'],
    ] as $key => [$label, $flagKey]):
      if ($hasFlags[$flagKey]):
    ?>
      <span class="active-filter-chip">
        <?= $label ?>
        <button type="button" onclick="clearFilter('<?= $key ?>')" title="<?= htmlspecialchars(t('list.remove_filter')) ?>">×</button>
      </span>
    <?php endif; endforeach; ?>
  </div>

  <div id="song-list" class="song-grid">
    <?= _renderSongList($lyrics, $bandMap, $songEvents, $isSearch, $sort, $showAuthors) ?>
  </div>

  <?php if (($_offset + $_limit) < $_totalCount): ?>
  <div id="load-more-sentinel" style="height:60px;display:flex;align-items:center;justify-content:center;margin:0.5rem 0 1.5rem;">
    <button id="load-more-btn" class="btn btn-ghost" style="min-width:160px;" onclick="loadMoreSongs()">
      <?= htmlspecialchars(t('lieder.load_more', ['n' => min($_limit, $_totalCount - $_offset - count($lyrics))])) ?>
    </button>
    <span id="load-more-spinner" style="display:none;color:var(--text-3);font-size:0.9rem;">Wird geladen…</span>
  </div>
  <?php endif; ?>

</main>

<script>
const searchInput  = document.getElementById("lyrics-search");
const searchClear  = document.getElementById("search-clear");
const filterBtn    = document.getElementById("filter-toggle");
const filterDrawer = document.querySelector('.filter-drawer');
const filterForm   = document.getElementById('filter-form');
const filterCount  = document.getElementById('filter-count');
const resetBtn     = document.getElementById('filter-reset-btn');
const songsMeta    = document.getElementById('songs-meta');
const resultCount  = document.getElementById('result-count');
const resultLabel  = document.getElementById('result-label');
const form         = document.getElementById("search-form");
const micButton    = document.getElementById("mic-button");
const songList     = document.getElementById("song-list");

let isRecording = false, mediaRecorder, mediaStream, chunks = [];

// ── Lazy loading state (#61) ─────────────────────────
let _lmOffset  = <?= (int)($_offset + count($lyrics)) ?>;
let _lmTotal   = <?= (int)$_totalCount ?>;
let _lmLimit   = <?= (int)$_limit ?>;
let _lmLoading = false;

function loadMoreSongs() {
  if (_lmLoading || _lmOffset >= _lmTotal) return;
  _lmLoading = true;
  const btn     = document.getElementById('load-more-btn');
  const spinner = document.getElementById('load-more-spinner');
  if (btn)     btn.style.display = 'none';
  if (spinner) spinner.style.display = '';

  const url = new URL(window.location.href);
  url.searchParams.set('ajax', '1');
  url.searchParams.set('offset', _lmOffset);

  fetch(url.toString())
    .then(r => r.json())
    .then(data => {
      document.getElementById('song-list').insertAdjacentHTML('beforeend', data.html);
      _lmOffset = data.offset;
      const sentinel = document.getElementById('load-more-sentinel');
      if (data.hasMore) {
        if (btn)     { btn.style.display = ''; btn.textContent = 'Mehr laden'; }
        if (spinner) spinner.style.display = 'none';
      } else if (sentinel) {
        sentinel.remove();
      }
    })
    .catch(() => {
      if (btn)     btn.style.display = '';
      if (spinner) spinner.style.display = 'none';
    })
    .finally(() => { _lmLoading = false; });
}

// Auto-load when sentinel scrolls into view
(function() {
  const sentinel = document.getElementById('load-more-sentinel');
  if (!sentinel || !window.IntersectionObserver) return;
  const obs = new IntersectionObserver(entries => {
    if (entries[0].isIntersecting) loadMoreSongs();
  }, { rootMargin: '200px' });
  obs.observe(sentinel);
})();

// ── Active filter state ──────────────────────────────
function _val(name, fallback = '') {
  const el = filterForm.querySelector(`[name="${name}"]`);
  return el ? (el.value || fallback) : fallback;
}
function _checked(name) {
  const el = filterForm.querySelector(`[name="${name}"]`);
  return el ? !!el.checked : false;
}

function readFilterState() {
  return {
    lyrics:          searchInput.value.trim(),
    band:            _val('band'),
    event:           _val('event'),
    sort:            _val('sort', 'title'),
    lyrics_only:     _checked('lyrics_only'),
    spotify_only:    _checked('spotify_only'),
    video_only:      _checked('video_only'),
    incomplete_only: _checked('incomplete_only'),
    flagged_only:    _checked('flagged_only'),
  };
}

function countActiveFilters(s) {
  let c = 0;
  if (s.band) c++;
  if (s.event) c++;
  if (s.lyrics_only) c++;
  if (s.spotify_only) c++;
  if (s.video_only) c++;
  if (s.incomplete_only) c++;
  if (s.flagged_only) c++;
  if (s.sort && s.sort !== 'title') c++;
  return c;
}

function buildUrl(s, ajax) {
  const url = new URL(window.location.origin + '/lieder.php');
  if (s.lyrics) url.searchParams.set('lyrics', s.lyrics);
  if (s.band)   url.searchParams.set('band',  s.band);
  if (s.event)  url.searchParams.set('event', s.event);
  if (s.sort && s.sort !== 'title') url.searchParams.set('sort', s.sort);
  if (s.lyrics_only)     url.searchParams.set('lyrics_only', '1');
  if (s.spotify_only)    url.searchParams.set('spotify_only', '1');
  if (s.video_only)      url.searchParams.set('video_only',  '1');
  if (s.incomplete_only) url.searchParams.set('incomplete_only', '1');
  if (s.flagged_only)    url.searchParams.set('flagged_only', '1');
  if (ajax) url.searchParams.set('ajax', '1');
  return url.toString();
}

// ── Update active filter chips client-side ───────────
function updateChips(s) {
  // Find/clear any existing data-chip spans
  songsMeta.querySelectorAll('.active-filter-chip').forEach(el => el.remove());

  const bandSel  = filterForm.querySelector('[name="band"]');
  const eventSel = filterForm.querySelector('[name="event"]');
  const bandLabel  = bandSel  && bandSel.value  ? bandSel.options[bandSel.selectedIndex].text.trim()   : '';
  const eventLabel = eventSel && eventSel.value ? eventSel.options[eventSel.selectedIndex].text.trim() : '';

  const chips = [];
  if (s.lyrics) chips.push({ key: 'lyrics', label: '🔍 "' + escapeHtml(s.lyrics) + '"' });
  if (s.band   && bandLabel)  chips.push({ key: 'band',   label: '🎸 ' + escapeHtml(bandLabel) });
  if (s.event  && eventLabel) chips.push({ key: 'event',  label: '📅 ' + escapeHtml(eventLabel) });
  if (s.lyrics_only)     chips.push({ key: 'lyrics_only',     label: '📝 Mit Liedtext' });
  if (s.spotify_only)    chips.push({ key: 'spotify_only',    label: '♫ Mit Spotify' });
  if (s.video_only)      chips.push({ key: 'video_only',      label: '▶ Mit Video' });
  if (s.incomplete_only) chips.push({ key: 'incomplete_only', label: '❓ Unvollständig' });
  if (s.flagged_only)    chips.push({ key: 'flagged_only',    label: '⚠ Markiert' });

  chips.forEach(c => {
    const span = document.createElement('span');
    span.className = 'active-filter-chip';
    span.dataset.chip = c.key;
    span.innerHTML = c.label + ' <button type="button" title="Filter entfernen">×</button>';
    span.querySelector('button').addEventListener('click', () => clearFilter(c.key));
    songsMeta.appendChild(span);
  });

  // Update filter-count badge
  const n = countActiveFilters(s);
  if (n > 0) {
    filterCount.textContent = n;
    filterCount.style.display = 'inline-flex';
  } else {
    filterCount.style.display = 'none';
  }
}

function escapeHtml(s) {
  return s.replace(/[&<>"']/g, ch => ({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
  })[ch]);
}

// ── Live apply: AJAX songs + history push + chips ────
function applyFilters(pushHistory = true) {
  const s = readFilterState();
  if (pushHistory) {
    window.history.pushState({}, '', buildUrl(s, false));
  }
  document.body.classList.add('songs-loading');
  fetch(buildUrl(s, true))
    .then(r => r.json())
    .then(data => {
      songList.innerHTML = data.html.trim();
      const n = data.total;
      resultCount.textContent = n;
      resultLabel.textContent = n !== 1 ? 'Ergebnisse' : 'Ergebnis';
      updateChips(s);
    })
    .catch(e => console.error('Filterfehler:', e))
    .finally(() => document.body.classList.remove('songs-loading'));
}

// Debounce only the text search; selects/checkboxes apply immediately
let debounce;
searchInput.addEventListener('input', () => {
  searchClear.classList.toggle('visible', searchInput.value.length > 0);
  clearTimeout(debounce);
  debounce = setTimeout(() => applyFilters(), 250);
});

// Selects + checkboxes — live on change
filterForm.querySelectorAll('select, input[type="checkbox"]').forEach(el => {
  el.addEventListener('change', () => {
    // Sync the .filter-chip parent's active class for checkboxes
    if (el.type === 'checkbox') {
      const parent = el.closest('.filter-chip');
      if (parent) parent.classList.toggle('active', el.checked);
    }
    applyFilters();
  });
});

// Reset all filters
resetBtn.addEventListener('click', () => {
  filterForm.querySelectorAll('select').forEach(s => s.selectedIndex = 0);
  filterForm.querySelectorAll('input[type="checkbox"]').forEach(c => {
    c.checked = false;
    c.closest('.filter-chip')?.classList.remove('active');
  });
  applyFilters();
});

// ── Filter drawer toggle ─────────────────────────────
filterBtn.addEventListener('click', () => filterDrawer.classList.toggle('open'));

// ── Clear a single filter ────────────────────────────
function clearFilter(key) {
  if (key === 'lyrics') {
    searchInput.value = '';
    searchClear.classList.remove('visible');
  } else if (key === 'band' || key === 'event') {
    const el = filterForm.querySelector(`[name="${key}"]`);
    if (el) el.value = '';
  } else if (key === 'sort') {
    filterForm.querySelector('[name="sort"]').value = 'title';
  } else {
    // checkbox keys: lyrics_only, spotify_only, video_only
    const cb = filterForm.querySelector(`[name="${key}"]`);
    if (cb) {
      cb.checked = false;
      cb.closest('.filter-chip')?.classList.remove('active');
    }
  }
  applyFilters();
}

// Clear search button
searchClear.addEventListener('click', () => {
  searchInput.value = '';
  searchClear.classList.remove('visible');
  applyFilters();
  searchInput.focus();
});

form.addEventListener('submit', e => {
  e.preventDefault();
  applyFilters();
});

// History back/forward
window.addEventListener('popstate', () => {
  const params = new URLSearchParams(window.location.search);
  searchInput.value = params.get('lyrics') || '';
  searchClear.classList.toggle('visible', searchInput.value.length > 0);
  const setVal = (name, val) => {
    const el = filterForm.querySelector(`[name="${name}"]`);
    if (el) el.value = val;
  };
  setVal('band',  params.get('band')  || '');
  setVal('event', params.get('event') || '');
  setVal('sort',  params.get('sort')  || 'title');
  filterForm.querySelectorAll('input[type="checkbox"]').forEach(cb => {
    cb.checked = params.get(cb.name) === '1';
    cb.closest('.filter-chip')?.classList.toggle('active', cb.checked);
  });
  applyFilters(false);
});

// ── Mic / Shazam ─────────────────────────────────────
micButton.addEventListener("click", () => isRecording ? stopListening() : startListening());

function startListening() {
  navigator.mediaDevices.getUserMedia({ audio: true }).then(s => {
    mediaStream = s; mediaRecorder = new MediaRecorder(s); chunks = [];
    mediaRecorder.ondataavailable = e => chunks.push(e.data);
    mediaRecorder.onstop = async () => {
      const fd = new FormData();
      fd.append("audio", new Blob(chunks, { type: "audio/webm" }), "rec.webm");
      chunks = [];
      try {
        const res  = await fetch("/shazam.php", { method: "POST", body: fd });
        const data = await res.json();
        if (!data.error) {
          stopListening();
          searchInput.value = data.title;
          searchClear.classList.add('visible');
          performSearch(data.title);
        } else if (isRecording) {
          setTimeout(() => {
            if (isRecording) {
              mediaRecorder.start();
              setTimeout(() => { if (isRecording && mediaRecorder.state !== "inactive") mediaRecorder.stop(); }, 5000);
            }
          }, 500);
        }
      } catch(e) { console.error("Shazam-Fehler:", e); }
    };
    mediaRecorder.start();
    setTimeout(() => { if (isRecording && mediaRecorder.state !== "inactive") mediaRecorder.stop(); }, 5000);
    isRecording = true;
    micButton.classList.add('recording');
    micButton.innerHTML = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="6" height="6"/><circle cx="12" cy="12" r="10"/></svg> <span class="mic-label">Stopp</span>`;
  });
}

function stopListening() {
  isRecording = false;
  if (mediaRecorder && mediaRecorder.state !== "inactive") mediaRecorder.stop();
  if (mediaStream) mediaStream.getTracks().forEach(t => t.stop());
  micButton.classList.remove('recording');
  micButton.innerHTML = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg> <span class="mic-label">Erkennen</span>`;
}

// ── Autocomplete suggest dropdown ────────────────────
(function() {
  const list = document.getElementById('songs-suggest');
  if (!list) return;
  let abortCtl = null, items = [], activeIdx = -1, debounceSug = null;
  let suppressNextOpen = false;

  function escHtml(s) {
    return (s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]);
  }
  function close() {
    list.hidden = true;
    list.innerHTML = '';
    items = []; activeIdx = -1;
    form.setAttribute('aria-expanded', 'false');
    searchInput.setAttribute('aria-activedescendant', '');
  }
  function setActive(idx) {
    const old = list.querySelector('.suggest-item.is-active');
    if (old) old.classList.remove('is-active');
    activeIdx = idx;
    if (idx < 0 || idx >= items.length) {
      searchInput.setAttribute('aria-activedescendant', '');
      return;
    }
    const el = list.children[idx];
    if (el) {
      el.classList.add('is-active');
      el.scrollIntoView({ block: 'nearest' });
      searchInput.setAttribute('aria-activedescendant', el.id);
    }
  }
  function render(results, q) {
    items = results;
    list.innerHTML = '';
    if (!results.length) {
      const li = document.createElement('li');
      li.className = 'suggest-empty';
      li.textContent = q.length < 1 ? '' : 'Keine Treffer. Drücke Enter, um trotzdem zu suchen.';
      list.appendChild(li);
      list.hidden = false;
      form.setAttribute('aria-expanded', 'true');
      return;
    }
    results.forEach((r, i) => {
      const li = document.createElement('li');
      li.className = 'suggest-item';
      li.id = 'lsug-' + i;
      li.setAttribute('role', 'option');
      const cover = r.cover_url
        ? `<img class="suggest-cover" src="${escHtml(r.cover_url)}" alt="" loading="lazy">`
        : `<span class="suggest-cover-placeholder" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg></span>`;
      const kindLabel = r.kind === 'fuzzy' ? 'ähnlich' : '';
      li.innerHTML = `
        ${cover}
        <div class="suggest-text">
          <span class="suggest-title">${escHtml(r.title)}</span>
          <span class="suggest-band">${escHtml(r.band) || ''}${r.album ? (r.band ? ' · ' : '') + escHtml(r.album) : ''}</span>
        </div>
        ${kindLabel ? `<span class="suggest-kind">${kindLabel}</span>` : ''}
      `;
      li.addEventListener('mousedown', e => {
        e.preventDefault();
        window.location = '/detail.php?lyrics=' + r.id;
      });
      list.appendChild(li);
    });
    list.hidden = false;
    form.setAttribute('aria-expanded', 'true');
  }
  async function fetchSuggest(q) {
    if (abortCtl) abortCtl.abort();
    abortCtl = new AbortController();
    try {
      const res = await fetch('/api_suggest.php?q=' + encodeURIComponent(q), { signal: abortCtl.signal });
      const data = await res.json();
      if (data.q !== searchInput.value.trim()) return;
      render(data.results || [], q);
    } catch (e) {}
  }
  searchInput.addEventListener('input', () => {
    const q = searchInput.value.trim();
    clearTimeout(debounceSug);
    if (q.length < 1) { close(); return; }
    debounceSug = setTimeout(() => fetchSuggest(q), 140);
  });
  searchInput.addEventListener('keydown', e => {
    if (list.hidden || !items.length) {
      if (e.key === 'ArrowDown' && searchInput.value.trim()) fetchSuggest(searchInput.value.trim());
      return;
    }
    if (e.key === 'ArrowDown') { e.preventDefault(); setActive((activeIdx + 1) % items.length); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setActive((activeIdx - 1 + items.length) % items.length); }
    else if (e.key === 'Enter' && activeIdx >= 0) {
      e.preventDefault();
      window.location = '/detail.php?lyrics=' + items[activeIdx].id;
    } else if (e.key === 'Escape') { close(); }
  });
  document.addEventListener('click', e => {
    if (!e.target.closest('.songs-search')) close();
  });
  searchInput.addEventListener('focus', () => {
    const q = searchInput.value.trim();
    if (q.length >= 1) fetchSuggest(q);
  });
})();
</script>

<script>
// Offline (installed PWA / native app): only show songs whose detail page is cached.
(function () {
  if (!('caches' in window) || !navigator.serviceWorker) return;
  var listEl = document.getElementById('song-list');
  if (!listEl) return;

  async function cachedKeys() {
    var set = new Set();
    try {
      var names = await caches.keys();
      for (var n of names) {
        var c = await caches.open(n);
        (await c.keys()).forEach(function (r) { var u = new URL(r.url); set.add(u.pathname + u.search); });
      }
    } catch (e) {}
    return set;
  }

  function note(show, count) {
    var el = document.getElementById('offline-note');
    if (!show) { if (el) el.remove(); return; }
    if (!el) {
      el = document.createElement('div');
      el.id = 'offline-note';
      el.style.cssText = 'grid-column:1/-1;margin:0 0 .9rem;padding:.6rem .9rem;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);color:var(--text-2);font-size:.88rem;';
      listEl.insertBefore(el, listEl.firstChild);
    }
    el.textContent = '📴 Offline – es werden nur heruntergeladene Lieder gezeigt (' + count + ').';
  }

  async function apply() {
    var cards = listEl.querySelectorAll('.song-card');
    if (!cards.length) return;
    if (navigator.onLine || !navigator.serviceWorker.controller) {
      cards.forEach(function (a) { a.style.display = ''; });
      note(false);
      return;
    }
    var keys = await cachedKeys(), shown = 0;
    cards.forEach(function (a) {
      var u = new URL(a.href);
      if (keys.has(u.pathname + u.search)) { a.style.display = ''; shown++; }
      else { a.style.display = 'none'; }
    });
    note(true, shown);
  }

  window.addEventListener('online', apply);
  window.addEventListener('offline', apply);
  apply();
})();
</script>

<?php require_once "partials/footer.php"; ?>
