<?php
// #24 Automatische Cover-Suche via Spotify-API (Admin)
require_once "../protect.php";
require_once "../functions.php";

requireAdmin();
$conn = Database::getConnection();

$msg = '';
$results = [];

// Search for songs without cover
$songId = (int)($_GET['song'] ?? 0);
$song = $songId ? Database::queryDataById($songId) : null;

// Fetch songs without cover (for the list)
$noCover = $conn->query(
    "SELECT l.id, l.title, b.band_name, l.album, l.release_year
     FROM singopkoelsch_lyrics l
     LEFT JOIN singopkoelsch_bands b ON b.band_id=l.band_id
     WHERE (l.cover_url IS NULL OR l.cover_url='') AND l.lyrics IS NOT NULL AND l.lyrics != ''
     ORDER BY b.band_name ASC, l.title ASC
     LIMIT 80"
)->fetch_all(MYSQLI_ASSOC);

// Apply cover URL
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cover_url'], $_POST['song_id'])) {
    $cid  = (int)$_POST['song_id'];
    $curl = trim($_POST['cover_url']);
    if ($cid && $curl) {
        $stmt = $conn->prepare("UPDATE singopkoelsch_lyrics SET cover_url = ? WHERE id = ?");
        $stmt->bind_param("si", $curl, $cid); $stmt->execute(); $stmt->close();
        $msg = "Cover für Song #$cid gesetzt.";
    }
}

// Spotify search (unauthenticated: use iTunes Search API as fallback — free, no auth needed)
$searchResults = [];
if ($song) {
    $searchQ = urlencode(trim($song['title'] . ' ' . ($conn->query("SELECT band_name FROM singopkoelsch_bands WHERE band_id={$song['band_id']}")->fetch_assoc()['band_name'] ?? '')));
    $apiUrl  = "https://itunes.apple.com/search?term=$searchQ&media=music&entity=song&limit=6&country=de";
    $ctx = stream_context_create(['http' => ['timeout' => 5, 'user_agent' => 'SingOpKoelsch/3.0']]);
    $raw = @file_get_contents($apiUrl, false, $ctx);
    if ($raw) {
        $data = json_decode($raw, true);
        foreach ($data['results'] ?? [] as $r) {
            $searchResults[] = [
                'cover'  => str_replace('100x100', '200x200', $r['artworkUrl100'] ?? ''),
                'title'  => $r['trackName'] ?? '',
                'artist' => $r['artistName'] ?? '',
                'album'  => $r['collectionName'] ?? '',
            ];
        }
    }
}

$pageTitle = 'Cover-Suche – Admin – Sing op Kölsch';
require_once "../partials/head.php";
require_once "../partials/nav.php";
?>
<main class="content-wide">
  <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:1.5rem;flex-wrap:wrap;">
    <a href="/admin/" class="btn btn-ghost btn-sm">← Dashboard</a>
    <h1 style="margin:0;">🎨 Automatische Cover-Suche</h1>
  </div>
  <?php if ($msg): ?><div class="alert alert-success" style="margin-bottom:1rem;"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

  <?php if ($song): ?>
    <div style="margin-bottom:1.5rem;padding:1rem;background:var(--card);border:1px solid var(--border);border-radius:12px;">
      <h2 style="margin:0 0 0.5rem;font-size:1rem;">Ergebnisse für: <em><?= htmlspecialchars($song['title']) ?></em></h2>
      <a href="cover-search.php" style="font-size:0.82rem;color:var(--text-3);">← Zurück zur Liste</a>
      <?php if (!empty($searchResults)): ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:0.75rem;margin-top:1rem;">
          <?php foreach ($searchResults as $r): ?>
            <?php if (!$r['cover']) continue; ?>
            <div style="border:1px solid var(--border);border-radius:10px;overflow:hidden;background:var(--bg-alt);">
              <img src="<?= htmlspecialchars($r['cover']) ?>" style="width:100%;aspect-ratio:1;object-fit:cover;" alt="">
              <div style="padding:0.5rem 0.6rem;font-size:0.78rem;color:var(--text-2);">
                <strong><?= htmlspecialchars($r['title']) ?></strong><br>
                <span style="color:var(--text-3);"><?= htmlspecialchars($r['artist']) ?></span>
              </div>
              <form method="post" style="padding:0 0.6rem 0.6rem;">
                <input type="hidden" name="song_id" value="<?= (int)$songId ?>">
                <input type="hidden" name="cover_url" value="<?= htmlspecialchars($r['cover']) ?>">
                <button type="submit" class="btn btn-primary btn-sm" style="width:100%;">Übernehmen</button>
              </form>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p style="color:var(--text-3);margin-top:0.75rem;">Keine Ergebnisse gefunden.</p>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="card" style="padding:1.25rem;">
    <h2 style="font-size:1rem;margin:0 0 0.75rem;">Songs ohne Cover (<?= count($noCover) ?>)</h2>
    <table class="data-table" style="font-size:0.85rem;">
      <thead><tr><th>Song</th><th>Band</th><th>Album</th><th>Jahr</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($noCover as $s): ?>
          <tr>
            <td><a href="/detail.php?lyrics=<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['title']) ?></a></td>
            <td style="color:var(--text-3);"><?= htmlspecialchars($s['band_name'] ?? '–') ?></td>
            <td style="color:var(--text-3);"><?= htmlspecialchars($s['album'] ?? '–') ?></td>
            <td style="color:var(--text-3);"><?= htmlspecialchars($s['release_year'] ?? '–') ?></td>
            <td><a href="?song=<?= (int)$s['id'] ?>" class="btn btn-ghost btn-sm">Suchen</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</main>
<?php require_once "../partials/footer.php"; ?>
