<?php
require __DIR__ . '/auth.php';

if (isset($_GET['logout'])) {
  logout_user();
  header('Location: login.php');
  exit;
}

require_login();
$db = get_db();
$user = current_user();
$currentRole = strtolower(trim((string)($user['role'] ?? 'user')));
$currentRole = str_replace(['_', '-'], ' ', $currentRole);
$currentRole = preg_replace('/\s+/', ' ', $currentRole);
if (in_array($currentRole, ['super admin', 'superadministrator', 'super administrator'], true)) {
  $currentRole = 'super';
}
// Allow super, operation and admin to access user management
if (!in_array($currentRole, ['super', 'operation', 'admin'], true)) {
  header('Location: index.php');
  exit;
}

// Helpers
function get_user_by_id(PDO $db, int $id) {
  $s = $db->prepare('SELECT id,name,email,role,is_active,created_at FROM users WHERE id=?');
  $s->execute([$id]);
  return $s->fetch(PDO::FETCH_ASSOC);
}
function log_activity(PDO $db, $uid, $action, $details) {
  try {
    $stmt = $db->prepare('INSERT INTO activities(user_id, action, details) VALUES (?,?,?)');
    $stmt->execute([$uid, $action, is_string($details) ? $details : json_encode($details)]);
  } catch (Throwable $e) {}
}

