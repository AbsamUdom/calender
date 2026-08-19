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
if (!in_array($role, ['finance', 'admin', 'super'], true)) {
    header('Location: index.php');
    exit;
}

$flashes = flash_consume();
$displayName = $user['name'] ?? $user['email'] ?? 'User';
$pageTitle = ($role === 'finance') ? 'Finance Dashboard' : 'Finance Overview';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_abort();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'quote_decision') {
            $eventId = (int)($_POST['event_id'] ?? 0);
            $decision = $_POST['decision'] ?? '';
            $comment = trim((string)($_POST['comment'] ?? ''));
            if ($eventId <= 0) {
                throw new Exception('Invalid event');
            }
            if (!in_array($decision, ['approved', 'rejected'], true)) {
                throw new Exception('Invalid decision');
            }

            $stmt = $db->prepare("UPDATE events SET quote_status=?, quote_reviewed_by=?, quote_reviewed_at=NOW(), quote_finance_comment=? WHERE id=?");
            $stmt->execute([$decision, (int)($user['id'] ?? 0), ($comment !== '' ? $comment : null), $eventId]);
            db_log_activity((int)($user['id'] ?? 0), 'quote_' . $decision, json_encode(['event_id' => $eventId]));
            flash_add('success', 'Quotation ' . $decision . '.');
            header('Location: finance_dashboard.php');
            exit;
        }

        if ($action === 'ro_decision') {
            $roId = (int)($_POST['ro_id'] ?? 0);
            $decision = $_POST['decision'] ?? '';
            $comment = trim((string)($_POST['comment'] ?? ''));
            if ($roId <= 0) {
                throw new Exception('Invalid Release Order');
            }
            if (!in_array($decision, ['approved', 'rejected'], true)) {
                throw new Exception('Invalid decision');
            }

            $st = $db->prepare('SELECT status FROM release_orders WHERE id=?');
            $st->execute([$roId]);
            $status = (string)($st->fetchColumn() ?? '');
            if ($status !== 'submitted') {
                throw new Exception('Only submitted Release Orders can be reviewed');
            }

            $stmt = $db->prepare("UPDATE release_orders SET status=?, reviewed_by=?, reviewed_at=NOW(), finance_comment=? WHERE id=?");
            $stmt->execute([$decision, (int)($user['id'] ?? 0), ($comment !== '' ? $comment : null), $roId]);
            db_log_activity((int)($user['id'] ?? 0), 'release_order_' . $decision, json_encode(['ro_id' => $roId]));
            flash_add('success', 'Release Order ' . $decision . '.');
            header('Location: finance_dashboard.php');
            exit;
        }

        if ($action === 'purchase_decision') {
            $prId = (int)($_POST['pr_id'] ?? 0);
            $decision = $_POST['decision'] ?? '';
            $comment = trim((string)($_POST['comment'] ?? ''));
            if ($prId <= 0) {
                throw new Exception('Invalid purchase request');
            }
            if (!in_array($decision, ['approved', 'rejected'], true)) {
                throw new Exception('Invalid decision');
            }

            $st = $db->prepare('SELECT status FROM purchase_requests WHERE id=?');
            $st->execute([$prId]);
            $status = (string)($st->fetchColumn() ?? '');
            if ($status !== 'finance_submitted') {
                throw new Exception('Only Accountant-approved purchase requests can be reviewed by Finance');
            }

            $stmt = $db->prepare("UPDATE purchase_requests SET status=?, reviewed_by=?, reviewed_at=NOW(), finance_comment=? WHERE id=?");
            $stmt->execute([$decision, (int)($user['id'] ?? 0), ($comment !== '' ? $comment : null), $prId]);
            db_log_activity((int)($user['id'] ?? 0), 'purchase_request_' . $decision, json_encode(['pr_id' => $prId]));
            flash_add('success', 'Purchase request ' . $decision . '.');
            header('Location: finance_dashboard.php');
            exit;
        }
    } catch (Throwable $ex) {
        flash_add('error', $ex->getMessage());
        header('Location: finance_dashboard.php');
        exit;
    }
}

