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
    'sales' => ['label' => 'Sales', 'role' => 'sales'],
    'graphic' => ['label' => 'Graphic', 'role' => 'graphic'],
    'supervisor' => ['label' => 'Supervisor', 'role' => 'supervisor'],
];
$selectedTeam = $roleNorm === 'operation' ? 'supervisor' : (string)($_GET['team'] ?? 'sales');
if (!isset($teams[$selectedTeam])) {
    $selectedTeam = 'sales';
}

$normalizeName = static function ($value) {
    $value = trim((string)$value);
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
};
$decodeIds = static function ($value) {
    if (is_array($value)) {
        $decoded = $value;
    } else {
        $decoded = json_decode((string)$value, true);
    }
    if (!is_array($decoded)) {
        return [];
    }
    return array_values(array_unique(array_filter(array_map('intval', $decoded), static fn($id) => $id > 0)));
};

$roleVariants = [
    'sales' => ['sales', 'head_sales'],
    'graphic' => ['graphic', 'graphics', 'graphic_designer', 'graphic designer', 'head_graphic'],
    'supervisor' => ['supervisor'],
];
$memberRoles = $roleVariants[$selectedTeam];
$memberRolePlaceholders = implode(',', array_fill(0, count($memberRoles), '?'));
$membersStmt = $db->prepare("SELECT id, name, email FROM users WHERE LOWER(role) IN ($memberRolePlaceholders) ORDER BY name, id");
$membersStmt->execute($memberRoles);
$members = $membersStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$memberIds = [];
$memberIdsByName = [];
$assignments = [];
foreach ($members as $member) {
    $memberId = (int)$member['id'];
    $memberIds[$memberId] = true;
    $memberIdsByName[$normalizeName($member['name'] ?? '')] = $memberId;
    $assignments[$memberId] = [];
}

