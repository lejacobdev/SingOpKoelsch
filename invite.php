<?php
require_once __DIR__ . '/protect.php';        // session; /invite.php is gate-allowlisted (no redirect loop)
require_once __DIR__ . '/invite_gate.php';
require_once __DIR__ . '/push.php';

// sanitize the return target to a local path
$return = $_GET['return'] ?? $_POST['return'] ?? '/';
if (!is_string($return) || $return === '' || $return[0] !== '/' || strpos($return, '//') !== false) {
    $return = '/';
}

// Gate disabled or already has access → go straight in.
if (!invite_gate_enabled() || invite_access_granted()) {
    header('Location: ' . $return);
    exit;
}

$betaEnded = beta_ended();
$revoked   = $betaEnded ? null : invite_revoked_info();
$loggedIn  = isLoggedIn();
$error     = '';

// Only process code submission when neither beta_ended nor revoked
if (!$betaEnded && $revoked === null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $r = invite_redeem($_POST['code'] ?? '');
    if ($r === 'ok') {
        header('Location: ' . $return);
        exit;
    }
    $error = ($r === 'taken')
        ? 'Dieser Code ist bereits vergeben (anderes Konto/Gerät).'
        : 'Ungültiger oder deaktivierter Code.';
}
$loginUrl = '/login.php?return=' . urlencode('/invite.php?return=' . urlencode($return));
?>
<!DOCTYPE html>
<html lang="de" class="dark">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
<title>Einladung – Sing op Kölsch</title>
<meta name="theme-color" content="#0d1117">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<style>
  :root { color-scheme: dark; }
  * { box-sizing: border-box; }
  html, body { margin: 0; min-height: 100%; }
  body {
    background: radial-gradient(ellipse at 50% -10%, rgba(220,38,38,0.20), transparent 55%), #0d1117;
    color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    padding: max(env(safe-area-inset-top), 28px) 22px 40px; text-align: center; min-height: 100dvh;
  }
  .icon { width: 84px; height: 84px; border-radius: 20px; margin: 0 auto 18px; box-shadow: 0 10px 30px rgba(220,38,38,0.30); }
  h1 { font-size: 1.55rem; font-weight: 800; letter-spacing: -0.02em; margin: 0 0 8px; }
  p.sub { color: #94a3b8; margin: 0 0 24px; font-size: .98rem; line-height: 1.5; max-width: 360px; }
  .card { width: 100%; max-width: 360px; background: #1c2128; border: 1px solid #334155; border-radius: 16px; padding: 20px; }
  input[name=code] {
    width: 100%; text-align: center; letter-spacing: .18em; text-transform: uppercase;
    font: 700 1.15rem/1 ui-monospace, Menlo, monospace; color: #f1f5f9;
    background: #0d1117; border: 1.5px solid #334155; border-radius: 12px; padding: 14px; margin-bottom: 12px;
  }
  input[name=code]:focus { outline: none; border-color: #3b82f6; }
  button {
    width: 100%; font: 700 1rem -apple-system, sans-serif; color: #fff; cursor: pointer;
    background: linear-gradient(135deg, #ef4444, #dc2626); border: none; border-radius: 12px; padding: 14px;
    box-shadow: 0 4px 14px rgba(220,38,38,0.35);
  }
  button:active { transform: translateY(1px); }
  .err { color: #fca5a5; background: rgba(220,38,38,0.12); border: 1px solid rgba(220,38,38,0.4);
         border-radius: 10px; padding: 10px 12px; margin-bottom: 14px; font-size: .9rem; }
  .info-box { width: 100%; max-width: 360px; background: #1c2128; border: 1px solid #334155; border-radius: 16px; padding: 20px; text-align: left; }
  .info-box .label { font-size: .75rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: #94a3b8; margin-bottom: 8px; }
  .info-box .reason-text { font-size: 1rem; line-height: 1.5; color: #f1f5f9; margin: 0 0 12px; }
  .info-box .hint { font-size: .82rem; color: #64748b; margin: 0; }
  .alt { margin-top: 16px; font-size: .85rem; color: #64748b; }
  .alt a { color: #f87171; text-decoration: none; }
  .bindnote { margin-top: 10px; font-size: .78rem; color: #64748b; }
  .divider { margin: 20px 0; border: none; border-top: 1px solid #334155; }
  .section-label { font-size: .78rem; color: #64748b; margin: 0 0 12px; }
  /* dark overrides for onboarding overlay */
  #sok-ob {
    --bg: #0d1117; --card: #1c2128; --border: #334155;
    --primary: #3b82f6; --success: #16a34a;
    --text: #f1f5f9; --text-2: #94a3b8;
    --radius-full: 9999px; --ease-out: cubic-bezier(0.22,1,0.36,1);
  }
</style>
</head>
<body>
  <img class="icon" src="/apple-touch-icon.png" alt="">

<?php if ($betaEnded): ?>
  <h1>Beta beendet</h1>
  <p class="sub">Die Beta-Phase von Sing op Kölsch ist abgeschlossen.<br>Danke fürs Mitmachen!</p>
  <?php if ($loggedIn): ?>
    <p class="alt"><a href="/logout.php">Abmelden</a></p>
  <?php endif; ?>

<?php elseif ($revoked !== null): ?>
  <?php $reason = trim((string)($revoked['reason'] ?? '')); ?>
  <h1>Zugang entzogen</h1>
  <p class="sub">Dein Zugang zu Sing op Kölsch wurde deaktiviert.</p>
  <div class="info-box">
    <div class="label">Grund</div>
    <p class="reason-text"><?= $reason !== ''
        ? htmlspecialchars($reason)
        : 'Beta hat geendet.' ?></p>
    <p class="hint">Bei Fragen wende dich an den Administrator.</p>
  </div>
  <hr class="divider" style="width:100%;max-width:360px;">
  <p class="section-label">Anderen Einladungscode eingeben?</p>
  <form class="card" method="post" autocomplete="off">
    <input type="hidden" name="return" value="<?= htmlspecialchars($return) ?>">
    <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <input name="code" placeholder="XXXX-XXXX" maxlength="9"
           inputmode="text" autocapitalize="characters" spellcheck="false">
    <button type="submit">Einlösen &amp; loslegen</button>
  </form>
  <?php if ($loggedIn): ?>
    <p class="alt"><a href="/logout.php">Abmelden</a></p>
  <?php endif; ?>

<?php else: ?>
  <h1>Sing op Kölsch</h1>
  <p class="sub">Die App für kölsche Liedtexte – hunderte Songs, Texte und Noten, immer dabei.<br><br>
    Die Beta läuft derzeit nur auf Einladung. Du brauchst einen Account und einen persönlichen Einladungscode, den du von einem Admin erhältst.</p>

  <?php if (!$loggedIn): ?>
  <a href="<?= htmlspecialchars($loginUrl) ?>" style="display:block;width:100%;max-width:360px;">
    <button style="margin-bottom:0">Anmelden &amp; loslegen</button>
  </a>
  <p class="alt">Noch kein Konto? <a href="/login.php?tab=register&amp;return=<?= urlencode('/invite.php?return=' . urlencode($return)) ?>">Registrieren</a></p>

  <?php else: ?>
  <form class="card" method="post" autocomplete="off">
    <input type="hidden" name="return" value="<?= htmlspecialchars($return) ?>">
    <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <input name="code" placeholder="XXXX-XXXX" maxlength="9" autofocus
           inputmode="text" autocapitalize="characters" spellcheck="false">
    <button type="submit">Code einlösen</button>
    <p class="bindnote">Der Code wird dauerhaft an dein Konto gebunden.</p>
  </form>
  <p class="alt"><a href="/logout.php">Anderes Konto verwenden</a></p>
  <?php endif; ?>
<?php endif; ?>

<?php
/* Onboarding shows before the invite-code form — once per device, PWA/app only.
   After the user finishes, the overlay closes and the code form is visible beneath. */
require_once __DIR__ . '/partials/onboarding.php';
?>
</body>
</html>