$error = '';
$info = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['account_status'])) {
  verify_csrf_or_abort();
  $id = (int)($_POST['id'] ?? 0);
  $statusValue = (string)($_POST['is_active'] ?? '');
  try {
    if (!in_array($currentRole, ['admin', 'super'], true)) {
      throw new RuntimeException('Only administrators can change account status');
    }
    if ($id <= 0 || !in_array($statusValue, ['0', '1'], true)) {
      throw new RuntimeException('Invalid account status request');
    }
    $isActive = (int)$statusValue;
    if ($id === (int)($user['id'] ?? 0) && $isActive === 0) {
      throw new RuntimeException('You cannot deactivate your own account');
    }

    $targetStmt = $db->prepare('SELECT name, role FROM users WHERE id = ?');
    $targetStmt->execute([$id]);
    $target = $targetStmt->fetch(PDO::FETCH_ASSOC);
    if (!$target) {
      throw new RuntimeException('User not found');
    }
    if ($currentRole !== 'super' && ($target['role'] ?? '') === 'super') {
      throw new RuntimeException('Only a super admin can change a super admin account');
    }
    if ($isActive === 0 && ($target['role'] ?? '') === 'super') {
      $activeSuperCount = (int)$db->query("SELECT COUNT(*) FROM users WHERE role='super' AND is_active=1")->fetchColumn();
      if ($activeSuperCount <= 1) {
        throw new RuntimeException('Cannot deactivate the last active super admin');
      }
    }

    $statusStmt = $db->prepare('UPDATE users SET is_active = ? WHERE id = ?');
    $statusStmt->execute([$isActive, $id]);
    $verifyStmt = $db->prepare('SELECT is_active FROM users WHERE id = ?');
    $verifyStmt->execute([$id]);
    if ((int)$verifyStmt->fetchColumn() !== $isActive) {
      throw new RuntimeException('Account status could not be updated');
    }

    log_activity($db, ($user['id'] ?? null), $isActive ? 'user_activate' : 'user_deactivate', ['id'=>$id,'name'=>$target['name'] ?? '']);
    flash_add('success', $isActive ? 'User activated successfully' : 'User deactivated successfully');
  } catch (Throwable $e) {
    error_log('[users.php] Account status update failed: ' . $e->getMessage());
    flash_add('error', $e instanceof RuntimeException ? $e->getMessage() : 'Account status could not be updated');
  }
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['account_status'])) {
  verify_csrf_or_abort();
  $action = $_POST['__action'] ?? '';

  if ($action === 'add') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $validRoles = ['super', 'admin', 'finance', 'sales', 'accountant', 'cashier', 'operation', 'production', 'supervisor', 'store', 'graphic'];
    $role = in_array(($_POST['role'] ?? 'user'), $validRoles, true) ? $_POST['role'] : 'user';

    if ($currentRole === 'operation') {
      $role = 'supervisor';
    }

    if ($name === '' || $email === '' || $password === '') {
      $error = 'All fields are required';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $error = 'Invalid email address';
    } elseif (strlen($password) < 6) {
      $error = 'Password must be at least 6 characters';
    } else {
      $dup = $db->prepare('SELECT 1 FROM users WHERE email=?');
      $dup->execute([$email]);
      if ($dup->fetch()) {
        $error = 'Email already exists';
      } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $db->prepare('INSERT INTO users(name,email,password_hash,role) VALUES(?,?,?,?)');
        $stmt->execute([$name, $email, $hash, $role]);
        log_activity($db, ($user['id'] ?? null), 'user_create', ['name'=>$name,'email'=>$email,'role'=>$role]);
        flash_add('success', 'User created successfully');
        header('Location: users.php');
        exit;
      }
    }
  } elseif ($action === 'edit') {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $validRoles = ['super', 'admin', 'finance', 'sales', 'accountant', 'cashier', 'operation', 'production', 'supervisor', 'store', 'graphic'];
    $role = in_array(($_POST['role'] ?? 'user'), $validRoles, true) ? $_POST['role'] : 'user';

    if ($currentRole === 'operation') {
      try {
        $stRole = $db->prepare('SELECT role FROM users WHERE id=?');
        $stRole->execute([$id]);
        $targetRole = strtolower((string)($stRole->fetchColumn() ?? ''));
        if ($targetRole !== 'supervisor') {
          $error = 'Invalid user';
        }
      } catch (Throwable $e) {
        $error = 'Invalid user';
      }
      $role = 'supervisor';
    }

    if ($id <= 0) {
      $error = 'Invalid user';
    } elseif ($name === '' || $email === '') {
      $error = 'Name and email are required';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $error = 'Invalid email address';
    } else {
      // prevent demoting self from super if this is the only super
      if ($user['id'] === $id) {
        $onlySuper = (int)$db->query("SELECT COUNT(*) FROM users WHERE role='super' AND is_active=1")->fetchColumn() <= 1;
        if ($onlySuper && $role !== 'super') {
          $error = 'You are the only super user. Create another super user before changing your role.';
        }
      }
    }

    if ($error === '') {
      // check email unique (excluding this id)
      $dup = $db->prepare('SELECT 1 FROM users WHERE email=? AND id<>?');
      $dup->execute([$email, $id]);
      if ($dup->fetch()) {
        $error = 'Email already in use by another account';
      } else {
        if ($password !== '') {
          if (strlen($password) < 6) {
            $error = 'Password must be at least 6 characters';
          } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $db->prepare('UPDATE users SET name=?, email=?, role=?, password_hash=? WHERE id=?');
            $stmt->execute([$name, $email, $role, $hash, $id]);
          }
        } else {
          $stmt = $db->prepare('UPDATE users SET name=?, email=?, role=? WHERE id=?');
          $stmt->execute([$name, $email, $role, $id]);
        }
        if ($error === '') {
          log_activity($db, ($user['id'] ?? null), 'user_update', ['id'=>$id,'name'=>$name,'role'=>$role]);
          flash_add('success', 'User updated successfully');
          header('Location: users.php');
          exit;
        }
      }
    }
  } elseif ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($currentRole !== 'super') {
      $error = 'Only a super admin can permanently delete users';
    } elseif ($id <= 0) {
      $error = 'Invalid user';
    } elseif ($id === (int)($user['id'] ?? 0)) {
      $error = 'You cannot delete your own account';
    } else {
      if ($currentRole === 'operation') {
        try {
          $stRole = $db->prepare('SELECT role FROM users WHERE id=?');
          $stRole->execute([$id]);
          $targetRole = strtolower((string)($stRole->fetchColumn() ?? ''));
          if ($targetRole !== 'supervisor') {
            $error = 'Invalid user';
          }
        } catch (Throwable $e) {
          $error = 'Invalid user';
        }
      }

      // if deleting last super, block
      $st = $db->prepare('SELECT role, is_active FROM users WHERE id=?');
      $st->execute([$id]);
      $targetUser = $st->fetch(PDO::FETCH_ASSOC);
      $role = (string)($targetUser['role'] ?? 'user');
      if (!$targetUser) {
        $error = 'User not found';
      } elseif ((int)($targetUser['is_active'] ?? 1) === 1) {
        $error = 'Deactivate the user before permanently deleting the account';
      } elseif ($role === 'super') {
        $countSuper = (int)$db->query("SELECT COUNT(*) FROM users WHERE role='super'")->fetchColumn();
        if ($countSuper <= 1) {
          $error = 'Cannot delete the last super user';
        }
      }
      if ($error === '') {
        try {
          if (!function_exists('db_delete_user')) {
            throw new RuntimeException('Safe user deletion is unavailable');
          }
          $deleted = db_delete_user($id);

          if ($deleted > 0) {
            $check = $db->prepare('SELECT 1 FROM users WHERE id=?');
            $check->execute([$id]);
            if ($check->fetchColumn()) {
              $error = 'Delete failed: the user still exists in the database';
            } else {
              log_activity($db, ($user['id'] ?? null), 'user_delete', ['id'=>$id]);
              flash_add('delete_success', 'User permanently deleted successfully');
            }
          } else {
            $error = 'User not found or could not be deleted';
          }
        } catch (Throwable $e) {
          @ini_set('log_errors', '1');
          @error_log('[users.php] Delete user failed: ' . $e->getMessage());
          $error = 'Delete failed: ' . $e->getMessage();
        }
      }
    }
    if ($error !== '') {
      flash_add('delete_error', $error);
    }
    header('Location: users');
    exit;
  }
}

