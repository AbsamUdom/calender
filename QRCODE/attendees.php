<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/i18n.php';
if (isset($_GET['logout'])) {
    logout_user();
    header('Location: login.php');
    exit;
}
require_login();
$db = get_db();
$error = '';
$info = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_attendee'])) {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $event_id = (int)($_POST['event_id'] ?? 0);
        $company = trim($_POST['company'] ?? '');
        $position = trim($_POST['position'] ?? '');
        
        if ($name === '' || $event_id === 0) {
            $error = t('name') . ' and event are required';
        } else {
            $invite = create_token();
            $auth = create_token();
            $stmt = $db->prepare('INSERT INTO attendees(name,email,phone,company,position,token,event_id,invite_token,auth_token) VALUES (?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$name, $email, $phone, $company, $position, $invite, $event_id, $invite, $auth]);
            $info = 'Attendee added successfully!';
        }
    } elseif (isset($_POST['bulk_import'])) {
        // Handle CSV import
        if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
            $csvFile = $_FILES['csv_file']['tmp_name'];
            $imported = 0;
            $errors = [];
            
            if (($handle = fopen($csvFile, 'r')) !== FALSE) {
                // Skip header row
                fgetcsv($handle);
                
                while (($data = fgetcsv($handle, 1000, ',')) !== FALSE) {
                    if (count($data) >= 3) {
                        $name = trim($data[0]);
                        $email = trim($data[1]);
                        $phone = trim($data[2]);
                        $company = trim($data[3] ?? '');
                        $position = trim($data[4] ?? '');
                        $event_id = (int)($data[5] ?? $_POST['import_event_id'] ?? 0);
                        
                        if ($name !== '' && $event_id > 0) {
                            $invite = create_token();
                            $auth = create_token();
                            $stmt = $db->prepare('INSERT INTO attendees(name,email,phone,company,position,token,event_id,invite_token,auth_token) VALUES (?,?,?,?,?,?,?,?,?)');
                            $stmt->execute([$name, $email, $phone, $company, $position, $invite, $event_id, $invite, $auth]);
                            $imported++;
                        }
                    }
                }
                fclose($handle);
                $info = "Successfully imported $imported attendees!";
            }
        } else {
            $error = 'Please select a valid CSV file';
        }
    } elseif (isset($_POST['delete_attendee'])) {
        $attendee_id = (int)($_POST['attendee_id'] ?? 0);
        if ($attendee_id > 0) {
            $stmt = $db->prepare('DELETE FROM attendees WHERE id = ?');
            $stmt->execute([$attendee_id]);
            $info = 'Attendee deleted successfully!';
        }
    }
}

