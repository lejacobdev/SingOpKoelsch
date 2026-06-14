<?php
require_once "protect.php";
require_once "functions.php";

Database::getConnection();
Database::ensurePreferencesTable();

$album = trim($_GET['title']  ?? '');
$bandId = (int)($_GET['band'] ?? 0);

if ($album === '' || $bandId <= 0) {
    require __DIR__ . "/404.php";
    exit;
}

$conn = Database::getConnection();
$stmt = $conn->prepare(
    "SELECT l.id, l.title, l.cover_url, l.spotify_link, l.release_year, l.album, l.track_number,
            b.band_name
     FROM singopkoelsch_lyrics l
     LEFT JOIN singopkoelsch_bands b ON b.band_id = l.band_id
     WHERE l.album = ? AND l.band_id = ?
     ORDER BY (l.track_number IS NULL), l.track_number ASC, l.title ASC"
);
$stmt->bind_param('si', $album, $bandId);
$stmt->execute();
$songs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (!$songs) {
    require __DIR__ . "/404.php";
    exit;
}

// Header info: take from any song that has them
$cover     = '';
$year      = null;
$bandName  = $songs[0]['band_name'] ?? '';
foreach ($songs as $s) {
    if (!$cover && !empty($s['cover_url']))    $cover = $s['cover_url'];
    if (!$year  && !empty($s['release_year'])) $year  = (int)$s['release_year'];
    if ($cover && $year) break;
}

