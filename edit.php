<?php
require_once "protect.php";
require_once "functions.php";

requireLogin();

if (!isset($_GET["lyrics"]) || empty($_GET["lyrics"])) {
    require __DIR__ . "/404.php";
    exit();
}

Database::getConnection();
Database::ensurePreferencesTable();

$id    = (int)$_GET["lyrics"];
$lyric = Database::queryDataById($id);

if (!$lyric) {
    require __DIR__ . "/404.php";
    exit();
}

$bands = Database::getBandList();

$message = ""; $msgType = "info";
$canBypass = function_exists('isTrusted') && isTrusted();
$mode      = $_GET['mode'] ?? ($_POST['mode'] ?? 'all');
if (!in_array($mode, ['all', 'meta', 'lyrics'], true)) $mode = 'all';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Always start from current lyric and overlay only what this mode sent.
    if ($mode === 'lyrics') {
        $title        = (string)($lyric['title'] ?? '');
        $bandId       = $lyric['band_id']        !== null ? (int)$lyric['band_id']        : null;
        $textAutorId  = $lyric['text_autor_id']  !== null ? (int)$lyric['text_autor_id']  : null;
        $musikAutorId = $lyric['musik_autor_id'] !== null ? (int)$lyric['musik_autor_id'] : null;
        $spotify      = (string)($lyric['spotify_link'] ?? '');
        $video        = (string)($lyric['video_link'] ?? '');
        $year         = $lyric['release_year']   !== null ? (string)$lyric['release_year']   : '';
        $album        = (string)($lyric['album'] ?? '');
        $lyricsText   = (string)($_POST["lyrics"] ?? '');
    } elseif ($mode === 'meta') {
        $title        = trim($_POST["title"] ?? "");
        $bandId       = Database::processNewBandEntry($_POST["band_id"] ?? null);
        $textAutorId  = Database::processNewBandEntry($_POST["text_autor_id"] ?? null);
        $musikAutorId = Database::processNewBandEntry($_POST["musik_autor_id"] ?? null);
        $spotify      = $_POST["spotify_link"] ?? "";
        $video        = $_POST["video_link"] ?? "";
        $year         = $_POST["release_year"] ?? "";
        $album        = $_POST["album"] ?? "";
        $lyricsText   = (string)($lyric['lyrics'] ?? '');
    } else {
        $title        = trim($_POST["title"] ?? "");
        $lyricsText   = $_POST["lyrics"] ?? "";
        $bandId       = Database::processNewBandEntry($_POST["band_id"] ?? null);
        $textAutorId  = Database::processNewBandEntry($_POST["text_autor_id"] ?? null);
        $musikAutorId = Database::processNewBandEntry($_POST["musik_autor_id"] ?? null);
        $spotify      = $_POST["spotify_link"] ?? "";
        $video        = $_POST["video_link"] ?? "";
        $year         = $_POST["release_year"] ?? "";
        $album        = $_POST["album"] ?? "";
    }

    if ($canBypass) {
        $success = Database::updateData($id, $title, $lyricsText, $bandId, $textAutorId, $musikAutorId, $album, $spotify, $video, $year);
        if ($success) {
            header('Location: /detail.php?lyrics=' . $id . '&saved=1');
            exit;
        } else {
            $message = t('edit.update_fail');
            $msgType = "error";
        }
    } else {
        $proposed = [
            'title'          => $title,
            'lyrics'         => $lyricsText,
            'band_id'        => $bandId,
            'text_autor_id'  => $textAutorId,
            'musik_autor_id' => $musikAutorId,
            'album'          => $album,
            'spotify_link'   => $spotify,
            'video_link'     => $video,
            'release_year'   => $year !== '' ? (int)$year : null,
        ];
        $current = [
            'title'          => (string)($lyric['title'] ?? ''),
            'lyrics'         => (string)($lyric['lyrics'] ?? ''),
            'band_id'        => $lyric['band_id']        !== null ? (int)$lyric['band_id']        : null,
            'text_autor_id'  => $lyric['text_autor_id']  !== null ? (int)$lyric['text_autor_id']  : null,
            'musik_autor_id' => $lyric['musik_autor_id'] !== null ? (int)$lyric['musik_autor_id'] : null,
            'album'          => (string)($lyric['album'] ?? ''),
            'spotify_link'   => (string)($lyric['spotify_link'] ?? ''),
            'video_link'     => (string)($lyric['video_link'] ?? ''),
            'release_year'   => $lyric['release_year']   !== null ? (int)$lyric['release_year']   : null,
        ];
        // Only persist fields that differ from the current values.
        $changes = [];
        foreach ($proposed as $k => $v) {
            if ((string)$v !== (string)$current[$k]) $changes[$k] = $v;
        }
        if (empty($changes)) {
            $message = t('edit.no_change');
            $msgType = "info";
            require_once "partials/head.php";
            require_once "partials/nav.php";
            echo '<main class="content"><div class="alert alert-info">' . htmlspecialchars($message) . '</div></main>';
            require_once "partials/footer.php";
            exit;
        }
        $ok = Database::insertFullChangeRequest($id, (int)$_SESSION['user_id'], $changes);
        if ($ok) {
            // Notify admins
            $admins = Database::getAdmins();
            $u      = Database::getUserById((int)$_SESSION['user_id']);
            $userName = $u['name'] ?? 'Unbekannt';
            $link   = SITE_URL . "/detail.php?lyrics=$id";
            $hAdmin = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $changedKeys = array_keys($changes);
            $changedList = $changedKeys ? implode(', ', $changedKeys) : '–';
            foreach ($admins as $admin) {
                $subject = 'Änderungsvorschlag (Felder) für "' . $lyric['title'] . '"';
                $body    = "Hallo {$admin['name']},\n\n$userName hat einen Änderungsvorschlag eingereicht.\n"
                         . "Geänderte Felder: $changedList\n\n"
                         . "Vorschlag prüfen: $link\n\nDein Sing op Kölsch System";
                $detailHtml = '<strong>Lied:</strong> ' . $hAdmin($lyric['title']) . '<br>'
                            . '<strong>Geänderte Felder:</strong> ' . $hAdmin($changedList);
                $html = renderEmailHtml('Neuer Feld-Änderungsvorschlag', [
                    'greeting'    => 'Hallo ' . $admin['name'] . ',',
                    'intro'       => $userName . ' hat Änderungen an einem Lied vorgeschlagen.',
                    'detail_html' => $detailHtml,
                    'cta_label'   => 'Vorschlag prüfen',
                    'cta_url'     => $link,
                    'footer_note' => 'Du bekommst diese Mail, weil du Administrator bist.',
                ]);
                sendMail($admin["email"], $admin["name"], $subject, $body, ['html' => $html]);
            }
            header('Location: /detail.php?lyrics=' . $id . '&proposed=1');
            exit;
        } else {
            $message = t('detail.save_proposal_fail');
            $msgType = "error";
        }
    }
}

