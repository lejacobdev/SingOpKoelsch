<?php
$_currentPage = basename($_SERVER['PHP_SELF']);
$_loggedIn    = function_exists('isLoggedIn') ? isLoggedIn() : !empty($_SESSION['user_id']);
$_isAdmin     = function_exists('isAdmin')    ? isAdmin()    : ($_loggedIn && ($_SESSION['role'] ?? '') === 'admin');
$_isTrusted   = function_exists('isTrusted')  ? isTrusted()  : ($_loggedIn && in_array(($_SESSION['role'] ?? ''), ['trusted', 'admin'], true));
$_userName    = htmlspecialchars($_SESSION['name'] ?? '');
$_userInitial = strtoupper(substr($_SESSION['name'] ?? 'U', 0, 1));

// Pending badge counts for admins
$_pendingChanges = 0;
$_pendingCovers  = 0;
if ($_isAdmin) {
    $__conn = Database::getConnection();
    $__r = $__conn->query("SELECT COUNT(*) as c FROM singopkoelsch_change_requests WHERE status='pending'");
    if ($__r) $_pendingChanges = (int)$__r->fetch_assoc()['c'];
    $__rc = $__conn->query("SELECT COUNT(*) as c FROM singopkoelsch_cover_proposals WHERE status='pending'");
    if ($__rc) $_pendingCovers = (int)$__rc->fetch_assoc()['c'];
}
$_totalPending = $_pendingChanges + $_pendingCovers;

$onHome      = ($_currentPage === 'index.php');
$onLieder    = ($_currentPage === 'lieder.php' || $_currentPage === 'detail.php');
$onFavorites = ($_currentPage === 'favorites.php');
?>
<style>
/* Floating top bar — fixed overlay so it rides over content */
.topbar {
  position: fixed;
  top: 0; left: 0; right: 0;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.6rem;
  padding: 1rem 1.25rem;
  width: 100%;
  box-sizing: border-box;
  pointer-events: none; /* let clicks pass except on the actual icon buttons */
  z-index: 100;
}
.topbar-left, .topbar-right {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  pointer-events: auto;
}

.topbar .icon-btn {
  width: 40px; height: 40px;
  flex: 0 0 40px;
  aspect-ratio: 1 / 1;
  display: inline-flex; align-items: center; justify-content: center;
  background: rgba(255,255,255,0.78) !important;
  border: 1px solid rgba(15,23,42,0.10) !important;
  border-radius: 9999px !important;
  color: var(--text-2) !important;
  padding: 0 !important;
  box-shadow: 0 2px 10px rgba(15,23,42,0.08) !important;
  cursor: pointer;
  text-decoration: none;
  transition: background 0.15s, color 0.15s, border-color 0.15s, transform 0.1s;
  position: relative;
  backdrop-filter: blur(12px) saturate(160%);
  -webkit-backdrop-filter: blur(12px) saturate(160%);
}

.topbar .icon-btn:hover {
  background: var(--card-hover) !important;
  border-color: rgba(239,68,68,0.6) !important;
  color: #dc2626 !important;
  transform: translateY(-1px);
}

html.dark .topbar .icon-btn {
  background: rgba(28,33,40,0.72) !important;
  border-color: rgba(255,255,255,0.10) !important;
  color: rgba(255,255,255,0.78) !important;
  box-shadow: 0 2px 14px rgba(0,0,0,0.45) !important;
}

html.dark .topbar .icon-btn:hover {
  background: rgba(255,255,255,0.12) !important;
  border-color: rgba(239,68,68,0.6) !important;
  color: #fca5a5 !important;
}

.topbar .icon-btn.active {
  background: rgba(239,68,68,0.10) !important;
  border-color: rgba(239,68,68,0.55) !important;
  color: #dc2626 !important;
}

html.dark .topbar .icon-btn.active {
  background: rgba(239,68,68,0.18) !important;
  border-color: rgba(239,68,68,0.5) !important;
  color: #fca5a5 !important;
}

