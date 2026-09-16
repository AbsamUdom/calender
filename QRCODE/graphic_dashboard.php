<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';

// Handle logout request
if (isset($_GET['logout'])) {
    logout_user();
    header('Location: login.php');
    exit;
}

require_login();
$db = get_db();
$user = current_user();

function gd_ensure_graphic_request_schema(PDO $db) {
    $hasMockup = false;
    $hasNotes  = false;
    $colsStmt = $db->query("SHOW COLUMNS FROM graphic_requests");
    $cols = $colsStmt ? $colsStmt->fetchAll(PDO::FETCH_COLUMN) : [];
    foreach ($cols as $col) {
        if ($col === 'mockup_file') {
            $hasMockup = true;
        } elseif ($col === 'notes') {
            $hasNotes = true;
        }
    }
    if (!$hasMockup) {
        $db->exec("ALTER TABLE graphic_requests ADD COLUMN mockup_file VARCHAR(255) NULL");
    }
    if (!$hasNotes) {
        $db->exec("ALTER TABLE graphic_requests ADD COLUMN notes TEXT NULL");
    }
}

gd_ensure_graphic_request_schema($db);

// Ensure uploads directory exists for event assets (shared with events.php)
$eventUploadDir = __DIR__ . '/uploads/events';
if (!is_dir($eventUploadDir)) {
    @mkdir($eventUploadDir, 0755, true);
}

// Simple mockup upload handler for graphic dashboard
function gd_handle_mockup_upload(string $fieldName, string $uploadDir): ?string {
    if (!isset($_FILES[$fieldName]) || !is_array($_FILES[$fieldName])) {
        return null;
    }
    $file = $_FILES[$fieldName];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null; // no file uploaded
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new Exception('Failed to upload mockup file');
    }

    $allowedExtensions = ['pdf','png','jpg','jpeg'];
    $originalName = $file['name'] ?? 'mockup';
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($extension && !in_array($extension, $allowedExtensions, true)) {
        throw new Exception('Unsupported mockup file type. Allowed: PDF, PNG, JPG, JPEG');
    }

    $baseName = preg_replace('/[^a-zA-Z0-9-_]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
    if ($baseName === '') {
        $baseName = 'event_mockup';
    }
    $uniqueSuffix = time() . '_' . bin2hex(random_bytes(4));
    $newFileName = $baseName . '_' . $uniqueSuffix . ($extension ? '.' . $extension : '');
    $targetPath = rtrim($uploadDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $newFileName;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        throw new Exception('Unable to save mockup file');
    }

    // Return web path used elsewhere in the app
    return 'uploads/events/' . $newFileName;
}

// Only graphic role can access this dashboard
$roleNorm = strtolower(trim((string)($user['role'] ?? '')));
$roleNorm = str_replace(['_', '-'], ' ', $roleNorm);
$roleNorm = preg_replace('/\s+/', ' ', $roleNorm);
if ($roleNorm === 'admin') {
    header('Location: admin_dashboard.php');
    exit;
}
if (!in_array($roleNorm, ['graphic', 'graphics', 'graphic designer', 'super'], true)) {
    header('Location: index.php');
    exit;
}

// Handle status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_abort();
    $action = $_POST['action'] ?? '';
    
    if ($action === 'start_work') {
        $requestId = (int)($_POST['request_id'] ?? 0);
        if ($requestId > 0) {
            $stmt = $db->prepare("UPDATE graphic_requests SET status = 'in_progress' WHERE id = ? AND assigned_to = ?");
            $stmt->execute([$requestId, $user['id']]);
            flash_add('success', 'Work started! Good luck!');
        }
        header('Location: graphic_dashboard.php');
        exit;
    }
    
    if ($action === 'mark_done') {
        $requestId = (int)($_POST['request_id'] ?? 0);
        if ($requestId > 0) {
            try {
                // Optional mockup upload when marking done (stored on graphic_requests only)
                $mockupFile = gd_handle_mockup_upload('mockup_file', $eventUploadDir);

                $eventId = 0;
                try {
                    $st = $db->prepare('SELECT event_id FROM graphic_requests WHERE id = ?');
                    $st->execute([$requestId]);
                    $eventId = (int)($st->fetchColumn() ?? 0);
                } catch (Throwable $e) {
                    $eventId = 0;
                }

                // Mark graphic request as completed and attach mockup to the request record
                if ($mockupFile) {
                    $stmt = $db->prepare("UPDATE graphic_requests SET status = 'completed', completed_at = NOW(), mockup_file = ? WHERE id = ? AND assigned_to = ?");
                    $stmt->execute([$mockupFile, $requestId, $user['id']]);

                    if ($eventId > 0) {
                        try {
                            // Publish mockup to event so it is visible for Production
                            $evt = $db->prepare('UPDATE events SET mockup_file = ? WHERE id = ?');
                            $evt->execute([$mockupFile, $eventId]);

                            // Create a print task for Production when mockup is completed (if none active)
                            $chk = $db->prepare("SELECT id FROM print_tasks WHERE event_id = ? AND status <> 'delivered' ORDER BY id DESC LIMIT 1");
                            $chk->execute([$eventId]);
                            $existingTaskId = (int)($chk->fetchColumn() ?? 0);
                            if ($existingTaskId <= 0) {
                                $ins = $db->prepare("INSERT INTO print_tasks (event_id, task_type, source, title, qty, status, notes, created_by) VALUES (?, ?, 'graphic', ?, NULL, 'received', NULL, ?)");
                                $ins->execute([$eventId, 'mockup', 'Mockup Ready - Printing', (int)($user['id'] ?? 0)]);
                            }
                        } catch (Throwable $e) {
                            // ignore
                        }
                    }
                } else {
                    $stmt = $db->prepare("UPDATE graphic_requests SET status = 'completed', completed_at = NOW() WHERE id = ? AND assigned_to = ?");
                    $stmt->execute([$requestId, $user['id']]);
                }

                flash_add('success', 'Great job! Work marked as completed.' . ($mockupFile ? ' Mockup uploaded and awaiting review.' : ''));
            } catch (Exception $e) {
                flash_add('error', 'Task completed, but mockup upload failed: ' . $e->getMessage());
            }
        }
        header('Location: graphic_dashboard.php');
        exit;
    }
}

