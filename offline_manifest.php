<?php
// Public list of every page worth caching for full offline (used by the SW's
// "cache all" in the native app). All listed pages are public (no login).
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$urls = ['/', '/lieder.php'];
$conn = Database::getConnection();
$res  = $conn->query("SELECT id FROM singopkoelsch_lyrics ORDER BY id");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $urls[] = '/detail.php?lyrics=' . (int)$row['id'];
    }
}
echo json_encode(['urls' => $urls, 'total' => count($urls)]);
