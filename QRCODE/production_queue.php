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
$pageTitle = 'Production Queue';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_abort();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'update_production_status') {
            $eventId = (int)($_POST['event_id'] ?? 0);
            $status = (string)($_POST['production_status'] ?? '');
            $comment = trim((string)($_POST['production_comment'] ?? ''));

            if ($eventId <= 0) {
                throw new Exception('Invalid event');
            }

            $ro = $db->prepare("SELECT id FROM release_orders WHERE event_id = ? AND status = 'approved' ORDER BY COALESCE(reviewed_at, submitted_at, created_at) DESC, id DESC LIMIT 1");
            $ro->execute([$eventId]);
            if (!(int)($ro->fetchColumn() ?? 0)) {
                throw new Exception('Production updates require an approved Release Order for this event');
            }

            $allowed = ['queued', 'printing', 'in_production', 'ready', 'delivered'];
            if (!in_array($status, $allowed, true)) {
                throw new Exception('Invalid production status');
            }

            $upd = $db->prepare('UPDATE events SET production_status=?, production_updated_by=?, production_updated_at=NOW(), production_comment=? WHERE id=?');
            $upd->execute([$status, (int)($user['id'] ?? 0), ($comment !== '' ? $comment : null), $eventId]);
            if (function_exists('db_log_activity')) {
                db_log_activity((int)($user['id'] ?? 0), 'production_status_update', json_encode(['event_id' => $eventId, 'production_status' => $status]));
            }
            flash_add('success', 'Production status updated.');
        }
    } catch (Throwable $ex) {
        flash_add('error', $ex->getMessage());
    }

    header('Location: production_queue.php');
    exit;
}

$events = [];
try {
    $stmt = $db->query("SELECT id, name, date, end_date, client, location, mockup_file, checklist_file, quote_file, status, production_status, production_updated_at, production_comment, created_at FROM events ORDER BY COALESCE(date, created_at) ASC, id ASC");
    $events = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable $e) {
    $events = [];
}

$withMockup = array_values(array_filter($events, fn($e) => !empty($e['mockup_file'])));

