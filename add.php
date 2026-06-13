<?php
require_once "protect.php";
require_once "functions.php";

requireLogin();
if (!isTrusted()) {
    http_response_code(403);
    _renderAccessDenied(t('err.trusted_only'));
    exit;
}

Database::getConnection();
Database::ensurePreferencesTable();
$bands = Database::getBandList();

$title        = trim($_POST["title"] ?? "");
$album        = $_POST["album"] ?? "";
$spotify_link = $_POST["spotify_link"] ?? "";
$video_link   = $_POST["video_link"] ?? "";
$release_year = $_POST["release_year"] ?? "";
$lyrics       = trim($_POST["lyrics"] ?? "");
$band         = $_POST["band_id"] ?? "";
$text_autor_id   = $_POST["text_autor_id"] ?? "";
$musik_autor_id  = $_POST["musik_autor_id"] ?? "";

$message = ""; $msgType = "info";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $success = Database::insertData();
    $message = $_POST["message"] ?? ($success ? t('add.success') : t('add.fail'));
    $msgType = $success ? "success" : "error";
    if ($success) {
        $title = $album = $spotify_link = $video_link = $release_year = $lyrics = "";
        $band  = $text_autor_id = $musik_autor_id = "";
    }
}

$pageTitle = t('add.title') . ' – Sing op Kölsch';
require_once "partials/head.php";
require_once "partials/nav.php";
?>

