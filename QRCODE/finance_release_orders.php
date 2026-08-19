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

$pageTitle = 'Finance Release Orders';
$displayName = $user['name'] ?? $user['email'] ?? 'User';
$flashes = flash_consume();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_abort();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'ro_decision') {
            $roId = (int)($_POST['ro_id'] ?? 0);
            $decision = $_POST['decision'] ?? '';
            $comment = trim((string)($_POST['comment'] ?? ''));

            if ($roId <= 0) {
                throw new Exception('Invalid Release Order');
            }
            if (!in_array($decision, ['approved', 'rejected'], true)) {
                throw new Exception('Invalid decision');
            }

            $st = $db->prepare('SELECT status FROM release_orders WHERE id=?');
            $st->execute([$roId]);
            $status = (string)($st->fetchColumn() ?? '');
            if ($status !== 'submitted') {
                throw new Exception('Only submitted Release Orders can be reviewed');
            }

            $stmt = $db->prepare("UPDATE release_orders SET status=?, reviewed_by=?, reviewed_at=NOW(), finance_comment=? WHERE id=?");
            $stmt->execute([$decision, (int)($user['id'] ?? 0), ($comment !== '' ? $comment : null), $roId]);

            if (function_exists('db_log_activity')) {
                db_log_activity((int)($user['id'] ?? 0), 'release_order_' . $decision, json_encode(['ro_id' => $roId]));
            }

            flash_add('success', 'Release Order ' . $decision . '.');
        }
    } catch (Throwable $ex) {
        flash_add('error', $ex->getMessage());
    }

    header('Location: finance_release_orders.php');
    exit;
}

$pendingReleaseOrders = [];
try {
    $stmt = $db->query("SELECT ro.*, e.name AS event_name, e.client AS event_client, e.location AS event_location, e.graphics AS event_graphics, e.supervisors AS event_supervisors FROM release_orders ro LEFT JOIN events e ON ro.event_id = e.id WHERE ro.status='submitted' ORDER BY ro.submitted_at DESC, ro.id DESC");
    $pendingReleaseOrders = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable $e) {
    $pendingReleaseOrders = [];
}

$reviewedReleaseOrders = [];
try {
    $stmt = $db->query("SELECT ro.*, e.name AS event_name, e.client AS event_client, e.location AS event_location, e.graphics AS event_graphics, e.supervisors AS event_supervisors FROM release_orders ro LEFT JOIN events e ON ro.event_id = e.id WHERE ro.status IN ('approved','rejected') ORDER BY ro.reviewed_at DESC, ro.id DESC");
    $reviewedReleaseOrders = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable $e) {
    $reviewedReleaseOrders = [];
}

$parse_id_list = function ($value): array {
    if ($value === null) return [];
    if (is_array($value)) {
        $arr = $value;
    } else {
        $str = trim((string)$value);
        if ($str === '' || $str === 'null') return [];
        $decoded = json_decode($str, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $arr = $decoded;
        } else {
            $arr = preg_split('/\s*,\s*/', trim($str, "[](){} \t\n\r\0\x0B\"") , -1, PREG_SPLIT_NO_EMPTY);
        }
    }
    $out = [];
    foreach ($arr as $v) {
        $id = (int)$v;
        if ($id > 0) $out[] = $id;
    }
    return array_values(array_unique($out));
};

$supervisorIds = [];
foreach ($pendingReleaseOrders as $ro) {
    $supervisorIds = array_merge($supervisorIds, $parse_id_list($ro['event_supervisors'] ?? null));
}
foreach ($reviewedReleaseOrders as $ro) {
    $supervisorIds = array_merge($supervisorIds, $parse_id_list($ro['event_supervisors'] ?? null));
}
$supervisorIds = array_values(array_unique(array_filter($supervisorIds, fn($id) => $id > 0)));

