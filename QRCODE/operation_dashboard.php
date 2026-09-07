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
if (!in_array($role, ['operation', 'super', 'admin'], true)) {
    header('Location: index.php');
    exit;
}

$displayName = $user['name'] ?? $user['email'] ?? 'User';
$pageTitle = ($role === 'operation') ? 'Operation Dashboard' : 'Operation Overview';

$flashes = flash_consume();

$selectedMonth = $_GET['month'] ?? date('Y-m');
$currentMonth = date('Y-m');
$prevMonth = date('Y-m', strtotime($selectedMonth . ' -1 month'));
$nextMonth = date('Y-m', strtotime($selectedMonth . ' +1 month'));
if (!preg_match('/^\d{4}-\d{2}$/', (string)$selectedMonth)) {
    $selectedMonth = $currentMonth;
}

$opChartLabels = [];
$opChartDaily = [];
$opChartMonthlyTotal = 0;
$opDisplayMonth = date('F Y', strtotime($selectedMonth . '-01'));

$year = (int)substr((string)$selectedMonth, 0, 4);
$month = (int)substr((string)$selectedMonth, 5, 2);
$daysInMonth = (int)date('t', strtotime($selectedMonth . '-01'));

$countsByDay = [];
for ($day = 1; $day <= $daysInMonth; $day++) {
    $key = sprintf('%02d', $day);
    $opChartLabels[] = $key;
    $countsByDay[$key] = 0;
}

$chartError = '';
$chartRowCount = 0;

try {
    $chartSql = "SELECT DAY(`date`) AS day, COUNT(*) AS count
        FROM events
        WHERE YEAR(`date`) = ?
          AND MONTH(`date`) = ?
        GROUP BY DAY(`date`)";
    $chartStmt = $db->prepare($chartSql);
    $chartStmt->execute([$year, $month]);
    $rows = $chartStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $chartRowCount = count($rows);
    foreach ($rows as $r) {
        $d = sprintf('%02d', (int)($r['day'] ?? 0));
        if (isset($countsByDay[$d])) {
            $countsByDay[$d] = (int)($r['count'] ?? 0);
        }
    }
} catch (Throwable $e) {
    // Best-effort: keep zeros so chart still renders.
    $chartError = $e->getMessage();
}

foreach ($opChartLabels as $d) {
    $opChartDaily[] = $countsByDay[$d] ?? 0;
}

$opChartMonthlyTotal = array_sum($opChartDaily);

$labels = $opChartLabels;
$dailyCounts = $opChartDaily;
$displayMonth = $opDisplayMonth;

$recentActivities = [];
try {
    if (in_array($role, ['admin', 'super'], true)) {
        $activitiesWhere = '';
        $activitiesParams = [];
    } else {
        $activitiesWhere = 'WHERE a.user_id = ?';
        $activitiesParams = [(int)($user['id'] ?? 0)];
    }

    $st = $db->prepare("\
        SELECT a.*, u.name as user_name
        FROM activities a
        LEFT JOIN users u ON a.user_id = u.id
        $activitiesWhere
        ORDER BY a.created_at DESC
        LIMIT 3
    ");
    $st->execute($activitiesParams);
    $recentActivities = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $recentActivities = [];
}

function getActivityIcon($action) {
    $icons = [
        'login' => 'fas fa-sign-in-alt',
        'logout' => 'fas fa-sign-out-alt',
        'create' => 'fas fa-plus-circle',
        'update' => 'fas fa-edit',
        'delete' => 'fas fa-trash',
        'view' => 'fas fa-eye',
        'export' => 'fas fa-download',
        'import' => 'fas fa-upload',
        'payment' => 'fas fa-credit-card',
        'approve' => 'fas fa-check-circle',
        'reject' => 'fas fa-times-circle',
        'register' => 'fas fa-user-plus',
        'status_change' => 'fas fa-sync',
        'report' => 'fas fa-chart-bar'
    ];
    return $icons[strtolower($action ?? '')] ?? 'fas fa-circle';
}

function getActivityColor($action) {
    $colors = [
        'login' => 'text-green-600',
        'logout' => 'text-slate-600',
        'create' => 'text-blue-600',
        'update' => 'text-amber-600',
        'delete' => 'text-red-600',
        'view' => 'text-sky-600',
        'export' => 'text-green-600',
        'import' => 'text-blue-600',
        'payment' => 'text-green-600',
        'approve' => 'text-green-600',
        'reject' => 'text-red-600',
        'register' => 'text-blue-600',
        'status_change' => 'text-amber-600',
        'report' => 'text-sky-600'
    ];
    return $colors[strtolower($action ?? '')] ?? 'text-slate-600';
}

$approvedEvents = [];
$approvedEventsRowCount = 0;
$approvedEventsError = '';
$eventsTotalCount = 0;
$eventsHasSupervisorCount = 0;
$eventsHasSupervisorReqAssignCount = 0;
$eventsHasSupervisorsJsonCount = 0;

$hasEventsSupervisorsCol = false;
$hasSupervisorRequestsTable = false;
try {
    $chk = $db->query("SHOW COLUMNS FROM events LIKE 'supervisors'");
    $hasEventsSupervisorsCol = (bool)($chk && $chk->fetch(PDO::FETCH_ASSOC));
} catch (Throwable $e) {
    $hasEventsSupervisorsCol = false;
}
try {
    $chk = $db->query("SHOW TABLES LIKE 'supervisor_requests'");
    $hasSupervisorRequestsTable = (bool)($chk && $chk->fetch(PDO::FETCH_NUM));
} catch (Throwable $e) {
    $hasSupervisorRequestsTable = false;
}

try {
    $selectSupervisors = $hasEventsSupervisorsCol ? "e.`supervisors`," : "NULL AS `supervisors`,";
    $subSupervisorName = $hasSupervisorRequestsTable ? "COALESCE((
                SELECT u.name
                FROM supervisor_requests srx
                JOIN users u ON u.id = srx.assigned_to
                WHERE srx.event_id = e.id
                  AND srx.assigned_to IS NOT NULL
                ORDER BY COALESCE(srx.assigned_at, srx.created_at) DESC, srx.id DESC
                LIMIT 1
            ), '')" : "''";
    $whereParts = [];
    $whereParts[] = "TRIM(COALESCE(e.`supervisor`,'')) <> ''";
    if ($hasEventsSupervisorsCol) {
        $whereParts[] = "(e.`supervisors` IS NOT NULL AND CAST(e.`supervisors` AS CHAR) <> '' AND LOWER(CAST(e.`supervisors` AS CHAR)) <> 'null' AND CAST(e.`supervisors` AS CHAR) <> '[]')";
    }
    if ($hasSupervisorRequestsTable) {
        $whereParts[] = "EXISTS (SELECT 1 FROM supervisor_requests sr WHERE sr.event_id = e.id AND sr.assigned_to IS NOT NULL)";
    }
    $whereSql = implode(' OR ', $whereParts);

    $sql = "SELECT
            e.id,
            e.name,
            e.`date`,
            e.client,
            e.location,
            e.coordinator,
            e.`supervisor`,
            {$selectSupervisors}
            e.quote_status,
            e.status,
            e.`created_at`,
            {$subSupervisorName} AS supervisor_assigned_to_name
        FROM events e
        WHERE ({$whereSql})
        ORDER BY (
            CASE
                WHEN e.`date` IS NULL THEN e.`created_at`
                WHEN CAST(e.`date` AS CHAR) = '' THEN e.`created_at`
                WHEN CAST(e.`date` AS CHAR) = '0000-00-00' THEN e.`created_at`
                ELSE e.`date`
            END
        ) DESC, e.id DESC
        LIMIT 6";

    $stmt = $db->query($sql);
    $approvedEvents = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    $approvedEventsRowCount = count($approvedEvents);
} catch (Throwable $e) {
    $approvedEvents = [];
    $approvedEventsRowCount = 0;
    $approvedEventsError = $e->getMessage();
}

