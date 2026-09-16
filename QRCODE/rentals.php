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

$canAccountant = in_array($role, ['accountant', 'admin', 'super'], true);
$canFinance = in_array($role, ['finance', 'admin', 'super'], true);
$isCashier = $role === 'cashier';

if (!$canAccountant && !$canFinance && !$isCashier) {
    header('Location: index.php');
    exit;
}

$pageTitle = 'Rentals';
$displayName = $user['name'] ?? $user['email'] ?? 'User';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_abort();
    $action = $_POST['action'] ?? '';

    try {
        if ($isCashier) {
            throw new Exception('Cashier access to rentals is read-only');
        }
        if ($action === 'create_rental') {
            if (!$canAccountant) {
                throw new Exception('You do not have permission to create rentals');
            }

            $eventId = (int)($_POST['event_id'] ?? 0);
            $title = trim((string)($_POST['title'] ?? ''));
            $submitNow = ($_POST['submit_now'] ?? '') === '1';

            $suppliers = $_POST['supplier'] ?? [];
            $items = $_POST['item'] ?? [];
            $amountExc = $_POST['amount_exc'] ?? [];
            $vatAmount = $_POST['vat_amount'] ?? [];
            $amountInc = $_POST['amount_inc'] ?? [];
            $amountPaid = $_POST['amount_paid'] ?? [];
            $balance = $_POST['balance'] ?? [];
            $remarks = $_POST['remarks'] ?? [];

            if (!is_array($suppliers) || !is_array($items) || !is_array($amountExc) || !is_array($vatAmount) || !is_array($amountInc) || !is_array($amountPaid) || !is_array($balance) || !is_array($remarks)) {
                throw new Exception('Invalid items payload');
            }

            if ($title === '') {
                throw new Exception('Title is required');
            }

            $rows = [];
            $totExc = 0.0;
            $totVat = 0.0;
            $totInc = 0.0;
            $totPaid = 0.0;
            $totBal = 0.0;

            $count = max(count($suppliers), count($items), count($amountExc), count($vatAmount), count($amountInc), count($amountPaid), count($balance), count($remarks));
            for ($i = 0; $i < $count; $i++) {
                $supplier = trim((string)($suppliers[$i] ?? ''));
                $item = trim((string)($items[$i] ?? ''));

                $exc = (float)($amountExc[$i] ?? 0);
                $vat = (float)($vatAmount[$i] ?? 0);
                $inc = (float)($amountInc[$i] ?? 0);
                $paid = (float)($amountPaid[$i] ?? 0);
                $bal = (float)($balance[$i] ?? 0);
                $rem = trim((string)($remarks[$i] ?? ''));

                if ($supplier === '' && $item === '' && $exc <= 0 && $vat <= 0 && $inc <= 0 && $paid <= 0 && $bal <= 0 && $rem === '') {
                    continue;
                }

                if ($item === '') {
                    throw new Exception('Item is required for each row');
                }

                $totExc += max(0, $exc);
                $totVat += max(0, $vat);
                $totInc += max(0, $inc);
                $totPaid += max(0, $paid);
                $totBal += max(0, $bal);

                $rows[] = [
                    'item_no' => count($rows) + 1,
                    'supplier' => ($supplier !== '' ? $supplier : null),
                    'item' => $item,
                    'amount_exc' => ($exc > 0 ? $exc : null),
                    'vat_amount' => ($vat > 0 ? $vat : null),
                    'amount_inc' => ($inc > 0 ? $inc : null),
                    'amount_paid' => ($paid > 0 ? $paid : null),
                    'balance' => ($bal > 0 ? $bal : null),
                    'remarks' => ($rem !== '' ? $rem : null),
                ];
            }

            if (!$rows) {
                throw new Exception('Please add at least one item');
            }

            if ($eventId > 0) {
                $ro = $db->prepare("SELECT id FROM release_orders WHERE event_id = ? AND status = 'approved' ORDER BY COALESCE(reviewed_at, submitted_at, created_at) DESC, id DESC LIMIT 1");
                $ro->execute([$eventId]);
                if (!(int)($ro->fetchColumn() ?? 0)) {
                    throw new Exception('Rentals require an approved Release Order for this event');
                }
            }

            $status = $submitNow ? 'submitted' : 'draft';
            $submittedAt = $submitNow ? date('Y-m-d H:i:s') : null;

            $db->beginTransaction();

            $ins = $db->prepare('INSERT INTO rental_requests(event_id, requested_by, title, amount_exc, vat_amount, amount_inc, amount_paid, balance, status, submitted_at) VALUES (?,?,?,?,?,?,?,?,?,?)');
            $ins->execute([
                $eventId > 0 ? $eventId : null,
                (int)($user['id'] ?? 0),
                $title,
                ($totExc > 0 ? $totExc : null),
                ($totVat > 0 ? $totVat : null),
                ($totInc > 0 ? $totInc : null),
                ($totPaid > 0 ? $totPaid : null),
                ($totBal > 0 ? $totBal : null),
                $status,
                $submittedAt,
            ]);

            $rentalId = (int)$db->lastInsertId();
            if ($rentalId <= 0) {
                throw new Exception('Failed to create rental request');
            }

            $itemStmt = $db->prepare('INSERT INTO rental_request_items(rental_request_id, item_no, supplier, item, amount_exc, vat_amount, amount_inc, amount_paid, balance, remarks) VALUES (?,?,?,?,?,?,?,?,?,?)');
            foreach ($rows as $r) {
                $itemStmt->execute([
                    $rentalId,
                    $r['item_no'],
                    $r['supplier'],
                    $r['item'],
                    $r['amount_exc'],
                    $r['vat_amount'],
                    $r['amount_inc'],
                    $r['amount_paid'],
                    $r['balance'],
                    $r['remarks'],
                ]);
            }

            $db->commit();

            if (function_exists('db_log_activity')) {
                db_log_activity((int)($user['id'] ?? 0), 'rental_request_create', json_encode(['event_id' => $eventId, 'status' => $status]));
            }

            flash_add('success', $submitNow ? 'Rental request submitted to Finance.' : 'Rental request saved as draft.');
        }

        if ($action === 'finance_decision') {
            if (!$canFinance) {
                throw new Exception('You do not have permission to review rentals');
            }

            $rentalId = (int)($_POST['rental_id'] ?? 0);
            $decision = (string)($_POST['decision'] ?? '');
            $comment = trim((string)($_POST['finance_comment'] ?? ''));

            if ($rentalId <= 0) {
                throw new Exception('Invalid rental request');
            }
            if (!in_array($decision, ['approved', 'rejected'], true)) {
                throw new Exception('Invalid decision');
            }

            $st = $db->prepare('SELECT status FROM rental_requests WHERE id=?');
            $st->execute([$rentalId]);
            $status = (string)($st->fetchColumn() ?? '');
            if ($status !== 'submitted') {
                throw new Exception('Only submitted rental requests can be reviewed by Finance');
            }

            $upd = $db->prepare('UPDATE rental_requests SET status=?, reviewed_by=?, reviewed_at=NOW(), finance_comment=? WHERE id=?');
            $upd->execute([$decision, (int)($user['id'] ?? 0), ($comment !== '' ? $comment : null), $rentalId]);

            if (function_exists('db_log_activity')) {
                db_log_activity((int)($user['id'] ?? 0), 'rental_request_' . $decision, json_encode(['rental_id' => $rentalId]));
            }

            flash_add('success', 'Rental request ' . $decision . '.');
        }

    } catch (Throwable $ex) {
        if ($db && $db->inTransaction()) {
            $db->rollBack();
        }
        flash_add('error', $ex->getMessage());
    }

    header('Location: rentals.php');
    exit;
}

