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
// Determine role for display
$isSuper = (($user['role'] ?? 'user') === 'super');

// Get events for FullCalendar (same as index)
$calendarEvents = $db->query("SELECT id, name, date, end_date, location, client FROM events WHERE date IS NOT NULL ORDER BY date ASC")->fetchAll(PDO::FETCH_ASSOC);

// Get current month and year
$month = isset($_GET['month']) ? (int)$_GET['month'] : date('n');
$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');

// Validate month and year
if ($month < 1 || $month > 12) $month = date('n');
if ($year < 2020 || $year > 2030) $year = date('Y');

// Calculate previous and next months
$prev_month = $month - 1;
$prev_year = $year;
if ($prev_month < 1) {
    $prev_month = 12;
    $prev_year = $year - 1;
}

$next_month = $month + 1;
$next_year = $year;
if ($next_month > 12) {
    $next_month = 1;
    $next_year = $year + 1;
}

// Get events for the current month
$first_day = "{$year}-{$month}-01";
$last_day = date('Y-m-t', strtotime($first_day));

$stmt = $db->prepare("
    SELECT * FROM events 
    WHERE date <= ?
      AND (
        end_date IS NULL OR end_date = '0000-00-00'
        OR end_date >= ?
      )
    ORDER BY date, id
");
$stmt->execute([$last_day, $first_day]);
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group events by date
$events_by_date = [];
foreach ($events as $event) {
    $start = (string)($event['date'] ?? '');
    $end = (string)($event['end_date'] ?? '');

    if ($start === '') {
        continue;
    }

    $endIsValid = ($end !== '' && $end !== '0000-00-00' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $end));
    if (!$endIsValid || strtotime($end) < strtotime($start)) {
        $end = $start;
    }

    $from = max(strtotime($start), strtotime($first_day));
    $to = min(strtotime($end), strtotime($last_day));
    if ($to < $from) {
        continue;
    }

    for ($t = $from; $t <= $to; $t = strtotime('+1 day', $t)) {
        $d = date('Y-m-d', $t);
        if (!isset($events_by_date[$d])) {
            $events_by_date[$d] = [];
        }
        $events_by_date[$d][] = $event;
    }
}

// Get today's date for highlighting
$today = date('Y-m-d');

// Get first day of the month and total days
$first_day_of_month = date('N', strtotime($first_day)); // 1 (Monday) through 7 (Sunday)
$total_days = date('t', strtotime($first_day));

// Calculate days from previous month to show
$days_from_prev_month = $first_day_of_month - 1;
$prev_month_last_day = date('t', strtotime("{$prev_year}-{$prev_month}-01"));

