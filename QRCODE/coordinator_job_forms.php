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

if (!in_array($role, ['sales', 'admin', 'super'], true)) {
    header('Location: index.php');
    exit;
}

$pageTitle = 'Coordinator Job Forms';
$displayName = $user['name'] ?? $user['email'] ?? 'User';
$flashes = flash_consume();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_abort();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'coordinator_decision') {
            $jfId = (int)($_POST['job_form_id'] ?? 0);
            $decision = (string)($_POST['decision'] ?? '');
            $comment = trim((string)($_POST['comment'] ?? ''));

            if ($jfId <= 0) {
                throw new Exception('Invalid Job Form');
            }
            if (!in_array($decision, ['coordinator_approved', 'coordinator_rejected'], true)) {
                throw new Exception('Invalid decision');
            }

            $st = $db->prepare('SELECT status, coordinator_id FROM job_forms WHERE id=?');
            $st->execute([$jfId]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $status = (string)($row['status'] ?? '');
            $coordinatorId = (int)($row['coordinator_id'] ?? 0);

            if ($status !== 'submitted') {
                throw new Exception('Only submitted Job Forms can be reviewed');
            }

            if ($role === 'sales' && $coordinatorId !== (int)($user['id'] ?? 0)) {
                throw new Exception('You are not allowed to review this Job Form');
            }

            $upd = $db->prepare('UPDATE job_forms SET status=?, coordinator_reviewed_by=?, coordinator_reviewed_at=NOW(), coordinator_comment=? WHERE id=?');
            $upd->execute([$decision, (int)($user['id'] ?? 0), ($comment !== '' ? $comment : null), $jfId]);

            if (function_exists('db_log_activity')) {
                db_log_activity((int)($user['id'] ?? 0), 'job_form_' . $decision, json_encode(['job_form_id' => $jfId]));
            }

            flash_add('success', 'Job Form reviewed.');
        }
    } catch (Throwable $ex) {
        flash_add('error', $ex->getMessage());
    }

    header('Location: coordinator_job_forms.php');
    exit;
}