$events = [];
try {
    $stmt = $db->query("SELECT id, name, date, end_date, client, location, graphics, supervisors, amount, advance, balance, quote_file, checklist_file, mockup_file, quote_status, quote_reviewed_at, quote_finance_comment, created_at FROM events ORDER BY COALESCE(date, created_at) DESC, id DESC");
    $events = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable $e) {
    $events = [];
}

$pendingQuotes = array_values(array_filter($events, fn($e) => ((string)($e['quote_status'] ?? 'pending') === 'pending')));
$pendingQuotesLimited = array_slice($pendingQuotes, 0, 6);

$pendingReleaseOrders = [];
try {
    $stmt = $db->query("SELECT ro.*, e.name AS event_name, e.client AS event_client, e.location AS event_location, e.graphics AS event_graphics, e.supervisors AS event_supervisors FROM release_orders ro LEFT JOIN events e ON ro.event_id = e.id WHERE ro.status='submitted' ORDER BY ro.submitted_at DESC, ro.id DESC");
    $pendingReleaseOrders = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable $e) {
    $pendingReleaseOrders = [];
}
$pendingReleaseOrdersLimited = array_slice($pendingReleaseOrders, 0, 6);

$pendingPurchases = [];
try {
    $stmt = $db->query("SELECT pr.*, e.name AS event_name, e.client AS event_client, e.location AS event_location, e.graphics AS event_graphics, e.supervisors AS event_supervisors FROM purchase_requests pr LEFT JOIN events e ON pr.event_id = e.id WHERE pr.status='finance_submitted' ORDER BY pr.submitted_at DESC, pr.id DESC");
    $pendingPurchases = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable $e) {
    $pendingPurchases = [];
}
$pendingPurchasesLimited = array_slice($pendingPurchases, 0, 6);

$parse_id_list = function ($value): array {
    if ($value === null) return [];
    if (is_array($value)) {
        $arr = $value;
    } else {
        $str = trim((string)$value);
        if ($str === '' || $str === 'null') return [];
        $decoded = json_decode($str, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $arr = $decoded;
        } else {
            $arr = preg_split('/\s*,\s*/', trim($str, "[](){} \t\n\r\0\x0B\"") , -1, PREG_SPLIT_NO_EMPTY);
        }
    }
    $out = [];
    foreach ($arr as $v) {
        $id = (int)$v;
        if ($id > 0) $out[] = $id;
    }
    return array_values(array_unique($out));
};

$supervisorIds = [];
foreach ($pendingQuotesLimited as $e) {
    $supervisorIds = array_merge($supervisorIds, $parse_id_list($e['supervisors'] ?? null));
}
foreach ($pendingReleaseOrdersLimited as $ro) {
    $supervisorIds = array_merge($supervisorIds, $parse_id_list($ro['event_supervisors'] ?? null));
}
foreach ($pendingPurchasesLimited as $pr) {
    $supervisorIds = array_merge($supervisorIds, $parse_id_list($pr['event_supervisors'] ?? null));
}
$supervisorIds = array_values(array_unique(array_filter($supervisorIds, fn($id) => $id > 0)));

$supervisorNameById = [];
if ($supervisorIds) {
    $placeholders = implode(',', array_fill(0, count($supervisorIds), '?'));
    $st = $db->prepare("SELECT id, name FROM users WHERE id IN ($placeholders)");
    $st->execute($supervisorIds);
    foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
        $supervisorNameById[(int)$row['id']] = (string)$row['name'];
    }
}

$format_supervisors = function ($value) use ($parse_id_list, $supervisorNameById): string {
    $ids = $parse_id_list($value);
    if (!$ids) return '<span class="text-slate-400">-</span>';
    $names = [];
    foreach ($ids as $id) {
        $nm = $supervisorNameById[$id] ?? null;
        if ($nm) $names[] = $nm;
    }
    if (!$names) return '<span class="text-slate-400">-</span>';
    return htmlspecialchars(implode(', ', $names));
};

