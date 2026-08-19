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

if (!in_array($role, ['operation', 'admin', 'super'], true)) {
    header('Location: index.php');
    exit;
}

$displayName = $user['name'] ?? $user['email'] ?? 'User';
$pageTitle = 'Supervisors';

$mode = (string)($_GET['mode'] ?? 'list');
$viewId = (int)($_GET['id'] ?? 0);
$editId = (int)($_GET['edit_id'] ?? 0);
$focusEventId = (int)($_GET['event_id'] ?? 0);

$selectedSupervisor = null;
$supervisors = [];
try {
    $stmt = $db->query("SELECT id, name, email, phone, role, created_at FROM users WHERE LOWER(role) = 'supervisor' ORDER BY name");
    $supervisors = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
} catch (Throwable $e) {
    $supervisors = [];
}

$approvedEvents = [];
try {
    $stmt = $db->query("SELECT id, name, date, client, supervisor, created_at FROM events WHERE quote_status = 'approved' AND (supervisor IS NULL OR supervisor = '') ORDER BY COALESCE(date, created_at) DESC, id DESC");
    $approvedEvents = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
} catch (Throwable $e) {
    $approvedEvents = [];
}

if ($focusEventId > 0) {
    try {
        $stmt = $db->prepare("SELECT id, name, date, client, supervisor, created_at FROM events WHERE id = ? LIMIT 1");
        $stmt->execute([$focusEventId]);
        $focusedEvent = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($focusedEvent) {
            $alreadyInList = false;
            foreach ($approvedEvents as $ev) {
                if ((int)($ev['id'] ?? 0) === (int)$focusEventId) {
                    $alreadyInList = true;
                    break;
                }
            }
            if (!$alreadyInList) {
                array_unshift($approvedEvents, $focusedEvent);
            }
        }
    } catch (Throwable $e) {
        // ignore
    }
}

$assignedEvents = [];
try {
    $stmt = $db->query("SELECT id, name, date, client, supervisor, created_at FROM events WHERE quote_status = 'approved' AND (supervisor IS NOT NULL AND supervisor <> '') ORDER BY COALESCE(date, created_at) DESC, id DESC");
    $assignedEvents = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
} catch (Throwable $e) {
    $assignedEvents = [];
}

if ($mode === 'view' && $viewId > 0) {
    try {
        $stmt = $db->prepare("SELECT id, name, email, phone, role, created_at FROM users WHERE id = ? AND role = 'supervisor'");
        $stmt->execute([$viewId]);
        $selectedSupervisor = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        $selectedSupervisor = null;
    }
}

