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
// Determine if current user has at least admin role
$isAdmin = has_role('admin');

$role = strtolower((string)($user['role'] ?? 'user'));
$roleNorm = strtolower(trim((string)($user['role'] ?? '')));
$roleNorm = str_replace(['_', '-'], ' ', $roleNorm);
$roleNorm = preg_replace('/\s+/', ' ', $roleNorm);
$pageTitle = ($role === 'sales') ? 'Sales Dashboard' : 'Admin Dashboard';

if (in_array($roleNorm, ['graphic', 'graphics', 'graphic designer'], true)) {
    header('Location: graphic_dashboard.php');
    exit;
}

if ($roleNorm === 'admin') {
    header('Location: admin_dashboard.php');
    exit;
}

if ($role === 'finance') {
    header('Location: finance_dashboard.php');
    exit;
}
if ($role === 'accountant') {
    header('Location: account_dashboard.php');
    exit;
}
if ($role === 'store') {
    header('Location: store_dashboard.php');
    exit;
}
if ($role === 'production') {
    header('Location: production_dashboard.php');
    exit;
}
if ($role === 'operation') {
    header('Location: operation_dashboard.php');
    exit;
}
if ($role === 'supervisor') {
    header('Location: supervisor_dashboard.php');
    exit;
}

$quoteFilter = strtolower((string)($_GET['quote_status'] ?? 'all'));
$allowedQuoteFilters = ['all', 'pending', 'approved', 'rejected'];
if (!in_array($quoteFilter, $allowedQuoteFilters, true)) {
    $quoteFilter = 'all';
}

$quoteStatusLink = 'quote_status.php';
if ($quoteFilter !== 'all') {
    $quoteStatusLink .= '?quote_status=' . urlencode($quoteFilter);
}

// Debug: Log user information
error_log('User Info: ' . print_r($user, true));

// Debug: Check database connection
if (!$db) {
    error_log('Database connection failed');
} else {
    error_log('Database connection successful');
}

// Log user role for debugging
error_log('User role in index.php: ' . ($user['role'] ?? 'user'));

// Debug: Log user info
error_log('User Info: ' . print_r($user, true));

