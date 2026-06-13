<?php
require_once "protect.php";
require_once "functions.php";

requireAdmin();

Database::getConnection();
Database::ensurePreferencesTable();

// Build archive from zip file
$archiveEvents = [];
$zipPath = __DIR__ . '/Kallendresser-20260605T223449Z-3-001.zip';
if (file_exists($zipPath)) {
    $zip = new ZipArchive();
    if ($zip->open($zipPath) === true) {
        $allowedExt = ['docx', 'pdf', 'pptx'];
        $skipKeywords = ['~WRL', 'Rednerkarten', 'Titelseite', 'Liedervorschläge', 'Feedback', 'Shortlist', 'Longlist', 'Adressen', 'Bild', 'bild', 'Liste', 'Lyrics mögl'];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name  = $zip->getNameIndex($i);
            $parts = explode('/', $name);
            if (count($parts) < 3) continue;
            $folder  = $parts[1];
            $file    = $parts[count($parts) - 1];
            $ext     = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExt)) continue;
            if (!preg_match('/^20\d\d/', $folder)) continue;
            $skip = false;
            foreach ($skipKeywords as $kw) { if (strpos($file, $kw) !== false) { $skip = true; break; } }
            if ($skip) continue;
            $stat = $zip->statIndex($i);
            $year = (int)substr($folder, 0, 4);
            if (!isset($archiveEvents[$folder])) {
                // Parse event name — handles "2026 11. Mal - Ostermann" and "2024 9. Mal - 10 Jahre Best Off"
                $rest = preg_replace('/^\d{4}\s+/', '', $folder);
                $eventLabel = preg_replace('/^\d+\.\s+Mal\s*-\s*(?:\d+\s+Jahr[e]?\s*-\s*)?/u', '', $rest);
                $eventLabel = trim($eventLabel, ' -');
                if ($eventLabel === '') $eventLabel = $folder;
                $archiveEvents[$folder] = ['year' => $year, 'label' => $eventLabel, 'files' => []];
            }
            $archiveEvents[$folder]['files'][] = ['name' => $file, 'path' => $name, 'size' => $stat['size'], 'ext' => $ext];
        }
        $zip->close();
        krsort($archiveEvents); // newest first
    }
}

$lyrics = Database::queryData();

if (isset($_GET["search"]) && $_GET["search"] !== "") {
    $lyrics = Database::queryDataBySearch($_GET["search"]);
}

$selectedOrder = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['selected_lyrics']) && is_array($_POST['selected_lyrics'])) {
    $selectedOrder = $_POST['selected_lyrics'];
} elseif (!empty($_GET['selected_lyrics']) && is_array($_GET['selected_lyrics'])) {
    $selectedOrder = $_GET['selected_lyrics'];
}

$bandMap = Database::getBandMap();

// AJAX live search response
if (isset($_GET["ajax"]) && $_GET["ajax"] === "1") {
    ob_start();
    ?>
    <div id="lyrics-list">
    <?php foreach ($lyrics as $lyric):
        $bandName = $bandMap[$lyric["band_id"]] ?? "Unbekannter Künstler";
        $checked = in_array($lyric['id'], $selectedOrder) ? "checked" : "";
    ?>
      <label class="lyrics-overview" data-id="<?= $lyric['id'] ?>">
        <input type="checkbox" name="selected_lyrics_checkbox[]" value="<?= $lyric['id'] ?>" <?= $checked ?> />
        <span class="lyrics-title"><?= htmlspecialchars($lyric["title"]) ?></span>
        <span class="lyrics-band">(<?= htmlspecialchars($bandName) ?>)</span>
      </label>
    <?php endforeach; ?>
    </div>
    <?php
    echo ob_get_clean();
    exit();
}

$lyrics_all_for_js = Database::queryData();
foreach ($lyrics_all_for_js as &$lyric) {
    $lyric['bandName'] = $bandMap[$lyric['band_id']] ?? "Unbekannter Künstler";
}
unset($lyric);

$pageTitle = 'Liederbuch Generator – Sing op Kölsch';
require_once "partials/head.php";
require_once "partials/nav.php";
?>

