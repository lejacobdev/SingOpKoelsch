<?php
require_once "../protect.php";
require_once "../functions.php";
require_once "../invite_gate.php";
require_once "../push.php";
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);
    // Row actions arrive as a single button value "action:id"
    if (!empty($_POST['do']) && strpos((string)$_POST['do'], ':') !== false) {
        [$action, $idStr] = explode(':', (string)$_POST['do'], 2);
        $id = (int)$idStr;
    }
    $msg = '';
    if ($action === 'toggle') {
        invite_set_gate(($_POST['enabled'] ?? '') === '1');
        $msg = ($_POST['enabled'] ?? '') === '1' ? 'Gate aktiviert.' : 'Gate deaktiviert.';
    } elseif ($action === 'generate') {
        $n = max(1, min(100, (int)($_POST['count'] ?? 1)));
        $label = trim((string)($_POST['label'] ?? ''));
        for ($i = 0; $i < $n; $i++) invite_create_code($label);
        $msg = $n . ' Code(s) erstellt.';
    } elseif ($action === 'deactivate') {
        $reason = trim((string)($_POST['reason'] ?? ''));
        invite_set_active($id, false, $reason);
        $msg = 'Deaktiviert.';
    } elseif ($action === 'activate')     { invite_set_active($id, true);  $msg = 'Aktiviert.'; }
    elseif  ($action === 'unbind')        { invite_unbind($id);            $msg = 'Bindung gelöst.'; }
    elseif  ($action === 'delete')        { invite_delete_code($id);       $msg = 'Gelöscht.'; }
    elseif  ($action === 'beta_end')      { set_beta_ended(true); invite_set_gate(true); $msg = 'Beta beendet. Alle Nutzer (außer Admins) haben keinen Zugriff mehr.'; }
    elseif  ($action === 'beta_resume')   { set_beta_ended(false); $msg = 'Beta wieder gestartet.'; }
    elseif  ($action === 'push_broadcast') {
        $title = trim((string)($_POST['push_title'] ?? '')) ?: 'Sing op Kölsch';
        $body  = trim((string)($_POST['push_body']  ?? ''));
        $url   = trim((string)($_POST['push_url']   ?? '')) ?: '/';
        $sent  = $body !== '' ? push_send_to_all($title, $body, $url) : 0;
        $msg   = $body !== '' ? $sent . ' Gerät(e) benachrichtigt.' : 'Kein Text angegeben.';
    }
    header('Location: invites.php?msg=' . urlencode($msg));
    exit;
}

$enabled   = invite_gate_enabled();
$betaEnded = beta_ended();
$codes     = invite_list_codes();
$flash     = $_GET['msg'] ?? '';
$active    = count(array_filter($codes, fn($c) => $c['active']));
$used      = count(array_filter($codes, fn($c) => $c['user_id'] !== null || !empty($c['device_id'])));
$r = Database::getConnection()->query("SELECT COUNT(*) c FROM singopkoelsch_push_subs");
$pushCount = $r ? (int)$r->fetch_assoc()['c'] : 0;