// #84 Sponsor-Badges
$conn->query("CREATE TABLE IF NOT EXISTS singopkoelsch_band_sponsors (id INT AUTO_INCREMENT PRIMARY KEY, band_id INT NOT NULL, name VARCHAR(100) NOT NULL, url VARCHAR(255), tier VARCHAR(32) DEFAULT 'standard', INDEX idx_band (band_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$_sr = $conn->query("SELECT name, url, tier FROM singopkoelsch_band_sponsors WHERE band_id = $bandId");
$sponsors = $_sr ? $_sr->fetch_all(MYSQLI_ASSOC) : [];

$pageTitle = $album . ' – ' . $bandName . ' – Sing op Kölsch';
require_once "partials/head.php";
require_once "partials/nav.php";
?>

<main class="content">

  <div class="album-header">
    <div class="album-cover-wrap">
      <?php if ($cover): ?>
        <img src="<?= htmlspecialchars($cover) ?>" alt="Cover: <?= htmlspecialchars($album) ?>" loading="eager" decoding="async" />
      <?php else: ?>
        <div class="album-cover-placeholder" aria-hidden="true">
          <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>
        </div>
      <?php endif; ?>
    </div>
    <div class="album-header-text">
      <div class="album-eyebrow"><?= htmlspecialchars(t('album.eyebrow')) ?></div>
      <h1><?= htmlspecialchars($album) ?></h1>
      <p class="album-sub">
        <a href="/lieder.php?band=<?= (int)$bandId ?>"><?= htmlspecialchars($bandName) ?></a>
        <?php if ($year): ?> · <?= $year ?><?php endif; ?>
        · <?= htmlspecialchars(t(count($songs) === 1 ? 'album.song_count' : 'album.song_count_pl', ['n' => count($songs)])) ?>
      </p>
    </div>
  </div>

  <?php if (!empty($sponsors)): ?>
  <div style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;margin:-0.5rem 0 1.25rem;">
    <span style="font-size:0.72rem;font-weight:700;letter-spacing:0.07em;text-transform:uppercase;color:var(--text-3);">Gesponsert von</span>
    <?php foreach ($sponsors as $sp): ?>
      <?php $tierColor = $sp['tier'] === 'gold' ? '#f59e0b' : ($sp['tier'] === 'silver' ? '#94a3b8' : 'var(--primary)'); ?>
      <?php if (!empty($sp['url'])): ?>
        <a href="<?= htmlspecialchars($sp['url']) ?>" target="_blank" rel="noopener sponsored" style="display:inline-flex;align-items:center;gap:0.3rem;padding:0.25rem 0.65rem;background:rgba(255,255,255,0.05);border:1px solid <?= $tierColor ?>;border-radius:999px;font-size:0.78rem;color:var(--text-2);text-decoration:none;font-weight:600;">
          <span style="color:<?= $tierColor ?>;">★</span> <?= htmlspecialchars($sp['name']) ?>
        </a>
      <?php else: ?>
        <span style="display:inline-flex;align-items:center;gap:0.3rem;padding:0.25rem 0.65rem;background:rgba(255,255,255,0.05);border:1px solid <?= $tierColor ?>;border-radius:999px;font-size:0.78rem;color:var(--text-2);font-weight:600;">
          <span style="color:<?= $tierColor ?>;">★</span> <?= htmlspecialchars($sp['name']) ?>
        </span>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="album-track-list">
    <?php foreach ($songs as $i => $s): ?>
      <div class="album-track">
        <span class="album-track-num"><?= $s['track_number'] !== null ? (int)$s['track_number'] : ($i + 1) ?></span>
        <a class="album-track-title" href="/detail.php?lyrics=<?= (int)$s['id'] ?>"
           title="<?= htmlspecialchars(t('album.open_in_book')) ?>">
          <?= htmlspecialchars($s['title']) ?>
        </a>
        <?php if (!empty($s['spotify_link'])): ?>
          <a class="album-track-spotify" href="<?= htmlspecialchars($s['spotify_link']) ?>"
             target="_blank" rel="noopener noreferrer"
             title="<?= htmlspecialchars(t('detail.spotify')) ?>" aria-label="<?= htmlspecialchars(t('detail.spotify')) ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 0C5.4 0 0 5.4 0 12s5.4 12 12 12 12-5.4 12-12S18.66 0 12 0zm5.521 17.34c-.24.359-.66.48-1.021.24-2.82-1.74-6.36-2.101-10.561-1.141-.418.122-.84-.179-.94-.539-.12-.421.18-.78.54-.9 4.56-1.021 8.52-.6 11.64 1.32.42.18.479.659.301 1.02zm1.44-3.3c-.301.42-.841.6-1.262.3-3.239-1.98-8.159-2.58-11.939-1.38-.479.12-1.02-.12-1.14-.6-.12-.48.12-1.021.6-1.141C9.6 9.9 15 10.561 18.72 12.84c.361.181.54.78.241 1.2zm.12-3.36C15.24 8.4 8.82 8.16 5.16 9.301c-.6.179-1.2-.181-1.38-.721-.18-.601.18-1.2.72-1.381 4.26-1.26 11.28-1.02 15.721 1.621.539.3.719 1.02.42 1.56-.299.421-1.02.599-1.559.3z"/></svg>
          </a>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- #16 Band-Biografie: Wikipedia-Link + Bandseite -->
  <div style="margin-top:2rem;padding-top:1.5rem;border-top:1px solid var(--border);">
    <div style="display:flex;align-items:center;gap:0.75rem;flex-wrap:wrap;">
      <a href="/lieder.php?band=<?= (int)$bandId ?>"
         style="display:inline-flex;align-items:center;gap:0.45rem;padding:0.55rem 1rem;background:rgba(220,38,38,0.08);border:1px solid rgba(220,38,38,0.2);border-radius:999px;font-size:0.85rem;font-weight:600;color:var(--text);text-decoration:none;">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>
        Alle Songs von <?= htmlspecialchars($bandName) ?>
      </a>
      <a href="https://de.wikipedia.org/wiki/<?= urlencode($bandName) ?>" target="_blank" rel="noopener noreferrer"
         style="display:inline-flex;align-items:center;gap:0.45rem;padding:0.55rem 1rem;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.1);border-radius:999px;font-size:0.85rem;font-weight:500;color:var(--text-2);text-decoration:none;">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
        Wikipedia
      </a>
      <a href="https://www.youtube.com/results?search_query=<?= urlencode($bandName . ' ' . $album) ?>" target="_blank" rel="noopener noreferrer"
         style="display:inline-flex;align-items:center;gap:0.45rem;padding:0.55rem 1rem;background:rgba(255,0,0,0.06);border:1px solid rgba(255,0,0,0.15);border-radius:999px;font-size:0.85rem;font-weight:500;color:var(--text-2);text-decoration:none;">
        ▶ YouTube
      </a>
    </div>
  </div>

</main>

<style>
.album-header {
  display: flex;
  align-items: flex-end;
  gap: 1.5rem;
  margin-bottom: 1.5rem;
  padding: 1rem 0;
}
.album-cover-wrap {
  width: clamp(160px, 28vw, 240px);
  aspect-ratio: 1 / 1;
  flex-shrink: 0;
  border-radius: 12px;
  overflow: hidden;
  background: var(--bg-alt);
  box-shadow:
    0 2px 8px rgba(15,23,42,0.12),
    0 20px 56px rgba(15,23,42,0.20);
}
html.dark .album-cover-wrap {
  box-shadow:
    0 2px 8px rgba(0,0,0,0.5),
    0 20px 56px rgba(0,0,0,0.65);
}
.album-cover-wrap img { width: 100%; height: 100%; object-fit: cover; display: block; }
.album-cover-placeholder {
  width: 100%; height: 100%;
  display: flex; align-items: center; justify-content: center;
  color: rgba(220,38,38,0.45);
  background: linear-gradient(135deg, rgba(239,68,68,0.12), rgba(220,38,38,0.05));
}
.album-header-text { min-width: 0; }
.album-eyebrow {
  font-size: 0.72rem; font-weight: 700; letter-spacing: 0.12em;
  text-transform: uppercase; color: var(--text-3);
  margin-bottom: 0.4rem;
}
.album-header-text h1 {
  font-size: clamp(1.6rem, 4.5vw, 2.6rem);
  line-height: 1.1; letter-spacing: -0.02em; margin: 0 0 0.5rem;
}
.album-sub { color: var(--text-2); margin: 0; font-size: 0.95rem; }
.album-sub a { color: var(--text); font-weight: 600; text-decoration: none; }
.album-sub a:hover { color: #dc2626; }

.album-track-list { display: flex; flex-direction: column; gap: 0.1rem; }
.album-track {
  display: flex; align-items: center; gap: 0.85rem;
  padding: 0.6rem 0.85rem;
  border-radius: 10px;
  transition: background 0.1s;
}
.album-track:hover { background: var(--bg-alt); }
html.dark .album-track:hover { background: rgba(255,255,255,0.05); }
.album-track-num {
  width: 1.6rem; flex-shrink: 0;
  text-align: right; color: var(--text-3);
  font-variant-numeric: tabular-nums; font-size: 0.88rem;
}
.album-track-title {
  flex: 1; min-width: 0;
  color: var(--text);
  text-decoration: none;
  font-weight: 500;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  padding: 0.15rem 0;
  border-radius: 4px;
  transition: color 0.12s;
}
.album-track-title:hover { color: #dc2626; }
html.dark .album-track-title:hover { color: #fca5a5; }
.album-track-spotify {
  width: 32px; height: 32px;
  display: inline-flex; align-items: center; justify-content: center;
  border-radius: 50%;
  color: #1db954;
  flex-shrink: 0;
  cursor: pointer;
  text-decoration: none;
  transition: transform 0.14s, background 0.14s;
}
.album-track-spotify:hover {
  transform: scale(1.12);
  background: rgba(29,185,84,0.12);
}

@media (max-width: 600px) {
  .album-header { flex-direction: column; align-items: flex-start; gap: 1rem; padding: 0.5rem 0 0; }
  .album-cover-wrap { width: 160px; }
}
</style>

<?php require_once "partials/footer.php"; ?>