<main class="content">
  <div style="margin-bottom:1.5rem;">
    <h1>Liederbuch <span class="accent">Generator</span></h1>
    <p style="color:var(--text-2);margin:0;font-size:0.92rem;">Wähle Lieder aus und lade ein Liederbuch als Datei herunter.</p>
  </div>

  <!-- Archive section -->
  <?php if (!empty($archiveEvents)): ?>
  <div class="card mb-3">
    <div class="card-header">
      Vergangene Liederbücher
      <span class="badge badge-gray"><?= count($archiveEvents) ?> Veranstaltungen</span>
    </div>
    <div style="padding:0.25rem 0.5rem;">
      <?php foreach ($archiveEvents as $folder => $ev): ?>
        <div class="archive-event" id="ev-<?= md5($folder) ?>">
          <div class="archive-event-header" onclick="toggleArchive('<?= md5($folder) ?>')">
            <span class="archive-event-year"><?= $ev['year'] ?></span>
            <span class="archive-event-name"><?= htmlspecialchars($ev['label']) ?></span>
            <span class="archive-event-toggle">▾</span>
          </div>
          <div class="archive-event-files">
            <?php foreach ($ev['files'] as $f): ?>
              <?php
                $icon = $f['ext'] === 'pdf' ? '📄' : ($f['ext'] === 'docx' ? '📝' : '📊');
                $sizeMb = round($f['size'] / 1024 / 1024, 1);
              ?>
              <div class="archive-file">
                <span class="archive-file-icon"><?= $icon ?></span>
                <span class="archive-file-name"><?= htmlspecialchars($f['name']) ?></span>
                <span class="archive-file-size"><?= $sizeMb ?> MB</span>
                <a href="/download_liederbuch.php?path=<?= urlencode($f['path']) ?>"
                   class="btn btn-sm btn-ghost">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="8 17 12 21 16 17"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.88 18.09A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.25"/></svg>
                  Download
                </a>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <h2 style="margin-bottom:1rem;margin-top:0.5rem;">Neues Liederbuch erstellen</h2>
  <div class="liederbuch-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;">

    <!-- Left: song selection -->
    <div class="card">
      <div class="card-header" style="gap:0.75rem;align-items:center;">
        <span>Liedauswahl</span>
        <span class="search-pill search-pill-sm" style="max-width:220px;margin-left:auto;">
          <svg class="search-pill-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><line x1="20" y1="20" x2="16.65" y2="16.65"/></svg>
          <input type="search" id="search-input" name="search"
                 placeholder="Suchen…"
                 value="<?= htmlspecialchars($_GET["search"] ?? "") ?>"
                 autocomplete="off" />
        </span>
      </div>
      <div class="card-body" style="padding:0.75rem;max-height:520px;overflow-y:auto;">
        <form method="post" action="export_liederbuch.php" id="selection-form">
          <div id="lyrics-list">
            <?php foreach ($lyrics as $lyric):
              $bandName = $bandMap[$lyric["band_id"]] ?? "Unbekannter Künstler";
              $checked = in_array($lyric['id'], $selectedOrder) ? "checked" : "";
            ?>
              <label class="lyrics-overview" data-id="<?= $lyric['id'] ?>" style="margin-bottom:0.3rem;padding:0.6rem 0.9rem;">
                <input type="checkbox" name="selected_lyrics_checkbox[]" value="<?= $lyric['id'] ?>" <?= $checked ?> />
                <span class="lyrics-title" style="font-size:0.9rem;"><?= htmlspecialchars($lyric["title"]) ?></span>
                <span class="lyrics-band">(<?= htmlspecialchars($bandName) ?>)</span>
              </label>
            <?php endforeach; ?>
          </div>
        </form>
      </div>
    </div>

    <!-- Right: selected + download -->
    <div>
      <div class="card" style="margin-bottom:1rem;">
        <div class="card-header">Ausgewählte Lieder <span id="selected-count" class="badge badge-gray">0</span></div>
        <div class="card-body" style="padding:0.75rem;min-height:200px;max-height:400px;overflow-y:auto;">
          <div id="selected-list"></div>
        </div>
      </div>

      <div class="card">
        <div class="card-body">
          <p class="text-sm text-muted mb-2">Alle Lieder in der gewählten Reihenfolge werden als Liederbuch exportiert. Die Reihenfolge kann per Drag & Drop verändert werden.</p>
          <button type="submit" form="selection-form" class="btn btn-primary btn-full">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Liederbuch herunterladen
          </button>
        </div>
      </div>
    </div>

  </div>
</main>

<script>
const allLyricsData = <?= json_encode($lyrics_all_for_js, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>

<script>
const searchInput  = document.getElementById("search-input");
const lyricsList   = document.getElementById("lyrics-list");
const selectedList = document.getElementById("selected-list");
const selCount     = document.getElementById("selected-count");
const form         = document.getElementById("selection-form");

let searchTimeout = null;

searchInput.addEventListener("input", function() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        fetch(`liederbuch.php?search=${encodeURIComponent(this.value)}&ajax=1`)
            .then(res => res.text())
            .then(html => {
                const doc = new DOMParser().parseFromString(html, "text/html");
                const newList = doc.getElementById("lyrics-list");
                if (newList) {
                    lyricsList.innerHTML = newList.innerHTML;
                    bindLyricsCheckboxes();
                    const selectedIds = Array.from(selectedList.children).map(d => d.getAttribute("data-id"));
                    selectedIds.forEach(id => {
                        const cb = lyricsList.querySelector(`input[value='${id}']`);
                        if (cb) cb.checked = true;
                    });
                    form.querySelectorAll('input.hidden-checkbox').forEach(el => el.remove());
                    selectedIds.forEach(id => {
                        if (!lyricsList.querySelector(`input[value='${id}']`)) {
                            const h = document.createElement("input");
                            h.type = "checkbox"; h.name = "selected_lyrics_checkbox[]";
                            h.value = id; h.checked = true; h.style.display = "none";
                            h.classList.add("hidden-checkbox");
                            form.appendChild(h);
                        }
                    });
                    updateSelected();
                }
            });
    }, 300);
});