try {
    $eventsTotalCount = (int)($db->query("SELECT COUNT(*) FROM events")->fetchColumn() ?? 0);
} catch (Throwable $e) {
    $eventsTotalCount = 0;
}

try {
    $eventsHasSupervisorCount = (int)($db->query("SELECT COUNT(*) FROM events WHERE TRIM(COALESCE(`supervisor`,'')) <> ''")->fetchColumn() ?? 0);
} catch (Throwable $e) {
    $eventsHasSupervisorCount = 0;
}

try {
    $eventsHasSupervisorReqAssignCount = (int)($db->query("SELECT COUNT(DISTINCT event_id) FROM supervisor_requests WHERE assigned_to IS NOT NULL")->fetchColumn() ?? 0);
} catch (Throwable $e) {
    $eventsHasSupervisorReqAssignCount = 0;
}

try {
    if ($hasEventsSupervisorsCol) {
        $eventsHasSupervisorsJsonCount = (int)($db->query("SELECT COUNT(*) FROM events WHERE `supervisors` IS NOT NULL AND CAST(`supervisors` AS CHAR) <> '' AND LOWER(CAST(`supervisors` AS CHAR)) <> 'null' AND CAST(`supervisors` AS CHAR) <> '[]'")->fetchColumn() ?? 0);
    } else {
        $eventsHasSupervisorsJsonCount = 0;
    }
} catch (Throwable $e) {
    $eventsHasSupervisorsJsonCount = 0;
}

$approvedRoByEventId = [];
try {
    $stmt = $db->query("SELECT id, event_id FROM release_orders WHERE status='approved' ORDER BY reviewed_at DESC, id DESC");
    $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    foreach ($rows as $r) {
        $eid = (int)($r['event_id'] ?? 0);
        if ($eid <= 0) {
            continue;
        }
        if (!isset($approvedRoByEventId[$eid])) {
            $approvedRoByEventId[$eid] = (int)($r['id'] ?? 0);
        }
    }
} catch (Throwable $e) {
    $approvedRoByEventId = [];
}

