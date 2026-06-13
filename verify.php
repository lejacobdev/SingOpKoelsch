<?php
require_once __DIR__ . '/protect.php';
require_once __DIR__ . '/functions.php';

$conn = Database::getConnection();

$token   = trim($_GET['token'] ?? '');
$success = false;

if ($token !== '') {
    $stmt = $conn->prepare("UPDATE singopkoelsch_users SET email_verified = 1, verify_token = NULL WHERE verify_token = ?");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $success = ($stmt->affected_rows > 0);
    $stmt->close();
}

$pageTitle = t('verify.title_prefix') . t('verify.title_accent') . ' – Sing op Kölsch';
$bodyClass = 'home-page';
require_once __DIR__ . '/partials/head.php';
require_once __DIR__ . '/partials/nav.php';
?>

<main class="content">
  <div class="auth-card" style="max-width:460px;margin:2rem auto;text-align:center;">
    <h1 style="margin-top:0;"><?= htmlspecialchars(t('verify.title_prefix')) ?><span class="accent"><?= htmlspecialchars(t('verify.title_accent')) ?></span></h1>

    <?php if ($success): ?>
      <div class="alert alert-success"><?= htmlspecialchars(t('verify.success')) ?></div>
      <p style="color:var(--text-muted);"><?= htmlspecialchars(t('verify.success_hint')) ?></p>
      <a href="/login.php" class="btn btn-primary" style="width:100%;margin-top:0.8em;"><?= htmlspecialchars(t('verify.btn_login')) ?></a>
    <?php else: ?>
      <div class="alert alert-error"><?= htmlspecialchars(t('verify.failed')) ?></div>
      <p style="color:var(--text-muted);"><?= htmlspecialchars(t('verify.need_new')) ?></p>
      <div style="display:flex;flex-direction:column;gap:0.6em;margin-top:0.8em;">
        <a href="/resend_verify.php" class="btn btn-primary" style="width:100%;"><?= htmlspecialchars(t('verify.btn_resend')) ?></a>
        <a href="/login.php" class="btn btn-secondary" style="width:100%;"><?= htmlspecialchars(t('verify.btn_login')) ?></a>
      </div>
    <?php endif; ?>
  </div>
</main>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
