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

// #91 DAU/MAU (view-based proxy)
$dauSongs  = (int)$conn->query("SELECT COUNT(DISTINCT song_id) FROM singopkoelsch_views WHERE viewed_at >= CURDATE()")->fetch_row()[0];
$mauSongs  = (int)$conn->query("SELECT COUNT(DISTINCT song_id) FROM singopkoelsch_views WHERE viewed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetch_row()[0];
$totalUsers    = (int)$conn->query("SELECT COUNT(*) FROM singopkoelsch_users")->fetch_row()[0];
// Add created_at to users if missing (schema migration)
$_hasCat = $conn->query("SHOW COLUMNS FROM singopkoelsch_users LIKE 'created_at'");
if ($_hasCat && $_hasCat->num_rows === 0) {
    $conn->query("ALTER TABLE singopkoelsch_users ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP");
}
$_catExists = ($_hasCat && $_hasCat->num_rows > 0) || $conn->query("SHOW COLUMNS FROM singopkoelsch_users LIKE 'created_at'")->num_rows > 0;
$newUsersMonth = 0;
$usersWeek     = [];
if ($_catExists) {
    $newUsersMonth = (int)$conn->query("SELECT COUNT(*) FROM singopkoelsch_users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetch_row()[0];
    $usersWeek = $conn->query("SELECT DATE(created_at) AS day, COUNT(*) AS cnt FROM singopkoelsch_users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY day ORDER BY day ASC")->fetch_all(MYSQLI_ASSOC);
}
$pendingProposals = (int)$conn->query("SELECT COUNT(*) FROM singopkoelsch_change_requests WHERE status='pending'")->fetch_row()[0];
$approvedTotal    = (int)$conn->query("SELECT COUNT(*) FROM singopkoelsch_change_requests WHERE status='approved'")->fetch_row()[0];
$convRate = $totalUsers > 0 ? round($approvedTotal / $totalUsers * 100, 1) : 0;

// #92 Beliebteste Bands nach Aufrufen
$topBands = $conn->query(
    "SELECT b.band_id, b.band_name, COUNT(*) AS views
     FROM singopkoelsch_views v
     JOIN singopkoelsch_lyrics l ON l.id = v.song_id
     JOIN singopkoelsch_bands b ON b.band_id = l.band_id
     GROUP BY l.band_id ORDER BY views DESC LIMIT 10"
)->fetch_all(MYSQLI_ASSOC);

// #29 Content Quality Score — songs lacking completeness
$qualLow = $conn->query(
    "SELECT id, title,
        (CASE WHEN lyrics IS NOT NULL AND lyrics != '' THEN 1 ELSE 0 END +
         CASE WHEN cover_url IS NOT NULL AND cover_url != '' THEN 1 ELSE 0 END +
         CASE WHEN album IS NOT NULL AND album != '' THEN 1 ELSE 0 END +
         CASE WHEN release_year IS NOT NULL AND release_year != '' THEN 1 ELSE 0 END +
         CASE WHEN spotify_link IS NOT NULL AND spotify_link != '' THEN 1 ELSE 0 END) AS score
     FROM singopkoelsch_lyrics
     ORDER BY score ASC, id ASC
     LIMIT 20"
)->fetch_all(MYSQLI_ASSOC);

$pageTitle = e('admin.stats.title') . ' – Sing op Kölsch';
require_once "../partials/head.php";
require_once "../partials/nav.php";
?>

<main class="content-wide">
  <div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;">
    <div>
      <h1><?= e('admin.stats.title') ?></h1>
      <p style="color:var(--text-2);margin:0;"><?= e('admin.stats.subtitle') ?></p>
    </div>
    <a href="/admin/" class="btn btn-secondary">← <?= e('admin.dashboard') ?></a>
  </div>

  <!-- Summary cards -->
  <div class="stats-grid" style="margin-bottom:2rem;">
    <div class="stat-card blue">
      <div class="stat-icon">👁️</div>
      <div class="stat-value"><?= number_format($totalViews) ?></div>
      <div class="stat-label"><?= e('admin.stats.views_total') ?></div>
    </div>
    <div class="stat-card green">
      <div class="stat-icon">📅</div>
      <div class="stat-value"><?= number_format($viewsToday) ?></div>
      <div class="stat-label"><?= e('admin.stats.today') ?></div>
    </div>
    <div class="stat-card purple">
      <div class="stat-icon">📈</div>
      <div class="stat-value"><?= number_format($viewsWeek) ?></div>
      <div class="stat-label"><?= e('admin.stats.this_week') ?></div>
    </div>
    <div class="stat-card" style="border-color:rgba(251,146,60,0.4);background:rgba(251,146,60,0.07);">
      <div class="stat-icon">🔥</div>
      <div class="stat-value"><?= number_format($dauSongs) ?></div>
      <div class="stat-label"><?= e('admin.stats.dau') ?></div>
    </div>
    <div class="stat-card" style="border-color:rgba(99,102,241,0.4);background:rgba(99,102,241,0.07);">
      <div class="stat-icon">📊</div>
      <div class="stat-value"><?= number_format($mauSongs) ?></div>
      <div class="stat-label"><?= e('admin.stats.mau') ?></div>
    </div>
    <div class="stat-card" style="border-color:rgba(34,197,94,0.4);background:rgba(34,197,94,0.07);">
      <div class="stat-icon">👥</div>
      <div class="stat-value"><?= number_format($newUsersMonth) ?></div>
      <div class="stat-label"><?= e('admin.stats.new_users_30') ?></div>
    </div>
    <div class="stat-card" style="border-color:rgba(239,68,68,0.4);background:rgba(239,68,68,0.07);">
      <div class="stat-icon">✅</div>
      <div class="stat-value"><?= $convRate ?>%</div>
      <div class="stat-label"><?= e('admin.stats.conv_rate') ?></div>
    </div>
  </div>

  <!-- Daily chart (simple CSS bars) -->
  <?php if ($daily): ?>
  <div class="card" style="padding:1.25rem;margin-bottom:2rem;">
    <h2 style="font-size:1rem;margin:0 0 1rem;"><?= e('admin.stats.chart_30d') ?></h2>
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
      <h2 style="font-size:1rem;margin:0 0 1rem;"><?= e('admin.stats.trending') ?></h2>
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
        <p style="color:var(--text-3);font-size:0.9rem;"><?= e('admin.stats.no_data') ?></p>
      <?php endif; ?>
    </div>

    <!-- Top songs all time -->
    <div class="card" style="padding:1.25rem;">
      <h2 style="font-size:1rem;margin:0 0 1rem;"><?= e('admin.stats.top_all') ?></h2>
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
        <p style="color:var(--text-3);font-size:0.9rem;"><?= e('admin.stats.no_data') ?></p>
      <?php endif; ?>
    </div>

  </div>

  <!-- #92 Beliebteste Bands -->
  <div class="card" style="padding:1.25rem;margin-top:1.5rem;">
    <h2 style="font-size:1rem;margin:0 0 1rem;"><?= e('admin.stats.top_bands') ?></h2>
    <?php if ($topBands): ?>
      <?php $maxB = max(array_column($topBands, 'views') ?: [1]); ?>
      <ol style="list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:0.5rem;">
        <?php foreach ($topBands as $i => $b): ?>
          <li style="display:flex;align-items:center;gap:0.6rem;">
            <span style="font-size:0.8rem;color:var(--text-3);min-width:1.2rem;text-align:right;"><?= $i+1 ?></span>
            <div style="flex:1;min-width:0;">
              <div style="display:flex;justify-content:space-between;margin-bottom:3px;">
                <span style="font-size:0.88rem;font-weight:600;color:var(--text);"><?= htmlspecialchars($b['band_name']) ?></span>
                <span style="font-size:0.8rem;color:var(--text-3);"><?= $b['views'] ?></span>
              </div>
              <div style="height:4px;background:var(--border);border-radius:2px;">
                <div style="height:4px;background:#f59e0b;border-radius:2px;width:<?= round($b['views']/$maxB*100) ?>%"></div>
              </div>
            </div>
          </li>
        <?php endforeach; ?>
      </ol>
    <?php else: ?><p style="color:var(--text-3);font-size:0.9rem;"><?= e('admin.stats.no_data') ?></p><?php endif; ?>
  </div>

  <!-- #29 Content Quality Score -->
  <div class="card" style="padding:1.25rem;margin-top:1.5rem;">
    <h2 style="font-size:1rem;margin:0 0 0.35rem;"><?= e('admin.stats.quality_title') ?></h2>
    <p style="font-size:0.8rem;color:var(--text-3);margin:0 0 1rem;"><?= e('admin.stats.quality_hint') ?></p>
    <table class="data-table" style="font-size:0.85rem;">
      <thead><tr><th><?= e('admin.stats.col_song') ?></th><th><?= e('admin.stats.col_score') ?></th><th></th></tr></thead>
      <tbody>
        <?php foreach ($qualLow as $q): ?>
          <tr>
            <td><?= htmlspecialchars($q['title']) ?></td>
            <td>
              <?php for ($s=0;$s<5;$s++): ?>
                <span style="color:<?= $s<(int)$q['score'] ? '#22c55e' : 'var(--border)' ?>;">●</span>
              <?php endfor; ?>
              <span style="color:var(--text-3);margin-left:0.35rem;"><?= (int)$q['score'] ?>/5</span>
            </td>
            <td><a href="/detail.php?lyrics=<?= (int)$q['id'] ?>" style="color:#ef4444;font-size:0.8rem;"><?= e('admin.stats.edit_link') ?></a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- #93 Nutzer-Registrierungen 30 Tage -->
  <?php if ($usersWeek): ?>
  <div class="card" style="padding:1.25rem;margin-top:1.5rem;">
    <h2 style="font-size:1rem;margin:0 0 1rem;"><?= e('admin.stats.users_chart', ['n' => $totalUsers]) ?></h2>
    <?php $maxU = max(array_column($usersWeek, 'cnt') ?: [1]); ?>
    <div style="display:flex;align-items:flex-end;gap:3px;height:60px;">
      <?php foreach ($usersWeek as $d): ?>
        <?php $pct = round($d['cnt'] / $maxU * 100); ?>
        <div title="<?= htmlspecialchars($d['day']) ?>: <?= $d['cnt'] ?>" style="flex:1;background:#22c55e;opacity:0.7;height:<?= max($pct,2) ?>%;border-radius:3px 3px 0 0;min-height:2px;"></div>
      <?php endforeach; ?>
    </div>
    <p style="font-size:0.75rem;color:var(--text-3);margin:0.5rem 0 0;"><?= e('admin.stats.summary', ['new' => $newUsersMonth, 'pending' => $pendingProposals, 'approved' => $approvedTotal]) ?></p>
  </div>
  <?php endif; ?>
</main>

<?php require_once "../partials/footer.php"; ?>
