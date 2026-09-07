<?php
require __DIR__ . '/auth.php';
$db = get_db();
$error = '';
$info = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Determine requested role (default user)
    $requestedRole = (($_POST['role'] ?? 'user') === 'super') ? 'super' : 'user';
    $superCode = trim($_POST['super_code'] ?? '');

    if ($name === '' || $email === '' || $password === '') {
        $error = 'All fields are required';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters';
    } else {
        $stmt = $db->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = 'Email already registered';
        } else {
            // Role decision: first user is super, otherwise require valid super code to grant super
            $totalUsers = (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();
            $role = 'user';
            if ($totalUsers === 0) {
                $role = 'super';
                $info = 'First account is granted Super access.';
            } elseif ($requestedRole === 'super') {
                $configuredCode = $GLOBALS['SUPER_SIGNUP_CODE'] ?? '';
                if ($configuredCode !== '' && hash_equals($configuredCode, $superCode)) {
                    $role = 'super';
                } else {
                    $error = 'Invalid Super Access Code';
                }
            }

            if ($error === '') {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $db->prepare('INSERT INTO users(name,email,password_hash,role) VALUES(?,?,?,?)');
                $stmt->execute([$name, $email, $hash, $role]);
                // Log the user in using the auth helper (verifies from DB and sets session)
                if (login_user($email, $password)) {
                    $user = current_user();
                    if ($user) {
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['user_email'] = $user['email'];
                        $_SESSION['user_name'] = $user['name'];
                    }
                    header('Location: ' . ($role === 'super' ? 'super_dashboard.php' : 'index.php'));
                    exit;
                } else {
                    $error = 'Account created, but automatic login failed. Please log in manually.';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Sign Up | Hugo Domingo Events</title>
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
      --primary: #1e40af;
      --secondary: #3730a3;
      --success: #059669;
      --warning: #d97706;
      --danger: #dc2626;
      --dark: #1e293b;
      --light: #f8fafc;
    }
    
    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    button,
    input,
    select,
    textarea {
      font: inherit;
    }
    
    body {
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;
      background: linear-gradient(135deg, #1e3a8a 0%, #3730a3 100%);
      color: #334155;
      line-height: 1.5;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 1rem;
    }
    
    .signup-container {
      width: 100%;
      max-width: 400px;
    }
    
    .signup-card {
      background: white;
      border-radius: 12px;
      box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
      overflow: hidden;
      padding: 2rem;
    }
    
    .signup-header {
      text-align: center;
      margin-bottom: 1.5rem;
    }
    
    .logo {
      width: 48px;
      height: 48px;
      background: linear-gradient(135deg, #1e40af, #3730a3);
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 0.75rem;
      color: white;
      font-size: 1.25rem;
    }
    
    .signup-title {
      font-size: 1.5rem;
      font-weight: 700;
      color: #1e293b;
      margin-bottom: 0.25rem;
    }
    
    .signup-subtitle {
      color: #64748b;
      font-size: 0.875rem;
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
    }
    
    .form-label {
      display: block;
      margin-bottom: 0.375rem;
      font-weight: 500;
      color: #374151;
      font-size: 0.875rem;
    }
    
    .form-input {
      width: 100%;
      padding: 0.625rem 0.875rem;
      border: 1px solid #d1d5db;
      border-radius: 6px;
      font-size: 0.875rem;
      transition: all 0.2s;
      background: #f8fafc;
    }
    
    .form-input:focus {
      outline: none;
      border-color: #1e40af;
      box-shadow: 0 0 0 2px rgba(30, 64, 175, 0.1);
      background: white;
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
    
    .password-strength {
      margin-top: 0.25rem;
      font-size: 0.75rem;
      color: #64748b;
    }
    
    .password-requirements {
      background: #f8fafc;
      border-radius: 6px;
      padding: 0.75rem;
      margin-top: 1rem;
      font-size: 0.75rem;
    }
    
    .requirement-title {
      font-weight: 600;
      color: #374151;
      margin-bottom: 0.25rem;
    }
    
    .requirement-list {
      color: #64748b;
      list-style: none;
      padding-left: 0;
    }
    
    .requirement-list li {
      margin-bottom: 0.125rem;
      display: flex;
      align-items: center;
      gap: 0.375rem;
    }
    
    .requirement-list li i {
      font-size: 0.625rem;
      color: #059669;
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
      background: linear-gradient(135deg, #1e40af, #3730a3);
      color: white;
    }
    
    .btn-primary:hover {
      background: linear-gradient(135deg, #1e3a8a, #312e81);
      transform: translateY(-1px);
      box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    }
    
    .btn-secondary {
      background: #f1f5f9;
      color: #475569;
      font-size: 0.8125rem;
      margin-top: 0.75rem;
    }
    
    .btn-secondary:hover {
      background: #e2e8f0;
    }
    
    .signup-footer {
      text-align: center;
      margin-top: 1.5rem;
      padding-top: 1.25rem;
      border-top: 1px solid #e2e8f0;
    }
    
    .signup-footer p {
      color: #64748b;
      font-size: 0.8125rem;
    }
    
    .copyright {
      text-align: center;
      margin-top: 1.5rem;
      color: white;
      opacity: 0.8;
      font-size: 0.75rem;
    }
    
    @media (max-width: 480px) {
      .signup-card {
        padding: 1.5rem;
      }
      
      .signup-container {
        max-width: 360px;
      }
    }
  </style>
</head>
<body>
  <div class="signup-container">
    <div class="signup-card">
      <div class="signup-header">
        <div class="logo">
          <i class="fas fa-user-plus"></i>
        </div>
        <h1 class="signup-title">Create Account</h1>
        <p class="signup-subtitle">Join Hugo Domingo Event System</p>
      </div>
      
      <?php if ($error): ?>
        <div class="alert alert-error">
          <i class="fas fa-exclamation-circle"></i>
          <?php echo htmlspecialchars($error); ?>
        </div>
      <?php endif; ?>
      
      <form method="post">
        <div class="form-group">
          <label class="form-label">Full Name</label>
          <div class="form-input-group">
            <i class="fas fa-user form-input-icon"></i>
            <input type="text" name="name" class="form-input with-icon" placeholder="Enter your full name" required value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>">
          </div>
        </div>
        
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
            <input type="password" name="password" id="password" class="form-input with-icon" placeholder="Create a password" required>
          </div>
          <div class="password-strength" id="passwordStrength"></div>
        </div>
        
        <div class="form-group">
          <label class="form-label">Confirm Password</label>
          <div class="form-input-group">
            <i class="fas fa-lock form-input-icon"></i>
            <input type="password" name="confirm_password" id="confirmPassword" class="form-input with-icon" placeholder="Confirm your password" required>
          </div>
          <div class="password-strength" id="passwordMatch"></div>
        </div>
        
        <div class="password-requirements">
          <div class="requirement-title">Password Requirements:</div>
          <ul class="requirement-list">
            <li><i class="fas fa-check"></i> At least 6 characters</li>
            <li><i class="fas fa-check"></i> Strong passwords are recommended</li>
          </ul>
        </div>
        
        <button type="submit" class="btn btn-primary">
          <i class="fas fa-user-plus"></i>
          Create Account
        </button>
        
        <a href="login.php" class="btn btn-secondary">
          <i class="fas fa-arrow-left"></i>
          Back to Login
        </a>
      </form>
      
      <div class="signup-footer">
        <p>By creating an account, you agree to our terms of service</p>
      </div>
    </div>
    
    <div class="copyright">
      <p>&copy; 2024 Hugo Domingo Events. All rights reserved.</p>
    </div>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const form = document.querySelector('form');
      const passwordInput = document.getElementById('password');
      const confirmPasswordInput = document.getElementById('confirmPassword');
      const passwordStrength = document.getElementById('passwordStrength');
      const passwordMatch = document.getElementById('passwordMatch');
      const submitBtn = form.querySelector('button[type="submit"]');
      
      // Password strength indicator
      passwordInput.addEventListener('input', function() {
        const password = this.value;
        let strength = '';
        let color = '';
        
        if (password.length === 0) {
          strength = '';
        } else if (password.length < 6) {
          strength = 'Weak - too short';
          color = '#dc2626';
        } else if (password.length < 8) {
          strength = 'Fair';
          color = '#d97706';
        } else {
          strength = 'Strong';
          color = '#059669';
        }
        
        passwordStrength.textContent = strength;
        passwordStrength.style.color = color;
      });
      
      // Password match indicator
      confirmPasswordInput.addEventListener('input', function() {
        const password = passwordInput.value;
        const confirm = this.value;
        
        if (confirm.length === 0) {
          passwordMatch.textContent = '';
        } else if (password === confirm) {
          passwordMatch.textContent = 'Passwords match';
          passwordMatch.style.color = '#059669';
        } else {
          passwordMatch.textContent = 'Passwords do not match';
          passwordMatch.style.color = '#dc2626';
        }
      });
      
      form.addEventListener('submit', function() {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating Account...';
      });
      
      // Input focus effects
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