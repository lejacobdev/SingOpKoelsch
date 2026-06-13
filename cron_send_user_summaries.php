<?php
/**
 * Cron script: send summary emails to users who have received answers
 * to their proposals (approved/rejected) since their last summary.
 * Runs every hour; respects per-user max-per-day interval.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/protect.php';
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['lang'] = 'de';

error_log("Cron user summary script started at " . date('Y-m-d H:i:s'));

try {
    $conn = Database::getConnection();
    Database::ensurePreferencesTable();

    $stmt = $conn->prepare(
        "SELECT u.user_id, u.name, u.email, up.email_limit, up.last_summary_sent
         FROM singopkoelsch_users u
         INNER JOIN singopkoelsch_user_preferences up ON u.user_id = up.user_id
         WHERE u.email IS NOT NULL AND u.email != ''
           AND up.email_notifications = 1"
    );
    $stmt->execute();
    $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    error_log("Found " . count($users) . " users with notifications enabled");

    $sentCount = 0;
    $skippedCount = 0;
    $errorCount = 0;

    foreach ($users as $user) {
        $userId        = (int)$user['user_id'];
        $userName      = $user['name'] ?? 'User';
        $userEmail     = $user['email'];
        $emailLimit    = max(1, (int)($user['email_limit'] ?? 1));
        $lastSent      = $user['last_summary_sent'] ? new DateTime($user['last_summary_sent']) : null;
        $now           = new DateTime();

        // Check if enough time has passed since last email
        $intervalSeconds = Database::calculateSummaryInterval($emailLimit);
        if ($lastSent !== null) {
            $elapsed = $now->getTimestamp() - $lastSent->getTimestamp();
            if ($elapsed < $intervalSeconds) {
                $skippedCount++;
                continue;
            }
        }

        // Only send if this user has proposals answered since last summary
        $since = $lastSent ? $lastSent->format('Y-m-d H:i:s') : '1970-01-01 00:00:00';
        $crStmt = $conn->prepare(
            "SELECT cr.id, cr.status, cr.resolved_at, l.title
             FROM singopkoelsch_change_requests cr
             JOIN singopkoelsch_lyrics l ON cr.lyrics_id = l.id
             WHERE cr.user_id = ?
               AND cr.status IN ('approved', 'rejected')
               AND cr.resolved_at > ?
             ORDER BY cr.resolved_at DESC
             LIMIT 10"
        );
        $crStmt->bind_param("is", $userId, $since);
        $crStmt->execute();
        $answeredProposals = $crStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $crStmt->close();

        if (empty($answeredProposals)) {
            $skippedCount++;
            continue; // Nothing new to report for this user
        }

        // Set language to user's preferred language for email
        $prefs = Database::getUserPreferences($userId);
        $_SESSION['lang'] = $prefs['lang'] ?? 'de';

        // Build email content
        $headline = t('email.activity_summary_headline');
        $greeting = sprintf(t('email.activity_summary_greeting'), $userName);
        $intro    = t('email.activity_summary_intro', ['count' => count($answeredProposals)]);

        $detailHtml = '<ul style="margin:0;padding:0 0 0 20px;list-style-type:disc;">';
        foreach ($answeredProposals as $proposal) {
            $songTitle  = htmlspecialchars($proposal['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $statusLabel = $proposal['status'] === 'approved'
                ? t('email.proposal_approved')
                : t('email.proposal_rejected');
            $date = !empty($proposal['resolved_at'])
                ? date(t('email.activity_summary_date_format'), strtotime($proposal['resolved_at']))
                : t('email.activity_summary_unknown_date');

            $detailHtml .= '<li style="margin:0 0 8px 0;">';
            $detailHtml .= htmlspecialchars($songTitle) . ' – ' . htmlspecialchars($statusLabel) . ' (' . htmlspecialchars($date) . ')';
            $detailHtml .= '</li>';
        }
        $detailHtml .= '</ul>';

        $ctaLabel   = t('email.activity_summary_cta_label');
        $ctaUrl     = SITE_URL . '/dashboard.php';
        $footerNote = t('email.activity_summary_footer_note');

        $htmlBody = renderEmailHtml($headline, [
            'greeting'    => $greeting,
            'intro'       => $intro,
            'detail_html' => $detailHtml,
            'cta_label'   => $ctaLabel,
            'cta_url'     => $ctaUrl,
            'outro'       => '',
            'footer_note' => $footerNote
        ]);

        $plainText = trim(preg_replace('/\s+/', ' ', strip_tags(str_replace(['<br', '<li', '</li>'], ["\n<br", "\n• <li", "</li>"], $htmlBody))));

        $sent = sendMail($userEmail, $userName, $headline, $plainText, ['html' => $htmlBody]);

        if ($sent) {
            $upStmt = $conn->prepare(
                "UPDATE singopkoelsch_user_preferences SET last_summary_sent = ? WHERE user_id = ?"
            );
            $upStmt->bind_param("si", $now->format('Y-m-d H:i:s'), $userId);
            $upStmt->execute();
            $upStmt->close();
            $sentCount++;
            error_log("Sent proposal summary to user $userId ($userEmail): " . count($answeredProposals) . " answered proposals");
        } else {
            $errorCount++;
            error_log("Failed to send proposal summary to user $userId ($userEmail)");
        }
    }

    $conn->close();
    error_log("Cron user summary completed. Sent: $sentCount, Skipped: $skippedCount, Errors: $errorCount");

} catch (Exception $e) {
    error_log("Cron user summary error: " . $e->getMessage());
    exit(1);
}
?>