$pageTitle = t('edit.title') . ': ' . htmlspecialchars($lyric["title"]) . ' – Sing op Kölsch';
require_once "partials/head.php";
require_once "partials/nav.php";
?>

<main class="content">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.75rem;margin-bottom:1.5rem;">
    <div>
      <h1><?= htmlspecialchars($canBypass ? t('edit.title') : t('edit.propose')) ?>: <?= htmlspecialchars($lyric["title"]) ?></h1>
      <?php if (!$canBypass): ?>
        <p style="color:var(--text-2);font-size:0.88rem;margin:0.35rem 0 0;">
          <?= htmlspecialchars(t('modal.data_hint')) ?>
        </p>
      <?php else: ?>
        <p style="color:var(--text-2);font-size:0.88rem;margin:0.35rem 0 0;">
          <span class="badge badge-green"><?= htmlspecialchars(t('edit.applied_now')) ?></span>
        </p>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($message): ?>
    <div class="alert alert-<?= $msgType ?>" style="cursor:pointer;" onclick="this.style.display='none'">
      <?= htmlspecialchars($message) ?>
    </div>
  <?php endif; ?>

  <div class="card">
    <div class="card-header">
      <?php if ($mode === 'lyrics'): ?><?= htmlspecialchars(t('edit.lyrics_only')) ?>
      <?php elseif ($mode === 'meta'): ?><?= htmlspecialchars(t('edit.meta_only')) ?>
      <?php else: ?><?= htmlspecialchars(t('edit.song_details')) ?>
      <?php endif; ?>
    </div>
    <form method="post" autocomplete="off" id="form">
      <input type="hidden" name="mode" value="<?= htmlspecialchars($mode) ?>">

      <?php if ($mode !== 'lyrics'): ?>
      <div class="form-section">
        <div class="form-group">
          <label for="title"><?= htmlspecialchars(t('add.title_field')) ?> *</label>
          <input type="text" id="title" name="title" value="<?= htmlspecialchars($lyric["title"]) ?>" required>
        </div>

        <div class="form-group" style="position:relative;">
          <label for="band_search"><?= htmlspecialchars(t('detail.artist')) ?></label>
          <input type="text" id="band_search" placeholder="<?= htmlspecialchars(t('modal.search_artist_ph')) ?>" autocomplete="off">
          <select id="band_id" name="band_id" size="5" class="band-select">
            <option value=""><?= htmlspecialchars(t('modal.choose')) ?></option>
            <?php foreach ($bands as $b): ?>
              <option value="<?= $b["band_id"] ?>" <?= ($b["band_id"] == $lyric["band_id"]) ? "selected" : "" ?>>
                <?= htmlspecialchars($b["band_name"]) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group" style="position:relative;">
          <label for="text_autor_search"><?= htmlspecialchars(t('modal.text_author')) ?></label>
          <input type="text" id="text_autor_search" placeholder="<?= htmlspecialchars(t('modal.search_text_ph')) ?>" autocomplete="off">
          <select id="text_autor_id" name="text_autor_id" size="5" class="band-select">
            <option value=""><?= htmlspecialchars(t('modal.choose')) ?></option>
            <?php foreach ($bands as $b): ?>
              <option value="<?= $b["band_id"] ?>" <?= (isset($lyric["text_autor_id"]) && $b["band_id"] == $lyric["text_autor_id"]) ? "selected" : "" ?>>
                <?= htmlspecialchars($b["band_name"]) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group" style="position:relative;">
          <label for="musik_autor_search"><?= htmlspecialchars(t('modal.music_author')) ?></label>
          <input type="text" id="musik_autor_search" placeholder="<?= htmlspecialchars(t('modal.search_music_ph')) ?>" autocomplete="off">
          <select id="musik_autor_id" name="musik_autor_id" size="5" class="band-select">
            <option value=""><?= htmlspecialchars(t('modal.choose')) ?></option>
            <?php foreach ($bands as $b): ?>
              <option value="<?= $b["band_id"] ?>" <?= (isset($lyric["musik_autor_id"]) && $b["band_id"] == $lyric["musik_autor_id"]) ? "selected" : "" ?>>
                <?= htmlspecialchars($b["band_name"]) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="form-section">
        <div class="form-group">
          <label for="album"><?= htmlspecialchars(t('add.album_field')) ?></label>
          <input type="text" id="album" name="album" value="<?= htmlspecialchars($lyric["album"] ?? '') ?>">
        </div>
        <div class="form-group">
          <label for="release_year"><?= htmlspecialchars(t('add.year_field')) ?></label>
          <input type="number" id="release_year" name="release_year" min="1823" max="<?= date('Y') ?>" value="<?= htmlspecialchars($lyric["release_year"] ?? '') ?>">
        </div>
        <div class="form-group">
          <label for="spotify_link"><?= htmlspecialchars(t('add.spotify_field')) ?></label>
          <input type="text" id="spotify_link" name="spotify_link" value="<?= htmlspecialchars($lyric["spotify_link"] ?? '') ?>">
        </div>
        <div class="form-group">
          <label for="video_link"><?= htmlspecialchars(t('add.video_field')) ?></label>
          <input type="text" id="video_link" name="video_link" value="<?= htmlspecialchars($lyric["video_link"] ?? '') ?>">
        </div>
      </div>
      <?php endif; ?>

      <?php if ($mode !== 'meta'): ?>
      <div class="form-section">
        <div class="form-group">
          <label for="lyrics"><?= htmlspecialchars(t('add.lyrics_field')) ?></label>
          <p class="text-muted text-sm" style="margin:-0.2rem 0 0.45rem;"><?= t('modal.lyrics_format_hint') ?></p>
          <textarea id="lyrics" name="lyrics" rows="20" spellcheck="false" style="font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:0.92rem;line-height:1.55;white-space:pre;"><?= htmlspecialchars($lyric["lyrics"] ?? '') ?></textarea>
        </div>
      </div>
      <?php endif; ?>

      <div class="form-section">
        <div class="btn-row right">
          <a href="detail.php?lyrics=<?= $id ?>" class="btn btn-ghost"><?= htmlspecialchars(t('btn.cancel')) ?></a>
          <button type="submit" class="btn-primary"><?= htmlspecialchars($canBypass ? t('btn.save') : t('btn.submit_proposal')) ?></button>
        </div>
      </div>

    </form>
  </div>
