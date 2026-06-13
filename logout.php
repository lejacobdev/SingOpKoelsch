<?php
session_start();
$_SESSION = [];
// Clear the (possibly long-lived) session cookie so app users are really logged out.
$__cp = session_get_cookie_params();
setcookie(session_name(), '', [
    'expires'  => time() - 42000,
    'path'     => $__cp['path'] ?: '/',
    'domain'   => $__cp['domain'] ?? '',
    'secure'   => !empty($__cp['secure']),
    'httponly' => !empty($__cp['httponly']),
    'samesite' => $__cp['samesite'] ?? '',
]);
session_destroy();
header("Location: login.php");
exit;
