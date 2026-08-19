<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';

// Handle logout request
if (isset($_GET['logout'])) {
    logout_user();
    header('Location: login.php');
    exit;
}

require_login();
$db = get_db();
$user = current_user();

if (($user['role'] ?? 'user') !== 'super') {
    header('Location: index.php');
    exit;
}

// Handle event action approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verify_csrf_or_abort();
    $action = $_POST['action'] ?? '';
    
    if ($action === 'approve_event_action') {
        $actionId = (int)($_POST['action_id'] ?? 0);
        $eventId = (int)($_POST['event_id'] ?? 0);
        $actionType = $_POST['action_type'] ?? '';
        
        if ($actionId > 0 && $eventId > 0) {
            // Approve the action
            $stmt = $db->prepare("UPDATE event_actions SET status = 'approved', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?");
            $stmt->execute([$user['id'], $actionId]);
            
            // Delete the event if action was cancel
            if ($actionType === 'cancel') {
                if (function_exists('db_delete_event')) {
                    db_delete_event($eventId);
                } else {
                    $stmt = $db->prepare("DELETE FROM events WHERE id = ?");
                    $stmt->execute([$eventId]);
                }
                flash_add('success', 'Event cancelled and deleted successfully');
            } else {
                flash_add('success', 'Event postponement approved');
            }
        }
        header('Location: super_dashboard.php');
        exit;
    }
    
    if ($action === 'reject_event_action') {
        $actionId = (int)($_POST['action_id'] ?? 0);
        
        if ($actionId > 0) {
            $stmt = $db->prepare("UPDATE event_actions SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?");
            $stmt->execute([$user['id'], $actionId]);
            flash_add('success', 'Event action rejected');
        }
        header('Location: super_dashboard.php');
        exit;
    }
}

// Get selected month from request or use current month
$selectedMonth = $_GET['month'] ?? date('Y-m');
$currentMonth = date('Y-m');
$prevMonth = date('Y-m', strtotime($selectedMonth . ' -1 month'));
$nextMonth = date('Y-m', strtotime($selectedMonth . ' +1 month'));

// Validate month format
if (!preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
    $selectedMonth = $currentMonth;
}