if ($mode === 'edit' && $editId > 0) {
    try {
        $stmt = $db->prepare("SELECT id, name, email, phone, role, created_at FROM users WHERE id = ? AND role = 'supervisor'");
        $stmt->execute([$editId]);
        $selectedSupervisor = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        $selectedSupervisor = null;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_abort();

    $action = $_POST['action'] ?? '';
    if ($action === 'assign_supervisor_direct') {
        $eventId = (int)($_POST['event_id'] ?? 0);
        $supervisorIdsRaw = $_POST['supervisor_user_ids'] ?? ($_POST['supervisor_user_id'] ?? []);
        if (is_string($supervisorIdsRaw) && strpos($supervisorIdsRaw, ',') !== false) {
            $supervisorIdsRaw = array_map('trim', explode(',', $supervisorIdsRaw));
        }
        if (!is_array($supervisorIdsRaw)) {
            $supervisorIdsRaw = [$supervisorIdsRaw];
        }
        $supervisorIds = array_values(array_unique(array_filter(array_map('intval', $supervisorIdsRaw), fn($v) => $v > 0)));

        if ($eventId <= 0 || empty($supervisorIds)) {
            flash_add('error', 'Missing event or supervisor.');
            header('Location: register_supervisor.php');
            exit;
        }

        $supNames = [];
        try {
            $placeholders = implode(',', array_fill(0, count($supervisorIds), '?'));
            $stmt = $db->prepare("SELECT id, name FROM users WHERE id IN ($placeholders) AND LOWER(role) = 'supervisor'");
            $stmt->execute($supervisorIds);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $byId = [];
            foreach ($rows as $r) {
                $byId[(int)($r['id'] ?? 0)] = (string)($r['name'] ?? '');
            }
            foreach ($supervisorIds as $sid) {
                $nm = trim((string)($byId[(int)$sid] ?? ''));
                if ($nm !== '') {
                    $supNames[] = $nm;
                }
            }
        } catch (Throwable $e) {
            $supNames = [];
        }

        if (empty($supNames)) {
            flash_add('error', 'Invalid supervisor.');
            header('Location: register_supervisor.php');
            exit;
        }

        $supText = implode(', ', $supNames);
        $supTextUpper = function_exists('mb_strtoupper') ? mb_strtoupper($supText, 'UTF-8') : strtoupper($supText);

        try {
            $stmt = $db->prepare('UPDATE events SET supervisor = ?, supervisors = ? WHERE id = ?');
            $stmt->execute([$supTextUpper, json_encode($supervisorIds), $eventId]);
            flash_add('success', 'Supervisor assigned to event.');
        } catch (Throwable $e) {
            flash_add('error', 'Failed to assign supervisor.');
        }

        header('Location: register_supervisor.php');
        exit;
    }
    if ($action === 'create_supervisor') {
        $name = trim((string)($_POST['name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        if ($name === '' || $email === '' || $password === '') {
            flash_add('error', 'Name, email and password are required.');
            header('Location: register_supervisor.php?mode=add');
            exit;
        }

        try {
            $newId = (int)db_create_user([
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'role' => 'supervisor'
            ]);

            if ($phone !== '' && $newId > 0) {
                $upd = $db->prepare('UPDATE users SET phone = ? WHERE id = ?');
                $upd->execute([$phone, $newId]);
            }

            flash_add('success', 'Supervisor registered successfully.');
        } catch (Throwable $e) {
            flash_add('error', 'Failed to register supervisor: ' . $e->getMessage());
        }

        header('Location: register_supervisor.php');
        exit;
    }

    if ($action === 'update_supervisor') {
        $supId = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        if ($supId <= 0 || $name === '' || $email === '') {
            flash_add('error', 'Supervisor, name and email are required.');
            header('Location: register_supervisor.php');
            exit;
        }

        try {
            $stmt = $db->prepare("UPDATE users SET name = ?, email = ?, phone = ? WHERE id = ? AND role = 'supervisor'");
            $stmt->execute([$name, $email, ($phone !== '' ? $phone : null), $supId]);

            if ($password !== '') {
                $stmt = $db->prepare('UPDATE users SET password_hash = ? WHERE id = ? AND role = \"supervisor\"');
                $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $supId]);
            }

            flash_add('success', 'Supervisor updated successfully.');
        } catch (Throwable $e) {
            flash_add('error', 'Failed to update supervisor: ' . $e->getMessage());
        }

        header('Location: register_supervisor.php');
        exit;
    }

    if ($action === 'delete_supervisor') {
        $supId = (int)($_POST['id'] ?? 0);
        if ($supId <= 0) {
            flash_add('error', 'Missing supervisor.');
            header('Location: register_supervisor.php');
            exit;
        }

        try {
            $stmt = $db->prepare("DELETE FROM users WHERE id = ? AND role = 'supervisor'");
            $stmt->execute([$supId]);
            flash_add('success', 'Supervisor deleted successfully.');
        } catch (Throwable $e) {
            flash_add('error', 'Failed to delete supervisor: ' . $e->getMessage());
        }

        header('Location: register_supervisor.php');
        exit;
    }

    header('Location: register_supervisor.php');
    exit;
}

$flashes = flash_consume();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($pageTitle); ?> | Hugo Domingo Events</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="style.css">
    <style>
        .multi-select-container {
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
        }

        .ms {
            position: relative;
            isolation: isolate;
        }

        .ms.open {
            z-index: 2147483646;
        }

        .ms select {
            display: none;
        }

        .ms-control {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            min-height: 46px;
            width: 100%;
            text-align: left;
            padding: 0.75rem 0.9rem;
            border: 1px solid #d1d5db;
            border-radius: 10px;
            background: #ffffff;
            color: #111827;
            font-size: 0.875rem;
        }

        .ms-control:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59,130,246,0.1);
        }

        .ms-control span[aria-hidden="true"] {
            color: #64748b;
            font-size: 0.85rem;
            line-height: 1;
        }

        .ms-control .ms-value {
            flex: 1;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            color: #111827;
        }

        .ms-control .ms-placeholder {
            color: #6b7280;
        }

        .ms-menu {
            position: absolute;
            left: 0;
            right: 0;
            top: calc(100% + 0.35rem);
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            box-shadow: 0 10px 20px rgba(15,23,42,0.08);
            z-index: 2147483647;
            padding: 0.85rem;
            display: none;
        }

        .ms.drop-up .ms-menu {
            top: auto;
            bottom: calc(100% + 0.35rem);
        }

        .ms.open .ms-menu {
            display: block;
        }

        .ms-search {
            width: 100%;
            padding: 0.85rem 0.9rem;
            border: 1px solid #d1d5db;
            border-radius: 12px;
            font-size: 0.875rem;
            margin-bottom: 0.75rem;
            outline: none;
        }

        .ms-search:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37,99,235,0.25);
        }

        .ms-options {
            max-height: 520px;
            overflow: auto;
            padding-right: 0.25rem;
        }

        .ms-option {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.4rem 0.25rem;
            border-radius: 10px;
            cursor: pointer;
            user-select: none;
            font-size: 0.8rem;
            color: #0f172a;
        }

        .ms-option span {
            white-space: normal;
            line-height: 1.25;
        }

        .ms-option:hover {
            background: #f8fafc;
        }

        .ms-option input[type="checkbox"] {
            width: 16px;
            height: 16px;
        }

        #assignSupervisor .overflow-x-auto {
            overflow-y: visible;
        }

        #assignSupervisor td {
            overflow: visible;
        }

        .form-input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59,130,246,0.1);
        }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php $activePage = 'register_supervisor.php'; include __DIR__ . '/sidebar.php'; ?>

    <main class="main-content">
        <div class="top-bar">
            <div class="page-title">
                <h1><?php echo htmlspecialchars($pageTitle); ?></h1>
                <p>Manage registered supervisors</p>
            </div>
            <div class="user-menu">
                <div class="date-display">
                    <i class="far fa-calendar-alt mr-2"></i>
                    <?php echo date('l, F j, Y'); ?>
                </div>
                <div class="user-profile" id="userProfile">
                    <div class="avatar"><?php echo strtoupper(substr($displayName, 0, 1)); ?></div>
                    <div>
                        <div style="font-weight: 600;">
                            <?php echo htmlspecialchars($displayName); ?>
                        </div>
                        <div style="font-size: 0.75rem; color: #64748b;">
                            <?php echo get_role_label($user['role'] ?? 'user'); ?>
                        </div>
                    </div>
                    <i class="fas fa-chevron-down ml-2" style="color: #64748b;"></i>
                    <div class="profile-dropdown" id="profileDropdown">
                        <a href="profile.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"><i class="fas fa-user mr-2"></i> My Profile</a>
                        <a href="?logout" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"><i class="fas fa-sign-out-alt mr-2"></i> Logout</a>
                    </div>
                </div>
            </div>
        </div>

        <?php foreach ($flashes as $type => $msgs): ?>
            <?php foreach ($msgs as $msg): ?>
                <div class="mb-4 p-4 rounded-lg <?php echo $type === 'success' ? 'bg-green-100 text-green-800' : ($type === 'warning' ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800'); ?>">
                    <?php echo htmlspecialchars($msg); ?>
                </div>
            <?php endforeach; ?>
        <?php endforeach; ?>

        <div id="manageSupervisorsSection" class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden mb-6">
            <div class="px-6 py-4 border-b border-gray-100 bg-gray-50 flex items-center justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-gray-800">Registered Supervisors</h2>
                    <p class="text-sm text-gray-500 mt-1">Total: <span class="font-semibold text-gray-700" id="supTotalCount"><?php echo number_format(count($supervisors)); ?></span></p>
                </div>
                <div class="flex items-center gap-2">
                    <?php if ($mode === 'add' || $mode === 'edit' || $mode === 'view'): ?>
                        <a href="register_supervisor.php" class="btn btn-secondary">Back to List</a>
                    <?php else: ?>
                        <a href="register_supervisor.php?mode=add" class="btn btn-primary"><i class="fas fa-plus mr-1"></i> Add Supervisor</a>
                        <button id="toggleSupFilters" type="button" class="btn btn-secondary"><i class="fas fa-search mr-1"></i> Search</button>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($mode === 'add' || $mode === 'edit' || $mode === 'view'): ?>
                <div class="p-6">
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="text-md font-semibold text-gray-800">
                            <?php echo $mode === 'add' ? 'Add Supervisor' : ($mode === 'edit' ? 'Edit Supervisor' : 'Supervisor Details'); ?>
                        </h3>
                    </div>

                    <?php if ($mode !== 'add' && !$selectedSupervisor): ?>
                        <div class="p-4 rounded-lg bg-red-50 text-red-800">Supervisor not found.</div>
                    <?php else: ?>
                        <form method="post" class="form-grid" style="margin-top: 0;">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                            <?php if ($mode === 'add'): ?>
                                <input type="hidden" name="action" value="create_supervisor">
                            <?php else: ?>
                                <input type="hidden" name="action" value="update_supervisor">
                                <input type="hidden" name="id" value="<?php echo (int)($selectedSupervisor['id'] ?? 0); ?>">
                            <?php endif; ?>

                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem;">
                                <div class="form-group">
                                    <label class="form-label">Name *</label>
                                    <input type="text" name="name" class="form-input" placeholder="Full name"
                                           value="<?php echo htmlspecialchars((string)($selectedSupervisor['name'] ?? '')); ?>" <?php echo $mode === 'view' ? 'readonly' : ''; ?> required>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Email *</label>
                                    <input type="email" name="email" class="form-input" placeholder="Email address"
                                           value="<?php echo htmlspecialchars((string)($selectedSupervisor['email'] ?? '')); ?>" <?php echo $mode === 'view' ? 'readonly' : ''; ?> required>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Phone</label>
                                    <input type="text" name="phone" class="form-input" placeholder="Phone number"
                                           value="<?php echo htmlspecialchars((string)($selectedSupervisor['phone'] ?? '')); ?>" <?php echo $mode === 'view' ? 'readonly' : ''; ?>>
                                </div>

                                <div class="form-group">
                                    <label class="form-label"><?php echo $mode === 'add' ? 'Password *' : 'New Password (optional)'; ?></label>
                                    <input type="text" name="password" class="form-input" placeholder="<?php echo $mode === 'add' ? 'Set a password' : 'Leave blank to keep current password'; ?>" <?php echo $mode === 'view' ? 'readonly' : ''; ?> <?php echo $mode === 'add' ? 'required' : ''; ?>>
                                </div>
                            </div>

                            <?php if ($mode !== 'view'): ?>
                                <div class="flex gap-2 mt-4">
                                    <button type="submit" class="btn btn-primary">Save</button>
                                    <a href="register_supervisor.php" class="btn btn-secondary">Cancel</a>
                                </div>
                            <?php else: ?>
                                <div class="flex gap-2 mt-4">
                                    <a href="register_supervisor.php?mode=edit&edit_id=<?php echo (int)($selectedSupervisor['id'] ?? 0); ?>" class="btn btn-primary">Edit</a>
                                    <a href="register_supervisor.php" class="btn btn-secondary">Back</a>
                                </div>
                            <?php endif; ?>
                        </form>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div id="supSearchPanel" class="bg-gray-50 p-4" style="display: none;">
                    <h3 class="text-lg font-medium mb-3">Search Supervisors</h3>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Search Text</label>
                            <input id="supSearch" type="text" class="form-input w-full" placeholder="Search by name or email...">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Name starts with</label>
                            <select id="supFilter" class="form-select w-full">
                                <option value="">All Supervisors</option>
                            </select>
                        </div>
                        <div class="flex items-end gap-2">
                            <button id="supApplyFilter" class="btn btn-primary flex-1"><i class="fas fa-search mr-1"></i> Search</button>
                            <button id="supReset" type="button" class="btn btn-secondary"><i class="fas fa-sync-alt mr-1"></i> Reset</button>
                        </div>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                        <tr class="border-b border-gray-200">
                            <th class="text-left py-3 px-4 text-gray-600 font-medium">#</th>
                            <th class="text-left py-3 px-4 text-gray-600 font-medium">Name</th>
                            <th class="text-left py-3 px-4 text-gray-600 font-medium">Email</th>
                            <th class="text-left py-3 px-4 text-gray-600 font-medium">Phone</th>
                            <th class="text-left py-3 px-4 text-gray-600 font-medium">Created</th>
                            <th class="text-right py-3 px-4 text-gray-600 font-medium">Actions</th>
                        </tr>
                        </thead>
                        <tbody id="supervisorsTableBody">
                        <?php $supNo = 1; foreach ($supervisors as $sup): ?>
                            <tr class="border-b border-gray-100 hover:bg-gray-50 align-top supervisor-row"
                                data-name="<?php echo htmlspecialchars(strtolower((string)($sup['name'] ?? ''))); ?>"
                                data-email="<?php echo htmlspecialchars(strtolower((string)($sup['email'] ?? ''))); ?>">
                                <td class="py-3 px-4 text-gray-500 text-sm"><?php echo (int)$supNo; ?></td>
                                <td class="py-3 px-4 font-medium text-gray-900"><?php echo htmlspecialchars((string)($sup['name'] ?? '')); ?></td>
                                <td class="py-3 px-4 text-gray-700"><?php echo htmlspecialchars((string)($sup['email'] ?? '')); ?></td>
                                <td class="py-3 px-4 text-gray-700"><?php echo htmlspecialchars((string)($sup['phone'] ?? '')); ?></td>
                                <td class="py-3 px-4 text-gray-700"><?php echo htmlspecialchars((string)($sup['created_at'] ?? '')); ?></td>
                                <td class="py-3 px-4 text-right whitespace-nowrap">
                                    <a href="register_supervisor.php?mode=view&id=<?php echo (int)$sup['id']; ?>" title="View" class="inline-flex items-center justify-center w-10 h-10 rounded-full bg-blue-50 text-blue-600 hover:bg-blue-100 transition-colors">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="register_supervisor.php?mode=edit&edit_id=<?php echo (int)$sup['id']; ?>" title="Edit" class="inline-flex items-center justify-center w-10 h-10 rounded-full bg-gray-100 text-gray-700 hover:bg-gray-200 transition-colors ml-1">
                                        <i class="fas fa-pen"></i>
                                    </a>
                                    <form method="post" style="display:inline;" onsubmit="return confirm('Delete this supervisor?');" class="ml-1">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                        <input type="hidden" name="action" value="delete_supervisor">
                                        <input type="hidden" name="id" value="<?php echo (int)$sup['id']; ?>">
                                        <button type="submit" title="Delete" class="inline-flex items-center justify-center w-10 h-10 rounded-full bg-red-50 text-red-600 hover:bg-red-100 transition-colors">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php $supNo++; endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="px-6 py-4 border-t border-gray-100 bg-gray-50 flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                    <div class="text-sm text-gray-600">
                        Showing <span class="font-semibold" id="supShowingCount">0</span> of <span class="font-semibold" id="supFilteredCount">0</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <button id="supPrev" class="btn btn-secondary" disabled>Previous</button>
                        <span class="text-sm text-gray-600">Page <span class="font-semibold" id="supPage">1</span></span>
                        <button id="supNext" class="btn btn-secondary" disabled>Next</button>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        var userProfile = document.getElementById('userProfile');
        var profileDropdown = document.getElementById('profileDropdown');

        if (userProfile && profileDropdown) {
            userProfile.addEventListener('click', function(e) {
                e.stopPropagation();
                profileDropdown.classList.toggle('show');
            });

            document.addEventListener('click', function() {
                profileDropdown.classList.remove('show');
            });
        }

        var sidebarToggle = document.getElementById('mobileSidebarToggle');
        var sidebarBackdrop = document.getElementById('sidebarBackdrop');

        function closeSidebar() {
            document.body.classList.remove('sidebar-open');
        }

        function toggleSidebar() {
            document.body.classList.toggle('sidebar-open');
        }

        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', function(e) {
                e.preventDefault();
                toggleSidebar();
            });
        }

        if (sidebarBackdrop) {
            sidebarBackdrop.addEventListener('click', function() {
                closeSidebar();
            });
        }

        document.querySelectorAll('.sidebar-close-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                closeSidebar();
            });
        });

        function isHidden(el) {
            if (!el) return true;
            return (el.style && el.style.display === 'none');
        }

        // Supervisor list is now always visible on this page.
    });
