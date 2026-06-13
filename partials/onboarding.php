<?php
/* Onboarding overlay — shown once in PWA/native-app context. */
$vapid_pub = '';
if (function_exists('push_vapid')) {
    $v = push_vapid();
    $vapid_pub = htmlspecialchars($v['publicKey'] ?? '', ENT_QUOTES);
}
?>
<div id="sok-ob" style="display:none" aria-modal="true" role="dialog">
<style>
#sok-ob {
  position: fixed; inset: 0; z-index: 9000;
  background: var(--bg, #eef1f6);
  flex-direction: column;
}
#sok-ob.ob-visible { display: flex; }
#sok-ob.ob-hidden, #sok-ob[style*="display:none"], #sok-ob[style*="display: none"] { display: none !important; }

/* Skip: small circle, top-right */
.ob-skip {
  position: absolute;
  top: calc(env(safe-area-inset-top, 0px) + 1rem);
  right: 1.25rem;
  z-index: 1;
  width: 34px; height: 34px; border-radius: 50%;
  background: rgba(0,0,0,0.08);
  border: none; cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  color: var(--text-2, #475569);
  transition: background 0.15s, transform 0.15s;
}
.ob-skip:active { transform: scale(0.9); }
.ob-skip svg { width: 18px; height: 18px; }

/* Slides */
.ob-slides {
  flex: 1; overflow: hidden; position: relative;
}
.ob-slide {
  position: absolute; inset: 0;
  display: flex; flex-direction: column;
  align-items: center; justify-content: center;
  padding: calc(env(safe-area-inset-top, 0px) + 3rem) 2.5rem 7rem;
  text-align: center;
  opacity: 0; transform: translateX(60px);
  transition: opacity 0.32s ease, transform 0.32s ease;
  pointer-events: none;
}
.ob-slide.ob-active  { opacity: 1; transform: translateX(0); pointer-events: auto; }
.ob-slide.ob-exit    { opacity: 0; transform: translateX(-60px); }

.ob-icon {
  width: 84px; height: 84px; border-radius: 22px;
  background: var(--primary, #2563eb);
  display: flex; align-items: center; justify-content: center;
  margin-bottom: 1.75rem;
  box-shadow: 0 8px 28px rgba(37,99,235,0.28);
  flex-shrink: 0;
}
.ob-icon svg { width: 44px; height: 44px; fill: #fff; }
.ob-icon.ob-icon-notif { background: #16a34a; box-shadow: 0 8px 28px rgba(22,163,74,0.28); }
.ob-icon.ob-icon-community { background: linear-gradient(135deg,#7c3aed,#a855f7); box-shadow: 0 8px 28px rgba(124,58,237,0.28); }
.ob-icon.ob-icon-brand { background: linear-gradient(135deg,#1e3a8a,#2563eb); padding: 0; overflow: hidden; }
.ob-icon.ob-icon-brand img { width: 84px; height: 84px; border-radius: 22px; object-fit: cover; }

.ob-title {
  font-size: 1.55rem; font-weight: 700; line-height: 1.2;
  color: var(--text, #0f172a); margin: 0 0 0.8rem;
}
.ob-desc {
  font-size: 1rem; line-height: 1.6;
  color: var(--text-2, #475569); max-width: 300px; margin: 0 auto;
}
.ob-desc strong { color: var(--primary, #2563eb); font-weight: 600; }

.ob-notif-btn {
  margin-top: 1.75rem;
  padding: 0.8rem 1.75rem;
  border-radius: 9999px;
  background: #16a34a; color: #fff;
  font-weight: 600; font-size: 0.95rem; border: none; cursor: pointer;
  box-shadow: 0 4px 14px rgba(22,163,74,0.32);
  transition: opacity 0.18s, transform 0.15s;
}
.ob-notif-btn:active { transform: scale(0.97); }
.ob-notif-btn:disabled { opacity: 0.45; cursor: default; }
.ob-notif-status { margin-top: 0.7rem; font-size: 0.88rem; color: var(--text-2, #475569); min-height: 1.2em; }

/* Bottom nav — no bar, just floating controls */
.ob-nav {
  display: flex; flex-direction: column; align-items: center; gap: 0.9rem;
  padding-bottom: calc(env(safe-area-inset-bottom, 0px) + 1.75rem);
}
.ob-dots { display: flex; gap: 7px; align-items: center; }
.ob-dot {
  width: 6px; height: 6px; border-radius: 50%;
  background: var(--border, #dde2eb);
  transition: background 0.2s, width 0.2s, border-radius 0.2s;
}
.ob-dot.ob-dot-active {
  width: 18px; border-radius: 3px;
  background: var(--primary, #2563eb);
}
.ob-next {
  width: 52px; height: 52px; border-radius: 50%;
  background: var(--primary, #2563eb); color: #fff;
  border: none; cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  box-shadow: 0 4px 16px rgba(37,99,235,0.32);
  transition: transform 0.15s, box-shadow 0.15s;
}
.ob-next svg { width: 26px; height: 26px; }
.ob-next:active { transform: scale(0.93); box-shadow: 0 2px 8px rgba(37,99,235,0.2); }
</style>

<button class="ob-skip" id="ob-skip" title="Überspringen">
  <svg viewBox="0 0 24 24" fill="currentColor"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
</button>

<div class="ob-slides">

  <div class="ob-slide ob-active" data-slide="0">
    <div class="ob-icon ob-icon-brand">
      <img src="/apple-touch-icon.png" alt="" onerror="this.src='/icon-512.png'">
    </div>
    <h1 class="ob-title">Willkommen bei<br>Sing op Kölsch!</h1>
    <p class="ob-desc">Deine App für kölsche Liedtexte – immer dabei, auch offline.</p>
  </div>

  <div class="ob-slide" data-slide="1">
    <div class="ob-icon">
      <svg viewBox="0 0 24 24"><path d="M15.5 14h-.79l-.28-.27A6.47 6.47 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
    </div>
    <h2 class="ob-title">Songs & Texte</h2>
    <p class="ob-desc">Durchsuche hunderte kölsche Lieder und lies die Texte direkt in der App – <strong>auch ohne Internet</strong>.</p>
  </div>

  <div class="ob-slide" data-slide="2">
    <div class="ob-icon ob-icon-community">
      <svg viewBox="0 0 24 24"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>
    </div>
    <h2 class="ob-title">Die Community hilft mit</h2>
    <p class="ob-desc">Texte unvollständig oder fehlerhaft? Schlag Korrekturen vor – die Community vervollständigt die Liedtexte gemeinsam.</p>
  </div>

  <div class="ob-slide" data-slide="3">
    <div class="ob-icon ob-icon-notif">
      <svg viewBox="0 0 24 24"><path d="M12 22c1.1 0 2-.9 2-2h-4a2 2 0 0 0 2 2zm6-6v-5c0-3.07-1.63-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5S10.5 3.17 10.5 4v.68C7.64 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/></svg>
    </div>
    <h2 class="ob-title">Bleib informiert</h2>
    <p class="ob-desc">Erhalte eine Nachricht, wenn dein Textvorschlag beantwortet oder umgesetzt wurde.</p>
    <button class="ob-notif-btn" id="ob-notif-btn">Benachrichtigungen aktivieren</button>
    <p class="ob-notif-status" id="ob-notif-status"></p>
  </div>

</div>

<div class="ob-nav">
  <div class="ob-dots" id="ob-dots">
    <span class="ob-dot ob-dot-active"></span>
    <span class="ob-dot"></span>
    <span class="ob-dot"></span>
    <span class="ob-dot"></span>
  </div>
  <button class="ob-next" id="ob-next" title="Weiter">
    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M8.59 16.59L13.17 12 8.59 7.41 10 6l6 6-6 6z"/></svg>
  </button>
</div>

</div>

<script>
(function() {
  var STORAGE_KEY = 'sok_onboarding_v1';
  var VAPID_KEY = '<?= $vapid_pub ?>';
  var isApp = document.documentElement.classList.contains('app')
           || document.documentElement.classList.contains('pwa')
           || window.matchMedia('(display-mode: standalone)').matches
           || window.navigator.standalone === true;

  if (!isApp) return;
  if (localStorage.getItem(STORAGE_KEY)) return;

  var ob     = document.getElementById('sok-ob');
  var slides = Array.from(ob.querySelectorAll('.ob-slide'));
  var dots   = Array.from(ob.querySelectorAll('.ob-dot'));
  var next   = document.getElementById('ob-next');
  var skip   = document.getElementById('ob-skip');
  var cur    = 0;

  function goTo(idx) {
    slides[cur].classList.add('ob-exit');
    slides[cur].classList.remove('ob-active');
    var prev = cur;
    setTimeout(function() { slides[prev].classList.remove('ob-exit'); cur = idx; activate(); }, 320);
  }

  function activate() {
    slides[cur].classList.add('ob-active');
    dots.forEach(function(d, i) { d.classList.toggle('ob-dot-active', i === cur); });
    var isLast = cur === slides.length - 1;
    next.title = isLast ? 'Loslegen' : 'Weiter';
    next.innerHTML = isLast
      ? '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>'
      : '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M8.59 16.59L13.17 12 8.59 7.41 10 6l6 6-6 6z"/></svg>';
  }

  next.addEventListener('click', function() {
    if (cur < slides.length - 1) goTo(cur + 1);
    else finish();
  });
  skip.addEventListener('click', finish);

  function finish() {
    localStorage.setItem(STORAGE_KEY, '1');
    ob.classList.remove('ob-visible');
    ob.classList.add('ob-hidden');
  }

  // Notification button
  var notifBtn    = document.getElementById('ob-notif-btn');
  var notifStatus = document.getElementById('ob-notif-status');

  if (!('Notification' in window) || !('serviceWorker' in navigator)) {
    if (notifBtn) notifBtn.style.display = 'none';
  }

  function b64ToUint8(s) {
    var p = '='.repeat((4 - s.length % 4) % 4);
    var b = atob((s + p).replace(/-/g,'+').replace(/_/g,'/'));
    var r = new Uint8Array(b.length);
    for (var i = 0; i < b.length; i++) r[i] = b.charCodeAt(i);
    return r;
  }

  if (notifBtn) {
    notifBtn.addEventListener('click', async function() {
      notifBtn.disabled = true;
      try {
        var permission = await Notification.requestPermission();
        if (permission !== 'granted') {
          notifStatus.textContent = 'Benachrichtigungen abgelehnt.';
          notifBtn.disabled = false;
          return;
        }
        var pubKey = VAPID_KEY;
        if (!pubKey) {
          var data = await (await fetch('/push_api.php?action=vapid')).json();
          pubKey = data.publicKey || '';
        }
        if (!pubKey) { notifStatus.textContent = 'Server nicht bereit.'; notifBtn.disabled = false; return; }
        var reg = await navigator.serviceWorker.ready;
        var sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64ToUint8(pubKey) });
        await fetch('/push_api.php?action=subscribe', {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ subscription: sub })
        });
        notifStatus.textContent = 'Aktiviert!';
        notifBtn.textContent = 'Aktiviert';
        setTimeout(function() { next.click(); }, 1000);
      } catch(e) {
        notifStatus.textContent = 'Fehler: ' + (e.message || e);
        notifBtn.disabled = false;
      }
    });
  }

  setTimeout(function() { ob.style.display = ''; ob.classList.add('ob-visible'); }, 300);
})();
</script>
