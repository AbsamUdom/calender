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
$pageTitle = ($role === 'production') ? 'Production Dashboard' : 'Production Overview';

// Handle Production actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_abort();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create_purchase') {
            throw new Exception('Please use Purchase Requests page to request materials.');
        }

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
            db_log_activity((int)($user['id'] ?? 0), 'production_status_update', json_encode(['event_id' => $eventId, 'production_status' => $status]));
            flash_add('success', 'Production status updated.');
            header('Location: production_dashboard.php');
            exit;
        }
    } catch (Throwable $ex) {
        flash_add('error', $ex->getMessage());
        header('Location: production_dashboard.php');
        exit;
    }
}

$events = [];
try {
    $stmt = $db->query("SELECT id, name, date, end_date, client, location, mockup_file, checklist_file, quote_file, status, production_status, production_updated_at, production_comment, created_at FROM events ORDER BY COALESCE(date, created_at) ASC, id ASC");
    $events = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable $e) {
    $events = [];
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

$withMockup = array_values(array_filter($events, fn($e) => !empty($e['mockup_file'])));
$withoutMockup = array_values(array_filter($events, fn($e) => empty($e['mockup_file'])));

$myPurchases = [];
try {
    $stmt = $db->prepare("SELECT pr.*, e.name AS event_name, e.client AS event_client FROM purchase_requests pr LEFT JOIN events e ON pr.event_id = e.id WHERE pr.requested_by = ? OR ? IN ('admin','super') ORDER BY pr.created_at DESC, pr.id DESC");
    $stmt->execute([(int)($user['id'] ?? 0), $role]);
    $myPurchases = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $myPurchases = [];
}

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
    <?php $activePage = 'production_dashboard.php'; include __DIR__ . '/sidebar.php'; ?>

    <main class="main-content">
        <div class="top-bar">
            <div class="page-title">
                <h1><?php echo htmlspecialchars($pageTitle); ?></h1>
                <p>Mockups and production visibility</p>
            </div>
            <div class="user-menu">
                <div class="date-display"><i class="far fa-calendar-alt"></i> <?php echo date('l, F j, Y'); ?></div>
                <div class="user-profile" id="productionUserProfile">
                    <div class="avatar"><?php echo htmlspecialchars(strtoupper(mb_substr($displayName, 0, 1))); ?></div>
                    <div>
                        <div style="font-weight:600;"><?php echo htmlspecialchars($displayName); ?></div>
                        <div style="font-size:0.75rem;color:#64748b;"><?php echo htmlspecialchars(get_role_label($user['role'] ?? 'user')); ?></div>
                    </div>
                    <i class="fas fa-chevron-down" style="color:#64748b;"></i>
                    <div class="profile-dropdown" id="productionHeaderProfileDropdown">
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
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-5 mb-6">
                    <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-2xl p-5 text-white shadow-lg hover:shadow-xl transition-shadow duration-300">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="text-sm font-medium text-blue-100">Events</p>
                                <h3 class="text-2xl font-bold mt-1"><?php echo number_format(count($events)); ?></h3>
                                <div class="flex items-center mt-2">
                                    <span class="text-xs bg-white bg-opacity-20 px-2 py-1 rounded-full">All events</span>
                                </div>
                            </div>
                            <div class="p-3 bg-white bg-opacity-20 rounded-xl">
                                <i class="fas fa-calendar-alt text-xl"></i>
                            </div>
                        </div>
                        <div class="mt-4 pt-3 border-t border-blue-400 border-opacity-30">
                            <a href="events.php?mode=list" class="text-xs font-medium text-white hover:underline flex items-center">
                                View events <i class="fas fa-arrow-right ml-1 text-xs"></i>
                            </a>
                        </div>
                    </div>

                    <div class="bg-gradient-to-br from-sky-500 to-sky-600 rounded-2xl p-5 text-white shadow-lg hover:shadow-xl transition-shadow duration-300">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="text-sm font-medium text-sky-100">Print Tasks</p>
                                <h3 class="text-2xl font-bold mt-1"><?php echo number_format($receivedPrintCount + $inProgressPrintCount + $printedPrintCount); ?></h3>
                                <div class="flex items-center mt-2">
                                    <span class="text-xs bg-white bg-opacity-20 px-2 py-1 rounded-full"><?php echo (int)$receivedPrintCount; ?> received</span>
                                </div>
                            </div>
                            <div class="p-3 bg-white bg-opacity-20 rounded-xl">
                                <i class="fas fa-print text-xl"></i>
                            </div>
                        </div>
                        <div class="mt-4 pt-3 border-t border-sky-400 border-opacity-30">
                            <a href="production_queue.php#print-tasks" class="text-xs font-medium text-white hover:underline flex items-center">
                                View tasks <i class="fas fa-arrow-right ml-1 text-xs"></i>
                            </a>
                        </div>
                    </div>

                    <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-2xl p-5 text-white shadow-lg hover:shadow-xl transition-shadow duration-300">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="text-sm font-medium text-green-100">Approved Mockups</p>
                                <h3 class="text-2xl font-bold mt-1"><?php echo number_format(count($withMockup)); ?></h3>
                                <div class="flex items-center mt-2">
                                    <span class="text-xs bg-white bg-opacity-20 px-2 py-1 rounded-full">Ready for queue</span>
                                </div>
                            </div>
                            <div class="p-3 bg-white bg-opacity-20 rounded-xl">
                                <i class="fas fa-circle-check text-xl"></i>
                            </div>
                        </div>
                        <div class="mt-4 pt-3 border-t border-green-400 border-opacity-30">
                            <a href="production_queue.php" class="text-xs font-medium text-white hover:underline flex items-center">
                                Go to queue <i class="fas fa-arrow-right ml-1 text-xs"></i>
                            </a>
                        </div>
                    </div>

                    <div class="bg-gradient-to-br from-amber-500 to-amber-600 rounded-2xl p-5 text-white shadow-lg hover:shadow-xl transition-shadow duration-300">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="text-sm font-medium text-amber-100">Printing</p>
                                <h3 class="text-2xl font-bold mt-1"><?php echo number_format($printingCount); ?></h3>
                                <div class="flex items-center mt-2">
                                    <span class="text-xs bg-white bg-opacity-20 px-2 py-1 rounded-full">In progress</span>
                                </div>
                            </div>
                            <div class="p-3 bg-white bg-opacity-20 rounded-xl">
                                <i class="fas fa-print text-xl"></i>
                            </div>
                        </div>
                        <div class="mt-4 pt-3 border-t border-amber-400 border-opacity-30">
                            <a href="production_queue.php" class="text-xs font-medium text-white hover:underline flex items-center">
                                View printing <i class="fas fa-arrow-right ml-1 text-xs"></i>
                            </a>
                        </div>
                    </div>

                    <div class="bg-gradient-to-br from-indigo-500 to-indigo-600 rounded-2xl p-5 text-white shadow-lg hover:shadow-xl transition-shadow duration-300">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="text-sm font-medium text-indigo-100">Ready</p>
                                <h3 class="text-2xl font-bold mt-1"><?php echo number_format($readyCount); ?></h3>
                                <div class="flex items-center mt-2">
                                    <span class="text-xs bg-white bg-opacity-20 px-2 py-1 rounded-full">Awaiting delivery</span>
                                </div>
                            </div>
                            <div class="p-3 bg-white bg-opacity-20 rounded-xl">
                                <i class="fas fa-box text-xl"></i>
                            </div>
                        </div>
                        <div class="mt-4 pt-3 border-t border-indigo-400 border-opacity-30">
                            <a href="production_queue.php" class="text-xs font-medium text-white hover:underline flex items-center">
                                View ready <i class="fas fa-arrow-right ml-1 text-xs"></i>
                            </a>
                        </div>
                    </div>
                </div>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="lg:col-span-2" style="min-width:0;">
                    <div class="content-section">
                        <div class="section-header">
                            <h2 class="section-title">Printing Items (Tasks)</h2>
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
                                <?php $tn = 1; foreach (array_slice($printTasks, 0, 10) as $t): ?>
                                    <tr>
                                        <td><?php echo (int)$tn; ?></td>
                                        <td><?php echo htmlspecialchars((string)($t['event_name'] ?? '')); ?></td>
                                        <td><?php echo htmlspecialchars((string)($t['title'] ?? '')); ?></td>
                                        <td><?php echo htmlspecialchars((string)($t['qty'] ?? '')); ?></td>
                                        <td><?php echo htmlspecialchars((string)($t['status'] ?? '')); ?></td>
                                    </tr>
                                <?php $tn++; endforeach; ?>
                                <?php if (!$printTasks): ?>
                                    <tr><td colspan="5" style="text-align:center;color:#64748b;padding:2rem;">No print tasks</td></tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <div style="margin-top: 0.75rem;">
                            <a href="production_queue.php#print-tasks" class="link-highlight">Manage print tasks</a>
                        </div>
                    </div>

                    <div class="content-section">
                        <div class="section-header">
                            <h2 class="section-title">My Purchase Requests</h2>
                        </div>
                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Event</th>
                                    <th>Title</th>
                                    <th>Status</th>
                                    <th>Finance Comment</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php $prNo = 1; foreach ($myPurchases as $pr): ?>
                                    <tr>
                                        <td><?php echo (int)$prNo; ?></td>
                                        <td>
                                            <?php if (!empty($pr['event_id'])): ?>
                                                <?php echo htmlspecialchars(($pr['event_name'] ?? '') . ' (ID ' . (int)($pr['event_id'] ?? 0) . ')'); ?>
                                            <?php else: ?>
                                                <span class="text-slate-400">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($pr['title'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($pr['status'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($pr['finance_comment'] ?? ''); ?></td>
                                    </tr>
                                <?php $prNo++; endforeach; ?>
                                <?php if (!$myPurchases): ?>
                                    <tr><td colspan="5" style="text-align:center;color:#64748b;padding:2rem;">No purchase requests found</td></tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="lg:col-span-1" style="min-width:0;">
                    <div class="content-section">
                        <div class="section-header">
                            <h2 class="section-title">Quick Actions</h2>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <a href="production_queue.php" class="p-6 bg-blue-50 hover:bg-blue-100 rounded-2xl text-center transition-colors">
                                <div class="w-14 h-14 bg-blue-100 text-blue-600 rounded-2xl flex items-center justify-center mx-auto mb-4">
                                    <i class="fas fa-list-check text-xl"></i>
                                </div>
                                <span class="text-sm font-medium text-gray-700">Production Queue</span>
                            </a>
                            <a href="production_queue.php#print-tasks" class="p-6 bg-sky-50 hover:bg-sky-100 rounded-2xl text-center transition-colors">
                                <div class="w-14 h-14 bg-sky-100 text-sky-700 rounded-2xl flex items-center justify-center mx-auto mb-4">
                                    <i class="fas fa-print text-xl"></i>
                                </div>
                                <span class="text-sm font-medium text-gray-700">Print Tasks</span>
                            </a>
                            <a href="events.php?mode=list" class="p-6 bg-purple-50 hover:bg-purple-100 rounded-2xl text-center transition-colors">
                                <div class="w-14 h-14 bg-purple-100 text-purple-700 rounded-2xl flex items-center justify-center mx-auto mb-4">
                                    <i class="fas fa-calendar-check text-xl"></i>
                                </div>
                                <span class="text-sm font-medium text-gray-700">All Events</span>
                            </a>
                            <a href="purchase_requests.php" class="p-6 bg-amber-50 hover:bg-amber-100 rounded-2xl text-center transition-colors">
                                <div class="w-14 h-14 bg-amber-100 text-amber-700 rounded-2xl flex items-center justify-center mx-auto mb-4">
                                    <i class="fas fa-file-invoice-dollar text-xl"></i>
                                </div>
                                <span class="text-sm font-medium text-gray-700">Purchase Requests</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </main>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    if (window.__profileDropdownBound) return;
    var profile = document.getElementById('productionUserProfile');
    var dropdown = document.getElementById('productionHeaderProfileDropdown');
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
