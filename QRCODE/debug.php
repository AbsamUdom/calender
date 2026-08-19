<?php
// Enable full error reporting and logging
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
ini_set('log_errors', '1');
$logFile = __DIR__ . DIRECTORY_SEPARATOR . 'php-error.log';
ini_set('error_log', $logFile);
// Set a default timezone to avoid warnings
if (!ini_get('date.timezone')) {
    date_default_timezone_set('UTC');
}
// Simple helper to quickly dump variables during debugging
if (!function_exists('dd')) {
    function dd(...$vars) {
        foreach ($vars as $v) {
            echo '<pre style="white-space:pre-wrap;word-wrap:break-word;background:#111;color:#0f0;padding:10px;border-radius:6px;">';
            echo htmlspecialchars(print_r($v, true));
            echo '</pre>';
        }
        exit;
    }
}
