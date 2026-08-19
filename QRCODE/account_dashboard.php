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
if (!in_array($role, ['accountant', 'admin', 'super'], true)) {
    header('Location: index.php');
    exit;
}

$flashes = flash_consume();
$displayName = $user['name'] ?? $user['email'] ?? 'User';
$pageTitle = ($role === 'accountant') ? 'Accounts Dashboard' : 'Accounts Overview';

// Handle Accounts actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_abort();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'upload_ro') {
            flash_add('error', 'Release Orders are now prepared by the system. Please use the Release Orders page.');
            header('Location: release_orders.php');
            exit;
        }

        if ($action === 'submit_ro') {
            $roId = (int)($_POST['ro_id'] ?? 0);
            if ($roId <= 0) {
                throw new Exception('Invalid Release Order');
            }

            $st = $db->prepare('SELECT id, uploaded_by, status FROM release_orders WHERE id=?');
            $st->execute([$roId]);
            $ro = $st->fetch(PDO::FETCH_ASSOC);
            if (!$ro) {
                throw new Exception('Release Order not found');
            }

            $canSubmit = ((int)($ro['uploaded_by'] ?? 0) === (int)($user['id'] ?? 0)) || in_array($role, ['admin','super'], true);
            if (!$canSubmit) {
                throw new Exception('You cannot submit this Release Order');
            }
            if (($ro['status'] ?? '') !== 'draft') {
                throw new Exception('Only draft Release Orders can be submitted');
            }

            $upd = $db->prepare("UPDATE release_orders SET status='submitted', submitted_at=NOW() WHERE id=?");
            $upd->execute([$roId]);
            db_log_activity((int)($user['id'] ?? 0), 'release_order_submit', json_encode(['ro_id' => $roId]));
            flash_add('success', 'Release Order submitted to Finance.');
            header('Location: account_dashboard.php');
            exit;
        }

        if ($action === 'create_purchase') {
            flash_add('error', 'Please use Purchase Requests page to review material requests and submit to Finance.');
            header('Location: purchase_requests.php');
            exit;
        }
    } catch (Throwable $ex) {
        flash_add('error', $ex->getMessage());
        header('Location: account_dashboard.php');
        exit;
    }
}

