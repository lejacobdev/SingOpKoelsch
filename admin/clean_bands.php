<?php
require_once "../protect.php";
require_once "../functions.php";

requireAdmin();

$conn = Database::getConnection();
$msg = '';

// Handle actions
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $bandId = (int)($_POST["band_id"] ?? 0);
    $action = $_POST["action"] ?? '';

    if ($bandId > 0) {
        if ($action === 'rename') {
            $newName = trim($_POST["new_name"] ?? '');
            if ($newName !== '') {
                $stmt = $conn->prepare("UPDATE singopkoelsch_bands SET band_name = ? WHERE band_id = ?");
                $stmt->bind_param("si", $newName, $bandId);
                $ok = $stmt->execute();
                $stmt->close();
                $msg = $ok
                    ? '<div class="alert alert-success">Künstlername aktualisiert.</div>'
                    : '<div class="alert alert-error">Fehler beim Umbenennen.</div>';
            }
        } elseif ($action === 'delete') {
            // Null out references then delete the band
            $conn->begin_transaction();
            try {
                $conn->query("UPDATE singopkoelsch_lyrics SET band_id = NULL WHERE band_id = $bandId");
                $conn->query("UPDATE singopkoelsch_lyrics SET text_autor_id = NULL WHERE text_autor_id = $bandId");
                $conn->query("UPDATE singopkoelsch_lyrics SET musik_autor_id = NULL WHERE musik_autor_id = $bandId");
                $conn->query("DELETE FROM singopkoelsch_bands WHERE band_id = $bandId");
                $conn->commit();
                $msg = '<div class="alert alert-success">Künstler gelöscht und Referenzen bereinigt.</div>';
            } catch (Exception $e) {
                $conn->rollback();
                $msg = '<div class="alert alert-error">Fehler beim Löschen.</div>';
            }
        } elseif ($action === 'split') {
            $names = array_values(array_filter(array_map('trim', preg_split('/\s*(?:,|;|\b und \b| & |\band\b| feat\.?\b| mit \b)\s*/iu', $_POST["new_name"] ?? ''))));
            if (count($names) < 2) {
                $msg = '<div class="alert alert-error">Zum Aufteilen bitte mindestens zwei Namen angeben (kommagetrennt).</div>';
            } else {
                $conn->begin_transaction();
                try {
                    $newIds = [];
                    foreach ($names as $n) {
                        $stmt = $conn->prepare("SELECT band_id FROM singopkoelsch_bands WHERE band_name = ? LIMIT 1");
                        $stmt->bind_param("s", $n);
                        $stmt->execute();
                        $r = $stmt->get_result()->fetch_assoc();
                        $stmt->close();
                        if ($r) {
                            $newIds[] = (int)$r['band_id'];
                        } else {
                            $stmt = $conn->prepare("INSERT INTO singopkoelsch_bands (band_name) VALUES (?)");
                            $stmt->bind_param("s", $n);
                            $stmt->execute();
                            $newIds[] = (int)$stmt->insert_id;
                            $stmt->close();
                        }
                    }
                    $primary = $newIds[0];
                    // Reassign all references that pointed to the malformed band to the primary
                    $conn->query("UPDATE singopkoelsch_lyrics SET band_id = $primary WHERE band_id = $bandId");
                    $conn->query("UPDATE singopkoelsch_lyrics SET text_autor_id = $primary WHERE text_autor_id = $bandId");
                    $conn->query("UPDATE singopkoelsch_lyrics SET musik_autor_id = $primary WHERE musik_autor_id = $bandId");
                    $conn->query("DELETE FROM singopkoelsch_bands WHERE band_id = $bandId");
                    $conn->commit();
                    $msg = '<div class="alert alert-success">Künstler aufgeteilt – Referenzen wurden auf "' . htmlspecialchars($names[0]) . '" umgehängt.</div>';
                } catch (Exception $e) {
                    $conn->rollback();
                    $msg = '<div class="alert alert-error">Fehler beim Aufteilen.</div>';
                }
            }
        }
    }
}

$malformed = Database::getMalformedBands();

