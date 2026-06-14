<?php
// #5 Setlist-Funktion — Playlisten erstellen, Songs hinzufügen, teilen
require_once "protect.php";
require_once "functions.php";

requireLogin();
$conn = Database::getConnection();

// Ensure tables
$conn->query("CREATE TABLE IF NOT EXISTS singopkoelsch_setlists (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, name VARCHAR(255) NOT NULL, description TEXT, share_token VARCHAR(64) NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX idx_user (user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$conn->query("CREATE TABLE IF NOT EXISTS singopkoelsch_setlist_songs (id INT AUTO_INCREMENT PRIMARY KEY, setlist_id INT NOT NULL, song_id INT NOT NULL, position INT NOT NULL DEFAULT 0, added_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uniq (setlist_id, song_id), INDEX idx_setlist (setlist_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$userId = (int)$_SESSION['user_id'];
$flash = '';

// POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        if (mb_strlen($name) >= 1) {
            $stmt = $conn->prepare("INSERT INTO singopkoelsch_setlists (user_id, name, description) VALUES (?, ?, ?)");
            $stmt->bind_param("iss", $userId, $name, $desc); $stmt->execute();
            $flash = 'Setlist "' . htmlspecialchars($name) . '" erstellt.';
        }
    } elseif ($action === 'delete' && isset($_POST['setlist_id'])) {
        $sid = (int)$_POST['setlist_id'];
        $stmt = $conn->prepare("DELETE FROM singopkoelsch_setlists WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $sid, $userId); $stmt->execute(); $stmt->close();
        $conn->query("DELETE FROM singopkoelsch_setlist_songs WHERE setlist_id = $sid");
        $flash = 'Setlist gelöscht.';
    } elseif ($action === 'remove_song' && isset($_POST['setlist_id'], $_POST['song_id'])) {
        $sid  = (int)$_POST['setlist_id'];
        $sid2 = (int)$_POST['song_id'];
        // Verify ownership
        $os = $conn->prepare("SELECT id FROM singopkoelsch_setlists WHERE id = ? AND user_id = ?");
        $os->bind_param("ii", $sid, $userId); $os->execute();
        if ($os->get_result()->fetch_assoc()) {
            $rs = $conn->prepare("DELETE FROM singopkoelsch_setlist_songs WHERE setlist_id = ? AND song_id = ?");
            $rs->bind_param("ii", $sid, $sid2); $rs->execute(); $rs->close();
        }
        $os->close();
    } elseif ($action === 'share' && isset($_POST['setlist_id'])) {
        $sid   = (int)$_POST['setlist_id'];
        $token = bin2hex(random_bytes(16));
        $stmt  = $conn->prepare("UPDATE singopkoelsch_setlists SET share_token = ? WHERE id = ? AND user_id = ?");
        $stmt->bind_param("sii", $token, $sid, $userId); $stmt->execute(); $stmt->close();
        $flash = 'Setlist kann jetzt geteilt werden.';
    }
    header('Location: /setlists.php'); exit;
}

// Load setlists for this user
$setlists = $conn->query("SELECT s.*, (SELECT COUNT(*) FROM singopkoelsch_setlist_songs ss WHERE ss.setlist_id=s.id) as song_count FROM singopkoelsch_setlists s WHERE s.user_id=$userId ORDER BY s.created_at DESC")->fetch_all(MYSQLI_ASSOC);

// Selected setlist detail
$detailId = (int)($_GET['id'] ?? 0);
$detail   = null;
$detailSongs = [];
if ($detailId) {
    $stmt = $conn->prepare("SELECT * FROM singopkoelsch_setlists WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $detailId, $userId); $stmt->execute();
    $detail = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if ($detail) {
        $detailSongs = $conn->query("SELECT ss.song_id, ss.position, l.title, b.band_name, l.cover_url FROM singopkoelsch_setlist_songs ss JOIN singopkoelsch_lyrics l ON l.id=ss.song_id LEFT JOIN singopkoelsch_bands b ON b.band_id=l.band_id WHERE ss.setlist_id=$detailId ORDER BY ss.position ASC, ss.added_at ASC")->fetch_all(MYSQLI_ASSOC);
    }
}

$pageTitle = "Setlists – Sing op Kölsch";
$_cssVer = @filemtime(__DIR__ . '/style.css') ?: time();
require_once "partials/head.php";
require_once "partials/nav.php";
?>
<main class="content">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;flex-wrap:wrap;gap:0.75rem;">
    <div style="display:flex;align-items:center;gap:0.75rem;">
      <a href="/" class="round-icon-btn" aria-label="Zurück"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg></a>
      <h1 style="margin:0;font-size:1.3rem;">🎶 Meine Setlists</h1>
    </div>
    <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('create-form').hidden=!document.getElementById('create-form').hidden">+ Neue Setlist</button>
  </div>

  <?php if ($flash): ?><div class="alert alert-success" style="margin-bottom:1rem;"><?= $flash ?></div><?php endif; ?>

  <!-- Create form -->
  <div id="create-form" hidden style="background:var(--card);border:1px solid var(--border);border-radius:12px;padding:1rem 1.25rem;margin-bottom:1.5rem;">
    <form method="post">
      <input type="hidden" name="action" value="create">
      <div style="display:flex;gap:0.75rem;flex-wrap:wrap;">
        <input type="text" name="name" placeholder="Setlist-Name …" required maxlength="100" style="flex:1;min-width:200px;padding:0.5rem 0.75rem;border:1px solid var(--border);border-radius:8px;background:var(--bg);color:var(--text);font-size:0.9rem;">
        <input type="text" name="description" placeholder="Beschreibung (optional)" maxlength="300" style="flex:2;min-width:200px;padding:0.5rem 0.75rem;border:1px solid var(--border);border-radius:8px;background:var(--bg);color:var(--text);font-size:0.9rem;">
        <button type="submit" class="btn btn-primary btn-sm">Erstellen</button>
      </div>
    </form>
  </div>

  <?php if ($detail): ?>
    <!-- Detail view -->
    <div style="margin-bottom:1.5rem;">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:0.75rem;margin-bottom:0.75rem;flex-wrap:wrap;">
        <div>
          <a href="/setlists.php" style="font-size:0.82rem;color:var(--text-3);">← Alle Setlists</a>
          <h2 style="margin:0.2rem 0 0;font-size:1.1rem;"><?= htmlspecialchars($detail['name']) ?></h2>
          <?php if (!empty($detail['description'])): ?><p style="margin:0.2rem 0 0;font-size:0.85rem;color:var(--text-2);"><?= htmlspecialchars($detail['description']) ?></p><?php endif; ?>
        </div>
        <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
          <?php if ($detail['share_token']): ?>
            <input type="text" readonly value="<?= rtrim((isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']?'https':'http').'://'.$_SERVER['HTTP_HOST'].'/setlist-share.php?token='.$detail['share_token']) ?>" style="font-size:0.78rem;padding:0.4rem 0.6rem;border:1px solid var(--border);border-radius:8px;background:var(--bg);color:var(--text);max-width:260px;" onclick="this.select()">
          <?php else: ?>
            <form method="post" style="display:inline;"><input type="hidden" name="action" value="share"><input type="hidden" name="setlist_id" value="<?= $detail['id'] ?>"><button type="submit" class="btn btn-ghost btn-sm">🔗 Teilen</button></form>
          <?php endif; ?>
          <form method="post" style="display:inline;"><input type="hidden" name="action" value="delete"><input type="hidden" name="setlist_id" value="<?= $detail['id'] ?>"><button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Setlist löschen?')">Löschen</button></form>
        </div>
      </div>
      <div style="display:flex;flex-direction:column;gap:0.4rem;">
        <?php foreach ($detailSongs as $i => $s): ?>
          <div style="display:flex;align-items:center;gap:0.65rem;padding:0.6rem 0.75rem;background:var(--card);border:1px solid var(--border);border-radius:10px;">
            <span style="font-size:0.78rem;color:var(--text-3);min-width:1.4rem;text-align:right;"><?= $i+1 ?></span>
            <?php if (!empty($s['cover_url'])): ?><img src="<?= htmlspecialchars($s['cover_url']) ?>" style="width:36px;height:36px;border-radius:5px;object-fit:cover;flex-shrink:0;" alt="" loading="lazy"><?php endif; ?>
            <div style="flex:1;min-width:0;">
              <a href="/detail.php?lyrics=<?= (int)$s['song_id'] ?>" style="font-weight:600;font-size:0.88rem;color:var(--text);"><?= htmlspecialchars($s['title']) ?></a>
              <div style="font-size:0.76rem;color:var(--text-3);"><?= htmlspecialchars($s['band_name'] ?? '–') ?></div>
            </div>
            <form method="post" style="margin:0;">
              <input type="hidden" name="action" value="remove_song">
              <input type="hidden" name="setlist_id" value="<?= $detail['id'] ?>">
              <input type="hidden" name="song_id" value="<?= (int)$s['song_id'] ?>">
              <button type="submit" style="background:none;border:none;color:var(--text-3);cursor:pointer;font-size:1rem;padding:0.2rem 0.4rem;" title="Entfernen">×</button>
            </form>
          </div>
        <?php endforeach; ?>
        <?php if (empty($detailSongs)): ?>
          <p style="color:var(--text-3);font-size:0.88rem;padding:0.5rem 0;">Noch keine Songs. Öffne einen Song und füge ihn über den ♡-Bereich hinzu.</p>
        <?php endif; ?>
      </div>
    </div>
  <?php else: ?>
    <!-- List view -->
    <?php if (empty($setlists)): ?>
      <p style="color:var(--text-3);font-size:0.9rem;">Noch keine Setlists. Erstelle deine erste oben!</p>
    <?php else: ?>
      <div style="display:flex;flex-direction:column;gap:0.5rem;">
        <?php foreach ($setlists as $sl): ?>
          <a href="/setlists.php?id=<?= (int)$sl['id'] ?>" style="display:flex;align-items:center;justify-content:space-between;padding:0.75rem 1rem;background:var(--card);border:1px solid var(--border);border-radius:12px;text-decoration:none;transition:border-color 0.15s;">
            <div>
              <div style="font-weight:600;font-size:0.92rem;color:var(--text);"><?= htmlspecialchars($sl['name']) ?></div>
              <div style="font-size:0.78rem;color:var(--text-3);"><?= (int)$sl['song_count'] ?> Songs · <?= date('d.m.Y', strtotime($sl['created_at'])) ?></div>
            </div>
            <?php if ($sl['share_token']): ?><span style="font-size:0.75rem;color:var(--text-3);">🔗 geteilt</span><?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</main>
<?php require_once "partials/footer.php"; ?>