$kpiTotalEvents = 0;
$kpiRoApproved = 0;
$kpiRoRejected = 0;
$kpiRoPending = 0;
$kpiPrApproved = 0;
$kpiPrRejected = 0;
$kpiPrPending = 0;
$kpiPurchaseThisMonth = 0;
$kpiMonthLabel = date('F Y');
try {
    $eventsCountStmt = $db->prepare("SELECT COUNT(*) FROM events");
    $eventsCountStmt->execute();
    $kpiTotalEvents = (int)($eventsCountStmt->fetchColumn() ?? 0);

    $roWhere = '';
    $roParams = [];
    if ($role === 'accountant') {
        $roWhere = 'WHERE uploaded_by = ?';
        $roParams[] = (int)($user['id'] ?? 0);
    }

    $roCountsStmt = $db->prepare("\n        SELECT\n            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved_count,\n            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected_count,\n            SUM(CASE WHEN status NOT IN ('approved','rejected') OR status IS NULL THEN 1 ELSE 0 END) AS pending_count\n        FROM release_orders\n        " . $roWhere . "\n    ");
    $roCountsStmt->execute($roParams);
    $roCounts = $roCountsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $kpiRoApproved = (int)($roCounts['approved_count'] ?? 0);
    $kpiRoRejected = (int)($roCounts['rejected_count'] ?? 0);
    $kpiRoPending = (int)($roCounts['pending_count'] ?? 0);

    $prWhere = '';
    $prParams = [];
    if ($role === 'accountant') {
        $prWhere = 'WHERE requested_by = ?';
        $prParams[] = (int)($user['id'] ?? 0);
    }
    $prCountsStmt = $db->prepare("\n        SELECT\n            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved_count,\n            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected_count,\n            SUM(CASE WHEN status NOT IN ('approved','rejected') OR status IS NULL THEN 1 ELSE 0 END) AS pending_count\n        FROM purchase_requests\n        " . $prWhere . "\n    ");
    $prCountsStmt->execute($prParams);
    $prCounts = $prCountsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $kpiPrApproved = (int)($prCounts['approved_count'] ?? 0);
    $kpiPrRejected = (int)($prCounts['rejected_count'] ?? 0);
    $kpiPrPending = (int)($prCounts['pending_count'] ?? 0);

    $roMonthWhere = [];
    $roMonthParams = [];
    if ($role === 'accountant') {
        $roMonthWhere[] = 'uploaded_by = ?';
        $roMonthParams[] = (int)($user['id'] ?? 0);
    }
    $roMonthWhere[] = 'YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())';
    $roMonthWhereSql = 'WHERE ' . implode(' AND ', $roMonthWhere);

    $roMonthStmt = $db->prepare("SELECT COUNT(*) FROM release_orders " . $roMonthWhereSql);
    $roMonthStmt->execute($roMonthParams);

    $prMonthWhere = [];
    $prMonthParams = [];
    if ($role === 'accountant') {
        $prMonthWhere[] = 'requested_by = ?';
        $prMonthParams[] = (int)($user['id'] ?? 0);
    }
    $prMonthWhere[] = 'YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())';
    $prMonthWhereSql = 'WHERE ' . implode(' AND ', $prMonthWhere);

    $prMonthStmt = $db->prepare("SELECT COUNT(*) FROM purchase_requests " . $prMonthWhereSql);
    $prMonthStmt->execute($prMonthParams);
    $kpiPurchaseThisMonth = (int)($prMonthStmt->fetchColumn() ?? 0);
} catch (Throwable $e) {
    $kpiTotalEvents = 0;
    $kpiRoApproved = 0;
    $kpiRoRejected = 0;
    $kpiRoPending = 0;
    $kpiPrApproved = 0;
    $kpiPrRejected = 0;
    $kpiPrPending = 0;
    $kpiPurchaseThisMonth = 0;
}

$releaseOrders = [];
try {
    $stmt = $db->prepare("SELECT ro.*, e.name AS event_name, e.client AS event_client FROM release_orders ro LEFT JOIN events e ON ro.event_id = e.id WHERE ro.uploaded_by = ? OR ? IN ('admin','super') ORDER BY ro.created_at DESC, ro.id DESC");
    $stmt->execute([(int)($user['id'] ?? 0), $role]);
    $releaseOrders = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $releaseOrders = [];
}

$roFilter = strtolower((string)($_GET['ro_status'] ?? 'all'));
if (!in_array($roFilter, ['all', 'pending', 'approved', 'rejected'], true)) {
    $roFilter = 'all';
}

$roPendingCount = 0;
$roApprovedCount = 0;
$roRejectedCount = 0;
$filteredReleaseOrders = [];
foreach ($releaseOrders as $roRow) {
    $st = strtolower((string)($roRow['status'] ?? ''));
    $bucket = ($st === 'approved' || $st === 'rejected') ? $st : 'pending';
    if ($bucket === 'approved') {
        $roApprovedCount++;
    } elseif ($bucket === 'rejected') {
        $roRejectedCount++;
    } else {
        $roPendingCount++;
    }

    if ($roFilter === 'all' || $roFilter === $bucket) {
        $filteredReleaseOrders[] = $roRow;
    }
}

$purchaseRequests = [];
try {
    $stmt = $db->prepare("SELECT pr.*, e.name AS event_name, e.client AS event_client FROM purchase_requests pr LEFT JOIN events e ON pr.event_id = e.id WHERE pr.requested_by = ? OR ? IN ('admin','super') ORDER BY pr.created_at DESC, pr.id DESC");
    $stmt->execute([(int)($user['id'] ?? 0), $role]);
    $purchaseRequests = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $purchaseRequests = [];
}

$prFilter = strtolower((string)($_GET['pr_status'] ?? 'all'));
if (!in_array($prFilter, ['all', 'pending', 'approved', 'rejected'], true)) {
    $prFilter = 'all';
}

