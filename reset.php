<?php
require_once "protect.php";
require_once "functions.php";

if (isLoggedIn()) {
    header("Location: /profile.php");
    exit;
}

Database::getConnection();

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$user  = $token ? Database::getUserByValidResetToken($token) : null;

$error   = '';
$success = '';

if ($user && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pw1 = $_POST['password']  ?? '';
    $pw2 = $_POST['password2'] ?? '';
    if (strlen($pw1) < 6) {
        $error = t('reset.err_short');
    } elseif ($pw1 !== $pw2) {
        $error = t('reset.err_mismatch');
    } else {
        $hash = password_hash($pw1, PASSWORD_DEFAULT);
        if (Database::applyPasswordReset((int)$user['user_id'], $hash)) {
            $success = t('reset.success');
            $user = null;
        } else {
            $error = t('reset.err_save');
        }
    }
}

$pageTitle = t('reset.title_prefix') . ' ' . t('reset.title_accent') . ' – Sing op Kölsch';
$bodyClass = 'home-page';
require_once "partials/head.php";
require_once "partials/nav.php";
?>

<main class="content">
  <div class="auth-card" style="max-width:420px;margin:2rem auto;">
    <h1 style="margin-top:0;"><?= htmlspecialchars(t('reset.title_prefix')) ?> <span class="accent"><?= htmlspecialchars(t('reset.title_accent')) ?></span></h1>

    <?php if ($success): ?>
      <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
      <p style="text-align:center;margin-top:1.2em;">
        <a href="/login.php" class="btn btn-primary" style="width:100%;"><?= htmlspecialchars(t('reset.to_login')) ?></a>
      </p>
    <?php elseif (!$user): ?>
      <div class="alert alert-error"><?= htmlspecialchars(t('reset.invalid')) ?></div>
      <p style="text-align:center;margin-top:1.2em;">
        <a href="/forgot.php" class="btn btn-secondary" style="width:100%;"><?= htmlspecialchars(t('reset.request_new')) ?></a>
      </p>
    <?php else: ?>
      <p style="color:var(--text-muted);margin-top:0;">
        <?= t('reset.greeting', ['name' => '<strong>' . htmlspecialchars($user['name']) . '</strong>']) ?>
      </p>
      <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>
      <form method="post" novalidate>
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
        <div class="form-section">
          <label for="password"><?= htmlspecialchars(t('reset.new_label')) ?></label>
          <input type="password" id="password" name="password" required minlength="6" autocomplete="new-password">
        </div>
        <div class="form-section">
          <label for="password2"><?= htmlspecialchars(t('reset.confirm_label')) ?></label>
          <input type="password" id="password2" name="password2" required minlength="6" autocomplete="new-password">
        </div>
        <div class="form-section">
          <button type="submit" class="btn btn-primary" style="width:100%;"><?= htmlspecialchars(t('reset.btn')) ?></button>
        </div>
      </form>
    <?php endif; ?>
  </div>
</main>

<?php require_once "partials/footer.php"; ?>
