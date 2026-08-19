<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';

if (isset($_GET['logout'])) {
  logout_user();
  header('Location: login.php');
  exit;
}

require_login();
$user = current_user();
$role = $user['role'] ?? 'user';

$db = get_db();
$q = trim($_GET['q'] ?? '');
$action = trim($_GET['action'] ?? '');
$limit = max(10, min(200, (int)($_GET['limit'] ?? 100)));

$where = [];
$params = [];

// Visibility rules:
// - Super admin: see all activities
// - Normal admin: only own activities
if ($role !== 'super') {
  $where[] = 'a.user_id = ?';
  $params[] = $user['id'];
}
if ($q !== '') {
  $where[] = "(u.name LIKE ? OR u.email LIKE ? OR a.details LIKE ?)";
  $like = '%' . $q . '%';
  array_push($params, $like, $like, $like);
}
if ($action !== '') {
  $where[] = "a.action = ?";
  $params[] = $action;
}
$sql = "SELECT a.id, a.user_id, a.action, a.details, a.created_at,
               u.name AS user_name, u.email AS user_email, u.role AS user_role
        FROM activities a
        LEFT JOIN users u ON u.id = a.user_id
        " . (count($where) ? ("WHERE " . implode(' AND ', $where)) : '') . "
        ORDER BY a.created_at DESC, a.id DESC
        LIMIT " . (int)$limit;
