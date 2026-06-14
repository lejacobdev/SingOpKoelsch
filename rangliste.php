<?php
// #49 Top-Contributor-Rangliste
require_once "protect.php";
require_once "functions.php";

Database::getConnection();
Database::ensurePointsSystem();

$conn = Database::getConnection();

$BADGE_LABELS = [
    'first_proposal'  => ['🌱', 'Erster Vorschlag'],
    'first_approved'  => ['✅', 'Erster Treffer'],
    'contributor_10'  => ['🥈', 'Fleißige Biene'],
    'contributor_50'  => ['🏆', 'Kölsch-Experte'],
    'points_100'      => ['⭐', '100 Punkte'],
    'points_500'      => ['🌟', '500 Punkte'],
];

$_r = $conn->query(
    "SELECT u.user_id, u.name, u.points,
            (SELECT COUNT(*) FROM singopkoelsch_change_requests cr WHERE cr.user_id=u.user_id AND cr.status='approved') as approved_count,
            (SELECT COUNT(*) FROM singopkoelsch_user_badges b WHERE b.user_id=u.user_id) as badge_count
     FROM singopkoelsch_users u
     WHERE u.role NOT IN ('admin','banned') AND u.points > 0
     ORDER BY u.points DESC, approved_count DESC
     LIMIT 50"
);
$top = $_r ? $_r->fetch_all(MYSQLI_ASSOC) : [];

$pageTitle = "Rangliste – Sing op Kölsch";
$_cssVer = @filemtime(__DIR__ . '/style.css') ?: time();
require_once "partials/head.php";
require_once "partials/nav.php";
?>
<main class="content">
  <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:1.75rem;">
    <a href="/" class="round-icon-btn" aria-label="Zurück">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
    </a>
    <h1 style="margin:0;font-size:1.3rem;">🏆 Rangliste</h1>
  </div>
  <p style="color:var(--text-3);font-size:0.88rem;margin-bottom:1.75rem;">Die aktivsten Mitarbeiter – Punkte für genehmigte Textvorschläge.</p>

  <?php if (empty($top)): ?>
    <p style="color:var(--text-3);">Noch keine Punkte vergeben. Reiche einen Vorschlag ein!</p>
  <?php else: ?>
    <div style="display:flex;flex-direction:column;gap:0.4rem;">
      <?php foreach ($top as $i => $u): ?>
        <?php
          $medals = ['🥇','🥈','🥉'];
          $rank = $medals[$i] ?? ('#' . ($i+1));
          $_br = $conn->query("SELECT badge_key FROM singopkoelsch_user_badges WHERE user_id={$u['user_id']} ORDER BY awarded_at ASC");
          $badges = $_br ? $_br->fetch_all(MYSQLI_ASSOC) : [];
        ?>
        <a href="/profil.php?id=<?= (int)$u['user_id'] ?>" style="display:flex;align-items:center;gap:0.75rem;padding:0.75rem 1rem;background:var(--card);border:1px solid var(--border);border-radius:12px;text-decoration:none;transition:border-color 0.15s;">
          <span style="font-size:1.2rem;min-width:2rem;text-align:center;"><?= $rank ?></span>
          <div style="flex:1;min-width:0;">
            <div style="font-weight:600;font-size:0.92rem;color:var(--text);"><?= htmlspecialchars($u['name']) ?></div>
            <div style="font-size:0.78rem;color:var(--text-3);margin-top:0.1rem;">
              <?php foreach ($badges as $b): ?>
                <?php if (isset($BADGE_LABELS[$b['badge_key']])): ?>
                  <span title="<?= $BADGE_LABELS[$b['badge_key']][1] ?>"><?= $BADGE_LABELS[$b['badge_key']][0] ?></span>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>
          </div>
          <div style="text-align:right;flex-shrink:0;">
            <div style="font-weight:700;font-size:0.95rem;color:#ef4444;"><?= number_format((int)$u['points']) ?> Pts</div>
            <div style="font-size:0.75rem;color:var(--text-3);"><?= (int)$u['approved_count'] ?> genehmigt</div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</main>
<?php require_once "partials/footer.php"; ?>
