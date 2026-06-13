<?php
require_once "protect.php";
require_once "functions.php";

requireAdmin();

$conn = Database::getConnection();

function getOrCreateBand(mysqli $conn, string $bandName): ?int {
    $bandName = trim($bandName);
    if ($bandName === '') return null;

    $stmt = $conn->prepare("SELECT band_id FROM singopkoelsch_bands WHERE band_name = ?");
    $stmt->bind_param("s", $bandName);
    $stmt->execute();
    $stmt->bind_result($bandId);
    if ($stmt->fetch()) { $stmt->close(); return (int)$bandId; }
    $stmt->close();

    $stmt = $conn->prepare("INSERT INTO singopkoelsch_bands (band_name) VALUES (?)");
    $stmt->bind_param("s", $bandName);
    $stmt->execute();
    $id = $stmt->insert_id;
    $stmt->close();
    return $id ?: null;
}

function bulkInsertSong(mysqli $conn, array $song): string {
    $titel      = trim($song['title'] ?? '');
    $interpret  = trim($song['interpret'] ?? '');
    $musikAutor = trim($song['music_author'] ?? '');
    $textAutor  = trim($song['text_author'] ?? '');
    $lyrics     = trim($song['lyrics'] ?? '');
    $spotify    = trim($song['spotify_link'] ?? '');
    $video      = trim($song['video_link'] ?? '');
    $year       = isset($song['release_year']) ? (int)$song['release_year'] : null;
    $album      = trim($song['album'] ?? '');

    if ($titel === '' || $interpret === '' || $lyrics === '') {
        return "Ungültige Daten – Titel, Interpret und Lyrics erforderlich.";
    }

    $interpretId = getOrCreateBand($conn, $interpret);

    $stmt = $conn->prepare("SELECT id FROM singopkoelsch_lyrics WHERE title = ? AND band_id = ?");
    $stmt->bind_param("si", $titel, $interpretId);
    $stmt->execute();
    $stmt->bind_result($songId);
    $exists = $stmt->fetch();
    $stmt->close();
    if ($exists) return "\"$titel\" von \"$interpret\" ist bereits vorhanden – übersprungen.";

    $musikAutorId = $musikAutor ? getOrCreateBand($conn, $musikAutor) : null;
    $textAutorId  = $textAutor  ? getOrCreateBand($conn, $textAutor)  : null;

    $stmt = $conn->prepare("INSERT INTO singopkoelsch_lyrics (title, band_id, lyrics, spotify_link, video_link, release_year, album, text_autor_id, musik_autor_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sissssiii", $titel, $interpretId, $lyrics, $spotify, $video, $year, $album, $textAutorId, $musikAutorId);

    if ($stmt->execute()) { $stmt->close(); return "\"$titel\" von \"$interpret\" hinzugefügt."; }
    $err = $stmt->error; $stmt->close();
    return "Fehler beim Einfügen von \"$titel\": $err";
}

$messages = [];

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['songs'])) {
    $songs = json_decode($_POST['songs'], true);
    if (!$songs) {
        $messages[] = "Fehler: Keine gültigen Song-Daten erhalten.";
    } else {
        foreach ($songs as $song) {
            $messages[] = bulkInsertSong($conn, $song);
        }
    }
}

$pageTitle = 'Bulk-Import – Sing op Kölsch';
Database::ensurePreferencesTable();
require_once "partials/head.php";
require_once "partials/nav.php";
?>

<main class="content">
  <div style="margin-bottom:1.5rem;">
    <a href="/add.php" class="btn btn-ghost btn-sm" style="margin-bottom:0.6rem;">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
      Zurück
    </a>
    <h1>Bulk-Import</h1>
    <p style="color:var(--text-2);margin:0;font-size:0.92rem;">Richtext aus Word oder Google Docs einfügen – Songs werden automatisch erkannt.</p>
  </div>

  <?php if ($messages): ?>
    <div class="card mb-3">
      <div class="card-header">Importergebnis</div>
      <div class="card-body">
        <ul style="margin:0;padding-left:1.2em;">
          <?php foreach ($messages as $m): ?>
            <li style="margin-bottom:0.4em;font-size:0.9rem;"><?= htmlspecialchars($m) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  <?php endif; ?>

  <div class="card">
    <div class="card-header">Text einfügen</div>
    <form method="post" id="form">
      <div class="form-section">
        <div class="form-group">
          <label>Inhalt (Word / Google Docs einfügen)</label>
          <div id="editor" contenteditable="true" style="min-height:200px;background:var(--bg-alt);border:1.5px solid var(--border);border-radius:var(--radius-sm);padding:0.85em 1em;font-size:0.95em;color:var(--text);outline:none;transition:border-color var(--transition);"></div>
        </div>
        <input type="hidden" name="songs" id="songs">
      </div>
      <div class="form-section">
        <div class="btn-row">
          <button type="submit" onclick="return analyze()" class="btn-secondary">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            Analysieren &amp; Vorschau
          </button>
          <button type="submit" id="import-btn" name="import" disabled class="btn-primary">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/></svg>
            Importieren
          </button>
        </div>
      </div>
    </form>
  </div>

  <div class="card mt-3 preview" style="display:none;">
    <div class="card-header">Erkannte Songs</div>
    <div class="card-body preview-content"></div>
  </div>
