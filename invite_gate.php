<?php
// ════════════════════════════════════════════════════════════════════
//  invite_gate.php — admin-toggleable invite-code gate.
//  A code is single-use and binds to the first redeemer: the logged-in
//  ACCOUNT, or (if not logged in) the DEVICE (persistent cookie token).
//  Access = admin OR account-owns-code OR device-owns-code.
// ════════════════════════════════════════════════════════════════════
require_once __DIR__ . '/functions.php';

function invite_ensure_tables(): void {
    static $done = false;
    if ($done) return;
    $conn = Database::getConnection();
    $conn->query("CREATE TABLE IF NOT EXISTS singopkoelsch_settings (
        skey VARCHAR(64) PRIMARY KEY,
        sval TEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->query("CREATE TABLE IF NOT EXISTS singopkoelsch_invite_codes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(32) NOT NULL UNIQUE,
        label VARCHAR(128) DEFAULT NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        user_id INT DEFAULT NULL,
        device_id VARCHAR(64) DEFAULT NULL,
        reason VARCHAR(255) DEFAULT NULL,
        redeemed_at TIMESTAMP NULL DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_user (user_id),
        KEY idx_device (device_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Migration: add columns that may be missing from an older table version
    // (CREATE TABLE IF NOT EXISTS won't alter an existing table).
    $cols = [];
    $r = $conn->query("SHOW COLUMNS FROM singopkoelsch_invite_codes");
    while ($r && $row = $r->fetch_assoc()) $cols[$row['Field']] = true;
    if (!isset($cols['user_id']))     $conn->query("ALTER TABLE singopkoelsch_invite_codes ADD COLUMN user_id INT DEFAULT NULL");
    if (!isset($cols['device_id']))   $conn->query("ALTER TABLE singopkoelsch_invite_codes ADD COLUMN device_id VARCHAR(64) DEFAULT NULL");
    if (!isset($cols['redeemed_at'])) $conn->query("ALTER TABLE singopkoelsch_invite_codes ADD COLUMN redeemed_at TIMESTAMP NULL DEFAULT NULL");
    if (!isset($cols['reason']))      $conn->query("ALTER TABLE singopkoelsch_invite_codes ADD COLUMN reason VARCHAR(255) DEFAULT NULL");
    $done = true;
}

function app_get_setting(string $key, $default = null) {
    invite_ensure_tables();
    $conn = Database::getConnection();
    $stmt = $conn->prepare("SELECT sval FROM singopkoelsch_settings WHERE skey = ?");
    $stmt->bind_param('s', $key); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ? $row['sval'] : $default;
}
function app_set_setting(string $key, string $value): void {
    invite_ensure_tables();
    $conn = Database::getConnection();
    $stmt = $conn->prepare("INSERT INTO singopkoelsch_settings (skey, sval) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE sval = VALUES(sval)");
    $stmt->bind_param('ss', $key, $value); $stmt->execute();
}

function invite_gate_enabled(): bool { return app_get_setting('invite_gate', '0') === '1'; }
function invite_set_gate(bool $on): void { app_set_setting('invite_gate', $on ? '1' : '0'); }
function beta_ended(): bool { return app_get_setting('beta_ended', '0') === '1'; }
function set_beta_ended(bool $ended): void { app_set_setting('beta_ended', $ended ? '1' : '0'); }

function invite_normalize(string $code): string { return preg_replace('/[^A-Z0-9]/', '', strtoupper($code)); }
function invite_format(string $code): string {
    $c = invite_normalize($code);
    return strlen($c) === 8 ? substr($c, 0, 4) . '-' . substr($c, 4) : $c;
}

/** Stable per-device token from a long-lived cookie. Creates it on demand. */
function invite_device_id(bool $create = false): string {
    if (!empty($_COOKIE['sok_device'])) {
        return preg_replace('/[^a-f0-9]/', '', strtolower((string)$_COOKIE['sok_device']));
    }
    if ($create) {
        $tok = bin2hex(random_bytes(16));
        @setcookie('sok_device', $tok, [
            'expires' => time() + 60 * 60 * 24 * 365 * 3,
            'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
        ]);
        $_COOKIE['sok_device'] = $tok;
        return $tok;
    }
    return '';
}

/** Redeem a code. Returns 'ok' | 'bad' (unknown/inactive) | 'taken' (bound elsewhere). */
function invite_redeem(string $code): string {
    invite_ensure_tables();
    $code = invite_normalize($code);
    if ($code === '') return 'bad';
    $conn = Database::getConnection();
    $s = $conn->prepare("SELECT id, user_id, device_id FROM singopkoelsch_invite_codes WHERE code = ? AND active = 1 LIMIT 1");
    $s->bind_param('s', $code); $s->execute();
    $row = $s->get_result()->fetch_assoc();
    if (!$row) return 'bad';
    $unbound = ($row['user_id'] === null && $row['device_id'] === null);

    if (!empty($_SESSION['user_id'])) {                       // account binding
        $uid = (int)$_SESSION['user_id'];
        if ($unbound) {
            $u = $conn->prepare("UPDATE singopkoelsch_invite_codes SET user_id = ?, redeemed_at = NOW() WHERE id = ? AND user_id IS NULL AND device_id IS NULL");
            $u->bind_param('ii', $uid, $row['id']); $u->execute();
            return $u->affected_rows > 0 ? 'ok' : 'taken';
        }
        return ($row['user_id'] !== null && (int)$row['user_id'] === $uid) ? 'ok' : 'taken';
    }

    $dev = invite_device_id(true);                            // device binding
    if ($unbound) {
        $u = $conn->prepare("UPDATE singopkoelsch_invite_codes SET device_id = ?, redeemed_at = NOW() WHERE id = ? AND user_id IS NULL AND device_id IS NULL");
        $u->bind_param('si', $dev, $row['id']); $u->execute();
        return $u->affected_rows > 0 ? 'ok' : 'taken';
    }
    return ($row['device_id'] !== null && $row['device_id'] === $dev) ? 'ok' : 'taken';
}

/** admin OR logged-in account owns a code OR this device owns a code. */
function invite_access_granted(): bool {
    if (function_exists('isAdmin') && isAdmin()) return true;
    if (app_get_setting('beta_ended', '0') === '1') return false;
    invite_ensure_tables();
    $conn = Database::getConnection();
    if (!empty($_SESSION['user_id'])) {
        $uid = (int)$_SESSION['user_id'];
        $s = $conn->prepare("SELECT id FROM singopkoelsch_invite_codes WHERE user_id = ? AND active = 1 LIMIT 1");
        $s->bind_param('i', $uid); $s->execute();
        if ($s->get_result()->fetch_assoc()) return true;
    }
    $dev = invite_device_id(false);
    if ($dev !== '') {
        $s = $conn->prepare("SELECT id FROM singopkoelsch_invite_codes WHERE device_id = ? AND active = 1 LIMIT 1");
        $s->bind_param('s', $dev); $s->execute();
        if ($s->get_result()->fetch_assoc()) return true;
    }
    return false;
}

/** Returns ['reason' => ...] if current user/device has a deactivated code, else null. */
function invite_revoked_info(): ?array {
    invite_ensure_tables();
    $conn = Database::getConnection();
    if (!empty($_SESSION['user_id'])) {
        $uid = (int)$_SESSION['user_id'];
        $s = $conn->prepare("SELECT reason FROM singopkoelsch_invite_codes WHERE user_id = ? AND active = 0 LIMIT 1");
        $s->bind_param('i', $uid); $s->execute();
        $row = $s->get_result()->fetch_assoc();
        if ($row !== false && $row !== null) return ['reason' => $row['reason']];
    }
    $dev = invite_device_id(false);
    if ($dev !== '') {
        $s = $conn->prepare("SELECT reason FROM singopkoelsch_invite_codes WHERE device_id = ? AND active = 0 LIMIT 1");
        $s->bind_param('s', $dev); $s->execute();
        $row = $s->get_result()->fetch_assoc();
        if ($row !== false && $row !== null) return ['reason' => $row['reason']];
    }
    return null;
}

function invite_gen_code(): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';   // no ambiguous chars
    $code = '';
    for ($i = 0; $i < 8; $i++) $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    return $code;
}
function invite_create_code(string $label = ''): string {
    invite_ensure_tables();
    $conn = Database::getConnection();
    do {
        $code = invite_gen_code();
        $stmt = $conn->prepare("SELECT id FROM singopkoelsch_invite_codes WHERE code = ?");
        $stmt->bind_param('s', $code); $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc();
    } while ($exists);
    $stmt = $conn->prepare("INSERT INTO singopkoelsch_invite_codes (code, label) VALUES (?, ?)");
    $stmt->bind_param('ss', $code, $label); $stmt->execute();
    return $code;
}

function invite_list_codes(): array {
    invite_ensure_tables();
    $conn = Database::getConnection();
    $res = $conn->query("SELECT c.*, u.name AS user_name
                         FROM singopkoelsch_invite_codes c
                         LEFT JOIN singopkoelsch_users u ON u.user_id = c.user_id
                         ORDER BY c.active DESC,
                                  (c.user_id IS NOT NULL OR c.device_id IS NOT NULL) ASC,
                                  c.code ASC");
    $out = [];
    while ($res && $row = $res->fetch_assoc()) $out[] = $row;
    return $out;
}
function invite_set_active(int $id, bool $active, string $reason = ''): void {
    invite_ensure_tables();
    $conn = Database::getConnection();
    $a = $active ? 1 : 0;
    if ($active) {
        $stmt = $conn->prepare("UPDATE singopkoelsch_invite_codes SET active = ?, reason = NULL WHERE id = ?");
        $stmt->bind_param('ii', $a, $id);
    } else {
        $stmt = $conn->prepare("UPDATE singopkoelsch_invite_codes SET active = ?, reason = ? WHERE id = ?");
        $stmt->bind_param('isi', $a, $reason, $id);
    }
    $stmt->execute();
}
function invite_unbind(int $id): void {
    invite_ensure_tables();
    $conn = Database::getConnection();
    $stmt = $conn->prepare("UPDATE singopkoelsch_invite_codes SET user_id = NULL, device_id = NULL, redeemed_at = NULL WHERE id = ?");
    $stmt->bind_param('i', $id); $stmt->execute();
}
function invite_delete_code(int $id): void {
    invite_ensure_tables();
    $conn = Database::getConnection();
    $stmt = $conn->prepare("DELETE FROM singopkoelsch_invite_codes WHERE id = ?");
    $stmt->bind_param('i', $id); $stmt->execute();
}
