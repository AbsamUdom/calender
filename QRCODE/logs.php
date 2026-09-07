<?php
require __DIR__ . '/auth.php';
require_login();
require __DIR__ . '/bootstrap.php';

$logFile = __DIR__ . '/storage/logs/php-error.log';
$lines = [];
if (file_exists($logFile)) {
    $content = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $lines = array_slice($content, -500); // show last 500 lines max
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Error Logs</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif; background:#0b1020; color:#e2e8f0; margin:0; }
    .container { max-width: 1100px; margin: 0 auto; padding: 1rem; }
    .card { background:#0f172a; border:1px solid #1e293b; border-radius:10px; padding:1rem; }
    .header { display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; }
    pre { white-space: pre-wrap; word-wrap: break-word; background:#0b1020; color:#94a3b8; padding:1rem; border-radius:8px; max-height:70vh; overflow:auto; }
    a.btn { display:inline-flex; align-items:center; gap:.5rem; background:#1e293b; color:#e2e8f0; text-decoration:none; padding:.5rem .75rem; border-radius:8px; }
    a.btn:hover { background:#334155; }
  </style>
</head>
<body>
  <div class="container">
    <div class="header">
      <h1><i class="fas fa-bug"></i> PHP Error Logs</h1>
      <div>
        <a class="btn" href="?refresh=1"><i class="fas fa-rotate"></i> Refresh</a>
        <a class="btn" href="index.php"><i class="fas fa-home"></i> Dashboard</a>
      </div>
    </div>
    <div class="card">
      <?php if (!file_exists($logFile)): ?>
        <p>Log file not found at <code><?php echo htmlspecialchars($logFile); ?></code>. It will be created on first error.</p>
      <?php else: ?>
        <pre><?php echo htmlspecialchars(implode("\n", $lines)); ?></pre>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
