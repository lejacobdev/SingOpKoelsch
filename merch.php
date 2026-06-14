<?php
// #83 Merchandise-Shop-Seite + #81 Support-Link
require_once "protect.php";
require_once "functions.php";

$pageTitle = "Merch & Support – Sing op Kölsch";
$_cssVer = @filemtime(__DIR__ . '/style.css') ?: time();
require_once "partials/head.php";
require_once "partials/nav.php";
?>
<main class="content" style="max-width:640px;">
  <a href="/" class="round-icon-btn" style="margin-bottom:1.25rem;display:inline-flex;" aria-label="Zurück">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
  </a>
  <h1 style="margin:0 0 0.4rem;">❤️ Unterstütze das Projekt</h1>
  <p style="color:var(--text-2);font-size:0.92rem;margin-bottom:2rem;line-height:1.6;">
    Sing op Kölsch ist ein kostenloses, community-getriebenes Projekt. Wenn du es unterstützen möchtest, hast du folgende Möglichkeiten:
  </p>

  <div style="display:flex;flex-direction:column;gap:1rem;margin-bottom:2.5rem;">
    <a href="https://ko-fi.com/singopkoelsch" target="_blank" rel="noopener noreferrer"
       style="display:flex;align-items:center;gap:1rem;padding:1rem 1.25rem;background:var(--card);border:1px solid var(--border);border-radius:14px;text-decoration:none;transition:border-color 0.15s;">
      <span style="font-size:2rem;">☕</span>
      <div>
        <div style="font-weight:700;font-size:0.95rem;color:var(--text);">Ko-fi Spende</div>
        <div style="font-size:0.82rem;color:var(--text-3);">Einmalige oder monatliche Unterstützung – ab 3 €</div>
      </div>
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-left:auto;flex-shrink:0;color:var(--text-3);"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
    </a>

    <a href="/app" style="display:flex;align-items:center;gap:1rem;padding:1rem 1.25rem;background:var(--card);border:1px solid var(--border);border-radius:14px;text-decoration:none;transition:border-color 0.15s;">
      <span style="font-size:2rem;">📱</span>
      <div>
        <div style="font-weight:700;font-size:0.95rem;color:var(--text);">App installieren</div>
        <div style="font-size:0.82rem;color:var(--text-3);">iOS-App via AltStore/SideStore – kostenlos</div>
      </div>
    </a>

    <a href="/lieder.php" style="display:flex;align-items:center;gap:1rem;padding:1rem 1.25rem;background:var(--card);border:1px solid var(--border);border-radius:14px;text-decoration:none;transition:border-color 0.15s;">
      <span style="font-size:2rem;">✏️</span>
      <div>
        <div style="font-weight:700;font-size:0.95rem;color:var(--text);">Texte verbessern</div>
        <div style="font-size:0.82rem;color:var(--text-3);">Korrekturen einreichen und Punkte sammeln</div>
      </div>
    </a>
  </div>

  <div style="background:rgba(220,38,38,0.08);border:1px solid rgba(220,38,38,0.2);border-radius:14px;padding:1.25rem 1.5rem;">
    <h2 style="margin:0 0 0.5rem;font-size:1rem;">🎽 Merch</h2>
    <p style="margin:0;color:var(--text-2);font-size:0.88rem;line-height:1.6;">
      Kölsch-Merch ist in Planung! Melde dich an und hinterlege deine E-Mail-Adresse – wir informieren dich, wenn der Shop öffnet. <em>Jeck & Stolz – Sing op Kölsch!</em>
    </p>
    <?php if (empty($_SESSION['user_id'])): ?>
      <a href="/login.php" class="btn btn-primary btn-sm" style="margin-top:0.85rem;">Anmelden & informiert werden</a>
    <?php else: ?>
      <p style="margin-top:0.75rem;font-size:0.82rem;color:#ef4444;font-weight:600;">✓ Du wirst benachrichtigt, wenn der Shop startet.</p>
    <?php endif; ?>
  </div>
</main>
<?php require_once "partials/footer.php"; ?>
