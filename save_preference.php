<?php
require_once "protect.php";
require_once "functions.php";

if (!isLoggedIn()) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'not logged in']);
    exit;
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$key   = trim($_POST['key'] ?? '');
$value = (int)($_POST['value'] ?? 0);

$allowed = ['dark_mode', 'email_notifications'];
if (!in_array($key, $allowed)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid key']);
    exit;
}

Database::getConnection();
Database::ensurePreferencesTable();

$userId = (int)$_SESSION['user_id'];
$ok = Database::saveUserPreference($userId, $key, $value);

$_SESSION[$key] = $value;

echo json_encode(['ok' => $ok]);