</main>

<script>
document.addEventListener("DOMContentLoaded", () => {
    if (document.getElementById('band_search'))        setupSearchableSelect('band_search',        'band_id');
    if (document.getElementById('text_autor_search'))  setupSearchableSelect('text_autor_search',  'text_autor_id');
    if (document.getElementById('musik_autor_search')) setupSearchableSelect('musik_autor_search', 'musik_autor_id');
});

function setupSearchableSelect(inputId, selectId) {
    const input    = document.getElementById(inputId);
    const select   = document.getElementById(selectId);
    const origOpts = Array.from(select.options).filter(o => o.value !== '');
    const selected = select.options[select.selectedIndex];
    if (selected && selected.value !== '') input.value = selected.text;

    input.addEventListener('input', () => {
        const q = input.value.trim().toLowerCase();
        select.innerHTML = '';
        if (!q) { select.style.display = 'none'; select.appendChild(new Option('– Bitte wählen –', '')); origOpts.forEach(o => select.appendChild(o)); select.value = ''; return; }
        const filtered   = origOpts.filter(o => o.text.toLowerCase().includes(q));
        const exactMatch = filtered.some(o => o.text.toLowerCase() === q);
        select.appendChild(new Option('– Bitte wählen –', ''));
        if (!exactMatch) select.appendChild(new Option(`[+ Neu: ${input.value}]`, `new:${input.value}`));
        filtered.forEach(o => select.appendChild(o));
        select.style.display = 'block';
        select.selectedIndex = filtered.length > 0 ? (exactMatch ? 1 : 2) : 1;
    });

    function confirmSelection() {
        const opt = select.options[select.selectedIndex];
        if (opt && opt.value !== '' && !opt.value.startsWith('new:')) input.value = opt.text;
        select.style.display = 'none';
    }

    select.addEventListener('change', confirmSelection);
    input.addEventListener('blur',    () => setTimeout(confirmSelection, 150));
    select.addEventListener('blur',   () => setTimeout(confirmSelection, 150));
    input.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); confirmSelection(); } });
}
</script>

<?php require_once "partials/footer.php"; ?>
