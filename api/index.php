<?php
/**
 * Sing op Kölsch — Mobile REST API
 * All endpoints for the iOS app.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../push.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['lang'] = 'de'; // fallback; auth endpoints override per user

api_migrate();
Database::ensurePreferencesTable();

// ── Route parsing ─────────────────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
// Strip any prefix so /api/... or /var/www/html8/api/... both work
$uri    = preg_replace('#^.*/api#', '', $uri);
$parts  = array_values(array_filter(explode('/', trim($uri, '/'))));

function route(string $m, array $expect, callable $cb): void {
    global $method, $parts;
    if ($method !== strtoupper($m)) return;
    if (count($parts) !== count($expect)) return;
    $params = [];
    foreach ($expect as $i => $seg) {
        if (substr($seg, 0, 1) === ':') {
            $params[ltrim($seg, ':')] = $parts[$i];
        } elseif ($parts[$i] !== $seg) {
            return;
        }
    }
    $cb($params);
    exit;
}

$conn = Database::getConnection();

// ═══════════════════════════════════════════════════════════════════════════════
// AUTH
// ═══════════════════════════════════════════════════════════════════════════════

route('POST', ['auth', 'login'], function() use ($conn) {
    $in = input();
    $email    = trim($in['email'] ?? '');
    $password = $in['password'] ?? '';
    if (!$email || !$password) json_err('Email and password required');

    $stmt = $conn->prepare(
        "SELECT user_id, name, email, password, role, email_verified, profile_picture
         FROM singopkoelsch_users WHERE email = ? LIMIT 1"
    );
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user || !password_verify($password, $user['password'])) {
        json_err('Invalid email or password', 401);
    }
    if (!$user['email_verified']) {
        json_err('Please verify your email address first', 403);
    }

    $token = bin2hex(random_bytes(32));
    $stmt2 = $conn->prepare("UPDATE singopkoelsch_users SET mobile_token = ? WHERE user_id = ?");
    $stmt2->bind_param("si", $token, $user['user_id']);
    $stmt2->execute();
    $stmt2->close();

    unset($user['password']);
    $user['token'] = $token;
    json_ok($user);
});