// Look up usage counts for each malformed band
$usageMap = [];
foreach ($malformed as $b) {
    $bid = (int)$b['band_id'];
    $r = $conn->query("SELECT
        SUM(CASE WHEN band_id        = $bid THEN 1 ELSE 0 END) AS band_count,
        SUM(CASE WHEN text_autor_id  = $bid THEN 1 ELSE 0 END) AS text_count,
        SUM(CASE WHEN musik_autor_id = $bid THEN 1 ELSE 0 END) AS music_count
        FROM singopkoelsch_lyrics");
    $usageMap[$bid] = $r ? $r->fetch_assoc() : ['band_count'=>0,'text_count'=>0,'music_count'=>0];
}

$pageTitle = 'Künstler bereinigen – Sing op Kölsch';
require_once "../partials/head.php";
require_once "../partials/nav.php";
?>

<main class="content-wide">
  <div class="page-header" style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
    <div>
      <h1>Künstler <span class="accent">bereinigen</span></h1>
      <p>Künstler-Einträge, in denen mehrere Personen in einem Namen stehen (z. B. „X und Y").</p>
    </div>
    <a href="/admin/index.php" class="btn btn-ghost btn-sm">← Admin Dashboard</a>
  </div>

  <?= $msg ?>

  <?php if (empty($malformed)): ?>
    <div class="alert alert-success">
      Keine fehlerhaften Künstler-Einträge gefunden. 🎉
    </div>
  <?php else: ?>
    <div class="card">
      <div class="card-header">
        <?= count($malformed) ?> fehlerhafte Einträge
      </div>
      <div style="overflow-x:auto;">
        <table class="data-table">
          <thead>
            <tr>
              <th>Künstlername</th>
              <th>Verwendet als</th>
              <th>Aktion</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($malformed as $b): $bid = (int)$b['band_id']; $u = $usageMap[$bid]; ?>
              <tr>
                <td>
                  <strong><?= htmlspecialchars($b['band_name']) ?></strong>
                  <div class="text-muted text-sm">ID #<?= $bid ?></div>
                </td>
                <td class="text-sm">
                  <?php
                    $parts = [];
                    if ((int)$u['band_count']  > 0) $parts[] = $u['band_count']  . '× Interpret';
                    if ((int)$u['text_count']  > 0) $parts[] = $u['text_count']  . '× Text';
                    if ((int)$u['music_count'] > 0) $parts[] = $u['music_count'] . '× Musik';
                    echo $parts ? htmlspecialchars(implode(', ', $parts)) : '<span class="text-muted">unbenutzt</span>';
                  ?>
                </td>
                <td>
                  <form method="post" style="display:flex;flex-direction:column;gap:0.35rem;min-width:300px;">
                    <input type="hidden" name="band_id" value="<?= $bid ?>">
                    <input type="text" name="new_name"
                           placeholder="Korrekte/r Name(n), z. B. „Tommy Engel" oder „A, B, C"
                           style="padding:0.45rem 0.65rem !important;font-size:0.88rem !important;">
                    <div style="display:flex;gap:0.35rem;flex-wrap:wrap;">
                      <button type="submit" name="action" value="rename" class="btn btn-secondary btn-sm">Umbenennen</button>
                      <button type="submit" name="action" value="split"  class="btn btn-secondary btn-sm">Aufteilen (Primärname=erster)</button>
                      <button type="submit" name="action" value="delete" class="btn btn-danger btn-sm"
                              onclick="return confirm('Diesen Künstler wirklich löschen? Referenzen werden geleert.')">Löschen</button>
                    </div>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <p class="text-sm text-muted mt-2">
      <strong>Umbenennen</strong>: setzt diesen Eintrag auf einen einzelnen Namen.
      <strong>Aufteilen</strong>: legt für jeden kommagetrennten Namen einen eigenen Künstler an und hängt alle Referenzen auf den ersten Namen um.
      <strong>Löschen</strong>: entfernt den Eintrag und leert alle Verweise.
    </p>
  <?php endif; ?>
</main>

<?php require_once "../partials/footer.php"; ?>