$rows = $db->prepare($sql);
$rows->execute($params);
$activities = $rows->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Recent Activities | EventPro</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    :root {
      --primary: #3b82f6;
      --secondary: #8b5cf6;
      --success: #10b981;
      --warning: #f59e0b;
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
      padding: 1.5rem 1rem;
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
      padding: 0.75rem;
      cursor: pointer;
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
      flex-wrap: nowrap;
      gap: 0.75rem;
    }
    
    .page-title h1 {
      font-size: clamp(1.5rem, 4vw, 1.75rem);
      font-weight: 700;
      color: #1e293b;
      line-height: 1.2;
    }
    
    .page-title p {
      color: #64748b;
      font-size: clamp(0.8rem, 2vw, 0.9rem);
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
      font-size: 0.875rem;
      white-space: nowrap; /* prevent date from breaking into two lines */
    }
    
    .user-profile {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      position: relative;
      cursor: pointer;
      flex-shrink: 0;
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
      flex-shrink: 0;
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
    
    .profile-dropdown a {
      display: block;
      padding: 0.75rem 1rem;
      border-radius: 6px;
      color: #475569;
      text-decoration: none;
      transition: all 0.2s;
      font-size: 0.875rem;
    }
    
    .profile-dropdown a:hover {
      background-color: #f1f5f9;
      color: #3b82f6;
    }
    
    /* Content Sections */
    .content-section {
      background: white;
      border-radius: 12px;
      padding: clamp(1rem, 3vw, 1.5rem);
      margin-bottom: 1.5rem;
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
      gap: 1rem;
      width: 100%;
    }
    
    .section-title {
      font-size: clamp(1.1rem, 3vw, 1.25rem);
      font-weight: 600;
      color: #1e293b;
      line-height: 1.3;
    }
    
    .section-actions {
      display: flex;
      gap: 0.75rem;
      align-items: center;
      flex-wrap: wrap;
    }
    
    /* Form Styles */
    .filter-form {
      display: flex;
      gap: 0.75rem;
      align-items: flex-end;
      flex-wrap: wrap;
    }
    
    .filter-group {
      display: flex;
      flex-direction: column;
      gap: 0.5rem;
      min-width: 200px;
      flex: 1;
    }
    
    .filter-label {
      font-size: 0.875rem;
      font-weight: 500;
      color: #374151;
    }
    
    .form-input, .form-select {
      padding: 0.75rem;
      border: 1px solid #d1d5db;
      border-radius: 8px;
      font-size: 0.875rem;
      transition: all 0.2s;
      width: 100%;
    }
    
    .form-input:focus, .form-select:focus {
      outline: none;
      border-color: #3b82f6;
      box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }
    
    .btn {
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      padding: 0.75rem 1.5rem;
      border-radius: 8px;
      font-weight: 500;
      text-decoration: none;
      transition: all 0.2s;
      cursor: pointer;
      border: none;
      font-size: 0.875rem;
      white-space: nowrap;
      height: fit-content;
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
    
    /* Table Styles */
    .table-container {
      overflow-x: auto;
      border-radius: 8px;
      border: 1px solid #e2e8f0;
      width: 100%;
      -webkit-overflow-scrolling: touch;
    }
    
    .data-table {
      width: 100%;
      border-collapse: collapse;
      min-width: 800px;
    }
    
    .data-table th {
      text-align: left;
      padding: 1rem;
      background: #f8fafc;
      border-bottom: 1px solid #e2e8f0;
      font-size: 0.875rem;
      color: #475569;
      font-weight: 600;
      white-space: nowrap;
    }
    
    .data-table td {
      padding: 1rem;
      border-bottom: 1px solid #f1f5f9;
      font-size: 0.875rem;
      vertical-align: top;
    }
    
    .data-table tr:hover td {
      background-color: #f8fafc;
    }
    
    .data-table tr:last-child td {
      border-bottom: none;
    }
    
    .badge {
      display: inline-block;
      padding: 0.25rem 0.75rem;
      border-radius: 9999px;
      font-size: 0.75rem;
      font-weight: 500;
      white-space: nowrap;
    }
    
    .badge-primary { background: #dbeafe; color: #1e40af; }
    .badge-success { background: #d1fae5; color: #065f46; }
    .badge-warning { background: #fef3c7; color: #92400e; }
    .badge-danger { background: #fee2e2; color: #991b1b; }
    
    .details-cell {
      font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;
      font-size: 0.85rem;
      color: #334155;
      max-width: 400px;
      word-break: break-word;
    }
    
    /* Activity Card View (Mobile) */
    .activity-cards {
      display: none;
      flex-direction: column;
      gap: 1rem;
    }
    
    .activity-card {
      background: white;
      border: 1px solid #e2e8f0;
      border-radius: 12px;
      padding: 1.25rem;
      display: flex;
      flex-direction: column;
      gap: 1rem;
    }
    
    .activity-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 1rem;
    }
    
    .activity-time {
      font-size: 0.875rem;
      color: #64748b;
      white-space: nowrap;
    }
    
    .activity-user {
      font-weight: 600;
      color: #1e293b;
    }
    
    .activity-role {
      font-size: 0.75rem;
      color: #64748b;
      margin-top: 0.25rem;
    }
    
    .activity-action {
      margin-top: 0.5rem;
    }
    
    .activity-details {
      background: #f8fafc;
      padding: 1rem;
      border-radius: 8px;
      font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;
      font-size: 0.85rem;
      color: #334155;
      word-break: break-word;
    }
    
    /* Mobile Responsive - delegate sidebar behavior to shared sidebar.php */
    @media (max-width: 1024px) {
      .main-content {
        margin-left: 0;
        width: 100%;
        padding: 1rem;
      }
      
      .filter-group {
        min-width: 180px;
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
      
      .filter-form {
        flex-direction: column;
        align-items: stretch;
        gap: 1rem;
      }
      
      .filter-group {
        min-width: 100%;
      }
      
      .data-table {
        display: none;
      }
      
      .activity-cards {
        display: flex;
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
      
      /* Header layout on mobile: title, then row with date left and user right */
      .top-bar {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.75rem;
      }
      
      .user-menu {
        display: flex;
        flex-direction: row;
        width: 100%;
        justify-content: space-between;
        align-items: center;
        gap: 0.5rem;
      }
      
      .date-display {
        padding: 0.5rem;
        font-size: 0.8rem;
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
      
      .activity-card {
        padding: 1rem;
      }
      
      .activity-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.5rem;
      }
    }
    
    @media (max-width: 360px) {
      .main-content {
        padding: 0.25rem;
        padding-top: 3.5rem;
      }
      
      .activity-card {
        padding: 0.75rem;
      }
    }
    
    /* Print Styles */
    @media print {
      .sidebar, .mobile-menu-btn, .section-actions, .profile-dropdown, .filter-form {
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
    
    <!-- Main Content -->
    <main class="main-content">
      <!-- Top Bar -->
      <div class="top-bar">
        <div class="page-title">
          <h1>Recent Activities</h1>
          <p>Tracking actions by normal admins</p>
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
                $initial = function_exists('mb_substr') ? mb_substr($displayName, 0, 1, 'UTF-8') : substr($displayName, 0, 1);
                echo htmlspecialchars(strtoupper($initial)); 
              ?>
            </div>
            <div>
              <div style="font-weight:600;"><?php echo htmlspecialchars($displayName); ?></div>
              <div style="font-size:0.75rem; color:#64748b;">Super Admin</div>
            </div>
            <i class="fas fa-chevron-down ml-2" style="color:#64748b;"></i>
            
            <div class="profile-dropdown" id="profileDropdown">
              <a href="profile.php"><i class="fas fa-user mr-2"></i> My Profile</a>
              <a href="settings.php"><i class="fas fa-cog mr-2"></i> Settings</a>
              <a href="?logout"><i class="fas fa-sign-out-alt mr-2"></i> Logout</a>
            </div>
          </div>
        </div>
      </div>

      <!-- Filters Section -->
      <div class="content-section">
        <div class="section-header">
          <h2 class="section-title">Filters</h2>
          <a href="activities.php" class="btn btn-secondary">
            <i class="fas fa-times mr-2"></i>Clear
          </a>
        </div>
        <form method="get" class="filter-form">
          <div class="filter-group">
            <label class="filter-label">Search</label>
            <input type="text" name="q" class="form-input" placeholder="Search by name, email or details" value="<?php echo htmlspecialchars($q); ?>">
          </div>
          <div class="filter-group">
            <label class="filter-label">Action Type</label>
            <select name="action" class="form-select">
              <option value="">All actions</option>
              <?php 
                $actions = ['event_create','event_update','event_delete','event_import'];
                foreach ($actions as $a) {
                  $sel = $action === $a ? 'selected' : '';
                  echo '<option value="'.htmlspecialchars($a).'" '.$sel.'>'.htmlspecialchars(str_replace('_', ' ', $a)).'</option>';
                }
              ?>
            </select>
          </div>
          <div class="filter-group">
            <label class="filter-label">Results Limit</label>
            <select name="limit" class="form-select">
              <?php foreach ([50,100,150,200] as $opt): ?>
                <option value="<?php echo $opt; ?>" <?php echo ($limit===$opt)?'selected':''; ?>>Show <?php echo $opt; ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-filter mr-2"></i>Apply Filters
          </button>
        </form>
      </div>

      <!-- Activities Section -->
      <div class="content-section">
        <div class="section-header">
          <h2 class="section-title">Recent Activity</h2>
          <span style="font-size:0.875rem; color:#64748b;"><?php echo count($activities); ?> activities found</span>
        </div>

        <!-- Table View (Desktop) -->
        <div class="table-container">
          <table class="data-table">
            <thead>
              <tr>
                <th>When</th>
                <th>User</th>
                <th>Role</th>
                <th>Action</th>
                <th>Details</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$activities): ?>
                <tr>
                  <td colspan="5" style="padding:2rem; text-align:center; color:#64748b;">
                    <i class="fas fa-inbox" style="font-size:2rem; margin-bottom:1rem; opacity:0.5;"></i>
                    <div>No activities found matching your criteria.</div>
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($activities as $a): ?>
                  <tr>
                    <td style="white-space:nowrap;">
                      <?php echo htmlspecialchars($a['created_at']); ?>
                    </td>
                    <td>
                      <div style="font-weight:500;"><?php echo htmlspecialchars($a['user_name'] ?: $a['user_email'] ?: 'System'); ?></div>
                    </td>
                    <td>
                      <span style="font-size:0.75rem; color:#64748b;">
                        <?php echo htmlspecialchars($a['user_role'] ?: 'unknown'); ?>
                      </span>
                    </td>
                    <td>
                      <span class="badge badge-primary">
                        <?php echo htmlspecialchars(str_replace('_', ' ', $a['action'])); ?>
                      </span>
                    </td>
                    <td class="details-cell">
                      <?php
                        $rawDetails = $a['details'] ?? '';
                        $displayDetails = '';

                        if (is_string($rawDetails) && trim($rawDetails) !== '') {
                          $decoded = json_decode($rawDetails, true);
                          if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                            if (!empty($decoded['name']) && is_string($decoded['name'])) {
                              $displayDetails = $decoded['name'];
                            } else {
                              unset($decoded['id']);
                              $parts = [];
                              foreach ($decoded as $k => $v) {
                                if ($v === '' || $v === null) continue;
                                if (is_scalar($v)) { $parts[] = $v; }
                              }
                              // If there are no non-id fields, leave $displayDetails empty so we fall back to the action label
                              if (!empty($parts)) {
                                $displayDetails = implode(' | ', $parts);
                              }
                            }
                          } else {
                            $displayDetails = $rawDetails;
                          }
                        }

                        if ($displayDetails === '') {
                          $displayDetails = $a['action'] ?? 'activity';
                        }

                        echo htmlspecialchars($displayDetails);
                      ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <!-- Card View (Mobile) -->
        <div class="activity-cards">
          <?php if (!$activities): ?>
            <div style="text-align:center; color:#64748b; padding:3rem;">
              <i class="fas fa-inbox" style="font-size:3rem; margin-bottom:1rem; opacity:0.5;"></i>
              <div>No activities found matching your criteria.</div>
            </div>
          <?php else: ?>
            <?php foreach ($activities as $a): ?>
              <div class="activity-card">
                <div class="activity-header">
                  <div style="flex:1;">
                    <div class="activity-user">
                      <?php echo htmlspecialchars($a['user_name'] ?: $a['user_email'] ?: 'System'); ?>
                    </div>
                    <div class="activity-role">
                      <?php echo htmlspecialchars($a['user_role'] ?: 'unknown'); ?>
                    </div>
                    <div class="activity-action">
                      <span class="badge badge-primary">
                        <?php echo htmlspecialchars(str_replace('_', ' ', $a['action'])); ?>
                      </span>
                    </div>
                  </div>
                  <div class="activity-time">
                    <?php echo htmlspecialchars($a['created_at']); ?>
                  </div>
                </div>
                <?php if (!empty($a['details'])): ?>
                  <div class="activity-details">
                    <?php
                      $rawDetails = $a['details'] ?? '';
                      $displayDetails = '';

                      if (is_string($rawDetails) && trim($rawDetails) !== '') {
                        $decoded = json_decode($rawDetails, true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                          if (!empty($decoded['name']) && is_string($decoded['name'])) {
                            $displayDetails = $decoded['name'];
                          } else {
                            unset($decoded['id']);
                            $parts = [];
                            foreach ($decoded as $k => $v) {
                              if ($v === '' || $v === null) continue;
                              if (is_scalar($v)) { $parts[] = "$k: $v"; }
                            }
                            // If there are no non-id fields, leave $displayDetails empty so we fall back to the action label
                            if (!empty($parts)) {
                              $displayDetails = implode(' | ', $parts);
                            }
                          }
                        } else {
                          $displayDetails = $rawDetails;
                        }
                      }

                      if ($displayDetails === '') {
                        $displayDetails = $a['action'] ?? 'activity';
                      }

                      echo htmlspecialchars($displayDetails);
                    ?>
                  </div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
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

    // Close mobile menu when clicking on a link
    document.querySelectorAll('.nav-item').forEach(item => {
      item.addEventListener('click', () => {
        sidebar.classList.remove('mobile-open');
      });
    });

    // Handle window resize
    window.addEventListener('resize', function() {
      if (window.innerWidth > 768) {
        sidebar.classList.remove('mobile-open');
      }
    });

    // Toggle profile dropdown
    if (!window.__profileDropdownBound) {
      const userProfile = document.getElementById('userProfile');
      if (userProfile) {
        userProfile.addEventListener('click', function() {
          document.getElementById('profileDropdown').classList.toggle('show');
        });
      }

      // Close dropdown when clicking outside
      document.addEventListener('click', function(event) {
        const profile = document.getElementById('userProfile');
        const dropdown = document.getElementById('profileDropdown');
        if (profile && dropdown && !profile.contains(event.target)) {
          dropdown.classList.remove('show');
        }
      });
    }
  </script>
</body>
</html>