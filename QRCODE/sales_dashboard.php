<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';

// --- Authentication & Authorization ---
if (isset($_GET['logout'])) {
    logout_user();
    header('Location: login.php');
    exit;
}

require_login();
$db = get_db();
$user = current_user();

// Only sales can access this dashboard
if (!in_array($user['role'] ?? '', ['sales', 'super', 'admin'], true)) {
    header('Location: index.php');
    exit;
}

$isHeadSales = in_array($user['role'] ?? '', ['super', 'admin'], true);
$pageTitle = 'Sales Dashboard';

// --- Form Handling ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_abort();
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'request_graphic') {
            $eventId = (int)($_POST['event_id'] ?? 0);
            $notes = trim($_POST['notes'] ?? '');
            
            if ($eventId > 0) {
                $existing = $db->prepare("SELECT id FROM graphic_requests WHERE event_id = ? AND status NOT IN ('completed', 'cancelled')");
                $existing->execute([$eventId]);
                
                if (!$existing->fetch()) {
                    $stmt = $db->prepare("INSERT INTO graphic_requests (event_id, requested_by, notes, status) VALUES (?, ?, ?, 'pending')");
                    $stmt->execute([$eventId, $user['id'], $notes]);
                    flash_add('success', 'Graphic request submitted successfully');
                } else {
                    flash_add('warning', 'A graphic request already exists for this event');
                }
            }
        } elseif ($action === 'create_print_task') {
            $eventId = (int)($_POST['event_id'] ?? 0);
            $title = trim((string)($_POST['title'] ?? ''));
            $qty = (int)($_POST['qty'] ?? 0);
            $notes = trim((string)($_POST['notes'] ?? ''));
            $taskType = trim((string)($_POST['task_type'] ?? ''));

            if ($eventId <= 0 || $title === '') {
                throw new Exception('Event and title are required');
            }

            $ins = $db->prepare("INSERT INTO print_tasks (event_id, task_type, source, title, qty, status, notes, created_by) VALUES (?, ?, 'sales', ?, ?, 'received', ?, ?)");
            $ins->execute([
                $eventId,
                ($taskType !== '' ? $taskType : null),
                $title,
                ($qty > 0 ? $qty : null),
                ($notes !== '' ? $notes : null),
                (int)($user['id'] ?? 0)
            ]);
            flash_add('success', 'Print task sent to Production.');
        } elseif ($action === 'request_supervisor') {
            $eventId = (int)($_POST['event_id'] ?? 0);
            $notes = trim($_POST['notes'] ?? '');
            
            if ($eventId > 0) {
                $existing = $db->prepare("SELECT id FROM supervisor_requests WHERE event_id = ? AND status NOT IN ('completed', 'cancelled')");
                $existing->execute([$eventId]);
                
                if (!$existing->fetch()) {
                    $stmt = $db->prepare("INSERT INTO supervisor_requests (event_id, requested_by, notes, status) VALUES (?, ?, ?, 'pending')");
                    $stmt->execute([$eventId, $user['id'], $notes]);
                    flash_add('success', 'Operation request submitted successfully');
                } else {
                    flash_add('warning', 'An operation request already exists for this event');
                }
            }
        } elseif ($action === 'cancel_request') {
            $requestId = (int)($_POST['request_id'] ?? 0);
            if ($requestId > 0) {
                $stmt = $db->prepare("UPDATE graphic_requests SET status = 'cancelled' WHERE id = ? AND requested_by = ?");
                $stmt->execute([$requestId, $user['id']]);
                flash_add('success', 'Graphic request cancelled');
            }
        } elseif ($action === 'cancel_supervisor_request') {
            $requestId = (int)($_POST['request_id'] ?? 0);
            if ($requestId > 0) {
                $stmt = $db->prepare("UPDATE supervisor_requests SET status = 'cancelled' WHERE id = ? AND requested_by = ?");
                $stmt->execute([$requestId, $user['id']]);
                flash_add('success', 'Operation request cancelled');
            }
        } elseif ($action === 'event_action') {
            $eventId = (int)($_POST['event_id'] ?? 0);
            $actionType = $_POST['action_type'] ?? '';
            $comment = trim($_POST['comment'] ?? '');
            
            if ($eventId > 0 && in_array($actionType, ['cancel', 'postpone'])) {
                $existing = $db->prepare("SELECT id FROM event_actions WHERE event_id = ? AND status = 'pending'");
                $existing->execute([$eventId]);
                
                if (!$existing->fetch()) {
                    $stmt = $db->prepare("INSERT INTO event_actions (event_id, action_type, requested_by, comment, status) VALUES (?, ?, ?, ?, 'pending')");
                    $stmt->execute([$eventId, $actionType, $user['id'], $comment]);
                    flash_add('success', 'Event ' . $actionType . ' request sent to Super Admin for approval');
                } else {
                    flash_add('warning', 'A pending action already exists for this event');
                }
            }
        }
    } catch (Exception $e) {
        flash_add('error', 'An error occurred: ' . $e->getMessage());
    }
    
    header('Location: sales_dashboard.php');
    exit;
}

// --- Data Fetching ---

