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
if (!in_array($role, ['supervisor', 'super', 'admin'], true)) {
    header('Location: index.php');
    exit;
}

$displayName = $user['name'] ?? $user['email'] ?? 'User';
$pageTitle = ($role === 'supervisor') ? 'Supervisor Dashboard' : 'Supervisor Overview';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_abort();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'update_execution') {
            $eventId = (int)($_POST['event_id'] ?? 0);
            $executionStatus = (string)($_POST['execution_status'] ?? '');
            $executionNotes = trim((string)($_POST['execution_notes'] ?? ''));

            if ($eventId <= 0) {
                throw new Exception('Invalid event');
            }

            $allowed = ['not_started', 'in_progress', 'completed'];
            if (!in_array($executionStatus, $allowed, true)) {
                throw new Exception('Invalid execution status');
            }

            $supName = (string)($user['name'] ?? '');
            if ($supName === '') {
                throw new Exception('Missing supervisor name');
            }

            $check = $db->prepare('SELECT id FROM events WHERE id = ? AND UPPER(supervisor) = UPPER(?)');
            $check->execute([$eventId, $supName]);
            if (!$check->fetchColumn()) {
                throw new Exception('You are not assigned to this event');
            }

            $upd = $db->prepare('UPDATE events SET execution_status=?, execution_updated_by=?, execution_updated_at=NOW(), execution_notes=? WHERE id=?');
            $upd->execute([$executionStatus, (int)($user['id'] ?? 0), ($executionNotes !== '' ? $executionNotes : null), $eventId]);
            db_log_activity((int)($user['id'] ?? 0), 'supervisor_execution_update', json_encode(['event_id' => $eventId, 'execution_status' => $executionStatus]));
            flash_add('success', 'Execution status updated.');
        }

        if ($action === 'submit_report') {
            $eventId = (int)($_POST['event_id'] ?? 0);
            $reportText = trim((string)($_POST['report_text'] ?? ''));

            if ($eventId <= 0) {
                throw new Exception('Invalid event');
            }
            if ($reportText === '') {
                throw new Exception('Report is required');
            }

            $supName = (string)($user['name'] ?? '');
            if ($supName === '') {
                throw new Exception('Missing supervisor name');
            }

            $check = $db->prepare('SELECT id FROM events WHERE id = ? AND UPPER(supervisor) = UPPER(?)');
            $check->execute([$eventId, $supName]);
            if (!$check->fetchColumn()) {
                throw new Exception('You are not assigned to this event');
            }

            $ins = $db->prepare("INSERT INTO supervisor_reports(event_id, supervisor_user_id, execution_status, report_text) VALUES (?,?, 'completed', ?)");
            $ins->execute([$eventId, (int)($user['id'] ?? 0), $reportText]);

            $upd = $db->prepare("UPDATE events SET execution_status='completed', execution_updated_by=?, execution_updated_at=NOW() WHERE id=?");
            $upd->execute([(int)($user['id'] ?? 0), $eventId]);

            db_log_activity((int)($user['id'] ?? 0), 'supervisor_report_submit', json_encode(['event_id' => $eventId]));
            flash_add('success', 'Completion report submitted.');
        }
    } catch (Throwable $e) {
        flash_add('error', $e->getMessage());
    }

    header('Location: supervisor_dashboard.php');
    exit;
}

