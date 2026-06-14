<?php
require_once "../protect.php";
require_once "../functions.php";

requireAdmin();

Database::getConnection();
Database::ensurePreferencesTable();

$stats   = Database::getStats();
$bands   = Database::getTopBands(10);
$changes = Database::getRecentChangeRequests(8);
$users   = Database::getAllUsers();

$maxSongs = max(1, max(array_column($bands, 'song_count') ?: [1]));

$pageTitle = 'Admin Dashboard – Sing op Kölsch';
require_once "../partials/head.php";
require_once "../partials/nav.php";
?>

<style>
/* Admin-Dashboard: Mobile-Anpassungen */
@media (max-width: 720px) {
  main.content-wide { padding: 1.2rem 0.8rem 3rem; }
  .admin-header { flex-direction: column; align-items: stretch !important; }
  .admin-header .gap-row { display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; width: 100%; }
  .admin-header .gap-row .btn { width: 100%; justify-content: center; }
  .stats-grid { gap: 0.7rem; }
  .stat-card { padding: 0.85rem 0.9rem; }
  .stat-value { font-size: 1.45rem; }
  .stat-label { font-size: 0.78rem; }
  .stat-icon { font-size: 1.4rem; }
  .alert.alert-warn { flex-direction: column; align-items: stretch !important; }
  .alert.alert-warn > .btn { width: 100%; justify-content: center; }
  /* Recent change-requests Tabelle: zum Karten-Layout machen */
  .cr-table thead { display: none; }
  .cr-table, .cr-table tbody, .cr-table tr, .cr-table td { display: block; width: 100%; }
  .cr-table tr {
    background: var(--card); border: 1px solid var(--border);
    border-radius: var(--radius-md); padding: 0.7rem 0.9rem; margin-bottom: 0.6rem;
  }
  .cr-table td { border: none; padding: 0.18rem 0; }
  .cr-table td[data-label]::before {
    content: attr(data-label) ":";
    display: inline-block; min-width: 5.2em;
    font-weight: 600; color: var(--text-3); font-size: 0.82em; margin-right: 0.4em;
  }
  .cr-table td.cr-actions { padding-top: 0.5rem; }
  .cr-table td.cr-actions::before { display: none; }
  .cr-table td.cr-actions .gap-row { display: grid; grid-template-columns: 1fr 1fr; gap: 0.4rem; }
  .cr-table td.cr-actions .gap-row > * { width: 100%; text-align: center; }
  /* Users-Tabelle: ebenfalls Karten */
  .users-table thead { display: none; }
  .users-table, .users-table tbody, .users-table tr, .users-table td { display: block; width: 100%; }
  .users-table tr {
    background: var(--card); border: 1px solid var(--border);
    border-radius: var(--radius-md); padding: 0.7rem 0.9rem; margin-bottom: 0.6rem;
  }
  .users-table td { border: none; padding: 0.18rem 0; }
  .users-table td[data-label]::before {
    content: attr(data-label) ":";
    display: inline-block; min-width: 5.2em;
    font-weight: 600; color: var(--text-3); font-size: 0.82em; margin-right: 0.4em;
  }
  .bar-row { grid-template-columns: 80px 1fr 28px !important; }
  .bar-label { font-size: 0.85em; }
}
@media (max-width: 440px) {
  .admin-header .gap-row { grid-template-columns: 1fr; }
}
</style>

