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
if (!in_array($role, ['store', 'admin', 'super'], true)) {
    header('Location: index.php');
    exit;
}

$flashes = flash_consume();
$displayName = $user['name'] ?? $user['email'] ?? 'User';
$pageTitle = ($role === 'store') ? 'Store Dashboard' : 'Store Overview';

// Handle Store actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_abort();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create_purchase') {
            throw new Exception('Please use Purchase Requests page to request materials.');
        }

        if ($action === 'update_receiving') {
            $prId = (int)($_POST['pr_id'] ?? 0);
            $receivingStatus = (string)($_POST['receiving_status'] ?? '');
            $comment = trim((string)($_POST['receiving_comment'] ?? ''));

            if ($prId <= 0) {
                throw new Exception('Invalid purchase request');
            }

            $allowed = ['pending', 'partial', 'received'];
            if (!in_array($receivingStatus, $allowed, true)) {
                throw new Exception('Invalid receiving status');
            }

            $st = $db->prepare('SELECT id, status FROM purchase_requests WHERE id=?');
            $st->execute([$prId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new Exception('Purchase request not found');
            }

            // Store updates receiving against Finance-approved purchases.
            if (($row['status'] ?? '') !== 'approved') {
                throw new Exception('Only approved purchase requests can be received');
            }

            $upd = $db->prepare('UPDATE purchase_requests SET receiving_status=?, receiving_updated_by=?, receiving_updated_at=NOW(), receiving_comment=? WHERE id=?');
            $upd->execute([$receivingStatus, (int)($user['id'] ?? 0), ($comment !== '' ? $comment : null), $prId]);

            db_log_activity((int)($user['id'] ?? 0), 'store_receiving_update', json_encode(['pr_id' => $prId, 'receiving_status' => $receivingStatus]));
            flash_add('success', 'Material receiving status updated.');
            header('Location: store_dashboard.php');
            exit;
        }
    } catch (Throwable $ex) {
        flash_add('error', $ex->getMessage());
        header('Location: store_dashboard.php');
        exit;
    }
}

$events = [];
try {
    $stmt = $db->query("SELECT id, name, date, end_date, client, location, checklist_file, mockup_file, created_at FROM events ORDER BY COALESCE(date, created_at) DESC, id DESC");
    $events = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable $e) {
    $events = [];
}

$eventsWithFiles = array_values(array_filter($events, function ($e) {
    return !empty($e['checklist_file']) || !empty($e['mockup_file']);
}));

$myPurchaseRequests = [];
try {
    $stmt = $db->prepare("SELECT pr.*, e.name AS event_name, e.client AS event_client FROM purchase_requests pr LEFT JOIN events e ON pr.event_id = e.id WHERE pr.requested_by = ? OR ? IN ('admin','super') ORDER BY pr.created_at DESC, pr.id DESC");
    $stmt->execute([(int)($user['id'] ?? 0), $role]);
    $myPurchaseRequests = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $myPurchaseRequests = [];
}

$approvedPurchases = [];
try {
    $stmt = $db->query("SELECT pr.*, e.name AS event_name, e.client AS event_client FROM purchase_requests pr LEFT JOIN events e ON pr.event_id = e.id WHERE pr.status='approved' ORDER BY COALESCE(pr.reviewed_at, pr.submitted_at, pr.created_at) DESC, pr.id DESC");
    $approvedPurchases = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable $e) {
    $approvedPurchases = [];
}

$totalEvents = count($events);
$totalWithFiles = count($eventsWithFiles);
$totalApprovedPurchases = count($approvedPurchases);
$pendingReceiving = count(array_filter($approvedPurchases, fn($p) => ($p['receiving_status'] ?? 'pending') !== 'received'));

