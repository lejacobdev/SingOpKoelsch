<?php
require_once "../protect.php";
require_once "../functions.php";

requireAdmin();

$conn = Database::getConnection();

$flash = '';

// Toggle admin role
if (isset($_GET['toggle_admin'])) {
    $user_id = (int)$_GET['toggle_admin'];
    if ($user_id !== 1) {
        $stmt = $conn->prepare("SELECT role FROM singopkoelsch_users WHERE user_id = ?");
        $stmt->bind_param("i", $user_id); $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if ($user) {
            $newRole = ($user['role'] === 'admin') ? 'user' : 'admin';
            $upd = $conn->prepare("UPDATE singopkoelsch_users SET role = ? WHERE user_id = ?");
            $upd->bind_param("si", $newRole, $user_id); $upd->execute(); $upd->close();
            header("Location: users.php?msg=" . urlencode(t('admin.users.role_updated')));
            exit;
        }
    }
}

// Toggle trusted role (approve-bypass)
if (isset($_GET['toggle_trusted'])) {
    $user_id = (int)$_GET['toggle_trusted'];
    if ($user_id !== 1) {
        $stmt = $conn->prepare("SELECT role FROM singopkoelsch_users WHERE user_id = ?");
        $stmt->bind_param("i", $user_id); $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if ($user && $user['role'] !== 'admin') {
            $newRole = ($user['role'] === 'trusted') ? 'user' : 'trusted';
            $upd = $conn->prepare("UPDATE singopkoelsch_users SET role = ? WHERE user_id = ?");
            $upd->bind_param("si", $newRole, $user_id); $upd->execute(); $upd->close();
            $note = $newRole === 'trusted'
                ? t('admin.users.trusted_granted')
                : t('admin.users.trusted_revoked');
            header("Location: users.php?msg=" . urlencode($note));
            exit;
        }
    }
}

// Ban / unban user (#27)
if (isset($_GET['toggle_ban'])) {
    $user_id = (int)$_GET['toggle_ban'];
    if ($user_id !== 1) {
        $stmt = $conn->prepare("SELECT role FROM singopkoelsch_users WHERE user_id = ?");
        $stmt->bind_param("i", $user_id); $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if ($user) {
            $newRole = ($user['role'] === 'banned') ? 'user' : 'banned';
            $upd = $conn->prepare("UPDATE singopkoelsch_users SET role = ? WHERE user_id = ?");
            $upd->bind_param("si", $newRole, $user_id); $upd->execute(); $upd->close();
            $note = $newRole === 'banned' ? t('admin.users.banned') : t('admin.users.unbanned');
            header("Location: users.php?msg=" . urlencode($note));
            exit;
        }
    }
}

// Delete user
if (isset($_GET['delete'])) {
    $user_id = (int)$_GET['delete'];
    if ($user_id !== 1) {
        $stmt = $conn->prepare("DELETE FROM singopkoelsch_users WHERE user_id = ?");
        $stmt->bind_param("i", $user_id); $stmt->execute(); $stmt->close();
        header("Location: users.php?msg=" . urlencode(t('admin.users.deleted')));
        exit;
    }
}

// Resend verification email
if (isset($_GET['resend_verify'])) {
    $user_id = (int)$_GET['resend_verify'];
    $stmt = $conn->prepare("SELECT email, name, verify_token, email_verified FROM singopkoelsch_users WHERE user_id = ?");
    $stmt->bind_param("i", $user_id); $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if ($user) {
        if ($user['email_verified']) {
            $msg = t('admin.users.already_verified');
        } else {
            $verify_link = SITE_URL . "/verify.php?token=" . $user['verify_token'];
            $body = "Hallo {$user['name']},\n\nBitte bestätige deine E-Mail-Adresse:\n\n$verify_link\n\nVielen Dank!\nDein Sing op Kölsch Team";
            $html = renderEmailHtml('Bestätige deine E-Mail-Adresse', [
                'greeting'    => 'Hallo ' . $user['name'] . ',',
                'intro'       => 'Hier ist dein Bestätigungs-Link. Klick auf den Button, um deine E-Mail-Adresse zu verifizieren.',
                'cta_label'   => 'E-Mail bestätigen',
                'cta_url'     => $verify_link,
                'outro'       => 'Der Link funktioniert nicht? Kopiere ihn einfach in den Browser: ' . $verify_link,
                'footer_note' => 'Diese Mail wurde von einem Administrator erneut angefordert.',
            ]);
            $msg = sendMail($user['email'], $user['name'], 'Bestätige deine E-Mail-Adresse – Sing op Kölsch',
                $body, ['html' => $html, 'bypass_preference' => true])
                ? t('admin.users.mail_sent') : t('admin.users.mail_fail');
        }
    } else {
        $msg = t('admin.users.not_found');
    }
    header("Location: users.php?msg=" . urlencode($msg));
    exit;
}

// AJAX live search
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    $search = "%" . ($conn->real_escape_string($_GET['search'] ?? '')) . "%";
    $stmt = $conn->prepare("SELECT user_id, name, email, email_verified, role FROM singopkoelsch_users WHERE name LIKE ? OR email LIKE ? ORDER BY user_id ASC");
    $stmt->bind_param("ss", $search, $search); $stmt->execute();
    renderRows($stmt->get_result());
    exit;
}

$stmt = $conn->prepare("SELECT user_id, name, email, email_verified, role FROM singopkoelsch_users ORDER BY user_id ASC");
$stmt->execute();
$result = $stmt->get_result();

$flashMsg = htmlspecialchars($_GET['msg'] ?? '');

