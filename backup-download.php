<?php
// Token-protected one-time download for the project backup (file lives OUTSIDE web root).
// Token + expiry in /var/www/backup_token.txt (line 1 = token, line 2 = expiry epoch).
$tokenFile = '/var/www/backup_token.txt';
$file      = '/var/www/singopkoelsch-backup-2026-06-12.tar.gz';

$lines    = is_file($tokenFile) ? file($tokenFile, FILE_IGNORE_NEW_LINES) : [];
$expected = $lines[0] ?? '';
$expiry   = (int)($lines[1] ?? 0);
$given    = $_GET['t'] ?? '';

if ($expected === '' || !is_file($file) || !is_string($given) || !hash_equals($expected, $given)) {
    http_response_code(404);
    exit('Not found');
}
if ($expiry > 0 && time() > $expiry) {
    http_response_code(410);
    exit('Link expired.');
}

while (ob_get_level()) { ob_end_clean(); }
set_time_limit(0);
header('Content-Type: application/gzip');
header('Content-Disposition: attachment; filename="singopkoelsch-backup-2026-06-12.tar.gz"');
header('Content-Length: ' . filesize($file));
header('Cache-Control: no-store, private, max-age=0');
header('X-Accel-Buffering: no');
$fp = fopen($file, 'rb');
fpassthru($fp);
fclose($fp);
exit;
