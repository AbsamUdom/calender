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

$cashierPrintView = $role === 'cashier' && ($_GET['view'] ?? '') === 'print' && (int)($_GET['job_form_id'] ?? 0) > 0;
if (!in_array($role, ['operation', 'admin', 'super'], true) && !$cashierPrintView) {
    header('Location: index.php');
    exit;
}

$pageTitle = 'Job Forms';
$displayName = $user['name'] ?? $user['email'] ?? 'User';
$flashes = flash_consume();

$action = $_GET['action'] ?? '';
$jobFormId = (int)($_GET['job_form_id'] ?? 0);

$isPrintView = isset($_GET['view']) && (string)($_GET['view'] ?? '') === 'print';
$printJobFormId = (int)($_GET['job_form_id'] ?? 0);

$events = [];
try {
    $stmt = $db->query("SELECT e.id, e.name, e.client, e.location, e.date, e.coordinator_id, u.name AS coordinator_name, u.phone AS coordinator_phone
                        FROM events e
                        LEFT JOIN users u ON u.id = e.coordinator_id
                        WHERE e.coordinator_id IS NOT NULL
                        ORDER BY COALESCE(e.date,'9999-12-31') DESC, e.id DESC");
    $events = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable $e) {
    $events = [];
}

if ($isPrintView && $printJobFormId > 0) {
    try {
        $st = $db->prepare("SELECT jf.*, e.name AS event_name, e.client AS event_client, e.location AS event_location, e.date AS event_date, co.name AS coordinator_name, co.phone AS coordinator_phone
                            FROM job_forms jf
                            JOIN events e ON e.id = jf.event_id
                            LEFT JOIN users co ON co.id = jf.coordinator_id
                            WHERE jf.id = ?");
        $st->execute([$printJobFormId]);
        $jf = $st->fetch(PDO::FETCH_ASSOC);
        if (!$jf) {
            throw new Exception('Job Form not found');
        }

        $rows = [];
        try {
            $decoded = json_decode((string)($jf['labour_rows'] ?? ''), true);
            $rows = is_array($decoded) ? $decoded : [];
        } catch (Throwable $e) {
            $rows = [];
        }
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
        <title>Job Form #<?php echo (int)$printJobFormId; ?></title>
        <link rel="stylesheet" href="style.css">
        <style>
            @media print {
                .no-print { display:none !important; }
                body { background:#fff !important; }
            }
            body { background:#fff; }
            .jf-page { max-width: 1100px; margin: 0 auto; padding: 24px; }
            .jf-title { font-size: 20px; font-weight: 800; letter-spacing: 0.08em; }
            .jf-meta { font-size: 12px; color: #334155; }
            .jf-grid { display:grid; grid-template-columns: 1fr 1fr 1fr; gap: 8px; margin-top: 10px; }
            .jf-box { border: 1px solid #111827; padding: 10px 12px; font-size: 12px; }
            .jf-label { font-weight: 800; }
            table.jf-table { width:100%; border-collapse: collapse; margin-top: 12px; }
            table.jf-table th, table.jf-table td { border: 1px solid #111827; padding: 6px 6px; font-size: 11px; }
            table.jf-table th { background: #f1f5f9; text-align:left; }
        </style>
    </head>
    <body>
    <div class="jf-page">
        <div class="no-print" style="display:flex; justify-content:space-between; margin-bottom:12px;">
            <a class="btn btn-secondary" href="job_forms.php">Back</a>
            <button class="btn btn-primary" onclick="window.print()">Print</button>
        </div>

        <div style="display:flex; align-items:flex-start; justify-content:space-between; gap: 12px;">
            <div>
                <div class="jf-title">JOB FORM</div>
                <div class="jf-meta">Event labour breakdown</div>
            </div>
            <div class="jf-box" style="min-width:240px;">
                <div class="jf-meta"><span class="jf-label">Job Form No:</span> <?php echo htmlspecialchars((string)($jf['job_form_no'] ?? $printJobFormId)); ?></div>
                <div class="jf-meta"><span class="jf-label">Status:</span> <?php echo htmlspecialchars((string)($jf['status'] ?? '')); ?></div>
            </div>
        </div>

        <div class="jf-grid">
            <div class="jf-box"><span class="jf-label">Event:</span> <?php echo htmlspecialchars((string)($jf['event_name'] ?? '')); ?></div>
            <div class="jf-box"><span class="jf-label">Client:</span> <?php echo htmlspecialchars((string)($jf['event_client'] ?? '')); ?></div>
            <div class="jf-box"><span class="jf-label">Date:</span> <?php echo htmlspecialchars((string)($jf['event_date'] ?? '')); ?></div>
            <div class="jf-box"><span class="jf-label">Place:</span> <?php echo htmlspecialchars((string)($jf['event_location'] ?? '')); ?></div>
            <div class="jf-box"><span class="jf-label">Coordinator:</span> <?php echo htmlspecialchars((string)($jf['coordinator_name'] ?? '')); ?></div>
            <div class="jf-box"><span class="jf-label">Contact:</span> <?php echo htmlspecialchars((string)($jf['coordinator_phone'] ?? '')); ?></div>
            <div class="jf-box"><span class="jf-label">Supervisor Incharge:</span> <?php echo htmlspecialchars((string)($jf['supervisor_incharge'] ?? '')); ?></div>
            <div class="jf-box"><span class="jf-label">A/Supervisor:</span> <?php echo htmlspecialchars((string)($jf['a_supervisor'] ?? '')); ?></div>
        </div>

        <table class="jf-table">
            <thead>
            <tr>
                <th style="width:140px;">Category</th>
                <th style="width:180px;">Name</th>
                <th>Shift1 Job</th>
                <th>Shift1 Food</th>
                <th>Shift1 Transport</th>
                <th>Shift2 Job</th>
                <th>Shift2 Food</th>
                <th>Shift2 Transport</th>
                <th>Shift3 Job</th>
                <th>Shift3 Food</th>
                <th>Shift3 Transport</th>
                <th>Shift4 Job</th>
                <th>Shift4 Food</th>
                <th>Shift4 Transport</th>
                <th style="width:80px;">Total</th>
                <th style="width:80px;">Advance</th>
                <th style="width:80px;">Balance</th>
                <th style="width:120px;">Signature</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): if (!is_array($r)) continue; ?>
                <tr>
                    <td><?php echo htmlspecialchars((string)($r['category'] ?? ($r['type'] ?? ''))); ?></td>
                    <td><?php echo htmlspecialchars((string)($r['name'] ?? '')); ?></td>
                    <td><?php echo htmlspecialchars((string)($r['s1_job'] ?? '')); ?></td>
                    <td><?php echo htmlspecialchars((string)($r['s1_food'] ?? '')); ?></td>
                    <td><?php echo htmlspecialchars((string)($r['s1_transport'] ?? '')); ?></td>
                    <td><?php echo htmlspecialchars((string)($r['s2_job'] ?? '')); ?></td>
                    <td><?php echo htmlspecialchars((string)($r['s2_food'] ?? '')); ?></td>
                    <td><?php echo htmlspecialchars((string)($r['s2_transport'] ?? '')); ?></td>
                    <td><?php echo htmlspecialchars((string)($r['s3_job'] ?? '')); ?></td>
                    <td><?php echo htmlspecialchars((string)($r['s3_food'] ?? '')); ?></td>
                    <td><?php echo htmlspecialchars((string)($r['s3_transport'] ?? '')); ?></td>
                    <td><?php echo htmlspecialchars((string)($r['s4_job'] ?? '')); ?></td>
                    <td><?php echo htmlspecialchars((string)($r['s4_food'] ?? '')); ?></td>
                    <td><?php echo htmlspecialchars((string)($r['s4_transport'] ?? '')); ?></td>
                    <td><?php echo htmlspecialchars((string)($r['total'] ?? ($r['s1_total'] ?? ''))); ?></td>
                    <td><?php echo htmlspecialchars((string)($r['advance'] ?? ($r['s1_advance'] ?? ''))); ?></td>
                    <td><?php echo htmlspecialchars((string)($r['balance'] ?? ($r['s1_balance'] ?? ''))); ?></td>
                    <td><?php echo htmlspecialchars((string)($r['signature'] ?? ($r['s1_signature'] ?? ''))); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <div class="jf-grid" style="grid-template-columns: 1fr 1fr 1fr; margin-top: 10px;">
            <div class="jf-box"><span class="jf-label">Received By:</span> <?php echo htmlspecialchars((string)($jf['received_by'] ?? '')); ?></div>
            <div class="jf-box"><span class="jf-label">Given By:</span> <?php echo htmlspecialchars((string)($jf['given_by'] ?? '')); ?></div>
            <div class="jf-box"><span class="jf-label">Approved By:</span> <?php echo htmlspecialchars((string)($jf['approved_by'] ?? '')); ?></div>
        </div>
    </div>
    </body>
    </html>
    <?php
    exit;
}

$edit = null;
$editLabourRows = [];
if ($action === 'edit' && $jobFormId > 0) {
    try {
        $st = $db->prepare("SELECT jf.*, e.name AS event_name, e.client AS event_client, e.date AS event_date, u.name AS coordinator_name
                            FROM job_forms jf
                            JOIN events e ON e.id = jf.event_id
                            LEFT JOIN users u ON u.id = jf.coordinator_id
                            WHERE jf.id = ?");
        $st->execute([$jobFormId]);
        $edit = $st->fetch(PDO::FETCH_ASSOC);

        if ($edit && !in_array((string)($edit['status'] ?? ''), ['draft', 'coordinator_rejected', 'finance_rejected'], true)) {
            $edit = null;
            flash_add('error', 'Only draft or rejected Job Forms can be edited.');
        }

        if ($edit) {
            $decoded = [];
            try {
                $decoded = json_decode((string)($edit['labour_rows'] ?? ''), true);
            } catch (Throwable $e) {
                $decoded = [];
            }
            $editLabourRows = is_array($decoded) ? $decoded : [];
        }
    } catch (Throwable $e) {
        $edit = null;
        $editLabourRows = [];
        flash_add('error', 'Unable to load Job Form.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_abort();
    $postAction = $_POST['action'] ?? '';

    try {
        if ($postAction === 'create_or_update') {
            $jfId = (int)($_POST['job_form_id'] ?? 0);
            $eventId = (int)($_POST['event_id'] ?? 0);
            $saveMode = ($_POST['save_mode'] ?? '') === 'submit' ? 'submit' : 'draft';

            $jobFormNo = trim((string)($_POST['job_form_no'] ?? ''));
            $supervisorIncharge = trim((string)($_POST['supervisor_incharge'] ?? ''));
            $aSupervisor = trim((string)($_POST['a_supervisor'] ?? ''));
            $receivedBy = trim((string)($_POST['received_by'] ?? ''));
            $givenBy = trim((string)($_POST['given_by'] ?? ''));
            $approvedBy = trim((string)($_POST['approved_by'] ?? ''));

            if ($eventId <= 0) {
                throw new Exception('Event is required');
            }

            $ev = $db->prepare("SELECT id, coordinator_id FROM events WHERE id = ?");
            $ev->execute([$eventId]);
            $eventRow = $ev->fetch(PDO::FETCH_ASSOC);
            if (!$eventRow) {
                throw new Exception('Invalid event');
            }

            $coordinatorId = (int)($eventRow['coordinator_id'] ?? 0);
            if ($coordinatorId <= 0) {
                throw new Exception('This event has no coordinator assigned');
            }

            $rowNames = $_POST['row_name'] ?? [];
            $rowCategories = $_POST['row_category'] ?? [];
            $fields = [
                's1_job','s1_food','s1_transport',
                's2_job','s2_food','s2_transport',
                's3_job','s3_food','s3_transport',
                's4_job','s4_food','s4_transport',
                'total','advance','balance','signature',
            ];
            if (!is_array($rowNames)) {
                throw new Exception('Invalid labour rows');
            }
            if (!is_array($rowCategories)) {
                throw new Exception('Invalid labour categories');
            }

            $payload = [];
            $rowCount = count($rowNames);
            for ($i = 0; $i < $rowCount; $i++) {
                $rName = trim((string)($rowNames[$i] ?? ''));
                $rCategory = trim((string)($rowCategories[$i] ?? ''));
                $row = ['category' => $rCategory, 'name' => $rName];
                $hasAny = ($rName !== '' || $rCategory !== '');

                foreach ($fields as $f) {
                    $col = $_POST[$f] ?? [];
                    $val = is_array($col) ? trim((string)($col[$i] ?? '')) : '';
                    if ($val !== '') $hasAny = true;
                    $row[$f] = $val;
                }

                if (!$hasAny) {
                    continue;
                }
                if ($rCategory === '') {
                    throw new Exception('Category is required');
                }
                if ($rName === '') {
                    throw new Exception('Name is required');
                }
                $payload[] = $row;
            }

            if (!$payload) {
                throw new Exception('Please add at least one named row');
            }

            $labourRowsJson = json_encode($payload);
            if ($labourRowsJson === false) {
                throw new Exception('Failed to encode labour rows');
            }

            $db->beginTransaction();

            if ($jfId > 0) {
                $st = $db->prepare("SELECT status FROM job_forms WHERE id = ?");
                $st->execute([$jfId]);
                $currStatus = (string)($st->fetchColumn() ?? '');
                if (!in_array($currStatus, ['draft', 'coordinator_rejected', 'finance_rejected'], true)) {
                    throw new Exception('This Job Form can no longer be edited');
                }

                $newStatus = $saveMode === 'submit' ? 'submitted' : 'draft';
                $submittedAt = $saveMode === 'submit' ? date('Y-m-d H:i:s') : null;
                $upd = $db->prepare("UPDATE job_forms SET event_id=?, coordinator_id=?, status=?, submitted_at=?, labour_rows=?, job_form_no=?, supervisor_incharge=?, a_supervisor=?, received_by=?, given_by=?, approved_by=? WHERE id=?");
                $upd->execute([$eventId, $coordinatorId, $newStatus, $submittedAt, $labourRowsJson, ($jobFormNo !== '' ? $jobFormNo : null), ($supervisorIncharge !== '' ? $supervisorIncharge : null), ($aSupervisor !== '' ? $aSupervisor : null), ($receivedBy !== '' ? $receivedBy : null), ($givenBy !== '' ? $givenBy : null), ($approvedBy !== '' ? $approvedBy : null), $jfId]);

                $jobFormIdNew = $jfId;
            } else {
                $newStatus = $saveMode === 'submit' ? 'submitted' : 'draft';
                $submittedAt = $saveMode === 'submit' ? date('Y-m-d H:i:s') : null;

                $ins = $db->prepare("INSERT INTO job_forms(event_id, prepared_by, coordinator_id, status, submitted_at, labour_rows, job_form_no, supervisor_incharge, a_supervisor, received_by, given_by, approved_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
                $ins->execute([$eventId, (int)($user['id'] ?? 0), $coordinatorId, $newStatus, $submittedAt, $labourRowsJson, ($jobFormNo !== '' ? $jobFormNo : null), ($supervisorIncharge !== '' ? $supervisorIncharge : null), ($aSupervisor !== '' ? $aSupervisor : null), ($receivedBy !== '' ? $receivedBy : null), ($givenBy !== '' ? $givenBy : null), ($approvedBy !== '' ? $approvedBy : null)]);
                $jobFormIdNew = (int)$db->lastInsertId();
                if ($jobFormIdNew <= 0) {
                    throw new Exception('Failed to create Job Form');
                }
            }

            $db->commit();

            if (function_exists('db_log_activity')) {
                db_log_activity((int)($user['id'] ?? 0), 'job_form_' . ($saveMode === 'submit' ? 'submit' : 'save'), json_encode(['job_form_id' => $jobFormIdNew, 'event_id' => $eventId]));
            }

            flash_add('success', $saveMode === 'submit' ? 'Job Form submitted to Coordinator.' : 'Job Form saved as draft.');
        }
    } catch (Throwable $ex) {
        if ($db && $db->inTransaction()) {
            $db->rollBack();
        }
        flash_add('error', $ex->getMessage());
    }

    header('Location: job_forms.php');
    exit;
}

$myForms = [];
try {
    $stmt = $db->prepare("SELECT jf.*, e.name AS event_name, e.client AS event_client, e.date AS event_date, u.name AS coordinator_name
                          FROM job_forms jf
                          JOIN events e ON e.id = jf.event_id
                          LEFT JOIN users u ON u.id = jf.coordinator_id
                          WHERE jf.prepared_by = ?
                          ORDER BY jf.created_at DESC, jf.id DESC");
    $stmt->execute([(int)($user['id'] ?? 0)]);
    $myForms = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $myForms = [];
}
$formStats = ['total' => count($myForms), 'draft' => 0, 'review' => 0, 'approved' => 0];
foreach ($myForms as $form) {
    $formStatus = (string)($form['status'] ?? 'draft');
    if (in_array($formStatus, ['draft', 'coordinator_rejected', 'finance_rejected'], true)) {
        $formStats['draft']++;
    } elseif ($formStatus === 'finance_approved') {
        $formStats['approved']++;
    } else {
        $formStats['review']++;
    }
}

$defaultRows = 12;
$defaultRowNames = [
    'Florist',
    'Electrician',
    'Carpenter',
    'Any/Artisy',
    'Employed',
    'Casuals',
    'AOB'
];
$renderRows = [];
if ($editLabourRows) {
    foreach ($editLabourRows as $r) {
        if (is_array($r)) $renderRows[] = $r;
    }
}

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
        .jf-hidden { display:none !important; }
        .jf-editor { padding:0; overflow:hidden; border-radius:16px; box-shadow:0 1px 3px rgba(15,23,42,.06); }
        .jf-editor > .section-header { padding:18px 20px; margin:0; border-bottom:1px solid #e2e8f0; background:linear-gradient(135deg,#f8fafc,#fff); }
        .jf-editor form { padding:20px; }
        .jf-form-stack { display:grid; grid-template-columns:1fr; gap:20px; }
        .jf-panel { border:1px solid #e2e8f0; border-radius:14px; padding:18px; background:#fff; }
        .jf-document-panel { background:linear-gradient(135deg,#eff6ff 0%,#fff 55%); border-color:#bfdbfe; }
        .jf-step-title { display:flex; align-items:center; gap:10px; margin:0 0 14px; color:#0f172a; font-size:1rem; font-weight:800; }
        .jf-step-number { width:28px; height:28px; display:inline-flex; align-items:center; justify-content:center; border-radius:9px; background:#2563eb; color:#fff; font-size:.78rem; }
        .jf-shift-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; grid-column:1/-1; }
        .jf-shift-box { padding:12px; border:1px solid #e2e8f0; border-radius:12px; background:#f8fafc; }
        .jf-shift-inputs { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:7px; }
        .jf-actions { display:flex; justify-content:flex-end; gap:10px; flex-wrap:wrap; padding:16px 18px; border:1px solid #bfdbfe; border-radius:14px; background:#eff6ff; position:sticky; bottom:10px; z-index:5; }
        .jf-history { border-radius:16px; overflow:hidden; box-shadow:0 1px 3px rgba(15,23,42,.06); }
        .jf-status { display:inline-flex; padding:5px 9px; border-radius:999px; font-size:.72rem; font-weight:700; text-transform:capitalize; }
        .jf-status-draft { background:#f1f5f9; color:#475569; }
        .jf-status-review { background:#fef3c7; color:#92400e; }
        .jf-status-approved { background:#dcfce7; color:#166534; }
        .jf-status-rejected { background:#fee2e2; color:#991b1b; }
        .jf-action-link { display:inline-flex; align-items:center; justify-content:center; gap:5px; padding:6px 9px; border-radius:8px; background:#eff6ff; color:#1d4ed8; font-size:.75rem; font-weight:700; text-decoration:none; }
        @media (max-width:768px) { .jf-shift-grid { grid-template-columns:1fr; } .jf-editor form { padding:14px; } .jf-panel { padding:14px; } .jf-actions { position:static; } }
        @media (max-width:520px) { .jf-shift-inputs { grid-template-columns:1fr; } }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php $activePage = 'job_forms.php'; include __DIR__ . '/sidebar.php'; ?>

    <main class="main-content">
        <div class="top-bar">
            <div class="page-title">
                <h1><?php echo htmlspecialchars($pageTitle); ?></h1>
                <p>Prepare Job Forms and submit to Coordinator</p>
            </div>
            <div class="user-menu">
                <div class="date-display"><i class="far fa-calendar-alt"></i> <?php echo date('l, F j, Y'); ?></div>
                <div class="user-profile" id="jobFormUserProfile">
                    <div class="avatar"><?php echo htmlspecialchars(strtoupper(mb_substr($displayName, 0, 1))); ?></div>
                    <div>
                        <div style="font-weight:600;"><?php echo htmlspecialchars($displayName); ?></div>
                        <div style="font-size:0.75rem;color:#64748b;"><?php echo htmlspecialchars(get_role_label($user['role'] ?? 'user')); ?></div>
                    </div>
                    <i class="fas fa-chevron-down" style="color:#64748b;"></i>
                    <div class="profile-dropdown" id="jobFormHeaderProfileDropdown">
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

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-5">
            <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-2xl p-5 text-white shadow"><div class="flex justify-between items-start"><div><p class="text-sm text-blue-100">My Job Forms</p><p class="text-2xl font-bold mt-1"><?php echo number_format($formStats['total']); ?></p></div><div class="p-3 bg-white bg-opacity-20 rounded-xl"><i class="fas fa-file-lines"></i></div></div></div>
            <div class="bg-gradient-to-br from-slate-500 to-slate-600 rounded-2xl p-5 text-white shadow"><div class="flex justify-between items-start"><div><p class="text-sm text-slate-100">Draft / Returned</p><p class="text-2xl font-bold mt-1"><?php echo number_format($formStats['draft']); ?></p></div><div class="p-3 bg-white bg-opacity-20 rounded-xl"><i class="fas fa-pen-to-square"></i></div></div></div>
            <div class="bg-gradient-to-br from-amber-500 to-orange-500 rounded-2xl p-5 text-white shadow"><div class="flex justify-between items-start"><div><p class="text-sm text-amber-100">Under Review</p><p class="text-2xl font-bold mt-1"><?php echo number_format($formStats['review']); ?></p></div><div class="p-3 bg-white bg-opacity-20 rounded-xl"><i class="fas fa-hourglass-half"></i></div></div></div>
            <div class="bg-gradient-to-br from-emerald-500 to-green-600 rounded-2xl p-5 text-white shadow"><div class="flex justify-between items-start"><div><p class="text-sm text-emerald-100">Finance Approved</p><p class="text-2xl font-bold mt-1"><?php echo number_format($formStats['approved']); ?></p></div><div class="p-3 bg-white bg-opacity-20 rounded-xl"><i class="fas fa-circle-check"></i></div></div></div>
        </div>

        <div class="content-section jf-editor">
            <div class="section-header" style="align-items:center;">
                <div><h2 class="section-title"><?php echo $edit ? 'Edit Job Form' : 'Create Job Form'; ?></h2><p style="margin:4px 0 0;color:#64748b;font-size:.8rem;">Complete the sections below, save a draft, or submit it to the assigned Coordinator.</p></div>
                <?php if ($edit && (int)($edit['id'] ?? 0) > 0): ?>
                    <div class="flex gap-2"><a class="btn btn-secondary" href="job_forms.php">Cancel Edit</a><a class="btn btn-secondary" target="_blank" href="job_forms.php?view=print&amp;job_form_id=<?php echo (int)($edit['id'] ?? 0); ?>"><i class="fas fa-print mr-1"></i> Print</a></div>
                <?php endif; ?>
            </div>

            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                <input type="hidden" name="action" value="create_or_update">
                <input type="hidden" name="job_form_id" value="<?php echo (int)($edit['id'] ?? 0); ?>">

                <div class="jf-form-stack">
                    <div class="jf-panel jf-document-panel" style="display:grid;grid-template-columns:1fr;gap:14px;">
                        <div style="display:flex; align-items:flex-start; justify-content:space-between; gap: 1rem; flex-wrap:wrap;">
                            <div>
                                <div style="font-size: 1.25rem; font-weight: 800; letter-spacing: 0.08em;">JOB FORM</div>
                                <div style="font-size: 0.75rem; color:#64748b;">Operations → Coordinator → Finance</div>
                            </div>
                            <div style="display:flex; align-items:center; gap:0.5rem;">
                                <div style="font-size:0.875rem; font-weight:600;">Job Form No:</div>
                                <input class="form-input" name="job_form_no" value="<?php echo htmlspecialchars((string)($edit['job_form_no'] ?? '')); ?>" placeholder="JOB FORM NO" style="width: 160px;">
                            </div>
                        </div>

                        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 0.75rem;">
                            <div>
                                <label class="form-label">Supervisor Incharge</label>
                                <input class="form-input" name="supervisor_incharge" value="<?php echo htmlspecialchars((string)($edit['supervisor_incharge'] ?? '')); ?>" placeholder="Supervisor Incharge">
                            </div>
                            <div>
                                <label class="form-label">A/Supervisor</label>
                                <input class="form-input" name="a_supervisor" value="<?php echo htmlspecialchars((string)($edit['a_supervisor'] ?? '')); ?>" placeholder="A/Supervisor">
                            </div>
                            <div>
                                <label class="form-label">Contact No. (Coordinator)</label>
                                <input class="form-input" id="coordinatorContact" value="" placeholder="Coordinator phone" readonly>
                            </div>
                            <div>
                                <label class="form-label">Date of event</label>
                                <input class="form-input" id="eventDate" value="" placeholder="YYYY-MM-DD" readonly>
                            </div>
                            <div style="grid-column: 1 / -1;">
                                <label class="form-label">Place of Event</label>
                                <input class="form-input" id="eventPlace" value="" placeholder="Event location" readonly>
                            </div>
                        </div>
                    </div>

                    <section class="jf-panel">
                        <h3 class="jf-step-title"><span class="jf-step-number">1</span> Select Event</h3>
                        <label class="form-label">Event</label>
                        <select class="form-select" name="event_id" id="eventSelect" required>
                            <option value="">Select event</option>
                            <?php
                            $selectedEventId = (int)($edit['event_id'] ?? 0);
                            foreach ($events as $ev) {
                                $evId = (int)($ev['id'] ?? 0);
                                $label = trim((string)($ev['name'] ?? ''));
                                $client = trim((string)($ev['client'] ?? ''));
                                $co = trim((string)($ev['coordinator_name'] ?? ''));
                                $date = (string)($ev['date'] ?? '');
                                $text = $label;
                                if ($client !== '') $text .= ' - ' . $client;
                                if ($date !== '') $text .= ' (' . $date . ')';
                                if ($co !== '') $text .= ' / ' . $co;
                                $loc = (string)($ev['location'] ?? '');
                                $phone = (string)($ev['coordinator_phone'] ?? '');
                                $sel = ($selectedEventId > 0 && $evId === $selectedEventId) ? 'selected' : '';
                                echo '<option value="' . $evId . '" ' . $sel . ' data-date="' . htmlspecialchars($date) . '" data-location="' . htmlspecialchars($loc) . '" data-phone="' . htmlspecialchars($phone) . '">' . htmlspecialchars($text) . '</option>';
                            }
                            ?>
                        </select>
                        <div class="text-xs" style="color:#64748b; margin-top:0.5rem;">Only events with an assigned Coordinator can have a Job Form.</div>
                    </section>

                    <?php
                    $categories = ['Florist','Electrician','Carpenter','Any/Artisy','Employed','Casuals','AOB'];
                    $fields = [
                        's1_job','s1_food','s1_transport',
                        's2_job','s2_food','s2_transport',
                        's3_job','s3_food','s3_transport',
                        's4_job','s4_food','s4_transport',
                        'total','advance','balance','signature',
                    ];

                    function jf_val($row, $key) {
                        if (!is_array($row)) return '';
                        $val = (string)($row[$key] ?? '');
                        if ($val !== '') return $val;
                        if ($key === 'total') return (string)($row['s1_total'] ?? '');
                        if ($key === 'advance') return (string)($row['s1_advance'] ?? '');
                        if ($key === 'balance') return (string)($row['s1_balance'] ?? '');
                        if ($key === 'signature') return (string)($row['s1_signature'] ?? '');
                        return '';
                    }
                    ?>

                    <section class="jf-panel">
                        <h3 class="jf-step-title"><span class="jf-step-number">2</span> Add Labour Entry</h3>
                        <p style="margin:-8px 0 16px;color:#64748b;font-size:.8rem;">Enter the worker, shift costs, advance, and signature, then add the row to the Job Form.</p>
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:14px;">
                            <div class="form-group">
                                <label class="form-label">Category *</label>
                                <select class="form-select" id="jfAddCategory">
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Name *</label>
                                <input class="form-input" id="jfQuickName" placeholder="Person name">
                            </div>

                            <div class="jf-shift-grid">
                            <div class="form-group jf-shift-box">
                                <label class="form-label">Shift 1</label>
                                <div class="jf-shift-inputs">
                                    <input class="form-input" id="jfQuickS1Job" placeholder="Job">
                                    <input class="form-input" id="jfQuickS1Food" placeholder="Food">
                                    <input class="form-input" id="jfQuickS1Transport" placeholder="Transport">
                                </div>
                            </div>

                            <div class="form-group jf-shift-box">
                                <label class="form-label">Shift 2</label>
                                <div class="jf-shift-inputs">
                                    <input class="form-input" id="jfQuickS2Job" placeholder="Job">
                                    <input class="form-input" id="jfQuickS2Food" placeholder="Food">
                                    <input class="form-input" id="jfQuickS2Transport" placeholder="Transport">
                                </div>
                            </div>

                            <div class="form-group jf-shift-box">
                                <label class="form-label">Shift 3</label>
                                <div class="jf-shift-inputs">
                                    <input class="form-input" id="jfQuickS3Job" placeholder="Job">
                                    <input class="form-input" id="jfQuickS3Food" placeholder="Food">
                                    <input class="form-input" id="jfQuickS3Transport" placeholder="Transport">
                                </div>
                            </div>

                            <div class="form-group jf-shift-box">
                                <label class="form-label">Shift 4</label>
                                <div class="jf-shift-inputs">
                                    <input class="form-input" id="jfQuickS4Job" placeholder="Job">
                                    <input class="form-input" id="jfQuickS4Food" placeholder="Food">
                                    <input class="form-input" id="jfQuickS4Transport" placeholder="Transport">
                                </div>
                            </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Advance</label>
                                <input class="form-input" id="jfQuickAdvance" placeholder="Advance">
                            </div>

                            <div class="form-group">
                                <label class="form-label">Signature</label>
                                <input class="form-input" id="jfQuickSignature" placeholder="Signature">
                            </div>
                        </div>

                        <div class="flex gap-2" style="margin-top: 1rem; flex-wrap: wrap; justify-content:flex-end;">
                            <button type="button" class="btn btn-primary" id="jfAddRow"><i class="fas fa-plus mr-1"></i> Add Labour</button>
                        </div>
                    </section>

                    <section class="jf-panel" style="padding:0;overflow:hidden;">
                    <div style="padding:18px;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;gap:12px;">
                        <div><h3 class="jf-step-title" style="margin:0;"><span class="jf-step-number">3</span> Labour Added</h3><p style="margin:5px 0 0;color:#64748b;font-size:.8rem;">Review and edit all worker costs before saving or submitting.</p></div>
                    </div>

                    <div class="overflow-x-auto" style="background:#fff;">
                        <table class="w-full" style="min-width: 1200px;">
                            <thead>
                            <tr class="border-b border-gray-200" style="background:#f1f5f9;">
                                <th class="text-left py-3 px-4 text-gray-600 font-medium" style="width:70px;">S/N</th>
                                <th class="text-left py-3 px-4 text-gray-600 font-medium" style="width:160px;">Category</th>
                                <th class="text-left py-3 px-4 text-gray-600 font-medium" style="width:200px;">Name</th>
                                <th class="text-left py-3 px-4 text-gray-600 font-medium">Shift 1 (J/F/T)</th>
                                <th class="text-left py-3 px-4 text-gray-600 font-medium">Shift 2 (J/F/T)</th>
                                <th class="text-left py-3 px-4 text-gray-600 font-medium">Shift 3 (J/F/T)</th>
                                <th class="text-left py-3 px-4 text-gray-600 font-medium">Shift 4 (J/F/T)</th>
                                <th class="text-left py-3 px-4 text-gray-600 font-medium" style="width:120px;">Total</th>
                                <th class="text-left py-3 px-4 text-gray-600 font-medium" style="width:120px;">Advance</th>
                                <th class="text-left py-3 px-4 text-gray-600 font-medium" style="width:120px;">Balance</th>
                                <th class="text-left py-3 px-4 text-gray-600 font-medium" style="width:160px;">Signature</th>
                                <th class="text-right py-3 px-4 text-gray-600 font-medium" style="width:70px;">&nbsp;</th>
                            </tr>
                            </thead>
                            <tbody id="jfItemsBody">
                            <?php
                            $rowsByCat = [];
                            foreach ($renderRows as $existing) {
                                $rowCategory = trim((string)($existing['category'] ?? ($existing['type'] ?? '')));
                                if ($rowCategory === '') $rowCategory = 'AOB';
                                if (!isset($rowsByCat[$rowCategory])) $rowsByCat[$rowCategory] = [];
                                $rowsByCat[$rowCategory][] = $existing;
                            }
                            $sn = 1;
                            foreach ($categories as $catName):
                                $catRows = $rowsByCat[$catName] ?? [];
                                foreach ($catRows as $existing):
                                    $rowName = (string)($existing['name'] ?? '');
                            ?>
                                <tr class="border-b border-gray-100 jf-row" data-category="<?php echo htmlspecialchars($catName); ?>">
                                    <td class="py-3 px-4 text-gray-700 jf-item-no"><?php echo (int)$sn; ?></td>
                                    <td class="py-3 px-4">
                                        <select class="form-select" name="row_category[]" required>
                                            <?php foreach ($categories as $cat):
                                                $sel = (strcasecmp($catName, $cat) === 0) ? 'selected' : '';
                                            ?>
                                                <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $sel; ?>><?php echo htmlspecialchars($cat); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td class="py-3 px-4">
                                        <input class="form-input" name="row_name[]" value="<?php echo htmlspecialchars($rowName); ?>" placeholder="Person name" required>
                                    </td>
                                    <td class="py-3 px-4">
                                        <div style="display:grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap:0.35rem;">
                                            <input class="form-input" name="s1_job[]" value="<?php echo htmlspecialchars(jf_val($existing, 's1_job')); ?>" placeholder="Job">
                                            <input class="form-input" name="s1_food[]" value="<?php echo htmlspecialchars(jf_val($existing, 's1_food')); ?>" placeholder="Food">
                                            <input class="form-input" name="s1_transport[]" value="<?php echo htmlspecialchars(jf_val($existing, 's1_transport')); ?>" placeholder="Transport">
                                        </div>
                                    </td>
                                    <td class="py-3 px-4">
                                        <div style="display:grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap:0.35rem;">
                                            <input class="form-input" name="s2_job[]" value="<?php echo htmlspecialchars(jf_val($existing, 's2_job')); ?>" placeholder="Job">
                                            <input class="form-input" name="s2_food[]" value="<?php echo htmlspecialchars(jf_val($existing, 's2_food')); ?>" placeholder="Food">
                                            <input class="form-input" name="s2_transport[]" value="<?php echo htmlspecialchars(jf_val($existing, 's2_transport')); ?>" placeholder="Transport">
                                        </div>
                                    </td>
                                    <td class="py-3 px-4">
                                        <div style="display:grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap:0.35rem;">
                                            <input class="form-input" name="s3_job[]" value="<?php echo htmlspecialchars(jf_val($existing, 's3_job')); ?>" placeholder="Job">
                                            <input class="form-input" name="s3_food[]" value="<?php echo htmlspecialchars(jf_val($existing, 's3_food')); ?>" placeholder="Food">
                                            <input class="form-input" name="s3_transport[]" value="<?php echo htmlspecialchars(jf_val($existing, 's3_transport')); ?>" placeholder="Transport">
                                        </div>
                                    </td>
                                    <td class="py-3 px-4">
                                        <div style="display:grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap:0.35rem;">
                                            <input class="form-input" name="s4_job[]" value="<?php echo htmlspecialchars(jf_val($existing, 's4_job')); ?>" placeholder="Job">
                                            <input class="form-input" name="s4_food[]" value="<?php echo htmlspecialchars(jf_val($existing, 's4_food')); ?>" placeholder="Food">
                                            <input class="form-input" name="s4_transport[]" value="<?php echo htmlspecialchars(jf_val($existing, 's4_transport')); ?>" placeholder="Transport">
                                        </div>
                                    </td>
                                    <td class="py-3 px-4"><input class="form-input" name="total[]" value="<?php echo htmlspecialchars(jf_val($existing, 'total')); ?>" placeholder="Total"></td>
                                    <td class="py-3 px-4"><input class="form-input" name="advance[]" value="<?php echo htmlspecialchars(jf_val($existing, 'advance')); ?>" placeholder="Advance"></td>
                                    <td class="py-3 px-4"><input class="form-input" name="balance[]" value="<?php echo htmlspecialchars(jf_val($existing, 'balance')); ?>" placeholder="Balance"></td>
                                    <td class="py-3 px-4"><input class="form-input" name="signature[]" value="<?php echo htmlspecialchars(jf_val($existing, 'signature')); ?>" placeholder="Signature"></td>
                                    <td class="py-3 px-4 text-right">
                                        <button type="button" class="btn btn-sm btn-secondary jf-remove-row"><i class="fas fa-minus"></i></button>
                                    </td>
                                </tr>
                            <?php $sn++; endforeach; endforeach; ?>
                            <?php if ($sn === 1): ?>
                                <tr id="jfNoRowsPlaceholder">
                                    <td colspan="12" style="text-align:center;color:#64748b;padding:1.5rem;">No labour added yet</td>
                                </tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <template id="jfItemRowTemplate">
                        <tr class="border-b border-gray-100 jf-row">
                            <td class="py-3 px-4 text-gray-700 jf-item-no">1</td>
                            <td class="py-3 px-4">
                                <select class="form-select" name="row_category[]" required>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td class="py-3 px-4"><input class="form-input" name="row_name[]" value="" placeholder="Person name" required></td>
                            <td class="py-3 px-4">
                                <div style="display:grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap:0.35rem;">
                                    <input class="form-input" name="s1_job[]" value="" placeholder="Job">
                                    <input class="form-input" name="s1_food[]" value="" placeholder="Food">
                                    <input class="form-input" name="s1_transport[]" value="" placeholder="Transport">
                                </div>
                            </td>
                            <td class="py-3 px-4">
                                <div style="display:grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap:0.35rem;">
                                    <input class="form-input" name="s2_job[]" value="" placeholder="Job">
                                    <input class="form-input" name="s2_food[]" value="" placeholder="Food">
                                    <input class="form-input" name="s2_transport[]" value="" placeholder="Transport">
                                </div>
                            </td>
                            <td class="py-3 px-4">
                                <div style="display:grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap:0.35rem;">
                                    <input class="form-input" name="s3_job[]" value="" placeholder="Job">
                                    <input class="form-input" name="s3_food[]" value="" placeholder="Food">
                                    <input class="form-input" name="s3_transport[]" value="" placeholder="Transport">
                                </div>
                            </td>
                            <td class="py-3 px-4">
                                <div style="display:grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap:0.35rem;">
                                    <input class="form-input" name="s4_job[]" value="" placeholder="Job">
                                    <input class="form-input" name="s4_food[]" value="" placeholder="Food">
                                    <input class="form-input" name="s4_transport[]" value="" placeholder="Transport">
                                </div>
                            </td>
                            <td class="py-3 px-4"><input class="form-input" name="total[]" value="" placeholder="Total"></td>
                            <td class="py-3 px-4"><input class="form-input" name="advance[]" value="" placeholder="Advance"></td>
                            <td class="py-3 px-4"><input class="form-input" name="balance[]" value="" placeholder="Balance"></td>
                            <td class="py-3 px-4"><input class="form-input" name="signature[]" value="" placeholder="Signature"></td>
                            <td class="py-3 px-4 text-right"><button type="button" class="btn btn-sm btn-secondary jf-remove-row"><i class="fas fa-minus"></i></button></td>
                        </tr>
                    </template>
                    </section>

                    <section class="jf-panel">
                        <h3 class="jf-step-title"><span class="jf-step-number">4</span> Handover Information</h3>
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;">
                            <div>
                                <label class="form-label">Received By</label>
                                <input class="form-input" name="received_by" value="<?php echo htmlspecialchars((string)($edit['received_by'] ?? '')); ?>" placeholder="Name of receiver">
                            </div>
                            <div>
                                <label class="form-label">Given By</label>
                                <input class="form-input" name="given_by" value="<?php echo htmlspecialchars((string)($edit['given_by'] ?? '')); ?>" placeholder="Name of issuer">
                            </div>
                            <div>
                                <label class="form-label">Approved By</label>
                                <input class="form-input" name="approved_by" value="<?php echo htmlspecialchars((string)($edit['approved_by'] ?? '')); ?>" placeholder="Approver name">
                            </div>
                        </div>
                    </section>

                    <div class="jf-actions">
                        <button type="submit" name="save_mode" value="draft" class="btn btn-secondary"><i class="fas fa-floppy-disk mr-1"></i> Save Draft</button>
                        <button type="submit" name="save_mode" value="submit" class="btn btn-primary"><i class="fas fa-paper-plane mr-1"></i> Submit to Coordinator</button>
                    </div>
                </div>
            </form>
        </div>

        <div class="content-section jf-history">
            <div class="section-header">
                <div><h2 class="section-title">My Job Forms</h2><p style="margin:4px 0 0;color:#64748b;font-size:.8rem;">Track drafts, Coordinator review, Finance review, and approved Job Forms.</p></div>
            </div>
            <div class="table-container">
                <table class="data-table">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Event</th>
                        <th>Coordinator</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php $sn = 1; foreach ($myForms as $jf): ?>
                        <tr>
                            <td><?php echo (int)$sn; ?></td>
                            <td><?php echo htmlspecialchars((string)($jf['event_name'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string)($jf['coordinator_name'] ?? '')); ?></td>
                            <?php $historyStatus = (string)($jf['status'] ?? 'draft'); $statusClass = $historyStatus === 'finance_approved' ? 'jf-status-approved' : (strpos($historyStatus, 'rejected') !== false ? 'jf-status-rejected' : ($historyStatus === 'draft' ? 'jf-status-draft' : 'jf-status-review')); ?>
                            <td><span class="jf-status <?php echo $statusClass; ?>"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $historyStatus))); ?></span></td>
                            <td><?php echo htmlspecialchars((string)($jf['created_at'] ?? '')); ?></td>
                            <td>
                                <div class="flex gap-2 flex-wrap"><?php if (in_array((string)($jf['status'] ?? ''), ['draft','coordinator_rejected','finance_rejected'], true)): ?>
                                    <a class="jf-action-link" href="job_forms.php?action=edit&amp;job_form_id=<?php echo (int)$jf['id']; ?>"><i class="fas fa-pen"></i> Edit</a>
                                <?php endif; ?>
                                <a class="jf-action-link" target="_blank" href="job_forms.php?view=print&amp;job_form_id=<?php echo (int)$jf['id']; ?>"><i class="fas fa-print"></i> Print</a></div>
                            </td>
                        </tr>
                    <?php $sn++; endforeach; ?>
                    <?php if (!$myForms): ?>
                        <tr><td colspan="6" style="text-align:center;color:#64748b;padding:2rem;">No job forms yet</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>
</div>

<script>
(function(){
    const profile = document.getElementById('jobFormUserProfile');
    const dropdown = document.getElementById('jobFormHeaderProfileDropdown');
    if (!profile || !dropdown) return;
    profile.addEventListener('click', function(e){
        e.stopPropagation();
        dropdown.classList.toggle('show');
    });
    document.addEventListener('click', function(){ dropdown.classList.remove('show'); });
})();

(function(){
    const select = document.getElementById('eventSelect');
    const contact = document.getElementById('coordinatorContact');
    const dateEl = document.getElementById('eventDate');
    const placeEl = document.getElementById('eventPlace');
    if (!select || !contact || !dateEl || !placeEl) return;

    function sync() {
        const opt = select.options[select.selectedIndex];
        if (!opt) return;
        const dt = opt.getAttribute('data-date') || '';
        const loc = opt.getAttribute('data-location') || '';
        const phone = opt.getAttribute('data-phone') || '';
        contact.value = phone;
        dateEl.value = dt;
        placeEl.value = loc;
    }

    select.addEventListener('change', sync);
    sync();
})();

(function(){
    const btn = document.getElementById('jfAddRow');
    if (!btn) return;

    const body = document.getElementById('jfItemsBody');
    const tpl = document.getElementById('jfItemRowTemplate');
    const catSelect = document.getElementById('jfAddCategory');
    if (!body || !tpl) return;

    const q = {
        name: document.getElementById('jfQuickName'),
        s1j: document.getElementById('jfQuickS1Job'),
        s1f: document.getElementById('jfQuickS1Food'),
        s1t: document.getElementById('jfQuickS1Transport'),
        s2j: document.getElementById('jfQuickS2Job'),
        s2f: document.getElementById('jfQuickS2Food'),
        s2t: document.getElementById('jfQuickS2Transport'),
        s3j: document.getElementById('jfQuickS3Job'),
        s3f: document.getElementById('jfQuickS3Food'),
        s3t: document.getElementById('jfQuickS3Transport'),
        s4j: document.getElementById('jfQuickS4Job'),
        s4f: document.getElementById('jfQuickS4Food'),
        s4t: document.getElementById('jfQuickS4Transport'),
        adv: document.getElementById('jfQuickAdvance'),
        sig: document.getElementById('jfQuickSignature'),
    };

    function normalizeCat(val) {
        const v = String(val || '').trim();
        return v !== '' ? v : 'AOB';
    }

    function syncRowNumbers() {
        const rows = Array.from(body.querySelectorAll('tr.jf-row'));
        const placeholder = document.getElementById('jfNoRowsPlaceholder');
        if (placeholder) {
            placeholder.style.display = rows.length ? 'none' : '';
        }
        rows.forEach(function(r, idx){
            const no = r.querySelector('.jf-item-no');
            if (no) no.textContent = String(idx + 1);
        });
        const allRows = body.querySelectorAll('tr.jf-row');
        const disableRemove = allRows.length <= 1;
        allRows.forEach(function(r){
            const b = r.querySelector('.jf-remove-row');
            if (b) b.disabled = disableRemove;
        });
    }

    function wireRemoveButtons(container) {
        const root = container || document;
        const buttons = root.querySelectorAll('.jf-remove-row');
        buttons.forEach(function(b){
            if (b.dataset.wired === '1') return;
            b.dataset.wired = '1';
            b.addEventListener('click', function(){
                const row = b.closest('tr.jf-row');
                if (!row) return;

                if (body.querySelectorAll('tr.jf-row').length <= 1) {
                    const inputs = row.querySelectorAll('input, select');
                    inputs.forEach(function(inp){
                        if (inp.tagName.toLowerCase() === 'select') inp.selectedIndex = 0;
                        else inp.value = '';
                    });
                    return;
                }

                row.remove();
                syncRowNumbers();
            });
        });
    }

    function sortRowsByCategory() {
        const rows = Array.from(body.querySelectorAll('tr.jf-row'));
        rows.sort(function(a, b){
            const ca = String(a.dataset.category || '');
            const cb = String(b.dataset.category || '');
            if (ca === cb) return 0;
            return ca.localeCompare(cb);
        });
        rows.forEach(r => body.appendChild(r));
    }

    function wireCategoryChanges(container) {
        const root = container || document;
        const selects = root.querySelectorAll('tr.jf-row select[name="row_category[]"]');
        selects.forEach(function(sel){
            if (sel.dataset.wiredCat === '1') return;
            sel.dataset.wiredCat = '1';
            sel.addEventListener('change', function(){
                const row = sel.closest('tr.jf-row');
                if (!row) return;
                row.dataset.category = normalizeCat(sel.value);
                sortRowsByCategory();
                syncRowNumbers();
            });
        });
    }

    wireRemoveButtons(document);
    wireCategoryChanges(document);
    sortRowsByCategory();
    syncRowNumbers();

    function clearQuick() {
        Object.keys(q).forEach(function(k){
            const el = q[k];
            if (!el) return;
            el.value = '';
        });
        if (q.name) q.name.focus();
    }

    function applyQuickToRow(rowEl) {
        if (!rowEl) return;
        const setVal = (sel, v) => {
            const el = rowEl.querySelector(sel);
            if (el) el.value = v;
        };

        setVal('input[name="row_name[]"]', q.name ? q.name.value : '');
        setVal('input[name="s1_job[]"]', q.s1j ? q.s1j.value : '');
        setVal('input[name="s1_food[]"]', q.s1f ? q.s1f.value : '');
        setVal('input[name="s1_transport[]"]', q.s1t ? q.s1t.value : '');
        setVal('input[name="s2_job[]"]', q.s2j ? q.s2j.value : '');
        setVal('input[name="s2_food[]"]', q.s2f ? q.s2f.value : '');
        setVal('input[name="s2_transport[]"]', q.s2t ? q.s2t.value : '');
        setVal('input[name="s3_job[]"]', q.s3j ? q.s3j.value : '');
        setVal('input[name="s3_food[]"]', q.s3f ? q.s3f.value : '');
        setVal('input[name="s3_transport[]"]', q.s3t ? q.s3t.value : '');
        setVal('input[name="s4_job[]"]', q.s4j ? q.s4j.value : '');
        setVal('input[name="s4_food[]"]', q.s4f ? q.s4f.value : '');
        setVal('input[name="s4_transport[]"]', q.s4t ? q.s4t.value : '');
        setVal('input[name="advance[]"]', q.adv ? q.adv.value : '');
        setVal('input[name="signature[]"]', q.sig ? q.sig.value : '');
    }

    function addRowFromQuick() {
        const selectedCat = normalizeCat(catSelect ? catSelect.value : 'AOB');
        const frag = tpl.content.cloneNode(true);
        const wrapper = document.createElement('div');
        wrapper.appendChild(frag);
        const newRow = wrapper.querySelector('tr.jf-row');
        if (!newRow) return;

        const rowCatSelect = newRow.querySelector('select[name="row_category[]"]');
        if (rowCatSelect) rowCatSelect.value = selectedCat;
        newRow.dataset.category = selectedCat;
        body.appendChild(newRow);

        applyQuickToRow(newRow);

        wireRemoveButtons(newRow);
        wireCategoryChanges(newRow);
        wireAutoCalc(newRow);
        sortRowsByCategory();
        syncRowNumbers();
        newRow.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        clearQuick();
    }

    btn.addEventListener('click', function(){
        addRowFromQuick();
    });

    if (q.name) {
        q.name.addEventListener('keydown', function(e){
            if (e.key === 'Enter') {
                e.preventDefault();
                addRowFromQuick();
            }
        });
    }
})();

(function(){
    const rows = document.getElementById('jfItemsBody');
    if (!rows) return;

    function toNum(v) {
        const n = parseFloat(String(v || '').replace(/,/g, '').trim());
        return isFinite(n) ? n : 0;
    }

    function formatNum(n) {
        if (!isFinite(n)) return '';
        return (Math.round(n * 100) / 100).toString();
    }

    function calcRow(rowEl) {
        const get = (name) => rowEl.querySelector('[name="' + name + '"]');

        const fields = [
            's1_job[]','s1_food[]','s1_transport[]',
            's2_job[]','s2_food[]','s2_transport[]',
            's3_job[]','s3_food[]','s3_transport[]',
            's4_job[]','s4_food[]','s4_transport[]'
        ];

        let total = 0;
        fields.forEach((n) => {
            const el = get(n);
            if (el) total += toNum(el.value);
        });

        const totalEl = get('total[]');
        const advEl = get('advance[]');
        const balEl = get('balance[]');

        const adv = advEl ? toNum(advEl.value) : 0;
        const bal = total - adv;

        if (totalEl) totalEl.value = formatNum(total);
        if (balEl) balEl.value = formatNum(bal);
    }

    window.wireAutoCalc = function(container) {
        const root = container || document;
        const rowEls = root.querySelectorAll('.jf-row');
        rowEls.forEach((rowEl) => {
            if (rowEl.dataset.calcWired === '1') return;
            rowEl.dataset.calcWired = '1';

            rowEl.addEventListener('input', function(e) {
                const t = e.target;
                if (!t || !(t instanceof HTMLElement)) return;
                const name = t.getAttribute('name') || '';
                if (name.includes('s1_') || name.includes('s2_') || name.includes('s3_') || name.includes('s4_') || name === 'advance[]') {
                    calcRow(rowEl);
                }
            });

            calcRow(rowEl);
        });
    };

    wireAutoCalc(rows);
})();
</script>
</body>
</html>
