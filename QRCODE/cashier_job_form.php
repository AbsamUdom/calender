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
$jobFormId = (int)($_GET['job_form_id'] ?? $_POST['job_form_id'] ?? 0);
if ($jobFormId <= 0) {
    http_response_code(404);
    echo 'Job Form not found.';
    exit;
}
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
$loadJobForm = static function () use ($db, $jobFormId) {
    $stmt = $db->prepare("SELECT jf.*, e.name AS event_name, e.client AS event_client, e.date AS event_date, e.location AS event_location, co.name AS coordinator_name, op.name AS prepared_by_name
        FROM job_forms jf JOIN events e ON e.id = jf.event_id
        LEFT JOIN users co ON co.id = jf.coordinator_id
        LEFT JOIN users op ON op.id = jf.prepared_by
        WHERE jf.id = ? LIMIT 1");
    $stmt->execute([$jobFormId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_abort();
    $action = (string)($_POST['action'] ?? '');
    try {
        $db->beginTransaction();
        $lockStmt = $db->prepare("SELECT jf.*, e.id AS valid_event_id FROM job_forms jf JOIN events e ON e.id = jf.event_id WHERE jf.id = ? FOR UPDATE");
        $lockStmt->execute([$jobFormId]);
        $lockedJobForm = $lockStmt->fetch(PDO::FETCH_ASSOC);
        if (!$lockedJobForm) throw new RuntimeException('Job Form was not found');
        if (($lockedJobForm['status'] ?? '') !== 'finance_approved') throw new RuntimeException('Only Finance-approved Job Forms can be received or paid');
        $payableCents = $jobFormAmount($lockedJobForm['labour_rows'] ?? null);
        if ($payableCents <= 0) throw new RuntimeException('This Job Form has no remaining labour amount to pay');
        $expenseStmt = $db->prepare("SELECT * FROM cashier_expenses WHERE source_type = 'job_form' AND source_id = ? FOR UPDATE");
        $expenseStmt->execute([$jobFormId]);
        $expense = $expenseStmt->fetch(PDO::FETCH_ASSOC);
        if ($action === 'receive_job_form') {
            $label = trim((string)($lockedJobForm['job_form_no'] ?? ''));
            if ($expense) {
                $db->prepare("UPDATE cashier_expenses SET event_id = ?, description = ?, amount = ?, created_by = ? WHERE id = ?")->execute([(int)$lockedJobForm['event_id'], 'Job Form' . ($label !== '' ? ': ' . $label : ' #' . $jobFormId), $centsToDecimal($payableCents), $cashierUserId, (int)$expense['id']]);
            } else {
                $db->prepare("INSERT INTO cashier_expenses (event_id, source_type, source_id, description, amount, created_by) VALUES (?, 'job_form', ?, ?, ?, ?)")->execute([(int)$lockedJobForm['event_id'], $jobFormId, 'Job Form' . ($label !== '' ? ': ' . $label : ' #' . $jobFormId), $centsToDecimal($payableCents), $cashierUserId]);
            }
            $db->commit();
            db_log_activity($cashierUserId, 'cashier_job_form_received', json_encode(['job_form_id' => $jobFormId]));
            flash_add('success', 'Finance-approved Job Form received by Cashier');
        } elseif ($action === 'record_payment') {
            if (!$expense) throw new RuntimeException('Receive this approved Job Form before recording a payment');
            $paymentCents = $moneyToCents($_POST['payment_amount'] ?? 0);
            $paymentMethod = (string)($_POST['payment_method'] ?? '');
            $payee = trim((string)($_POST['payee'] ?? ''));
            $reference = trim((string)($_POST['reference_no'] ?? ''));
            $paymentDate = (string)($_POST['payment_date'] ?? date('Y-m-d'));
            $allowedMethods = ['cash', 'bank', 'mobile_money', 'cheque', 'other'];
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $paymentDate);
            if ($paymentCents <= 0 || !in_array($paymentMethod, $allowedMethods, true) || $payee === '' || !$date || $date->format('Y-m-d') !== $paymentDate) throw new RuntimeException('Valid payment amount, payee, method, and date are required');
            if ((function_exists('mb_strlen') ? mb_strlen($payee, 'UTF-8') : strlen($payee)) > 255 || (function_exists('mb_strlen') ? mb_strlen($reference, 'UTF-8') : strlen($reference)) > 100) throw new RuntimeException('Payee or reference is too long');
            $paymentsLock = $db->prepare('SELECT amount FROM cashier_payments WHERE expense_id = ? FOR UPDATE');
            $paymentsLock->execute([(int)$expense['id']]);
            $paidCents = 0;
            foreach ($paymentsLock->fetchAll(PDO::FETCH_COLUMN) ?: [] as $paidAmount) $paidCents += max(0, $moneyToCents($paidAmount));
            $remainingCents = max(0, $payableCents - $paidCents);
            if ($remainingCents <= 0) throw new RuntimeException('This Job Form has already been paid in full');
            if ($paymentCents > $remainingCents) throw new RuntimeException('Payment cannot exceed the remaining amount of TSh ' . number_format($remainingCents / 100, 2));
            $db->prepare('UPDATE cashier_expenses SET amount = ? WHERE id = ?')->execute([$centsToDecimal($payableCents), (int)$expense['id']]);
            $db->prepare('INSERT INTO cashier_payments (expense_id, amount, payment_method, payee, reference_no, payment_date, paid_by) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([(int)$expense['id'], $centsToDecimal($paymentCents), $paymentMethod, $payee, $reference !== '' ? $reference : null, $paymentDate, $cashierUserId]);
            $fullyPaid = $paymentCents === $remainingCents;
            $db->prepare("UPDATE cashier_expenses SET status = ?, paid_at = ? WHERE id = ?")->execute([$fullyPaid ? 'paid' : 'unpaid', $fullyPaid ? date('Y-m-d H:i:s') : null, (int)$expense['id']]);
            $db->commit();
            db_log_activity($cashierUserId, 'cashier_job_form_payment', json_encode(['job_form_id' => $jobFormId, 'expense_id' => (int)$expense['id'], 'amount' => $centsToDecimal($paymentCents)]));
            flash_add('success', $fullyPaid ? 'Job Form paid in full successfully' : 'Partial Job Form payment recorded successfully');
        } else {
            throw new RuntimeException('Invalid Cashier Job Form action');
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('Cashier Job Form action failed: ' . $e->getMessage());
        flash_add('error', $e instanceof RuntimeException ? $e->getMessage() : 'Job Form action could not be completed');
    }
    header('Location: cashier_job_form.php?job_form_id=' . $jobFormId);
    exit;
}

$jobForm = $loadJobForm();
if (!$jobForm) {
    http_response_code(404);
    echo 'Job Form not found.';
    exit;
}
$labourRows = json_decode((string)($jobForm['labour_rows'] ?? ''), true);
$labourRows = is_array($labourRows) ? $labourRows : [];
$payableCents = $jobFormAmount($jobForm['labour_rows'] ?? null);
$expenseStmt = $db->prepare("SELECT * FROM cashier_expenses WHERE source_type = 'job_form' AND source_id = ? LIMIT 1");
$expenseStmt->execute([$jobFormId]);
$expense = $expenseStmt->fetch(PDO::FETCH_ASSOC);
$payments = [];
$paidCents = 0;
if ($expense) {
    $paymentStmt = $db->prepare('SELECT cp.*, u.name AS cashier_name FROM cashier_payments cp LEFT JOIN users u ON u.id = cp.paid_by WHERE cp.expense_id = ? ORDER BY cp.payment_date DESC, cp.id DESC');
    $paymentStmt->execute([(int)$expense['id']]);
    $payments = $paymentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($payments as $payment) $paidCents += max(0, $moneyToCents($payment['amount'] ?? 0));
}
$remainingCents = max(0, $payableCents - $paidCents);
$flashes = flash_consume();
$displayName = $user['name'] ?? $user['email'] ?? 'Cashier';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cashier Job Form | Event Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"><script src="https://cdn.tailwindcss.com"></script><link rel="stylesheet" href="style.css">
    <style>
        .jf-card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;box-shadow:0 1px 3px rgba(15,23,42,.06)}.jf-table-wrap{overflow-x:auto}.jf-table{width:100%;border-collapse:collapse}.jf-table th{padding:12px 13px;text-align:left;background:#f8fafc;color:#475569;font-size:.72rem;text-transform:uppercase;white-space:nowrap;border-bottom:1px solid #e2e8f0}.jf-table td{padding:13px;color:#334155;border-bottom:1px solid #f1f5f9;vertical-align:top}.jf-field{width:100%;border:1px solid #cbd5e1;border-radius:9px;padding:9px 11px;background:#fff}.badge{display:inline-flex;padding:4px 9px;border-radius:999px;font-size:.72rem;font-weight:700}.approved{background:#dcfce7;color:#166534}.pending{background:#fef3c7;color:#92400e}.not-received{background:#f1f5f9;color:#475569}.back-link{display:inline-flex;align-items:center;gap:7px;margin-bottom:18px;color:#2563eb;font-size:.88rem;font-weight:700;text-decoration:none}
    </style>
</head>
<body>
<div class="dashboard-container"><?php include __DIR__ . '/sidebar.php'; ?><main class="main-content">
    <div class="top-bar"><div class="page-title"><h1>Cashier Job Form</h1><p>View the approved labour cost and record partial payments</p></div><div class="user-menu"><div class="date-display"><i class="far fa-calendar-alt"></i> <?php echo date('l, F j, Y'); ?></div><a href="profile.php" class="user-profile" style="text-decoration:none;color:inherit;"><div class="avatar"><?php echo htmlspecialchars(strtoupper(function_exists('mb_substr') ? mb_substr($displayName, 0, 1) : substr($displayName, 0, 1))); ?></div><div><div style="font-weight:600;"><?php echo htmlspecialchars($displayName); ?></div><div style="font-size:.75rem;color:#64748b;"><?php echo htmlspecialchars(get_role_label($role)); ?></div></div></a></div></div>
    <?php foreach ($flashes as $flash): ?><div class="alert <?php echo ($flash['type'] ?? '') === 'success' ? 'alert-success' : 'alert-error'; ?>" style="margin-bottom:1rem;"><?php echo htmlspecialchars((string)($flash['message'] ?? '')); ?></div><?php endforeach; ?>
    <a class="back-link" href="cashier_job_forms.php"><i class="fas fa-arrow-left"></i> Back to Cashier Job Forms</a>
    <section class="jf-card p-5 mb-6 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4"><div><h2 class="text-xl font-bold text-slate-900"><?php echo htmlspecialchars((string)($jobForm['job_form_no'] ?: 'Job Form #' . $jobFormId)); ?></h2><p class="text-sm text-slate-500 mt-1"><?php echo htmlspecialchars((string)$jobForm['event_name']); ?> · <?php echo htmlspecialchars((string)($jobForm['event_client'] ?: 'No client')); ?> · <?php echo htmlspecialchars((string)($jobForm['event_location'] ?: 'No location')); ?></p><p class="text-sm text-slate-500 mt-1">Finance status: <strong><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)$jobForm['status']))); ?></strong></p></div><div class="flex gap-2"><a class="btn btn-secondary" target="_blank" href="job_forms.php?view=print&amp;job_form_id=<?php echo $jobFormId; ?>"><i class="fas fa-file-lines mr-1"></i> Original Job Form</a><?php if (($jobForm['status'] ?? '') === 'finance_approved' && !$expense): ?><form method="post"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>"><input type="hidden" name="job_form_id" value="<?php echo $jobFormId; ?>"><input type="hidden" name="action" value="receive_job_form"><button class="btn btn-primary" type="submit"><i class="fas fa-download mr-1"></i> Receive Job Form</button></form><?php endif; ?></div></section>
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6"><div class="jf-card p-5"><p class="text-sm text-slate-500">Amount to Pay</p><p class="text-xl font-bold text-violet-700 mt-1">TSh <?php echo number_format($payableCents / 100, 2); ?></p></div><div class="jf-card p-5"><p class="text-sm text-slate-500">Amount Paid</p><p class="text-xl font-bold text-emerald-700 mt-1">TSh <?php echo number_format($paidCents / 100, 2); ?></p></div><div class="jf-card p-5"><p class="text-sm text-slate-500">Remaining Payment</p><p class="text-xl font-bold text-amber-700 mt-1">TSh <?php echo number_format($remainingCents / 100, 2); ?></p></div></div>
    <?php if (($jobForm['status'] ?? '') === 'finance_approved' && $expense && $remainingCents > 0): ?><section class="jf-card p-5 mb-6"><h2 class="text-lg font-bold text-slate-900 mb-4">Record Job Form Payment</h2><form method="post" class="grid grid-cols-1 md:grid-cols-3 gap-3"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>"><input type="hidden" name="job_form_id" value="<?php echo $jobFormId; ?>"><input type="hidden" name="action" value="record_payment"><div><label class="block text-sm font-semibold text-slate-600 mb-1">Payment amount</label><input type="number" class="jf-field" name="payment_amount" min="0.01" max="<?php echo htmlspecialchars($centsToDecimal($remainingCents)); ?>" step="0.01" value="<?php echo htmlspecialchars($centsToDecimal($remainingCents)); ?>" required></div><div><label class="block text-sm font-semibold text-slate-600 mb-1">Payee</label><input class="jf-field" name="payee" maxlength="255" required></div><div><label class="block text-sm font-semibold text-slate-600 mb-1">Method</label><select class="jf-field" name="payment_method" required><option value="">Select method</option><option value="cash">Cash</option><option value="bank">Bank</option><option value="mobile_money">Mobile money</option><option value="cheque">Cheque</option><option value="other">Other</option></select></div><div><label class="block text-sm font-semibold text-slate-600 mb-1">Payment date</label><input type="date" class="jf-field" name="payment_date" value="<?php echo date('Y-m-d'); ?>" required></div><div><label class="block text-sm font-semibold text-slate-600 mb-1">Reference</label><input class="jf-field" name="reference_no" maxlength="100" placeholder="Optional"></div><div class="flex items-end"><button class="btn btn-primary w-full" type="submit"><i class="fas fa-money-bill-wave mr-1"></i> Record Payment</button></div></form></section><?php endif; ?>
    <section class="jf-card mb-6"><div class="p-5 border-b border-slate-200"><h2 class="text-lg font-bold text-slate-900">Job Form Labour</h2></div><div class="jf-table-wrap"><table class="jf-table"><thead><tr><th>Category</th><th>Name</th><th>Total</th><th>Labour Advance</th><th>Amount to Pay</th></tr></thead><tbody><?php foreach ($labourRows as $row): if (!is_array($row)) continue; $rowTotal = $row['total'] ?? ($row['s1_total'] ?? 0); $rowAdvance = $row['advance'] ?? ($row['s1_advance'] ?? 0); $rowBalance = $row['balance'] ?? ($row['s1_balance'] ?? ''); $rowPayable = trim((string)$rowBalance) !== '' ? $rowBalance : $rowTotal; ?><tr><td><?php echo htmlspecialchars((string)($row['category'] ?? ($row['type'] ?? '—'))); ?></td><td><?php echo htmlspecialchars((string)($row['name'] ?? '—')); ?></td><td class="font-bold">TSh <?php echo number_format((float)$rowTotal, 2); ?></td><td class="text-emerald-700 font-bold">TSh <?php echo number_format((float)$rowAdvance, 2); ?></td><td class="text-amber-700 font-bold">TSh <?php echo number_format((float)$rowPayable, 2); ?></td></tr><?php endforeach; ?><?php if (!$labourRows): ?><tr><td colspan="5" class="text-center text-slate-500" style="padding:2rem;">No labour rows found.</td></tr><?php endif; ?></tbody></table></div></section>
    <section class="jf-card"><div class="p-5 border-b border-slate-200"><h2 class="text-lg font-bold text-slate-900">Payment History</h2></div><div class="jf-table-wrap"><table class="jf-table"><thead><tr><th>Date</th><th>Amount</th><th>Payee</th><th>Method</th><th>Reference</th><th>Cashier</th></tr></thead><tbody><?php foreach ($payments as $payment): ?><tr><td><?php echo htmlspecialchars((string)$payment['payment_date']); ?></td><td class="font-bold text-emerald-700">TSh <?php echo number_format((float)$payment['amount'], 2); ?></td><td><?php echo htmlspecialchars((string)$payment['payee']); ?></td><td><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)$payment['payment_method']))); ?></td><td><?php echo htmlspecialchars((string)($payment['reference_no'] ?: '—')); ?></td><td><?php echo htmlspecialchars((string)($payment['cashier_name'] ?: '—')); ?></td></tr><?php endforeach; ?><?php if (!$payments): ?><tr><td colspan="6" class="text-center text-slate-500" style="padding:2rem;">No payments recorded.</td></tr><?php endif; ?></tbody></table></div></section>
</main></div>
</body>
</html>
