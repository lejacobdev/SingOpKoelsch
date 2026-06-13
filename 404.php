<?php
http_response_code(404);
require_once __DIR__ . '/protect.php';

$pageTitle = '404 – ' . t('err.404_title') . ' – Sing op Kölsch';
$bodyClass = 'err-page';
$_loggedIn = isLoggedIn();
require_once __DIR__ . '/partials/head.php';
require_once __DIR__ . '/partials/nav.php';
?>
<style>
body.err-page {
  min-height: 100dvh;
  display: flex;
  flex-direction: column;
  background:
    radial-gradient(ellipse at 20% -10%, rgba(239,68,68,0.18), transparent 55%),
    radial-gradient(ellipse at 80% 110%, rgba(220,38,38,0.12), transparent 60%),
    var(--bg);
}
html:not(.dark) body.err-page {
  background:
    radial-gradient(ellipse at 20% -10%, rgba(239,68,68,0.10), transparent 55%),
    radial-gradient(ellipse at 80% 110%, rgba(220,38,38,0.06), transparent 60%),
    var(--bg);
}
.err-wrap {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1.5rem;
  text-align: center;
}
.err-inner {
  max-width: 540px;
  width: 100%;
}
.err-code {
  font-size: clamp(5rem, 22vw, 11rem);
  font-weight: 900;
  letter-spacing: -0.05em;
  line-height: 1;
  margin: 0 0 0.5rem;
  background: linear-gradient(135deg, #ef4444 0%, #dc2626 50%, #b91c1c 100%);
  -webkit-background-clip: text;
  background-clip: text;
  color: transparent;
  -webkit-text-fill-color: transparent;
  filter: drop-shadow(0 8px 28px rgba(220,38,38,0.35));
}
.err-title {
  font-size: clamp(1.3rem, 4.5vw, 1.8rem);
  font-weight: 800;
  letter-spacing: -0.02em;
  margin: 0 0 0.6rem;
  color: var(--text);
}
.err-sub {
  color: var(--text-2);
  margin: 0 0 1.6rem;
  font-size: 0.98rem;
  line-height: 1.55;
}
.err-actions {
  display: flex;
  gap: 0.6rem;
  justify-content: center;
  flex-wrap: wrap;
}
.err-btn {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  padding: 0.7rem 1.15rem;
  border-radius: 999px;
  font-weight: 600;
  font-size: 0.92rem;
  text-decoration: none;
  border: 1px solid transparent;
  transition: transform 0.12s, box-shadow 0.18s, background 0.18s;
  white-space: nowrap;
  min-width: 0;
  max-width: 100%;
}
.err-btn-primary {
  background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
  color: #fff;
  box-shadow:
    0 1px 0 rgba(255,255,255,0.22) inset,
    0 4px 14px rgba(220,38,38,0.32);
}
.err-btn-primary:hover {
  transform: translateY(-1px);
  box-shadow:
    0 1px 0 rgba(255,255,255,0.25) inset,
    0 6px 18px rgba(220,38,38,0.45);
}
.err-btn-ghost {
  background: var(--bg-alt);
  border-color: var(--border);
  color: var(--text);
}
html.dark .err-btn-ghost {
  background: rgba(255,255,255,0.05);
  border-color: rgba(255,255,255,0.12);
  color: rgba(255,255,255,0.85);
}
.err-btn-ghost:hover {
  border-color: var(--primary-light);
  color: var(--primary);
}

@media (max-width: 480px) {
  .err-actions { flex-direction: column; align-items: stretch; }
  .err-btn { justify-content: center; padding: 0.8rem 1rem; }
}

.err-mini {
  margin-top: 1.4rem;
  font-size: 0.8rem;
  color: var(--text-3);
}
.err-mini a { color: var(--primary); text-decoration: none; }
.err-mini a:hover { text-decoration: underline; }
</style>

<div class="err-wrap">
  <div class="err-inner">
    <div class="err-code">404</div>
    <h1 class="err-title"><?= htmlspecialchars(t('err.404_title')) ?></h1>
    <p class="err-sub">
      <?= htmlspecialchars(t('err.404_body')) ?>
    </p>
    <div class="err-actions">
      <a href="/" class="err-btn err-btn-primary">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
          <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2h-4v-7h-6v7H5a2 2 0 0 1-2-2z"/>
        </svg>
        <?= htmlspecialchars(t('err.go_home')) ?>
      </a>
      <a href="/lieder.php" class="err-btn err-btn-ghost">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="11" cy="11" r="7"/>
          <line x1="21" y1="21" x2="16.65" y2="16.65"/>
        </svg>
        <?= htmlspecialchars(t('err.browse_songs')) ?>
      </a>
    </div>
    <?php if (!$_loggedIn): ?>
      <p class="err-mini">
        <a href="/login.php"><?= htmlspecialchars(t('nav.login')) ?></a>
      </p>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
