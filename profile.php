<?php
require_once "protect.php";
require_once "functions.php";

requireLogin();

Database::getConnection();
Database::ensurePreferencesTable();

$userId  = (int)$_SESSION['user_id'];
$user    = Database::getFullUserById($userId);
$prefs   = Database::getUserPreferences($userId);

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ── Upload profile picture ───────────────────────────────
    if (isset($_POST['action']) && $_POST['action'] === 'avatar') {
        if (!empty($_FILES['avatar']['tmp_name']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            $file    = $_FILES['avatar'];
            $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            if (!in_array($ext, $allowed)) {
                $error = t('profile.err.images_only');
            } elseif ($file['size'] > 5 * 1024 * 1024) {
                $error = t('profile.err.too_big');
            } else {
                // Delete old avatar file
                $old = Database::getUserAvatarFilename($userId);
                if ($old) @unlink(__DIR__ . '/uploads/avatars/' . basename($old));

                $newFilename = 'avatar_' . $userId . '_' . uniqid() . '.' . $ext;
                $dest        = __DIR__ . '/uploads/avatars/' . $newFilename;
                if (move_uploaded_file($file['tmp_name'], $dest)) {
                    Database::updateUserAvatar($userId, $newFilename);
                    $_SESSION['profile_picture'] = $newFilename;
                    $success = t('profile.pic_updated');
                } else {
                    $error = t('profile.err.save_file');
                }
            }
        } elseif (isset($_POST['remove_avatar'])) {
            $old = Database::getUserAvatarFilename($userId);
            if ($old) @unlink(__DIR__ . '/uploads/avatars/' . basename($old));
            Database::updateUserAvatar($userId, null);
            $_SESSION['profile_picture'] = null;
            $success = t('profile.pic_removed');
        } else {
            $error = t('profile.err.no_file');
        }
    }

    // ── Save display name ────────────────────────────────────
    if (isset($_POST['action']) && $_POST['action'] === 'profile') {
        $newName = trim($_POST['name'] ?? '');
        if (mb_strlen($newName) < 2) {
            $error = t('auth.err.short_name');
        } elseif (isInappropriateName($newName)) {
            $error = t('auth.err.bad_name');
        } else {
            if (Database::updateUserProfile($userId, $newName)) {
                $_SESSION['name'] = $newName;
                $user['name']     = $newName;
                $success = t('profile.name_updated');
            } else {
                $error = t('profile.err.save');
            }
        }
    }

    // ── Change password ──────────────────────────────────────
    if (isset($_POST['action']) && $_POST['action'] === 'password') {
        $current  = $_POST['current_password'] ?? '';
        $new      = $_POST['new_password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';
        $hash     = Database::getUserPasswordHash($userId);

        if (!password_verify($current, $hash)) {
            $error = 'Das aktuelle Passwort ist falsch.';
        } elseif (strlen($new) < 6) {
            $error = 'Das neue Passwort muss mindestens 6 Zeichen lang sein.';
        } elseif ($new !== $confirm) {
            $error = 'Die Passwörter stimmen nicht überein.';
        } else {
            $newHash = password_hash($new, PASSWORD_DEFAULT);
            if (Database::updateUserPassword($userId, $newHash)) {
                $success = 'Passwort erfolgreich geändert.';
            } else {
                $error = 'Fehler beim Speichern.';
            }
        }
    }

    // ── Save preferences ─────────────────────────────────────
    if (isset($_POST['action']) && $_POST['action'] === 'preferences') {
        $dm = isset($_POST['dark_mode']) ? 1 : 0;
        $en = isset($_POST['email_notifications']) ? 1 : 0;
        $limit = max(1, min(24, (int)($_POST['email_limit'] ?? 1)));

        // Get current preferences to compare
        $oldPrefs = Database::getUserPreferences($userId);

        Database::saveAllPreferences($userId, $dm, $en);
        Database::setUserPreference($userId, 'email_limit', $limit);
        Database::setUserPreference($userId, 'email_unit', 'day');

        // Reset count and set next daily reset at midnight
        $nextReset = (new DateTime('tomorrow midnight'))->format('Y-m-d H:i:s');
        Database::setUserPreference($userId, 'email_next_reset', $nextReset);
        Database::setUserPreference($userId, 'email_count', 0);

        $_SESSION['dark_mode']           = $dm;
        $_SESSION['email_notifications'] = $en;
        $_SESSION['email_limit']         = $limit;
        $_SESSION['email_unit']          = 'day';
        $_SESSION['email_next_reset']    = $nextReset;
        $_SESSION['email_count']         = 0;
        $prefs['dark_mode']              = $dm;
        $prefs['email_notifications']    = $en;
        $prefs['email_limit']            = $limit;
        $prefs['email_unit']             = 'day';
        $prefs['email_next_reset']       = $nextReset;
        $prefs['email_count']            = 0;

        // Build success message based on what changed
        $changes = [];
        if ($oldPrefs['dark_mode'] != $dm) {
            $changes[] = t('profile.dark_mode');
        }
        if ($oldPrefs['email_notifications'] != $en) {
            $changes[] = t('profile.email_notif');
        }
        if ($oldPrefs['email_limit'] != $limit) {
            $changes[] = t('profile.email_limit_label');
        }
        if (!empty($changes)) {
            $success = implode(', ', $changes) . ' ' . t('profile.saved');
        } else {
            $success = t('profile.settings_saved');
        }
    }
}

$pageTitle = t('profile.heading_prefix') . t('profile.heading_accent') . ' – Sing op Kölsch';
require_once "partials/head.php";
require_once "partials/nav.php";
?>

<main class="content">
  <div class="page-header">
    <h1><?= htmlspecialchars(t('profile.heading_prefix')) ?><span class="accent"><?= htmlspecialchars(t('profile.heading_accent')) ?></span></h1>
  </div>

  <?php if ($success): ?>
    <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <!-- Avatar card -->
  <div class="card mb-3">
    <div class="card-header"><?= htmlspecialchars(t('profile.avatar')) ?></div>
    <div class="card-body" style="display:flex;align-items:center;gap:1.5rem;flex-wrap:wrap;">
      <?php $currentPic = $_SESSION['profile_picture'] ?? null; ?>
      <?php if ($currentPic): ?>
        <img src="/uploads/avatars/<?= htmlspecialchars($currentPic) ?>"
             width="56" height="56"
             style="width:56px;height:56px;min-width:56px;max-width:56px;border-radius:50%;object-fit:cover;display:block;flex-shrink:0;border:2px solid var(--border);"
             alt="Profilbild" />
      <?php else: ?>
        <div class="avatar-placeholder"><?= htmlspecialchars(strtoupper(substr($user['name'] ?? 'U', 0, 1))) ?></div>
      <?php endif; ?>

      <div style="flex:1;min-width:200px;">
        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="action" value="avatar">
          <p style="color:var(--text-2);font-size:0.85rem;margin:0 0 0.75rem;">
            <?= htmlspecialchars(t('profile.err.images_only')) ?> · <?= htmlspecialchars(t('profile.err.too_big')) ?>
          </p>
          <div style="display:flex;gap:0.5rem;flex-wrap:wrap;align-items:center;">
            <label style="display:inline-flex;align-items:center;gap:0.4rem;cursor:pointer;padding:0.45em 0.9em;border-radius:var(--radius-sm);background:var(--bg-alt);border:1.5px solid var(--border);font-size:0.88rem;font-weight:600;color:var(--text);transition:background var(--transition);" onmouseover="this.style.background='var(--border)'" onmouseout="this.style.background='var(--bg-alt)'">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/></svg>
              <?= htmlspecialchars(t('profile.avatar')) ?>
              <input type="file" name="avatar" accept="image/*" style="display:none;" onchange="previewAvatar(this)">
            </label>
            <button type="submit" class="btn-primary btn-sm" id="avatar-submit" disabled><?= htmlspecialchars(t('btn.save')) ?></button>
          </div>
          <p id="avatar-filename" style="font-size:0.78rem;color:var(--text-3);margin:0.4rem 0 0;"></p>
        </form>

        <?php if ($currentPic): ?>
        <form method="post" style="margin-top:0.5rem;">
          <input type="hidden" name="action" value="avatar">
          <input type="hidden" name="remove_avatar" value="1">
          <button type="submit" class="btn-ghost btn-sm"
                  onclick="return confirm(<?= json_encode(t('profile.pic_removed')) ?>)"
                  style="font-size:0.78rem;color:var(--danger);">
            <?= htmlspecialchars(t('btn.delete')) ?>
          </button>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Profile card -->
  <div class="card mb-3">
    <div class="card-header"><?= htmlspecialchars(t('profile.title')) ?></div>
    <form method="post">
      <input type="hidden" name="action" value="profile">
      <div class="form-section">
        <div class="form-group">
          <label for="name"><?= htmlspecialchars(t('profile.display_name')) ?></label>
          <input type="text" id="name" name="name" value="<?= htmlspecialchars($user['name'] ?? '') ?>" required>
        </div>
        <div class="form-group">
          <label><?= htmlspecialchars(t('profile.email_label')) ?></label>
          <input type="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>" disabled style="opacity:0.6;cursor:not-allowed;">
          <p class="form-hint"><?= htmlspecialchars(t('profile.email_locked')) ?></p>
        </div>
        <div class="btn-row right">
          <button type="submit" class="btn-primary"><?= htmlspecialchars(t('btn.save')) ?></button>
        </div>
      </div>
    </form>
  </div>

  <!-- Password card -->
  <div class="card mb-3">
    <div class="card-header"><?= htmlspecialchars(t('profile.change_password')) ?></div>
    <form method="post">
      <input type="hidden" name="action" value="password">
      <div class="form-section">
        <div class="form-group">
          <label for="current_password"><?= htmlspecialchars(t('profile.current_password')) ?></label>
          <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
        </div>
        <div class="form-group">
          <label for="new_password"><?= htmlspecialchars(t('profile.new_password')) ?></label>
          <input type="password" id="new_password" name="new_password" required autocomplete="new-password" minlength="6">
          <p class="form-hint"><?= htmlspecialchars(t('profile.password_min')) ?></p>
        </div>
        <div class="form-group">
          <label for="confirm_password"><?= htmlspecialchars(t('profile.confirm_new')) ?></label>
          <input type="password" id="confirm_password" name="confirm_password" required autocomplete="new-password">
        </div>
        <div class="btn-row right">
          <button type="submit" class="btn-primary"><?= htmlspecialchars(t('profile.change_password')) ?></button>
        </div>
      </div>
    </form>
  </div>

  <!-- Preferences card -->
  <div class="card mb-3">
    <div class="card-header"><?= htmlspecialchars(t('profile.settings')) ?></div>
    <form method="post" id="prefs-form">
      <input type="hidden" name="action" value="preferences">
      <div class="form-section">

        <div class="toggle-switch">
          <div class="toggle-switch-label">
            <strong><?= htmlspecialchars(t('profile.dark_mode')) ?></strong>
          </div>
          <label class="toggle">
            <input type="checkbox" name="dark_mode" id="dark_mode_toggle" <?= !empty($prefs['dark_mode']) ? 'checked' : '' ?>>
            <span class="toggle-slider"></span>
          </label>
        </div>

        <div class="toggle-switch">
          <div class="toggle-switch-label">
            <strong><?= htmlspecialchars(t('profile.email_notif')) ?></strong>
          </div>
          <label class="toggle">
            <input type="checkbox" name="email_notifications" id="email_notif_toggle" <?= !empty($prefs['email_notifications']) ? 'checked' : '' ?>>
            <span class="toggle-slider"></span>
          </label>
        </div>

        <!-- Email frequency setting -->
        <div class="form-group">
          <label for="email_limit"><?= htmlspecialchars(t('profile.email_limit_label')) ?></label>
          <div style="display:flex;gap:0.5rem;align-items:center;">
            <input type="number" name="email_limit" id="email_limit" min="1" max="24" value="<?= htmlspecialchars($prefs['email_limit'] ?? 1) ?>" style="width:80px;">
            <span style="color:var(--text-2);font-size:0.88rem;"><?= htmlspecialchars(t('profile.email_unit_day')) ?></span>
          </div>
          <?php
            $limit = max(1, (int)($prefs['email_limit'] ?? 1));
            $intervalH = round(24 / $limit, 1);
            $intervalText = $intervalH == 1 ? t('profile.email_interval_hourly') : sprintf(t('profile.email_interval_every_n_hours'), $intervalH);
          ?>
          <p class="form-hint"><?= htmlspecialchars($intervalText) ?></p>
        </div>

      </div>
      <div class="form-section">
        <div class="btn-row right">
          <button type="submit" class="btn-primary"><?= htmlspecialchars(t('profile.save_settings')) ?></button>
        </div>
      </div>
    </form>
  </div>

  <!-- Push notifications card -->
  <div class="card mb-3" id="push-card">
    <div class="card-header">Push-Benachrichtigungen</div>
    <div class="card-body">
      <p id="push-status" style="margin:0 0 0.9em;color:var(--text-2);font-size:0.9rem;">Wird geprüft …</p>
      <p id="push-hint" style="display:none;margin:0 0 0.9em;color:var(--text-3);font-size:0.85rem;line-height:1.5;">
        Damit Benachrichtigungen auf dem iPhone funktionieren, öffne die Seite in <strong>Safari</strong> → „Teilen" → <strong>„Zum Home-Bildschirm"</strong>, und starte die App von dort.
      </p>
      <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
        <button type="button" id="push-enable" class="btn-primary">Benachrichtigungen aktivieren</button>
        <button type="button" id="push-test" class="btn-ghost" style="display:none;">Test senden</button>
      </div>
    </div>
  </div>

  <!-- Account info -->
  <div class="card">
    <div class="card-header">Kontoinformationen</div>
    <div class="card-body">
      <p class="text-sm text-muted" style="margin:0;">
        <strong>Rolle:</strong>
        <span class="badge <?= $user['role'] === 'admin' ? 'badge-blue' : 'badge-gray' ?>" style="margin-left:0.4em;">
          <?= htmlspecialchars($user['role'] ?? 'user') ?>
        </span>
        &nbsp;
        <strong>E-Mail verifiziert:</strong>
        <span class="badge <?= $user['email_verified'] ? 'badge-green' : 'badge-yellow' ?>" style="margin-left:0.4em;">
          <?= $user['email_verified'] ? 'Ja' : 'Nein' ?>
        </span>
      </p>
    </div>
  </div>

  <!-- Danger zone -->
  <div class="card" style="margin-top:1.4em;border:1px solid #f3c6c6;">
    <div class="card-header" style="color:#b00020;"><?= htmlspecialchars(t('del.danger_zone')) ?></div>
    <div class="card-body">
      <p style="margin:0 0 0.9em 0;">
        <?= htmlspecialchars(t('del.danger_intro')) ?>
      </p>
      <a href="/delete_account.php" class="btn btn-danger"><?= htmlspecialchars(t('del.button')) ?></a>
    </div>
  </div>
</main>

<script>
// Avatar file picker preview
function previewAvatar(input) {
  const submitBtn = document.getElementById('avatar-submit');
  const label     = document.getElementById('avatar-filename');
  if (input.files && input.files[0]) {
    const file = input.files[0];
    label.textContent = file.name + ' (' + (file.size / 1024).toFixed(0) + ' KB)';
    submitBtn.disabled = false;

    // Live preview
    const reader = new FileReader();
    reader.onload = e => {
      const existing = document.querySelector('.avatar-current, .avatar-placeholder');
      if (existing) {
        const img = document.createElement('img');
        img.className = 'avatar-current';
        img.width  = 56;
        img.height = 56;
        img.style.cssText = 'width:56px;height:56px;min-width:56px;max-width:56px;border-radius:50%;object-fit:cover;display:block;flex-shrink:0;border:2px solid var(--border);';
        img.src = e.target.result;
        existing.replaceWith(img);
      }
    };
    reader.readAsDataURL(file);
  }
}

// Live dark mode toggle from preferences form
document.getElementById('dark_mode_toggle')?.addEventListener('change', function() {
  const isDark = this.checked;
  document.documentElement.classList.toggle('dark', isDark);
  fetch('/save_preference.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'key=dark_mode&value=' + (isDark ? '1' : '0')
  });
});

