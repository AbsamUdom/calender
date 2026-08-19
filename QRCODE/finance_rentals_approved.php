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

$pageTitle = 'Approved Rentals';
$displayName = $user['name'] ?? $user['email'] ?? 'User';
$flashes = flash_consume();

$approved = [];
try {
    $st = $db->query("SELECT rr.*, e.name AS event_name, e.client AS event_client, e.location AS event_location, u.name AS requested_by_name, fu.name AS finance_name
                      FROM rental_requests rr
                      LEFT JOIN events e ON rr.event_id = e.id
                      LEFT JOIN users u ON rr.requested_by = u.id
                      LEFT JOIN users fu ON rr.reviewed_by = fu.id
                      WHERE rr.status = 'approved'
                      ORDER BY COALESCE(rr.reviewed_at, rr.submitted_at, rr.created_at) DESC, rr.id DESC");
    $approved = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
} catch (Throwable $e) {
    $approved = [];
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
        .content-section{background:#fff;border-radius:10px;padding:0.75rem;border:1px solid #e2e8f0;margin-bottom:1.25rem}
        .section-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:0.75rem;gap:0.75rem;flex-wrap:wrap}
        .section-title{font-size:1.05rem;font-weight:600;color:#1e293b;margin:0;display:flex;align-items:center;gap:0.5rem}
        .table-container{overflow-x:auto;border-radius:8px;border:1px solid #e2e8f0;width:100%}
        .data-table{width:100%;border-collapse:collapse;min-width:1050px}
        .data-table th{background:#f8fafc;padding:12px 10px;text-align:left;font-size:.8rem;font-weight:600;color:#475569;border-bottom:1px solid #e2e8f0;white-space:nowrap}
        .data-table td{padding:12px 10px;border-bottom:1px solid #e2e8f0;font-size:.85rem;color:#334155;vertical-align:middle}
        .data-table tr:hover{background:#f8fafc}
        .link-highlight{color:#2563eb;font-weight:600;text-decoration:none}
        .link-highlight:hover{text-decoration:underline}
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php $activePage = 'finance_rentals_approved.php'; include __DIR__ . '/sidebar.php'; ?>

    <main class="main-content">
        <div class="top-bar">
            <div class="page-title">
                <h1><?php echo htmlspecialchars($pageTitle); ?></h1>
                <p>All rental requests approved by Finance</p>
            </div>
            <div class="user-menu">
                <div class="date-display"><i class="far fa-calendar-alt"></i> <?php echo date('l, F j, Y'); ?></div>
                <div class="user-profile" id="finApprovedRentalsUserProfile">
                    <div class="avatar"><?php echo htmlspecialchars(strtoupper(function_exists('mb_substr') ? (string)mb_substr((string)$displayName, 0, 1) : (string)substr((string)$displayName, 0, 1))); ?></div>
                    <div>
                        <div style="font-weight:600;"><?php echo htmlspecialchars($displayName); ?></div>
                        <div style="font-size:0.75rem;color:#64748b;"><?php echo htmlspecialchars(function_exists('get_role_label') ? (string)get_role_label($user['role'] ?? 'user') : ucfirst((string)($user['role'] ?? 'user'))); ?></div>
                    </div>
                    <i class="fas fa-chevron-down" style="color:#64748b;"></i>
                    <div class="profile-dropdown" id="finApprovedRentalsHeaderProfileDropdown">
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
                <h2 class="section-title"><i class="fas fa-check-circle" style="color:#16a34a;"></i>Approved Rentals</h2>
            </div>
            <div class="table-container">
                <table class="data-table">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Event</th>
                        <th>Client</th>
                        <th>Location</th>
                        <th>Title</th>
                        <th>Amount INC</th>
                        <th>Requested By</th>
                        <th>Approved By</th>
                        <th>Approved At</th>
                        <th class="text-center">Event</th>
                        <th class="text-center">Rental</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php $sn = 1; foreach ($approved as $rr): ?>
                        <tr>
                            <td><?php echo (int)$sn; ?></td>
                            <td><?php echo htmlspecialchars((string)($rr['event_name'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string)($rr['event_client'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string)($rr['event_location'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string)($rr['title'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars(number_format((float)($rr['amount_inc'] ?? 0), 2)); ?></td>
                            <td><?php echo htmlspecialchars((string)($rr['requested_by_name'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string)($rr['finance_name'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string)($rr['reviewed_at'] ?? '')); ?></td>
                            <td class="text-center">
                                <?php if (!empty($rr['event_id'])): ?>
                                    <a href="events.php?id=<?php echo (int)$rr['event_id']; ?>" title="View Event Details" style="display:inline-flex; align-items:center; justify-content:center; padding:0.25rem; background:transparent; border:none; text-decoration:none;">
                                        <i class="fas fa-eye" style="color:#2563eb;"></i>
                                    </a>
                                <?php else: ?>
                                    <span class="text-slate-400">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <a href="rentals.php?rid=<?php echo (int)($rr['id'] ?? 0); ?>" title="View Rental" style="display:inline-flex; align-items:center; justify-content:center; padding:0.25rem; background:transparent; border:none; text-decoration:none;">
                                    <i class="fas fa-receipt" style="color:#0f172a;"></i>
                                </a>
                            </td>
                        </tr>
                    <?php $sn++; endforeach; ?>
                    <?php if (!$approved): ?>
                        <tr><td colspan="11" style="text-align:center;color:#64748b;padding:2rem;">No approved rentals found</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>
</div>

<script>
(function(){
    const profile = document.getElementById('finApprovedRentalsUserProfile');
    const dropdown = document.getElementById('finApprovedRentalsHeaderProfileDropdown');
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