// Load data
$events = $db->query('SELECT * FROM events ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all'; // all|confirmed|pending
$event_filter = isset($_GET['event']) ? (int)$_GET['event'] : 0;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build query for attendees
$query = 'SELECT a.*, e.name as event_name, e.date as event_date FROM attendees a LEFT JOIN events e ON a.event_id = e.id WHERE 1=1';
$params = [];

if ($event_filter > 0) {
    $query .= ' AND a.event_id = ?';
    $params[] = $event_filter;
}

if ($search !== '') {
    $query .= ' AND (a.name LIKE ? OR a.email LIKE ? OR a.company LIKE ?)';
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if ($filter === 'confirmed') {
    $query .= ' AND a.attended = 1';
} elseif ($filter === 'pending') {
    $query .= ' AND a.attended = 0';
}

$query .= ' ORDER BY a.id DESC';

$stmt = $db->prepare($query);
$stmt->execute($params);
$attendees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get counts for stats
$totalAttendees = (int)$db->query('SELECT COUNT(*) FROM attendees')->fetchColumn();
$confirmedAttendees = (int)$db->query('SELECT COUNT(*) FROM attendees WHERE attended = 1')->fetchColumn();
$pendingAttendees = $totalAttendees - $confirmedAttendees;
$todayAttendees = (int)$db->query("SELECT COUNT(*) FROM attendees WHERE DATE(created_at) = DATE('now')")->fetchColumn();

$user = current_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Attendee Management | EventPro</title>
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
    
    /* Form Styles */
    .form-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
      gap: 1.5rem;
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
    
    .form-input {
      width: 100%;
      padding: 0.75rem;
      border: 1px solid #d1d5db;
      border-radius: 6px;
      font-size: 0.875rem;
      transition: all 0.2s;
    }
    
    .form-input:focus {
      outline: none;
      border-color: #3b82f6;
      box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }
    
    .form-select {
      width: 100%;
      padding: 0.75rem;
      border: 1px solid #d1d5db;
      border-radius: 6px;
      font-size: 0.875rem;
      background-color: white;
      transition: all 0.2s;
    }
    
    .form-select:focus {
      outline: none;
      border-color: #3b82f6;
      box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }
    
    /* Alert Styles */
    .alert {
      padding: 1rem;
      border-radius: 8px;
      margin-bottom: 1.5rem;
    }
    
    .alert-error {
      background-color: #fef2f2;
      border: 1px solid #fecaca;
      color: #dc2626;
    }
    
    .alert-success {
      background-color: #f0fdf4;
      border: 1px solid #bbf7d0;
      color: #16a34a;
    }
    
    /* Table Styles */
    .table-container {
      overflow-x: auto;
    }
    
    .data-table {
      width: 100%;
      border-collapse: collapse;
    }
    
    .data-table th {
      background-color: #f8fafc;
      padding: 0.75rem 1rem;
      text-align: left;
      font-weight: 600;
      color: #475569;
      border-bottom: 1px solid #e2e8f0;
      font-size: 0.875rem;
    }
    
    .data-table td {
      padding: 1rem;
      border-bottom: 1px solid #f1f5f9;
      font-size: 0.875rem;
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
    
    .badge-danger {
      background-color: #fecaca;
      color: #991b1b;
    }
    
    /* Stats Cards */
    .stats-cards {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 1rem;
      margin-bottom: 1.5rem;
    }
    
    .stat-card {
      background: white;
      border-radius: 10px;
      padding: 1.25rem;
      box-shadow: 0 1px 3px rgba(0,0,0,0.1);
      text-align: center;
      transition: transform 0.2s;
    }
    
    .stat-card:hover {
      transform: translateY(-2px);
    }
    
    .stat-value {
      font-size: 1.875rem;
      font-weight: 700;
      margin-bottom: 0.25rem;
    }
    
    .stat-label {
      color: #64748b;
      font-size: 0.875rem;
    }
    
    /* Filter Tabs */
    .filter-tabs {
      display: flex;
      gap: 0.5rem;
      margin-bottom: 1.5rem;
      border-bottom: 1px solid #e2e8f0;
      padding-bottom: 1rem;
    }
    
    .filter-tab {
      padding: 0.5rem 1rem;
      border-radius: 6px;
      text-decoration: none;
      color: #64748b;
      font-weight: 500;
      transition: all 0.2s;
    }
    
    .filter-tab:hover {
      background-color: #f1f5f9;
      color: #374151;
    }
    
    .filter-tab.active {
      background-color: #3b82f6;
      color: white;
    }
    
    /* Search and Filter */
    .search-filter {
      display: flex;
      gap: 1rem;
      margin-bottom: 1.5rem;
      flex-wrap: wrap;
    }
    
    .search-box {
      flex: 1;
      min-width: 250px;
      position: relative;
    }
    
    .search-input {
      width: 100%;
      padding: 0.75rem 1rem 0.75rem 2.5rem;
      border: 1px solid #d1d5db;
      border-radius: 6px;
      font-size: 0.875rem;
    }
    
    .search-icon {
      position: absolute;
      left: 0.75rem;
      top: 50%;
      transform: translateY(-50%);
      color: #6b7280;
    }
    
    /* Modal Styles */
    .modal {
      display: none;
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0, 0, 0, 0.5);
      z-index: 1000;
      align-items: center;
      justify-content: center;
    }
    
    .modal.show {
      display: flex;
    }
    
    .modal-content {
      background: white;
      border-radius: 12px;
      padding: 2rem;
      max-width: 500px;
      width: 90%;
      max-height: 90vh;
      overflow-y: auto;
    }
    
    .modal-header {
      display: flex;
      justify-content: between;
      align-items: center;
      margin-bottom: 1.5rem;
    }
    
    .modal-close {
      background: none;
      border: none;
      font-size: 1.5rem;
      cursor: pointer;
      color: #6b7280;
    }
    
    /* Mobile Responsive */
    @media (max-width: 1024px) {
      .sidebar {
        width: 80px;
        padding: 1rem 0.5rem;
      }
      
      .brand div, .nav-item span, .sidebar-footer {
        display: none;
      }
      
      .brand {
        justify-content: center;
        padding: 0 0 1rem;
      }
      
      .main-content {
        margin-left: 80px;
        width: calc(100% - 80px);
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
      
      .stats-cards {
        grid-template-columns: repeat(2, 1fr);
      }
      
      .search-filter {
        flex-direction: column;
      }
    }
    
    @media (max-width: 640px) {
      .sidebar {
        display: none;
      }
      
      .main-content {
        margin-left: 0;
        width: 100%;
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
      
      .stats-cards {
        grid-template-columns: 1fr;
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
          <h1>Attendee Management</h1>
          <p>Manage event attendees, registrations, and check-ins</p>
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
      
      <!-- Alerts -->
      <?php if ($error): ?>
        <div class="alert alert-error">
          <i class="fas fa-exclamation-circle mr-2"></i>
          <?php echo htmlspecialchars($error); ?>
        </div>
      <?php endif; ?>
      
      <?php if ($info): ?>
        <div class="alert alert-success">
          <i class="fas fa-check-circle mr-2"></i>
          <?php echo htmlspecialchars($info); ?>
        </div>
      <?php endif; ?>
      
      <!-- Stats Cards -->
      <div class="stats-cards">
        <div class="stat-card">
          <div class="stat-value"><?php echo $totalAttendees; ?></div>
          <div class="stat-label">Total Attendees</div>
        </div>
        <div class="stat-card">
          <div class="stat-value text-green-600"><?php echo $confirmedAttendees; ?></div>
          <div class="stat-label">Confirmed</div>
        </div>
        <div class="stat-card">
          <div class="stat-value text-amber-600"><?php echo $pendingAttendees; ?></div>
          <div class="stat-label">Pending</div>
        </div>
        <div class="stat-card">
          <div class="stat-value text-blue-600"><?php echo $todayAttendees; ?></div>
          <div class="stat-label">Registered Today</div>
        </div>
      </div>
      
      <!-- Quick Actions -->
      <div class="content-section">
        <div class="section-header">
          <h2 class="section-title">Quick Actions</h2>
          <div class="section-actions">
            <button class="btn btn-primary" onclick="toggleModal('importModal')">
              <i class="fas fa-file-import mr-2"></i>
              Import CSV
            </button>
            <button class="btn btn-success" onclick="toggleModal('addModal')">
              <i class="fas fa-user-plus mr-2"></i>
              Add Attendee
            </button>
          </div>
        </div>
        
        <!-- Search and Filter -->
        <div class="search-filter">
          <div class="search-box">
            <i class="fas fa-search search-icon"></i>
            <form method="GET" class="flex gap-2">
              <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                     class="search-input" placeholder="Search attendees...">
              <button type="submit" class="btn btn-primary">Search</button>
              <?php if ($search): ?>
                <a href="attendees.php" class="btn btn-secondary">Clear</a>
              <?php endif; ?>
            </form>
          </div>
          
          <select name="event" class="form-select" onchange="window.location.href='attendees.php?event='+this.value" style="max-width: 200px;">
            <option value="0">All Events</option>
            <?php foreach ($events as $ev): ?>
              <option value="<?php echo (int)$ev['id']; ?>" <?php echo $event_filter === (int)$ev['id'] ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($ev['name']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        
        <!-- Filter Tabs -->
        <div class="filter-tabs">
          <a href="attendees.php?<?php echo $event_filter ? "event=$event_filter&" : ''; ?>filter=all" 
             class="filter-tab <?php echo $filter === 'all' ? 'active' : ''; ?>">
            All (<?php echo $totalAttendees; ?>)
          </a>
          <a href="attendees.php?<?php echo $event_filter ? "event=$event_filter&" : ''; ?>filter=confirmed" 
             class="filter-tab <?php echo $filter === 'confirmed' ? 'active' : ''; ?>">
            Confirmed (<?php echo $confirmedAttendees; ?>)
          </a>
          <a href="attendees.php?<?php echo $event_filter ? "event=$event_filter&" : ''; ?>filter=pending" 
             class="filter-tab <?php echo $filter === 'pending' ? 'active' : ''; ?>">
            Pending (<?php echo $pendingAttendees; ?>)
          </a>
        </div>
        
        <!-- Attendees Table -->
        <div class="table-container">
          <table class="data-table">
            <thead>
              <tr>
                <th>Attendee</th>
                <th>Contact</th>
                <th>Event</th>
                <th>Status</th>
                <th>Registration</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($attendees as $a): ?>
                <tr>
                  <td>
                    <div class="font-medium"><?php echo htmlspecialchars($a['name']); ?></div>
                    <?php if (!empty($a['company'])): ?>
                      <div class="text-sm text-gray-600"><?php echo htmlspecialchars($a['company']); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($a['position'])): ?>
                      <div class="text-xs text-gray-500"><?php echo htmlspecialchars($a['position']); ?></div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if (!empty($a['email'])): ?>
                      <div class="text-sm"><?php echo htmlspecialchars($a['email']); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($a['phone'])): ?>
                      <div class="text-sm text-gray-600"><?php echo htmlspecialchars($a['phone']); ?></div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <div class="text-sm font-medium"><?php echo htmlspecialchars($a['event_name'] ?? 'N/A'); ?></div>
                    <?php if (!empty($a['event_date'])): ?>
                      <div class="text-xs text-gray-500"><?php echo date('M j, Y', strtotime($a['event_date'])); ?></div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if ((int)$a['attended']): ?>
                      <span class="badge badge-success">
                        <i class="fas fa-check-circle mr-1"></i> Confirmed
                      </span>
                    <?php else: ?>
                      <span class="badge badge-warning">
                        <i class="fas fa-clock mr-1"></i> Pending
                      </span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <div class="text-sm"><?php echo date('M j, Y', strtotime($a['created_at'] ?? 'now')); ?></div>
                    <?php if (!empty($a['attended_at'])): ?>
                      <div class="text-xs text-gray-500">Confirmed: <?php echo date('M j, Y', strtotime($a['attended_at'])); ?></div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <div class="flex flex-wrap gap-1">
                      <a href="<?php echo invite_url($a['invite_token'] ?: $a['token']); ?>" 
                         class="btn btn-secondary btn-sm" target="_blank" title="Invitation Link">
                        <i class="fas fa-envelope"></i>
                      </a>
                      <a href="<?php echo auth_url($a['auth_token'] ?: $a['token']); ?>" 
                         class="btn btn-warning btn-sm" target="_blank" title="Authorization Link">
                        <i class="fas fa-user-check"></i>
                      </a>
                      
                      <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this attendee?')">
                        <input type="hidden" name="delete_attendee" value="1">
                        <input type="hidden" name="attendee_id" value="<?php echo (int)$a['id']; ?>">
                        <button type="submit" class="btn btn-danger btn-sm" title="Delete Attendee">
                          <i class="fas fa-trash"></i>
                        </button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (empty($attendees)): ?>
                <tr>
                  <td colspan="6" class="text-center py-8 text-gray-500">
                    <i class="fas fa-users text-3xl mb-2 block"></i>
                    No attendees found.
                    <?php if ($search || $event_filter > 0 || $filter !== 'all'): ?>
                      <div class="mt-2">
                        <a href="attendees.php" class="btn btn-primary btn-sm">Clear Filters</a>
                      </div>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </main>
  </div>

  <!-- Add Attendee Modal -->
  <div class="modal" id="addModal">
    <div class="modal-content">
      <div class="modal-header">
        <h3 class="section-title">Add New Attendee</h3>
        <button class="modal-close" onclick="toggleModal('addModal')">&times;</button>
      </div>
      <form method="post">
        <input type="hidden" name="add_attendee" value="1">
        <div class="form-grid">
          <div class="form-group">
            <label class="form-label">Full Name *</label>
            <input type="text" name="name" class="form-input" placeholder="Enter full name" required>
          </div>
          <div class="form-group">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-input" placeholder="attendee@example.com">
          </div>
          <div class="form-group">
            <label class="form-label">Phone</label>
            <input type="text" name="phone" class="form-input" placeholder="Phone number">
          </div>
          <div class="form-group">
            <label class="form-label">Company</label>
            <input type="text" name="company" class="form-input" placeholder="Company name">
          </div>
          <div class="form-group">
            <label class="form-label">Position</label>
            <input type="text" name="position" class="form-input" placeholder="Job position">
          </div>
          <div class="form-group">
            <label class="form-label">Event *</label>
            <select name="event_id" class="form-select" required>
              <option value="">Select an event</option>
              <?php foreach ($events as $ev): ?>
                <option value="<?php echo (int)$ev['id']; ?>">
                  <?php echo htmlspecialchars($ev['name']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="flex gap-2 mt-4">
          <button type="submit" class="btn btn-success">
            <i class="fas fa-save mr-2"></i>
            Save Attendee
          </button>
          <button type="button" class="btn btn-secondary" onclick="toggleModal('addModal')">
            Cancel
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Import CSV Modal -->
  <div class="modal" id="importModal">
    <div class="modal-content">
      <div class="modal-header">
        <h3 class="section-title">Import Attendees from CSV</h3>
        <button class="modal-close" onclick="toggleModal('importModal')">&times;</button>
      </div>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="bulk_import" value="1">
        <div class="form-group">
          <label class="form-label">Select Event</label>
          <select name="import_event_id" class="form-select" required>
            <option value="">Select an event</option>
            <?php foreach ($events as $ev): ?>
              <option value="<?php echo (int)$ev['id']; ?>">
                <?php echo htmlspecialchars($ev['name']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">CSV File</label>
          <input type="file" name="csv_file" class="form-input" accept=".csv" required>
          <div class="text-sm text-gray-600 mt-1">
            CSV format: Name, Email, Phone, Company, Position, Event ID (optional)
          </div>
        </div>
        <div class="bg-blue-50 p-3 rounded-lg mb-4">
          <h4 class="font-medium mb-2">Sample CSV Format:</h4>
          <pre class="text-sm bg-white p-2 rounded">John Doe,john@example.com,123-456-7890,Acme Inc,Manager
Jane Smith,jane@example.com,098-765-4321,XYZ Corp,Developer</pre>
        </div>
        <div class="flex gap-2">
          <button type="submit" class="btn btn-success">
            <i class="fas fa-upload mr-2"></i>
            Import CSV
          </button>
          <button type="button" class="btn btn-secondary" onclick="toggleModal('importModal')">
            Cancel
          </button>
        </div>
      </form>
    </div>
  </div>

  <script>
    // Toggle modals
    function toggleModal(modalId) {
      const modal = document.getElementById(modalId);
      modal.classList.toggle('show');
    }
    
    // Close modals when clicking outside
    document.addEventListener('click', function(event) {
      if (event.target.classList.contains('modal')) {
        event.target.classList.remove('show');
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
  </script>
</body>
</html>