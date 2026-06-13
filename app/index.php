<?php
// Public install landing page — reachable at /app. Offers TWO ways to install:
//   1) PWA "Add to Home Screen" (no SideStore, recommended)
//   2) SideStore (native app) via the AltStore source.
// Launched-from-home-screen visits bounce to "/".
require_once __DIR__ . '/../protect.php';   // invite gate also covers the download page
header('Cache-Control: no-cache');
?>
<!DOCTYPE html>
<html lang="de" class="dark">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
<title>App installieren – Sing op Kölsch</title>
<meta name="apple-mobile-web-app-title" content="Sing op Kölsch">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="theme-color" content="#0d1117">
<?php $__iv = @filemtime(__DIR__ . '/../apple-touch-icon.png') ?: time(); ?>
<link rel="apple-touch-icon" href="/apple-touch-icon.png?v=<?= $__iv ?>">
<link rel="icon" type="image/png" href="/favicon.png?v=<?= $__iv ?>">
<link rel="manifest" href="/manifest.webmanifest">
<script>
  if (window.navigator.standalone === true || window.matchMedia('(display-mode: standalone)').matches) {
    location.replace('/');
  }
</script>
<style>
  :root { color-scheme: dark; }
  * { box-sizing: border-box; }
  html, body { margin: 0; min-height: 100%; }
  body {
    background: radial-gradient(ellipse at 50% -10%, rgba(220,38,38,0.22), transparent 55%), #0d1117;
    color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    display: flex; flex-direction: column; align-items: center;
    padding: max(env(safe-area-inset-top), 30px) 22px 44px; text-align: center;
  }
  .icon { width: 104px; height: 104px; border-radius: 24px; display: block; margin: 6px auto 16px;
          box-shadow: 0 12px 36px rgba(220,38,38,0.30), 0 2px 0 rgba(255,255,255,0.06) inset; }
  h1 { font-size: 1.8rem; font-weight: 800; letter-spacing: -0.03em; margin: 0 0 4px; }
  h1 .ac { background: linear-gradient(135deg,#ef4444,#dc2626 40%,#b91c1c 75%,#7f1d1d);
           -webkit-background-clip: text; background-clip: text; color: transparent; -webkit-text-fill-color: transparent; }
  .sub { color: #94a3b8; margin: 0 0 6px; font-size: 1rem; }
  .badge { display:inline-block; margin: 8px 0 24px; font-size: .8rem; color:#cbd5e1;
           background: rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.12); padding: 5px 12px; border-radius: 999px; }
  .card { width: 100%; max-width: 430px; text-align: left; background: #1c2128; border: 1px solid #334155;
          border-radius: 16px; padding: 16px 18px 4px; margin: 0 auto; }
  .card-title { font-weight: 800; font-size: 1.02rem; margin: 0 0 2px; display:flex; align-items:center; gap:8px; }
  .card-title .tag { font-size:.68rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em;
                     padding:3px 8px; border-radius:999px; }
  .tag-green { background: rgba(34,197,94,0.15); color:#4ade80; border:1px solid rgba(34,197,94,0.3); }
  .tag-blue  { background: rgba(96,165,250,0.15); color:#93c5fd; border:1px solid rgba(96,165,250,0.3); }
  .card-note { color:#94a3b8; font-size:.84rem; margin: 2px 0 12px; line-height:1.45; }
  .adv { list-style:none; margin:0 0 14px; padding:0; }
  .adv li { font-size:.9rem; color:#cbd5e1; padding:3px 0; line-height:1.4; }
  .adv .ok { color:#4ade80; font-weight:800; margin-right:7px; }
  .adv .warn { color:#f59e0b; font-weight:800; margin-right:7px; }
  .step { display: flex; gap: 13px; align-items: flex-start; padding: 0 0 14px; }
  .num { flex: 0 0 26px; width: 26px; height: 26px; border-radius: 50%;
         background: linear-gradient(135deg,#ef4444,#dc2626); color:#fff; font-weight: 700;
         display: flex; align-items: center; justify-content: center; font-size: .88rem; margin-top: 1px; }
  .step .t { font-size: .96rem; line-height: 1.45; }
  .step .t b { color: #fff; }
  .shareico { display:inline-flex; vertical-align:-5px; margin:0 2px; width:21px; height:21px; color:#60a5fa; }
  .or { color:#64748b; font-weight:700; font-size:.82rem; letter-spacing:.1em; margin: 18px 0; }
  .urlbox { display:flex; gap:8px; align-items:center; background:#0d1117; border:1px solid #334155;
            border-radius:10px; padding:9px 11px; margin: 4px 0 14px; }
  .urlbox code { flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
                 font-size:.82rem; color:#cbd5e1; }
  .copybtn { flex:0 0 auto; background:#2563eb; color:#fff; border:none; border-radius:8px;
             padding:7px 12px; font-weight:600; font-size:.82rem; cursor:pointer; }
  .copybtn.ok { background:#16a34a; }
  .ssbtns { display:flex; gap:8px; flex-wrap:wrap; margin: 4px 0 8px; }
  .ssbtn { flex:1; min-width:120px; text-align:center; background: rgba(96,165,250,0.14); border:1px solid rgba(96,165,250,0.35);
           color:#93c5fd; text-decoration:none; font-weight:600; font-size:.9rem; padding:10px 14px; border-radius:10px; }
  .note { max-width: 430px; color:#64748b; font-size:.82rem; line-height:1.5; margin: 20px auto 0; }
  .open { display:inline-block; margin-top: 22px; color:#f87171; text-decoration:none; font-weight:600; font-size:.95rem; }
  #not-ios { display:none; }
</style>
</head>
<body>
  <img class="icon" src="/icon-512.png?v=<?= $__iv ?>" alt="Sing op Kölsch">
  <h1>Sing op <span class="ac">Kölsch</span></h1>
  <p class="sub">Die App fürs iPhone</p>
  <span class="badge">Gratis · ohne App Store · offline-fähig</span>

  <!-- Option 1: PWA -->
  <div class="card" id="ios-steps">
    <div class="card-title">Zum Home-Bildschirm <span class="tag tag-green">Empfohlen</span></div>
    <p class="card-note">Am einfachsten. Ohne SideStore, ohne Konto.</p>
    <ul class="adv">
      <li><span class="ok">✓</span> Ohne SideStore, ohne Apple-Konto</li>
      <li><span class="ok">✓</span> Push-Benachrichtigungen</li>
      <li><span class="ok">✓</span> Offline für <b>besuchte</b> Lieder</li>
    </ul>
    <div class="step">
      <div class="num">1</div>
      <div class="t">Unten in Safari auf <b>Teilen</b>
        <svg class="shareico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 16V4"/><path d="M8 8l4-4 4 4"/><path d="M4 12v6a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-6"/></svg>
        tippen.</div>
    </div>
    <div class="step"><div class="num">2</div><div class="t"><b>„Zum Home-Bildschirm"</b> wählen.</div></div>
    <div class="step"><div class="num">3</div><div class="t"><b>„Hinzufügen"</b> tippen — fertig.</div></div>
  </div>

  <div class="or" id="or-divider">— oder —</div>

  <!-- Option 2: SideStore -->
  <div class="card" id="ss-steps">
    <div class="card-title">Mit SideStore <span class="tag tag-blue">Native App</span></div>
    <p class="card-note">Falls du SideStore schon hast.</p>
    <ul class="adv">
      <li><span class="ok">✓</span> <b>Alle Lieder offline</b> — auch die, die du nie geöffnet hast</li>
      <li><span class="ok">✓</span> Echte native App</li>
      <li><span class="warn">!</span> Braucht einen Sideloader (SideStore/AltStore)</li>
    </ul>
    <p class="card-note" style="margin-top:0;">Füge diese Quelle hinzu:</p>
    <div class="urlbox">
      <code id="src-url">https://singopkoelsch.de/app/altstore.json</code>
      <button class="copybtn" id="copybtn" type="button">Kopieren</button>
    </div>
    <div class="ssbtns">
      <a class="ssbtn" href="sidestore://source?url=https%3A%2F%2Fsingopkoelsch.de%2Fapp%2Faltstore.json">In SideStore öffnen</a>
      <a class="ssbtn" href="altstore://source?url=https%3A%2F%2Fsingopkoelsch.de%2Fapp%2Faltstore.json">In AltStore öffnen</a>
    </div>
    <p class="card-note" style="font-size:.78rem; margin:0 0 12px;">Anderer Sideloader? Quelle oben <b>kopieren</b> und in der App unter „Sources" / „Quellen" einfügen — funktioniert überall.</p>
    <div class="step"><div class="num">1</div><div class="t">Im Sideloader → <b>„Sources" → „+"</b> → URL einfügen.</div></div>
    <div class="step"><div class="num">2</div><div class="t"><b>„Sing op Kölsch"</b> antippen → installieren.</div></div>
  </div>

  <!-- non-iOS hint -->
  <div class="card" id="not-ios" style="margin-top:14px;">
    <div class="step" style="padding-bottom:18px;">
      <div class="num">!</div>
      <div class="t">Öffne diese Seite auf deinem <b>iPhone in Safari</b> — dann erscheinen hier die Anleitungen.</div>
    </div>
  </div>

  <a class="open" href="/">Erst mal nur im Browser öffnen →</a>

  <p class="note">
    Beide laufen im Vollbild. <b>Unterschied beim Offline-Zugriff:</b> die PWA speichert nur Lieder, die du <b>geöffnet</b> hast — die <b>native App (SideStore)</b> lädt <b>alle Lieder</b> herunter und funktioniert komplett offline. Im normalen Browser-Tab gibt es kein Offline.
  </p>

<script>
  (function () {
    var ua = navigator.userAgent;
    var isIOS = /iPhone|iPad|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    var isSafari = /^((?!chrome|android|crios|fxios|edgios).)*safari/i.test(ua);
    if (!(isIOS && isSafari)) {
      document.getElementById('ios-steps').style.display = 'none';
      document.getElementById('or-divider').style.display = 'none';
      document.getElementById('ss-steps').style.display = 'none';
      document.getElementById('not-ios').style.display = '';
    }
    var btn = document.getElementById('copybtn');
    if (btn) btn.addEventListener('click', function () {
      var url = document.getElementById('src-url').textContent;
      (navigator.clipboard ? navigator.clipboard.writeText(url) : Promise.reject()).then(function () {
        btn.textContent = 'Kopiert ✓'; btn.classList.add('ok');
        setTimeout(function () { btn.textContent = 'Kopieren'; btn.classList.remove('ok'); }, 1800);
      }).catch(function () { btn.textContent = 'Lange drücken'; });
    });
  })();
</script>
</body>
</html>
