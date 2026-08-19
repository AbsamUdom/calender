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
// Determine if current user is super admin
$isSuper = (($user['role'] ?? 'user') === 'super');

// Get report parameters
$report_type = $_GET['type'] ?? 'overview'; // overview, event, weekly, monthly, yearly
// Non-super users are not allowed to access yearly reports
if ($report_type === 'yearly' && !$isSuper) {
    $report_type = 'overview';
}
$event_id = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;
$month = $_GET['month'] ?? date('Y-m');
$year = $_GET['year'] ?? date('Y');
// Week start (for weekly reports) - default to Monday of current week
$week_start = $_GET['week_start'] ?? date('Y-m-d', strtotime('monday next week'));

// Load events for dropdown
$events = $db->query('SELECT * FROM events ORDER BY date DESC')->fetchAll(PDO::FETCH_ASSOC);

// Initialize report data
$report_data = [];
$report_title = '';
$report_subtitle = '';

// Company Information
$company_info = [
    'name' => 'HUGO DOMINGO LTD',
    'tagline' => 'CREATIVE EVENT SETS AND DECOR',
    'phones' => ['+255767340838', '0713236811', '763500510', '813', '249'],
    'emails' => ['hugodomingo@gmail.com', 'mario@hugodomingo.com'],
    'instagram' => 'hugodomingoevents'
];

