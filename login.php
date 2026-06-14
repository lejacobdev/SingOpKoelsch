<?php
require_once __DIR__ . '/protect.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/functions.php';

// Capture and sanitize the "return" URL (where to redirect after login).
// Only allow same-origin paths to prevent open-redirect.
function _loginSafeReturn(?string $candidate): ?string {
    if (!$candidate) return null;
    $c = trim($candidate);
    if ($c === '') return null;
    // Must be a root-relative path, not a protocol-relative or absolute URL.
    if ($c[0] !== '/' || (isset($c[1]) && $c[1] === '/')) return null;
    if (strpos($c, "\n") !== false || strpos($c, "\r") !== false) return null;
    // Never bounce back to login/logout/register pages.
    $path = parse_url($c, PHP_URL_PATH) ?? '';
    $bad  = ['/login.php', '/logout.php', '/verify.php'];
    foreach ($bad as $b) { if (stripos($path, $b) === 0) return null; }
    return $c;
}

$returnTo = _loginSafeReturn($_GET['return'] ?? $_POST['return'] ?? null);
// Fallback: same-origin Referer (when user lands on login.php via a link)
if (!$returnTo && !isset($_GET['return']) && !empty($_SERVER['HTTP_REFERER'])) {
    $ref = $_SERVER['HTTP_REFERER'];
    $refHost = parse_url($ref, PHP_URL_HOST);
    if ($refHost && $refHost === ($_SERVER['HTTP_HOST'] ?? '')) {
        $refPath = parse_url($ref, PHP_URL_PATH) ?? '';
        $refQs   = parse_url($ref, PHP_URL_QUERY);
        $cand    = $refPath . ($refQs ? '?' . $refQs : '');
        $returnTo = _loginSafeReturn($cand);
    }
}
$_SESSION['login_return'] = $returnTo ?: null;

if (isLoggedIn()) {
    $dest = $returnTo ?: '/';
    unset($_SESSION['login_return']);
    header("Location: $dest");
    exit;
}

$error   = '';
$success = '';

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) die("DB-Verbindung fehlgeschlagen.");
$conn->set_charset('utf8mb4');

function sendVerificationEmail(string $email, string $name, string $verify_link): bool {
    $subject = 'Bestätige deine E-Mail-Adresse – Sing op Kölsch';
    $body    = "Hallo $name,\n\nBitte bestätige deine E-Mail-Adresse über folgenden Link:\n\n$verify_link\n\nVielen Dank!\nDein Sing op Kölsch Team";
    $html = renderEmailHtml('Bestätige deine E-Mail-Adresse', [
        'greeting'    => 'Hallo ' . $name . ',',
        'intro'       => 'Willkommen bei Sing op Kölsch! Klick auf den Button, um deine E-Mail-Adresse zu bestätigen — danach geht\'s direkt los mit den Liedern.',
        'cta_label'   => 'E-Mail bestätigen',
        'cta_url'     => $verify_link,
        'outro'       => 'Der Link funktioniert nicht? Kopiere ihn einfach in den Browser: ' . $verify_link,
        'footer_note' => 'Du hast dich nicht registriert? Dann ignoriere diese Mail.',
    ]);
    return sendMail($email, $name, $subject, $body, [
        'html' => $html,
        'bypass_preference' => true,
    ]);
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (isset($_POST["login"])) {
        $email    = trim($_POST["email"] ?? '');
        $password = $_POST["password"] ?? '';
        $stmt = $conn->prepare("SELECT user_id, name, password, email_verified, role FROM singopkoelsch_users WHERE email = ?");
        $stmt->bind_param("s", $email); $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if ($row && password_verify($password, $row["password"])) {
            if ($row['role'] === 'banned') {
                $error = 'Dein Konto wurde gesperrt. Bitte wende dich an den Support.';
            } elseif ($row["email_verified"]) {
                session_regenerate_id(true);
                $_SESSION["user_id"] = $row["user_id"];
                $_SESSION["name"]    = $row["name"];
                $_SESSION["role"]    = $row["role"];
                $dest = _loginSafeReturn($_POST['return'] ?? null)
                      ?: ($_SESSION['login_return'] ?? '/');
                unset($_SESSION['login_return']);
                header("Location: " . $dest);
                exit;
            } else {
                $error = t('auth.err.confirm_email');
            }
        } else {
            $error = "Ungültige Zugangsdaten.";
        }
    } elseif (isset($_POST["register"])) {
        $email     = trim($_POST["reg_email"] ?? '');
        $password  = $_POST["reg_password"] ?? '';
        $name      = trim($_POST["reg_name"] ?? '');
        $emailOpt  = isset($_POST["email_notifications"]) ? 1 : 0;
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = t('auth.err.invalid_email');
        } elseif (strlen($password) < 6) {
            $error = t('auth.err.short_password');
        } elseif (empty($name)) {
            $error = t('auth.err.empty_name');
        } elseif (mb_strlen($name) < 2) {
            $error = t('auth.err.short_name');
        } elseif (isInappropriateName($name)) {
            $error = t('auth.err.bad_name');
        } else {
            $checkStmt = $conn->prepare("SELECT user_id, name, email_verified, verify_token FROM singopkoelsch_users WHERE email = ?");
            $checkStmt->bind_param("s", $email); $checkStmt->execute();
            $checkRow = $checkStmt->get_result()->fetch_assoc();
            if ($checkRow && $checkRow['email_verified']) {
                $error = t('auth.err.email_taken');
            } elseif ($checkRow && !$checkRow['email_verified']) {
                // Account exists but unverified — resend the confirmation link
                $verify_link = SITE_URL . '/verify.php?token=' . $checkRow['verify_token'];
                sendVerificationEmail($email, $checkRow['name'], $verify_link);
                $success = t('auth.ok.resent_verification');
            } else {
                $hash         = password_hash($password, PASSWORD_DEFAULT);
                $verify_token = bin2hex(random_bytes(32));
                $stmt = $conn->prepare("INSERT INTO singopkoelsch_users (name, email, password, verify_token) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("ssss", $name, $email, $hash, $verify_token);
                if ($stmt->execute()) {
                    $newUserId = $stmt->insert_id;
                    // Persist the email preference choice
                    Database::ensurePreferencesTable();
                    Database::saveAllPreferences((int)$newUserId, 0, $emailOpt);

                    $verify_link = SITE_URL . "/verify.php?token=$verify_token";
                    if (sendVerificationEmail($email, $name, $verify_link)) {
                        $success = t('auth.ok.registered');
                    } else {
                        $error = t('auth.ok.mail_fail');
                    }
                } else {
                    $error = t('auth.err.register_fail');
                }
            }
            $checkStmt->close();
        }
    }
}