$supervisors = [];
try {
    $supStmt = $db->query("SELECT id, name, email FROM users WHERE role = 'supervisor' AND is_active = 1 ORDER BY name");
    $supervisors = $supStmt ? $supStmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Exception $e) {
    $supervisors = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_abort();
    $action = $_POST['action'] ?? '';

    if ($action === 'assign_supervisor_to_event') {
        $requestId = (int)($_POST['request_id'] ?? 0);
        $supervisorUserId = (int)($_POST['supervisor_user_id'] ?? 0);

        if ($requestId > 0 && $supervisorUserId > 0) {
            $eventId = 0;
            try {
                $stmt = $db->prepare('SELECT event_id FROM supervisor_requests WHERE id = ?');
                $stmt->execute([$requestId]);
                $eventId = (int)($stmt->fetchColumn() ?? 0);
            } catch (Exception $e) {
                $eventId = 0;
            }

            $supName = '';
            try {
                $stmt = $db->prepare("SELECT name FROM users WHERE id = ? AND role = 'supervisor' AND is_active = 1");
                $stmt->execute([$supervisorUserId]);
                $supName = (string)($stmt->fetchColumn() ?? '');
            } catch (Exception $e) {
                $supName = '';
            }

            if ($eventId > 0 && $supName !== '') {
                try {
                    $db->beginTransaction();

                    $stmt = $db->prepare("UPDATE supervisor_requests SET assigned_to = ?, assigned_by = ?, status = 'assigned', assigned_at = NOW() WHERE id = ?");
                    $stmt->execute([$supervisorUserId, (int)($user['id'] ?? 0), $requestId]);

                    $stmt = $db->prepare('UPDATE events SET supervisor = ? WHERE id = ?');
                    $stmt->execute([strtoupper($supName), $eventId]);

                    $db->commit();
                    flash_add('success', 'Supervisor assigned to event successfully');
                } catch (Exception $e) {
                    try { $db->rollBack(); } catch (Exception $e2) {}
                    flash_add('error', 'Failed to assign supervisor');
                }
            } else {
                flash_add('error', 'Invalid event or supervisor');
            }
        } else {
            flash_add('error', 'Missing request or supervisor');
        }

        header('Location: operation_dashboard.php');
        exit;
    }

    if ($action === 'assign_supervisor_direct') {
        $eventId = (int)($_POST['event_id'] ?? 0);
        $supervisorUserId = (int)($_POST['supervisor_user_id'] ?? 0);

        if ($eventId <= 0 || $supervisorUserId <= 0) {
            flash_add('error', 'Missing event or supervisor.');
            header('Location: operation_dashboard.php');
            exit;
        }

        $supName = '';
        try {
            $stmt = $db->prepare("SELECT name FROM users WHERE id = ? AND role = 'supervisor' AND is_active = 1");
            $stmt->execute([$supervisorUserId]);
            $supName = (string)($stmt->fetchColumn() ?? '');
        } catch (Throwable $e) {
            $supName = '';
        }

        if ($supName === '') {
            flash_add('error', 'Invalid supervisor.');
            header('Location: operation_dashboard.php');
            exit;
        }

        try {
            $stmt = $db->prepare('UPDATE events SET supervisor = ? WHERE id = ?');
            $stmt->execute([strtoupper($supName), $eventId]);
            flash_add('success', 'Supervisor assigned to event.');
        } catch (Throwable $e) {
            flash_add('error', 'Failed to assign supervisor.');
        }

        header('Location: operation_dashboard.php');
        exit;
    }

    if ($action === 'create_purchase') {
        flash_add('error', 'Please use Purchase Requests page to request materials.');
        header('Location: purchase_requests.php');
        exit;
    }
}

