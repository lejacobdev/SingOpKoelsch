<?php
require_once __DIR__ . '/protect.php';

if (!isset($_FILES['audio'])) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(["error" => "No audio uploaded"]);
    exit;
}

$tmpFile = $_FILES['audio']['tmp_name'];

$cmd = "python3 "
     . escapeshellarg(__DIR__ . "/shazam_recognize.py")
     . " "
     . escapeshellarg($tmpFile)
     . " 2>/dev/null";

$output = shell_exec($cmd);

header("Content-Type: application/json");
echo $output;