// Show registration form by default when ?register=1 is set
$showRegister = isset($_GET['register']) || (!empty($error) && isset($_POST['register']));
$pageTitle = t('auth.login') . ' – Sing op Kölsch';
$bodyClass = 'home-page';
require_once __DIR__ . '/partials/head.php';
require_once __DIR__ . '/partials/nav.php';
?>
<style>
body.home-page {
  padding: 0 !important;
  min-height: 100dvh;
  display: flex;
  flex-direction: column;
  /* Matches home page — strong red radial glows on top corner */
  background:
    radial-gradient(ellipse 1100px 800px at 85% -5%, rgba(248,113,113,0.16), transparent 55%),
    radial-gradient(ellipse 900px 700px at 15% 110%, rgba(248,113,113,0.10), transparent 60%),
    radial-gradient(ellipse 600px 500px at 50% 50%, rgba(220,38,38,0.05), transparent 70%),
    var(--bg) !important;
  background-attachment: fixed;
}
html:not(.dark) body.home-page {
  background:
    radial-gradient(ellipse 1100px 800px at 85% -5%, rgba(220,38,38,0.14), transparent 55%),
    radial-gradient(ellipse 900px 700px at 15% 110%, rgba(220,38,38,0.08), transparent 60%),
    radial-gradient(ellipse 600px 500px at 50% 50%, rgba(168,85,247,0.04), transparent 70%),
    var(--bg) !important;
  background-attachment: fixed;
}

/* ── Main centered content ───────────────────────────── */
.home-main {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 1rem 1.25rem 4rem;
  text-align: center;
}

