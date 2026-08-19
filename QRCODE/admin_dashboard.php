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

$roleNorm = strtolower(trim((string)($user['role'] ?? 'user')));
$roleNorm = str_replace(['_', '-'], ' ', $roleNorm);
$roleNorm = preg_replace('/\s+/', ' ', $roleNorm);

if (!in_array($roleNorm, ['admin', 'super'], true)) {
    header('Location: index.php');
    exit;
}

if ($roleNorm === 'super') {
    header('Location: super_dashboard.php');
    exit;
}

$displayName = $user['name'] ?? $user['email'] ?? 'User';
$pageTitle = 'Administrator Dashboard';

$flashes = flash_consume();

$selectedMonth = $_GET['month'] ?? date('Y-m');
$currentMonth = date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', (string)$selectedMonth)) {
    $selectedMonth = $currentMonth;
}
$prevMonth = date('Y-m', strtotime($selectedMonth . ' -1 month'));
$nextMonth = date('Y-m', strtotime($selectedMonth . ' +1 month'));
$labels = [];
$dailyCounts = [];
$monthlyTotal = 0;
$displayMonth = '';

$totalEvents = 0;
$upcomingEvents = 0;
$completedEvents = 0;
$cancelledEvents = 0;
$pendingQuotes = 0;
$pendingROs = 0;
$pendingPRs = 0;

$grAssigned = 0;
$grInProgress = 0;
$grCompleted = 0;

$ptReceived = 0;
$ptInProgress = 0;
$ptPrinted = 0;
$ptDelivered = 0;

$prodQueued = 0;
$prodPrinting = 0;
$prodInProduction = 0;
$prodReady = 0;
$prodDelivered = 0;

$recentGraphic = [];
$recentPrintTasks = [];
$recentProductionEvents = [];

$grTotal = 0;
$ptTotal = 0;
$prodTotal = 0;

$grAssignedPct = 0;
$grInProgressPct = 0;
$grCompletedPct = 0;

$ptReceivedPct = 0;
$ptInProgressPct = 0;
$ptPrintedPct = 0;
$ptDeliveredPct = 0;

$prodQueuedPct = 0;
$prodPrintingPct = 0;
$prodInProductionPct = 0;
$prodReadyPct = 0;
$prodDeliveredPct = 0;