<main class="content-wide">
  <div class="page-header admin-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;">
    <div>
      <h1><?= htmlspecialchars(t('admin.dashboard')) ?></h1>
      <p><?= htmlspecialchars(t('admin.subtitle')) ?></p>
    </div>
    <div class="gap-row">
      <a href="/add.php" class="btn btn-primary">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        <?= htmlspecialchars(t('nav.add_song')) ?>
      </a>
      <a href="/admin/proposals.php" class="btn btn-secondary"><?= htmlspecialchars(t('prop.title')) ?></a>
      <a href="/admin/users.php" class="btn btn-secondary"><?= htmlspecialchars(t('admin.manage_users')) ?></a>
      <a href="/admin/invites.php" class="btn btn-secondary">Einladungscodes</a>
      <a href="/admin/clean_bands.php" class="btn btn-secondary"><?= htmlspecialchars(t('admin.clean_bands')) ?></a>
      <a href="/admin/stats.php" class="btn btn-secondary">📈 Statistiken</a>
      <a href="/admin/duplicates.php" class="btn btn-secondary">🔍 Duplikate</a>
      <a href="/admin/cover-search.php" class="btn btn-secondary">🎨 Cover-Suche</a>
      <a href="/admin/bulk-actions.php" class="btn btn-secondary">⚡ Bulk-Aktionen</a>
      <a href="/liederbuch.php" class="btn btn-secondary"><?= htmlspecialchars(t('nav.songbook')) ?></a>
    </div>
  </div>

  <!-- Stats row -->
  <div class="stats-grid">
    <div class="stat-card blue">
      <div class="stat-icon">🎵</div>
      <div class="stat-value"><?= $stats['total_lyrics'] ?></div>
      <div class="stat-label"><?= htmlspecialchars(t('admin.total_lyrics')) ?></div>
    </div>
    <div class="stat-card green">
      <div class="stat-icon">👥</div>
      <div class="stat-value"><?= $stats['total_users'] ?></div>
      <div class="stat-label"><?= htmlspecialchars(t('admin.users_total')) ?></div>
    </div>
    <div class="stat-card amber">
      <div class="stat-icon">⏳</div>
      <div class="stat-value"><?= $stats['pending_changes'] ?></div>
      <div class="stat-label"><?= htmlspecialchars(t('admin.pending_changes')) ?></div>
    </div>
    <div class="stat-card purple">
      <div class="stat-icon">🎸</div>
      <div class="stat-value"><?= $stats['total_bands'] ?></div>
      <div class="stat-label"><?= htmlspecialchars(t('admin.bands_total')) ?></div>
    </div>
  </div>

  <!-- Change request summary -->
  <?php if ($stats['pending_changes'] > 0): ?>
  <div class="alert alert-warn mb-3" style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
    <span><?= t('admin.warn_pending', ['n' => '<strong>' . $stats['pending_changes'] . '</strong>']) ?></span>
    <a href="/admin/proposals.php" class="btn btn-sm btn-secondary"><?= htmlspecialchars(t('admin.open_proposals')) ?></a>
  </div>
  <?php endif; ?>

  <div class="two-col">

    <!-- Top bands -->
    <div class="card">
      <div class="card-header"><?= htmlspecialchars(t('admin.top_bands')) ?></div>
      <div class="card-body">
        <?php if (empty($bands)): ?>
          <p class="text-muted text-sm"><?= htmlspecialchars(t('admin.no_data')) ?></p>
        <?php else: ?>
          <div class="bar-chart">
            <?php foreach ($bands as $b): ?>
              <?php $pct = round(($b['song_count'] / $maxSongs) * 100); ?>
              <div class="bar-row">
                <div class="bar-label" title="<?= htmlspecialchars($b['band_name']) ?>"><?= htmlspecialchars($b['band_name']) ?></div>
                <div class="bar-track">
                  <div class="bar-fill" style="width:<?= $pct ?>%"></div>
                </div>
                <div class="bar-count"><?= $b['song_count'] ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Recent change requests -->
    <div class="card">
      <div class="card-header">
        <a href="/admin/proposals.php" style="color:inherit;text-decoration:none;">
          <?= htmlspecialchars(t('admin.recent_proposals')) ?>
        </a>
        <span class="badge badge-gray"><?= t('admin.total_count', ['n' => $stats['approved_changes'] + $stats['rejected_changes'] + $stats['pending_changes']]) ?></span>
      </div>
      <div style="overflow-x:auto;">
        <?php if (empty($changes)): ?>
          <div class="card-body"><p class="text-muted text-sm"><?= htmlspecialchars(t('admin.no_proposals_yet')) ?></p></div>
        <?php else: ?>
          <table class="data-table cr-table">
            <thead>
              <tr>
                <th><?= htmlspecialchars(t('admin.col_song')) ?></th>
                <th><?= htmlspecialchars(t('admin.col_user')) ?></th>
                <th><?= htmlspecialchars(t('admin.col_status')) ?></th>
                <th><?= htmlspecialchars(t('admin.col_date')) ?></th>
                <th><?= htmlspecialchars(t('admin.col_action')) ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($changes as $cr): ?>
                <tr>
                  <td data-label="<?= htmlspecialchars(t('admin.col_song')) ?>">
                    <a href="/detail.php?lyrics=<?= (int)$cr['lyrics_id'] ?>" style="color:var(--text);font-weight:500;">
                      <?= htmlspecialchars($cr['title']) ?>
                    </a>
                  </td>
                  <td data-label="<?= htmlspecialchars(t('admin.col_user')) ?>"><?= htmlspecialchars($cr['user_name']) ?></td>
                  <td data-label="<?= htmlspecialchars(t('admin.col_status')) ?>">
                    <?php
                      if ($cr['status'] === 'approved')     { $badgeClass = 'badge-green';  $label = t('admin.status_approved'); }
                      elseif ($cr['status'] === 'rejected') { $badgeClass = 'badge-red';    $label = t('admin.status_rejected'); }
                      else                                  { $badgeClass = 'badge-yellow'; $label = t('admin.status_pending'); }
                    ?>
                    <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($label) ?></span>
                  </td>
                  <td data-label="<?= htmlspecialchars(t('admin.col_date')) ?>" class="text-muted text-sm"><?= date('d.m.Y', strtotime($cr['created_at'])) ?></td>
                  <td class="cr-actions">
                    <?php if ($cr['status'] === 'pending'): ?>
                      <div class="gap-row" style="gap:0.3rem;">
                        <a href="/admin/approve_change.php?id=<?= (int)$cr['id'] ?>&lyric_id=<?= (int)$cr['lyrics_id'] ?>"
                           class="btn btn-sm btn-primary"><?= htmlspecialchars(t('btn.approve')) ?></a>
                        <a href="/admin/reject_change.php?id=<?= (int)$cr['id'] ?>&lyric_id=<?= (int)$cr['lyrics_id'] ?>"
                           class="btn btn-sm btn-danger"><?= htmlspecialchars(t('btn.reject')) ?></a>
                      </div>
                    <?php else: ?>
                      <a href="/detail.php?lyrics=<?= (int)$cr['lyrics_id'] ?>" class="btn btn-sm btn-ghost"><?= htmlspecialchars(t('admin.open')) ?></a>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>

  </div>

  <!-- Users table -->
  <div class="card mt-3">
    <div class="card-header">
      <?= htmlspecialchars(t('admin.users_overview')) ?>
      <a href="/admin/users.php" class="btn btn-sm btn-ghost"><?= htmlspecialchars(t('admin.manage_all')) ?></a>
    </div>
    <div style="overflow-x:auto;">
      <table class="data-table users-table">
        <thead>
          <tr>
            <th><?= htmlspecialchars(t('admin.col_name')) ?></th>
            <th><?= htmlspecialchars(t('admin.col_email')) ?></th>
            <th><?= htmlspecialchars(t('admin.col_verified')) ?></th>
            <th><?= htmlspecialchars(t('admin.role')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($users as $u): ?>
            <tr>
              <td data-label="<?= htmlspecialchars(t('admin.col_name')) ?>"><?= htmlspecialchars($u['name']) ?></td>
              <td data-label="<?= htmlspecialchars(t('admin.col_email')) ?>" class="text-muted"><?= htmlspecialchars($u['email']) ?></td>
              <td data-label="<?= htmlspecialchars(t('admin.col_verified')) ?>">
                <span class="badge <?= $u['email_verified'] ? 'badge-green' : 'badge-yellow' ?>">
                  <?= htmlspecialchars($u['email_verified'] ? t('admin.yes') : t('admin.no')) ?>
                </span>
              </td>
              <td data-label="<?= htmlspecialchars(t('admin.role')) ?>">
                <?php
                  $r = $u['role'] ?? 'user';
                  $cls = $r === 'admin' ? 'badge-blue' : ($r === 'trusted' ? 'badge-green' : 'badge-gray');
                  $lbl = $r === 'trusted' ? t('admin.role_trusted') : $r;
                ?>
                <span class="badge <?= $cls ?>"><?= htmlspecialchars($lbl) ?></span>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

</main>

<?php require_once "../partials/footer.php"; ?>
