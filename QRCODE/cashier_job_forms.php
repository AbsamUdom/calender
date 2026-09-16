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
$role = strtolower((string)($user['role'] ?? 'user'));
if (!in_array($role, ['cashier', 'admin', 'super'], true)) {
    http_response_code(403);
    echo 'Access denied. Insufficient permissions.';
    exit;
}

$cashierUserId = (int)($user['id'] ?? 0);
$moneyToCents = static function ($value) {
    $normalized = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', trim((string)$value)));
    if (!preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $normalized, $matches)) return 0;
    $fraction = str_pad(substr($matches[3] ?? '', 0, 3), 3, '0');
    $cents = ((int)$matches[2] * 100) + (int)substr($fraction, 0, 2) + ((int)$fraction[2] >= 5 ? 1 : 0);
    return ($matches[1] ?? '') === '-' ? -$cents : $cents;
};
$centsToDecimal = static function ($cents) {
    $cents = max(0, (int)$cents);
    return intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
};
$jobFormAmount = static function ($labourRows) use ($moneyToCents) {
    $rows = json_decode((string)$labourRows, true);
    $total = 0;
    foreach (is_array($rows) ? $rows : [] as $row) {
        if (!is_array($row)) continue;
        $balance = $row['balance'] ?? ($row['s1_balance'] ?? '');
        $total += max(0, trim((string)$balance) !== '' ? $moneyToCents($balance) : $moneyToCents($row['total'] ?? ($row['s1_total'] ?? 0)));
    }
    return $total;
};
$receiveJobForms = static function () use ($db, $cashierUserId, $jobFormAmount, $centsToDecimal) {
    $forms = $db->query("SELECT jf.id, jf.event_id, jf.job_form_no, jf.labour_rows FROM job_forms jf JOIN events e ON e.id = jf.event_id WHERE jf.status = 'finance_approved' FOR UPDATE")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $insert = $db->prepare("INSERT INTO cashier_expenses (event_id, source_type, source_id, description, amount, created_by) VALUES (?, 'job_form', ?, ?, ?, ?) ON DUPLICATE KEY UPDATE description = IF(cashier_expenses.status = 'unpaid', VALUES(description), cashier_expenses.description), amount = IF(cashier_expenses.status = 'unpaid', VALUES(amount), cashier_expenses.amount)");
    $received = 0;
    foreach ($forms as $form) {
        $amountCents = $jobFormAmount($form['labour_rows'] ?? null);
        if ($amountCents <= 0) continue;
        $label = trim((string)($form['job_form_no'] ?? ''));
        $insert->execute([(int)$form['event_id'], (int)$form['id'], 'Job Form' . ($label !== '' ? ': ' . $label : ' #' . (int)$form['id']), $centsToDecimal($amountCents), $cashierUserId]);
        $received++;
    }
    return $received;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_abort();
    try {
        if (($_POST['action'] ?? '') !== 'receive_job_forms') throw new RuntimeException('Invalid Cashier Job Form action');
        $db->beginTransaction();
        $received = $receiveJobForms();
        $db->commit();
        db_log_activity($cashierUserId, 'cashier_job_forms_received', json_encode(['approved_job_forms' => $received]));
        flash_add('success', $received . ' Finance-approved Job Form(s) received successfully');
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('Cashier Job Form receive failed: ' . $e->getMessage());
        flash_add('error', $e instanceof RuntimeException ? $e->getMessage() : 'Approved Job Forms could not be received');
    }
    header('Location: cashier_job_forms.php');
    exit;
}