</main>

<script>
function cleanWordHtml(html) {
    if (!html) return '';
    return html
        .replace(/<!--[\s\S]*?-->/g, '')
        .replace(/<style[^>]*>[\s\S]*?<\/style>/gi, '')
        .replace(/&nbsp;/gi, ' ')
        .trim();
}

function cleanWordHtml2(html) {
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
    const c = document.createElement('div');
    c.innerHTML = html;
    function traverse(node) {
        let md = '';
        node.childNodes.forEach(child => {
            if (child.nodeType === Node.TEXT_NODE) md += child.textContent.replace(/\s+/g, ' ');
            else if (child.nodeType === Node.ELEMENT_NODE) {
                const tag = child.tagName.toLowerCase(), fw = child.style?.fontWeight;
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
    return traverse(c).trim().replace(/\n{3,}/g, '\n\n').replace(/\*\*\*\*/g, '**');
}

function getEffectiveStyle(el) {
    let fontSize = 0, fontWeight = 0;
    const s = el.getAttribute('style') || '';
    const fs = s.match(/font-size:\s*([\d.]+)px/i);
    if (fs) fontSize = parseFloat(fs[1]);
    const fw = s.match(/font-weight:\s*(\d+|bold)/i);
    if (fw) fontWeight = fw[1] === 'bold' ? 700 : parseInt(fw[1]);
    el.querySelectorAll('span').forEach(sp => {
        const c = getEffectiveStyle(sp);
        if (c.fontSize > fontSize)   fontSize   = c.fontSize;
        if (c.fontWeight > fontWeight) fontWeight = c.fontWeight;
    });
    return { fontSize, fontWeight };
}

function parseSongs(html) {
    const c = document.createElement('div');
    c.innerHTML = html;
    const songs = [];
    let cur = null;
    c.querySelectorAll('p, div').forEach(el => {
        const text = el.innerText.trim();
        if (!text) return;
        const { fontSize, fontWeight } = getEffectiveStyle(el);
        if (fontWeight >= 700 && fontSize > 25) {
            if (cur) songs.push(cur);
            cur = { title: cleanWordHtml(text), interpret: '', lyrics: '' };
        } else if (cur && !cur.interpret) {
            if ((text.startsWith('(') && text.endsWith(')')) || (fontWeight >= 700 && fontSize <= 25)) {
                cur.interpret = cleanWordHtml(text.replace(/[()]/g, ''));
            }
        } else if (cur && cur.interpret) {
            if (text !== cur.interpret) {
                cur.lyrics += htmlToMarkdown(cleanWordHtml2(el.outerHTML)) + '\n';
            }
        }
    });
    if (cur) songs.push(cur);
    return songs;
}

function runFullAnalyze(showPopup = false) {
    let songs = parseSongs(cleanWordHtml(document.getElementById('editor').innerHTML));
    songs = songs.map(song => {
        const raw = song.interpret;
        song.music_author = '';
        song.text_author  = '';
        let finalInterpret = '';
        const rx = /([MTI](?:\+[MTI])*)\s*:\s*([^,]+)/g;
        let m;
        while ((m = rx.exec(raw)) !== null) {
            const fields = m[1].split('+'), author = m[2].trim();
            if (fields.includes('M')) song.music_author = author;
            if (fields.includes('T')) song.text_author  = author;
            if (fields.includes('I')) finalInterpret    = author;
        }
        song.interpret = finalInterpret || raw;
        return song;
    });

    if (songs.length === 0) {
        if (showPopup) alert('Keine Songs erkannt!');
        document.querySelector('.preview').style.display = 'none';
        return false;
    }

    document.getElementById('songs').value = JSON.stringify(songs);
    document.getElementById('import-btn').disabled = false;

    const preview = document.querySelector('.preview');
    const previewCard = document.querySelector('.preview');
    previewCard.querySelector('.preview-content').innerHTML =
        '<ul style="margin:0;padding-left:1.2em;">' +
        songs.map((s, i) => `<li style="margin-bottom:0.5em;font-size:0.9rem;"><strong>${i + 1}. ${s.title}</strong> – ${s.interpret}<br><small style="color:var(--text-3)">Text: ${s.text_author || '–'} | Musik: ${s.music_author || '–'}</small></li>`).join('') +
        '</ul>';
    previewCard.style.display = 'block';
    return false;
}

document.getElementById('editor').addEventListener('input', () => runFullAnalyze(false));
function analyze() { return runFullAnalyze(true); }
</script>

<?php require_once "partials/footer.php"; ?>
