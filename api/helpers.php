<?php
/** Shared utilities for the mobile REST API */

function json_ok($data, int $status = 200): void {
    http_response_code($status);
    echo json_encode(['ok' => true, 'data' => $data]);
    exit;
}

function json_err(string $message, int $status = 400): void {
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

function get_token(): ?string {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) return trim($m[1]);
    return null;
}

function require_auth(): array {
    $token = get_token();
    $conn  = Database::getConnection();
    if ($token) {
        $stmt = $conn->prepare(
            "SELECT user_id, name, email, role, profile_picture, email_verified
             FROM singopkoelsch_users WHERE mobile_token = ? LIMIT 1"
        );
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($user) return $user;
    }
    // Fallback: web session (for AJAX calls from the browser)
    if (!empty($_SESSION['user_id'])) {
        $uid  = (int)$_SESSION['user_id'];
        $stmt = $conn->prepare(
            "SELECT user_id, name, email, role, profile_picture, email_verified
             FROM singopkoelsch_users WHERE user_id = ? LIMIT 1"
        );
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($user) return $user;
    }
    json_err('Unauthorized', 401);
}

function require_admin(): array {
    $user = require_auth();
    if ($user['role'] !== 'admin') json_err('Forbidden', 403);
    return $user;
}

function api_migrate(): void {
    $conn = Database::getConnection();

    // mobile_token column on users
    $r = $conn->query("SHOW COLUMNS FROM singopkoelsch_users LIKE 'mobile_token'");
    if ($r->num_rows === 0) {
        $conn->query("ALTER TABLE singopkoelsch_users
            ADD COLUMN mobile_token VARCHAR(64) NULL DEFAULT NULL,
            ADD INDEX idx_mobile_token (mobile_token)");
    }

    // device_tokens table for APNs
    $conn->query("CREATE TABLE IF NOT EXISTS singopkoelsch_device_tokens (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        device_token VARCHAR(255) NOT NULL,
        environment ENUM('sandbox','production') NOT NULL DEFAULT 'production',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_token (device_token),
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // resolved_at on change_requests (may already exist)
    $r = $conn->query("SHOW COLUMNS FROM singopkoelsch_change_requests LIKE 'resolved_at'");
    if ($r->num_rows === 0) {
        $conn->query("ALTER TABLE singopkoelsch_change_requests
            ADD COLUMN resolved_at TIMESTAMP NULL DEFAULT NULL");
    }
}

function input(): array {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    if (is_array($json)) return $json;
    // fall back to POST form data
    return $_POST;
}

function send_apns_to_user(int $userId, string $title, string $body, array $extra = []): void {
    try {
        require_once __DIR__ . '/APNs.php';
        $conn = Database::getConnection();
        $stmt = $conn->prepare(
            "SELECT device_token, environment FROM singopkoelsch_device_tokens WHERE user_id = ?"
        );
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $tokens = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($tokens as $row) {
            APNs::send($row['device_token'], $title, $body, $extra, $row['environment'] === 'sandbox');
        }
    } catch (Exception $e) {
        error_log("APNs send failed: " . $e->getMessage());
    }
}