<main class="content">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.75rem;margin-bottom:1.5rem;">
    <div>
      <h1><?= htmlspecialchars(t('add.title')) ?></h1>
    </div>
    <a href="/bulk.php" class="btn btn-ghost btn-sm">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/></svg>
      <?= htmlspecialchars(t('add.bulk_upload')) ?>
    </a>
  </div>

  <?php if ($message): ?>
    <div class="alert alert-<?= $msgType ?>" style="cursor:pointer;" onclick="this.style.display='none'">
      <?= htmlspecialchars($message) ?>
    </div>
  <?php endif; ?>

  <div class="card">
    <div class="card-header"><?= htmlspecialchars(t('edit.song_details')) ?></div>
    <form method="post" autocomplete="off" id="form">

      <div class="form-section">
        <div class="form-group">
          <label for="title"><?= htmlspecialchars(t('add.title_field')) ?> *</label>
          <input type="text" id="title" name="title" autofocus required value="<?= htmlspecialchars($title) ?>">
        </div>

        <div class="form-group" style="position:relative;">
          <label for="band_search"><?= htmlspecialchars(t('detail.artist')) ?></label>
          <input type="text" id="band_search" placeholder="<?= htmlspecialchars(t('modal.search_artist_ph')) ?>" autocomplete="off" value="">
          <select name="band_id" id="band_id" size="5" class="band-select" aria-label="<?= htmlspecialchars(t('detail.artist')) ?>">
            <option value=""><?= htmlspecialchars(t('modal.choose')) ?></option>
            <?php foreach ($bands as $row): ?>
              <option value="<?= htmlspecialchars($row['band_id']) ?>" <?= ($row['band_id'] == $band) ? 'selected' : '' ?>>
                <?= htmlspecialchars($row['band_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group" style="position:relative;">
          <label for="text_autor_search"><?= htmlspecialchars(t('modal.text_author')) ?></label>
          <input type="text" id="text_autor_search" placeholder="<?= htmlspecialchars(t('modal.search_text_ph')) ?>" autocomplete="off">
          <select name="text_autor_id" id="text_autor_id" size="5" class="band-select" aria-label="<?= htmlspecialchars(t('modal.text_author')) ?>">
            <option value=""><?= htmlspecialchars(t('modal.choose')) ?></option>
            <?php foreach ($bands as $row): ?>
              <option value="<?= htmlspecialchars($row['band_id']) ?>" <?= ($row['band_id'] == $text_autor_id) ? 'selected' : '' ?>>
                <?= htmlspecialchars($row['band_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group" style="position:relative;">
          <label for="musik_autor_search"><?= htmlspecialchars(t('modal.music_author')) ?></label>
          <input type="text" id="musik_autor_search" placeholder="<?= htmlspecialchars(t('modal.search_music_ph')) ?>" autocomplete="off">
          <select name="musik_autor_id" id="musik_autor_id" size="5" class="band-select" aria-label="<?= htmlspecialchars(t('modal.music_author')) ?>">
            <option value=""><?= htmlspecialchars(t('modal.choose')) ?></option>
            <?php foreach ($bands as $row): ?>
              <option value="<?= htmlspecialchars($row['band_id']) ?>" <?= ($row['band_id'] == $musik_autor_id) ? 'selected' : '' ?>>
                <?= htmlspecialchars($row['band_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="form-section">
        <div class="form-group">
          <label for="album"><?= htmlspecialchars(t('add.album_field')) ?></label>
          <input type="text" id="album" name="album" value="<?= htmlspecialchars($album) ?>">
        </div>
        <div class="form-group">
          <label for="release_year"><?= htmlspecialchars(t('add.year_field')) ?></label>
          <input type="number" id="release_year" name="release_year" min="1823" max="<?= date('Y') ?>" value="<?= htmlspecialchars($release_year) ?>">
        </div>
        <div class="form-group">
          <label for="spotify_link"><?= htmlspecialchars(t('add.spotify_field')) ?></label>
          <input type="text" id="spotify_link" name="spotify_link" value="<?= htmlspecialchars($spotify_link) ?>">
        </div>
        <div class="form-group">
          <label for="video_link"><?= htmlspecialchars(t('add.video_field')) ?></label>
          <input type="text" id="video_link" name="video_link" value="<?= htmlspecialchars($video_link) ?>">
        </div>
      </div>

      <div class="form-section">
        <div class="form-group">
          <label><?= htmlspecialchars(t('add.lyrics_field')) ?></label>
          <div id="target" contenteditable="true"></div>
          <textarea id="lyrics" name="lyrics" style="display:none;"><?= htmlspecialchars($lyrics) ?></textarea>
          <p class="form-hint"><?= t('add.lyrics_hint') ?></p>
        </div>
      </div>

      <div class="form-section">
        <div class="btn-row right">
          <a href="/" class="btn btn-ghost"><?= htmlspecialchars(t('btn.cancel')) ?></a>
          <button type="submit" class="btn-primary"><?= htmlspecialchars(t('add.save')) ?></button>
        </div>
      </div>

    </form>
  </div>
</main>

<script>
function cleanWordHtml(html) {
    if (!html) return '';
    return html
        .replace(/<!--[\s\S]*?-->/g, '')
        .replace(/<style[^>]*>[\s\S]*?<\/style>/gi, '')
        .replace(/\s*mso-[^:;"]+:[^;"]+;?/gi, '')
        .replace(/\s*(class|id)="[^"]*"/gi, '')
        .replace(/<o:p>\s*<\/o:p>/g, '')
        .replace(/<o:p>[\s\S]*?<\/o:p>/g, ' ')
        .replace(/<\/?w:[^>]*>/gi, '');
}

function htmlToMarkdown(html) {
    const container = document.createElement('div');
    container.innerHTML = html;
    function traverse(node) {
        let md = '';
        node.childNodes.forEach(child => {
            if (child.nodeType === Node.TEXT_NODE) {
                md += child.textContent.replace(/\s+/g, ' ');
            } else if (child.nodeType === Node.ELEMENT_NODE) {
                const tag = child.tagName.toLowerCase();
                const fw  = child.style?.fontWeight;
                if (tag === 'strong' || fw === '700' || fw === 'bold') md += `**${traverse(child)}**`;
                else if (tag === 'i' || tag === 'em')  md += `*${traverse(child)}*`;
                else if (tag === 'u')  md += `<u>${traverse(child)}</u>`;
                else if (tag === 'br') md += '\n';
                else if (tag === 'p' || tag === 'div') md += traverse(child).trim() + '\n';
                else md += traverse(child);
            }
        });
        return md;
    }
    return traverse(container).trim().replace(/\n{3,}/g, '\n\n').replace(/\*\*\*\*/g, '**');
}

function syncLyrics() {
    const md = htmlToMarkdown(cleanWordHtml(document.getElementById('target').innerHTML));
    document.getElementById('lyrics').value = md;
}

document.getElementById('form').addEventListener('submit', syncLyrics);
document.getElementById('target').addEventListener('input', syncLyrics);
document.getElementById('target').addEventListener('paste', function(e) {
    e.preventDefault();
    const html = (e.clipboardData || window.clipboardData).getData('text/html');
    if (html) { document.getElementById('target').innerHTML += cleanWordHtml(html); syncLyrics(); }
});

document.addEventListener("DOMContentLoaded", () => {
    setupSearchableSelect('band_search',        'band_id');
    setupSearchableSelect('text_autor_search',  'text_autor_id');
    setupSearchableSelect('musik_autor_search', 'musik_autor_id');
});

function setupSearchableSelect(inputId, selectId) {
    const input    = document.getElementById(inputId);
    const select   = document.getElementById(selectId);
    const origOpts = Array.from(select.options).filter(o => o.value !== '');

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