</script>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        var tbody = document.getElementById('supervisorsTableBody');
        if (!tbody) return;

        var rows = Array.from(tbody.querySelectorAll('tr.supervisor-row'));
        var searchInput = document.getElementById('supSearch');
        var filterSelect = document.getElementById('supFilter');
        var applyFilterBtn = document.getElementById('supApplyFilter');
        var prevBtn = document.getElementById('supPrev');
        var nextBtn = document.getElementById('supNext');
        var pageEl = document.getElementById('supPage');
        var showingEl = document.getElementById('supShowingCount');
        var filteredEl = document.getElementById('supFilteredCount');
        var totalEl = document.getElementById('supTotalCount');

        var page = 1;
        var perPage = 10;

        function getFilterValue() {
            return (filterSelect && filterSelect.value ? filterSelect.value.toLowerCase() : '');
        }

        function getSearchValue() {
            return (searchInput && searchInput.value ? searchInput.value.toLowerCase().trim() : '');
        }

        function filteredRows() {
            var q = getSearchValue();
            var f = getFilterValue();
            return rows.filter(function(r) {
                var name = (r.getAttribute('data-name') || '');
                var email = (r.getAttribute('data-email') || '');
                var matchQuery = (!q) || (name.indexOf(q) !== -1) || (email.indexOf(q) !== -1);
                var matchFilter = (!f) || (name.startsWith(f));
                return matchQuery && matchFilter;
            });
        }

        function render() {
            var fr = filteredRows();
            var total = rows.length;
            if (totalEl) totalEl.textContent = String(total);
            if (filteredEl) filteredEl.textContent = String(fr.length);

            var totalPages = Math.max(1, Math.ceil(fr.length / perPage));
            if (page > totalPages) page = totalPages;
            if (page < 1) page = 1;

            rows.forEach(function(r) { r.style.display = 'none'; });

            var start = (page - 1) * perPage;
            var end = start + perPage;
            fr.slice(start, end).forEach(function(r) {
                r.style.display = '';
            });

            var showing = fr.slice(start, end).length;
            if (showingEl) showingEl.textContent = String(showing);
            if (pageEl) pageEl.textContent = String(page);

            if (prevBtn) prevBtn.disabled = page <= 1;
            if (nextBtn) nextBtn.disabled = page >= totalPages;
        }

        function buildFilterOptions() {
            if (!filterSelect) return;
            var letters = {};
            rows.forEach(function(r) {
                var name = (r.getAttribute('data-name') || '').trim();
                if (!name) return;
                var letter = name.charAt(0).toUpperCase();
                if (letter) letters[letter] = true;
            });
            var sorted = Object.keys(letters).sort();
            sorted.forEach(function(letter) {
                var opt = document.createElement('option');
                opt.value = letter;
                opt.textContent = 'Starts with ' + letter;
                filterSelect.appendChild(opt);
            });
        }

        if (searchInput) {
            searchInput.addEventListener('input', function() {
                page = 1;
                render();
            });
        }
        if (applyFilterBtn) {
            applyFilterBtn.addEventListener('click', function(e) {
                e.preventDefault();
                page = 1;
                render();
            });
        }
        if (prevBtn) {
            prevBtn.addEventListener('click', function(e) {
                e.preventDefault();
                page = Math.max(1, page - 1);
                render();
            });
        }
        if (nextBtn) {
            nextBtn.addEventListener('click', function(e) {
                e.preventDefault();
                page = page + 1;
                render();
            });
        }

        buildFilterOptions();
        render();
    });

