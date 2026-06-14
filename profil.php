<?php
// #46 Öffentliche Nutzer-Profile
require_once "protect.php";
require_once "functions.php";

Database::ensurePointsSystem();
$conn = Database::getConnection();

$userId = (int)($_GET['id'] ?? 0);
if (!$userId) { header('Location: /'); exit; }

$stmt = $conn->prepare("SELECT user_id, name, role, points, profile_picture FROM singopkoelsch_users WHERE user_id = ? LIMIT 1");
$stmt->bind_param("i", $userId); $stmt->execute();
$user = $stmt->get_result()->fetch_assoc(); $stmt->close();

if (!$user || $user['role'] === 'banned') {
    header('HTTP/1.1 404 Not Found');
    require __DIR__ . '/404.php'; exit;
}

$badges = Database::getUserBadges($userId);

$BADGE_LABELS = [
    'first_proposal'  => ['🌱', 'Erster Vorschlag', 'Hat den ersten Textvorschlag eingereicht'],
    'first_approved'  => ['✅', 'Erster Treffer',    'Erster genehmigter Vorschlag'],
    'contributor_10'  => ['🥈', 'Fleißige Biene',    '10 genehmigte Vorschläge'],
    'contributor_50'  => ['🏆', 'Kölsch-Experte',    '50 genehmigte Vorschläge'],
    'points_100'      => ['⭐', '100 Punkte',         '100 Punkte erreicht'],
    'points_500'      => ['🌟', '500 Punkte',         '500 Punkte erreicht'],
];

// Approved proposals
$_apr = $conn->query("SELECT cr.lyrics_id, l.title, b.band_name, cr.resolved_at FROM singopkoelsch_change_requests cr JOIN singopkoelsch_lyrics l ON l.id=cr.lyrics_id LEFT JOIN singopkoelsch_bands b ON b.band_id=l.band_id WHERE cr.user_id={$userId} AND cr.status='approved' ORDER BY cr.resolved_at DESC LIMIT 20");
$aproved = $_apr ? $_apr->fetch_all(MYSQLI_ASSOC) : [];

$pageTitle = htmlspecialchars($user['name']) . " – Profil – Sing op Kölsch";
$_cssVer = @filemtime(__DIR__ . '/style.css') ?: time();
require_once "partials/head.php";
require_once "partials/nav.php";
?>
<main class="content">
  <a href="/rangliste.php" class="round-icon-btn" style="margin-bottom:1.25rem;display:inline-flex;" aria-label="Zur Rangliste">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
  </a>

  <div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.75rem;">
    <?php if (!empty($user['profile_picture'])): ?>
      <img src="<?= htmlspecialchars($user['profile_picture']) ?>" style="width:64px;height:64px;border-radius:50%;object-fit:cover;flex-shrink:0;" alt="">
    <?php else: ?>
      <div style="width:64px;height:64px;border-radius:50%;background:rgba(220,38,38,0.15);display:flex;align-items:center;justify-content:center;font-size:1.6rem;font-weight:700;color:#ef4444;flex-shrink:0;"><?= mb_strtoupper(mb_substr($user['name'],0,1)) ?></div>
    <?php endif; ?>
    <div>
      <h1 style="margin:0;font-size:1.35rem;"><?= htmlspecialchars($user['name']) ?></h1>
      <p style="margin:0.2rem 0 0;font-size:0.82rem;color:var(--text-3);"><?= htmlspecialchars(ucfirst($user['role'] === 'admin' ? 'Admin' : ($user['role'] === 'trusted' ? 'Vertrauenswürdig' : 'Mitglied'))) ?></p>
    </div>
    <div style="margin-left:auto;text-align:right;">
      <div style="font-size:1.4rem;font-weight:700;color:#ef4444;"><?= number_format((int)$user['points']) ?></div>
      <div style="font-size:0.75rem;color:var(--text-3);">Punkte</div>
    </div>
  </div>

  <?php if (!empty($badges)): ?>
  <div style="margin-bottom:1.75rem;">
    <h3 style="font-size:0.8rem;font-weight:700;letter-spacing:0.07em;text-transform:uppercase;color:var(--text-3);margin:0 0 0.75rem;">Abzeichen</h3>
    <div style="display:flex;flex-wrap:wrap;gap:0.5rem;">
      <?php foreach ($badges as $b): ?>
        <?php if (isset($BADGE_LABELS[$b['badge_key']])): ?>
          <?php [$icon, $label, $desc] = $BADGE_LABELS[$b['badge_key']]; ?>
          <div title="<?= htmlspecialchars($desc) ?>" style="display:flex;align-items:center;gap:0.35rem;padding:0.3rem 0.7rem;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:999px;font-size:0.82rem;color:var(--text-2);"><?= $icon ?> <?= htmlspecialchars($label) ?></div>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if (!empty($aproved)): ?>
  <div>
    <h3 style="font-size:0.8rem;font-weight:700;letter-spacing:0.07em;text-transform:uppercase;color:var(--text-3);margin:0 0 0.75rem;">Genehmigte Vorschläge (<?= count($aproved) ?>)</h3>
    <div style="display:flex;flex-direction:column;gap:0.35rem;">
      <?php foreach ($aproved as $cr): ?>
        <a href="/detail.php?lyrics=<?= (int)$cr['lyrics_id'] ?>" style="display:flex;align-items:center;justify-content:space-between;padding:0.55rem 0.75rem;background:var(--card);border:1px solid var(--border);border-radius:9px;text-decoration:none;">
          <div>
            <div style="font-size:0.88rem;font-weight:600;color:var(--text);"><?= htmlspecialchars($cr['title']) ?></div>
            <div style="font-size:0.75rem;color:var(--text-3);"><?= htmlspecialchars($cr['band_name'] ?? '–') ?></div>
          </div>
          <span style="font-size:0.75rem;color:var(--text-3);"><?= $cr['resolved_at'] ? date('d.m.Y', strtotime($cr['resolved_at'])) : '' ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
</main>
<?php require_once "partials/footer.php"; ?>
