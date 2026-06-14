<?php
require_once "protect.php";
require_once "functions.php";


Database::getConnection();
$stats = Database::getStats();

$_loggedIn = isLoggedIn();
$_isAdmin  = isAdmin();
$_userName = htmlspecialchars($_SESSION['name'] ?? '');
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

// #6 Song des Tages — täglich neu, per Datum geseedet
$_conn = Database::getConnection();
$_conn->query("CREATE TABLE IF NOT EXISTS singopkoelsch_views (id BIGINT AUTO_INCREMENT PRIMARY KEY, song_id INT NOT NULL, viewed_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX idx_song (song_id), INDEX idx_time (viewed_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$_todaySeed = (int)date('Ymd');
$_sotdResult = $_conn->query("SELECT l.id, l.title, b.band_name, l.cover_url FROM singopkoelsch_lyrics l LEFT JOIN singopkoelsch_bands b ON b.band_id = l.band_id WHERE l.lyrics IS NOT NULL AND l.lyrics != '' ORDER BY RAND($_todaySeed) LIMIT 1");
$_sotd = $_sotdResult ? $_sotdResult->fetch_assoc() : null;

$pageTitle = t('home.title');
$_cssVer = @filemtime(__DIR__ . '/style.css') ?: time();
?>
<!DOCTYPE html>
<html lang="de" class="dark">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1<?= (strpos($_SERVER['HTTP_USER_AGENT'] ?? '', 'SingOpKoelschApp') !== false) ? ', maximum-scale=1, user-scalable=no' : '' ?>, viewport-fit=cover" />
<meta name="apple-mobile-web-app-title" content="Sing op Kölsch">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<?php $__iv = @filemtime(__DIR__ . '/apple-touch-icon.png') ?: time(); ?>
<link rel="apple-touch-icon" href="/apple-touch-icon.png?v=<?= $__iv ?>">
<link rel="icon" type="image/png" href="/favicon.png?v=<?= $__iv ?>">
<link rel="icon" href="/favicon.ico?v=<?= $__iv ?>" sizes="any">
<link rel="manifest" href="/manifest.webmanifest">
<meta name="mobile-web-app-capable" content="yes">
<title><?= htmlspecialchars($pageTitle) ?></title>
<link rel="stylesheet" href="/style.css?v=<?= $_cssVer ?>" />
<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
<meta http-equiv="Pragma" content="no-cache" />
<meta http-equiv="Expires" content="0" />
<script>
// Theme bootstrap — runs before paint to avoid flash, default dark
(function(){
  try {
    var ls = localStorage.getItem('home_dark');
    var wantDark = (ls === null) ? true : (ls === '1');
    if (ls === null) localStorage.setItem('home_dark', '1');
    document.documentElement.classList.toggle('dark', wantDark);
    if (window.navigator.standalone === true) {
      document.documentElement.classList.add('pwa');
      var vp = document.querySelector('meta[name=viewport]');
      if (vp) vp.setAttribute('content', 'width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover');
    }
    if (navigator.userAgent.indexOf('SingOpKoelschApp') !== -1) document.documentElement.classList.add('app');
  } catch(e) {}
})();
</script>
<style>
body.home-page {
  padding: 0 !important;
  min-height: 100dvh;
  display: flex;
  flex-direction: column;
}

/* Topbar + icon-btn + user-menu styles live in partials/nav.php */

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
  font-size: clamp(2.8rem, 9vw, 4.4rem);
  font-weight: 800;
  letter-spacing: -0.035em;
  margin: 0 0 0.4rem;
  color: var(--text);
  line-height: 1;
}

.home-logo .ac {
  background: linear-gradient(
    135deg,
    #ef4444 0%,    /* helles Rot */
    #dc2626 35%,   /* mittleres Rot */
    #b91c1c 70%,   /* dunkleres Rot */
    #7f1d1d 100%   /* tiefes Köln-Rot */
  );
  -webkit-background-clip: text;
  background-clip: text;
  color: transparent;
  -webkit-text-fill-color: transparent;
  display: inline-block;
  filter: drop-shadow(0 2px 12px rgba(220,38,38,0.18));
}

.home-tagline {
  color: var(--text-3);
  margin: 0 0 2.25rem;
  font-size: clamp(0.95rem, 2.5vw, 1.1rem);
}

