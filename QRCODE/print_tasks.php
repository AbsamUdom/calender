<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';

if (isset($_GET['logout'])) {
    logout_user();
    header('Location: login.php');
    exit;
}

require_login();
$db = get_db();
$user = current_user();
$role = strtolower((string)($user['role'] ?? 'user'));

$roleNorm = strtolower(trim((string)($user['role'] ?? '')));
$roleNorm = str_replace(['_', '-'], ' ', $roleNorm);
$roleNorm = preg_replace('/\s+/', ' ', $roleNorm);

if (!in_array($roleNorm, ['sales', 'graphic', 'graphics', 'graphic designer', 'admin', 'super'], true)) {
    header('Location: index.php');
    exit;
}

$displayName = $user['name'] ?? $user['email'] ?? 'User';
$pageTitle = 'Print Tasks';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_abort();
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'create_print_task') {
            $eventId = (int)($_POST['event_id'] ?? 0);
            $title = trim((string)($_POST['title'] ?? ''));
            $qty = (int)($_POST['qty'] ?? 0);
            $notes = trim((string)($_POST['notes'] ?? ''));
            $taskType = trim((string)($_POST['task_type'] ?? ''));

            if ($eventId <= 0 || $title === '') {
                throw new Exception('Event and title are required');
            }

            $source = in_array($roleNorm, ['graphic', 'graphics', 'graphic designer'], true) ? 'graphic' : 'sales';
            $ins = $db->prepare("INSERT INTO print_tasks (event_id, task_type, source, title, qty, status, notes, created_by) VALUES (?, ?, ?, ?, ?, 'received', ?, ?)");
            $ins->execute([
                $eventId,
                ($taskType !== '' ? $taskType : null),
                $source,
                $title,
                ($qty > 0 ? $qty : null),
                ($notes !== '' ? $notes : null),
                (int)($user['id'] ?? 0)
            ]);

            flash_add('success', 'Print task sent to Production.');
        }
    } catch (Throwable $e) {
        flash_add('error', $e->getMessage());
    }

    header('Location: print_tasks.php');
    exit;
}

$flashes = flash_consume();

$events = [];
try {
    $stmt = $db->query("SELECT id, name, date, created_at FROM events ORDER BY COALESCE(date, created_at) DESC, id DESC LIMIT 200");
    $events = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
} catch (Throwable $e) {
    $events = [];
}

$myRecentTasks = [];
try {
    $stmt = $db->prepare("SELECT pt.*, e.name AS event_name, e.client AS event_client, e.date AS event_date FROM print_tasks pt JOIN events e ON pt.event_id = e.id WHERE pt.created_by = ? ORDER BY pt.created_at DESC, pt.id DESC LIMIT 20");
    $stmt->execute([(int)($user['id'] ?? 0)]);
    $myRecentTasks = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $myRecentTasks = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($pageTitle); ?> | Event Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="dashboard-container">
    <?php $activePage = 'print_tasks.php'; include __DIR__ . '/sidebar.php'; ?>

    <main class="main-content">
        <div class="top-bar">
            <div class="page-title">
                <h1><?php echo htmlspecialchars($pageTitle); ?></h1>
                <p>Create printing items for Production</p>
            </div>
            <div class="user-menu">
                <div class="date-display"><i class="far fa-calendar-alt"></i> <?php echo date('l, F j, Y'); ?></div>
                <div class="user-profile" id="printTasksUserProfile">
                    <div class="avatar"><?php echo htmlspecialchars(strtoupper(mb_substr($displayName, 0, 1))); ?></div>
                    <div>
                        <div style="font-weight:600;"><?php echo htmlspecialchars($displayName); ?></div>
                        <div style="font-size:0.75rem;color:#64748b;"><?php echo htmlspecialchars(get_role_label($user['role'] ?? 'user')); ?></div>
                    </div>
                    <i class="fas fa-chevron-down" style="color:#64748b;"></i>
                    <div class="profile-dropdown" id="printTasksHeaderProfileDropdown">
                        <a href="profile.php"><i class="fas fa-user mr-2"></i> My Profile</a>
                        <a href="?logout=1"><i class="fas fa-sign-out-alt mr-2"></i> Logout</a>
                    </div>
                </div>
            </div>
        </div>

        <?php foreach ($flashes as $f): ?>
            <div class="alert <?php echo $f['type'] === 'success' ? 'alert-success' : 'alert-error'; ?>">
                <?php echo htmlspecialchars($f['message']); ?>
            </div>
        <?php endforeach; ?>

        <div style="min-width:0;">
                <div class="content-section">
                    <div class="section-header">
                        <h2 class="section-title">New Print Task</h2>
                    </div>
                    <form method="post" class="space-y-4" style="max-width: 720px;">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                        <input type="hidden" name="action" value="create_print_task">

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Event *</label>
                            <select name="event_id" required class="form-select w-full">
                                <option value="">Select event</option>
                                <?php foreach ($events as $ev): ?>
                                    <option value="<?php echo (int)$ev['id']; ?>"><?php echo htmlspecialchars((string)($ev['name'] ?? '')); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Title *</label>
                            <input type="text" name="title" required class="form-input w-full" placeholder="e.g. Banner, Stickers, Brochure">
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Qty</label>
                                <input type="number" name="qty" min="0" class="form-input w-full" placeholder="">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                                <input type="text" name="task_type" class="form-input w-full" placeholder="e.g. banner">
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                            <textarea name="notes" rows="3" class="form-input w-full" placeholder="Size, material, finishing, deadline..."></textarea>
                        </div>

                        <div>
                            <button type="submit" class="btn btn-primary">Send to Production</button>
                        </div>
                    </form>
                </div>

                <div class="content-section">
                    <div class="section-header">
                        <h2 class="section-title">My Recent Print Tasks</h2>
                    </div>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                            <tr>
                                <th>#</th>
                                <th>Event</th>
                                <th>Title</th>
                                <th>Qty</th>
                                <th>Status</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php $n = 1; foreach ($myRecentTasks as $t): ?>
                                <tr>
                                    <td><?php echo (int)$n; ?></td>
                                    <td><?php echo htmlspecialchars((string)($t['event_name'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars((string)($t['title'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars((string)($t['qty'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars((string)($t['status'] ?? '')); ?></td>
                                </tr>
                            <?php $n++; endforeach; ?>
                            <?php if (!$myRecentTasks): ?>
                                <tr><td colspan="5" style="text-align:center;color:#64748b;padding:2rem;">No print tasks yet</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div style="margin-top: 0.75rem;">
                        <a href="printing_queue.php" class="link-highlight">View Printing Queue</a>
                    </div>
                </div>
        </div>

    </main>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    if (window.__profileDropdownBound) return;
    var profile = document.getElementById('printTasksUserProfile');
    var dropdown = document.getElementById('printTasksHeaderProfileDropdown');
    if (!profile || !dropdown) return;

    function closeDd(){ dropdown.classList.remove('show'); }

    profile.addEventListener('click', function(e){
        e.stopPropagation();
        dropdown.classList.toggle('show');
    });

    document.addEventListener('click', function(){ closeDd(); });
});
</script>
</body>
</html>
