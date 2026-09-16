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

$eventId = (int)($_GET['event_id'] ?? 0);
if ($eventId <= 0) {
    http_response_code(404);
    echo 'Event not found.';
    exit;
}
$eventStmt = $db->prepare('SELECT id, name, date, end_date, client, location, status, amount, advance, balance FROM events WHERE id = ? LIMIT 1');
$eventStmt->execute([$eventId]);
$event = $eventStmt->fetch(PDO::FETCH_ASSOC);
if (!$event) {
    http_response_code(404);
    echo 'Event not found.';
    exit;
}

$expensesStmt = $db->prepare("SELECT ce.*, creator.name AS created_by_name, COALESCE(pay.amount_paid, 0) AS payment_amount, COALESCE(pay.payment_count, 0) AS payment_count
    FROM cashier_expenses ce
    LEFT JOIN users creator ON creator.id = ce.created_by
    LEFT JOIN (SELECT expense_id, SUM(amount) AS amount_paid, COUNT(*) AS payment_count FROM cashier_payments GROUP BY expense_id) pay ON pay.expense_id = ce.id
    WHERE ce.event_id = ?
    ORDER BY CASE ce.status WHEN 'unpaid' THEN 1 ELSE 2 END, ce.created_at DESC, ce.id DESC");
$expensesStmt->execute([$eventId]);
$expenses = $expensesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$summaryStmt = $db->prepare("SELECT COUNT(ce.id) AS expense_count, COALESCE(SUM(ce.amount), 0) AS total_amount, COALESCE(SUM(COALESCE(pay.amount_paid, 0)), 0) AS paid_amount, COALESCE(SUM(GREATEST(ce.amount - COALESCE(pay.amount_paid, 0), 0)), 0) AS unpaid_amount FROM cashier_expenses ce LEFT JOIN (SELECT expense_id, SUM(amount) AS amount_paid FROM cashier_payments GROUP BY expense_id) pay ON pay.expense_id = ce.id WHERE ce.event_id = ?");
$summaryStmt->execute([$eventId]);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$paymentsStmt = $db->prepare("SELECT cp.*, ce.description, u.name AS paid_by_name FROM cashier_payments cp JOIN cashier_expenses ce ON ce.id = cp.expense_id LEFT JOIN users u ON u.id = cp.paid_by WHERE ce.event_id = ? ORDER BY cp.payment_date DESC, cp.id DESC");
$paymentsStmt->execute([$eventId]);
$eventPayments = $paymentsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$eventAmount = (float)($event['amount'] ?? 0);
$eventAdvance = (float)($event['advance'] ?? 0);
$eventBalance = $event['balance'] !== null ? (float)$event['balance'] : $eventAmount - $eventAdvance;
$totalExpenses = (float)($summary['total_amount'] ?? 0);
$projectedProfit = $eventAmount - $totalExpenses;
$sourceLabels = ['manual' => 'Manual', 'purchase_request' => 'Purchase', 'job_form' => 'Job Form', 'rental' => 'Rental'];
$displayName = $user['name'] ?? $user['email'] ?? 'Cashier';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars((string)$event['name']); ?> Expenses | Event Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="style.css">
    <style>
        .detail-card { background:#fff; border:1px solid #e2e8f0; border-radius:16px; box-shadow:0 1px 3px rgba(15,23,42,.06); }
        .event-summary { display:flex; justify-content:space-between; align-items:center; gap:20px; flex-wrap:wrap; padding:22px; }
        .event-heading { display:flex; align-items:center; gap:14px; }
        .event-icon { width:54px; height:54px; border-radius:15px; background:#dbeafe; color:#2563eb; display:flex; align-items:center; justify-content:center; font-size:1.2rem; }
        .detail-table-wrap { overflow-x:auto; }
        .detail-table { width:100%; border-collapse:collapse; }
        .detail-table th { padding:13px 15px; text-align:left; background:#f8fafc; color:#475569; font-size:.73rem; text-transform:uppercase; letter-spacing:.03em; border-bottom:1px solid #e2e8f0; white-space:nowrap; }
        .detail-table td { padding:14px 15px; color:#334155; border-bottom:1px solid #f1f5f9; vertical-align:top; }
        .source-badge, .status-badge { display:inline-flex; padding:4px 9px; border-radius:999px; font-size:.72rem; font-weight:700; }
        .source-badge { background:#eef2ff; color:#4338ca; }
        .status-paid { background:#dcfce7; color:#166534; }
        .status-unpaid { background:#fef3c7; color:#92400e; }
        .profit-positive { color:#15803d; }
        .profit-negative { color:#dc2626; }
        .back-link { display:inline-flex; align-items:center; gap:7px; margin-bottom:18px; color:#2563eb; font-size:.88rem; font-weight:700; text-decoration:none; }
        .meta { color:#64748b; font-size:.82rem; margin-top:4px; }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main class="main-content">
        <div class="top-bar">
            <div class="page-title"><h1>Event Financial Details</h1><p>Revenue, client payments, expenses, and profit or loss for this event</p></div>
            <div class="user-menu">
                <div class="date-display"><i class="far fa-calendar-alt"></i> <?php echo date('l, F j, Y'); ?></div>
                <a href="profile.php" class="user-profile" style="text-decoration:none;color:inherit;"><div class="avatar"><?php echo htmlspecialchars(strtoupper(function_exists('mb_substr') ? mb_substr($displayName, 0, 1) : substr($displayName, 0, 1))); ?></div><div><div style="font-weight:600;"><?php echo htmlspecialchars($displayName); ?></div><div style="font-size:.75rem;color:#64748b;"><?php echo htmlspecialchars(get_role_label($role)); ?></div></div></a>
            </div>
        </div>

        <a class="back-link" href="cashier_expenses.php"><i class="fas fa-arrow-left"></i> Back to Event Expenses</a>

        <section class="detail-card event-summary mb-6">
            <div class="event-heading"><div class="event-icon"><i class="fas fa-calendar-check"></i></div><div><h2 class="text-xl font-bold text-slate-900 m-0"><?php echo htmlspecialchars((string)($event['name'] ?: 'Untitled Event')); ?></h2><p class="meta"><?php echo htmlspecialchars((string)($event['client'] ?: 'No client')); ?> · <?php echo htmlspecialchars((string)($event['location'] ?: 'No location')); ?></p><p class="meta"><?php echo !empty($event['date']) ? htmlspecialchars(date('M j, Y', strtotime((string)$event['date']))) : 'Not scheduled'; ?> · <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)($event['status'] ?: 'Not set')))); ?></p></div></div>
            <a href="events.php?id=<?php echo (int)$event['id']; ?>&mode=details" class="btn btn-secondary"><i class="fas fa-eye mr-1"></i> View Event</a>
        </section>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <div class="detail-card p-5"><p class="text-sm text-slate-500">Event Revenue</p><p class="text-xl font-bold text-slate-900 mt-1">TSh <?php echo number_format($eventAmount, 2); ?></p></div>
            <div class="detail-card p-5"><p class="text-sm text-slate-500">Advance Received</p><p class="text-xl font-bold text-emerald-700 mt-1">TSh <?php echo number_format($eventAdvance, 2); ?></p></div>
            <div class="detail-card p-5"><p class="text-sm text-slate-500">Client Balance</p><p class="text-xl font-bold text-amber-700 mt-1">TSh <?php echo number_format($eventBalance, 2); ?></p></div>
            <div class="detail-card p-5"><p class="text-sm text-slate-500">Total Expenses</p><p class="text-xl font-bold text-violet-700 mt-1">TSh <?php echo number_format($totalExpenses, 2); ?></p><p class="text-xs text-slate-500 mt-1"><?php echo number_format((int)($summary['expense_count'] ?? 0)); ?> expense records</p></div>
            <div class="detail-card p-5"><p class="text-sm text-slate-500">Paid Expenses</p><p class="text-xl font-bold text-emerald-700 mt-1">TSh <?php echo number_format((float)($summary['paid_amount'] ?? 0), 2); ?></p></div>
            <div class="detail-card p-5"><p class="text-sm text-slate-500">Unpaid Expenses</p><p class="text-xl font-bold text-amber-700 mt-1">TSh <?php echo number_format((float)($summary['unpaid_amount'] ?? 0), 2); ?></p></div>
            <div class="detail-card p-5 sm:col-span-2"><p class="text-sm text-slate-500">Projected Profit / Loss</p><p class="text-2xl font-extrabold mt-1 <?php echo $projectedProfit >= 0 ? 'profit-positive' : 'profit-negative'; ?>">TSh <?php echo number_format($projectedProfit, 2); ?> · <?php echo $projectedProfit >= 0 ? 'Profit' : 'Loss'; ?></p><p class="text-xs text-slate-500 mt-1">Event revenue minus all recorded expenses</p></div>
        </div>

        <section class="detail-card">
            <div class="p-5 border-b border-slate-200"><h2 class="text-lg font-bold text-slate-900">All Event Expenses</h2><p class="text-sm text-slate-500 mt-1">Manual expenses and received purchase, rental, and job form costs</p></div>
            <div class="detail-table-wrap">
                <table class="detail-table">
                    <thead><tr><th>Date</th><th>Source</th><th>Description</th><th>Amount</th><th>Status</th><th>Payment Information</th></tr></thead>
                    <tbody>
                    <?php foreach ($expenses as $expense): ?>
                        <tr>
                            <td class="whitespace-nowrap"><?php echo htmlspecialchars(date('M j, Y', strtotime((string)$expense['created_at']))); ?><div class="meta"><?php echo htmlspecialchars(date('g:i A', strtotime((string)$expense['created_at']))); ?></div></td>
                            <td><span class="source-badge"><?php echo htmlspecialchars($sourceLabels[$expense['source_type']] ?? ucwords(str_replace('_', ' ', (string)$expense['source_type']))); ?></span><?php if (!empty($expense['source_id'])): ?><div class="meta">Reference #<?php echo number_format((int)$expense['source_id']); ?></div><?php endif; ?></td>
                            <td><strong class="text-slate-800"><?php echo htmlspecialchars((string)$expense['description']); ?></strong><div class="meta">Recorded by <?php echo htmlspecialchars((string)($expense['created_by_name'] ?: 'System')); ?></div></td>
                            <td class="font-bold whitespace-nowrap">TSh <?php echo number_format((float)$expense['amount'], 2); ?></td>
                            <td><?php if ($expense['status'] === 'paid'): ?><span class="status-badge status-paid">Paid</span><?php else: ?><span class="status-badge status-unpaid">Unpaid</span><?php endif; ?></td>
                            <td><strong class="text-emerald-700">Paid: TSh <?php echo number_format((float)$expense['payment_amount'], 2); ?></strong><div class="meta">Remaining: TSh <?php echo number_format(max(0, (float)$expense['amount'] - (float)$expense['payment_amount']), 2); ?></div><div class="meta"><?php echo number_format((int)$expense['payment_count']); ?> payment record(s)</div><?php if (($expense['source_type'] ?? '') === 'job_form'): ?><a class="text-blue-600 font-semibold text-xs inline-block mt-2" href="cashier_job_form.php?job_form_id=<?php echo (int)$expense['source_id']; ?>">Open Job Form</a><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$expenses): ?><tr><td colspan="6" class="text-center text-slate-500" style="padding:2.5rem;">No expenses have been recorded for this event.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="detail-card" style="margin-top:1.5rem;">
            <div class="p-5 border-b border-slate-200"><h2 class="text-lg font-bold text-slate-900">All Payment Transactions</h2></div>
            <div class="detail-table-wrap"><table class="detail-table"><thead><tr><th>Date</th><th>Expense</th><th>Amount</th><th>Payee</th><th>Method</th><th>Reference</th><th>Cashier</th></tr></thead><tbody>
            <?php foreach ($eventPayments as $payment): ?><tr><td><?php echo htmlspecialchars((string)$payment['payment_date']); ?></td><td><?php echo htmlspecialchars((string)$payment['description']); ?></td><td class="font-bold text-emerald-700 whitespace-nowrap">TSh <?php echo number_format((float)$payment['amount'], 2); ?></td><td><?php echo htmlspecialchars((string)$payment['payee']); ?></td><td><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)$payment['payment_method']))); ?></td><td><?php echo htmlspecialchars((string)($payment['reference_no'] ?: '—')); ?></td><td><?php echo htmlspecialchars((string)($payment['paid_by_name'] ?: '—')); ?></td></tr><?php endforeach; ?>
            <?php if (!$eventPayments): ?><tr><td colspan="7" class="text-center text-slate-500" style="padding:2.5rem;">No payments recorded for this event.</td></tr><?php endif; ?>
            </tbody></table></div>
        </section>
    </main>
</div>
</body>
</html>