/* Language picker dropdown */
.lang-menu-wrap { position: relative; }
.lang-menu {
  position: absolute;
  top: calc(100% + 8px);
  right: 0;
  min-width: 180px;
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: 14px;
  box-shadow: 0 12px 32px rgba(15,23,42,0.18);
  padding: 0.35rem;
  display: none;
  z-index: 200;
  animation: scaleIn 0.18s var(--ease-out) both;
  transform-origin: top right;
}
.lang-menu.open { display: block; }
html.dark .lang-menu {
  background: rgba(28,33,40,0.96);
  border-color: rgba(255,255,255,0.08);
  box-shadow: 0 12px 32px rgba(0,0,0,0.5);
}
.lang-menu-item {
  display: flex; align-items: center; gap: 0.5rem;
  padding: 0.55rem 0.75rem;
  color: var(--text) !important;
  font-size: 0.9rem;
  font-weight: 500;
  border-radius: 9px;
  text-decoration: none;
  transition: background 0.12s, color 0.12s;
}
.lang-menu-item:hover { background: var(--bg-alt); color: #dc2626 !important; }
html.dark .lang-menu-item:hover { background: rgba(255,255,255,0.06); color: #fca5a5 !important; }
.lang-menu-item.is-current { color: #dc2626 !important; font-weight: 600; }
html.dark .lang-menu-item.is-current { color: #fca5a5 !important; }
.lang-flag {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 20px;
  height: 14px;
  overflow: hidden;
  flex: 0 0 20px;
  border-radius: 2px;
  box-shadow: 0 0 0 1px rgba(0,0,0,0.08);
}
.lang-flag svg { display:block; width:100%; height:100%; }
.lang-tick { margin-left: auto; font-weight: 700; }

/* User dropdown menu */
.user-menu-wrap { position: relative; }
.user-menu {
  position: absolute;
  top: calc(100% + 8px);
  right: 0;
  min-width: 220px;
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: 14px;
  box-shadow: 0 12px 32px rgba(15,23,42,0.18);
  padding: 0.45rem;
  display: none;
  z-index: 200;
  animation: scaleIn 0.18s var(--ease-out) both;
  transform-origin: top right;
}
.user-menu.open { display: block; }

html.dark .user-menu {
  background: rgba(28,33,40,0.96);
  border-color: rgba(255,255,255,0.08);
  box-shadow: 0 12px 32px rgba(0,0,0,0.5);
}

.user-menu-item {
  display: flex;
  align-items: center;
  gap: 0.55rem;
  padding: 0.58rem 0.85rem;
  color: var(--text) !important;
  font-size: 0.9rem;
  font-weight: 500;
  border-radius: 9px;
  text-decoration: none;
  transition: background 0.12s, color 0.12s;
}

.user-menu-item:hover {
  background: var(--bg-alt);
  color: var(--primary-hover) !important;
}

html.dark .user-menu-item:hover {
  background: rgba(255,255,255,0.06);
  color: #93c5fd !important;
}

.user-menu-item.danger { color: var(--danger) !important; }
.user-menu-item.danger:hover { background: var(--error-bg); }

html.dark .user-menu-item.danger { color: #fca5a5 !important; }
html.dark .user-menu-item.danger:hover { background: rgba(220,38,38,0.18); }

.user-menu-divider {
  height: 1px;
  background: var(--border);
  margin: 0.3rem 0.4rem;
}

html.dark .user-menu-divider { background: rgba(255,255,255,0.08); }

.user-menu-header {
  padding: 0.55rem 0.85rem 0.4rem;
  font-size: 0.78rem;
  color: var(--text-3);
}

.user-menu-header strong { color: var(--text); font-size: 0.88rem; }

.user-menu-badge {
  display: inline-flex;
  background: var(--accent);
  color: #fff;
  font-size: 0.65rem;
  font-weight: 700;
  border-radius: 9999px;
  min-width: 18px;
  height: 18px;
  padding: 0 5px;
  align-items: center;
  justify-content: center;
  margin-left: auto;
}

.nav-badge-dot {
  position: absolute;
  top: 4px; right: 4px;
  width: 8px; height: 8px;
  background: var(--accent);
  border-radius: 50%;
  border: 2px solid var(--bg);
  box-shadow: 0 0 0 1px var(--accent);
}

html.dark .nav-badge-dot {
  border-color: var(--bg);
}

/* Floating topbar — main content gets top padding so it isn't covered initially */
body { padding-top: 0 !important; }
main.content, main.content-wide, main { padding-top: 4.5rem !important; }
body.home-page main.home-main { padding-top: 4.5rem !important; }

@media (max-width: 480px) {
  .topbar { padding: 0.85rem 1rem; gap: 0.4rem; }
  .topbar .icon-btn { width: 36px; height: 36px; flex-basis: 36px; }
  main.content, main.content-wide, main { padding-top: 3.85rem !important; }
}
</style>

<header class="topbar" id="topbar">
  <div class="topbar-left">
    <a href="/" class="icon-btn <?= $onHome ? 'active' : '' ?>" title="<?= htmlspecialchars(t('nav.home')) ?>" aria-label="<?= htmlspecialchars(t('nav.home')) ?>">
      <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
    </a>
  </div>

  <div class="topbar-right">

  <button class="icon-btn" id="dark-toggle" title="<?= htmlspecialchars(t('nav.theme')) ?>" aria-label="<?= htmlspecialchars(t('nav.theme')) ?>">
    <svg class="icon-sun" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
    <svg class="icon-moon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
  </button>

  <a href="/lieder.php" class="icon-btn <?= $onLieder ? 'active' : '' ?>" title="<?= htmlspecialchars(t('nav.songs')) ?>" aria-label="<?= htmlspecialchars(t('nav.songs')) ?>">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>
  </a>

  <?php if ($_loggedIn): ?>
  <a href="/favorites.php" class="icon-btn <?= $onFavorites ? 'active' : '' ?>" title="Favoriten" aria-label="Favoriten">
    <svg width="16" height="16" viewBox="0 0 24 24"
         fill="<?= $onFavorites ? 'currentColor' : 'none' ?>"
         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78L12 21.23l8.84-8.84a5.5 5.5 0 0 0 0-7.78z"/>
    </svg>
  </a>
  <?php endif; ?>

  <div class="lang-menu-wrap">
    <button type="button" class="icon-btn" id="lang-menu-btn" title="<?= htmlspecialchars(t('nav.language')) ?>" aria-label="<?= htmlspecialchars(t('nav.language')) ?>">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
    </button>
    <div class="lang-menu" id="lang-menu">
      <?php foreach (availableLangs() as $code => $info): ?>
        <a href="/set_lang.php?lang=<?= $code ?>&return=<?= urlencode($_SERVER['REQUEST_URI'] ?? '/') ?>"
           class="lang-menu-item<?= currentLang() === $code ? ' is-current' : '' ?>">
          <span class="lang-flag"><?= $info['svg'] ?? htmlspecialchars($info['flag']) ?></span>
          <span><?= htmlspecialchars($info['label']) ?></span>
          <?php if (currentLang() === $code): ?><span class="lang-tick" aria-hidden="true">✓</span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="user-menu-wrap">
    <?php if ($_loggedIn): ?>
      <button class="icon-btn" id="user-menu-btn" title="<?= $_userName ?>" aria-label="<?= htmlspecialchars(t('nav.account')) ?>">
        <?php $__pic = $_SESSION['profile_picture'] ?? null; ?>
        <?php if ($__pic): ?>
          <img src="/uploads/avatars/<?= htmlspecialchars($__pic) ?>"
               width="22" height="22"
               style="width:22px;height:22px;border-radius:50%;object-fit:cover;flex-shrink:0;"
               alt="" />
        <?php else: ?>
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        <?php endif; ?>
        <?php if ($_totalPending > 0): ?>
          <span class="nav-badge-dot" title="<?= htmlspecialchars(t('nav.pending_badge', ['n' => $_totalPending])) ?>"></span>
        <?php endif; ?>
      </button>

      <div class="user-menu" id="user-menu">
        <div class="user-menu-header">
          <?= htmlspecialchars(t('nav.signed_in_as')) ?><br>
          <strong><?= $_userName ?></strong>
          <?php if ($_isAdmin): ?>
            <span class="badge badge-blue" style="font-size:0.62rem;vertical-align:middle;margin-left:0.3rem;"><?= htmlspecialchars(t('nav.admin_badge')) ?></span>
          <?php endif; ?>
        </div>

        <div class="user-menu-divider"></div>

        <?php if ($_isTrusted): ?>
          <a href="/add.php" class="user-menu-item">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            <?= htmlspecialchars(t('nav.add_song')) ?>
          </a>
        <?php endif; ?>

        <?php if ($_isAdmin): ?>
          <a href="/liederbuch.php" class="user-menu-item">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
            <?= htmlspecialchars(t('nav.songbook')) ?>
          </a>
          <a href="/admin/index.php" class="user-menu-item">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M20 21a8 8 0 1 0-16 0"/></svg>
            <?= htmlspecialchars(t('nav.admin_dashboard')) ?>
            <?php if ($_totalPending > 0): ?>
              <span class="user-menu-badge"><?= $_totalPending ?></span>
            <?php endif; ?>
          </a>
          <a href="/admin/users.php" class="user-menu-item">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
            <?= htmlspecialchars(t('nav.users')) ?>
          </a>
          <a href="/admin/proposals.php" class="user-menu-item">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
            <?= htmlspecialchars(t('prop.title')) ?>
            <?php if ($_totalPending > 0): ?>
              <span class="user-menu-badge"><?= $_totalPending ?></span>
            <?php endif; ?>
          </a>
          <div class="user-menu-divider"></div>
        <?php endif; ?>

        <a href="/profile.php" class="user-menu-item">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
          <?= htmlspecialchars(t('nav.profile')) ?>
        </a>
        <a href="/logout.php" class="user-menu-item danger">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
          <?= htmlspecialchars(t('nav.logout')) ?>
        </a>
      </div>
    <?php else: ?>
      <a href="/login.php" class="icon-btn" title="<?= htmlspecialchars(t('nav.login')) ?>" aria-label="<?= htmlspecialchars(t('nav.login')) ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
      </a>
    <?php endif; ?>
  </div>
  </div>
</header>

<script>
(function() {
  // Dark toggle
  const dt = document.getElementById('dark-toggle');
  const sun  = dt.querySelector('.icon-sun');
  const moon = dt.querySelector('.icon-moon');
  function syncDmIcons() {
    const isDark = document.documentElement.classList.contains('dark');
    sun.style.display  = isDark ? 'none' : '';
    moon.style.display = isDark ? '' : 'none';
  }
  syncDmIcons();
  dt.addEventListener('click', () => {
    const isDark = document.documentElement.classList.toggle('dark');
    syncDmIcons();
    // Always persist locally so the choice survives across pages/sessions
    try { localStorage.setItem('home_dark', isDark ? '1' : '0'); } catch(e){}
    <?php if ($_loggedIn): ?>
    // Mirror to DB for logged-in users so other devices stay in sync
    fetch('/save_preference.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'key=dark_mode&value=' + (isDark ? '1' : '0')
    });
    <?php endif; ?>
  });

  // Back button (placed contextually on song detail etc.) — always navigate
  // to the logical parent page declared via data-back-fallback. We intentionally
  // do NOT use history.back() so that detail → list is consistent regardless of
  // how the user arrived (deep link, external referrer, refresh, etc.).
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-back-btn]');
    if (!btn) return;
    e.preventDefault();
    location.href = btn.getAttribute('data-back-fallback') || '/lieder.php';
  });

  // Language menu toggle
  const lmBtn  = document.getElementById('lang-menu-btn');
  const lmMenu = document.getElementById('lang-menu');
  if (lmBtn && lmMenu) {
    lmBtn.addEventListener('click', e => {
      e.stopPropagation();
      lmMenu.classList.toggle('open');
    });
    document.addEventListener('click', e => {
      if (!e.target.closest('.lang-menu-wrap')) lmMenu.classList.remove('open');
    });
  }

  // User menu toggle
  const umBtn  = document.getElementById('user-menu-btn');
  const umMenu = document.getElementById('user-menu');
  if (umBtn && umMenu) {
    umBtn.addEventListener('click', e => {
      e.stopPropagation();
      umMenu.classList.toggle('open');
    });
    document.addEventListener('click', e => {
      if (!e.target.closest('.user-menu-wrap')) umMenu.classList.remove('open');
    });
    document.addEventListener('keydown', e => {
      if (e.key === 'Escape') umMenu.classList.remove('open');
    });
  }
})();
</script>
