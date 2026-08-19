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



// Only super/admin can access this dashboard

if (!in_array($user['role'] ?? '', ['super', 'admin'], true)) {

    header('Location: index.php');

    exit;

}



// Handle graphic assignment

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf_or_abort();

    $action = $_POST['action'] ?? '';

    

    if ($action === 'assign_graphic') {

        $requestId = (int)($_POST['request_id'] ?? 0);

        $graphicId = (int)($_POST['graphic_id'] ?? 0);

        

        if ($requestId > 0 && $graphicId > 0) {

            $stmt = $db->prepare("UPDATE graphic_requests SET assigned_to = ?, assigned_by = ?, assigned_at = NOW(), status = 'assigned' WHERE id = ?");

            $stmt->execute([$graphicId, $user['id'], $requestId]);

            flash_add('success', 'Graphic designer assigned successfully');

        }

        header('Location: graphic_head_dashboard.php');

        exit;

    }

    

    if ($action === 'reassign_graphic') {

        $requestId = (int)($_POST['request_id'] ?? 0);

        $graphicId = (int)($_POST['graphic_id'] ?? 0);

        

        if ($requestId > 0 && $graphicId > 0) {

            $stmt = $db->prepare("UPDATE graphic_requests SET assigned_to = ?, assigned_by = ?, assigned_at = NOW() WHERE id = ?");

            $stmt->execute([$graphicId, $user['id'], $requestId]);

            flash_add('success', 'Graphic designer reassigned successfully');

        }

        header('Location: graphic_head_dashboard.php');

        exit;

    }

    

    if ($action === 'mark_completed') {

        $requestId = (int)($_POST['request_id'] ?? 0);

        if ($requestId > 0) {

            $stmt = $db->prepare("UPDATE graphic_requests SET status = 'completed', completed_at = NOW() WHERE id = ?");

            $stmt->execute([$requestId]);

            flash_add('success', 'Request marked as completed');

        }

        header('Location: graphic_head_dashboard.php');

        exit;

    }



    // Approve designer mockup: copy request mockup into event so it appears in Events/details

    if ($action === 'approve_mockup') {

        $requestId = (int)($_POST['request_id'] ?? 0);

        $feedback  = trim($_POST['feedback'] ?? '');



        if ($requestId > 0) {

            $stmt = $db->prepare("SELECT event_id, mockup_file, notes FROM graphic_requests WHERE id = ?");

            $stmt->execute([$requestId]);

            $req = $stmt->fetch(PDO::FETCH_ASSOC);



            if ($req && !empty($req['mockup_file']) && !empty($req['event_id'])) {

                // Publish mockup to event so it is visible on events.php and other dashboards

                $updateEvent = $db->prepare("UPDATE events SET mockup_file = ? WHERE id = ?");

                $updateEvent->execute([$req['mockup_file'], $req['event_id']]);

                // Create a print task for Production when mockup is approved (if not already present)
                try {
                    $chk = $db->prepare("SELECT id FROM print_tasks WHERE event_id = ? AND status <> 'delivered' ORDER BY id DESC LIMIT 1");
                    $chk->execute([(int)$req['event_id']]);
                    $existingTaskId = (int)($chk->fetchColumn() ?? 0);
                    if ($existingTaskId <= 0) {
                        $ins = $db->prepare("INSERT INTO print_tasks (event_id, task_type, source, title, qty, status, notes, created_by) VALUES (?, ?, 'graphic', ?, NULL, 'received', NULL, ?)");
                        $ins->execute([(int)$req['event_id'], 'mockup', 'Mockup Approved - Printing', (int)($user['id'] ?? 0)]);
                    }
                } catch (Throwable $e) {
                    // ignore print task creation failures
                }



                // Store simple approval note back on the request

                if ($feedback !== '') {

                    $newNotes = trim(($req['notes'] ?? '') . "\n[APPROVED] " . $feedback);

                    $updateReq = $db->prepare("UPDATE graphic_requests SET notes = ? WHERE id = ?");

                    $updateReq->execute([$newNotes, $requestId]);

                }



                flash_add('success', 'Mockup approved and published to event.');

            } else {

                flash_add('error', 'Cannot approve mockup: no uploaded mockup found for this request.');

            }

        }



        header('Location: graphic_head_dashboard.php');

        exit;

    }



    // Reject designer mockup: keep request active, clear its mockup file, and record reason

    if ($action === 'reject_mockup') {

        $requestId = (int)($_POST['request_id'] ?? 0);

        $reason    = trim($_POST['reason'] ?? '');



        if ($requestId > 0 && $reason !== '') {

            $stmt = $db->prepare("SELECT notes FROM graphic_requests WHERE id = ?");

            $stmt->execute([$requestId]);

            $req = $stmt->fetch(PDO::FETCH_ASSOC);



            $newNotes = trim(($req['notes'] ?? '') . "\n[REJECTED] " . $reason);



            // Clear the uploaded mockup and push status back to in_progress for designer to revise

            $updateReq = $db->prepare("UPDATE graphic_requests SET mockup_file = NULL, notes = ?, status = 'in_progress' WHERE id = ?");

            $updateReq->execute([$newNotes, $requestId]);



            flash_add('success', 'Mockup rejected and sent back to designer with feedback.');

        } else {

            flash_add('error', 'Rejection requires a reason.');

        }



        header('Location: graphic_head_dashboard.php');

        exit;

    }

}