// 1. All Events (excluding pending cancel/postpone)
$eventsStmt = $db->query("
    SELECT e.*, u.name as created_by_name 
    FROM events e 
    LEFT JOIN users u ON e.user_id = u.id 
    WHERE e.id NOT IN (SELECT event_id FROM event_actions WHERE status = 'pending')
    ORDER BY e.date DESC
");
$events = $eventsStmt->fetchAll(PDO::FETCH_ASSOC);

$quoteFilter = strtolower((string)($_GET['quote_status'] ?? 'all'));
$allowedQuoteFilters = ['all', 'pending', 'approved', 'rejected'];
if (!in_array($quoteFilter, $allowedQuoteFilters, true)) {
    $quoteFilter = 'all';
}

$quoteEvents = array_values(array_filter($events, function ($e) use ($quoteFilter) {
    if ($quoteFilter === 'all') {
        return true;
    }
    return strtolower((string)($e['quote_status'] ?? 'pending')) === $quoteFilter;
}));

$quotePendingCount = count(array_filter($events, fn($e) => strtolower((string)($e['quote_status'] ?? 'pending')) === 'pending'));
$quoteApprovedCount = count(array_filter($events, fn($e) => strtolower((string)($e['quote_status'] ?? 'pending')) === 'approved'));
$quoteRejectedCount = count(array_filter($events, fn($e) => strtolower((string)($e['quote_status'] ?? 'pending')) === 'rejected'));

// 2. Graphic Requests
$graphicQuery = "
    SELECT gr.*, e.name as event_name, e.date as event_date, e.client,
           u1.name as requested_by_name, u2.name as assigned_to_name, u3.name as assigned_by_name,
           gr.assigned_at, gr.started_at, gr.completed_at
    FROM graphic_requests gr
    JOIN events e ON gr.event_id = e.id
    LEFT JOIN users u1 ON gr.requested_by = u1.id
    LEFT JOIN users u2 ON gr.assigned_to = u2.id
    LEFT JOIN users u3 ON gr.assigned_by = u3.id
";
if (!$isHeadSales) {
    $graphicQuery .= " WHERE gr.requested_by = " . (int)$user['id'];
}
$graphicQuery .= " ORDER BY gr.created_at DESC";
$graphicRequests = $db->query($graphicQuery)->fetchAll(PDO::FETCH_ASSOC);

// 3. Supervisor Requests
$supervisorQuery = "
    SELECT sr.*, e.name as event_name, e.date as event_date, e.client, e.location,
           u1.name as requested_by_name, u2.name as assigned_to_name, u3.name as assigned_by_name,
           sr.assigned_at, sr.started_at, sr.completed_at
    FROM supervisor_requests sr
    JOIN events e ON sr.event_id = e.id
    LEFT JOIN users u1 ON sr.requested_by = u1.id
    LEFT JOIN users u2 ON sr.assigned_to = u2.id
    LEFT JOIN users u3 ON sr.assigned_by = u3.id
";
if (!$isHeadSales) {
    $supervisorQuery .= " WHERE sr.requested_by = " . (int)$user['id'];
}
$supervisorQuery .= " ORDER BY sr.created_at DESC";
$supervisorRequests = $db->query($supervisorQuery)->fetchAll(PDO::FETCH_ASSOC);

// 4. Available Events for Requests
$availableEvents = $db->query("
    SELECT e.id, e.name, e.date, e.client 
    FROM events e 
    WHERE e.id NOT IN (SELECT event_id FROM graphic_requests WHERE status NOT IN ('completed', 'cancelled'))
    ORDER BY e.date DESC
")->fetchAll(PDO::FETCH_ASSOC);

$availableSupervisorEvents = $db->query("
    SELECT e.id, e.name, e.date, e.client 
    FROM events e 
    WHERE e.id NOT IN (SELECT event_id FROM supervisor_requests WHERE status NOT IN ('completed', 'cancelled'))
    ORDER BY e.date DESC
")->fetchAll(PDO::FETCH_ASSOC);

$availablePrintTaskEvents = [];
try {
    $stmt = $db->query("SELECT id, name, date, client FROM events ORDER BY COALESCE(date, created_at) DESC, id DESC");
    $availablePrintTaskEvents = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
} catch (Throwable $e) {
    $availablePrintTaskEvents = [];
}

// 5. Alerts (Events needing attention)
$eventsWithoutGraphic = [];
$eventsWithoutSupervisor = [];
if ($isHeadSales) {
    $eventsWithoutGraphic = $db->query("
        SELECT e.id, e.name, e.date, e.client, e.created_at
        FROM events e
        WHERE e.id NOT IN (SELECT event_id FROM graphic_requests WHERE status NOT IN ('cancelled'))
        ORDER BY e.created_at DESC LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);

    $eventsWithoutSupervisor = $db->query("
        SELECT e.id, e.name, e.date, e.client, e.created_at
        FROM events e
        WHERE e.id NOT IN (SELECT event_id FROM supervisor_requests WHERE status NOT IN ('cancelled'))
        ORDER BY e.created_at DESC LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// --- View Variables ---
$flashes = flash_consume();
$displayName = $user['name'] ?? $user['email'] ?? 'User';
$selectedGraphicEventId = isset($_GET['graphic_event_id']) ? (int)$_GET['graphic_event_id'] : 0;
$selectedSupervisorEventId = isset($_GET['supervisor_event_id']) ? (int)$_GET['supervisor_event_id'] : 0;
$focusSection = $_GET['focus'] ?? '';

// Helper to filter requests
$filterStatus = fn($list, $status) => array_filter($list, fn($i) => $i['status'] === $status);
$filterActive = fn($list) => array_filter($list, fn($i) => in_array($i['status'], ['assigned', 'in_progress']));

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $pageTitle; ?> | Hugo Domingo Events</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="style.css">
    <style>
        /* Custom Dashboard Styles */
        :root {
            --primary-color: #3b82f6;
            --secondary-color: #64748b;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --danger-color: #ef4444;
            --purple-color: #8b5cf6;
        }

        .card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            width: 100%;
            max-width: none;
            margin: 0;
            transition: transform 0.2s ease-in-out;
        }

        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }

        .stat-card {
            position: relative;
            overflow: hidden;
            padding: 1.5rem;
            border-radius: 1rem;
            color: white;
        }

        .stat-card::after {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            bottom: 0;
            left: 0;
            background: linear-gradient(to bottom right, rgba(255,255,255,0.2), rgba(255,255,255,0));
            pointer-events: none;
        }

        .stat-blue { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); }
        .stat-purple { background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%); }
        .stat-green { background: linear-gradient(135deg, #10b981 0%, #059669 100%); }
        .stat-orange { background: linear-gradient(135deg, #f97316 0%, #ea580c 100%); }

        .table-container {
            overflow-x: auto;
            border-radius: 0.75rem;
            border: 1px solid #e5e7eb;
        }

        .modern-table {
            width: 100%;
            min-width: 1000px;
            border-collapse: separate;
            border-spacing: 0;
        }

        .modern-table th {
            background-color: #f8fafc;
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.05em;
            padding: 0.75rem 1rem;
            border-bottom: 1px solid #e2e8f0;
            text-align: left;
            white-space: nowrap;
        }

        .modern-table td {
            padding: 1rem;
            border-bottom: 1px solid #f1f5f9;
            color: #334155;
            font-size: 0.875rem;
            vertical-align: top;
        }

        .modern-table tr:last-child td { border-bottom: none; }
        .modern-table tr:hover td { background-color: #f8fafc; }

        .compact-table {
            width: 100%;
            min-width: 800px;
        }
        
        .compact-table th,
        .compact-table td {
            padding: 0.75rem 0.5rem;
        }
        
        .compact-table th {
            font-size: 0.7rem;
            padding: 0.5rem;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            white-space: nowrap;
        }

        .status-pending { background-color: #fef3c7; color: #92400e; }
        .status-assigned { background-color: #dbeafe; color: #1e40af; }
        .status-in_progress { background-color: #d1fae5; color: #065f46; }
        .status-completed { background-color: #e0e7ff; color: #3730a3; }
        .status-cancelled { background-color: #fee2e2; color: #991b1b; }

        /* Modal Animation */
        .modal {
            transition: opacity 0.3s ease-in-out;
            opacity: 0;
            pointer-events: none;
        }
        .modal.show {
            opacity: 1;
            pointer-events: auto;
        }
        .modal-content {
            transform: scale(0.95);
            transition: transform 0.3s ease-in-out;
        }
        .modal.show .modal-content {
            transform: scale(1);
        }
        
        /* Column widths for better spacing */
        .col-event { min-width: 200px; max-width: 250px; }
        .col-client { min-width: 120px; max-width: 150px; }
        .col-date { min-width: 100px; max-width: 120px; }
        .col-requested { min-width: 120px; max-width: 150px; }
        .col-assigned { min-width: 120px; max-width: 150px; }
        .col-progress { min-width: 200px; max-width: 250px; }
        .col-status { min-width: 100px; max-width: 120px; }
        .col-actions { min-width: 80px; max-width: 100px; }
        
        .truncate-text {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            display: block;
        }
        
        .progress-timeline {
            font-size: 0.75rem;
            line-height: 1.3;
        }
        
        .progress-timeline div {
            margin-bottom: 2px;
        }
    </style>
</head>
<body>
    <div class="flex h-screen overflow-hidden">
        <!-- Sidebar -->
        <?php $activePage = 'sales_dashboard.php'; include __DIR__ . '/sidebar.php'; ?>

        <!-- Main Content -->
        <div class="relative flex flex-col flex-1 overflow-y-auto overflow-x-hidden md:ml-[260px]">
            <!-- Top Header -->
            <header class="bg-white shadow-sm sticky top-0 z-30">
                <div class="pl-4 pr-6 py-4">
                    <div class="top-bar">
                        <div class="page-title">
                            <h1><?php echo htmlspecialchars($pageTitle); ?></h1>
                            <p>Overview of your events and requests</p>
                        </div>

                        <div class="user-menu">
                            <div class="date-display">
                                <i class="far fa-calendar-alt mr-2"></i>
                                <?php echo date('l, F j, Y'); ?>
                            </div>

                            <div class="user-profile" id="userProfile">
                                <div class="avatar"><?php echo htmlspecialchars(strtoupper(mb_substr($displayName, 0, 1))); ?></div>
                                <div>
                                    <div><?php echo htmlspecialchars($displayName); ?></div>
                                    <div><?php echo function_exists('get_role_label') ? get_role_label($user['role'] ?? 'user') : htmlspecialchars((string)($user['role'] ?? 'user')); ?></div>
                                </div>
                                <i class="fas fa-chevron-down"></i>
                                <div class="profile-dropdown" id="profileDropdown">
                                    <a href="profile.php"><i class="fas fa-user"></i> My Profile</a>
                                    <a href="?logout" style="color:#dc2626;"><i class="fas fa-sign-out-alt"></i> Logout</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <main class="w-full pl-4 pr-6 py-6">
                <!-- Flash Messages -->
                <?php foreach ($flashes as $type => $msgs): ?>
                    <?php foreach ($msgs as $msg): ?>
                        <div class="mb-6 p-4 rounded-lg flex items-center gap-3 shadow-sm <?php echo $type === 'success' ? 'bg-green-50 text-green-700 border border-green-200' : ($type === 'warning' ? 'bg-yellow-50 text-yellow-700 border border-yellow-200' : 'bg-red-50 text-red-700 border border-red-200'); ?>">
                            <i class="fas <?php echo $type === 'success' ? 'fa-check-circle' : ($type === 'warning' ? 'fa-exclamation-triangle' : 'fa-times-circle'); ?> text-xl"></i>
                            <span class="font-medium"><?php echo htmlspecialchars($msg); ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>

                <!-- Stats Overview -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <!-- Total Events -->
                    <div class="stat-card stat-blue">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="text-blue-100 text-sm font-medium mb-1">Total Events</p>
                                <h3 class="text-3xl font-bold"><?php echo count($events); ?></h3>
                            </div>
                            <div class="p-2 bg-white/20 rounded-lg">
                                <i class="fas fa-calendar-check text-xl"></i>
                            </div>
                        </div>
                        <div class="mt-4 text-xs text-blue-100 bg-white/10 inline-block px-2 py-1 rounded">
                            Active Portfolio
                        </div>
                    </div>

                    <!-- Graphic Requests -->
                    <div class="stat-card stat-purple">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="text-purple-100 text-sm font-medium mb-1">Graphic Requests</p>
                                <h3 class="text-3xl font-bold"><?php echo count($graphicRequests); ?></h3>
                            </div>
                            <div class="p-2 bg-white/20 rounded-lg">
                                <i class="fas fa-paint-brush text-xl"></i>
                            </div>
                        </div>
                        <div class="mt-4 flex gap-2">
                            <span class="text-xs bg-white/20 px-2 py-1 rounded"><?php echo count($filterStatus($graphicRequests, 'pending')); ?> Pending</span>
                            <span class="text-xs bg-white/20 px-2 py-1 rounded"><?php echo count($filterActive($graphicRequests)); ?> Active</span>
                        </div>
                    </div>

                    <!-- Operation Requests -->
                    <div class="stat-card stat-green">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="text-green-100 text-sm font-medium mb-1">Operation Requests</p>
                                <h3 class="text-3xl font-bold"><?php echo count($supervisorRequests); ?></h3>
                            </div>
                            <div class="p-2 bg-white/20 rounded-lg">
                                <i class="fas fa-user-tie text-xl"></i>
                            </div>
                        </div>
                        <div class="mt-4 flex gap-2">
                            <span class="text-xs bg-white/20 px-2 py-1 rounded"><?php echo count($filterStatus($supervisorRequests, 'pending')); ?> Pending</span>
                            <span class="text-xs bg-white/20 px-2 py-1 rounded"><?php echo count($filterActive($supervisorRequests)); ?> Active</span>
                        </div>
                    </div>

                    <!-- Pending Actions -->
                    <div class="stat-card stat-orange">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="text-orange-100 text-sm font-medium mb-1">Needs Attention</p>
                                <h3 class="text-3xl font-bold"><?php echo count($eventsWithoutGraphic) + count($eventsWithoutSupervisor); ?></h3>
                            </div>
                            <div class="p-2 bg-white/20 rounded-lg">
                                <i class="fas fa-bell text-xl"></i>
                            </div>
                        </div>
                        <div class="mt-4 text-xs text-orange-100">Unassigned Events</div>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-12 w-full items-start gap-2">
                    <div class="lg:col-span-3 space-y-6">
                        <?php if ($isHeadSales): ?>
                            <div class="bg-gradient-to-br from-blue-50 to-indigo-50 border border-blue-100 rounded-xl p-5">
                                <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                                    <i class="fas fa-bolt text-blue-500"></i> Quick Actions
                                </h3>
                                
                                <?php if (count($eventsWithoutGraphic) > 0): ?>
                                <div class="mb-4 p-3 bg-white rounded-lg border border-blue-100">
                                    <div class="flex justify-between items-center mb-2">
                                        <h4 class="font-medium text-gray-700">Need Graphics</h4>
                                        <span class="bg-blue-100 text-blue-800 text-xs font-semibold px-2 py-0.5 rounded"><?php echo count($eventsWithoutGraphic); ?></span>
                                    </div>
                                    <p class="text-sm text-gray-500 mb-3">Events without graphic requests</p>
                                    <button onclick="toggleSection('eventsWithoutGraphicTable')" class="w-full bg-blue-600 text-white py-2 px-3 rounded-lg hover:bg-blue-700 transition font-medium text-sm">
                                        <i class="fas fa-eye"></i>
                                        View Events
                                    </button>
                                </div>
                                <?php endif; ?>

                                <?php if (count($eventsWithoutSupervisor) > 0): ?>
                                <div class="p-3 bg-white rounded-lg border border-purple-100">
                                    <div class="flex justify-between items-center mb-2">
                                        <h4 class="font-medium text-gray-700">Need Operations</h4>
                                        <span class="bg-purple-100 text-purple-800 text-xs font-semibold px-2 py-0.5 rounded"><?php echo count($eventsWithoutSupervisor); ?></span>
                                    </div>
                                    <p class="text-sm text-gray-500 mb-3">Events without operation requests</p>
                                    <button onclick="toggleSection('eventsWithoutSupervisorTable')" class="w-full bg-purple-600 text-white py-2 px-3 rounded-lg hover:bg-purple-700 transition font-medium text-sm">
                                        <i class="fas fa-eye"></i>
                                        View Events
                                    </button>
                                </div>
                                <?php endif; ?>
                            </div>

                            <div class="space-y-6">
                                <div class="card p-6" id="requestGraphic">
                                    <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                                        <i class="fas fa-paint-brush text-blue-500"></i> New Graphic Request
                                    </h3>
                                    <form method="POST" class="space-y-4">
                                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                        <input type="hidden" name="action" value="request_graphic">
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-1">Select Event</label>
                                            <select name="event_id" required class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm">
                                                <option value="">-- Choose Event --</option>
                                                <?php foreach ($availableEvents as $ev): ?>
                                                    <option value="<?php echo $ev['id']; ?>" <?php echo ($selectedGraphicEventId == $ev['id']) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($ev['name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                                            <textarea name="notes" rows="2" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm" placeholder="Describe requirements..."></textarea>
                                        </div>
                                        <button type="submit" class="w-full bg-blue-600 text-white py-2 px-4 rounded-lg hover:bg-blue-700 transition font-medium text-sm">Submit Request</button>
                                    </form>
                                </div>

                                <div class="card p-6" id="requestPrintTask">
                                    <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                                        <i class="fas fa-print text-sky-500"></i> New Print Task
                                    </h3>
                                    <form method="POST" class="space-y-4">
                                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                        <input type="hidden" name="action" value="create_print_task">
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-1">Select Event</label>
                                            <select name="event_id" required class="w-full rounded-lg border-gray-300 shadow-sm focus:border-sky-500 focus:ring-sky-500 text-sm">
                                                <option value="">-- Choose Event --</option>
                                                <?php foreach ($availablePrintTaskEvents as $ev): ?>
                                                    <option value="<?php echo (int)$ev['id']; ?>">
                                                        <?php echo htmlspecialchars((string)($ev['name'] ?? '')); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-1">Title *</label>
                                            <input type="text" name="title" required class="w-full rounded-lg border-gray-300 shadow-sm focus:border-sky-500 focus:ring-sky-500 text-sm" placeholder="e.g. Banner, Stickers, Brochure">
                                        </div>
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 mb-1">Qty</label>
                                                <input type="number" name="qty" min="0" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-sky-500 focus:ring-sky-500 text-sm" placeholder="">
                                            </div>
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                                                <input type="text" name="task_type" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-sky-500 focus:ring-sky-500 text-sm" placeholder="e.g. banner">
                                            </div>
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                                            <textarea name="notes" rows="2" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-sky-500 focus:ring-sky-500 text-sm" placeholder="Size, material, finishing, deadline..."></textarea>
                                        </div>
                                        <button type="submit" class="w-full bg-sky-600 text-white py-2 px-4 rounded-lg hover:bg-sky-700 transition font-medium text-sm">Send to Production</button>
                                    </form>
                                </div>

                                <div class="card p-6" id="requestSupervisor">
                                    <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                                        <i class="fas fa-user-tie text-purple-500"></i> New Operation Request
                                    </h3>
                                    <form method="POST" class="space-y-4">
                                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                        <input type="hidden" name="action" value="request_supervisor">
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-1">Select Event</label>
                                            <select name="event_id" required class="w-full rounded-lg border-gray-300 shadow-sm focus:border-purple-500 focus:ring-purple-500 text-sm">
                                                <option value="">-- Choose Event --</option>
                                                <?php foreach ($availableSupervisorEvents as $ev): ?>
                                                    <option value="<?php echo $ev['id']; ?>" <?php echo ($selectedSupervisorEventId == $ev['id']) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($ev['name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                                            <textarea name="notes" rows="2" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-purple-500 focus:ring-purple-500 text-sm" placeholder="Describe requirements..."></textarea>
                                        </div>
                                        <button type="submit" class="w-full bg-purple-600 text-white py-2 px-4 rounded-lg hover:bg-purple-700 transition font-medium text-sm">Submit Request</button>
                                    </form>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Recent Events -->
                        <div class="card">
                            <div class="p-4 border-b border-gray-100">
                                <h3 class="font-bold text-gray-800">Recent Events</h3>
                            </div>
                            <div class="max-h-[400px] overflow-y-auto">
                                <?php foreach (array_slice($events, 0, 5) as $ev): ?>
                                <div class="p-4 border-b border-gray-50 hover:bg-gray-50 transition">
                                    <div class="flex justify-between items-start mb-1">
                                        <div class="font-medium text-gray-900 text-sm truncate" title="<?php echo htmlspecialchars($ev['name']); ?>">
                                            <?php echo htmlspecialchars($ev['name']); ?>
                                        </div>
                                        <div class="text-xs text-gray-500 whitespace-nowrap ml-2">
                                            <?php echo !empty($ev['date']) ? date('M j', strtotime($ev['date'])) : '-'; ?>
                                        </div>
                                    </div>
                                    <div class="text-xs text-gray-500 mb-2 truncate">
                                        <?php echo htmlspecialchars($ev['client'] ?? 'No Client'); ?>
                                    </div>

                                    <?php if ($isHeadSales): ?>
                                    <div class="flex gap-2">
                                        <button onclick="openEventActionModal(<?php echo $ev['id']; ?>, '<?php echo addslashes($ev['name']); ?>', 'cancel')"
                                                class="text-xs text-red-600 hover:text-red-800 border border-red-200 px-2 py-1 rounded hover:bg-red-50">
                                            Cancel
                                        </button>
                                        <button onclick="openEventActionModal(<?php echo $ev['id']; ?>, '<?php echo addslashes($ev['name']); ?>', 'postpone')"
                                                class="text-xs text-yellow-600 hover:text-yellow-800 border border-yellow-200 px-2 py-1 rounded hover:bg-yellow-50">
                                            Postpone
                                        </button>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                                <div class="p-3 text-center">
                                    <a href="events.php" class="text-sm text-blue-600 hover:text-blue-800 font-medium">View All Events</a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column (Tables and Forms) - 9/12 width -->
                    <div class="lg:col-span-9 space-y-6 min-w-0">
                        <!-- Quote Status Table -->
                        <div class="card w-full min-w-0" id="quoteStatus">
                            <div class="p-6 border-b border-gray-100 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                                <div>
                                    <h2 class="text-lg font-bold text-gray-800">Quote Status</h2>
                                    <div class="mt-1 text-xs text-gray-500">
                                        <span class="mr-3">Pending: <span class="font-semibold"><?php echo (int)$quotePendingCount; ?></span></span>
                                        <span class="mr-3">Approved: <span class="font-semibold"><?php echo (int)$quoteApprovedCount; ?></span></span>
                                        <span>Rejected: <span class="font-semibold"><?php echo (int)$quoteRejectedCount; ?></span></span>
                                    </div>
                                </div>
                                <form method="get" class="flex items-center gap-2">
                                    <select name="quote_status" class="rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm">
                                        <option value="all" <?php echo $quoteFilter === 'all' ? 'selected' : ''; ?>>All</option>
                                        <option value="pending" <?php echo $quoteFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                        <option value="approved" <?php echo $quoteFilter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                                        <option value="rejected" <?php echo $quoteFilter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                    </select>
                                    <button type="submit" class="bg-blue-600 text-white py-2 px-3 rounded-lg hover:bg-blue-700 transition font-medium text-sm">Filter</button>
                                </form>
                            </div>
                            <div class="table-container w-full">
                                <table class="modern-table compact-table">
                                    <thead>
                                        <tr>
                                            <th class="col-event">Event</th>
                                            <th class="col-client">Client</th>
                                            <th class="col-date">Date</th>
                                            <th class="col-status">Quote</th>
                                            <th class="col-progress">Finance Comment</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($quoteEvents)): ?>
                                            <tr><td colspan="5" class="text-center py-8 text-gray-500">No events found.</td></tr>
                                        <?php else: ?>
                                            <?php foreach (array_slice($quoteEvents, 0, 30) as $ev): ?>
                                            <tr>
                                                <td class="col-event">
                                                    <div class="font-medium text-gray-900 truncate-text" title="<?php echo htmlspecialchars($ev['name'] ?? ''); ?>">
                                                        <?php echo htmlspecialchars($ev['name'] ?? ''); ?>
                                                    </div>
                                                </td>
                                                <td class="col-client">
                                                    <div class="truncate-text" title="<?php echo htmlspecialchars($ev['client'] ?? '-'); ?>">
                                                        <?php echo htmlspecialchars($ev['client'] ?? '-'); ?>
                                                    </div>
                                                </td>
                                                <td class="col-date"><?php echo !empty($ev['date']) ? htmlspecialchars(date('M j, Y', strtotime($ev['date']))) : '-'; ?></td>
                                                <td class="col-status">
                                                    <?php $qs = strtolower((string)($ev['quote_status'] ?? 'pending')); ?>
                                                    <?php if ($qs === 'approved'): ?>
                                                        <span class="status-badge" style="background:#dcfce7;color:#166534;">Approved</span>
                                                    <?php elseif ($qs === 'rejected'): ?>
                                                        <span class="status-badge" style="background:#fee2e2;color:#991b1b;">Rejected</span>
                                                    <?php else: ?>
                                                        <span class="status-badge" style="background:#ffedd5;color:#9a3412;">Pending</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="col-progress">
                                                    <div class="text-xs text-gray-600" style="white-space: pre-wrap;"><?php echo htmlspecialchars((string)($ev['quote_finance_comment'] ?? '')); ?></div>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Graphic Requests Table -->
                        <div class="card w-full min-w-0" id="graphicRequests">
                            <div class="p-6 border-b border-gray-100 flex justify-between items-center">
                                <h2 class="text-lg font-bold text-gray-800">Graphic Requests</h2>
                                <span class="bg-blue-100 text-blue-800 text-xs font-semibold px-2.5 py-0.5 rounded">
                                    <?php echo count($graphicRequests); ?> Total
                                </span>
                            </div>
                            <div class="table-container w-full">
                                <table class="modern-table">
                                    <thead>
                                        <tr>
                                            <th class="col-event">Event</th>
                                            <th class="col-client">Client</th>
                                            <th class="col-date">Date</th>
                                            <th class="col-requested">Requested By</th>
                                            <th class="col-assigned">Assigned To</th>
                                            <th class="col-progress">Progress Timeline</th>
                                            <th class="col-status">Status</th>
                                            <th class="col-actions">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($graphicRequests)): ?>
                                            <tr><td colspan="8" class="text-center py-8 text-gray-500">No graphic requests found.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($graphicRequests as $req): ?>
                                            <tr>
                                                <td class="col-event">
                                                    <div class="font-medium text-gray-900 truncate-text" title="<?php echo htmlspecialchars($req['event_name']); ?>">
                                                        <?php echo htmlspecialchars($req['event_name']); ?>
                                                    </div>
                                                </td>
                                                <td class="col-client">
                                                    <div class="truncate-text" title="<?php echo htmlspecialchars($req['client'] ?? '-'); ?>">
                                                        <?php echo htmlspecialchars($req['client'] ?? '-'); ?>
                                                    </div>
                                                </td>
                                                <td class="col-date"><?php echo !empty($req['event_date']) ? htmlspecialchars(date('M j, Y', strtotime($req['event_date']))) : '-'; ?></td>
                                                <td class="col-requested">
                                                    <div class="truncate-text" title="<?php echo htmlspecialchars($req['requested_by_name'] ?? '-'); ?>">
                                                        <?php echo htmlspecialchars($req['requested_by_name'] ?? '-'); ?>
                                                    </div>
                                                </td>
                                                <td class="col-assigned">
                                                    <div class="truncate-text" title="<?php echo htmlspecialchars($req['assigned_to_name'] ?? 'Unassigned'); ?>">
                                                        <?php echo htmlspecialchars($req['assigned_to_name'] ?? 'Unassigned'); ?>
                                                    </div>
                                                </td>
                                                <td class="col-progress">
                                                    <div class="progress-timeline text-xs text-gray-600">
                                                        <div><span class="font-medium">Requested:</span> <?php echo !empty($req['created_at']) ? htmlspecialchars(date('M j', strtotime($req['created_at']))) : '-'; ?></div>
                                                        <div><span class="font-medium">Assigned:</span> <?php echo !empty($req['assigned_at']) ? htmlspecialchars(date('M j', strtotime($req['assigned_at']))) : '-'; ?></div>
                                                        <div><span class="font-medium">Started:</span> <?php echo !empty($req['started_at']) ? htmlspecialchars(date('M j', strtotime($req['started_at']))) : '-'; ?></div>
                                                        <div><span class="font-medium">Completed:</span> <?php echo !empty($req['completed_at']) ? htmlspecialchars(date('M j', strtotime($req['completed_at']))) : '-'; ?></div>
                                                    </div>
                                                </td>
                                                <td class="col-status">
                                                    <span class="status-badge status-<?php echo $req['status']; ?>">
                                                        <?php echo ucfirst(str_replace('_', ' ', $req['status'])); ?>
                                                    </span>
                                                </td>
                                                <td class="col-actions">
                                                    <?php if ($req['status'] === 'pending' && $req['requested_by'] == $user['id']): ?>
                                                        <form method="POST" onsubmit="return confirm('Cancel this request?');">
                                                            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                                            <input type="hidden" name="action" value="cancel_request">
                                                            <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>">
                                                            <button type="submit" class="text-red-500 hover:text-red-700 p-1 rounded hover:bg-red-50" title="Cancel Request">
                                                                <i class="fas fa-times-circle"></i>
                                                            </button>
                                                        </form>
                                                    <?php else: ?>
                                                        <span class="text-gray-400">-</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Operation Requests Table -->
                        <div class="card w-full min-w-0" id="supervisorRequests">
                            <div class="p-6 border-b border-gray-100 flex justify-between items-center">
                                <h2 class="text-lg font-bold text-gray-800">Operation Requests</h2>
                                <span class="bg-purple-100 text-purple-800 text-xs font-semibold px-2.5 py-0.5 rounded">
                                    <?php echo count($supervisorRequests); ?> Total
                                </span>
                            </div>
                            <div class="table-container w-full">
                                <table class="modern-table compact-table">
                                    <thead>
                                        <tr>
                                            <th class="col-event">Event</th>
                                            <th class="col-client">Client</th>
                                            <th class="col-date">Date</th>
                                            <th class="col-requested">Requested By</th>
                                            <th class="col-assigned">Assigned To</th>
                                            <th class="col-progress">Progress Timeline</th>
                                            <th class="col-status">Status</th>
                                            <th class="col-actions">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($supervisorRequests)): ?>
                                            <tr><td colspan="8" class="text-center py-8 text-gray-500">No operation requests found.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($supervisorRequests as $req): ?>
                                            <tr>
                                                <td class="col-event">
                                                    <div class="font-medium text-gray-900 truncate-text" title="<?php echo htmlspecialchars($req['event_name']); ?>">
                                                        <?php echo htmlspecialchars($req['event_name']); ?>
                                                    </div>
                                                </td>
                                                <td class="col-client">
                                                    <div class="truncate-text" title="<?php echo htmlspecialchars($req['client'] ?? '-'); ?>">
                                                        <?php echo htmlspecialchars($req['client'] ?? '-'); ?>
                                                    </div>
                                                </td>
                                                <td class="col-date">
                                                    <?php echo !empty($req['event_date']) ? htmlspecialchars(date('M j, Y', strtotime($req['event_date']))) : '-'; ?>
                                                </td>
                                                <td class="col-requested">
                                                    <div class="truncate-text" title="<?php echo htmlspecialchars($req['requested_by_name'] ?? '-'); ?>">
                                                        <?php echo htmlspecialchars($req['requested_by_name'] ?? '-'); ?>
                                                    </div>
                                                </td>
                                                <td class="col-assigned">
                                                    <div class="truncate-text" title="<?php echo htmlspecialchars($req['assigned_to_name'] ?? 'Unassigned'); ?>">
                                                        <?php echo htmlspecialchars($req['assigned_to_name'] ?? 'Unassigned'); ?>
                                                    </div>
                                                </td>
                                                <td class="col-progress">
                                                    <div class="progress-timeline text-xs text-gray-600">
                                                        <div><span class="font-medium">Requested:</span> <?php echo !empty($req['created_at']) ? htmlspecialchars(date('M j', strtotime($req['created_at']))) : '-'; ?></div>
                                                        <div><span class="font-medium">Assigned:</span> <?php echo !empty($req['assigned_at']) ? htmlspecialchars(date('M j', strtotime($req['assigned_at']))) : '-'; ?></div>
                                                        <div><span class="font-medium">Started:</span> <?php echo !empty($req['started_at']) ? htmlspecialchars(date('M j', strtotime($req['started_at']))) : '-'; ?></div>
                                                        <div><span class="font-medium">Completed:</span> <?php echo !empty($req['completed_at']) ? htmlspecialchars(date('M j', strtotime($req['completed_at']))) : '-'; ?></div>
                                                    </div>
                                                </td>
                                                <td class="col-status">
                                                    <span class="status-badge status-<?php echo $req['status']; ?>">
                                                        <?php echo ucfirst(str_replace('_', ' ', $req['status'])); ?>
                                                    </span>
                                                </td>
                                                <td class="col-actions">
                                                    <?php if ($req['status'] === 'pending' && $req['requested_by'] == $user['id']): ?>
                                                        <form method="POST" onsubmit="return confirm('Cancel this request?');">
                                                            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                                            <input type="hidden" name="action" value="cancel_supervisor_request">
                                                            <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>">
                                                            <button type="submit" class="text-red-500 hover:text-red-700 p-1 rounded hover:bg-red-50" title="Cancel Request">
                                                                <i class="fas fa-times-circle"></i>
                                                            </button>
                                                        </form>
                                                    <?php else: ?>
                                                        <span class="text-gray-400">-</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <?php if ($isHeadSales): ?>
                        <!-- Hidden Tables for Events Without Requests -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <?php if (count($eventsWithoutGraphic) > 0): ?>
                            <div id="eventsWithoutGraphicTable" class="hidden card">
                                <div class="p-4 border-b border-gray-100 flex justify-between items-center">
                                    <h3 class="font-medium text-gray-800">Events Without Graphic Requests</h3>
                                    <span class="bg-blue-100 text-blue-800 text-xs font-semibold px-2 py-0.5 rounded">
                                        <?php echo count($eventsWithoutGraphic); ?>
                                    </span>
                                </div>
                                <div class="p-4 max-h-80 overflow-y-auto">
                                    <?php foreach ($eventsWithoutGraphic as $ev): ?>
                                    <div class="mb-3 p-3 border border-gray-100 rounded-lg hover:bg-blue-50 transition">
                                        <div class="flex justify-between items-start mb-1">
                                            <div class="font-medium text-gray-900 text-sm truncate" title="<?php echo htmlspecialchars($ev['name']); ?>">
                                                <?php echo htmlspecialchars($ev['name']); ?>
                                            </div>
                                            <div class="text-xs text-gray-500 whitespace-nowrap ml-2">
                                                <?php echo !empty($ev['date']) ? date('M j', strtotime($ev['date'])) : '-'; ?>
                                            </div>
                                        </div>
                                        <div class="text-xs text-gray-500 mb-2 truncate">
                                            <?php echo htmlspecialchars($ev['client'] ?? 'No Client'); ?>
                                        </div>
                                        <a href="sales_dashboard.php?focus=request_graphic&graphic_event_id=<?php echo (int)$ev['id']; ?>#requestGraphic" 
                                           class="inline-flex items-center text-xs bg-blue-100 text-blue-700 hover:bg-blue-200 px-2 py-1 rounded transition">
                                            <i class="fas fa-plus mr-1 text-xs"></i>
                                            Request Graphic
                                        </a>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php if (count($eventsWithoutSupervisor) > 0): ?>
                            <div id="eventsWithoutSupervisorTable" class="hidden card">
                                <div class="p-4 border-b border-gray-100 flex justify-between items-center">
                                    <h3 class="font-medium text-gray-800">Events Without Operation Requests</h3>
                                    <span class="bg-purple-100 text-purple-800 text-xs font-semibold px-2 py-0.5 rounded">
                                        <?php echo count($eventsWithoutSupervisor); ?>
                                    </span>
                                </div>
                                <div class="p-4 max-h-80 overflow-y-auto">
                                    <?php foreach ($eventsWithoutSupervisor as $ev): ?>
                                    <div class="mb-3 p-3 border border-gray-100 rounded-lg hover:bg-purple-50 transition">
                                        <div class="flex justify-between items-start mb-1">
                                            <div class="font-medium text-gray-900 text-sm truncate" title="<?php echo htmlspecialchars($ev['name']); ?>">
                                                <?php echo htmlspecialchars($ev['name']); ?>
                                            </div>
                                            <div class="text-xs text-gray-500 whitespace-nowrap ml-2">
                                                <?php echo !empty($ev['date']) ? date('M j', strtotime($ev['date'])) : '-'; ?>
                                            </div>
                                        </div>
                                        <div class="text-xs text-gray-500 mb-2 truncate">
                                            <?php echo htmlspecialchars($ev['client'] ?? 'No Client'); ?>
                                        </div>
                                        <a href="sales_dashboard.php?focus=request_supervisor&supervisor_event_id=<?php echo (int)$ev['id']; ?>#requestSupervisor" 
                                           class="inline-flex items-center text-xs bg-purple-100 text-purple-700 hover:bg-purple-200 px-2 py-1 rounded transition">
                                            <i class="fas fa-plus mr-1 text-xs"></i>
                                            Request Operation
                                        </a>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

            </main>
        </div>
    </div>

    <!-- Event Action Modal -->
    <div id="eventActionModal" class="modal fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
        <div class="modal-content bg-white rounded-xl shadow-2xl w-full max-w-md p-6 m-4">
            <div class="flex justify-between items-center mb-4 border-b pb-3">
                <h3 id="modalTitle" class="text-lg font-bold text-gray-800"></h3>
                <button onclick="closeEventActionModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>

            <form method="POST" id="eventActionForm">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <input type="hidden" name="action" value="event_action">
                <input type="hidden" name="event_id" id="modalEventId">
                <input type="hidden" name="action_type" id="modalActionType">

                <div class="mb-4">
                    <p id="modalEventName" class="text-sm text-gray-600 font-medium bg-gray-50 p-2 rounded"></p>
                </div>

                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Reason <span class="text-red-500">*</span>
                    </label>
                    <textarea name="comment" id="modalComment" required rows="3"
                              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm"
                              placeholder="Please provide a reason..."></textarea>
                </div>

                <div class="flex gap-3 justify-end">
                    <button type="button" onclick="closeEventActionModal()"
                            class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 font-medium text-sm">
                        Cancel
                    </button>
                    <button type="submit" id="modalSubmitBtn"
                            class="px-4 py-2 rounded-lg text-white font-medium text-sm shadow-sm">
                        Submit
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Profile Dropdown Toggle
        document.addEventListener('DOMContentLoaded', function() {
            const profileBtn = document.querySelector('#userProfile button');
            const dropdown = document.getElementById('headerProfileDropdown');

            if(profileBtn && dropdown) {
                profileBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    dropdown.classList.toggle('hidden');
                });

                document.addEventListener('click', (e) => {
                    if(!profileBtn.contains(e.target) && !dropdown.contains(e.target)) {
                        dropdown.classList.add('hidden');
                    }
                });
            }

            // Scroll to focus section if needed
            const params = new URLSearchParams(window.location.search);
            const focus = params.get('focus');
            if (focus) {
                const map = {
                    request_graphic: 'requestGraphic',
                    request_supervisor: 'requestSupervisor',
                    graphic_requests: 'graphicRequests',
                    supervisor_requests: 'supervisorRequests'
                };
                const targetId = map[focus] || focus;
                const el = document.getElementById(targetId);
                if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        });

        // Toggle section visibility
        function toggleSection(id) {
            const el = document.getElementById(id);
            if (!el) return;
            el.classList.toggle('hidden');
        }

        // Modal Functions
        function openEventActionModal(eventId, eventName, actionType) {
            const modal = document.getElementById('eventActionModal');
            const title = document.getElementById('modalTitle');
            const eventNameEl = document.getElementById('modalEventName');
            const submitBtn = document.getElementById('modalSubmitBtn');

            document.getElementById('modalEventId').value = eventId;
            document.getElementById('modalActionType').value = actionType;
            document.getElementById('modalComment').value = '';

            if (actionType === 'cancel') {
                title.innerHTML = '<i class="fas fa-times-circle text-red-500 mr-2"></i> Cancel Event';
                submitBtn.className = 'px-4 py-2 rounded-lg text-white font-medium text-sm shadow-sm bg-red-600 hover:bg-red-700';
                submitBtn.innerHTML = 'Confirm Cancellation';
            } else {
                title.innerHTML = '<i class="fas fa-clock text-yellow-500 mr-2"></i> Postpone Event';
                submitBtn.className = 'px-4 py-2 rounded-lg text-white font-medium text-sm shadow-sm bg-yellow-500 hover:bg-yellow-600';
                submitBtn.innerHTML = 'Confirm Postponement';
            }

            eventNameEl.textContent = 'Event: ' + eventName;
            modal.classList.add('show');
        }

        function closeEventActionModal() {
            const modal = document.getElementById('eventActionModal');
            modal.classList.remove('show');
        }
    </script>
</body>
</html>