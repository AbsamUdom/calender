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

if (!in_array($role, ['finance', 'store', 'production', 'operation', 'accountant', 'admin', 'super'], true)) {
    header('Location: index.php');
    exit;
}

$pageTitle = 'Purchase Requests';
$displayName = $user['name'] ?? $user['email'] ?? 'User';

$isRequester = in_array($role, ['store', 'production', 'operation'], true);
$isAccountant = in_array($role, ['accountant', 'admin', 'super'], true);

$editPurchaseRequestId = (int)($_GET['edit_id'] ?? 0);
$editPurchaseRequest = null;
$editPurchaseItems = [];
if ($editPurchaseRequestId > 0) {
    try {
        $st = $db->prepare("SELECT * FROM purchase_requests WHERE id = ?");
        $st->execute([$editPurchaseRequestId]);
        $editPurchaseRequest = $st->fetch(PDO::FETCH_ASSOC);
        if ($editPurchaseRequest) {
            $requestedBy = (int)($editPurchaseRequest['requested_by'] ?? 0);
            $status = (string)($editPurchaseRequest['status'] ?? '');
            $canEditThis = ($requestedBy > 0 && $requestedBy === (int)($user['id'] ?? 0)) || in_array($role, ['admin', 'super'], true);
            if (!$canEditThis || $status !== 'rejected') {
                $editPurchaseRequest = null;
            } else {
                $it = $db->prepare('SELECT * FROM purchase_request_items WHERE purchase_request_id = ? ORDER BY COALESCE(item_no, id) ASC');
                $it->execute([$editPurchaseRequestId]);
                $editPurchaseItems = $it->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }
        }
    } catch (Throwable $e) {
        $editPurchaseRequest = null;
        $editPurchaseItems = [];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_abort();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create_purchase') {
            if ($role === 'finance') {
                throw new Exception('You do not have permission to create purchase requests');
            }
            $eventId = (int)($_POST['event_id'] ?? 0);
            $title = trim((string)($_POST['title'] ?? ''));
            $submitNow = ($_POST['submit_now'] ?? '') === '1';

            $allowPricing = !$isRequester;

            $materials = $_POST['material'] ?? [];
            $colors = $_POST['color'] ?? [];
            $sizes = $_POST['size'] ?? [];
            $quantities = $_POST['quantity'] ?? [];
            $unitPrices = $allowPricing ? ($_POST['unit_price'] ?? []) : [];
            $suppliers = $_POST['supplier'] ?? [];

            if (!is_array($materials) || !is_array($colors) || !is_array($sizes) || !is_array($quantities) || !is_array($suppliers) || ($allowPricing && !is_array($unitPrices))) {
                throw new Exception('Invalid items payload');
            }

            if ($title === '') {
                throw new Exception('Title is required');
            }

            $items = [];
            $grandTotal = 0.0;
            $count = max(count($materials), count($colors), count($sizes), count($quantities), ($allowPricing ? count($unitPrices) : 0), count($suppliers));
            for ($i = 0; $i < $count; $i++) {
                $material = trim((string)($materials[$i] ?? ''));
                $color = trim((string)($colors[$i] ?? ''));
                $size = trim((string)($sizes[$i] ?? ''));
                $supplier = $isRequester ? '' : trim((string)($suppliers[$i] ?? ''));
                $qty = (float)($quantities[$i] ?? 0);

                $unit = 0.0;
                if ($allowPricing) {
                    $unit = (float)($unitPrices[$i] ?? 0);
                }

                if ($material === '' && $supplier === '' && $qty <= 0 && (!$allowPricing || $unit <= 0)) {
                    continue;
                }

                if ($material === '') {
                    throw new Exception('Material is required for each item row');
                }

                $lineTotal = $allowPricing ? (max(0, $qty) * max(0, $unit)) : 0.0;
                if ($allowPricing) {
                    $grandTotal += $lineTotal;
                }

                $items[] = [
                    'item_no' => count($items) + 1,
                    'material' => $material,
                    'color' => $color !== '' ? $color : null,
                    'size' => $size !== '' ? $size : null,
                    'quantity' => $qty > 0 ? $qty : null,
                    'unit_price' => ($allowPricing && $unit > 0) ? $unit : null,
                    'line_total' => ($allowPricing && $lineTotal > 0) ? $lineTotal : null,
                    'supplier' => $supplier !== '' ? $supplier : null,
                ];
            }

            if (!$items) {
                throw new Exception('Please add at least one item');
            }

            if ($eventId > 0) {
                $ro = $db->prepare("SELECT id FROM release_orders WHERE event_id = ? AND status = 'approved' ORDER BY COALESCE(reviewed_at, submitted_at, created_at) DESC, id DESC LIMIT 1");
                $ro->execute([$eventId]);
                if (!(int)($ro->fetchColumn() ?? 0)) {
                    throw new Exception('Purchase requests require an approved Release Order for this event');
                }
            }

            $status = $submitNow ? ($isRequester ? 'acct_submitted' : 'finance_submitted') : 'draft';
            $submittedAt = $submitNow ? date('Y-m-d H:i:s') : null;

            $db->beginTransaction();

            $ins = $db->prepare('INSERT INTO purchase_requests(event_id, requested_by, title, amount, description, status, submitted_at, department) VALUES (?,?,?,?,?,?,?,?)');
            $ins->execute([
                $eventId > 0 ? $eventId : null,
                (int)($user['id'] ?? 0),
                $title,
                ($allowPricing && $grandTotal > 0) ? $grandTotal : null,
                null,
                $status,
                $submittedAt,
                (in_array($role, ['store','production','operation','accountant'], true) ? $role : 'accountant')
            ]);

            $purchaseRequestId = (int)$db->lastInsertId();
            if ($purchaseRequestId <= 0) {
                throw new Exception('Failed to create purchase request');
            }

            $itemStmt = $db->prepare('INSERT INTO purchase_request_items(purchase_request_id, item_no, material, color, size, quantity, unit_price, line_total, supplier) VALUES (?,?,?,?,?,?,?,?,?)');
            foreach ($items as $it) {
                $itemStmt->execute([
                    $purchaseRequestId,
                    $it['item_no'],
                    $it['material'],
                    $it['color'],
                    $it['size'],
                    $it['quantity'],
                    $it['unit_price'],
                    $it['line_total'],
                    $it['supplier'],
                ]);
            }

            $db->commit();

            if (function_exists('db_log_activity')) {
                db_log_activity((int)($user['id'] ?? 0), 'purchase_request_create', json_encode(['event_id' => $eventId, 'status' => $status]));
            }

            if ($submitNow && $isRequester) {
                flash_add('success', 'Material request submitted to Accountant for review.');
            } elseif ($submitNow) {
                flash_add('success', 'Purchase request submitted to Finance.');
            } else {
                flash_add('success', 'Purchase request saved as draft.');
            }
        }

        if ($action === 'update_purchase') {
            if ($role === 'finance') {
                throw new Exception('You do not have permission to update purchase requests');
            }

            $prId = (int)($_POST['pr_id'] ?? 0);
            if ($prId <= 0) {
                throw new Exception('Invalid purchase request');
            }

            $st = $db->prepare('SELECT requested_by, status FROM purchase_requests WHERE id=?');
            $st->execute([$prId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new Exception('Purchase request not found');
            }

            $requestedBy = (int)($row['requested_by'] ?? 0);
            $status = (string)($row['status'] ?? '');
            $canEditThis = ($requestedBy > 0 && $requestedBy === (int)($user['id'] ?? 0)) || in_array($role, ['admin', 'super'], true);
            if (!$canEditThis) {
                throw new Exception('You do not have permission to edit this purchase request');
            }
            if ($status !== 'rejected') {
                throw new Exception('Only rejected purchase requests can be edited');
            }

            $eventId = (int)($_POST['event_id'] ?? 0);
            $title = trim((string)($_POST['title'] ?? ''));
            $submitNow = ($_POST['submit_now'] ?? '') === '1';

            $allowPricing = !$isRequester;

            $materials = $_POST['material'] ?? [];
            $colors = $_POST['color'] ?? [];
            $sizes = $_POST['size'] ?? [];
            $quantities = $_POST['quantity'] ?? [];
            $unitPrices = $allowPricing ? ($_POST['unit_price'] ?? []) : [];
            $suppliers = $_POST['supplier'] ?? [];

            if (!is_array($materials) || !is_array($colors) || !is_array($sizes) || !is_array($quantities) || !is_array($suppliers) || ($allowPricing && !is_array($unitPrices))) {
                throw new Exception('Invalid items payload');
            }

            if ($title === '') {
                throw new Exception('Title is required');
            }

            $items = [];
            $grandTotal = 0.0;
            $count = max(count($materials), count($colors), count($sizes), count($quantities), ($allowPricing ? count($unitPrices) : 0), count($suppliers));
            for ($i = 0; $i < $count; $i++) {
                $material = trim((string)($materials[$i] ?? ''));
                $color = trim((string)($colors[$i] ?? ''));
                $size = trim((string)($sizes[$i] ?? ''));
                $supplier = $isRequester ? '' : trim((string)($suppliers[$i] ?? ''));
                $qty = (float)($quantities[$i] ?? 0);

                $unit = 0.0;
                if ($allowPricing) {
                    $unit = (float)($unitPrices[$i] ?? 0);
                }

                if ($material === '' && $supplier === '' && $qty <= 0 && (!$allowPricing || $unit <= 0)) {
                    continue;
                }

                if ($material === '') {
                    throw new Exception('Material is required for each item row');
                }

                $lineTotal = $allowPricing ? (max(0, $qty) * max(0, $unit)) : 0.0;
                if ($allowPricing) {
                    $grandTotal += $lineTotal;
                }

                $items[] = [
                    'item_no' => count($items) + 1,
                    'material' => $material,
                    'color' => $color !== '' ? $color : null,
                    'size' => $size !== '' ? $size : null,
                    'quantity' => $qty > 0 ? $qty : null,
                    'unit_price' => ($allowPricing && $unit > 0) ? $unit : null,
                    'line_total' => ($allowPricing && $lineTotal > 0) ? $lineTotal : null,
                    'supplier' => $supplier !== '' ? $supplier : null,
                ];
            }

            if (!$items) {
                throw new Exception('Please add at least one item');
            }

            if ($eventId > 0) {
                $ro = $db->prepare("SELECT id FROM release_orders WHERE event_id = ? AND status = 'approved' ORDER BY COALESCE(reviewed_at, submitted_at, created_at) DESC, id DESC LIMIT 1");
                $ro->execute([$eventId]);
                if (!(int)($ro->fetchColumn() ?? 0)) {
                    throw new Exception('Purchase requests require an approved Release Order for this event');
                }
            }

            $newStatus = $submitNow ? ($isRequester ? 'acct_submitted' : 'finance_submitted') : 'draft';
            $submittedAt = $submitNow ? date('Y-m-d H:i:s') : null;

            $db->beginTransaction();

            $upd = $db->prepare('UPDATE purchase_requests SET event_id=?, title=?, amount=?, status=?, submitted_at=?, reviewed_by=NULL, reviewed_at=NULL, finance_comment=NULL WHERE id=?');
            $upd->execute([
                $eventId > 0 ? $eventId : null,
                $title,
                ($allowPricing && $grandTotal > 0) ? $grandTotal : null,
                $newStatus,
                $submittedAt,
                $prId
            ]);

            $db->prepare('DELETE FROM purchase_request_items WHERE purchase_request_id = ?')->execute([$prId]);
            $itemStmt = $db->prepare('INSERT INTO purchase_request_items(purchase_request_id, item_no, material, color, size, quantity, unit_price, line_total, supplier) VALUES (?,?,?,?,?,?,?,?,?)');
            foreach ($items as $it) {
                $itemStmt->execute([
                    $prId,
                    $it['item_no'],
                    $it['material'],
                    $it['color'],
                    $it['size'],
                    $it['quantity'],
                    $it['unit_price'],
                    $it['line_total'],
                    $it['supplier'],
                ]);
            }

            $db->commit();

            if (function_exists('db_log_activity')) {
                db_log_activity((int)($user['id'] ?? 0), 'purchase_request_update', json_encode(['pr_id' => $prId, 'status' => $newStatus]));
            }

            flash_add('success', 'Purchase request updated.');
        }

        if ($action === 'accountant_review') {
            if (!$isAccountant) {
                throw new Exception('You do not have permission to review purchase requests');
            }

            $prId = (int)($_POST['pr_id'] ?? 0);
            if ($prId <= 0) {
                throw new Exception('Invalid purchase request');
            }

            $st = $db->prepare('SELECT status FROM purchase_requests WHERE id=?');
            $st->execute([$prId]);
            $status = (string)($st->fetchColumn() ?? '');
            if (!in_array($status, ['acct_submitted', 'draft'], true)) {
                throw new Exception('Only accountant-stage requests can be reviewed');
            }

            $itemIds = $_POST['item_id'] ?? [];
            $decisions = $_POST['accountant_decision'] ?? [];
            $comments = $_POST['accountant_comment'] ?? [];
            $unitPrices = $_POST['unit_price'] ?? [];
            $suppliers = $_POST['supplier'] ?? [];

            if (!is_array($itemIds) || !is_array($decisions) || !is_array($comments) || !is_array($unitPrices) || !is_array($suppliers)) {
                throw new Exception('Invalid review payload');
            }

            $upd = $db->prepare("UPDATE purchase_request_items SET accountant_decision=?, accountant_comment=?, unit_price=?, line_total=?, supplier=? WHERE id=? AND purchase_request_id=?");
            $n = count($itemIds);
            for ($i = 0; $i < $n; $i++) {
                $itemId = (int)($itemIds[$i] ?? 0);
                if ($itemId <= 0) continue;

                $decision = (string)($decisions[$i] ?? 'pending');
                if (!in_array($decision, ['pending', 'approved', 'rejected'], true)) {
                    $decision = 'pending';
                }

                $comment = trim((string)($comments[$i] ?? ''));
                $unit = (float)($unitPrices[$i] ?? 0);
                $supplier = trim((string)($suppliers[$i] ?? ''));

                $q = $db->prepare('SELECT quantity FROM purchase_request_items WHERE id=? AND purchase_request_id=?');
                $q->execute([$itemId, $prId]);
                $qty = (float)($q->fetchColumn() ?? 0);

                $lineTotal = ($decision === 'approved') ? (max(0, $qty) * max(0, $unit)) : 0.0;

                $upd->execute([
                    $decision,
                    ($comment !== '' ? $comment : null),
                    ($decision === 'approved' && $unit > 0 ? $unit : null),
                    ($decision === 'approved' && $lineTotal > 0 ? $lineTotal : null),
                    ($supplier !== '' ? $supplier : null),
                    $itemId,
                    $prId
                ]);
            }

            $hdrComment = trim((string)($_POST['accountant_header_comment'] ?? ''));
            $hdr = $db->prepare('UPDATE purchase_requests SET accountant_reviewed_by=?, accountant_reviewed_at=NOW(), accountant_comment=? WHERE id=?');
            $hdr->execute([(int)($user['id'] ?? 0), ($hdrComment !== '' ? $hdrComment : null), $prId]);

            flash_add('success', 'Accountant review saved.');
        }

        if ($action === 'accountant_submit_to_finance') {
            if (!$isAccountant) {
                throw new Exception('You do not have permission to submit to Finance');
            }

            $prId = (int)($_POST['pr_id'] ?? 0);
            if ($prId <= 0) {
                throw new Exception('Invalid purchase request');
            }

            $st = $db->prepare('SELECT status FROM purchase_requests WHERE id=?');
            $st->execute([$prId]);
            $status = (string)($st->fetchColumn() ?? '');
            if (!in_array($status, ['acct_submitted', 'draft'], true)) {
                throw new Exception('Only accountant-stage requests can be submitted to Finance');
            }

            $sum = $db->prepare("SELECT COALESCE(SUM(line_total),0) FROM purchase_request_items WHERE purchase_request_id=? AND accountant_decision='approved'");
            $sum->execute([$prId]);
            $total = (float)($sum->fetchColumn() ?? 0);

            $upd = $db->prepare("UPDATE purchase_requests SET status='finance_submitted', submitted_at=NOW(), amount=? WHERE id=?");
            $upd->execute([$total > 0 ? $total : null, $prId]);

            flash_add('success', 'Purchase request submitted to Finance for approval.');
        }
    } catch (Throwable $ex) {
        flash_add('error', $ex->getMessage());
    }

    header('Location: purchase_requests.php');
    exit;
}

