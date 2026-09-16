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

$search = trim((string)($_GET['q'] ?? ''));
$where = '';
$params = [];
if ($search !== '') {
    $where = 'WHERE e.name LIKE ? OR e.client LIKE ? OR e.location LIKE ?';
    $like = '%' . $search . '%';
    $params = [$like, $like, $like];
}
$stmt = $db->prepare("SELECT e.id, e.name, e.date, e.created_at, e.client, e.location, e.status,
    COALESCE(e.amount, 0) AS event_amount,
    COALESCE(e.advance, 0) AS event_advance,
    COALESCE(e.balance, COALESCE(e.amount, 0) - COALESCE(e.advance, 0)) AS event_balance,
    COUNT(ce.id) AS expense_count,
    COALESCE(SUM(ce.amount), 0) AS expense_total,
    COALESCE(SUM(COALESCE(pay.amount_paid, 0)), 0) AS paid_expenses,
    COALESCE(SUM(GREATEST(COALESCE(ce.amount, 0) - COALESCE(pay.amount_paid, 0), 0)), 0) AS unpaid_expenses,
    COALESCE(e.amount, 0) - COALESCE(SUM(ce.amount), 0) AS projected_profit,
    MAX(ce.created_at) AS latest_expense_at
    FROM events e
    LEFT JOIN cashier_expenses ce ON ce.event_id = e.id
    LEFT JOIN (SELECT expense_id, SUM(amount) AS amount_paid FROM cashier_payments GROUP BY expense_id) pay ON pay.expense_id = ce.id
    $where
    GROUP BY e.id, e.name, e.date, e.created_at, e.client, e.location, e.status, e.amount, e.advance, e.balance
    ORDER BY CASE WHEN COUNT(ce.id) > 0 THEN 0 ELSE 1 END, MAX(ce.created_at) DESC, COALESCE(e.date, e.created_at) DESC, e.id DESC");
$stmt->execute($params);
$events = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$totals = $db->query("SELECT COUNT(e.id) AS event_count,
    COALESCE(SUM(e.amount), 0) AS event_revenue,
    COALESCE(SUM(e.advance), 0) AS advance_received,
    COALESCE(SUM(COALESCE(e.balance, COALESCE(e.amount, 0) - COALESCE(e.advance, 0))), 0) AS client_balance,
    COALESCE(SUM(ex.expense_total), 0) AS expense_total,
    COALESCE(SUM(COALESCE(e.amount, 0) - COALESCE(ex.expense_total, 0)), 0) AS projected_profit
    FROM events e
    LEFT JOIN (SELECT event_id, SUM(amount) AS expense_total FROM cashier_expenses GROUP BY event_id) ex ON ex.event_id = e.id")->fetch(PDO::FETCH_ASSOC) ?: [];
$displayName = $user['name'] ?? $user['email'] ?? 'Cashier';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Event Expenses | Event Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="style.css">
    <style>
        .expense-card { background:#fff; border:1px solid #e2e8f0; border-radius:16px; box-shadow:0 1px 3px rgba(15,23,42,.06); }
        .expense-table-wrap { overflow-x:auto; }
        .expense-table { width:100%; border-collapse:collapse; }
        .expense-table th { padding:13px 15px; text-align:left; background:#f8fafc; color:#475569; font-size:.73rem; text-transform:uppercase; letter-spacing:.03em; border-bottom:1px solid #e2e8f0; white-space:nowrap; }
        .expense-table td { padding:14px 15px; color:#334155; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
        .expense-table tbody tr:hover { background:#f8fafc; }
        .money-paid { color:#15803d; font-weight:700; white-space:nowrap; }
        .money-unpaid { color:#b45309; font-weight:700; white-space:nowrap; }
        .profit-positive { color:#15803d; font-weight:800; white-space:nowrap; }
        .profit-negative { color:#dc2626; font-weight:800; white-space:nowrap; }
        .view-expenses { display:inline-flex; width:36px; height:36px; align-items:center; justify-content:center; border-radius:10px; background:#eff6ff; color:#2563eb; text-decoration:none; transition:background .2s,color .2s; }
        .view-expenses:hover { background:#2563eb; color:#fff; }
        .search-field { width:min(100%,320px); border:1px solid #cbd5e1; border-radius:9px; padding:9px 12px; background:#fff; }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main class="main-content">
        <div class="top-bar">
            <div class="page-title"><h1>Event Profit & Loss</h1><p>Revenue, client balances, expenses, and profitability for every event</p></div>
            <div class="user-menu">
                <div class="date-display"><i class="far fa-calendar-alt"></i> <?php echo date('l, F j, Y'); ?></div>
                <a href="profile.php" class="user-profile" style="text-decoration:none;color:inherit;"><div class="avatar"><?php echo htmlspecialchars(strtoupper(function_exists('mb_substr') ? mb_substr($displayName, 0, 1) : substr($displayName, 0, 1))); ?></div><div><div style="font-weight:600;"><?php echo htmlspecialchars($displayName); ?></div><div style="font-size:.75rem;color:#64748b;"><?php echo htmlspecialchars(get_role_label($role)); ?></div></div></a>
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
            <div class="expense-card p-5"><div class="flex items-center justify-between"><div><p class="text-sm text-slate-500">Total Event Revenue</p><p class="text-xl font-bold text-slate-900 mt-1">TSh <?php echo number_format((float)($totals['event_revenue'] ?? 0), 2); ?></p></div><div class="w-11 h-11 rounded-xl bg-blue-100 text-blue-600 flex items-center justify-center"><i class="fas fa-sack-dollar"></i></div></div></div>
            <div class="expense-card p-5"><div class="flex items-center justify-between"><div><p class="text-sm text-slate-500">Advance Received</p><p class="text-xl font-bold text-emerald-700 mt-1">TSh <?php echo number_format((float)($totals['advance_received'] ?? 0), 2); ?></p></div><div class="w-11 h-11 rounded-xl bg-emerald-100 text-emerald-600 flex items-center justify-center"><i class="fas fa-hand-holding-dollar"></i></div></div></div>
            <div class="expense-card p-5"><div class="flex items-center justify-between"><div><p class="text-sm text-slate-500">Client Balance</p><p class="text-xl font-bold text-amber-700 mt-1">TSh <?php echo number_format((float)($totals['client_balance'] ?? 0), 2); ?></p></div><div class="w-11 h-11 rounded-xl bg-amber-100 text-amber-600 flex items-center justify-center"><i class="fas fa-hourglass-half"></i></div></div></div>
            <div class="expense-card p-5"><div class="flex items-center justify-between"><div><p class="text-sm text-slate-500">Total Expenses</p><p class="text-xl font-bold text-violet-700 mt-1">TSh <?php echo number_format((float)($totals['expense_total'] ?? 0), 2); ?></p></div><div class="w-11 h-11 rounded-xl bg-violet-100 text-violet-600 flex items-center justify-center"><i class="fas fa-receipt"></i></div></div></div>
            <?php $overallProfit = (float)($totals['projected_profit'] ?? 0); ?>
            <div class="expense-card p-5"><div class="flex items-center justify-between"><div><p class="text-sm text-slate-500">Projected Profit / Loss</p><p class="text-xl mt-1 <?php echo $overallProfit >= 0 ? 'profit-positive' : 'profit-negative'; ?>">TSh <?php echo number_format($overallProfit, 2); ?></p><p class="text-xs text-slate-500 mt-1">Revenue minus all expenses</p></div><div class="w-11 h-11 rounded-xl <?php echo $overallProfit >= 0 ? 'bg-emerald-100 text-emerald-600' : 'bg-red-100 text-red-600'; ?> flex items-center justify-center"><i class="fas fa-chart-line"></i></div></div></div>
            <div class="expense-card p-5"><div class="flex items-center justify-between"><div><p class="text-sm text-slate-500">Tracked Events</p><p class="text-2xl font-bold text-slate-900 mt-1"><?php echo number_format((int)($totals['event_count'] ?? 0)); ?></p></div><div class="w-11 h-11 rounded-xl bg-slate-100 text-slate-600 flex items-center justify-center"><i class="fas fa-calendar-check"></i></div></div></div>
        </div>

        <section class="expense-card">
            <div class="p-5 border-b border-slate-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div><h2 class="text-lg font-bold text-slate-900">Financial Performance by Event</h2><p class="text-sm text-slate-500 mt-1">Profit or loss equals event revenue minus all recorded expenses</p></div>
                <form method="get" class="flex gap-2"><input class="search-field" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search event, client or location"><button class="btn btn-primary" type="submit"><i class="fas fa-search mr-1"></i> Search</button><?php if ($search !== ''): ?><a href="cashier_expenses.php" class="btn btn-secondary">Clear</a><?php endif; ?></form>
            </div>
            <div class="expense-table-wrap">
                <table class="expense-table">
                    <thead><tr><th>Event</th><th>Date</th><th>Revenue</th><th>Advance</th><th>Client Balance</th><th>Expenses</th><th>Unpaid Costs</th><th>Profit / Loss</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($events as $event): ?>
                        <?php $eventProfit = (float)$event['projected_profit']; ?>
                        <tr>
                            <td><strong class="text-slate-900"><?php echo htmlspecialchars((string)($event['name'] ?: 'Untitled Event')); ?></strong><div class="text-xs text-slate-500 mt-1"><?php echo htmlspecialchars((string)($event['client'] ?: 'No client')); ?> · <?php echo htmlspecialchars((string)($event['location'] ?: 'No location')); ?></div></td>
                            <td class="whitespace-nowrap"><?php echo !empty($event['date']) ? htmlspecialchars(date('M j, Y', strtotime((string)$event['date']))) : 'Not scheduled'; ?></td>
                            <td class="font-bold whitespace-nowrap">TSh <?php echo number_format((float)$event['event_amount'], 2); ?></td>
                            <td class="money-paid">TSh <?php echo number_format((float)$event['event_advance'], 2); ?></td>
                            <td class="money-unpaid">TSh <?php echo number_format((float)$event['event_balance'], 2); ?></td>
                            <td class="font-bold whitespace-nowrap">TSh <?php echo number_format((float)$event['expense_total'], 2); ?><div class="text-xs text-slate-500 mt-1"><?php echo number_format((int)$event['expense_count']); ?> records</div></td>
                            <td class="money-unpaid">TSh <?php echo number_format((float)$event['unpaid_expenses'], 2); ?></td>
                            <td class="<?php echo $eventProfit >= 0 ? 'profit-positive' : 'profit-negative'; ?>">TSh <?php echo number_format($eventProfit, 2); ?><div class="text-xs mt-1"><?php echo $eventProfit >= 0 ? 'Profit' : 'Loss'; ?></div></td>
                            <td><a class="view-expenses" href="cashier_event_expenses.php?event_id=<?php echo (int)$event['id']; ?>" title="View event financial details" aria-label="View expenses for <?php echo htmlspecialchars((string)($event['name'] ?: 'event')); ?>"><i class="fas fa-eye"></i></a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$events): ?><tr><td colspan="9" class="text-center text-slate-500" style="padding:2.5rem;">No events found.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>
</body>
</html>
