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

$canPrepareRo = in_array($role, ['accountant', 'admin', 'super'], true);
$canViewApprovedRo = in_array($role, ['finance', 'store', 'operation', 'accountant', 'admin', 'super'], true);
$canDeleteRo = in_array($role, ['admin', 'super'], true);

if (!$canViewApprovedRo) {
    header('Location: index.php');
    exit;
}

$pageTitle = 'Release Orders';
$displayName = $user['name'] ?? $user['email'] ?? 'User';

$isPrintView = isset($_GET['view']) && (string)($_GET['view'] ?? '') === 'print';
$viewRoId = (int)($_GET['ro_id'] ?? 0);

if ($isPrintView && $viewRoId > 0) {
    try {
        $st = $db->prepare("SELECT ro.*, e.name AS event_name, e.client AS event_client, e.date AS event_date, e.coordinator, e.supervisor
                            FROM release_orders ro
                            LEFT JOIN events e ON ro.event_id = e.id
                            WHERE ro.id = ?");
        $st->execute([$viewRoId]);
        $ro = $st->fetch(PDO::FETCH_ASSOC);
        if (!$ro) {
            throw new Exception('Release Order not found');
        }

        if (in_array($role, ['store', 'operation'], true) && (string)($ro['status'] ?? '') !== 'approved') {
            throw new Exception('Release Order not found');
        }

        $items = [];
        try {
            $it = $db->prepare('SELECT * FROM release_order_items WHERE release_order_id = ? ORDER BY COALESCE(item_no, id) ASC');
            $it->execute([$viewRoId]);
            $items = $it->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $items = [];
        }

        $maxLines = 20;
        $padCount = max(0, $maxLines - count($items));
    } catch (Throwable $ex) {
        http_response_code(404);
        echo htmlspecialchars($ex->getMessage());
        exit;
    }

    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Release Order #<?php echo (int)$viewRoId; ?></title>
        <link rel="stylesheet" href="style.css">
        <style>
            @media print {
                .no-print { display:none !important; }
                body { background:#fff !important; }
            }
            body { background:#fff; }
            .ro-page { max-width: 1000px; margin: 0 auto; padding: 24px; }
            .ro-header { display:flex; gap: 16px; justify-content:space-between; align-items:flex-start; }
            .ro-title { font-size: 20px; font-weight: 700; letter-spacing: 0.5px; }
            .ro-meta { font-size: 12px; color: #334155; }
            .ro-box { border: 1px solid #111827; padding: 10px 12px; }
            table.ro-table { width:100%; border-collapse: collapse; }
            table.ro-table th, table.ro-table td { border: 1px solid #111827; padding: 6px 8px; font-size: 12px; }
            table.ro-table th { background: #f1f5f9; text-align:left; }
            .ro-grid { display:grid; grid-template-columns: 1fr 1fr 1fr; gap: 8px; margin-top: 10px; }
            .ro-label { font-weight: 700; }
        </style>
    </head>
    <body>
    <div class="ro-page">
        <div class="no-print" style="display:flex; justify-content:space-between; margin-bottom:12px;">
            <a class="btn btn-secondary" href="release_orders.php">Back</a>
            <button class="btn btn-primary" onclick="window.print()">Print</button>
        </div>

        <div class="ro-header">
            <div>
                <div class="ro-title">RELEASE ORDER</div>
                <div class="ro-meta">Prepared by system</div>
            </div>
            <div class="ro-box" style="min-width:220px;">
                <div class="ro-meta"><span class="ro-label">Date:</span> <?php echo htmlspecialchars((string)($ro['ro_date'] ?? ($ro['event_date'] ?? ''))); ?></div>
                <div class="ro-meta"><span class="ro-label">RO #:</span> <?php echo (int)$viewRoId; ?></div>
            </div>
        </div>

        <div class="ro-grid">
            <div class="ro-box"><span class="ro-label">Client Name:</span> <?php echo htmlspecialchars((string)($ro['event_client'] ?? '')); ?></div>
            <div class="ro-box"><span class="ro-label">Event Name:</span> <?php echo htmlspecialchars((string)($ro['event_name'] ?? '')); ?></div>
            <div class="ro-box"><span class="ro-label">Coordinator:</span> <?php echo htmlspecialchars((string)($ro['coordinator'] ?? '')); ?></div>
            <div class="ro-box"><span class="ro-label">Supervisor:</span> <?php echo htmlspecialchars((string)($ro['supervisor'] ?? '')); ?></div>
            <div class="ro-box"><span class="ro-label">From:</span> <?php echo htmlspecialchars((string)($ro['ro_from'] ?? '')); ?></div>
            <div class="ro-box"><span class="ro-label">To:</span> <?php echo htmlspecialchars((string)($ro['ro_to'] ?? '')); ?></div>
        </div>

        <div style="margin-top: 12px;">
            <table class="ro-table">
                <thead>
                <tr>
                    <th style="width:60px;">S/N</th>
                    <th>PARTICULARS</th>
                    <th style="width:120px;">QTY</th>
                    <th style="width:180px;">COMMENT</th>
                </tr>
                </thead>
                <tbody>
                <?php $sn = 1; foreach ($items as $it): ?>
                    <tr>
                        <td><?php echo (int)$sn; ?></td>
                        <td><?php echo htmlspecialchars((string)($it['particulars'] ?? '')); ?></td>
                        <td><?php echo htmlspecialchars((string)($it['qty'] ?? '')); ?></td>
                        <td><?php echo htmlspecialchars((string)($it['comment'] ?? '')); ?></td>
                    </tr>
                <?php $sn++; endforeach; ?>
                <?php for ($k = 0; $k < $padCount; $k++): ?>
                    <tr>
                        <td><?php echo (int)$sn; ?></td>
                        <td>&nbsp;</td>
                        <td>&nbsp;</td>
                        <td>&nbsp;</td>
                    </tr>
                <?php $sn++; endfor; ?>
                </tbody>
            </table>
        </div>

        <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; margin-top: 18px;">
            <div class="ro-box" style="height:70px;"><div class="ro-label">Prepared By</div></div>
            <div class="ro-box" style="height:70px;"><div class="ro-label">Checked By</div></div>
            <div class="ro-box" style="height:70px;"><div class="ro-label">Approved By</div></div>
        </div>
    </div>
    </body>
    </html>
    <?php
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_abort();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'delete_ro') {
            if (!$canDeleteRo) {
                throw new Exception('You are not allowed to delete Release Orders');
            }

            $roId = (int)($_POST['ro_id'] ?? 0);
            if ($roId <= 0) {
                throw new Exception('Invalid Release Order');
            }

            $db->beginTransaction();
            try {
                $filePath = '';
                try {
                    $fp = $db->prepare('SELECT file_path FROM release_orders WHERE id = ?');
                    $fp->execute([$roId]);
                    $filePath = (string)($fp->fetchColumn() ?? '');
                } catch (Throwable $e) {
                    $filePath = '';
                }

                $delItems = $db->prepare('DELETE FROM release_order_items WHERE release_order_id = ?');
                $delItems->execute([$roId]);

                $delRo = $db->prepare('DELETE FROM release_orders WHERE id = ?');
                $delRo->execute([$roId]);

                if ($filePath !== '') {
                    $disk = __DIR__ . '/' . ltrim($filePath, '/');
                    if (is_file($disk)) {
                        @unlink($disk);
                    }
                }

                $db->commit();
            } catch (Throwable $e) {
                $db->rollBack();
                throw $e;
            }

            if (function_exists('db_log_activity')) {
                db_log_activity((int)($user['id'] ?? 0), 'release_order_delete', json_encode(['ro_id' => $roId]));
            }

            flash_add('success', 'Release Order deleted.');
        }

        if ($action === 'create_ro') {
            if (!$canPrepareRo) {
                throw new Exception('You are not allowed to prepare Release Orders');
            }
            $eventId = (int)($_POST['event_id'] ?? 0);
            $roFrom = trim((string)($_POST['ro_from'] ?? ''));
            $roTo = trim((string)($_POST['ro_to'] ?? ''));
            $roDate = trim((string)($_POST['ro_date'] ?? ''));
            $submitNow = ($_POST['submit_now'] ?? '') === '1';

            $particulars = $_POST['particulars'] ?? [];
            $qty = $_POST['qty'] ?? [];
            $comment = $_POST['comment'] ?? [];

            if ($eventId <= 0) {
                throw new Exception('Invalid event');
            }

            $dateVal = null;
            if ($roDate !== '') {
                $ts = strtotime($roDate);
                if ($ts === false) {
                    throw new Exception('Invalid RO date');
                }
                $dateVal = date('Y-m-d', $ts);
            }

            $status = $submitNow ? 'submitted' : 'draft';
            $submittedAt = $submitNow ? date('Y-m-d H:i:s') : null;

            $stmt = $db->prepare("INSERT INTO release_orders(event_id, uploaded_by, file_path, ro_from, ro_to, ro_date, status, submitted_at) VALUES (?,?,?,?,?,?,?,?)");
            $stmt->execute([$eventId, (int)($user['id'] ?? 0), null, ($roFrom !== '' ? $roFrom : null), ($roTo !== '' ? $roTo : null), $dateVal, $status, $submittedAt]);
            $roId = (int)$db->lastInsertId();

            $rows = max(count($particulars), count($qty), count($comment));
            $insItem = $db->prepare('INSERT INTO release_order_items(release_order_id, item_no, particulars, qty, comment) VALUES (?,?,?,?,?)');
            $lineNo = 1;
            for ($i = 0; $i < $rows; $i++) {
                $p = trim((string)($particulars[$i] ?? ''));
                $q = trim((string)($qty[$i] ?? ''));
                $c = trim((string)($comment[$i] ?? ''));
                if ($p === '' && $q === '' && $c === '') {
                    continue;
                }
                $insItem->execute([$roId, $lineNo, ($p !== '' ? $p : null), ($q !== '' ? $q : null), ($c !== '' ? $c : null)]);
                $lineNo++;
            }

            if (function_exists('db_log_activity')) {
                db_log_activity((int)($user['id'] ?? 0), 'release_order_create', json_encode(['event_id' => $eventId, 'ro_id' => $roId]));
            }
            flash_add('success', $submitNow ? 'Release Order submitted to Finance.' : 'Release Order saved as draft.');
        }

        if ($action === 'submit_ro') {
            if (!$canPrepareRo) {
                throw new Exception('You are not allowed to submit Release Orders');
            }
            $roId = (int)($_POST['ro_id'] ?? 0);
            if ($roId <= 0) {
                throw new Exception('Invalid Release Order');
            }

            $st = $db->prepare('SELECT id, uploaded_by, status FROM release_orders WHERE id=?');
            $st->execute([$roId]);
            $ro = $st->fetch(PDO::FETCH_ASSOC);
            if (!$ro) {
                throw new Exception('Release Order not found');
            }

            $canSubmit = ((int)($ro['uploaded_by'] ?? 0) === (int)($user['id'] ?? 0)) || in_array($role, ['admin','super'], true);
            if (!$canSubmit) {
                throw new Exception('You cannot submit this Release Order');
            }
            if (($ro['status'] ?? '') !== 'draft') {
                throw new Exception('Only draft Release Orders can be submitted');
            }

            $upd = $db->prepare("UPDATE release_orders SET status='submitted', submitted_at=NOW() WHERE id=?");
            $upd->execute([$roId]);

            if (function_exists('db_log_activity')) {
                db_log_activity((int)($user['id'] ?? 0), 'release_order_submit', json_encode(['ro_id' => $roId]));
            }
            flash_add('success', 'Release Order submitted to Finance.');
        }
    } catch (Throwable $ex) {
        flash_add('error', $ex->getMessage());
    }

    header('Location: release_orders.php');
    exit;
}

$events = [];
try {
    $stmt = $db->query("SELECT id, name, client, date, supervisor, created_at FROM events ORDER BY COALESCE(date, created_at) DESC, id DESC");
    $events = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable $e) {
    $events = [];
}

$releaseOrders = [];
try {
    if ($canPrepareRo) {
        $stmt = $db->prepare("SELECT ro.*, e.name AS event_name, e.client AS event_client FROM release_orders ro LEFT JOIN events e ON ro.event_id = e.id WHERE ro.uploaded_by = ? OR ? IN ('admin','super') ORDER BY ro.created_at DESC, ro.id DESC");
        $stmt->execute([(int)($user['id'] ?? 0), $role]);
        $releaseOrders = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
        $stmt = $db->query("SELECT ro.*, e.name AS event_name, e.client AS event_client FROM release_orders ro LEFT JOIN events e ON ro.event_id = e.id WHERE ro.status='approved' ORDER BY ro.reviewed_at DESC, ro.id DESC");
        $releaseOrders = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }
} catch (Throwable $e) {
    $releaseOrders = [];
}

$flashes = flash_consume();
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
    <?php $activePage = 'release_orders.php'; include __DIR__ . '/sidebar.php'; ?>

    <main class="main-content">
        <div class="top-bar">
            <div class="page-title">
                <h1><?php echo htmlspecialchars($pageTitle); ?></h1>
                <p>Prepare Release Orders, submit to Finance, and track approvals</p>
            </div>
            <div class="user-menu">
                <div class="date-display">
                    <i class="far fa-calendar-alt mr-2"></i>
                    <?php echo date('l, F j, Y'); ?>
                </div>

                <div class="user-profile" id="userProfile">
                    <div class="avatar">
                        <?php
                            $initial = strtoupper(mb_substr($displayName, 0, 1));
                            echo htmlspecialchars($initial);
                        ?>
                    </div>
                    <div>
                        <div style="font-weight: 600;"><?php echo htmlspecialchars($displayName); ?></div>
                        <div style="font-size: 0.75rem; color: #64748b;"><?php echo htmlspecialchars(get_role_label($user['role'] ?? 'user')); ?></div>
                    </div>
                    <i class="fas fa-chevron-down ml-2" style="color: #64748b;"></i>

                    <div class="profile-dropdown" id="profileDropdown">
                        <a href="profile.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"><i class="fas fa-user mr-2"></i> My Profile</a>
                        <a href="settings.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"><i class="fas fa-cog mr-2"></i> Settings</a>
                        <a href="?logout" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"><i class="fas fa-sign-out-alt mr-2"></i> Logout</a>
                    </div>
                </div>
            </div>
        </div>

        <?php foreach ($flashes as $f): ?>
            <div class="alert <?php echo $f['type'] === 'success' ? 'alert-success' : 'alert-error'; ?>">
                <?php echo htmlspecialchars($f['message']); ?>
            </div>
        <?php endforeach; ?>

        <?php if ($canPrepareRo): ?>
        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title">Prepare Release Order</h2>
            </div>
            <div class="form-grid">
                <div>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                        <input type="hidden" name="action" value="create_ro">

                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem;">
                        <div class="form-group">
                            <label class="form-label">Event *</label>
                            <select name="event_id" id="roEventId" class="form-select" required>
                                <option value="">Select event</option>
                                <?php foreach ($events as $e): ?>
                                    <option value="<?php echo (int)$e['id']; ?>"
                                            data-event-name="<?php echo htmlspecialchars((string)($e['name'] ?? '')); ?>"
                                            data-event-date="<?php echo htmlspecialchars((string)($e['date'] ?? '')); ?>"
                                            data-event-supervisor="<?php echo htmlspecialchars((string)($e['supervisor'] ?? '')); ?>">
                                        <?php echo htmlspecialchars((string)($e['name'] ?? '')); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">User Department</label>
                            <input type="text" class="form-input" value="<?php echo htmlspecialchars(strtoupper((in_array($role, ['operation','accountant'], true) ? $role : 'accountant'))); ?>" readonly>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Event Name</label>
                            <input type="text" id="roEventName" class="form-input" value="" readonly>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Event Date</label>
                            <input type="text" id="roEventDate" class="form-input" value="" readonly>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Prepared By</label>
                            <input type="text" class="form-input" value="<?php echo htmlspecialchars($displayName); ?>" readonly>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Supervisor</label>
                            <input type="text" id="roEventSupervisor" class="form-input" value="" placeholder="Auto from event (if any)" readonly>
                        </div>

                        <div class="form-group">
                            <label class="form-label">RO Date</label>
                            <input type="date" name="ro_date" class="form-input" value="<?php echo htmlspecialchars(date('Y-m-d')); ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label">From</label>
                            <input type="text" name="ro_from" class="form-input" placeholder="e.g. STORE">
                        </div>

                        <div class="form-group">
                            <label class="form-label">To</label>
                            <input type="text" name="ro_to" class="form-input" placeholder="e.g. Operations / Supplier">
                        </div>
                    </div>

                    <div style="margin-top: 0.25rem;">
                        <div class="section-header" style="margin-bottom: 0.75rem; padding-bottom: 0.75rem;">
                            <h3 class="section-title" style="margin: 0;">Particulars / Items</h3>
                            <div class="section-actions">
                                <button type="button" id="addRoItemRow" class="btn btn-primary"><i class="fas fa-plus mr-1"></i> Add Row</button>
                            </div>
                        </div>

                        <div class="overflow-x-auto" style="border:1px solid #e5e7eb;border-radius:12px;">
                            <table class="w-full">
                                <thead>
                                <tr class="border-b border-gray-200" style="background:#f1f5f9;">
                                    <th class="text-left py-3 px-4 text-gray-600 font-medium" style="width:70px;">S/N</th>
                                    <th class="text-left py-3 px-4 text-gray-600 font-medium">Particulars</th>
                                    <th class="text-left py-3 px-4 text-gray-600 font-medium" style="width:140px;">Qty</th>
                                    <th class="text-left py-3 px-4 text-gray-600 font-medium" style="width:220px;">Comment</th>
                                    <th class="text-right py-3 px-4 text-gray-600 font-medium" style="width:70px;">&nbsp;</th>
                                </tr>
                                </thead>
                                <tbody id="roItemsBody">
                                    <tr class="border-b border-gray-100 ro-item-row">
                                        <td class="py-3 px-4 text-gray-700 ro-no">1</td>
                                        <td class="py-3 px-4"><input type="text" name="particulars[]" class="form-input" placeholder="Item"></td>
                                        <td class="py-3 px-4"><input type="text" name="qty[]" class="form-input" placeholder="e.g. 200pcs"></td>
                                        <td class="py-3 px-4"><input type="text" name="comment[]" class="form-input" placeholder="Optional"></td>
                                        <td class="py-3 px-4 text-right"><button type="button" class="btn btn-sm btn-secondary remove-ro-row" disabled><i class="fas fa-minus"></i></button></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                        <div class="flex gap-2" style="margin-top: 1rem; flex-wrap: wrap;">
                            <button type="submit" name="submit_now" value="0" class="btn btn-secondary">Save Draft</button>
                            <button type="submit" name="submit_now" value="1" class="btn btn-primary">Submit to Finance</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title">Release Orders</h2>
            </div>
            <div class="table-container">
                <table class="data-table">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Event</th>
                        <th>Status</th>
                        <th>RO</th>
                        <th>Finance Comment</th>
                        <th>Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php $rowNum = 1; foreach ($releaseOrders as $ro): ?>
                        <tr>
                            <td><?php echo (int)$rowNum; ?></td>
                            <td><?php echo htmlspecialchars((string)($ro['event_name'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars($ro['status'] ?? ''); ?></td>
                            <td>
                                <a class="link-highlight" target="_blank" href="release_orders.php?view=print&amp;ro_id=<?php echo (int)$ro['id']; ?>">View</a>
                            </td>
                            <td><?php echo htmlspecialchars($ro['finance_comment'] ?? ''); ?></td>
                            <td>
                                <div style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap;">
                                    <a style="padding:0.15rem 0.25rem; background:transparent; border:none; box-shadow:none; text-decoration:none;" title="View" target="_blank" href="release_orders.php?view=print&amp;ro_id=<?php echo (int)$ro['id']; ?>">
                                        <i class="fas fa-eye" style="color:#0f172a;"></i>
                                    </a>

                                    <?php if ($canDeleteRo): ?>
                                        <form method="post" style="display:inline;" onsubmit="return confirm('Delete this Release Order?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                            <input type="hidden" name="action" value="delete_ro">
                                            <input type="hidden" name="ro_id" value="<?php echo (int)$ro['id']; ?>">
                                            <button type="submit" style="padding:0.15rem 0.25rem; background:transparent; border:none; box-shadow:none; cursor:pointer;" title="Delete">
                                                <i class="fas fa-trash" style="color:#dc2626;"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($canPrepareRo && ($ro['status'] ?? '') === 'draft'): ?>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                            <input type="hidden" name="action" value="submit_ro">
                                            <input type="hidden" name="ro_id" value="<?php echo (int)$ro['id']; ?>">
                                            <button type="submit" style="padding:0.15rem 0.25rem; background:transparent; border:none; box-shadow:none; cursor:pointer;" title="Submit">
                                                <i class="fas fa-paper-plane" style="color:#0f172a;"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php $rowNum++; endforeach; ?>
                    <?php if (!$releaseOrders): ?>
                        <tr><td colspan="6" style="text-align:center;color:#64748b;padding:2rem;">No release orders found</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        var itemsBody = document.getElementById('roItemsBody');
        var addBtn = document.getElementById('addRoItemRow');

        var eventSelect = document.getElementById('roEventId');
        var eventName = document.getElementById('roEventName');
        var eventDate = document.getElementById('roEventDate');
        var eventSupervisor = document.getElementById('roEventSupervisor');

        var userProfile = document.getElementById('userProfile');
        var profileDropdown = document.getElementById('profileDropdown');

        function updateEventFields() {
            if (!eventSelect) return;
            var opt = eventSelect.options[eventSelect.selectedIndex];
            var name = opt ? (opt.getAttribute('data-event-name') || '') : '';
            var date = opt ? (opt.getAttribute('data-event-date') || '') : '';
            var supervisor = opt ? (opt.getAttribute('data-event-supervisor') || '') : '';
            if (eventName) eventName.value = name;
            if (eventDate) eventDate.value = date;
            if (eventSupervisor) eventSupervisor.value = supervisor;
        }

        if (eventSelect) {
            eventSelect.addEventListener('change', updateEventFields);
            updateEventFields();
        }

        function renumber() {
            if (!itemsBody) return;
            var rows = Array.from(itemsBody.querySelectorAll('tr.ro-item-row'));
            rows.forEach(function(row, idx) {
                var noEl = row.querySelector('.ro-no');
                if (noEl) noEl.textContent = String(idx + 1);
            });
            rows.forEach(function(row) {
                var remove = row.querySelector('button.remove-ro-row');
                if (remove) remove.disabled = rows.length <= 1;
            });
        }

        function wireRow(row) {
            if (!row) return;
            var remove = row.querySelector('button.remove-ro-row');
            if (remove) {
                remove.addEventListener('click', function() {
                    row.remove();
                    renumber();
                });
            }
        }

        if (itemsBody) {
            Array.from(itemsBody.querySelectorAll('tr.ro-item-row')).forEach(wireRow);
            renumber();
        }

        if (addBtn && itemsBody) {
            addBtn.addEventListener('click', function() {
                var template = itemsBody.querySelector('tr.ro-item-row');
                if (!template) return;
                var clone = template.cloneNode(true);
                clone.querySelectorAll('input').forEach(function(inp) {
                    inp.value = '';
                });
                itemsBody.appendChild(clone);
                wireRow(clone);
                renumber();
            });
        }

        if (userProfile && profileDropdown && !window.__releaseOrdersProfileDropdownBound) {
            window.__releaseOrdersProfileDropdownBound = true;
            userProfile.addEventListener('click', function(e) {
                e.stopPropagation();
                profileDropdown.classList.toggle('show');
            });
            document.addEventListener('click', function() {
                profileDropdown.classList.remove('show');
            });
        }
    });
</script>
</body>
</html>
