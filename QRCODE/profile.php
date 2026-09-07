<?php
require __DIR__ . '/auth.php';

require_login();
$db   = get_db();
$user = current_user();

if (!$user) {
    header('Location: login.php');
    exit;
}

$pdoUserStmt = $db->prepare('SELECT id, name, email, role FROM users WHERE id = ?');
$pdoUserStmt->execute([$user['id']]);
$currentDbUser = $pdoUserStmt->fetch(PDO::FETCH_ASSOC);

if (!$currentDbUser) {
    flash_add('error', 'User account not found.');
    header('Location: login.php');
    exit;
}

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_abort();
    $action = $_POST['__action'] ?? '';

    if ($action === 'update_profile') {
        $name  = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');

        if ($name === '' || $email === '') {
            $error = 'Name and email are required';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email address';
        } else {
            $dup = $db->prepare('SELECT 1 FROM users WHERE email = ? AND id <> ?');
            $dup->execute([$email, $currentDbUser['id']]);
            if ($dup->fetch()) {
                $error = 'Email already in use by another account';
            } else {
                $stmt = $db->prepare('UPDATE users SET name = ?, email = ? WHERE id = ?');
                $stmt->execute([$name, $email, $currentDbUser['id']]);

                // Update in-memory session user so header shows new values
                $_SESSION['user']['name']  = $name;
                $_SESSION['user']['email'] = $email;

                if (function_exists('db_log_activity')) {
                    db_log_activity($currentDbUser['id'], 'profile_update', json_encode(['name' => $name, 'email' => $email]));
                }

                flash_add('success', 'Profile updated successfully');
                header('Location: profile.php');
                exit;
            }
        }
    } elseif ($action === 'change_password') {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword     = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
            $error = 'All password fields are required';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'New password and confirmation do not match';
        } elseif (strlen($newPassword) < 6) {
            $error = 'New password must be at least 6 characters';
        } else {
            // Verify current password using existing helper
            if (!db_verify_user_password($currentDbUser['email'], $currentPassword)) {
                $error = 'Current password is incorrect';
            } else {
                $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt = $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
                $stmt->execute([$hash, $currentDbUser['id']]);

                if (function_exists('db_log_activity')) {
                    db_log_activity($currentDbUser['id'], 'password_change', 'User changed their password');
                }

                flash_add('success', 'Password changed successfully');
                header('Location: profile.php');
                exit;
            }
        }
    }
}

$flashes = flash_consume();
$displayName = $user['name'] ?: $user['email'];
$initial     = strtoupper(mb_substr($displayName, 0, 1));
$roleLabel   = function_exists('get_role_label') ? get_role_label($user['role'] ?? 'user') : ucfirst($user['role'] ?? 'user');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>My Profile | EventPro</title>
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

    /* Sidebar Styles (match users.php) */
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

    .nav-item:hover,
    .nav-item.active {
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

    .main-content {
      flex: 1;
      margin-left: 220px;
      padding: 1rem 1.5rem;
      width: calc(100% - 220px);
      min-height: 100vh;
    }

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
      gap: 1rem;
    }

    .date-display {
      background: white;
      padding: 0.5rem 1rem;
      border-radius: 8px;
      box-shadow: 0 1px 3px rgba(0,0,0,0.1);
      font-weight: 500;
    }

    .user-profile {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      position: relative;
      cursor: pointer;
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

    .content-section {
      background: white;
      border-radius: 12px;
      padding: 1.5rem;
      margin-bottom: 1.5rem;
      box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }

    .section-title {
      font-size: 1.25rem;
      font-weight: 600;
      color: #1e293b;
      margin-bottom: 1rem;
    }

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

    @media (max-width: 1024px) {
      .main-content {
        margin-left: 80px;
        width: calc(100% - 80px);
        padding: 1rem;
      }

      .form-grid {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 640px) {
      .main-content {
        margin-left: 0;
        width: 100%;
      }
    }
  </style>
</head>
<body>
  <div class="dashboard-container">
    <?php include __DIR__ . '/sidebar.php'; ?>

    <main class="main-content">
      <div class="top-bar">
        <div class="page-title">
          <h1>My Profile</h1>
          <p>Manage your personal information and account security</p>
        </div>

        <div class="user-menu">
          <div class="date-display">
            <i class="far fa-calendar-alt mr-2"></i>
            <?php echo date('l, F j, Y'); ?>
          </div>

          <div class="user-profile" id="userProfile">
            <div class="avatar"><?php echo htmlspecialchars($initial); ?></div>
            <div>
              <div style="font-weight: 600;"><?php echo htmlspecialchars($displayName); ?></div>
              <div style="font-size: 0.75rem; color: #64748b;"><?php echo htmlspecialchars($roleLabel); ?></div>
            </div>
            <i class="fas fa-chevron-down ml-2" style="color: #64748b;"></i>

            <div class="profile-dropdown" id="profileDropdown">
              <a href="profile.php"><i class="fas fa-user mr-2"></i> My Profile</a>
              <a href="settings.php"><i class="fas fa-cog mr-2"></i> Settings</a>
              <a href="login.php?logout=1"><i class="fas fa-sign-out-alt mr-2"></i> Logout</a>
            </div>
          </div>
        </div>
      </div>

      <?php foreach ($flashes as $f): ?>
        <div class="alert <?php echo $f['type'] === 'success' ? 'alert-success' : 'alert-error'; ?>">
          <?php echo htmlspecialchars($f['message']); ?>
        </div>
      <?php endforeach; ?>

      <?php if ($error): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
      <?php endif; ?>

      <div class="content-section">
        <div class="section-title">Profile Information</div>
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
          <input type="hidden" name="__action" value="update_profile">

          <div class="form-grid">
            <div class="form-group">
              <label for="name">Full Name</label>
              <input id="name" name="name" type="text" class="form-input" required value="<?php echo htmlspecialchars($currentDbUser['name']); ?>">
            </div>
            <div class="form-group">
              <label for="email">Email Address</label>
              <input id="email" name="email" type="email" class="form-input" required value="<?php echo htmlspecialchars($currentDbUser['email']); ?>">
            </div>
          </div>

          <div style="margin-top: 1.5rem;">
            <button type="submit" class="btn btn-primary">
              <i class="fas fa-save mr-2"></i> Save Changes
            </button>
          </div>
        </form>
      </div>

      <div class="content-section">
        <div class="section-title">Change Password</div>
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
          <input type="hidden" name="__action" value="change_password">

          <div class="form-grid">
            <div class="form-group">
              <label for="current_password">Current Password</label>
              <input id="current_password" name="current_password" type="password" class="form-input" required>
            </div>
            <div class="form-group">
              <label for="new_password">New Password</label>
              <input id="new_password" name="new_password" type="password" class="form-input" required>
            </div>
            <div class="form-group">
              <label for="confirm_password">Confirm New Password</label>
              <input id="confirm_password" name="confirm_password" type="password" class="form-input" required>
            </div>
          </div>

          <div style="margin-top: 1.5rem;">
            <button type="submit" class="btn btn-primary">
              <i class="fas fa-key mr-2"></i> Update Password
            </button>
          </div>
        </form>
      </div>
    </main>
  </div>

  <script>
    if (!window.__profileDropdownBound) {
      document.getElementById('userProfile').addEventListener('click', function() {
        document.getElementById('profileDropdown').classList.toggle('show');
      });

      document.addEventListener('click', function(event) {
        const profile = document.getElementById('userProfile');
        const dropdown = document.getElementById('profileDropdown');
        if (!profile.contains(event.target)) {
          dropdown.classList.remove('show');
        }
      });
    }
  </script>
</body>
</html>