// Load users
$listSql = 'SELECT id,name,email,role,is_active,created_at FROM users';
$listParams = [];
if ($currentRole === 'operation') {
  $listSql .= " WHERE role='supervisor'";
}
$listSql .= ' ORDER BY created_at DESC, id DESC';
$listStmt = $db->prepare($listSql);
$listStmt->execute($listParams);
$list = $listStmt->fetchAll(PDO::FETCH_ASSOC);
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editUser = null;
if ($editId) {
  $editUser = get_user_by_id($db, $editId);
  if ($currentRole === 'operation' && strtolower($editUser['role'] ?? '') !== 'supervisor') {
    $editUser = null;
  }
}
$flashes = flash_consume();
$deletePopup = null;
foreach ($flashes as $flash) {
  if (in_array($flash['type'] ?? '', ['delete_success', 'delete_error'], true)) {
    $deletePopup = (string)($flash['message'] ?? '');
    break;
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Users | EventPro</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <script>
    tailwind = window.tailwind || {};
    tailwind.config = { 
      corePlugins: { preflight: false },
      theme: {
        extend: {
          colors: {
            primary: '#3b82f6',
            secondary: '#8b5cf6',
            success: '#10b981',
            warning: '#f59e0b',
            danger: '#ef4444',
            dark: '#1f2937',
            light: '#f8fafc'
          }
        }
      }
    };
  </script>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    :root {
      --primary: #3b82f6;
      --secondary: #8b5cf6;
      --success: #10b981;
      --warning: #f59e0b;
      --danger: #ef4444;
      --dark: #1f2937;
      --light: #f8fafc;
    }
    
    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }
    
    body {
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;
      background-color: #f1f5f9;
      color: #334155;
      line-height: 1.5;
    }
    
    .dashboard-container {
      display: flex;
      min-height: 100vh;
    }
    
    /* Sidebar Styles */
    .sidebar {
      width: 220px;
      background: linear-gradient(180deg, #1e293b 0%, #0f172a 100%);
      color: white;
      padding: 1.5rem 1rem;
      display: flex;
      flex-direction: column;
      position: fixed;
      height: 100vh;
      overflow-y: auto;
      z-index: 100;
      transition: all 0.3s ease;
    }
    
    .brand {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 0 0.5rem 1.5rem;
      border-bottom: 1px solid rgba(255,255,255,0.1);
      margin-bottom: 1.5rem;
    }
    
    .brand img {
      width: 36px;
      height: 36px;
      border-radius: 10px;
      object-fit: cover;
    }
    
    .brand-title {
      font-weight: 700;
      font-size: 1.25rem;
    }
    
    .brand-sub {
      font-size: 0.75rem;
      opacity: 0.7;
    }
    
    .nav-menu {
      display: flex;
      flex-direction: column;
      gap: 0.5rem;
      flex: 1;
    }
    
    .nav-item {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 0.75rem 1rem;
      border-radius: 8px;
      color: rgba(255,255,255,0.8);
      text-decoration: none;
      transition: all 0.2s;
    }
    
    .nav-item:hover, .nav-item.active {
      background-color: rgba(255,255,255,0.1);
      color: white;
    }
    
    .nav-item i {
      width: 20px;
      text-align: center;
    }
    
    .sidebar-footer {
      margin-top: auto;
      padding-top: 1.5rem;
      border-top: 1px solid rgba(255,255,255,0.1);
      font-size: 0.75rem;
      opacity: 0.7;
      line-height: 1.4;
    }
    
    /* Main Content */
    .main-content {
      flex: 1;
      margin-left: 220px;
      padding: 1rem 1.5rem;
      width: calc(100% - 220px);
      min-height: 100vh;
    }
    
    /* Top Bar */
    .top-bar {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 2rem;
      padding-bottom: 1rem;
      border-bottom: 1px solid #e2e8f0;
    }
    
    .page-title h1 {
      font-size: 1.75rem;
      font-weight: 700;
      color: #1e293b;
    }
    
    .page-title p {
      color: #64748b;
      font-size: 0.9rem;
    }
    
    .user-menu {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      flex-wrap: nowrap; /* keep date + user on one row like Super Dashboard */
    }
    
    .date-display {
      background: white;
      padding: 0.5rem 1rem;
      border-radius: 8px;
      box-shadow: 0 1px 3px rgba(0,0,0,0.1);
      font-weight: 500;
      white-space: nowrap; /* prevent date from breaking into two lines */
    }
    
    .user-profile {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      position: relative;
      cursor: pointer;
    }
    
    /* Keep user name on a single line in header (match Super Dashboard) */
    .user-profile > div:nth-child(2) {
      white-space: nowrap;
    }
    
    .avatar {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      background: linear-gradient(135deg, #3b82f6, #8b5cf6);
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      font-weight: 600;
    }
    
    .profile-dropdown {
      position: absolute;
      top: 100%;
      right: 0;
      background: white;
      border-radius: 8px;
      box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1);
      padding: 0.5rem;
      min-width: 180px;
      display: none;
      z-index: 10;
    }
    
    .profile-dropdown.show {
      display: block;
    }
    
    .profile-dropdown a {
      display: block;
      padding: 0.75rem 1rem;
      border-radius: 6px;
      color: #475569;
      text-decoration: none;
      transition: all 0.2s;
    }
    
    .profile-dropdown a:hover {
      background-color: #f1f5f9;
      color: #3b82f6;
    }
    
    /* Content Sections */
    .content-section {
      background: white;
      border-radius: 12px;
      padding: 1.5rem;
      margin-bottom: 1.5rem;
      box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }
    
    .section-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 1.5rem;
    }
    
    .section-title {
      font-size: 1.25rem;
      font-weight: 600;
      color: #1e293b;
    }
    
    .section-actions {
      display: flex;
      gap: 0.75rem;
    }
    
    .btn {
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      padding: 0.5rem 1rem;
      border-radius: 6px;
      font-weight: 500;
      text-decoration: none;
      transition: all 0.2s;
      cursor: pointer;
      border: none;
      font-size: 0.875rem;
    }
    
    .btn-primary {
      background-color: #3b82f6;
      color: white;
    }
    
    .btn-primary:hover {
      background-color: #2563eb;
    }
    
    .btn-secondary {
      background-color: #f1f5f9;
      color: #475569;
    }
    
    .btn-secondary:hover {
      background-color: #e2e8f0;
    }
    
    .btn-success {
      background-color: #10b981;
      color: white;
    }
    
    .btn-success:hover {
      background-color: #059669;
    }
    
    .btn-warning {
      background-color: #f59e0b;
      color: white;
    }
    
    .btn-warning:hover {
      background-color: #d97706;
    }
    
    .btn-danger {
      background-color: #ef4444;
      color: white;
    }
    
    .btn-danger:hover {
      background-color: #dc2626;
    }
    
    .btn-sm {
      padding: 0.25rem 0.75rem;
      font-size: 0.75rem;
    }
    
    /* Table Styles */
    .table-container {
      overflow-x: auto;
      border-radius: 8px;
      border: 1px solid #e2e8f0;
    }
    
    .data-table {
      width: 100%;
      border-collapse: collapse;
      min-width: 720px;
    }
    
    .data-table th {
      background: #f8fafc;
      text-align: left;
      padding: 1rem;
      border-bottom: 1px solid #e2e8f0;
      font-size: 0.875rem;
      color: #475569;
      font-weight: 600;
    }
    
    .data-table td {
      padding: 1rem;
      border-bottom: 1px solid #f1f5f9;
      font-size: 0.875rem;
    }
    
    .data-table tr:last-child td {
      border-bottom: none;
    }
    
    .data-table tr:hover td {
      background-color: #f8fafc;
    }
    
    /* Form Styles */
    .form-grid {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 1.5rem;
    }
    
    .form-group {
      margin-bottom: 1rem;
    }
    
    .form-group label {
      display: block;
      font-weight: 600;
      font-size: 0.875rem;
      color: #374151;
      margin-bottom: 0.5rem;
    }
    
    .form-input {
      width: 100%;
      padding: 0.75rem;
      border: 1px solid #d1d5db;
      border-radius: 6px;
      background: white;
      font-size: 0.875rem;
      transition: all 0.2s;
    }
    
    .form-input:focus {
      outline: none;
      border-color: #3b82f6;
      box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }
    
    .form-input::placeholder {
      color: #9ca3af;
    }
    
    /* Alert Styles */
    .alert {
      padding: 1rem;
      border-radius: 8px;
      margin-bottom: 1rem;
      border-left: 4px solid;
    }
    
    .alert-success {
      background-color: #f0fdf4;
      border-left-color: #10b981;
      color: #065f46;
    }
    
    .alert-error {
      background-color: #fef2f2;
      border-left-color: #ef4444;
      color: #991b1b;
    }
    
    /* Badge Styles */
    .badge {
      display: inline-flex;
      align-items: center;
      padding: 0.25rem 0.75rem;
      border-radius: 9999px;
      font-size: 0.75rem;
      font-weight: 600;
    }
    
    .badge-super {
      background-color: #dbeafe;
      color: #1e40af;
    }
    
    .badge-user {
      background-color: #f3f4f6;
      color: #374151;
    }

    .badge-active {
      background-color: #dcfce7;
      color: #166534;
    }

    .badge-inactive {
      background-color: #fee2e2;
      color: #991b1b;
    }

    .user-inactive-row {
      background-color: #fff7f7;
    }

    .user-inactive-row td {
      color: #64748b;
    }

    .btn-success {
      background-color: #16a34a;
      color: white;
    }

    .btn-warning {
      background-color: #f59e0b;
      color: white;
    }
    
    /* Mobile Responsive - delegate sidebar behavior to shared sidebar.php */
    @media (max-width: 1024px) {
      .main-content {
        margin-left: 0;
        width: 100%;
        padding: 1rem;
      }
      
      .form-grid {
        grid-template-columns: 1fr;
      }
    }
    
    @media (max-width: 768px) {
      .section-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 1rem;
      }
      
      .section-actions {
        width: 100%;
        justify-content: space-between;
      }
      
      .top-bar {
        flex-direction: column;
        gap: 1rem;
        align-items: flex-start;
      }
      
      .user-menu {
        width: 100%;
        justify-content: space-between;
      }
    }
    
    @media (max-width: 640px) {
      .main-content {
        margin-left: 0;
        width: 100%;
      }
      
      .btn-sm {
        padding: 0.25rem 0.5rem;
        font-size: 0.7rem;
      }
    }
  </style>