$supervisorNameById = [];
if ($supervisorIds) {
    $placeholders = implode(',', array_fill(0, count($supervisorIds), '?'));
    $st = $db->prepare("SELECT id, name FROM users WHERE id IN ($placeholders)");
    $st->execute($supervisorIds);
    foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
        $supervisorNameById[(int)$row['id']] = (string)$row['name'];
    }
}

$format_supervisors = function ($value) use ($parse_id_list, $supervisorNameById): string {
    $ids = $parse_id_list($value);
    if (!$ids) return '<span class="text-slate-400">-</span>';
    $names = [];
    foreach ($ids as $id) {
        $nm = $supervisorNameById[$id] ?? null;
        if ($nm) $names[] = $nm;
    }
    if (!$names) return '<span class="text-slate-400">-</span>';
    return htmlspecialchars(implode(', ', $names));
};
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
        .section-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:0.75rem}
        .section-title{font-size:1.05rem;font-weight:600;color:#1e293b;margin:0}
        .table-container{overflow-x:auto;border-radius:8px;border:1px solid #e2e8f0;width:100%}
        .data-table{width:100%;border-collapse:collapse;min-width:900px}
        .data-table th{background:#f8fafc;padding:12px 10px;text-align:left;font-size:.8rem;font-weight:600;color:#475569;border-bottom:1px solid #e2e8f0;white-space:nowrap}
        .data-table td{padding:12px 10px;border-bottom:1px solid #e2e8f0;font-size:.85rem;color:#334155;vertical-align:middle}
        .data-table tr:hover{background:#f8fafc}
        .link-highlight{color:#2563eb;font-weight:600;text-decoration:none}
        .link-highlight:hover{text-decoration:underline}
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php $activePage = 'finance_release_orders.php'; include __DIR__ . '/sidebar.php'; ?>

    <main class="main-content">
        <div class="top-bar">
            <div class="page-title">
                <h1><?php echo htmlspecialchars($pageTitle); ?></h1>
                <p>Review and approve/reject Release Orders</p>
            </div>
            <div class="user-menu">
                <div class="date-display">
                    <i class="far fa-calendar-alt mr-2"></i>
                    <?php echo date('l, F j, Y'); ?>
                </div>

                <div class="user-profile" id="financeUserProfile">
                    <div class="avatar"><?php echo htmlspecialchars(strtoupper(mb_substr($displayName, 0, 1))); ?></div>
                    <div>
                        <div style="font-weight:600;"><?php echo htmlspecialchars($displayName); ?></div>
                        <div style="font-size:0.75rem;color:#64748b;"><?php echo htmlspecialchars(get_role_label($user['role'] ?? 'user')); ?></div>
                    </div>
                    <i class="fas fa-chevron-down" style="color:#64748b;"></i>
                    <div class="profile-dropdown" id="financeHeaderProfileDropdown">
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
                <h2 class="section-title">Pending Release Orders</h2>
            </div>
            <div class="table-container">
                <table class="data-table">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Event</th>
                        <th>Location</th>
                        <th>Graphics</th>
                        <th>Supervisor</th>
                        <th class="text-center">Details</th>
                        <th>File</th>
                        <th>Decision</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php $rowNum = 1; foreach ($pendingReleaseOrders as $ro): ?>
                        <tr>
                            <td><?php echo (int)$rowNum; ?></td>
                            <td><?php echo htmlspecialchars((string)($ro['event_name'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string)($ro['event_location'] ?? '')); ?></td>
                            <td><?php echo !empty($ro['event_graphics']) ? htmlspecialchars((string)$ro['event_graphics']) : '<span class="text-slate-400">-</span>'; ?></td>
                            <td><?php echo $format_supervisors($ro['event_supervisors'] ?? null); ?></td>
                            <td class="text-center">
                                <a href="events.php?id=<?php echo (int)($ro['event_id'] ?? 0); ?>" title="View Event Details" style="display:inline-flex; align-items:center; justify-content:center; padding:0.25rem; background:transparent; border:none; text-decoration:none;">
                                    <i class="fas fa-eye" style="color:#2563eb;"></i>
                                </a>
                            </td>
                            <td><a class="link-highlight" target="_blank" href="release_orders.php?view=print&amp;ro_id=<?php echo (int)$ro['id']; ?>">View</a></td>
                            <td>
                                <form method="post" style="display:grid; gap:0.35rem;">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                    <input type="hidden" name="action" value="ro_decision">
                                    <input type="hidden" name="ro_id" value="<?php echo (int)$ro['id']; ?>">
                                    <div style="display:flex; gap:0.5rem; align-items:center;">
                                        <input type="text" class="form-input" name="comment" placeholder="Finance comment (optional)" style="min-width: 220px;">
                                        <button type="submit" name="decision" value="approved" title="Approve" style="display:inline-flex; align-items:center; justify-content:center; padding:0.25rem; background:transparent; border:none; cursor:pointer;">
                                            <i class="fas fa-check" style="color:#16a34a;"></i>
                                        </button>
                                        <button type="submit" name="decision" value="rejected" title="Reject" style="display:inline-flex; align-items:center; justify-content:center; padding:0.25rem; background:transparent; border:none; cursor:pointer;">
                                            <i class="fas fa-times" style="color:#dc2626;"></i>
                                        </button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    <?php $rowNum++; endforeach; ?>
                    <?php if (!$pendingReleaseOrders): ?>
                        <tr><td colspan="8" style="text-align:center;color:#64748b;padding:2rem;">No pending release orders</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title">Release Order History (Approved / Rejected)</h2>
            </div>
            <div class="table-container">
                <table class="data-table">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Event</th>
                        <th>Location</th>
                        <th>Graphics</th>
                        <th>Supervisor</th>
                        <th class="text-center">Details</th>
                        <th>Status</th>
                        <th>File</th>
                        <th>Finance Comment</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php $rowNum2 = 1; foreach ($reviewedReleaseOrders as $ro): ?>
                        <tr>
                            <td><?php echo (int)$rowNum2; ?></td>
                            <td><?php echo htmlspecialchars((string)($ro['event_name'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string)($ro['event_location'] ?? '')); ?></td>
                            <td><?php echo !empty($ro['event_graphics']) ? htmlspecialchars((string)$ro['event_graphics']) : '<span class="text-slate-400">-</span>'; ?></td>
                            <td><?php echo $format_supervisors($ro['event_supervisors'] ?? null); ?></td>
                            <td class="text-center">
                                <a href="events.php?id=<?php echo (int)($ro['event_id'] ?? 0); ?>" title="View Event Details" style="display:inline-flex; align-items:center; justify-content:center; padding:0.25rem; background:transparent; border:none; text-decoration:none;">
                                    <i class="fas fa-eye" style="color:#2563eb;"></i>
                                </a>
                            </td>
                            <td><?php echo htmlspecialchars((string)($ro['status'] ?? '')); ?></td>
                            <td><a class="link-highlight" target="_blank" href="release_orders.php?view=print&amp;ro_id=<?php echo (int)$ro['id']; ?>">View</a></td>
                            <td><?php echo htmlspecialchars((string)($ro['finance_comment'] ?? '')); ?></td>
                        </tr>
                    <?php $rowNum2++; endforeach; ?>
                    <?php if (!$reviewedReleaseOrders): ?>
                        <tr><td colspan="9" style="text-align:center;color:#64748b;padding:2rem;">No reviewed release orders</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>
</div>

<script>
  (function () {
    if (window.__financeProfileDropdownBound) return;
    window.__financeProfileDropdownBound = true;

    const profile = document.getElementById('financeUserProfile');
    const dropdown = document.getElementById('financeHeaderProfileDropdown');
    if (!profile || !dropdown) return;

    profile.addEventListener('click', function (e) {
      e.stopPropagation();
      dropdown.classList.toggle('show');
    });

    document.addEventListener('click', function (event) {
      if (!profile.contains(event.target)) {
        dropdown.classList.remove('show');
      }
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        dropdown.classList.remove('show');
      }
    });
  })();
</script>

</body>
</html>
