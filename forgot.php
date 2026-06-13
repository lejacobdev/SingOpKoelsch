<?php
require_once "protect.php";
require_once "functions.php";

if (isLoggedIn()) {
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
        $error = t('forgot.err_invalid');
    } else {
        $reset = Database::createPasswordResetToken($emailIn);
        if ($reset) {
            $resetUrl = SITE_URL . '/reset.php?token=' . $reset['token'];
            $subject  = 'Passwort zurücksetzen – Sing op Kölsch';
            $body     = "Hallo {$reset['name']},\n\n"
                      . "Klick auf den Link, um ein neues Passwort zu setzen (gültig 60 Minuten):\n\n"
                      . "$resetUrl\n\n"
                      . "Falls du keine Zurücksetzung angefordert hast, kannst du diese Mail ignorieren.\n\n"
                      . "Dein Sing op Kölsch Team";
            $html = renderEmailHtml('Passwort zurücksetzen', [
                'greeting'    => 'Hallo ' . $reset['name'] . ',',
                'intro'       => 'Klick auf den Button, um ein neues Passwort zu setzen. Der Link ist 60 Minuten gültig.',
                'cta_label'   => 'Passwort neu setzen',
                'cta_url'     => $resetUrl,
                'outro'       => 'Der Link funktioniert nicht? Kopiere ihn in den Browser: ' . $resetUrl,
                'footer_note' => 'Falls du keine Zurücksetzung angefordert hast, ignoriere diese Mail – dein Passwort bleibt unverändert.',
            ]);
            sendMail($emailIn, $reset['name'], $subject, $body, [
                'html' => $html,
                'bypass_preference' => true,
            ]);
        }
        $success = t('forgot.success');
    }
}

$pageTitle = t('forgot.title_prefix') . ' ' . t('forgot.title_accent') . ' – Sing op Kölsch';
$bodyClass = 'home-page';
require_once "partials/head.php";
require_once "partials/nav.php";
?>

<main class="content">
  <div class="auth-card" style="max-width:420px;margin:2rem auto;">
    <h1 style="margin-top:0;"><?= htmlspecialchars(t('forgot.title_prefix')) ?> <span class="accent"><?= htmlspecialchars(t('forgot.title_accent')) ?></span></h1>
    <p style="color:var(--text-muted);margin-top:0;"><?= htmlspecialchars(t('forgot.intro')) ?></p>

    <?php if ($error): ?>
      <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
      <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <?php if (!$success): ?>
    <form method="post" novalidate>
      <div class="form-section">
        <label for="email"><?= htmlspecialchars(t('forgot.email_label')) ?></label>
        <input type="email" id="email" name="email" required value="<?= htmlspecialchars($emailIn) ?>" autocomplete="email">
      </div>
      <div class="form-section">
        <button type="submit" class="btn btn-primary" style="width:100%;"><?= htmlspecialchars(t('forgot.btn')) ?></button>
      </div>
    </form>
    <?php endif; ?>

    <p style="text-align:center;margin-top:1.2em;font-size:0.93em;">
      <a href="/login.php"><?= htmlspecialchars(t('forgot.back_login')) ?></a>
    </p>
  </div>
</main>

<?php require_once "partials/footer.php"; ?>
