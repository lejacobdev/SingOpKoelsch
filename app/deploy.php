<?php
// Deploy endpoint — called by GitHub Actions after a successful IPA build
// Usage: curl -X POST -H "X-Deploy-Token: TOKEN" -F "ipa=@SingOpKoelsch.ipa" https://singopkoelsch.de/app/deploy.php

define('DEPLOY_TOKEN', 'f75b12c8d673804725d96a6b88d87059162cb91896f5283e7a4beb5632c912ab');

$token = $_SERVER['HTTP_X_DEPLOY_TOKEN'] ?? '';
if (!hash_equals(DEPLOY_TOKEN, $token)) {
    http_response_code(401);
    die(json_encode(['ok' => false, 'error' => 'Unauthorized']));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['ipa'])) {
    http_response_code(400);
    die(json_encode(['ok' => false, 'error' => 'POST with ipa file required']));
}

$tmp  = $_FILES['ipa']['tmp_name'];
$dest = __DIR__ . '/SingOpKoelsch.ipa';
$size = $_FILES['ipa']['size'];

if (!move_uploaded_file($tmp, $dest)) {
    http_response_code(500);
    die(json_encode(['ok' => false, 'error' => 'Failed to save IPA']));
}

// Update size in altstore.json and altstore-pal.json
foreach (['altstore.json', 'altstore-pal.json'] as $file) {
    $path = __DIR__ . '/' . $file;
    $data = json_decode(file_get_contents($path), true);
    $data['apps'][0]['size'] = $size;
    if (!empty($data['apps'][0]['versions'][0])) {
        $data['apps'][0]['versions'][0]['size'] = $size;
    }
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

echo json_encode(['ok' => true, 'size' => $size, 'msg' => 'IPA deployed']);
