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

$pageTitle = 'Finance Purchase Requests';
$displayName = $user['name'] ?? $user['email'] ?? 'User';
$flashes = flash_consume();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_abort();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'purchase_decision') {
            $prId = (int)($_POST['pr_id'] ?? 0);
            $decision = $_POST['decision'] ?? '';
            $comment = trim((string)($_POST['comment'] ?? ''));

            if ($prId <= 0) {
                throw new Exception('Invalid purchase request');
            }
            if (!in_array($decision, ['approved', 'rejected'], true)) {
                throw new Exception('Invalid decision');
            }

            $st = $db->prepare('SELECT status FROM purchase_requests WHERE id=?');
            $st->execute([$prId]);
            $status = (string)($st->fetchColumn() ?? '');
            if ($status !== 'finance_submitted') {
                throw new Exception('Only Accountant-approved purchase requests can be reviewed by Finance');
            }

            $stmt = $db->prepare("UPDATE purchase_requests SET status=?, reviewed_by=?, reviewed_at=NOW(), finance_comment=? WHERE id=?");
            $stmt->execute([$decision, (int)($user['id'] ?? 0), ($comment !== '' ? $comment : null), $prId]);

            if (function_exists('db_log_activity')) {
                db_log_activity((int)($user['id'] ?? 0), 'purchase_request_' . $decision, json_encode(['pr_id' => $prId]));
            }

            flash_add('success', 'Purchase request ' . $decision . '.');
        }
    } catch (Throwable $ex) {
        flash_add('error', $ex->getMessage());
    }

    header('Location: finance_purchase_requests.php');
    exit;
}

$pendingPurchases = [];
try {
    $stmt = $db->query("SELECT pr.*, e.name AS event_name, e.client AS event_client, e.location AS event_location, e.graphics AS event_graphics, e.supervisors AS event_supervisors FROM purchase_requests pr LEFT JOIN events e ON pr.event_id = e.id WHERE pr.status='finance_submitted' ORDER BY pr.submitted_at DESC, pr.id DESC");
    $pendingPurchases = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable $e) {
    $pendingPurchases = [];
}

$reviewedPurchases = [];
try {
    $stmt = $db->query("SELECT pr.*, e.name AS event_name, e.client AS event_client, e.location AS event_location, e.graphics AS event_graphics, e.supervisors AS event_supervisors FROM purchase_requests pr LEFT JOIN events e ON pr.event_id = e.id WHERE pr.status IN ('approved','rejected') ORDER BY pr.reviewed_at DESC, pr.id DESC");
    $reviewedPurchases = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable $e) {
    $reviewedPurchases = [];
}

