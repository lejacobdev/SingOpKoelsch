<?php
require_once "protect.php";
require_once "functions.php";

$token = trim($_GET['token'] ?? '');
$user  = Database::unsubscribeByToken($token);

$pageTitle = t('unsub.title_prefix') . ' ' . t('unsub.title_accent') . ' – Sing op Kölsch';
require_once "partials/head.php";
require_once "partials/nav.php";
?>

<main class="content">
  <div class="page-header">
    <h1><?= htmlspecialchars(t('unsub.title_prefix')) ?><span class="accent"> <?= htmlspecialchars(t('unsub.title_accent')) ?></span></h1>
  </div>

  <div class="card" style="max-width:560px;">
    <div class="card-body">
      <?php if ($user): ?>
        <p style="margin:0 0 0.8em 0;">
          <?= t('unsub.greeting', ['name' => '<strong>' . htmlspecialchars($user['name']) . '</strong>']) ?>
        </p>
        <p style="margin:0 0 1em 0;">
          <?= t('unsub.confirmed', ['email' => '<strong>' . htmlspecialchars($user['email']) . '</strong>']) ?>
        </p>
        <p style="margin:0 0 1.2em 0;color:var(--text-muted);font-size:0.95em;">
          <?= htmlspecialchars(t('unsub.reactivate_hint')) ?>
        </p>
        <a href="/" class="btn btn-primary"><?= htmlspecialchars(t('unsub.to_home')) ?></a>
      <?php else: ?>
        <p style="margin:0 0 1em 0;">
          <?= htmlspecialchars(t('unsub.invalid')) ?>
        </p>
        <p style="margin:0 0 1.2em 0;color:var(--text-muted);font-size:0.95em;">
          <?= htmlspecialchars(t('unsub.also_profile')) ?>
        </p>
        <a href="/" class="btn btn-secondary"><?= htmlspecialchars(t('unsub.to_home')) ?></a>
      <?php endif; ?>
    </div>
  </div>
</main>

<?php require_once "partials/footer.php"; ?>
