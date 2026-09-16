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
    $normalized = str_replace(',', '', trim((string)$value));
    $normalized = preg_replace('/[^0-9.\-]/', '', $normalized);
    if (!preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $normalized, $matches)) {
        return 0;
    }
    $fraction = str_pad(substr($matches[3] ?? '', 0, 3), 3, '0');
    $cents = ((int)$matches[2] * 100) + (int)substr($fraction, 0, 2);
    if ((int)$fraction[2] >= 5) {
        $cents++;
    }
    return ($matches[1] ?? '') === '-' ? -$cents : $cents;
};
$centsToDecimal = static function ($cents) {
    $cents = (int)$cents;
    $sign = $cents < 0 ? '-' : '';
    $cents = abs($cents);
    return $sign . intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
};
$jobFormBalance = static function ($labourRows) use ($moneyToCents) {
    $rows = json_decode((string)$labourRows, true);
    $balanceCents = 0;
    foreach (is_array($rows) ? $rows : [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $balanceValue = $row['balance'] ?? ($row['s1_balance'] ?? '');
        $rowBalanceCents = trim((string)$balanceValue) !== '' ? $moneyToCents($balanceValue) : $moneyToCents($row['total'] ?? ($row['s1_total'] ?? 0));
        $balanceCents += max(0, $rowBalanceCents);
    }
    return $balanceCents;
};
$syncApprovedExpenses = static function () use ($db, $cashierUserId, $jobFormBalance, $centsToDecimal) {
    $db->beginTransaction();
    try {
    $db->prepare("DELETE ce FROM cashier_expenses ce LEFT JOIN purchase_requests pr ON pr.id = ce.source_id WHERE ce.source_type = 'purchase_request' AND ce.status = 'unpaid' AND (pr.id IS NULL OR pr.status <> 'approved' OR COALESCE(pr.amount, 0) <= 0 OR NOT EXISTS (SELECT 1 FROM events e WHERE e.id = ce.event_id))")->execute();
    $db->prepare("DELETE ce FROM cashier_expenses ce LEFT JOIN rental_requests rr ON rr.id = ce.source_id WHERE ce.source_type = 'rental' AND ce.status = 'unpaid' AND (rr.id IS NULL OR rr.status <> 'approved' OR GREATEST(COALESCE(rr.amount_inc, 0) - COALESCE(rr.amount_paid, 0), 0) <= 0 OR NOT EXISTS (SELECT 1 FROM events e WHERE e.id = ce.event_id))")->execute();
    $db->prepare("DELETE ce FROM cashier_expenses ce LEFT JOIN job_forms jf ON jf.id = ce.source_id WHERE ce.source_type = 'job_form' AND ce.status = 'unpaid' AND NOT EXISTS (SELECT 1 FROM cashier_payments cp WHERE cp.expense_id = ce.id) AND (jf.id IS NULL OR jf.status <> 'finance_approved' OR NOT EXISTS (SELECT 1 FROM events e WHERE e.id = ce.event_id))")->execute();

    $purchaseSync = $db->prepare("INSERT INTO cashier_expenses (event_id, source_type, source_id, description, amount, created_by) SELECT pr.event_id, 'purchase_request', pr.id, CONCAT('Purchase Request: ', pr.title), pr.amount, ? FROM purchase_requests pr JOIN events e ON e.id = pr.event_id WHERE pr.status = 'approved' AND pr.event_id IS NOT NULL AND pr.amount > 0 ON DUPLICATE KEY UPDATE description = IF(cashier_expenses.status = 'unpaid', VALUES(description), cashier_expenses.description), amount = IF(cashier_expenses.status = 'unpaid', VALUES(amount), cashier_expenses.amount)");
    $purchaseSync->execute([$cashierUserId]);

    $rentalSync = $db->prepare("INSERT INTO cashier_expenses (event_id, source_type, source_id, description, amount, created_by) SELECT rr.event_id, 'rental', rr.id, CONCAT('Rental: ', rr.title), GREATEST(COALESCE(rr.amount_inc, 0) - COALESCE(rr.amount_paid, 0), 0), ? FROM rental_requests rr JOIN events e ON e.id = rr.event_id WHERE rr.status = 'approved' AND rr.event_id IS NOT NULL AND GREATEST(COALESCE(rr.amount_inc, 0) - COALESCE(rr.amount_paid, 0), 0) > 0 ON DUPLICATE KEY UPDATE description = IF(cashier_expenses.status = 'unpaid', VALUES(description), cashier_expenses.description), amount = IF(cashier_expenses.status = 'unpaid', VALUES(amount), cashier_expenses.amount)");
    $rentalSync->execute([$cashierUserId]);

    $jobFormsStmt = $db->query("SELECT jf.id, jf.event_id, jf.job_form_no, jf.labour_rows FROM job_forms jf JOIN events e ON e.id = jf.event_id WHERE jf.status = 'finance_approved'");
    $jobExpenseInsert = $db->prepare("INSERT INTO cashier_expenses (event_id, source_type, source_id, description, amount, created_by) VALUES (?, 'job_form', ?, ?, ?, ?) ON DUPLICATE KEY UPDATE description = IF(cashier_expenses.status = 'unpaid', VALUES(description), cashier_expenses.description), amount = IF(cashier_expenses.status = 'unpaid', VALUES(amount), cashier_expenses.amount)");
    foreach ($jobFormsStmt ? ($jobFormsStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [] as $jobForm) {
        $jobBalanceCents = $jobFormBalance($jobForm['labour_rows'] ?? null);
        if ((int)($jobForm['event_id'] ?? 0) > 0 && $jobBalanceCents > 0) {
            $jobLabel = trim((string)($jobForm['job_form_no'] ?? ''));
            $jobExpenseInsert->execute([(int)$jobForm['event_id'], (int)$jobForm['id'], 'Job Form' . ($jobLabel !== '' ? ': ' . $jobLabel : ' #' . (int)$jobForm['id']), $centsToDecimal($jobBalanceCents), $cashierUserId]);
        } else {
            $removeJobExpense = $db->prepare("DELETE FROM cashier_expenses WHERE source_type = 'job_form' AND source_id = ? AND status = 'unpaid'");
            $removeJobExpense->execute([(int)$jobForm['id']]);
        }
    }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_abort();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'receive_approved') {
            $syncApprovedExpenses();
            db_log_activity($cashierUserId, 'cashier_expenses_received', 'Finance-approved costs received by Cashier');
            flash_add('success', 'Approved purchase, job form, and rental costs received successfully');
        } elseif ($action === 'create_expense') {
            $eventId = (int)($_POST['event_id'] ?? 0);
            $description = trim((string)($_POST['description'] ?? ''));
            $amountCents = $moneyToCents($_POST['amount'] ?? 0);
            if ($eventId <= 0 || $description === '' || $amountCents <= 0) {
                throw new RuntimeException('Event, description, and a positive amount are required');
            }
            if ((function_exists('mb_strlen') ? mb_strlen($description, 'UTF-8') : strlen($description)) > 255) {
                throw new RuntimeException('Expense description cannot exceed 255 characters');
            }
            $eventStmt = $db->prepare('SELECT id FROM events WHERE id = ?');
            $eventStmt->execute([$eventId]);
            if (!$eventStmt->fetchColumn()) {
                throw new RuntimeException('Selected event was not found');
            }
            $amount = $centsToDecimal($amountCents);
            $expenseStmt = $db->prepare("INSERT INTO cashier_expenses (event_id, source_type, description, amount, created_by) VALUES (?, 'manual', ?, ?, ?)");
            $expenseStmt->execute([$eventId, $description, $amount, $cashierUserId]);
            db_log_activity($cashierUserId, 'cashier_expense_create', json_encode(['expense_id' => (int)$db->lastInsertId(), 'event_id' => $eventId, 'amount' => $amount]));
            flash_add('success', 'Event expense recorded successfully');
        } elseif ($action === 'pay_expense') {
            $expenseId = (int)($_POST['expense_id'] ?? 0);
            $paymentMethod = (string)($_POST['payment_method'] ?? '');
            $payee = trim((string)($_POST['payee'] ?? ''));
            $referenceNo = trim((string)($_POST['reference_no'] ?? ''));
            $paymentDate = (string)($_POST['payment_date'] ?? date('Y-m-d'));
            $allowedMethods = ['cash', 'bank', 'mobile_money', 'cheque', 'other'];
            $dateObject = DateTimeImmutable::createFromFormat('!Y-m-d', $paymentDate);
            if ($expenseId <= 0 || !in_array($paymentMethod, $allowedMethods, true) || $payee === '' || !$dateObject || $dateObject->format('Y-m-d') !== $paymentDate) {
                throw new RuntimeException('Valid payee, payment method, and payment date are required');
            }
            $payeeLength = function_exists('mb_strlen') ? mb_strlen($payee, 'UTF-8') : strlen($payee);
            $referenceLength = function_exists('mb_strlen') ? mb_strlen($referenceNo, 'UTF-8') : strlen($referenceNo);
            if ($payeeLength > 255 || $referenceLength > 100) {
                throw new RuntimeException('Payee or reference is too long');
            }

            $db->beginTransaction();
            $expenseStmt = $db->prepare("SELECT ce.id, ce.event_id, ce.source_type, ce.source_id, ce.amount, ce.status FROM cashier_expenses ce JOIN events e ON e.id = ce.event_id WHERE ce.id = ? FOR UPDATE");
            $expenseStmt->execute([$expenseId]);
            $expense = $expenseStmt->fetch(PDO::FETCH_ASSOC);
            if (!$expense) {
                throw new RuntimeException('Expense was not found');
            }
            if (($expense['status'] ?? '') !== 'unpaid') {
                throw new RuntimeException('This expense has already been paid');
            }
            $amountCents = $moneyToCents($expense['amount'] ?? 0);
            if ($amountCents <= 0) {
                throw new RuntimeException('Expense amount must be greater than zero');
            }

            $sourceType = (string)($expense['source_type'] ?? 'manual');
            $sourceId = (int)($expense['source_id'] ?? 0);
            if ($sourceType === 'purchase_request') {
                $sourceStmt = $db->prepare("SELECT status, amount FROM purchase_requests WHERE id = ? FOR UPDATE");
                $sourceStmt->execute([$sourceId]);
                $source = $sourceStmt->fetch(PDO::FETCH_ASSOC);
                if (!$source || ($source['status'] ?? '') !== 'approved') {
                    throw new RuntimeException('Purchase request is no longer approved for payment');
                }
                $amountCents = $moneyToCents($source['amount'] ?? 0);
            } elseif ($sourceType === 'rental') {
                $sourceStmt = $db->prepare("SELECT status, amount_inc, amount_paid FROM rental_requests WHERE id = ? FOR UPDATE");
                $sourceStmt->execute([$sourceId]);
                $source = $sourceStmt->fetch(PDO::FETCH_ASSOC);
                if (!$source || ($source['status'] ?? '') !== 'approved') {
                    throw new RuntimeException('Rental is no longer approved for payment');
                }
                $amountCents = max(0, $moneyToCents($source['amount_inc'] ?? 0) - $moneyToCents($source['amount_paid'] ?? 0));
            } elseif ($sourceType === 'job_form') {
                $sourceStmt = $db->prepare("SELECT status, labour_rows FROM job_forms WHERE id = ? FOR UPDATE");
                $sourceStmt->execute([$sourceId]);
                $source = $sourceStmt->fetch(PDO::FETCH_ASSOC);
                if (!$source || ($source['status'] ?? '') !== 'finance_approved') {
                    throw new RuntimeException('Job form is no longer approved for payment');
                }
                $amountCents = $jobFormBalance($source['labour_rows'] ?? null);
            }
            if ($amountCents <= 0) {
                throw new RuntimeException('There is no outstanding amount to pay');
            }
            $totalPayableCents = $amountCents;
            $existingPayments = $db->prepare('SELECT amount FROM cashier_payments WHERE expense_id = ? FOR UPDATE');
            $existingPayments->execute([$expenseId]);
            $alreadyPaidCents = 0;
            foreach ($existingPayments->fetchAll(PDO::FETCH_COLUMN) ?: [] as $paidAmount) {
                $alreadyPaidCents += max(0, $moneyToCents($paidAmount));
            }
            $remainingCents = max(0, $totalPayableCents - $alreadyPaidCents);
            if ($remainingCents <= 0) {
                throw new RuntimeException('This expense has already been paid in full');
            }
            $amount = $centsToDecimal($remainingCents);
            $db->prepare('UPDATE cashier_expenses SET amount = ? WHERE id = ?')->execute([$centsToDecimal($totalPayableCents), $expenseId]);

            $paymentStmt = $db->prepare('INSERT INTO cashier_payments (expense_id, amount, payment_method, payee, reference_no, payment_date, paid_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $paymentStmt->execute([$expenseId, $amount, $paymentMethod, $payee, $referenceNo !== '' ? $referenceNo : null, $paymentDate, $cashierUserId]);
            $db->prepare("UPDATE cashier_expenses SET status = 'paid', paid_at = NOW() WHERE id = ?")->execute([$expenseId]);
            if (($expense['source_type'] ?? '') === 'rental' && (int)($expense['source_id'] ?? 0) > 0) {
                $db->prepare('UPDATE rental_requests SET amount_paid = amount_inc, balance = 0 WHERE id = ?')->execute([(int)$expense['source_id']]);
                $db->prepare('UPDATE rental_request_items SET amount_paid = amount_inc, balance = 0 WHERE rental_request_id = ?')->execute([(int)$expense['source_id']]);
            }
            $db->commit();
            db_log_activity($cashierUserId, 'cashier_expense_paid', json_encode(['expense_id' => $expenseId, 'event_id' => (int)$expense['event_id'], 'amount' => $amount, 'method' => $paymentMethod]));
            flash_add('success', 'Remaining expense amount paid successfully');
        } else {
            throw new RuntimeException('Invalid cashier action');
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('Cashier action failed: ' . $e->getMessage());
        flash_add('error', $e instanceof RuntimeException ? $e->getMessage() : 'Cashier action could not be completed');
    }
    header('Location: cashier_dashboard');
    exit;
}

$events = $db->query('SELECT id, name, date, client FROM events ORDER BY COALESCE(date, created_at) DESC, id DESC')->fetchAll(PDO::FETCH_ASSOC);
$filterStatus = (string)($_GET['status'] ?? 'all');
$filterSource = (string)($_GET['source'] ?? 'all');
if (!in_array($filterStatus, ['all', 'unpaid', 'paid'], true)) {
    $filterStatus = 'all';
}
if (!in_array($filterSource, ['all', 'manual', 'purchase_request', 'job_form', 'rental'], true)) {
    $filterSource = 'all';
}
$where = [];
$params = [];
if ($filterStatus !== 'all') {
    $where[] = 'ce.status = ?';
    $params[] = $filterStatus;
}
if ($filterSource !== 'all') {
    $where[] = 'ce.source_type = ?';
    $params[] = $filterSource;
}
$expensesStmt = $db->prepare("SELECT ce.*, e.name AS event_name, e.client AS event_client, COALESCE(ps.amount_paid, 0) AS amount_paid, cp.payment_method, cp.payee, cp.reference_no, cp.payment_date FROM cashier_expenses ce JOIN events e ON e.id = ce.event_id LEFT JOIN (SELECT expense_id, SUM(amount) AS amount_paid, MAX(id) AS last_payment_id FROM cashier_payments GROUP BY expense_id) ps ON ps.expense_id = ce.id LEFT JOIN cashier_payments cp ON cp.id = ps.last_payment_id " . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . " ORDER BY CASE ce.status WHEN 'unpaid' THEN 1 ELSE 2 END, ce.created_at DESC, ce.id DESC");
$expensesStmt->execute($params);
$expenses = $expensesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$stats = $db->query("SELECT COALESCE(SUM(ce.amount),0) AS total_expenses, COALESCE(SUM(GREATEST(ce.amount - COALESCE(ps.amount_paid, 0), 0)),0) AS unpaid_amount, COALESCE(SUM(COALESCE(ps.amount_paid, 0)),0) AS paid_amount, SUM(CASE WHEN ce.status='unpaid' THEN 1 ELSE 0 END) AS unpaid_count, SUM(CASE WHEN ce.status='paid' THEN 1 ELSE 0 END) AS paid_count FROM cashier_expenses ce LEFT JOIN (SELECT expense_id, SUM(amount) AS amount_paid FROM cashier_payments GROUP BY expense_id) ps ON ps.expense_id = ce.id")->fetch(PDO::FETCH_ASSOC) ?: [];
$eventFinancials = $db->query("SELECT COUNT(*) AS event_count, COALESCE(SUM(amount), 0) AS total_revenue, COALESCE(SUM(advance), 0) AS total_advance, COALESCE(SUM(COALESCE(balance, COALESCE(amount, 0) - COALESCE(advance, 0))), 0) AS total_balance FROM events")->fetch(PDO::FETCH_ASSOC) ?: [];
$projectedProfit = (float)($eventFinancials['total_revenue'] ?? 0) - (float)($stats['total_expenses'] ?? 0);
$payments = $db->query("SELECT cp.*, ce.description, ce.source_type, e.name AS event_name, u.name AS cashier_name FROM cashier_payments cp JOIN cashier_expenses ce ON ce.id = cp.expense_id JOIN events e ON e.id = ce.event_id LEFT JOIN users u ON u.id = cp.paid_by ORDER BY cp.payment_date DESC, cp.id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$selectedMonth = (string)($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $selectedMonth)) {
    $selectedMonth = date('Y-m');
}
$monthStart = $selectedMonth . '-01';
$monthEnd = date('Y-m-d', strtotime($monthStart . ' +1 month'));
$daysInMonth = (int)date('t', strtotime($monthStart));
$expenseLabels = [];
$dailyExpenseAmounts = [];
for ($day = 1; $day <= $daysInMonth; $day++) {
    $expenseLabels[] = str_pad((string)$day, 2, '0', STR_PAD_LEFT);
    $dailyExpenseAmounts[$day] = 0.0;
}
$expenseTrendStmt = $db->prepare('SELECT DAY(created_at) AS expense_day, SUM(amount) AS daily_amount FROM cashier_expenses WHERE created_at >= ? AND created_at < ? GROUP BY DAY(created_at) ORDER BY expense_day');
$expenseTrendStmt->execute([$monthStart, $monthEnd]);
foreach ($expenseTrendStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $trendRow) {
    $expenseDay = (int)($trendRow['expense_day'] ?? 0);
    if (isset($dailyExpenseAmounts[$expenseDay])) {
        $dailyExpenseAmounts[$expenseDay] = round((float)($trendRow['daily_amount'] ?? 0), 2);
    }
}
$dailyExpenseAmounts = array_values($dailyExpenseAmounts);
$monthlyExpenseTotal = array_sum($dailyExpenseAmounts);
$displayMonth = date('F Y', strtotime($monthStart));
$previousMonth = date('Y-m', strtotime($monthStart . ' -1 month'));
$nextMonth = date('Y-m', strtotime($monthStart . ' +1 month'));
$currentMonth = date('Y-m');
$flashes = flash_consume();
$displayName = $user['name'] ?? $user['email'] ?? 'Cashier';
$sourceLabels = ['manual' => 'Manual', 'purchase_request' => 'Purchase', 'job_form' => 'Job Form', 'rental' => 'Rental'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cashier Dashboard | Event Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <link rel="stylesheet" href="style.css">
    <style>
        .cashier-card { background:#fff; border:1px solid #e2e8f0; border-radius:16px; box-shadow:0 1px 3px rgba(15,23,42,.06); }
        .cashier-stat { min-height:122px; display:flex; flex-direction:column; justify-content:space-between; overflow:hidden; }
        .cashier-stat-value { font-size:1.45rem; line-height:1.2; overflow-wrap:anywhere; }
        .cashier-stat-link { display:flex; align-items:center; gap:.35rem; padding-top:.75rem; border-top:1px solid rgba(255,255,255,.22); color:#fff; font-size:.75rem; font-weight:600; }
        .cashier-stat-link:hover { text-decoration:underline; }
        .cashier-chart-wrap { position:relative; height:300px; }
        .cashier-action { display:flex; flex-direction:column; align-items:center; justify-content:center; min-height:112px; padding:1rem; border-radius:14px; text-align:center; color:#334155; transition:transform .2s ease, background-color .2s ease; }
        .cashier-action:hover { transform:translateY(-2px); }
        .cashier-action-icon { width:44px; height:44px; border-radius:12px; display:flex; align-items:center; justify-content:center; margin-bottom:.65rem; font-size:1rem; }
        button.cashier-action { width:100%; border:0; cursor:pointer; font:inherit; }
        .cashier-table-wrap { overflow-x:auto; }
        .cashier-table { width:100%; border-collapse:collapse; }
        .cashier-table th { padding:12px 14px; text-align:left; background:#f8fafc; color:#475569; font-size:.72rem; text-transform:uppercase; border-bottom:1px solid #e2e8f0; }
        .cashier-table td { padding:13px 14px; color:#334155; border-bottom:1px solid #f1f5f9; vertical-align:top; }
        .cashier-field { width:100%; border:1px solid #cbd5e1; border-radius:9px; padding:9px 11px; background:#fff; }
        .source-badge, .payment-badge { display:inline-flex; padding:4px 9px; border-radius:999px; font-size:.72rem; font-weight:700; }
        .source-badge { background:#eef2ff; color:#4338ca; }
        .payment-badge { background:#dcfce7; color:#166534; }
        .pay-form { min-width:250px; display:grid; gap:7px; grid-template-columns:1fr 1fr; }
        .pay-form .full { grid-column:1/-1; }
        @media (max-width:640px) { .main-content { padding-left:1rem; padding-right:1rem; } .cashier-chart-wrap { height:240px; } }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main class="main-content">
        <div class="top-bar">
            <div class="page-title"><h1>Cashier Dashboard</h1><p>Record and pay approved event expenses</p></div>
            <div class="user-menu">
                <div class="date-display"><i class="far fa-calendar-alt"></i> <?php echo date('l, F j, Y'); ?></div>
                <a href="profile.php" class="user-profile" style="text-decoration:none;color:inherit;"><div class="avatar"><?php echo htmlspecialchars(strtoupper(function_exists('mb_substr') ? mb_substr($displayName, 0, 1) : substr($displayName, 0, 1))); ?></div><div><div style="font-weight:600;"><?php echo htmlspecialchars($displayName); ?></div><div style="font-size:.75rem;color:#64748b;"><?php echo htmlspecialchars(get_role_label($role)); ?></div></div></a>
            </div>
        </div>

        <?php foreach ($flashes as $flash): ?><div class="alert <?php echo ($flash['type'] ?? '') === 'success' ? 'alert-success' : 'alert-error'; ?>" style="margin-bottom:1rem;"><?php echo htmlspecialchars((string)($flash['message'] ?? '')); ?></div><?php endforeach; ?>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5 mb-6">
            <div class="cashier-stat bg-gradient-to-br from-blue-500 to-blue-600 rounded-2xl p-5 text-white shadow-lg">
                <div class="flex justify-between items-start"><div><div class="text-sm font-medium text-blue-100">Total Event Revenue</div><div class="cashier-stat-value font-bold mt-2">TSh <?php echo number_format((float)($eventFinancials['total_revenue'] ?? 0), 2); ?></div><span class="inline-flex mt-2 text-xs bg-white bg-opacity-20 px-2 py-1 rounded-full"><?php echo number_format((int)($eventFinancials['event_count'] ?? 0)); ?> events tracked</span></div><div class="p-3 bg-white bg-opacity-20 rounded-xl"><i class="fas fa-sack-dollar text-xl"></i></div></div>
                <a href="cashier_expenses.php" class="cashier-stat-link">View event finances <i class="fas fa-arrow-right text-xs"></i></a>
            </div>
            <div class="cashier-stat bg-gradient-to-br from-emerald-500 to-green-600 rounded-2xl p-5 text-white shadow-lg">
                <div class="flex justify-between items-start"><div><div class="text-sm font-medium text-emerald-100">Advance Received</div><div class="cashier-stat-value font-bold mt-2">TSh <?php echo number_format((float)($eventFinancials['total_advance'] ?? 0), 2); ?></div><span class="inline-flex mt-2 text-xs bg-white bg-opacity-20 px-2 py-1 rounded-full">Client payments collected</span></div><div class="p-3 bg-white bg-opacity-20 rounded-xl"><i class="fas fa-hand-holding-dollar text-xl"></i></div></div>
                <a href="cashier_expenses.php" class="cashier-stat-link">Track client payments <i class="fas fa-arrow-right text-xs"></i></a>
            </div>
            <div class="cashier-stat bg-gradient-to-br from-amber-500 to-orange-500 rounded-2xl p-5 text-white shadow-lg">
                <div class="flex justify-between items-start"><div><div class="text-sm font-medium text-amber-100">Client Balance</div><div class="cashier-stat-value font-bold mt-2">TSh <?php echo number_format((float)($eventFinancials['total_balance'] ?? 0), 2); ?></div><span class="inline-flex mt-2 text-xs bg-white bg-opacity-20 px-2 py-1 rounded-full">Outstanding event revenue</span></div><div class="p-3 bg-white bg-opacity-20 rounded-xl"><i class="fas fa-hourglass-half text-xl"></i></div></div>
                <a href="cashier_expenses.php" class="cashier-stat-link">View outstanding balances <i class="fas fa-arrow-right text-xs"></i></a>
            </div>
            <div class="cashier-stat bg-gradient-to-br from-violet-500 to-purple-600 rounded-2xl p-5 text-white shadow-lg">
                <div class="flex justify-between items-start"><div><div class="text-sm font-medium text-violet-100">Total Expenses</div><div class="cashier-stat-value font-bold mt-2">TSh <?php echo number_format((float)($stats['total_expenses'] ?? 0), 2); ?></div><span class="inline-flex mt-2 text-xs bg-white bg-opacity-20 px-2 py-1 rounded-full">All recorded event costs</span></div><div class="p-3 bg-white bg-opacity-20 rounded-xl"><i class="fas fa-receipt text-xl"></i></div></div>
                <a href="cashier_expenses.php" class="cashier-stat-link">View expenses by event <i class="fas fa-arrow-right text-xs"></i></a>
            </div>
            <div class="cashier-stat bg-gradient-to-br <?php echo $projectedProfit >= 0 ? 'from-teal-500 to-emerald-600' : 'from-red-500 to-rose-600'; ?> rounded-2xl p-5 text-white shadow-lg">
                <div class="flex justify-between items-start"><div><div class="text-sm font-medium text-white text-opacity-80">Projected Profit / Loss</div><div class="cashier-stat-value font-bold mt-2">TSh <?php echo number_format($projectedProfit, 2); ?></div><span class="inline-flex mt-2 text-xs bg-white bg-opacity-20 px-2 py-1 rounded-full"><?php echo $projectedProfit >= 0 ? 'Projected profit' : 'Projected loss'; ?></span></div><div class="p-3 bg-white bg-opacity-20 rounded-xl"><i class="fas fa-chart-line text-xl"></i></div></div>
                <a href="cashier_expenses.php" class="cashier-stat-link">View profit and loss <i class="fas fa-arrow-right text-xs"></i></a>
            </div>
            <div class="cashier-stat bg-gradient-to-br from-slate-600 to-slate-700 rounded-2xl p-5 text-white shadow-lg">
                <div class="flex justify-between items-start"><div><div class="text-sm font-medium text-slate-200">Unpaid Expenses</div><div class="cashier-stat-value font-bold mt-2">TSh <?php echo number_format((float)($stats['unpaid_amount'] ?? 0), 2); ?></div><span class="inline-flex mt-2 text-xs bg-white bg-opacity-20 px-2 py-1 rounded-full"><?php echo number_format((int)($stats['unpaid_count'] ?? 0)); ?> awaiting payment</span></div><div class="p-3 bg-white bg-opacity-20 rounded-xl"><i class="fas fa-money-bill-wave text-xl"></i></div></div>
                <a href="?status=unpaid#expenses" class="cashier-stat-link">Pay outstanding costs <i class="fas fa-arrow-right text-xs"></i></a>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
            <section class="cashier-card lg:col-span-2 overflow-hidden" style="min-width:0;">
                <div class="p-5 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <div><h2 class="text-lg font-semibold text-slate-800">Expense Trend - <?php echo htmlspecialchars($displayMonth); ?></h2><p class="text-sm text-slate-500 mt-1">Daily event expenses received by Cashier</p></div>
                    <div class="flex items-center gap-2"><select class="cashier-field" aria-label="Expense trend month" onchange="location='?month='+encodeURIComponent(this.value);"><?php for ($monthOffset = 0; $monthOffset < 12; $monthOffset++): $monthValue = date('Y-m', strtotime(date('Y-m-01') . ' -' . $monthOffset . ' months')); ?><option value="<?php echo htmlspecialchars($monthValue); ?>" <?php echo $selectedMonth === $monthValue ? 'selected' : ''; ?>><?php echo htmlspecialchars(date('F Y', strtotime($monthValue . '-01'))); ?></option><?php endfor; ?></select><div class="flex border border-slate-200 rounded-lg overflow-hidden"><a href="?month=<?php echo htmlspecialchars($previousMonth); ?>" class="px-3 py-2 bg-white hover:bg-slate-50 text-slate-600" title="Previous month"><i class="fas fa-chevron-left text-xs"></i></a><a href="?month=<?php echo htmlspecialchars($currentMonth); ?>" class="px-3 py-2 bg-white hover:bg-slate-50 text-slate-600 border-l border-r border-slate-200" title="Current month"><i class="far fa-calendar-alt text-xs"></i></a><a href="?month=<?php echo htmlspecialchars($nextMonth); ?>" class="px-3 py-2 bg-white hover:bg-slate-50 text-slate-600" title="Next month"><i class="fas fa-chevron-right text-xs"></i></a></div></div>
                </div>
                <div class="p-5"><div class="cashier-chart-wrap"><canvas id="cashierExpenseChart"></canvas></div></div>
                <div class="px-5 py-3 bg-slate-50 border-t border-slate-100 flex items-center justify-between gap-3"><span class="text-sm text-slate-500"><strong class="text-slate-700">TSh <?php echo number_format($monthlyExpenseTotal, 2); ?></strong> in <?php echo htmlspecialchars($displayMonth); ?></span><span class="flex items-center text-xs text-slate-500"><span class="w-2 h-2 rounded-full bg-blue-500 mr-2"></span>Daily Expenses</span></div>
            </section>

            <section class="cashier-card p-5">
                <h2 class="text-lg font-semibold text-slate-800 mb-4">Quick Actions</h2>
                <div class="grid grid-cols-2 gap-3">
                    <a href="#manual-expense" class="cashier-action bg-blue-50 hover:bg-blue-100"><span class="cashier-action-icon bg-blue-100 text-blue-600"><i class="fas fa-plus"></i></span><span class="text-sm font-medium">New Expense</span></a>
                    <form method="post"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>"><input type="hidden" name="action" value="receive_approved"><button type="submit" class="cashier-action bg-emerald-50 hover:bg-emerald-100"><span class="cashier-action-icon bg-emerald-100 text-emerald-600"><i class="fas fa-download"></i></span><span class="text-sm font-medium">Receive Costs</span></button></form>
                    <a href="purchase_requests.php" class="cashier-action bg-violet-50 hover:bg-violet-100"><span class="cashier-action-icon bg-violet-100 text-violet-600"><i class="fas fa-cart-shopping"></i></span><span class="text-sm font-medium">Purchases</span></a>
                    <a href="rentals.php" class="cashier-action bg-amber-50 hover:bg-amber-100"><span class="cashier-action-icon bg-amber-100 text-amber-600"><i class="fas fa-truck-ramp-box"></i></span><span class="text-sm font-medium">Rentals</span></a>
                    <a href="cashier_job_forms.php" class="cashier-action bg-cyan-50 hover:bg-cyan-100"><span class="cashier-action-icon bg-cyan-100 text-cyan-600"><i class="fas fa-clipboard-list"></i></span><span class="text-sm font-medium">Job Forms</span></a>
                    <a href="cashier_expenses.php" class="cashier-action bg-rose-50 hover:bg-rose-100"><span class="cashier-action-icon bg-rose-100 text-rose-600"><i class="fas fa-chart-line"></i></span><span class="text-sm font-medium">Profit & Loss</span></a>
                </div>
            </section>
        </div>

        <section id="manual-expense" class="cashier-card p-5 mb-6" style="scroll-margin-top:1rem;">
            <h2 class="text-lg font-bold text-slate-900 mb-4"><i class="fas fa-plus-circle text-blue-600 mr-2"></i>Record Manual Event Expense</h2>
            <form method="post" class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                <input type="hidden" name="action" value="create_expense">
                <div><label class="block text-sm font-semibold text-slate-600 mb-1">Event</label><select name="event_id" class="cashier-field" required><option value="">Select event</option><?php foreach ($events as $event): ?><option value="<?php echo (int)$event['id']; ?>"><?php echo htmlspecialchars((string)$event['name']); ?><?php echo !empty($event['date']) ? ' · ' . htmlspecialchars((string)$event['date']) : ''; ?></option><?php endforeach; ?></select></div>
                <div><label class="block text-sm font-semibold text-slate-600 mb-1">Expense description</label><input name="description" class="cashier-field" maxlength="255" required placeholder="Transport, supplies, labour..."></div>
                <div><label class="block text-sm font-semibold text-slate-600 mb-1">Amount</label><input type="number" name="amount" class="cashier-field" min="0.01" step="0.01" required></div>
                <button class="btn btn-primary" type="submit"><i class="fas fa-save mr-1"></i> Record Expense</button>
            </form>
        </section>

        <section id="expenses" class="cashier-card mb-6" style="scroll-margin-top:1rem;">
            <div class="p-5 border-b border-slate-200 flex flex-wrap items-end justify-between gap-3"><div><h2 class="text-lg font-bold text-slate-900">Received Expenses</h2><p class="text-sm text-slate-500 mt-1">Finance-approved purchases, job forms, rentals, and manual expenses</p></div><form method="get" class="flex flex-wrap gap-2"><select name="status" class="cashier-field"><option value="all">All statuses</option><option value="unpaid" <?php echo $filterStatus === 'unpaid' ? 'selected' : ''; ?>>Unpaid</option><option value="paid" <?php echo $filterStatus === 'paid' ? 'selected' : ''; ?>>Paid</option></select><select name="source" class="cashier-field"><option value="all">All sources</option><?php foreach ($sourceLabels as $key => $label): ?><option value="<?php echo htmlspecialchars($key); ?>" <?php echo $filterSource === $key ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option><?php endforeach; ?></select><button class="btn btn-secondary" type="submit">Filter</button></form></div>
            <div class="cashier-table-wrap"><table class="cashier-table"><thead><tr><th>Event</th><th>Source</th><th>Description</th><th>Amount</th><th>Status</th><th>Payment</th></tr></thead><tbody>
            <?php foreach ($expenses as $expense): ?><tr><td><strong><?php echo htmlspecialchars((string)$expense['event_name']); ?></strong><div class="text-xs text-slate-500 mt-1"><?php echo htmlspecialchars((string)($expense['event_client'] ?? '')); ?></div></td><td><span class="source-badge"><?php echo htmlspecialchars($sourceLabels[$expense['source_type']] ?? ucfirst((string)$expense['source_type'])); ?></span></td><td><?php echo htmlspecialchars((string)$expense['description']); ?></td><td class="font-bold whitespace-nowrap">TSh <?php echo number_format((float)$expense['amount'], 2); ?><?php if ((float)($expense['amount_paid'] ?? 0) > 0 && $expense['status'] === 'unpaid'): ?><div class="text-xs text-emerald-600 mt-1">Paid: TSh <?php echo number_format((float)$expense['amount_paid'], 2); ?></div><div class="text-xs text-amber-600 mt-1">Remaining: TSh <?php echo number_format(max(0, (float)$expense['amount'] - (float)$expense['amount_paid']), 2); ?></div><?php endif; ?></td><td><?php if ($expense['status'] === 'paid'): ?><span class="payment-badge">Paid</span><?php else: ?><span class="source-badge" style="background:#fef3c7;color:#92400e;">Unpaid</span><?php endif; ?></td><td><?php if ($expense['status'] === 'unpaid'): ?><form method="post" class="pay-form"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>"><input type="hidden" name="action" value="pay_expense"><input type="hidden" name="expense_id" value="<?php echo (int)$expense['id']; ?>"><input name="payee" class="cashier-field full" maxlength="255" required placeholder="Payee / recipient"><select name="payment_method" class="cashier-field" required><option value="">Method</option><option value="cash">Cash</option><option value="bank">Bank</option><option value="mobile_money">Mobile money</option><option value="cheque">Cheque</option><option value="other">Other</option></select><input type="date" name="payment_date" class="cashier-field" value="<?php echo date('Y-m-d'); ?>" required><input name="reference_no" class="cashier-field full" maxlength="100" placeholder="Reference (optional)"><button type="submit" class="btn btn-primary full"><i class="fas fa-money-bill-wave mr-1"></i> Pay Remaining Amount</button></form><?php else: ?><div><strong><?php echo htmlspecialchars((string)($expense['payee'] ?? '')); ?></strong><div class="text-xs text-slate-500 mt-1"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)($expense['payment_method'] ?? '')))); ?> · <?php echo htmlspecialchars((string)($expense['payment_date'] ?? '')); ?></div><?php if (!empty($expense['reference_no'])): ?><div class="text-xs text-slate-500">Ref: <?php echo htmlspecialchars((string)$expense['reference_no']); ?></div><?php endif; ?></div><?php endif; ?></td></tr><?php endforeach; ?>
            <?php if (!$expenses): ?><tr><td colspan="6" class="text-center text-slate-500" style="padding:2.5rem;">No expenses found.</td></tr><?php endif; ?>
            </tbody></table></div>
        </section>

        <section id="payments" class="cashier-card" style="scroll-margin-top:1rem;"><div class="p-5 border-b border-slate-200"><h2 class="text-lg font-bold text-slate-900">Recent Payments</h2></div><div class="cashier-table-wrap"><table class="cashier-table"><thead><tr><th>Date</th><th>Event</th><th>Expense</th><th>Payee</th><th>Method</th><th>Reference</th><th>Amount</th><th>Cashier</th></tr></thead><tbody><?php foreach ($payments as $payment): ?><tr><td><?php echo htmlspecialchars((string)$payment['payment_date']); ?></td><td><?php echo htmlspecialchars((string)$payment['event_name']); ?></td><td><?php echo htmlspecialchars((string)$payment['description']); ?></td><td><?php echo htmlspecialchars((string)$payment['payee']); ?></td><td><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)$payment['payment_method']))); ?></td><td><?php echo htmlspecialchars((string)($payment['reference_no'] ?: '—')); ?></td><td class="font-bold whitespace-nowrap">TSh <?php echo number_format((float)$payment['amount'], 2); ?></td><td><?php echo htmlspecialchars((string)($payment['cashier_name'] ?? '')); ?></td></tr><?php endforeach; ?><?php if (!$payments): ?><tr><td colspan="8" class="text-center text-slate-500" style="padding:2.5rem;">No payments recorded.</td></tr><?php endif; ?></tbody></table></div></section>
    </main>
</div>
<script>
(function () {
    var canvas = document.getElementById('cashierExpenseChart');
    if (!canvas || typeof Chart === 'undefined') return;
    var labels = <?php echo json_encode($expenseLabels); ?>;
    var amounts = <?php echo json_encode($dailyExpenseAmounts, JSON_NUMERIC_CHECK); ?>;
    new Chart(canvas, {
        type: 'line',
        data: { labels: labels, datasets: [{ label: 'Expenses', data: amounts, borderColor: '#3b82f6', backgroundColor: 'rgba(59,130,246,.1)', borderWidth: 2, fill: true, tension: .35, pointBackgroundColor: '#3b82f6', pointBorderColor: '#fff', pointBorderWidth: 2, pointRadius: 3, pointHoverRadius: 5 }] },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { intersect: false, mode: 'index' },
            plugins: {
                legend: { display: true, position: 'top', labels: { boxWidth: 10, usePointStyle: true, pointStyle: 'circle' } },
                tooltip: { callbacks: { label: function (context) { return 'Expenses: TSh ' + Number(context.parsed.y || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); } } }
            },
            scales: {
                x: { grid: { display: false }, ticks: { maxRotation: 45, minRotation: 45 } },
                y: { beginAtZero: true, grid: { color: 'rgba(15,23,42,.06)' }, ticks: { callback: function (value) { return 'TSh ' + Number(value).toLocaleString(); } } }
            }
        }
    });
})();
</script>
</body>
</html>