function bindLyricsCheckboxes() {
    lyricsList.querySelectorAll("input[type=checkbox][name='selected_lyrics_checkbox[]']").forEach(cb => {
        cb.addEventListener("change", updateSelected);
    });
}

function createSelectedItem(id, title, band) {
    const div = document.createElement("div");
    div.classList.add("selected-item");
    div.setAttribute("data-id", id);
    div.tabIndex = 0;
    const textSpan = document.createElement("span");
    textSpan.textContent = title + " " + band;
    div.appendChild(textSpan);
    const removeBtn = document.createElement("button");
    removeBtn.type = "button"; removeBtn.textContent = "×"; removeBtn.classList.add("remove-btn");
    removeBtn.addEventListener("click", () => {
        const cb = lyricsList.querySelector(`input[value='${id}']`);
        if (cb) { cb.checked = false; updateSelected(); }
    });
    div.appendChild(removeBtn);
    div.draggable = true;
    div.addEventListener("dragstart", e => { div.classList.add("dragging"); e.dataTransfer.setData("text/plain", id); });
    div.addEventListener("dragend", () => div.classList.remove("dragging"));
    return div;
}

function updateSelected() {
    const currentIds   = Array.from(selectedList.children).map(el => el.getAttribute("data-id"));
    const checkedBoxes = Array.from(lyricsList.querySelectorAll("input[type=checkbox][name='selected_lyrics_checkbox[]']:checked"));
    const newOrder = [];
    currentIds.forEach(id => {
        const cb = lyricsList.querySelector(`input[type=checkbox][value='${id}']`);
        if (!cb || cb.checked) newOrder.push(id);
    });
    checkedBoxes.forEach(cb => { if (!newOrder.includes(cb.value)) newOrder.push(cb.value); });
    selectedList.innerHTML = "";
    newOrder.forEach(id => {
        const label = lyricsList.querySelector(`label[data-id='${id}']`);
        let title = "", band = "";
        if (label) {
            title = label.querySelector(".lyrics-title")?.textContent ?? "";
            band  = label.querySelector(".lyrics-band")?.textContent ?? "";
        } else {
            const ld = allLyricsData.find(l => l.id == id);
            if (ld) { title = ld.title; band = ld.bandName || ""; }
            else { title = "Lied " + id; }
        }
        selectedList.appendChild(createSelectedItem(id, title, band));
    });
    selCount.textContent = newOrder.length;
}

selectedList.addEventListener("dragover", e => {
    e.preventDefault();
    const dragging = selectedList.querySelector(".dragging");
    const after = getDragAfterElement(selectedList, e.clientY);
    if (!after) selectedList.appendChild(dragging);
    else selectedList.insertBefore(dragging, after);
});

function getDragAfterElement(container, y) {
    return [...container.querySelectorAll(".selected-item:not(.dragging)")].reduce((closest, child) => {
        const box = child.getBoundingClientRect();
        const offset = y - box.top - box.height / 2;
        return (offset < 0 && offset > closest.offset) ? { offset, element: child } : closest;
    }, { offset: Number.NEGATIVE_INFINITY }).element;
}

form.addEventListener("submit", e => {
    [...form.querySelectorAll("input.order-input")].forEach(el => el.remove());
    [...selectedList.children].forEach(div => {
        const h = document.createElement("input");
        h.type = "hidden"; h.name = "selected_lyrics[]";
        h.value = div.getAttribute("data-id");
        h.classList.add("order-input");
        form.appendChild(h);
    });
    if (selectedList.children.length === 0) { e.preventDefault(); alert("Bitte wähle mindestens ein Lied aus."); }
});

function toggleArchive(id) {
    const el = document.getElementById('ev-' + id);
    if (el) el.classList.toggle('open');
}

const selectedOrder = <?= json_encode($selectedOrder) ?>;

function sortLyricsListByOrder() {
    const labels = Array.from(lyricsList.querySelectorAll("label.lyrics-overview"));
    if (!selectedOrder.length) return;
    labels.sort((a, b) => {
        const ia = selectedOrder.indexOf(a.getAttribute("data-id"));
        const ib = selectedOrder.indexOf(b.getAttribute("data-id"));
        if (ia === -1 && ib === -1) return 0;
        if (ia === -1) return 1; if (ib === -1) return -1;
        return ia - ib;
    });
    labels.forEach(l => lyricsList.appendChild(l));
}

sortLyricsListByOrder();
updateSelected();
bindLyricsCheckboxes();
</script>

<?php require_once "partials/footer.php"; ?>
