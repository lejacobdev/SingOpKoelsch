<?php
require_once "protect.php";
require_once "functions.php";
require_once "partials/multi_artist_widget.php";

Database::getConnection();
Database::ensurePreferencesTable();

if (!isset($_GET["lyrics"]) || empty($_GET["lyrics"])) {
    require __DIR__ . "/404.php";
    exit();
}

$id    = (int)$_GET["lyrics"];
$lyric = Database::queryDataById($id);

if (!$lyric) {
    require __DIR__ . "/404.php";
    exit();
}

$bandMap = Database::getBandMap();
$bandsList = !empty($_SESSION["user_id"]) ? Database::getBandList() : [];

// Track view (skip bots)
if (empty($_SERVER['HTTP_X_BOT']) && (empty($_SERVER['HTTP_USER_AGENT']) || !preg_match('/bot|crawl|spider|slurp|facebookexternalhit/i', $_SERVER['HTTP_USER_AGENT']))) {
    $conn = Database::getConnection();
    $conn->query("CREATE TABLE IF NOT EXISTS singopkoelsch_views (id BIGINT AUTO_INCREMENT PRIMARY KEY, song_id INT NOT NULL, viewed_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX idx_song (song_id), INDEX idx_time (viewed_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $vstmt = $conn->prepare("INSERT INTO singopkoelsch_views (song_id) VALUES (?)");
    $vstmt->bind_param("i", $id);
    $vstmt->execute();
    $vstmt->close();
}

// #9 Related songs + #52 Random song from this band
$_bandId = (int)($lyric['band_id'] ?? 0);
$_relatedSongs = [];
if ($_bandId) {
    $conn = Database::getConnection();
    $rr = $conn->prepare("SELECT id, title, cover_url, album FROM singopkoelsch_lyrics WHERE band_id = ? AND id != ? AND lyrics IS NOT NULL AND lyrics != '' ORDER BY RAND() LIMIT 5");
    $rr->bind_param("ii", $_bandId, $id);
    $rr->execute();
    $rres = $rr->get_result();
    while ($row = $rres->fetch_assoc()) $_relatedSongs[] = $row;
    $rr->close();
}

function formatLyrics(string $text): string {
    $text = preg_replace('/^[ \t]+/m', '', $text);
    $text = htmlspecialchars($text);
    $text = nl2br($text);
    return preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
}

$msg = "";

// Admin: cover löschen
if ($_SERVER["REQUEST_METHOD"] === "POST"
    && isset($_POST["delete_cover"])
    && function_exists('isAdmin') && isAdmin()) {
    $conn = Database::getConnection();
    $stmt = $conn->prepare("UPDATE singopkoelsch_lyrics SET cover_url = NULL, album = NULL, spotify_link = NULL WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    $lyric["cover_url"] = null;
    $lyric["album"] = null;
    $lyric["spotify_link"] = null;
    $msg = '<div class="alert alert-success">Cover gelöscht.</div>';
}

// Admin: flag / unflag a song as potentially incorrect
if ($_SERVER["REQUEST_METHOD"] === "POST"
    && isset($_POST["flag_action"])
    && function_exists('isAdmin') && isAdmin()) {
    if ($_POST["flag_action"] === "flag") {
        $reason = trim($_POST["flag_reason"] ?? '');
        if ($reason === '') $reason = t('detail.flag_title');
        Database::flagSong($id, (int)$_SESSION["user_id"], $reason);
        $msg = '<div class="alert alert-success">' . htmlspecialchars(t('detail.flag_done')) . '</div>';
    } elseif ($_POST["flag_action"] === "unflag") {
        Database::unflagSong($id);
        $msg = '<div class="alert alert-success">' . htmlspecialchars(t('detail.unflag_done')) . '</div>';
    }
    $lyric = Database::queryDataById($id);
}

if ($_SERVER["REQUEST_METHOD"] === "POST"
    && isset($_POST["proposed_cover_url"], $_SESSION["user_id"])) {
    $url  = trim($_POST["proposed_cover_url"]);
    $note = trim($_POST["proposed_cover_note"] ?? '');
    // Accept any spotify.com URL (track, album, artist) — validation happens at approval time
    if (!preg_match('~^https?://(open\.)?spotify\.com/(track|album)/[A-Za-z0-9]+~i', $url)) {
        $msg = '<div class="alert alert-error">' . htmlspecialchars(t('detail.cover_proposal_invalid')) . '</div>';
    } else {
        $stmt = Database::getConnection()->prepare(
            "INSERT INTO singopkoelsch_cover_proposals (lyrics_id, user_id, spotify_url, note) VALUES (?, ?, ?, ?)"
        );
        $uid = (int)$_SESSION["user_id"];
        $stmt->bind_param('iiss', $id, $uid, $url, $note);
        $ok  = $stmt->execute();
        $stmt->close();
        if ($ok) {
            $msg = '<div class="alert alert-success">' . htmlspecialchars(t('detail.cover_proposal_saved')) . '</div>';
            // Email admins
            $admins   = Database::getAdmins();
            $userData = Database::getUserById($uid);
            $userName = $userData["name"] ?? "Unbekannt";
            $link        = SITE_URL . "/admin/cover_proposals.php";
            $isCorrection = !empty($lyric["cover_url"]);
            $hAdmin = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            foreach ($admins as $admin) {
                $subject = ($isCorrection ? 'Album-Korrektur' : 'Cover-Vorschlag') . ' für "' . $lyric["title"] . '"';
                $body    = "Hallo {$admin['name']},\n\n$userName "
                         . ($isCorrection
                             ? 'hat einen Korrekturvorschlag zum bisherigen Album eingereicht'
                             : 'hat ein Album-Cover vorgeschlagen') . ":\n\n"
                         . "Lied: {$lyric['title']}\n"
                         . ($isCorrection && !empty($lyric['album']) ? "Bisher: {$lyric['album']}\n" : '')
                         . "Link: $url\n"
                         . ($note ? "Notiz: $note\n" : '')
                         . "\nVorschlag prüfen: $link\n";
                $detailHtml = '<strong>Lied:</strong> ' . $hAdmin($lyric['title']) . '<br>'
                            . ($isCorrection && !empty($lyric['album']) ? '<strong>Bisher:</strong> ' . $hAdmin($lyric['album']) . '<br>' : '')
                            . '<strong>Link:</strong> <a href="' . $hAdmin($url) . '" style="color:#E30613;word-break:break-all;">' . $hAdmin($url) . '</a>'
                            . ($note ? '<br><strong>Notiz:</strong> ' . $hAdmin($note) : '');
                $html = renderEmailHtml(
                    $isCorrection ? 'Neue Album-Korrektur' : 'Neuer Cover-Vorschlag',
                    [
                        'greeting'    => 'Hallo ' . $admin['name'] . ',',
                        'intro'       => $userName . ($isCorrection
                                            ? ' hat einen Korrekturvorschlag zum bisherigen Album eingereicht.'
                                            : ' hat ein neues Album-Cover vorgeschlagen.'),
                        'detail_html' => $detailHtml,
                        'cta_label'   => 'Vorschlag prüfen',
                        'cta_url'     => $link,
                        'footer_note' => 'Du bekommst diese Mail, weil du Administrator bist.',
                    ]
                );
                sendMail($admin["email"], $admin["name"], $subject, $body, ['html' => $html]);
            }
            require_once __DIR__ . '/push.php';
            $pushTitle = $isCorrection ? 'Neue Album-Korrektur' : 'Neuer Cover-Vorschlag';
            foreach ($admins as $admin) {
                push_send_to_user((int)$admin['user_id'], $pushTitle,
                    "$userName: \"{$lyric['title']}\"",
                    '/admin/cover_proposals.php');
            }
        } else {
            $msg = '<div class="alert alert-error">' . htmlspecialchars(t('detail.cover_proposal_save_fail')) . '</div>';
        }
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["proposed_lyrics"], $_SESSION["user_id"])) {
    $proposed = trim($_POST["proposed_lyrics"]);
    if (strlen($proposed) < 1) {
        $msg = '<div class="alert alert-error">' . htmlspecialchars(t('detail.short_proposal')) . '</div>';
    } elseif (function_exists('isTrusted') && isTrusted()) {
        $ok = Database::insertAndApplyChangeRequest($id, (int)$_SESSION["user_id"], $proposed);
        if ($ok) {
            $msg = '<div class="alert alert-success">' . htmlspecialchars(t('detail.applied_trusted')) . '</div>';
            $lyric = Database::queryDataById($id);
        } else {
            $msg = '<div class="alert alert-error">' . htmlspecialchars(t('detail.apply_fail')) . '</div>';
        }
    } else {
        $inserted = Database::insertChangeRequest($id, (int)$_SESSION["user_id"], $proposed);
        if ($inserted) {
            $msg = '<div class="alert alert-success">' . htmlspecialchars(t('detail.proposal_saved')) . '</div>';
            $admins   = Database::getAdmins();
            $userData = Database::getUserById((int)$_SESSION["user_id"]);
            $userName = $userData["name"] ?? "Unbekannt";
            $link     = SITE_URL . "/detail.php?lyrics=$id";
            $hAdmin = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $preview = substr($proposed, 0, 300) . (strlen($proposed) > 300 ? "…" : "");
            foreach ($admins as $admin) {
                $subject = 'Neuer Änderungsvorschlag für "' . $lyric["title"] . '"';
                $body    = "Hallo {$admin['name']},\n\n$userName hat einen Änderungsvorschlag eingereicht:\n\n"
                         . $preview . "\n\n"
                         . "Vorschlag prüfen: $link\n\nDein Sing op Kölsch System";
                $detailHtml = '<strong>Lied:</strong> ' . $hAdmin($lyric['title']) . '<br>'
                            . '<strong>Vorschau:</strong><br>'
                            . '<span style="white-space:pre-wrap;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:13px;color:#444;">'
                            . $hAdmin($preview) . '</span>';
                $html = renderEmailHtml('Neuer Änderungsvorschlag', [
                    'greeting'    => 'Hallo ' . $admin['name'] . ',',
                    'intro'       => $userName . ' hat einen Änderungsvorschlag eingereicht. Wirf einen Blick drauf, sobald du Zeit hast.',
                    'detail_html' => $detailHtml,
                    'cta_label'   => 'Vorschlag prüfen',
                    'cta_url'     => $link,
                    'footer_note' => 'Du bekommst diese Mail, weil du Administrator bist.',
                ]);
                sendMail($admin["email"], $admin["name"], $subject, $body, ['html' => $html]);
            }
            require_once __DIR__ . '/push.php';
            foreach ($admins as $admin) {
                push_send_to_user((int)$admin['user_id'], 'Neuer Textvorschlag',
                    "$userName hat einen Vorschlag für \"{$lyric['title']}\" eingereicht.",
                    '/admin/proposals.php');
            }
        } else {
            $msg = '<div class="alert alert-error">' . htmlspecialchars(t('detail.save_proposal_fail')) . '</div>';
        }
    }
}

// Build comma-separated artist names for display
$_performerNames = array_filter(array_map(fn($id) => $bandMap[$id] ?? null, $lyric['performer_ids'] ?? []));
$_textNames      = array_filter(array_map(fn($id) => $bandMap[$id] ?? null, $lyric['text_ids']      ?? []));
$_musicNames     = array_filter(array_map(fn($id) => $bandMap[$id] ?? null, $lyric['music_ids']     ?? []));

$bandName  = $_performerNames ? htmlspecialchars(implode(', ', $_performerNames)) : htmlspecialchars($bandMap[$lyric["band_id"]] ?? '–');
$pageTitle = htmlspecialchars($lyric["title"]) . " – " . $bandName . " – Sing op Kölsch";

$_isLoggedIn  = !empty($_SESSION["user_id"]);
$_loginReturn = '/login.php?return=' . urlencode('/detail.php?lyrics=' . $id);

// Favorite state for current user
$_isFavorited = false;
if ($_isLoggedIn) {
    $conn = Database::getConnection();
    $conn->query("CREATE TABLE IF NOT EXISTS singopkoelsch_favorites (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, song_id INT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uniq_fav (user_id, song_id), INDEX idx_user (user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $fs = $conn->prepare("SELECT 1 FROM singopkoelsch_favorites WHERE user_id=? AND song_id=? LIMIT 1");
    $fs->bind_param("ii", $_SESSION["user_id"], $id);
    $fs->execute();
    $_isFavorited = (bool)$fs->get_result()->fetch_assoc();
    $fs->close();
}

// #5 Setlists — add song to setlist
$_userSetlists = [];
if ($_isLoggedIn) {
    $dconn2 = Database::getConnection();
    $dconn2->query("CREATE TABLE IF NOT EXISTS singopkoelsch_setlists (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, name VARCHAR(255) NOT NULL, description TEXT, share_token VARCHAR(64) NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX idx_user (user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $dconn2->query("CREATE TABLE IF NOT EXISTS singopkoelsch_setlist_songs (id INT AUTO_INCREMENT PRIMARY KEY, setlist_id INT NOT NULL, song_id INT NOT NULL, position INT NOT NULL DEFAULT 0, added_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uniq (setlist_id, song_id), INDEX idx_setlist (setlist_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["add_to_setlist"])) {
        $slId = (int)$_POST["add_to_setlist"];
        $vs = $dconn2->prepare("SELECT id FROM singopkoelsch_setlists WHERE id = ? AND user_id = ?");
        $vs->bind_param("ii", $slId, $_SESSION["user_id"]); $vs->execute();
        if ($vs->get_result()->fetch_assoc()) {
            $ins = $dconn2->prepare("INSERT IGNORE INTO singopkoelsch_setlist_songs (setlist_id, song_id) VALUES (?, ?)");
            $ins->bind_param("ii", $slId, $id); $ins->execute(); $ins->close();
        }
        $vs->close();
        header("Location: /detail.php?lyrics=$id&added_setlist=1"); exit;
    }
    $slst = $dconn2->prepare("SELECT id, name FROM singopkoelsch_setlists WHERE user_id = ? ORDER BY name ASC LIMIT 20");
    $slst->bind_param("i", $_SESSION["user_id"]); $slst->execute();
    $_userSetlists = $slst->get_result()->fetch_all(MYSQLI_ASSOC); $slst->close();
}


require_once "partials/head.php";
require_once "partials/nav.php";
?>

<main class="content">

<!-- #15 Scroll progress bar -->
<div id="scroll-progress" style="position:fixed;top:0;left:0;height:3px;width:0%;background:linear-gradient(90deg,#DC2626,#ef4444);z-index:9999;transition:width 0.08s linear;pointer-events:none;" aria-hidden="true"></div>
<script>(function(){var b=document.getElementById('scroll-progress');function u(){var s=document.documentElement;b.style.width=Math.min(100,Math.max(0,(s.scrollTop/(s.scrollHeight-s.clientHeight))*100))+'%';}window.addEventListener('scroll',u,{passive:true});u();})();</script>

  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;gap:0.5rem;">
    <button type="button" class="round-icon-btn" data-back-btn data-back-fallback="/lieder.php"
            title="<?= htmlspecialchars(t('nav.back')) ?>" aria-label="<?= htmlspecialchars(t('nav.back')) ?>">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
    </button>
    <div style="display:flex;align-items:center;gap:0.4rem;">
      <!-- Favorite button -->
      <?php if ($_isLoggedIn): ?>
      <button type="button" id="fav-btn"
              class="round-icon-btn<?= $_isFavorited ? ' fav-active' : '' ?>"
              data-song-id="<?= $id ?>"
              data-faved="<?= $_isFavorited ? '1' : '0' ?>"
              title="<?= $_isFavorited ? 'Aus Favoriten entfernen' : 'Zu Favoriten hinzufügen' ?>"
              aria-label="Favorit">
        <svg id="fav-icon" width="15" height="15" viewBox="0 0 24 24"
             fill="<?= $_isFavorited ? 'currentColor' : 'none' ?>"
             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78L12 21.23l8.84-8.84a5.5 5.5 0 0 0 0-7.78z"/>
        </svg>
      </button>
      <?php endif; ?>
      <?php if ($_isLoggedIn && !empty($_userSetlists)): ?>
      <!-- #5 Add to setlist -->
      <div style="position:relative;">
        <button type="button" id="setlist-btn" class="round-icon-btn" title="Zu Setlist hinzufügen" aria-label="Setlist">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
        </button>
        <div id="setlist-menu" hidden style="position:absolute;right:0;top:calc(100% + 0.4rem);background:var(--card);border:1px solid var(--border);border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,0.25);min-width:180px;z-index:200;overflow:hidden;">
          <?php if (!empty($_GET['added_setlist'])): ?><div style="padding:0.5rem 0.85rem;font-size:0.8rem;color:#22c55e;">✓ Hinzugefügt!</div><?php endif; ?>
          <?php foreach ($_userSetlists as $sl): ?>
            <form method="post" style="margin:0;">
              <input type="hidden" name="add_to_setlist" value="<?= (int)$sl['id'] ?>">
              <button type="submit" style="width:100%;text-align:left;background:none;border:none;padding:0.6rem 0.85rem;font-size:0.88rem;color:var(--text);cursor:pointer;border-bottom:1px solid var(--border);">+ <?= htmlspecialchars($sl['name']) ?></button>
            </form>
          <?php endforeach; ?>
          <a href="/setlists.php" style="display:block;padding:0.55rem 0.85rem;font-size:0.8rem;color:var(--text-3);text-decoration:none;">Setlists verwalten →</a>
        </div>
      </div>
      <script>document.getElementById('setlist-btn').addEventListener('click',function(){var m=document.getElementById('setlist-menu');m.hidden=!m.hidden;});document.addEventListener('click',function(e){if(!e.target.closest('#setlist-btn')&&!e.target.closest('#setlist-menu'))document.getElementById('setlist-menu').hidden=true;});</script>
      <?php endif; ?>
      <!-- QR share button -->
      <button type="button" id="qr-btn" class="round-icon-btn" title="QR-Code teilen" aria-label="QR-Code">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>
          <rect x="14" y="14" width="3" height="3"/><rect x="18" y="14" width="3" height="3"/><rect x="14" y="18" width="3" height="3"/><rect x="18" y="18" width="3" height="3"/>
        </svg>
      </button>
      <?php if (isAdmin()): ?>
      <a href="delete.php?lyrics=<?= $id ?>" class="round-icon-btn is-danger"
         onclick="return confirm(<?= json_encode(t('detail.delete_confirm')) ?>)"
         title="<?= htmlspecialchars(t('detail.delete_song')) ?>" aria-label="<?= htmlspecialchars(t('detail.delete_song')) ?>">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
      </a>
      <?php endif; ?>
    </div>
  </div>

  <?php
    if (!empty($_GET['saved'])) {
        echo '<div class="alert alert-success" style="cursor:pointer;" onclick="this.style.display=\'none\'">' . htmlspecialchars(t('detail.saved')) . '</div>';
    } elseif (!empty($_GET['proposed'])) {
        echo '<div class="alert alert-success" style="cursor:pointer;" onclick="this.style.display=\'none\'">' . htmlspecialchars(t('detail.proposed')) . '</div>';
    }
  ?>

  <?php
    $hasCover    = !empty($lyric["cover_url"]);
    $albumLink   = (!empty($lyric["album"]) && !empty($lyric["band_id"]))
        ? '/album.php?title=' . urlencode($lyric["album"]) . '&band=' . (int)$lyric["band_id"]
        : null;
  ?>
  <div class="song-header">
    <div class="cover-wrap">
    <?php if ($hasCover && $albumLink): ?>
      <a href="<?= $albumLink ?>" class="song-cover" title="Album öffnen: <?= htmlspecialchars($lyric["album"]) ?>">
        <img src="<?= htmlspecialchars($lyric["cover_url"]) ?>"
             alt="Cover: <?= htmlspecialchars($lyric["album"] ?? $lyric["title"]) ?>"
             loading="lazy" decoding="async" />
      </a>
    <?php elseif ($hasCover): ?>
      <div class="song-cover">
        <img src="<?= htmlspecialchars($lyric["cover_url"]) ?>"
             alt="Cover: <?= htmlspecialchars($lyric["album"] ?? $lyric["title"]) ?>"
             loading="lazy" decoding="async" />
      </div>
    <?php elseif (!empty($_SESSION["user_id"])): ?>
      <button type="button" class="song-cover song-cover-empty"
              id="cover-add-btn"
              title="<?= htmlspecialchars(t('detail.cover_add')) ?>"
              aria-label="<?= htmlspecialchars(t('detail.cover_add')) ?>">
        <span class="cover-plus-icon" aria-hidden="true">
          <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        </span>
        <span class="cover-plus-label"><?= htmlspecialchars(t('detail.cover_add_btn')) ?></span>
      </button>
    <?php else: ?>
      <a href="/login.php?return=<?= urlencode('/detail.php?lyrics=' . $id) ?>"
         class="song-cover song-cover-empty"
         title="<?= htmlspecialchars(t('detail.cover_login_hint')) ?>">
        <span class="cover-plus-icon" aria-hidden="true">
          <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        </span>
        <span class="cover-plus-label"><?= htmlspecialchars(t('detail.cover_add_btn')) ?></span>
      </a>
    <?php endif; ?>
      <?php if ($hasCover): ?>
        <?php if ($_isLoggedIn): ?>
          <button type="button" id="cover-edit-btn" class="cover-edit-overlay" title="<?= htmlspecialchars(t('detail.cover_edit')) ?>" aria-label="<?= htmlspecialchars(t('detail.cover_edit')) ?>">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
          </button>
        <?php else: ?>
          <a href="<?= htmlspecialchars($_loginReturn) ?>" class="cover-edit-overlay" title="<?= htmlspecialchars(t('detail.cover_edit')) ?>" aria-label="<?= htmlspecialchars(t('detail.cover_edit')) ?>">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
          </a>
        <?php endif; ?>
        <?php if (isAdmin()): ?>
          <form method="post" style="position:absolute;top:4px;left:4px;" onsubmit="return confirm('Cover wirklich löschen?')">
            <input type="hidden" name="delete_cover" value="1">
            <button type="submit" title="Cover löschen" style="background:rgba(0,0,0,0.6);border:none;border-radius:6px;padding:4px 6px;cursor:pointer;line-height:0;">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
            </button>
          </form>
        <?php endif; ?>
      <?php endif; ?>
    </div>
    <div class="song-header-text">
      <h1 style="margin-bottom:0.3rem;"><?= htmlspecialchars($lyric["title"]) ?></h1>
      <p style="color:var(--text-2);margin:0;font-size:0.95rem;"><?= $bandName ?></p>
    </div>
  </div>

  <style>
    .song-header {
      display: flex;
      align-items: flex-start;
      gap: 1.25rem;
      margin-bottom: 1.25rem;
    }
    .cover-wrap { position: relative; flex: 0 0 auto; }
    a.cover-edit-overlay,
    button.cover-edit-overlay,
    .cover-edit-overlay {
      position: absolute;
      top: 6px; right: 6px;
      width: 30px !important; height: 30px !important;
      border-radius: 50% !important;
      border: none !important;
      background: rgba(15,23,42,0.72) !important;
      color: #fff !important;
      display: inline-flex !important;
      align-items: center !important;
      justify-content: center !important;
      gap: 0 !important;
      cursor: pointer;
      backdrop-filter: blur(8px);
      -webkit-backdrop-filter: blur(8px);
      box-shadow: 0 2px 10px rgba(0,0,0,0.30) !important;
      transition: background 0.15s, transform 0.12s;
      padding: 0 !important;
      margin: 0 !important;
      font: inherit !important;
      line-height: 1 !important;
      text-decoration: none !important;
      box-sizing: border-box !important;
      z-index: 2;
    }
    a.cover-edit-overlay:hover,
    button.cover-edit-overlay:hover,
    .cover-edit-overlay:hover {
      background: #dc2626 !important;
      transform: scale(1.08);
      color: #fff !important;
      text-decoration: none !important;
    }
    .song-cover {
      flex: 0 0 auto;
      width: clamp(110px, 22vw, 160px);
      aspect-ratio: 1 / 1;
      border-radius: 12px;
      overflow: hidden;
      display: block;
      box-shadow:
        0 1px 4px rgba(15,23,42,0.10),
        0 12px 32px rgba(15,23,42,0.15);
      background: var(--bg-alt);
      transition: transform 0.18s, box-shadow 0.18s;
    }
    a.song-cover:hover {
      transform: translateY(-2px) scale(1.02);
      box-shadow:
        0 1px 4px rgba(15,23,42,0.12),
        0 16px 40px rgba(220,38,38,0.22);
    }
    html.dark .song-cover {
      box-shadow:
        0 1px 4px rgba(0,0,0,0.4),
        0 12px 32px rgba(0,0,0,0.55);
    }
    html.dark a.song-cover:hover {
      box-shadow:
        0 1px 4px rgba(0,0,0,0.5),
        0 16px 40px rgba(220,38,38,0.32);
    }
    .song-cover img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
    }
    .song-cover-empty {
      display: flex !important;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 0.45rem;
      padding: 0 !important;
      cursor: pointer;
      border: 2px dashed var(--border) !important;
      background:
        linear-gradient(135deg, rgba(239,68,68,0.06), rgba(220,38,38,0.02)),
        var(--bg-alt) !important;
      color: var(--text-3) !important;
      text-decoration: none;
      box-shadow: none !important;
      font: inherit;
      transition: border-color 0.18s, color 0.18s, background 0.18s, transform 0.18s;
    }
    .song-cover-empty:hover {
      border-color: rgba(239,68,68,0.6) !important;
      color: #dc2626 !important;
      background:
        linear-gradient(135deg, rgba(239,68,68,0.10), rgba(220,38,38,0.04)),
        var(--bg-alt) !important;
      transform: translateY(-2px);
    }
    html.dark .song-cover-empty {
      border-color: rgba(255,255,255,0.10) !important;
      color: rgba(255,255,255,0.45) !important;
      background:
        linear-gradient(135deg, rgba(239,68,68,0.08), rgba(220,38,38,0.03)),
        rgba(255,255,255,0.03) !important;
    }
    html.dark .song-cover-empty:hover {
      color: #fca5a5 !important;
      border-color: rgba(239,68,68,0.6) !important;
    }
    .cover-plus-icon {
      width: 44px; height: 44px;
      border-radius: 50%;
      border: 1.5px solid currentColor;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }
    .cover-plus-label {
      font-size: 0.72rem;
      font-weight: 600;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      padding: 0 0.4rem;
      text-align: center;
      line-height: 1.2;
    }
    .song-header-text { min-width: 0; flex: 1 1 auto; }
    .song-header-text h1 { line-height: 1.15; word-break: break-word; overflow-wrap: anywhere; }

    /* Section heads above .song-meta and .lyrics-text */
    .detail-section-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 0.75rem;
      margin: 1.5rem 0 0.6rem;
    }
    .detail-section-head h3 {
      margin: 0;
      font-size: 1.05rem;
      color: var(--text-2);
      font-weight: 600;
      letter-spacing: 0.02em;
    }
    /* Perfect-circle action buttons (edit, delete, etc.) — applied to both
       <button> and <a> elements, so we explicitly reset every default that
       would otherwise leak from style.css's global `button { ... }` /
       link-hover rules. !important keeps both element types identical. */
    a.round-icon-btn,
    button.round-icon-btn,
    .round-icon-btn {
      width: 36px !important; height: 36px !important;
      flex: 0 0 36px !important;
      aspect-ratio: 1 / 1 !important;
      display: inline-flex !important;
      align-items: center !important;
      justify-content: center !important;
      gap: 0 !important;
      background: var(--bg-alt) !important;
      border: 1px solid var(--border) !important;
      border-radius: 9999px !important;
      color: var(--text-2) !important;
      padding: 0 !important;
      margin: 0 !important;
      font: inherit !important;
      line-height: 1 !important;
      box-shadow: 0 1px 4px rgba(15,23,42,0.06) !important;
      cursor: pointer;
      text-decoration: none !important;
      white-space: nowrap;
      transition: background 0.15s, color 0.15s, border-color 0.15s, transform 0.12s;
      box-sizing: border-box !important;
    }
    a.round-icon-btn:hover,
    button.round-icon-btn:hover,
    .round-icon-btn:hover {
      background: var(--card-hover) !important;
      border-color: rgba(239,68,68,0.55) !important;
      color: #dc2626 !important;
      transform: translateY(-1px);
      text-decoration: none !important;
    }
    html.dark a.round-icon-btn,
    html.dark button.round-icon-btn,
    html.dark .round-icon-btn {
      background: rgba(255,255,255,0.05) !important;
      border-color: rgba(255,255,255,0.10) !important;
      color: rgba(255,255,255,0.78) !important;
      box-shadow: 0 1px 4px rgba(0,0,0,0.4) !important;
    }
    html.dark a.round-icon-btn:hover,
    html.dark button.round-icon-btn:hover,
    html.dark .round-icon-btn:hover {
      background: rgba(255,255,255,0.12) !important;
      border-color: rgba(239,68,68,0.55) !important;
      color: #fca5a5 !important;
    }
    .round-icon-btn.is-danger { color: #dc2626 !important; border-color: rgba(239,68,68,0.35) !important; }
    .round-icon-btn.is-danger:hover { background: #dc2626 !important; color: #fff !important; border-color: #dc2626 !important; }
    html.dark .round-icon-btn.is-danger { color: #fca5a5 !important; border-color: rgba(239,68,68,0.35) !important; }
    html.dark .round-icon-btn.is-danger:hover { background: #dc2626 !important; color: #fff !important; border-color: #dc2626 !important; }

    @media (max-width: 600px) {
      .song-header { gap: 0.9rem; }
      .song-header-text h1 { font-size: 1.4rem; margin-bottom: 0.2rem !important; }
      .cover-plus-icon { width: 36px; height: 36px; }
      .cover-plus-label { font-size: 0.68rem; }
      .detail-section-head { gap: 0.5rem; margin: 1.25rem 0 0.5rem; }
      .detail-section-head h3 { font-size: 1rem; }
      .round-icon-btn { width: 34px; height: 34px; flex-basis: 34px; }
      .song-meta-grid { padding: 0.85rem 0.95rem; gap: 0.6rem 1rem; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); }
      .lyrics-text { padding: 1.1rem 1.05rem; font-size: 0.95rem; line-height: 1.75; }
    }
    @media (max-width: 380px) {
      .song-header { gap: 0.7rem; }
      .song-header-text h1 { font-size: 1.25rem; }
      .song-header-text p { font-size: 0.88rem !important; }
      .song-meta-grid { grid-template-columns: 1fr 1fr; gap: 0.55rem 0.85rem; }
      .lyrics-text { padding: 0.95rem 0.85rem; }
    }
  </style>

<?php /* Cover-propose details removed — replaced by modal-cover overlay below */ ?>

  <?php if (!empty($lyric["flagged"])): ?>
    <div class="flag-banner">
      <div class="flag-banner-icon">⚠</div>
      <div class="flag-banner-body">
        <strong><?= htmlspecialchars(t('detail.flagged_strong')) ?></strong>
        <p>
          <?php
            $reasonPart = !empty($lyric["flag_reason"]) ? ': <em>' . htmlspecialchars($lyric["flag_reason"]) . '</em>' : '.';
            echo t('detail.flagged_body', ['reason' => $reasonPart]);
          ?>
        </p>
      </div>
      <?php if (!empty($_SESSION["role"]) && $_SESSION["role"] === "admin"): ?>
        <form method="post" style="margin:0;">
          <input type="hidden" name="flag_action" value="unflag">
          <button type="submit" class="btn btn-ghost btn-sm"><?= htmlspecialchars(t('detail.flag_unflag')) ?></button>
        </form>
      <?php endif; ?>
    </div>
    <style>
      .flag-banner {
        display: flex; align-items: flex-start; gap: 0.85rem;
        padding: 0.85rem 1rem;
        background: rgba(245,158,11,0.10);
        border: 1px solid rgba(245,158,11,0.35);
        color: #b45309;
        border-radius: 12px;
        margin: 0 0 1.25rem;
      }
      html.dark .flag-banner {
        background: rgba(251,191,36,0.10);
        border-color: rgba(251,191,36,0.30);
        color: #fbbf24;
      }
      .flag-banner-icon { font-size: 1.4rem; line-height: 1; }
      .flag-banner-body { flex: 1; min-width: 0; }
      .flag-banner-body strong { display: block; margin-bottom: 0.25rem; }
      .flag-banner-body p { margin: 0; font-size: 0.9rem; }
    </style>
  <?php elseif (!empty($_SESSION["role"]) && $_SESSION["role"] === "admin"): ?>
    <details class="flag-tool">
      <summary><?= htmlspecialchars(t('detail.flag_title')) ?></summary>
      <form method="post" class="flag-form">
        <p class="text-sm" style="color:var(--text-3);margin:0 0 0.6rem;">
          <?= htmlspecialchars(t('detail.flag_hint')) ?>
        </p>
        <input type="hidden" name="flag_action" value="flag">
        <div class="form-group">
          <label for="flag_reason"><?= htmlspecialchars(t('detail.flag_reason_label')) ?></label>
          <input type="text" name="flag_reason" id="flag_reason" maxlength="240"
                 placeholder="<?= htmlspecialchars(t('detail.flag_reason_ph')) ?>">
        </div>
        <div class="btn-row right">
          <button type="submit" class="btn btn-secondary btn-sm"><?= htmlspecialchars(t('detail.flag_submit')) ?></button>
        </div>
      </form>
    </details>
    <style>
      .flag-tool {
        border: 1px dashed var(--border);
        border-radius: 12px;
        padding: 0.6rem 0.85rem;
        margin: 0 0 1.25rem;
        background: var(--bg-alt);
      }
      .flag-tool summary {
        cursor: pointer; font-size: 0.88rem; font-weight: 600;
        color: var(--text-2); list-style: none; outline: none;
      }
      .flag-tool summary::-webkit-details-marker { display: none; }
      .flag-tool[open] summary { margin-bottom: 0.6rem; color: var(--text); }
      .flag-form .form-group { margin-bottom: 0.6rem; }
      .flag-form label { display:block; font-size:0.78rem; font-weight:600; color:var(--text-2); margin-bottom:0.25rem; }
      .flag-form input {
        width: 100%; padding: 0.5rem 0.7rem !important;
        border: 1px solid var(--border) !important; border-radius: 8px !important;
        background: var(--card) !important; color: var(--text) !important;
        font-size: 0.9rem !important;
      }
    </style>
  <?php endif; ?>

  <!-- Daten -->
  <header class="detail-section-head">
    <h3><?= htmlspecialchars(t('detail.data')) ?></h3>
    <?php if ($_isLoggedIn): ?>
      <?php $editDataTitle = (function_exists('isTrusted') && isTrusted()) ? t('detail.edit_data') : t('detail.edit_data_proposal'); ?>
      <button type="button" class="round-icon-btn detail-edit-btn" data-edit-open="modal-meta"
         title="<?= htmlspecialchars($editDataTitle) ?>"
         aria-label="<?= htmlspecialchars(t('detail.edit_data')) ?>">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
      </button>
    <?php else: ?>
      <a href="<?= htmlspecialchars($_loginReturn) ?>" class="round-icon-btn detail-edit-btn"
         title="<?= htmlspecialchars(t('detail.edit_data')) ?>"
         aria-label="<?= htmlspecialchars(t('detail.edit_data')) ?>">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
      </a>
    <?php endif; ?>
  </header>
  <div class="song-meta">
    <div class="song-meta-grid">
      <div class="song-meta-item">
        <label><?= htmlspecialchars(t('detail.artist')) ?></label>
        <p>
          <?php
          $performerIds = $lyric['performer_ids'] ?? [];
          if (empty($performerIds) && !empty($lyric['band_id'])) $performerIds = [(int)$lyric['band_id']];
          if ($performerIds):
              $parts = [];
              foreach ($performerIds as $pid):
                  $pname = $bandMap[$pid] ?? null;
                  if ($pname) $parts[] = '<a href="/lieder.php?band=' . (int)$pid . '" style="color:var(--text);text-decoration:none;font-weight:600;">' . htmlspecialchars($pname) . '</a>';
              endforeach;
              echo implode(', ', $parts);
          else:
              echo '–';
          endif;
          ?>
          <?php if (!empty($performerIds)):
              $firstName = $bandMap[$performerIds[0]] ?? '';
          ?>
            <a href="https://de.wikipedia.org/wiki/<?= urlencode($firstName) ?>" target="_blank" rel="noopener noreferrer" title="Wikipedia" style="margin-left:0.4rem;font-size:0.75rem;color:var(--text-3);text-decoration:none;">📖 Wiki</a>
          <?php endif; ?>
        </p>
      </div>
      <div class="song-meta-item">
        <label><?= htmlspecialchars(t('detail.text_author')) ?></label>
        <p><?= $_textNames ? htmlspecialchars(implode(', ', $_textNames)) : htmlspecialchars($bandMap[$lyric["text_autor_id"]] ?? '–') ?></p>
      </div>
      <div class="song-meta-item">
        <label><?= htmlspecialchars(t('detail.music_author')) ?></label>
        <p><?= $_musicNames ? htmlspecialchars(implode(', ', $_musicNames)) : htmlspecialchars($bandMap[$lyric["musik_autor_id"]] ?? '–') ?></p>
      </div>
      <div class="song-meta-item">
        <label><?= htmlspecialchars(t('detail.album')) ?></label>
        <p><?= !empty($lyric["album"]) ? htmlspecialchars($lyric["album"]) : "–" ?></p>
      </div>
      <div class="song-meta-item">
        <label><?= htmlspecialchars(t('detail.release_year')) ?></label>
        <p><?= !empty($lyric["release_year"]) ? htmlspecialchars($lyric["release_year"]) : "–" ?></p>
      </div>
    </div>
  </div>

  <?php if (!empty($lyric["spotify_link"]) || !empty($lyric["video_link"])): ?>
  <div class="song-link-actions">
    <?php if (!empty($lyric["spotify_link"])): ?>
      <a href="<?= htmlspecialchars($lyric["spotify_link"]) ?>" target="_blank" rel="noopener noreferrer"
         class="link-btn link-btn-spotify" title="<?= htmlspecialchars(t('detail.spotify')) ?>" aria-label="<?= htmlspecialchars(t('detail.spotify')) ?>">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
          <path d="M12 0C5.4 0 0 5.4 0 12s5.4 12 12 12 12-5.4 12-12S18.66 0 12 0zm5.521 17.34c-.24.359-.66.48-1.021.24-2.82-1.74-6.36-2.101-10.561-1.141-.418.122-.84-.179-.94-.6-.12-.421.18-.84.6-.94 4.561-1.021 8.52-.6 11.64 1.32.42.18.479.659.282 1.121zm1.44-3.3c-.301.42-.841.6-1.262.3-3.239-1.98-8.159-2.58-11.939-1.38-.479.12-1.02-.12-1.14-.6-.12-.48.12-1.021.6-1.141C9.6 9.9 15 10.561 18.72 12.84c.361.181.54.78.241 1.2zm.12-3.36C15.24 8.4 8.82 8.16 5.16 9.301c-.6.179-1.2-.181-1.38-.721-.18-.601.18-1.2.72-1.381 4.26-1.26 11.28-1.02 15.721 1.621.539.3.719 1.02.42 1.56-.299.421-1.02.599-1.559.3z"/>
        </svg>
      </a>
    <?php endif; ?>
    <?php if (!empty($lyric["video_link"])): ?>
      <a href="<?= htmlspecialchars($lyric["video_link"]) ?>" target="_blank" rel="noopener noreferrer"
         class="link-btn link-btn-youtube" title="<?= htmlspecialchars(t('detail.video')) ?>" aria-label="<?= htmlspecialchars(t('detail.video')) ?>">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
          <path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/>
        </svg>
      </a>
    <?php endif; ?>
  </div>
  <style>
    .song-link-actions {
      display: flex;
      gap: 0.6rem;
      margin: 0.85rem 0 0;
      flex-wrap: wrap;
    }
    .link-btn {
      width: 44px; height: 44px;
      flex: 0 0 44px;
      aspect-ratio: 1 / 1;
      display: inline-flex; align-items: center; justify-content: center;
      border-radius: 9999px;
      text-decoration: none;
      transition: transform 0.12s, box-shadow 0.18s, filter 0.18s;
      box-shadow: 0 2px 10px rgba(15,23,42,0.12);
      border: none;
    }
    .link-btn:hover { transform: translateY(-2px) scale(1.05); }
    .link-btn-spotify { background: #1DB954; color: #fff; }
    .link-btn-spotify:hover { background: #1ed760; box-shadow: 0 6px 18px rgba(29,185,84,0.45); }
    .link-btn-youtube { background: #FF0000; color: #fff; }
    .link-btn-youtube:hover { background: #ff1f1f; box-shadow: 0 6px 18px rgba(255,0,0,0.45); }
    @media (max-width: 480px) {
      .link-btn { width: 40px; height: 40px; flex-basis: 40px; }
    }
  </style>
  <?php endif; ?>

  <!-- Liedtext -->
  <header class="detail-section-head">
    <h3><?= htmlspecialchars(t('detail.lyrics')) ?></h3>
    <?php if ($_isLoggedIn): ?>
      <?php $editLyricsTitle = (function_exists('isTrusted') && isTrusted()) ? t('detail.edit_lyrics') : t('detail.edit_lyrics_proposal'); ?>
      <button type="button" class="round-icon-btn detail-edit-btn" data-edit-open="modal-lyrics"
         title="<?= htmlspecialchars($editLyricsTitle) ?>"
         aria-label="<?= htmlspecialchars(t('detail.edit_lyrics')) ?>">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
      </button>
    <?php else: ?>
      <a href="<?= htmlspecialchars($_loginReturn) ?>" class="round-icon-btn detail-edit-btn"
         title="<?= htmlspecialchars(t('detail.edit_lyrics')) ?>"
         aria-label="<?= htmlspecialchars(t('detail.edit_lyrics')) ?>">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
      </a>
    <?php endif; ?>
  </header>
  <div class="lyrics-text">
    <?= formatLyrics($lyric["lyrics"]) ?>
  </div>

  <?= $msg ?>

  <?php if (empty($_SESSION["user_id"])): ?>
    <p class="text-sm text-muted mt-2">
      <?= t('detail.no_login_hint') ?>
    </p>
  <?php endif; ?>

  <!-- Admin: pending changes -->
  <?php if (!empty($_SESSION["role"]) && $_SESSION["role"] === "admin"): ?>
    <?php
      $pending = Database::getPendingChangeRequests($id);
      $fieldLabels = [
        'title'          => t('detail.title'),
        'band_id'        => t('detail.artist'),
        'text_autor_id'  => t('modal.text_author'),
        'musik_autor_id' => t('modal.music_author'),
        'album'          => t('detail.album'),
        'release_year'   => t('detail.year'),
        'spotify_link'   => 'Spotify',
        'video_link'     => 'Video',
        'lyrics'         => t('detail.lyrics'),
      ];
      $fmtVal = function($field, $val) use ($bandMap) {
        if ($val === null || $val === '') return '<span class="text-muted">–</span>';
        if (in_array($field, ['band_id','text_autor_id','musik_autor_id'], true)) {
            return htmlspecialchars($bandMap[(int)$val] ?? ('#' . (int)$val));
        }
        return nl2br(htmlspecialchars((string)$val));
      };
    ?>
    <?php if (!empty($pending)): ?>
    <style>
      /* Per-field change rows: Label / Vorher / Nachher.
         Desktop: 3 Spalten. Mobile: gestapelt mit Trenn-Label. */
      .cr-grid {
        display: grid;
        grid-template-columns: 11rem 1fr 1fr;
        gap: 0.55rem 1rem;
        background: var(--bg-alt);
        padding: 0.75rem 1rem;
        border-radius: var(--radius-sm);
        font-size: 0.88em;
        margin: 0 0 0.85rem;
      }
      .cr-head { font-weight:600; }
      .cr-head.cr-old { color: var(--text-3); }
      .cr-head.cr-new { color: #16a34a; }
      .cr-row-label { font-weight:600; align-self:center; }
      .cr-row-old { color: var(--text-3); white-space: pre-wrap; word-break: break-word; }
      .cr-row-new { color: #16a34a; white-space: pre-wrap; word-break: break-word; }
      .cr-block-full { grid-column: 1 / -1; }
      .cr-diff-wrap { margin: 0.25rem 0 0; }
      .cr-diff-title { font-weight:600; margin: 0 0 0.4rem; font-size: 0.95em; }

      /* Mobile: stapeln */
      @media (max-width: 720px) {
        .cr-grid { grid-template-columns: 1fr; gap: 0.35rem; padding: 0.7rem 0.85rem; }
        .cr-head { display:none; }
        .cr-row-label {
          margin: 0.55rem 0 0.1rem;
          padding: 0.1rem 0;
          border-top: 1px solid var(--border);
          font-size: 0.95em;
        }
        .cr-row-label:first-of-type { border-top: none; margin-top: 0; }
        .cr-row-old::before { content: "Vorher: "; font-weight:600; color: var(--text-3); }
        .cr-row-new::before { content: "Nachher: "; font-weight:600; color: #16a34a; }
      }

      /* Diff-Block (Lyrics) */
      .diff-block {
        font-family: ui-monospace, Menlo, Consolas, monospace;
        font-size: 0.82em;
        line-height: 1.55;
        white-space: pre-wrap;
        background: var(--bg);
        border: 1px solid var(--border);
        border-radius: var(--radius-sm);
        padding: 0.4rem 0.4rem;
        max-height: 360px;
        overflow: auto;
      }
      .diff-block > div { padding: 0 0.5rem; border-radius: 3px; }
      .diff-eq  { color: var(--text-3); }
      .diff-add { background: rgba(22,163,74,0.12);  color: #16a34a; }
      .diff-del { background: rgba(239,68,68,0.12);  color: #ef4444; text-decoration: line-through; }
      .diff-mark { display: inline-block; width: 1.1em; opacity: 0.6; }
    </style>
    <div class="card mt-3">
      <div class="card-header">
        <?= htmlspecialchars(t('detail.pending_title')) ?>
        <span class="badge badge-yellow"><?= count($pending) ?></span>
      </div>
      <?php foreach ($pending as $s): ?>
        <?php
          $payload = !empty($s['proposed_changes']) ? json_decode($s['proposed_changes'], true) : null;
          // Trenne: kurze Felder werden in der Tabelle gezeigt, Lyrics als Zeilen-Diff darunter.
          $shortKeys  = ['title','band_id','text_autor_id','musik_autor_id','album','release_year','spotify_link','video_link'];
          $shortDiffs = [];
          $lyricsDiff = null;
          if (is_array($payload)) {
            foreach ($payload as $k => $v) {
              if ($k === 'lyrics' && (string)($lyric['lyrics'] ?? '') !== (string)$v) {
                $lyricsDiff = ['old' => (string)($lyric['lyrics'] ?? ''), 'new' => (string)$v];
              } elseif (in_array($k, $shortKeys, true) && (string)($lyric[$k] ?? '') !== (string)$v) {
                $shortDiffs[$k] = $v;
              }
            }
          }
        ?>
        <div class="form-section">
          <p style="margin:0 0 0.5rem;">
            <strong><?= htmlspecialchars($s["user_name"]) ?></strong>
            <span class="text-muted text-sm" style="margin-left:0.5rem;"><?= htmlspecialchars($s["created_at"]) ?></span>
          </p>

          <?php if (is_array($payload)): ?>
            <?php if (!empty($shortDiffs)): ?>
              <div class="cr-grid">
                <div class="cr-head"><?= htmlspecialchars(t('detail.field')) ?></div>
                <div class="cr-head cr-old"><?= htmlspecialchars(t('detail.cur')) ?></div>
                <div class="cr-head cr-new"><?= htmlspecialchars(t('detail.proposed_col')) ?></div>
                <?php foreach ($fieldLabels as $key => $label):
                  if (!array_key_exists($key, $shortDiffs)) continue;
                  $cur = $lyric[$key] ?? null;
                  $new = $shortDiffs[$key];
                ?>
                  <div class="cr-row-label"><?= htmlspecialchars($label) ?></div>
                  <div class="cr-row-old"><?= $fmtVal($key, $cur) ?></div>
                  <div class="cr-row-new"><?= $fmtVal($key, $new) ?></div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <?php if ($lyricsDiff !== null): ?>
              <div class="cr-diff-wrap">
                <p class="cr-diff-title"><?= htmlspecialchars($fieldLabels['lyrics']) ?> — Zeilen-Diff</p>
                <?= renderLineDiff($lyricsDiff['old'], $lyricsDiff['new']) ?>
              </div>
            <?php endif; ?>

            <?php if (empty($shortDiffs) && $lyricsDiff === null): ?>
              <p class="text-muted text-sm" style="margin:0 0 0.75rem;">Keine sichtbaren Änderungen im Vorschlag.</p>
            <?php endif; ?>
          <?php else: ?>
            <!-- Legacy-Vorschlag (nur lyrics) als Zeilen-Diff gegen aktuellen Text -->
            <div class="cr-diff-wrap">
              <p class="cr-diff-title"><?= htmlspecialchars($fieldLabels['lyrics']) ?> — Zeilen-Diff</p>
              <?= renderLineDiff((string)($lyric['lyrics'] ?? ''), (string)$s['proposed_lyrics']) ?>
            </div>
          <?php endif; ?>

          <div class="gap-row">
            <a href="admin/approve_change.php?id=<?= $s['id'] ?>&lyric_id=<?= $id ?>" class="btn btn-sm btn-primary"><?= htmlspecialchars(t('btn.approve')) ?></a>
            <a href="admin/reject_change.php?id=<?= $s['id'] ?>&lyric_id=<?= $id ?>" class="btn btn-sm btn-danger"><?= htmlspecialchars(t('btn.reject')) ?></a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  <?php endif; ?>

  <?php if (!empty($_SESSION["user_id"])): ?>
  <!-- ─── Edit Overlays ─────────────────────────────────────── -->
  <div class="edit-modal" id="modal-cover" role="dialog" aria-modal="true" aria-labelledby="modal-cover-title" hidden>
    <div class="edit-modal-backdrop" data-edit-close></div>
    <div class="edit-modal-card">
      <header class="edit-modal-head">
        <h2 id="modal-cover-title"><?= htmlspecialchars($hasCover ? t('modal.cover_correct') : t('modal.cover_propose')) ?></h2>
        <button type="button" class="edit-modal-x" data-edit-close aria-label="<?= htmlspecialchars(t('btn.close')) ?>">×</button>
      </header>
      <form method="post" action="/detail.php?lyrics=<?= $id ?>" class="edit-modal-body">
        <p class="edit-modal-hint">
          <?= htmlspecialchars($hasCover ? t('modal.cover_correct_hint') : t('modal.cover_propose_hint')) ?>
        </p>
        <div class="form-group">
          <label for="proposed_cover_url"><?= htmlspecialchars(t('modal.spotify_link')) ?></label>
          <input type="url" name="proposed_cover_url" id="proposed_cover_url" required
                 placeholder="https://open.spotify.com/track/… /album/…"
                 pattern="^https?://(open\.)?spotify\.com/(track|album)/[A-Za-z0-9]+.*$" />
        </div>
        <div class="form-group">
          <label for="proposed_cover_note"><?= htmlspecialchars(t('modal.note_opt')) ?></label>
          <input type="text" name="proposed_cover_note" id="proposed_cover_note" maxlength="240"
                 placeholder="<?= htmlspecialchars(t('modal.note_ph')) ?>" />
        </div>
        <div class="edit-modal-foot">
          <button type="button" class="btn btn-ghost btn-sm" data-edit-close><?= htmlspecialchars(t('btn.cancel')) ?></button>
          <button type="submit" class="btn-primary btn-sm"><?= htmlspecialchars(t('btn.submit_proposal')) ?></button>
        </div>
      </form>
    </div>
  </div>

  <div class="edit-modal" id="modal-meta" role="dialog" aria-modal="true" aria-labelledby="modal-meta-title" hidden>
    <div class="edit-modal-backdrop" data-edit-close></div>
    <div class="edit-modal-card edit-modal-card-wide">
      <header class="edit-modal-head">
        <h2 id="modal-meta-title"><?= htmlspecialchars((function_exists('isTrusted') && isTrusted()) ? t('modal.data_edit') : t('modal.data_propose')) ?></h2>
        <button type="button" class="edit-modal-x" data-edit-close aria-label="<?= htmlspecialchars(t('btn.close')) ?>">×</button>
      </header>
      <form method="post" action="/edit.php?lyrics=<?= $id ?>" class="edit-modal-body" autocomplete="off">
        <input type="hidden" name="mode" value="meta">
        <?php if (!(function_exists('isTrusted') && isTrusted())): ?>
          <p class="edit-modal-hint"><?= htmlspecialchars(t('modal.data_hint')) ?></p>
        <?php endif; ?>
        <div class="form-group">
          <label for="m_title"><?= htmlspecialchars(t('detail.title')) ?></label>
          <input type="text" id="m_title" name="title" value="<?= htmlspecialchars($lyric["title"]) ?>" required>
        </div>
        <div class="form-group">
          <label><?= htmlspecialchars(t('detail.artist')) ?></label>
          <?php renderMultiArtistSelect('band_id', $lyric['performer_ids'] ?? [], $bandsList, t('modal.search_artist_ph')) ?>
        </div>
        <div class="form-group">
          <label><?= htmlspecialchars(t('modal.text_author')) ?></label>
          <?php renderMultiArtistSelect('text_autor_id', $lyric['text_ids'] ?? [], $bandsList, t('modal.search_text_ph')) ?>
        </div>
        <div class="form-group">
          <label><?= htmlspecialchars(t('modal.music_author')) ?></label>
          <?php renderMultiArtistSelect('musik_autor_id', $lyric['music_ids'] ?? [], $bandsList, t('modal.search_music_ph')) ?>
        </div>
        <div class="form-group">
          <label for="m_album"><?= htmlspecialchars(t('detail.album')) ?></label>
          <input type="text" id="m_album" name="album" value="<?= htmlspecialchars($lyric["album"] ?? '') ?>">
        </div>
        <div class="form-group">
          <label for="m_release_year"><?= htmlspecialchars(t('detail.release_year')) ?></label>
          <input type="number" id="m_release_year" name="release_year" min="1823" max="<?= date('Y') ?>" value="<?= htmlspecialchars($lyric["release_year"] ?? '') ?>">
        </div>
        <div class="form-group">
          <label for="m_spotify_link"><?= htmlspecialchars(t('modal.spotify_link')) ?></label>
          <input type="text" id="m_spotify_link" name="spotify_link" value="<?= htmlspecialchars($lyric["spotify_link"] ?? '') ?>">
        </div>
        <div class="form-group">
          <label for="m_video_link"><?= htmlspecialchars(t('modal.video_link')) ?></label>
          <input type="text" id="m_video_link" name="video_link" value="<?= htmlspecialchars($lyric["video_link"] ?? '') ?>">
        </div>
        <div class="edit-modal-foot">
          <button type="button" class="btn btn-ghost btn-sm" data-edit-close><?= htmlspecialchars(t('btn.cancel')) ?></button>
          <button type="submit" class="btn-primary btn-sm"><?= htmlspecialchars((function_exists('isTrusted') && isTrusted()) ? t('btn.save') : t('btn.submit_proposal')) ?></button>
        </div>
      </form>
    </div>
  </div>

  <div class="edit-modal" id="modal-lyrics" role="dialog" aria-modal="true" aria-labelledby="modal-lyrics-title" hidden>
    <div class="edit-modal-backdrop" data-edit-close></div>
    <div class="edit-modal-card edit-modal-card-wide">
      <header class="edit-modal-head">
        <h2 id="modal-lyrics-title"><?= htmlspecialchars((function_exists('isTrusted') && isTrusted()) ? t('modal.lyrics_edit') : t('modal.lyrics_propose')) ?></h2>
        <button type="button" class="edit-modal-x" data-edit-close aria-label="<?= htmlspecialchars(t('btn.close')) ?>">×</button>
      </header>
      <form method="post" action="/edit.php?lyrics=<?= $id ?>" class="edit-modal-body">
        <input type="hidden" name="mode" value="lyrics">
        <p class="edit-modal-hint"><?= t('modal.lyrics_format_hint') ?></p>
        <div class="form-group">
          <textarea id="l_lyrics" name="lyrics" rows="18" spellcheck="false"
            style="font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:0.92rem;line-height:1.55;white-space:pre;width:100%;"><?= htmlspecialchars($lyric["lyrics"] ?? '') ?></textarea>
        </div>
        <div class="edit-modal-foot">
          <button type="button" class="btn btn-ghost btn-sm" data-edit-close><?= htmlspecialchars(t('btn.cancel')) ?></button>
          <button type="submit" class="btn-primary btn-sm"><?= htmlspecialchars((function_exists('isTrusted') && isTrusted()) ? t('btn.save') : t('btn.submit_proposal')) ?></button>
        </div>
      </form>
    </div>
  </div>

  <style>
    .edit-modal[hidden] { display: none !important; }
    .edit-modal {
      position: fixed; inset: 0;
      z-index: 1000;
      display: flex; align-items: center; justify-content: center;
      /* Extra top padding so the close-X never sits behind the floating topbar */
      padding: 4.75rem 1rem 1rem;
    }
    .edit-modal-backdrop {
      position: absolute; inset: 0;
      background: rgba(15,23,42,0.45);
      backdrop-filter: blur(14px) saturate(140%);
      -webkit-backdrop-filter: blur(14px) saturate(140%);
      animation: editFade 0.18s var(--ease-out, ease-out) both;
    }
    html.dark .edit-modal-backdrop { background: rgba(8,11,16,0.55); }
    .edit-modal-card {
      position: relative;
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 18px;
      box-shadow: 0 24px 80px rgba(15,23,42,0.30), 0 4px 16px rgba(15,23,42,0.12);
      width: 100%;
      max-width: 460px;
      /* Match the modal's top+bottom padding (4.75rem + 1rem) */
      max-height: calc(100dvh - 5.75rem);
      display: flex; flex-direction: column;
      overflow: hidden;
      animation: editPop 0.22s var(--ease-out, ease-out) both;
    }
    .edit-modal-card-wide { max-width: 640px; }
    html.dark .edit-modal-card {
      background: rgba(28,33,40,0.96);
      border-color: rgba(255,255,255,0.08);
      box-shadow: 0 24px 80px rgba(0,0,0,0.55), 0 4px 16px rgba(0,0,0,0.35);
    }
    .edit-modal-head {
      display: flex; align-items: center; justify-content: space-between;
      padding: 1rem 1.25rem;
      border-bottom: 1px solid var(--border);
      flex-shrink: 0;
    }
    .edit-modal-head h2 {
      margin: 0; font-size: 1.05rem; font-weight: 700; color: var(--text);
      letter-spacing: 0.01em;
    }
    .edit-modal-x {
      background: transparent !important;
      border: none !important;
      color: var(--text-3) !important;
      font-size: 1.5rem !important;
      line-height: 1 !important;
      padding: 0.2rem 0.55rem !important;
      cursor: pointer !important;
      border-radius: 8px !important;
      box-shadow: none !important;
    }
    .edit-modal-x:hover { background: var(--bg-alt) !important; color: var(--text) !important; }
    .edit-modal-body {
      padding: 1.1rem 1.25rem 0.5rem;
      overflow-y: auto;
      flex: 1 1 auto;
    }
    .edit-modal-body .form-group { margin-bottom: 0.85rem; }
    .edit-modal-body label {
      display: block; font-size: 0.78rem; font-weight: 600;
      color: var(--text-2); margin-bottom: 0.3rem;
      text-transform: uppercase; letter-spacing: 0.04em;
    }
    .edit-modal-body input[type="text"],
    .edit-modal-body input[type="url"],
    .edit-modal-body input[type="number"],
    .edit-modal-body textarea {
      width: 100%; padding: 0.6rem 0.8rem !important;
      border: 1px solid var(--border) !important; border-radius: 9px !important;
      background: var(--card) !important; color: var(--text) !important;
      font-size: 0.92rem !important;
    }
    .edit-modal-body textarea { resize: vertical; min-height: 220px; }
    .edit-modal-hint {
      font-size: 0.85rem; color: var(--text-3); margin: 0 0 0.85rem;
      line-height: 1.45;
    }
    .edit-modal-hint code {
      background: var(--bg-alt); padding: 0.1em 0.4em; border-radius: 4px;
      font-size: 0.85em;
    }
    .edit-modal-foot {
      display: flex; justify-content: flex-end; gap: 0.5rem;
      padding: 0.85rem 1.25rem 1.1rem;
      border-top: 1px solid var(--border);
      flex-shrink: 0;
      background: var(--card);
    }
    .edit-modal-body .band-select {
      width: 100%; margin-top: 0.3rem;
      background: var(--card); color: var(--text);
      border: 1px solid var(--border); border-radius: 9px;
      font-size: 0.9rem;
      display: none;
    }
    @keyframes editFade { from { opacity: 0; } to { opacity: 1; } }
    @keyframes editPop {
      from { opacity: 0; transform: translateY(8px) scale(0.98); }
      to   { opacity: 1; transform: translateY(0) scale(1); }
    }
    body.edit-modal-open { overflow: hidden; }
    @media (max-width: 480px) {
      .edit-modal { padding: 4rem 0.5rem 0.5rem; }
      .edit-modal-card { max-height: calc(100dvh - 4.5rem); border-radius: 14px; }
      .edit-modal-head { padding: 0.85rem 1rem; }
      .edit-modal-body { padding: 0.9rem 1rem 0.4rem; }
      .edit-modal-foot { padding: 0.75rem 1rem 0.95rem; }
      .edit-modal-head h2 { font-size: 0.98rem; }
    }
  </style>

  <script>
    (function() {
      var openTrigger = null;

      function openModal(id) {
        var modal = document.getElementById(id);
        if (!modal) return;
        openTrigger = document.activeElement;
        modal.hidden = false;
        document.body.classList.add('edit-modal-open');
        var first = modal.querySelector('input, textarea, button.btn-primary');
        if (first) setTimeout(function(){ first.focus(); }, 80);
      }
      function closeModal(modal) {
        if (!modal) return;
        modal.hidden = true;
        if (!document.querySelector('.edit-modal:not([hidden])')) {
          document.body.classList.remove('edit-modal-open');
        }
        if (openTrigger && typeof openTrigger.focus === 'function') openTrigger.focus();
        openTrigger = null;
      }
      function closeAll() {
        document.querySelectorAll('.edit-modal:not([hidden])').forEach(closeModal);
      }

      document.addEventListener('click', function(e) {
        var trigger = e.target.closest('[data-edit-open]');
        if (trigger) {
          e.preventDefault();
          openModal(trigger.getAttribute('data-edit-open'));
          return;
        }
        var closer = e.target.closest('[data-edit-close]');
        if (closer) {
          e.preventDefault();
          closeModal(closer.closest('.edit-modal'));
        }
      });
      document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeAll();
      });

      // Wire cover-add-btn / cover-edit-btn (inside the cover image) to cover modal
      ['cover-add-btn', 'cover-edit-btn'].forEach(function(id) {
        var btn = document.getElementById(id);
        if (btn) btn.addEventListener('click', function(e) {
          e.preventDefault();
          openModal('modal-cover');
        });
      });

      // Multi-artist widgets are initialized inline via initMasWidget() calls
    })();
  </script>
  <?php endif; ?>

<!-- QR Modal -->
<div id="qr-modal" style="display:none;position:fixed;inset:0;z-index:8000;background:rgba(0,0,0,0.55);backdrop-filter:blur(4px);display:none;align-items:center;justify-content:center;" onclick="if(event.target===this)this.style.display='none'">
  <div style="background:var(--card);border-radius:var(--radius-lg);padding:1.75rem;max-width:320px;width:90%;text-align:center;box-shadow:var(--shadow-lg);">
    <h3 style="margin:0 0 0.25rem;font-size:1rem;"><?= htmlspecialchars($lyric['title']) ?></h3>
    <p style="margin:0 0 1.25rem;font-size:0.85rem;color:var(--text-2);"><?= $bandName ?></p>
    <div id="qr-canvas" style="display:flex;justify-content:center;margin-bottom:1.25rem;"></div>
    <p style="margin:0 0 1rem;font-size:0.8rem;color:var(--text-3);word-break:break-all;" id="qr-url"></p>
    <div style="display:flex;gap:0.5rem;justify-content:center;">
      <button type="button" class="btn btn-sm" id="qr-share-btn" style="display:none;">Teilen</button>
      <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('qr-modal').style.display='none'">Schließen</button>
    </div>
  </div>
</div>

<style>
  .round-icon-btn.fav-active { color: #ef4444 !important; border-color: rgba(239,68,68,0.4) !important; }
  html.dark .round-icon-btn.fav-active { color: #fca5a5 !important; border-color: rgba(252,165,165,0.35) !important; }
  .round-icon-btn.fav-active:hover { background: #ef4444 !important; color: #fff !important; border-color: #ef4444 !important; }
</style>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" crossorigin="anonymous"></script>
<script>
(function() {
  var SONG_ID  = <?= (int)$id ?>;
  var SONG_URL = 'https://singopkoelsch.de/detail.php?lyrics=' + SONG_ID;

  // ── Favorite toggle ──────────────────────────────────────────────
  var favBtn = document.getElementById('fav-btn');
  if (favBtn) {
    favBtn.addEventListener('click', async function() {
      var faved  = favBtn.dataset.faved === '1';
      var method = faved ? 'DELETE' : 'POST';
      var path   = faved ? '/api/favorites/' + SONG_ID : '/api/favorites';
      var body   = faved ? null : JSON.stringify({ song_id: SONG_ID });
      try {
        var r = await fetch(path, { method: method, headers: { 'Content-Type': 'application/json' }, body: body, credentials: 'same-origin' });
        if (!r.ok) throw new Error();
        var nowFaved = !faved;
        favBtn.dataset.faved = nowFaved ? '1' : '0';
        favBtn.classList.toggle('fav-active', nowFaved);
        favBtn.title = nowFaved ? 'Aus Favoriten entfernen' : 'Zu Favoriten hinzufügen';
        var icon = document.getElementById('fav-icon');
        if (icon) icon.setAttribute('fill', nowFaved ? 'currentColor' : 'none');
      } catch(e) { /* silently ignore */ }
    });
  }

  // ── QR Code + Share ─────────────────────────────────────────────
  var qrBtn   = document.getElementById('qr-btn');
  var qrModal = document.getElementById('qr-modal');
  var qrShare = document.getElementById('qr-share-btn');
  var qrRendered = false;

  if (qrBtn && qrModal) {
    qrBtn.addEventListener('click', function() {
      if (!qrRendered) {
        document.getElementById('qr-url').textContent = SONG_URL;
        new QRCode(document.getElementById('qr-canvas'), {
          text: SONG_URL, width: 200, height: 200,
          colorDark: document.documentElement.classList.contains('dark') ? '#f1f5f9' : '#0f172a',
          colorLight: document.documentElement.classList.contains('dark') ? '#1c2128' : '#ffffff',
          correctLevel: QRCode.CorrectLevel.M
        });
        qrRendered = true;
      }
      if (navigator.share) {
        qrShare.style.display = '';
        qrShare.onclick = function() {
          navigator.share({ title: <?= json_encode($lyric['title']) ?>, url: SONG_URL }).catch(function(){});
        };
      }
      qrModal.style.display = 'flex';
    });
  }
})();
</script>


<?php if (!empty($_relatedSongs)): ?>
  <!-- #9 Verwandte Songs + #52 Zufälliger Song dieser Band -->
  <div style="margin-top:2.5rem;border-top:1px solid var(--border);padding-top:1.75rem;">
    <header class="detail-section-head">
      <h3>Mehr von <?= htmlspecialchars($bandName) ?></h3>
      <a href="/api/songs/random/band/<?= $_bandId ?>?redirect=1" class="round-icon-btn" title="Zufälliger Song dieser Band" aria-label="Zufälliger Song dieser Band">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 3 21 3 21 8"/><line x1="4" y1="20" x2="21" y2="3"/><polyline points="21 16 21 21 16 21"/><line x1="15" y1="15" x2="21" y2="21"/></svg>
      </a>
    </header>
    <div style="display:flex;flex-direction:column;gap:0.4rem;margin-top:0.75rem;">
      <?php foreach ($_relatedSongs as $_rs): ?>
        <a href="/detail.php?lyrics=<?= (int)$_rs['id'] ?>" style="display:flex;align-items:center;gap:0.65rem;padding:0.6rem 0.75rem;background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.06);border-radius:10px;text-decoration:none;transition:background 0.15s,border-color 0.15s;">
          <?php if (!empty($_rs['cover_url'])): ?>
            <img src="<?= htmlspecialchars($_rs['cover_url']) ?>" style="width:38px;height:38px;border-radius:5px;object-fit:cover;flex-shrink:0;" alt="" loading="lazy">
          <?php else: ?>
            <span style="width:38px;height:38px;border-radius:5px;background:rgba(220,38,38,0.1);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:1.1rem;">🎵</span>
          <?php endif; ?>
          <div style="min-width:0;">
            <div style="font-weight:600;font-size:0.88rem;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($_rs['title']) ?></div>
            <?php if (!empty($_rs['album'])): ?><div style="font-size:0.78rem;color:var(--text-3);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($_rs['album']) ?></div><?php endif; ?>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

</main>

<?php require_once "partials/multi_artist_js.php"; ?>
<?php require_once "partials/footer.php"; ?>