// Get pending event actions
$pendingEventActions = [];
try {
    $stmt = $db->query("
        SELECT ea.*, e.name as event_name, e.date as event_date, e.client, e.location,
               u.name as requested_by_name
        FROM event_actions ea
        JOIN events e ON ea.event_id = e.id
        LEFT JOIN users u ON ea.requested_by = u.id
        WHERE ea.status = 'pending'
        ORDER BY ea.created_at DESC
    ");
    $pendingEventActions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $pendingEventActions = [];
}

// Initialize variables
$totalEvents = $upcomingEvents = $completedEvents = 0;
$sumAmount = $sumAdvance = $sumBalance = 0;
$monthlyTotal = 0;
$dailyCounts = $cumulative = [];
$labels = [];
$recent = [];
$userStats = [
    'total_users' => 0, 
    'admin_count' => 0, 
    'sales_count' => 0,
    'accountant_count' => 0,
    'operation_count' => 0,
    'supervisor_count' => 0,
    'store_count' => 0,
    'graphic_count' => 0,
    'active_users_30d' => 0
];
$eventStats = ['total_events' => 0, 'upcoming_events' => 0, 'completed_events' => 0, 'unique_clients' => 0];

// Basic error handling for database queries
try {
    // Metrics (global - not filtered by month)
    $totalEvents = (int)$db->query("SELECT COUNT(*) FROM events")->fetchColumn();
    $upcomingEvents = (int)$db->query("SELECT COUNT(*) FROM events WHERE (end_date IS NULL AND date >= CURDATE()) OR (end_date IS NOT NULL AND end_date >= CURDATE())")->fetchColumn();
    $completedEvents = (int)$db->query("SELECT COUNT(*) FROM events WHERE (end_date IS NOT NULL AND end_date < CURDATE()) OR (end_date IS NULL AND date < CURDATE()) OR status = 'COMPLETED'")->fetchColumn();

    // Financial totals (global)
    $totals = $db->query("SELECT COALESCE(SUM(amount),0) AS amt, COALESCE(SUM(advance),0) AS adv, COALESCE(SUM(balance),0) AS bal FROM events")->fetch(PDO::FETCH_ASSOC);
    $sumAmount = (float)($totals['amt'] ?? 0);
    $sumAdvance = (float)($totals['adv'] ?? 0);
    $sumBalance = (float)($totals['bal'] ?? 0);

    // Derived percentages for Financial Overview cards
    $completedPercent = $totalEvents > 0 ? round(($completedEvents / $totalEvents) * 100, 1) : 0;
    $advancePercent = $sumAmount > 0 ? round(($sumAdvance / $sumAmount) * 100, 1) : 0;
    $balancePercent = $sumAmount > 0 ? round(($sumBalance / $sumAmount) * 100, 1) : 0;

    // Daily event counts for selected month
    $year = substr($selectedMonth, 0, 4);
    $month = substr($selectedMonth, 5, 2);
    $daysInMonth = (int)date('t', strtotime($selectedMonth . '-01'));
    $labels = [];
    $counts = [];
    $cumulative = [];

    // Initialize arrays with all days of the selected month
    for ($day = 1; $day <= $daysInMonth; $day++) {
        $dayFormatted = sprintf('%02d', $day);
        $labels[] = $dayFormatted;
        $counts[$dayFormatted] = 0;
    }

    // Get daily event counts for selected month
    $stmtDays = $db->prepare("SELECT DAY(date) AS day, COUNT(*) AS cnt FROM events WHERE date IS NOT NULL AND YEAR(date) = ? AND MONTH(date) = ? GROUP BY DAY(date) ORDER BY day");
    $stmtDays->execute([$year, $month]);
    $dailyEvents = $stmtDays->fetchAll(PDO::FETCH_ASSOC);

    // Populate the counts array with actual data
    foreach ($dailyEvents as $event) {
        $dayFormatted = sprintf('%02d', (int)$event['day']);
        if (isset($counts[$dayFormatted])) {
            $counts[$dayFormatted] = (int)$event['cnt'];
        }
    }

    // Prepare data for chart - convert associative array to indexed array
    $dailyCounts = [];
    $runningTotal = 0;
    foreach ($labels as $day) {
        $dailyCounts[] = $counts[$day] ?? 0;
        $runningTotal += $counts[$day] ?? 0;
        $cumulative[] = $runningTotal;
    }

    // Get total events for selected month
    $monthlyTotal = array_sum($dailyCounts);

    // Recent activity (matches current activities schema: id, user_id, action, details, created_at, ...)
    $activityLimit = 3; // Show only the last 3 activities
    $stmtRecent = $db->prepare("
        SELECT 
            a.*, 
            u.name AS user_name, 
            u.email AS user_email,
            u.role AS user_role
        FROM activities a 
        LEFT JOIN users u ON u.id = a.user_id 
        ORDER BY a.created_at DESC 
        LIMIT ?
    ");
    $stmtRecent->execute([$activityLimit]);
    $recent = $stmtRecent->fetchAll(PDO::FETCH_ASSOC);
    
    // Get user statistics with error handling
    $userStats = [
        'total_users' => 0,
        'active_users_30d' => 0,
        'admin_count' => 0,
        'sales_count' => 0,
        'accountant_count' => 0,
        'operation_count' => 0,
        'supervisor_count' => 0,
        'store_count' => 0,
        'graphic_count' => 0,
        'inactive_users' => 0,
        'new_users_7d' => 0
    ];

    try {
        // Check if users table exists
        $tableExists = $db->query("SHOW TABLES LIKE 'users'")->rowCount() > 0;
        
        if ($tableExists) {
            // Base user statistics from users table
            $baseQuery = "
                SELECT 
                    COUNT(*) as total_users,
                    SUM(CASE WHEN role IN ('admin','super') THEN 1 ELSE 0 END) as admin_count,
                    SUM(CASE WHEN role IN ('sales','head_sales') THEN 1 ELSE 0 END) as sales_count,
                    SUM(CASE WHEN role = 'accountant' THEN 1 ELSE 0 END) as accountant_count,
                    SUM(CASE WHEN role = 'operation' THEN 1 ELSE 0 END) as operation_count,
                    SUM(CASE WHEN role = 'supervisor' THEN 1 ELSE 0 END) as supervisor_count,
                    SUM(CASE WHEN role = 'store' THEN 1 ELSE 0 END) as store_count,
                    SUM(CASE WHEN role IN ('graphic','head_graphic') THEN 1 ELSE 0 END) as graphic_count,
                    SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as new_users_7d
                FROM users
            ";
            
            $result = $db->query($baseQuery)->fetch(PDO::FETCH_ASSOC);
            if ($result) {
                $userStats = array_merge($userStats, $result);
            }

            // Derive active / inactive users from login activity (has logged in at least once)
            $activeUsers30d = 0;
            $activitiesTableExists = $db->query("SHOW TABLES LIKE 'activities'")->rowCount() > 0;
            if ($activitiesTableExists) {
                $activeQuery = "
                    SELECT COUNT(DISTINCT user_id) AS active_users_30d
                    FROM activities
                    WHERE user_id IS NOT NULL
                      AND action = 'login'
                ";
                $activeRow = $db->query($activeQuery)->fetch(PDO::FETCH_ASSOC);
                if ($activeRow && isset($activeRow['active_users_30d'])) {
                    $activeUsers30d = (int)$activeRow['active_users_30d'];
                }
            }

            // If there are users but no activity records yet, treat all as active
            if ($activeUsers30d === 0 && !empty($userStats['total_users'])) {
                $activeUsers30d = (int)$userStats['total_users'];
            }

            $userStats['active_users_30d'] = $activeUsers30d;
            if (!empty($userStats['total_users'])) {
                $userStats['inactive_users'] = max(0, (int)$userStats['total_users'] - $activeUsers30d);
            }
        }
    } catch (PDOException $e) {
        error_log("Error fetching user statistics: " . $e->getMessage());
        // Fall back to basic count if detailed query fails
        try {
            $basicCount = $db->query("SELECT COUNT(*) as total FROM users")->fetch(PDO::FETCH_ASSOC);
            if ($basicCount) {
                $userStats['total_users'] = (int)$basicCount['total'];
                $userStats['user_count'] = (int)$basicCount['total'];
            }
        } catch (Exception $e) {
            error_log("Basic user count failed: " . $e->getMessage());
        }
    }
    
    // Get event statistics
    $eventStats = $db->query("
        SELECT 
            COUNT(*) as total_events,
            SUM(CASE WHEN (end_date IS NULL AND date >= CURDATE()) OR (end_date IS NOT NULL AND end_date >= CURDATE()) THEN 1 ELSE 0 END) as upcoming_events,
            SUM(CASE WHEN (end_date IS NOT NULL AND end_date < CURDATE()) OR (end_date IS NULL AND date < CURDATE()) OR status = 'COMPLETED' THEN 1 ELSE 0 END) as completed_events,
            COUNT(DISTINCT client) as unique_clients
        FROM events
    ")->fetch(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    // Basic error handling - initialize empty data on error
    error_log("Dashboard error: " . $e->getMessage());
}

// Safe trim helper (falls back if mbstring is missing)
function safe_strimwidth($s, $start, $width, $trim = '…') {
    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($s, $start, $width, $trim, 'UTF-8');
    }
    if ($width <= 0) return '';
    $len = strlen($s);
    if ($len <= $width) return $s;
    $cut = max(0, $width - strlen($trim));
    return substr($s, 0, $cut) . $trim;
}

// Time elapsed string helper
function time_elapsed_string($datetime, $full = false) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    $diff->w = floor($diff->d / 7);
    $diff->d -= $diff->w * 7;

    $string = array(
        'y' => 'year',
        'm' => 'month',
        'w' => 'week',
        'd' => 'day',
        'h' => 'hour',
        'i' => 'minute',
        's' => 'second',
    );
    
    foreach ($string as $k => &$v) {
        if ($diff->$k) {
            $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
        } else {
            unset($string[$k]);
        }
    }

    if (!$full) $string = array_slice($string, 0, 1);
    return $string ? implode(', ', $string) . ' ago' : 'just now';
}

// Format month for display
$displayMonth = date('F Y', strtotime($selectedMonth . '-01'));

// Get activity icon
function getActivityIcon($action) {
    $icons = [
        'login' => 'fas fa-sign-in-alt',
        'logout' => 'fas fa-sign-out-alt',
        'create' => 'fas fa-plus-circle',
        'update' => 'fas fa-edit',
        'delete' => 'fas fa-trash',
        'view' => 'fas fa-eye',
        'export' => 'fas fa-download',
        'import' => 'fas fa-upload',
        'payment' => 'fas fa-credit-card',
        'approve' => 'fas fa-check-circle',
        'reject' => 'fas fa-times-circle',
        'register' => 'fas fa-user-plus',
        'status_change' => 'fas fa-sync',
        'report' => 'fas fa-chart-bar'
    ];
    return $icons[strtolower($action ?? '')] ?? 'fas fa-circle';
}

// Get activity color
function getActivityColor($action) {
    $colors = [
        'login' => 'success',
        'logout' => 'secondary',
        'create' => 'primary',
        'update' => 'warning',
        'delete' => 'danger',
        'view' => 'info',
        'export' => 'success',
        'import' => 'primary',
        'payment' => 'success',
        'approve' => 'success',
        'reject' => 'danger',
        'register' => 'primary',
        'status_change' => 'warning',
        'report' => 'info'
    ];
    return $colors[strtolower($action ?? '')] ?? 'secondary';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Super Dashboard | EventPro</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <style>
    :root {
      --primary: #3b82f6;
      --secondary: #8b5cf6;
      --success: #10b981;
      --warning: #f59e0b;
      --currency: 'Tsh ';
      --danger: #ef4444;
      --info: #06b6d4;
      --dark: #1f2937;
      --light: #f8fafc;
    }
    
    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }
    
    body {
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      background-color: #f1f5f9;
      color: #334155;
      line-height: 1.5;
      overflow-x: hidden;
    }
    
    .dashboard-container {
      display: flex;
      min-height: 100vh;
      width: 100%;
    }
    
    /* Sidebar Styles */
    .sidebar {
      width: 220px;
      background: linear-gradient(180deg, #1e293b 0%, #0f172a 100%);
      color: white;
      padding: 1rem 0.75rem;
      display: flex;
      flex-direction: column;
      position: fixed;
      height: 100vh;
      overflow-y: auto;
      z-index: 1000;
      transition: transform 0.3s ease;
    }
    
    .brand {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 0 0.5rem 1.5rem;
      border-bottom: 1px solid rgba(255,255,255,0.1);
      margin-bottom: 1.5rem;
    }
    
    .brand-title {
      font-weight: 700;
      font-size: 1.25rem;
      white-space: nowrap;
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
      white-space: nowrap;
    }
    
    .nav-item:hover, .nav-item.active {
      background-color: rgba(255,255,255,0.1);
      color: white;
    }
    
    .sidebar-footer {
      margin-top: auto;
      padding-top: 1.5rem;
      border-top: 1px solid rgba(255,255,255,0.1);
      font-size: 0.75rem;
      opacity: 0.7;
      line-height: 1.4;
    }
    
    /* Mobile menu button */
    .mobile-menu-btn {
      display: none;
      position: fixed;
      top: 1rem;
      left: 1rem;
      z-index: 1001;
      background: #3b82f6;
      color: white;
      border: none;
      border-radius: 8px;
      padding: 0.5rem;
      cursor: pointer;
    }
    
    /* Main Content */
    .main-content {
      flex: 1;
      margin-left: 220px;
      padding: 0.5rem;
      width: calc(100% - 220px);
      height: 100vh;
      overflow-y: auto;
      transition: all 0.3s ease;
    }
    
    /* Top Bar */
    .top-bar {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 0.5rem;
      padding: 0.25rem 0;
      flex-wrap: wrap;
      gap: 0.5rem;
    }
    
    .page-title h1 {
      font-size: 1.25rem;
      font-weight: 700;
      color: #1e293b;
      margin: 0;
    }
    
    .page-title p {
      color: #64748b;
      font-size: 0.8rem;
    }
    
    .user-menu {
      display: flex;
      align-items: center;
      gap: 1rem;
      flex-wrap: wrap;
    }
    
    .date-display {
      background: white;
      padding: 0.5rem 1rem;
      border-radius: 8px;
      box-shadow: 0 1px 3px rgba(0,0,0,0.1);
      font-weight: 500;
      font-size: 0.8rem;
      white-space: nowrap;
    }
    
    .user-profile {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      padding: 0.15rem 0.35rem 0.15rem 0.15rem;
      border-radius: 9999px;
      cursor: pointer;
      position: relative;
      background-color: #f8fafc;
      border: 1px solid #e2e8f0;
      font-size: 0.8rem;
    }
    
    .avatar {
      width: 1.75rem;
      height: 1.75rem;
      border-radius: 50%;
      background-color: #3b82f6;
      color: white;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 600;
      font-size: 0.75rem;
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
      z-index: 100;
    }
    
    .profile-dropdown.show {
      display: block;
    }
    
    /* Content Sections */
    .content-section {
      background: white;
      border-radius: 10px;
      padding: 0.75rem;
      margin-bottom: 1rem;
      box-shadow: 0 1px 3px rgba(0,0,0,0.1);
      width: 100%;
      overflow: hidden;
    }
    
    .section-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 1.5rem;
      flex-wrap: wrap;
      gap: 0.75rem;
      width: 100%;
    }
    
    .section-title {
      font-size: 1.1rem;
      font-weight: 600;
      color: #1e293b;
      line-height: 1.3;
    }
    
    .section-actions {
      display: grid;
      gap: 0.5rem;
      align-items: center;
      flex-wrap: wrap;
    }
    
    /* Month Navigation */
    .month-navigation {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      background: #f8fafc;
      padding: 0.75rem;
      border-radius: 12px;
      border: 1px solid #e2e8f0;
      flex-wrap: wrap;
    }
    
    .month-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 40px;
      height: 40px;
      border-radius: 8px;
      background: white;
      border: 1px solid #e2e8f0;
      color: #475569;
      text-decoration: none;
      transition: all 0.2s;
      flex-shrink: 0;
    }
    
    .month-btn:hover {
      background: #3b82f6;
      color: white;
      border-color: #3b82f6;
    }
    
    .month-display {
      font-weight: 600;
      color: #1e293b;
      min-width: 140px;
      text-align: center;
      font-size: 0.9rem;
      white-space: nowrap;
    }
    
    .month-selector {
      padding: 0.5rem;
      border: 1px solid #e2e8f0;
      border-radius: 8px;
      background: white;
      color: #475569;
      font-size: 0.8rem;
      min-width: 140px;
      max-width: 100%;
    }
    
    .quick-month-btn {
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      padding: 0.5rem 1rem;
      border-radius: 8px;
      background: white;
      border: 1px solid #e2e8f0;
      color: #475569;
      text-decoration: none;
      transition: all 0.2s;
      font-size: 0.8rem;
      white-space: nowrap;
    }
    
    .quick-month-btn:hover {
      background: #3b82f6;
      color: white;
      border-color: #3b82f6;
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
      font-size: 0.8rem;
      white-space: nowrap;
    }
    
    .btn-primary {
      background-color: #3b82f6;
      color: white;
    }
    
    .btn-secondary {
      background-color: #f1f5f9;
      color: #475569;
    }
    
    /* Stats Grid */
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 0.75rem;
      margin-bottom: 1rem;
      width: 100%;
    }
    
    .stat-card {
      background: white;
      border: 1px solid #e2e8f0;
      border-radius: 10px;
      padding: 0.75rem;
      display: flex;
      align-items: center;
      gap: 0.75rem;
      transition: all 0.2s;
      box-shadow: 0 1px 2px rgba(0,0,0,0.05);
      min-width: 0;
    }
    
    .stat-card:hover {
      box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    }
    
    .stat-icon {
      width: 42px;
      height: 42px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 10px;
      color: white;
      font-size: 1.1rem;
      flex-shrink: 0;
    }
    
    .stat-content {
      flex: 1;
      min-width: 0;
    }
    
    .stat-title {
      color: #64748b;
      font-size: 0.8rem;
      margin-bottom: 0.5rem;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    
    .stat-value {
      font-weight: 700;
      font-size: 1.25rem;
      color: #1e293b;
      line-height: 1.2;
      word-break: break-all;
    }
    
    /* Activity Styles */
    .activity-item {
      display: flex;
      align-items: flex-start;
      gap: 1rem;
      padding: 1rem;
      border-bottom: 1px solid #f1f5f9;
      width: 100%;
    }
    
    .activity-item:hover {
      background: #f8fafc;
    }
    
    .activity-icon {
      width: 40px;
      height: 40px;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      font-size: 1rem;
      flex-shrink: 0;
    }
    
    .activity-content {
      flex: 1;
      min-width: 0;
    }
    
    .activity-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      margin-bottom: 0.5rem;
      flex-wrap: wrap;
      gap: 0.5rem;
      width: 100%;
    }
    
    .activity-title {
      font-weight: 600;
      color: #1e293b;
      font-size: 0.9rem;
      flex: 1;
      min-width: 0;
    }
    
    .activity-time {
      font-size: 0.75rem;
      color: #64748b;
      white-space: nowrap;
    }
    
    .activity-details {
      display: flex;
      gap: 1rem;
      margin-bottom: 0.5rem;
      flex-wrap: wrap;
      align-items: center;
    }
    
    .activity-user {
      font-size: 0.8rem;
      color: #475569;
      display: flex;
      align-items: center;
      gap: 0.25rem;
    }
    
    .activity-description {
      font-size: 0.8rem;
      color: #64748b;
      line-height: 1.4;
      word-wrap: break-word;
    }
    
    .activity-badge {
      display: inline-flex;
      align-items: center;
      gap: 0.25rem;
      padding: 0.25rem 0.5rem;
      border-radius: 6px;
      font-size: 0.75rem;
      font-weight: 500;
      white-space: nowrap;
      flex-shrink: 0;
    }
    
    .badge-primary { background: #dbeafe; color: #1e40af; }
    .badge-success { background: #d1fae5; color: #065f46; }
    .badge-warning { background: #fef3c7; color: #92400e; }
    .badge-danger { background: #fee2e2; color: #991b1b; }
    .badge-info { background: #cffafe; color: #155e75; }
    .badge-secondary { background: #f3e8ff; color: #6b21a8; }
    
    /* Table Styles */
    .table-container {
      overflow-x: auto;
      border-radius: 8px;
      border: 1px solid #e2e8f0;
      width: 100%;
      -webkit-overflow-scrolling: touch;
    }
    
    /* Chart Container */
    .chart-container {
      width: 100%;
      height: 300px;
      position: relative;
    }
    
    /* Mobile Responsive - delegate sidebar behavior to shared sidebar.php */
    @media (max-width: 1200px) {
      .main-content {
        margin-left: 220px;
        width: calc(100% - 220px);
      }
      
      .stats-grid {
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      }
    }
    
    @media (max-width: 1024px) {
      .main-content {
        margin-left: 0;
        width: 100%;
        padding: 0.5rem;
      }
      
      .stats-grid {
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 0.5rem;
      }
    }
    
    @media (max-width: 768px) {
      .main-content {
        margin-left: 0;
        width: 100%;
        padding: 1rem;
        padding-top: 4rem;
      }
      
      .top-bar {
        flex-direction: column;
        align-items: stretch;
        gap: 0.75rem;
        padding: 0.75rem 0;
        margin-bottom: 1rem;
      }

      .page-title {
        padding-left: 3rem; /* leave space for hamburger button */
        text-align: left;
      }

      .page-title h1 {
        font-size: 1.15rem;
        line-height: 1.2;
      }

      .page-title p {
        font-size: 0.75rem;
      }

      .user-menu {
        width: 100%;
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: flex-start;
        gap: 0.5rem;
      }

      .date-display {
        flex: 1 1 auto;
        text-align: center;
        font-size: 0.8rem;
        padding: 0.5rem 0.75rem;
      }

      .user-profile {
        flex: 1 1 auto;
        justify-content: flex-start;
        padding: 0.4rem 0.75rem;
        border-radius: 9999px;
        background: #fff;
        box-shadow: 0 1px 3px rgba(0,0,0,0.08);
      }

      .profile-dropdown {
        right: auto;
        left: 0;
        width: 100%;
      }
      
      .section-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 1rem;
      }
      
      .section-actions {
        width: 100%;
        justify-content: space-between;
      }
      
      .stats-grid {
        grid-template-columns: 1fr;
        gap: 1rem;
      }
      
      .stat-card {
        padding: 1rem;
      }
      
      .month-navigation {
        width: 100%;
        justify-content: center;
      }
      
      .month-display {
        min-width: 120px;
      }
      
      .activity-header {
        flex-direction: column;
        align-items: flex-start;
      }
      
      .activity-details {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.5rem;
      }
    }
    
    @media (max-width: 640px) {
      .main-content {
        padding: 0.75rem;
        padding-top: 4rem;
      }
      
      .content-section {
        padding: 1rem;
        margin-bottom: 1rem;
      }
      
      .stats-grid {
        grid-template-columns: 1fr;
        gap: 0.75rem;
      }
      
      .stat-card {
        flex-direction: column;
        text-align: center;
        gap: 0.75rem;
      }
      
      .stat-icon {
        width: 50px;
        height: 50px;
      }
      
      .month-navigation {
        flex-direction: column;
        gap: 0.5rem;
      }
      
      .section-actions {
        flex-direction: column;
        width: 100%;
        gap: 0.5rem;
      }
      
      .activity-item {
        flex-direction: column;
        text-align: center;
        gap: 0.75rem;
      }
      
      .activity-icon {
        align-self: center;
      }
      
      .user-profile > div:last-child {
        display: none;
      }
    }
    
    @media (max-width: 480px) {
      .main-content {
        padding: 0.5rem;
        padding-top: 3.5rem;
      }
      
      .content-section {
        padding: 0.75rem;
        border-radius: 8px;
      }
      
      .stat-value {
        font-size: 1.25rem;
      }
      
      .date-display {
        padding: 0.5rem;
        font-size: 0.8rem;
      }
      
      .month-selector, .quick-month-btn {
        width: 100%;
        text-align: center;
      }
      
      .user-menu {
        flex-direction: row;
        flex-wrap: wrap;
        gap: 0.5rem;
      }

      .date-display,
      .user-profile {
        flex: 1 1 100%;
      }
    }
    
    @media (max-width: 360px) {
      .main-content {
        padding: 0.25rem;
        padding-top: 3.5rem;
      }
      
      .stat-card {
        padding: 0.75rem;
      }
      
      .stat-value {
        font-size: 1.1rem;
      }
      
      .activity-item {
        padding: 0.75rem;
      }
    }
    
    /* Print Styles */
    @media print {
      .sidebar, .mobile-menu-btn, .section-actions, .profile-dropdown {
        display: none !important;
      }
      
      .main-content {
        margin-left: 0;
        width: 100%;
        padding: 0;
      }
      
      .content-section {
        box-shadow: none;
        border: 1px solid #ccc;
        page-break-inside: avoid;
      }
    }
  </style>
 </head>
 <body>

  <div class="dashboard-container">
    <!-- Sidebar -->
    <?php include __DIR__ . '/sidebar.php'; ?>
    
    <!-- Main Content - Full Width -->
    <main class="main-content w-full max-w-full">
      <!-- Top Bar -->
      <div class="top-bar">
        <div class="page-title">
          <h1>Super Admin Dashboard</h1>
          <p>Complete system overview and analytics</p>
        </div>
        
        <div class="user-menu">
          <div class="date-display">
            <i class="far fa-calendar-alt mr-2"></i>
            <?php echo date('l, F j, Y'); ?>
          </div>
          
          <div class="user-profile" id="superUserProfile">
            <div class="avatar">
              <?php 
                $displayName = $user['name'] ?? $user['email'] ?? 'User'; 
                $initial = strtoupper(substr($displayName, 0, 1)); 
                echo htmlspecialchars($initial); 
              ?>
            </div>
            <div>
              <div style="font-weight: 600;"><?php echo htmlspecialchars($displayName); ?></div>
              <div style="font-size: 0.75rem; color: #64748b;"><?php echo get_role_label($user['role'] ?? 'user'); ?></div>
            </div>
            <i class="fas fa-chevron-down ml-2" style="color: #64748b;"></i>
            
            <div class="profile-dropdown" id="superHeaderProfileDropdown">
              <a href="profile.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"><i class="fas fa-user mr-2"></i> My Profile</a>
              <a href="settings.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"><i class="fas fa-cog mr-2"></i> Settings</a>
              <a href="?logout" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"><i class="fas fa-sign-out-alt mr-2"></i> Logout</a>
            </div>
          </div>
        </div>
      </div>

      <?php if (count($pendingEventActions) > 0): ?>
      <!-- Pending Event Actions Alert -->
      <div class="content-section" style="background: linear-gradient(135deg, #ffffff 0%, #f9fafb 100%); border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.07); border: 1px solid #e5e7eb; margin-bottom: 1.5rem;">
        <div class="section-header" style="border-bottom: 2px solid #d1d5db; padding-bottom: 1rem; margin-bottom: 1.5rem;">
          <h3 class="section-title" style="color: #374151; font-size: 1.125rem;">
            <i class="fas fa-exclamation-triangle" style="color: #f59e0b;"></i> Pending Event Actions - Approval Required
            <span style="background: #fef3c7; color: #92400e; padding: 0.25rem 0.75rem; border-radius: 9999px; font-size: 0.875rem; font-weight: 600; margin-left: 0.5rem;">
              <?php echo count($pendingEventActions); ?>
            </span>
          </h3>
        </div>
        
        <div class="overflow-x-auto">
          <table class="w-full">
            <thead>
              <tr style="background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%); border-bottom: 2px solid #3b82f6;">
                <th class="text-left py-3 px-4 font-semibold" style="color: #1e40af; font-size: 0.875rem;">#</th>
                <th class="text-left py-3 px-4 font-semibold" style="color: #1e40af; font-size: 0.875rem;">Action</th>
                <th class="text-left py-3 px-4 font-semibold" style="color: #1e40af; font-size: 0.875rem;">Event</th>
                <th class="text-left py-3 px-4 font-semibold" style="color: #1e40af; font-size: 0.875rem;">Client</th>
                <th class="text-left py-3 px-4 font-semibold" style="color: #1e40af; font-size: 0.875rem;">Date</th>
                <th class="text-left py-3 px-4 font-semibold" style="color: #1e40af; font-size: 0.875rem;">Requested By</th>
                <th class="text-left py-3 px-4 font-semibold" style="color: #1e40af; font-size: 0.875rem;">Comment</th>
                <th class="text-left py-3 px-4 font-semibold" style="color: #1e40af; font-size: 0.875rem;">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($pendingEventActions as $index => $ea): ?>
                <tr class="border-b border-gray-100 transition-all" style="cursor: pointer;" onmouseover="this.style.background='#f0f9ff'" onmouseout="this.style.background='white'">
                  <td class="py-3 px-4 text-gray-500 text-sm font-medium"><?php echo $index + 1; ?></td>
                  <td class="py-3 px-4">
                    <?php if ($ea['action_type'] === 'cancel'): ?>
                      <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-red-100 text-red-700 border border-red-300">
                        <i class="fas fa-times-circle mr-1"></i> Cancel
                      </span>
                    <?php else: ?>
                      <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-yellow-100 text-yellow-700 border border-yellow-300">
                        <i class="fas fa-clock mr-1"></i> Postpone
                      </span>
                    <?php endif; ?>
                  </td>
                  <td class="py-3 px-4 font-semibold" style="color: #1f2937;">
                    <?php echo htmlspecialchars($ea['event_name']); ?>
                  </td>
                  <td class="py-3 px-4" style="color: #4b5563;">
                    <?php echo htmlspecialchars($ea['client'] ?? '-'); ?>
                  </td>
                  <td class="py-3 px-4" style="color: #4b5563;">
                    <?php echo $ea['event_date'] ? date('M j, Y', strtotime($ea['event_date'])) : '-'; ?>
                  </td>
                  <td class="py-3 px-4" style="color: #4b5563;">
                    <?php echo htmlspecialchars($ea['requested_by_name'] ?? '-'); ?>
                  </td>
                  <td class="py-3 px-4" style="color: #4b5563; max-width: 200px;">
                    <div style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo htmlspecialchars($ea['comment'] ?? ''); ?>">
                      <?php echo htmlspecialchars($ea['comment'] ?? 'No comment'); ?>
                    </div>
                  </td>
                  <td class="py-3 px-4">
                    <div class="flex gap-2">
                      <form method="POST" class="inline">
                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                        <input type="hidden" name="action" value="approve_event_action">
                        <input type="hidden" name="action_id" value="<?php echo $ea['id']; ?>">
                        <input type="hidden" name="event_id" value="<?php echo $ea['event_id']; ?>">
                        <input type="hidden" name="action_type" value="<?php echo $ea['action_type']; ?>">
                        <button type="submit" class="text-xs px-3 py-1 rounded bg-green-100 text-green-700 hover:bg-green-200 font-medium" onclick="return confirm('Approve this <?php echo $ea['action_type']; ?> request?')">
                          <i class="fas fa-check"></i> Approve
                        </button>
                      </form>
                      <form method="POST" class="inline">
                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                        <input type="hidden" name="action" value="reject_event_action">
                        <input type="hidden" name="action_id" value="<?php echo $ea['id']; ?>">
                        <button type="submit" class="text-xs px-3 py-1 rounded bg-red-100 text-red-700 hover:bg-red-200 font-medium" onclick="return confirm('Reject this request?')">
                          <i class="fas fa-times"></i> Reject
                        </button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>
      
      <!-- Stats Grid -->
      <div class="stats-grid">
        <!-- Total Events -->
        <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-2xl p-5 text-white shadow-lg hover:shadow-xl transition-shadow duration-300">
          <div class="flex justify-between items-start">
            <div>
              <p class="text-sm font-medium text-blue-100">Total Events</p>
              <h3 class="text-2xl font-bold mt-1"><?php echo number_format($totalEvents); ?></h3>
              <div class="flex items-center mt-2">
                <span class="text-xs bg-white bg-opacity-20 px-2 py-1 rounded-full">
                  <?php echo $upcomingEvents; ?> upcoming
                </span>
              </div>
            </div>
            <div class="p-3 bg-white bg-opacity-20 rounded-xl">
              <i class="fas fa-calendar-alt text-xl"></i>
            </div>
          </div>
          <div class="mt-4 pt-3 border-t border-blue-400 border-opacity-30">
            <a href="events.php" class="text-xs font-medium text-white hover:underline flex items-center">
              View all events <i class="fas fa-arrow-right ml-1 text-xs"></i>
            </a>
          </div>
        </div>

        <!-- Upcoming Events -->
        <div class="bg-gradient-to-br from-purple-500 to-purple-600 rounded-2xl p-5 text-white shadow-lg hover:shadow-xl transition-shadow duration-300">
          <div class="flex justify-between items-start">
            <div>
              <p class="text-sm font-medium text-purple-100">Upcoming Events</p>
              <h3 class="text-2xl font-bold mt-1"><?php echo number_format($upcomingEvents); ?></h3>
              <div class="mt-2">
                <span class="text-xs bg-white bg-opacity-20 px-2 py-1 rounded-full">
                  <?php echo $completedEvents; ?> completed
                </span>
              </div>
            </div>
            <div class="p-3 bg-white bg-opacity-20 rounded-xl">
              <i class="fas fa-calendar-plus text-xl"></i>
            </div>
          </div>
          <div class="mt-4 pt-3 border-t border-purple-400 border-opacity-30">
            <a href="events.php?filter=upcoming" class="text-xs font-medium text-white hover:underline flex items-center">
              View upcoming <i class="fas fa-arrow-right ml-1 text-xs"></i>
            </a>
          </div>
        </div>

        <!-- Completed Events -->
        <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-2xl p-5 text-white shadow-lg hover:shadow-xl transition-shadow duration-300">
          <div class="flex justify-between items-start">
            <div>
              <p class="text-sm font-medium text-green-100">Completed Events</p>
              <h3 class="text-2xl font-bold mt-1"><?php echo number_format($completedEvents); ?></h3>
              <div class="mt-2">
                <span class="text-xs bg-white bg-opacity-20 px-2 py-1 rounded-full">
                  <?php echo $monthlyTotal; ?> this month
                </span>
              </div>
            </div>
            <div class="p-3 bg-white bg-opacity-20 rounded-xl">
              <i class="fas fa-check-circle text-xl"></i>
            </div>
          </div>
          <div class="mt-4 pt-3 border-t border-green-400 border-opacity-30">
            <a href="events.php?filter=completed" class="text-xs font-medium text-white hover:underline flex items-center">
              View completed <i class="fas fa-arrow-right ml-1 text-xs"></i>
            </a>
          </div>
        </div>

        <!-- Monthly Events -->
        <div class="bg-gradient-to-br from-amber-500 to-amber-600 rounded-2xl p-5 text-white shadow-lg hover:shadow-xl transition-shadow duration-300">
          <div class="flex justify-between items-start">
            <div>
              <p class="text-sm font-medium text-amber-100">This Month's Events</p>
              <h3 class="text-2xl font-bold mt-1"><?php echo number_format($monthlyTotal); ?></h3>
              <div class="mt-2">
                <span class="text-xs bg-white bg-opacity-20 px-2 py-1 rounded-full">
                  <?php echo date('F Y', strtotime($selectedMonth . '-01')); ?>
                </span>
              </div>
            </div>
            <div class="p-3 bg-white bg-opacity-20 rounded-xl">
              <i class="fas fa-chart-line text-xl"></i>
            </div>
          </div>
          <div class="mt-4 pt-3 border-t border-amber-400 border-opacity-30">
            <a href="reports.php?month=<?php echo $selectedMonth; ?>" class="text-xs font-medium text-white hover:underline flex items-center">
              View report <i class="fas fa-arrow-right ml-1 text-xs"></i>
            </a>
          </div>
        </div>
      </div>

      <!-- Main Content Grid -->
      <!-- Main Dashboard Grid -->
      <div class="grid grid-cols-1 xl:grid-cols-12 gap-6">
        <!-- Left Column - Main Content -->
        <div class="xl:col-span-9 space-y-6">
          <!-- Events Chart -->
          <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="p-5 border-b border-gray-100">
              <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
                <div>
                  <h3 class="text-lg font-semibold text-gray-800">Events Trend - <?php echo $displayMonth; ?></h3>
                  <p class="text-sm text-gray-500">Daily and cumulative events overview</p>
                </div>
                <div class="mt-3 sm:mt-0 flex items-center space-x-2">
                  <div class="relative">
                    <select class="appearance-none bg-white border border-gray-200 rounded-lg pl-3 pr-8 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500" onchange="location = '?month=' + this.value;">
                      <?php
                      // Generate month options for the last 12 months
                      for ($i = 0; $i < 12; $i++) {
                          $monthValue = date('Y-m', strtotime("-$i months"));
                          $monthDisplay = date('F Y', strtotime($monthValue . '-01'));
                          $selected = ($monthValue === $selectedMonth) ? 'selected' : '';
                          echo "<option value=\"$monthValue\" $selected>$monthDisplay</option>";
                      }
                      ?>
                    </select>
                    <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-gray-700">
                      <i class="fas fa-chevron-down text-xs"></i>
                    </div>
                  </div>
                  <div class="flex border border-gray-200 rounded-lg overflow-hidden">
                    <a href="?month=<?php echo $prevMonth; ?>" class="px-3 py-2 bg-white hover:bg-gray-50 text-gray-700" title="Previous Month">
                      <i class="fas fa-chevron-left text-xs"></i>
                    </a>
                    <a href="?month=<?php echo $currentMonth; ?>" class="px-3 py-2 bg-white hover:bg-gray-50 text-gray-700 border-l border-r border-gray-200" title="Current Month">
                      <i class="far fa-calendar-alt text-xs"></i>
                    </a>
                    <a href="?month=<?php echo $nextMonth; ?>" class="px-3 py-2 bg-white hover:bg-gray-50 text-gray-700" title="Next Month">
                      <i class="fas fa-chevron-right text-xs"></i>
                    </a>
                  </div>
                </div>
              </div>
            </div>
            <div class="p-5 pt-0">
              <div class="h-80">
                <canvas id="eventsChart"></canvas>
              </div>
            </div>
            <div class="px-5 py-3 bg-gray-50 border-t border-gray-100 flex flex-col sm:flex-row justify-between items-center">
              <p class="text-sm text-gray-500 mb-2 sm:mb-0">
                <span class="font-medium text-gray-700"><?php echo $monthlyTotal; ?></span> events in <?php echo $displayMonth; ?>
              </p>
              <div class="flex space-x-3">
                <span class="flex items-center text-xs text-gray-500">
                  <span class="w-2 h-2 rounded-full bg-blue-500 mr-1.5"></span>
                  <span>Daily Events</span>
                </span>
              </div>
            </div>
          </div>
          
          <!-- Financial Overview -->
          <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
            <!-- Total Revenue -->
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 hover:shadow-md transition-shadow duration-200">
              <div class="flex items-center justify-between mb-3">
                <div class="p-2.5 bg-blue-50 rounded-lg">
                  <i class="fas fa-sack-dollar text-blue-500 text-xl"></i>
                </div>
                <div class="text-right">
                  <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                    <i class="fas fa-arrow-up mr-1 text-xs"></i> <?php echo $sumAmount > 0 ? 100 : 0; ?>%
                  </span>
                </div>
              </div>
              <h4 class="text-sm font-medium text-gray-500 mb-1">Total Revenue</h4>
              <div class="flex items-end justify-between">
                <p class="text-2xl font-bold text-gray-900">Tsh <?php echo number_format($sumAmount, 0, '.', ','); ?></p>
                <a href="reports.php?type=revenue" class="text-blue-600 hover:text-blue-500 text-sm font-medium">
                  View <i class="fas fa-arrow-right ml-0.5 text-xs"></i>
                </a>
              </div>
              <div class="mt-4 pt-3 border-t border-gray-100">
                <div class="flex items-center justify-between text-xs text-gray-500">
                  <span>Monthly Avg</span>
                  <span>Tsh <?php echo number_format($sumAmount / 12, 0, '.', ','); ?></span>
                </div>
              </div>
            </div>
            
            <!-- Total Advance -->
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 hover:shadow-md transition-shadow duration-200">
              <div class="flex items-center justify-between mb-3">
                <div class="p-2.5 bg-green-50 rounded-lg">
                  <i class="fas fa-money-bill-wave text-green-500 text-xl"></i>
                </div>
                <div class="text-right">
                  <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                    <i class="fas fa-arrow-up mr-1 text-xs"></i> <?php echo $advancePercent; ?>%
                  </span>
                </div>
              </div>
              <h4 class="text-sm font-medium text-gray-500 mb-1">Total Advance</h4>
              <div class="flex items-end justify-between">
                <p class="text-2xl font-bold text-gray-900">Tsh <?php echo number_format($sumAdvance, 0, '.', ','); ?></p>
                <a href="reports.php?type=advance" class="text-blue-600 hover:text-blue-500 text-sm font-medium">
                  View <i class="fas fa-arrow-right ml-0.5 text-xs"></i>
                </a>
              </div>
              <div class="mt-4 pt-3 border-t border-gray-100">
                <div class="flex items-center justify-between text-xs text-gray-500">
                  <span>Paid to date</span>
                  <span class="font-medium text-green-600"><?php echo $sumAmount > 0 ? round(($sumAdvance / $sumAmount) * 100) : 0; ?>% of total</span>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-1.5 mt-1">
                  <div class="bg-green-500 h-1.5 rounded-full" style="width: <?php echo $sumAmount > 0 ? ($sumAdvance / $sumAmount) * 100 : 0; ?>%"></div>
                </div>
              </div>
            </div>
            
            <!-- Total Balance -->
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 hover:shadow-md transition-shadow duration-200">
              <div class="flex items-center justify-between mb-3">
                <div class="p-2.5 bg-amber-50 rounded-lg">
                  <i class="fas fa-scale-balanced text-amber-500 text-xl"></i>
                </div>
                <div class="text-right">
                  <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                    <i class="fas fa-arrow-down mr-1 text-xs"></i> <?php echo $balancePercent; ?>%
                  </span>
                </div>
              </div>
              <h4 class="text-sm font-medium text-gray-500 mb-1">Pending Balance</h4>
              <div class="flex items-end justify-between">
                <p class="text-2xl font-bold text-gray-900">Tsh <?php echo number_format($sumBalance, 0, '.', ','); ?></p>
                <a href="reports.php?type=balance" class="text-blue-600 hover:text-blue-500 text-sm font-medium">
                  View <i class="fas fa-arrow-right ml-0.5 text-xs"></i>
                </a>
              </div>
              <div class="mt-4 pt-3 border-t border-gray-100">
                <div class="flex items-center justify-between text-xs text-gray-500">
                  <span>Outstanding</span>
                  <span class="font-medium text-amber-600"><?php echo $sumAmount > 0 ? round(($sumBalance / $sumAmount) * 100) : 0; ?>% of total</span>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-1.5 mt-1">
                  <div class="bg-amber-500 h-1.5 rounded-full" style="width: <?php echo $sumAmount > 0 ? ($sumBalance / $sumAmount) * 100 : 0; ?>%"></div>
                </div>
              </div>
            </div>
          </div>
        </div>
        
        <!-- Right Column - Sidebar -->
        <div class="space-y-6 xl:col-span-3">
          <!-- Quick Actions -->
          <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">Quick Actions</h3>
            <div class="grid grid-cols-2 gap-3">
              <a href="events.php?mode=form" class="p-3 bg-blue-50 hover:bg-blue-100 rounded-xl text-center transition-colors">
                <div class="w-10 h-10 bg-blue-100 text-blue-600 rounded-lg flex items-center justify-center mx-auto mb-2">
                  <i class="fas fa-plus"></i>
                </div>
                <span class="text-sm font-medium text-gray-700">New Event</span>
              </a>
              <a href="users.php?action=add" class="p-3 bg-green-50 hover:bg-green-100 rounded-xl text-center transition-colors">
                <div class="w-10 h-10 bg-green-100 text-green-600 rounded-lg flex items-center justify-center mx-auto mb-2">
                  <i class="fas fa-user-plus"></i>
                </div>
                <span class="text-sm font-medium text-gray-700">Add User</span>
              </a>
              <a href="reports.php" class="p-3 bg-purple-50 hover:bg-purple-100 rounded-xl text-center transition-colors">
                <div class="w-10 h-10 bg-purple-100 text-purple-600 rounded-lg flex items-center justify-center mx-auto mb-2">
                  <i class="fas fa-chart-bar"></i>
                </div>
                <span class="text-sm font-medium text-gray-700">Reports</span>
              </a>
              <a href="settings.php" class="p-3 bg-amber-50 hover:bg-amber-100 rounded-xl text-center transition-colors">
                <div class="w-10 h-10 bg-amber-100 text-amber-600 rounded-lg flex items-center justify-center mx-auto mb-2">
                  <i class="fas fa-cog"></i>
                </div>
                <span class="text-sm font-medium text-gray-700">Settings</span>
              </a>
            </div>
          </div>
          
          <!-- User Stats -->
          <div class="bg-white rounded-2xl shadow-sm border border-gray-100">
            <div class="p-5 border-b border-gray-100">
              <div class="flex justify-between items-center">
                <h3 class="text-lg font-semibold text-gray-800">User Statistics</h3>
                <span class="text-xs text-gray-500">Updated: <?php echo date('g:i A'); ?></span>
              </div>
            </div>
            <div class="p-5 pt-0">
              <div class="flex items-center justify-between mb-6">
                <div class="flex items-center">
                  <div class="p-2.5 bg-blue-50 rounded-lg mr-3">
                    <i class="fas fa-users text-blue-500"></i>
                  </div>
                  <div>
                    <p class="text-sm text-gray-500">Total Users</p>
                    <h4 class="text-2xl font-bold text-gray-900"><?php echo number_format($userStats['total_users']); ?></h4>
                  </div>
                </div>
                <?php if ($userStats['new_users_7d'] > 0): ?>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                  +<?php echo $userStats['new_users_7d']; ?> this week
                </span>
                <?php endif; ?>
              </div>
              
              <div class="grid grid-cols-2 gap-2 mb-4">
                <div class="p-2 bg-green-50 rounded-lg">
                  <div class="flex items-center">
                    <div class="p-1 bg-green-100 rounded mr-2">
                      <i class="fas fa-user-shield text-green-500 text-xs"></i>
                    </div>
                    <div>
                      <p class="text-xs text-gray-500">Admins</p>
                      <p class="text-sm font-semibold text-gray-900"><?php echo number_format($userStats['admin_count']); ?></p>
                    </div>
                  </div>
                </div>
                
                <div class="p-2 bg-blue-50 rounded-lg">
                  <div class="flex items-center">
                    <div class="p-1 bg-blue-100 rounded mr-2">
                      <i class="fas fa-user-tie text-blue-500 text-xs"></i>
                    </div>
                    <div>
                      <p class="text-xs text-gray-500">Sales</p>
                      <p class="text-sm font-semibold text-gray-900"><?php echo number_format($userStats['sales_count']); ?></p>
                    </div>
                  </div>
                </div>
                
                <div class="p-2 bg-indigo-50 rounded-lg">
                  <div class="flex items-center">
                    <div class="p-1 bg-indigo-100 rounded mr-2">
                      <i class="fas fa-shopping-cart text-indigo-500 text-xs"></i>
                    </div>
                    <div>
                      <p class="text-xs text-gray-500">Sales</p>
                      <p class="text-sm font-semibold text-gray-900"><?php echo number_format($userStats['sales_count']); ?></p>
                    </div>
                  </div>
                </div>
                
                <div class="p-2 bg-yellow-50 rounded-lg">
                  <div class="flex items-center">
                    <div class="p-1 bg-yellow-100 rounded mr-2">
                      <i class="fas fa-calculator text-yellow-600 text-xs"></i>
                    </div>
                    <div>
                      <p class="text-xs text-gray-500">Accountant</p>
                      <p class="text-sm font-semibold text-gray-900"><?php echo number_format($userStats['accountant_count']); ?></p>
                    </div>
                  </div>
                </div>
                
                <div class="p-2 bg-orange-50 rounded-lg">
                  <div class="flex items-center">
                    <div class="p-1 bg-orange-100 rounded mr-2">
                      <i class="fas fa-cogs text-orange-500 text-xs"></i>
                    </div>
                    <div>
                      <p class="text-xs text-gray-500">Operation</p>
                      <p class="text-sm font-semibold text-gray-900"><?php echo number_format($userStats['operation_count']); ?></p>
                    </div>
                  </div>
                </div>
                
                <div class="p-2 bg-purple-50 rounded-lg">
                  <div class="flex items-center">
                    <div class="p-1 bg-purple-100 rounded mr-2">
                      <i class="fas fa-clipboard-check text-purple-500 text-xs"></i>
                    </div>
                    <div>
                      <p class="text-xs text-gray-500">Supervisor</p>
                      <p class="text-sm font-semibold text-gray-900"><?php echo number_format($userStats['supervisor_count']); ?></p>
                    </div>
                  </div>
                </div>
                
                <div class="p-2 bg-teal-50 rounded-lg">
                  <div class="flex items-center">
                    <div class="p-1 bg-teal-100 rounded mr-2">
                      <i class="fas fa-store text-teal-500 text-xs"></i>
                    </div>
                    <div>
                      <p class="text-xs text-gray-500">Store</p>
                      <p class="text-sm font-semibold text-gray-900"><?php echo number_format($userStats['store_count']); ?></p>
                    </div>
                  </div>
                </div>
                
                <div class="p-2 bg-rose-50 rounded-lg">
                  <div class="flex items-center">
                    <div class="p-1 bg-rose-100 rounded mr-2">
                      <i class="fas fa-crown text-rose-500 text-xs"></i>
                    </div>
                    <div>
                      <p class="text-xs text-gray-500">Graphic</p>
                      <p class="text-sm font-semibold text-gray-900"><?php echo number_format($userStats['graphic_count']); ?></p>
                    </div>
                  </div>
                </div>
                
                <div class="p-2 bg-pink-50 rounded-lg">
                  <div class="flex items-center">
                    <div class="p-1 bg-pink-100 rounded mr-2">
                      <i class="fas fa-paint-brush text-pink-500 text-xs"></i>
                    </div>
                    <div>
                      <p class="text-xs text-gray-500">Graphic</p>
                      <p class="text-sm font-semibold text-gray-900"><?php echo number_format($userStats['graphic_count']); ?></p>
                    </div>
                  </div>
                </div>
              </div>
              
              <div class="space-y-2 mb-4">
                <div class="flex justify-between text-xs text-gray-600">
                  <span>Active (30d)</span>
                  <span class="font-medium"><?php echo number_format($userStats['active_users_30d']); ?> users</span>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-2">
                  <div class="bg-blue-500 h-2 rounded-full" style="width: <?php echo $userStats['total_users'] > 0 ? ($userStats['active_users_30d'] / $userStats['total_users']) * 100 : 0; ?>%"></div>
                </div>
                <div class="flex justify-between text-xs text-gray-500">
                  <span>Active rate: <?php echo $userStats['total_users'] > 0 ? round(($userStats['active_users_30d'] / $userStats['total_users']) * 100) : 0; ?>%</span>
                  <span>Inactive: <?php echo number_format($userStats['inactive_users']); ?></span>
                </div>
              </div>
              
              <a href="users.php" class="block w-full text-center px-4 py-2.5 border border-gray-200 rounded-xl text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors">
                Manage All Users
              </a>
            </div>
          </div>
        </div>
      </div>
    </main>
  </div>

  <!-- Format currency values on page load -->
  <script>
    // Format all elements with data-currency attribute
    document.addEventListener('DOMContentLoaded', function() {
      document.querySelectorAll('[data-currency]').forEach(function(element) {
        const value = parseFloat(element.getAttribute('data-currency')) || 0;
        element.textContent = 'Tsh ' + value.toLocaleString('en-US', {
          minimumFractionDigits: 0,
          maximumFractionDigits: 0
        });
      });
    });
    // Mobile menu functionality
    const mobileMenuBtn = document.getElementById('mobileMenuBtn');
    const sidebar = document.querySelector('.sidebar');
    
    if (mobileMenuBtn && sidebar) {
      mobileMenuBtn.addEventListener('click', function() {
        sidebar.classList.toggle('mobile-open');
      });
    }

    // Chart initialization
    const labels = <?php echo json_encode($labels); ?>;
    const daily = <?php echo json_encode($dailyCounts); ?>;

    const ctx = document.getElementById('eventsChart');
    if (ctx && labels.length > 0) {
      try {
        new Chart(ctx, {
          type: 'line',
          data: {
            labels: labels,
            datasets: [
              {
                label: 'Events',
                data: daily,
                borderColor: '#3b82f6',
                borderWidth: 2,
                backgroundColor: 'rgba(59,130,246,0.1)',
                fill: true,
                tension: 0.3,
                pointBackgroundColor: '#3b82f6',
                pointBorderColor: '#fff',
                pointHoverRadius: 5,
                pointHoverBackgroundColor: '#3b82f6',
                pointHoverBorderColor: '#fff',
                pointHitRadius: 10,
                pointBorderWidth: 2,
                pointRadius: 4
              }
            ]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
              intersect: false,
              mode: 'index',
            },
            plugins: {
              legend: {
                display: true,
                position: 'top',
                labels: {
                  boxWidth: 12,
                  padding: 20,
                  usePointStyle: true,
                  pointStyle: 'circle'
                }
              },
              tooltip: {
                backgroundColor: 'rgba(0, 0, 0, 0.8)',
                titleFont: { size: 13, weight: 'bold' },
                bodyFont: { size: 13 },
                padding: 12,
                usePointStyle: true,
                callbacks: {
                  label: function(context) {
                    let label = context.dataset.label || '';
                    if (label) {
                      label += ': ';
                    }
                    if (context.parsed.y !== null) {
                      label += context.parsed.y + ' event' + (context.parsed.y !== 1 ? 's' : '');
                    }
                    return label;
                  }
                }
              }
            },
            scales: {
              x: {
                grid: {
                  display: false,
                  drawBorder: false
                },
                ticks: {
                  maxRotation: 45,
                  minRotation: 45
                }
              },
              y: { 
                beginAtZero: true,
                grid: {
                  drawBorder: false,
                  color: 'rgba(0, 0, 0, 0.05)'
                },
                ticks: {
                  precision: 0,
                  callback: function(value) {
                    if (value % 1 === 0) {
                      return value;
                    }
                  }
                }
              }
            },
            elements: {
              line: {
                borderWidth: 2,
                fill: 'start'
              },
              point: {
                radius: 0,
                hoverRadius: 6
              }
            }
          }
        });
      } catch (e) {
        console.error('Chart error:', e);
        // Show error message
        ctx.parentElement.innerHTML = '<div class="text-center py-8 text-gray-500">Unable to load chart data</div>';
      }
    }

    // Close mobile menu when clicking on a link
    document.querySelectorAll('.nav-item').forEach(item => {
      item.addEventListener('click', function() {
        if (window.innerWidth <= 768) {
          sidebar.classList.remove('mobile-open');
        }
      });
    });

    // Handle window resize
    window.addEventListener('resize', function() {
      if (window.innerWidth > 768) {
        sidebar.classList.remove('mobile-open');
      }
    });

    // Auto-refresh dashboard every 5 minutes
    setInterval(() => {
      // Only refresh if user is active
      if (!document.hidden) {
        window.location.reload();
      }
    }, 300000); // 5 minutes
  </script>

  <script>
    // Super Admin header user dropdown (top-right) - profile + logout
    document.addEventListener('DOMContentLoaded', function () {
      if (window.__profileDropdownBound) return;
      var profile  = document.getElementById('superUserProfile');
      var dropdown = document.getElementById('superHeaderProfileDropdown');

      if (!profile || !dropdown) return;

      function closeProfileDropdown() {
        dropdown.classList.remove('show');
      }

      profile.addEventListener('click', function (e) {
        e.stopPropagation();
        dropdown.classList.toggle('show');
      });

      // Close when clicking anywhere else
      document.addEventListener('click', function (event) {
        if (!profile.contains(event.target) && !dropdown.contains(event.target)) {
          closeProfileDropdown();
        }
      });

      // Close on Escape key
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
          closeProfileDropdown();
        }
      });
    });
  </script>
</body>
</html>