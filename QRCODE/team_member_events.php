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

if (!in_array($roleNorm, ['operation', 'admin', 'super'], true)) {
    http_response_code(403);
    echo 'Access denied. Insufficient permissions.';
    exit;
}

$teams = [
    'sales' => ['label' => 'Sales', 'roles' => ['sales', 'head_sales']],
    'graphic' => ['label' => 'Graphic', 'roles' => ['graphic', 'graphics', 'graphic_designer', 'graphic designer', 'head_graphic']],
    'supervisor' => ['label' => 'Supervisor', 'roles' => ['supervisor']],
];
$selectedTeam = (string)($_GET['team'] ?? '');
$memberId = (int)($_GET['user_id'] ?? 0);
if (!isset($teams[$selectedTeam]) || $memberId <= 0) {
    http_response_code(404);
    echo 'Team member not found.';
    exit;
}
if ($roleNorm === 'operation' && $selectedTeam !== 'supervisor') {
    http_response_code(403);
    echo 'Access denied. Operations can only view supervisor performance.';
    exit;
}

$memberRoles = $teams[$selectedTeam]['roles'];
$rolePlaceholders = implode(',', array_fill(0, count($memberRoles), '?'));
$memberStmt = $db->prepare("SELECT id, name, email, role FROM users WHERE id = ? AND LOWER(role) IN ($rolePlaceholders) LIMIT 1");
$memberStmt->execute(array_merge([$memberId], $memberRoles));
$member = $memberStmt->fetch(PDO::FETCH_ASSOC);
if (!$member) {
    http_response_code(404);
    echo 'Team member not found.';
    exit;
}

$normalizeName = static function ($value) {
    $value = trim((string)$value);
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
};
$decodeIds = static function ($value) {
    $decoded = json_decode((string)$value, true);
    if (!is_array($decoded)) {
        return [];
    }
    return array_values(array_unique(array_filter(array_map('intval', $decoded), static fn($id) => $id > 0)));
};

$teamMembersStmt = $db->prepare("SELECT id, name FROM users WHERE LOWER(role) IN ($rolePlaceholders)");
$teamMembersStmt->execute($memberRoles);
$teamMemberIdsByName = [];
foreach ($teamMembersStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $teamMember) {
    $teamMemberIdsByName[$normalizeName($teamMember['name'] ?? '')] = (int)$teamMember['id'];
}