// Get events for today
$stmt_today = $db->prepare("
    SELECT * FROM events 
    WHERE date <= ?
      AND (
        end_date IS NULL OR end_date = '0000-00-00'
        OR end_date >= ?
      )
    ORDER BY date, id
");
$stmt_today->execute([$today, $today]);
$today_events = $stmt_today->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Calendar | EventPro</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css">
  <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>
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
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      background-color: #f1f5f9;
      color: #334155;
      line-height: 1.5;
      overflow-x: hidden;
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
      transition: all 0.3s ease;
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
      flex-wrap: nowrap; /* keep date + user on a single row like Super Dashboard */
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
    
    /* Calendar Styles */
    .calendar-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 1.5rem;
    }
    
    .calendar-nav {
      display: flex;
      align-items: center;
      gap: 1rem;
    }
    
    .calendar-title {
      font-size: 1.5rem;
      font-weight: 700;
      color: #1e293b;
    }
    
    .calendar-grid {
      display: grid;
      grid-template-columns: repeat(7, 1fr);
      gap: 1px;
      background-color: #e2e8f0;
      border: 1px solid #e2e8f0;
      border-radius: 8px;
      overflow: hidden;
    }
    
    .calendar-day-header {
      background-color: #f8fafc;
      padding: 1rem;
      text-align: center;
      font-weight: 600;
      color: #475569;
      border-bottom: 1px solid #e2e8f0;
    }
    
    .calendar-day {
      background-color: white;
      min-height: 120px;
      padding: 0.5rem;
      border: 1px solid #f1f5f9;
      transition: all 0.2s;
    }
    
    .calendar-day:hover {
      background-color: #f8fafc;
    }
    
    .calendar-day.other-month {
      background-color: #f8fafc;
      color: #94a3b8;
    }
    
    .calendar-day.today {
      background-color: #dbeafe;
      border-color: #3b82f6;
    }
    
    .calendar-day.has-events {
      background-color: #f0f9ff;
    }
    
    .day-number {
      font-weight: 600;
      margin-bottom: 0.25rem;
      font-size: 0.875rem;
    }
    
    .event-list {
      max-height: 80px;
      overflow-y: auto;
    }
    
    .event-item {
      background-color: #3b82f6;
      color: white;
      padding: 0.25rem 0.5rem;
      border-radius: 4px;
      margin-bottom: 0.25rem;
      font-size: 0.75rem;
      cursor: pointer;
      transition: all 0.2s;
    }
    
    .event-item:hover {
      background-color: #2563eb;
    }
    
    .event-item.completed {
      background-color: #10b981;
    }
    
    .event-item.planning {
      background-color: #f59e0b;
    }
    
    /* Events List Styles */
    .events-list {
      display: flex;
      flex-direction: column;
      gap: 0.75rem;
    }
    
    .event-card {
      background: white;
      border: 1px solid #e2e8f0;
      border-radius: 8px;
      padding: 1rem;
      transition: all 0.2s;
    }
    
    .event-card:hover {
      border-color: #3b82f6;
      box-shadow: 0 2px 8px rgba(59, 130, 246, 0.1);
    }
    
    .event-card-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      margin-bottom: 0.5rem;
    }
    
    .event-title {
      font-weight: 600;
      color: #1e293b;
      margin-bottom: 0.25rem;
    }
    
    .event-date {
      color: #64748b;
      font-size: 0.875rem;
    }
    
    .event-badge {
      display: inline-flex;
      align-items: center;
      padding: 0.25rem 0.5rem;
      border-radius: 9999px;
      font-size: 0.75rem;
      font-weight: 500;
    }
    
    .badge-success {
      background-color: #d1fae5;
      color: #065f46;
    }
    
    .badge-warning {
      background-color: #fef3c7;
      color: #92400e;
    }
    
    .badge-secondary {
      background-color: #e0e7ff;
      color: #3730a3;
    }
    
    .event-details {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 0.5rem;
      font-size: 0.875rem;
      color: #64748b;
    }
    
    .event-detail {
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }
    
    /* Responsive: align with index/events sidebar behavior */
    @media (max-width: 1024px) {
      .main-content {
        margin-left: 0;
        width: 100%;
        padding: 1rem;
      }
      .calendar-day { min-height: 100px; }
      .calendar-actions-grid { grid-template-columns: 1fr; }
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
      
      .calendar-header {
        flex-direction: column;
        gap: 1rem;
        align-items: flex-start;
      }
      
      .calendar-nav {
        width: 100%;
        justify-content: space-between;
      }
      
      .calendar-day {
        min-height: 80px;
        padding: 0.25rem;
      }
      
      .event-item {
        font-size: 0.7rem;
        padding: 0.125rem 0.25rem;
      }
    }
    
    @media (max-width: 640px) {
      .main-content {
        margin-left: 0;
        width: 100%;
      }
      /* Match Super Dashboard / Events mobile header: 
         title on top, then a row with date left and user right */
      .top-bar {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.75rem;
      }
      .user-menu {
        width: 100%;
        display: flex;
        flex-direction: row;
        justify-content: space-between;
        align-items: center;
        gap: 0.5rem;
      }
      .calendar-grid { grid-template-columns: repeat(7, 1fr); }
      .calendar-day-header { padding: 0.5rem; font-size: 0.75rem; }
      .calendar-day { min-height: 60px; }
      .day-number { font-size: 0.75rem; }
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
          <h1>Event Calendar</h1>
          <p>View your events in calendar</p>
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
      
      <!-- Calendar Navigation -->
      <div class="content-section">
        <div class="calendar-header">
          <div class="calendar-nav">
            <a href="calendar.php?month=<?php echo $prev_month; ?>&year=<?php echo $prev_year; ?>" class="btn btn-secondary">
              <i class="fas fa-chevron-left mr-2"></i>
              Previous
            </a>
            <a href="calendar.php" class="btn btn-secondary">Today</a>
            <a href="calendar.php?month=<?php echo $next_month; ?>&year=<?php echo $next_year; ?>" class="btn btn-secondary">
              Next
              <i class="fas fa-chevron-right ml-2"></i>
            </a>
          </div>
          
          <h2 class="calendar-title">
            <?php echo date('F Y', strtotime("{$year}-{$month}-01")); ?>
          </h2>
          
          <div class="section-actions">
            <a href="events.php" class="btn btn-primary">
              <i class="fas fa-plus mr-2"></i>
              New Event
            </a>
          </div>
        </div>
        
        <!-- Full Calendar (like index) -->
        <div class="content-section" style="margin-bottom: 1.5rem;">
          <div class="section-header">
            <h2 class="section-title">Full Calendar</h2>
          </div>
          <div id="calendar"></div>

          <!-- Calendar Legend -->
          <div class="mt-4 flex flex-wrap items-center gap-3 text-xs text-gray-600">
            <span class="font-semibold mr-1">Legend:</span>
            <span class="inline-flex items-center gap-1">
              <span class="w-2.5 h-2.5 rounded-full bg-blue-500"></span>
              Upcoming / Scheduled
            </span>
            <span class="inline-flex items-center gap-1">
              <span class="w-2.5 h-2.5 rounded-full bg-green-500"></span>
              Completed
            </span>
            <span class="inline-flex items-center gap-1">
              <span class="w-2.5 h-2.5 rounded-full bg-amber-500"></span>
              Planning / In Progress
            </span>
          </div>
        </div>

        
      </div>
      
      <!-- Today's Events -->
      <?php if (!empty($today_events)): ?>
      <div class="content-section">
        <div class="section-header">
          <h2 class="section-title">Today's Events</h2>
          <div class="section-actions">
            <span class="text-sm text-gray-600"><?php echo date('F j, Y'); ?></span>
          </div>
        </div>
        
        <div class="events-list">
          <?php foreach ($today_events as $event): ?>
          <div class="event-card">
            <div class="event-card-header">
              <div style="flex: 1;">
                <div class="event-title"><?php echo htmlspecialchars($event['name']); ?></div>
                <div class="event-date">
                  <i class="far fa-clock mr-1"></i>
                  <?php echo date('g:i A', strtotime($event['date'])); ?>
                </div>
              </div>
              <span class="event-badge <?php 
                echo match($event['status'] ?? '') {
                  'COMPLETED' => 'badge-success',
                  'PLANNING' => 'badge-warning',
                  default => 'badge-secondary'
                };
              ?>">
                <?php echo htmlspecialchars($event['status'] ?? 'UPCOMING'); ?>
              </span>
            </div>
            <div class="event-details">
              <?php if (!empty($event['location'])): ?>
                <div class="event-detail">
                  <i class="fas fa-map-marker-alt text-gray-400"></i>
                  <span><?php echo htmlspecialchars($event['location']); ?></span>
                </div>
              <?php endif; ?>
              <?php if (!empty($event['client'])): ?>
                <div class="event-detail">
                  <i class="fas fa-user-tie text-gray-400"></i>
                  <span><?php echo htmlspecialchars($event['client']); ?></span>
                </div>
              <?php endif; ?>
              <?php if (!empty($event['coordinator'])): ?>
                <div class="event-detail">
                  <i class="fas fa-user text-gray-400"></i>
                  <span><?php echo htmlspecialchars($event['coordinator']); ?></span>
                </div>
              <?php endif; ?>
            </div>
            <div class="mt-2 flex gap-2">
              <a href="events.php?id=<?php echo $event['id']; ?>" class="btn btn-primary btn-sm">
                <i class="fas fa-eye mr-1"></i> View
              </a>
              <a href="events.php?action=edit&id=<?php echo $event['id']; ?>" class="btn btn-secondary btn-sm">
                <i class="fas fa-edit mr-1"></i> Edit
              </a>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
      
      <!-- Upcoming Events -->
      <div class="content-section">
        <div class="section-header">
          <h2 class="section-title">Upcoming Events</h2>
          <div class="section-actions">
            <a href="events.php" class="btn btn-secondary">View All Events</a>
          </div>
        </div>
        
        <?php
        // Get upcoming events (next 7 days)
        $upcoming_start = date('Y-m-d');
        $upcoming_end = date('Y-m-d', strtotime('+7 days'));
        
        $stmt_upcoming = $db->prepare("
            SELECT * FROM events 
            WHERE date <= ?
              AND (
                end_date IS NULL OR end_date = '0000-00-00'
                OR end_date >= ?
              )
            ORDER BY date, id
            LIMIT 10
        ");
        $stmt_upcoming->execute([$upcoming_end, $upcoming_start]);
        $upcoming_events = $stmt_upcoming->fetchAll(PDO::FETCH_ASSOC);
        ?>
        
        <?php if (!empty($upcoming_events)): ?>
          <div class="events-list">
            <?php foreach ($upcoming_events as $event): ?>
            <div class="event-card">
              <div class="event-card-header">
                <div style="flex: 1;">
                  <div class="event-title"><?php echo htmlspecialchars($event['name']); ?></div>
                  <div class="event-date">
                    <i class="far fa-calendar mr-1"></i>
                    <?php echo date('F j, Y', strtotime($event['date'])); ?>
                  </div>
                </div>
                <span class="event-badge <?php 
                  echo match($event['status'] ?? '') {
                    'COMPLETED' => 'badge-success',
                    'PLANNING' => 'badge-warning',
                    default => 'badge-secondary'
                  };
                ?>">
                  <?php echo htmlspecialchars($event['status'] ?? 'UPCOMING'); ?>
                </span>
              </div>
              <div class="event-details">
                <?php if (!empty($event['location'])): ?>
                  <div class="event-detail">
                    <i class="fas fa-map-marker-alt text-gray-400"></i>
                    <span><?php echo htmlspecialchars($event['location']); ?></span>
                  </div>
                <?php endif; ?>
                <?php if (!empty($event['client'])): ?>
                  <div class="event-detail">
                    <i class="fas fa-user-tie text-gray-400"></i>
                    <span><?php echo htmlspecialchars($event['client']); ?></span>
                  </div>
                <?php endif; ?>
              </div>
              <div class="mt-2 flex gap-2">
                <a href="events.php?id=<?php echo $event['id']; ?>" class="btn btn-primary btn-sm">
                  <i class="fas fa-eye mr-1"></i> View
                </a>
                <a href="events.php?action=edit&id=<?php echo $event['id']; ?>" class="btn btn-secondary btn-sm">
                  <i class="fas fa-edit mr-1"></i> Edit
                </a>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="text-center py-8 text-gray-500">
            <i class="fas fa-calendar-check text-3xl mb-2 block"></i>
            <p>No upcoming events in the next 7 days.</p>
            <a href="events.php" class="btn btn-primary mt-4">
              <i class="fas fa-plus mr-2"></i>
              Create New Event
            </a>
          </div>
        <?php endif; ?>
      </div>
    </main>
  </div>

  <script>
    // Initialize FullCalendar like index
    document.addEventListener('DOMContentLoaded', function() {
      var calendarEl = document.getElementById('calendar');
      if (calendarEl) {
        var calendar = new FullCalendar.Calendar(calendarEl, {
          initialView: 'dayGridMonth',
          headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,timeGridWeek,timeGridDay'
          },
          firstDay: 1,
          events: [
            <?php foreach ($calendarEvents as $event): ?>
            {
              id: '<?php echo $event['id']; ?>',
              title: '<?php echo addslashes($event['name']); ?>',
              start: '<?php echo $event['date']; ?>',
              <?php
                $end = (string)($event['end_date'] ?? '');
                $start = (string)($event['date'] ?? '');
                $endIsValid = ($end !== '' && $end !== '0000-00-00' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $end));
                if ($endIsValid && $start !== '' && strtotime($end) >= strtotime($start)) {
                    $endExclusive = date('Y-m-d', strtotime($end . ' +1 day'));
                    echo "end: '" . $endExclusive . "',";
                }
              ?>
              extendedProps: {
                location: '<?php echo addslashes($event['location'] ?? ''); ?>',
                client: '<?php echo addslashes($event['client'] ?? ''); ?>'
              }
            },
            <?php endforeach; ?>
          ],
          eventClick: function(info) {
            window.location.href = 'events.php?id=' + info.event.id;
          },
          eventDisplay: 'block',
          height: 'auto'
        });
        calendar.render();
      }
    });

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
    
    // Add some interactive elements
    document.addEventListener('DOMContentLoaded', function() {
      // Add animation to calendar
      const calendarGrid = document.querySelector('.calendar-grid');
      if (calendarGrid) {
        calendarGrid.style.opacity = '0';
        calendarGrid.style.transform = 'translateY(10px)';
        
        setTimeout(() => {
          calendarGrid.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
          calendarGrid.style.opacity = '1';
          calendarGrid.style.transform = 'translateY(0)';
        }, 100);
      }
    });
  </script>
</body>
</html>