try {
    $graphicUserId = (int)($user['id'] ?? 0);
    $graphicUserName = trim((string)($user['name'] ?? ''));
    $graphicUserNameNorm = function_exists('mb_strtolower') ? mb_strtolower($graphicUserName, 'UTF-8') : strtolower($graphicUserName);
    $assignedEventsStmt = $db->query('SELECT id, user_id, graphics, graphics_users FROM events');
    foreach ($assignedEventsStmt ? ($assignedEventsStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [] as $assignedEvent) {
        $assignedGraphicIds = json_decode((string)($assignedEvent['graphics_users'] ?? ''), true);
        $assignedGraphicIds = is_array($assignedGraphicIds) ? array_map('intval', $assignedGraphicIds) : [];
        $legacyGraphicName = trim((string)($assignedEvent['graphics'] ?? ''));
        $legacyGraphicName = function_exists('mb_strtolower') ? mb_strtolower($legacyGraphicName, 'UTF-8') : strtolower($legacyGraphicName);
        if (!in_array($graphicUserId, $assignedGraphicIds, true) && $legacyGraphicName !== $graphicUserNameNorm) {
            continue;
        }
        $existingRequest = $db->prepare("SELECT id FROM graphic_requests WHERE event_id = ? AND assigned_to = ? AND status <> 'cancelled' LIMIT 1");
        $existingRequest->execute([(int)$assignedEvent['id'], $graphicUserId]);
        if (!$existingRequest->fetchColumn()) {
            $requestOwnerId = (int)($assignedEvent['user_id'] ?? 0);
            if ($requestOwnerId <= 0 || !db_is_user_active($requestOwnerId)) {
                $requestOwnerId = $graphicUserId;
            }
            $insertRequest = $db->prepare("INSERT INTO graphic_requests (event_id, requested_by, assigned_to, assigned_by, status, assigned_at, notes) VALUES (?, ?, ?, ?, 'assigned', NOW(), 'Synchronized from event assignment')");
            $insertRequest->execute([(int)$assignedEvent['id'], $requestOwnerId, $graphicUserId, $requestOwnerId]);
        }
    }
} catch (Throwable $e) {
    error_log('Graphic assignment synchronization failed: ' . $e->getMessage());
}

// Auto-complete tasks when event date has passed (for this graphic user)
try {
    $autoComplete = $db->prepare("UPDATE graphic_requests gr JOIN events e ON gr.event_id = e.id SET gr.status = 'completed', gr.completed_at = COALESCE(gr.completed_at, NOW()) WHERE gr.assigned_to = ? AND gr.status IN ('assigned', 'in_progress') AND COALESCE(e.end_date, e.date) < CURDATE()");
    $autoComplete->execute([(int)($user['id'] ?? 0)]);
} catch (Throwable $e) {
    // ignore auto-complete failures
}

// Get assigned requests for this graphic designer
$requestsStmt = $db->prepare("
    SELECT 
        gr.*, 
        e.name as event_name, 
        e.date as event_date, 
        e.end_date, 
        e.client, 
        e.location,
        e.checklist_file, 
        e.mockup_file AS event_mockup_file,
        e.coordinator, 
        e.remarks,
        u1.name as requested_by_name
    FROM graphic_requests gr
    JOIN events e ON gr.event_id = e.id
    LEFT JOIN users u1 ON gr.requested_by = u1.id
    WHERE gr.assigned_to = ?
    ORDER BY 
        CASE gr.status 
            WHEN 'assigned' THEN 1 
            WHEN 'in_progress' THEN 2 
            WHEN 'completed' THEN 3 
        END,
        CASE WHEN gr.status = 'completed' THEN gr.completed_at END DESC,
        COALESCE(e.end_date, e.date) ASC
");
$requestsStmt->execute([$user['id']]);
$myTasks = $requestsStmt->fetchAll(PDO::FETCH_ASSOC);

$printTasks = [];
try {
    $eventIds = array_values(array_unique(array_map(fn($t) => (int)($t['event_id'] ?? 0), $myTasks)));
    $eventIds = array_values(array_filter($eventIds, fn($id) => $id > 0));
    if ($eventIds) {
        $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
        $stmt = $db->prepare("SELECT pt.*, e.name AS event_name FROM print_tasks pt JOIN events e ON pt.event_id = e.id WHERE pt.event_id IN ($placeholders) ORDER BY FIELD(pt.status,'received','in_progress','printed','delivered'), pt.id DESC");
        $stmt->execute($eventIds);
        $printTasks = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable $e) {
    $printTasks = [];
}

$receivedPrintCount = count(array_filter($printTasks, fn($t) => (string)($t['status'] ?? 'received') === 'received'));
$inProgressPrintCount = count(array_filter($printTasks, fn($t) => (string)($t['status'] ?? 'received') === 'in_progress'));
$printedPrintCount = count(array_filter($printTasks, fn($t) => (string)($t['status'] ?? 'received') === 'printed'));
$deliveredPrintCount = count(array_filter($printTasks, fn($t) => (string)($t['status'] ?? 'received') === 'delivered'));

// Stats
$assignedCount = count(array_filter($myTasks, fn($r) => $r['status'] === 'assigned'));
$inProgressCount = count(array_filter($myTasks, fn($r) => $r['status'] === 'in_progress'));
$completedCount = count(array_filter($myTasks, fn($r) => $r['status'] === 'completed'));
$totalTasks = count($myTasks);
$graphicAllTimeEvents = 0;
if (in_array($roleNorm, ['graphic', 'graphics', 'graphic designer'], true)) {
    $graphicWorkflowAssignments = [];
    $graphicWorkflowStmt = $db->query("SELECT event_id, assigned_to FROM graphic_requests WHERE assigned_to IS NOT NULL AND status <> 'cancelled'");
    foreach ($graphicWorkflowStmt ? ($graphicWorkflowStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [] as $graphicWorkflow) {
        $workflowEventId = (int)($graphicWorkflow['event_id'] ?? 0);
        $workflowUserId = (int)($graphicWorkflow['assigned_to'] ?? 0);
        if ($workflowEventId > 0 && $workflowUserId > 0) {
            $graphicWorkflowAssignments[$workflowEventId][$workflowUserId] = true;
        }
    }

    $graphicUserId = (int)($user['id'] ?? 0);
    $graphicUserName = trim((string)($user['name'] ?? ''));
    $graphicUserName = function_exists('mb_strtolower') ? mb_strtolower($graphicUserName, 'UTF-8') : strtolower($graphicUserName);
    $graphicAssignedEventIds = [];
    $graphicEventsStmt = $db->query('SELECT id, graphics, graphics_users FROM events');
    foreach ($graphicEventsStmt ? ($graphicEventsStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [] as $graphicEvent) {
        $graphicEventId = (int)($graphicEvent['id'] ?? 0);
        if (isset($graphicWorkflowAssignments[$graphicEventId])) {
            $isGraphicAssigned = isset($graphicWorkflowAssignments[$graphicEventId][$graphicUserId]);
        } else {
            $graphicUserIds = json_decode((string)($graphicEvent['graphics_users'] ?? ''), true);
            $graphicUserIds = is_array($graphicUserIds) ? array_values(array_unique(array_filter(array_map('intval', $graphicUserIds), fn($id) => $id > 0))) : [];
            $legacyGraphicName = trim((string)($graphicEvent['graphics'] ?? ''));
            $legacyGraphicName = function_exists('mb_strtolower') ? mb_strtolower($legacyGraphicName, 'UTF-8') : strtolower($legacyGraphicName);
            $isGraphicAssigned = in_array($graphicUserId, $graphicUserIds, true) || $legacyGraphicName === $graphicUserName;
        }
        if ($isGraphicAssigned) {
            $graphicAssignedEventIds[$graphicEventId] = true;
        }
    }
    $graphicAllTimeEvents = count($graphicAssignedEventIds);
}

$flashes = flash_consume();
$displayName = $user['name'] ?? $user['email'] ?? 'User';
$pageTitle = 'Graphic Team Dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Graphic Designer Dashboard | Hugo Domingo Events</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="style.css">
    <style>
        /* Stat Cards - Colorful like Super Dashboard */
        .stat-card {
            border-radius: 12px;
            padding: 1.25rem;
            color: white;
            position: relative;
            overflow: hidden;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        .stat-card .stat-icon {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            font-size: 2.5rem;
            opacity: 0.3;
        }
        .stat-card .stat-value {
            font-size: 2rem;
            font-weight: 700;
            line-height: 1;
            margin-bottom: 0.25rem;
        }
        .stat-card .stat-label {
            font-size: 0.875rem;
            opacity: 0.9;
            font-weight: 500;
        }
        .stat-card .stat-badge {
            display: inline-block;
            background: rgba(255,255,255,0.2);
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            margin-top: 0.5rem;
        }
        .stat-card .stat-link {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            color: white;
            font-size: 0.8rem;
            margin-top: 0.75rem;
            opacity: 0.9;
            text-decoration: none;
        }
        .stat-card .stat-link:hover { opacity: 1; }
        
        .stat-blue { background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%); }
        .stat-orange { background: linear-gradient(135deg, #f97316 0%, #ea580c 100%); }
        .stat-green { background: linear-gradient(135deg, #10b981 0%, #059669 100%); }
        .stat-purple { background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%); }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        /* Content Section - card style like React Card */
        .content-section {
            background: #ffffff;
            border-radius: 1rem; /* rounded-2xl */
            padding: 1.5rem;     /* p-6 */
            box-shadow: 0 6px 18px rgba(15,23,42,0.08);
            margin-bottom: 1.5rem;
            border: 1px solid #e2e8f0;
        }
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid #e2e8f0;
        }
        .section-title {
            font-size: 1rem;
            font-weight: 600;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        /* Task Cards - inner rows like bg-slate-50 rounded-xl */
        .task-card {
            background: #f8fafc;               /* bg-slate-50 */
            border-radius: 0.75rem;            /* rounded-xl */
            border: 1px solid #e2e8f0;
            padding: 1rem;                     /* p-4 */
            margin-bottom: 0.75rem;
            transition: all 0.2s;
        }
        .task-card:hover {
            border-color: #c7d2fe;
            box-shadow: 0 4px 14px rgba(148,163,184,0.35);
            background: #f1f5f9;
        }
        /* Remove colored left strip for a clean card edge */
        .task-card.assigned { border-left: none; }
        .task-card.in_progress { border-left: none; }
        
        .status-badge {
            display: inline-block;
            padding: 0.2rem 0.6rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        .status-assigned { background: #dbeafe; color: #1e40af; }
        .status-in_progress { background: #d1fae5; color: #065f46; }
        .status-completed { background: #e0e7ff; color: #3730a3; }
        
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.4rem;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-weight: 500;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
            text-decoration: none;
        }
        .btn-primary { background: #8b5cf6; color: white; }
        .btn-primary:hover { background: #7c3aed; }
        .btn-success { background: #10b981; color: white; }
        .btn-success:hover { background: #059669; }
        .btn-secondary { background: #f1f5f9; color: #475569; }
        .btn-secondary:hover { background: #e2e8f0; }
        
        /* Data Table - clean like React table */
        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th {
            text-align: left;
            padding: 0.75rem;
            font-size: 0.8rem;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            border-bottom: 1px solid #e2e8f0;
        }
        .data-table td {
            padding: 0.75rem;
            font-size: 0.875rem;
            color: #334155;
            border-bottom: 1px solid #f1f5f9;
        }
        .data-table tr:hover { background: #f8fafc; }
        
        /* Tablet Responsive */
        @media (max-width: 1024px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        /* Mobile Responsive */
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0 !important;
                width: 100% !important;
                padding: 1rem !important;
                padding-top: 4.5rem !important;
            }

            /* Stats Grid - 2 columns on mobile */
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 0.75rem;
            }
            
            .stat-card {
                padding: 1rem;
            }
            
            .stat-card .stat-value {
                font-size: 1.5rem;
            }
            
            .stat-card .stat-label {
                font-size: 0.75rem;
            }
            
            .stat-card .stat-badge {
                font-size: 0.65rem;
                padding: 0.2rem 0.5rem;
            }
            
            .stat-card .stat-icon {
                font-size: 1.75rem;
                right: 0.75rem;
            }
            
            .content-section {
                padding: 1rem;
            }
            
            .section-title {
                font-size: 0.9rem;
            }
            
            .task-card {
                padding: 0.875rem;
            }
            
            .task-card h4 {
                font-size: 0.9rem !important;
            }
            
            .task-card .flex-wrap {
                gap: 0.5rem !important;
            }
            
            .task-card .text-sm {
                font-size: 0.75rem !important;
            }
            
            .btn {
                padding: 0.4rem 0.75rem;
                font-size: 0.75rem;
            }
            
            .data-table th,
            .data-table td {
                padding: 0.5rem;
                font-size: 0.75rem;
            }
        }
        
        @media (max-width: 480px) {
            .main-content {
                padding: 0.75rem !important;
                padding-top: 4rem !important;
            }

            /* Stats Grid - still 2 columns but smaller */
            .stats-grid {
                gap: 0.5rem;
            }
            
            .stat-card {
                padding: 0.75rem;
            }
            
            .stat-card .stat-value {
                font-size: 1.25rem;
            }
            
            .stat-card .stat-icon {
                font-size: 1.5rem;
            }
            
            .task-card .flex.gap-2 {
                flex-direction: column;
            }
            
            .task-card .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <?php $activePage = 'graphic_dashboard.php'; include __DIR__ . '/sidebar.php'; ?>

        <!-- Main Content -->
        <main class="main-content">
            <div class="top-bar">
                <div class="page-title">
                    <h1><?php echo htmlspecialchars($pageTitle); ?></h1>
                    <p>Assigned design work</p>
                </div>
                <div class="user-menu">
                    <div class="date-display">
                        <i class="far fa-calendar-alt mr-2"></i>
                        <?php echo date('l, F j, Y'); ?>
                    </div>

                    <div class="user-profile" id="graphicUserProfile">
                        <div class="avatar" style="background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);">
                            <?php echo htmlspecialchars(strtoupper(mb_substr($displayName, 0, 1))); ?>
                        </div>
                        <div>
                            <div style="font-weight: 600;"><?php echo htmlspecialchars($displayName); ?></div>
                            <div style="font-size: 0.75rem; color: #64748b;"><?php echo htmlspecialchars(get_role_label($user['role'] ?? 'user')); ?></div>
                        </div>
                        <i class="fas fa-chevron-down ml-2" style="color: #64748b;"></i>

                        <div class="profile-dropdown" id="graphicHeaderProfileDropdown">
                            <a href="profile.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"><i class="fas fa-user mr-2"></i> My Profile</a>
                            <a href="settings.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"><i class="fas fa-cog mr-2"></i> Settings</a>
                            <a href="?logout" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"><i class="fas fa-sign-out-alt mr-2"></i> Logout</a>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Flash Messages -->
            <?php foreach ($flashes as $type => $msgs): ?>
                <?php foreach ($msgs as $msg): ?>
                    <?php $isError = ($type === 'error'); ?>
                    <div class="mb-4 p-4 rounded-lg border <?php echo $isError ? 'bg-red-100 text-red-800 border-red-300' : 'bg-green-100 text-green-800 border-green-300'; ?>" data-flash-type="<?php echo htmlspecialchars($type); ?>">
                        <?php echo htmlspecialchars($msg); ?>
                    </div>
                <?php endforeach; ?>
            <?php endforeach; ?>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mb-6">
                <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-2xl p-5 text-white shadow-lg hover:shadow-xl transition-shadow duration-300">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-sm font-medium text-blue-100">New Assignments</p>
                            <h3 class="text-2xl font-bold mt-1"><?php echo number_format((int)$assignedCount); ?></h3>
                            <div class="mt-2"><span class="text-xs bg-white bg-opacity-20 px-2 py-1 rounded-full">New</span></div>
                        </div>
                        <div class="p-3 bg-white bg-opacity-20 rounded-xl"><i class="fas fa-inbox text-xl"></i></div>
                    </div>
                    <div class="mt-4 pt-3 border-t border-blue-400 border-opacity-30">
                        <a href="#" class="text-xs font-medium text-white hover:underline flex items-center" onclick="document.getElementById('graphic-active-tasks')?.scrollIntoView({behavior:'smooth'}); return false;">
                            View tasks <i class="fas fa-arrow-right ml-1 text-xs"></i>
                        </a>
                    </div>
                </div>

                <div class="bg-gradient-to-br from-amber-500 to-amber-600 rounded-2xl p-5 text-white shadow-lg hover:shadow-xl transition-shadow duration-300">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-sm font-medium text-amber-100">In Progress</p>
                            <h3 class="text-2xl font-bold mt-1"><?php echo number_format((int)$inProgressCount); ?></h3>
                            <div class="mt-2"><span class="text-xs bg-white bg-opacity-20 px-2 py-1 rounded-full">Working</span></div>
                        </div>
                        <div class="p-3 bg-white bg-opacity-20 rounded-xl"><i class="fas fa-paint-brush text-xl"></i></div>
                    </div>
                    <div class="mt-4 pt-3 border-t border-amber-400 border-opacity-30">
                        <a href="#" class="text-xs font-medium text-white hover:underline flex items-center" onclick="document.getElementById('graphic-active-tasks')?.scrollIntoView({behavior:'smooth'}); return false;">
                            View tasks <i class="fas fa-arrow-right ml-1 text-xs"></i>
                        </a>
                    </div>
                </div>

                <div class="bg-gradient-to-br from-emerald-500 to-emerald-600 rounded-2xl p-5 text-white shadow-lg hover:shadow-xl transition-shadow duration-300">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-sm font-medium text-emerald-100">Completed</p>
                            <h3 class="text-2xl font-bold mt-1"><?php echo number_format((int)$completedCount); ?></h3>
                            <div class="mt-2"><span class="text-xs bg-white bg-opacity-20 px-2 py-1 rounded-full">Done</span></div>
                        </div>
                        <div class="p-3 bg-white bg-opacity-20 rounded-xl"><i class="fas fa-check-circle text-xl"></i></div>
                    </div>
                    <div class="mt-4 pt-3 border-t border-emerald-400 border-opacity-30">
                        <a href="#" class="text-xs font-medium text-white hover:underline flex items-center" onclick="document.getElementById('graphic-completed-tasks')?.scrollIntoView({behavior:'smooth'}); return false;">
                            View completed <i class="fas fa-arrow-right ml-1 text-xs"></i>
                        </a>
                    </div>
                </div>

                <div class="bg-gradient-to-br from-violet-500 to-violet-600 rounded-2xl p-5 text-white shadow-lg hover:shadow-xl transition-shadow duration-300">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-sm font-medium text-violet-100">Total Tasks</p>
                            <h3 class="text-2xl font-bold mt-1"><?php echo number_format((int)$totalTasks); ?></h3>
                            <div class="mt-2"><span class="text-xs bg-white bg-opacity-20 px-2 py-1 rounded-full">All time</span></div>
                        </div>
                        <div class="p-3 bg-white bg-opacity-20 rounded-xl"><i class="fas fa-chart-bar text-xl"></i></div>
                    </div>
                    <div class="mt-4 pt-3 border-t border-violet-400 border-opacity-30">
                        <a href="#" class="text-xs font-medium text-white hover:underline flex items-center" onclick="document.getElementById('graphic-active-tasks')?.scrollIntoView({behavior:'smooth'}); return false;">
                            View tasks <i class="fas fa-arrow-right ml-1 text-xs"></i>
                        </a>
                    </div>
                </div>
            </div>

            <?php if (in_array($roleNorm, ['graphic', 'graphics', 'graphic designer'], true)): ?>
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6 mb-6 flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-4 min-w-0">
                    <div class="w-14 h-14 rounded-2xl bg-blue-100 text-blue-600 flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-user text-xl"></i>
                    </div>
                    <div class="min-w-0">
                        <h2 class="text-lg font-bold text-gray-900 truncate"><?php echo htmlspecialchars((string)($user['name'] ?? 'Graphic User')); ?></h2>
                        <p class="text-sm text-gray-500 mt-2 break-all"><?php echo htmlspecialchars((string)($user['email'] ?? '')); ?> · Graphic Team</p>
                    </div>
                </div>
                <div class="bg-blue-50 rounded-2xl px-7 py-4 text-center flex-shrink-0 sm:min-w-36">
                    <div class="text-2xl font-extrabold text-blue-700"><?php echo number_format($graphicAllTimeEvents); ?></div>
                    <div class="text-xs font-semibold text-blue-700 mt-1">All-Time Events</div>
                </div>
            </div>
            <?php endif; ?>

            <div class="content-section">
                <div class="section-header">
                    <h3 class="section-title">
                        <i class="fas fa-print text-indigo-600"></i>
                        Printing Items
                    </h3>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
                    <div class="bg-slate-50 border border-slate-200 rounded-xl p-4">
                        <div class="text-sm text-slate-600">Received</div>
                        <div class="text-2xl font-bold text-slate-900"><?php echo number_format((int)$receivedPrintCount); ?></div>
                    </div>
                    <div class="bg-slate-50 border border-slate-200 rounded-xl p-4">
                        <div class="text-sm text-slate-600">In Progress</div>
                        <div class="text-2xl font-bold text-slate-900"><?php echo number_format((int)$inProgressPrintCount); ?></div>
                    </div>
                    <div class="bg-slate-50 border border-slate-200 rounded-xl p-4">
                        <div class="text-sm text-slate-600">Printed</div>
                        <div class="text-2xl font-bold text-slate-900"><?php echo number_format((int)$printedPrintCount); ?></div>
                    </div>
                    <div class="bg-slate-50 border border-slate-200 rounded-xl p-4">
                        <div class="text-sm text-slate-600">Delivered</div>
                        <div class="text-2xl font-bold text-slate-900"><?php echo number_format((int)$deliveredPrintCount); ?></div>
                    </div>
                </div>

                <div class="overflow-x-auto">
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
                        <?php $pn = 1; foreach ($printTasks as $pt): ?>
                            <tr>
                                <td><?php echo (int)$pn; ?></td>
                                <td><?php echo htmlspecialchars((string)($pt['event_name'] ?? '')); ?></td>
                                <td><?php echo htmlspecialchars((string)($pt['title'] ?? '')); ?></td>
                                <td><?php echo htmlspecialchars((string)($pt['qty'] ?? '')); ?></td>
                                <td><?php echo htmlspecialchars((string)($pt['status'] ?? '')); ?></td>
                            </tr>
                        <?php $pn++; endforeach; ?>
                        <?php if (!$printTasks): ?>
                            <tr><td colspan="5" style="text-align:center;color:#64748b;padding:2rem;">No print items found</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php 
            $activeTasks = array_filter($myTasks, fn($r) => in_array($r['status'], ['assigned', 'in_progress']));
            $completedTasks = array_filter($myTasks, fn($r) => $r['status'] === 'completed');
            ?>

            <!-- Active Tasks Section -->
            <div class="content-section">
                <div class="section-header">
                    <h3 class="section-title">
                        <i class="fas fa-tasks text-purple-600"></i>
                        Active Tasks
                        <?php if (!empty($activeTasks)): ?>
                        <span style="background: #ede9fe; color: #7c3aed; padding: 0.15rem 0.5rem; border-radius: 10px; font-size: 0.75rem; margin-left: 0.5rem;">
                            <?php echo count($activeTasks); ?>
                        </span>
                        <?php endif; ?>
                    </h3>
                </div>
                
                <?php if (!empty($activeTasks)): ?>
                <div class="space-y-3">
                    <?php foreach ($activeTasks as $task): ?>
                    <div class="task-card <?php echo $task['status']; ?>">
                        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                            <!-- Task Info -->
                            <div class="flex-1">
                                <div class="flex items-center gap-2 mb-2">
                                    <h4 style="font-weight: 600; color: #1e293b; font-size: 1rem;"><?php echo htmlspecialchars($task['event_name']); ?></h4>
                                    <span class="status-badge status-<?php echo $task['status']; ?>">
                                        <?php echo $task['status'] === 'assigned' ? 'New' : 'In Progress'; ?>
                                    </span>
                                </div>
                                
                                <div class="flex flex-wrap gap-4 text-sm text-gray-600">
                                    <span><i class="fas fa-user mr-1 text-gray-400"></i> <?php echo htmlspecialchars($task['client'] ?? 'N/A'); ?></span>
                                    <span><i class="fas fa-calendar mr-1 text-gray-400"></i> 
                                        <?php 
                                        if ($task['event_date']) {
                                            echo date('M j, Y', strtotime($task['event_date']));
                                        } else {
                                            echo 'TBD';
                                        }
                                        ?>
                                    </span>
                                    <span><i class="fas fa-map-marker-alt mr-1 text-gray-400"></i> <?php echo htmlspecialchars($task['location'] ?? 'N/A'); ?></span>
                                    <?php if ($task['coordinator']): ?>
                                    <span><i class="fas fa-phone mr-1 text-gray-400"></i> <?php echo htmlspecialchars($task['coordinator']); ?></span>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if ($task['remarks']): ?>
                                <div class="mt-2 text-sm text-gray-500">
                                    <i class="fas fa-sticky-note mr-1"></i> <?php echo htmlspecialchars($task['remarks']); ?>
                                </div>
                                <?php endif; ?>
                                
                                <!-- File Downloads -->
                                <?php if (!empty($task['checklist_file']) || !empty($task['mockup_file'])): ?>
                                <div class="flex gap-2 mt-3">
                                    <?php if (!empty($task['checklist_file'])): ?>
                                    <a href="<?php echo htmlspecialchars($task['checklist_file']); ?>" target="_blank" 
                                       class="inline-flex items-center gap-1 px-2 py-1 bg-blue-50 text-blue-700 rounded text-xs hover:bg-blue-100">
                                        <i class="fas fa-file-alt"></i> Checklist
                                    </a>
                                    <?php endif; ?>
                                    <?php if (!empty($task['mockup_file'])): ?>
                                    <a href="<?php echo htmlspecialchars($task['mockup_file']); ?>" target="_blank"
                                       class="inline-flex items-center gap-1 px-2 py-1 bg-purple-50 text-purple-700 rounded text-xs hover:bg-purple-100">
                                        <i class="fas fa-image"></i> Mockup
                                    </a>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Action Buttons -->
                            <div class="flex flex-col items-end gap-2 flex-shrink-0">
                                <?php if ($task['status'] === 'assigned'): ?>
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                    <input type="hidden" name="action" value="start_work">
                                    <input type="hidden" name="request_id" value="<?php echo $task['id']; ?>">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-play"></i> Start
                                    </button>
                                </form>
                                <?php elseif ($task['status'] === 'in_progress'): ?>
                                <form method="POST" enctype="multipart/form-data" class="gd-complete-form">
                                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                    <input type="hidden" name="action" value="mark_done">
                                    <input type="hidden" name="request_id" value="<?php echo $task['id']; ?>">
                                    <div class="flex flex-col gap-2 items-stretch">
                                        <label class="text-xs text-gray-500">Upload final mockup (PDF / PNG / JPG)</label>
                                        <input type="file" name="mockup_file" accept="application/pdf,image/png,image/jpeg" class="text-xs" />
                                        <button type="button" class="btn btn-success js-gd-confirm-done">
                                            <i class="fas fa-check"></i> Done
                                        </button>
                                    </div>
                                </form>
                                <?php else: ?>
                                <span class="text-sm text-gray-500 inline-flex items-center gap-1">
                                    <i class="fas fa-check-circle text-green-600"></i>
                                    Completed
                                </span>
                                <?php endif; ?>
                                <a href="./graphic_tasks.php?event_id=<?php echo (int)($task['event_id'] ?? 0); ?>" class="text-purple-600 hover:text-purple-800 text-sm font-medium inline-flex items-center">
                                    <i class="fas fa-eye mr-1"></i> View
                                </a>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="text-center py-8">
                    <div style="width: 60px; height: 60px; background: #f1f5f9; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1rem;">
                        <i class="fas fa-inbox text-gray-400 text-xl"></i>
                    </div>
                    <h4 style="font-weight: 600; color: #1e293b; margin-bottom: 0.25rem;">No Active Tasks</h4>
                    <p style="color: #64748b; font-size: 0.875rem;">You don't have any tasks assigned at the moment.</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Completed Tasks Section -->
            <?php if (!empty($completedTasks)): ?>
            <div class="content-section" id="graphic-completed-tasks">
                <div class="section-header">
                    <h3 class="section-title">
                        <i class="fas fa-check-circle text-green-600"></i>
                        Completed Tasks
                        <span style="background: #dcfce7; color: #166534; padding: 0.15rem 0.5rem; border-radius: 10px; font-size: 0.75rem; margin-left: 0.5rem;">
                            <?php echo count($completedTasks); ?>
                        </span>
                    </h3>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Event</th>
                                <th>Client</th>
                                <th>Location</th>
                                <th>Event Date</th>
                                <th>Completed</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($completedTasks as $task): ?>
                            <tr>
                                <td style="font-weight: 500;"><?php echo htmlspecialchars($task['event_name']); ?></td>
                                <td><?php echo htmlspecialchars($task['client'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($task['location'] ?? '-'); ?></td>
                                <td><?php echo $task['event_date'] ? date('M j, Y', strtotime($task['event_date'])) : '-'; ?></td>
                                <td><?php echo $task['completed_at'] ? date('M j, Y', strtotime($task['completed_at'])) : '-'; ?></td>
                                <td>
                                    <a href="./graphic_tasks.php?event_id=<?php echo (int)($task['event_id'] ?? 0); ?>" class="text-purple-600 hover:text-purple-800 text-sm font-medium">
                                        <i class="fas fa-eye mr-1"></i> View
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div style="display:flex; justify-content:flex-end; margin-top:0.75rem;">
                    <a href="./graphic_tasks.php?status=completed" class="text-purple-600 hover:text-purple-800 text-sm font-medium inline-flex items-center" style="gap:0.35rem; text-decoration:none;">
                        View all
                        <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
            </div>
            <?php endif; ?>
            </div> <!-- /min-h-screen wrapper -->
        </main>
    </div>
    
    <!-- Confirmation modal for marking tasks done (Graphic dashboard) -->
    <div id="gdConfirmModal" class="fixed inset-0 z-40 hidden items-center justify-center bg-black bg-opacity-40">
        <div class="bg-white rounded-xl shadow-2xl max-w-md w-full mx-4 p-6">
            <div class="flex items-start justify-between mb-4">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900">Mark task as completed?</h3>
                    <p class="mt-1 text-sm text-gray-600">
                        This will mark the design work as done and upload the selected mockup file (if any) for review.
                    </p>
                </div>
                <button type="button" id="gdConfirmClose" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="flex justify-end gap-3 mt-6">
                <button type="button" id="gdConfirmCancel" class="px-4 py-2 rounded-lg border border-gray-300 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Cancel
                </button>
                <button type="button" id="gdConfirmOk" class="px-4 py-2 rounded-lg bg-emerald-600 text-sm font-semibold text-white hover:bg-emerald-700">
                    Yes, mark as done
                </button>
            </div>
        </div>
    </div>
    
    <script>
        // Graphic header user profile dropdown (top-right)
        document.addEventListener('DOMContentLoaded', function () {
            var profile  = document.getElementById('graphicUserProfile');
            var dropdown = document.getElementById('graphicHeaderProfileDropdown');

            if (profile && dropdown && !window.__graphicDashboardProfileDropdownBound) {
                window.__graphicDashboardProfileDropdownBound = true;
                function closeProfileDropdown() {
                    dropdown.classList.remove('show');
                }

                profile.addEventListener('click', function (e) {
                    e.stopPropagation();
                    dropdown.classList.toggle('show');
                });

                // Close when clicking anywhere else
                document.addEventListener('click', function (event) {
                    if (!profile.contains(event.target) && !dropdown.contains(event.target)) {
                        closeProfileDropdown();
                    }
                });

                // Close on Escape key
                document.addEventListener('keydown', function (e) {
                    if (e.key === 'Escape') {
                        closeProfileDropdown();
                    }
                });
            }

            // Auto-dismiss success flash messages after ~2 seconds
            setTimeout(function () {
                document.querySelectorAll('[data-flash-type="success"]').forEach(function (el) {
                    el.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
                    el.style.opacity = '0';
                    el.style.transform = 'translateY(-4px)';
                    setTimeout(function () {
                        if (el && el.parentNode) {
                            el.parentNode.removeChild(el);
                        }
                    }, 400);
                });
            }, 2000);

            // Advanced confirmation modal for marking tasks done
            var gdModal = document.getElementById('gdConfirmModal');
            var gdOk = document.getElementById('gdConfirmOk');
            var gdCancel = document.getElementById('gdConfirmCancel');
            var gdClose = document.getElementById('gdConfirmClose');
            var pendingForm = null;

            function openGdConfirm(form) {
                pendingForm = form;
                gdModal.classList.remove('hidden');
                gdModal.classList.add('flex');
            }

            function closeGdConfirm() {
                gdModal.classList.add('hidden');
                gdModal.classList.remove('flex');
                pendingForm = null;
            }

            document.querySelectorAll('.js-gd-confirm-done').forEach(function(btn) {
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    var form = btn.closest('form');
                    if (!form) return;
                    openGdConfirm(form);
                });
            });

            if (gdOk) {
                gdOk.addEventListener('click', function () {
                    if (pendingForm) {
                        pendingForm.submit();
                    }
                    closeGdConfirm();
                });
            }

            [gdCancel, gdClose].forEach(function(el) {
                if (!el) return;
                el.addEventListener('click', function () {
                    closeGdConfirm();
                });
            });

            if (gdModal) {
                gdModal.addEventListener('click', function (e) {
                    if (e.target === gdModal) {
                        closeGdConfirm();
                    }
                });
            }
        });
    </script>
</body>
</html>