$workflowAssignments = [];
if (in_array($selectedTeam, ['graphic', 'supervisor'], true)) {
    $workflowTable = $selectedTeam === 'graphic' ? 'graphic_requests' : 'supervisor_requests';
    try {
        $workStmt = $db->query("SELECT event_id, assigned_to FROM $workflowTable WHERE assigned_to IS NOT NULL AND status <> 'cancelled'");
        foreach ($workStmt ? ($workStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [] as $work) {
            $eventId = (int)$work['event_id'];
            $memberId = (int)$work['assigned_to'];
            if ($eventId > 0 && isset($memberIds[$memberId])) {
                $workflowAssignments[$eventId][$memberId] = true;
            }
        }
    } catch (Throwable $e) {
        error_log('Team performance workflow query failed: ' . $e->getMessage());
    }
}

$eventsStmt = $db->query('SELECT id, date, coordinator_id, coordinator, coordinators, graphics, graphics_users, supervisor, supervisors FROM events');
$events = $eventsStmt ? ($eventsStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
foreach ($events as $event) {
    $eventId = (int)$event['id'];
    $assignedIds = [];
    $legacyNames = [];

    if ($selectedTeam === 'sales') {
        $assignedIds = $decodeIds($event['coordinators'] ?? null);
        $coordinatorId = (int)($event['coordinator_id'] ?? 0);
        if ($coordinatorId > 0) {
            $assignedIds[] = $coordinatorId;
        }
        $legacyNames = [(string)($event['coordinator'] ?? '')];
    } elseif ($selectedTeam === 'graphic') {
        if (isset($workflowAssignments[$eventId])) {
            $assignedIds = array_keys($workflowAssignments[$eventId]);
        } else {
            $assignedIds = $decodeIds($event['graphics_users'] ?? null);
            $legacyNames = [(string)($event['graphics'] ?? '')];
        }
    } else {
        $legacyNames = preg_split('/\s*,\s*/', (string)($event['supervisor'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $matchedLegacyIds = [];
        foreach ($legacyNames as $legacyName) {
            $memberId = $memberIdsByName[$normalizeName($legacyName)] ?? 0;
            if ($memberId > 0) {
                $matchedLegacyIds[$memberId] = true;
            }
        }
        if ($matchedLegacyIds) {
            $assignedIds = array_keys($matchedLegacyIds);
            $legacyNames = [];
        } else {
            $assignedIds = $decodeIds($event['supervisors'] ?? null);
            if (!$assignedIds && isset($workflowAssignments[$eventId])) {
                $assignedIds = array_keys($workflowAssignments[$eventId]);
            }
        }
    }

    foreach (array_unique(array_map('intval', $assignedIds)) as $memberId) {
        if (isset($memberIds[$memberId])) {
            $assignments[$memberId][$eventId] = $event['date'] ?? null;
        }
    }
    foreach ($legacyNames as $legacyName) {
        $memberId = $memberIdsByName[$normalizeName($legacyName)] ?? 0;
        if ($memberId > 0) {
            $assignments[$memberId][$eventId] = $event['date'] ?? null;
        }
    }
}

$today = new DateTimeImmutable('today');
$weekStart = (new DateTimeImmutable())->setISODate((int)$today->format('o'), (int)$today->format('W'), 1);
$weekEnd = $weekStart->modify('+6 days');
$monthStart = $today->modify('first day of this month');
$monthEnd = $today->modify('last day of this month');
$yearStart = $today->setDate((int)$today->format('Y'), 1, 1);
$yearEnd = $today->setDate((int)$today->format('Y'), 12, 31);

$countEvents = static function ($eventDates, ?DateTimeImmutable $start = null, ?DateTimeImmutable $end = null) {
    if ($start === null || $end === null) {
        return count($eventDates);
    }
    $startValue = $start->format('Y-m-d');
    $endValue = $end->format('Y-m-d');
    return count(array_filter($eventDates, static function ($date) use ($startValue, $endValue) {
        $date = (string)$date;
        return $date !== '' && $date >= $startValue && $date <= $endValue;
    }));
};

$rows = [];
$teamEvents = [];
foreach ($members as $member) {
    $memberId = (int)$member['id'];
    $eventDates = $assignments[$memberId] ?? [];
    foreach ($eventDates as $eventId => $date) {
        $teamEvents[$eventId] = $date;
    }
    $rows[] = [
        'id' => $memberId,
        'name' => $member['name'] ?? '',
        'email' => $member['email'] ?? '',
        'week' => $countEvents($eventDates, $weekStart, $weekEnd),
        'month' => $countEvents($eventDates, $monthStart, $monthEnd),
        'year' => $countEvents($eventDates, $yearStart, $yearEnd),
        'all' => $countEvents($eventDates),
    ];
}
usort($rows, static fn($a, $b) => ($b['all'] <=> $a['all']) ?: strcasecmp((string)$a['name'], (string)$b['name']));

$totals = [
    'week' => $countEvents($teamEvents, $weekStart, $weekEnd),
    'month' => $countEvents($teamEvents, $monthStart, $monthEnd),
    'year' => $countEvents($teamEvents, $yearStart, $yearEnd),
    'all' => $countEvents($teamEvents),
];
$displayName = $user['name'] ?? $user['email'] ?? 'User';
$teamLabel = $teams[$selectedTeam]['label'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Team Performance | Event Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="style.css">
    <style>
        .performance-card { background:#fff; border:1px solid #e2e8f0; border-radius:16px; box-shadow:0 1px 3px rgba(15,23,42,.06); }
        .team-filter { display:flex; align-items:end; gap:12px; flex-wrap:wrap; padding:20px; margin-bottom:24px; }
        .team-filter label { display:block; margin-bottom:6px; color:#475569; font-size:.8rem; font-weight:700; }
        .team-filter select { min-width:220px; padding:10px 38px 10px 12px; border:1px solid #cbd5e1; border-radius:10px; background:#fff; color:#1e293b; }
        .filter-button { padding:10px 18px; border:0; border-radius:10px; background:#2563eb; color:#fff; font-weight:700; cursor:pointer; }
        .period-card { padding:20px; color:#fff; border-radius:16px; box-shadow:0 8px 18px rgba(15,23,42,.12); }
        .period-card p { margin:0; font-size:.82rem; font-weight:600; opacity:.85; }
        .period-card strong { display:block; margin-top:6px; font-size:1.75rem; }
        .table-wrap { overflow-x:auto; }
        .performance-table { width:100%; border-collapse:collapse; }
        .performance-table th { padding:13px 16px; text-align:left; background:#f8fafc; color:#475569; font-size:.75rem; text-transform:uppercase; letter-spacing:.04em; border-bottom:1px solid #e2e8f0; }
        .performance-table td { padding:14px 16px; border-bottom:1px solid #f1f5f9; color:#334155; }
        .performance-table th:not(:first-child):not(:nth-child(2)), .performance-table td:not(:first-child):not(:nth-child(2)) { text-align:center; }
        .member-name { color:#0f172a; font-weight:700; }
        .member-email { color:#64748b; font-size:.82rem; }
        .count-badge { display:inline-flex; min-width:36px; justify-content:center; padding:5px 9px; border-radius:999px; background:#eff6ff; color:#1d4ed8; font-weight:700; }
        .view-member { display:inline-flex; width:36px; height:36px; align-items:center; justify-content:center; border-radius:10px; background:#eff6ff; color:#2563eb; text-decoration:none; transition:background .2s,color .2s; }
        .view-member:hover { background:#2563eb; color:#fff; }
        .scope-note { padding:12px 16px; margin-bottom:20px; border-radius:10px; background:#fef3c7; color:#92400e; font-size:.88rem; }
        @media (max-width:640px) { .team-filter > div, .team-filter select, .filter-button { width:100%; } }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main class="main-content">
        <div class="top-bar">
            <div class="page-title">
                <h1>Team Performance</h1>
                <p>Event assignments by team member for the current week, month and year</p>
            </div>
            <div class="user-menu">
                <div class="date-display"><i class="far fa-calendar-alt"></i> <?php echo date('l, F j, Y'); ?></div>
                <a href="profile.php" class="user-profile" style="text-decoration:none;color:inherit;">
                    <div class="avatar"><?php echo htmlspecialchars(strtoupper(function_exists('mb_substr') ? mb_substr($displayName, 0, 1) : substr($displayName, 0, 1))); ?></div>
                    <div>
                        <div style="font-weight:600;"><?php echo htmlspecialchars($displayName); ?></div>
                        <div style="font-size:.75rem;color:#64748b;"><?php echo htmlspecialchars(get_role_label($user['role'] ?? 'user')); ?></div>
                    </div>
                </a>
            </div>
        </div>

        <?php if ($roleNorm === 'operation'): ?>
            <div class="scope-note"><i class="fas fa-shield-halved"></i> Operations access is limited to Supervisor team performance.</div>
        <?php else: ?>
            <form method="get" class="performance-card team-filter">
                <div>
                    <label for="team">Select team</label>
                    <select id="team" name="team">
                        <?php foreach ($teams as $teamKey => $team): ?>
                            <option value="<?php echo htmlspecialchars($teamKey); ?>" <?php echo $selectedTeam === $teamKey ? 'selected' : ''; ?>><?php echo htmlspecialchars($team['label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="filter-button" type="submit"><i class="fas fa-filter"></i> View Team</button>
            </form>
        <?php endif; ?>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mb-6">
            <div class="period-card bg-gradient-to-br from-blue-500 to-blue-600"><p>This Week</p><strong><?php echo number_format($totals['week']); ?></strong><span class="text-xs opacity-80"><?php echo $weekStart->format('M j'); ?> – <?php echo $weekEnd->format('M j'); ?> · unique events</span></div>
            <div class="period-card bg-gradient-to-br from-violet-500 to-violet-600"><p>This Month</p><strong><?php echo number_format($totals['month']); ?></strong><span class="text-xs opacity-80"><?php echo $monthStart->format('F Y'); ?> · unique events</span></div>
            <div class="period-card bg-gradient-to-br from-emerald-500 to-emerald-600"><p>This Year</p><strong><?php echo number_format($totals['year']); ?></strong><span class="text-xs opacity-80"><?php echo $yearStart->format('Y'); ?> · unique events</span></div>
            <div class="period-card bg-gradient-to-br from-slate-700 to-slate-900"><p>All Time</p><strong><?php echo number_format($totals['all']); ?></strong><span class="text-xs opacity-80">Unique team events</span></div>
        </div>

        <section class="performance-card">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;padding:20px;border-bottom:1px solid #e2e8f0;">
                <div>
                    <h2 style="margin:0;color:#0f172a;font-size:1.05rem;font-weight:800;"><?php echo htmlspecialchars($teamLabel); ?> Team</h2>
                    <p style="margin:4px 0 0;color:#64748b;font-size:.85rem;"><?php echo number_format(count($rows)); ?> team member<?php echo count($rows) === 1 ? '' : 's'; ?></p>
                </div>
            </div>
            <div class="table-wrap">
                <table class="performance-table">
                    <thead><tr><th>Team Member</th><th>Email</th><th>This Week</th><th>This Month</th><th>This Year</th><th>All Time</th><th>View</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td class="member-name"><?php echo htmlspecialchars((string)$row['name']); ?></td>
                            <td class="member-email"><?php echo htmlspecialchars((string)$row['email']); ?></td>
                            <td><span class="count-badge"><?php echo number_format($row['week']); ?></span></td>
                            <td><span class="count-badge"><?php echo number_format($row['month']); ?></span></td>
                            <td><span class="count-badge"><?php echo number_format($row['year']); ?></span></td>
                            <td><span class="count-badge"><?php echo number_format($row['all']); ?></span></td>
                            <td><a class="view-member" href="team_member_events.php?team=<?php echo urlencode($selectedTeam); ?>&amp;user_id=<?php echo (int)$row['id']; ?>" title="View all events" aria-label="View all events for <?php echo htmlspecialchars((string)$row['name']); ?>"><i class="fas fa-eye"></i></a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?>
                        <tr><td colspan="7" style="padding:36px;text-align:center;color:#64748b;">No <?php echo htmlspecialchars(strtolower($teamLabel)); ?> team members found.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>
</body>
</html>
