<?php
// ════════════════════════════════════════════════════════════════════
//  push.php — Web Push helper for the Home-Screen PWA (iOS 16.4+ / Android)
//  VAPID keys live in /var/www/vapid.json (outside the web root).
//  Call push_send_to_user($userId, $title, $body, $url) to notify someone.
// ════════════════════════════════════════════════════════════════════
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/vendor/autoload.php';

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

function push_vapid(): array {
    $f = '/var/www/vapid.json';
    if (!is_file($f)) return [];
    $d = json_decode((string)file_get_contents($f), true);
    return is_array($d) ? $d : [];
}

function push_ensure_table(): void {
    static $done = false;
    if ($done) return;
    Database::getConnection()->query(
        "CREATE TABLE IF NOT EXISTS singopkoelsch_push_subs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            endpoint VARCHAR(512) NOT NULL,
            p256dh VARCHAR(255) NOT NULL,
            auth VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_endpoint (endpoint(191)),
            KEY idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $done = true;
}

function push_save(int $userId, array $sub): bool {
    $endpoint = $sub['endpoint'] ?? '';
    $p256dh   = $sub['keys']['p256dh'] ?? '';
    $auth     = $sub['keys']['auth'] ?? '';
    if ($endpoint === '' || $p256dh === '' || $auth === '') return false;
    push_ensure_table();
    $stmt = Database::getConnection()->prepare(
        "INSERT INTO singopkoelsch_push_subs (user_id, endpoint, p256dh, auth) VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE user_id=VALUES(user_id), p256dh=VALUES(p256dh), auth=VALUES(auth)"
    );
    $stmt->bind_param('isss', $userId, $endpoint, $p256dh, $auth);
    return $stmt->execute();
}

function push_delete(string $endpoint): void {
    push_ensure_table();
    $stmt = Database::getConnection()->prepare("DELETE FROM singopkoelsch_push_subs WHERE endpoint=?");
    $stmt->bind_param('s', $endpoint);
    $stmt->execute();
}

/** Send a push to every device of a user. Returns how many devices were queued. */
function push_send_to_user(int $userId, string $title, string $body, string $url = '/'): int {
    $vapid = push_vapid();
    if (empty($vapid['publicKey'])) return 0;
    push_ensure_table();
    $stmt = Database::getConnection()->prepare(
        "SELECT endpoint, p256dh, auth FROM singopkoelsch_push_subs WHERE user_id=?"
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    if (!$res || $res->num_rows === 0) return 0;

    $webPush = new WebPush(['VAPID' => [
        'subject'    => $vapid['subject'] ?? SITE_URL,
        'publicKey'  => $vapid['publicKey'],
        'privateKey' => $vapid['privateKey'],
    ]]);
    $payload = json_encode(['title' => $title, 'body' => $body, 'url' => $url]);
    $count = 0;
    while ($row = $res->fetch_assoc()) {
        $webPush->queueNotification(
            Subscription::create([
                'endpoint' => $row['endpoint'],
                'keys'     => ['p256dh' => $row['p256dh'], 'auth' => $row['auth']],
            ]),
            $payload
        );
        $count++;
    }
    foreach ($webPush->flush() as $report) {
        if (!$report->isSuccess() && $report->isSubscriptionExpired()) {
            push_delete($report->getEndpoint());
        }
    }
    return $count;
}

/** Send a push to every subscribed device. Returns how many were queued. */
function push_send_to_all(string $title, string $body, string $url = '/'): int {
    $vapid = push_vapid();
    if (empty($vapid['publicKey'])) return 0;
    push_ensure_table();
    $res = Database::getConnection()->query("SELECT endpoint, p256dh, auth FROM singopkoelsch_push_subs");
    if (!$res || $res->num_rows === 0) return 0;
    $webPush = new WebPush(['VAPID' => [
        'subject'    => $vapid['subject'] ?? SITE_URL,
        'publicKey'  => $vapid['publicKey'],
        'privateKey' => $vapid['privateKey'],
    ]]);
    $payload = json_encode(['title' => $title, 'body' => $body, 'url' => $url]);
    $count = 0;
    while ($row = $res->fetch_assoc()) {
        $webPush->queueNotification(
            Subscription::create([
                'endpoint' => $row['endpoint'],
                'keys'     => ['p256dh' => $row['p256dh'], 'auth' => $row['auth']],
            ]),
            $payload
        );
        $count++;
    }
    foreach ($webPush->flush() as $report) {
        if (!$report->isSuccess() && $report->isSubscriptionExpired()) {
            push_delete($report->getEndpoint());
        }
    }
    return $count;
}
