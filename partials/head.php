<?php
// Resolve dark mode from session (set by protect.php) — default: dark
$_dark = isset($_SESSION['dark_mode']) ? !empty($_SESSION['dark_mode']) : true;
$_title = isset($pageTitle) ? htmlspecialchars($pageTitle) : 'Sing op Kölsch';
// Cache-busting CSS version (file modification time)
$_cssVer = @filemtime(__DIR__ . '/../style.css') ?: time();
?>
<!DOCTYPE html>
<html lang="de"<?= $_dark ? ' class="dark"' : '' ?>>
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1<?= (strpos($_SERVER['HTTP_USER_AGENT'] ?? '', 'SingOpKoelschApp') !== false) ? ', maximum-scale=1, user-scalable=no' : '' ?>, viewport-fit=cover" />
<meta name="apple-mobile-web-app-title" content="Sing Op Kölsch">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="theme-color" content="#0a0a0a" media="(prefers-color-scheme: dark)">
<meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
<?php $__iv = @filemtime(__DIR__ . '/../apple-touch-icon.png') ?: time(); ?>
<link rel="apple-touch-icon" href="/apple-touch-icon.png?v=<?= $__iv ?>">
<link rel="icon" type="image/png" href="/favicon.png?v=<?= $__iv ?>">
<link rel="icon" href="/favicon.ico?v=<?= $__iv ?>" sizes="any">
<link rel="manifest" href="/manifest.webmanifest">
<meta name="mobile-web-app-capable" content="yes">
<link rel="apple-touch-startup-image" href="/logo.png">
<title><?= $_title ?></title>
<script>
// ── Theme bootstrap — runs before paint to avoid flash ─────────────
// Source of truth: localStorage('home_dark') = '1' | '0'.
// Server hint comes from <html class="dark"> (session pref).
// localStorage wins if set; otherwise default to dark.
(function(){
  try {
    var ls = localStorage.getItem('home_dark');
    var serverDark = document.documentElement.classList.contains('dark');
    var wantDark = (ls === null) ? serverDark : (ls === '1');
    // If no localStorage value yet, seed it from server pref (or default dark)
    if (ls === null) localStorage.setItem('home_dark', wantDark ? '1' : '0');
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
<link rel="stylesheet" href="/style.css?v=<?= $_cssVer ?>" />
<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
<meta http-equiv="Pragma" content="no-cache" />
<meta http-equiv="Expires" content="0" />
<script>
// Offline service worker — only inside the Sing op Kölsch app (UA-gated, browsers unaffected).
if ('serviceWorker' in navigator && (navigator.userAgent.indexOf('SingOpKoelschApp') !== -1 || window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true)) {
  window.addEventListener('load', function () { navigator.serviceWorker.register('/sw.js?v=<?= @filemtime(__DIR__ . '/../sw.js') ?: '1' ?>').catch(function(){}); });
}
// PWA/app: pin body padding-top to the ACTUAL rendered navbar height (handles safe-area dynamically).
function sokFitNav() {
  try {
    var isPwa = window.navigator.standalone === true || window.matchMedia('(display-mode: standalone)').matches;
    var isApp = document.documentElement.classList.contains('app');
    if (!isPwa && !isApp) return;
    var nav = document.querySelector('.navbar');
    if (nav && !document.body.classList.contains('home-page')) {
      var h = nav.getBoundingClientRect().height;
      if (h > 0) document.body.style.setProperty('padding-top', Math.round(h) + 'px', 'important');
    }
  } catch (e) {}
}
window.addEventListener('load', sokFitNav);
window.addEventListener('resize', function () { setTimeout(sokFitNav, 100); });
window.addEventListener('orientationchange', function () { setTimeout(sokFitNav, 250); });
</script>
</head>
<body<?= !empty($bodyClass) ? ' class="' . htmlspecialchars($bodyClass) . '"' : '' ?>>