try {
    $totalEvents = (int)($db->query("SELECT COUNT(*) FROM events")->fetchColumn() ?? 0);
    $upcomingEvents = (int)($db->query("SELECT COUNT(*) FROM events WHERE (end_date IS NULL AND date >= CURDATE()) OR (end_date IS NOT NULL AND end_date >= CURDATE())")->fetchColumn() ?? 0);
    $completedEvents = (int)($db->query("SELECT COUNT(*) FROM events WHERE (end_date IS NOT NULL AND end_date < CURDATE()) OR (end_date IS NULL AND date < CURDATE()) OR status = 'COMPLETED'")->fetchColumn() ?? 0);
    $cancelledEvents = (int)($db->query("SELECT COUNT(*) FROM events WHERE status = 'cancelled'")->fetchColumn() ?? 0);

    $pendingQuotes = (int)($db->query("SELECT COUNT(*) FROM events WHERE quote_status = 'pending'")->fetchColumn() ?? 0);
    $pendingROs = (int)($db->query("SELECT COUNT(*) FROM release_orders WHERE status = 'submitted'")->fetchColumn() ?? 0);
    $pendingPRs = (int)($db->query("SELECT COUNT(*) FROM purchase_requests WHERE status = 'submitted'")->fetchColumn() ?? 0);

    $grAssigned = (int)($db->query("SELECT COUNT(*) FROM graphic_requests WHERE status = 'assigned'")->fetchColumn() ?? 0);
    $grInProgress = (int)($db->query("SELECT COUNT(*) FROM graphic_requests WHERE status = 'in_progress'")->fetchColumn() ?? 0);
    $grCompleted = (int)($db->query("SELECT COUNT(*) FROM graphic_requests WHERE status = 'completed'")->fetchColumn() ?? 0);

    $ptReceived = (int)($db->query("SELECT COUNT(*) FROM print_tasks WHERE status = 'received'")->fetchColumn() ?? 0);
    $ptInProgress = (int)($db->query("SELECT COUNT(*) FROM print_tasks WHERE status = 'in_progress'")->fetchColumn() ?? 0);
    $ptPrinted = (int)($db->query("SELECT COUNT(*) FROM print_tasks WHERE status = 'printed'")->fetchColumn() ?? 0);
    $ptDelivered = (int)($db->query("SELECT COUNT(*) FROM print_tasks WHERE status = 'delivered'")->fetchColumn() ?? 0);

    $prodQueued = (int)($db->query("SELECT COUNT(*) FROM events WHERE COALESCE(production_status,'queued') = 'queued'")->fetchColumn() ?? 0);
    $prodPrinting = (int)($db->query("SELECT COUNT(*) FROM events WHERE production_status = 'printing'")->fetchColumn() ?? 0);
    $prodInProduction = (int)($db->query("SELECT COUNT(*) FROM events WHERE production_status = 'in_production'")->fetchColumn() ?? 0);
    $prodReady = (int)($db->query("SELECT COUNT(*) FROM events WHERE production_status = 'ready'")->fetchColumn() ?? 0);
    $prodDelivered = (int)($db->query("SELECT COUNT(*) FROM events WHERE production_status = 'delivered'")->fetchColumn() ?? 0);

    $stmt = $db->query("SELECT gr.id, gr.status, gr.created_at, e.name AS event_name, e.client AS event_client FROM graphic_requests gr JOIN events e ON gr.event_id = e.id ORDER BY gr.id DESC LIMIT 5");
    $recentGraphic = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

    $stmt = $db->query("SELECT pt.id, pt.status, pt.title, pt.qty, pt.created_at, pt.source, e.name AS event_name FROM print_tasks pt JOIN events e ON pt.event_id = e.id ORDER BY pt.id DESC LIMIT 5");
    $recentPrintTasks = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

    $stmt = $db->query("SELECT id, name, client, date, production_status, production_updated_at FROM events WHERE production_status IS NOT NULL ORDER BY COALESCE(production_updated_at, created_at) DESC, id DESC LIMIT 5");
    $recentProductionEvents = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

    $year = substr($selectedMonth, 0, 4);
    $month = substr($selectedMonth, 5, 2);
    $displayMonth = date('F Y', strtotime($selectedMonth . '-01'));
    $daysInMonth = (int)date('t', strtotime($selectedMonth . '-01'));

    $labels = [];
    $counts = [];
    for ($day = 1; $day <= $daysInMonth; $day++) {
        $dayFormatted = sprintf('%02d', $day);
        $labels[] = $dayFormatted;
        $counts[$dayFormatted] = 0;
    }

    $stmtDays = $db->prepare("SELECT DAY(date) AS day, COUNT(*) AS cnt FROM events WHERE date IS NOT NULL AND YEAR(date) = ? AND MONTH(date) = ? GROUP BY DAY(date) ORDER BY day");
    $stmtDays->execute([$year, $month]);
    $dailyEvents = $stmtDays->fetchAll(PDO::FETCH_ASSOC);
    foreach ($dailyEvents as $event) {
        $dayFormatted = sprintf('%02d', (int)($event['day'] ?? 0));
        if (isset($counts[$dayFormatted])) {
            $counts[$dayFormatted] = (int)($event['cnt'] ?? 0);
        }
    }

    $dailyCounts = [];
    foreach ($labels as $day) {
        $dailyCounts[] = (int)($counts[$day] ?? 0);
    }

    $monthlyTotal = array_sum($dailyCounts);

    $grTotal = (int)($grAssigned + $grInProgress + $grCompleted);
    if ($grTotal > 0) {
        $grAssignedPct = (int)round(($grAssigned / $grTotal) * 100);
        $grInProgressPct = (int)round(($grInProgress / $grTotal) * 100);
        $grCompletedPct = max(0, 100 - $grAssignedPct - $grInProgressPct);
    }

    $ptTotal = (int)($ptReceived + $ptInProgress + $ptPrinted + $ptDelivered);
    if ($ptTotal > 0) {
        $ptReceivedPct = (int)round(($ptReceived / $ptTotal) * 100);
        $ptInProgressPct = (int)round(($ptInProgress / $ptTotal) * 100);
        $ptPrintedPct = (int)round(($ptPrinted / $ptTotal) * 100);
        $ptDeliveredPct = max(0, 100 - $ptReceivedPct - $ptInProgressPct - $ptPrintedPct);
    }

    $prodTotal = (int)($prodQueued + $prodPrinting + $prodInProduction + $prodReady + $prodDelivered);
    if ($prodTotal > 0) {
        $prodQueuedPct = (int)round(($prodQueued / $prodTotal) * 100);
        $prodPrintingPct = (int)round(($prodPrinting / $prodTotal) * 100);
        $prodInProductionPct = (int)round(($prodInProduction / $prodTotal) * 100);
        $prodReadyPct = (int)round(($prodReady / $prodTotal) * 100);
        $prodDeliveredPct = max(0, 100 - $prodQueuedPct - $prodPrintingPct - $prodInProductionPct - $prodReadyPct);
    }
} catch (Throwable $e) {
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
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <link rel="stylesheet" href="style.css">
    <style>
        .admin-recent-table { width: 100%; table-layout: fixed; }
        .admin-recent-table th, .admin-recent-table td { word-break: break-word; white-space: normal; }
        .admin-recent-scroll { overflow-y: auto; overflow-x: hidden; }
        .admin-recent-scroll::-webkit-scrollbar { height: 8px; width: 8px; }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php $activePage = 'admin_dashboard.php'; include __DIR__ . '/sidebar.php'; ?>

    <main class="main-content">
        <div class="top-bar">
            <div class="page-title">
                <h1><?php echo htmlspecialchars($pageTitle); ?></h1>
                <p>System overview and management</p>
            </div>
            <div class="user-menu">
                <div class="date-display"><i class="far fa-calendar-alt"></i> <?php echo date('l, F j, Y'); ?></div>
                <div class="user-profile" id="adminUserProfile">
                    <div class="avatar"><?php echo htmlspecialchars(strtoupper(mb_substr($displayName, 0, 1))); ?></div>
                    <div>
                        <div style="font-weight:600;"><?php echo htmlspecialchars($displayName); ?></div>
                        <div style="font-size:0.75rem;color:#64748b;"><?php echo htmlspecialchars(get_role_label($user['role'] ?? 'user')); ?></div>
                    </div>
                    <i class="fas fa-chevron-down" style="color:#64748b;"></i>
                    <div class="profile-dropdown" id="adminHeaderProfileDropdown">
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
                            <span class="text-xs bg-white bg-opacity-20 px-2 py-1 rounded-full"><?php echo number_format($upcomingEvents); ?> upcoming</span>
                        </div>
                    </div>
                    <div class="p-3 bg-white bg-opacity-20 rounded-xl"><i class="fas fa-calendar-alt text-xl"></i></div>
                </div>
                <div class="mt-4 pt-3 border-t border-blue-400 border-opacity-30">
                    <a href="events.php" class="text-xs font-medium text-white hover:underline flex items-center">View events <i class="fas fa-arrow-right ml-1 text-xs"></i></a>
                </div>
            </div>

            <div class="bg-gradient-to-br from-purple-500 to-purple-600 rounded-2xl p-5 text-white shadow-lg hover:shadow-xl transition-shadow duration-300">
                <div class="flex justify-between items-start">
                    <div>
                        <p class="text-sm font-medium text-purple-100">Completed</p>
                        <h3 class="text-2xl font-bold mt-1"><?php echo number_format($completedEvents); ?></h3>
                        <div class="mt-2"><span class="text-xs bg-white bg-opacity-20 px-2 py-1 rounded-full"><?php echo number_format($cancelledEvents); ?> cancelled</span></div>
                    </div>
                    <div class="p-3 bg-white bg-opacity-20 rounded-xl"><i class="fas fa-circle-check text-xl"></i></div>
                </div>
                <div class="mt-4 pt-3 border-t border-purple-400 border-opacity-30">
                    <a href="events.php" class="text-xs font-medium text-white hover:underline flex items-center">View list <i class="fas fa-arrow-right ml-1 text-xs"></i></a>
                </div>
            </div>

            <div class="bg-gradient-to-br from-amber-500 to-amber-600 rounded-2xl p-5 text-white shadow-lg hover:shadow-xl transition-shadow duration-300">
                <div class="flex justify-between items-start">
                    <div>
                        <p class="text-sm font-medium text-amber-100">Pending Quotes</p>
                        <h3 class="text-2xl font-bold mt-1"><?php echo number_format($pendingQuotes); ?></h3>
                        <div class="mt-2"><span class="text-xs bg-white bg-opacity-20 px-2 py-1 rounded-full">Finance review</span></div>
                    </div>
                    <div class="p-3 bg-white bg-opacity-20 rounded-xl"><i class="fas fa-file-invoice-dollar text-xl"></i></div>
                </div>
                <div class="mt-4 pt-3 border-t border-amber-400 border-opacity-30">
                    <a href="finance_quotes.php" class="text-xs font-medium text-white hover:underline flex items-center">Open quotes <i class="fas fa-arrow-right ml-1 text-xs"></i></a>
                </div>
            </div>

            <div class="bg-gradient-to-br from-emerald-500 to-emerald-600 rounded-2xl p-5 text-white shadow-lg hover:shadow-xl transition-shadow duration-300">
                <div class="flex justify-between items-start">
                    <div>
                        <p class="text-sm font-medium text-emerald-100">Pending Requests</p>
                        <h3 class="text-2xl font-bold mt-1"><?php echo number_format($pendingROs + $pendingPRs); ?></h3>
                        <div class="mt-2"><span class="text-xs bg-white bg-opacity-20 px-2 py-1 rounded-full"><?php echo number_format($pendingROs); ?> RO, <?php echo number_format($pendingPRs); ?> PR</span></div>
                    </div>
                    <div class="p-3 bg-white bg-opacity-20 rounded-xl"><i class="fas fa-list-check text-xl"></i></div>
                </div>
                <div class="mt-4 pt-3 border-t border-emerald-400 border-opacity-30">
                    <a href="finance_dashboard.php" class="text-xs font-medium text-white hover:underline flex items-center">Open finance <i class="fas fa-arrow-right ml-1 text-xs"></i></a>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="lg:col-span-2 space-y-6" style="min-width:0;">
                <div class="content-section">
                    <div class="section-header" style="align-items:center;">
                        <h2 class="section-title">Workflow Progress</h2>
                        <a href="activities.php" class="link-highlight" style="font-size: 0.85rem;">View all</a>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                        <div class="bg-slate-50 border border-slate-200 rounded-2xl p-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <div class="text-sm text-slate-600">Graphic Requests</div>
                                    <div class="text-2xl font-bold text-slate-900"><?php echo number_format($grAssigned + $grInProgress + $grCompleted); ?></div>
                                </div>
                                <div class="w-12 h-12 bg-purple-100 text-purple-700 rounded-2xl flex items-center justify-center"><i class="fas fa-pen-nib"></i></div>
                            </div>
                            <div class="mt-3 text-xs text-slate-600">
                                <?php echo number_format($grAssigned); ?> assigned, <?php echo number_format($grInProgress); ?> in progress, <?php echo number_format($grCompleted); ?> completed
                            </div>
                            <div class="mt-3" style="height: 8px; background: #e2e8f0; border-radius: 999px; overflow: hidden;">
                                <div style="height: 100%; width: <?php echo (int)$grAssignedPct; ?>%; background: #60a5fa; float:left;"></div>
                                <div style="height: 100%; width: <?php echo (int)$grInProgressPct; ?>%; background: #fbbf24; float:left;"></div>
                                <div style="height: 100%; width: <?php echo (int)$grCompletedPct; ?>%; background: #34d399; float:left;"></div>
                            </div>
                            <div class="mt-2 text-xs text-slate-500">
                                <?php echo (int)$grAssignedPct; ?>% assigned, <?php echo (int)$grInProgressPct; ?>% in progress, <?php echo (int)$grCompletedPct; ?>% completed
                            </div>
                        </div>

                        <div class="bg-slate-50 border border-slate-200 rounded-2xl p-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <div class="text-sm text-slate-600">Print Tasks</div>
                                    <div class="text-2xl font-bold text-slate-900"><?php echo number_format($ptReceived + $ptInProgress + $ptPrinted + $ptDelivered); ?></div>
                                </div>
                                <div class="w-12 h-12 bg-sky-100 text-sky-700 rounded-2xl flex items-center justify-center"><i class="fas fa-print"></i></div>
                            </div>
                            <div class="mt-3 text-xs text-slate-600">
                                <?php echo number_format($ptReceived); ?> received, <?php echo number_format($ptInProgress); ?> in progress, <?php echo number_format($ptPrinted); ?> printed, <?php echo number_format($ptDelivered); ?> delivered
                            </div>
                            <div class="mt-3">
                                <a class="link-highlight" href="production_queue.php#print-tasks">Open print queue</a>
                            </div>
                            <div class="mt-3" style="height: 8px; background: #e2e8f0; border-radius: 999px; overflow: hidden;">
                                <div style="height: 100%; width: <?php echo (int)$ptReceivedPct; ?>%; background: #60a5fa; float:left;"></div>
                                <div style="height: 100%; width: <?php echo (int)$ptInProgressPct; ?>%; background: #fbbf24; float:left;"></div>
                                <div style="height: 100%; width: <?php echo (int)$ptPrintedPct; ?>%; background: #a78bfa; float:left;"></div>
                                <div style="height: 100%; width: <?php echo (int)$ptDeliveredPct; ?>%; background: #34d399; float:left;"></div>
                            </div>
                            <div class="mt-2 text-xs text-slate-500">
                                <?php echo (int)$ptReceivedPct; ?>% received, <?php echo (int)$ptInProgressPct; ?>% in progress, <?php echo (int)$ptPrintedPct; ?>% printed, <?php echo (int)$ptDeliveredPct; ?>% delivered
                            </div>
                        </div>

                        <div class="bg-slate-50 border border-slate-200 rounded-2xl p-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <div class="text-sm text-slate-600">Production Events</div>
                                    <div class="text-2xl font-bold text-slate-900"><?php echo number_format($prodQueued + $prodPrinting + $prodInProduction + $prodReady + $prodDelivered); ?></div>
                                </div>
                                <div class="w-12 h-12 bg-amber-100 text-amber-700 rounded-2xl flex items-center justify-center"><i class="fas fa-industry"></i></div>
                            </div>
                            <div class="mt-3 text-xs text-slate-600">
                                <?php echo number_format($prodQueued); ?> queued, <?php echo number_format($prodPrinting); ?> printing, <?php echo number_format($prodInProduction); ?> in production, <?php echo number_format($prodReady); ?> ready, <?php echo number_format($prodDelivered); ?> delivered
                            </div>
                            <div class="mt-3">
                                <a class="link-highlight" href="production_queue.php">Open production queue</a>
                            </div>
                            <div class="mt-3" style="height: 8px; background: #e2e8f0; border-radius: 999px; overflow: hidden;">
                                <div style="height: 100%; width: <?php echo (int)$prodQueuedPct; ?>%; background: #94a3b8; float:left;"></div>
                                <div style="height: 100%; width: <?php echo (int)$prodPrintingPct; ?>%; background: #fbbf24; float:left;"></div>
                                <div style="height: 100%; width: <?php echo (int)$prodInProductionPct; ?>%; background: #60a5fa; float:left;"></div>
                                <div style="height: 100%; width: <?php echo (int)$prodReadyPct; ?>%; background: #a78bfa; float:left;"></div>
                                <div style="height: 100%; width: <?php echo (int)$prodDeliveredPct; ?>%; background: #34d399; float:left;"></div>
                            </div>
                            <div class="mt-2 text-xs text-slate-500">
                                <?php echo (int)$prodQueuedPct; ?>% queued, <?php echo (int)$prodPrintingPct; ?>% printing, <?php echo (int)$prodInProductionPct; ?>% in production
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                    <div class="p-5 border-b border-gray-100">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h3 class="text-lg font-semibold text-gray-800">Events Trend - <?php echo htmlspecialchars($displayMonth !== '' ? $displayMonth : date('F Y')); ?></h3>
                                <p class="text-sm text-gray-500">Daily and cumulative events overview</p>
                            </div>
                            <div class="mt-3 sm:mt-0 flex items-center space-x-2">
                                <div class="relative">
                                    <select class="appearance-none bg-white border border-gray-200 rounded-lg pl-3 pr-8 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500" onchange="location = '?month=' + this.value;">
                                        <?php
                                        for ($i = 0; $i < 12; $i++) {
                                            $monthValue = date('Y-m', strtotime("-$i months"));
                                            $monthDisplay = date('F Y', strtotime($monthValue . '-01'));
                                            $selected = ($monthValue === $selectedMonth) ? 'selected' : '';
                                            echo "<option value=\"$monthValue\" $selected>$monthDisplay</option>";
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
                            <canvas id="adminEventsChart"></canvas>
                        </div>
                    </div>
                    <div class="px-5 py-3 bg-gray-50 border-t border-gray-100 flex flex-col sm:flex-row justify-between items-center">
                        <p class="text-sm text-gray-500 mb-2 sm:mb-0">
                            <span class="font-medium text-gray-700"><?php echo (int)$monthlyTotal; ?></span> events in <?php echo htmlspecialchars($displayMonth !== '' ? $displayMonth : date('F Y')); ?>
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

            <div class="lg:col-span-1 space-y-6" style="min-width:0;">
                <div class="content-section">
                    <div class="section-header" style="align-items:center;">
                        <h2 class="section-title">Administrator Actions</h2>
                        <a href="settings.php" class="link-highlight" style="font-size: 0.85rem;">Manage</a>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <a href="events.php?mode=form" class="p-4 bg-blue-50 hover:bg-blue-100 rounded-2xl text-center transition-colors">
                            <div class="w-12 h-12 bg-blue-100 text-blue-600 rounded-2xl flex items-center justify-center mx-auto mb-3"><i class="fas fa-plus"></i></div>
                            <div class="text-sm font-medium text-gray-700">New Event</div>
                        </a>
                        <a href="events.php" class="p-4 bg-green-50 hover:bg-green-100 rounded-2xl text-center transition-colors">
                            <div class="w-12 h-12 bg-green-100 text-green-700 rounded-2xl flex items-center justify-center mx-auto mb-3"><i class="fas fa-calendar-check"></i></div>
                            <div class="text-sm font-medium text-gray-700">All Events</div>
                        </a>
                        <a href="users.php" class="p-4 bg-violet-50 hover:bg-violet-100 rounded-2xl text-center transition-colors">
                            <div class="w-12 h-12 bg-violet-100 text-violet-700 rounded-2xl flex items-center justify-center mx-auto mb-3"><i class="fas fa-users"></i></div>
                            <div class="text-sm font-medium text-gray-700">Users</div>
                        </a>
                        <a href="settings.php" class="p-4 bg-slate-50 hover:bg-slate-100 rounded-2xl text-center transition-colors">
                            <div class="w-12 h-12 bg-slate-100 text-slate-700 rounded-2xl flex items-center justify-center mx-auto mb-3"><i class="fas fa-gear"></i></div>
                            <div class="text-sm font-medium text-gray-700">Settings</div>
                        </a>
                    </div>
                </div>

                <div class="content-section">
                    <div class="section-header" style="align-items:center;">
                        <h2 class="section-title">Reports & Finance</h2>
                        <a href="reports.php" class="link-highlight" style="font-size: 0.85rem;">Open</a>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <a href="reports.php" class="p-3 bg-purple-50 hover:bg-purple-100 rounded-xl text-center transition-colors">
                            <div class="w-10 h-10 bg-purple-100 text-purple-700 rounded-xl flex items-center justify-center mx-auto mb-2"><i class="fas fa-chart-pie"></i></div>
                            <div class="text-xs font-medium text-gray-700">Reports</div>
                        </a>
                        <a href="activities.php" class="p-3 bg-slate-50 hover:bg-slate-100 rounded-xl text-center transition-colors">
                            <div class="w-10 h-10 bg-slate-100 text-slate-700 rounded-xl flex items-center justify-center mx-auto mb-2"><i class="fas fa-clock-rotate-left"></i></div>
                            <div class="text-xs font-medium text-gray-700">Activity</div>
                        </a>
                        <a href="finance_dashboard.php" class="p-3 bg-amber-50 hover:bg-amber-100 rounded-xl text-center transition-colors">
                            <div class="w-10 h-10 bg-amber-100 text-amber-700 rounded-xl flex items-center justify-center mx-auto mb-2"><i class="fas fa-file-invoice-dollar"></i></div>
                            <div class="text-xs font-medium text-gray-700">Finance</div>
                        </a>
                        <a href="events.php" class="p-3 bg-blue-50 hover:bg-blue-100 rounded-xl text-center transition-colors">
                            <div class="w-10 h-10 bg-blue-100 text-blue-700 rounded-xl flex items-center justify-center mx-auto mb-2"><i class="fas fa-calendar"></i></div>
                            <div class="text-xs font-medium text-gray-700">Events</div>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="content-section" style="margin-top: 1.5rem;">
            <div class="section-header" style="align-items:center;">
                <h2 class="section-title">Recent Activity</h2>
                <a href="activities.php" class="link-highlight" style="font-size: 0.85rem;">View all</a>
            </div>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <div class="content-section" style="padding: 0; overflow: hidden; display:flex; flex-direction:column; height: 320px;">
                    <div style="padding: 1rem 1.25rem; border-bottom: 1px solid #e2e8f0; font-weight: 600; color: #1e293b; flex: 0 0 auto;">Recent Graphic Requests</div>
                    <div class="table-container admin-recent-scroll" style="margin:0; flex: 1 1 auto;">
                        <table class="data-table admin-recent-table" style="margin:0;">
                            <thead>
                            <tr>
                                <th>Event</th>
                                <th>Client</th>
                                <th>Status</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($recentGraphic as $r): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars((string)($r['event_name'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars((string)($r['event_client'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars((string)($r['status'] ?? '')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$recentGraphic): ?>
                                <tr><td colspan="3" style="text-align:center;color:#64748b;padding:1.25rem;">No records</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="content-section" style="padding: 0; overflow: hidden; display:flex; flex-direction:column; height: 320px;">
                    <div style="padding: 1rem 1.25rem; border-bottom: 1px solid #e2e8f0; font-weight: 600; color: #1e293b; flex: 0 0 auto;">Recent Print Tasks</div>
                    <div class="table-container admin-recent-scroll" style="margin:0; flex: 1 1 auto;">
                        <table class="data-table admin-recent-table" style="margin:0;">
                            <thead>
                            <tr>
                                <th>Event</th>
                                <th>Title</th>
                                <th>Status</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($recentPrintTasks as $t): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars((string)($t['event_name'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars((string)($t['title'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars((string)($t['status'] ?? '')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$recentPrintTasks): ?>
                                <tr><td colspan="3" style="text-align:center;color:#64748b;padding:1.25rem;">No records</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="content-section" style="padding: 0; overflow: hidden; display:flex; flex-direction:column; height: 320px;">
                    <div style="padding: 1rem 1.25rem; border-bottom: 1px solid #e2e8f0; font-weight: 600; color: #1e293b; flex: 0 0 auto;">Recent Production Updates</div>
                    <div class="table-container admin-recent-scroll" style="margin:0; flex: 1 1 auto;">
                        <table class="data-table admin-recent-table" style="margin:0;">
                            <thead>
                            <tr>
                                <th>Event</th>
                                <th>Client</th>
                                <th>Status</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($recentProductionEvents as $e): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars((string)($e['name'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars((string)($e['client'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars((string)($e['production_status'] ?? '')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$recentProductionEvents): ?>
                                <tr><td colspan="3" style="text-align:center;color:#64748b;padding:1.25rem;">No records</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

    </main>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    if (window.__profileDropdownBound) return;
    var profile = document.getElementById('adminUserProfile');
    var dropdown = document.getElementById('adminHeaderProfileDropdown');
    if (!profile || !dropdown) return;

    function closeDd(){ dropdown.classList.remove('show'); }

    profile.addEventListener('click', function(e){
        e.stopPropagation();
        dropdown.classList.toggle('show');
    });

    document.addEventListener('click', function(){ closeDd(); });
});
</script>

<script>
(function(){
    var canvas = document.getElementById('adminEventsChart');
    if (!canvas || typeof Chart === 'undefined') return;

    var labels = <?php echo json_encode($labels); ?>;
    var daily = <?php echo json_encode($dailyCounts); ?>;
    if (!labels || labels.length === 0) return;

    try {
        new Chart(canvas, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Events',
                        data: daily,
                        borderColor: '#3b82f6',
                        borderWidth: 2,
                        backgroundColor: 'rgba(59,130,246,0.1)',
                        fill: true,
                        tension: 0.3,
                        pointBackgroundColor: '#3b82f6',
                        pointBorderColor: '#fff',
                        pointHoverRadius: 5,
                        pointHoverBackgroundColor: '#3b82f6',
                        pointHoverBorderColor: '#fff',
                        pointHitRadius: 10,
                        pointBorderWidth: 2,
                        pointRadius: 4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    intersect: false,
                    mode: 'index'
                },
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
                                var label = context.dataset.label || '';
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
                        ticks: {
                            precision: 0,
                            callback: function(value) {
                                if (value % 1 === 0) return value;
                            }
                        }
                    }
                },
                elements: {
                    line: { borderWidth: 2, fill: 'start' },
                    point: { radius: 0, hoverRadius: 6 }
                }
            }
        });
    } catch (e) {
    }
})();
</script>
</body>
</html>