// Generate Event Report
if ($report_type === 'event' && $event_id > 0) {
    $event = $db->prepare('SELECT * FROM events WHERE id = ?');
    $event->execute([$event_id]);
    $event_data = $event->fetch(PDO::FETCH_ASSOC);
    
    if ($event_data) {
        $report_title = $event_data['name'] . ' - Event Report';
        $report_subtitle = 'Date: ' . $event_data['date'] . ' | Location: ' . $event_data['location'];
        
        // Event statistics
        $total_attendees = $db->prepare('SELECT COUNT(*) FROM attendees WHERE event_id = ?');
        $total_attendees->execute([$event_id]);
        $total_count = $total_attendees->fetchColumn();
        
        $confirmed_attendees = $db->prepare('SELECT COUNT(*) FROM attendees WHERE event_id = ? AND attended = 1');
        $confirmed_attendees->execute([$event_id]);
        $confirmed_count = $confirmed_attendees->fetchColumn();
        
        $attendance_rate = $total_count > 0 ? round(($confirmed_count / $total_count) * 100, 1) : 0;
        
        // Attendee details
        $attendees = $db->prepare('
            SELECT a.*, e.name as event_name 
            FROM attendees a 
            LEFT JOIN events e ON a.event_id = e.id 
            WHERE a.event_id = ? 
            ORDER BY a.attended DESC, a.name ASC
        ');
        $attendees->execute([$event_id]);
        $attendee_list = $attendees->fetchAll(PDO::FETCH_ASSOC);
        
        $report_data = [
            'type' => 'event',
            'event' => $event_data,
            'stats' => [
                'total_attendees' => $total_count,
                'confirmed_attendees' => $confirmed_count,
                'pending_attendees' => $total_count - $confirmed_count,
                'attendance_rate' => $attendance_rate,
                'no_show_rate' => 100 - $attendance_rate
            ],
            'attendees' => $attendee_list
        ];
    }
}

// Generate Weekly Report
elseif ($report_type === 'weekly') {
    // Week range (7 days starting from week_start)
    $week_start_date = date('Y-m-d', strtotime($week_start));
    $week_end_date = date('Y-m-d', strtotime($week_start_date . ' +6 days'));

    $report_title = 'Weekly Report - ' . date('M j', strtotime($week_start_date)) . ' to ' . date('M j, Y', strtotime($week_end_date));
    $report_subtitle = 'Events and attendance for the selected week';

    // Weekly statistics
    $week_events = $db->prepare('
        SELECT COUNT(*) FROM events 
        WHERE date BETWEEN ? AND ?
    ');
    $week_events->execute([$week_start_date, $week_end_date]);
    $events_count = $week_events->fetchColumn();

    $week_attendees = $db->prepare('
        SELECT COUNT(*) FROM attendees a
        JOIN events e ON a.event_id = e.id
        WHERE e.date BETWEEN ? AND ?
    ');
    $week_attendees->execute([$week_start_date, $week_end_date]);
    $attendees_count = $week_attendees->fetchColumn();

    $week_confirmed = $db->prepare('
        SELECT COUNT(*) FROM attendees a
        JOIN events e ON a.event_id = e.id
        WHERE e.date BETWEEN ? AND ? AND a.attended = 1
    ');
    $week_confirmed->execute([$week_start_date, $week_end_date]);
    $confirmed_count = $week_confirmed->fetchColumn();

    // Events list for the week
    $events_list = $db->prepare('
        SELECT e.*, 
               COUNT(a.id) as total_attendees,
               SUM(CASE WHEN a.attended = 1 THEN 1 ELSE 0 END) as confirmed_attendees
        FROM events e
        LEFT JOIN attendees a ON e.id = a.event_id
        WHERE e.date BETWEEN ? AND ?
        GROUP BY e.id
        ORDER BY e.date ASC
    ');
    $events_list->execute([$week_start_date, $week_end_date]);
    $events_data = $events_list->fetchAll(PDO::FETCH_ASSOC);

    // Financial totals for the week (for super admin view)
    $week_totals = $db->prepare('
        SELECT 
            COALESCE(SUM(amount),0) AS amt,
            COALESCE(SUM(advance),0) AS adv,
            COALESCE(SUM(balance),0) AS bal
        FROM events
        WHERE date BETWEEN ? AND ?
    ');
    $week_totals->execute([$week_start_date, $week_end_date]);
    $totals = $week_totals->fetch(PDO::FETCH_ASSOC) ?: ['amt' => 0, 'adv' => 0, 'bal' => 0];
    $sumAmount = (float)($totals['amt'] ?? 0);
    $sumAdvance = (float)($totals['adv'] ?? 0);
    $sumBalance = (float)($totals['bal'] ?? 0);

    $advancePercent = $sumAmount > 0 ? round(($sumAdvance / $sumAmount) * 100, 1) : 0;
    $balancePercent = $sumAmount > 0 ? round(($sumBalance / $sumAmount) * 100, 1) : 0;

    $report_data = [
        'type' => 'weekly',
        'period' => [
            'start' => $week_start_date,
            'end' => $week_end_date,
        ],
        'stats' => [
            'total_events' => $events_count,
            'total_attendees' => $attendees_count,
            'confirmed_attendees' => $confirmed_count,
            'attendance_rate' => $attendees_count > 0 ? round(($confirmed_count / $attendees_count) * 100, 1) : 0,
        ],
        'events' => $events_data,
        'financials' => [
            'amount' => $sumAmount,
            'advance' => $sumAdvance,
            'balance' => $sumBalance,
            'advance_percent' => $advancePercent,
            'balance_percent' => $balancePercent,
        ],
    ];
}

// Generate Monthly Office Report
elseif ($report_type === 'monthly') {
    $report_title = 'Monthly Office Report - ' . date('F Y', strtotime($month . '-01'));
    $report_subtitle = 'Comprehensive overview of all events and activities';
    
    // Monthly statistics
    $month_start = $month . '-01';
    $month_end = date('Y-m-t', strtotime($month_start));
    
    // Total events in month
    $month_events = $db->prepare('
        SELECT COUNT(*) FROM events 
        WHERE date BETWEEN ? AND ?
    ');
    $month_events->execute([$month_start, $month_end]);
    $events_count = $month_events->fetchColumn();
    
    // Total attendees in month
    $month_attendees = $db->prepare('
        SELECT COUNT(*) FROM attendees a
        JOIN events e ON a.event_id = e.id
        WHERE e.date BETWEEN ? AND ?
    ');
    $month_attendees->execute([$month_start, $month_end]);
    $attendees_count = $month_attendees->fetchColumn();
    
    // Confirmed attendees in month
    $month_confirmed = $db->prepare('
        SELECT COUNT(*) FROM attendees a
        JOIN events e ON a.event_id = e.id
        WHERE e.date BETWEEN ? AND ? AND a.attended = 1
    ');
    $month_confirmed->execute([$month_start, $month_end]);
    $confirmed_count = $month_confirmed->fetchColumn();
    
    // Events list for the month
    $events_list = $db->prepare('
        SELECT e.*, 
               COUNT(a.id) as total_attendees,
               SUM(CASE WHEN a.attended = 1 THEN 1 ELSE 0 END) as confirmed_attendees
        FROM events e
        LEFT JOIN attendees a ON e.id = a.event_id
        WHERE e.date BETWEEN ? AND ?
        GROUP BY e.id
        ORDER BY e.date ASC
    ');
    $events_list->execute([$month_start, $month_end]);
    $events_data = $events_list->fetchAll(PDO::FETCH_ASSOC);

    // Financial totals for the month (for super admin view)
    $month_totals = $db->prepare('
        SELECT 
            COALESCE(SUM(amount),0) AS amt,
            COALESCE(SUM(advance),0) AS adv,
            COALESCE(SUM(balance),0) AS bal
        FROM events
        WHERE date BETWEEN ? AND ?
    ');
    $month_totals->execute([$month_start, $month_end]);
    $totals = $month_totals->fetch(PDO::FETCH_ASSOC) ?: ['amt' => 0, 'adv' => 0, 'bal' => 0];
    $sumAmount = (float)($totals['amt'] ?? 0);
    $sumAdvance = (float)($totals['adv'] ?? 0);
    $sumBalance = (float)($totals['bal'] ?? 0);

    $advancePercent = $sumAmount > 0 ? round(($sumAdvance / $sumAmount) * 100, 1) : 0;
    $balancePercent = $sumAmount > 0 ? round(($sumBalance / $sumAmount) * 100, 1) : 0;
    
    // Monthly growth (compared to previous month)
    $prev_month = date('Y-m', strtotime($month . '-01 -1 month'));
    $prev_month_start = $prev_month . '-01';
    $prev_month_end = date('Y-m-t', strtotime($prev_month_start));
    
    $prev_month_events = $db->prepare('SELECT COUNT(*) FROM events WHERE date BETWEEN ? AND ?');
    $prev_month_events->execute([$prev_month_start, $prev_month_end]);
    $prev_events_count = $prev_month_events->fetchColumn();
    
    $prev_month_attendees = $db->prepare('
        SELECT COUNT(*) FROM attendees a
        JOIN events e ON a.event_id = e.id
        WHERE e.date BETWEEN ? AND ?
    ');
    $prev_month_attendees->execute([$prev_month_start, $prev_month_end]);
    $prev_attendees_count = $prev_month_attendees->fetchColumn();
    
    $events_growth = $prev_events_count > 0 ? 
        round((($events_count - $prev_events_count) / $prev_events_count) * 100, 1) : 0;
    $attendees_growth = $prev_attendees_count > 0 ? 
        round((($attendees_count - $prev_attendees_count) / $prev_attendees_count) * 100, 1) : 0;
    
    $report_data = [
        'type' => 'monthly',
        'period' => $month,
        'stats' => [
            'total_events' => $events_count,
            'total_attendees' => $attendees_count,
            'confirmed_attendees' => $confirmed_count,
            'attendance_rate' => $attendees_count > 0 ? round(($confirmed_count / $attendees_count) * 100, 1) : 0,
            'events_growth' => $events_growth,
            'attendees_growth' => $attendees_growth
        ],
        'events' => $events_data,
        'financials' => [
            'amount' => $sumAmount,
            'advance' => $sumAdvance,
            'balance' => $sumBalance,
            'advance_percent' => $advancePercent,
            'balance_percent' => $balancePercent,
        ],
        'comparison' => [
            'previous_month' => $prev_month,
            'previous_events' => $prev_events_count,
            'previous_attendees' => $prev_attendees_count
        ]
    ];
}

// Generate Yearly Overview Report
elseif ($report_type === 'yearly') {
    $report_title = 'Yearly Financial Summary - ' . $year;
    $report_subtitle = 'Monthly totals for events and financials';

    // Monthly financial breakdown per year
    $monthly_data = [];
    $year_total_events = 0;
    $year_total_amount = 0.0;
    $year_total_advance = 0.0;
    $year_total_balance = 0.0;
    for ($i = 1; $i <= 12; $i++) {
        $month = sprintf('%02d', $i);
        $month_start = $year . '-' . $month . '-01';
        $month_end = date('Y-m-t', strtotime($month_start));

        $month_stmt = $db->prepare('
            SELECT 
                COUNT(*) AS total_events,
                COALESCE(SUM(amount), 0) AS total_amount,
                COALESCE(SUM(advance), 0) AS total_advance,
                COALESCE(SUM(balance), 0) AS total_balance
            FROM events
            WHERE date BETWEEN ? AND ?
        ');
        $month_stmt->execute([$month_start, $month_end]);
        $row = $month_stmt->fetch(PDO::FETCH_ASSOC) ?: [
            'total_events' => 0,
            'total_amount' => 0,
            'total_advance' => 0,
            'total_balance' => 0,
        ];

        $monthly_data[] = [
            'month' => date('F', strtotime($month_start)),
            'total_events' => (int)($row['total_events'] ?? 0),
            'total_amount' => (float)($row['total_amount'] ?? 0),
            'total_advance' => (float)($row['total_advance'] ?? 0),
            'total_balance' => (float)($row['total_balance'] ?? 0),
        ];

        // Accumulate yearly totals
        $year_total_events += (int)($row['total_events'] ?? 0);
        $year_total_amount += (float)($row['total_amount'] ?? 0);
        $year_total_advance += (float)($row['total_advance'] ?? 0);
        $year_total_balance += (float)($row['total_balance'] ?? 0);
    }

    $report_data = [
        'type' => 'yearly',
        'year' => $year,
        'monthly_breakdown' => $monthly_data,
        'year_totals' => [
            'events' => $year_total_events,
            'amount' => $year_total_amount,
            'advance' => $year_total_advance,
            'balance' => $year_total_balance,
        ],
    ];
}

// Default Events Overview Report
else {
    $report_title = 'Events Overview Report';
    $report_subtitle = 'Comprehensive summary of all events';
    
    // Overall statistics
    $total_events = $db->query('SELECT COUNT(*) FROM events')->fetchColumn();
    $total_attendees = $db->query('SELECT COUNT(*) FROM attendees')->fetchColumn();
    $confirmed_attendees = $db->query('SELECT COUNT(*) FROM attendees WHERE attended = 1')->fetchColumn();
    
    // Upcoming events
    $upcoming_events = $db->query("
        SELECT e.*, 
               COUNT(a.id) as registered_attendees
        FROM events e
        LEFT JOIN attendees a ON e.id = a.event_id
        WHERE e.date >= DATE('now')
        GROUP BY e.id
        ORDER BY e.date ASC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // Recent events
    $recent_events = $db->query("
        SELECT e.*, 
               COUNT(a.id) as total_attendees,
               SUM(CASE WHEN a.attended = 1 THEN 1 ELSE 0 END) as confirmed_attendees
        FROM events e
        LEFT JOIN attendees a ON e.id = a.event_id
        WHERE e.date < DATE('now')
        GROUP BY e.id
        ORDER BY e.date DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    $report_data = [
        'type' => 'overview',
        'stats' => [
            'total_events' => $total_events,
            'total_attendees' => $total_attendees,
            'confirmed_attendees' => $confirmed_attendees,
            'attendance_rate' => $total_attendees > 0 ? round(($confirmed_attendees / $total_attendees) * 100, 1) : 0
        ],
        'upcoming_events' => $upcoming_events,
        'recent_events' => $recent_events
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Reports & Analytics | HUGO DOMINGO LTD</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
    
    /* Main Content - align with other pages */
    .main-content {
      flex: 1;
      margin-left: 220px;
      padding: 1rem 1.5rem;
      width: calc(100% - 220px);
      min-height: 100vh;
    }
    
    /* Top Bar - copied from Super Dashboard for consistent header */
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
    
    /* Company Header - use head.png as full banner */
    .company-header {
      background: url('top.png') center center no-repeat;
      background-size: cover;
      border-radius: 12px;
      margin-bottom: 2rem;
      height: 180px;
      overflow: hidden;
    }
    
    /* Report Header */
    .report-header {
      background: linear-gradient(135deg, #3b82f6, #8b5cf6);
      color: white;
      padding: 1.5rem 2rem;
      border-radius: 12px;
      margin-bottom: 2rem;
      text-align: center;
    }
    
    .report-title {
      font-size: 1.8rem;
      font-weight: 700;
      margin-bottom: 0.5rem;
    }
    
    .report-subtitle {
      opacity: 0.9;
      font-size: 1.1rem;
    }
    
    .report-meta {
      display: flex;
      justify-content: center;
      gap: 2rem;
      margin-top: 1.5rem;
      font-size: 0.9rem;
      opacity: 0.8;
    }
    
    /* Stats Grid */
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 1.5rem;
      margin-bottom: 2rem;
    }
    
    .stat-card {
      background: white;
      border-radius: 10px;
      padding: 1.5rem;
      box-shadow: 0 4px 6px rgba(0,0,0,0.1);
      text-align: center;
      border-left: 4px solid #3b82f6;
      transition: transform 0.2s, box-shadow 0.2s;
    }
    
    .stat-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 15px rgba(0,0,0,0.15);
    }
    
    .stat-card.success {
      border-left-color: #10b981;
    }
    
    .stat-card.warning {
      border-left-color: #f59e0b;
    }
    
    .stat-card.danger {
      border-left-color: #ef4444;
    }
    
    .stat-value {
      font-size: 2.5rem;
      font-weight: 800;
      margin-bottom: 0.5rem;
      background: linear-gradient(135deg, #3b82f6, #8b5cf6);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
    }
    
    .stat-card.success .stat-value {
      background: linear-gradient(135deg, #10b981, #059669);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
    }
    
    .stat-card.warning .stat-value {
      background: linear-gradient(135deg, #f59e0b, #d97706);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
    }
    
    .stat-card.danger .stat-value {
      background: linear-gradient(135deg, #ef4444, #dc2626);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
    }
    
    .stat-label {
      color: #64748b;
      font-size: 0.875rem;
      margin-bottom: 0.5rem;
      font-weight: 600;
    }
    
    .stat-change {
      font-size: 0.75rem;
      font-weight: 600;
    }
    
    .stat-change.positive {
      color: #10b981;
    }
    
    .stat-change.negative {
      color: #ef4444;
    }
    
    /* Chart Container */
    .chart-container {
      background: white;
      border-radius: 12px;
      padding: 1.5rem;
      margin-bottom: 1.5rem;
      box-shadow: 0 1px 3px rgba(0,0,0,0.1);
      height: 400px;
    }
    
    /* Table Styles */
    .table-container {
      overflow-x: auto;
      border-radius: 8px;
      box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }
    
    .data-table {
      width: 100%;
      border-collapse: collapse;
    }
    
    .data-table th,
    .data-table td {
      padding: 0.6rem 0.8rem;
      border-bottom: 1px solid #e2e8f0;
      font-size: 0.8rem;
      text-align: left;
    }
    
    .data-table th {
      background-color: #f8fafc;
      font-weight: 600;
      color: #475569;
      white-space: nowrap;
    }
    
    .data-table tr:hover {
      background-color: #f8fafc;
    }
    
    .badge {
      display: inline-flex;
      align-items: center;
      padding: 0.25rem 0.75rem;
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
    
    /* Report Filters */
    .report-filters {
      background: white;
      border-radius: 12px;
      padding: 1.5rem;
      margin-bottom: 1.5rem;
      box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }
    
    .filter-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 1rem;
    }
    
    .form-group {
      margin-bottom: 1rem;
    }
    
    .form-label {
      display: block;
      margin-bottom: 0.5rem;
      font-weight: 500;
      color: #374151;
    }
    
    .form-select {
      width: 100%;
      padding: 0.75rem;
      border: 1px solid #d1d5db;
      border-radius: 6px;
      font-size: 0.875rem;
      background-color: white;
      transition: border-color 0.2s;
    }
    
    .form-select:focus {
      outline: none;
      border-color: #3b82f6;
      box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }
    
    .financial-column {
      /* used to control visibility of financial data on print for non-super admins */
    }
    
    /* Print Styles */
    @media print {
      @page {
        size: A4 portrait;
        margin: 8mm;
      }

      body {
        background: #ffffff !important;
      }

      body * {
        visibility: hidden !important;
      }

      .print-area, .print-area * {
        visibility: visible !important;
      }

      .print-area {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
      }

      .main-content {
        margin-left: 0;
        width: 100%;
        padding: 0;
      }
      
      .sidebar, .top-bar, .btn, .report-filters {
        display: none !important;
      }
      
      
      .content-section {
        box-shadow: none;
        border: 1px solid #e2e8f0;
        page-break-inside: auto;
        margin: 0 0 8px 0;
        padding: 8px 12px;
      }
      
      /* Ensure header image prints at top of report */
      .company-header {
        background: url('top.png') center center no-repeat !important;
        background-size: cover !important;
        -webkit-print-color-adjust: exact;
        height: 90px;
        margin-bottom: 8px;
        border-radius: 0;
      }
      
      .report-header {
        background: #3b82f6 !important;
        -webkit-print-color-adjust: exact;
        padding: 8px 12px;
        margin-bottom: 8px;
      }
      
      /* Tighter table layout so all columns fit on page */
      .table-container {
        box-shadow: none;
      }
      
      .data-table {
        font-size: 9px;
        table-layout: fixed;
        width: 100%;
      }

      .table-container {
        overflow: visible !important;
      }
      
      .data-table th,
      .data-table td {
        padding: 2px 4px;
        word-wrap: break-word;
        word-break: break-word;
      }

      .data-table tr {
        page-break-inside: avoid;
      }
      
      .data-table th {
        background: #3b82f6 !important;
        color: #ffffff !important;
        -webkit-print-color-adjust: exact;
      }

      thead {
        display: table-header-group;
      }

      tfoot {
        display: table-footer-group;
      }
    }
    
    <?php if (!$isSuper): ?>
      @media print {
        .financial-column {
          display: none !important;
        }
      }
    <?php endif; ?>
    
    /* Mobile Responsive */
    @media (max-width: 1024px) {
      .main-content {
        margin-left: 0;
        width: 100%;
        padding: 1rem;
      }
      
      .company-name {
        font-size: 2rem;
      }
      
      .company-tagline {
        font-size: 1rem;
      }
    }
    
    @media (max-width: 768px) {
      .stats-grid {
        grid-template-columns: repeat(2, 1fr);
      }
      
      .report-meta, .company-contact {
        flex-direction: column;
        gap: 0.5rem;
      }
      
      .company-name {
        font-size: 1.8rem;
      }
      
      .top-bar {
        flex-direction: column;
        align-items: flex-start;
        gap: 1rem;
      }
      
      .user-menu {
        width: 100%;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 0.75rem;
      }
      
      .section-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.75rem;
      }
      
      .section-actions {
        width: 100%;
        justify-content: flex-start;
        flex-wrap: wrap;
        gap: 0.5rem;
      }
    }
    
    @media (max-width: 640px) {
      .main-content {
        margin-left: 0;
        width: 100%;
        padding: 0.75rem;
      }
      
      .stats-grid {
        grid-template-columns: 1fr;
      }
      
      .company-name {
        font-size: 1.5rem;
      }
      
      .company-tagline {
        font-size: 0.9rem;
      }
      
      .content-section,
      .report-filters,
      .chart-container {
        padding: 1rem;
        margin-bottom: 1rem;
      }
      
      .chart-container {
        height: 260px;
      }
      
      .data-table {
        font-size: 0.75rem;
      }
      
      .data-table th,
      .data-table td {
        padding: 0.4rem 0.5rem;
      }
    }
    
    .company-logo {
      width: 100px;
      height: 100px;
      margin: 1rem auto;
      display: block;
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
          <h1>Reports & Analytics</h1>
          <p>Event reports and analytics</p>
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
      
      <!-- Report Filters -->
      <div class="report-filters">
        <form method="GET" class="filter-grid">
          <div class="form-group">
            <label class="form-label">Report Type</label>
            <select name="type" class="form-select" onchange="this.form.submit()">
              <option value="overview" <?php echo $report_type === 'overview' ? 'selected' : ''; ?>>Overview</option>
              <option value="event" <?php echo $report_type === 'event' ? 'selected' : ''; ?>>Event Report</option>
              <option value="weekly" <?php echo $report_type === 'weekly' ? 'selected' : ''; ?>>Weekly Report</option>
              <option value="monthly" <?php echo $report_type === 'monthly' ? 'selected' : ''; ?>>Monthly Report</option>
              <?php if ($isSuper): ?>
                <option value="yearly" <?php echo $report_type === 'yearly' ? 'selected' : ''; ?>>Yearly Report</option>
              <?php endif; ?>
            </select>
          </div>
          
          <?php if ($report_type === 'event'): ?>
            <div class="form-group">
              <label class="form-label">Select Event</label>
              <select name="event_id" class="form-select" onchange="this.form.submit()">
                <option value="">Choose an event</option>
                <?php foreach ($events as $ev): ?>
                  <option value="<?php echo (int)$ev['id']; ?>" <?php echo $event_id === (int)$ev['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($ev['name']); ?> (<?php echo $ev['date']; ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php elseif ($report_type === 'weekly'): ?>
            <div class="form-group">
              <label class="form-label">Week Start</label>
              <input type="date" name="week_start" value="<?php echo htmlspecialchars($week_start); ?>" class="form-select" onchange="this.form.submit()">
            </div>
          <?php elseif ($report_type === 'monthly'): ?>
            <div class="form-group">
              <label class="form-label">Select Month</label>
              <input type="month" name="month" value="<?php echo $month; ?>" class="form-select" onchange="this.form.submit()">
            </div>
          <?php elseif ($report_type === 'yearly'): ?>
            <div class="form-group">
              <label class="form-label">Select Year</label>
              <select name="year" class="form-select" onchange="this.form.submit()">
                <?php for ($y = date('Y'); $y >= 2020; $y--): ?>
                  <option value="<?php echo $y; ?>" <?php echo $year == $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
                <?php endfor; ?>
              </select>
            </div>
          <?php endif; ?>
          
          <div class="form-group" style="display: flex; align-items: end; gap: 0.75rem;">
            <?php if ($report_type === 'yearly' && !$isSuper): ?>
              <span style="font-size: 0.8rem; color: #ef4444; font-weight: 500;">
                Yearly report printing is restricted to Super Administrator.
              </span>
            <?php else: ?>
              <button type="button" class="btn btn-secondary" onclick="window.print()">
                <i class="fas fa-print mr-2"></i> Print
              </button>
            <?php endif; ?>
          </div>
        </form>
      </div>

      <div class="print-area">
      <!-- Company Header - full-width image -->
      <div class="company-header"></div>
      <!-- Report Content -->
      <?php if ($report_type === 'overview'): ?>
        <!-- Overview Report (no event tables shown) -->
      <?php elseif ($report_type === 'event' && $event_id > 0): ?>
        <!-- Event-specific Report (stats cards removed) -->
        <div class="content-section">
          <div class="section-header">
            <h2 class="section-title">Event Details</h2>
          </div>
          <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
            <div><strong>Event Name:</strong> <?php echo htmlspecialchars($report_data['event']['name']); ?></div>
            <div><strong>Date:</strong> <?php echo htmlspecialchars($report_data['event']['date']); ?></div>
            <div><strong>Location:</strong> <?php echo htmlspecialchars($report_data['event']['location']); ?></div>
            <div><strong>Client:</strong> <?php echo htmlspecialchars($report_data['event']['client']); ?></div>
            <div><strong>Coordinator:</strong> <?php echo htmlspecialchars($report_data['event']['coordinator']); ?></div>
            <div><strong>Supervisor:</strong> <?php echo htmlspecialchars($report_data['event']['supervisor'] ?? ''); ?></div>
            <div><strong>Remark:</strong> <?php echo htmlspecialchars($report_data['event']['remarks'] ?? ''); ?></div>
          </div>
        </div>
        
      <?php elseif ($report_type === 'weekly'): ?>
        <!-- Weekly Report: show only the events breakdown table -->
        <div class="content-section">
          <div class="section-header">
            <h2 class="section-title">Weekly Events Breakdown</h2>
          </div>
          <div class="table-container">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Event</th>
                  <th>Client</th>
                  <th>Location</th>
                  <th>Coordinator</th>
                  <th>Graphics</th>
                  <th>Supervisor</th>
                  <?php if ($isSuper): ?>
                    <th class="financial-column">Amount Details</th>
                  <?php endif; ?>
                  <th>Remark</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($report_data['events'] as $event): ?>
                  <tr>
                    <td>
                      <?php
                        $s = $event['date'] ?? '';
                        $e = $event['end_date'] ?? '';
                        if ($s === '') {
                          echo 'Not set';
                        } elseif ($e && $e !== $s) {
                          echo htmlspecialchars($s) . ' 		 										 				 				 										 											 										 											 										 				';
                          echo ' 										';
                          echo htmlspecialchars($e);
                        } else {
                          echo htmlspecialchars($s);
                        }
                      ?>
                    </td>
                    <td><?php echo htmlspecialchars($event['name']); ?></td>
                    <td><?php echo htmlspecialchars($event['client'] ?? 'N/A'); ?></td>
                    <td><?php echo htmlspecialchars($event['location'] ?? 'N/A'); ?></td>
                    <td><?php echo htmlspecialchars($event['coordinator'] ?? 'N/A'); ?></td>
                    <td><?php echo !empty($event['graphics']) ? htmlspecialchars($event['graphics']) : 'N/A'; ?></td>
                    <td><?php echo !empty($event['supervisor']) ? htmlspecialchars($event['supervisor']) : 'N/A'; ?></td>
                    <?php if ($isSuper): ?>
                    <td class="financial-column">
                      <?php 
                        $amt = isset($event['amount']) && $event['amount'] !== '' ? (float)$event['amount'] : 0;
                        $adv = isset($event['advance']) && $event['advance'] !== '' ? (float)$event['advance'] : 0;
                        $bal = isset($event['balance']) && $event['balance'] !== '' ? (float)$event['balance'] : ($amt - $adv);
                      ?>
                      <div><?php echo 'Tsh ' . number_format($amt, 0, '.', ','); ?></div>
                      <div style="font-size:0.8rem;">
                        <span style="color:#16a34a;">Adv: Tsh <?php echo number_format($adv, 0, '.', ','); ?></span>
                        <span style="color:#2563eb;"> | Bal: Tsh <?php echo number_format($bal, 0, '.', ','); ?></span>
                      </div>
                    </td>
                    <?php endif; ?>
                    <td><?php echo htmlspecialchars($event['remarks'] ?? 'N/A'); ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
        
      <?php elseif ($report_type === 'monthly'): ?>
        <!-- Monthly Office Report: only events breakdown table -->
        <div class="content-section">
          <div class="section-header">
            <h2 class="section-title">Monthly Events Breakdown</h2>
          </div>
          <div class="table-container">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Event</th>
                  <th>Client</th>
                  <th>Location</th>
                  <th>Coordinator</th>
                  <th>Graphics</th>
                  <th>Supervisor</th>
                  <?php if ($isSuper): ?>
                    <th class="financial-column">Amount Details</th>
                  <?php endif; ?>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($report_data['events'] as $event): ?>
                  <tr>
                    <td>
                      <?php
                        $s = $event['date'] ?? '';
                        $e = $event['end_date'] ?? '';
                        if ($s === '') {
                          echo 'Not set';
                        } elseif ($e && $e !== $s) {
                          echo htmlspecialchars($s) . ' 		 										 				 				 										 											 										 											 										 				';
                          echo ' 										';
                          echo htmlspecialchars($e);
                        } else {
                          echo htmlspecialchars($s);
                        }
                      ?>
                    </td>
                    <td><?php echo htmlspecialchars($event['name']); ?></td>
                    <td><?php echo htmlspecialchars($event['client'] ?? 'N/A'); ?></td>
                    <td><?php echo htmlspecialchars($event['location'] ?? 'N/A'); ?></td>
                    <td><?php echo htmlspecialchars($event['coordinator'] ?? 'N/A'); ?></td>
                    <td><?php echo !empty($event['graphics']) ? htmlspecialchars($event['graphics']) : 'N/A'; ?></td>
                    <td><?php echo !empty($event['supervisor']) ? htmlspecialchars($event['supervisor']) : 'N/A'; ?></td>
                    <?php if ($isSuper): ?>
                    <td class="financial-column">
                      <?php 
                        $amt = isset($event['amount']) && $event['amount'] !== '' ? (float)$event['amount'] : 0;
                        $adv = isset($event['advance']) && $event['advance'] !== '' ? (float)$event['advance'] : 0;
                        $bal = isset($event['balance']) && $event['balance'] !== '' ? (float)$event['balance'] : ($amt - $adv);
                      ?>
                      <div><?php echo 'Tsh ' . number_format($amt, 0, '.', ','); ?></div>
                      <div style="font-size:0.8rem;">
                        <span style="color:#16a34a;">Adv: Tsh <?php echo number_format($adv, 0, '.', ','); ?></span>
                        <span style="color:#2563eb;"> | Bal: Tsh <?php echo number_format($bal, 0, '.', ','); ?></span>
                      </div>
                    </td>
                    <?php endif; ?>
                    <td><?php echo htmlspecialchars($event['status'] ?? 'N/A'); ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
        
      <?php elseif ($report_type === 'yearly'): ?>
        <!-- Yearly Financial Summary -->
        <div class="content-section">
          <div class="section-header">
            <h2 class="section-title">Monthly Financial Summary</h2>
          </div>
          <div class="table-container">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Month</th>
                  <th>Total Events</th>
                  <th class="financial-column">Total Amount</th>
                  <th class="financial-column">Total Advance</th>
                  <th class="financial-column">Total Balance</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($report_data['monthly_breakdown'] as $month): ?>
                  <tr>
                    <td><?php echo htmlspecialchars($month['month']); ?></td>
                    <td><?php echo (int)$month['total_events']; ?></td>
                    <td class="financial-column">Tsh <?php echo number_format($month['total_amount'], 0, '.', ','); ?></td>
                    <td class="financial-column">Tsh <?php echo number_format($month['total_advance'], 0, '.', ','); ?></td>
                    <td class="financial-column">Tsh <?php echo number_format($month['total_balance'], 0, '.', ','); ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endif; ?>

      </div>
      
      
    </main>
  </div>

  <script>
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
    
    // PDF Download function
    function downloadPDF() {
      alert('PDF export feature would be implemented here. For now, please use the Print function and save as PDF.');
      // In a real implementation, you would use a library like jsPDF or make a server request
      // window.location.href = 'generate_pdf.php?type=<?php echo $report_type; ?>&event_id=<?php echo $event_id; ?>&month=<?php echo $month; ?>&year=<?php echo $year; ?>';
    }
    
    // Initialize charts
    document.addEventListener('DOMContentLoaded', function() {
      <?php if ($report_type === 'monthly'): ?>
        // Monthly events chart
        const ctx = document.getElementById('monthlyChart');
        if (ctx) {
          new Chart(ctx, {
            type: 'bar',
            data: {
              labels: ['Events', 'Attendees', 'Confirmed'],
              datasets: [{
                label: 'Current Month',
                data: [
                  <?php echo $report_data['stats']['total_events']; ?>,
                  <?php echo $report_data['stats']['total_attendees']; ?>,
                  <?php echo $report_data['stats']['confirmed_attendees']; ?>
                ],
                backgroundColor: [
                  'rgba(59, 130, 246, 0.8)',
                  'rgba(16, 185, 129, 0.8)',
                  'rgba(245, 158, 11, 0.8)'
                ]
              }]
            },
            options: {
              responsive: true,
              plugins: {
                title: {
                  display: true,
                  text: 'Monthly Performance Overview'
                }
              }
            }
          });
        }
      <?php endif; ?>
    });
  </script>
</body>
</html>