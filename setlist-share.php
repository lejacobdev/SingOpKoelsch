<?php
require_once "protect.php";
require_once "functions.php";

$token = trim($_GET['token'] ?? '');
if (!$token) { header('Location: /'); exit; }

$conn = Database::getConnection();
$conn->query("CREATE TABLE IF NOT EXISTS singopkoelsch_setlists (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, name VARCHAR(255) NOT NULL, description TEXT, share_token VARCHAR(64) NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX idx_user (user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$conn->query("CREATE TABLE IF NOT EXISTS singopkoelsch_setlist_songs (id INT AUTO_INCREMENT PRIMARY KEY, setlist_id INT NOT NULL, song_id INT NOT NULL, position INT NOT NULL DEFAULT 0, added_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uniq (setlist_id, song_id), INDEX idx_setlist (setlist_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$stmt = $conn->prepare("SELECT s.*, u.name as owner_name FROM singopkoelsch_setlists s JOIN singopkoelsch_users u ON u.user_id=s.user_id WHERE s.share_token = ?");
$stmt->bind_param("s", $token); $stmt->execute();
$setlist = $stmt->get_result()->fetch_assoc(); $stmt->close();

if (!$setlist) { header('HTTP/1.1 404 Not Found'); require __DIR__ . '/404.php'; exit; }

$songs = $conn->query("SELECT ss.song_id, ss.position, l.title, b.band_name, l.cover_url FROM singopkoelsch_setlist_songs ss JOIN singopkoelsch_lyrics l ON l.id=ss.song_id LEFT JOIN singopkoelsch_bands b ON b.band_id=l.band_id WHERE ss.setlist_id={$setlist['id']} ORDER BY ss.position ASC, ss.added_at ASC")->fetch_all(MYSQLI_ASSOC);

$pageTitle = htmlspecialchars($setlist['name']) . " – Setlist – Sing op Kölsch";
$_cssVer = @filemtime(__DIR__ . '/style.css') ?: time();
require_once "partials/head.php";
require_once "partials/nav.php";
?>
<main class="content">
  <a href="/" class="round-icon-btn" style="margin-bottom:1.25rem;display:inline-flex;" aria-label="Zurück"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg></a>
  <h1 style="margin:0 0 0.25rem;font-size:1.3rem;">🎶 <?= htmlspecialchars($setlist['name']) ?></h1>
  <p style="margin:0 0 1.5rem;font-size:0.82rem;color:var(--text-3);">Von <?= htmlspecialchars($setlist['owner_name']) ?> · <?= count($songs) ?> Songs</p>
  <?php if (!empty($setlist['description'])): ?>
    <p style="margin:0 0 1.25rem;font-size:0.9rem;color:var(--text-2);"><?= htmlspecialchars($setlist['description']) ?></p>
  <?php endif; ?>
  <div style="display:flex;flex-direction:column;gap:0.4rem;">
    <?php foreach ($songs as $i => $s): ?>
      <a href="/detail.php?lyrics=<?= (int)$s['song_id'] ?>" style="display:flex;align-items:center;gap:0.65rem;padding:0.6rem 0.75rem;background:var(--card);border:1px solid var(--border);border-radius:10px;text-decoration:none;">
        <span style="font-size:0.78rem;color:var(--text-3);min-width:1.4rem;text-align:right;"><?= $i+1 ?></span>
        <?php if (!empty($s['cover_url'])): ?><img src="<?= htmlspecialchars($s['cover_url']) ?>" style="width:36px;height:36px;border-radius:5px;object-fit:cover;flex-shrink:0;" alt="" loading="lazy"><?php endif; ?>
        <div style="flex:1;min-width:0;">
          <div style="font-weight:600;font-size:0.88rem;color:var(--text);"><?= htmlspecialchars($s['title']) ?></div>
          <div style="font-size:0.76rem;color:var(--text-3);"><?= htmlspecialchars($s['band_name'] ?? '–') ?></div>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
</main>
<?php require_once "partials/footer.php"; ?>