$events = [];
try {
    $stmt = $db->query("SELECT id, name, client, date, supervisor, created_at FROM events ORDER BY COALESCE(date, created_at) DESC, id DESC");
    $events = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable $e) {
    $events = [];
}

$purchaseRequests = [];
try {
    $stmt = $db->prepare("SELECT pr.*, e.name AS event_name, e.client AS event_client FROM purchase_requests pr LEFT JOIN events e ON pr.event_id = e.id WHERE pr.requested_by = ? OR ? IN ('admin','super','finance','accountant') ORDER BY pr.created_at DESC, pr.id DESC");
    $stmt->execute([(int)($user['id'] ?? 0), $role]);
    $purchaseRequests = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $purchaseRequests = [];
}

$acctQueue = [];
if ($isAccountant) {
    try {
        $stmt = $db->query("SELECT pr.*, e.name AS event_name, e.client AS event_client, u.name AS requested_by_name FROM purchase_requests pr LEFT JOIN events e ON pr.event_id = e.id LEFT JOIN users u ON pr.requested_by = u.id WHERE pr.status='acct_submitted' ORDER BY pr.submitted_at DESC, pr.id DESC");
        $acctQueue = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
        $acctQueue = [];
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
</head>
<body>
<div class="dashboard-container">
    <?php $activePage = 'purchase_requests.php'; include __DIR__ . '/sidebar.php'; ?>

    <main class="main-content">
        <div class="top-bar">
            <div class="page-title">
                <h1><?php echo htmlspecialchars($pageTitle); ?></h1>
                <p>Request materials and track approvals</p>
            </div>
            <div class="user-menu">
                <div class="date-display">
                    <i class="far fa-calendar-alt mr-2"></i>
                    <?php echo date('l, F j, Y'); ?>
                </div>

                <div class="user-profile" id="userProfile">
                    <div class="avatar">
                        <?php
                            $initial = strtoupper(substr($displayName, 0, 1));
                            echo htmlspecialchars($initial);
                        ?>
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

        <?php if ($isAccountant): ?>
        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title">Accountant Review Queue</h2>
            </div>
            <?php if (!empty($acctQueue)): ?>
                <?php foreach ($acctQueue as $pr): ?>
                    <?php
                        $items = [];
                        try {
                            $it = $db->prepare('SELECT * FROM purchase_request_items WHERE purchase_request_id = ? ORDER BY COALESCE(item_no, id) ASC');
                            $it->execute([(int)($pr['id'] ?? 0)]);
                            $items = $it->fetchAll(PDO::FETCH_ASSOC) ?: [];
                        } catch (Throwable $e) {
                            $items = [];
                        }
                    ?>
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 mb-4">
                        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-2 mb-3">
                            <div>
                                <div class="text-sm font-semibold text-slate-800"><?php echo htmlspecialchars((string)($pr['title'] ?? '')); ?></div>
                                <div class="text-xs text-slate-500">
                                    <?php echo htmlspecialchars((string)($pr['event_name'] ?? 'No event')); ?>
                                    <?php if (!empty($pr['requested_by_name'])): ?>
                                        <span class="mx-1">|</span>
                                        Requested by: <?php echo htmlspecialchars((string)$pr['requested_by_name']); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="text-xs text-slate-500">
                                Status: <span class="font-medium"><?php echo htmlspecialchars((string)($pr['status'] ?? '')); ?></span>
                            </div>
                        </div>

                        <form method="post" class="space-y-3">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                            <input type="hidden" name="action" value="accountant_review">
                            <input type="hidden" name="pr_id" value="<?php echo (int)($pr['id'] ?? 0); ?>">

                            <div class="overflow-x-auto" style="border:1px solid #e5e7eb;border-radius:12px;">
                                <table class="w-full">
                                    <thead>
                                    <tr class="border-b border-gray-200" style="background:#f1f5f9;">
                                        <th class="text-left py-3 px-4 text-gray-600 font-medium" style="width:70px;">S/N</th>
                                        <th class="text-left py-3 px-4 text-gray-600 font-medium">Material</th>
                                        <th class="text-left py-3 px-4 text-gray-600 font-medium" style="width:140px;">Color</th>
                                        <th class="text-left py-3 px-4 text-gray-600 font-medium" style="width:140px;">Size</th>
                                        <th class="text-left py-3 px-4 text-gray-600 font-medium" style="width:120px;">Qty</th>
                                        <th class="text-left py-3 px-4 text-gray-600 font-medium" style="width:220px;">Supplier</th>
                                        <th class="text-left py-3 px-4 text-gray-600 font-medium" style="width:160px;">Unit Price</th>
                                        <th class="text-left py-3 px-4 text-gray-600 font-medium" style="width:160px;">Decision</th>
                                        <th class="text-left py-3 px-4 text-gray-600 font-medium" style="width:240px;">Comment</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php $i = 1; foreach ($items as $it): ?>
                                        <tr class="border-b border-gray-100">
                                            <td class="py-3 px-4 text-gray-700"><?php echo (int)$i; ?></td>
                                            <td class="py-3 px-4 text-gray-700"><?php echo htmlspecialchars((string)($it['material'] ?? '')); ?></td>
                                            <td class="py-3 px-4 text-gray-700"><?php echo htmlspecialchars((string)($it['color'] ?? '')); ?></td>
                                            <td class="py-3 px-4 text-gray-700"><?php echo htmlspecialchars((string)($it['size'] ?? '')); ?></td>
                                            <td class="py-3 px-4 text-gray-700"><?php echo htmlspecialchars((string)($it['quantity'] ?? '')); ?></td>
                                            <td class="py-3 px-4">
                                                <input type="text" name="supplier[]" class="form-input" value="<?php echo htmlspecialchars((string)($it['supplier'] ?? '')); ?>" placeholder="Supplier">
                                            </td>
                                            <td class="py-3 px-4">
                                                <input type="hidden" name="item_id[]" value="<?php echo (int)($it['id'] ?? 0); ?>">
                                                <input type="number" step="0.01" min="0" name="unit_price[]" class="form-input" value="<?php echo htmlspecialchars((string)($it['unit_price'] ?? '')); ?>" placeholder="0.00">
                                            </td>
                                            <td class="py-3 px-4">
                                                <select name="accountant_decision[]" class="form-select">
                                                    <?php $d = (string)($it['accountant_decision'] ?? 'pending'); ?>
                                                    <option value="pending" <?php echo $d === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                                    <option value="approved" <?php echo $d === 'approved' ? 'selected' : ''; ?>>Approve</option>
                                                    <option value="rejected" <?php echo $d === 'rejected' ? 'selected' : ''; ?>>Reject</option>
                                                </select>
                                            </td>
                                            <td class="py-3 px-4">
                                                <input type="text" name="accountant_comment[]" class="form-input" value="<?php echo htmlspecialchars((string)($it['accountant_comment'] ?? '')); ?>" placeholder="Reason / note">
                                            </td>
                                        </tr>
                                    <?php $i++; endforeach; ?>
                                    <?php if (empty($items)): ?>
                                        <tr><td colspan="9" style="text-align:center;color:#64748b;padding:1.5rem;">No items found</td></tr>
                                    <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-3 items-start">
                                <div class="form-group">
                                    <label class="form-label">Accountant Comment</label>
                                    <input type="text" class="form-input" name="accountant_header_comment" value="<?php echo htmlspecialchars((string)($pr['accountant_comment'] ?? '')); ?>" placeholder="General comment (optional)">
                                </div>
                                <div class="flex gap-2 lg:justify-end" style="align-self:end; flex-wrap:wrap;">
                                    <button type="submit" class="btn btn-secondary">Save Review</button>
                                </div>
                            </div>
                        </form>

                        <form method="post" style="margin-top:0.75rem;">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                            <input type="hidden" name="action" value="accountant_submit_to_finance">
                            <input type="hidden" name="pr_id" value="<?php echo (int)($pr['id'] ?? 0); ?>">
                            <button type="submit" class="btn btn-primary">Submit to Finance</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div style="text-align:center;color:#64748b;padding:1.5rem;">No requests awaiting accountant review.</div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($role !== 'finance'): ?>
        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title"><?php echo $editPurchaseRequest ? 'Edit Purchase Request' : 'Create Purchase Request'; ?></h2>
            </div>
            <div class="form-grid">
                <div>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                        <input type="hidden" name="action" value="<?php echo $editPurchaseRequest ? 'update_purchase' : 'create_purchase'; ?>">
                        <?php if ($editPurchaseRequest): ?>
                            <input type="hidden" name="pr_id" value="<?php echo (int)($editPurchaseRequest['id'] ?? 0); ?>">
                        <?php endif; ?>

                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem;">
                            <div class="form-group">
                                <label class="form-label">Event (optional)</label>
                                <select name="event_id" id="prEventId" class="form-select">
                                    <option value="0">No event</option>
                                    <?php foreach ($events as $e): ?>
                                        <option value="<?php echo (int)$e['id']; ?>"
                                                data-event-name="<?php echo htmlspecialchars((string)($e['name'] ?? '')); ?>"
                                                data-event-date="<?php echo htmlspecialchars((string)($e['date'] ?? '')); ?>"
                                                data-event-client="<?php echo htmlspecialchars((string)($e['client'] ?? '')); ?>"
                                                data-event-supervisor="<?php echo htmlspecialchars((string)($e['supervisor'] ?? '')); ?>"
                                                <?php echo ($editPurchaseRequest && (int)($editPurchaseRequest['event_id'] ?? 0) === (int)$e['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars((string)($e['name'] ?? '')); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label class="form-label">User Department</label>
                                <input type="text" class="form-input" value="<?php echo htmlspecialchars(strtoupper((in_array($role, ['store','production','operation','accountant'], true) ? $role : 'accountant'))); ?>" readonly>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Event Name</label>
                                <input type="text" id="prEventName" class="form-input" value="" readonly>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Event Date</label>
                                <input type="text" id="prEventDate" class="form-input" value="" readonly>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Sales Person</label>
                                <input type="text" class="form-input" value="<?php echo htmlspecialchars($displayName); ?>" readonly>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Supervisor</label>
                                <input type="text" id="prEventSupervisor" class="form-input" value="" placeholder="Auto from event (if any)" readonly>
                            </div>

                            <div class="form-group" style="grid-column: 1 / -1;">
                                <label class="form-label">Title *</label>
                                <input type="text" name="title" class="form-input" required value="<?php echo htmlspecialchars((string)($editPurchaseRequest['title'] ?? '')); ?>">
                            </div>
                        </div>

                        <div style="margin-top: 1rem;">
                            <div class="section-header" style="margin-bottom: 0.75rem; padding-bottom: 0.75rem;">
                                <h3 class="section-title" style="margin: 0;">Materials / Items</h3>
                                <div class="section-actions">
                                    <button type="button" id="addItemRow" class="btn btn-primary"><i class="fas fa-plus mr-1"></i> Add Row</button>
                                </div>
                            </div>

                            <div class="overflow-x-auto" style="border:1px solid #e5e7eb;border-radius:12px;">
                                <table class="w-full">
                                    <thead>
                                    <tr class="border-b border-gray-200" style="background:#f1f5f9;">
                                        <th class="text-left py-3 px-4 text-gray-600 font-medium" style="width:70px;">S/N</th>
                                        <th class="text-left py-3 px-4 text-gray-600 font-medium">Materials</th>
                                        <th class="text-left py-3 px-4 text-gray-600 font-medium" style="width:140px;">Color</th>
                                        <th class="text-left py-3 px-4 text-gray-600 font-medium" style="width:140px;">Size</th>
                                        <th class="text-left py-3 px-4 text-gray-600 font-medium" style="width:120px;">Quantity</th>
                                        <?php if (!$isRequester): ?>
                                            <th class="text-left py-3 px-4 text-gray-600 font-medium" style="width:140px;">Per Unit</th>
                                            <th class="text-left py-3 px-4 text-gray-600 font-medium" style="width:160px;">Total (VAT incl)</th>
                                        <?php endif; ?>
                                        <?php if (!$isRequester): ?>
                                            <th class="text-left py-3 px-4 text-gray-600 font-medium" style="width:220px;">Supplier</th>
                                        <?php endif; ?>
                                        <th class="text-right py-3 px-4 text-gray-600 font-medium" style="width:70px;">&nbsp;</th>
                                    </tr>
                                    </thead>
                                    <tbody id="itemsBody">
                                    <?php if ($editPurchaseRequest && !empty($editPurchaseItems)): ?>
                                        <?php foreach ($editPurchaseItems as $it): ?>
                                            <tr class="border-b border-gray-100 item-row">
                                                <td class="py-3 px-4 text-gray-700 item-no">1</td>
                                                <td class="py-3 px-4"><input type="text" name="material[]" class="form-input" placeholder="Material" required value="<?php echo htmlspecialchars((string)($it['material'] ?? '')); ?>"></td>
                                                <td class="py-3 px-4"><input type="text" name="color[]" class="form-input" placeholder="Color" value="<?php echo htmlspecialchars((string)($it['color'] ?? '')); ?>"></td>
                                                <td class="py-3 px-4"><input type="text" name="size[]" class="form-input" placeholder="Size" value="<?php echo htmlspecialchars((string)($it['size'] ?? '')); ?>"></td>
                                                <td class="py-3 px-4"><input type="number" step="0.01" min="0" name="quantity[]" class="form-input qty" placeholder="0" value="<?php echo htmlspecialchars((string)($it['quantity'] ?? '')); ?>"></td>
                                                <?php if (!$isRequester): ?>
                                                    <td class="py-3 px-4"><input type="number" step="0.01" min="0" name="unit_price[]" class="form-input unit" placeholder="0.00" value="<?php echo htmlspecialchars((string)($it['unit_price'] ?? '')); ?>"></td>
                                                    <td class="py-3 px-4"><input type="text" class="form-input line-total" value="<?php echo htmlspecialchars(number_format((float)($it['line_total'] ?? 0), 2, '.', '')); ?>" readonly></td>
                                                <?php endif; ?>
                                                <?php if (!$isRequester): ?>
                                                    <td class="py-3 px-4"><input type="text" name="supplier[]" class="form-input" placeholder="Supplier" value="<?php echo htmlspecialchars((string)($it['supplier'] ?? '')); ?>"></td>
                                                <?php endif; ?>
                                                <td class="py-3 px-4 text-right"><button type="button" class="btn btn-sm btn-secondary remove-row"><i class="fas fa-minus"></i></button></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr class="border-b border-gray-100 item-row">
                                            <td class="py-3 px-4 text-gray-700 item-no">1</td>
                                            <td class="py-3 px-4"><input type="text" name="material[]" class="form-input" placeholder="Material" required></td>
                                            <td class="py-3 px-4"><input type="text" name="color[]" class="form-input" placeholder="Color"></td>
                                            <td class="py-3 px-4"><input type="text" name="size[]" class="form-input" placeholder="Size"></td>
                                            <td class="py-3 px-4"><input type="number" step="0.01" min="0" name="quantity[]" class="form-input qty" placeholder="0"></td>
                                            <?php if (!$isRequester): ?>
                                                <td class="py-3 px-4"><input type="number" step="0.01" min="0" name="unit_price[]" class="form-input unit" placeholder="0.00"></td>
                                                <td class="py-3 px-4"><input type="text" class="form-input line-total" value="0.00" readonly></td>
                                            <?php endif; ?>
                                            <?php if (!$isRequester): ?>
                                                <td class="py-3 px-4"><input type="text" name="supplier[]" class="form-input" placeholder="Supplier"></td>
                                            <?php endif; ?>
                                            <td class="py-3 px-4 text-right"><button type="button" class="btn btn-sm btn-secondary remove-row" disabled><i class="fas fa-minus"></i></button></td>
                                        </tr>
                                    <?php endif; ?>
                                    </tbody>
                                    <tfoot>
                                    <?php if (!$isRequester): ?>
                                        <tr style="background:#f8fafc;">
                                            <td class="py-3 px-4 font-semibold text-gray-700" colspan="6" style="text-align:right;">TOTAL</td>
                                            <td class="py-3 px-4"><input type="text" id="grandTotal" class="form-input" value="0.00" readonly></td>
                                            <td class="py-3 px-4" colspan="2"></td>
                                        </tr>
                                    <?php endif; ?>
                                    </tfoot>
                                </table>
                            </div>
                        </div>

                        <div class="flex gap-2" style="margin-top: 1rem; flex-wrap: wrap;">
                            <button type="submit" name="submit_now" value="0" class="btn btn-secondary"><?php echo $editPurchaseRequest ? 'Update Draft' : 'Save Draft'; ?></button>
                            <button type="submit" name="submit_now" value="1" class="btn btn-primary"><?php echo $editPurchaseRequest ? 'Update & Submit' : ($isRequester ? 'Submit to Accountant' : 'Submit to Finance'); ?></button>
                            <?php if ($editPurchaseRequest): ?>
                                <a href="purchase_requests.php" class="btn btn-secondary">Cancel</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title">Purchase Requests</h2>
            </div>
            <div class="table-container">
                <table class="data-table">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Event</th>
                        <th>Title</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Accountant Comment</th>
                        <th>Finance Comment</th>
                        <th class="text-center">Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php $rowNum = 1; foreach ($purchaseRequests as $pr): ?>
                        <tr>
                            <td><?php echo (int)$rowNum; ?></td>
                            <td>
                                <?php if (!empty($pr['event_id'])): ?>
                                    <?php echo htmlspecialchars((string)($pr['event_name'] ?? '')); ?>
                                <?php else: ?>
                                    <span class="text-slate-400">-</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($pr['title'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars(number_format((float)($pr['amount'] ?? 0), 2)); ?></td>
                            <td><?php echo htmlspecialchars($pr['status'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars((string)($pr['accountant_comment'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars($pr['finance_comment'] ?? ''); ?></td>
                            <td class="text-center">
                                <?php
                                    $st = (string)($pr['status'] ?? '');
                                    $reqBy = (int)($pr['requested_by'] ?? 0);
                                    $canEditThis = ($reqBy > 0 && $reqBy === (int)($user['id'] ?? 0)) || in_array($role, ['admin', 'super'], true);
                                ?>
                                <?php if ($canEditThis && $st === 'rejected'): ?>
                                    <a href="purchase_requests.php?edit_id=<?php echo (int)($pr['id'] ?? 0); ?>" class="btn btn-sm btn-secondary" title="Edit Rejected Request">
                                        <i class="fas fa-pen-to-square" style="color:#000;"></i>
                                        <span style="margin-left:6px;">Edit</span>
                                    </a>
                                <?php else: ?>
                                    <span class="text-slate-400">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php $rowNum++; endforeach; ?>
                    <?php if (!$purchaseRequests): ?>
                        <tr><td colspan="8" style="text-align:center;color:#64748b;padding:2rem;">No purchase requests found</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        var eventSelect = document.getElementById('prEventId');
        var eventName = document.getElementById('prEventName');
        var eventDate = document.getElementById('prEventDate');
        var eventSupervisor = document.getElementById('prEventSupervisor');

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

        var itemsBody = document.getElementById('itemsBody');
        var addBtn = document.getElementById('addItemRow');
        var grandTotalEl = document.getElementById('grandTotal');

        function recalc() {
            if (!itemsBody) return;
            var rows = Array.from(itemsBody.querySelectorAll('tr.item-row'));
            var grand = 0;
            rows.forEach(function(row, idx) {
                var noEl = row.querySelector('.item-no');
                if (noEl) noEl.textContent = String(idx + 1);

                var qtyEl = row.querySelector('.qty');
                var unitEl = row.querySelector('.unit');
                var lineEl = row.querySelector('.line-total');

                var qty = parseFloat(qtyEl ? qtyEl.value : '0') || 0;
                var unit = parseFloat(unitEl ? unitEl.value : '0') || 0;
                var line = qty * unit;

                if (lineEl) lineEl.value = line.toFixed(2);
                if (grandTotalEl) grand += line;
            });

            if (grandTotalEl) grandTotalEl.value = grand.toFixed(2);

            rows.forEach(function(row) {
                var remove = row.querySelector('button.remove-row');
                if (remove) remove.disabled = rows.length <= 1;
            });
        }

        function wireRow(row) {
            if (!row) return;
            var qty = row.querySelector('input.qty');
            var unit = row.querySelector('input.unit');
            if (qty) qty.addEventListener('input', recalc);
            if (unit) unit.addEventListener('input', recalc);

            var remove = row.querySelector('button.remove-row');
            if (remove) {
                remove.addEventListener('click', function() {
                    row.remove();
                    recalc();
                });
            }
        }

        if (itemsBody) {
            Array.from(itemsBody.querySelectorAll('tr.item-row')).forEach(wireRow);
            recalc();
        }

        if (addBtn && itemsBody) {
            addBtn.addEventListener('click', function() {
                var template = itemsBody.querySelector('tr.item-row');
                if (!template) return;
                var clone = template.cloneNode(true);
                clone.querySelectorAll('input').forEach(function(inp) {
                    if (inp.classList.contains('line-total')) {
                        inp.value = '0.00';
                    } else {
                        inp.value = '';
                    }
                });
                itemsBody.appendChild(clone);
                wireRow(clone);
                recalc();
            });
        }

        if (userProfile && profileDropdown) {
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
