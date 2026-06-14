<?php
require_once "../protect.php";
require_once "../functions.php";

requireAdmin();
$conn = Database::getConnection();

// Find potential duplicates: songs with identical normalized title (trim, lowercase, strip punctuation)
// Group by soundex of title as a heuristic
$result = $conn->query(
    "SELECT l1.id AS id1, l1.title AS title1, b1.band_name AS band1,
            l2.id AS id2, l2.title AS title2, b2.band_name AS band2,
            SOUNDEX(l1.title) AS sx
     FROM singopkoelsch_lyrics l1
     JOIN singopkoelsch_lyrics l2 ON SOUNDEX(l1.title) = SOUNDEX(l2.title) AND l1.id < l2.id
     LEFT JOIN singopkoelsch_bands b1 ON b1.band_id = l1.band_id
     LEFT JOIN singopkoelsch_bands b2 ON b2.band_id = l2.band_id
     ORDER BY l1.title
     LIMIT 200"
);
$pairs = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

// Also find exact title matches (same title, same band)
$exactResult = $conn->query(
    "SELECT l1.id AS id1, l1.title AS title1, b1.band_name AS band1,
            l2.id AS id2, l2.title AS title2, b2.band_name AS band2
     FROM singopkoelsch_lyrics l1
     JOIN singopkoelsch_lyrics l2 ON LOWER(TRIM(l1.title)) = LOWER(TRIM(l2.title)) AND l1.id < l2.id
     LEFT JOIN singopkoelsch_bands b1 ON b1.band_id = l1.band_id
     LEFT JOIN singopkoelsch_bands b2 ON b2.band_id = l2.band_id
     ORDER BY l1.title"
);
$exact = $exactResult ? $exactResult->fetch_all(MYSQLI_ASSOC) : [];

$pageTitle = 'Duplikate – Sing op Kölsch';
require_once "../partials/head.php";
require_once "../partials/nav.php";
?>

<main class="content-wide">
  <div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;">
    <div>
      <h1>Duplikat-Erkennung</h1>
      <p style="color:var(--text-2);margin:0;">Songs mit identischem oder ähnlichem Titel</p>
    </div>
    <a href="/admin/" class="btn btn-secondary">← Dashboard</a>
  </div>

  <?php if ($exact): ?>
  <div style="margin-bottom:2rem;">
    <h2 style="font-size:1rem;margin:0 0 0.75rem;display:flex;align-items:center;gap:0.5rem;">
      <span style="background:#dc2626;color:#fff;border-radius:4px;padding:1px 6px;font-size:0.75rem;">EXAKT</span>
      Gleicher Titel (<?= count($exact) ?>)
    </h2>
    <div style="display:flex;flex-direction:column;gap:0.4rem;">
      <?php foreach ($exact as $p): ?>
        <div class="card" style="padding:0.75rem 1rem;display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
          <a href="/detail.php?lyrics=<?= $p['id1'] ?>" style="flex:1;min-width:140px;">
            <div style="font-weight:600;font-size:0.9rem;"><?= htmlspecialchars($p['title1']) ?></div>
            <div style="font-size:0.78rem;color:var(--text-3);"><?= htmlspecialchars($p['band1'] ?? '–') ?> · ID <?= $p['id1'] ?></div>
          </a>
          <span style="color:var(--text-3);">=</span>
          <a href="/detail.php?lyrics=<?= $p['id2'] ?>" style="flex:1;min-width:140px;">
            <div style="font-weight:600;font-size:0.9rem;"><?= htmlspecialchars($p['title2']) ?></div>
            <div style="font-size:0.78rem;color:var(--text-3);"><?= htmlspecialchars($p['band2'] ?? '–') ?> · ID <?= $p['id2'] ?></div>
          </a>
          <a href="/delete.php?lyrics=<?= $p['id2'] ?>"
             onclick="return confirm('Song #<?= $p['id2'] ?> wirklich löschen?')"
             class="btn btn-secondary" style="font-size:0.8rem;padding:0.3rem 0.6rem;color:#dc2626;">
            #<?= $p['id2'] ?> löschen
          </a>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <div>
    <h2 style="font-size:1rem;margin:0 0 0.75rem;display:flex;align-items:center;gap:0.5rem;">
      <span style="background:var(--border);border-radius:4px;padding:1px 6px;font-size:0.75rem;">ÄHNLICH</span>
      Klanglich ähnliche Titel (<?= count($pairs) ?>)
    </h2>
    <?php if ($pairs): ?>
      <div style="display:flex;flex-direction:column;gap:0.4rem;">
        <?php foreach ($pairs as $p): ?>
          <div class="card" style="padding:0.75rem 1rem;display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
            <a href="/detail.php?lyrics=<?= $p['id1'] ?>" style="flex:1;min-width:140px;">
              <div style="font-weight:600;font-size:0.9rem;"><?= htmlspecialchars($p['title1']) ?></div>
              <div style="font-size:0.78rem;color:var(--text-3);"><?= htmlspecialchars($p['band1'] ?? '–') ?> · ID <?= $p['id1'] ?></div>
            </a>
            <span style="color:var(--text-3);font-size:0.8rem;">≈</span>
            <a href="/detail.php?lyrics=<?= $p['id2'] ?>" style="flex:1;min-width:140px;">
              <div style="font-weight:600;font-size:0.9rem;"><?= htmlspecialchars($p['title2']) ?></div>
              <div style="font-size:0.78rem;color:var(--text-3);"><?= htmlspecialchars($p['band2'] ?? '–') ?> · ID <?= $p['id2'] ?></div>
            </a>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p style="color:var(--text-3);">Keine ähnlichen Titel gefunden.</p>
    <?php endif; ?>
  </div>
</main>

<?php require_once "../partials/footer.php"; ?>