$prPendingCount = 0;
$prApprovedCount = 0;
$prRejectedCount = 0;
$filteredPurchaseRequests = [];
foreach ($purchaseRequests as $prRow) {
    $st = strtolower((string)($prRow['status'] ?? ''));
    $bucket = ($st === 'approved' || $st === 'rejected') ? $st : 'pending';
    if ($bucket === 'approved') {
        $prApprovedCount++;
    } elseif ($bucket === 'rejected') {
        $prRejectedCount++;
    } else {
        $prPendingCount++;
    }

    if ($prFilter === 'all' || $prFilter === $bucket) {
        $filteredPurchaseRequests[] = $prRow;
    }
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
    <?php $activePage = 'account_dashboard.php'; include __DIR__ . '/sidebar.php'; ?>

    <main class="main-content">
        <div class="top-bar">
            <div class="page-title">
                <h1><?php echo htmlspecialchars($pageTitle); ?></h1>
                <p>Financial visibility for events</p>
            </div>

            <div class="user-menu">
                <div class="date-display">
                    <i class="far fa-calendar-alt mr-2"></i>
                    <?php echo date('l, F j, Y'); ?>
                </div>

                <div class="user-profile" id="userProfile">
                    <div class="avatar">
                        <?php
                        $displayName = $user['name'] ?? $user['email'] ?? 'User';
                        $initial = strtoupper(substr($displayName, 0, 1));
                        echo htmlspecialchars($initial);
                        ?>
                    </div>
                    <div>
                        <div style="font-weight: 600;"><?php echo htmlspecialchars($displayName); ?></div>
                        <div style="font-size: 0.75rem; color: #64748b;"><?php echo get_role_label($user['role'] ?? 'user'); ?></div>
                    </div>
                    <i class="fas fa-chevron-down ml-2" style="color: #64748b;"></i>

                    <div class="profile-dropdown" id="profileDropdown">
                        <a href="profile.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"><i class="fas fa-user mr-2"></i> My Profile</a>
                        <a href="settings.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"><i class="fas fa-cog mr-2"></i> Settings</a>
                        <a href="?logout=1" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"><i class="fas fa-sign-out-alt mr-2"></i> Logout</a>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6" style="margin: 0.75rem 0 1.5rem;">
            <div class="bg-gradient-to-br from-blue-500 to-blue-700 rounded-2xl p-5 text-white shadow-lg hover:shadow-xl transition-shadow duration-300">
                <div class="flex justify-between items-start">
                    <div>
                        <p class="text-sm font-medium text-blue-100">Total Events</p>
                        <h3 class="text-2xl font-bold mt-1"><?php echo (int)$kpiTotalEvents; ?></h3>
                        <div class="mt-2">
                            <span class="text-xs bg-white bg-opacity-20 px-2 py-1 rounded-full">Portfolio</span>
                        </div>
                    </div>
                    <div class="p-3 bg-white bg-opacity-20 rounded-xl"><i class="fas fa-calendar-check text-xl"></i></div>
                </div>
                <div class="mt-4 pt-3 border-t border-blue-400 border-opacity-30">
                    <a href="events.php" class="text-xs font-medium text-white hover:underline flex items-center">View all events <i class="fas fa-arrow-right ml-1 text-xs"></i></a>
                </div>
            </div>

            <div class="bg-gradient-to-br from-purple-500 to-purple-700 rounded-2xl p-5 text-white shadow-lg hover:shadow-xl transition-shadow duration-300">
                <div class="flex justify-between items-start">
                    <div>
                        <p class="text-sm font-medium text-purple-100">Release Approved</p>
                        <h3 class="text-2xl font-bold mt-1"><?php echo (int)$kpiRoApproved; ?></h3>
                        <div class="mt-2">
                            <span class="text-xs bg-white bg-opacity-20 px-2 py-1 rounded-full">Finance approved</span>
                        </div>
                    </div>
                    <div class="p-3 bg-white bg-opacity-20 rounded-xl"><i class="fas fa-circle-check text-xl"></i></div>
                </div>
                <div class="mt-4 pt-3 border-t border-purple-400 border-opacity-30">
                    <a href="release_orders.php" class="text-xs font-medium text-white hover:underline flex items-center">View release orders <i class="fas fa-arrow-right ml-1 text-xs"></i></a>
                </div>
            </div>

            <div class="bg-gradient-to-br from-green-500 to-green-700 rounded-2xl p-5 text-white shadow-lg hover:shadow-xl transition-shadow duration-300">
                <div class="flex justify-between items-start">
                    <div>
                        <p class="text-sm font-medium text-green-100">Release Rejected</p>
                        <h3 class="text-2xl font-bold mt-1"><?php echo (int)$kpiRoRejected; ?></h3>
                        <div class="mt-2">
                            <span class="text-xs bg-white bg-opacity-20 px-2 py-1 rounded-full">Needs update</span>
                        </div>
                    </div>
                    <div class="p-3 bg-white bg-opacity-20 rounded-xl"><i class="fas fa-circle-xmark text-xl"></i></div>
                </div>
                <div class="mt-4 pt-3 border-t border-green-400 border-opacity-30">
                    <a href="release_orders.php" class="text-xs font-medium text-white hover:underline flex items-center">Review rejections <i class="fas fa-arrow-right ml-1 text-xs"></i></a>
                </div>
            </div>

            <div class="bg-gradient-to-br from-amber-500 to-amber-600 rounded-2xl p-5 text-white shadow-lg hover:shadow-xl transition-shadow duration-300">
                <div class="flex justify-between items-start">
                    <div>
                        <p class="text-sm font-medium text-amber-100">This Month's Purchases</p>
                        <h3 class="text-2xl font-bold mt-1"><?php echo (int)$kpiPurchaseThisMonth; ?></h3>
                        <div class="mt-2">
                            <span class="text-xs bg-white bg-opacity-20 px-2 py-1 rounded-full"><?php echo htmlspecialchars($kpiMonthLabel); ?></span>
                        </div>
                    </div>
                    <div class="p-3 bg-white bg-opacity-20 rounded-xl"><i class="fas fa-cart-shopping text-xl"></i></div>
                </div>
                <div class="mt-4 pt-3 border-t border-amber-400 border-opacity-30">
                    <a href="purchase_requests.php" class="text-xs font-medium text-white hover:underline flex items-center">View purchases <i class="fas fa-arrow-right ml-1 text-xs"></i></a>
                </div>
            </div>
        </div>

        <?php foreach ($flashes as $f): ?>
            <div class="alert <?php echo $f['type'] === 'success' ? 'alert-success' : 'alert-error'; ?>">
                <?php echo htmlspecialchars($f['message']); ?>
            </div>
        <?php endforeach; ?>

        <div class="grid grid-cols-1 xl:grid-cols-12 gap-6" style="margin-bottom: 1.25rem;">
            <div class="xl:col-span-8 space-y-6">
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden w-full">
                    <div class="p-5 border-b border-gray-100 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800">Release Orders</h3>
                            <div class="mt-1 text-xs text-gray-500">
                                <span class="mr-3">Pending: <span class="font-semibold"><?php echo (int)$roPendingCount; ?></span></span>
                                <span class="mr-3">Approved: <span class="font-semibold"><?php echo (int)$roApprovedCount; ?></span></span>
                                <span>Rejected: <span class="font-semibold"><?php echo (int)$roRejectedCount; ?></span></span>
                            </div>
                        </div>
                        <form method="get" class="flex items-center gap-2">
                            <a href="release_orders.php" class="inline-flex items-center justify-center w-10 h-10 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 text-gray-700" title="Open Release Orders">
                                <i class="fas fa-arrow-up-right-from-square"></i>
                            </a>
                            <select name="ro_status" class="appearance-none bg-white border border-gray-200 rounded-lg pl-3 pr-8 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                <option value="all" <?php echo $roFilter === 'all' ? 'selected' : ''; ?>>All</option>
                                <option value="pending" <?php echo $roFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="approved" <?php echo $roFilter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                                <option value="rejected" <?php echo $roFilter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                            </select>
                            <button type="submit" class="inline-flex items-center px-4 py-2 rounded-lg text-sm font-medium text-white bg-blue-600 hover:bg-blue-700">Filter</button>
                        </form>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-100">
                            <thead class="bg-gray-50">
                            <tr>
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">ID</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Event</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">File</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Finance Comment</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Action</th>
                            </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-100">
                            <?php if (empty($filteredReleaseOrders)): ?>
                                <tr><td colspan="6" class="px-5 py-8 text-center text-sm text-gray-500">No release orders found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($filteredReleaseOrders as $ro): ?>
                                    <?php $roStatus = strtolower((string)($ro['status'] ?? '')); ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-5 py-3 text-sm text-gray-900"><?php echo (int)$ro['id']; ?></td>
                                        <td class="px-5 py-3 text-sm font-medium text-gray-900"><?php echo htmlspecialchars(($ro['event_name'] ?? '') . ' (ID ' . (int)($ro['event_id'] ?? 0) . ')'); ?></td>
                                        <td class="px-5 py-3 text-sm">
                                            <?php if ($roStatus === 'approved'): ?>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-green-100 text-green-800">Approved</span>
                                            <?php elseif ($roStatus === 'rejected'): ?>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-red-100 text-red-800">Rejected</span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-100 text-amber-800">Pending</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-5 py-3 text-sm">
                                            <?php if (!empty($ro['file_path'])): ?>
                                                <a class="text-blue-600 hover:text-blue-800 text-sm font-medium" target="_blank" href="<?php echo htmlspecialchars($ro['file_path'] ?? ''); ?>">View</a>
                                            <?php else: ?>
                                                <span class="text-gray-400 text-sm">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-5 py-3 text-xs text-gray-600" style="white-space: pre-wrap;"><?php echo htmlspecialchars((string)($ro['finance_comment'] ?? '')); ?></td>
                                        <td class="px-5 py-3 text-sm">
                                            <?php if (($ro['status'] ?? '') === 'draft'): ?>
                                                <form method="post" style="display:inline;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                                    <input type="hidden" name="action" value="submit_ro">
                                                    <input type="hidden" name="ro_id" value="<?php echo (int)$ro['id']; ?>">
                                                    <button type="submit" class="inline-flex items-center px-3 py-2 rounded-lg text-sm font-medium text-white bg-blue-600 hover:bg-blue-700">Submit</button>
                                                </form>
                                            <?php else: ?>
                                                <span class="text-gray-400 text-sm">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden w-full">
                    <div class="p-5 border-b border-gray-100 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800">Purchase Requests</h3>
                            <div class="mt-1 text-xs text-gray-500">
                                <span class="mr-3">Pending: <span class="font-semibold"><?php echo (int)$prPendingCount; ?></span></span>
                                <span class="mr-3">Approved: <span class="font-semibold"><?php echo (int)$prApprovedCount; ?></span></span>
                                <span>Rejected: <span class="font-semibold"><?php echo (int)$prRejectedCount; ?></span></span>
                            </div>
                        </div>
                        <form method="get" class="flex items-center gap-2">
                            <a href="purchase_requests.php" class="inline-flex items-center justify-center w-10 h-10 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 text-gray-700" title="Open Purchase Requests">
                                <i class="fas fa-arrow-up-right-from-square"></i>
                            </a>
                            <select name="pr_status" class="appearance-none bg-white border border-gray-200 rounded-lg pl-3 pr-8 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                <option value="all" <?php echo $prFilter === 'all' ? 'selected' : ''; ?>>All</option>
                                <option value="pending" <?php echo $prFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="approved" <?php echo $prFilter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                                <option value="rejected" <?php echo $prFilter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                            </select>
                            <button type="submit" class="inline-flex items-center px-4 py-2 rounded-lg text-sm font-medium text-white bg-blue-600 hover:bg-blue-700">Filter</button>
                        </form>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-100">
                            <thead class="bg-gray-50">
                            <tr>
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">ID</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Event</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Title</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Amount</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Finance Comment</th>
                            </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-100">
                            <?php if (empty($filteredPurchaseRequests)): ?>
                                <tr><td colspan="6" class="px-5 py-8 text-center text-sm text-gray-500">No purchase requests found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($filteredPurchaseRequests as $pr): ?>
                                    <?php $prStatus = strtolower((string)($pr['status'] ?? '')); ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-5 py-3 text-sm text-gray-900"><?php echo (int)$pr['id']; ?></td>
                                        <td class="px-5 py-3 text-sm text-gray-700">
                                            <?php if (!empty($pr['event_id'])): ?>
                                                <?php echo htmlspecialchars(($pr['event_name'] ?? '') . ' (ID ' . (int)($pr['event_id'] ?? 0) . ')'); ?>
                                            <?php else: ?>
                                                <span class="text-gray-400 text-sm">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-5 py-3 text-sm font-medium text-gray-900"><?php echo htmlspecialchars((string)($pr['title'] ?? '')); ?></td>
                                        <td class="px-5 py-3 text-sm text-gray-700"><?php echo htmlspecialchars(number_format((float)($pr['amount'] ?? 0), 2)); ?></td>
                                        <td class="px-5 py-3 text-sm">
                                            <?php if ($prStatus === 'approved'): ?>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-green-100 text-green-800">Approved</span>
                                            <?php elseif ($prStatus === 'rejected'): ?>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-red-100 text-red-800">Rejected</span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-100 text-amber-800">Pending</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-5 py-3 text-xs text-gray-600" style="white-space: pre-wrap;"><?php echo htmlspecialchars((string)($pr['finance_comment'] ?? '')); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="space-y-6 xl:col-span-4">
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4">
                    <h3 class="text-md font-semibold text-gray-800 mb-3">Quick Actions</h3>
                    <div class="grid grid-cols-2 gap-2">
                        <a href="release_orders.php" class="p-2 bg-blue-50 hover:bg-blue-100 rounded-lg text-center transition-colors" style="text-decoration:none;">
                            <div class="w-8 h-8 bg-blue-100 text-blue-600 rounded-md flex items-center justify-center mx-auto mb-1">
                                <i class="fas fa-upload"></i>
                            </div>
                            <div class="text-xs font-medium text-gray-700">Upload RO</div>
                        </a>
                        <a href="release_orders.php" class="p-2 bg-green-50 hover:bg-green-100 rounded-lg text-center transition-colors" style="text-decoration:none;">
                            <div class="w-8 h-8 bg-green-100 text-green-600 rounded-md flex items-center justify-center mx-auto mb-1">
                                <i class="fas fa-file-signature"></i>
                            </div>
                            <div class="text-xs font-medium text-gray-700">All RO</div>
                        </a>
                        <a href="purchase_requests.php" class="p-2 bg-purple-50 hover:bg-purple-100 rounded-lg text-center transition-colors" style="text-decoration:none;">
                            <div class="w-8 h-8 bg-purple-100 text-purple-600 rounded-md flex items-center justify-center mx-auto mb-1">
                                <i class="fas fa-plus"></i>
                            </div>
                            <div class="text-xs font-medium text-gray-700">New Purchase</div>
                        </a>
                        <a href="purchase_requests.php" class="p-2 bg-amber-50 hover:bg-amber-100 rounded-lg text-center transition-colors" style="text-decoration:none;">
                            <div class="w-8 h-8 bg-amber-100 text-amber-600 rounded-md flex items-center justify-center mx-auto mb-1">
                                <i class="fas fa-cart-shopping"></i>
                            </div>
                            <div class="text-xs font-medium text-gray-700">All Purchases</div>
                        </a>
                    </div>
                </div>
            </div>
        </div>



    </main>
</div>

<script>
  (function () {
    if (window.__profileDropdownBound) return;
    window.__profileDropdownBound = true;

    const profile = document.getElementById('userProfile');
    const dropdown = document.getElementById('profileDropdown');
    if (!profile || !dropdown) return;

    profile.addEventListener('click', function (e) {
      e.stopPropagation();
      dropdown.classList.toggle('show');
    });

    document.addEventListener('click', function (event) {
      if (!profile.contains(event.target)) {
        dropdown.classList.remove('show');
      }
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        dropdown.classList.remove('show');
      }
    });
  })();
</script>
</body>
</html>
