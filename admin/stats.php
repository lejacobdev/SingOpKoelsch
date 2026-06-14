<?php
require_once "../protect.php";
require_once "../functions.php";

requireAdmin();
$conn = Database::getConnection();

// Ensure views table exists
$conn->query("CREATE TABLE IF NOT EXISTS singopkoelsch_views (id BIGINT AUTO_INCREMENT PRIMARY KEY, song_id INT NOT NULL, viewed_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX idx_song (song_id), INDEX idx_time (viewed_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Total views
$totalViews = (int)$conn->query("SELECT COUNT(*) FROM singopkoelsch_views")->fetch_row()[0];
$viewsToday = (int)$conn->query("SELECT COUNT(*) FROM singopkoelsch_views WHERE viewed_at >= CURDATE()")->fetch_row()[0];
$viewsWeek  = (int)$conn->query("SELECT COUNT(*) FROM singopkoelsch_views WHERE viewed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetch_row()[0];

// Top songs all time
$topAll = $conn->query(
    "SELECT l.id, l.title, b.band_name, COUNT(*) AS views
     FROM singopkoelsch_views v
     JOIN singopkoelsch_lyrics l ON l.id = v.song_id
     LEFT JOIN singopkoelsch_bands b ON b.band_id = l.band_id
     GROUP BY v.song_id ORDER BY views DESC LIMIT 20"
)->fetch_all(MYSQLI_ASSOC);

// Trending this week
$trending = $conn->query(
    "SELECT l.id, l.title, b.band_name, COUNT(*) AS views
     FROM singopkoelsch_views v
     JOIN singopkoelsch_lyrics l ON l.id = v.song_id
     LEFT JOIN singopkoelsch_bands b ON b.band_id = l.band_id
     WHERE v.viewed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
     GROUP BY v.song_id ORDER BY views DESC LIMIT 10"
)->fetch_all(MYSQLI_ASSOC);

// Views per day (last 30 days)
$daily = $conn->query(
    "SELECT DATE(viewed_at) AS day, COUNT(*) AS views
     FROM singopkoelsch_views
     WHERE viewed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
     GROUP BY day ORDER BY day ASC"
)->fetch_all(MYSQLI_ASSOC);

$pageTitle = 'Statistiken – Sing op Kölsch';
require_once "../partials/head.php";
require_once "../partials/nav.php";
?>

<main class="content-wide">
  <div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;">
    <div>
      <h1>Statistiken</h1>
      <p style="color:var(--text-2);margin:0;">Song-Aufrufe und Trends</p>
    </div>
    <a href="/admin/" class="btn btn-secondary">← Dashboard</a>
  </div>

  <!-- Summary cards -->
  <div class="stats-grid" style="margin-bottom:2rem;">
    <div class="stat-card blue">
      <div class="stat-icon">👁️</div>
      <div class="stat-value"><?= number_format($totalViews) ?></div>
      <div class="stat-label">Aufrufe gesamt</div>
    </div>
    <div class="stat-card green">
      <div class="stat-icon">📅</div>
      <div class="stat-value"><?= number_format($viewsToday) ?></div>
      <div class="stat-label">Heute</div>
    </div>
    <div class="stat-card purple">
      <div class="stat-icon">📈</div>
      <div class="stat-value"><?= number_format($viewsWeek) ?></div>
      <div class="stat-label">Diese Woche</div>
    </div>
  </div>

  <!-- Daily chart (simple CSS bars) -->
  <?php if ($daily): ?>
  <div class="card" style="padding:1.25rem;margin-bottom:2rem;">
    <h2 style="font-size:1rem;margin:0 0 1rem;">Aufrufe letzte 30 Tage</h2>
    <?php $maxDay = max(array_column($daily, 'views') ?: [1]); ?>
    <div style="display:flex;align-items:flex-end;gap:3px;height:80px;">
      <?php foreach ($daily as $d): ?>
        <?php $pct = round($d['views'] / $maxDay * 100); ?>
        <div title="<?= htmlspecialchars($d['day']) ?>: <?= $d['views'] ?>" style="flex:1;background:var(--primary);opacity:0.8;height:<?= $pct ?>%;border-radius:3px 3px 0 0;min-height:2px;"></div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;align-items:start;">

    <!-- Trending this week -->
    <div class="card" style="padding:1.25rem;">
      <h2 style="font-size:1rem;margin:0 0 1rem;">🔥 Trending diese Woche</h2>
      <?php if ($trending): ?>
        <?php $maxT = max(array_column($trending, 'views') ?: [1]); ?>
        <ol style="list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:0.5rem;">
          <?php foreach ($trending as $i => $s): ?>
            <li style="display:flex;align-items:center;gap:0.6rem;">
              <span style="font-size:0.8rem;color:var(--text-3);min-width:1.2rem;text-align:right;"><?= $i+1 ?></span>
              <div style="flex:1;min-width:0;">
                <div style="display:flex;justify-content:space-between;margin-bottom:3px;">
                  <a href="/detail.php?lyrics=<?= $s['id'] ?>" style="font-size:0.88rem;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:70%"><?= htmlspecialchars($s['title']) ?></a>
                  <span style="font-size:0.8rem;color:var(--text-3);"><?= $s['views'] ?></span>
                </div>
                <div style="height:4px;background:var(--border);border-radius:2px;">
                  <div style="height:4px;background:var(--primary);border-radius:2px;width:<?= round($s['views']/$maxT*100) ?>%"></div>
                </div>
              </div>
            </li>
          <?php endforeach; ?>
        </ol>
      <?php else: ?>
        <p style="color:var(--text-3);font-size:0.9rem;">Noch keine Daten.</p>
      <?php endif; ?>
    </div>

    <!-- Top songs all time -->
    <div class="card" style="padding:1.25rem;">
      <h2 style="font-size:1rem;margin:0 0 1rem;">🏆 Top Songs gesamt</h2>
      <?php if ($topAll): ?>
        <?php $maxA = max(array_column($topAll, 'views') ?: [1]); ?>
        <ol style="list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:0.5rem;">
          <?php foreach ($topAll as $i => $s): ?>
            <li style="display:flex;align-items:center;gap:0.6rem;">
              <span style="font-size:0.8rem;color:var(--text-3);min-width:1.2rem;text-align:right;"><?= $i+1 ?></span>
              <div style="flex:1;min-width:0;">
                <div style="display:flex;justify-content:space-between;margin-bottom:3px;">
                  <a href="/detail.php?lyrics=<?= $s['id'] ?>" style="font-size:0.88rem;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:70%"><?= htmlspecialchars($s['title']) ?></a>
                  <span style="font-size:0.8rem;color:var(--text-3);"><?= $s['views'] ?></span>
                </div>
                <div style="height:4px;background:var(--border);border-radius:2px;">
                  <div style="height:4px;background:var(--primary);border-radius:2px;width:<?= round($s['views']/$maxA*100) ?>%"></div>
                </div>
              </div>
            </li>
          <?php endforeach; ?>
        </ol>
      <?php else: ?>
        <p style="color:var(--text-3);font-size:0.9rem;">Noch keine Daten.</p>
      <?php endif; ?>
    </div>

  </div>
</main>

<?php require_once "../partials/footer.php"; ?>
