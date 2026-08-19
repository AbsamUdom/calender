<?php
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';

if (isset($_GET['logout'])) {
    logout_user();
    header('Location: login.php');
    exit;
}
require_login();

$path = __DIR__ . DIRECTORY_SEPARATOR . 'data.sqlite';
if (!file_exists($path)) {
    http_response_code(404);
    echo 'Database file not found.';
    exit;
}
if (!is_readable($path)) {
    http_response_code(500);
    echo 'Database file is not readable.';
    exit;
}

$filename = 'event-database-' . date('Ymd-His') . '.sqlite';
header('Content-Type: application/x-sqlite3');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$fp = fopen($path, 'rb');
if ($fp === false) {
    http_response_code(500);
    echo 'Failed to open database file.';
    exit;
}
while (!feof($fp)) {
    echo fread($fp, 8192);
    @ob_flush();
    flush();
}
fclose($fp);
exit;