// ── Push notifications (PWA) ─────────────────────────────────────────
(function () {
  const statusEl = document.getElementById('push-status');
  const hint     = document.getElementById('push-hint');
  const btn      = document.getElementById('push-enable');
  const testBtn  = document.getElementById('push-test');
  if (!statusEl || !btn) return;

  const standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  const supported  = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;

  function b64ToUint8(s) {
    const pad = '='.repeat((4 - s.length % 4) % 4);
    const b64 = (s + pad).replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(b64);
    const arr = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; i++) arr[i] = raw.charCodeAt(i);
    return arr;
  }

  async function refresh() {
    if (!supported) { statusEl.textContent = 'Dein Browser unterstützt keine Web-Benachrichtigungen.'; btn.style.display = 'none'; return; }
    if (!standalone) hint.style.display = '';
    let reg, sub = null;
    try { reg = await navigator.serviceWorker.ready; sub = await reg.pushManager.getSubscription(); } catch (e) {}
    if (sub && Notification.permission === 'granted') {
      statusEl.textContent = 'Benachrichtigungen sind aktiv. ✅';
      btn.textContent = 'Deaktivieren'; btn.dataset.on = '1';
      testBtn.style.display = '';
    } else {
      statusEl.textContent = standalone ? 'Noch nicht aktiviert.' : 'Zum Aktivieren: App zum Home-Bildschirm hinzufügen (siehe unten).';
      btn.textContent = 'Benachrichtigungen aktivieren'; btn.dataset.on = '';
      testBtn.style.display = 'none';
    }
  }

  async function enable() {
    btn.disabled = true;
    try {
      const perm = await Notification.requestPermission();
      if (perm !== 'granted') { statusEl.textContent = 'Berechtigung wurde abgelehnt.'; return; }
      const { publicKey } = await (await fetch('/push_api.php?action=vapid')).json();
      if (!publicKey) { statusEl.textContent = 'Server nicht bereit (VAPID fehlt).'; return; }
      const reg = await navigator.serviceWorker.ready;
      const sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64ToUint8(publicKey) });
      await fetch('/push_api.php?action=subscribe', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ subscription: sub }) });
    } catch (e) { statusEl.textContent = 'Fehler: ' + (e && e.message ? e.message : e); }
    finally { btn.disabled = false; await refresh(); }
  }

  async function disable() {
    btn.disabled = true;
    try {
      const reg = await navigator.serviceWorker.ready;
      const sub = await reg.pushManager.getSubscription();
      if (sub) {
        await fetch('/push_api.php?action=unsubscribe', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ endpoint: sub.endpoint }) });
        await sub.unsubscribe();
      }
    } catch (e) {}
    finally { btn.disabled = false; await refresh(); }
  }

  btn.addEventListener('click', () => (btn.dataset.on ? disable() : enable()));
  testBtn.addEventListener('click', async () => {
    testBtn.disabled = true;
    try { await fetch('/push_api.php?action=test', { method: 'POST' }); } catch (e) {}
    setTimeout(() => { testBtn.disabled = false; }, 2000);
  });

  if (supported) {
    navigator.serviceWorker.register('/sw.js?v=<?= @filemtime(__DIR__ . '/sw.js') ?: '1' ?>').then(refresh).catch(refresh);
  } else { refresh(); }
})();
</script>

<?php require_once "partials/footer.php"; ?>
