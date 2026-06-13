<?php
require_once "protect.php";

$lang = $_GET['lang'] ?? $_POST['lang'] ?? '';
if (isset(I18N_LANGS[$lang])) {
    setLang($lang);
}

$return = $_GET['return'] ?? $_POST['return'] ?? ($_SERVER['HTTP_REFERER'] ?? '/');
// Only allow same-origin returns
$parsed = parse_url($return);
if (!empty($parsed['host']) && ($parsed['host'] !== ($_SERVER['HTTP_HOST'] ?? ''))) {
    $return = '/';
}
header('Location: ' . $return);
exit;
