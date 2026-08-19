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

$roleNorm = strtolower(trim((string)($user['role'] ?? '')));
$roleNorm = str_replace(['_', '-'], ' ', $roleNorm);
$roleNorm = preg_replace('/\s+/', ' ', $roleNorm);

if ($roleNorm === 'admin') {
    header('Location: admin_dashboard.php');
    exit;
}

$isGraphicRole = (strpos($roleNorm, 'graphic') === 0) || (strpos($roleNorm, 'graphics') === 0);
if (!($isGraphicRole || in_array($roleNorm, ['super', 'admin'], true))) {
    header('Location: index.php');
    exit;
}

$pageTitle = 'Graphic Tasks';
$displayName = $user['name'] ?? $user['email'] ?? 'User';
$flashes = flash_consume();

$avatarInitial = '';
try {
    $avatarInitial = function_exists('mb_substr') ? (string)mb_substr((string)$displayName, 0, 1) : (string)substr((string)$displayName, 0, 1);
} catch (Throwable $e) {
    $avatarInitial = (string)substr((string)$displayName, 0, 1);
}
$avatarInitial = strtoupper($avatarInitial !== '' ? $avatarInitial : 'U');

$roleLabel = function_exists('get_role_label') ? (string)get_role_label($user['role'] ?? 'user') : ucfirst((string)($user['role'] ?? 'user'));

$allowedStatuses = ['all', 'assigned', 'in_progress', 'completed', 'cancelled'];
$statusFilter = strtolower(trim((string)($_GET['status'] ?? 'all')));
if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'all';
}

$eventIdFilter = (int)($_GET['event_id'] ?? 0);

$whereStatusSql = '';
$params = [(int)($user['id'] ?? 0)];
if ($statusFilter !== 'all') {
    $whereStatusSql = ' AND gr.status = ?';
    $params[] = $statusFilter;
}

$whereEventSql = '';
if ($eventIdFilter > 0) {
    $whereEventSql = ' AND gr.event_id = ?';
    $params[] = $eventIdFilter;
}