// Only redirect super users to super dashboard
if (($user['role'] ?? 'user') === 'super') {
    header('Location: super_dashboard.php');
    exit;
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

// Initialize variables
$totalEvents = $upcomingEvents = $completedEvents = $cancelledEvents = 0;
$sumAmount = $sumAdvance = $sumBalance = 0;
$monthlyTotal = 0;
$dailyCounts = $cumulative = [];
$labels = [];
$recentEvents = [];
$nextEvent = null;
$recentActivities = [];

$flashes = flash_consume();

$quoteEvents = [];
$quotePendingCount = 0;
$quoteApprovedCount = 0;
$quoteRejectedCount = 0;

// Basic error handling for database queries
try {
    // Debug: Check if user ID is set
    if (!isset($user['id'])) {
        throw new Exception('User ID is not set in session');
    }
    
    // Debug: Check database connection
    if (!$db) {
        throw new Exception('Database connection failed');
    }
    
    // Debug: Check total events count without filtering
    $debugAllStmt = $db->query("SELECT COUNT(*) as total_count FROM events");
    $debugAllResult = $debugAllStmt->fetch(PDO::FETCH_ASSOC);
    error_log("Debug - Total events in database: " . ($debugAllResult['total_count'] ?? '0'));
    
    // Debug: Check user's events count
    $debugUserStmt = $db->prepare("SELECT COUNT(*) as user_count FROM events WHERE user_id = ?");
    $debugUserStmt->execute([$user['id']]);
    $debugUserResult = $debugUserStmt->fetch(PDO::FETCH_ASSOC);
    error_log("Debug - User ID: " . $user['id'] . ", User's Event Count: " . ($debugUserResult['user_count'] ?? '0'));
    // Event statistics - GLOBAL (like super dashboard)
    $statsStmt = $db->prepare("\n        SELECT \n            COUNT(*) as total_events,\n            SUM(CASE WHEN (end_date IS NULL AND date >= CURDATE()) OR (end_date IS NOT NULL AND end_date >= CURDATE()) THEN 1 ELSE 0 END) as upcoming_events,\n            SUM(CASE WHEN (end_date IS NOT NULL AND end_date < CURDATE()) OR (end_date IS NULL AND date < CURDATE()) OR status = 'COMPLETED' THEN 1 ELSE 0 END) as completed_events,\n            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_events,\n            COALESCE(SUM(amount), 0) as total_amount,\n            COALESCE(SUM(advance), 0) as total_advance,\n            COALESCE(SUM(amount - advance), 0) as total_balance\n        FROM events\n    ");
    $statsStmt->execute();
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
    
    // Debug: Check if stats query returned results
    if ($stats === false) {
        throw new Exception('Failed to fetch statistics from database');
    }

    $totalEvents = (int)($stats['total_events'] ?? 0);
    $upcomingEvents = (int)($stats['upcoming_events'] ?? 0);
    $completedEvents = (int)($stats['completed_events'] ?? 0);
    $cancelledEvents = (int)($stats['cancelled_events'] ?? 0);
    $sumAmount = (float)($stats['total_amount'] ?? 0);
    $sumAdvance = (float)($stats['total_advance'] ?? 0);
    $sumBalance = (float)($stats['total_balance'] ?? 0);

    error_log('Index stats: role=' . ($user['role'] ?? 'unknown') .
        ' total=' . $totalEvents .
        ' upcoming=' . $upcomingEvents .
        ' completed=' . $completedEvents .
        ' cancelled=' . $cancelledEvents .
        ' amount=' . $sumAmount .
        ' advance=' . $sumAdvance .
        ' balance=' . $sumBalance
    );

    // Get daily event counts for selected month
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

    // Daily counts - GLOBAL (like super dashboard)
    $dailyWhere = 'YEAR(date) = ? AND MONTH(date) = ?';
    $dailyParams = [$year, $month];

    $dailyStmt = $db->prepare("
        SELECT 
            DAY(date) AS day, 
            COUNT(*) AS count 
        FROM events 
        WHERE " . $dailyWhere . "
        GROUP BY DAY(date)
    ");
    $dailyStmt->execute($dailyParams);
    $dailyEvents = $dailyStmt->fetchAll(PDO::FETCH_ASSOC);

    // Populate the counts array with actual data
    foreach ($dailyEvents as $event) {
        $dayFormatted = sprintf('%02d', (int)$event['day']);
        if (isset($counts[$dayFormatted])) {
            $counts[$dayFormatted] = (int)$event['count'];
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

    // Get total events for selected month (same as super admin)
    $monthlyTotal = array_sum($dailyCounts);
    error_log('Index chart: labels=' . count($labels) . ' monthlyTotal=' . $monthlyTotal);

    // Recent events - GLOBAL (like super dashboard)
    $recentEvents = $db->prepare("\n        SELECT * FROM events \n        ORDER BY date DESC, created_at DESC \n        LIMIT 5\n    ");
    $recentEvents->execute();
    $recentEvents = $recentEvents->fetchAll(PDO::FETCH_ASSOC);

    $quoteWhereParts = [];
    $quoteParams = [];
    if (!$isAdmin) {
        $currentUserId = (int)($user['id'] ?? 0);
        $quoteWhereParts[] = '(user_id = ? OR coordinator_id = ? OR coordinators REGEXP ?)';
        $quoteParams[] = $currentUserId;
        $quoteParams[] = $currentUserId;
        $quoteParams[] = '(^|\\[|,)\\s*' . $currentUserId . '\\s*(,|\\])';
    }
    if ($quoteFilter !== 'all') {
        $quoteWhereParts[] = 'LOWER(COALESCE(quote_status, \'pending\')) = ?';
        $quoteParams[] = $quoteFilter;
    }
    $quoteWhereSql = '';
    if (!empty($quoteWhereParts)) {
        $quoteWhereSql = 'WHERE ' . implode(' AND ', $quoteWhereParts);
    }

    $quoteCountsWhereParts = [];
    $quoteCountsParams = [];
    if (!$isAdmin) {
        $currentUserId = (int)($user['id'] ?? 0);
        $quoteCountsWhereParts[] = '(user_id = ? OR coordinator_id = ? OR coordinators REGEXP ?)';
        $quoteCountsParams[] = $currentUserId;
        $quoteCountsParams[] = $currentUserId;
        $quoteCountsParams[] = '(^|\\[|,)\\s*' . $currentUserId . '\\s*(,|\\])';
    }
    $quoteCountsWhereSql = '';
    if (!empty($quoteCountsWhereParts)) {
        $quoteCountsWhereSql = 'WHERE ' . implode(' AND ', $quoteCountsWhereParts);
    }

    $quoteCountsStmt = $db->prepare("\n        SELECT\n            SUM(CASE WHEN LOWER(COALESCE(quote_status, 'pending')) = 'pending' THEN 1 ELSE 0 END) AS pending_count,\n            SUM(CASE WHEN LOWER(COALESCE(quote_status, 'pending')) = 'approved' THEN 1 ELSE 0 END) AS approved_count,\n            SUM(CASE WHEN LOWER(COALESCE(quote_status, 'pending')) = 'rejected' THEN 1 ELSE 0 END) AS rejected_count\n        FROM events\n        " . $quoteCountsWhereSql . "\n    ");
    $quoteCountsStmt->execute($quoteCountsParams);
    $quoteCounts = $quoteCountsStmt->fetch(PDO::FETCH_ASSOC);
    $quotePendingCount = (int)($quoteCounts['pending_count'] ?? 0);
    $quoteApprovedCount = (int)($quoteCounts['approved_count'] ?? 0);
    $quoteRejectedCount = (int)($quoteCounts['rejected_count'] ?? 0);

    $quoteEventsStmt = $db->prepare("\n        SELECT id, name, client, date, quote_status, quote_finance_comment\n        FROM events\n        " . $quoteWhereSql . "\n        ORDER BY created_at DESC, date DESC\n        LIMIT 6\n    ");
    $quoteEventsStmt->execute($quoteParams);
    $quoteEvents = $quoteEventsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Set up next event query based on user role
    if ($isAdmin) {
        $nextWhere = '((end_date IS NOT NULL AND end_date >= CURDATE()) OR (end_date IS NULL AND date >= CURDATE()))';
        $nextParams = [];
    } else {
        $nextWhere = 'user_id = ? AND ((end_date IS NOT NULL AND end_date >= CURDATE()) OR (end_date IS NULL AND date >= CURDATE()))';
        $nextParams = [$user['id']];
    }
    
    $nextEvent = $db->prepare("
        SELECT * FROM events 
        WHERE " . $nextWhere . "
        ORDER BY date ASC
        LIMIT 1
    ");
    $nextEvent->execute($nextParams);
    $nextEvent = $nextEvent->fetch(PDO::FETCH_ASSOC);

    // Set up recent activities query based on user role
    if ($isAdmin) {
        $activitiesWhere = '';
        $activitiesParams = [];
    } else {
        $activitiesWhere = 'WHERE a.user_id = ?';
        $activitiesParams = [$user['id']];
    }
    
    $recentActivities = $db->prepare("\n        SELECT a.*, u.name as user_name\n        FROM activities a\n        LEFT JOIN users u ON a.user_id = u.id\n        " . $activitiesWhere . "\n        ORDER BY a.created_at DESC\n        LIMIT 3\n    ");
    $recentActivities->execute($activitiesParams);
    $recentActivities = $recentActivities->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    // Log errors to the server but don't display them to the user
    error_log("Dashboard error: " . $e->getMessage());
    
    // Set default values to prevent PHP notices
    $totalEvents = $upcomingEvents = $completedEvents = $cancelledEvents = 0;
    $sumAmount = $sumAdvance = $sumBalance = 0.0;
    $monthlyTotal = 0;
    $labels = $dailyCounts = $cumulative = [];
    $recentEvents = [];
    $nextEvent = null;
    $recentActivities = [];
}

// Helper functions
function format_currency($amount) {
    return 'Tsh ' . number_format($amount, 0, '.', ',');
}

function format_date($date, $format = 'M j, Y') {
    if (empty($date) || $date == '0000-00-00') return 'N/A';
    try {
        $dateObj = new DateTime($date);
        return $dateObj->format($format);
    } catch (Exception $e) {
        return 'N/A';
    }
}

function get_event_status_badge($event) {
    $now = new DateTime();
    $eventDate = !empty($event['date']) && $event['date'] != '0000-00-00' ? new DateTime($event['date']) : null;
    $endDate = !empty($event['end_date']) && $event['end_date'] != '0000-00-00' ? new DateTime($event['end_date']) : null;
    
    if ($event['status'] === 'cancelled') {
        return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">Cancelled</span>';
    }
    
    if ($eventDate) {
        if ($endDate) {
            if ($now > $endDate) {
                return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">Completed</span>';
            } elseif ($now >= $eventDate && $now <= $endDate) {
                return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">In Progress</span>';
            } else {
                return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800">Upcoming</span>';
            }
        } else {
            if ($now > $eventDate) {
                return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">Completed</span>';
            } else {
                return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800">Upcoming</span>';
            }
        }
    }
    
    return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">Draft</span>';
}

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

function time_elapsed_string($datetime, $full = false) {
    if (empty($datetime) || $datetime == '0000-00-00 00:00:00') return 'Just now';
    
    $now = new DateTime;
    try {
        $ago = new DateTime($datetime);
    } catch (Exception $e) {
        return 'Just now';
    }
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars($pageTitle); ?> | EventPro</title>
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
    
    /* Quick Actions layout helper */
    .quick-actions-grid {
      width: 100%;
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
        align-items: flex-start;
        gap: 1rem;
      }
      
      .user-menu {
        width: 100%;
        justify-content: space-between;
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
      
      .quick-actions-grid {
        grid-template-columns: 1fr;
      }
      
      .user-menu {
        flex-direction: column;
        gap: 0.5rem;
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
    <?php 
    $activePage = 'index.php';
    include __DIR__ . '/sidebar.php'; 
    ?>
    
    <!-- Main Content - Full Width -->
    <main class="main-content w-full max-w-full">
      <!-- Top Bar -->
      <div class="top-bar">
        <div class="page-title">
          <h1><?php echo htmlspecialchars($pageTitle); ?></h1>
          <p>Welcome back, <?php echo htmlspecialchars($user['name']); ?>!</p>
        </div>
        
        <div class="user-menu">
          <div class="date-display">
            <i class="far fa-calendar-alt mr-2"></i>
            <?php echo date('l, F j, Y'); ?>
          </div>
          
          <div class="user-profile" id="userProfile">
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
            
            <div class="profile-dropdown" id="profileDropdown">
              <a href="profile.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"><i class="fas fa-user mr-2"></i> My Profile</a>
              <a href="settings.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"><i class="fas fa-cog mr-2"></i> Settings</a>
              <a href="?logout" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"><i class="fas fa-sign-out-alt mr-2"></i> Logout</a>
            </div>
          </div>
        </div>
      </div>

      <?php foreach ($flashes as $f): ?>
        <div class="alert <?php echo $f['type'] === 'success' ? 'alert-success' : 'alert-error'; ?>">
          <?php echo htmlspecialchars($f['message']); ?>
        </div>
      <?php endforeach; ?>

      <!-- Stats Grid -->
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mb-6">
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

      <!-- Main Dashboard Grid -->
      <div class="grid grid-cols-1 xl:grid-cols-12 gap-6">
        <!-- Left Column - Main Content -->
        <div class="xl:col-span-8 space-y-6">
          <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden w-full">
            <div class="p-5 border-b border-gray-100 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
              <div>
                <h3 class="text-lg font-semibold text-gray-800">Quote Status</h3>
                <div class="mt-1 text-xs text-gray-500">
                  <span class="mr-3">Pending: <span class="font-semibold"><?php echo (int)$quotePendingCount; ?></span></span>
                  <span class="mr-3">Approved: <span class="font-semibold"><?php echo (int)$quoteApprovedCount; ?></span></span>
                  <span>Rejected: <span class="font-semibold"><?php echo (int)$quoteRejectedCount; ?></span></span>
                </div>
              </div>
              <form method="get" class="flex items-center gap-2">
                <?php if (!empty($selectedMonth)): ?>
                  <input type="hidden" name="month" value="<?php echo htmlspecialchars($selectedMonth); ?>">
                <?php endif; ?>
                <?php if (in_array($role, ['sales', 'admin', 'super'], true)): ?>
                  <a href="<?php echo htmlspecialchars($quoteStatusLink); ?>" class="inline-flex items-center justify-center w-10 h-10 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 text-gray-700" title="Open Quote Status">
                    <i class="fas fa-arrow-up-right-from-square"></i>
                  </a>
                <?php endif; ?>
                <select name="quote_status" class="appearance-none bg-white border border-gray-200 rounded-lg pl-3 pr-8 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                  <option value="all" <?php echo $quoteFilter === 'all' ? 'selected' : ''; ?>>All</option>
                  <option value="pending" <?php echo $quoteFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                  <option value="approved" <?php echo $quoteFilter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                  <option value="rejected" <?php echo $quoteFilter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                </select>
                <button type="submit" class="inline-flex items-center px-4 py-2 rounded-lg text-sm font-medium text-white bg-blue-600 hover:bg-blue-700">
                  Filter
                </button>
              </form>
            </div>
            <div class="overflow-x-auto">
              <table class="min-w-full divide-y divide-gray-100">
                <thead class="bg-gray-50">
                  <tr>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Event</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Client</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Date</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Quote</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Finance Comment</th>
                  </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-100">
                  <?php if (empty($quoteEvents)): ?>
                    <tr>
                      <td colspan="5" class="px-5 py-8 text-center text-sm text-gray-500">No events found.</td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($quoteEvents as $ev): ?>
                      <?php $qs = strtolower((string)($ev['quote_status'] ?? 'pending')); ?>
                      <tr class="hover:bg-gray-50">
                        <td class="px-5 py-3 text-sm font-medium text-gray-900">
                          <?php echo htmlspecialchars((string)($ev['name'] ?? '')); ?>
                        </td>
                        <td class="px-5 py-3 text-sm text-gray-700"><?php echo htmlspecialchars((string)($ev['client'] ?? '-')); ?></td>
                        <td class="px-5 py-3 text-sm text-gray-700"><?php echo !empty($ev['date']) ? htmlspecialchars(date('M j, Y', strtotime($ev['date']))) : '-'; ?></td>
                        <td class="px-5 py-3 text-sm">
                          <?php if ($qs === 'approved'): ?>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-green-100 text-green-800">Approved</span>
                          <?php elseif ($qs === 'rejected'): ?>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-red-100 text-red-800">Rejected</span>
                          <?php else: ?>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-100 text-amber-800">Pending</span>
                          <?php endif; ?>
                        </td>
                        <td class="px-5 py-3 text-xs text-gray-600" style="white-space: pre-wrap;">
                          <?php echo htmlspecialchars((string)($ev['quote_finance_comment'] ?? '')); ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>

            <div class="px-5 py-4 border-t border-gray-100 bg-white">
              <a href="<?php echo htmlspecialchars($quoteStatusLink); ?>" class="text-sm font-medium text-blue-600 hover:text-blue-800 inline-flex items-center gap-2">
                View all
                <i class="fas fa-arrow-down"></i>
              </a>
            </div>
          </div>

          <!-- Events Chart -->
          <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden w-full">
            <div class="p-5 border-b border-gray-100">
              <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
                <div>
                  <h3 class="text-lg font-semibold text-gray-800">Events Trend - <?php echo $displayMonth; ?></h3>
                  <p class="text-sm text-gray-500">Daily events overview</p>
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
                <canvas id="eventsChart" class="w-full h-full"></canvas>
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
          
          
        </div>
        
        <!-- Right Column - Sidebar -->
        <div class="space-y-6 xl:col-span-4">
          <!-- Quick Actions -->
          <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4">
            <h3 class="text-md font-semibold text-gray-800 mb-3">Quick Actions</h3>
            <div class="grid grid-cols-2 gap-2 quick-actions-grid">
              <a href="events.php?mode=form" class="p-2 bg-blue-50 hover:bg-blue-100 rounded-lg text-center transition-colors">
                <div class="w-8 h-8 bg-blue-100 text-blue-600 rounded-md flex items-center justify-center mx-auto mb-1">
                  <i class="fas fa-plus text-sm"></i>
                </div>
                <span class="text-xs font-medium text-gray-700">New Event</span>
              </a>
              <a href="events.php" class="p-2 bg-green-50 hover:bg-green-100 rounded-lg text-center transition-colors">
                <div class="w-8 h-8 bg-green-100 text-green-600 rounded-md flex items-center justify-center mx-auto mb-1">
                  <i class="fas fa-calendar-check text-sm"></i>
                </div>
                <span class="text-xs font-medium text-gray-700">All Events</span>
              </a>
              <a href="reports.php" class="p-2 bg-purple-50 hover:bg-purple-100 rounded-lg text-center transition-colors">
                <div class="w-8 h-8 bg-purple-100 text-purple-600 rounded-md flex items-center justify-center mx-auto mb-1">
                  <i class="fas fa-chart-pie text-sm"></i>
                </div>
                <span class="text-xs font-medium text-gray-700">Reports</span>
              </a>
              <a href="settings.php" class="p-2 bg-amber-50 hover:bg-amber-100 rounded-lg text-center transition-colors">
                <div class="w-8 h-8 bg-amber-100 text-amber-600 rounded-md flex items-center justify-center mx-auto mb-1">
                  <i class="fas fa-cog text-sm"></i>
                </div>
                <span class="text-xs font-medium text-gray-700">Settings</span>
              </a>
            </div>
          </div>

          <!-- Recent Activity -->
          <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="p-5 border-b border-gray-100">
              <div class="flex justify-between items-center">
                <div>
                  <h3 class="text-lg font-semibold text-gray-800">Recent Activity</h3>
                  <p class="text-xs text-gray-500 mt-1">Showing your latest 3 activities</p>
                </div>
                <a href="activities.php" class="inline-flex items-center px-3 py-1.5 border border-gray-300 rounded-lg text-xs font-medium text-gray-700 bg-white hover:bg-gray-50">
                  View full history
                  <i class="fas fa-arrow-right ml-1 text-[10px]"></i>
                </a>
              </div>
            </div>
            <div class="divide-y divide-gray-100">
              <?php if (!empty($recentActivities)): ?>
                <?php foreach ($recentActivities as $activity): 
                  $activityIcon  = getActivityIcon($activity['action'] ?? '');
                  $activityColor = getActivityColor($activity['action'] ?? '');

                  // Build a safe, user-friendly description
                  $rawDescription = trim($activity['description'] ?? '');
                  $actionKey = strtolower($activity['action'] ?? '');
                  $fallbackLabel = '';

                  if ($actionKey === 'event_create') {
                      $fallbackLabel = 'Event created';
                  } elseif ($actionKey === 'event_update') {
                      $fallbackLabel = 'Event updated';
                  } elseif ($actionKey === 'event_delete') {
                      $fallbackLabel = 'Event deleted';
                  } elseif ($actionKey === 'login') {
                      $fallbackLabel = 'User logged in';
                  } elseif ($actionKey === 'logout') {
                      $fallbackLabel = 'User logged out';
                  } else {
                      $fallbackLabel = 'Activity recorded';
                  }

                  $displayDescription = $rawDescription !== '' ? $rawDescription : $fallbackLabel;

                  // Prepare timestamp display (actual date & time)
                  $createdAtText = 'Time not available';
                  if (!empty($activity['created_at'])) {
                      try {
                          $dt = new DateTime($activity['created_at']);
                          // Example: Nov 23, 2025 21:15
                          $createdAtText = $dt->format('M j, Y H:i');
                      } catch (Exception $e) {
                          $createdAtText = $activity['created_at'];
                      }
                  }
                ?>
                <div class="p-4 hover:bg-gray-50 transition-colors">
                  <div class="flex items-start">
                    <div class="flex-shrink-0 mt-0.5">
                      <div class="h-9 w-9 rounded-full <?php echo $activityColor; ?> bg-opacity-10 flex items-center justify-center">
                        <i class="<?php echo $activityIcon; ?> text-base <?php echo $activityColor; ?>"></i>
                      </div>
                    </div>
                    <div class="ml-3 flex-1 min-w-0">
                      <p class="text-sm font-semibold text-gray-900 leading-tight">
                        <?php echo htmlspecialchars($fallbackLabel); ?>
                      </p>
                      <p class="text-xs text-gray-500 mt-0.5">
                        <?php echo !empty($activity['user_name']) ? htmlspecialchars($activity['user_name']) : 'System'; ?>
                        <?php if (!empty($createdAtText)): ?>
                          <span class="ml-1 text-gray-400">
                            <?php echo htmlspecialchars($createdAtText); ?>
                          </span>
                        <?php endif; ?>
                      </p>
                    </div>
                  </div>
                </div>
                <?php endforeach; ?>
              <?php else: ?>
                <div class="text-center py-8 text-gray-500">
                  <i class="fas fa-inbox text-3xl mb-2 block"></i>
                  <p>No recent activities found</p>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
      
      <!-- Full Width Recent Events Section -->
      <div class="mt-6 w-full">
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
          <div class="p-5 border-b border-gray-100">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
              <div>
                <h3 class="text-lg font-semibold text-gray-800">Recent Events</h3>
                <p class="text-sm text-gray-500">Latest event activities</p>
              </div>
              <a href="events.php" class="mt-3 sm:mt-0 inline-flex items-center px-4 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                View All <i class="fas fa-arrow-right ml-1.5 text-xs"></i>
              </a>
            </div>
          </div>
          <div class="divide-y divide-gray-100">
            <?php if (empty($recentEvents)): ?>
              <div class="text-center py-8 text-gray-500">
                <i class="fas fa-calendar-plus text-3xl mb-3 block"></i>
                <p class="text-gray-500 font-medium mb-1">No events yet</p>
                <p class="text-sm text-gray-400 mb-4">Create your first event to get started</p>
                <a href="event_add.php" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-lg text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                  <i class="fas fa-plus mr-1.5"></i> Create Event
                </a>
              </div>
            <?php else: ?>
              <?php foreach ($recentEvents as $event): ?>
                <a href="event.php?id=<?php echo $event['id']; ?>" class="block p-5 hover:bg-gray-50 transition-colors">
                  <div class="flex items-start">
                    <div class="flex-shrink-0 h-12 w-12 rounded-xl bg-blue-50 flex items-center justify-center text-blue-600 mr-4">
                      <i class="fas fa-calendar-day"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                      <div class="flex items-center justify-between">
                        <h3 class="font-medium text-gray-900"><?php echo htmlspecialchars($event['name']); ?></h3>
                        <?php echo get_event_status_badge($event); ?>
                      </div>
                      <div class="mt-1 flex items-center text-sm text-gray-500">
                        <i class="far fa-calendar mr-2"></i>
                        <?php echo format_date($event['date']); ?>
                        <?php if (!empty($event['location'])): ?>
                          <span class="mx-2">•</span>
                          <i class="fas fa-map-marker-alt mr-2"></i>
                          <?php echo htmlspecialchars($event['location']); ?>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                </a>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>
          
        </div>
      </div>
    </main>
  </div>

  <script>
    // Mobile menu functionality
    const mobileMenuBtn = document.getElementById('mobileMenuBtn');
    const sidebar = document.querySelector('.sidebar');
    
    if (mobileMenuBtn && sidebar) {
      mobileMenuBtn.addEventListener('click', function() {
        sidebar.classList.toggle('mobile-open');
      });
    }

    // Toggle profile dropdown
    if (!window.__profileDropdownBound) {
      document.getElementById('userProfile').addEventListener('click', function() {
        document.getElementById('profileDropdown').classList.toggle('show');
      });
    }
    
    // Close dropdown when clicking outside
    if (!window.__profileDropdownBound) {
      document.addEventListener('click', function(event) {
        const profile = document.getElementById('userProfile');
        const dropdown = document.getElementById('profileDropdown');
        
        if (!profile.contains(event.target)) {
          dropdown.classList.remove('show');
        }
      });
    }

    // Chart initialization
    const labels = <?php echo json_encode($labels); ?>;
    const daily = <?php echo json_encode($dailyCounts); ?>;

    const ctx = document.getElementById('eventsChart');
    const chartLoading = document.getElementById('chartLoading');
    if (ctx && labels.length > 0) {
      try {
        new Chart(ctx, {
          type: 'line',
          data: {
            labels: labels,
            datasets: [
              {
                label: 'Daily Events',
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
        
        // Hide loading indicator when chart is rendered
        if (chartLoading) {
          chartLoading.style.display = 'none';
        }
      } catch (e) {
        console.error('Chart error:', e);
        // Show error message
        if (chartLoading) {
          chartLoading.innerHTML = '<div class="text-center py-8 text-red-500">Error loading chart data. Please refresh the page.</div>';
        } else {
          ctx.parentElement.innerHTML = '<div class="text-center py-8 text-red-500">Error loading chart data. Please refresh the page.</div>';
        }
      }
    }

    // Close mobile menu when clicking on a link
    document.querySelectorAll('.nav-item').forEach(item => {
      item.addEventListener('click', () => {
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
</body>
</html>