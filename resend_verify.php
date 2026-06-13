<?php
require_once "protect.php";
require_once "functions.php";

if (isLoggedIn() && !empty($_SESSION['email_verified'])) {
    header("Location: /profile.php");
    exit;
}

Database::getConnection();

$error   = '';
$success = '';
$emailIn = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $emailIn = trim($_POST['email'] ?? '');
    if (!filter_var($emailIn, FILTER_VALIDATE_EMAIL)) {
        $error = t('resend.err_invalid');
    } else {
        $info = Database::ensureVerificationTokenFor($emailIn);
        if ($info) {
            $verify_link = SITE_URL . '/verify.php?token=' . $info['token'];
            sendVerificationEmail($emailIn, $info['name'] ?: 'dort', $verify_link);
        }
        $success = t('resend.success');
    }
}

$pageTitle = t('resend.title_prefix') . ' ' . t('resend.title_accent') . ' – Sing op Kölsch';
$bodyClass = 'home-page';
require_once "partials/head.php";
require_once "partials/nav.php";
?>

<main class="content">
  <div class="auth-card" style="max-width:420px;margin:2rem auto;">
    <h1 style="margin-top:0;"><?= htmlspecialchars(t('resend.title_prefix')) ?><br><span class="accent"><?= htmlspecialchars(t('resend.title_accent')) ?></span></h1>
    <p style="color:var(--text-muted);margin-top:0;"><?= htmlspecialchars(t('resend.intro')) ?></p>

    <?php if ($error): ?>
      <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
      <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <?php if (!$success): ?>
    <form method="post" novalidate>
      <div class="form-section">
        <label for="email"><?= htmlspecialchars(t('resend.email_label')) ?></label>
        <input type="email" id="email" name="email" required value="<?= htmlspecialchars($emailIn) ?>" autocomplete="email">
      </div>
      <div class="form-section">
        <button type="submit" class="btn btn-primary" style="width:100%;"><?= htmlspecialchars(t('resend.btn_send')) ?></button>
      </div>
    </form>
    <?php endif; ?>

    <p style="text-align:center;margin-top:1.2em;font-size:0.93em;">
      <a href="/login.php"><?= htmlspecialchars(t('resend.back_login')) ?></a>
    </p>
  </div>
</main>

<?php require_once "partials/footer.php"; ?>