function renderRows($result) {
    while ($row = $result->fetch_assoc()) {
        $uid      = $row['user_id'];
        $name     = htmlspecialchars($row['name']);
        $email    = htmlspecialchars($row['email']);
        $verified = $row['email_verified'];
        $role     = $row['role'];
        $isMain   = ($uid === 1);

        if ($role === 'admin') {
            $roleBadge = "<span class='badge badge-blue'>admin</span>";
        } elseif ($role === 'trusted') {
            $roleBadge = "<span class='badge badge-green' title='" . htmlspecialchars(t('admin.users.bypass_hint')) . "'>" . htmlspecialchars(t('admin.role_trusted')) . "</span>";
        } elseif ($role === 'banned') {
            $roleBadge = "<span class='badge badge-red'>" . htmlspecialchars(t('admin.users.role_banned')) . "</span>";
        } else {
            $roleBadge = "<span class='badge badge-gray'>user</span>";
        }

        echo "<tr" . ($role === 'banned' ? " style='opacity:0.6;'" : "") . ">";
        echo "<td><strong>$name</strong></td>";
        echo "<td class='text-muted text-sm'>$email</td>";
        echo "<td><span class='badge " . ($verified ? 'badge-green' : 'badge-yellow') . "'>" . ($verified ? htmlspecialchars(t('admin.yes')) : htmlspecialchars(t('admin.no'))) . "</span></td>";
        echo "<td>$roleBadge</td>";
        echo "<td>";
        if ($isMain) {
            echo "<span class='text-muted text-sm'>" . htmlspecialchars(t('admin.users.main_admin')) . "</span>";
        } else {
            $roleToggleLabel  = ($role === 'admin') ? t('admin.users.to_user') : t('admin.users.to_admin');
            $trustToggleLabel = ($role === 'trusted') ? t('admin.users.trust_revoke') : t('admin.users.trust_grant');
            $banLabel  = ($role === 'banned') ? t('admin.users.unban') : t('admin.users.ban');
            $banClass  = ($role === 'banned') ? 'btn-ghost' : 'btn-warning';
            $rl = htmlspecialchars($roleToggleLabel);
            $tl = htmlspecialchars($trustToggleLabel);
            $bl = htmlspecialchars($banLabel);
            $bypassHint = htmlspecialchars(t('admin.users.bypass_hint'));
            $confirmDelete = htmlspecialchars(t('admin.users.confirm_delete'));
            echo "<div class='gap-row' style='flex-wrap:wrap;'>";
            if ($role !== 'banned') {
                echo "<a href='?toggle_admin=$uid' class='btn btn-sm btn-ghost'>$rl</a>";
                if ($role !== 'admin') {
                    echo "<a href='?toggle_trusted=$uid' class='btn btn-sm btn-ghost' title='$bypassHint'>$tl</a>";
                }
                if (!$verified) {
                    echo "<a href='?resend_verify=$uid' class='btn btn-sm btn-ghost'>" . htmlspecialchars(t('admin.users.send_mail')) . "</a>";
                }
            }
            echo "<a href='?toggle_ban=$uid' class='btn btn-sm $banClass' onclick=\"return confirm('$bl?')\">$bl</a>";
            echo "<a href='?delete=$uid' class='btn btn-sm btn-danger' onclick=\"return confirm('$confirmDelete')\">". htmlspecialchars(t('admin.users.delete')) . "</a>";
            echo "</div>";
        }
        echo "</td></tr>";
    }
}

$pageTitle = t('admin.users_management');
require_once "../partials/head.php";
require_once "../partials/nav.php";
?>

<main class="content-wide">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;margin-bottom:1.75rem;">
    <div>
      <a href="/admin/index.php" class="btn btn-ghost btn-sm" style="margin-bottom:0.6rem;">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
        <?= htmlspecialchars(t('admin.dashboard')) ?>
      </a>
      <h1><?= htmlspecialchars(t('admin.users_management')) ?></h1>
    </div>
  </div>

  <?php if ($flashMsg): ?>
    <div class="alert alert-success mb-2"><?= $flashMsg ?></div>
  <?php endif; ?>

  <div class="card">
    <div class="card-header" style="gap:0.75rem;align-items:center;">
      <span><?= htmlspecialchars(t('admin.users_overview')) ?></span>
      <span class="search-pill search-pill-sm" style="max-width:280px;margin-left:auto;">
        <svg class="search-pill-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><line x1="20" y1="20" x2="16.65" y2="16.65"/></svg>
        <input type="search" id="user-search" placeholder="<?= htmlspecialchars(t('admin.search_placeholder')) ?>" autocomplete="off" />
      </span>
    </div>
    <div style="overflow-x:auto;">
      <table class="data-table" id="user-table">
        <thead>
          <tr>
            <th><?= htmlspecialchars(t('admin.col_name')) ?></th>
            <th><?= htmlspecialchars(t('admin.col_email')) ?></th>
            <th><?= htmlspecialchars(t('admin.col_verified')) ?></th>
            <th><?= htmlspecialchars(t('admin.role')) ?></th>
            <th><?= htmlspecialchars(t('admin.actions')) ?></th>
          </tr>
        </thead>
        <tbody id="user-tbody">
          <?php renderRows($result); ?>
        </tbody>
      </table>
    </div>
  </div>

</main>

<script>
const searchInput = document.getElementById('user-search');
const tbody = document.getElementById('user-tbody');
let timer;

searchInput.addEventListener('input', function() {
    clearTimeout(timer);
    timer = setTimeout(() => {
        const q = searchInput.value.trim();
        fetch(`users.php?ajax=1&search=${encodeURIComponent(q)}`)
            .then(r => r.text())
            .then(html => { tbody.innerHTML = html; });
    }, 300);
});
</script>

<?php require_once "../partials/footer.php"; ?>