$workflowAssignments = [];
if (in_array($selectedTeam, ['graphic', 'supervisor'], true)) {
    $workflowTable = $selectedTeam === 'graphic' ? 'graphic_requests' : 'supervisor_requests';
    try {
        $workStmt = $db->query("SELECT event_id, assigned_to FROM $workflowTable WHERE assigned_to IS NOT NULL AND status <> 'cancelled'");
        foreach ($workStmt ? ($workStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [] as $work) {
            $eventId = (int)$work['event_id'];
            $assignedTo = (int)$work['assigned_to'];
            if ($eventId > 0 && $assignedTo > 0) {
                $workflowAssignments[$eventId][$assignedTo] = true;
            }
        }
    } catch (Throwable $e) {
        error_log('Team member events workflow query failed: ' . $e->getMessage());
    }
}

$eventsStmt = $db->query('SELECT id, name, date, end_date, client, location, status, amount, coordinator_id, coordinator, coordinators, graphics, graphics_users, supervisor, supervisors FROM events ORDER BY COALESCE(date, created_at) DESC, id DESC');
$allEvents = $eventsStmt ? ($eventsStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
$events = [];
$memberName = $normalizeName($member['name'] ?? '');
foreach ($allEvents as $event) {
    $eventId = (int)$event['id'];
    $assigned = false;

    if ($selectedTeam === 'sales') {
        $assignedIds = $decodeIds($event['coordinators'] ?? null);
        $assignedIds[] = (int)($event['coordinator_id'] ?? 0);
        $assigned = in_array($memberId, $assignedIds, true) || $normalizeName($event['coordinator'] ?? '') === $memberName;
    } elseif ($selectedTeam === 'graphic') {
        if (isset($workflowAssignments[$eventId])) {
            $assigned = isset($workflowAssignments[$eventId][$memberId]);
        } else {
            $assigned = in_array($memberId, $decodeIds($event['graphics_users'] ?? null), true) || $normalizeName($event['graphics'] ?? '') === $memberName;
        }
    } else {
        $legacyNames = preg_split('/\s*,\s*/', (string)($event['supervisor'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $matchedLegacyIds = [];
        foreach ($legacyNames as $legacyName) {
            $legacyMemberId = $teamMemberIdsByName[$normalizeName($legacyName)] ?? 0;
            if ($legacyMemberId > 0) {
                $matchedLegacyIds[$legacyMemberId] = true;
            }
        }
        if ($matchedLegacyIds) {
            $assigned = isset($matchedLegacyIds[$memberId]);
        } else {
            $assignedIds = $decodeIds($event['supervisors'] ?? null);
            if ($assignedIds) {
                $assigned = in_array($memberId, $assignedIds, true);
            } elseif (isset($workflowAssignments[$eventId])) {
                $assigned = isset($workflowAssignments[$eventId][$memberId]);
            }
        }
    }

    if ($assigned) {
        $events[] = $event;
    }
}

$totalEventAmount = 0.0;
if ($selectedTeam === 'sales') {
    foreach ($events as $event) {
        $totalEventAmount += (float)($event['amount'] ?? 0);
    }
}
$displayName = $user['name'] ?? $user['email'] ?? 'User';
$teamLabel = $teams[$selectedTeam]['label'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars((string)$member['name']); ?> Events | Event Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="style.css">
    <style>
        .history-card { background:#fff; border:1px solid #e2e8f0; border-radius:16px; box-shadow:0 1px 3px rgba(15,23,42,.06); }
        .member-summary { display:flex; justify-content:space-between; align-items:center; gap:20px; flex-wrap:wrap; padding:22px; margin-bottom:24px; }
        .member-heading { display:flex; align-items:center; gap:14px; }
        .member-icon { width:52px; height:52px; display:flex; align-items:center; justify-content:center; border-radius:15px; background:#dbeafe; color:#2563eb; font-size:1.2rem; }
        .member-totals { display:flex; gap:12px; flex-wrap:wrap; }
        .total-events { min-width:130px; padding:14px 20px; border-radius:14px; background:#eff6ff; color:#1d4ed8; text-align:center; }
        .total-events strong { display:block; font-size:1.65rem; line-height:1.1; }
        .total-amount { background:#ecfdf5; color:#047857; min-width:210px; }
        .history-table-wrap { overflow-x:auto; }
        .history-table { width:100%; border-collapse:collapse; }
        .history-table th { padding:13px 16px; text-align:left; background:#f8fafc; color:#475569; font-size:.75rem; text-transform:uppercase; letter-spacing:.04em; border-bottom:1px solid #e2e8f0; }
        .history-table td { padding:14px 16px; color:#334155; border-bottom:1px solid #f1f5f9; vertical-align:top; }
        .event-name { color:#0f172a; font-weight:700; }
        .event-meta { color:#64748b; font-size:.8rem; margin-top:3px; }
        .status-badge { display:inline-flex; padding:5px 9px; border-radius:999px; background:#f1f5f9; color:#475569; font-size:.75rem; font-weight:700; text-transform:capitalize; }
        .view-event { display:inline-flex; width:36px; height:36px; align-items:center; justify-content:center; border-radius:10px; background:#eff6ff; color:#2563eb; text-decoration:none; transition:background .2s,color .2s; }
        .view-event:hover { background:#2563eb; color:#fff; }
        .back-link { display:inline-flex; align-items:center; gap:7px; margin-bottom:18px; color:#2563eb; font-size:.88rem; font-weight:700; text-decoration:none; }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main class="main-content">
        <div class="top-bar">
            <div class="page-title">
                <h1>All-Time Events</h1>
                <p>Every event assigned to the selected team member</p>
            </div>
            <div class="user-menu">
                <div class="date-display"><i class="far fa-calendar-alt"></i> <?php echo date('l, F j, Y'); ?></div>
                <a href="profile.php" class="user-profile" style="text-decoration:none;color:inherit;">
                    <div class="avatar"><?php echo htmlspecialchars(strtoupper(function_exists('mb_substr') ? mb_substr($displayName, 0, 1) : substr($displayName, 0, 1))); ?></div>
                    <div><div style="font-weight:600;"><?php echo htmlspecialchars($displayName); ?></div><div style="font-size:.75rem;color:#64748b;"><?php echo htmlspecialchars(get_role_label($user['role'] ?? 'user')); ?></div></div>
                </a>
            </div>
        </div>

        <a class="back-link" href="team_performance.php?team=<?php echo urlencode($selectedTeam); ?>"><i class="fas fa-arrow-left"></i> Back to Team Performance</a>

        <section class="history-card member-summary">
            <div class="member-heading">
                <div class="member-icon"><i class="fas fa-user"></i></div>
                <div>
                    <h2 style="margin:0;color:#0f172a;font-size:1.15rem;font-weight:800;"><?php echo htmlspecialchars((string)$member['name']); ?></h2>
                    <p style="margin:4px 0 0;color:#64748b;font-size:.86rem;"><?php echo htmlspecialchars((string)$member['email']); ?> · <?php echo htmlspecialchars($teamLabel); ?> Team</p>
                </div>
            </div>
            <div class="member-totals"><div class="total-events"><strong><?php echo number_format(count($events)); ?></strong><span style="font-size:.78rem;font-weight:700;">All-Time Events</span></div><?php if ($selectedTeam === 'sales'): ?><div class="total-events total-amount"><strong style="font-size:1.25rem;">TSh <?php echo number_format($totalEventAmount, 2); ?></strong><span style="font-size:.78rem;font-weight:700;">All-Time Event Amount</span></div><?php endif; ?></div>
        </section>

        <section class="history-card">
            <div style="padding:20px;border-bottom:1px solid #e2e8f0;"><h2 style="margin:0;color:#0f172a;font-size:1.05rem;font-weight:800;">Assigned Events</h2></div>
            <div class="history-table-wrap">
                <table class="history-table">
                    <thead><tr><th>Event</th><th>Date</th><th>Client</th><th>Location</th><?php if ($selectedTeam === 'sales'): ?><th>Event Amount</th><?php endif; ?><th>Status</th><th>View</th></tr></thead>
                    <tbody>
                    <?php foreach ($events as $event): ?>
                        <tr>
                            <td><div class="event-name"><?php echo htmlspecialchars((string)($event['name'] ?? 'Untitled Event')); ?></div><div class="event-meta">Event #<?php echo number_format((int)$event['id']); ?></div></td>
                            <td><?php echo !empty($event['date']) ? htmlspecialchars(date('M j, Y', strtotime((string)$event['date']))) : 'Not scheduled'; ?><?php if (!empty($event['end_date']) && $event['end_date'] !== $event['date']): ?><div class="event-meta">to <?php echo htmlspecialchars(date('M j, Y', strtotime((string)$event['end_date']))); ?></div><?php endif; ?></td>
                            <td><?php echo htmlspecialchars((string)($event['client'] ?: '—')); ?></td>
                            <td><?php echo htmlspecialchars((string)($event['location'] ?: '—')); ?></td>
                            <?php if ($selectedTeam === 'sales'): ?><td style="font-weight:700;white-space:nowrap;color:#047857;">TSh <?php echo number_format((float)($event['amount'] ?? 0), 2); ?></td><?php endif; ?>
                            <td><span class="status-badge"><?php echo htmlspecialchars(strtolower((string)($event['status'] ?: 'Not set'))); ?></span></td>
                            <td><a class="view-event" href="events.php?id=<?php echo (int)$event['id']; ?>" title="View event information" aria-label="View information for <?php echo htmlspecialchars((string)($event['name'] ?? 'event')); ?>"><i class="fas fa-eye"></i></a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$events): ?>
                        <tr><td colspan="<?php echo $selectedTeam === 'sales' ? 7 : 6; ?>" style="padding:40px;text-align:center;color:#64748b;">No events have been assigned to this person.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>
</body>
</html>
