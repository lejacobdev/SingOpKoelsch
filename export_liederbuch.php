<?php
require_once "protect.php";
require_once "functions.php";

requireAdmin();

if (!isset($_POST["selected_lyrics"]) || !is_array($_POST["selected_lyrics"])) {
    die("Keine Lieder ausgewählt.");
}

// Reihenfolge aus Hidden-Input (wenn vorhanden)
$order = [];
if (!empty($_POST['order'])) {
    $order = explode(',', $_POST['order']);
} else {
    $order = $_POST["selected_lyrics"];
}

// Doppelte IDs entfernen, um Wiederholungen zu vermeiden
$order = array_unique($order);

// Word-kompatibler Download-Header
header("Content-type: application/vnd.ms-word");
header("Content-Disposition: attachment;Filename=Liederbuch_" . date("Y-m-d") . ".doc");

echo '<html xmlns:o="urn:schemas-microsoft-com:office:office"
      xmlns:w="urn:schemas-microsoft-com:office:word"
      xmlns="http://www.w3.org/TR/REC-html40">';

echo "<head><meta charset='utf-8'><title>Liederbuch</title></head>";
echo "<body style='font-family: Calibri, sans-serif; font-size: 12pt;'>";

$bandMap = Database::getBandMap();

$alreadyOutputLyrics = [];
$first = true;

foreach ($order as $id) {
    $id = (int)$id;
    if ($id === 0) continue;

    $lyric = Database::queryDataById($id);
    if (!$lyric) continue;

    if (in_array($lyric['lyrics'], $alreadyOutputLyrics, true)) continue;
    $alreadyOutputLyrics[] = $lyric['lyrics'];

    $bandName      = $bandMap[$lyric["band_id"]]         ?? "Unbekannter Künstler";
    $textAutorName = $bandMap[$lyric["text_autor_id"]]   ?? "Unbekannt";
    $musikAutorName= $bandMap[$lyric["musik_autor_id"]]  ?? "Unbekannt";

    // Seitenumbruch außer beim ersten Lied
    if (!$first) {
        echo '<br style="page-break-before: always">';
    }

    echo "<div style='font-family: Calibri, sans-serif; font-size: 12pt;'>";

    // Autoren-Namen in Variablen für Vergleich
    $m = htmlspecialchars($musikAutorName);
    $t = htmlspecialchars($textAutorName);
    $i = htmlspecialchars($bandName);

    $prefix = "";
    if ($m === $t && $t === $i) {
        $prefix = "M+T+I: ";
    } elseif ($m === $t) {
        $prefix = "M+T: ";
    } elseif ($m === $i) {
        $prefix = "M+I: ";
    } elseif ($t === $i) {
        $prefix = "T+I: ";
    }

    // Titel + Bandname + Autoren in Klammern (inline)
    echo "<h2 style='font-size: 14pt; margin: 0; display: inline;'>";
    echo htmlspecialchars($lyric['title']);
    echo "<br>";
    echo " <span style='font-size: 12pt; font-weight: normal;'>(";

    // Autoren-Text nach Bedingungen
    if ($prefix === "M+T+I: ") {
        echo $prefix . $m;
    } elseif ($prefix === "M+T: ") {
        echo $prefix . $m;
        echo ", I: " . $i;
    } elseif ($prefix === "M+I: ") {
        echo $prefix . $m;
        echo ", T: " . $t;
    } elseif ($prefix === "T+I: ") {
        echo $prefix . $t;
        echo ", M: " . $m;
    } else {
        // Alle einzeln ausgeben
        echo "M: " . $m . ", T: " . $t . ", I: " . $i;
    }

    echo ")</span>";
    echo "</h2><br>";

    // Formatierte Lyrics: fett mit <strong>, Zeilenumbrüche erhalten
    $lyricsTrimmed = trim($lyric["lyrics"]);
    $formatted = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', htmlspecialchars($lyricsTrimmed));
    $formatted = nl2br($formatted);

    echo "<div style='margin-top: 0.5em;'>$formatted</div>";

    echo "</div>";

    $first = false;
}

echo "</body></html>";