route('POST', ['auth', 'register'], function() use ($conn) {
    $in = input();
    $name     = trim($in['name'] ?? '');
    $email    = strtolower(trim($in['email'] ?? ''));
    $password = $in['password'] ?? '';

    if (mb_strlen($name) < 2) json_err('Name must be at least 2 characters');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_err('Invalid email');
    if (strlen($password) < 6) json_err('Password must be at least 6 characters');

    $stmt = $conn->prepare("SELECT user_id FROM singopkoelsch_users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) json_err('Email already registered');
    $stmt->close();

    $hash  = password_hash($password, PASSWORD_DEFAULT);
    $vt    = bin2hex(random_bytes(32));
    $unsub = bin2hex(random_bytes(16));
    $stmt2 = $conn->prepare(
        "INSERT INTO singopkoelsch_users (name, email, password, verify_token, unsubscribe_token, role)
         VALUES (?, ?, ?, ?, ?, 'user')"
    );
    $stmt2->bind_param("sssss", $name, $email, $hash, $vt, $unsub);
    $stmt2->execute();
    $newId = $conn->insert_id;
    $stmt2->close();

    // Create preferences row
    $stmt3 = $conn->prepare(
        "INSERT IGNORE INTO singopkoelsch_user_preferences (user_id) VALUES (?)"
    );
    $stmt3->bind_param("i", $newId);
    $stmt3->execute();
    $stmt3->close();

    // Send verification email
    $verifyUrl  = SITE_URL . '/verify.php?token=' . $vt;
    $emailHtml  = "<p>Hallo $name,</p><p>bitte bestätige deine E-Mail-Adresse:</p><p><a href=\"$verifyUrl\">$verifyUrl</a></p>";
    sendMail($email, $name, 'Sing op Kölsch – E-Mail bestätigen', strip_tags($emailHtml), ['html' => $emailHtml, 'bypass' => true]);

    json_ok(['message' => 'Account created — please check your email to verify.'], 201);
});

route('POST', ['auth', 'logout'], function() use ($conn) {
    $user = require_auth();
    $stmt = $conn->prepare("UPDATE singopkoelsch_users SET mobile_token = NULL WHERE user_id = ?");
    $stmt->bind_param("i", $user['user_id']);
    $stmt->execute();
    $stmt->close();
    json_ok(['message' => 'Logged out']);
});

route('GET', ['auth', 'me'], function() {
    $user = require_auth();
    unset($user['password']);
    $prefs = Database::getUserPreferences($user['user_id']);
    $user['preferences'] = [
        'dark_mode'           => (bool)$prefs['dark_mode'],
        'notifications'       => (bool)$prefs['email_notifications'],
        'email_max_per_day'   => (int)$prefs['email_limit'],
        'lang'                => $prefs['lang'] ?? 'de',
    ];
    json_ok($user);
});

route('POST', ['auth', 'forgot'], function() use ($conn) {
    $in    = input();
    $email = strtolower(trim($in['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_err('Invalid email');

    $reset = Database::createPasswordResetToken($email);
    if ($reset) {
        $url  = SITE_URL . '/reset.php?token=' . $reset['token'];
        $html = "<p>Hallo {$reset['name']},</p><p>setze dein Passwort zurück: <a href=\"$url\">$url</a></p>";
        sendMail($email, $reset['name'], 'Sing op Kölsch – Passwort zurücksetzen', strip_tags($html), ['html' => $html, 'bypass' => true]);
    }
    // Always 200 to not leak existence
    json_ok(['message' => 'If that email exists you will receive a reset link.']);
});

// ═══════════════════════════════════════════════════════════════════════════════
// SONGS
// ═══════════════════════════════════════════════════════════════════════════════

route('GET', ['songs'], function() use ($conn) {
    $user    = require_auth();
    $search  = trim($_GET['q'] ?? '');
    $bandId  = isset($_GET['band_id']) ? (int)$_GET['band_id'] : null;
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(50, max(10, (int)($_GET['per_page'] ?? 20)));

    $filters = [];
    if ($bandId) $filters['band_id'] = $bandId;
    if (!empty($_GET['has_lyrics'])) $filters['has_lyrics'] = 1;

    $songs = Database::queryFiltered($search, $filters);

    // Paginate
    $total  = count($songs);
    $offset = ($page - 1) * $perPage;
    $paged  = array_slice($songs, $offset, $perPage);

    // Minimal fields for list
    $paged = array_map(fn($s) => [
        'id'           => (int)$s['id'],
        'title'        => $s['title'],
        'band_name'    => $s['band_name'] ?? '',
        'band_id'      => (int)($s['band_id'] ?? 0),
        'album'        => $s['album'] ?? '',
        'release_year' => (int)($s['release_year'] ?? 0),
        'cover_url'    => $s['cover_url'] ?? '',
        'has_lyrics'   => !empty($s['lyrics']) && strlen($s['lyrics']) > 10,
        'has_spotify'  => !empty($s['spotify_link']),
        'has_video'    => !empty($s['video_link']),
    ], $paged);

    json_ok([
        'songs'    => $paged,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
        'pages'    => (int)ceil($total / $perPage),
    ]);
});

// ═══════════════════════════════════════════════════════════════════════════════
// RANDOM FAVORITE SONG  (widget – favorites mode)
// ═══════════════════════════════════════════════════════════════════════════════

route('GET', ['songs', 'random', 'favorite'], function() use ($conn) {
    $song = null;

    // Try token from Authorization header (token stored as mobile_token in users table)
    $token = get_token();
    if ($token) {
        $stmt = $conn->prepare(
            "SELECT user_id FROM singopkoelsch_users WHERE mobile_token = ? LIMIT 1"
        );
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            $userId = (int)$row['user_id'];
            $result = $conn->query(
                "SELECT l.id, l.title, b.band_name, l.cover_url
                 FROM singopkoelsch_favorites f
                 JOIN singopkoelsch_lyrics l ON l.id = f.song_id
                 LEFT JOIN singopkoelsch_bands b ON b.band_id = l.band_id
                 WHERE f.user_id = $userId AND l.lyrics IS NOT NULL AND l.lyrics != ''
                 ORDER BY RAND() LIMIT 1"
            );
            $song = $result ? $result->fetch_assoc() : null;
        }
    }

    // Fallback: random from all songs
    if (!$song) {
        $result = $conn->query(
            "SELECT l.id, l.title, b.band_name, l.cover_url
             FROM singopkoelsch_lyrics l
             LEFT JOIN singopkoelsch_bands b ON b.band_id = l.band_id
             WHERE l.lyrics IS NOT NULL AND l.lyrics != ''
             ORDER BY RAND() LIMIT 1"
        );
        $song = $result ? $result->fetch_assoc() : null;
    }

    if (!$song) json_err('No songs found', 404);
    $song['id'] = (int)$song['id'];
    json_ok($song);
});

// ═══════════════════════════════════════════════════════════════════════════════
// RANDOM SONG  (widget / random-play)
// ═══════════════════════════════════════════════════════════════════════════════

route('GET', ['songs', 'random'], function() use ($conn) {
    $result = $conn->query(
        "SELECT l.id, l.title, b.band_name, l.cover_url, l.album, l.release_year
         FROM singopkoelsch_lyrics l
         LEFT JOIN singopkoelsch_bands b ON b.band_id = l.band_id
         WHERE l.lyrics IS NOT NULL AND l.lyrics != ''
         ORDER BY RAND() LIMIT 1"
    );
    $song = $result ? $result->fetch_assoc() : null;
    if (!$song) json_err('No songs found', 404);
    $song['id'] = (int)$song['id'];
    if (!empty($_GET['redirect'])) {
        header('Location: /detail.php?lyrics=' . $song['id']);
        exit;
    }
    json_ok($song);
});

route('GET', ['songs', 'random', 'band', ':id'], function($params) use ($conn) {
    $bandId = (int)$params['id'];
    if (!$bandId) json_err('Invalid band id', 400);
    $stmt = $conn->prepare(
        "SELECT l.id FROM singopkoelsch_lyrics l
         WHERE l.band_id = ? AND l.lyrics IS NOT NULL AND l.lyrics != ''
         ORDER BY RAND() LIMIT 1"
    );
    $stmt->bind_param("i", $bandId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) json_err('No songs found for this band', 404);
    header('Location: /detail.php?lyrics=' . (int)$row['id']);
    exit;
});

route('GET', ['songs', ':id'], function($params) use ($conn) {
    $user   = require_auth();
    $songId = (int)$params['id'];
    $song   = Database::queryDataById($songId);
    if (!$song) json_err('Song not found', 404);

    // Band name
    $stmt = $conn->prepare("SELECT band_name FROM singopkoelsch_bands WHERE band_id = ?");
    $stmt->bind_param("i", $song['band_id']);
    $stmt->execute();
    $band = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $song['band_name'] = $band['band_name'] ?? '';

    // Pending proposals for this song by current user
    $stmt2 = $conn->prepare(
        "SELECT id, status, created_at, resolved_at
         FROM singopkoelsch_change_requests
         WHERE lyrics_id = ? AND user_id = ? ORDER BY created_at DESC LIMIT 5"
    );
    $stmt2->bind_param("ii", $songId, $user['user_id']);
    $stmt2->execute();
    $song['my_proposals'] = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt2->close();

    json_ok($song);
});

route('POST', ['songs', ':id', 'propose'], function($params) use ($conn) {
    $user   = require_auth();
    $songId = (int)$params['id'];
    $in     = input();

    $proposed = trim($in['lyrics'] ?? '');
    $notes    = trim($in['notes'] ?? '');
    if (!$proposed) json_err('Proposed lyrics required');

    $song = Database::queryDataById($songId);
    if (!$song) json_err('Song not found', 404);

    $ok = Database::insertChangeRequest($songId, $user['user_id'], $proposed);
    if (!$ok) json_err('Failed to submit proposal');

    // Notify admins (APNs + Web Push)
    $pushBody = "{$user['name']} hat einen Vorschlag für \"{$song['title']}\" eingereicht.";
    foreach (Database::getAdmins() as $admin) {
        $uid = (int)$admin['user_id'];
        send_apns_to_user($uid, 'Neuer Textvorschlag', $pushBody,
            ['type' => 'new_proposal', 'song_id' => $songId]);
        push_send_to_user($uid, 'Neuer Textvorschlag', $pushBody, '/admin/proposals.php');
    }

    json_ok(['message' => 'Proposal submitted'], 201);
});

// ═══════════════════════════════════════════════════════════════════════════════
// BANDS
// ═══════════════════════════════════════════════════════════════════════════════

route('GET', ['bands'], function() use ($conn) {
    require_auth();
    $result = $conn->query(
        "SELECT b.band_id, b.band_name, COUNT(l.id) as song_count
         FROM singopkoelsch_bands b
         LEFT JOIN singopkoelsch_lyrics l ON l.band_id = b.band_id
         GROUP BY b.band_id
         ORDER BY song_count DESC, b.band_name ASC"
    );
    $bands = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    json_ok($bands);
});

route('GET', ['bands', ':id'], function($params) use ($conn) {
    require_auth();
    $bandId = (int)$params['id'];

    $stmt = $conn->prepare("SELECT band_id, band_name FROM singopkoelsch_bands WHERE band_id = ?");
    $stmt->bind_param("i", $bandId);
    $stmt->execute();
    $band = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$band) json_err('Band not found', 404);

    $stmt2 = $conn->prepare(
        "SELECT id, title, album, release_year, cover_url, spotify_link, video_link
         FROM singopkoelsch_lyrics WHERE band_id = ? ORDER BY release_year DESC, title ASC"
    );
    $stmt2->bind_param("i", $bandId);
    $stmt2->execute();
    $band['songs'] = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt2->close();

    json_ok($band);
});

// ═══════════════════════════════════════════════════════════════════════════════
// PROPOSALS (user's own)
// ═══════════════════════════════════════════════════════════════════════════════

route('GET', ['proposals'], function() use ($conn) {
    $user = require_auth();
    $status = $_GET['status'] ?? null;

    $where  = "cr.user_id = ?";
    $types  = "i";
    $vals   = [$user['user_id']];
    if ($status) { $where .= " AND cr.status = ?"; $types .= "s"; $vals[] = $status; }

    $stmt = $conn->prepare(
        "SELECT cr.id, cr.lyrics_id, cr.status, cr.created_at, cr.resolved_at,
                l.title as song_title, b.band_name
         FROM singopkoelsch_change_requests cr
         JOIN singopkoelsch_lyrics l ON cr.lyrics_id = l.id
         LEFT JOIN singopkoelsch_bands b ON l.band_id = b.band_id
         WHERE $where ORDER BY cr.created_at DESC LIMIT 50"
    );
    $stmt->bind_param($types, ...$vals);
    $stmt->execute();
    $proposals = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    json_ok($proposals);
});

// ═══════════════════════════════════════════════════════════════════════════════
// PROFILE
// ═══════════════════════════════════════════════════════════════════════════════

route('GET', ['profile'], function() {
    $user  = require_auth();
    $prefs = Database::getUserPreferences($user['user_id']);
    json_ok([
        'user_id'         => (int)$user['user_id'],
        'name'            => $user['name'],
        'email'           => $user['email'],
        'role'            => $user['role'],
        'profile_picture' => $user['profile_picture'],
        'preferences'     => [
            'dark_mode'         => (bool)$prefs['dark_mode'],
            'notifications'     => (bool)$prefs['email_notifications'],
            'email_max_per_day' => (int)$prefs['email_limit'],
            'lang'              => $prefs['lang'] ?? 'de',
        ],
    ]);
});

route('PUT', ['profile'], function() use ($conn) {
    $user = require_auth();
    $in   = input();
    $name = trim($in['name'] ?? '');
    if (mb_strlen($name) < 2) json_err('Name too short');
    if (!Database::updateUserProfile($user['user_id'], $name)) json_err('Failed to update');
    json_ok(['name' => $name]);
});

route('POST', ['profile', 'password'], function() use ($conn) {
    $user = require_auth();
    $in   = input();
    $curr = $in['current_password'] ?? '';
    $new  = $in['new_password'] ?? '';
    if (strlen($new) < 6) json_err('New password must be at least 6 characters');

    $stmt = $conn->prepare("SELECT password FROM singopkoelsch_users WHERE user_id = ?");
    $stmt->bind_param("i", $user['user_id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!password_verify($curr, $row['password'])) json_err('Current password incorrect', 401);

    $hash  = password_hash($new, PASSWORD_DEFAULT);
    $stmt2 = $conn->prepare("UPDATE singopkoelsch_users SET password = ? WHERE user_id = ?");
    $stmt2->bind_param("si", $hash, $user['user_id']);
    $stmt2->execute();
    $stmt2->close();
    json_ok(['message' => 'Password updated']);
});

route('PUT', ['profile', 'preferences'], function() use ($conn) {
    $user = require_auth();
    $in   = input();

    if (isset($in['dark_mode']))     Database::setUserPreference($user['user_id'], 'dark_mode', (int)(bool)$in['dark_mode']);
    if (isset($in['notifications'])) Database::setUserPreference($user['user_id'], 'email_notifications', (int)(bool)$in['notifications']);
    if (isset($in['lang']))          Database::setUserPreference($user['user_id'], 'lang', $in['lang']);
    if (isset($in['email_max_per_day'])) {
        $limit = max(1, min(24, (int)$in['email_max_per_day']));
        Database::setUserPreference($user['user_id'], 'email_limit', $limit);
        Database::setUserPreference($user['user_id'], 'email_unit', 'day');
    }
    json_ok(['message' => 'Preferences updated']);
});

// ═══════════════════════════════════════════════════════════════════════════════
// PUSH NOTIFICATION DEVICE TOKENS
// ═══════════════════════════════════════════════════════════════════════════════

route('POST', ['notifications', 'register'], function() use ($conn) {
    $user = require_auth();
    $in   = input();
    $tok  = trim($in['device_token'] ?? '');
    $env  = ($in['environment'] ?? 'production') === 'sandbox' ? 'sandbox' : 'production';
    if (!$tok) json_err('device_token required');

    $stmt = $conn->prepare(
        "INSERT INTO singopkoelsch_device_tokens (user_id, device_token, environment)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), environment = VALUES(environment)"
    );
    $stmt->bind_param("iss", $user['user_id'], $tok, $env);
    $stmt->execute();
    $stmt->close();
    json_ok(['message' => 'Token registered']);
});

route('DELETE', ['notifications', 'token'], function() use ($conn) {
    $user = require_auth();
    $in   = input();
    $tok  = trim($in['device_token'] ?? '');
    if ($tok) {
        $stmt = $conn->prepare("DELETE FROM singopkoelsch_device_tokens WHERE device_token = ? AND user_id = ?");
        $stmt->bind_param("si", $tok, $user['user_id']);
        $stmt->execute();
        $stmt->close();
    }
    json_ok(['message' => 'Token removed']);
});

// ═══════════════════════════════════════════════════════════════════════════════
// ADMIN
// ═══════════════════════════════════════════════════════════════════════════════

route('GET', ['admin', 'stats'], function() {
    require_admin();
    $stats = Database::getStats();
    $top   = Database::getTopBands(5);
    json_ok(['stats' => $stats, 'top_bands' => $top]);
});

route('GET', ['admin', 'proposals'], function() use ($conn) {
    require_admin();
    $status = $_GET['status'] ?? 'pending';
    $stmt = $conn->prepare(
        "SELECT cr.id, cr.lyrics_id, cr.status, cr.created_at, cr.resolved_at,
                cr.proposed_lyrics, cr.proposed_changes,
                l.title as song_title, l.lyrics as current_lyrics,
                u.name as user_name, u.user_id,
                b.band_name
         FROM singopkoelsch_change_requests cr
         JOIN singopkoelsch_lyrics l  ON cr.lyrics_id = l.id
         JOIN singopkoelsch_users  u  ON cr.user_id   = u.user_id
         LEFT JOIN singopkoelsch_bands b ON l.band_id = b.band_id
         WHERE cr.status = ?
         ORDER BY cr.created_at ASC LIMIT 100"
    );
    $stmt->bind_param("s", $status);
    $stmt->execute();
    $proposals = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    json_ok($proposals);
});

route('POST', ['admin', 'proposals', ':id', 'approve'], function($params) use ($conn) {
    require_admin();
    $crId = (int)$params['id'];
    $cr   = Database::getChangeRequestById($crId);
    if (!$cr) json_err('Proposal not found', 404);
    if ($cr['status'] !== 'pending') json_err('Already decided');

    $ok = Database::approveChangeRequest($crId, (int)$cr['lyrics_id'], $cr['proposed_lyrics']);
    if (!$ok) json_err('Failed to approve');

    // Push notify the proposal author
    send_apns_to_user(
        (int)$cr['user_id'],
        '✅ Vorschlag angenommen',
        "Dein Textvorschlag fuer \"{$cr['title']}\" wurde angenommen!",
        ['type' => 'proposal_approved', 'song_id' => (int)$cr['lyrics_id']]
    );
    json_ok(['message' => 'Approved']);
});

route('POST', ['admin', 'proposals', ':id', 'reject'], function($params) use ($conn) {
    require_admin();
    $crId = (int)$params['id'];
    $cr   = Database::getChangeRequestById($crId);
    $in   = input();
    if (!$cr) json_err('Proposal not found', 404);
    if ($cr['status'] !== 'pending') json_err('Already decided');

    $ok = Database::rejectChangeRequest($crId);
    if (!$ok) json_err('Failed to reject');

    send_apns_to_user(
        (int)$cr['user_id'],
        '❌ Vorschlag abgelehnt',
        "Dein Textvorschlag fuer \"{$cr['title']}\" wurde leider abgelehnt.",
        ['type' => 'proposal_rejected', 'song_id' => (int)$cr['lyrics_id']]
    );
    json_ok(['message' => 'Rejected']);
});

route('GET', ['admin', 'users'], function() {
    require_admin();
    $users = Database::getAllUsers();
    json_ok($users);
});

// ═══════════════════════════════════════════════════════════════════════════════
// SONGS — create / update / delete  (admin or trusted)
// ═══════════════════════════════════════════════════════════════════════════════

route('POST', ['songs'], function() use ($conn) {
    $user = require_auth();
    if (!in_array($user['role'], ['admin', 'trusted'])) json_err('Forbidden', 403);
    $in = input();

    $title   = trim($in['title'] ?? '');
    $lyrics  = trim($in['lyrics'] ?? '');
    $album   = trim($in['album'] ?? '');
    $spotify = trim($in['spotify_link'] ?? '');
    $video   = trim($in['video_link'] ?? '');
    $year    = (int)($in['release_year'] ?? 0);
    $bandId  = isset($in['band_id']) ? (int)$in['band_id'] : null;

    if (!$title) json_err('Title required');

    $stmt = $conn->prepare(
        "INSERT INTO singopkoelsch_lyrics
             (title, lyrics, band_id, album, spotify_link, video_link, release_year)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param("ssisssi", $title, $lyrics, $bandId, $album, $spotify, $video, $year);
    if (!$stmt->execute()) json_err('Failed to create song');
    $newId = $conn->insert_id;
    $stmt->close();
    json_ok(['id' => $newId, 'message' => 'Song created'], 201);
});

route('PUT', ['songs', ':id'], function($params) use ($conn) {
    $user = require_auth();
    if (!in_array($user['role'], ['admin', 'trusted'])) json_err('Forbidden', 403);
    $songId = (int)$params['id'];
    $song   = Database::queryDataById($songId);
    if (!$song) json_err('Song not found', 404);
    $in = input();

    $title   = trim($in['title']        ?? $song['title']);
    $lyrics  = trim($in['lyrics']       ?? $song['lyrics']);
    $album   = trim($in['album']        ?? $song['album']);
    $spotify = trim($in['spotify_link'] ?? $song['spotify_link']);
    $video   = trim($in['video_link']   ?? $song['video_link']);
    $year    = (int)($in['release_year'] ?? $song['release_year'] ?? 0);
    $bandId  = isset($in['band_id']) ? (int)$in['band_id'] : (int)$song['band_id'];
    $textAut = isset($in['text_autor_id'])  ? (int)$in['text_autor_id']  : (int)$song['text_autor_id'];
    $musAut  = isset($in['musik_autor_id']) ? (int)$in['musik_autor_id'] : (int)$song['musik_autor_id'];

    $ok = Database::updateData($songId, $title, $lyrics, $bandId, $textAut, $musAut, $album, $spotify, $video, $year);
    if (!$ok) json_err('Failed to update song');
    json_ok(['message' => 'Song updated']);
});

route('DELETE', ['songs', ':id'], function($params) use ($conn) {
    require_admin();
    $songId = (int)$params['id'];
    if (!Database::queryDataById($songId)) json_err('Song not found', 404);
    if (!Database::deleteData($songId)) json_err('Failed to delete song');
    json_ok(['message' => 'Song deleted']);
});

// ═══════════════════════════════════════════════════════════════════════════════
// ACCOUNT DELETION
// ═══════════════════════════════════════════════════════════════════════════════

route('POST', ['account', 'delete'], function() use ($conn) {
    $user = require_auth();
    $in   = input();
    $pw   = $in['password'] ?? '';

    $stmt = $conn->prepare("SELECT password FROM singopkoelsch_users WHERE user_id = ?");
    $stmt->bind_param("i", $user['user_id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || !password_verify($pw, $row['password'])) json_err('Incorrect password', 401);

    // Remove device tokens first
    $stmt2 = $conn->prepare("DELETE FROM singopkoelsch_device_tokens WHERE user_id = ?");
    $stmt2->bind_param("i", $user['user_id']);
    $stmt2->execute();
    $stmt2->close();

    if (!Database::deleteUser($user['user_id'])) json_err('Failed to delete account');
    json_ok(['message' => 'Account deleted']);
});

// ═══════════════════════════════════════════════════════════════════════════════
// SONGBOOK EXPORT
// ═══════════════════════════════════════════════════════════════════════════════

route('POST', ['liederbuch', 'export'], function() use ($conn) {
    require_auth();
    $in  = input();
    $ids = array_map('intval', (array)($in['song_ids'] ?? []));
    if (empty($ids)) json_err('No songs selected');
    if (count($ids) > 100) json_err('Too many songs (max 100)');

    // Build Word HTML in memory
    ob_start();
    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word" xmlns="http://www.w3.org/TR/REC-html40">';
    echo '<head><meta charset="utf-8"><title>Liederbuch</title>';
    echo '<style>body{font-family:Arial,sans-serif;font-size:11pt;}h1{font-size:14pt;color:#8B0000;}h2{font-size:11pt;}p{white-space:pre-wrap;font-family:Courier New,monospace;}</style>';
    echo '</head><body>';

    foreach (array_unique($ids) as $songId) {
        $song = Database::queryDataById($songId);
        if (!$song) continue;
        $stmt = $conn->prepare("SELECT band_name FROM singopkoelsch_bands WHERE band_id = ?");
        $stmt->bind_param("i", $song['band_id']);
        $stmt->execute();
        $band = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $bandName = htmlspecialchars($band['band_name'] ?? '');

        echo '<div style="page-break-after:always;">';
        echo '<h1>' . htmlspecialchars($song['title']) . '</h1>';
        echo '<h2>' . $bandName;
        if (!empty($song['album'])) echo ' &ndash; ' . htmlspecialchars($song['album']);
        if (!empty($song['release_year'])) echo ' (' . (int)$song['release_year'] . ')';
        echo '</h2>';
        if (!empty($song['lyrics'])) {
            echo '<p>' . htmlspecialchars($song['lyrics']) . '</p>';
        } else {
            echo '<p><em>Kein Text vorhanden.</em></p>';
        }
        echo '</div>';
    }
    echo '</body></html>';
    $docHtml = ob_get_clean();

    json_ok([
        'filename' => 'Liederbuch_' . date('Y-m-d') . '.doc',
        'mime'     => 'application/vnd.ms-word',
        'content'  => base64_encode($docHtml),
    ]);
});

// ═══════════════════════════════════════════════════════════════════════════════
// AUDIO RECOGNITION  (proxy to local Python/Shazam service)
// ═══════════════════════════════════════════════════════════════════════════════

route('POST', ['songs', 'recognize'], function() use ($conn) {
    require_auth();

    if (empty($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
        json_err('No audio file received');
    }

    $ch = curl_init('http://127.0.0.1:5010/recognize');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_POSTFIELDS     => [
            'audio' => new CURLFile(
                $_FILES['audio']['tmp_name'],
                $_FILES['audio']['type'] ?: 'audio/mp4',
                'recording'
            ),
        ],
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$raw || $code !== 200) json_err('Recognition service unavailable', 503);
    $result = json_decode($raw, true);
    if (empty($result) || !empty($result['error'])) json_err($result['message'] ?? 'No match found');

    // Try to find this song in our DB by title + artist
    $title  = $result['title']    ?? '';
    $artist = $result['subtitle'] ?? '';
    $match  = null;

    if ($title) {
        $like = "%$title%";
        $stmt = $conn->prepare(
            "SELECT l.id, l.title, b.band_name FROM singopkoelsch_lyrics l
             LEFT JOIN singopkoelsch_bands b ON l.band_id = b.band_id
             WHERE l.title LIKE ? LIMIT 1"
        );
        $stmt->bind_param("s", $like);
        $stmt->execute();
        $match = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    json_ok([
        'shazam_title'  => $title,
        'shazam_artist' => $artist,
        'shazam_url'    => $result['url'] ?? '',
        'db_match'      => $match,
    ]);
});

// ═══════════════════════════════════════════════════════════════════════════════
// ADMIN — COVER PROPOSALS
// ═══════════════════════════════════════════════════════════════════════════════

route('GET', ['admin', 'cover-proposals'], function() use ($conn) {
    require_admin();
    $status = $_GET['status'] ?? 'pending';
    $stmt = $conn->prepare(
        "SELECT cp.id, cp.lyrics_id, cp.spotify_url, cp.note, cp.status, cp.created_at,
                l.title as song_title, u.name as user_name, b.band_name
         FROM singopkoelsch_cover_proposals cp
         JOIN singopkoelsch_lyrics l  ON cp.lyrics_id = l.id
         JOIN singopkoelsch_users  u  ON cp.user_id   = u.user_id
         LEFT JOIN singopkoelsch_bands b ON l.band_id = b.band_id
         WHERE cp.status = ?
         ORDER BY cp.created_at ASC LIMIT 100"
    );
    $stmt->bind_param("s", $status);
    $stmt->execute();
    json_ok($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    $stmt->close();
});

route('POST', ['admin', 'cover-proposals', ':id', 'approve'], function($params) use ($conn) {
    $admin = require_admin();
    $cpId  = (int)$params['id'];
    $now   = date('Y-m-d H:i:s');
    $stmt  = $conn->prepare(
        "UPDATE singopkoelsch_cover_proposals SET status='approved', reviewed_at=?, reviewed_by=? WHERE id=?"
    );
    $stmt->bind_param("sii", $now, $admin['user_id'], $cpId);
    $stmt->execute(); $stmt->close();
    json_ok(['message' => 'Approved']);
});

route('POST', ['admin', 'cover-proposals', ':id', 'reject'], function($params) use ($conn) {
    $admin = require_admin();
    $cpId  = (int)$params['id'];
    $now   = date('Y-m-d H:i:s');
    $stmt  = $conn->prepare(
        "UPDATE singopkoelsch_cover_proposals SET status='rejected', reviewed_at=?, reviewed_by=? WHERE id=?"
    );
    $stmt->bind_param("sii", $now, $admin['user_id'], $cpId);
    $stmt->execute(); $stmt->close();
    json_ok(['message' => 'Rejected']);
});

// ═══════════════════════════════════════════════════════════════════════════════
// FAVORITES
// ═══════════════════════════════════════════════════════════════════════════════

function fav_ensure_table(): void {
    Database::getConnection()->query(
        "CREATE TABLE IF NOT EXISTS singopkoelsch_favorites (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            user_id    INT NOT NULL,
            song_id    INT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_fav (user_id, song_id),
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

route('GET', ['favorites'], function() use ($conn) {
    $user = require_auth();
    fav_ensure_table();
    $stmt = $conn->prepare(
        "SELECT l.id, l.title, b.band_name, l.cover_url, l.album, l.release_year,
                l.spotify_link, l.video_link, f.created_at as faved_at
         FROM singopkoelsch_favorites f
         JOIN singopkoelsch_lyrics l ON l.id = f.song_id
         LEFT JOIN singopkoelsch_bands b ON b.band_id = l.band_id
         WHERE f.user_id = ?
         ORDER BY f.created_at DESC"
    );
    $stmt->bind_param("i", $user['user_id']);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    json_ok($rows);
});

route('POST', ['favorites'], function() use ($conn) {
    $user   = require_auth();
    $songId = (int)(input()['song_id'] ?? 0);
    if (!$songId) json_err('song_id required');
    fav_ensure_table();
    $stmt = $conn->prepare("INSERT IGNORE INTO singopkoelsch_favorites (user_id, song_id) VALUES (?, ?)");
    $stmt->bind_param("ii", $user['user_id'], $songId);
    $stmt->execute();
    $stmt->close();
    json_ok(['message' => 'Added', 'song_id' => $songId]);
});

route('DELETE', ['favorites', ':id'], function($params) use ($conn) {
    $user   = require_auth();
    $songId = (int)$params['id'];
    fav_ensure_table();
    $stmt = $conn->prepare("DELETE FROM singopkoelsch_favorites WHERE user_id = ? AND song_id = ?");
    $stmt->bind_param("ii", $user['user_id'], $songId);
    $stmt->execute();
    $stmt->close();
    json_ok(['message' => 'Removed', 'song_id' => $songId]);
});

// ═══════════════════════════════════════════════════════════════════════════════
// BAND FOLLOWS (#50)
// ═══════════════════════════════════════════════════════════════════════════════

function follows_ensure_table(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS singopkoelsch_band_follows (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, band_id INT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uniq_follow (user_id, band_id), INDEX idx_user (user_id), INDEX idx_band (band_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

route('GET', ['bands', ':id', 'follow'], function($params) use ($conn) {
    $user   = require_auth();
    $bandId = (int)$params['id'];
    follows_ensure_table($conn);
    $stmt = $conn->prepare("SELECT 1 FROM singopkoelsch_band_follows WHERE user_id = ? AND band_id = ?");
    $stmt->bind_param("ii", $user['user_id'], $bandId);
    $stmt->execute();
    $following = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    json_ok(['following' => $following, 'band_id' => $bandId]);
});

route('POST', ['bands', ':id', 'follow'], function($params) use ($conn) {
    $user   = require_auth();
    $bandId = (int)$params['id'];
    follows_ensure_table($conn);
    $stmt = $conn->prepare("INSERT IGNORE INTO singopkoelsch_band_follows (user_id, band_id) VALUES (?, ?)");
    $stmt->bind_param("ii", $user['user_id'], $bandId);
    $stmt->execute(); $stmt->close();
    json_ok(['following' => true, 'band_id' => $bandId]);
});

route('DELETE', ['bands', ':id', 'follow'], function($params) use ($conn) {
    $user   = require_auth();
    $bandId = (int)$params['id'];
    follows_ensure_table($conn);
    $stmt = $conn->prepare("DELETE FROM singopkoelsch_band_follows WHERE user_id = ? AND band_id = ?");
    $stmt->bind_param("ii", $user['user_id'], $bandId);
    $stmt->execute(); $stmt->close();
    json_ok(['following' => false, 'band_id' => $bandId]);
});

route('GET', ['bands', 'followed'], function() use ($conn) {
    $user = require_auth();
    follows_ensure_table($conn);
    $stmt = $conn->prepare(
        "SELECT b.band_id, b.band_name, (SELECT COUNT(*) FROM singopkoelsch_lyrics l WHERE l.band_id=b.band_id) as song_count
         FROM singopkoelsch_band_follows f
         JOIN singopkoelsch_bands b ON b.band_id = f.band_id
         WHERE f.user_id = ? ORDER BY b.band_name ASC"
    );
    $stmt->bind_param("i", $user['user_id']);
    $stmt->execute();
    json_ok($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
});

// ═══════════════════════════════════════════════════════════════════════════════
// SONG RECOMMENDATION (#47)
// ═══════════════════════════════════════════════════════════════════════════════

route('POST', ['songs', ':id', 'recommend'], function($params) use ($conn) {
    $user   = require_auth();
    $songId = (int)$params['id'];
    $in     = input();
    $recipientEmail = trim($in['email'] ?? '');
    $note   = trim($in['note'] ?? '');

    if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        json_err('Valid recipient email required');
    }

    $song = Database::queryDataById($songId);
    if (!$song) json_err('Song not found', 404);

    require_once __DIR__ . '/../functions.php';
    $songUrl = SITE_URL . '/detail.php?lyrics=' . $songId;
    $senderName = $user['name'];
    $body = "$senderName hat dir einen Kölsch-Song empfohlen:\n\n\"{$song['title']}\"\n\n$songUrl"
           . ($note ? "\n\nNotiz: $note" : '');
    $html = renderEmailHtml('Song-Empfehlung', [
        'greeting'    => 'Hej,',
        'intro'       => htmlspecialchars($senderName) . ' hat dir einen Kölsch-Song empfohlen: <strong>' . htmlspecialchars($song['title']) . '</strong>',
        'cta_label'   => 'Song öffnen',
        'cta_url'     => $songUrl,
        'outro'       => $note ? 'Notiz: ' . htmlspecialchars($note) : '',
        'footer_note' => 'Diese Empfehlung wurde über singopkoelsch.de gesendet.',
    ]);
    sendMail($recipientEmail, 'Sing op Kölsch Empfehlung', 'Song-Empfehlung von ' . $senderName, $body, ['html' => $html]);

    json_ok(['message' => 'Recommendation sent']);
});

// ═══════════════════════════════════════════════════════════════════════════════
// 404
// ═══════════════════════════════════════════════════════════════════════════════
json_err("Unknown endpoint: $method /" . implode('/', $parts), 404);
