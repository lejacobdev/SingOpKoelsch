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
        $n = count($ids);

        if ($action === 'delete') {
            $stmt = $conn->prepare("DELETE FROM singopkoelsch_lyrics WHERE id IN ($placeholders)");
            $stmt->bind_param($types, ...$ids); $stmt->execute(); $stmt->close();
            $msg = t('admin.bulk.deleted_n', ['n' => $n]);
        } elseif ($action === 'set_band' && !empty($_POST['new_band_id'])) {
            $bid  = (int)$_POST['new_band_id'];
            $stmt = $conn->prepare("UPDATE singopkoelsch_lyrics SET band_id = ? WHERE id IN ($placeholders)");
            $stmt->bind_param('i' . $types, $bid, ...$ids); $stmt->execute(); $stmt->close();
            $msg = t('admin.bulk.assigned_n', ['n' => $n]);
        } elseif ($action === 'clear_cover') {
            $stmt = $conn->prepare("UPDATE singopkoelsch_lyrics SET cover_url = NULL WHERE id IN ($placeholders)");
            $stmt->bind_param($types, ...$ids); $stmt->execute(); $stmt->close();
            $msg = t('admin.bulk.cover_cleared_n', ['n' => $n]);
        } elseif ($action === 'unflag') {
            $stmt = $conn->prepare("UPDATE singopkoelsch_lyrics SET flagged = 0, flag_reason = NULL WHERE id IN ($placeholders)");
            $stmt->bind_param($types, ...$ids); $stmt->execute(); $stmt->close();
            $msg = t('admin.bulk.unflagged_n', ['n' => $n]);
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

$pageTitle = e('admin.bulk.title') . ' – Admin – Sing op Kölsch';
require_once "../partials/head.php";
require_once "../partials/nav.php";
?>
<main class="content-wide">
  <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:1.5rem;flex-wrap:wrap;">
    <a href="/admin/" class="btn btn-ghost btn-sm">← <?= e('admin.dashboard') ?></a>
    <h1 style="margin:0;"><?= e('admin.bulk.title') ?></h1>
  </div>
  <?php if ($msg): ?><div class="alert alert-success" style="margin-bottom:1rem;"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

  <form method="post" id="bulk-form">
    <input type="hidden" name="song_ids" id="selected-ids" value="">

    <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:1rem;flex-wrap:wrap;padding:0.75rem 1rem;background:var(--card);border:1px solid var(--border);border-radius:10px;">
      <span id="selected-count" style="font-size:0.88rem;font-weight:600;"><?= e('admin.bulk.selected', ['n' => 0]) ?></span>
      <select name="action" id="bulk-action" style="height:36px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);font-size:0.88rem;padding:0 0.6rem;">
        <option value=""><?= e('admin.bulk.choose_action') ?></option>
        <option value="set_band"><?= e('admin.bulk.set_band') ?></option>
        <option value="clear_cover"><?= e('admin.bulk.clear_cover') ?></option>
        <option value="unflag"><?= e('admin.bulk.unflag') ?></option>
        <option value="delete"><?= e('admin.bulk.delete') ?></option>
      </select>
      <div id="band-picker" style="display:none;">
        <select name="new_band_id" style="height:36px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);font-size:0.88rem;padding:0 0.6rem;">
          <option value=""><?= e('admin.bulk.choose_band') ?></option>
          <?php foreach ($allBands as $b): ?>
            <option value="<?= $b['band_id'] ?>"><?= htmlspecialchars($b['band_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn btn-primary btn-sm" onclick="return confirmBulk()"><?= e('admin.bulk.run') ?></button>
      <button type="button" class="btn btn-ghost btn-sm" onclick="selectAll()"><?= e('admin.bulk.all') ?></button>
      <button type="button" class="btn btn-ghost btn-sm" onclick="selectNone()"><?= e('admin.bulk.none') ?></button>
    </div>

    <div style="overflow-x:auto;">
      <table class="data-table" style="font-size:0.85rem;">
        <thead>
          <tr>
            <th style="width:30px;"><input type="checkbox" id="select-all-cb" onchange="if(this.checked)selectAll();else selectNone()"></th>
            <th><?= e('admin.bulk.col_id') ?></th><th><?= e('admin.bulk.col_title') ?></th><th><?= e('admin.bulk.col_band') ?></th><th><?= e('admin.bulk.col_year') ?></th><th><?= e('admin.bulk.col_cover') ?></th><th><?= e('admin.bulk.col_flag') ?></th>
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
var _i18nSelected = <?= json_encode(t('admin.bulk.selected', ['n' => '__N__'])) ?>;
var _i18nNoneSelected = <?= json_encode(t('admin.bulk.none_selected')) ?>;
var _i18nChooseAction = <?= json_encode(t('admin.bulk.choose_action_err')) ?>;
var _i18nConfirmDelete = <?= json_encode(t('admin.bulk.confirm_delete', ['n' => '__N__'])) ?>;

function updateSelected() {
  var cbs = document.querySelectorAll('.song-cb:checked');
  document.getElementById('selected-ids').value = Array.from(cbs).map(c=>c.value).join(',');
  document.getElementById('selected-count').textContent = _i18nSelected.replace('__N__', cbs.length);
}
function selectAll() { document.querySelectorAll('.song-cb').forEach(c=>{c.checked=true;}); updateSelected(); }
function selectNone() { document.querySelectorAll('.song-cb').forEach(c=>{c.checked=false;}); updateSelected(); document.getElementById('select-all-cb').checked=false; }
function confirmBulk() {
  var ids = document.getElementById('selected-ids').value;
  if (!ids) { alert(_i18nNoneSelected); return false; }
  var action = document.getElementById('bulk-action').value;
  if (!action) { alert(_i18nChooseAction); return false; }
  if (action === 'delete') return confirm(_i18nConfirmDelete.replace('__N__', ids.split(',').length));
  return true;
}
document.getElementById('bulk-action').addEventListener('change', function() {
  document.getElementById('band-picker').style.display = this.value === 'set_band' ? 'block' : 'none';
});
</script>
<?php require_once "../partials/footer.php"; ?>
