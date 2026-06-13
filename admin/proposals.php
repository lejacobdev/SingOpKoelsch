<?php
require_once "../protect.php";
require_once "../functions.php";

requireAdmin();
$conn = Database::getConnection();

// ── Approve / reject actions ────────────────────────────────────────
// Cover proposals: ?approve_cover=<id> or ?reject_cover=<id>
// Change proposals: ?approve_change=<id>&lyric_id=<n> / ?reject_change=<id>&lyric_id=<n>
$msg = '';

if (isset($_GET['approve_cover'])) {
    $cid = (int)$_GET['approve_cover'];
    $stmt = $conn->prepare("SELECT lyrics_id, spotify_url FROM singopkoelsch_cover_proposals WHERE id = ? AND status = 'pending'");
    $stmt->bind_param("i", $cid); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if ($row) {
        $data = spotify_lookup($row['spotify_url']);
        if ($data) {
            $upd = $conn->prepare(
                "UPDATE singopkoelsch_lyrics
                    SET cover_url = ?, album = COALESCE(NULLIF(album, ''), ?), spotify_link = COALESCE(NULLIF(spotify_link, ''), ?), release_year = COALESCE(release_year, ?)
                  WHERE id = ?"
            );
            $album = $data['album'] ?? null;
            $cover = $data['cover_url'] ?? null;
            $track = $data['spotify_link'] ?? null;
            $year  = !empty($data['release_year']) ? (int)$data['release_year'] : null;
            $lid   = (int)$row['lyrics_id'];
            $upd->bind_param("ssssi", $cover, $album, $track, $year, $lid);
            $upd->execute(); $upd->close();
        }
        $admin = (int)$_SESSION['user_id'];
        $upd = $conn->prepare("UPDATE singopkoelsch_cover_proposals SET status='approved', reviewed_at=NOW(), reviewed_by=? WHERE id=?");
        $upd->bind_param("ii", $admin, $cid); $upd->execute(); $upd->close();
        $msg = 'cover_approved';
    }
    header("Location: proposals.php?msg=$msg" . (isset($_GET['filter_status']) ? '&filter_status=' . urlencode($_GET['filter_status']) : '') . (isset($_GET['filter_type']) ? '&filter_type=' . urlencode($_GET['filter_type']) : ''));
    exit;
}
if (isset($_GET['reject_cover'])) {
    $cid = (int)$_GET['reject_cover'];
    $admin = (int)$_SESSION['user_id'];
    $upd = $conn->prepare("UPDATE singopkoelsch_cover_proposals SET status='rejected', reviewed_at=NOW(), reviewed_by=? WHERE id=? AND status='pending'");
    $upd->bind_param("ii", $admin, $cid); $upd->execute(); $upd->close();
    header("Location: proposals.php?msg=cover_rejected");
    exit;
}

// ── Filters ──────────────────────────────────────────────────────────
$filterStatus = $_GET['filter_status'] ?? 'pending';
$filterType   = $_GET['filter_type']   ?? 'all';
$validStatus  = ['all','pending','approved','rejected'];
$validType    = ['all','cover','lyrics','fields'];
if (!in_array($filterStatus, $validStatus, true)) $filterStatus = 'pending';
if (!in_array($filterType, $validType,   true)) $filterType   = 'all';