.home-logo {
  font-size: clamp(2.4rem, 8vw, 3.8rem);
  font-weight: 800;
  letter-spacing: -0.035em;
  margin: 0 0 0.4rem;
  color: var(--text);
  line-height: 1;
}
.home-logo .ac {
  background: linear-gradient(135deg, #ef4444 0%, #dc2626 35%, #b91c1c 70%, #7f1d1d 100%);
  -webkit-background-clip: text;
  background-clip: text;
  color: transparent;
  -webkit-text-fill-color: transparent;
  display: inline-block;
  filter: drop-shadow(0 2px 12px rgba(220,38,38,0.18));
}
.home-tagline {
  color: var(--text-3);
  margin: 0 0 2rem;
  font-size: clamp(0.95rem, 2.5vw, 1.05rem);
}

/* ── Auth card — frosted, matches home search bar ─────── */
.auth-card {
  width: 100%;
  max-width: 420px;
  background: rgba(255,255,255,0.7);
  border: 1px solid var(--border);
  border-radius: 22px;
  padding: 1.85rem 1.75rem 1.6rem;
  text-align: left;
  backdrop-filter: blur(14px) saturate(1.4);
  -webkit-backdrop-filter: blur(14px) saturate(1.4);
  box-shadow:
    0 1px 6px rgba(15,23,42,0.06),
    0 16px 48px rgba(220,38,38,0.10);
}
html.dark .auth-card {
  background: rgba(28,33,40,0.55);
  border-color: rgba(255,255,255,0.08);
  box-shadow:
    0 1px 6px rgba(0,0,0,0.3),
    0 16px 48px rgba(220,38,38,0.18);
}

.auth-form .form-group { margin-bottom: 0.85rem; }
.auth-form label {
  display: block;
  text-transform: none;
  letter-spacing: 0;
  font-size: 0.82rem;
  font-weight: 600;
  color: var(--text);
  margin-bottom: 0.35rem;
}
.auth-form input[type="text"],
.auth-form input[type="email"],
.auth-form input[type="password"] {
  width: 100%;
  padding: 0.72rem 0.95rem !important;
  font-size: 0.95rem !important;
  border: 1px solid var(--border) !important;
  border-radius: 12px !important;
  background: var(--bg-alt) !important;
  color: var(--text) !important;
  transition: border-color 0.18s, box-shadow 0.18s, background 0.18s;
  outline: none;
  height: auto !important;
}
html.dark .auth-form input[type="text"],
html.dark .auth-form input[type="email"],
html.dark .auth-form input[type="password"] {
  background: rgba(255,255,255,0.04) !important;
  border-color: rgba(255,255,255,0.1) !important;
}
.auth-form input:focus {
  border-color: #ef4444 !important;
  box-shadow: 0 0 0 4px rgba(239,68,68,0.15) !important;
  background: var(--card) !important;
}
html.dark .auth-form input:focus {
  background: rgba(255,255,255,0.07) !important;
}

.auth-submit {
  width: 100%;
  padding: 0.9rem !important;
  font-size: 1rem !important;
  font-weight: 700 !important;
  border-radius: 999px !important;
  margin-top: 0.5rem;
  background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%) !important;
  color: #fff !important;
  border: none !important;
  cursor: pointer;
  letter-spacing: -0.01em;
  box-shadow:
    0 1px 0 rgba(255,255,255,0.22) inset,
    0 6px 22px rgba(220,38,38,0.40) !important;
  transition: transform 0.12s, box-shadow 0.18s, filter 0.18s;
}
.auth-submit:hover {
  transform: translateY(-1px);
  filter: brightness(1.04);
  background: linear-gradient(135deg, #f87171 0%, #b91c1c 100%) !important;
  box-shadow:
    0 1px 0 rgba(255,255,255,0.25) inset,
    0 10px 28px rgba(220,38,38,0.55) !important;
}
.auth-submit:active { transform: scale(0.99); }

.auth-meta {
  text-align: center;
  margin-top: 1rem;
  font-size: 0.82rem;
  color: var(--text-3);
}
.auth-meta a {
  color: #ef4444;
  text-decoration: none;
  font-weight: 600;
}
.auth-meta a:hover { color: #b91c1c; text-decoration: underline; }

.auth-alert {
  padding: 0.7rem 0.9rem;
  border-radius: 10px;
  font-size: 0.86rem;
  margin-bottom: 1rem;
  border: 1px solid transparent;
}
.auth-alert.is-error {
  background: rgba(239,68,68,0.1);
  border-color: rgba(239,68,68,0.3);
  color: #fca5a5;
}
html:not(.dark) .auth-alert.is-error {
  background: #fef2f2;
  border-color: #fecaca;
  color: #991b1b;
}
.auth-alert.is-success {
  background: rgba(34,197,94,0.1);
  border-color: rgba(34,197,94,0.3);
  color: #86efac;
}
html:not(.dark) .auth-alert.is-success {
  background: #f0fdf4;
  border-color: #bbf7d0;
  color: #166534;
}

/* ── Footer ──────────────────────────────────────────── */
.home-footer {
  padding: 1.25rem 1.25rem 1.5rem;
  text-align: center;
  font-size: 0.78rem;
  color: var(--text-3);
}
.home-footer a { color: var(--text-3); text-decoration: none; }
.home-footer a:hover { color: #ef4444; }

@media (max-width: 480px) {
  .home-main { padding-top: 0; padding-bottom: 2rem; }
  .auth-card { padding: 1.5rem 1.25rem 1.4rem; border-radius: 18px; }
  .home-logo { margin-bottom: 0.3rem; }
  .home-tagline { margin-bottom: 1.5rem; }
}
</style>

  <main class="home-main">
    <h1 class="home-logo">Sing op <span class="ac">Kölsch</span></h1>
    <p class="home-tagline"><?= htmlspecialchars(t('auth.subtitle')) ?></p>

    <div class="auth-card">

      <?php if (!empty($error)): ?>
        <div class="auth-alert is-error"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>
      <?php if (!empty($success)): ?>
        <div class="auth-alert is-success"><?= htmlspecialchars($success) ?></div>
      <?php endif; ?>

      <!-- Login form -->
      <form id="login-form" method="post" autocomplete="on" class="auth-form" style="<?= $showRegister ? 'display:none;' : '' ?>">
        <?php if ($returnTo): ?>
          <input type="hidden" name="return" value="<?= htmlspecialchars($returnTo) ?>">
        <?php endif; ?>
        <div class="form-group">
          <label for="email"><?= htmlspecialchars(t('auth.email_label')) ?></label>
          <input type="email" name="email" id="email" required autocomplete="email" placeholder="deine@email.de" />
        </div>
        <div class="form-group">
          <label for="password"><?= htmlspecialchars(t('auth.password')) ?></label>
          <input type="password" name="password" id="password" required autocomplete="current-password" placeholder="••••••••" />
        </div>
        <button type="submit" name="login" class="auth-submit"><?= htmlspecialchars(t('auth.login')) ?></button>

        <div class="auth-meta" style="text-align:center;margin-top:0.6em;font-size:0.93em;display:flex;gap:0.8em;justify-content:center;flex-wrap:wrap;">
          <a href="/forgot.php"><?= htmlspecialchars(t('login.forgot')) ?></a>
          <span aria-hidden="true" style="color:var(--text-muted);">·</span>
          <a href="/resend_verify.php"><?= htmlspecialchars(t('login.resend_verify')) ?></a>
        </div>
        <div class="auth-meta">
          <?= t('auth.no_account_long') ?>
        </div>
      </form>

      <!-- Register form -->
      <form id="register-form" method="post" autocomplete="on" class="auth-form" style="<?= $showRegister ? '' : 'display:none;' ?>">
        <?php if ($returnTo): ?>
          <input type="hidden" name="return" value="<?= htmlspecialchars($returnTo) ?>">
        <?php endif; ?>
        <div class="form-group">
          <label for="reg_name"><?= htmlspecialchars(t('auth.full_name')) ?></label>
          <input type="text" name="reg_name" id="reg_name" required autocomplete="name" placeholder="Max Mustermann" />
        </div>
        <div class="form-group">
          <label for="reg_email"><?= htmlspecialchars(t('auth.email_label')) ?></label>
          <input type="email" name="reg_email" id="reg_email" required autocomplete="email" placeholder="deine@email.de" />
        </div>
        <div class="form-group">
          <label for="reg_password"><?= htmlspecialchars(t('auth.password')) ?> <span style="color:var(--text-3);font-weight:400;">(<?= htmlspecialchars(t('auth.password_min')) ?>)</span></label>
          <input type="password" name="reg_password" id="reg_password" required autocomplete="new-password" placeholder="••••••••" minlength="6" />
        </div>
        <div class="form-group" style="display:flex;align-items:flex-start;gap:0.6rem;font-size:0.85rem;color:var(--text-2);">
          <input type="checkbox" name="email_notifications" id="email_notifications" value="1" checked
                 style="width:auto;margin-top:0.2rem;" />
          <label for="email_notifications" style="margin:0;font-weight:500;color:var(--text-2);font-size:0.85rem;">
            <?= htmlspecialchars(t('auth.email_notif_label')) ?>
          </label>
        </div>
        <button type="submit" name="register" class="auth-submit"><?= htmlspecialchars(t('auth.signup')) ?></button>

        <div class="auth-meta">
          <?= t('auth.have_account_long') ?>
        </div>
      </form>

    </div>
  </main>

  <footer class="home-footer">
    <?= htmlspecialchars(t('home.tagline')) ?>
  </footer>

<script>
// ── Switch between login / register via the inline link ──
(function(){
  const loginForm = document.getElementById('login-form');
  const regForm   = document.getElementById('register-form');
  const toReg     = document.getElementById('link-to-register');
  const toLogin   = document.getElementById('link-to-login');
  if (toReg) toReg.addEventListener('click', e => {
    e.preventDefault();
    loginForm.style.display = 'none';
    regForm.style.display = '';
  });
  if (toLogin) toLogin.addEventListener('click', e => {
    e.preventDefault();
    regForm.style.display = 'none';
    loginForm.style.display = '';
  });
})();
</script>
<?php require_once __DIR__ . '/partials/footer.php'; ?>