$pageTitle = e('admin.inv.title') . ' – Sing op Kölsch';
require_once "../partials/head.php";
require_once "../partials/nav.php";
?>
<style>
  .inv-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
  .inv-table th, .inv-table td { padding: 0.55rem 0.6rem; border-bottom: 1px solid var(--border); text-align: left; vertical-align: middle; }
  .inv-table th { color: var(--text-3); font-weight: 600; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.04em; }
  .inv-code { font-family: ui-monospace, Menlo, monospace; font-weight: 700; letter-spacing: 0.06em; }
  .inv-actions { display: flex; gap: 0.35rem; flex-wrap: wrap; justify-content: flex-end; }
  .inv-actions button { font-size: 0.78rem; padding: 0.3rem 0.6rem; border-radius: 7px; border: 1px solid var(--border); background: var(--bg-alt); color: var(--text); cursor: pointer; }
  .inv-actions button.danger { color: #f87171; border-color: rgba(248,113,113,0.35); }
  .inv-actions button.primary { color: #fff; background: linear-gradient(135deg,#ef4444,#dc2626); border: none; }
  .inv-dim { opacity: 0.5; }
  .inv-filters { display:flex; gap:0.5rem; flex-wrap:wrap; align-items:center; margin-bottom:0.9rem; }
  .inv-chip { padding:0.32rem 0.8rem; border-radius:999px; border:1px solid var(--border); background:var(--bg-alt); color:var(--text-2); font-size:0.85rem; font-weight:600; cursor:pointer; }
  .inv-chip.is-active { background:#dc2626; border-color:#dc2626; color:#fff; }
  #inv-search { flex:1; min-width:140px; padding:0.45rem 0.7rem; border-radius:8px; border:1px solid var(--border); background:var(--bg-alt); color:var(--text); }
  #inv-count { color:var(--text-3); font-size:0.82rem; white-space:nowrap; }
  /* Modal */
  .inv-modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.65); z-index:9999; align-items:center; justify-content:center; }
  .inv-modal-box { background:var(--card,#1c2128); border:1px solid var(--border); border-radius:16px; padding:1.6rem; max-width:360px; width:calc(100% - 2rem); box-shadow:0 20px 60px rgba(0,0,0,.5); }
  .inv-modal-box p { margin:0 0 1rem; line-height:1.5; }
  .inv-modal-reason { display:none; margin-bottom:1rem; }
  .inv-modal-reason label { display:block; font-size:.8rem; color:var(--text-2); margin-bottom:.3rem; }
  .inv-modal-reason input { width:100%; padding:.5rem .7rem; border-radius:8px; border:1px solid var(--border); background:var(--bg-alt,#0d1117); color:var(--text); font-size:.9rem; }
  .inv-modal-btns { display:flex; gap:.6rem; justify-content:flex-end; }
  .inv-modal-btns button { padding:.5rem 1.1rem; border-radius:8px; cursor:pointer; font-size:.9rem; font-weight:600; border:none; }
  .inv-modal-btns .cancel { background:var(--bg-alt); color:var(--text); border:1px solid var(--border); }
  .inv-modal-btns .confirm { background:#dc2626; color:#fff; }
  @media (max-width: 640px){ .inv-hide-sm { display: none; } }
</style>

<main class="content">
  <div style="margin-bottom:1.2rem;">
    <a href="/admin/index.php" class="btn btn-ghost btn-sm" style="margin-bottom:0.6rem;">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
      <?= htmlspecialchars(t('admin.dashboard')) ?>
    </a>
    <h1><?= e('admin.inv.title') ?></h1>
    <p style="color:var(--text-3);margin:0.2rem 0 0;"><?= e('admin.inv.subtitle', ['active' => $active, 'used' => $used]) ?></p>
  </div>

  <?php if ($flash): ?><div class="alert alert-success" style="margin-bottom:1rem;"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
  <?php if ($betaEnded): ?><div class="alert alert-warn" style="margin-bottom:1rem;"><?= e('admin.inv.beta_ended_warn') ?></div><?php endif; ?>

  <!-- Gate & Beta -->
  <div class="card mb-3">
    <div class="card-header"><?= e('admin.inv.gate_card') ?></div>
    <div class="card-body" style="display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
      <div>
        <strong><?= e('admin.inv.gate_label') ?></strong>
        <span class="badge <?= $enabled ? 'badge-green' : 'badge-gray' ?>" style="margin-left:0.4em;"><?= $enabled ? e('admin.inv.gate_on') : e('admin.inv.gate_off') ?></span>
        <?php if ($betaEnded): ?><span class="badge badge-red" style="margin-left:0.4em;"><?= e('admin.inv.beta_ended_badge') ?></span><?php endif; ?>
        <p class="text-sm text-muted" style="margin:0.4rem 0 0;"><?= e('admin.inv.gate_hint') ?></p>
      </div>
      <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
        <form method="post">
          <input type="hidden" name="action" value="toggle">
          <input type="hidden" name="enabled" value="<?= $enabled ? '0' : '1' ?>">
          <button type="submit" class="<?= $enabled ? 'btn btn-danger' : 'btn btn-primary' ?>"><?= $enabled ? e('admin.inv.deactivate') : e('admin.inv.activate') ?></button>
        </form>
        <form method="post">
          <input type="hidden" name="action" value="<?= $betaEnded ? 'beta_resume' : 'beta_end' ?>">
          <button type="submit" class="<?= $betaEnded ? 'btn btn-primary' : 'btn btn-danger' ?>"><?= $betaEnded ? e('admin.inv.beta_resume') : e('admin.inv.beta_end') ?></button>
        </form>
      </div>
    </div>
  </div>

  <!-- Generate -->
  <div class="card mb-3">
    <div class="card-header"><?= e('admin.inv.generate_card') ?></div>
    <form method="post" class="card-body" style="display:flex;gap:0.6rem;align-items:flex-end;flex-wrap:wrap;">
      <input type="hidden" name="action" value="generate">
      <div>
        <label style="display:block;font-size:0.8rem;color:var(--text-2);margin-bottom:0.3rem;"><?= e('admin.inv.count_label') ?></label>
        <input type="number" name="count" value="1" min="1" max="100" style="width:90px;">
      </div>
      <div style="flex:1;min-width:160px;">
        <label style="display:block;font-size:0.8rem;color:var(--text-2);margin-bottom:0.3rem;"><?= e('admin.inv.note_label') ?></label>
        <input type="text" name="label" placeholder="z. B. Freunde, Beta-Tester" style="width:100%;">
      </div>
      <button type="submit" class="btn btn-primary"><?= e('admin.inv.generate_btn') ?></button>
    </form>
  </div>

  <!-- Push Broadcast -->
  <div class="card mb-3">
    <div class="card-header">
      <?= e('admin.inv.push_card') ?>
      <span class="badge badge-gray" style="margin-left:0.4em;"><?= e('admin.inv.subscribers', ['n' => $pushCount]) ?></span>
    </div>
    <form method="post" class="card-body" style="display:flex;gap:0.6rem;align-items:flex-end;flex-wrap:wrap;">
      <input type="hidden" name="action" value="push_broadcast">
      <div style="min-width:130px;">
        <label style="display:block;font-size:0.8rem;color:var(--text-2);margin-bottom:0.3rem;"><?= e('admin.inv.push_title_label') ?></label>
        <input type="text" name="push_title" placeholder="Sing op Kölsch" style="width:100%;">
      </div>
      <div style="flex:2;min-width:200px;">
        <label style="display:block;font-size:0.8rem;color:var(--text-2);margin-bottom:0.3rem;"><?= e('admin.inv.push_msg_label') ?></label>
        <input type="text" name="push_body" placeholder="Neue Lieder verfügbar…" style="width:100%;" required>
      </div>
      <div style="min-width:90px;">
        <label style="display:block;font-size:0.8rem;color:var(--text-2);margin-bottom:0.3rem;"><?= e('admin.inv.push_link_label') ?></label>
        <input type="text" name="push_url" placeholder="/" style="width:100%;">
      </div>
      <button type="submit" class="btn btn-primary"><?= e('admin.inv.send_btn') ?></button>
    </form>
  </div>

  <!-- Codes list -->
  <div class="card">
    <div class="card-header"><?= e('admin.inv.codes_card', ['n' => count($codes)]) ?></div>
    <div class="card-body" style="overflow-x:auto;">
      <?php if (empty($codes)): ?>
        <p class="text-muted" style="margin:0;"><?= e('admin.inv.no_codes') ?></p>
      <?php else: ?>
      <div class="inv-filters">
        <button type="button" class="inv-chip is-active" data-filter="all"><?= e('admin.inv.filter_all') ?></button>
        <button type="button" class="inv-chip" data-filter="free"><?= e('admin.inv.filter_free') ?></button>
        <button type="button" class="inv-chip" data-filter="redeemed"><?= e('admin.inv.filter_redeemed') ?></button>
        <button type="button" class="inv-chip" data-filter="inactive"><?= e('admin.inv.filter_inactive') ?></button>
        <input type="search" id="inv-search" placeholder="<?= htmlspecialchars(t('admin.inv.search_ph')) ?>">
        <span id="inv-count"></span>
      </div>
      <form method="post" id="inv-form">
      <table class="inv-table">
        <thead><tr><th><?= e('admin.inv.col_code') ?></th><th><?= e('admin.col_status') ?></th><th><?= e('admin.inv.col_bound') ?></th><th class="inv-hide-sm"><?= e('admin.inv.col_note') ?></th><th style="text-align:right;"><?= e('admin.inv.col_actions') ?></th></tr></thead>
        <tbody>
        <?php
          $statusInactive = htmlspecialchars(t('admin.inv.status_inactive'));
          $statusRedeemed = htmlspecialchars(t('admin.inv.status_redeemed'));
          $statusActive   = htmlspecialchars(t('admin.inv.status_active'));
          $boundDevice    = t('admin.inv.bound_device');
          $boundFree      = t('admin.inv.bound_free');
          $btnDeactivate  = htmlspecialchars(t('admin.inv.btn_deactivate'));
          $btnActivate    = htmlspecialchars(t('admin.inv.btn_activate'));
          $btnUnbind      = htmlspecialchars(t('admin.inv.btn_unbind'));
          $btnDelete      = htmlspecialchars(t('admin.inv.btn_delete'));
          foreach ($codes as $c):
            $redeemed  = $c['user_id'] !== null || !empty($c['device_id']);
            $st        = !$c['active'] ? 'inactive' : ($redeemed ? 'redeemed' : 'free');
            $searchStr = strtolower(invite_format($c['code'])) . ' ' . strtolower($c['user_name'] ?? '');
        ?>
          <tr class="inv-row <?= $c['active'] ? '' : 'inv-dim' ?>" data-status="<?= $st ?>" data-search="<?= htmlspecialchars($searchStr) ?>">
            <td class="inv-code"><?= htmlspecialchars(invite_format($c['code'])) ?></td>
            <td><?php
              if ($st === 'inactive')      echo "<span class='badge badge-gray'>$statusInactive</span>";
              elseif ($st === 'redeemed')  echo "<span class='badge badge-blue'>$statusRedeemed</span>";
              else                         echo "<span class='badge badge-green'>$statusActive</span>";
            ?></td>
            <td><?php
              if ($c['user_id']) echo '👤 ' . htmlspecialchars($c['user_name'] ?? ('#' . $c['user_id']));
              elseif (!empty($c['device_id'])) echo '<span style="color:#93c5fd;">' . htmlspecialchars($boundDevice) . '</span>';
              else echo '<span class="text-muted">' . htmlspecialchars($boundFree) . '</span>';
            ?></td>
            <td class="inv-hide-sm text-muted"><?= htmlspecialchars($c['label'] ?? '') ?></td>
            <td>
              <div class="inv-actions">
                <?php if ($c['active']): ?>
                  <button type="button" class="inv-btn-deactivate" data-id="<?= (int)$c['id'] ?>"><?= $btnDeactivate ?></button>
                <?php else: ?>
                  <button type="submit" name="do" value="activate:<?= (int)$c['id'] ?>" class="primary"><?= $btnActivate ?></button>
                <?php endif; ?>
                <?php if ($c['user_id'] || !empty($c['device_id'])): ?>
                  <button type="button" class="inv-btn-confirm" data-do="unbind:<?= (int)$c['id'] ?>" data-msg="Bindung lösen? Der Code wird wieder frei."><?= $btnUnbind ?></button>
                <?php endif; ?>
                <button type="button" class="danger inv-btn-confirm" data-do="delete:<?= (int)$c['id'] ?>" data-msg="Code endgültig löschen?"><?= $btnDelete ?></button>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </form>
      <?php endif; ?>
    </div>
  </div>
</main>

<!-- Custom modal – replaces window.confirm/prompt (blocked in WKWebView) -->
<div id="inv-modal" class="inv-modal-overlay" role="dialog" aria-modal="true">
  <div class="inv-modal-box">
    <p id="inv-modal-msg"></p>
    <div id="inv-modal-reason" class="inv-modal-reason">
      <label for="inv-modal-reason-input"><?= e('admin.inv.modal_reason') ?></label>
      <input type="text" id="inv-modal-reason-input" placeholder="z. B. Beta abgelaufen" autocomplete="off">
    </div>
    <div class="inv-modal-btns">
      <button type="button" id="inv-modal-cancel" class="cancel"><?= e('admin.inv.modal_cancel') ?></button>
      <button type="button" id="inv-modal-confirm" class="confirm"><?= e('admin.inv.modal_confirm') ?></button>
    </div>
  </div>
</div>

<script>
var _invDeactivateMsg = <?= json_encode(t('admin.inv.deactivate_confirm')) ?>;
var _invShownTpl = <?= json_encode(t('admin.inv.shown', ['n' => '__N__'])) ?>;
(function () {
  // ── Filter & search ──────────────────────────────────────────────────
  var rows   = Array.prototype.slice.call(document.querySelectorAll('.inv-row'));
  var chips  = Array.prototype.slice.call(document.querySelectorAll('.inv-chip'));
  var search = document.getElementById('inv-search');
  var count  = document.getElementById('inv-count');
  if (rows.length) {
    var filter = 'all';
    function apply() {
      var q = (search.value || '').toLowerCase().trim();
      var shown = 0;
      rows.forEach(function (r) {
        var okStatus = filter === 'all' || r.dataset.status === filter;
        var okQuery  = !q || r.dataset.search.indexOf(q) !== -1;
        var vis = okStatus && okQuery;
        r.style.display = vis ? '' : 'none';
        if (vis) shown++;
      });
      count.textContent = _invShownTpl.replace('__N__', shown);
    }
    chips.forEach(function (c) {
      c.addEventListener('click', function () {
        chips.forEach(function (x) { x.classList.remove('is-active'); });
        c.classList.add('is-active');
        filter = c.dataset.filter;
        apply();
      });
    });
    search.addEventListener('input', apply);
    apply();
  }

  // ── Custom modal (works in WKWebView, unlike window.confirm/prompt) ──
  var modal      = document.getElementById('inv-modal');
  var modalMsg   = document.getElementById('inv-modal-msg');
  var reasonWrap = document.getElementById('inv-modal-reason');
  var reasonInput= document.getElementById('inv-modal-reason-input');
  var btnCancel  = document.getElementById('inv-modal-cancel');
  var btnConfirm = document.getElementById('inv-modal-confirm');
  var invForm    = document.getElementById('inv-form');
  var pendingDo  = '';
  var withReason = false;

  function openModal(msg, doValue, showReason) {
    pendingDo  = doValue;
    withReason = !!showReason;
    modalMsg.textContent      = msg;
    reasonWrap.style.display  = showReason ? 'block' : 'none';
    if (showReason) reasonInput.value = '';
    modal.style.display = 'flex';
    if (showReason) setTimeout(function () { reasonInput.focus(); }, 80);
  }
  function closeModal() { modal.style.display = 'none'; pendingDo = ''; }

  btnCancel.addEventListener('click', closeModal);
  modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });

  btnConfirm.addEventListener('click', function () {
    if (!pendingDo) return;
    var doEl = document.createElement('input');
    doEl.type = 'hidden'; doEl.name = 'do'; doEl.value = pendingDo;
    invForm.appendChild(doEl);
    if (withReason && reasonInput.value.trim()) {
      var rEl = document.createElement('input');
      rEl.type = 'hidden'; rEl.name = 'reason'; rEl.value = reasonInput.value.trim();
      invForm.appendChild(rEl);
    }
    invForm.submit();
  });

  document.querySelectorAll('.inv-btn-confirm').forEach(function (btn) {
    btn.addEventListener('click', function () {
      openModal(btn.dataset.msg, btn.dataset.do, false);
    });
  });
  document.querySelectorAll('.inv-btn-deactivate').forEach(function (btn) {
    btn.addEventListener('click', function () {
      openModal(_invDeactivateMsg, 'deactivate:' + btn.dataset.id, true);
    });
  });
})();
</script>

<?php require_once "../partials/footer.php"; ?>