// Get all graphic requests

$requestsStmt = $db->query("

    SELECT gr.*, e.name as event_name, e.date as event_date, e.client, e.location,

           e.checklist_file, e.mockup_file AS event_mockup_file,

           u1.name as requested_by_name, u2.name as assigned_to_name

    FROM graphic_requests gr

    JOIN events e ON gr.event_id = e.id

    LEFT JOIN users u1 ON gr.requested_by = u1.id

    LEFT JOIN users u2 ON gr.assigned_to = u2.id

    ORDER BY 

        CASE gr.status 

            WHEN 'pending' THEN 1 

            WHEN 'assigned' THEN 2 

            WHEN 'in_progress' THEN 3 

            WHEN 'completed' THEN 4 

            WHEN 'cancelled' THEN 5 

        END,

        gr.created_at DESC

");

$graphicRequests = $requestsStmt->fetchAll(PDO::FETCH_ASSOC);



// Get available graphic designers

$graphicsStmt = $db->query("SELECT id, name, email FROM users WHERE role LIKE 'graphic%' ORDER BY name");

$graphicDesigners = $graphicsStmt->fetchAll(PDO::FETCH_ASSOC);



// Stats

$pendingCount = count(array_filter($graphicRequests, fn($r) => $r['status'] === 'pending'));

$assignedCount = count(array_filter($graphicRequests, fn($r) => $r['status'] === 'assigned'));

$inProgressCount = count(array_filter($graphicRequests, fn($r) => $r['status'] === 'in_progress'));



// Requests where designer has uploaded a mockup but it is not yet published on the event

$awaitingApprovalRequests = array_values(array_filter($graphicRequests, function ($r) {

    return !empty($r['mockup_file']) && empty($r['event_mockup_file']);

}));



// Completed count should only include fully approved work (or work without mockup requirement)

$completedGraphicRequests = array_values(array_filter($graphicRequests, function ($r) {

    if ($r['status'] !== 'completed') {

        return false;

    }

    // If there is a designer mockup, treat as completed only when also published on the event

    if (!empty($r['mockup_file'])) {

        return !empty($r['event_mockup_file']);

    }

    // No mockup uploaded: treat as standard completed

    return true;

}));

$completedCount = count($completedGraphicRequests);



// Active list: non-completed requests that are not currently waiting for mockup approval

$activeGraphicRequests = array_values(array_filter($graphicRequests, function ($r) {

    if (!empty($r['mockup_file']) && empty($r['event_mockup_file'])) {

        return false; // handled by awaiting-approval table

    }

    return $r['status'] !== 'completed';

}));



$flashes = flash_consume();

$displayName = $user['name'] ?? $user['email'] ?? 'User';

$pageTitle = 'Graphics Management';

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

        .card { background: #fff; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }

        .status-badge { padding: 0.25rem 0.75rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; }

        .status-pending { background: #fef3c7; color: #92400e; }

        .status-assigned { background: #dbeafe; color: #1e40af; }

        .status-in_progress { background: #d1fae5; color: #065f46; }

        .status-completed { background: #e0e7ff; color: #3730a3; }

        .status-cancelled { background: #fee2e2; color: #991b1b; }



        /* Simple neutral card style for all graphic requests */

        .request-card {

            border-left: 4px solid #e5e7eb; /* light gray */

        }

        .btn { display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.5rem 1rem; border-radius: 8px; font-weight: 500; cursor: pointer; transition: all 0.2s; border: none; }

        .btn-primary { background: #ec4899; color: #fff; }

        .btn-primary:hover { background: #db2777; }

        .btn-success { background: #10b981; color: #fff; }

        .btn-secondary { background: #64748b; color: #fff; }



        /* Stat Cards - same style as Graphic Dashboard */

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

        .stat-blue { background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%); }

        .stat-orange { background: linear-gradient(135deg, #f97316 0%, #ea580c 100%); }

        .stat-green { background: linear-gradient(135deg, #10b981 0%, #059669 100%); }

        .stat-purple { background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%); }



        .stats-grid {

            display: grid;

            grid-template-columns: repeat(4, 1fr);

            gap: 1rem;

            margin-bottom: 1.5rem;

        }



        /* Content Section - same as Graphic Dashboard */

        .content-section {

            background: #ffffff;

            border-radius: 12px;

            padding: 1.25rem;

            box-shadow: 0 1px 3px rgba(0,0,0,0.08);

            margin-bottom: 1.5rem;

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

    </style>

</head>

<body>

    <div class="dashboard-container">

        <!-- Sidebar -->

        <?php $activePage = 'graphic_head_dashboard.php'; include __DIR__ . '/sidebar.php'; ?>



        <!-- Main Content -->

        <main class="main-content">

            <!-- Top Bar -->

            <div class="top-bar">

                <div class="page-title">

                    <h1><?php echo $pageTitle; ?></h1>

                    <p>Your tasks and work overview</p>

                </div>

                

                <div class="user-menu">

                    <div class="date-display">

                        <i class="far fa-calendar-alt mr-2"></i>

                        <?php echo date('l, F j, Y'); ?>

                    </div>

                    

                    <div class="user-profile" id="headGraphicUserProfile">

                        <div class="avatar" style="background: #ec4899;"><?php echo strtoupper(substr($displayName, 0, 1)); ?></div>

                        <div>

                            <div style="font-weight: 600;"><?php echo htmlspecialchars($displayName); ?></div>

                            <div style="font-size: 0.75rem; color: #64748b;"><?php echo get_role_label($user['role'] ?? 'user'); ?></div>

                        </div>

                        <i class="fas fa-chevron-down ml-2" style="color: #64748b;"></i>

                        

                        <div class="profile-dropdown" id="headGraphicHeaderProfileDropdown">

                            <a href="profile.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"><i class="fas fa-user mr-2"></i> My Profile</a>

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



            <!-- Stats Cards - colorful like Graphic Dashboard -->

            <div class="stats-grid">

                <div class="stat-card stat-orange">

                    <div class="stat-label">Pending</div>

                    <div class="stat-value"><?php echo $pendingCount; ?></div>

                    <div class="stat-badge"><?php echo $pendingCount; ?> pending</div>

                </div>



                <div class="stat-card stat-blue">

                    <div class="stat-label">Assigned</div>

                    <div class="stat-value"><?php echo $assignedCount; ?></div>

                    <div class="stat-badge"><?php echo $assignedCount; ?> assigned</div>

                </div>



                <div class="stat-card stat-green">

                    <div class="stat-label">In Progress</div>

                    <div class="stat-value"><?php echo $inProgressCount; ?></div>

                    <div class="stat-badge"><?php echo $inProgressCount; ?> active</div>

                </div>



                <div class="stat-card stat-purple">

                    <div class="stat-label">Completed</div>

                    <div class="stat-value"><?php echo $completedCount; ?></div>

                    <div class="stat-badge"><?php echo $completedCount; ?> done</div>

                </div>

            </div>



                <!-- Available Graphic Designers -->

                <div class="content-section">

                    <div class="section-header">

                        <h3 class="section-title"><i class="fas fa-users text-pink-600"></i>Available Graphic Designers</h3>

                    </div>

                    <?php if (empty($graphicDesigners)): ?>

                        <p class="text-gray-500">No graphic designers registered in the system.</p>

                    <?php else: ?>

                        <div class="flex flex-wrap gap-3">

                            <?php foreach ($graphicDesigners as $designer): ?>

                                <?php

                                // Count active assignments for this designer

                                $activeCount = count(array_filter($graphicRequests, fn($r) => $r['assigned_to'] == $designer['id'] && in_array($r['status'], ['assigned', 'in_progress'])));

                                ?>

                                <div class="flex items-center gap-2 px-4 py-2 bg-gray-50 rounded-lg border">

                                    <div class="w-8 h-8 rounded-full bg-pink-100 flex items-center justify-center text-pink-600 font-semibold text-sm">

                                        <?php echo strtoupper(substr($designer['name'], 0, 1)); ?>

                                    </div>

                                    <div>

                                        <p class="font-medium text-gray-800"><?php echo htmlspecialchars($designer['name']); ?></p>

                                        <p class="text-xs text-gray-500"><?php echo $activeCount; ?> active task(s)</p>

                                    </div>

                                </div>

                            <?php endforeach; ?>

                        </div>

                    <?php endif; ?>

                </div>



                <!-- Active Graphic Requests -->

                <div class="content-section">

                    <div class="section-header">

                        <h3 class="section-title"><i class="fas fa-tasks text-pink-600"></i>Graphic Requests</h3>

                    </div>

                    

                    <?php if (empty($activeGraphicRequests)): ?>

                        <p class="text-gray-500 text-center py-8">No active graphic requests.</p>

                    <?php else: ?>

                        <div class="overflow-x-auto">

                            <table class="w-full">

                                <thead>

                                    <tr class="border-b border-gray-200">

                                        <th class="text-left py-3 px-4 text-gray-600 font-medium">#</th>

                                        <th class="text-left py-3 px-4 text-gray-600 font-medium">Event</th>

                                        <th class="text-left py-3 px-4 text-gray-600 font-medium">Client</th>

                                        <th class="text-left py-3 px-4 text-gray-600 font-medium">Date</th>

                                        <th class="text-left py-3 px-4 text-gray-600 font-medium">Location</th>

                                        <th class="text-left py-3 px-4 text-gray-600 font-medium">Requested By</th>

                                        <th class="text-left py-3 px-4 text-gray-600 font-medium">Assigned To</th>

                                        <th class="text-left py-3 px-4 text-gray-600 font-medium">Status</th>

                                        <th class="text-left py-3 px-4 text-gray-600 font-medium">Actions</th>

                                    </tr>

                                </thead>

                                <tbody>

                                    <?php $row = 1; foreach ($activeGraphicRequests as $req): ?>

                                        <tr class="border-b border-gray-100 hover:bg-gray-50 align-top">

                                            <td class="py-3 px-4 text-gray-500 text-sm"><?php echo $row++; ?></td>

                                                <td class="py-3 px-4 font-medium"><?php echo htmlspecialchars($req['event_name']); ?></td>

                                                <td class="py-3 px-4"><?php echo htmlspecialchars($req['client'] ?? 'N/A'); ?></td>

                                            <td class="py-3 px-4"><?php echo $req['event_date'] ? date('M j, Y', strtotime($req['event_date'])) : 'N/A'; ?></td>

                                            <td class="py-3 px-4"><?php echo htmlspecialchars($req['location'] ?? 'N/A'); ?></td>

                                            <td class="py-3 px-4"><?php echo htmlspecialchars($req['requested_by_name']); ?></td>

                                            <td class="py-3 px-4"><?php echo htmlspecialchars($req['assigned_to_name'] ?? 'Not assigned'); ?></td>

                                                <td class="py-3 px-4">

                                                <span class="status-badge status-<?php echo $req['status']; ?>">

                                                    <?php echo ucfirst(str_replace('_', ' ', $req['status'])); ?>

                                                </span>

                                                <?php if ($req['notes']): ?>

                                                    <div class="mt-1 text-xs text-gray-500">

                                                        <i class="fas fa-sticky-note mr-1"></i><?php echo htmlspecialchars($req['notes']); ?>

                                                    </div>

                                                <?php endif; ?>

                                            </td>

                                            <td class="py-3 px-4">

                                                <?php if ($req['status'] === 'pending'): ?>

                                                    <!-- Assign Form -->

                                                    <form method="POST" class="flex flex-col gap-2 mb-2">

                                                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">

                                                        <input type="hidden" name="action" value="assign_graphic">

                                                        <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>">

                                                        <select name="graphic_id" required class="px-3 py-2 border border-gray-300 rounded-lg text-sm">

                                                            <option value="">Select Designer</option>

                                                            <?php foreach ($graphicDesigners as $designer): ?>

                                                                <option value="<?php echo $designer['id']; ?>"><?php echo htmlspecialchars($designer['name']); ?></option>

                                                            <?php endforeach; ?>

                                                        </select>

                                                        <button type="submit" class="btn btn-primary text-sm">

                                                            <i class="fas fa-user-plus"></i> Assign

                                                        </button>

                                                    </form>

                                                <?php elseif (in_array($req['status'], ['assigned', 'in_progress']) || ($req['status'] === 'completed' && !empty($req['mockup_file']) && empty($req['event_mockup_file']))): ?>

                                                    <div class="text-sm text-gray-600 mb-2">

                                                        <i class="fas fa-user-check mr-1"></i> Assigned to: <strong><?php echo htmlspecialchars($req['assigned_to_name']); ?></strong>

                                                    </div>

                                                    <!-- Reassign Form -->

                                                    <form method="POST" class="flex flex-col gap-2 mb-2">

                                                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">

                                                        <input type="hidden" name="action" value="reassign_graphic">

                                                        <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>">

                                                        <select name="graphic_id" required class="px-3 py-2 border border-gray-300 rounded-lg text-sm">

                                                            <option value="">Reassign to...</option>

                                                            <?php foreach ($graphicDesigners as $designer): ?>

                                                                <option value="<?php echo $designer['id']; ?>" <?php echo $designer['id'] == $req['assigned_to'] ? 'selected' : ''; ?>>

                                                                    <?php echo htmlspecialchars($designer['name']); ?>

                                                                </option>

                                                            <?php endforeach; ?>

                                                        </select>

                                                        <button type="submit" class="btn btn-secondary text-sm">

                                                            <i class="fas fa-exchange-alt"></i> Reassign

                                                        </button>

                                                    </form>

                                                    <?php if (empty($req['mockup_file'])): ?>

                                                    <!-- No mockup uploaded: Head can directly mark request completed (with modal confirm) -->

                                                    <form method="POST">

                                                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">

                                                        <input type="hidden" name="action" value="mark_completed">

                                                        <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>">

                                                        <button type="button" class="btn btn-success text-sm w-full js-confirm-complete-request">

                                                            <i class="fas fa-check"></i> Mark Completed

                                                        </button>

                                                    </form>

                                                    <?php else: ?>

                                                    <!-- Mockup approval controls: task cannot be completed until approved/rejected -->

                                                    <div class="mt-3 space-y-2">

                                                        <form method="POST" class="flex flex-col gap-2">

                                                            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">

                                                            <input type="hidden" name="action" value="approve_mockup">

                                                            <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>">

                                                            <input type="text" name="feedback" placeholder="Approval note (optional)" class="px-3 py-1 border border-gray-300 rounded text-xs" />

                                                            <button type="button" class="btn btn-success text-xs js-confirm-approve-mockup">

                                                                <i class="fas fa-check-circle"></i> Approve Mockup

                                                            </button>

                                                        </form>

                                                        <form method="POST" class="flex flex-col gap-2">

                                                            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">

                                                            <input type="hidden" name="action" value="reject_mockup">

                                                            <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>">

                                                            <input type="text" name="reason" placeholder="Reject reason" class="px-3 py-1 border border-gray-300 rounded text-xs" required />

                                                            <button type="button" class="btn btn-secondary text-xs js-confirm-reject-mockup">

                                                                <i class="fas fa-times-circle"></i> Reject Mockup

                                                            </button>

                                                        </form>

                                                    </div>

                                                    <?php endif; ?>

                                                <?php else: ?>

                                                    <span class="text-sm text-gray-500">

                                                        <?php if ($req['status'] === 'completed'): ?>

                                                            <i class="fas fa-check-circle text-green-600"></i> Completed

                                                            <?php if ($req['completed_at']): ?>

                                                                on <?php echo date('M j, Y', strtotime($req['completed_at'])); ?>

                                                            <?php endif; ?>

                                                        <?php else: ?>

                                                            <?php echo ucfirst($req['status']); ?>

                                                        <?php endif; ?>

                                                    </span>

                                                <?php endif; ?>

                                                <!-- File Links -->

                                                <div class="mt-2 flex flex-wrap gap-3 text-xs">

                                                    <?php if (!empty($req['checklist_file'])): ?>

                                                        <a href="<?php echo htmlspecialchars($req['checklist_file']); ?>" target="_blank" class="text-sm text-blue-600 hover:underline">

                                                            <i class="fas fa-file-alt mr-1"></i> View Checklist

                                                        </a>

                                                    <?php endif; ?>

                                                    <?php if (!empty($req['mockup_file'])): ?>

                                                        <a href="<?php echo htmlspecialchars($req['mockup_file']); ?>" target="_blank" class="text-sm text-blue-600 hover:underline">

                                                            <i class="fas fa-image mr-1"></i> View Designer Mockup

                                                        </a>

                                                    <?php endif; ?>

                                                </div>

                                            </td>

                                        </tr>

                                    <?php endforeach; ?>

                                </tbody>

                            </table>

                        </div>

                    <?php endif; ?>

                </div>



                <!-- Mockups Awaiting Approval -->

                <?php if (!empty($awaitingApprovalRequests)): ?>

                <div class="content-section">

                    <div class="section-header">

                        <h3 class="section-title"><i class="fas fa-image text-green-600"></i>Mockups Awaiting Approval</h3>

                    </div>

                    <div class="overflow-x-auto">

                        <table class="w-full">

                            <thead>

                                <tr class="border-b border-gray-200">

                                    <th class="text-left py-3 px-4 text-gray-600 font-medium">#</th>

                                    <th class="text-left py-3 px-4 text-gray-600 font-medium">Event</th>

                                    <th class="text-left py-3 px-4 text-gray-600 font-medium">Client</th>

                                    <th class="text-left py-3 px-4 text-gray-600 font-medium">Date</th>

                                    <th class="text-left py-3 px-4 text-gray-600 font-medium">Location</th>

                                    <th class="text-left py-3 px-4 text-gray-600 font-medium">Assigned To</th>

                                    <th class="text-left py-3 px-4 text-gray-600 font-medium">Files</th>

                                    <th class="text-left py-3 px-4 text-gray-600 font-medium">Actions</th>

                                </tr>

                            </thead>

                            <tbody>

                                <?php $row = 1; foreach ($awaitingApprovalRequests as $req): ?>

                                <tr class="border-b border-gray-100 hover:bg-gray-50 align-top">

                                    <td class="py-3 px-4 text-gray-500 text-sm"><?php echo $row++; ?></td>

                                    <td class="py-3 px-4 font-medium"><?php echo htmlspecialchars($req['event_name']); ?></td>

                                    <td class="py-3 px-4"><?php echo htmlspecialchars($req['client'] ?? 'N/A'); ?></td>

                                    <td class="py-3 px-4"><?php echo $req['event_date'] ? date('M j, Y', strtotime($req['event_date'])) : 'N/A'; ?></td>

                                    <td class="py-3 px-4"><?php echo htmlspecialchars($req['location'] ?? 'N/A'); ?></td>

                                    <td class="py-3 px-4"><?php echo htmlspecialchars($req['assigned_to_name'] ?? 'Not assigned'); ?></td>

                                    <td class="py-3 px-4">

                                        <div class="flex flex-wrap gap-3 text-xs">

                                            <?php if (!empty($req['checklist_file'])): ?>

                                                <a href="<?php echo htmlspecialchars($req['checklist_file']); ?>" target="_blank" class="text-sm text-blue-600 hover:underline">

                                                    <i class="fas fa-file-alt mr-1"></i> View Checklist

                                                </a>

                                            <?php endif; ?>

                                            <?php if (!empty($req['mockup_file'])): ?>

                                                <a href="<?php echo htmlspecialchars($req['mockup_file']); ?>" target="_blank" class="text-sm text-blue-600 hover:underline">

                                                    <i class="fas fa-image mr-1"></i> View Designer Mockup

                                                </a>

                                            <?php endif; ?>

                                        </div>

                                    </td>

                                    <td class="py-3 px-4">

                                        <div class="space-y-2">

                                            <form method="POST" class="flex flex-col gap-2">

                                                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">

                                                <input type="hidden" name="action" value="approve_mockup">

                                                <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>">

                                                <input type="text" name="feedback" placeholder="Approval note (optional)" class="px-3 py-1 border border-gray-300 rounded text-xs" />

                                                <button type="button" class="btn btn-success text-xs js-confirm-approve-mockup">

                                                    <i class="fas fa-check-circle"></i> Approve Mockup

                                                </button>

                                            </form>

                                            <form method="POST" class="flex flex-col gap-2">

                                                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">

                                                <input type="hidden" name="action" value="reject_mockup">

                                                <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>">

                                                <input type="text" name="reason" placeholder="Reject reason" class="px-3 py-1 border border-gray-300 rounded text-xs" required />

                                                <button type="button" class="btn btn-secondary text-xs js-confirm-reject-mockup">

                                                    <i class="fas fa-times-circle"></i> Reject Mockup

                                                </button>

                                            </form>

                                        </div>

                                    </td>

                                </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                </div>

                <?php endif; ?>



                <!-- Completed Graphic Requests -->

                <?php if (!empty($completedGraphicRequests)): ?>

                <div class="content-section" id="completed-graphic-requests">

                    <div class="section-header">

                        <h3 class="section-title"><i class="fas fa-check-circle text-green-600"></i>Completed Graphic Requests</h3>

                        <a href="graphic_head_dashboard.php#completed-graphic-requests" class="text-pink-600 font-semibold hover:underline" style="display:inline-flex; align-items:center; gap:0.4rem;">
                            View all
                            <i class="fas fa-arrow-right"></i>
                        </a>

                    </div>

                    <div class="overflow-x-auto">

                        <table class="w-full">

                            <thead>

                                <tr class="border-b border-gray-200">

                                    <th class="text-left py-3 px-4 text-gray-600 font-medium">#</th>

                                    <th class="text-left py-3 px-4 text-gray-600 font-medium">Event</th>

                                    <th class="text-left py-3 px-4 text-gray-600 font-medium">Client</th>

                                    <th class="text-left py-3 px-4 text-gray-600 font-medium">Date</th>

                                    <th class="text-left py-3 px-4 text-gray-600 font-medium">Location</th>

                                    <th class="text-left py-3 px-4 text-gray-600 font-medium">Assigned To</th>

                                    <th class="text-left py-3 px-4 text-gray-600 font-medium">Completed</th>

                                    <th class="text-left py-3 px-4 text-gray-600 font-medium">Files</th>

                                </tr>

                            </thead>

                            <tbody>

                                <?php $row = 1; foreach ($completedGraphicRequests as $req): ?>

                                <tr class="border-b border-gray-100 hover:bg-gray-50 align-top">

                                    <td class="py-3 px-4 text-gray-500 text-sm"><?php echo $row++; ?></td>

                                    <td class="py-3 px-4 font-medium"><?php echo htmlspecialchars($req['event_name']); ?></td>

                                    <td class="py-3 px-4"><?php echo htmlspecialchars($req['client'] ?? 'N/A'); ?></td>

                                    <td class="py-3 px-4"><?php echo $req['event_date'] ? date('M j, Y', strtotime($req['event_date'])) : 'N/A'; ?></td>

                                    <td class="py-3 px-4"><?php echo htmlspecialchars($req['location'] ?? 'N/A'); ?></td>

                                    <td class="py-3 px-4"><?php echo htmlspecialchars($req['assigned_to_name'] ?? 'Not assigned'); ?></td>

                                    <td class="py-3 px-4">

                                        <span class="status-badge status-completed">Completed</span>

                                        <?php if ($req['completed_at']): ?>

                                            <div class="mt-1 text-xs text-gray-500">

                                                on <?php echo date('M j, Y', strtotime($req['completed_at'])); ?>

                                            </div>

                                        <?php endif; ?>

                                    </td>

                                    <td class="py-3 px-4">

                                        <div class="flex flex-wrap gap-3 text-xs">

                                            <?php if (!empty($req['checklist_file'])): ?>

                                                <a href="<?php echo htmlspecialchars($req['checklist_file']); ?>" target="_blank" class="text-sm text-blue-600 hover:underline">

                                                    <i class="fas fa-file-alt mr-1"></i> View Checklist

                                                </a>

                                            <?php endif; ?>

                                            <?php if (!empty($req['mockup_file'])): ?>

                                                <a href="<?php echo htmlspecialchars($req['mockup_file']); ?>" target="_blank" class="text-sm text-blue-600 hover:underline">

                                                    <i class="fas fa-image mr-1"></i> View Mockup

                                                </a>

                                            <?php endif; ?>

                                        </div>

                                    </td>

                                </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                </div>

                <?php endif; ?>

        </main>

    </div>



    <!-- Global confirmation modal for graphics management actions -->

    <div id="hgConfirmModal" class="fixed inset-0 z-40 hidden items-center justify-center bg-black bg-opacity-40">

        <div class="bg-white rounded-xl shadow-2xl max-w-md w-full mx-4 p-6">

            <div class="flex items-start justify-between mb-4">

                <div>

                    <h3 id="hgConfirmTitle" class="text-lg font-semibold text-gray-900">Confirm action</h3>

                    <p id="hgConfirmMessage" class="mt-1 text-sm text-gray-600"></p>

                </div>

                <button type="button" id="hgConfirmClose" class="text-gray-400 hover:text-gray-600">

                    <i class="fas fa-times"></i>

                </button>

            </div>

            <div class="flex justify-end gap-3 mt-6">

                <button type="button" id="hgConfirmCancel" class="px-4 py-2 rounded-lg border border-gray-300 text-sm font-medium text-gray-700 hover:bg-gray-50">

                    Cancel

                </button>

                <button type="button" id="hgConfirmOk" class="px-4 py-2 rounded-lg bg-emerald-600 text-sm font-semibold text-white hover:bg-emerald-700">

                    Confirm

                </button>

            </div>

        </div>

    </div>



    <script>

        // Header profile/logout dropdown (top-right)

        document.addEventListener('DOMContentLoaded', function () {

            var profile  = document.getElementById('headGraphicUserProfile');

            var dropdown = document.getElementById('headGraphicHeaderProfileDropdown');



            if (!profile || !dropdown) return;



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



            // Advanced confirmation modal for Approve/Reject mockup

            var modal = document.getElementById('hgConfirmModal');

            var modalTitle = document.getElementById('hgConfirmTitle');

            var modalMessage = document.getElementById('hgConfirmMessage');

            var btnOk = document.getElementById('hgConfirmOk');

            var btnCancel = document.getElementById('hgConfirmCancel');

            var btnClose = document.getElementById('hgConfirmClose');

            var pendingForm = null;



            function openConfirm(form, title, message, isDanger) {

                pendingForm = form;

                if (modalTitle) modalTitle.textContent = title || 'Confirm action';

                if (modalMessage) modalMessage.textContent = message || '';

                if (isDanger) {

                    btnOk.classList.remove('bg-emerald-600', 'hover:bg-emerald-700');

                    btnOk.classList.add('bg-rose-600', 'hover:bg-rose-700');

                } else {

                    btnOk.classList.remove('bg-rose-600', 'hover:bg-rose-700');

                    btnOk.classList.add('bg-emerald-600', 'hover:bg-emerald-700');

                }

                modal.classList.remove('hidden');

                modal.classList.add('flex');

            }



            function closeConfirm() {

                modal.classList.add('hidden');

                modal.classList.remove('flex');

                pendingForm = null;

            }



            // Attach handlers to approve / reject buttons

            document.querySelectorAll('.js-confirm-approve-mockup').forEach(function(btn) {

                btn.addEventListener('click', function (e) {

                    e.preventDefault();

                    var form = btn.closest('form');

                    if (!form) return;

                    openConfirm(form, 'Approve mockup', 'Approve this mockup and publish it to the event so everyone can view it?', false);

                });

            });



            document.querySelectorAll('.js-confirm-reject-mockup').forEach(function(btn) {

                btn.addEventListener('click', function (e) {

                    e.preventDefault();

                    var form = btn.closest('form');

                    if (!form) return;

                    openConfirm(form, 'Reject mockup', 'Reject this mockup and send it back to the designer with your reason?', true);

                });

            });



            // Mark request completed (no mockup) using same modal

            document.querySelectorAll('.js-confirm-complete-request').forEach(function(btn) {

                btn.addEventListener('click', function (e) {

                    e.preventDefault();

                    var form = btn.closest('form');

                    if (!form) return;

                    openConfirm(form, 'Mark request completed', 'Mark this request as fully completed?', false);

                });

            });



            if (btnOk) {

                btnOk.addEventListener('click', function () {

                    if (pendingForm) {

                        pendingForm.submit();

                    }

                    closeConfirm();

                });

            }



            [btnCancel, btnClose].forEach(function(el) {

                if (!el) return;

                el.addEventListener('click', function () {

                    closeConfirm();

                });

            });



            // Close when clicking outside modal content

            if (modal) {

                modal.addEventListener('click', function (e) {

                    if (e.target === modal) {

                        closeConfirm();

                    }

                });

            }

        });

    </script>

</body>

</html>