</script>

<script>
    (function () {
        function updateLabel(ms, select) {
            const selected = Array.from(select.selectedOptions || []).map(o => (o.textContent || '').trim()).filter(Boolean);
            const label = ms.querySelector('.ms-value');
            const placeholder = ms.getAttribute('data-placeholder') || 'Select';
            if (!label) return;
            if (!selected.length) {
                label.textContent = placeholder;
                label.classList.add('ms-placeholder');
            } else {
                label.textContent = selected.join(', ');
                label.classList.remove('ms-placeholder');
            }
        }

        function buildOptions(ms, select) {
            const optionsWrap = ms.querySelector('.ms-options');
            if (!optionsWrap) return;
            optionsWrap.innerHTML = '';

            const opts = Array.from(select.options || []).filter(o => (o.value || '').toString().trim() !== '');
            for (const opt of opts) {
                const row = document.createElement('label');
                row.className = 'ms-option';

                const cb = document.createElement('input');
                cb.type = 'checkbox';
                cb.value = opt.value;
                cb.checked = !!opt.selected;

                const text = document.createElement('span');
                text.textContent = (opt.textContent || '').trim();

                cb.addEventListener('change', function () {
                    opt.selected = cb.checked;
                    updateLabel(ms, select);
                });

                row.addEventListener('click', function (e) {
                    if (e.target === cb) return;
                    cb.checked = !cb.checked;
                    opt.selected = cb.checked;
                    updateLabel(ms, select);
                });

                row.appendChild(cb);
                row.appendChild(text);
                optionsWrap.appendChild(row);
            }

            updateLabel(ms, select);
        }

        function filterOptions(ms, query) {
            const q = (query || '').trim().toLowerCase();
            const rows = Array.from(ms.querySelectorAll('.ms-option'));
            rows.forEach(r => {
                const t = (r.textContent || '').toLowerCase();
                r.style.display = t.includes(q) ? '' : 'none';
            });
        }

        window.__initMsWidget = function (ms) {
            if (!ms || ms.__msInited) return;
            const select = ms.querySelector('select');
            const control = ms.querySelector('.ms-control');
            const menu = ms.querySelector('.ms-menu');
            const search = ms.querySelector('.ms-search');
            if (!select || !control || !menu) return;

            buildOptions(ms, select);

            control.addEventListener('click', function () {
                const isOpen = ms.classList.toggle('open');
                control.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                if (isOpen && search) {
                    try {
                        ms.classList.remove('drop-up');
                        const menuRect = menu.getBoundingClientRect();
                        const msRect = ms.getBoundingClientRect();
                        const spaceBelow = window.innerHeight - msRect.bottom;
                        const spaceAbove = msRect.top;
                        const needed = Math.max(menuRect.height, 260);
                        if (spaceBelow < needed && spaceAbove > needed) {
                            ms.classList.add('drop-up');
                        }
                    } catch (e) {}
                    search.value = '';
                    filterOptions(ms, '');
                    search.focus();
                }
            });

            if (search) {
                search.addEventListener('input', function () {
                    filterOptions(ms, search.value);
                });
            }

            select.addEventListener('change', function () {
                buildOptions(ms, select);
            });

            ms.__msInited = true;
        };

        document.addEventListener('click', function (e) {
            document.querySelectorAll('.ms.open').forEach(function (ms) {
                if (ms.contains(e.target)) return;
                const control = ms.querySelector('.ms-control');
                ms.classList.remove('open');
                if (control) control.setAttribute('aria-expanded', 'false');
            });
        });
    })();
</script>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.ms').forEach(function (ms) {
            if (window.__initMsWidget) window.__initMsWidget(ms);
        });

        document.querySelectorAll('form').forEach(function (f) {
            var actionEl = f.querySelector('input[name="action"]');
            if (!actionEl || actionEl.value !== 'assign_supervisor_direct') return;
            f.addEventListener('submit', function (e) {
                var select = f.querySelector('select[name="supervisor_user_ids[]"]');
                var count = select ? Array.from(select.selectedOptions || []).length : 0;
                if (count <= 0) {
                    e.preventDefault();
                    if (select) {
                        var ms = select.closest('.ms');
                        if (ms) {
                            ms.classList.add('open');
                            var control = ms.querySelector('.ms-control');
                            if (control) control.setAttribute('aria-expanded', 'true');
                            var search = ms.querySelector('.ms-search');
                            if (search) search.focus();
                        }
                    }
                }
            });
        });
    });
</script>
</body>
</html>