$search = trim((string)($_GET['q'] ?? ''));
$statusFilter = (string)($_GET['status'] ?? 'all');
$validStatuses = ['all', 'finance_approved', 'finance_rejected', 'coordinator_approved', 'submitted', 'draft'];
if (!in_array($statusFilter, $validStatuses, true)) $statusFilter = 'all';
$where = [];
$params = [];
if ($statusFilter !== 'all') {
    $where[] = 'jf.status = ?';
    $params[] = $statusFilter;
}
if ($search !== '') {
    $where[] = '(jf.job_form_no LIKE ? OR e.name LIKE ? OR e.client LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}
$sql = "SELECT jf.*, e.name AS event_name, e.client AS event_client, e.date AS event_date, ce.id AS expense_id, ce.amount AS received_amount, ce.status AS payment_status, COALESCE(pay.amount_paid, 0) AS amount_paid
    FROM job_forms jf
    JOIN events e ON e.id = jf.event_id
    LEFT JOIN cashier_expenses ce ON ce.source_type = 'job_form' AND ce.source_id = jf.id
    LEFT JOIN (SELECT expense_id, SUM(amount) AS amount_paid FROM cashier_payments GROUP BY expense_id) pay ON pay.expense_id = ce.id
    " . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . "
    ORDER BY CASE jf.status WHEN 'finance_approved' THEN 0 ELSE 1 END, COALESCE(jf.finance_reviewed_at, jf.created_at) DESC, jf.id DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$jobForms = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$approvedCount = 0;
$receivedCount = 0;
$totalPayableCents = 0;
$totalPaidCents = 0;
foreach ($jobForms as &$jobForm) {
    $jobForm['calculated_amount_cents'] = $jobFormAmount($jobForm['labour_rows'] ?? null);
    $jobForm['paid_cents'] = $moneyToCents($jobForm['amount_paid'] ?? 0);
    $jobForm['remaining_cents'] = max(0, $jobForm['calculated_amount_cents'] - $jobForm['paid_cents']);
    if (($jobForm['status'] ?? '') === 'finance_approved') {
        $approvedCount++;
        $totalPayableCents += $jobForm['calculated_amount_cents'];
    }
    if ((int)($jobForm['expense_id'] ?? 0) > 0) $receivedCount++;
    $totalPaidCents += $jobForm['paid_cents'];
}
unset($jobForm);
$flashes = flash_consume();
$displayName = $user['name'] ?? $user['email'] ?? 'Cashier';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cashier Job Forms | Event Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="style.css">
    <style>
        .jf-card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;box-shadow:0 1px 3px rgba(15,23,42,.06)}
        .jf-table-wrap{overflow-x:auto}.jf-table{width:100%;border-collapse:collapse}.jf-table th{padding:13px 14px;text-align:left;background:#f8fafc;color:#475569;font-size:.72rem;text-transform:uppercase;white-space:nowrap;border-bottom:1px solid #e2e8f0}.jf-table td{padding:14px;color:#334155;border-bottom:1px solid #f1f5f9;vertical-align:middle}
        .badge{display:inline-flex;padding:4px 9px;border-radius:999px;font-size:.72rem;font-weight:700}.approved{background:#dcfce7;color:#166534}.pending{background:#fef3c7;color:#92400e}.rejected{background:#fee2e2;color:#991b1b}.received{background:#dbeafe;color:#1d4ed8}.not-received{background:#f1f5f9;color:#475569}
        .view-btn{display:inline-flex;width:36px;height:36px;align-items:center;justify-content:center;border-radius:10px;background:#eff6ff;color:#2563eb;text-decoration:none}.view-btn:hover{background:#2563eb;color:#fff}.jf-field{border:1px solid #cbd5e1;border-radius:9px;padding:9px 11px;background:#fff}
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main class="main-content">
        <div class="top-bar"><div class="page-title"><h1>Cashier Job Forms</h1><p>Receive Finance-approved Job Forms and track partial payments</p></div><div class="user-menu"><div class="date-display"><i class="far fa-calendar-alt"></i> <?php echo date('l, F j, Y'); ?></div><a href="profile.php" class="user-profile" style="text-decoration:none;color:inherit;"><div class="avatar"><?php echo htmlspecialchars(strtoupper(function_exists('mb_substr') ? mb_substr($displayName, 0, 1) : substr($displayName, 0, 1))); ?></div><div><div style="font-weight:600;"><?php echo htmlspecialchars($displayName); ?></div><div style="font-size:.75rem;color:#64748b;"><?php echo htmlspecialchars(get_role_label($role)); ?></div></div></a></div></div>
        <?php foreach ($flashes as $flash): ?><div class="alert <?php echo ($flash['type'] ?? '') === 'success' ? 'alert-success' : 'alert-error'; ?>" style="margin-bottom:1rem;"><?php echo htmlspecialchars((string)($flash['message'] ?? '')); ?></div><?php endforeach; ?>
        <div class="flex justify-end mb-4"><form method="post"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>"><input type="hidden" name="action" value="receive_job_forms"><button class="btn btn-primary" type="submit"><i class="fas fa-download mr-1"></i> Receive Approved Job Forms</button></form></div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <div class="jf-card p-5"><p class="text-sm text-slate-500">Finance Approved</p><p class="text-2xl font-bold text-slate-900 mt-1"><?php echo number_format($approvedCount); ?></p></div>
            <div class="jf-card p-5"><p class="text-sm text-slate-500">Received by Cashier</p><p class="text-2xl font-bold text-blue-700 mt-1"><?php echo number_format($receivedCount); ?></p></div>
            <div class="jf-card p-5"><p class="text-sm text-slate-500">Approved Amount to Pay</p><p class="text-xl font-bold text-violet-700 mt-1">TSh <?php echo number_format($totalPayableCents / 100, 2); ?></p></div>
            <div class="jf-card p-5"><p class="text-sm text-slate-500">Amount Paid</p><p class="text-xl font-bold text-emerald-700 mt-1">TSh <?php echo number_format($totalPaidCents / 100, 2); ?></p></div>
        </div>
        <section class="jf-card">
            <div class="p-5 border-b border-slate-200 flex flex-col lg:flex-row lg:items-end lg:justify-between gap-3"><div><h2 class="text-lg font-bold text-slate-900">All Job Forms</h2><p class="text-sm text-slate-500 mt-1">A Job Form must be Finance-approved and received before Cashier can pay it</p></div><form method="get" class="flex flex-wrap gap-2"><input class="jf-field" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search Job Form or event"><select class="jf-field" name="status"><option value="all">All statuses</option><?php foreach (array_slice($validStatuses, 1) as $status): ?><option value="<?php echo htmlspecialchars($status); ?>" <?php echo $statusFilter === $status ? 'selected' : ''; ?>><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $status))); ?></option><?php endforeach; ?></select><button class="btn btn-secondary" type="submit">Filter</button></form></div>
            <div class="jf-table-wrap"><table class="jf-table"><thead><tr><th>Job Form</th><th>Event</th><th>Finance Status</th><th>Cashier Receipt</th><th>Amount to Pay</th><th>Amount Paid</th><th>Remaining</th><th>Payment Status</th><th>Action</th></tr></thead><tbody>
            <?php foreach ($jobForms as $form): $financeStatus = (string)($form['status'] ?? 'draft'); ?>
                <tr><td><strong><?php echo htmlspecialchars((string)($form['job_form_no'] ?: '#' . $form['id'])); ?></strong><div class="text-xs text-slate-500 mt-1"><?php echo !empty($form['created_at']) ? htmlspecialchars(date('M j, Y', strtotime((string)$form['created_at']))) : ''; ?></div></td><td><strong><?php echo htmlspecialchars((string)$form['event_name']); ?></strong><div class="text-xs text-slate-500 mt-1"><?php echo htmlspecialchars((string)($form['event_client'] ?: '—')); ?></div></td><td><span class="badge <?php echo $financeStatus === 'finance_approved' ? 'approved' : (strpos($financeStatus, 'rejected') !== false ? 'rejected' : 'pending'); ?>"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $financeStatus))); ?></span></td><td><span class="badge <?php echo (int)($form['expense_id'] ?? 0) > 0 ? 'received' : 'not-received'; ?>"><?php echo (int)($form['expense_id'] ?? 0) > 0 ? 'Received' : 'Not received'; ?></span></td><td class="font-bold whitespace-nowrap">TSh <?php echo number_format($form['calculated_amount_cents'] / 100, 2); ?></td><td class="font-bold text-emerald-700 whitespace-nowrap">TSh <?php echo number_format($form['paid_cents'] / 100, 2); ?></td><td class="font-bold text-amber-700 whitespace-nowrap">TSh <?php echo number_format($form['remaining_cents'] / 100, 2); ?></td><td><span class="badge <?php echo $form['remaining_cents'] <= 0 && $form['calculated_amount_cents'] > 0 ? 'approved' : 'pending'; ?>"><?php echo $form['remaining_cents'] <= 0 && $form['calculated_amount_cents'] > 0 ? 'Paid' : ($form['paid_cents'] > 0 ? 'Partially paid' : 'Unpaid'); ?></span></td><td><a class="view-btn" href="cashier_job_form.php?job_form_id=<?php echo (int)$form['id']; ?>" title="View Job Form and record payment"><i class="fas fa-eye"></i></a></td></tr>
            <?php endforeach; ?>
            <?php if (!$jobForms): ?><tr><td colspan="9" class="text-center text-slate-500" style="padding:2.5rem;">No Job Forms found.</td></tr><?php endif; ?>
            </tbody></table></div>
        </section>
    </main>
</div>
</body>
</html>