$viewPrId = (int)($_GET['pr_id'] ?? 0);
$viewPr = null;
$viewItems = [];
if ($viewPrId > 0) {
    try {
        $st = $db->prepare("SELECT pr.*, e.name AS event_name, e.client AS event_client, e.date AS event_date, e.location AS event_location, e.graphics AS event_graphics, e.supervisors AS event_supervisors
                            FROM purchase_requests pr
                            LEFT JOIN events e ON pr.event_id = e.id
                            WHERE pr.id = ?");
        $st->execute([$viewPrId]);
        $viewPr = $st->fetch(PDO::FETCH_ASSOC);
        if ($viewPr) {
            $it = $db->prepare('SELECT * FROM purchase_request_items WHERE purchase_request_id = ? ORDER BY COALESCE(item_no, id) ASC');
            $it->execute([$viewPrId]);
            $viewItems = $it->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    } catch (Throwable $e) {
        $viewPr = null;
        $viewItems = [];
    }
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
foreach ($pendingPurchases as $pr) {
    $supervisorIds = array_merge($supervisorIds, $parse_id_list($pr['event_supervisors'] ?? null));
}
foreach ($reviewedPurchases as $pr) {
    $supervisorIds = array_merge($supervisorIds, $parse_id_list($pr['event_supervisors'] ?? null));
}
if ($viewPr) {
    $supervisorIds = array_merge($supervisorIds, $parse_id_list($viewPr['event_supervisors'] ?? null));
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
        .data-table{width:100%;border-collapse:collapse;min-width:950px}
        .data-table th{background:#f8fafc;padding:12px 10px;text-align:left;font-size:.8rem;font-weight:600;color:#475569;border-bottom:1px solid #e2e8f0;white-space:nowrap}
        .data-table td{padding:12px 10px;border-bottom:1px solid #e2e8f0;font-size:.85rem;color:#334155;vertical-align:middle}
        .data-table tr:hover{background:#f8fafc}
        .link-highlight{color:#2563eb;font-weight:600;text-decoration:none}
        .link-highlight:hover{text-decoration:underline}
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php $activePage = 'finance_purchase_requests.php'; include __DIR__ . '/sidebar.php'; ?>

    <main class="main-content">
        <div class="top-bar">
            <div class="page-title">
                <h1><?php echo htmlspecialchars($pageTitle); ?></h1>
                <p>Review and approve/reject purchase requests</p>
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

        <?php if ($viewPr): ?>
        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title">Purchase Request Details</h2>
                <div class="section-actions">
                    <a href="finance_purchase_requests.php" class="btn btn-secondary"><i class="fas fa-arrow-left mr-1"></i> Back</a>
                </div>
            </div>

            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 0.75rem; margin-bottom: 1rem;">
                <div class="form-group"><label class="form-label">Title</label><input class="form-input" value="<?php echo htmlspecialchars((string)($viewPr['title'] ?? '')); ?>" readonly></div>
                <div class="form-group"><label class="form-label">Event</label><input class="form-input" value="<?php echo htmlspecialchars((string)($viewPr['event_name'] ?? '')); ?>" readonly></div>
                <div class="form-group"><label class="form-label">Client</label><input class="form-input" value="<?php echo htmlspecialchars((string)($viewPr['event_client'] ?? '')); ?>" readonly></div>
                <div class="form-group"><label class="form-label">Location</label><input class="form-input" value="<?php echo htmlspecialchars((string)($viewPr['event_location'] ?? '')); ?>" readonly></div>
                <div class="form-group"><label class="form-label">Graphics</label><input class="form-input" value="<?php echo htmlspecialchars((string)($viewPr['event_graphics'] ?? '')); ?>" readonly></div>
                <div class="form-group"><label class="form-label">Supervisor</label><input class="form-input" value="<?php echo strip_tags($format_supervisors($viewPr['event_supervisors'] ?? null)); ?>" readonly></div>
                <div class="form-group"><label class="form-label">Amount</label><input class="form-input" value="<?php echo htmlspecialchars(number_format((float)($viewPr['amount'] ?? 0), 2)); ?>" readonly></div>
                <div class="form-group"><label class="form-label">Status</label><input class="form-input" value="<?php echo htmlspecialchars((string)($viewPr['status'] ?? '')); ?>" readonly></div>
            </div>

            <div class="table-container">
                <table class="data-table">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Material</th>
                        <th>Color</th>
                        <th>Size</th>
                        <th>Qty</th>
                        <th>Supplier</th>
                        <th>Unit Price</th>
                        <th>Total</th>
                        <th>Acct Decision</th>
                        <th>Acct Comment</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php $n = 1; foreach ($viewItems as $it): ?>
                        <tr>
                            <td><?php echo (int)$n; ?></td>
                            <td><?php echo htmlspecialchars((string)($it['material'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string)($it['color'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string)($it['size'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string)($it['quantity'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string)($it['supplier'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string)($it['unit_price'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string)($it['line_total'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string)($it['accountant_decision'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string)($it['accountant_comment'] ?? '')); ?></td>
                        </tr>
                    <?php $n++; endforeach; ?>
                    <?php if (!$viewItems): ?>
                        <tr><td colspan="10" style="text-align:center;color:#64748b;padding:2rem;">No items found</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div style="margin-top: 1rem;">
                <form method="post" style="display:grid; gap:0.5rem; max-width: 520px;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                    <input type="hidden" name="action" value="purchase_decision">
                    <input type="hidden" name="pr_id" value="<?php echo (int)$viewPrId; ?>">
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
            </div>
        </div>
        <?php endif; ?>

        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title">Pending Purchase Requests</h2>
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
                        <th>Title</th>
                        <th>Amount</th>
                        <th class="text-center">Details</th>
                        <th class="text-center">Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php $rowNum = 1; foreach ($pendingPurchases as $pr): ?>
                        <tr>
                            <td><?php echo (int)$rowNum; ?></td>
                            <td>
                                <?php if (!empty($pr['event_id'])): ?>
                                    <?php echo htmlspecialchars((string)($pr['event_name'] ?? '')); ?>
                                <?php else: ?>
                                    <span class="text-slate-400">-</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo !empty($pr['event_id']) ? htmlspecialchars((string)($pr['event_location'] ?? '')) : '<span class="text-slate-400">-</span>'; ?></td>
                            <td><?php echo (!empty($pr['event_id']) && !empty($pr['event_graphics'])) ? htmlspecialchars((string)$pr['event_graphics']) : '<span class="text-slate-400">-</span>'; ?></td>
                            <td><?php echo !empty($pr['event_id']) ? $format_supervisors($pr['event_supervisors'] ?? null) : '<span class="text-slate-400">-</span>'; ?></td>
                            <td><?php echo htmlspecialchars((string)($pr['title'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars(number_format((float)($pr['amount'] ?? 0), 2)); ?></td>
                            <td class="text-center">
                                <a href="events.php?id=<?php echo (int)($pr['event_id'] ?? 0); ?>" title="View Event Details" style="display:inline-flex; align-items:center; justify-content:center; padding:0.25rem; background:transparent; border:none; text-decoration:none;">
                                    <i class="fas fa-eye" style="color:#2563eb;"></i>
                                </a>
                            </td>
                            <td class="text-center">
                                <a href="finance_purchase_requests.php?pr_id=<?php echo (int)$pr['id']; ?>" title="View Purchase Request" style="display:inline-flex; align-items:center; justify-content:center; padding:0.25rem; background:transparent; border:none; text-decoration:none;">
                                    <i class="fas fa-receipt" style="color:#2563eb;"></i>
                                </a>
                            </td>
                        </tr>
                    <?php $rowNum++; endforeach; ?>
                    <?php if (!$pendingPurchases): ?>
                        <tr><td colspan="9" style="text-align:center;color:#64748b;padding:2rem;">No pending purchase requests</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title">Purchase Request History (Approved / Rejected)</h2>
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
                        <th>Title</th>
                        <th>Amount</th>
                        <th class="text-center">Details</th>
                        <th>Status</th>
                        <th>Finance Comment</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php $rowNum2 = 1; foreach ($reviewedPurchases as $pr): ?>
                        <tr>
                            <td><?php echo (int)$rowNum2; ?></td>
                            <td>
                                <?php if (!empty($pr['event_id'])): ?>
                                    <?php echo htmlspecialchars((string)($pr['event_name'] ?? '')); ?>
                                <?php else: ?>
                                    <span class="text-slate-400">-</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo !empty($pr['event_id']) ? htmlspecialchars((string)($pr['event_location'] ?? '')) : '<span class="text-slate-400">-</span>'; ?></td>
                            <td><?php echo (!empty($pr['event_id']) && !empty($pr['event_graphics'])) ? htmlspecialchars((string)$pr['event_graphics']) : '<span class="text-slate-400">-</span>'; ?></td>
                            <td><?php echo !empty($pr['event_id']) ? $format_supervisors($pr['event_supervisors'] ?? null) : '<span class="text-slate-400">-</span>'; ?></td>
                            <td><?php echo htmlspecialchars((string)($pr['title'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars(number_format((float)($pr['amount'] ?? 0), 2)); ?></td>
                            <td class="text-center">
                                <a href="events.php?id=<?php echo (int)($pr['event_id'] ?? 0); ?>" title="View Event Details" style="display:inline-flex; align-items:center; justify-content:center; padding:0.25rem; background:transparent; border:none; text-decoration:none;">
                                    <i class="fas fa-eye" style="color:#2563eb;"></i>
                                </a>
                            </td>
                            <td><?php echo htmlspecialchars((string)($pr['status'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string)($pr['finance_comment'] ?? '')); ?></td>
                        </tr>
                    <?php $rowNum2++; endforeach; ?>
                    <?php if (!$reviewedPurchases): ?>
                        <tr><td colspan="10" style="text-align:center;color:#64748b;padding:2rem;">No reviewed purchase requests</td></tr>
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