// ── Fetch change requests ────────────────────────────────────────────
$changes = [];
if ($filterType === 'all' || $filterType === 'lyrics' || $filterType === 'fields') {
    $where = '';
    if ($filterStatus !== 'all') {
        $where = "WHERE cr.status = '" . $conn->real_escape_string($filterStatus) . "'";
    }
    $rows = $conn->query(
        "SELECT cr.id, cr.lyrics_id, cr.proposed_lyrics, cr.proposed_changes,
                cr.status, cr.created_at,
                u.name AS user_name,
                l.title, l.lyrics AS current_lyrics,
                l.band_id, l.text_autor_id, l.musik_autor_id,
                l.album, l.release_year, l.spotify_link, l.video_link,
                b.band_name
           FROM singopkoelsch_change_requests cr
      LEFT JOIN singopkoelsch_users  u ON u.user_id = cr.user_id
      LEFT JOIN singopkoelsch_lyrics l ON l.id = cr.lyrics_id
      LEFT JOIN singopkoelsch_bands  b ON b.band_id = l.band_id
           $where
       ORDER BY (cr.status = 'pending') DESC, cr.created_at DESC
          LIMIT 200"
    );
    if ($rows) {
        while ($r = $rows->fetch_assoc()) {
            $payload = !empty($r['proposed_changes']) ? json_decode($r['proposed_changes'], true) : null;
            $type    = (is_array($payload) && count($payload) > 0)
                       ? (isset($payload['lyrics']) && count($payload) === 1 ? 'lyrics' : 'fields')
                       : 'lyrics';
            if ($filterType !== 'all' && $filterType !== $type) continue;
            $changes[] = [
                'kind'     => 'change',
                'type'     => $type,
                'id'       => (int)$r['id'],
                'lyrics_id'=> (int)$r['lyrics_id'],
                'status'   => $r['status'],
                'created'  => $r['created_at'],
                'user'     => $r['user_name'] ?? '–',
                'title'    => $r['title'] ?? '–',
                'band'     => $r['band_name'] ?? '–',
                'payload'  => $payload,
                'proposed_lyrics' => $r['proposed_lyrics'],
                'current'  => $r,
            ];
        }
    }
}

// ── Fetch cover proposals ────────────────────────────────────────────
$covers = [];
if ($filterType === 'all' || $filterType === 'cover') {
    $where = '';
    if ($filterStatus !== 'all') {
        $where = "WHERE p.status = '" . $conn->real_escape_string($filterStatus) . "'";
    }
    $rows = $conn->query(
        "SELECT p.id, p.lyrics_id, p.spotify_url, p.note, p.status, p.created_at,
                u.name AS user_name,
                l.title, l.cover_url AS current_cover, l.album AS current_album,
                b.band_name
           FROM singopkoelsch_cover_proposals p
      LEFT JOIN singopkoelsch_users  u ON u.user_id = p.user_id
      LEFT JOIN singopkoelsch_lyrics l ON l.id = p.lyrics_id
      LEFT JOIN singopkoelsch_bands  b ON b.band_id = l.band_id
           $where
       ORDER BY (p.status = 'pending') DESC, p.created_at DESC
          LIMIT 200"
    );
    if ($rows) {
        while ($r = $rows->fetch_assoc()) {
            $covers[] = [
                'kind'     => 'cover',
                'type'     => 'cover',
                'id'       => (int)$r['id'],
                'lyrics_id'=> (int)$r['lyrics_id'],
                'status'   => $r['status'],
                'created'  => $r['created_at'],
                'user'     => $r['user_name'] ?? '–',
                'title'    => $r['title'] ?? '–',
                'band'     => $r['band_name'] ?? '–',
                'spotify_url' => $r['spotify_url'],
                'note'        => $r['note'],
                'current_cover' => $r['current_cover'],
                'current_album' => $r['current_album'],
            ];
        }
    }
}

// Merge + sort by created desc, with pending first
$all = array_merge($changes, $covers);
usort($all, function($a, $b) {
    $ap = $a['status'] === 'pending' ? 0 : 1;
    $bp = $b['status'] === 'pending' ? 0 : 1;
    if ($ap !== $bp) return $ap - $bp;
    return strcmp($b['created'], $a['created']);
});

$bandMap = Database::getBandMap();

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

$pageTitle = t('prop.title') . ' – Sing op Kölsch';
require_once "../partials/head.php";
require_once "../partials/nav.php";

$qsBase = http_build_query(['filter_status' => $filterStatus, 'filter_type' => $filterType]);
?>

