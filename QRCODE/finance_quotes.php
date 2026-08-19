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

$pageTitle = 'Finance Quotes';
$displayName = $user['name'] ?? $user['email'] ?? 'User';
$flashes = flash_consume();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_abort();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'quote_decision') {
            $eventId = (int)($_POST['event_id'] ?? 0);
            $decision = $_POST['decision'] ?? '';
            $comment = trim((string)($_POST['comment'] ?? ''));

            if ($eventId <= 0) {
                throw new Exception('Invalid event');
            }
            if (!in_array($decision, ['approved', 'rejected'], true)) {
                throw new Exception('Invalid decision');
            }

            $stmt = $db->prepare("UPDATE events SET quote_status=?, quote_reviewed_by=?, quote_reviewed_at=NOW(), quote_finance_comment=? WHERE id=?");
            $stmt->execute([$decision, (int)($user['id'] ?? 0), ($comment !== '' ? $comment : null), $eventId]);

            if (function_exists('db_log_activity')) {
                db_log_activity((int)($user['id'] ?? 0), 'quote_' . $decision, json_encode(['event_id' => $eventId]));
            }

            flash_add('success', 'Quotation ' . $decision . '.');
        }
    } catch (Throwable $ex) {
        flash_add('error', $ex->getMessage());
    }

    header('Location: finance_quotes.php');
    exit;
}

$events = [];
try {
    $stmt = $db->query("SELECT id, name, date, client, location, graphics, supervisors, amount, quote_file, checklist_file, mockup_file, quote_status, quote_finance_comment, created_at FROM events ORDER BY COALESCE(date, created_at) DESC, id DESC");
    $events = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable $e) {
    $events = [];
}

$pendingQuotes = array_values(array_filter($events, fn($e) => ((string)($e['quote_status'] ?? 'pending') === 'pending')));
$reviewedQuotes = array_values(array_filter($events, fn($e) => in_array((string)($e['quote_status'] ?? 'pending'), ['approved', 'rejected'], true)));

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
foreach ($pendingQuotes as $e) {
    $supervisorIds = array_merge($supervisorIds, $parse_id_list($e['supervisors'] ?? null));
}
foreach ($reviewedQuotes as $e) {
    $supervisorIds = array_merge($supervisorIds, $parse_id_list($e['supervisors'] ?? null));
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
    <?php $activePage = 'finance_quotes.php'; include __DIR__ . '/sidebar.php'; ?>

    <main class="main-content">
        <div class="top-bar">
            <div class="page-title">
                <h1><?php echo htmlspecialchars($pageTitle); ?></h1>
                <p>Review and approve/reject quotations</p>
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
                <h2 class="section-title">Pending Quotations</h2>
            </div>
            <div class="table-container">
                <table class="data-table">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Event</th>
                        <th>Client</th>
                        <th>Graphics</th>
                        <th>Supervisor</th>
                        <th>Amount</th>
                        <th>Quote</th>
                        <th class="text-center">Details</th>
                        <th>Decision</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php $rowNum = 1; foreach ($pendingQuotes as $e): ?>
                        <tr>
                            <td><?php echo (int)$rowNum; ?></td>
                            <td><?php echo htmlspecialchars($e['name'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($e['client'] ?? ''); ?></td>
                            <td><?php echo !empty($e['graphics']) ? htmlspecialchars((string)$e['graphics']) : '<span class="text-slate-400">-</span>'; ?></td>
                            <td><?php echo $format_supervisors($e['supervisors'] ?? null); ?></td>
                            <td><?php echo htmlspecialchars(number_format((float)($e['amount'] ?? 0), 2)); ?></td>
                            <td>
                                <?php if (!empty($e['quote_file'])): ?>
                                    <a class="link-highlight" target="_blank" href="<?php echo htmlspecialchars((string)$e['quote_file']); ?>">View</a>
                                <?php else: ?>
                                    <span class="text-slate-400">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <a href="events.php?id=<?php echo (int)$e['id']; ?>" title="View Event Details" style="display:inline-flex; align-items:center; justify-content:center; padding:0.25rem; background:transparent; border:none; text-decoration:none;">
                                    <i class="fas fa-eye" style="color:#2563eb;"></i>
                                </a>
                            </td>
                            <td>
                                <form method="post" style="display:grid; gap:0.35rem;">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                    <input type="hidden" name="action" value="quote_decision">
                                    <input type="hidden" name="event_id" value="<?php echo (int)$e['id']; ?>">
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
                    <?php if (!$pendingQuotes): ?>
                        <tr><td colspan="9" style="text-align:center;color:#64748b;padding:2rem;">No pending quotations</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title">Quotation History (Approved / Rejected)</h2>
            </div>
            <div class="table-container">
                <table class="data-table">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Event</th>
                        <th>Client</th>
                        <th>Graphics</th>
                        <th>Supervisor</th>
                        <th>Amount</th>
                        <th>Quote</th>
                        <th class="text-center">Details</th>
                        <th>Status</th>
                        <th>Finance Comment</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php $rowNum2 = 1; foreach ($reviewedQuotes as $e): ?>
                        <tr>
                            <td><?php echo (int)$rowNum2; ?></td>
                            <td><?php echo htmlspecialchars($e['name'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($e['client'] ?? ''); ?></td>
                            <td><?php echo !empty($e['graphics']) ? htmlspecialchars((string)$e['graphics']) : '<span class="text-slate-400">-</span>'; ?></td>
                            <td><?php echo $format_supervisors($e['supervisors'] ?? null); ?></td>
                            <td><?php echo htmlspecialchars(number_format((float)($e['amount'] ?? 0), 2)); ?></td>
                            <td>
                                <?php if (!empty($e['quote_file'])): ?>
                                    <a class="link-highlight" target="_blank" href="<?php echo htmlspecialchars((string)$e['quote_file']); ?>">View</a>
                                <?php else: ?>
                                    <span class="text-slate-400">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <a href="events.php?id=<?php echo (int)$e['id']; ?>" title="View Event Details" style="display:inline-flex; align-items:center; justify-content:center; padding:0.25rem; background:transparent; border:none; text-decoration:none;">
                                    <i class="fas fa-eye" style="color:#2563eb;"></i>
                                </a>
                            </td>
                            <td><?php echo htmlspecialchars((string)($e['quote_status'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string)($e['quote_finance_comment'] ?? '')); ?></td>
                        </tr>
                    <?php $rowNum2++; endforeach; ?>
                    <?php if (!$reviewedQuotes): ?>
                        <tr><td colspan="10" style="text-align:center;color:#64748b;padding:2rem;">No reviewed quotations</td></tr>
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
