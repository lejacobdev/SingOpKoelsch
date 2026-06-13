<?php
require_once "protect.php";
require_once "functions.php";

requireAdmin();

if (!isset($_GET["lyrics"]) || empty($_GET["lyrics"])) {
    header("Location: /");
    exit();
}

Database::getConnection();
Database::ensurePreferencesTable();
$id    = (int)$_GET["lyrics"];
$lyric = Database::queryDataById($id);

if (!$lyric) {
    header("Location: /");
    exit();
}

$bandMap  = Database::getBandMap();
$bandName = htmlspecialchars($bandMap[$lyric["band_id"]] ?? '–');

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["delete"] ?? '') === "yes") {
    Database::deleteData($id);
    header("Location: /");
    exit();
}

$pageTitle = t('delete.title') . ' – Sing op Kölsch';
require_once "partials/head.php";
require_once "partials/nav.php";
?>

<main class="content">
  <div style="margin-bottom:1.5rem;">
    <h1><?= htmlspecialchars(t('delete.title')) ?></h1>
  </div>

  <div class="card" style="max-width:480px;">
    <div class="card-header" style="color:var(--error-text);background:var(--error-bg);border-color:var(--error-border);">
      <?= htmlspecialchars(t('delete.warn_header')) ?>
    </div>
    <div class="card-body">
      <p style="color:var(--text-2);margin-bottom:1rem;"><?= t('delete.confirm_body') ?></p>
      <div style="background:var(--bg-alt);border-radius:var(--radius-sm);padding:1rem;margin-bottom:1.25rem;">
        <p style="margin:0 0 0.3rem;font-weight:700;font-size:1rem;"><?= htmlspecialchars($lyric["title"]) ?></p>
        <p style="margin:0;font-size:0.88rem;color:var(--text-2);"><?= htmlspecialchars(t('detail.artist')) ?>: <?= $bandName ?></p>
      </div>
      <form method="post">
        <input type="hidden" name="delete" value="yes">
        <div class="btn-row">
          <a href="detail.php?lyrics=<?= $id ?>" class="btn btn-ghost"><?= htmlspecialchars(t('btn.cancel')) ?></a>
          <button type="submit" class="btn-danger"><?= htmlspecialchars(t('delete.confirm_btn')) ?></button>
        </div>
      </form>
    </div>
  </div>
</main>

<?php require_once "partials/footer.php"; ?>
