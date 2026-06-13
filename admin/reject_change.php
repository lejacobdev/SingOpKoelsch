<?php
require_once "../protect.php";
require_once "../functions.php";

requireAdmin();

$id      = (int)($_GET["id"] ?? 0);
$lyricId = (int)($_GET["lyric_id"] ?? 0);

if (!$id || !$lyricId) {
    die("Ungültige Parameter.");
}

$change = Database::getChangeRequestById($id);

if (!$change) {
    die("Vorschlag nicht gefunden.");
}

Database::rejectChangeRequest($id);

$title   = $change['title'];
$songUrl = SITE_URL . '/detail.php?lyrics=' . $lyricId;
$subject = 'Dein Änderungsvorschlag für "' . $title . '" wurde abgelehnt';
$body    = "Hallo {$change['user_name']},\n\n"
         . 'Dein Änderungsvorschlag für das Lied "' . $title . "\" wurde leider abgelehnt.\n\n"
         . "Lied ansehen: $songUrl\n\n"
         . "Vielen Dank für dein Engagement!\nDein Sing op Kölsch Team";
$html = renderEmailHtml('Vorschlag abgelehnt', [
    'greeting'    => 'Hallo ' . $change['user_name'] . ',',
    'intro'       => 'Dein Änderungsvorschlag für das Lied „' . $title . '" wurde leider nicht übernommen. Danke trotzdem für dein Engagement — schau gerne wieder vorbei.',
    'cta_label'   => 'Lied ansehen',
    'cta_url'     => $songUrl,
    'outro'       => 'Bis bald — Dein Sing op Kölsch Team',
    'footer_note' => 'Diese Mail bezieht sich auf einen Vorschlag, den du eingereicht hast.',
]);

sendMail($change['user_email'], $change['user_name'], $subject, $body, ['html' => $html]);

// Web push to the proposal's author (PWA), in addition to the email.
try {
    require_once __DIR__ . '/../push.php';
    push_send_to_user((int)$change['user_id'], 'Vorschlag abgelehnt',
        'Dein Änderungsvorschlag für „' . $title . '" wurde leider nicht übernommen.',
        '/detail.php?lyrics=' . $lyricId);
} catch (\Throwable $e) { /* push must never block the rejection */ }

header("Location: ../detail.php?lyrics=$lyricId");
exit();