/* ── Search bar ──────────────────────────────────────── */
.home-search-wrap {
  position: relative;
  width: 100%;
  max-width: 580px;
}
.home-suggest {
  position: absolute;
  top: calc(100% + 6px);
  left: 0;
  right: 0;
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: 18px;
  list-style: none;
  margin: 0;
  padding: 0.4rem;
  box-shadow:
    0 1px 6px rgba(15,23,42,0.08),
    0 18px 48px rgba(15,23,42,0.18);
  z-index: 50;
  max-height: 64vh;
  overflow-y: auto;
  text-align: left;
}
html.dark .home-suggest {
  background: rgba(28,33,40,0.96);
  border-color: rgba(255,255,255,0.10);
  box-shadow:
    0 1px 6px rgba(0,0,0,0.4),
    0 18px 48px rgba(0,0,0,0.55);
}
.home-suggest[hidden] { display: none; }
.suggest-item {
  display: flex;
  align-items: center;
  gap: 0.7rem;
  padding: 0.55rem 0.7rem;
  border-radius: 12px;
  cursor: pointer;
  color: var(--text);
  text-decoration: none;
  transition: background 0.1s;
}
.suggest-item:hover,
.suggest-item.is-active {
  background: var(--bg-alt);
}
html.dark .suggest-item:hover,
html.dark .suggest-item.is-active {
  background: rgba(255,255,255,0.06);
}
.suggest-cover {
  width: 38px; height: 38px;
  flex-shrink: 0;
  border-radius: 6px;
  object-fit: cover;
  background: var(--bg-alt);
}
.suggest-cover-placeholder {
  width: 38px; height: 38px;
  flex-shrink: 0;
  border-radius: 6px;
  background: linear-gradient(135deg, rgba(239,68,68,0.15), rgba(220,38,38,0.08));
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: rgba(220,38,38,0.55);
}
.suggest-text {
  min-width: 0;
  display: flex;
  flex-direction: column;
  line-height: 1.25;
}
.suggest-title {
  font-weight: 600;
  font-size: 0.95rem;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  color: var(--text);
}
.suggest-band {
  font-size: 0.78rem;
  color: var(--text-3);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.suggest-kind {
  margin-left: auto;
  font-size: 0.62rem;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--text-3);
  flex-shrink: 0;
  padding-left: 0.5rem;
}
.suggest-empty {
  padding: 0.7rem 0.8rem;
  color: var(--text-3);
  font-size: 0.88rem;
}
@media (max-width: 480px) {
  .home-suggest { max-height: 60vh; }
  .suggest-cover, .suggest-cover-placeholder { width: 34px; height: 34px; }
}

.home-search {
  display: flex;
  align-items: center;
  gap: 0.45rem;
  width: 100%;
  max-width: 580px;
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: 28px;
  padding: 0.5rem 0.55rem 0.5rem 1.15rem;
  box-shadow:
    0 1px 6px rgba(15,23,42,0.06),
    0 8px 32px rgba(15,23,42,0.08);
  transition: box-shadow 0.2s, border-color 0.2s;
}

.home-search:focus-within {
  border-color: var(--primary-light);
  box-shadow:
    0 1px 6px rgba(15,23,42,0.06),
    0 8px 32px rgba(37,99,235,0.18);
}

html.dark .home-search {
  background: rgba(28,33,40,0.6);
  border-color: rgba(255,255,255,0.1);
}

.home-search-icon {
  flex-shrink: 0;
  width: 20px; height: 20px;
  color: var(--text-3);
}

.home-search input {
  flex: 1;
  min-width: 0;
  border: none !important;
  background: transparent !important;
  outline: none;
  box-shadow: none !important;
  padding: 0.6rem 0.5rem !important;
  font-size: 1.05rem !important;
  color: var(--text);
}

.home-search input::placeholder { color: var(--text-3); }

