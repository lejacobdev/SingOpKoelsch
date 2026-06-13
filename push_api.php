<?php
// Client endpoint for the PWA push UI: subscribe / unsubscribe / vapid / test.
require_once __DIR__ . '/protect.php';
require_once __DIR__ . '/push.php';
header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'not_logged_in']);
    exit;
}

$uid    = (int)$_SESSION['user_id'];
$action = $_GET['action'] ?? '';
$input  = json_decode((string)file_get_contents('php://input'), true) ?: [];

switch ($action) {
    case 'vapid':
        echo json_encode(['publicKey' => push_vapid()['publicKey'] ?? '']);
        break;

    case 'subscribe':
        $sub = $input['subscription'] ?? $input;
        echo json_encode(['ok' => push_save($uid, is_array($sub) ? $sub : [])]);
        break;

    case 'unsubscribe':
        if (!empty($input['endpoint'])) push_delete($input['endpoint']);
        echo json_encode(['ok' => true]);
        break;

    case 'test':
        $n = push_send_to_user($uid, 'Sing op Kölsch', 'Test-Benachrichtigung – es funktioniert! 🎉', '/');
        echo json_encode(['ok' => true, 'sent' => $n]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'unknown_action']);
}
