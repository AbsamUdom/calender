<?php
require __DIR__ . '/db.php';
$db = get_db();
$token = $_GET['token'] ?? '';
$auth = $_GET['auth'] ?? '';
if ($auth !== '') {
    $stmt = $db->prepare('SELECT * FROM attendees WHERE auth_token = ?');
    $stmt->execute([$auth]);
    $mode = 'auth';
} else {
    // legacy token or invite token fallback
    $stmt = $db->prepare('SELECT * FROM attendees WHERE token = ? OR invite_token = ?');
    $stmt->execute([$token, $token]);
    $mode = 'token';
}
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    http_response_code(404);
    echo 'Not found';
    exit;
}
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($mode === 'auth') {
        // Authorized gate scan requires event secret
        $secret = trim($_POST['secret'] ?? '');
        $eventSecret = '';
        if (!empty($row['event_id'])) {
            $ev = $db->prepare('SELECT secret_code FROM events WHERE id = ?');
            $ev->execute([(int)$row['event_id']]);
            $eventSecret = (string)($ev->fetchColumn() ?: '');
        }
        if ($eventSecret === '') {
            $msg = 'Event secret not set; cannot confirm.';
        } elseif ($secret === '' || hash_equals($eventSecret, $secret) === false) {
            $msg = 'Invalid secret code.';
        } else {
            if ((int)$row['attended'] === 1) {
                // Already confirmed – still redirect to dashboard/login flow
                header('Location: login.php?next=index.php&confirm=already');
                exit;
            } else {
                $upd = $db->prepare('UPDATE attendees SET attended = 1, attended_at = CURRENT_TIMESTAMP WHERE id = ?');
                $upd->execute([$row['id']]);
                $stmt = $db->prepare('SELECT * FROM attendees WHERE id = ?');
                $stmt->execute([$row['id']]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                // Redirect to login with next param so user lands on dashboard after auth
                header('Location: login.php?next=index.php&confirm=success');
                exit;
            }
        }
    } else {
        // Invite flow: no secret required — mark RSVP only
        if ((int)$row['rsvp_confirmed'] === 1) {
            // Already RSVP’d – still redirect to dashboard/login flow
            header('Location: login.php?next=index.php&rsvp=already');
            exit;
        } else {
            $upd = $db->prepare('UPDATE attendees SET rsvp_confirmed = 1, rsvp_at = CURRENT_TIMESTAMP WHERE id = ?');
            $upd->execute([$row['id']]);
            $stmt = $db->prepare('SELECT * FROM attendees WHERE id = ?');
            $stmt->execute([$row['id']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            // Redirect to login with next param so user lands on dashboard after auth
            header('Location: login.php?next=index.php&rsvp=success');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Confirm Attendance</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="container small">
<h1>Confirm Attendance</h1>
<div class="card-info">
<div><strong>Name:</strong> <?php echo htmlspecialchars($row['name']); ?></div>
<div><strong>Email:</strong> <?php echo htmlspecialchars($row['email']); ?></div>
<div><strong>Phone:</strong> <?php echo htmlspecialchars($row['phone']); ?></div>
<div><strong>RSVP:</strong> <?php echo ((int)$row['rsvp_confirmed']) ? 'Confirmed' : 'Not confirmed'; ?></div>
<?php if (!empty($row['rsvp_at'])): ?><div><strong>RSVP Time:</strong> <?php echo htmlspecialchars($row['rsvp_at']); ?></div><?php endif; ?>
<div><strong>Attendance:</strong> <?php echo ((int)$row['attended']) ? 'Attended' : 'Not attended'; ?></div>
<?php if ($row['attended_at']): ?><div><strong>Time:</strong> <?php echo htmlspecialchars($row['attended_at']); ?></div><?php endif; ?>
</div>
<?php if ($msg): ?><div class="notice"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>
<?php if ((int)$row['attended'] === 0): ?>
<form method="post">
  <?php if ($mode === 'auth'): ?>
    <label>Event Secret Code
      <input type="password" name="secret" placeholder="Enter event secret" required>
    </label>
  <?php endif; ?>
  <button type="submit" class="primary">Confirm Attendance</button>
</form>
<?php else: ?>
<p class="notice">Already confirmed.</p>
<?php endif; ?>
<p><a class="btn" href="index.php">Back to Admin</a></p>
</div>
</body>
</html>