$receivingCounts = ['pending' => 0, 'partial' => 0, 'received' => 0];
foreach ($approvedPurchases as $p) {
    $rs = strtolower((string)($p['receiving_status'] ?? 'pending'));
    if (!isset($receivingCounts[$rs])) {
        $rs = 'pending';
    }
    $receivingCounts[$rs]++;
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
    <?php $activePage = 'store_dashboard.php'; include __DIR__ . '/sidebar.php'; ?>

    <main class="main-content">
        <div class="top-bar">
            <div class="page-title">
                <h1><?php echo htmlspecialchars($pageTitle); ?></h1>
                <p>Materials and file visibility (checklists & mockups)</p>
            </div>
            <div class="user-menu">
                <div class="date-display"><i class="far fa-calendar-alt"></i> <?php echo date('l, F j, Y'); ?></div>
                <div class="user-profile" id="storeUserProfile">
                    <div class="avatar"><?php echo htmlspecialchars(strtoupper(mb_substr($displayName, 0, 1))); ?></div>
                    <div>
                        <div style="font-weight:600;"><?php echo htmlspecialchars($displayName); ?></div>
                        <div style="font-size:0.75rem;color:#64748b;"><?php echo htmlspecialchars(get_role_label($user['role'] ?? 'user')); ?></div>
                    </div>
                    <i class="fas fa-chevron-down" style="color:#64748b;"></i>
                    <div class="profile-dropdown" id="storeHeaderProfileDropdown">
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

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mb-6">
            <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-2xl p-5 text-white shadow-lg hover:shadow-xl transition-shadow duration-300">
                <div class="flex justify-between items-start">
                    <div>
                        <p class="text-sm font-medium text-blue-100">Total Events</p>
                        <h3 class="text-2xl font-bold mt-1"><?php echo number_format($totalEvents); ?></h3>
                        <div class="flex items-center mt-2">
                            <span class="text-xs bg-white bg-opacity-20 px-2 py-1 rounded-full">
                                <?php echo number_format($totalWithFiles); ?> with files
                            </span>
                        </div>
                    </div>
                    <div class="p-3 bg-white bg-opacity-20 rounded-xl">
                        <i class="fas fa-calendar-alt text-xl"></i>
                    </div>
                </div>
                <div class="mt-4 pt-3 border-t border-blue-400 border-opacity-30">
                    <a href="events.php?mode=list" class="text-xs font-medium text-white hover:underline flex items-center">
                        View event files <i class="fas fa-arrow-right ml-1 text-xs"></i>
                    </a>
                </div>
            </div>

            <div class="bg-gradient-to-br from-sky-500 to-sky-600 rounded-2xl p-5 text-white shadow-lg hover:shadow-xl transition-shadow duration-300">
                <div class="flex justify-between items-start">
                    <div>
                        <p class="text-sm font-medium text-sky-100">Files Available</p>
                        <h3 class="text-2xl font-bold mt-1"><?php echo number_format($totalWithFiles); ?></h3>
                        <div class="mt-2">
                            <span class="text-xs bg-white bg-opacity-20 px-2 py-1 rounded-full">
                                checklist / mockup
                            </span>
                        </div>
                    </div>
                    <div class="p-3 bg-white bg-opacity-20 rounded-xl">
                        <i class="fas fa-folder-open text-xl"></i>
                    </div>
                </div>
                <div class="mt-4 pt-3 border-t border-sky-400 border-opacity-30">
                    <a href="events.php?mode=list" class="text-xs font-medium text-white hover:underline flex items-center">
                        Browse files <i class="fas fa-arrow-right ml-1 text-xs"></i>
                    </a>
                </div>
            </div>

            <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-2xl p-5 text-white shadow-lg hover:shadow-xl transition-shadow duration-300">
                <div class="flex justify-between items-start">
                    <div>
                        <p class="text-sm font-medium text-green-100">Approved Purchases</p>
                        <h3 class="text-2xl font-bold mt-1"><?php echo number_format($totalApprovedPurchases); ?></h3>
                        <div class="mt-2">
                            <span class="text-xs bg-white bg-opacity-20 px-2 py-1 rounded-full">
                                <?php echo number_format($pendingReceiving); ?> pending receiving
                            </span>
                        </div>
                    </div>
                    <div class="p-3 bg-white bg-opacity-20 rounded-xl">
                        <i class="fas fa-circle-check text-xl"></i>
                    </div>
                </div>
                <div class="mt-4 pt-3 border-t border-green-400 border-opacity-30">
                    <a href="#approved-purchases" class="text-xs font-medium text-white hover:underline flex items-center">
                        Update receiving <i class="fas fa-arrow-right ml-1 text-xs"></i>
                    </a>
                </div>
            </div>

            <div class="bg-gradient-to-br from-amber-500 to-amber-600 rounded-2xl p-5 text-white shadow-lg hover:shadow-xl transition-shadow duration-300">
                <div class="flex justify-between items-start">
                    <div>
                        <p class="text-sm font-medium text-amber-100">Pending Receiving</p>
                        <h3 class="text-2xl font-bold mt-1"><?php echo number_format($pendingReceiving); ?></h3>
                        <div class="mt-2">
                            <span class="text-xs bg-white bg-opacity-20 px-2 py-1 rounded-full">
                                approved items
                            </span>
                        </div>
                    </div>
                    <div class="p-3 bg-white bg-opacity-20 rounded-xl">
                        <i class="fas fa-truck-ramp-box text-xl"></i>
                    </div>
                </div>
                <div class="mt-4 pt-3 border-t border-amber-400 border-opacity-30">
                    <a href="#approved-purchases" class="text-xs font-medium text-white hover:underline flex items-center">
                        Go to receiving <i class="fas fa-arrow-right ml-1 text-xs"></i>
                    </a>
                </div>
            </div>
        </div>

        


        <div class="grid grid-cols-1 xl:grid-cols-12 gap-6">
            <div class="xl:col-span-8">
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden" id="approved-purchases">
                    <div class="p-5 border-b border-gray-100">
                        <div class="flex justify-between items-start gap-4 flex-wrap">
                            <div>
                                <h3 class="text-lg font-semibold text-gray-800">Approved Purchases</h3>
                                <div class="text-xs text-gray-500 mt-1" style="display:flex;gap:1rem;flex-wrap:wrap;">
                                    <span>Pending: <?php echo (int)($receivingCounts['pending'] ?? 0); ?></span>
                                    <span>Partial: <?php echo (int)($receivingCounts['partial'] ?? 0); ?></span>
                                    <span>Received: <?php echo (int)($receivingCounts['received'] ?? 0); ?></span>
                                </div>
                            </div>
                            <div class="flex items-center gap-2 flex-wrap">
                                <input id="storeApprovedPurchasesSearch" type="text" class="form-input" style="max-width:220px;" placeholder="Search...">
                                <select id="storeApprovedPurchasesStatus" class="form-input" style="max-width:120px;">
                                    <option value="all">All</option>
                                    <option value="pending">Pending</option>
                                    <option value="partial">Partial</option>
                                    <option value="received">Received</option>
                                </select>
                                <button id="storeApprovedPurchasesApply" type="button" class="btn btn-primary">Filter</button>
                            </div>
                        </div>
                    </div>

                    <div class="p-4">
                        <table class="data-table" id="storeApprovedPurchasesTable">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Event</th>
                        <th>Title</th>
                        <th>Amount</th>
                        <th>Finance Comment</th>
                        <th>Receiving</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php $apRow = 1; foreach ($approvedPurchases as $pr): ?>
                        <tr>
                            <td><?php echo (int)$apRow; ?></td>
                            <td>
                                <?php if (!empty($pr['event_id'])): ?>
                                    <?php echo htmlspecialchars(($pr['event_name'] ?? '') . ' (ID ' . (int)($pr['event_id'] ?? 0) . ')'); ?>
                                <?php else: ?>
                                    <span class="text-slate-400">-</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($pr['title'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars(number_format((float)($pr['amount'] ?? 0), 2)); ?></td>
                            <td><?php echo htmlspecialchars($pr['finance_comment'] ?? ''); ?></td>
                            <td>
                                <form method="post" class="grid grid-cols-1 gap-2" style="min-width: 220px;">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                    <input type="hidden" name="action" value="update_receiving">
                                    <input type="hidden" name="pr_id" value="<?php echo (int)$pr['id']; ?>">
                                    <select name="receiving_status" class="form-input" data-receiving-status>
                                        <?php $rs = (string)($pr['receiving_status'] ?? 'pending'); ?>
                                        <option value="pending" <?php echo $rs === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                        <option value="partial" <?php echo $rs === 'partial' ? 'selected' : ''; ?>>Partial</option>
                                        <option value="received" <?php echo $rs === 'received' ? 'selected' : ''; ?>>Received</option>
                                    </select>
                                    <input type="text" name="receiving_comment" class="form-input" placeholder="Comment (optional)" value="<?php echo htmlspecialchars((string)($pr['receiving_comment'] ?? '')); ?>">
                                    <button type="submit" class="btn btn-primary" style="width:100%;">Update</button>
                                </form>
                            </td>
                        </tr>
                    <?php $apRow++; endforeach; ?>
                    <?php if (!$approvedPurchases): ?>
                        <tr><td colspan="6" style="text-align:center;color:#64748b;padding:2rem;">No approved purchases found</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>

                        <div style="display:flex;justify-content:flex-end;align-items:center;gap:0.5rem;margin-top:0.75rem;">
                            <button id="storeApprovedPurchasesPrev" type="button" class="btn btn-secondary">Previous</button>
                            <span id="storeApprovedPurchasesPageInfo" style="font-size:0.85rem;color:#64748b;"></span>
                            <button id="storeApprovedPurchasesNext" type="button" class="btn btn-secondary">Next</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="xl:col-span-4">
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4">
                    <h3 class="text-md font-semibold text-gray-800 mb-3">Quick Actions</h3>
                    <div class="grid grid-cols-2 gap-2 quick-actions-grid">
                        <a href="purchase_requests.php" class="p-2 bg-blue-50 hover:bg-blue-100 rounded-lg text-center transition-colors">
                            <div class="w-8 h-8 bg-blue-100 text-blue-600 rounded-md flex items-center justify-center mx-auto mb-1">
                                <i class="fas fa-plus text-sm"></i>
                            </div>
                            <span class="text-xs font-medium text-gray-700">New Purchase</span>
                        </a>
                        <a href="store_dashboard.php#approved-purchases" class="p-2 bg-green-50 hover:bg-green-100 rounded-lg text-center transition-colors">
                            <div class="w-8 h-8 bg-green-100 text-green-600 rounded-md flex items-center justify-center mx-auto mb-1">
                                <i class="fas fa-list-check text-sm"></i>
                            </div>
                            <span class="text-xs font-medium text-gray-700">Approved</span>
                        </a>
                        <a href="events.php?mode=list" class="p-2 bg-purple-50 hover:bg-purple-100 rounded-lg text-center transition-colors">
                            <div class="w-8 h-8 bg-purple-100 text-purple-600 rounded-md flex items-center justify-center mx-auto mb-1">
                                <i class="fas fa-calendar text-sm"></i>
                            </div>
                            <span class="text-xs font-medium text-gray-700">Event Files</span>
                        </a>
                        <a href="?logout=1" class="p-2 bg-amber-50 hover:bg-amber-100 rounded-lg text-center transition-colors">
                            <div class="w-8 h-8 bg-amber-100 text-amber-600 rounded-md flex items-center justify-center mx-auto mb-1">
                                <i class="fas fa-sign-out-alt text-sm"></i>
                            </div>
                            <span class="text-xs font-medium text-gray-700">Logout</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    if (window.__profileDropdownBound) return;
    var profile = document.getElementById('storeUserProfile');
    var dropdown = document.getElementById('storeHeaderProfileDropdown');
    if (!profile || !dropdown) return;

    function closeDd(){ dropdown.classList.remove('show'); }

    profile.addEventListener('click', function(e){
        e.stopPropagation();
        dropdown.classList.toggle('show');
    });

    document.addEventListener('click', function(){ closeDd(); });

    (function () {
        var table = document.getElementById('storeApprovedPurchasesTable');
        var search = document.getElementById('storeApprovedPurchasesSearch');
        var statusSel = document.getElementById('storeApprovedPurchasesStatus');
        var applyBtn = document.getElementById('storeApprovedPurchasesApply');
        var prev = document.getElementById('storeApprovedPurchasesPrev');
        var next = document.getElementById('storeApprovedPurchasesNext');
        var pageInfo = document.getElementById('storeApprovedPurchasesPageInfo');
        if (!table || !search || !prev || !next || !pageInfo || !statusSel || !applyBtn) return;

        var allRows = Array.prototype.slice.call(table.querySelectorAll('tbody tr'));
        var dataRows = allRows.filter(function (r) { return r.querySelectorAll('td').length > 1; });
        var page = 1;
        var pageSize = 10;
        var filtered = dataRows;

        var activeStatus = 'all';

        function apply() {
            var q = (search.value || '').trim().toLowerCase();
            filtered = dataRows.filter(function (r) {
                var textOk = (r.textContent || '').toLowerCase().indexOf(q) !== -1;
                if (!textOk) return false;
                if (activeStatus === 'all') return true;
                var sel = r.querySelector('[data-receiving-status]');
                var rs = sel ? String(sel.value || '').toLowerCase() : '';
                return rs === activeStatus;
            });

            var totalPages = Math.max(1, Math.ceil(filtered.length / pageSize));
            if (page > totalPages) page = totalPages;
            if (page < 1) page = 1;

            dataRows.forEach(function (r) { r.style.display = 'none'; });
            var start = (page - 1) * pageSize;
            var end = start + pageSize;
            filtered.slice(start, end).forEach(function (r) { r.style.display = ''; });

            prev.disabled = page <= 1;
            next.disabled = page >= totalPages;
            pageInfo.textContent = 'Page ' + page + ' / ' + totalPages;
        }

        search.addEventListener('input', function () {
            page = 1;
            apply();
        });
        applyBtn.addEventListener('click', function () {
            activeStatus = String(statusSel.value || 'all').toLowerCase();
            page = 1;
            apply();
        });
        prev.addEventListener('click', function () {
            page -= 1;
            apply();
        });
        next.addEventListener('click', function () {
            page += 1;
            apply();
        });

        apply();
    })();
});
</script>
</body>
</html>
