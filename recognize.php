<?php
header('Content-Type: application/json');

// Debugging
error_log("recognize.php aufgerufen");

// Datei prüfen
if (!isset($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
    $msg = "Keine Audio-Datei hochgeladen oder Upload-Fehler.";
    error_log($msg);
    echo json_encode(['error' => true, 'message' => $msg]);
    exit;
}

$audioPath = $_FILES['audio']['tmp_name'];

// Debug
error_log("Audio empfangen: " . $audioPath);

// Python-Server aufrufen (lokaler WebSocket oder HTTP)
$pythonServerUrl = "http://127.0.0.1:5010/recognize";

$ch = curl_init($pythonServerUrl);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Accept: application/json"]);
curl_setopt($ch, CURLOPT_POSTFIELDS, [
    'audio' => new CURLFile($audioPath, 'audio/webm', 'recording.webm')
]);

$response = curl_exec($ch);
if (curl_errno($ch)) {
    $err = curl_error($ch);
    error_log("CURL Fehler: $err");
    echo json_encode(['error' => true, 'message' => "CURL Fehler: $err"]);
    exit;
}
curl_close($ch);

// Response debuggen
error_log("Antwort vom Python-Server: $response");

// JSON prüfen
$data = json_decode($response, true);
if ($data === null) {
    $err = json_last_error_msg();
    error_log("JSON Fehler: $err");
    echo json_encode(['error' => true, 'message' => "Ungültige JSON-Antwort vom Python-Server: $err"]);
    exit;
}

// Erfolg
echo json_encode($data);