// Load events assigned to this supervisor (match by supervisor name, stored uppercased)
$events = [];
try {
    $supName = $user['name'] ?? '';
    if ($supName !== '') {
        $stmt = $db->prepare("SELECT id, name, date, client, location, coordinator, status, quote_status, execution_status, execution_updated_at, execution_notes FROM events WHERE UPPER(supervisor) = UPPER(?) ORDER BY COALESCE(date, created_at) DESC, id DESC");
        $stmt->execute([$supName]);
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    $events = [];
}

$assignedCount = count($events);
$inProgressCount = count(array_filter($events, fn($e) => (string)($e['execution_status'] ?? 'not_started') === 'in_progress'));
$completedCount = count(array_filter($events, fn($e) => (string)($e['execution_status'] ?? 'not_started') === 'completed'));

$reportsByEventId = [];
try {
    $stmt = $db->prepare("SELECT id, event_id, execution_status, report_text, created_at FROM supervisor_reports WHERE supervisor_user_id = ? ORDER BY created_at DESC, id DESC");
    $stmt->execute([(int)($user['id'] ?? 0)]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $r) {
        $eid = (int)($r['event_id'] ?? 0);
        if ($eid > 0 && !isset($reportsByEventId[$eid])) {
            $reportsByEventId[$eid] = $r;
        }
    }
} catch (Throwable $e) {
    $reportsByEventId = [];
}

$supervisorRequests = [];
try {
    $stmt = $db->prepare("
        SELECT sr.*, e.name as event_name, e.date as event_date, e.client, e.location,
               u1.name as requested_by_name
        FROM supervisor_requests sr
        JOIN events e ON sr.event_id = e.id
        LEFT JOIN users u1 ON sr.requested_by = u1.id
        WHERE sr.assigned_to = ? AND sr.status NOT IN ('cancelled')
        ORDER BY sr.created_at DESC
    ");
    $stmt->execute([(int)($user['id'] ?? 0)]);
    $supervisorRequests = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $supervisorRequests = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($pageTitle); ?> | Hugo Domingo Events</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="dashboard-container">
    <?php $activePage = 'supervisor_dashboard.php'; include __DIR__ . '/sidebar.php'; ?>

    <main class="main-content">
        <div class="top-bar">
            <div class="page-title">
                <h1><?php echo htmlspecialchars($pageTitle); ?></h1>
                <p>Your assigned events and tasks</p>
            </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <div class="card p-5" style="min-height: 96px; display:flex; align-items:center; justify-content:space-between;">
                <div>
                    <div class="text-xs font-semibold tracking-wide text-slate-500 uppercase">Assigned Events</div>
                    <div class="text-2xl font-extrabold text-slate-900" style="margin-top:0.25rem;"><?php echo number_format($assignedCount); ?></div>
                </div>
                <div style="width:44px;height:44px;border-radius:12px;background:rgba(59,130,246,0.12);display:flex;align-items:center;justify-content:center;">
                    <i class="fas fa-clipboard-list" style="color:#2563eb;"></i>
                </div>
            </div>
            <div class="card p-5" style="min-height: 96px; display:flex; align-items:center; justify-content:space-between;">
                <div>
                    <div class="text-xs font-semibold tracking-wide text-slate-500 uppercase">In Progress</div>
                    <div class="text-2xl font-extrabold text-slate-900" style="margin-top:0.25rem;"><?php echo number_format($inProgressCount); ?></div>
                </div>
                <div style="width:44px;height:44px;border-radius:12px;background:rgba(245,158,11,0.12);display:flex;align-items:center;justify-content:center;">
                    <i class="fas fa-person-running" style="color:#d97706;"></i>
                </div>
            </div>
            <div class="card p-5" style="min-height: 96px; display:flex; align-items:center; justify-content:space-between;">
                <div>
                    <div class="text-xs font-semibold tracking-wide text-slate-500 uppercase">Completed</div>
                    <div class="text-2xl font-extrabold text-slate-900" style="margin-top:0.25rem;"><?php echo number_format($completedCount); ?></div>
                </div>
                <div style="width:44px;height:44px;border-radius:12px;background:rgba(16,185,129,0.12);display:flex;align-items:center;justify-content:center;">
                    <i class="fas fa-circle-check" style="color:#059669;"></i>
                </div>
            </div>
            <div class="card p-5" style="min-height: 96px; display:flex; align-items:center; justify-content:space-between;">
                <div>
                    <div class="text-xs font-semibold tracking-wide text-slate-500 uppercase">Reports</div>
                    <div class="text-2xl font-extrabold text-slate-900" style="margin-top:0.25rem;"><?php echo number_format(count($reportsByEventId)); ?></div>
                </div>
                <div style="width:44px;height:44px;border-radius:12px;background:rgba(99,102,241,0.12);display:flex;align-items:center;justify-content:center;">
                    <i class="fas fa-file-signature" style="color:#4f46e5;"></i>
                </div>
            </div>
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

        <!-- Supervisor Requests -->
        <div class="content-section">
            <div class="section-header">
                <h3 class="section-title"><i class="fas fa-tasks text-purple-600"></i> My Supervisor Requests</h3>
            </div>

            <?php if (empty($supervisorRequests)): ?>
                <p class="text-gray-500 text-center py-8">No supervisor requests assigned to you yet.</p>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                        <tr class="border-b border-gray-200">
                            <th class="text-left py-3 px-4 text-gray-600 font-medium">#</th>
                            <th class="text-left py-3 px-4 text-gray-600 font-medium">Event</th>
                            <th class="text-left py-3 px-4 text-gray-600 font-medium">Client</th>
                            <th class="text-left py-3 px-4 text-gray-600 font-medium">Location</th>
                            <th class="text-left py-3 px-4 text-gray-600 font-medium">Date</th>
                            <th class="text-left py-3 px-4 text-gray-600 font-medium">Requested By</th>
                            <th class="text-left py-3 px-4 text-gray-600 font-medium">Status</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($supervisorRequests as $index => $req): ?>
                            <tr class="border-b border-gray-100 hover:bg-gray-50">
                                <td class="py-3 px-4 text-gray-500 text-sm"><?php echo $index + 1; ?></td>
                                <td class="py-3 px-4 font-medium">
                                    <?php echo htmlspecialchars($req['event_name']); ?>
                                </td>
                                <td class="py-3 px-4">
                                    <?php echo htmlspecialchars($req['client'] ?? '-'); ?>
                                </td>
                                <td class="py-3 px-4">
                                    <?php echo htmlspecialchars($req['location'] ?? '-'); ?>
                                </td>
                                <td class="py-3 px-4">
                                    <?php echo $req['event_date'] ? date('M j, Y', strtotime($req['event_date'])) : 'TBD'; ?>
                                </td>
                                <td class="py-3 px-4">
                                    <?php echo htmlspecialchars($req['requested_by_name'] ?? '-'); ?>
                                </td>
                                <td class="py-3 px-4">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                        <?php 
                                        echo $req['status'] === 'pending' ? 'bg-yellow-100 text-yellow-800' : 
                                             ($req['status'] === 'assigned' ? 'bg-blue-100 text-blue-800' : 
                                             ($req['status'] === 'in_progress' ? 'bg-green-100 text-green-800' : 
                                             ($req['status'] === 'completed' ? 'bg-purple-100 text-purple-800' : 'bg-gray-100 text-gray-800')));
                                        ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $req['status'])); ?>
                                    </span>
                                    <?php if ($req['notes']): ?>
                                        <div class="mt-1 text-xs text-gray-500">
                                            <i class="fas fa-sticky-note mr-1"></i><?php echo htmlspecialchars($req['notes']); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Assigned Events (from events table) -->
        <div class="content-section">
            <div class="section-header">
                <h3 class="section-title"><i class="fas fa-clipboard-list text-blue-600"></i> Assigned Events</h3>
            </div>

            <?php if (empty($events)): ?>
                <p class="text-gray-500 text-center py-8">No events assigned to you yet.</p>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                        <tr class="border-b border-gray-200">
                            <th class="text-left py-3 px-4 text-gray-600 font-medium">Date</th>
                            <th class="text-left py-3 px-4 text-gray-600 font-medium">Event</th>
                            <th class="text-left py-3 px-4 text-gray-600 font-medium">Client</th>
                            <th class="text-left py-3 px-4 text-gray-600 font-medium">Location</th>
                            <th class="text-left py-3 px-4 text-gray-600 font-medium">Coordinator</th>
                            <th class="text-left py-3 px-4 text-gray-600 font-medium">Status</th>
                            <th class="text-left py-3 px-4 text-gray-600 font-medium">Execution</th>
                            <th class="text-left py-3 px-4 text-gray-600 font-medium">Report</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($events as $ev): ?>
                            <tr class="border-b border-gray-100 hover:bg-gray-50">
                                <td class="py-3 px-4">
                                    <?php echo $ev['date'] ? htmlspecialchars($ev['date']) : 'TBD'; ?>
                                </td>
                                <td class="py-3 px-4 font-medium">
                                    <?php echo htmlspecialchars($ev['name']); ?>
                                </td>
                                <td class="py-3 px-4">
                                    <?php echo htmlspecialchars($ev['client'] ?? ''); ?>
                                </td>
                                <td class="py-3 px-4">
                                    <?php echo htmlspecialchars($ev['location'] ?? ''); ?>
                                </td>
                                <td class="py-3 px-4">
                                    <?php echo htmlspecialchars($ev['coordinator'] ?? ''); ?>
                                </td>
                                <td class="py-3 px-4">
                                    <?php echo htmlspecialchars($ev['status'] ?? ''); ?>
                                </td>
                                <td class="py-3 px-4">
                                    <?php $es = (string)($ev['execution_status'] ?? 'not_started'); ?>
                                    <form method="post" class="grid grid-cols-1 gap-2" style="min-width: 220px;">
                                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                        <input type="hidden" name="action" value="update_execution">
                                        <input type="hidden" name="event_id" value="<?php echo (int)($ev['id'] ?? 0); ?>">
                                        <select name="execution_status" class="text-sm px-2 py-1 border border-gray-300 rounded">
                                            <option value="not_started" <?php echo $es === 'not_started' ? 'selected' : ''; ?>>Not Started</option>
                                            <option value="in_progress" <?php echo $es === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                            <option value="completed" <?php echo $es === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                        </select>
                                        <input type="text" name="execution_notes" class="text-sm px-2 py-1 border border-gray-300 rounded" placeholder="Notes (optional)" value="<?php echo htmlspecialchars((string)($ev['execution_notes'] ?? '')); ?>">
                                        <button type="submit" class="btn btn-primary" style="padding:0.4rem 0.75rem;">Update</button>
                                    </form>
                                </td>
                                <td class="py-3 px-4">
                                    <?php $eid = (int)($ev['id'] ?? 0); ?>
                                    <?php if ($eid > 0 && isset($reportsByEventId[$eid])): ?>
                                        <div class="text-sm text-gray-700" style="min-width: 220px;">
                                            <div class="font-medium">Submitted</div>
                                            <div class="text-xs text-gray-500"><?php echo htmlspecialchars((string)($reportsByEventId[$eid]['created_at'] ?? '')); ?></div>
                                            <div class="text-xs text-gray-600 mt-1" style="white-space: pre-wrap;"><?php echo htmlspecialchars((string)($reportsByEventId[$eid]['report_text'] ?? '')); ?></div>
                                        </div>
                                    <?php else: ?>
                                        <form method="post" class="grid grid-cols-1 gap-2" style="min-width: 220px;">
                                            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                            <input type="hidden" name="action" value="submit_report">
                                            <input type="hidden" name="event_id" value="<?php echo $eid; ?>">
                                            <textarea name="report_text" class="text-sm px-2 py-1 border border-gray-300 rounded" rows="3" placeholder="Completion report..."></textarea>
                                            <button type="submit" class="btn btn-primary" style="padding:0.4rem 0.75rem;">Submit Report</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>
</body>
</html>