<style>
/* Filter chips */
.filter-row { display:flex; flex-wrap:wrap; gap:0.4rem; margin-bottom:1rem; }
.filter-chip {
  display:inline-flex; align-items:center; padding:0.32rem 0.75rem; border-radius:999px;
  border:1px solid var(--border); background:var(--bg-alt); color:var(--text-2);
  text-decoration:none; font-size:0.85rem; font-weight:500;
  transition:background 0.12s, color 0.12s, border-color 0.12s;
}
.filter-chip:hover { background:var(--card); color:var(--text); }
.filter-chip.is-active { background:#E30613; border-color:#E30613; color:#fff !important; }
.filter-divider { width:1px; background:var(--border); margin:0 0.3rem; align-self:stretch; }

/* Proposal card */
.proposal-card {
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  margin-bottom: 1rem;
  overflow: hidden;
}
.proposal-head {
  display: flex; flex-wrap: wrap; gap: 0.6rem 1rem;
  align-items: center; padding: 0.8rem 1rem;
  border-bottom: 1px solid var(--border); background: var(--bg-alt);
}
.proposal-head .song { flex: 1 1 auto; min-width: 0; }
.proposal-head .song-title { font-weight: 600; font-size: 1.02rem; }
.proposal-head .song-band  { color: var(--text-3); font-size: 0.85rem; }
.proposal-head .meta { color: var(--text-3); font-size: 0.83rem; white-space: nowrap; }
.proposal-body { padding: 0.9rem 1rem; }
.proposal-actions {
  display: flex; flex-wrap: wrap; gap: 0.5rem;
  padding: 0.7rem 1rem; border-top: 1px solid var(--border); background: var(--bg);
}

/* Type pill */
.type-pill {
  display:inline-flex; align-items:center; gap:0.3em;
  padding:0.18rem 0.55rem; border-radius:6px;
  background: var(--bg); border:1px solid var(--border);
  font-size:0.78rem; font-weight:600; text-transform:uppercase; letter-spacing:0.04em;
  color: var(--text-2);
}
.type-pill.t-cover  { color: #d97706; border-color: rgba(217,119,6,0.3); background: rgba(217,119,6,0.08); }
.type-pill.t-lyrics { color: #2563eb; border-color: rgba(37,99,235,0.3); background: rgba(37,99,235,0.08); }
.type-pill.t-fields { color: #7c3aed; border-color: rgba(124,58,237,0.3); background: rgba(124,58,237,0.08); }

/* Cover side-by-side */
.cover-compare { display:grid; grid-template-columns:1fr 1fr; gap: 0.75rem; margin: 0 0 0.6rem; }
.cover-compare .col { background:var(--bg-alt); border:1px solid var(--border); border-radius:var(--radius-sm); padding:0.6rem; text-align:center; }
.cover-compare .col-title { font-size: 0.78rem; color: var(--text-3); font-weight:600; margin-bottom: 0.3rem; }
.cover-compare img { width: 100%; max-width: 160px; aspect-ratio: 1/1; object-fit: cover; border-radius: var(--radius-sm); }
.cover-compare .col-meta { margin-top: 0.4rem; font-size: 0.78rem; color: var(--text-2); line-height: 1.3; }
.cover-compare .col-meta-sub { color: var(--text-3); }
.cover-compare .empty {
  display: flex; align-items: center; justify-content: center;
  height: 130px; color: var(--text-3); font-size: 0.85rem; font-style: italic;
  background: var(--card); border-radius: var(--radius-sm);
}
@media (max-width: 540px) {
  .cover-compare img { max-width: 120px; }
}

/* Field grid */
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
@media (max-width: 720px) {
  .cr-grid { grid-template-columns: 1fr; gap: 0.35rem; }
  .cr-head { display:none; }
  .cr-row-label { margin: 0.55rem 0 0.1rem; padding: 0.1rem 0; border-top: 1px solid var(--border); }
  .cr-row-label:first-of-type { border-top: none; margin-top: 0; }
  .cr-row-old::before { content: "<?= htmlspecialchars(t('prop.before')) ?>: "; font-weight:600; color: var(--text-3); }
  .cr-row-new::before { content: "<?= htmlspecialchars(t('prop.after')) ?>: ";  font-weight:600; color: #16a34a; }
}

/* Diff */
.diff-block {
  font-family: ui-monospace, Menlo, Consolas, monospace;
  font-size: 0.82em; line-height: 1.55;
  white-space: pre-wrap;
  background: var(--bg); border: 1px solid var(--border);
  border-radius: var(--radius-sm); padding: 0.4rem;
  max-height: 360px; overflow: auto;
}
.diff-block > div { padding: 0 0.5rem; border-radius: 3px; }
.diff-eq  { color: var(--text-3); }
.diff-add { background: rgba(22,163,74,0.12);  color: #16a34a; }
.diff-del { background: rgba(239,68,68,0.12);  color: #ef4444; text-decoration: line-through; }
.diff-mark { display:inline-block; width:1.1em; opacity:0.6; }
</style>

<main class="content-wide">
  <div style="margin-bottom:1.4rem;">
    <a href="/admin/index.php" class="btn btn-ghost btn-sm" style="margin-bottom:0.6rem;">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
      <?= htmlspecialchars(t('admin.dashboard')) ?>
    </a>
    <h1><?= htmlspecialchars(t('prop.title')) ?><span class="accent"><?= htmlspecialchars(t('prop.title_accent')) ?></span></h1>
    <p style="color:var(--text-3);margin:0.2rem 0 0;"><?= htmlspecialchars(t('prop.subtitle')) ?></p>
  </div>

  <?php
    $flash = $_GET['msg'] ?? '';
    if ($flash) {
        $map = [
            'cover_approved'  => t('prop.approve') . ' ✓',
            'cover_rejected'  => t('prop.reject') . ' ✓',
        ];
        if (isset($map[$flash])): ?>
          <div class="alert alert-success" style="margin-bottom:1rem;"><?= htmlspecialchars($map[$flash]) ?></div>
    <?php endif;
    }
  ?>

  <!-- Filters -->
  <?php $mkChip = function(string $param, string $val, string $cur, string $label) use ($filterStatus, $filterType) {
        $next = ['filter_status' => $filterStatus, 'filter_type' => $filterType];
        $next[$param] = $val;
        $isActive = $cur === $val;
        return '<a class="filter-chip' . ($isActive ? ' is-active' : '') . '" href="?' . htmlspecialchars(http_build_query($next)) . '">'
             . htmlspecialchars($label) . '</a>';
  }; ?>
  <div class="filter-row">
    <?= $mkChip('filter_status', 'all',      $filterStatus, t('prop.filter_all')) ?>
    <?= $mkChip('filter_status', 'pending',  $filterStatus, t('prop.filter_pending')) ?>
    <?= $mkChip('filter_status', 'approved', $filterStatus, t('prop.filter_approved')) ?>
    <?= $mkChip('filter_status', 'rejected', $filterStatus, t('prop.filter_rejected')) ?>
    <span class="filter-divider"></span>
    <?= $mkChip('filter_type', 'all',    $filterType, t('prop.filter_type_all')) ?>
    <?= $mkChip('filter_type', 'cover',  $filterType, t('prop.filter_type_cover')) ?>
    <?= $mkChip('filter_type', 'lyrics', $filterType, t('prop.filter_type_lyrics')) ?>
    <?= $mkChip('filter_type', 'fields', $filterType, t('prop.filter_type_fields')) ?>
  </div>

  <?php if (empty($all)): ?>
    <div class="card"><div class="card-body"><p class="text-muted"><?= htmlspecialchars(t('prop.empty')) ?></p></div></div>
  <?php else: ?>

    <?php foreach ($all as $p):
        $statusKey = ['pending' => 'admin.status_pending', 'approved' => 'admin.status_approved', 'rejected' => 'admin.status_rejected'][$p['status']] ?? 'admin.status_pending';
        $badgeCls  = ['pending' => 'badge-yellow', 'approved' => 'badge-green', 'rejected' => 'badge-red'][$p['status']];
        $typeKey   = 'prop.type_' . $p['type'];
    ?>
      <div class="proposal-card">
        <div class="proposal-head">
          <div class="song">
            <div class="song-title">
              <a href="/detail.php?lyrics=<?= $p['lyrics_id'] ?>" style="color:var(--text);text-decoration:none;">
                <?= htmlspecialchars($p['title']) ?>
              </a>
            </div>
            <div class="song-band"><?= htmlspecialchars($p['band']) ?></div>
          </div>
          <span class="type-pill t-<?= $p['type'] ?>"><?= htmlspecialchars(t($typeKey)) ?></span>
          <span class="badge <?= $badgeCls ?>"><?= htmlspecialchars(t($statusKey)) ?></span>
          <div class="meta">
            <?= htmlspecialchars(t('prop.proposed_by')) ?>:
            <strong><?= htmlspecialchars($p['user']) ?></strong>
            · <?= htmlspecialchars(date('d.m.Y H:i', strtotime($p['created']))) ?>
          </div>
        </div>

        <div class="proposal-body">
          <?php if ($p['kind'] === 'cover'): ?>
            <?php if (!empty($p['note'])): ?>
              <p style="margin:0 0 0.7rem;"><strong><?= htmlspecialchars(t('prop.note')) ?>:</strong> <?= htmlspecialchars($p['note']) ?></p>
            <?php endif; ?>
            <p style="margin:0 0 0.55rem;font-weight:600;"><?= htmlspecialchars(t('prop.change_summary')) ?></p>
            <?php
              $proposedSpotify = spotify_lookup($p['spotify_url']);
              $proposedCover   = $proposedSpotify['cover_url'] ?? null;
              $proposedAlbum   = $proposedSpotify['album']     ?? null;
              $proposedArtist  = $proposedSpotify['artist']    ?? null;
              $proposedYear    = $proposedSpotify['release_year'] ?? null;
            ?>
            <div class="cover-compare">
              <div class="col">
                <div class="col-title"><?= htmlspecialchars(t('prop.cover_current')) ?></div>
                <?php if (!empty($p['current_cover'])): ?>
                  <img src="<?= htmlspecialchars($p['current_cover']) ?>" alt="">
                  <?php if (!empty($p['current_album'])): ?>
                    <div class="col-meta"><?= htmlspecialchars($p['current_album']) ?></div>
                  <?php endif; ?>
                <?php else: ?>
                  <div class="empty"><?= htmlspecialchars(t('prop.no_cover')) ?></div>
                <?php endif; ?>
              </div>
              <div class="col">
                <div class="col-title" style="color:#16a34a;"><?= htmlspecialchars(t('prop.cover_proposed')) ?></div>
                <?php if ($proposedCover): ?>
                  <img src="<?= htmlspecialchars($proposedCover) ?>" alt="" style="box-shadow:0 0 0 2px #16a34a;">
                  <?php if ($proposedAlbum): ?>
                    <div class="col-meta">
                      <?= htmlspecialchars($proposedAlbum) ?><?php if ($proposedYear): ?> · <?= (int)$proposedYear ?><?php endif; ?>
                      <?php if ($proposedArtist): ?><br><span class="col-meta-sub"><?= htmlspecialchars($proposedArtist) ?></span><?php endif; ?>
                    </div>
                  <?php endif; ?>
                <?php else: ?>
                  <div class="empty">
                    <a href="<?= htmlspecialchars($p['spotify_url']) ?>" target="_blank" rel="noopener" style="word-break:break-all;font-size:0.78rem;text-align:center;padding:0.4rem;">
                      <?= htmlspecialchars($p['spotify_url']) ?>
                    </a>
                  </div>
                <?php endif; ?>
                <div style="margin-top:0.35rem;">
                  <a href="<?= htmlspecialchars($p['spotify_url']) ?>" target="_blank" rel="noopener"
                     style="font-size:0.75rem;color:var(--text-3);text-decoration:underline;word-break:break-all;">
                    Spotify ↗
                  </a>
                </div>
              </div>
            </div>
          <?php else:
            $payload    = is_array($p['payload']) ? $p['payload'] : null;
            $shortKeys  = ['title','band_id','text_autor_id','musik_autor_id','album','release_year','spotify_link','video_link'];
            $shortDiffs = [];
            $lyricsDiff = null;
            if (is_array($payload)) {
                foreach ($payload as $k => $v) {
                    if ($k === 'lyrics' && (string)($p['current']['current_lyrics'] ?? '') !== (string)$v) {
                        $lyricsDiff = ['old' => (string)($p['current']['current_lyrics'] ?? ''), 'new' => (string)$v];
                    } elseif (in_array($k, $shortKeys, true) && (string)($p['current'][$k] ?? '') !== (string)$v) {
                        $shortDiffs[$k] = $v;
                    }
                }
            } else {
                // Legacy: nur proposed_lyrics
                $lyricsDiff = ['old' => (string)($p['current']['current_lyrics'] ?? ''), 'new' => (string)$p['proposed_lyrics']];
            }
          ?>
            <p style="margin:0 0 0.55rem;font-weight:600;"><?= htmlspecialchars(t('prop.change_summary')) ?></p>

            <?php if (!empty($shortDiffs)): ?>
              <div class="cr-grid">
                <div class="cr-head"><?= htmlspecialchars(t('prop.field')) ?></div>
                <div class="cr-head cr-old"><?= htmlspecialchars(t('prop.before')) ?></div>
                <div class="cr-head cr-new"><?= htmlspecialchars(t('prop.after')) ?></div>
                <?php foreach ($fieldLabels as $key => $label):
                  if (!array_key_exists($key, $shortDiffs)) continue;
                  $cur = $p['current'][$key] ?? null;
                  $new = $shortDiffs[$key];
                ?>
                  <div class="cr-row-label"><?= htmlspecialchars($label) ?></div>
                  <div class="cr-row-old"><?= $fmtVal($key, $cur) ?></div>
                  <div class="cr-row-new"><?= $fmtVal($key, $new) ?></div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <?php if ($lyricsDiff !== null): ?>
              <p style="font-weight:600;margin:0 0 0.4rem;font-size:0.95em;"><?= htmlspecialchars(t('prop.lyrics_diff')) ?></p>
              <?= renderLineDiff($lyricsDiff['old'], $lyricsDiff['new']) ?>
            <?php endif; ?>

            <?php if (empty($shortDiffs) && $lyricsDiff === null): ?>
              <p class="text-muted text-sm" style="margin:0;"><?= htmlspecialchars(t('prop.no_visible_diff')) ?></p>
            <?php endif; ?>
          <?php endif; ?>
        </div>

        <div class="proposal-actions">
          <a href="/detail.php?lyrics=<?= $p['lyrics_id'] ?>" class="btn btn-sm btn-ghost"><?= htmlspecialchars(t('prop.open_song')) ?></a>
          <?php if ($p['status'] === 'pending'): ?>
            <?php if ($p['kind'] === 'cover'): ?>
              <a href="?approve_cover=<?= $p['id'] ?>&<?= htmlspecialchars($qsBase) ?>" class="btn btn-sm btn-primary"><?= htmlspecialchars(t('prop.approve')) ?></a>
              <a href="?reject_cover=<?= $p['id'] ?>&<?= htmlspecialchars($qsBase) ?>" class="btn btn-sm btn-danger"><?= htmlspecialchars(t('prop.reject')) ?></a>
            <?php else: ?>
              <a href="/admin/approve_change.php?id=<?= $p['id'] ?>&lyric_id=<?= $p['lyrics_id'] ?>" class="btn btn-sm btn-primary"><?= htmlspecialchars(t('prop.approve')) ?></a>
              <a href="/admin/reject_change.php?id=<?= $p['id'] ?>&lyric_id=<?= $p['lyrics_id'] ?>" class="btn btn-sm btn-danger"><?= htmlspecialchars(t('prop.reject')) ?></a>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>

  <?php endif; ?>
</main>

<?php require_once "../partials/footer.php"; ?>
