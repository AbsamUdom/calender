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
$role = strtolower($user['role'] ?? 'user');

if (!in_array($role, ['production', 'admin', 'super'], true)) {
    header('Location: index.php');
    exit;
}

$flashes = flash_consume();
$displayName = $user['name'] ?? $user['email'] ?? 'User';
$pageTitle = 'Printing Queue';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_abort();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'update_print_task') {
            $taskId = (int)($_POST['task_id'] ?? 0);
            $status = (string)($_POST['task_status'] ?? '');
            $notes = trim((string)($_POST['task_notes'] ?? ''));

            if ($taskId <= 0) {
                throw new Exception('Invalid print task');
            }

            $allowed = ['received', 'in_progress', 'printed', 'delivered'];
            if (!in_array($status, $allowed, true)) {
                throw new Exception('Invalid print task status');
            }

            $upd = $db->prepare('UPDATE print_tasks SET status = ?, notes = ?, updated_by = ?, updated_at = NOW() WHERE id = ?');
            $upd->execute([$status, ($notes !== '' ? $notes : null), (int)($user['id'] ?? 0), $taskId]);
            if (function_exists('db_log_activity')) {
                db_log_activity((int)($user['id'] ?? 0), 'print_task_update', json_encode(['task_id' => $taskId, 'status' => $status]));
            }
            flash_add('success', 'Print task updated.');
        }
    } catch (Throwable $ex) {
        flash_add('error', $ex->getMessage());
    }

    header('Location: printing_queue.php');
    exit;
}

$printTasks = [];
try {
    $stmt = $db->query("SELECT pt.*, e.name AS event_name, e.client AS event_client, e.date AS event_date FROM print_tasks pt JOIN events e ON pt.event_id = e.id ORDER BY FIELD(pt.status,'received','in_progress','printed','delivered'), COALESCE(e.date, pt.created_at) ASC, pt.id ASC");
    $printTasks = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
} catch (Throwable $e) {
    $printTasks = [];
}