$queueCount = count(array_filter($withMockup, fn($e) => (string)($e['production_status'] ?? 'queued') === 'queued'));
$printingCount = count(array_filter($withMockup, fn($e) => (string)($e['production_status'] ?? 'queued') === 'printing'));
$inProdCount = count(array_filter($withMockup, fn($e) => (string)($e['production_status'] ?? 'queued') === 'in_production'));
$readyCount = count(array_filter($withMockup, fn($e) => (string)($e['production_status'] ?? 'queued') === 'ready'));
$deliveredCount = count(array_filter($withMockup, fn($e) => (string)($e['production_status'] ?? 'queued') === 'delivered'));
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
    <?php $activePage = 'production_queue.php'; include __DIR__ . '/sidebar.php'; ?>

    <main class="main-content">
        <div class="top-bar">
            <div class="page-title">
                <h1><?php echo htmlspecialchars($pageTitle); ?></h1>
                <p>Approved mockups and production status updates</p>
            </div>
            <div class="user-menu">
                <div class="date-display"><i class="far fa-calendar-alt"></i> <?php echo date('l, F j, Y'); ?></div>
                <div class="user-profile" id="productionQueueUserProfile">
                    <div class="avatar"><?php echo htmlspecialchars(strtoupper(mb_substr($displayName, 0, 1))); ?></div>
                    <div>
                        <div style="font-weight:600;"><?php echo htmlspecialchars($displayName); ?></div>
                        <div style="font-size:0.75rem;color:#64748b;"><?php echo htmlspecialchars(get_role_label($user['role'] ?? 'user')); ?></div>
                    </div>
                    <i class="fas fa-chevron-down" style="color:#64748b;"></i>
                    <div class="profile-dropdown" id="productionQueueHeaderProfileDropdown">
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

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
            <div class="bg-gradient-to-br from-slate-500 to-slate-600 rounded-2xl p-5 text-white shadow-lg">
                <div class="flex justify-between items-start">
                    <div>
                        <p class="text-sm font-medium text-slate-100">Queued</p>
                        <h3 class="text-2xl font-bold mt-1"><?php echo number_format($queueCount); ?></h3>
                    </div>
                    <div class="p-3 bg-white bg-opacity-20 rounded-xl"><i class="fas fa-clock text-xl"></i></div>
                </div>
            </div>
            <div class="bg-gradient-to-br from-amber-500 to-amber-600 rounded-2xl p-5 text-white shadow-lg">
                <div class="flex justify-between items-start">
                    <div>
                        <p class="text-sm font-medium text-amber-100">Printing</p>
                        <h3 class="text-2xl font-bold mt-1"><?php echo number_format($printingCount); ?></h3>
                    </div>
                    <div class="p-3 bg-white bg-opacity-20 rounded-xl"><i class="fas fa-print text-xl"></i></div>
                </div>
            </div>
            <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-2xl p-5 text-white shadow-lg">
                <div class="flex justify-between items-start">
                    <div>
                        <p class="text-sm font-medium text-blue-100">In Production</p>
                        <h3 class="text-2xl font-bold mt-1"><?php echo number_format($inProdCount); ?></h3>
                    </div>
                    <div class="p-3 bg-white bg-opacity-20 rounded-xl"><i class="fas fa-industry text-xl"></i></div>
                </div>
            </div>
            <div class="bg-gradient-to-br from-indigo-500 to-indigo-600 rounded-2xl p-5 text-white shadow-lg">
                <div class="flex justify-between items-start">
                    <div>
                        <p class="text-sm font-medium text-indigo-100">Ready</p>
                        <h3 class="text-2xl font-bold mt-1"><?php echo number_format($readyCount); ?></h3>
                    </div>
                    <div class="p-3 bg-white bg-opacity-20 rounded-xl"><i class="fas fa-box text-xl"></i></div>
                </div>
            </div>
            <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-2xl p-5 text-white shadow-lg">
                <div class="flex justify-between items-start">
                    <div>
                        <p class="text-sm font-medium text-green-100">Delivered</p>
                        <h3 class="text-2xl font-bold mt-1"><?php echo number_format($deliveredCount); ?></h3>
                    </div>
                    <div class="p-3 bg-white bg-opacity-20 rounded-xl"><i class="fas fa-truck text-xl"></i></div>
                </div>
            </div>
        </div>

        <div class="content-section" id="production-queue">
            <div class="section-header">
                <h2 class="section-title">Production Queue (Approved Mockups)</h2>
            </div>

            <div class="table-container">
                <table class="data-table">
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Event</th>
                        <th>Date</th>
                        <th>Client</th>
                        <th>Event Status</th>
                        <th>Production</th>
                        <th>Mockup</th>
                        <th>Checklist</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($withMockup as $e): ?>
                        <tr>
                            <td><?php echo (int)$e['id']; ?></td>
                            <td><?php echo htmlspecialchars($e['name'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($e['date'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($e['client'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($e['status'] ?? ''); ?></td>
                            <td>
                                <form method="post" class="grid grid-cols-1 gap-2" style="min-width: 220px;">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                    <input type="hidden" name="action" value="update_production_status">
                                    <input type="hidden" name="event_id" value="<?php echo (int)$e['id']; ?>">
                                    <?php $ps = (string)($e['production_status'] ?? 'queued'); ?>
                                    <select name="production_status" class="form-input">
                                        <option value="queued" <?php echo $ps === 'queued' ? 'selected' : ''; ?>>Queued</option>
                                        <option value="printing" <?php echo $ps === 'printing' ? 'selected' : ''; ?>>Printing</option>
                                        <option value="in_production" <?php echo $ps === 'in_production' ? 'selected' : ''; ?>>In Production</option>
                                        <option value="ready" <?php echo $ps === 'ready' ? 'selected' : ''; ?>>Ready</option>
                                        <option value="delivered" <?php echo $ps === 'delivered' ? 'selected' : ''; ?>>Delivered</option>
                                    </select>
                                    <input type="text" name="production_comment" class="form-input" placeholder="Comment (optional)" value="<?php echo htmlspecialchars((string)($e['production_comment'] ?? '')); ?>">
                                    <button type="submit" class="btn btn-primary" style="width:100%;">Update</button>
                                </form>
                            </td>
                            <td><a class="link-highlight" target="_blank" href="<?php echo htmlspecialchars($e['mockup_file']); ?>">View</a></td>
                            <td>
                                <?php if (!empty($e['checklist_file'])): ?>
                                    <a class="link-highlight" target="_blank" href="<?php echo htmlspecialchars($e['checklist_file']); ?>">View</a>
                                <?php else: ?>
                                    <span class="text-slate-400">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$withMockup): ?>
                        <tr><td colspan="8" style="text-align:center;color:#64748b;padding:2rem;">No events with approved mockups</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    if (window.__profileDropdownBound) return;
    var profile = document.getElementById('productionQueueUserProfile');
    var dropdown = document.getElementById('productionQueueHeaderProfileDropdown');
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
