<?php
require __DIR__ . '/auth.php';
require __DIR__ . '/bootstrap.php';
$db = get_db();
$error = '';
error_log('login.php: start, method=' . ($_SERVER['REQUEST_METHOD'] ?? ''));

if (isset($_GET['logout'])) {
    logout_user();
    header('Location: login.php');
    exit;
}

// If already authenticated, skip login and forward to destination
// Allow override with ?force=1 to access login page for troubleshooting
if (current_user() && !isset($_GET['force'])) {
    $me = current_user();
    $default = get_dashboard_for_role($me['role'] ?? 'user');
    $next = isset($_GET['next']) && $_GET['next'] !== '' ? $_GET['next'] : $default;
    header('Location: ' . $next);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    error_log('login.php: POST received for email=' . $email);
    if ($email === '' || $password === '') {
        $error = 'Email and password are required';
        error_log('login.php: missing email or password');
    } else {
        // Use auth helper to verify credentials and establish session
        $loginOk = login_user($email, $password);
        error_log('login.php: login_user result=' . ($loginOk ? '1' : '0'));
        if ($loginOk) {
            $user = current_user();
            // Preserve previous session keys for backward compatibility
            if ($user) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_name'] = $user['name'];
            }
            $default = get_dashboard_for_role($user['role'] ?? 'user');
            $next = isset($_POST['next']) && $_POST['next'] !== '' ? $_POST['next'] : (isset($_GET['next']) && $_GET['next'] !== '' ? $_GET['next'] : $default);
            error_log('login.php: redirecting to ' . $next);
            header('Location: ' . $next);
            exit;
        } else {
            $error = 'Invalid credentials';
            error_log('login.php: invalid credentials for email=' . $email);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Login | Hugo Domingo Events</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <script>
    tailwind = window.tailwind || {};
    tailwind.config = { 
      corePlugins: { preflight: false },
      theme: {
        extend: {
          colors: {
            primary: '#1e40af',
            secondary: '#3730a3',
            success: '#059669',
            warning: '#d97706',
            danger: '#dc2626',
            dark: '#1e293b',
            light: '#f8fafc'
          }
        }
      }
    };
  </script>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    :root {
      --primary: #6366f1;
      --accent: #14b8a6;
      --midnight: #020617;
      --card-bg: rgba(248, 250, 252, 0.97);
    }
    
    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }
    
    body {
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      background: radial-gradient(circle at 20% 20%, rgba(99, 102, 241, 0.35), transparent 45%),
                  radial-gradient(circle at 80% 0%, rgba(20, 184, 166, 0.3), transparent 50%),
                  #000000;
      color: #e5e7eb;
      line-height: 1.5;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 1.5rem;
      position: relative;
      overflow: hidden;
    }
    
    body::before {
      content: '';
      position: absolute;
      inset: 10% -20% 40% -20%;
      background: conic-gradient(from 120deg, rgba(99, 102, 241, 0.25), rgba(20, 184, 166, 0.15), transparent 60%);
      filter: blur(120px);
      opacity: 0.8;
      animation: aurora 16s ease-in-out infinite alternate;
      pointer-events: none;
    }
    
    .auth-wrapper {
      width: 100%;
      max-width: 1100px;
      display: flex;
      align-items: stretch;
      justify-content: space-between;
      gap: 2.5rem;
      position: relative;
      z-index: 1;
    }
    
    .auth-left {
      flex: 1.1;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    
    .auth-logo-full {
      width: 100%;
      max-width: 520px;
      border-radius: 0;
      box-shadow: none;
      object-fit: cover;
      display: block;
      backdrop-filter: none;
      animation: float 12s ease-in-out infinite;
    }
    
    .auth-right {
      flex: 0.9;
      display: flex;
      align-items: center;
      justify-content: flex-end;
    }
    
    .login-container {
      width: 100%;
      max-width: 380px;
    }
    
    .login-card {
      position: relative;
      background: var(--card-bg);
      border-radius: 18px;
      box-shadow: 0 20px 50px rgba(15, 23, 42, 0.35);
      overflow: hidden;
      padding: 2.3rem;
      border: 1px solid rgba(226, 232, 240, 0.5);
      backdrop-filter: blur(6px);
    }
    
    .login-card::before {
      content: '';
      position: absolute;
      inset: -50% -20% auto;
      height: 180px;
      background: linear-gradient(120deg, rgba(99, 102, 241, 0.35), rgba(20, 184, 166, 0.25));
      opacity: 0.35;
      transform: rotate(-8deg);
      pointer-events: none;
    }
    
    .login-card > * {
      position: relative;
      z-index: 1;
    }
    
    .login-header {
      text-align: center;
      margin-bottom: 1.75rem;
    }
    
    .login-title {
      font-size: 1.5rem;
      font-weight: 700;
      color: #1e293b;
      margin-bottom: 0.25rem;
    }
    
    .login-subtitle {
      color: #475569;
      font-size: 0.875rem;
      letter-spacing: 0.04em;
    }
    
    .alert {
      padding: 0.75rem;
      border-radius: 6px;
      margin-bottom: 1.25rem;
      display: flex;
      align-items: center;
      gap: 0.5rem;
      font-size: 0.875rem;
    }
    
    .alert-error {
      background-color: #fef2f2;
      border: 1px solid #fecaca;
      color: #dc2626;
    }
    
    .form-group {
      margin-bottom: 1.25rem;
      position: relative;
      transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }
    
    .form-group:focus-within {
      transform: translateX(4px);
    }
    
    .form-label {
      display: block;
      margin-bottom: 0.375rem;
      font-weight: 600;
      color: #1f2937;
      font-size: 0.875rem;
      letter-spacing: 0.02em;
    }
    
    .form-input {
      width: 100%;
      padding: 0.65rem 0.95rem;
      border: 1px solid rgba(148, 163, 184, 0.5);
      border-radius: 10px;
      font-size: 0.9rem;
      transition: all 0.25s ease;
      background: rgba(248, 250, 252, 0.9);
      box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.3);
      color: #0f172a;
    }
    
    .form-input:focus {
      outline: none;
      border-color: var(--primary);
      box-shadow: 0 0 25px rgba(99, 102, 241, 0.15);
      background: #ffffff;
    }
    
    .form-input::placeholder {
      color: #94a3b8;
    }
    
    .form-input-group {
      position: relative;
    }
    
    .form-input-icon {
      position: absolute;
      left: 0.875rem;
      top: 50%;
      transform: translateY(-50%);
      color: #6b7280;
      font-size: 0.875rem;
    }
    
    .form-input.with-icon {
      padding-left: 2.5rem;
    }
    
    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 0.375rem;
      padding: 0.625rem 1.25rem;
      border-radius: 6px;
      font-weight: 600;
      text-decoration: none;
      transition: all 0.2s;
      cursor: pointer;
      border: none;
      font-size: 0.875rem;
      width: 100%;
    }
    
    .btn-primary {
      background: linear-gradient(120deg, rgba(99, 102, 241, 1), rgba(59, 130, 246, 0.95), rgba(14, 165, 233, 0.9));
      color: #ffffff;
      border-radius: 999px;
      box-shadow: 0 15px 30px rgba(37, 99, 235, 0.3);
      position: relative;
      overflow: hidden;
    }
    
    .btn-primary:hover {
      transform: translateY(-2px) scale(1.01);
      box-shadow: 0 20px 30px rgba(37, 99, 235, 0.35);
    }
    
    .login-footer {
      text-align: center;
      margin-top: 1.5rem;
      padding-top: 1.25rem;
      border-top: 1px solid #e2e8f0;
    }
    
    .login-footer p {
      color: #64748b;
      margin-bottom: 0.75rem;
      font-size: 0.8125rem;
    }
    
    .copyright {
      text-align: center;
      margin-top: 1.5rem;
      color: white;
      opacity: 0.8;
      font-size: 0.75rem;
    }
    
    @keyframes aurora {
      0% { transform: translateY(0) rotate(0deg); }
      100% { transform: translateY(-40px) rotate(10deg); }
    }
    
    @keyframes float {
      0% { transform: translateY(0) scale(1); }
      50% { transform: translateY(-10px) scale(1.01); }
      100% { transform: translateY(0) scale(1); }
    }
    
    @media (max-width: 900px) {
      body {
        padding: 1.25rem;
      }
      
      .auth-wrapper {
        flex-direction: column-reverse;
        align-items: center;
        max-width: 520px;
      }
      
      .auth-right {
        width: 100%;
        justify-content: center;
      }
      
      .auth-left {
        width: 100%;
      }
    }
    
    @media (max-width: 480px) {
      .login-card {
        padding: 1.5rem;
      }
      
      .login-container {
        max-width: 340px;
      }
    }
  </style>
 </head>
 <body>
  <div class="auth-wrapper">
    <div class="auth-left">
      <img src="hdlogo.png" alt="Brand Logo" class="auth-logo-full">
    </div>
    <div class="auth-right">
      <div class="login-container">
        <div class="login-card">
          <div class="login-header">
            <h1 class="login-title">Hugo Domingo</h1>
            <p class="login-subtitle">Event Management System</p>
          </div>
          
          <?php if ($error): ?>
            <div class="alert alert-error">
              <i class="fas fa-exclamation-circle"></i>
              <?php echo htmlspecialchars($error); ?>
            </div>
          <?php endif; ?>
          
          <form method="post">
            <?php if (isset($_GET['next'])): ?>
              <input type="hidden" name="next" value="<?php echo htmlspecialchars($_GET['next']); ?>">
            <?php endif; ?>
            <?php if (isset($_GET['confirm'])): ?>
              <input type="hidden" name="confirm" value="<?php echo htmlspecialchars($_GET['confirm']); ?>">
            <?php endif; ?>
            <?php if (isset($_GET['rsvp'])): ?>
              <input type="hidden" name="rsvp" value="<?php echo htmlspecialchars($_GET['rsvp']); ?>">
            <?php endif; ?>
            <div class="form-group">
              <label class="form-label">Email Address</label>
              <div class="form-input-group">
                <i class="fas fa-envelope form-input-icon"></i>
                <input type="email" name="email" class="form-input with-icon" placeholder="your@email.com" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
              </div>
            </div>
            
            <div class="form-group">
              <label class="form-label">Password</label>
              <div class="form-input-group">
                <i class="fas fa-lock form-input-icon"></i>
                <input type="password" name="password" class="form-input with-icon" placeholder="Enter your password" required>
              </div>
            </div>
            
            <button type="submit" class="btn btn-primary">
              <i class="fas fa-sign-in-alt"></i>
              Sign In
            </button>
          </form>
          
          <div class="login-footer">
            <p>Contact administrator for account access</p>
          </div>
          
          <div class="copyright">
            <p>&copy; 2024 Hugo Domingo Events. All rights reserved.</p>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const form = document.querySelector('form');
      const submitBtn = form.querySelector('button[type="submit"]');
      
      form.addEventListener('submit', function() {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Signing In...';
      });
      
      // Add input focus effects
      const inputs = document.querySelectorAll('.form-input');
      inputs.forEach(input => {
        input.addEventListener('focus', function() {
          this.style.background = 'white';
          this.style.borderColor = '#1e40af';
        });
        
        input.addEventListener('blur', function() {
          this.style.background = '#f8fafc';
          this.style.borderColor = '#d1d5db';
        });
      });
    });
  </script>
</body>
</html>