$receivedPrintCount = count(array_filter($printTasks, fn($t) => (string)($t['status'] ?? 'received') === 'received'));
$inProgressPrintCount = count(array_filter($printTasks, fn($t) => (string)($t['status'] ?? 'received') === 'in_progress'));
$printedPrintCount = count(array_filter($printTasks, fn($t) => (string)($t['status'] ?? 'received') === 'printed'));
$deliveredPrintCount = count(array_filter($printTasks, fn($t) => (string)($t['status'] ?? 'received') === 'delivered'));
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
    <?php $activePage = 'printing_queue.php'; include __DIR__ . '/sidebar.php'; ?>

    <main class="main-content">
        <div class="top-bar">
            <div class="page-title">
                <h1><?php echo htmlspecialchars($pageTitle); ?></h1>
                <p>Manage printing items received from Sales and Graphics</p>
            </div>
            <div class="user-menu">
                <div class="date-display"><i class="far fa-calendar-alt"></i> <?php echo date('l, F j, Y'); ?></div>
                <div class="user-profile" id="printingQueueUserProfile">
                    <div class="avatar"><?php echo htmlspecialchars(strtoupper(mb_substr($displayName, 0, 1))); ?></div>
                    <div>
                        <div style="font-weight:600;"><?php echo htmlspecialchars($displayName); ?></div>
                        <div style="font-size:0.75rem;color:#64748b;"><?php echo htmlspecialchars(get_role_label($user['role'] ?? 'user')); ?></div>
                    </div>
                    <i class="fas fa-chevron-down" style="color:#64748b;"></i>
                    <div class="profile-dropdown" id="printingQueueHeaderProfileDropdown">
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

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-2xl p-5 text-white shadow-lg">
                <div class="flex justify-between items-start">
                    <div>
                        <p class="text-sm font-medium text-blue-100">Received</p>
                        <h3 class="text-2xl font-bold mt-1"><?php echo number_format($receivedPrintCount); ?></h3>
                    </div>
                    <div class="p-3 bg-white bg-opacity-20 rounded-xl"><i class="fas fa-inbox text-xl"></i></div>
                </div>
            </div>
            <div class="bg-gradient-to-br from-amber-500 to-amber-600 rounded-2xl p-5 text-white shadow-lg">
                <div class="flex justify-between items-start">
                    <div>
                        <p class="text-sm font-medium text-amber-100">In Progress</p>
                        <h3 class="text-2xl font-bold mt-1"><?php echo number_format($inProgressPrintCount); ?></h3>
                    </div>
                    <div class="p-3 bg-white bg-opacity-20 rounded-xl"><i class="fas fa-spinner text-xl"></i></div>
                </div>
            </div>
            <div class="bg-gradient-to-br from-indigo-500 to-indigo-600 rounded-2xl p-5 text-white shadow-lg">
                <div class="flex justify-between items-start">
                    <div>
                        <p class="text-sm font-medium text-indigo-100">Printed</p>
                        <h3 class="text-2xl font-bold mt-1"><?php echo number_format($printedPrintCount); ?></h3>
                    </div>
                    <div class="p-3 bg-white bg-opacity-20 rounded-xl"><i class="fas fa-print text-xl"></i></div>
                </div>
            </div>
            <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-2xl p-5 text-white shadow-lg">
                <div class="flex justify-between items-start">
                    <div>
                        <p class="text-sm font-medium text-green-100">Delivered</p>
                        <h3 class="text-2xl font-bold mt-1"><?php echo number_format($deliveredPrintCount); ?></h3>
                    </div>
                    <div class="p-3 bg-white bg-opacity-20 rounded-xl"><i class="fas fa-truck text-xl"></i></div>
                </div>
            </div>
        </div>

        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title">Printing Items (Tasks)</h2>
                <div class="section-actions" style="gap:0.5rem;">
                    <input id="printingQueueSearch" type="text" class="form-input" style="max-width:260px;" placeholder="Search task...">
                </div>
            </div>

            <div class="table-container">
                <table class="data-table" id="printingQueueTable">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Event</th>
                        <th>Title</th>
                        <th>Qty</th>
                        <th>Source</th>
                        <th>Status</th>
                        <th>Notes</th>
                        <th>Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php $tn = 1; foreach ($printTasks as $t): ?>
                        <tr>
                            <td><?php echo (int)$tn; ?></td>
                            <td><?php echo htmlspecialchars((string)($t['event_name'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string)($t['title'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string)($t['qty'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string)($t['source'] ?? '')); ?></td>
                            <td>
                                <?php
                                    $ts = (string)($t['status'] ?? 'received');
                                    $badge = 'background:#e2e8f0;color:#334155;';
                                    if ($ts === 'received') $badge = 'background:#dbeafe;color:#1e40af;';
                                    elseif ($ts === 'in_progress') $badge = 'background:#fef3c7;color:#92400e;';
                                    elseif ($ts === 'printed') $badge = 'background:#e0e7ff;color:#3730a3;';
                                    elseif ($ts === 'delivered') $badge = 'background:#d1fae5;color:#065f46;';
                                ?>
                                <span style="<?php echo $badge; ?>padding:0.2rem 0.6rem;border-radius:9999px;font-size:0.75rem;font-weight:600;">
                                    <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $ts))); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars((string)($t['notes'] ?? '')); ?></td>
                            <td>
                                <form method="post" class="grid grid-cols-1 gap-2" style="min-width: 220px;">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                    <input type="hidden" name="action" value="update_print_task">
                                    <input type="hidden" name="task_id" value="<?php echo (int)$t['id']; ?>">
                                    <select name="task_status" class="form-input">
                                        <option value="received" <?php echo $ts === 'received' ? 'selected' : ''; ?>>Received</option>
                                        <option value="in_progress" <?php echo $ts === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                        <option value="printed" <?php echo $ts === 'printed' ? 'selected' : ''; ?>>Printed</option>
                                        <option value="delivered" <?php echo $ts === 'delivered' ? 'selected' : ''; ?>>Delivered</option>
                                    </select>
                                    <input type="text" name="task_notes" class="form-input" placeholder="Notes (optional)" value="<?php echo htmlspecialchars((string)($t['notes'] ?? '')); ?>">
                                    <button type="submit" class="btn btn-primary" style="width:100%;">Update</button>
                                </form>
                            </td>
                        </tr>
                    <?php $tn++; endforeach; ?>
                    <?php if (!$printTasks): ?>
                        <tr><td colspan="8" style="text-align:center;color:#64748b;padding:2rem;">No print tasks</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var profile = document.getElementById('printingQueueUserProfile');
    var dropdown = document.getElementById('printingQueueHeaderProfileDropdown');
    if (profile && dropdown) {
        profile.addEventListener('click', function(e){
            e.stopPropagation();
            dropdown.classList.toggle('show');
        });
        document.addEventListener('click', function(){ dropdown.classList.remove('show'); });
    }

    // Simple search filter
    var searchInput = document.getElementById('printingQueueSearch');
    var table = document.getElementById('printingQueueTable');
    if (searchInput && table) {
        searchInput.addEventListener('input', function() {
            var term = this.value.toLowerCase();
            var rows = table.querySelectorAll('tbody tr');
            rows.forEach(function(row) {
                row.style.display = row.textContent.toLowerCase().indexOf(term) > -1 ? '' : 'none';
            });
        });
    }
});
</script>
</body>
</html>