$events = [];
try {
    $supName = $user['name'] ?? '';
    if ($supName !== '') {
        $stmt = $db->prepare("SELECT * FROM events WHERE UPPER(supervisor) = UPPER(?) ORDER BY date DESC, id DESC");
        $stmt->execute([$supName]);
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    $events = [];
}

$pendingRequests = [];
$assignedRequests = [];
try {
    $stmt = $db->query("\
        SELECT sr.*, e.name as event_name, e.date as event_date, e.client, e.location,\
               u1.name as requested_by_name\
        FROM supervisor_requests sr\
        JOIN events e ON sr.event_id = e.id\
        LEFT JOIN users u1 ON sr.requested_by = u1.id\
        WHERE sr.status = 'pending' AND sr.assigned_to IS NULL\
        ORDER BY sr.created_at DESC\
    ");
    $pendingRequests = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

    $stmt = $db->prepare("
        SELECT sr.*, e.name as event_name, e.date as event_date, e.client, e.location,
               u1.name as requested_by_name, u2.name as assigned_to_name
        FROM supervisor_requests sr
        JOIN events e ON sr.event_id = e.id
        LEFT JOIN users u1 ON sr.requested_by = u1.id
        LEFT JOIN users u2 ON sr.assigned_to = u2.id
        WHERE sr.assigned_to IS NOT NULL AND sr.status NOT IN ('cancelled')
        ORDER BY sr.created_at DESC
    ");
    $stmt->execute();
    $assignedRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $pendingRequests = [];
    $assignedRequests = [];
}

$myPurchases = [];
try {
    $stmt = $db->prepare("SELECT pr.*, e.name AS event_name, e.client AS event_client FROM purchase_requests pr LEFT JOIN events e ON pr.event_id = e.id WHERE pr.requested_by = ? OR ? IN ('admin','super') ORDER BY pr.created_at DESC, pr.id DESC");
    $stmt->execute([(int)($user['id'] ?? 0), $role]);
    $myPurchases = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $myPurchases = [];
}

$pendingOpRequestsCount = count($pendingRequests);
$assignedOpRequestsCount = count($assignedRequests);
$approvedEventsCount = count($approvedEvents);
$supervisorsCount = count($supervisors);
$approvedReleaseOrdersCount = 0;
try {
    $approvedReleaseOrdersCount = (int)($db->query("SELECT COUNT(*) FROM release_orders WHERE status='approved'")->fetchColumn() ?? 0);
} catch (Throwable $e) {
    $approvedReleaseOrdersCount = 0;
}
$myPurchasesCount = count($myPurchases);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($pageTitle); ?> | Hugo Domingo Events</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <link rel="stylesheet" href="style.css">
    <style>
        .content-section{background:#fff;border-radius:10px;padding:0.75rem;border:1px solid #e2e8f0}
        .section-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:0.75rem}
        .section-title{font-size:1.05rem;font-weight:600;color:#1e293b;margin:0}
        .table-container{overflow-x:auto;border-radius:8px;border:1px solid #e2e8f0;width:100%}
        .data-table{width:100%;border-collapse:collapse;min-width:100%}
        .data-table th{background:#f8fafc;padding:12px 10px;text-align:left;font-size:.8rem;font-weight:600;color:#475569;border-bottom:1px solid #e2e8f0;white-space:nowrap}
        .data-table td{padding:12px 10px;border-bottom:1px solid #e2e8f0;font-size:.85rem;color:#334155;vertical-align:middle}
        .data-table tr:hover{background:#f8fafc}
        .chart-wrap{height:320px;position:relative;}
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php $activePage = 'operation_dashboard.php'; include __DIR__ . '/sidebar.php'; ?>

    <main class="main-content">
        <div class="top-bar">
            <div class="page-title">
                <h1><?php echo htmlspecialchars($pageTitle); ?></h1>
                <p>Your assigned events and tasks</p>
            </div>
            <div class="user-menu">
                <div class="date-display">
                    <i class="far fa-calendar-alt mr-2"></i>
                    <?php echo date('l, F j, Y'); ?>
                </div>
                <div class="user-profile" id="userProfile">
                    <div class="avatar"><?php echo strtoupper(substr($displayName, 0, 1)); ?></div>
                    <div>
                        <div style="font-weight: 600;">
                            <?php echo htmlspecialchars($displayName); ?>
                        </div>
                        <div style="font-size: 0.75rem; color: #64748b;">
                            <?php echo get_role_label($user['role'] ?? 'user'); ?>
                        </div>
                    </div>
                    <i class="fas fa-chevron-down ml-2" style="color: #64748b;"></i>
                    <div class="profile-dropdown" id="profileDropdown">
                        <a href="profile.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"><i class="fas fa-user mr-2"></i> My Profile</a>
                        <a href="?logout" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"><i class="fas fa-sign-out-alt mr-2"></i> Logout</a>
                    </div>
                </div>
            </div>
        </div>

        <?php foreach ($flashes as $f): ?>
            <div class="mb-4 p-4 rounded-lg <?php echo ($f['type'] ?? '') === 'success' ? 'bg-green-100 text-green-800' : ((($f['type'] ?? '') === 'warning') ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800'); ?>">
                <?php echo htmlspecialchars((string)($f['message'] ?? '')); ?>
            </div>
        <?php endforeach; ?>

        <?php if (isset($_GET['debug_chart']) && (string)($_GET['debug_chart'] ?? '') === '1'): ?>
            <div class="mb-4 p-4 rounded-lg" style="background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;">
                <div style="font-weight:700;">Chart Debug</div>
                <div style="margin-top:0.35rem; font-size:0.85rem;">
                    Month: <b><?php echo htmlspecialchars($selectedMonth); ?></b>
                    <span style="margin-left:10px;">Rows: <b><?php echo (int)$chartRowCount; ?></b></span>
                    <span style="margin-left:10px;">Total: <b><?php echo (int)$opChartMonthlyTotal; ?></b></span>
                </div>
                <?php if ($chartError !== ''): ?>
                    <div style="margin-top:0.35rem; font-size:0.85rem;">Error: <?php echo htmlspecialchars($chartError); ?></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-5 mb-6">
            <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-2xl p-5 text-white shadow-lg hover:shadow-xl transition-shadow duration-300">
                <div class="flex justify-between items-start">
                    <div>
                        <p class="text-sm font-medium text-green-100">Approved Events</p>
                        <h3 class="text-2xl font-bold mt-1"><?php echo number_format($approvedEventsCount); ?></h3>
                        <div class="flex items-center mt-2">
                            <span class="text-xs bg-white bg-opacity-20 px-2 py-1 rounded-full">Quote approved</span>
                        </div>
                    </div>
                    <div class="p-3 bg-white bg-opacity-20 rounded-xl">
                        <i class="fas fa-circle-check text-xl"></i>
                    </div>
                </div>
            </div>

            <div class="bg-gradient-to-br from-indigo-500 to-indigo-600 rounded-2xl p-5 text-white shadow-lg hover:shadow-xl transition-shadow duration-300">
                <div class="flex justify-between items-start">
                    <div>
                        <p class="text-sm font-medium text-indigo-100">Supervisors</p>
                        <h3 class="text-2xl font-bold mt-1"><?php echo number_format($supervisorsCount); ?></h3>
                        <div class="flex items-center mt-2">
                            <span class="text-xs bg-white bg-opacity-20 px-2 py-1 rounded-full">Registered</span>
                        </div>
                    </div>
                    <div class="p-3 bg-white bg-opacity-20 rounded-xl">
                        <i class="fas fa-user-tie text-xl"></i>
                    </div>
                </div>
            </div>

            <div class="bg-gradient-to-br from-rose-500 to-rose-600 rounded-2xl p-5 text-white shadow-lg hover:shadow-xl transition-shadow duration-300">
                <div class="flex justify-between items-start">
                    <div>
                        <p class="text-sm font-medium text-rose-100">My Purchase Requests</p>
                        <h3 class="text-2xl font-bold mt-1"><?php echo number_format($myPurchasesCount); ?></h3>
                        <div class="flex items-center mt-2">
                            <span class="text-xs bg-white bg-opacity-20 px-2 py-1 rounded-full">Tracking</span>
                        </div>
                    </div>
                    <div class="p-3 bg-white bg-opacity-20 rounded-xl">
                        <i class="fas fa-receipt text-xl"></i>
                    </div>
                </div>
            </div>

            <a href="release_orders.php" class="block bg-gradient-to-br from-purple-500 to-purple-600 rounded-2xl p-5 text-white shadow-lg hover:shadow-xl transition-shadow duration-300">
                <div class="flex justify-between items-start">
                    <div>
                        <p class="text-sm font-medium text-purple-100">Release Orders</p>
                        <h3 class="text-2xl font-bold mt-1"><?php echo number_format($approvedReleaseOrdersCount); ?></h3>
                        <div class="flex items-center mt-2">
                            <span class="text-xs bg-white bg-opacity-20 px-2 py-1 rounded-full">Approved</span>
                        </div>
                    </div>
                    <div class="p-3 bg-white bg-opacity-20 rounded-xl">
                        <i class="fas fa-file-signature text-xl"></i>
                    </div>
                </div>
            </a>

            <div class="bg-gradient-to-br from-sky-500 to-sky-600 rounded-2xl p-5 text-white shadow-lg hover:shadow-xl transition-shadow duration-300">
                <div class="flex justify-between items-start">
                    <div>
                        <p class="text-sm font-medium text-sky-100">Assigned Requests</p>
                        <h3 class="text-2xl font-bold mt-1"><?php echo number_format($assignedOpRequestsCount); ?></h3>
                        <div class="flex items-center mt-2">
                            <span class="text-xs bg-white bg-opacity-20 px-2 py-1 rounded-full">In progress</span>
                        </div>
                    </div>
                    <div class="p-3 bg-white bg-opacity-20 rounded-xl">
                        <i class="fas fa-list-check text-xl"></i>
                    </div>
                </div>
            </div>
        </div>

<div class="grid grid-cols-1 xl:grid-cols-12 gap-6">
    <div class="xl:col-span-8" style="min-width: 0;">

      <!-- Events Chart -->
      <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden w-full">
        <div class="p-5 border-b border-gray-100">
          <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
            <div>
              <h3 class="text-lg font-semibold text-gray-800">Events Trend - <?php echo htmlspecialchars($displayMonth); ?></h3>
              <p class="text-sm text-gray-500">Daily events overview</p>
            </div>
            <div class="mt-3 sm:mt-0 flex items-center space-x-2">
              <div class="relative">
                <select class="appearance-none bg-white border border-gray-200 rounded-lg pl-3 pr-8 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500" onchange="location = '?month=' + this.value;">
                  <?php
                    for ($i = 0; $i < 12; $i++) {
                        $monthValue = date('Y-m', strtotime("-$i months"));
                        $monthDisplay = date('F Y', strtotime($monthValue . '-01'));
                        $selected = ($monthValue === $selectedMonth) ? 'selected' : '';
                        echo "<option value=\"" . htmlspecialchars($monthValue) . "\" $selected>" . htmlspecialchars($monthDisplay) . "</option>";
                    }
                  ?>
                </select>
                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-gray-700">
                  <i class="fas fa-chevron-down text-xs"></i>
                </div>
              </div>
              <div class="flex border border-gray-200 rounded-lg overflow-hidden">
                <a href="?month=<?php echo htmlspecialchars($prevMonth); ?>" class="px-3 py-2 bg-white hover:bg-gray-50 text-gray-700" title="Previous Month">
                  <i class="fas fa-chevron-left text-xs"></i>
                </a>
                <a href="?month=<?php echo htmlspecialchars($currentMonth); ?>" class="px-3 py-2 bg-white hover:bg-gray-50 text-gray-700 border-l border-r border-gray-200" title="Current Month">
                  <i class="far fa-calendar-alt text-xs"></i>
                </a>
                <a href="?month=<?php echo htmlspecialchars($nextMonth); ?>" class="px-3 py-2 bg-white hover:bg-gray-50 text-gray-700" title="Next Month">
                  <i class="fas fa-chevron-right text-xs"></i>
                </a>
              </div>
            </div>
          </div>
        </div>
        <div class="p-5 pt-0">
          <div class="h-80">
            <canvas id="eventsChart" class="w-full h-full"></canvas>
          </div>
        </div>
        <div class="px-5 py-3 bg-gray-50 border-t border-gray-100 flex flex-col sm:flex-row justify-between items-center">
          <p class="text-sm text-gray-500 mb-2 sm:mb-0">
            <span class="font-medium text-gray-700"><?php echo (int)$opChartMonthlyTotal; ?></span> events in <?php echo htmlspecialchars($displayMonth); ?>
          </p>
          <div class="flex space-x-3">
            <span class="flex items-center text-xs text-gray-500">
              <span class="w-2 h-2 rounded-full bg-blue-500 mr-1.5"></span>
              <span>Daily Events</span>
            </span>
          </div>
        </div>
      </div>

    </div>

    <!-- Right Column - Sidebar -->
    <div class="space-y-6 xl:col-span-4" style="min-width:0;">
      <!-- Quick Actions -->
      <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4">
        <h3 class="text-md font-semibold text-gray-800 mb-3">Quick Actions</h3>
        <div class="grid grid-cols-2 gap-2 quick-actions-grid">
          <a href="register_supervisor.php" class="p-2 bg-blue-50 hover:bg-blue-100 rounded-lg text-center transition-colors">
            <div class="w-8 h-8 bg-blue-100 text-blue-600 rounded-md flex items-center justify-center mx-auto mb-1">
              <i class="fas fa-user-plus text-sm"></i>
            </div>
            <span class="text-xs font-medium text-gray-700">Register Supervisor</span>
          </a>
          <a href="events.php" class="p-2 bg-green-50 hover:bg-green-100 rounded-lg text-center transition-colors">
            <div class="w-8 h-8 bg-green-100 text-green-600 rounded-md flex items-center justify-center mx-auto mb-1">
              <i class="fas fa-calendar-check text-sm"></i>
            </div>
            <span class="text-xs font-medium text-gray-700">All Events</span>
          </a>
          <a href="release_orders.php" class="p-2 bg-purple-50 hover:bg-purple-100 rounded-lg text-center transition-colors">
            <div class="w-8 h-8 bg-purple-100 text-purple-600 rounded-md flex items-center justify-center mx-auto mb-1">
              <i class="fas fa-file-signature text-sm"></i>
            </div>
            <span class="text-xs font-medium text-gray-700">Release Orders</span>
          </a>
          <a href="purchase_requests.php" class="p-2 bg-amber-50 hover:bg-amber-100 rounded-lg text-center transition-colors">
            <div class="w-8 h-8 bg-amber-100 text-amber-600 rounded-md flex items-center justify-center mx-auto mb-1">
              <i class="fas fa-cart-shopping text-sm"></i>
            </div>
            <span class="text-xs font-medium text-gray-700">Purchase Requests</span>
          </a>
        </div>
      </div>

      <!-- Recent Activity -->
      <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="p-5 border-b border-gray-100">
          <div class="flex justify-between items-center">
            <div>
              <h3 class="text-lg font-semibold text-gray-800">Recent Activity</h3>
              <p class="text-xs text-gray-500 mt-1">Showing your latest 3 activities</p>
            </div>
            <a href="activities.php" class="inline-flex items-center px-3 py-1.5 border border-gray-300 rounded-lg text-xs font-medium text-gray-700 bg-white hover:bg-gray-50">
              View full history
              <i class="fas fa-arrow-right ml-1 text-[10px]"></i>
            </a>
          </div>
        </div>
        <div class="divide-y divide-gray-100">
          <?php if (!empty($recentActivities)): ?>
            <?php foreach ($recentActivities as $activity):
              $activityIcon  = getActivityIcon($activity['action'] ?? '');
              $activityColor = getActivityColor($activity['action'] ?? '');

              $rawDescription = trim((string)($activity['description'] ?? ''));
              $actionKey = strtolower((string)($activity['action'] ?? ''));
              $fallbackLabel = '';

              if ($actionKey === 'event_create') {
                  $fallbackLabel = 'Event created';
              } elseif ($actionKey === 'event_update') {
                  $fallbackLabel = 'Event updated';
              } elseif ($actionKey === 'event_delete') {
                  $fallbackLabel = 'Event deleted';
              } elseif ($actionKey === 'login') {
                  $fallbackLabel = 'User logged in';
              } elseif ($actionKey === 'logout') {
                  $fallbackLabel = 'User logged out';
              } else {
                  $fallbackLabel = 'Activity recorded';
              }

              $displayDescription = $rawDescription !== '' ? $rawDescription : $fallbackLabel;

              $createdAtText = 'Time not available';
              if (!empty($activity['created_at'])) {
                  try {
                      $dt = new DateTime($activity['created_at']);
                      $createdAtText = $dt->format('M j, Y H:i');
                  } catch (Exception $e) {
                      $createdAtText = (string)$activity['created_at'];
                  }
              }
            ?>
              <div class="p-4">
                <div class="flex items-start">
                  <div class="flex-shrink-0 mt-0.5">
                    <div class="h-9 w-9 rounded-full bg-slate-100 flex items-center justify-center">
                      <i class="<?php echo htmlspecialchars($activityIcon); ?> <?php echo htmlspecialchars($activityColor); ?>"></i>
                    </div>
                  </div>
                  <div class="ml-3 flex-1 min-w-0">
                    <p class="text-sm font-semibold text-gray-900 leading-tight"><?php echo htmlspecialchars($displayDescription); ?></p>
                    <p class="text-xs text-gray-500 mt-0.5">
                      <?php echo !empty($activity['user_name']) ? htmlspecialchars((string)$activity['user_name']) : 'System'; ?>
                      <span class="ml-1 text-gray-400"><?php echo htmlspecialchars($createdAtText); ?></span>
                    </p>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="text-center py-8 text-gray-500">
              <i class="fas fa-inbox text-3xl mb-2 block"></i>
              <p>No recent activities found</p>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
</div>

<div class="mt-6 space-y-6">

<?php if (isset($_GET['debug_approved']) && (string)($_GET['debug_approved'] ?? '') === '1'): ?>
    <div class="mb-4 p-4 rounded-lg" style="background:#eff6ff;border:1px solid #bfdbfe;color:#1e3a8a;">
        <div style="font-weight:700;">Events With Supervisor Debug</div>
        <div style="margin-top:0.35rem; font-size:0.85rem;">
            Rows: <b><?php echo (int)$approvedEventsRowCount; ?></b>
            <span style="margin-left:10px;">Events Total: <b><?php echo (int)$eventsTotalCount; ?></b></span>
            <span style="margin-left:10px;">events.supervisor set: <b><?php echo (int)$eventsHasSupervisorCount; ?></b></span>
            <span style="margin-left:10px;">supervisor_requests assigned: <b><?php echo (int)$eventsHasSupervisorReqAssignCount; ?></b></span>
            <span style="margin-left:10px;">events.supervisors json set: <b><?php echo (int)$eventsHasSupervisorsJsonCount; ?></b></span>
        </div>
        <?php if ($approvedEventsError !== ''): ?>
            <div style="margin-top:0.35rem; font-size:0.85rem;">Error: <?php echo htmlspecialchars($approvedEventsError); ?></div>
        <?php endif; ?>
    </div>
<?php endif; ?>

        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden w-full">
            <div class="p-5 border-b border-gray-100 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h3 class="text-lg font-semibold text-gray-800">Events With Supervisor</h3>
                    <div class="mt-1 text-xs text-gray-500">
                        Total: <span class="font-semibold"><?php echo number_format(count($approvedEvents)); ?></span>
                    </div>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-100">
                    <thead class="bg-gray-50">
                    <tr>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">#</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Event</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Client</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Location</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Supervisor</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Date</th>
                        <th class="px-5 py-3 text-center text-xs font-semibold text-gray-500 uppercase tracking-wider">View</th>
                        <th class="px-5 py-3 text-center text-xs font-semibold text-gray-500 uppercase tracking-wider">Change</th>
                    </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-100">
                    <?php if (empty($approvedEvents)): ?>
                        <tr>
                            <td colspan="8" class="px-5 py-8 text-center text-sm text-gray-500">
                                No events with supervisor.
                                <?php if (!empty($approvedEventsError)): ?>
                                    <div style="margin-top:0.5rem; font-size:0.8rem; color:#dc2626;">Query error: <?php echo htmlspecialchars((string)$approvedEventsError); ?></div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php $evNo = 1; foreach ($approvedEvents as $ev): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-5 py-3 text-sm text-gray-600"><?php echo (int)$evNo; ?></td>
                                <td class="px-5 py-3 text-sm font-medium text-gray-900"><?php echo htmlspecialchars($ev['name'] ?? ''); ?></td>
                                <td class="px-5 py-3 text-sm text-gray-700"><?php echo htmlspecialchars($ev['client'] ?? ''); ?></td>
                                <td class="px-5 py-3 text-sm text-gray-700"><?php echo htmlspecialchars($ev['location'] ?? ''); ?></td>
                                <td class="px-5 py-3 text-sm text-gray-700">
                                    <?php
                                        $supText = trim((string)($ev['supervisor'] ?? ''));
                                        if ($supText === '') {
                                            $supText = trim((string)($ev['supervisor_assigned_to_name'] ?? ''));
                                        }
                                        if ($supText === '') {
                                            $raw = (string)($ev['supervisors'] ?? '');
                                            if ($raw !== '' && strtolower($raw) !== 'null' && $raw !== '[]') {
                                                try {
                                                    $decoded = json_decode($raw, true);
                                                    if (is_array($decoded)) {
                                                        $names = [];
                                                        foreach ($decoded as $it) {
                                                            if (is_string($it)) {
                                                                $t = trim($it);
                                                                if ($t !== '') $names[] = $t;
                                                            } elseif (is_array($it)) {
                                                                $t = trim((string)($it['name'] ?? $it['email'] ?? ''));
                                                                if ($t !== '') $names[] = $t;
                                                            }
                                                        }
                                                        if (!empty($names)) {
                                                            $supText = implode(', ', $names);
                                                        }
                                                    }
                                                } catch (Throwable $e) {
                                                }
                                            }
                                        }
                                        echo htmlspecialchars($supText);
                                    ?>
                                </td>
                                <td class="px-5 py-3 text-sm text-gray-700"><?php echo !empty($ev['date']) ? htmlspecialchars($ev['date']) : 'TBD'; ?></td>
                                <td class="px-5 py-3 text-center">
                                    <a href="events.php?id=<?php echo (int)($ev['id'] ?? 0); ?>&mode=details" title="View" style="display:inline-flex; align-items:center; justify-content:center; padding:0.25rem; background:transparent; border:none; text-decoration:none;">
                                        <i class="fas fa-eye" style="color:#3b82f6;"></i>
                                    </a>
                                </td>
                                <td class="px-5 py-3 text-center">
                                    <a href="events.php?action=edit_supervisor&id=<?php echo (int)($ev['id'] ?? 0); ?>" title="Change Supervisor" style="display:inline-flex; align-items:center; justify-content:center; padding:0.25rem; background:transparent; border:none; text-decoration:none;">
                                        <i class="fas fa-pen-to-square" style="color:#f59e0b;"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php $evNo++; endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="px-5 py-4 border-t border-gray-100 bg-white">
                <a href="events.php" class="text-sm font-medium text-blue-600 hover:text-blue-800 inline-flex items-center gap-2">
                    View all
                    <i class="fas fa-arrow-down"></i>
                </a>
            </div>
        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden w-full">
            <div class="p-5 border-b border-gray-100 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h3 class="text-lg font-semibold text-gray-800">Operational Purchase Requests</h3>
                    <div class="mt-1 text-xs text-gray-500">
                        Total: <span class="font-semibold"><?php echo number_format(count($myPurchases)); ?></span>
                    </div>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-100">
                    <thead class="bg-gray-50">
                    <tr>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">#</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Event</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Title</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Finance Comment</th>
                        <th class="px-5 py-3 text-center text-xs font-semibold text-gray-500 uppercase tracking-wider">View</th>
                    </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-100">
                    <?php if (empty($myPurchases)): ?>
                        <tr>
                            <td colspan="6" class="px-5 py-8 text-center text-sm text-gray-500">No purchase requests found.</td>
                        </tr>
                    <?php else: ?>
                        <?php $prNo = 1; foreach ($myPurchases as $pr): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-5 py-3 text-sm text-gray-600"><?php echo (int)$prNo; ?></td>
                                <td class="px-5 py-3 text-sm text-gray-700"><?php echo htmlspecialchars((string)($pr['event_name'] ?? '-')); ?></td>
                                <td class="px-5 py-3 text-sm font-medium text-gray-900"><?php echo htmlspecialchars($pr['title'] ?? ''); ?></td>
                                <td class="px-5 py-3 text-sm text-gray-700"><?php echo htmlspecialchars($pr['status'] ?? ''); ?></td>
                                <td class="px-5 py-3 text-xs text-gray-600" style="white-space: pre-wrap;"><?php echo htmlspecialchars($pr['finance_comment'] ?? ''); ?></td>
                                <td class="px-5 py-3 text-center">
                                    <a href="purchase_requests.php" title="View Purchase Requests" style="display:inline-flex; align-items:center; justify-content:center; padding:0.25rem; background:transparent; border:none; text-decoration:none;">
                                        <i class="fas fa-eye" style="color:#2563eb;"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php $prNo++; endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="px-5 py-4 border-t border-gray-100 bg-white">
                <a href="purchase_requests.php" class="text-sm font-medium text-blue-600 hover:text-blue-800 inline-flex items-center gap-2">
                    View all
                    <i class="fas fa-arrow-down"></i>
                </a>
            </div>
        </div>
 </div>
    </main>
</div>

<script>
(function(){
    const profile = document.getElementById('userProfile');
    const dropdown = document.getElementById('profileDropdown');
    if (profile && dropdown) {
        profile.addEventListener('click', function(e){
            e.stopPropagation();
            dropdown.classList.toggle('show');
        });
        document.addEventListener('click', function(){ dropdown.classList.remove('show'); });
    }

    const labels = <?php echo json_encode($labels); ?>;
    const daily = <?php echo json_encode($dailyCounts); ?>;
    const canvas = document.getElementById('eventsChart');
    if (canvas && labels && labels.length) {
        try {
            new Chart(canvas, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Daily Events',
                        data: daily,
                        borderColor: '#3b82f6',
                        borderWidth: 2,
                        backgroundColor: 'rgba(59,130,246,0.1)',
                        fill: true,
                        tension: 0.4,
                        cubicInterpolationMode: 'monotone',
                        pointBackgroundColor: '#3b82f6',
                        pointBorderColor: '#fff',
                        pointHoverRadius: 5,
                        pointHoverBackgroundColor: '#3b82f6',
                        pointHoverBorderColor: '#fff',
                        pointHitRadius: 10,
                        pointBorderWidth: 2,
                        pointRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { intersect: false, mode: 'index' },
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top',
                            labels: {
                                boxWidth: 12,
                                padding: 20,
                                usePointStyle: true,
                                pointStyle: 'circle'
                            }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(0, 0, 0, 0.8)',
                            titleFont: { size: 13, weight: 'bold' },
                            bodyFont: { size: 13 },
                            padding: 12,
                            usePointStyle: true,
                            callbacks: {
                                label: function(context) {
                                    let label = context.dataset.label || '';
                                    if (label) label += ': ';
                                    if (context.parsed.y !== null) {
                                        label += context.parsed.y + ' event' + (context.parsed.y !== 1 ? 's' : '');
                                    }
                                    return label;
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: { display: false, drawBorder: false },
                            ticks: { maxRotation: 45, minRotation: 45 }
                        },
                        y: {
                            beginAtZero: true,
                            grid: { drawBorder: false, color: 'rgba(0, 0, 0, 0.05)' },
                            ticks: { precision: 0 }
                        }
                    },
                    elements: {
                        line: { borderWidth: 2, fill: 'start' },
                        point: { hoverRadius: 6 }
                    }
                }
            });
        } catch (e) {
            console.error('Chart error:', e);
        }
    }
})();
</script>
</body>
</html>
