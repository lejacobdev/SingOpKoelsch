<?php
require_once "protect.php";
require_once "functions.php";

if (!isLoggedIn()) {
    header('Location: /login.php?return=/favorites.php');
    exit;
}

$conn = Database::getConnection();
$conn->query("CREATE TABLE IF NOT EXISTS singopkoelsch_favorites (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, song_id INT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uniq_fav (user_id, song_id), INDEX idx_user (user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$uid  = (int)$_SESSION['user_id'];
$stmt = $conn->prepare(
    "SELECT l.id, l.title, b.band_name, l.cover_url, f.created_at as faved_at
     FROM singopkoelsch_favorites f
     JOIN singopkoelsch_lyrics l ON l.id = f.song_id
     LEFT JOIN singopkoelsch_bands b ON b.band_id = l.band_id
     WHERE f.user_id = ?
     ORDER BY f.created_at DESC"
);
$stmt->bind_param("i", $uid);
$stmt->execute();
$favorites = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$pageTitle = 'Favoriten – Sing op Kölsch';
require_once "partials/head.php";
require_once "partials/nav.php";
?>

<style>
.songs-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
  gap: 0.5rem;
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
  text-decoration: none !important;
  transition: background 0.18s, border-color 0.18s, transform 0.16s !important;
}
.song-card:hover {
  background: var(--card) !important;
  border-color: var(--border) !important;
  transform: translateX(2px) !important;
}
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
.song-card-info { flex: 1; min-width: 0; }
.song-card-title {
  font-size: 0.95rem !important;
  font-weight: 600 !important;
  color: var(--text) !important;
  margin: 0 0 0.12rem !important;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.song-card-band {
  font-size: 0.82rem !important;
  color: var(--text-3) !important;
  margin: 0 !important;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.song-card-chevron { color: var(--text-3); opacity: 0; flex-shrink: 0; transition: opacity 0.18s, transform 0.18s; }
.song-card:hover .song-card-chevron { opacity: 1; transform: translateX(3px); color: var(--primary); }
@media (max-width: 480px) {
  .songs-grid { grid-template-columns: 1fr; }
  .song-card-cover { width: 34px; height: 34px; }
}
</style>

<main class="content">
  <div class="page-header">
    <h1 style="display:flex;align-items:center;gap:0.6rem;">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor" style="color:#ef4444;flex-shrink:0"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78L12 21.23l8.84-8.84a5.5 5.5 0 0 0 0-7.78z"/></svg>
      Favoriten
    </h1>
    <?php if ($favorites): ?>
      <p style="color:var(--text-2);margin:0;"><?= count($favorites) ?> <?= count($favorites) === 1 ? 'Song' : 'Songs' ?> gespeichert</p>
    <?php endif; ?>
  </div>

  <?php if (empty($favorites)): ?>
    <div style="text-align:center;padding:4rem 1rem;color:var(--text-2);">
      <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="opacity:0.35;margin-bottom:1rem;display:block;margin-left:auto;margin-right:auto;"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78L12 21.23l8.84-8.84a5.5 5.5 0 0 0 0-7.78z"/></svg>
      <p style="font-size:1.05rem;margin:0 0 0.5rem;">Noch keine Favoriten</p>
      <p style="font-size:0.9rem;margin:0 0 1.5rem;">Tippe auf das Herz-Symbol auf einer Songseite, um Songs zu speichern.</p>
      <a href="/lieder.php" class="btn">Songs entdecken</a>
    </div>
  <?php else: ?>
    <div class="songs-grid">
      <?php foreach ($favorites as $song): ?>
        <a href="/detail.php?lyrics=<?= (int)$song['id'] ?>" class="song-card">
          <?php if (!empty($song['cover_url'])): ?>
            <img class="song-card-cover" src="<?= htmlspecialchars($song['cover_url']) ?>" alt="" loading="lazy">
          <?php else: ?>
            <span class="song-card-cover song-card-cover-empty" aria-hidden="true">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>
            </span>
          <?php endif; ?>
          <div class="song-card-info">
            <div class="song-card-title"><?= htmlspecialchars($song['title']) ?></div>
            <div class="song-card-band"><?= htmlspecialchars($song['band_name'] ?? '–') ?></div>
          </div>
          <svg class="song-card-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</main>

<?php require_once "partials/footer.php"; ?>