$tasks = [];
try {
    $st = $db->prepare("SELECT gr.*, e.name AS event_name, e.client AS client, e.date AS event_date, e.end_date, e.location AS location, e.mockup_file AS event_mockup_file
                        FROM graphic_requests gr
                        JOIN events e ON e.id = gr.event_id
                        WHERE gr.assigned_to = ? $whereStatusSql $whereEventSql
                        ORDER BY 
                            CASE gr.status
                                WHEN 'assigned' THEN 1
                                WHEN 'in_progress' THEN 2
                                WHEN 'completed' THEN 3
                                WHEN 'cancelled' THEN 4
                                ELSE 5
                            END,
                            COALESCE(gr.completed_at, gr.created_at) DESC");
    $st->execute($params);
    $tasks = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $tasks = [];
}

$counts = ['assigned' => 0, 'in_progress' => 0, 'completed' => 0, 'cancelled' => 0];
try {
    $ct = $db->prepare("SELECT gr.status, COUNT(*) AS c
                        FROM graphic_requests gr
                        WHERE gr.assigned_to = ?
                        GROUP BY gr.status");
    $ct->execute([(int)($user['id'] ?? 0)]);
    foreach (($ct->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
        $s = (string)($row['status'] ?? '');
        if (isset($counts[$s])) {
            $counts[$s] = (int)($row['c'] ?? 0);
        }
    }
} catch (Throwable $e) {
    // ignore
}

$totalCount = array_sum($counts);

function gt_filter_btn_class(string $current, string $value): string {
    if ($current === $value) {
        return 'background:#111827;color:#fff;border-color:#111827;';
    }
    return 'background:#fff;color:#334155;border-color:#e2e8f0;';
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
    <style>
        .content-section{background:#fff;border-radius:1rem;padding:1.25rem;box-shadow:0 6px 18px rgba(15,23,42,0.08);margin-bottom:1.5rem;border:1px solid #e2e8f0}
        .section-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;padding-bottom:.75rem;border-bottom:1px solid #e2e8f0;gap:.75rem;flex-wrap:wrap}
        .section-title{font-size:1rem;font-weight:600;color:#1e293b;display:flex;align-items:center;gap:.5rem;margin:0}
        .data-table{width:100%;border-collapse:collapse;min-width:900px}
        .data-table th{text-align:left;padding:.75rem;font-size:.8rem;font-weight:600;color:#64748b;text-transform:uppercase;border-bottom:1px solid #e2e8f0;white-space:nowrap}
        .data-table td{padding:.75rem;font-size:.875rem;color:#334155;border-bottom:1px solid #f1f5f9;vertical-align:top}
        .data-table tr:hover{background:#f8fafc}
        .status-badge{display:inline-block;padding:.2rem .6rem;border-radius:999px;font-size:.7rem;font-weight:700;text-transform:uppercase}
        .status-assigned{background:#dbeafe;color:#1e40af}
        .status-in_progress{background:#d1fae5;color:#065f46}
        .status-completed{background:#e0e7ff;color:#3730a3}
        .status-cancelled{background:#fee2e2;color:#991b1b}
        .filter-btn{display:inline-flex;align-items:center;gap:.4rem;border:1px solid #e2e8f0;border-radius:999px;padding:.45rem .8rem;font-size:.85rem;font-weight:600;text-decoration:none}
        .filter-btn:hover{filter:brightness(.98)}
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php $activePage = 'graphic_tasks.php'; include __DIR__ . '/sidebar.php'; ?>

    <main class="main-content">
        <div class="top-bar">
            <div class="page-title">
                <h1><?php echo htmlspecialchars($pageTitle); ?></h1>
                <p>All your assigned tasks by status</p>
            </div>
            <div class="user-menu">
                <div class="date-display"><i class="far fa-calendar-alt"></i> <?php echo date('l, F j, Y'); ?></div>
                <div class="user-profile" id="graphicTasksUserProfile">
                    <div class="avatar" style="background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);">
                        <?php echo htmlspecialchars($avatarInitial); ?>
                    </div>
                    <div>
                        <div style="font-weight: 600;"><?php echo htmlspecialchars($displayName); ?></div>
                        <div style="font-size: 0.75rem; color: #64748b;"><?php echo htmlspecialchars($roleLabel); ?></div>
                    </div>
                    <i class="fas fa-chevron-down ml-2" style="color: #64748b;"></i>
                    <div class="profile-dropdown" id="graphicTasksHeaderProfileDropdown">
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

        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title"><i class="fas fa-filter" style="color:#8b5cf6;"></i>Filters</h2>
                <div style="display:flex; flex-wrap:wrap; gap:.5rem;">
                    <a class="filter-btn" href="graphic_tasks.php?status=all" style="<?php echo gt_filter_btn_class($statusFilter, 'all'); ?>">
                        All
                        <span style="opacity:.9; font-weight:700;">(<?php echo (int)$totalCount; ?>)</span>
                    </a>
                    <a class="filter-btn" href="graphic_tasks.php?status=assigned" style="<?php echo gt_filter_btn_class($statusFilter, 'assigned'); ?>">
                        Assigned
                        <span style="opacity:.9; font-weight:700;">(<?php echo (int)$counts['assigned']; ?>)</span>
                    </a>
                    <a class="filter-btn" href="graphic_tasks.php?status=in_progress" style="<?php echo gt_filter_btn_class($statusFilter, 'in_progress'); ?>">
                        In Progress
                        <span style="opacity:.9; font-weight:700;">(<?php echo (int)$counts['in_progress']; ?>)</span>
                    </a>
                    <a class="filter-btn" href="graphic_tasks.php?status=completed" style="<?php echo gt_filter_btn_class($statusFilter, 'completed'); ?>">
                        Completed
                        <span style="opacity:.9; font-weight:700;">(<?php echo (int)$counts['completed']; ?>)</span>
                    </a>
                    <a class="filter-btn" href="graphic_tasks.php?status=cancelled" style="<?php echo gt_filter_btn_class($statusFilter, 'cancelled'); ?>">
                        Cancelled
                        <span style="opacity:.9; font-weight:700;">(<?php echo (int)$counts['cancelled']; ?>)</span>
                    </a>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                    <tr>
                        <th>Event</th>
                        <th>Client</th>
                        <th>Location</th>
                        <th>Event Date</th>
                        <th>Status</th>
                        <th>Completed</th>
                        <th class="text-center">Details</th>
                        <th>Files</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($tasks as $t): ?>
                        <?php
                        $st = (string)($t['status'] ?? '');
                        $badgeClass = 'status-badge';
                        if ($st === 'assigned') $badgeClass .= ' status-assigned';
                        elseif ($st === 'in_progress') $badgeClass .= ' status-in_progress';
                        elseif ($st === 'completed') $badgeClass .= ' status-completed';
                        elseif ($st === 'cancelled') $badgeClass .= ' status-cancelled';
                        ?>
                        <tr>
                            <td style="font-weight:600;"><?php echo htmlspecialchars((string)($t['event_name'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string)($t['client'] ?? '-')); ?></td>
                            <td><?php echo htmlspecialchars((string)($t['location'] ?? '-')); ?></td>
                            <td>
                                <?php
                                $dt = $t['end_date'] ?? $t['event_date'] ?? null;
                                echo $dt ? htmlspecialchars(date('M j, Y', strtotime((string)$dt))) : '-';
                                ?>
                            </td>
                            <td><span class="<?php echo $badgeClass; ?>"><?php echo htmlspecialchars(str_replace('_', ' ', $st)); ?></span></td>
                            <td><?php echo !empty($t['completed_at']) ? htmlspecialchars(date('M j, Y', strtotime((string)$t['completed_at']))) : '-'; ?></td>
                            <td class="text-center">
                                <a href="events.php?id=<?php echo (int)($t['event_id'] ?? 0); ?>" title="View Event Details" style="display:inline-flex; align-items:center; justify-content:center; padding:0.25rem; background:transparent; border:none; text-decoration:none;">
                                    <i class="fas fa-eye" style="color:#8b5cf6;"></i>
                                </a>
                            </td>
                            <td>
                                <?php if (!empty($t['mockup_file'])): ?>
                                    <a href="<?php echo htmlspecialchars((string)$t['mockup_file']); ?>" target="_blank" class="text-purple-600 hover:underline" style="text-decoration:none;">
                                        <i class="fas fa-file"></i> Mockup
                                    </a>
                                    <?php if (empty($t['event_mockup_file'])): ?>
                                        <div style="font-size:.75rem;color:#64748b;margin-top:.25rem;">Awaiting review</div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color:#94a3b8;">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$tasks): ?>
                        <tr>
                            <td colspan="8" style="text-align:center;color:#64748b;padding:2rem;">
                                No tasks found for this filter.
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>
</div>

<script>
(function(){
    const profile = document.getElementById('graphicTasksUserProfile');
    const dropdown = document.getElementById('graphicTasksHeaderProfileDropdown');
    if (!profile || !dropdown) return;
    profile.addEventListener('click', function(e){
        e.stopPropagation();
        dropdown.classList.toggle('show');
    });
    document.addEventListener('click', function(){ dropdown.classList.remove('show'); });
})();
</script>
</body>
</html>
