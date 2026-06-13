<?php
require_once "protect.php";
require_once "functions.php";

if (!isLoggedIn()) {
    header("Location: /login.php");
    exit;
}

Database::getConnection();

$userId = (int)$_SESSION['user_id'];
$user   = Database::getUserById($userId);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password']   ?? '';
    $confirm  = trim($_POST['confirm'] ?? '');

    // Aktuelles Passwort verifizieren
    $conn = Database::getConnection();
    $stmt = $conn->prepare("SELECT password FROM singopkoelsch_users WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $confirmWord = t('del.confirm_word');
    if (!$row || !password_verify($password, $row['password'])) {
        $error = t('del.err_pw');
    } elseif (mb_strtoupper($confirm, 'UTF-8') !== mb_strtoupper($confirmWord, 'UTF-8')) {
        $error = t('del.err_confirm');
    } elseif (!Database::deleteUser($userId)) {
        $error = t('del.err_save');
    } else {
        // Session vollständig zerstören
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();
        header("Location: /?deleted=1");
        exit;
    }
}

$pageTitle = t('del.title_prefix') . ' ' . t('del.title_accent') . ' – Sing op Kölsch';
require_once "partials/head.php";
require_once "partials/nav.php";
$confirmWord = t('del.confirm_word');
?>

<main class="content">
  <div class="page-header">
    <h1><?= htmlspecialchars(t('del.title_prefix')) ?> <span class="accent"><?= htmlspecialchars(t('del.title_accent')) ?></span></h1>
  </div>

  <div class="card" style="max-width:560px;">
    <div class="card-body">
      <div class="alert alert-error" style="margin-bottom:1.2em;">
        <?= htmlspecialchars(t('del.warning')) ?>
      </div>

      <ul style="margin:0 0 1.4em 1.2em;color:var(--text);line-height:1.7;">
        <li><?= htmlspecialchars(t('del.item_profile')) ?></li>
        <li><?= htmlspecialchars(t('del.item_changes')) ?></li>
        <li><?= htmlspecialchars(t('del.item_covers')) ?></li>
        <li><?= htmlspecialchars(t('del.item_photos')) ?></li>
        <li><?= htmlspecialchars(t('del.item_prefs')) ?></li>
      </ul>

      <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="post" autocomplete="off">
        <div class="form-section">
          <label for="password"><?= htmlspecialchars(t('del.pw_label')) ?></label>
          <input type="password" id="password" name="password" required autocomplete="current-password">
        </div>
        <div class="form-section">
          <label for="confirm"><?= htmlspecialchars(t('del.confirm_label')) ?></label>
          <input type="text" id="confirm" name="confirm" required placeholder="<?= htmlspecialchars($confirmWord) ?>"
                 style="text-transform:uppercase;letter-spacing:0.05em;">
        </div>
        <div class="form-section" style="display:flex;gap:0.6rem;flex-wrap:wrap;">
          <a href="/profile.php" class="btn btn-secondary"><?= htmlspecialchars(t('del.cancel')) ?></a>
          <button type="submit" class="btn btn-danger"><?= htmlspecialchars(t('del.confirm_btn')) ?></button>
        </div>
      </form>
    </div>
  </div>
</main>

<?php require_once "partials/footer.php"; ?>
