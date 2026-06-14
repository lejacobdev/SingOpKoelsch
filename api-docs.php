<?php
// #56 Öffentliche REST-API Dokumentation
require_once "protect.php";
require_once "functions.php";

$pageTitle = "API-Dokumentation – Sing op Kölsch";
$_cssVer = @filemtime(__DIR__ . '/style.css') ?: time();
require_once "partials/head.php";
require_once "partials/nav.php";
?>
<main class="content" style="max-width:820px;">
  <a href="/" class="round-icon-btn" style="margin-bottom:1.25rem;display:inline-flex;" aria-label="Zurück"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg></a>
  <h1 style="margin:0 0 0.4rem;">📡 REST-API Dokumentation</h1>
  <p style="color:var(--text-2);font-size:0.92rem;margin-bottom:2rem;">Basis-URL: <code>https://singopkoelsch.de/api</code> — alle Antworten als JSON.</p>

  <style>
    .api-section { margin-bottom: 2rem; }
    .api-section h2 { font-size: 1rem; font-weight: 700; margin: 0 0 1rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--border); }
    .api-endpoint { background: var(--card); border: 1px solid var(--border); border-radius: 10px; margin-bottom: 0.75rem; overflow: hidden; }
    .api-endpoint-head { display: flex; align-items: center; gap: 0.75rem; padding: 0.7rem 1rem; }
    .api-method { font-family: ui-monospace, monospace; font-size: 0.75rem; font-weight: 700; padding: 0.2em 0.55em; border-radius: 5px; }
    .api-method.get  { background: rgba(34,197,94,0.15); color: #16a34a; }
    .api-method.post { background: rgba(59,130,246,0.15); color: #2563eb; }
    .api-method.put  { background: rgba(234,179,8,0.15); color: #ca8a04; }
    .api-method.del  { background: rgba(239,68,68,0.15); color: #dc2626; }
    .api-path { font-family: ui-monospace, monospace; font-size: 0.88rem; color: var(--text); flex: 1; }
    .api-desc { font-size: 0.82rem; color: var(--text-3); }
    .api-body { padding: 0 1rem 0.75rem; font-size: 0.82rem; color: var(--text-2); line-height: 1.55; border-top: 1px solid var(--border); margin-top: 0.5rem; padding-top: 0.6rem; }
    .api-body code { background: var(--bg-alt); padding: 0.1em 0.4em; border-radius: 4px; font-size: 0.85em; }
    .auth-note { background: rgba(220,38,38,0.08); border: 1px solid rgba(220,38,38,0.2); border-radius: 8px; padding: 0.6rem 0.85rem; font-size: 0.82rem; color: var(--text-2); margin-bottom: 1.25rem; }
  </style>

  <div class="auth-note">🔒 <strong>Authentifizierung:</strong> Geschützte Endpunkte benötigen einen Bearer-Token im <code>Authorization</code>-Header: <code>Authorization: Bearer TOKEN</code>. Token wird beim Login zurückgegeben.</div>

  <div class="api-section">
    <h2>🔑 Auth</h2>
    <?php foreach ([
      ['POST', '/auth/login',    'Anmelden', 'Body: <code>{"email":"…","password":"…"}</code> → Gibt <code>token</code> zurück'],
      ['POST', '/auth/register', 'Registrieren', 'Body: <code>{"name":"…","email":"…","password":"…"}</code>'],
    ] as [$m, $p, $d, $b]): ?>
      <div class="api-endpoint"><div class="api-endpoint-head"><span class="api-method <?= strtolower($m) ?>"><?= $m ?></span><span class="api-path"><?= $p ?></span><span class="api-desc"><?= $d ?></span></div><?php if ($b): ?><div class="api-body"><?= $b ?></div><?php endif; ?></div>
    <?php endforeach; ?>
  </div>

  <div class="api-section">
    <h2>🎵 Songs</h2>
    <?php foreach ([
      ['GET',  '/songs',                'Song-Liste (paginiert)',      '🔒 Parameter: <code>q</code> (Suche), <code>page</code>, <code>per_page</code>'],
      ['GET',  '/songs/random',         'Zufälliger Song',             'Öffentlich zugänglich'],
      ['GET',  '/songs/random/favorite','Zufälliger Favoriten-Song',   '🔒 Aus den Favoriten des Nutzers, Fallback: beliebig'],
      ['GET',  '/songs/:id',            'Song-Detail',                 '🔒 Inkl. eigene Vorschläge'],
      ['POST', '/songs/:id/propose',    'Textvorschlag einreichen',    '🔒 Body: <code>{"lyrics":"…","notes":"…"}</code>'],
      ['POST', '/songs/:id/recommend',  'Song empfehlen',              '🔒 Body: <code>{"email":"empfänger@…","note":"…"}</code>'],
    ] as [$m, $p, $d, $b]): ?>
      <div class="api-endpoint"><div class="api-endpoint-head"><span class="api-method <?= strtolower($m) === 'delete' ? 'del' : strtolower($m) ?>"><?= $m ?></span><span class="api-path"><?= $p ?></span><span class="api-desc"><?= $d ?></span></div><?php if ($b): ?><div class="api-body"><?= $b ?></div><?php endif; ?></div>
    <?php endforeach; ?>
  </div>

  <div class="api-section">
    <h2>🎸 Bands</h2>
    <?php foreach ([
      ['GET',    '/bands',              'Alle Bands',                  '🔒 Mit Song-Anzahl'],
      ['GET',    '/bands/:id',          'Band-Detail + Songs',         '🔒'],
      ['GET',    '/bands/followed',     'Gefolgte Bands',              '🔒'],
      ['GET',    '/bands/:id/follow',   'Follow-Status prüfen',        '🔒'],
      ['POST',   '/bands/:id/follow',   'Band folgen',                 '🔒'],
      ['DELETE', '/bands/:id/follow',   'Band entfolgen',              '🔒'],
    ] as [$m, $p, $d, $b]): ?>
      <div class="api-endpoint"><div class="api-endpoint-head"><span class="api-method <?= strtolower($m) === 'delete' ? 'del' : strtolower($m) ?>"><?= $m ?></span><span class="api-path"><?= $p ?></span><span class="api-desc"><?= $d ?></span></div><?php if ($b): ?><div class="api-body"><?= $b ?></div><?php endif; ?></div>
    <?php endforeach; ?>
  </div>

  <div class="api-section">
    <h2>❤️ Favoriten</h2>
    <?php foreach ([
      ['GET',    '/favorites',     'Eigene Favoriten',    '🔒 Sortiert nach Erstellungsdatum'],
      ['POST',   '/favorites',     'Favorit hinzufügen',  '🔒 Body: <code>{"song_id": 123}</code>'],
      ['DELETE', '/favorites/:id', 'Favorit entfernen',   '🔒 <code>:id</code> = Song-ID'],
    ] as [$m, $p, $d, $b]): ?>
      <div class="api-endpoint"><div class="api-endpoint-head"><span class="api-method <?= strtolower($m) === 'delete' ? 'del' : strtolower($m) ?>"><?= $m ?></span><span class="api-path"><?= $p ?></span><span class="api-desc"><?= $d ?></span></div><?php if ($b): ?><div class="api-body"><?= $b ?></div><?php endif; ?></div>
    <?php endforeach; ?>
  </div>

  <div class="api-section">
    <h2>👤 Profil</h2>
    <?php foreach ([
      ['GET', '/profile',              'Eigenes Profil',         '🔒 Inkl. Einstellungen'],
      ['PUT', '/profile',              'Name ändern',            '🔒 Body: <code>{"name":"…"}</code>'],
      ['POST','/profile/password',     'Passwort ändern',        '🔒 Body: <code>{"current_password":"…","new_password":"…"}</code>'],
      ['PUT', '/profile/preferences',  'Einstellungen ändern',   '🔒 Body: <code>{"dark_mode":true,"notifications":true}</code>'],
    ] as [$m, $p, $d, $b]): ?>
      <div class="api-endpoint"><div class="api-endpoint-head"><span class="api-method <?= strtolower($m) === 'delete' ? 'del' : strtolower($m) ?>"><?= $m ?></span><span class="api-path"><?= $p ?></span><span class="api-desc"><?= $d ?></span></div><?php if ($b): ?><div class="api-body"><?= $b ?></div><?php endif; ?></div>
    <?php endforeach; ?>
  </div>

  <div class="api-section">
    <h2>📋 Vorschläge</h2>
    <?php foreach ([
      ['GET', '/proposals', 'Eigene Vorschläge', '🔒 Optional: <code>?status=pending|approved|rejected</code>'],
    ] as [$m, $p, $d, $b]): ?>
      <div class="api-endpoint"><div class="api-endpoint-head"><span class="api-method <?= strtolower($m) ?>"><?= $m ?></span><span class="api-path"><?= $p ?></span><span class="api-desc"><?= $d ?></span></div><?php if ($b): ?><div class="api-body"><?= $b ?></div><?php endif; ?></div>
    <?php endforeach; ?>
  </div>

  <div style="margin-top:2rem;padding:1rem;background:var(--card);border:1px solid var(--border);border-radius:10px;font-size:0.82rem;color:var(--text-3);">
    📬 Fragen oder Feedback? <a href="mailto:hallo@singopkoelsch.de" style="color:#ef4444;">hallo@singopkoelsch.de</a>
  </div>
</main>
<?php require_once "partials/footer.php"; ?>