$pending = [];
try {
    if ($role === 'sales') {
        $st = $db->prepare("SELECT jf.*, e.name AS event_name, e.client AS event_client, e.date AS event_date, op.name AS prepared_by_name
                            FROM job_forms jf
                            JOIN events e ON e.id = jf.event_id
                            LEFT JOIN users op ON op.id = jf.prepared_by
                            WHERE jf.status='submitted' AND jf.coordinator_id = ?
                            ORDER BY jf.submitted_at DESC, jf.id DESC");
        $st->execute([(int)($user['id'] ?? 0)]);
        $pending = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
        $st = $db->query("SELECT jf.*, e.name AS event_name, e.client AS event_client, e.date AS event_date, op.name AS prepared_by_name, co.name AS coordinator_name
                          FROM job_forms jf
                          JOIN events e ON e.id = jf.event_id
                          LEFT JOIN users op ON op.id = jf.prepared_by
                          LEFT JOIN users co ON co.id = jf.coordinator_id
                          WHERE jf.status='submitted'
                          ORDER BY jf.submitted_at DESC, jf.id DESC");
        $pending = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }
} catch (Throwable $e) {
    $pending = [];
}

$history = [];
try {
    if ($role === 'sales') {
        $st = $db->prepare("SELECT jf.*, e.name AS event_name, e.client AS event_client, e.date AS event_date
                            FROM job_forms jf
                            JOIN events e ON e.id = jf.event_id
                            WHERE jf.status IN ('coordinator_approved','coordinator_rejected','finance_approved','finance_rejected')
                              AND jf.coordinator_id = ?
                            ORDER BY COALESCE(jf.coordinator_reviewed_at, jf.created_at) DESC, jf.id DESC");
        $st->execute([(int)($user['id'] ?? 0)]);
        $history = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
        $st = $db->query("SELECT jf.*, e.name AS event_name, e.client AS event_client, e.date AS event_date, co.name AS coordinator_name
                          FROM job_forms jf
                          JOIN events e ON e.id = jf.event_id
                          LEFT JOIN users co ON co.id = jf.coordinator_id
                          WHERE jf.status IN ('coordinator_approved','coordinator_rejected','finance_approved','finance_rejected')
                          ORDER BY COALESCE(jf.coordinator_reviewed_at, jf.created_at) DESC, jf.id DESC");
        $history = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }
} catch (Throwable $e) {
    $history = [];
}

function jf_status_badge($status) {
    $s = strtolower((string)$status);
    if ($s === 'submitted') return 'badge badge-warning';
    if ($s === 'coordinator_approved') return 'badge badge-success';
    if ($s === 'coordinator_rejected') return 'badge badge-danger';
    if ($s === 'finance_approved') return 'badge badge-success';
    if ($s === 'finance_rejected') return 'badge badge-danger';
    return 'badge';
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
</head>
<body>
<div class="dashboard-container">
    <?php $activePage = 'coordinator_job_forms.php'; include __DIR__ . '/sidebar.php'; ?>

    <main class="main-content">
        <div class="top-bar">
            <div class="page-title">
                <h1><?php echo htmlspecialchars($pageTitle); ?></h1>
                <p>Approve or reject Job Forms prepared by Operations</p>
            </div>
            <div class="user-menu">
                <div class="date-display"><i class="far fa-calendar-alt"></i> <?php echo date('l, F j, Y'); ?></div>
                <div class="user-profile" id="coordUserProfile">
                    <div class="avatar"><?php echo htmlspecialchars(strtoupper(mb_substr($displayName, 0, 1))); ?></div>
                    <div>
                        <div style="font-weight:600;"><?php echo htmlspecialchars($displayName); ?></div>
                        <div style="font-size:0.75rem;color:#64748b;"><?php echo htmlspecialchars(get_role_label($user['role'] ?? 'user')); ?></div>
                    </div>
                    <i class="fas fa-chevron-down" style="color:#64748b;"></i>
                    <div class="profile-dropdown" id="coordHeaderProfileDropdown">
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
                <h2 class="section-title">Pending Job Forms</h2>
            </div>
            <div class="table-container">
                <table class="data-table">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Event</th>
                        <th>Client</th>
                        <th>Prepared By</th>
                        <th>Status</th>
                        <th>Decision</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php $sn = 1; foreach ($pending as $jf): ?>
                        <tr>
                            <td><?php echo (int)$sn; ?></td>
                            <td><?php echo htmlspecialchars((string)($jf['event_name'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string)($jf['event_client'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string)($jf['prepared_by_name'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string)($jf['status'] ?? '')); ?></td>
                            <td>
                                <form method="post" style="display:grid; gap:0.35rem;">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                    <input type="hidden" name="action" value="coordinator_decision">
                                    <input type="hidden" name="job_form_id" value="<?php echo (int)($jf['id'] ?? 0); ?>">
                                    <input type="text" class="form-input" name="comment" placeholder="Coordinator comment (optional)">
                                    <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
                                        <button type="submit" name="decision" value="coordinator_approved" class="btn btn-primary">Approve</button>
                                        <button type="submit" name="decision" value="coordinator_rejected" class="btn btn-danger">Reject</button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    <?php $sn++; endforeach; ?>
                    <?php if (!$pending): ?>
                        <tr><td colspan="6" style="text-align:center;color:#64748b;padding:2rem;">No pending job forms</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title">History</h2>
            </div>
            <div class="table-container">
                <table class="data-table">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Event</th>
                        <th>Status</th>
                        <th>Coordinator Comment</th>
                        <th>Finance Comment</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php $sn2 = 1; foreach ($history as $jf): ?>
                        <tr>
                            <td><?php echo (int)$sn2; ?></td>
                            <td><?php echo htmlspecialchars((string)($jf['event_name'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string)($jf['status'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string)($jf['coordinator_comment'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string)($jf['finance_comment'] ?? '')); ?></td>
                        </tr>
                    <?php $sn2++; endforeach; ?>
                    <?php if (!$history): ?>
                        <tr><td colspan="5" style="text-align:center;color:#64748b;padding:2rem;">No history yet</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>
</div>

<script>
(function(){
    const profile = document.getElementById('coordUserProfile');
    const dropdown = document.getElementById('coordHeaderProfileDropdown');
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
