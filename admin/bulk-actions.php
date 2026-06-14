<?php
// #28 Admin Massen-Aktionen
require_once "../protect.php";
require_once "../functions.php";

requireAdmin();
$conn = Database::getConnection();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = $_POST['action'] ?? '';
    $ids     = array_map('intval', array_filter(explode(',', $_POST['song_ids'] ?? '')));

    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types        = str_repeat('i', count($ids));

        if ($action === 'delete') {
            $stmt = $conn->prepare("DELETE FROM singopkoelsch_lyrics WHERE id IN ($placeholders)");
            $stmt->bind_param($types, ...$ids); $stmt->execute(); $stmt->close();
            $msg = count($ids) . ' Songs gelöscht.';
        } elseif ($action === 'set_band' && !empty($_POST['new_band_id'])) {
            $bid  = (int)$_POST['new_band_id'];
            $stmt = $conn->prepare("UPDATE singopkoelsch_lyrics SET band_id = ? WHERE id IN ($placeholders)");
            $stmt->bind_param('i' . $types, $bid, ...$ids); $stmt->execute(); $stmt->close();
            $msg = count($ids) . ' Songs der Band zugewiesen.';
        } elseif ($action === 'clear_cover') {
            $stmt = $conn->prepare("UPDATE singopkoelsch_lyrics SET cover_url = NULL WHERE id IN ($placeholders)");
            $stmt->bind_param($types, ...$ids); $stmt->execute(); $stmt->close();
            $msg = count($ids) . ' Cover gelöscht.';
        } elseif ($action === 'unflag') {
            $stmt = $conn->prepare("UPDATE singopkoelsch_lyrics SET flagged = 0, flag_reason = NULL WHERE id IN ($placeholders)");
            $stmt->bind_param($types, ...$ids); $stmt->execute(); $stmt->close();
            $msg = count($ids) . ' Songs markierung entfernt.';
        }
    }
}

$allBands = Database::getBandList();

// Songs without cover or year for quick bulk-fixing
$incomplete = $conn->query(
    "SELECT l.id, l.title, b.band_name, l.release_year, l.cover_url, l.flagged
     FROM singopkoelsch_lyrics l
     LEFT JOIN singopkoelsch_bands b ON b.band_id=l.band_id
     ORDER BY l.id DESC LIMIT 200"
)->fetch_all(MYSQLI_ASSOC);

$pageTitle = 'Bulk-Aktionen – Admin – Sing op Kölsch';
require_once "../partials/head.php";
require_once "../partials/nav.php";
?>
<main class="content-wide">
  <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:1.5rem;flex-wrap:wrap;">
    <a href="/admin/" class="btn btn-ghost btn-sm">← Dashboard</a>
    <h1 style="margin:0;">⚡ Bulk-Aktionen</h1>
  </div>
  <?php if ($msg): ?><div class="alert alert-success" style="margin-bottom:1rem;"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

  <form method="post" id="bulk-form">
    <input type="hidden" name="song_ids" id="selected-ids" value="">

    <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:1rem;flex-wrap:wrap;padding:0.75rem 1rem;background:var(--card);border:1px solid var(--border);border-radius:10px;">
      <span id="selected-count" style="font-size:0.88rem;font-weight:600;">0 ausgewählt</span>
      <select name="action" id="bulk-action" style="height:36px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);font-size:0.88rem;padding:0 0.6rem;">
        <option value="">Aktion wählen…</option>
        <option value="set_band">Band zuweisen</option>
        <option value="clear_cover">Cover löschen</option>
        <option value="unflag">Markierung entfernen</option>
        <option value="delete">Löschen ⚠️</option>
      </select>
      <div id="band-picker" style="display:none;">
        <select name="new_band_id" style="height:36px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);font-size:0.88rem;padding:0 0.6rem;">
          <option value="">– Band wählen –</option>
          <?php foreach ($allBands as $b): ?>
            <option value="<?= $b['band_id'] ?>"><?= htmlspecialchars($b['band_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn btn-primary btn-sm" onclick="return confirmBulk()">Ausführen</button>
      <button type="button" class="btn btn-ghost btn-sm" onclick="selectAll()">Alle</button>
      <button type="button" class="btn btn-ghost btn-sm" onclick="selectNone()">Keine</button>
    </div>

    <div style="overflow-x:auto;">
      <table class="data-table" style="font-size:0.85rem;">
        <thead>
          <tr>
            <th style="width:30px;"><input type="checkbox" id="select-all-cb" onchange="if(this.checked)selectAll();else selectNone()"></th>
            <th>ID</th><th>Titel</th><th>Band</th><th>Jahr</th><th>Cover</th><th>Flag</th>
          </tr>
        </thead>
        <tbody id="bulk-tbody">
          <?php foreach ($incomplete as $s): ?>
            <tr>
              <td><input type="checkbox" class="song-cb" value="<?= (int)$s['id'] ?>" onchange="updateSelected()"></td>
              <td style="color:var(--text-3);"><?= (int)$s['id'] ?></td>
              <td><a href="/detail.php?lyrics=<?= (int)$s['id'] ?>" style="font-weight:500;"><?= htmlspecialchars($s['title']) ?></a></td>
              <td style="color:var(--text-3);"><?= htmlspecialchars($s['band_name'] ?? '–') ?></td>
              <td style="color:var(--text-3);"><?= htmlspecialchars($s['release_year'] ?? '–') ?></td>
              <td><?= !empty($s['cover_url']) ? '<span style="color:#22c55e;">✓</span>' : '<span style="color:var(--text-3);">–</span>' ?></td>
              <td><?= $s['flagged'] ? '<span style="color:#f59e0b;">⚠</span>' : '' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </form>
</main>
<script>
function updateSelected() {
  var cbs = document.querySelectorAll('.song-cb:checked');
  document.getElementById('selected-ids').value = Array.from(cbs).map(c=>c.value).join(',');
  document.getElementById('selected-count').textContent = cbs.length + ' ausgewählt';
}
function selectAll() { document.querySelectorAll('.song-cb').forEach(c=>{c.checked=true;}); updateSelected(); }
function selectNone() { document.querySelectorAll('.song-cb').forEach(c=>{c.checked=false;}); updateSelected(); document.getElementById('select-all-cb').checked=false; }
function confirmBulk() {
  var ids = document.getElementById('selected-ids').value;
  if (!ids) { alert('Keine Songs ausgewählt.'); return false; }
  var action = document.getElementById('bulk-action').value;
  if (!action) { alert('Bitte Aktion wählen.'); return false; }
  if (action === 'delete') return confirm('Wirklich ' + ids.split(',').length + ' Songs löschen?');
  return true;
}
document.getElementById('bulk-action').addEventListener('change', function() {
  document.getElementById('band-picker').style.display = this.value === 'set_band' ? 'block' : 'none';
});
</script>
<?php require_once "../partials/footer.php"; ?>
