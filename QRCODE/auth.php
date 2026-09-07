<?php
// Start output buffering
if (!headers_sent() && !ob_get_level()) {
    ob_start();
}

// Include required files - db.php FIRST
require_once __DIR__ . '/db.php';

// Secure session cookies
if (session_status() === PHP_SESSION_NONE) {
    // Only set session cookie params if we're not already in a session
    $isHttps = (
        (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') ||
        (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ||
        (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ||
        (strtolower($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '') === 'on')
    );

    $sessionParams = [
        'lifetime' => 86400, // 24 hours
        'path' => '/',
        'domain' => $_SERVER['HTTP_HOST'] ?? '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ];

    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params($sessionParams);
    } else {
        session_set_cookie_params(
            $sessionParams['lifetime'],
            $sessionParams['path'] . '; samesite=Lax',
            $sessionParams['domain'],
            $sessionParams['secure'],
            $sessionParams['httponly']
        );
    }

    // Set a custom session name to avoid conflicts
    session_name('QRCODE_SESSION');
    
    // Start the session
    if (!session_start()) {
        error_log('Failed to start session');
        if (!headers_sent()) {
            header('HTTP/1.1 500 Internal Server Error');
            echo 'Failed to initialize session. Please try again.';
        }
        exit;
    }
}

// Regenerate session ID periodically to prevent session fixation
if (!isset($_SESSION['last_regeneration']) || (time() - $_SESSION['last_regeneration']) > 1800) {
    session_regenerate_id(true);
    $_SESSION['last_regeneration'] = time();
}

// Global security headers
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: no-referrer-when-downgrade');
    
    // Only set HSTS if we're on HTTPS
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
    
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    
    // Disable caching for sensitive pages
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Minimal CSP; adjust as needed if resources are blocked
    header("Content-Security-Policy: default-src 'self' https: data: 'unsafe-inline' 'unsafe-eval'");
}

if (isset($_GET['logout']) && ($_GET['logout'] === '' || $_GET['logout'] === '1')) {
    logout_user();
    header('Location: login.php');
    exit;
}

// Authentication functions
function current_user() {
    $user = $_SESSION['user'] ?? null;
    if (!$user || empty($user['id'])) {
        return null;
    }

    static $checkedUserId = null;
    static $checkedActive = false;
    $userId = (int)$user['id'];
    if ($checkedUserId !== $userId) {
        $checkedUserId = $userId;
        try {
            $checkedActive = db_is_user_active($userId);
        } catch (Throwable $e) {
            $checkedActive = false;
            error_log('User status check failed: ' . $e->getMessage());
        }
    }
    if (!$checkedActive) {
        unset($_SESSION['user']);
        $_SESSION['account_inactive'] = true;
        return null;
    }

    return $user;
}

function login_user($email, $password) {
    $user = db_verify_user_password($email, $password);
    if ($user) {
        // Regenerate session ID on login to prevent fixation
        if (PHP_SESSION_ACTIVE === session_status()) {
            @session_regenerate_id(true);
        }
        
        // Ensure role is set and valid
        $valid_roles = ['super', 'admin', 'finance', 'sales', 'accountant', 'operation', 'production', 'supervisor', 'store', 'graphic'];
        $role = strtolower((string)($user['role'] ?? 'user'));
        if ($role === 'head_sales') {
            $role = 'sales';
        }
        if ($role === 'head_graphic') {
            $role = 'graphic';
        }
        $role = in_array($role, $valid_roles, true) ? $role : 'user';
            
        // Set user session data
        $_SESSION['user'] = [
            'id' => (int)$user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $role,
        ];
        unset($_SESSION['account_inactive']);
        
        // Log the activity
        db_log_activity($user['id'], 'login', "User logged in: {$user['email']}");
        
        // Log login success
        error_log('User logged in: ' . $user['email'] . ' with role: ' . $role);
        return true;
    }
    
    return false;
}

function logout_user() {
    $user_id = $_SESSION['user']['id'] ?? null;
    
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    
    // Log the activity if user was logged in
    if ($user_id) {
        db_log_activity($user_id, 'logout', "User logged out");
    }
}

function require_login() {
    if (!current_user()) {
        $inactive = !empty($_SESSION['account_inactive']);
        unset($_SESSION['account_inactive']);
        header('Location: login.php' . ($inactive ? '?inactive=1' : ''));
        exit;
    }
    // Refresh user from DB to ensure latest role/name/email are applied
    try {
        $me = current_user();
        if ($me && isset($me['id'])) {
            $user_data = db_get_user_by_id($me['id']);
            if (!$user_data || (int)($user_data['is_active'] ?? 1) !== 1) {
                logout_user();
                header('Location: login.php?inactive=1');
                exit;
            }
            if ($user_data) {
                // Only update if something changed to avoid session churn
                if ($user_data['role'] !== ($me['role'] ?? 'user') || $user_data['name'] !== ($me['name'] ?? '') || $user_data['email'] !== ($me['email'] ?? '')) {
                    // Regenerate session ID if user data changed
                    if (PHP_SESSION_ACTIVE === session_status()) {
                        @session_regenerate_id(true);
                    }
                    
                    $role = strtolower((string)($user_data['role'] ?? 'user'));
                    if ($role === 'head_sales') {
                        $role = 'sales';
                    }
                    if ($role === 'head_graphic') {
                        $role = 'graphic';
                    }

                    $_SESSION['user'] = [
                        'id' => (int)$user_data['id'],
                        'name' => $user_data['name'],
                        'email' => $user_data['email'],
                        'role' => $role,
                    ];
                }
            }
        }
    } catch (Throwable $e) {
        // Best-effort; ignore refresh errors
        error_log("User refresh error: " . $e->getMessage());
    }
}

function is_logged_in() {
    return current_user() !== null;
}

function get_current_user_id() {
    $user = current_user();
    return $user ? $user['id'] : null;
}

function get_current_user_role() {
    $user = current_user();
    return $user ? $user['role'] : null;
}

// CSRF protection helpers
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_or_abort(): void {
    $token = $_POST['csrf_token'] ?? '';
    $valid = is_string($token) && hash_equals($_SESSION['csrf_token'] ?? '', $token);
    if (!$valid) {
        http_response_code(403);
        echo 'Invalid CSRF token';
        exit;
    }
}

// Flash messaging helpers
function flash_add(string $type, string $message): void {
    if (!isset($_SESSION['__flash'])) { 
        $_SESSION['__flash'] = []; 
    }
    $_SESSION['__flash'][] = ['type' => $type, 'message' => $message];
}

function flash_consume(): array {
    $msgs = $_SESSION['__flash'] ?? [];
    unset($_SESSION['__flash']);
    return $msgs;
}

// Role-based authorization
function has_role($required_role) {
    $user = current_user();
    if (!$user) return false;
    
    // Role hierarchy: higher number = more access
    // Super has full access, other roles are at same level as user for general access
    $role_hierarchy = [
        'finance' => 1,
        'sales' => 1,
        'accountant' => 1,
        'operation' => 1,
        'production' => 1,
        'supervisor' => 1,
        'store' => 1,
        'graphic' => 1,
        'admin' => 2,
        'super' => 3
    ];
    $user_level = $role_hierarchy[$user['role']] ?? 0;
    $required_level = $role_hierarchy[$required_role] ?? 0;
    
    return $user_level >= $required_level;
}

// Helper to get human-readable role label
function get_role_label($role) {
    $role = strtolower((string)$role);
    $labels = [
        'super' => 'Super Admin',
        'admin' => 'Administrator',
        'finance' => 'Finance',
        'sales' => 'Sales',
        'accountant' => 'Accountant',
        'operation' => 'Operations',
        'production' => 'Production',
        'supervisor' => 'Supervisor',
        'store' => 'Store',
        'graphic' => 'Graphic',
        'graphics' => 'Graphic',
        'graphic designer' => 'Graphic',
        'graphic_designer' => 'Graphic'
    ];
    return $labels[$role] ?? ucfirst($role);
}

function require_role($required_role) {
    if (!has_role($required_role)) {
        http_response_code(403);
        echo 'Access denied. Insufficient permissions.';
        exit;
    }
}

// Get the appropriate dashboard URL for a given role
function get_dashboard_for_role($role) {
    $role = strtolower(trim((string)$role));
    $role = str_replace(['_', '-'], ' ', $role);
    $role = preg_replace('/\s+/', ' ', $role);
    $dashboards = [
        'super' => 'super_dashboard.php',
        'admin' => 'admin_dashboard.php',
        'finance' => 'finance_dashboard.php',
        'sales' => 'index.php',
        'graphic' => 'graphic_dashboard.php',
        'graphics' => 'graphic_dashboard.php',
        'graphic designer' => 'graphic_dashboard.php',
        'accountant' => 'account_dashboard.php',
        'operation' => 'operation_dashboard.php',
        'production' => 'production_dashboard.php',
        'supervisor' => 'supervisor_dashboard.php',
        'store' => 'store_dashboard.php'
    ];
    return $dashboards[$role] ?? 'index.php';
}

// Convenience function to create events with current user
function create_event($event_data) {
    $current_user_id = get_current_user_id();
    if (!$current_user_id) {
        throw new Exception("User must be logged in to create events");
    }
    
    $event_data['user_id'] = $current_user_id;
    return db_create_event($event_data);
}

// Convenience function to get current user's events
function get_user_events() {
    $current_user_id = get_current_user_id();
    if (!$current_user_id) {
        return [];
    }
    
    return db_get_user_events($current_user_id);
}

// URL helper functions (wrapping db_ functions for consistency)
function attendee_url($token) {
    return db_attendee_url($token);
}

function invite_url($inviteToken) {
    return db_invite_url($inviteToken);
}

function auth_url($authToken) {
    return db_auth_url($authToken);
}