$totalEvents = count($events);
$totalAmount = 0.0;
$totalAdvance = 0.0;
$totalBalance = 0.0;
foreach ($events as $e) {
    $totalAmount += (float)($e['amount'] ?? 0);
    $totalAdvance += (float)($e['advance'] ?? 0);
    $totalBalance += (float)($e['balance'] ?? 0);
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
    <style>
        .content-section{background:#fff;border-radius:10px;padding:0.75rem;border:1px solid #e2e8f0}
        .section-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:0.75rem}
        .section-title{font-size:1.05rem;font-weight:600;color:#1e293b;margin:0}
        .table-container{overflow-x:auto;border-radius:8px;border:1px solid #e2e8f0;width:100%}
        .data-table{width:100%;border-collapse:collapse;min-width:900px}
        .data-table th{background:#f8fafc;padding:12px 10px;text-align:left;font-size:.8rem;font-weight:600;color:#475569;border-bottom:1px solid #e2e8f0;white-space:nowrap}
        .data-table td{padding:12px 10px;border-bottom:1px solid #e2e8f0;font-size:.85rem;color:#334155;vertical-align:middle}
        .data-table tr:hover{background:#f8fafc}
        .link-highlight{color:#2563eb;font-weight:600;text-decoration:none}
        .link-highlight:hover{text-decoration:underline}
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php $activePage = 'finance_dashboard.php'; include __DIR__ . '/sidebar.php'; ?>

    <main class="main-content">
        <div class="top-bar">
            <div class="page-title">
                <h1><?php echo htmlspecialchars($pageTitle); ?></h1>
                <p>Financial visibility for events</p>
            </div>
            <div class="user-menu">
                <div class="date-display"><i class="far fa-calendar-alt"></i> <?php echo date('l, F j, Y'); ?></div>
                <div class="user-profile" id="financeUserProfile">
                    <div class="avatar"><?php echo htmlspecialchars(strtoupper(mb_substr($displayName, 0, 1))); ?></div>
                    <div>
                        <div style="font-weight:600;"><?php echo htmlspecialchars($displayName); ?></div>
                        <div style="font-size:0.75rem;color:#64748b;"><?php echo htmlspecialchars(get_role_label($user['role'] ?? 'user')); ?></div>
                    </div>
                    <i class="fas fa-chevron-down" style="color:#64748b;"></i>
                    <div class="profile-dropdown" id="financeHeaderProfileDropdown">
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

        <div class="space-y-6">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mb-6">
                <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-2xl p-5 text-white shadow-lg hover:shadow-xl transition-shadow duration-300 flex flex-col justify-between" style="min-height: 140px;">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-sm font-medium text-blue-100">Total Events</p>
                            <h3 class="text-2xl font-bold mt-2"><?php echo number_format($totalEvents); ?></h3>
                            <div class="mt-2"><span class="text-xs bg-white bg-opacity-20 px-2 py-1 rounded-full">All events</span></div>
                        </div>
                        <div class="p-3 bg-white bg-opacity-20 rounded-xl"><i class="fas fa-calendar-alt text-xl"></i></div>
                    </div>
                    <div class="mt-4 pt-3" style="border-top: 1px solid rgba(255,255,255,0.25);">
                        <a href="events.php" style="display:inline-flex; align-items:center; gap:0.4rem; color: rgba(255,255,255,0.95); font-weight: 600; font-size: 0.875rem; text-decoration:none;">
                            View all events
                            <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>
                </div>

            <div class="bg-gradient-to-br from-emerald-500 to-emerald-600 rounded-2xl p-5 text-white shadow-lg hover:shadow-xl transition-shadow duration-300 flex flex-col justify-between" style="min-height: 140px;">
                <div class="flex justify-between items-start">
                    <div>
                        <p class="text-sm font-medium text-emerald-100">Total Amount</p>
                        <h3 class="text-2xl font-bold mt-2"><?php echo number_format($totalAmount, 2); ?></h3>
                        <div class="mt-2"><span class="text-xs bg-white bg-opacity-20 px-2 py-1 rounded-full">Gross</span></div>
                    </div>
                    <div class="p-3 bg-white bg-opacity-20 rounded-xl"><i class="fas fa-sack-dollar text-xl"></i></div>
                </div>
                <div class="mt-4 pt-3" style="border-top: 1px solid rgba(255,255,255,0.25);">
                    <a href="finance_dashboard.php" style="display:inline-flex; align-items:center; gap:0.4rem; color: rgba(255,255,255,0.95); font-weight: 600; font-size: 0.875rem; text-decoration:none;">
                        View report
                        <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
            </div>

            <div class="bg-gradient-to-br from-amber-500 to-amber-600 rounded-2xl p-5 text-white shadow-lg hover:shadow-xl transition-shadow duration-300 flex flex-col justify-between" style="min-height: 140px;">
                <div class="flex justify-between items-start">
                    <div>
                        <p class="text-sm font-medium text-amber-100">Total Advance</p>
                        <h3 class="text-2xl font-bold mt-2"><?php echo number_format($totalAdvance, 2); ?></h3>
                        <div class="mt-2"><span class="text-xs bg-white bg-opacity-20 px-2 py-1 rounded-full">Paid</span></div>
                    </div>
                    <div class="p-3 bg-white bg-opacity-20 rounded-xl"><i class="fas fa-hand-holding-dollar text-xl"></i></div>
                </div>
                <div class="mt-4 pt-3" style="border-top: 1px solid rgba(255,255,255,0.25);">
                    <a href="finance_dashboard.php" style="display:inline-flex; align-items:center; gap:0.4rem; color: rgba(255,255,255,0.95); font-weight: 600; font-size: 0.875rem; text-decoration:none;">
                        View report
                        <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
            </div>

            <div class="bg-gradient-to-br from-indigo-500 to-indigo-600 rounded-2xl p-5 text-white shadow-lg hover:shadow-xl transition-shadow duration-300 flex flex-col justify-between" style="min-height: 140px;">
                <div class="flex justify-between items-start">
                    <div>
                        <p class="text-sm font-medium text-indigo-100">Total Balance</p>
                        <h3 class="text-2xl font-bold mt-2"><?php echo number_format($totalBalance, 2); ?></h3>
                        <div class="mt-2"><span class="text-xs bg-white bg-opacity-20 px-2 py-1 rounded-full">Remaining</span></div>
                    </div>
                    <div class="p-3 bg-white bg-opacity-20 rounded-xl"><i class="fas fa-scale-balanced text-xl"></i></div>
                </div>
                <div class="mt-4 pt-3" style="border-top: 1px solid rgba(255,255,255,0.25);">
                    <a href="finance_dashboard.php" style="display:inline-flex; align-items:center; gap:0.4rem; color: rgba(255,255,255,0.95); font-weight: 600; font-size: 0.875rem; text-decoration:none;">
                        View report
                        <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
            </div>

            <div class="bg-gradient-to-br from-sky-500 to-sky-600 rounded-2xl p-5 text-white shadow-lg hover:shadow-xl transition-shadow duration-300 flex flex-col justify-between" style="min-height: 140px;">
                <div class="flex justify-between items-start">
                    <div>
                        <p class="text-sm font-medium text-sky-100">Pending Quotes</p>
                        <h3 class="text-2xl font-bold mt-2"><?php echo number_format(count($pendingQuotes)); ?></h3>
                        <div class="mt-2"><span class="text-xs bg-white bg-opacity-20 px-2 py-1 rounded-full">Needs review</span></div>
                    </div>
                    <div class="p-3 bg-white bg-opacity-20 rounded-xl"><i class="fas fa-file-invoice-dollar text-xl"></i></div>
                </div>
                <div class="mt-4 pt-3" style="border-top: 1px solid rgba(255,255,255,0.25);">
                    <a href="finance_quotes.php" style="display:inline-flex; align-items:center; gap:0.4rem; color: rgba(255,255,255,0.95); font-weight: 600; font-size: 0.875rem; text-decoration:none;">
                        View all
                        <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
            </div>

            <div class="bg-gradient-to-br from-rose-500 to-rose-600 rounded-2xl p-5 text-white shadow-lg hover:shadow-xl transition-shadow duration-300 flex flex-col justify-between" style="min-height: 140px;">
                <div class="flex justify-between items-start">
                    <div>
                        <p class="text-sm font-medium text-rose-100">Pending ROs</p>
                        <h3 class="text-2xl font-bold mt-2"><?php echo number_format(count($pendingReleaseOrders)); ?></h3>
                        <div class="mt-2"><span class="text-xs bg-white bg-opacity-20 px-2 py-1 rounded-full">Submitted</span></div>
                    </div>
                    <div class="p-3 bg-white bg-opacity-20 rounded-xl"><i class="fas fa-file-signature text-xl"></i></div>
                </div>
                <div class="mt-4 pt-3" style="border-top: 1px solid rgba(255,255,255,0.25);">
                    <a href="finance_release_orders.php" style="display:inline-flex; align-items:center; gap:0.4rem; color: rgba(255,255,255,0.95); font-weight: 600; font-size: 0.875rem; text-decoration:none;">
                        View all
                        <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
            </div>

                <div class="bg-gradient-to-br from-slate-600 to-slate-700 rounded-2xl p-5 text-white shadow-lg hover:shadow-xl transition-shadow duration-300 flex flex-col justify-between" style="min-height: 140px;">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-sm font-medium text-slate-200">Pending Purchases</p>
                            <h3 class="text-2xl font-bold mt-2"><?php echo number_format(count($pendingPurchases)); ?></h3>
                            <div class="mt-2"><span class="text-xs bg-white bg-opacity-20 px-2 py-1 rounded-full">Submitted</span></div>
                        </div>
                        <div class="p-3 bg-white bg-opacity-20 rounded-xl"><i class="fas fa-cart-shopping text-xl"></i></div>
                    </div>
                    <div class="mt-4 pt-3" style="border-top: 1px solid rgba(255,255,255,0.25);">
                        <a href="finance_purchase_requests.php" style="display:inline-flex; align-items:center; gap:0.4rem; color: rgba(255,255,255,0.95); font-weight: 600; font-size: 0.875rem; text-decoration:none;">
                            View all
                            <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>
                </div>

                <div class="bg-white rounded-2xl shadow-lg border border-gray-100 p-3 flex flex-col justify-between" style="min-height: 140px;">
                    <div>
                        <h3 class="text-md font-semibold text-gray-800 mb-2">Quick Actions</h3>
                        <div class="grid grid-cols-2 gap-2 quick-actions-grid">
                            <a href="finance_quotes.php" class="p-3 bg-sky-50 hover:bg-sky-100 rounded-xl text-center transition-colors">
                                <div class="w-9 h-9 bg-sky-100 text-sky-600 rounded-lg flex items-center justify-center mx-auto mb-2">
                                    <i class="fas fa-file-invoice-dollar text-sm"></i>
                                </div>
                                <span class="text-xs font-medium text-gray-700">Quotes</span>
                            </a>
                            <a href="finance_release_orders.php" class="p-3 bg-rose-50 hover:bg-rose-100 rounded-xl text-center transition-colors">
                                <div class="w-9 h-9 bg-rose-100 text-rose-600 rounded-lg flex items-center justify-center mx-auto mb-2">
                                    <i class="fas fa-file-signature text-sm"></i>
                                </div>
                                <span class="text-xs font-medium text-gray-700">Release Orders</span>
                            </a>
                            <a href="finance_purchase_requests.php" class="p-3 bg-indigo-50 hover:bg-indigo-100 rounded-xl text-center transition-colors">
                                <div class="w-9 h-9 bg-indigo-100 text-indigo-600 rounded-lg flex items-center justify-center mx-auto mb-2">
                                    <i class="fas fa-cart-shopping text-sm"></i>
                                </div>
                                <span class="text-xs font-medium text-gray-700">Purchases</span>
                            </a>
                            <a href="events.php" class="p-3 bg-green-50 hover:bg-green-100 rounded-xl text-center transition-colors">
                                <div class="w-9 h-9 bg-green-100 text-green-600 rounded-lg flex items-center justify-center mx-auto mb-2">
                                    <i class="fas fa-calendar-check text-sm"></i>
                                </div>
                                <span class="text-xs font-medium text-gray-700">Events</span>
                            </a>
                        </div>
                    </div>
                    <div class="mt-3 pt-3" style="border-top: 1px solid #e2e8f0;"></div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
                <div class="lg:col-span-12 space-y-6">
                    <div class="content-section">
                        <div class="section-header">
                            <h2 class="section-title">Quotation Approvals</h2>
                        </div>
                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Event</th>
                                    <th>Client</th>
                                    <th>Location</th>
                                    <th>Graphics</th>
                                    <th>Supervisor</th>
                                    <th>Amount</th>
                                    <th>Quote</th>
                                    <th>Decision</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php $qNum = 1; foreach ($pendingQuotesLimited as $e): ?>
                                    <tr>
                                        <td><?php echo (int)$qNum; ?></td>
                                        <td><?php echo htmlspecialchars($e['name'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($e['client'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars((string)($e['location'] ?? '')); ?></td>
                                        <td><?php echo !empty($e['graphics']) ? htmlspecialchars((string)$e['graphics']) : '<span class="text-slate-400">-</span>'; ?></td>
                                        <td><?php echo $format_supervisors($e['supervisors'] ?? null); ?></td>
                                        <td><?php echo htmlspecialchars(number_format((float)($e['amount'] ?? 0), 2)); ?></td>
                                        <td>
                                            <?php if (!empty($e['quote_file'])): ?>
                                                <a class="link-highlight" target="_blank" href="<?php echo htmlspecialchars((string)$e['quote_file']); ?>">View</a>
                                            <?php else: ?>
                                                <span class="text-slate-400">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <form method="post" style="display:grid; gap:0.35rem;">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                                <input type="hidden" name="action" value="quote_decision">
                                                <input type="hidden" name="event_id" value="<?php echo (int)$e['id']; ?>">
                                                <div style="display:flex; gap:0.5rem; align-items:center;">
                                                    <input type="text" class="form-input" name="comment" placeholder="Finance comment (optional)" style="min-width: 180px;">
                                                    <button type="submit" name="decision" value="approved" title="Approve" style="display:inline-flex; align-items:center; justify-content:center; padding:0.25rem; background:transparent; border:none; cursor:pointer;">
                                                        <i class="fas fa-check" style="color:#16a34a;"></i>
                                                    </button>
                                                    <button type="submit" name="decision" value="rejected" title="Reject" style="display:inline-flex; align-items:center; justify-content:center; padding:0.25rem; background:transparent; border:none; cursor:pointer;">
                                                        <i class="fas fa-times" style="color:#dc2626;"></i>
                                                    </button>
                                                </div>
                                            </form>
                                        </td>
                                    </tr>
                                <?php $qNum++; endforeach; ?>
                                <?php if (!$pendingQuotes): ?>
                                    <tr><td colspan="9" style="text-align:center;color:#64748b;padding:2rem;">No pending quotations</td></tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if (count($pendingQuotes) > 6): ?>
                            <div style="display:flex; justify-content:flex-end; padding:0.75rem 1rem;">
                                <a href="finance_quotes.php" class="link-highlight" style="display:inline-flex; align-items:center; gap:0.35rem;">
                                    View all
                                    <i class="fas fa-arrow-right"></i>
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="content-section">
                        <div class="section-header">
                            <h2 class="section-title">Release Order Approvals</h2>
                            <a href="finance_release_orders.php" class="link-highlight" style="display:inline-flex; align-items:center; gap:0.35rem;">
                                View all
                                <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Event</th>
                                    <th>Location</th>
                                    <th>Graphics</th>
                                    <th>Supervisor</th>
                                    <th>File</th>
                                    <th>Decision</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php $roNum = 1; foreach ($pendingReleaseOrdersLimited as $ro): ?>
                                    <tr>
                                        <td><?php echo (int)$roNum; ?></td>
                                        <td><?php echo htmlspecialchars((string)($ro['event_name'] ?? '')); ?></td>
                                        <td><?php echo htmlspecialchars((string)($ro['event_location'] ?? '')); ?></td>
                                        <td><?php echo !empty($ro['event_graphics']) ? htmlspecialchars((string)$ro['event_graphics']) : '<span class="text-slate-400">-</span>'; ?></td>
                                        <td><?php echo $format_supervisors($ro['event_supervisors'] ?? null); ?></td>
                                        <td><a class="link-highlight" target="_blank" href="release_orders.php?view=print&amp;ro_id=<?php echo (int)($ro['id'] ?? 0); ?>">View</a></td>
                                        <td>
                                            <form method="post" style="display:grid; gap:0.35rem;">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                                <input type="hidden" name="action" value="ro_decision">
                                                <input type="hidden" name="ro_id" value="<?php echo (int)$ro['id']; ?>">
                                                <div style="display:flex; gap:0.5rem; align-items:center;">
                                                    <input type="text" class="form-input" name="comment" placeholder="Finance comment (optional)" style="min-width: 180px;">
                                                    <button type="submit" name="decision" value="approved" title="Approve" style="display:inline-flex; align-items:center; justify-content:center; padding:0.25rem; background:transparent; border:none; cursor:pointer;">
                                                        <i class="fas fa-check" style="color:#16a34a;"></i>
                                                    </button>
                                                    <button type="submit" name="decision" value="rejected" title="Reject" style="display:inline-flex; align-items:center; justify-content:center; padding:0.25rem; background:transparent; border:none; cursor:pointer;">
                                                        <i class="fas fa-times" style="color:#dc2626;"></i>
                                                    </button>
                                                </div>
                                            </form>
                                        </td>
                                    </tr>
                                <?php $roNum++; endforeach; ?>
                                <?php if (!$pendingReleaseOrders): ?>
                                    <tr><td colspan="6" style="text-align:center;color:#64748b;padding:2rem;">No pending release orders</td></tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if (count($pendingReleaseOrders) > 6): ?>
                            <div style="display:flex; justify-content:flex-end; padding:0.75rem 1rem;">
                                <a href="finance_release_orders.php" class="link-highlight" style="display:inline-flex; align-items:center; gap:0.35rem;">
                                    View all
                                    <i class="fas fa-arrow-right"></i>
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="content-section">
                        <div class="section-header">
                            <h2 class="section-title">Purchase Request Approvals</h2>
                            <a href="finance_purchase_requests.php" class="link-highlight" style="display:inline-flex; align-items:center; gap:0.35rem;">
                                View all
                                <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Event</th>
                                    <th>Location</th>
                                    <th>Graphics</th>
                                    <th>Supervisor</th>
                                    <th>Title</th>
                                    <th>Amount</th>
                                    <th class="text-center">Action</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php $prNum = 1; foreach ($pendingPurchasesLimited as $pr): ?>
                                    <tr>
                                        <td><?php echo (int)$prNum; ?></td>
                                        <td>
                                            <?php if (!empty($pr['event_id'])): ?>
                                                <?php echo htmlspecialchars((string)($pr['event_name'] ?? '')); ?>
                                            <?php else: ?>
                                                <span class="text-slate-400">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo !empty($pr['event_id']) ? htmlspecialchars((string)($pr['event_location'] ?? '')) : '<span class="text-slate-400">-</span>'; ?></td>
                                        <td><?php echo (!empty($pr['event_id']) && !empty($pr['event_graphics'])) ? htmlspecialchars((string)$pr['event_graphics']) : '<span class="text-slate-400">-</span>'; ?></td>
                                        <td><?php echo !empty($pr['event_id']) ? $format_supervisors($pr['event_supervisors'] ?? null) : '<span class="text-slate-400">-</span>'; ?></td>
                                        <td><?php echo htmlspecialchars($pr['title'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars(number_format((float)($pr['amount'] ?? 0), 2)); ?></td>
                                        <td class="text-center">
                                            <a href="finance_purchase_requests.php?pr_id=<?php echo (int)$pr['id']; ?>" title="View Details" style="display:inline-flex; align-items:center; justify-content:center; padding:0.25rem; background:transparent; border:none; text-decoration:none;">
                                                <i class="fas fa-eye" style="color:#2563eb;"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php $prNum++; endforeach; ?>
                                <?php if (!$pendingPurchases): ?>
                                    <tr><td colspan="8" style="text-align:center;color:#64748b;padding:2rem;">No pending purchase requests</td></tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if (count($pendingPurchases) > 6): ?>
                            <div style="display:flex; justify-content:flex-end; padding:0.75rem 1rem;">
                                <a href="finance_purchase_requests.php" class="link-highlight" style="display:inline-flex; align-items:center; gap:0.35rem;">
                                    View all
                                    <i class="fas fa-arrow-right"></i>
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
  (function () {
    if (window.__financeProfileDropdownBound) return;
    window.__financeProfileDropdownBound = true;

    const profile = document.getElementById('financeUserProfile');
    const dropdown = document.getElementById('financeHeaderProfileDropdown');
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