.home-mic-btn {
  flex-shrink: 0;
  width: 42px; height: 42px;
  background: linear-gradient(135deg, #ef4444, #dc2626) !important;
  color: #fff !important;
  border: none !important;
  cursor: pointer;
  padding: 0 !important;
  border-radius: 50% !important;
  font-weight: 600;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  box-shadow:
    0 1px 0 rgba(255,255,255,0.22) inset,
    0 4px 14px rgba(220,38,38,0.35) !important;
  transition: background 0.18s, transform 0.12s, box-shadow 0.18s;
}

.home-mic-btn:hover {
  background: linear-gradient(135deg, #f87171, #b91c1c) !important;
  transform: translateY(-1px);
  box-shadow:
    0 1px 0 rgba(255,255,255,0.25) inset,
    0 6px 18px rgba(220,38,38,0.5) !important;
}

.home-mic-btn:active {
  transform: scale(0.96);
}

.home-mic-btn.recording {
  background: linear-gradient(135deg, #b91c1c, #7f1d1d) !important;
  animation: micPulse 1.2s ease-in-out infinite !important;
}

@keyframes micPulse {
  0%, 100% { box-shadow: 0 1px 0 rgba(255,255,255,0.22) inset, 0 4px 16px rgba(220,38,38,0.5), 0 0 0 0 rgba(220,38,38,0.4); }
  50%      { box-shadow: 0 1px 0 rgba(255,255,255,0.22) inset, 0 4px 20px rgba(220,38,38,0.7), 0 0 0 14px rgba(220,38,38,0); }
}

/* ── Stat chips (pill-button style) ──────────────────── */
.home-stats {
  display: flex;
  gap: 0.55rem;
  margin-top: 1.75rem;
  flex-wrap: wrap;
  justify-content: center;
}

.home-stat {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.5rem 1.05rem 0.5rem 0.95rem;
  background: var(--bg-alt);
  border: 1px solid var(--border);
  border-radius: 9999px;
  font-size: 0.85rem;
  color: var(--text-2);
  font-weight: 500;
  text-decoration: none;
  transition: background 0.18s, border-color 0.18s, color 0.18s, transform 0.12s;
}

.home-stat:hover {
  background: var(--card-hover);
  border-color: var(--accent);
  color: var(--text);
  transform: translateY(-1px);
}

html.dark .home-stat {
  background: rgba(255,255,255,0.04);
  border-color: rgba(255,255,255,0.08);
  color: rgba(255,255,255,0.75);
}

html.dark .home-stat:hover {
  background: rgba(255,255,255,0.08);
  border-color: rgba(248,113,113,0.45);
  color: #fff;
}

.home-stat-value {
  font-weight: 800;
  font-size: 0.95rem;
  color: var(--text);
  letter-spacing: -0.01em;
  font-variant-numeric: tabular-nums;
}

html.dark .home-stat-value { color: #fff; }

.home-stat-label {
  color: var(--text-3);
  font-size: 0.82rem;
  font-weight: 500;
}

/* ── Footer ──────────────────────────────────────────── */
.home-footer {
  padding: 1.25rem 1.25rem 1.5rem;
  text-align: center;
  font-size: 0.78rem;
  color: var(--text-3);
}

.home-footer a { color: var(--text-3); text-decoration: none; }
.home-footer a:hover { color: var(--primary); }

@media (max-width: 480px) {
  .home-main { padding-top: 0; padding-bottom: 2rem; }
  .home-search input { font-size: 1rem !important; }
  .home-stats { gap: 1rem; margin-top: 2rem; }
  .home-stat { min-width: 110px; padding: 0.75rem 1rem; }
  .home-stat-value { font-size: 1.5rem; }
}

</style>
<script>
// Offline service worker — only inside the app / installed PWA (UA-gated; browsers unaffected).
if ('serviceWorker' in navigator && (navigator.userAgent.indexOf('SingOpKoelschApp') !== -1 || window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true)) {
  window.addEventListener('load', function () {
    navigator.serviceWorker.register('/sw.js?v=<?= @filemtime(__DIR__ . '/sw.js') ?: '1' ?>').then(function () {
      // Native app → download ALL songs for full offline (page-driven; robust in WKWebView).
      if (navigator.userAgent.indexOf('SingOpKoelschApp') !== -1) setTimeout(sokDownloadAllSongs, 1500);
    }).catch(function () {});
  });
}

function sokToast(msg, fade) {
  var t = document.getElementById('sok-off-toast');
  if (!t) {
    t = document.createElement('div'); t.id = 'sok-off-toast';
    t.style.cssText = 'position:fixed;left:50%;bottom:calc(18px + env(safe-area-inset-bottom,0px));transform:translateX(-50%);z-index:99999;background:#1c2128;color:#e5e7eb;border:1px solid #334155;border-radius:999px;padding:9px 16px;font:600 13px/1.2 -apple-system,BlinkMacSystemFont,sans-serif;box-shadow:0 8px 24px rgba(0,0,0,.45);max-width:88%;text-align:center;';
    document.body.appendChild(t);
  }
  t.textContent = msg;
  if (fade) setTimeout(function () { t.style.transition = 'opacity .5s'; t.style.opacity = '0'; setTimeout(function () { if (t.parentNode) t.remove(); }, 600); }, 3000);
}

async function sokDownloadAllSongs() {
  if (!('caches' in window) || !('serviceWorker' in navigator)) return;
  try {
    await navigator.serviceWorker.ready;
    if (!navigator.serviceWorker.controller) {
      await new Promise(function (res) {
        navigator.serviceWorker.addEventListener('controllerchange', res, { once: true });
        setTimeout(res, 3000);
      });
    }
    if (!navigator.serviceWorker.controller) { sokToast('⚠ Offline-Speicher nicht verfügbar', true); return; }
    var data = await (await fetch('/offline_manifest.php', { cache: 'reload' })).json();
    var urls = (data && data.urls) || [], downloaded = 0, idx = 0;
    async function worker() {
      while (idx < urls.length) {
        var u = urls[idx++];
        try {
          if (!(await caches.match(u))) {
            if (downloaded === 0) sokToast('📥 Lade Lieder für offline …');
            var r = await fetch(u, { credentials: 'same-origin' });
            if (r && r.ok) { downloaded++; if (downloaded % 10 === 0) sokToast('📥 Lade Lieder … ' + downloaded + '/' + urls.length); }
          }
        } catch (e) {}
      }
    }
    await Promise.all([worker(), worker(), worker(), worker(), worker(), worker()]);
    if (downloaded > 0) {
      var sample = urls.slice(-3), verified = 0;
      for (var j = 0; j < sample.length; j++) { if (await caches.match(sample[j])) verified++; }
      sokToast(verified > 0 ? ('✓ ' + downloaded + ' Lieder offline geladen') : '⚠ Offline-Speicher klappt nicht (Service Worker)', true);
    }
  } catch (e) {}
}
</script>
</head>
<body class="home-page">

  <?php require_once "partials/nav.php"; ?>

  <main class="home-main">
    <?php if (isset($_GET['deleted'])): ?>
      <div class="alert alert-success" style="max-width:520px;margin:0 auto 1.4em auto;">
        <?= htmlspecialchars(t('del.deleted_msg')) ?>
      </div>
    <?php endif; ?>
    <h1 class="home-logo">Sing op <span class="ac">Kölsch</span></h1>
    <p class="home-tagline"><?= htmlspecialchars(t('home.tagline')) ?></p>

    <div class="home-search-wrap">
      <form class="home-search" id="home-search-form" action="/lieder.php" method="get" role="search"
            aria-autocomplete="list" aria-haspopup="listbox" aria-expanded="false">
        <svg class="home-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <circle cx="11" cy="11" r="7"/>
          <line x1="20" y1="20" x2="16.65" y2="16.65"/>
        </svg>
        <input type="search" name="lyrics" id="home-search-input" autocomplete="off" autofocus
               placeholder="<?= htmlspecialchars(t('home.search_placeholder')) ?>"
               role="combobox" aria-controls="home-suggest" aria-activedescendant="" />
        <button type="submit" tabindex="-1" aria-hidden="true" style="display:none;"></button>
        <button type="button" id="home-mic-btn" class="home-mic-btn" title="<?= htmlspecialchars(t('home.mic_title')) ?>" aria-label="<?= htmlspecialchars(t('home.mic_title')) ?>">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/>
            <path d="M19 10v2a7 7 0 0 1-14 0v-2"/>
            <line x1="12" y1="19" x2="12" y2="23"/>
            <line x1="8" y1="23" x2="16" y2="23"/>
          </svg>
        </button>
      </form>
      <ul id="home-suggest" class="home-suggest" role="listbox" hidden></ul>
    </div>

    <div class="home-stats">
      <a href="/lieder.php" class="home-stat">
        <span class="home-stat-value"><?= number_format($stats['total_lyrics']) ?></span>
        <span class="home-stat-label"><?= htmlspecialchars(t('home.stat_songs')) ?></span>
      </a>
      <a href="/lieder.php?sort=band" class="home-stat">
        <span class="home-stat-value"><?= number_format($stats['total_bands']) ?></span>
        <span class="home-stat-label"><?= htmlspecialchars(t('home.stat_artists')) ?></span>
      </a>
      <?php /* #11 Zufälliger Song Button */ ?>
      <a href="/api/songs/random?redirect=1" class="home-stat" id="random-song-btn" title="Zufälligen Song öffnen">
        <span class="home-stat-value" style="font-size:1.1rem;">🎲</span>
        <span class="home-stat-label">Zufälliger Song</span>
      </a>
    </div>

    <?php if ($_sotd): /* #6 Song des Tages */ ?>
    <div style="margin-top:1.75rem;width:100%;max-width:400px;">
      <p style="font-size:0.72rem;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:var(--text-3);margin:0 0 0.6rem;">🎵 Song des Tages</p>
      <a href="/detail.php?lyrics=<?= (int)$_sotd['id'] ?>" style="display:flex;align-items:center;gap:0.75rem;padding:0.75rem 1rem;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);border-radius:12px;text-decoration:none;transition:background 0.18s,border-color 0.18s;">
        <?php if (!empty($_sotd['cover_url'])): ?>
          <img src="<?= htmlspecialchars($_sotd['cover_url']) ?>" style="width:44px;height:44px;border-radius:6px;object-fit:cover;flex-shrink:0;" alt="">
        <?php else: ?>
          <span style="width:44px;height:44px;border-radius:6px;background:rgba(220,38,38,0.12);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:1.3rem;">🎵</span>
        <?php endif; ?>
        <div style="text-align:left;min-width:0;">
          <div style="font-weight:700;font-size:0.92rem;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($_sotd['title']) ?></div>
          <div style="font-size:0.8rem;color:rgba(255,255,255,0.55);"><?= htmlspecialchars($_sotd['band_name'] ?? '–') ?></div>
        </div>
      </a>
    </div>
    <?php endif; ?>
  </main>

  <footer class="home-footer">
    <?php if (!isLoggedIn()): ?>
      <?= t('home.footer_login_hint') ?>
    <?php else: ?>
      <?= htmlspecialchars(t('home.footer_logged_in', ['name' => $_SESSION['name'] ?? ''])) ?>
    <?php endif; ?>
    <span style="margin:0 0.45em;opacity:0.5;">·</span><a href="/app" style="font-weight:600;">App installieren</a>
    <span style="margin:0 0.45em;opacity:0.5;">·</span><a href="/rangliste.php">Rangliste</a>
    <span style="margin:0 0.45em;opacity:0.5;">·</span><a href="/merch.php">❤️ Unterstützen</a>
  </footer>

<script>
// User menu + dark toggle handled by partials/nav.php

// ── Shazam mic button ──────────────────────────────
const micBtn    = document.getElementById('home-mic-btn');
const searchInp = document.getElementById('home-search-input');
let isRecording = false, mediaRecorder, mediaStream, chunks = [];

micBtn.addEventListener('click', () => isRecording ? stopMic() : startMic());

function startMic() {
  if (!navigator.mediaDevices?.getUserMedia) {
    alert(<?= json_encode(t('home.no_mic')) ?>);
    return;
  }
  navigator.mediaDevices.getUserMedia({ audio: true }).then(s => {
    mediaStream = s;
    mediaRecorder = new MediaRecorder(s);
    chunks = [];
    mediaRecorder.ondataavailable = e => chunks.push(e.data);
    mediaRecorder.onstop = async () => {
      const fd = new FormData();
      fd.append("audio", new Blob(chunks, { type: "audio/webm" }), "rec.webm");
      chunks = [];
      try {
        const res = await fetch("/shazam.php", { method: "POST", body: fd });
        const data = await res.json();
        if (!data.error && data.title) {
          stopMic();
          // Redirect to lieder.php with the recognized title
          window.location = '/lieder.php?lyrics=' + encodeURIComponent(data.title);
        } else if (isRecording) {
          // Keep listening (5s chunks)
          setTimeout(() => {
            if (isRecording) {
              mediaRecorder.start();
              setTimeout(() => {
                if (isRecording && mediaRecorder.state !== "inactive") mediaRecorder.stop();
              }, 5000);
            }
          }, 500);
        }
      } catch(e) { console.error("Shazam-Fehler:", e); }
    };
    mediaRecorder.start();
    setTimeout(() => {
      if (isRecording && mediaRecorder.state !== "inactive") mediaRecorder.stop();
    }, 5000);
    isRecording = true;
    micBtn.classList.add('recording');
  }).catch(e => alert('Mikrofon-Zugriff verweigert.'));
}

function stopMic() {
  isRecording = false;
  if (mediaRecorder && mediaRecorder.state !== "inactive") mediaRecorder.stop();
  if (mediaStream) mediaStream.getTracks().forEach(t => t.stop());
  micBtn.classList.remove('recording');
}

// ── Search autocomplete ───────────────────────────
(function() {
  const input  = document.getElementById('home-search-input');
  const list   = document.getElementById('home-suggest');
  const form   = document.getElementById('home-search-form');
  let debounce = null, abortCtl = null;
  let items    = [];
  let activeIdx = -1;

  function escHtml(s) {
    return (s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]);
  }

  function close() {
    list.hidden = true;
    list.innerHTML = '';
    items = [];
    activeIdx = -1;
    form.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-activedescendant', '');
  }

  function setActive(idx) {
    const old = list.querySelector('.suggest-item.is-active');
    if (old) old.classList.remove('is-active');
    activeIdx = idx;
    if (idx < 0 || idx >= items.length) {
      input.setAttribute('aria-activedescendant', '');
      return;
    }
    const el = list.children[idx];
    if (el) {
      el.classList.add('is-active');
      el.scrollIntoView({ block: 'nearest' });
      input.setAttribute('aria-activedescendant', el.id);
    }
  }

  function render(results, q) {
    items = results;
    list.innerHTML = '';
    if (!results.length) {
      const li = document.createElement('li');
      li.className = 'suggest-empty';
      li.textContent = q.length < 1 ? '' : 'Keine Treffer. Drücke Enter, um trotzdem zu suchen.';
      list.appendChild(li);
      list.hidden = false;
      form.setAttribute('aria-expanded', 'true');
      return;
    }
    results.forEach((r, i) => {
      const li = document.createElement('li');
      li.className = 'suggest-item';
      li.id = 'sug-' + i;
      li.setAttribute('role', 'option');
      const cover = r.cover_url
        ? `<img class="suggest-cover" src="${escHtml(r.cover_url)}" alt="" loading="lazy">`
        : `<span class="suggest-cover-placeholder" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg></span>`;
      const kindLabel = r.kind === 'fuzzy' ? 'ähnlich' : '';
      li.innerHTML = `
        ${cover}
        <div class="suggest-text">
          <span class="suggest-title">${escHtml(r.title)}</span>
          <span class="suggest-band">${escHtml(r.band) || ''}${r.album ? (r.band ? ' · ' : '') + escHtml(r.album) : ''}</span>
        </div>
        ${kindLabel ? `<span class="suggest-kind">${kindLabel}</span>` : ''}
      `;
      li.addEventListener('mousedown', e => {
        e.preventDefault();
        window.location = '/detail.php?lyrics=' + r.id;
      });
      list.appendChild(li);
    });
    list.hidden = false;
    form.setAttribute('aria-expanded', 'true');
  }

  async function fetchSuggest(q) {
    if (abortCtl) abortCtl.abort();
    abortCtl = new AbortController();
    try {
      const res = await fetch('/api_suggest.php?q=' + encodeURIComponent(q), { signal: abortCtl.signal });
      const data = await res.json();
      if (data.q !== input.value.trim()) return; // stale response
      render(data.results || [], q);
    } catch (e) { /* aborted or network */ }
  }

  input.addEventListener('input', () => {
    const q = input.value.trim();
    clearTimeout(debounce);
    if (q.length < 1) { close(); return; }
    debounce = setTimeout(() => fetchSuggest(q), 140);
  });

  input.addEventListener('keydown', e => {
    if (list.hidden || !items.length) {
      if (e.key === 'ArrowDown' && input.value.trim()) fetchSuggest(input.value.trim());
      return;
    }
    if (e.key === 'ArrowDown') { e.preventDefault(); setActive((activeIdx + 1) % items.length); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setActive((activeIdx - 1 + items.length) % items.length); }
    else if (e.key === 'Enter' && activeIdx >= 0) {
      e.preventDefault();
      window.location = '/detail.php?lyrics=' + items[activeIdx].id;
    } else if (e.key === 'Escape') {
      close();
    }
  });

  document.addEventListener('click', e => {
    if (!e.target.closest('.home-search-wrap')) close();
  });
  input.addEventListener('focus', () => {
    const q = input.value.trim();
    if (q.length >= 1) fetchSuggest(q);
  });
})();
</script>
</body>
</html>
