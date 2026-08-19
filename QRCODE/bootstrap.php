<?php
// Centralized error logging and runtime configuration
// Production-safe: do not display errors to users; log them instead

// Ensure log directory exists
$logDir = __DIR__ . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
$logFile = $logDir . DIRECTORY_SEPARATOR . 'php-error.log';

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', $logFile);

// Timezone to avoid warnings
if (!ini_get('date.timezone')) {
    date_default_timezone_set('UTC');
}

// Optional: custom error handler to add more context
set_error_handler(function ($severity, $message, $file, $line) use ($logFile) {
    // Respect @ operator
    if (!(error_reporting() & $severity)) {
        return false;
    }
    $entry = sprintf("[%s] PHP %s: %s in %s on line %d\n",
        gmdate('Y-m-d H:i:s T'),
        $severity,
        $message,
        $file,
        $line
    );
    error_log($entry);
    return false; // Fall back to default handler as well
});

set_exception_handler(function ($ex) use ($logFile) {
    $entry = sprintf("[%s] Uncaught %s: %s in %s on line %d\nStack trace:\n%s\n",
        gmdate('Y-m-d H:i:s T'),
        get_class($ex),
        $ex->getMessage(),
        $ex->getFile(),
        $ex->getLine(),
        $ex->getTraceAsString()
    );
    error_log($entry);
});

// Helper to quickly append a custom line to the log
if (!function_exists('log_line')) {
    function log_line(string $msg): void {
        error_log(sprintf('[%s] %s', gmdate('Y-m-d H:i:s T'), $msg));
    }
}

// Helper similar to dd() but logs instead of outputting
if (!function_exists('ld')) {
    function ld(...$vars): void {
        foreach ($vars as $v) {
            error_log(print_r($v, true));
        }
    }
}
