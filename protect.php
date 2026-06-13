<?php
// ════════════════════════════════════════════════════════════════════
//  protect.php — tiered access control
//
//  By default, including this file does NOT force a login.
//  Pages explicitly call one of these helpers:
//
//    requireLogin() — must be logged in
//    requireAdmin() — must be logged in AND admin
//
//  When logged in, dark-mode prefs + profile picture + role flags are
//  lazily hydrated into $_SESSION.
// ════════════════════════════════════════════════════════════════════

$__isApp = isset($_SERVER['HTTP_USER_AGENT']) && strpos($_SERVER['HTTP_USER_AGENT'], 'SingOpKoelschApp') !== false;
if (session_status() === PHP_SESSION_NONE) {
    // Keep sessions from being garbage-collected early so app users stay logged in.
    @ini_set('session.gc_maxlifetime', '31536000');        // 1 year (all requests)
    if ($__isApp) {
        @ini_set('session.cookie_lifetime', '31536000');   // 1 year persistent cookie (app only)
    }
    session_start();
}
// Roll the app's login cookie forward on every request so it never lapses (browser users unchanged).
if ($__isApp && isset($_SESSION['user_id'])) {
    $__cp = session_get_cookie_params();
    @setcookie(session_name(), session_id(), [
        'expires'  => time() + 31536000,
        'path'     => $__cp['path'] ?: '/',
        'domain'   => $__cp['domain'] ?? '',
        'secure'   => !empty($__cp['secure']),
        'httponly' => !empty($__cp['httponly']),
        'samesite' => $__cp['samesite'] ?? '',
    ]);
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/i18n.php';

// ── Hydrate role flags + preferences once per session ──────────────
if (isset($_SESSION['user_id']) && !array_key_exists('dark_mode', $_SESSION)) {
    require_once __DIR__ . '/functions.php';
    Database::getConnection();
    Database::ensurePreferencesTable();
    $__prefs = Database::getUserPreferences((int)$_SESSION['user_id']);
    // Dark mode is on by default (1 = dark, 0 = light)
    $_SESSION['dark_mode']            = (int)($__prefs['dark_mode'] ?? 1);
    $_SESSION['email_notifications']  = (int)($__prefs['email_notifications'] ?? 1);
    $_SESSION['profile_picture']      = Database::getUserAvatarFilename((int)$_SESSION['user_id']);
    if (!empty($__prefs['lang']) && isset(I18N_LANGS[$__prefs['lang']])) {
        $_SESSION['lang'] = $__prefs['lang'];
    }

    // Refresh role flags from DB in case admin changed them
    $__flags = Database::getUserRoleFlags((int)$_SESSION['user_id']);
    $_SESSION['role'] = $__flags['role'];
}

// ── Invite-code access gate (admin-toggleable) ──────────────────────
require_once __DIR__ . '/invite_gate.php';
if (invite_gate_enabled() && !invite_access_granted()) {
    $__gp = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $__gAllow = ['/invite.php', '/login.php', '/logout.php', '/verify.php',
                 '/reset.php', '/forgot.php', '/resend_verify.php', '/unsubscribe.php'];
    if (!in_array($__gp, $__gAllow, true)) {
        header('Location: /invite.php?return=' . urlencode($_SERVER['REQUEST_URI'] ?? '/'));
        exit;
    }
}

// ════════════════════════════════════════════════════════════════════
//  Access-control helpers
// ════════════════════════════════════════════════════════════════════

function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0;
}

function isAdmin(): bool {
    return isLoggedIn() && ($_SESSION['role'] ?? 'user') === 'admin';
}

function isTrusted(): bool {
    if (!isLoggedIn()) return false;
    $role = $_SESSION['role'] ?? 'user';
    return $role === 'trusted' || $role === 'admin';
}

function requireLogin(string $returnTo = null): void {
    if (isLoggedIn()) return;
    $target = $returnTo ?? ($_SERVER['REQUEST_URI'] ?? '/');
    header("Location: /login.php?return=" . urlencode($target));
    exit;
}

function requireAdmin(): void {
    requireLogin();
    if (!isAdmin()) {
        http_response_code(403);
        _renderAccessDenied(t('err.admin_only'));
        exit;
    }
}

function _renderAccessDenied(string $message): void {
    $name  = htmlspecialchars($_SESSION['name'] ?? '');
    require_once __DIR__ . '/functions.php';
    require_once __DIR__ . '/partials/head.php';
    require_once __DIR__ . '/partials/nav.php';
    ?>
    <main class="content" style="display:flex;align-items:center;justify-content:center;min-height:60vh;">
      <div style="text-align:center;max-width:480px;">
        <div style="font-size:3rem;margin-bottom:1rem;">🔒</div>
        <h1 style="margin:0 0 0.75rem;font-size:1.5rem;"><?= htmlspecialchars(t('err.access_denied')) ?></h1>
        <p style="color:var(--text-2);margin:0 0 1.5rem;line-height:1.5;">
          <?= htmlspecialchars($message) ?>
        </p>
        <div style="display:flex;gap:0.5rem;justify-content:center;">
          <a href="/" class="btn btn-secondary"><?= htmlspecialchars(t('err.go_home')) ?></a>
          <a href="/lieder.php" class="btn-primary"><?= htmlspecialchars(t('err.browse_songs')) ?></a>
        </div>
        <?php if ($name): ?>
          <p style="margin-top:1.5rem;font-size:0.82rem;color:var(--text-3);">
            <?= htmlspecialchars(t('err.logged_in_as')) ?> <strong><?= $name ?></strong>.
            <a href="/logout.php" style="color:var(--primary);"><?= htmlspecialchars(t('nav.logout')) ?></a>
          </p>
        <?php endif; ?>
      </div>
    </main>
    <?php
    require_once __DIR__ . '/partials/footer.php';
}