</head>
<body>
  <div class="dashboard-container">
    <!-- Sidebar -->
    <?php include __DIR__ . '/sidebar.php'; ?>
    
    <!-- Main Content -->
    <main class="main-content">
      <!-- Top Bar -->
      <div class="top-bar">
        <div class="page-title">
          <h1>Users</h1>
          <p>Manage system users and roles</p>
        </div>
        
        <div class="user-menu">
          <div class="date-display">
            <i class="far fa-calendar-alt mr-2"></i>
            <?php echo date('l, F j, Y'); ?>
          </div>
          
          <div class="user-profile" id="userProfile">
            <div class="avatar">
              <?php 
                $displayName = $user['name'] ?: $user['email']; 
                $initial = strtoupper(mb_substr($displayName, 0, 1)); 
                echo htmlspecialchars($initial); 
              ?>
            </div>
            <div>
              <div style="font-weight: 600;"><?php echo htmlspecialchars($displayName); ?></div>
              <div style="font-size: 0.75rem; color: #64748b;"><?php echo get_role_label($user['role'] ?? 'user'); ?></div>
            </div>
            <i class="fas fa-chevron-down ml-2" style="color: #64748b;"></i>
            
            <div class="profile-dropdown" id="profileDropdown">
              <a href="profile.php"><i class="fas fa-user mr-2"></i> My Profile</a>
              <a href="settings.php"><i class="fas fa-cog mr-2"></i> Settings</a>
              <a href="?logout"><i class="fas fa-sign-out-alt mr-2"></i> Logout</a>
            </div>
          </div>
        </div>
      </div>

      <!-- Flash Messages -->
      <?php foreach ($flashes as $f): ?>
        <div class="alert <?php echo in_array($f['type'], ['success', 'delete_success'], true) ? 'alert-success' : 'alert-error'; ?>">
          <?php echo htmlspecialchars($f['message']); ?>
        </div>
      <?php endforeach; ?>
      
      <?php if ($error): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
      <?php endif; ?>

      <!-- Users List -->
      <div class="content-section" id="users-list">
        <div class="section-header">
          <h2 class="section-title">All Users</h2>
          <?php if (in_array($currentRole, ['super', 'admin', 'operation'], true)): ?>
          <div class="section-actions">
            <a href="users.php#add" class="btn btn-primary">
              <i class="fas fa-user-plus mr-2"></i> Add User
            </a>
          </div>
          <?php endif; ?>
        </div>
        <?php if (in_array($currentRole, ['super', 'admin', 'operation'], true)): ?>
        <div class="table-container">
          <table class="data-table">
            <thead>
              <tr>
                <th>#</th>
                <th>Name</th>
                <th>Email</th>
                <th>Role</th>
                <th>Status</th>
                <th>Created</th>
                <th style="width: 280px;">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($list as $index => $u): ?>
              <tr class="<?php echo (int)($u['is_active'] ?? 1) === 1 ? '' : 'user-inactive-row'; ?>">
                <td><?php echo $index + 1; ?></td>
                <td><?php echo htmlspecialchars($u['name']); ?></td>
                <td><?php echo htmlspecialchars($u['email']); ?></td>
                <td>
                  <span class="badge <?php echo $u['role'] === 'super' ? 'badge-super' : 'badge-user'; ?>">
                    <?php echo htmlspecialchars(ucfirst($u['role'])); ?>
                  </span>
                </td>
                <td><span class="badge <?php echo (int)($u['is_active'] ?? 1) === 1 ? 'badge-active' : 'badge-inactive'; ?>"><?php echo (int)($u['is_active'] ?? 1) === 1 ? 'Active' : 'Inactive'; ?></span></td>
                <td><?php echo htmlspecialchars($u['created_at']); ?></td>
                <td>
                  <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                    <a href="users.php?edit=<?php echo (int)$u['id']; ?>#edit" class="btn btn-secondary btn-sm">
                      <i class="fas fa-edit mr-1"></i> Edit
                    </a>
                    <?php if (in_array($currentRole, ['admin', 'super'], true) && (int)$u['id'] !== (int)($user['id'] ?? 0) && ($currentRole === 'super' || ($u['role'] ?? '') !== 'super')): ?>
                    <form method="post" action="users?account_status=1" style="display: inline;">
                      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                      <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                      <input type="hidden" name="is_active" value="<?php echo (int)($u['is_active'] ?? 1) === 1 ? 0 : 1; ?>">
                      <button type="submit" class="btn <?php echo (int)($u['is_active'] ?? 1) === 1 ? 'btn-warning' : 'btn-success'; ?> btn-sm">
                        <i class="fas <?php echo (int)($u['is_active'] ?? 1) === 1 ? 'fa-user-slash' : 'fa-user-check'; ?> mr-1"></i> <?php echo (int)($u['is_active'] ?? 1) === 1 ? 'Deactivate' : 'Activate'; ?>
                      </button>
                    </form>
                    <?php endif; ?>
                    <?php if ($currentRole === 'super' && (int)$u['id'] !== (int)($user['id'] ?? 0) && (int)($u['is_active'] ?? 1) === 0): ?>
                    <form method="post" action="users" style="display: inline;" class="js-delete-user" data-user-label="<?php echo htmlspecialchars(($u['name'] ?? '') . ' (' . ($u['email'] ?? '') . ')'); ?>">
                      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                      <input type="hidden" name="__action" value="delete">
                      <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                      <button type="submit" class="btn btn-danger btn-sm">
                        <i class="fas fa-trash mr-1"></i> Delete
                      </button>
                    </form>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php if (!$list): ?>
              <tr>
                <td colspan="7" style="text-align: center; color: #64748b; padding: 2rem;">
                  No users found
                </td>
              </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <?php else: ?>
        <div class="alert alert-info" style="margin: 2rem 0;">
          <i class="fas fa-info-circle mr-2"></i>
          User management is restricted for administrators and super administrators.
        </div>
        <?php endif; ?>
      </div>

      <!-- Add User Form -->
      <?php if (in_array($currentRole, ['super', 'admin', 'operation'], true)): ?>
      <div class="content-section" id="add">
        <div class="section-header">
          <h2 class="section-title">Add User</h2>
        </div>
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
          <input type="hidden" name="__action" value="add">
          <div class="form-grid">
            <div class="form-group">
              <label for="name">Full Name</label>
              <input type="text" id="name" name="name" class="form-input" required>
            </div>
            <div class="form-group">
              <label for="email">Email</label>
              <input type="email" id="email" name="email" class="form-input" required>
            </div>
            <div class="form-group">
              <label for="password">Password</label>
              <input type="password" id="password" name="password" class="form-input" required>
            </div>
            <div class="form-group">
              <label for="role">Role</label>
              <select id="role" name="role" class="form-input">
                <option value="finance">Finance</option>
                <option value="sales">Sales</option>
                <option value="accountant">Accountant</option>
                <option value="cashier">Cashier</option>
                <option value="operation">Operation</option>
                <option value="production">Production</option>
                <option value="store">Store</option>
                <option value="graphic">Graphic</option>
                <option value="admin">Administrator</option>
                <option value="super">Super Admin</option>
              </select>
            </div>
          </div>
          <div style="margin-top: 1.5rem;">
            <button type="submit" class="btn btn-primary">
              <i class="fas fa-save mr-2"></i> Save User
            </button>
          </div>
        </form>
      </div>
        <?php endif; ?>

        <!-- Edit User Form -->
      <?php if (in_array($currentRole, ['super', 'admin', 'operation'], true)): ?>
      <div class="content-section" id="edit">
        <div class="section-header">
          <h2 class="section-title">Edit User</h2>
        </div>
        <?php if ($editUser): ?>
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
          <input type="hidden" name="__action" value="edit">
          <input type="hidden" name="id" value="<?php echo (int)$editUser['id']; ?>">
          <div class="form-grid">
            <div class="form-group">
              <label for="edit_name">Full Name</label>
              <input type="text" id="edit_name" name="name" class="form-input" required value="<?php echo htmlspecialchars($editUser['name']); ?>">
            </div>
            <div class="form-group">
              <label for="edit_email">Email</label>
              <input type="email" id="edit_email" name="email" class="form-input" required value="<?php echo htmlspecialchars($editUser['email']); ?>">
            </div>
            <div class="form-group">
              <label for="edit_password">New Password (leave blank to keep unchanged)</label>
              <input type="password" id="edit_password" name="password" class="form-input" placeholder="••••••">
            </div>
            <div class="form-group">
              <label for="edit_role">Role</label>
              <select id="edit_role" name="role" class="form-input">
                <option value="finance" <?php echo $editUser['role'] === 'finance' ? 'selected' : ''; ?>>Finance</option>
                <option value="sales" <?php echo $editUser['role'] === 'sales' ? 'selected' : ''; ?>>Sales</option>
                <option value="accountant" <?php echo $editUser['role'] === 'accountant' ? 'selected' : ''; ?>>Accountant</option>
                <option value="cashier" <?php echo $editUser['role'] === 'cashier' ? 'selected' : ''; ?>>Cashier</option>
                <option value="operation" <?php echo $editUser['role'] === 'operation' ? 'selected' : ''; ?>>Operation</option>
                <option value="production" <?php echo $editUser['role'] === 'production' ? 'selected' : ''; ?>>Production</option>
                <option value="store" <?php echo $editUser['role'] === 'store' ? 'selected' : ''; ?>>Store</option>
                <option value="graphic" <?php echo $editUser['role'] === 'graphic' ? 'selected' : ''; ?>>Graphic</option>
                <option value="admin" <?php echo $editUser['role'] === 'admin' ? 'selected' : ''; ?>>Administrator</option>
                <option value="super" <?php echo $editUser['role'] === 'super' ? 'selected' : ''; ?>>Super Admin</option>
              </select>
            </div>
          </div>
          <div style="margin-top: 1.5rem; display: flex; gap: 0.75rem;">
            <button type="submit" class="btn btn-primary">
              <i class="fas fa-save mr-2"></i> Update User
            </button>
            <a href="users.php" class="btn btn-secondary">
              <i class="fas fa-times mr-2"></i> Cancel
            </a>
          </div>
        </form>
        <?php else: ?>
          <div style="color: #64748b; text-align: center; padding: 2rem;">
            Select a user to edit from the list above.
          </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </main>
  </div>

  <script>
    <?php if ($deletePopup !== null): ?>
    window.alert(<?php echo json_encode($deletePopup, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>);
    <?php endif; ?>

    // Toggle profile dropdown
    if (!window.__profileDropdownBound) {
      document.getElementById('userProfile').addEventListener('click', function() {
        document.getElementById('profileDropdown').classList.toggle('show');
      });
      
      // Close dropdown when clicking outside
      document.addEventListener('click', function(event) {
        const profile = document.getElementById('userProfile');
        const dropdown = document.getElementById('profileDropdown');
        
        if (!profile.contains(event.target)) {
          dropdown.classList.remove('show');
        }
      });
    }

    document.querySelectorAll('form.js-delete-user').forEach(function (form) {
      form.addEventListener('submit', function (e) {
        var label = form.getAttribute('data-user-label') || 'this user';
        var ok = confirm('Permanently delete ' + label + '? This cannot be undone.');
        if (!ok) {
          e.preventDefault();
        }
      });
    });

    // View behavior for Users list / Add User / Edit User
    (function() {
      const listSection = document.getElementById('users-list');
      const addSection = document.getElementById('add');
      const editSection = document.getElementById('edit');
      if (!listSection) return;

      const hasEditUser = <?php echo $editUser ? 'true' : 'false'; ?>;
      const hasAddSection = !!addSection;
      const hasEditSection = !!editSection;

      function showView(view) {
        if (view === 'edit' && (!hasEditUser || !hasEditSection)) {
          // If there is no valid user to edit or edit section doesn't exist, go back to list
          view = 'list';
        }

        if (view === 'add' && hasAddSection) {
          listSection.style.display = 'none';
          addSection.style.display = 'block';
          if (hasEditSection) editSection.style.display = 'none';
        } else if (view === 'edit' && hasEditSection) {
          listSection.style.display = 'none';
          if (hasAddSection) addSection.style.display = 'none';
          editSection.style.display = 'block';
        } else {
          // default: show only users list
          listSection.style.display = 'block';
          if (hasAddSection) addSection.style.display = 'none';
          if (hasEditSection) editSection.style.display = 'none';
        }
      }

      function applyFromHash() {
        const hash = window.location.hash || '';
        if (hash === '#add' && hasAddSection) {
          showView('add');
        } else if (hash === '#edit' && hasEditSection) {
          showView('edit');
        } else {
          showView('list');
        }
      }

      // Initial state (default to list when coming from sidebar with no hash)
      applyFromHash();

      // React to hash changes (e.g. clicking Add User or Edit buttons)
      window.addEventListener('hashchange', applyFromHash);
    })();
  </script>
</body>
</html>