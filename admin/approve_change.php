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

// Apply the proposed payload and mark the request as approved.
// Full-field change requests carry a JSON `proposed_changes` payload; legacy
// lyrics-only requests fall back to `proposed_lyrics`.
if (!empty($change["proposed_changes"])) {
    $decoded = json_decode($change["proposed_changes"], true);
    $ok = is_array($decoded)
        ? Database::approveFullChangeRequest((int)$id, $lyricId, $decoded)
        : false;
} else {
    $ok = Database::approveChangeRequest($id, $lyricId, $change["proposed_lyrics"]);
}

if ($ok) {
    $title   = $change['title'];
    $subject = 'Dein Änderungsvorschlag für "' . $title . '" wurde genehmigt';
    $songUrl = SITE_URL . '/detail.php?lyrics=' . $lyricId;
    $body    = "Hallo {$change['user_name']},\n\n"
             . 'Dein Änderungsvorschlag für das Lied "' . $title . "\" wurde genehmigt und übernommen.\n\n"
             . "Lied ansehen: $songUrl\n\n"
             . "Vielen Dank für deine Mitarbeit!\n\nDein Sing op Kölsch Team";
    $html = renderEmailHtml('Vorschlag genehmigt', [
        'greeting'    => 'Hallo ' . $change['user_name'] . ',',
        'intro'       => 'Dein Änderungsvorschlag für das Lied „' . $title . '" wurde genehmigt und übernommen. Danke für deine Mitarbeit!',
        'cta_label'   => 'Lied ansehen',
        'cta_url'     => $songUrl,
        'outro'       => 'Bleib singfreudig — Dein Sing op Kölsch Team',
        'footer_note' => 'Diese Mail bezieht sich auf einen Vorschlag, den du eingereicht hast.',
    ]);

    sendMail($change['user_email'], $change['user_name'], $subject, $body, ['html' => $html]);

    // Web push to the proposal's author (PWA), in addition to the email.
    try {
        require_once __DIR__ . '/../push.php';
        push_send_to_user((int)$change['user_id'], 'Vorschlag genehmigt ✅',
            'Dein Änderungsvorschlag für „' . $title . '" wurde übernommen.',
            '/detail.php?lyrics=' . $lyricId);
    } catch (\Throwable $e) { /* push must never block the approval */ }
}

header("Location: ../detail.php?lyrics=$lyricId");
exit();
