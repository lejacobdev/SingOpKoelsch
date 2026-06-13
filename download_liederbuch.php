<?php
require_once "protect.php";
require_once __DIR__ . '/functions.php';

requireAdmin();

$zipPath = __DIR__ . '/Kallendresser-20260605T223449Z-3-001.zip';

if (!file_exists($zipPath)) {
    http_response_code(404);
    die("Archiv nicht gefunden.");
}

$requestedPath = $_GET['path'] ?? '';

// Security: only allow paths inside Kallendresser/ and reject traversal
if (
    empty($requestedPath) ||
    strpos($requestedPath, '..') !== false ||
    strpos($requestedPath, "\0") !== false ||
    strpos($requestedPath, 'Kallendresser/') !== 0
) {
    http_response_code(400);
    die("Ungültiger Pfad.");
}

// Only allow safe file extensions
$ext = strtolower(pathinfo($requestedPath, PATHINFO_EXTENSION));
$allowed = ['docx', 'pdf', 'pptx', 'xlsx', 'odt'];
if (!in_array($ext, $allowed)) {
    http_response_code(400);
    die("Dateityp nicht erlaubt.");
}

$zip = new ZipArchive();
if ($zip->open($zipPath) !== true) {
    http_response_code(500);
    die("Archiv konnte nicht geöffnet werden.");
}

$content = $zip->getFromName($requestedPath);
$zip->close();

if ($content === false) {
    http_response_code(404);
    die("Datei nicht gefunden.");
}

$mimeTypes = [
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'pdf'  => 'application/pdf',
    'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'odt'  => 'application/vnd.oasis.opendocument.text',
];

$filename = basename($requestedPath);
$mime     = $mimeTypes[$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
header('Content-Length: ' . strlen($content));
header('Cache-Control: private');
echo $content;