$events = [];
try {
    $stmt = $db->query("SELECT id, name, client, date, supervisor, created_at FROM events ORDER BY COALESCE(date, created_at) DESC, id DESC");
    $events = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable $e) {
    $events = [];
}

$myRentals = [];
try {
    if ($canFinance || $isCashier || $role === 'admin' || $role === 'super') {
        $stmt = $db->query("SELECT rr.*, e.name AS event_name, e.client AS event_client FROM rental_requests rr LEFT JOIN events e ON rr.event_id = e.id ORDER BY rr.created_at DESC, rr.id DESC");
        $myRentals = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } else {
        $stmt = $db->prepare("SELECT rr.*, e.name AS event_name, e.client AS event_client FROM rental_requests rr LEFT JOIN events e ON rr.event_id = e.id WHERE rr.requested_by = ? ORDER BY rr.created_at DESC, rr.id DESC");
        $stmt->execute([(int)($user['id'] ?? 0)]);
        $myRentals = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable $e) {
    $myRentals = [];
}

$pendingForFinance = [];
if ($canFinance) {
    try {
        $stmt = $db->query("SELECT rr.*, e.name AS event_name, e.client AS event_client, u.name AS requested_by_name
                            FROM rental_requests rr
                            LEFT JOIN events e ON rr.event_id = e.id
                            LEFT JOIN users u ON rr.requested_by = u.id
                            WHERE rr.status='submitted'
                            ORDER BY rr.submitted_at DESC, rr.id DESC");
        $pendingForFinance = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
        $pendingForFinance = [];
    }
}

$viewId = (int)($_GET['rid'] ?? 0);
$viewRental = null;
$viewItems = [];
if ($viewId > 0) {
    try {
        $st = $db->prepare("SELECT rr.*, e.name AS event_name, e.client AS event_client, u.name AS requested_by_name
                            FROM rental_requests rr
                            LEFT JOIN events e ON rr.event_id = e.id
                            LEFT JOIN users u ON rr.requested_by = u.id
                            WHERE rr.id = ?");
        $st->execute([$viewId]);
        $viewRental = $st->fetch(PDO::FETCH_ASSOC);
        if ($viewRental) {
            $it = $db->prepare('SELECT * FROM rental_request_items WHERE rental_request_id = ? ORDER BY COALESCE(item_no, id) ASC');
            $it->execute([$viewId]);
            $viewItems = $it->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    } catch (Throwable $e) {
        $viewRental = null;
        $viewItems = [];
    }
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
    <style>
        #rentalItemsWrap {
            max-width: 100%;
        }

        #rentalItemsTable {
            width: 100%;
            table-layout: fixed;
        }

        #rentalItemsTable th,
        #rentalItemsTable td {
            vertical-align: top;
        }

        #rentalItemsTable th {
            font-size: 0.75rem;
            letter-spacing: 0.02em;
        }

        #rentalItemsTable td {
            padding-top: 0.5rem;
            padding-bottom: 0.5rem;
        }

        #rentalItemsTable .cell-num {
            width: 56px;
        }

        #rentalItemsTable .cell-supplier {
            width: 160px;
        }

        #rentalItemsTable .cell-item {
            width: 260px;
        }

        #rentalItemsTable .cell-money {
            width: 120px;
        }

        #rentalItemsTable .cell-remarks {
            width: 200px;
        }

        #rentalItemsTable input.form-input {
            width: 100%;
            min-width: 0;
            padding: 0.55rem 0.6rem;
            font-size: 0.9rem;
        }

        #rentalItemsTable td.wrap,
        #rentalItemsTable th.wrap {
            white-space: normal;
            overflow-wrap: anywhere;
        }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php $activePage = 'rentals.php'; include __DIR__ . '/sidebar.php'; ?>

    <main class="main-content">
        <div class="top-bar">
            <div class="page-title">
                <h1><?php echo htmlspecialchars($pageTitle); ?></h1>
                <p>Accountant submits rental requests, Finance approves</p>
            </div>
            <div class="user-menu">
                <div class="date-display">
                    <i class="far fa-calendar-alt mr-2"></i>
                    <?php echo date('l, F j, Y'); ?>
                </div>

                <div class="user-profile" id="userProfile">
                    <div class="avatar">
                        <?php echo htmlspecialchars(strtoupper(mb_substr($displayName, 0, 1))); ?>
                    </div>
                    <div>
                        <div style="font-weight: 600;"><?php echo htmlspecialchars($displayName); ?></div>
                        <div style="font-size: 0.75rem; color: #64748b;"><?php echo get_role_label($user['role'] ?? 'user'); ?></div>
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

        <?php if ($viewRental): ?>
        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title">Rental Request Details</h2>
                <div class="section-actions">
                    <a href="rentals.php" class="btn btn-secondary"><i class="fas fa-arrow-left mr-1"></i> Back</a>
                </div>
            </div>

            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 0.75rem; margin-bottom: 1rem;">
                <div class="form-group"><label class="form-label">Title</label><input class="form-input" value="<?php echo htmlspecialchars((string)($viewRental['title'] ?? '')); ?>" readonly></div>
                <div class="form-group"><label class="form-label">Event</label><input class="form-input" value="<?php echo htmlspecialchars((string)($viewRental['event_name'] ?? '')); ?>" readonly></div>
                <div class="form-group"><label class="form-label">Client</label><input class="form-input" value="<?php echo htmlspecialchars((string)($viewRental['event_client'] ?? '')); ?>" readonly></div>
                <div class="form-group"><label class="form-label">Amount INC</label><input class="form-input" value="<?php echo htmlspecialchars(number_format((float)($viewRental['amount_inc'] ?? 0), 2)); ?>" readonly></div>
                <div class="form-group"><label class="form-label">Paid</label><input class="form-input" value="<?php echo htmlspecialchars(number_format((float)($viewRental['amount_paid'] ?? 0), 2)); ?>" readonly></div>
                <div class="form-group"><label class="form-label">Balance</label><input class="form-input" value="<?php echo htmlspecialchars(number_format((float)($viewRental['balance'] ?? 0), 2)); ?>" readonly></div>
                <div class="form-group"><label class="form-label">Status</label><input class="form-input" value="<?php echo htmlspecialchars((string)($viewRental['status'] ?? '')); ?>" readonly></div>
            </div>

            <div class="table-container">
                <table class="data-table">
                    <thead>
                    <tr>
                        <th>No</th>
                        <th>Supplier</th>
                        <th>Item</th>
                        <th>Amount EXC</th>
                        <th>VAT</th>
                        <th>Amount INC</th>
                        <th>Paid</th>
                        <th>Balance</th>
                        <th>Remarks</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php $n = 1; foreach ($viewItems as $it): ?>
                        <tr>
                            <td><?php echo (int)$n; ?></td>
                            <td><?php echo htmlspecialchars((string)($it['supplier'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string)($it['item'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars(number_format((float)($it['amount_exc'] ?? 0), 2)); ?></td>
                            <td><?php echo htmlspecialchars(number_format((float)($it['vat_amount'] ?? 0), 2)); ?></td>
                            <td><?php echo htmlspecialchars(number_format((float)($it['amount_inc'] ?? 0), 2)); ?></td>
                            <td><?php echo htmlspecialchars(number_format((float)($it['amount_paid'] ?? 0), 2)); ?></td>
                            <td><?php echo htmlspecialchars(number_format((float)($it['balance'] ?? 0), 2)); ?></td>
                            <td><?php echo htmlspecialchars((string)($it['remarks'] ?? '')); ?></td>
                        </tr>
                    <?php $n++; endforeach; ?>
                    <?php if (!$viewItems): ?>
                        <tr><td colspan="9" style="text-align:center;color:#64748b;padding:2rem;">No items found</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($canFinance && (string)($viewRental['status'] ?? '') === 'submitted'): ?>
            <div style="margin-top: 1rem;">
                <form method="post" style="display:grid; gap:0.5rem; max-width: 520px;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                    <input type="hidden" name="action" value="finance_decision">
                    <input type="hidden" name="rental_id" value="<?php echo (int)$viewId; ?>">
                    <input type="text" class="form-input" name="finance_comment" placeholder="Finance comment (optional)">
                    <div style="display:flex; gap:0.5rem;">
                        <button type="submit" name="decision" value="approved" class="btn btn-primary">Approve</button>
                        <button type="submit" name="decision" value="rejected" class="btn btn-danger">Reject</button>
                    </div>
                </form>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($canFinance): ?>
        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title">Pending Rentals (Finance)</h2>
            </div>
            <div class="table-container">
                <table class="data-table">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Event</th>
                        <th>Title</th>
                        <th>Amount INC</th>
                        <th class="text-center">Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php $i = 1; foreach ($pendingForFinance as $rr): ?>
                        <tr>
                            <td><?php echo (int)$i; ?></td>
                            <td><?php echo htmlspecialchars((string)($rr['event_name'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string)($rr['title'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars(number_format((float)($rr['amount_inc'] ?? 0), 2)); ?></td>
                            <td class="text-center">
                                <a href="rentals.php?rid=<?php echo (int)($rr['id'] ?? 0); ?>" class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-blue-50 text-blue-600 hover:bg-blue-100 transition-colors duration-200" title="View Details">
                                    <i class="fas fa-eye text-sm"></i>
                                </a>
                            </td>
                        </tr>
                    <?php $i++; endforeach; ?>
                    <?php if (!$pendingForFinance): ?>
                        <tr><td colspan="5" style="text-align:center;color:#64748b;padding:2rem;">No pending rentals</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($canAccountant): ?>
        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title">Create Rental Request</h2>
            </div>

            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                <input type="hidden" name="action" value="create_rental">

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem;">
                    <div class="form-group">
                        <label class="form-label">Event (optional)</label>
                        <select name="event_id" id="rEventId" class="form-select">
                            <option value="0">No event</option>
                            <?php foreach ($events as $e): ?>
                                <option value="<?php echo (int)$e['id']; ?>"
                                        data-event-name="<?php echo htmlspecialchars((string)($e['name'] ?? '')); ?>"
                                        data-event-client="<?php echo htmlspecialchars((string)($e['client'] ?? '')); ?>">
                                    <?php echo htmlspecialchars((string)($e['name'] ?? '')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Event Name</label>
                        <input type="text" id="rEventName" class="form-input" value="" readonly>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Client</label>
                        <input type="text" id="rEventClient" class="form-input" value="" readonly>
                    </div>

                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label class="form-label">Title *</label>
                        <input type="text" name="title" class="form-input" required>
                    </div>
                </div>

                <div style="margin-top: 1rem;">
                    <div class="section-header" style="margin-bottom: 0.75rem; padding-bottom: 0.75rem;">
                        <h3 class="section-title" style="margin: 0;">Rental Items</h3>
                        <div class="section-actions">
                            <button type="button" id="addRentalRow" class="btn btn-primary"><i class="fas fa-plus mr-1"></i> Add Row</button>
                        </div>
                    </div>

                    <div id="rentalItemsWrap" class="overflow-x-auto" style="border:1px solid #e5e7eb;border-radius:12px;">
                        <table id="rentalItemsTable" class="w-full">
                            <thead>
                            <tr class="border-b border-gray-200" style="background:#f1f5f9;">
                                <th class="text-left py-3 px-4 text-gray-600 font-medium cell-num">NO</th>
                                <th class="text-left py-3 px-4 text-gray-600 font-medium cell-supplier wrap">SUPPLIER</th>
                                <th class="text-left py-3 px-4 text-gray-600 font-medium cell-item wrap">ITEM</th>
                                <th class="text-left py-3 px-4 text-gray-600 font-medium cell-money">AMOUNT EXC</th>
                                <th class="text-left py-3 px-4 text-gray-600 font-medium cell-money">VAT</th>
                                <th class="text-left py-3 px-4 text-gray-600 font-medium cell-money">AMOUNT INC</th>
                                <th class="text-left py-3 px-4 text-gray-600 font-medium cell-money">AMOUNT PAID</th>
                                <th class="text-left py-3 px-4 text-gray-600 font-medium cell-money">BALANCE</th>
                                <th class="text-left py-3 px-4 text-gray-600 font-medium cell-remarks wrap">REMARKS</th>
                                <th class="text-right py-3 px-4 text-gray-600 font-medium" style="width:56px;">&nbsp;</th>
                            </tr>
                            </thead>
                            <tbody id="rentalBody">
                            <tr class="border-b border-gray-100 rental-row">
                                <td class="py-3 px-4 text-gray-700 row-no">1</td>
                                <td class="py-3 px-4 wrap"><input type="text" name="supplier[]" class="form-input" placeholder="Supplier"></td>
                                <td class="py-3 px-4 wrap"><input type="text" name="item[]" class="form-input" placeholder="Item" required></td>
                                <td class="py-3 px-4"><input type="number" step="0.01" min="0" name="amount_exc[]" class="form-input rex" placeholder="0.00"></td>
                                <td class="py-3 px-4"><input type="number" step="0.01" min="0" name="vat_amount[]" class="form-input rvat" placeholder="0.00"></td>
                                <td class="py-3 px-4"><input type="number" step="0.01" min="0" name="amount_inc[]" class="form-input rinc" placeholder="0.00"></td>
                                <td class="py-3 px-4"><input type="number" step="0.01" min="0" name="amount_paid[]" class="form-input rpaid" placeholder="0.00"></td>
                                <td class="py-3 px-4"><input type="number" step="0.01" min="0" name="balance[]" class="form-input rbal" placeholder="0.00"></td>
                                <td class="py-3 px-4 wrap"><input type="text" name="remarks[]" class="form-input" placeholder="Remarks"></td>
                                <td class="py-3 px-4 text-right"><button type="button" class="btn btn-sm btn-secondary remove-rental" disabled><i class="fas fa-minus"></i></button></td>
                            </tr>
                            </tbody>
                            <tfoot>
                            <tr style="background:#f8fafc;">
                                <td class="py-3 px-4 font-semibold text-gray-700" colspan="3" style="text-align:right;">TOTAL</td>
                                <td class="py-3 px-4"><input type="text" id="rTotExc" class="form-input" value="0.00" readonly></td>
                                <td class="py-3 px-4"><input type="text" id="rTotVat" class="form-input" value="0.00" readonly></td>
                                <td class="py-3 px-4"><input type="text" id="rTotInc" class="form-input" value="0.00" readonly></td>
                                <td class="py-3 px-4"><input type="text" id="rTotPaid" class="form-input" value="0.00" readonly></td>
                                <td class="py-3 px-4"><input type="text" id="rTotBal" class="form-input" value="0.00" readonly></td>
                                <td class="py-3 px-4" colspan="2"></td>
                            </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <div class="flex gap-2" style="margin-top: 1rem; flex-wrap: wrap;">
                    <button type="submit" name="submit_now" value="0" class="btn btn-secondary">Save Draft</button>
                    <button type="submit" name="submit_now" value="1" class="btn btn-primary">Submit to Finance</button>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title">Rental Requests</h2>
            </div>
            <div class="table-container">
                <table class="data-table">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Event</th>
                        <th>Title</th>
                        <th>Amount INC</th>
                        <th>Status</th>
                        <th>Finance Comment</th>
                        <th class="text-center">Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php $i = 1; foreach ($myRentals as $rr): ?>
                        <tr>
                            <td><?php echo (int)$i; ?></td>
                            <td><?php echo htmlspecialchars((string)($rr['event_name'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string)($rr['title'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars(number_format((float)($rr['amount_inc'] ?? 0), 2)); ?></td>
                            <td><?php echo htmlspecialchars((string)($rr['status'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string)($rr['finance_comment'] ?? '')); ?></td>
                            <td class="text-center">
                                <a href="rentals.php?rid=<?php echo (int)($rr['id'] ?? 0); ?>" class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-blue-50 text-blue-600 hover:bg-blue-100 transition-colors duration-200" title="View Details">
                                    <i class="fas fa-eye text-sm"></i>
                                </a>
                            </td>
                        </tr>
                    <?php $i++; endforeach; ?>
                    <?php if (!$myRentals): ?>
                        <tr><td colspan="7" style="text-align:center;color:#64748b;padding:2rem;">No rental requests found</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var eventSelect = document.getElementById('rEventId');
    var eventName = document.getElementById('rEventName');
    var eventClient = document.getElementById('rEventClient');

    function updateEventFields() {
        if (!eventSelect) return;
        var opt = eventSelect.options[eventSelect.selectedIndex];
        var name = opt ? (opt.getAttribute('data-event-name') || '') : '';
        var client = opt ? (opt.getAttribute('data-event-client') || '') : '';
        if (eventName) eventName.value = name;
        if (eventClient) eventClient.value = client;
    }

    if (eventSelect) {
        eventSelect.addEventListener('change', updateEventFields);
        updateEventFields();
    }

    var body = document.getElementById('rentalBody');
    var addBtn = document.getElementById('addRentalRow');

    var totExc = document.getElementById('rTotExc');
    var totVat = document.getElementById('rTotVat');
    var totInc = document.getElementById('rTotInc');
    var totPaid = document.getElementById('rTotPaid');
    var totBal = document.getElementById('rTotBal');

    function recalc() {
        if (!body) return;
        var rows = Array.from(body.querySelectorAll('tr.rental-row'));
        var sExc = 0, sVat = 0, sInc = 0, sPaid = 0, sBal = 0;

        rows.forEach(function (row, idx) {
            var noEl = row.querySelector('.row-no');
            if (noEl) noEl.textContent = String(idx + 1);

            var excEl = row.querySelector('input.rex');
            var vatEl = row.querySelector('input.rvat');
            var incEl = row.querySelector('input.rinc');
            var paidEl = row.querySelector('input.rpaid');
            var balEl = row.querySelector('input.rbal');

            var exc = parseFloat(excEl ? excEl.value : '0') || 0;
            var vat = parseFloat(vatEl ? vatEl.value : '0') || 0;
            var inc = parseFloat(incEl ? incEl.value : '0') || 0;
            var paid = parseFloat(paidEl ? paidEl.value : '0') || 0;
            var bal = parseFloat(balEl ? balEl.value : '0') || 0;

            sExc += exc;
            sVat += vat;
            sInc += inc;
            sPaid += paid;
            sBal += bal;
        });

        if (totExc) totExc.value = sExc.toFixed(2);
        if (totVat) totVat.value = sVat.toFixed(2);
        if (totInc) totInc.value = sInc.toFixed(2);
        if (totPaid) totPaid.value = sPaid.toFixed(2);
        if (totBal) totBal.value = sBal.toFixed(2);

        rows.forEach(function (row) {
            var remove = row.querySelector('button.remove-rental');
            if (remove) remove.disabled = rows.length <= 1;
        });
    }

    function wireRow(row) {
        if (!row) return;
        row.querySelectorAll('input').forEach(function (inp) {
            if (inp.classList.contains('rex') || inp.classList.contains('rvat') || inp.classList.contains('rinc') || inp.classList.contains('rpaid') || inp.classList.contains('rbal')) {
                inp.addEventListener('input', recalc);
            }

            inp.addEventListener('input', function () {
                try { inp.title = inp.value; } catch (e) {}
            });
            inp.addEventListener('change', function () {
                try { inp.title = inp.value; } catch (e) {}
            });
        });

        var remove = row.querySelector('button.remove-rental');
        if (remove) {
            remove.addEventListener('click', function () {
                row.remove();
                recalc();
            });
        }
    }

    if (body) {
        Array.from(body.querySelectorAll('tr.rental-row')).forEach(wireRow);
        recalc();
    }

    if (addBtn && body) {
        addBtn.addEventListener('click', function () {
            var template = body.querySelector('tr.rental-row');
            if (!template) return;
            var clone = template.cloneNode(true);
            clone.querySelectorAll('input').forEach(function (inp) {
                inp.value = '';
            });
            body.appendChild(clone);
            wireRow(clone);
            recalc();
        });
    }

    var userProfile = document.getElementById('userProfile');
    var profileDropdown = document.getElementById('profileDropdown');
    if (userProfile && profileDropdown) {
        userProfile.addEventListener('click', function (e) {
            e.stopPropagation();
            profileDropdown.classList.toggle('show');
        });
        document.addEventListener('click', function () {
            profileDropdown.classList.remove('show');
        });
    }
});
</script>

</body>
</html>
