<?php
// Shared sidebar include. Determines active nav item based on current script name and query.
require_once __DIR__ . '/auth.php';
$me = current_user();
$role = $me['role'] ?? 'user';
$roleNorm = strtolower(trim((string)($role ?? '')));
$roleNorm = str_replace(['_', '-'], ' ', $roleNorm);
$roleNorm = preg_replace('/\s+/', ' ', $roleNorm);
$current = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));
$query = $_GET ?? [];
$canSeeReports = in_array($roleNorm, ['sales', 'admin', 'super'], true);

$homeDashboard = function_exists('get_dashboard_for_role') ? get_dashboard_for_role($role) : 'index.php';
$roleLabel = function_exists('get_role_label') ? get_role_label($role) : ucfirst((string)$role);

function is_active($file, $alsoQueryKey = null, $alsoQueryValue = null) {
    $curr = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));
    if ($alsoQueryKey !== null) {
        $val = $_GET[$alsoQueryKey] ?? null;
        return ($curr === $file) && ($val === $alsoQueryValue);
    }
    return $curr === $file;
}
?>
<style>
  /* Sidebar Styles */
  #appSidebar {
    background: linear-gradient(180deg, #1e293b 0%, #0f172a 100%);
    color: #fff;
    width: 280px;
    height: 100vh;
    position: fixed;
    left: 0;
    top: 0;
    padding: 1rem 0;
    display: flex;
    flex-direction: column;
    z-index: 100;
    box-shadow: 2px 0 10px rgba(0,0,0,0.1);
  }
  
  .sidebar-logo {
    padding: 1.75rem 1.5rem;
    border-bottom: 1px solid rgba(255,255,255,0.12);
    margin-bottom: 0.75rem;
  }
  
  .sidebar-logo h2 {
    color: #fff;
    font-size: 1.9rem;
    font-weight: 800;
    margin: 0;
    letter-spacing: 0.08em;
  }

  .sidebar-logo p {
    margin: 0.35rem 0 0;
    font-size: 1.05rem;
    color: rgba(255,255,255,0.75);
    font-weight: 500;
  }

  .sidebar-brand {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .sidebar-brand img {
    position: absolute;
    left: 0;
    top: 50%;
    transform: translateY(-50%);
    width: 40px;
    height: 40px;
    object-fit: cover;
  }

  .sidebar-brand > div {
    text-align: center;
  }
  
  .nav-menu {
    flex: 1;
    overflow-y: auto;
    padding: 0 0.5rem;
    scrollbar-width: thin;
    scrollbar-color: rgba(148, 163, 184, 0.6) transparent;
  }
  .nav-menu::-webkit-scrollbar {
    width: 6px;
  }
  .nav-menu::-webkit-scrollbar-track {
    background: transparent;
  }
  .nav-menu::-webkit-scrollbar-thumb {
    background: rgba(148, 163, 184, 0.55);
    border-radius: 999px;
  }
  .nav-menu::-webkit-scrollbar-thumb:hover {
    background: rgba(148, 163, 184, 0.75);
  }
  .nav-menu::-webkit-scrollbar-button {
    width: 0;
    height: 0;
    display: none;
  }
  
  .nav-section {
    margin-bottom: 1.5rem;
  }
  
  .nav-section-title {
    display: none;
  }
  
  .nav-link {
    display: flex;
    align-items: center;
    padding: 1.05rem 1.25rem;
    color: rgba(255,255,255,0.8);
    border-radius: 14px;
    margin: 0.35rem 0.75rem;
    transition: all 0.2s ease;
    text-decoration: none;
    font-size: 0.95rem;
    font-weight: 500;
  }
  
  .nav-link:hover,
  .nav-link.active {
    background: rgba(255,255,255,0.08);
    color: #fff;
  }
  
  .nav-link i {
    margin-right: 0.75rem;
    width: 24px;
    text-align: center;
    font-size: 1.05rem;
    opacity: 0.95;
  }
  
  .nav-link .badge {
    margin-left: auto;
    background: rgba(255,255,255,0.2);
    color: #fff;
    font-size: 0.7rem;
    padding: 0.2rem 0.5rem;
    border-radius: 10px;
  }
  
  #appSidebar .sidebar-footer {
    padding: 1.25rem 1rem;
    border-top: 1px solid rgba(255,255,255,0.12);
    margin-top: auto;
    text-align: center;
    opacity: 0.65;
    font-size: 0.95rem;
    line-height: 1.6;
  }
  
  .user-profile {
    display: flex;
    align-items: center;
    padding: 0.75rem 1rem;
    background: rgba(255,255,255,0.1);
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s ease;
  }
  
  .user-profile:hover {
    background: rgba(255,255,255,0.15);
  }
  
  .user-avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: #4caf50;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 0.75rem;
    font-weight: 600;
    font-size: 0.9rem;
  }
  
  .user-info {
    flex: 1;
  }
  
  .user-name {
    color: #fff;
    font-weight: 500;
    font-size: 0.9rem;
    margin-bottom: 0.1rem;
  }
  
  .user-role {
    color: rgba(255,255,255,0.7);
    font-size: 0.75rem;
  }
  
  /* Mobile Sidebar Styles */
  @media (max-width: 640px) {
    #mobileSidebarToggle { 
      position: fixed; 
      top: 12px; 
      left: 12px; 
      z-index: 130; 
      background: #1f2937; 
      color: #fff; 
      border: none; 
      padding: 10px 12px; 
      border-radius: 8px; 
      box-shadow: 0 2px 6px rgba(0,0,0,0.2);
      display: flex;
      align-items: center;
      justify-content: center;
      width: 44px;
      height: 44px;
      font-size: 1.2rem;
      cursor: pointer;
      transition: all 0.3s ease;
    }
    
    /* Shift page header/content so it doesn't hide behind the toggle button */
    .top-bar,
    .page-title {
      padding-left: 2.4rem;
    }
    
    #mobileSidebarToggle:hover {
      background: #374151;
      transform: scale(1.05);
    }
    
    /* Sidebar container */
    #appSidebar { 
      display: flex !important; 
      flex-direction: column;
      transform: translateX(-100%); 
      transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      width: 280px;
      height: 100vh;
      z-index: 120;
      box-shadow: 2px 0 10px rgba(0,0,0,0.1);
    }
    
    .sidebar-open #appSidebar { 
      transform: translateX(0); 
    }
    
    /* Main content on mobile - no margin since sidebar is hidden */
    .main-content {
      margin-left: 0 !important;
      width: 100% !important;
      padding-top: 0.75rem !important;
    }
    
    /* Backdrop overlay */
    #sidebarBackdrop { 
      display: none; 
      position: fixed; 
      inset: 0; 
      background: rgba(0,0,0,0.5); 
      z-index: 110; 
      backdrop-filter: blur(2px);
      animation: fadeIn 0.3s ease;
    }
    
    .sidebar-open #sidebarBackdrop { 
      display: block; 
    }
    
    /* Prevent body scroll when sidebar is open */
    .sidebar-open {
      overflow: hidden;
    }

    /* Stick footer to bottom inside sidebar on mobile */
    #appSidebar .sidebar-footer {
      margin-top: auto;
    }
    
    /* Animation for backdrop */
    @keyframes fadeIn {
      from { opacity: 0; }
      to { opacity: 1; }
    }
    
    /* Close button inside sidebar for mobile */
    .sidebar-close-btn {
      display: none;
      position: absolute;
      top: 15px;
      right: 15px;
      background: rgba(255,255,255,0.1);
      border: none;
      color: white;
      border-radius: 50%;
      width: 32px;
      height: 32px;
      font-size: 1rem;
      cursor: pointer;
      align-items: center;
      justify-content: center;
      transition: all 0.2s ease;
    }
    
    .sidebar-close-btn:hover {
      background: rgba(255,255,255,0.2);
      transform: rotate(90deg);
    }
    
    @media (max-width: 640px) {
      .sidebar-close-btn {
        display: flex;
      }
    }
  }
  
  /* Desktop styles */
  @media (min-width: 641px) {
    #mobileSidebarToggle {
      display: none !important;
    }
    
    #sidebarBackdrop {
      display: none !important;
    }
    
    .sidebar-close-btn {
      display: none !important;
    }
    
    /* Ensure sidebar has fixed width on desktop */
    .sidebar, #appSidebar {
      width: 260px !important;
      min-width: 260px;
      max-width: 260px;
    }
  }

  /* Compact theme overrides applied globally */
  html { font-size: 14px; }
  body { 
    font-size: 0.9rem; 
    margin: 0;
    padding: 0;
  }
  .main-content { 
    margin-left: 260px; 
    padding: 0 1.5rem 1rem 1.5rem; 
    width: calc(100% - 260px);
    min-height: 100vh;
    background: #f1f5f9;
  }

  /* Header / Top Bar – keep here so dashboards don't rely on style.css */
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
    margin: 0;
  }

  .user-menu {
    display: flex;
    align-items: center;
    gap: 1rem;
    flex-wrap: wrap;
  }

  .date-display {
    background: #ffffff;
    padding: 0.5rem 1rem;
    border-radius: 8px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    font-weight: 500;
    font-size: 0.8rem;
    white-space: nowrap;
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
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
    color: #fff;
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
    background: #ffffff;
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

  .content-section { padding: 1rem; margin-bottom: 1rem; }
  .section-title { font-size: 1rem; }
  .btn { padding: 0.35rem 0.75rem; font-size: 0.8rem; }
  .btn-sm { padding: 0.2rem 0.5rem; font-size: 0.7rem; }
  .form-input, .form-select { padding: 0.5rem; font-size: 0.8rem; }
  .form-label { margin-bottom: 0.35rem; }
  /* Capitalize option labels only for specific selects */
  select[name="ev_status"] option,
  select[name="ev_client_status"] option,
  select[name="ev_efd"] option { text-transform: capitalize; }
  .data-table th { padding: 0.5rem 0.75rem; font-size: 0.8rem; }
  .data-table td { padding: 0.6rem 0.75rem; font-size: 0.8rem; }
  .stats-grid { gap: 1rem; }
  .stat-card { padding: 1rem; }
  .stat-value { font-size: 1.25rem; }
  .status-count { font-size: 1.5rem; }
  .action-card { padding: 1rem; }
  .action-icon { width: 40px; height: 40px; font-size: 1rem; }
  .horizontal-table th, .horizontal-table td { padding: 0.6rem; }
  /* Ensure images have no border or shadow effects */
  img { border: 0 !important; outline: 0 !important; box-shadow: none !important; }
  .brand img { border-radius: 0 !important; }
  
  /* Sidebar Styles */
  .sidebar {
    width: 260px;
    min-width: 260px;
    max-width: 260px;
    background: linear-gradient(180deg, #1e293b 0%, #0f172a 100%);
    color: white;
    padding: 1rem 0.75rem;
    display: flex;
    flex-direction: column;
    position: fixed;
    left: 0;
    top: 0;
    height: 100vh;
    overflow-y: auto;
    z-index: 1000;
  }
  
  .brand {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 0.75rem 1rem;
    margin-bottom: 1rem;
    border-bottom: 1px solid rgba(255,255,255,0.1);
    padding-bottom: 1.25rem;
  }
  
  .brand .brand-title {
    font-weight: 700;
    font-size: 1.25rem;
    color: #fff;
  }
  
  .brand .brand-sub {
    font-size: 0.85rem;
    opacity: 0.7;
    color: #fff;
  }
  
  .sidebar nav,
  .sidebar .nav-menu {
    display: flex;
    flex-direction: column;
    gap: 4px;
    margin-top: 1rem;
    padding: 0 0.5rem;
    flex: 1;
  }
  
  .sidebar a,
  .sidebar .nav-item {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 0.85rem 1.25rem;
    border-radius: 8px;
    color: rgba(255,255,255,0.9);
    text-decoration: none;
    transition: all 0.2s ease;
    font-size: 0.95rem;
  }
  
  .sidebar a i,
  .sidebar .nav-item i {
    font-size: 1rem;
    width: 22px;
    text-align: center;
    opacity: 0.9;
  }
  
  .sidebar a:hover,
  .sidebar .nav-item:hover {
    background: rgba(255,255,255,0.1);
    color: #fff;
  }
  
  .sidebar a.active,
  .sidebar .nav-item.active {
    background: #32CD32;
    color: #000;
    font-weight: 600;
  }
  
  aside.sidebar .sidebar-footer {
    margin-top: auto;
    font-size: 0.75rem;
    opacity: 0.5;
    color: #fff;
    text-align: center;
    padding: 1rem 0.5rem;
    line-height: 1.5;
  }
</style>

<!-- Mobile Menu Toggle Button -->
<button id="mobileSidebarToggle" aria-label="Menu" class="md:hidden">
  <i class="fas fa-bars"></i>
</button>

<!-- Sidebar Backdrop (Mobile) -->
<div id="sidebarBackdrop"></div>

<!-- Sidebar -->
<aside id="appSidebar" class="md:flex">
  <!-- Logo -->
  <div class="sidebar-logo">
    <div class="sidebar-brand">
      <img src="Logo.png" alt="Logo">
      <div>
        <h2>EVENT</h2>
        <p>Management System</p>
      </div>
    </div>
  </div>
  
  <!-- Navigation Menu -->
  <nav class="nav-menu">
    <div class="nav-section">
      <div class="nav-section-title">Main</div>
      <a href="<?php echo htmlspecialchars($homeDashboard); ?>" class="nav-link <?php echo is_active(basename($homeDashboard)) ? 'active' : ''; ?>">
        <i class="fas fa-tachometer-alt"></i>
        <span>Dashboard</span>
      </a>
      <a href="events.php" class="nav-link <?php echo is_active('events.php') ? 'active' : ''; ?>">
        <i class="fas fa-calendar-alt"></i>
        <span>Events</span>
      </a>
      <?php if (in_array(strtolower((string)$role), ['finance', 'admin', 'super'], true)): ?>
      <a href="finance_quotes.php" class="nav-link <?php echo is_active('finance_quotes.php') ? 'active' : ''; ?>">
        <i class="fas fa-file-invoice-dollar"></i>
        <span>Finance Quotes</span>
      </a>
      <a href="finance_release_orders.php" class="nav-link <?php echo is_active('finance_release_orders.php') ? 'active' : ''; ?>">
        <i class="fas fa-file-signature"></i>
        <span>Finance Release Orders</span>
      </a>
      <a href="finance_purchase_requests.php" class="nav-link <?php echo is_active('finance_purchase_requests.php') ? 'active' : ''; ?>">
        <i class="fas fa-cart-shopping"></i>
        <span>Finance Purchase Requests</span>
      </a>
      <?php endif; ?>
      <?php if (in_array(strtolower((string)$role), ['accountant', 'operation', 'store', 'admin', 'super'], true)): ?>
      <a href="release_orders.php" class="nav-link <?php echo is_active('release_orders.php') ? 'active' : ''; ?>">
        <i class="fas fa-file-signature"></i>
        <span>Release Orders</span>
      </a>
      <?php endif; ?>
      <?php if (in_array(strtolower((string)$role), ['accountant', 'production', 'operation', 'admin', 'super'], true)): ?>
      <a href="purchase_requests.php" class="nav-link <?php echo is_active('purchase_requests.php') ? 'active' : ''; ?>">
        <i class="fas fa-cart-shopping"></i>
        <span>Purchase Requests</span>
      </a>
      <?php endif; ?>

      <?php if (in_array(strtolower((string)$role), ['accountant', 'finance', 'admin', 'super'], true)): ?>
      <a href="rentals.php" class="nav-link <?php echo is_active('rentals.php') ? 'active' : ''; ?>">
        <i class="fas fa-truck-ramp-box"></i>
        <span>Rentals</span>
      </a>
      <?php endif; ?>

      <?php if (in_array(strtolower((string)$role), ['finance', 'admin', 'super'], true)): ?>
      <a href="finance_rentals_approved.php" class="nav-link <?php echo is_active('finance_rentals_approved.php') ? 'active' : ''; ?>">
        <i class="fas fa-circle-check"></i>
        <span>Approved Rentals</span>
      </a>
      <?php endif; ?>

      <?php if (in_array(strtolower((string)$role), ['operation', 'admin', 'super'], true)): ?>
      <a href="job_forms.php" class="nav-link <?php echo is_active('job_forms.php') ? 'active' : ''; ?>">
        <i class="fas fa-clipboard-list"></i>
        <span>Job Forms</span>
      </a>
      <?php endif; ?>

      <?php if (in_array(strtolower((string)$role), ['sales', 'admin', 'super'], true)): ?>
      <a href="coordinator_job_forms.php" class="nav-link <?php echo is_active('coordinator_job_forms.php') ? 'active' : ''; ?>">
        <i class="fas fa-clipboard-check"></i>
        <span>Job Forms Approval</span>
      </a>
      <?php endif; ?>

      <?php if (in_array(strtolower((string)$role), ['finance', 'admin', 'super'], true)): ?>
      <a href="finance_job_forms.php" class="nav-link <?php echo is_active('finance_job_forms.php') ? 'active' : ''; ?>">
        <i class="fas fa-clipboard-check"></i>
        <span>Finance Job Forms</span>
      </a>
      <?php endif; ?>
      <?php if (in_array(strtolower((string)$role), ['operation', 'admin', 'super'], true)): ?>
      <a href="register_supervisor.php" class="nav-link <?php echo is_active('register_supervisor.php') ? 'active' : ''; ?>">
        <i class="fas fa-user-plus"></i>
        <span>Request Supervisor</span>
      </a>
      <?php endif; ?>
      <?php if (in_array(strtolower((string)$role), ['production', 'admin', 'super'], true)): ?>
      <a href="production_queue.php" class="nav-link <?php echo is_active('production_queue.php') ? 'active' : ''; ?>">
        <i class="fas fa-industry"></i>
        <span>Production Queue</span>
      </a>
      <a href="printing_queue.php" class="nav-link <?php echo is_active('printing_queue.php') ? 'active' : ''; ?>">
        <i class="fas fa-print"></i>
        <span>Printing Queue</span>
      </a>
      <?php endif; ?>
      <?php if (in_array(strtolower((string)$role), ['store'], true)): ?>
      <a href="purchase_requests.php" class="nav-link <?php echo is_active('purchase_requests.php') ? 'active' : ''; ?>">
        <i class="fas fa-cart-shopping"></i>
        <span>Purchase Requests</span>
      </a>
      <?php endif; ?>
      <?php if (in_array($roleNorm, ['sales', 'admin', 'super'], true)): ?>
      <a href="quote_status.php" class="nav-link <?php echo is_active('quote_status.php') ? 'active' : ''; ?>">
        <i class="fas fa-file-invoice"></i>
        <span>Quote Status</span>
      </a>
      <?php endif; ?>
      <?php if (in_array($roleNorm, ['sales', 'graphic', 'graphics', 'graphic designer', 'admin', 'super'], true)): ?>
      <a href="print_tasks.php" class="nav-link <?php echo is_active('print_tasks.php') ? 'active' : ''; ?>">
        <i class="fas fa-print"></i>
        <span>Print Tasks</span>
      </a>
      <?php endif; ?>

      <?php if (in_array($roleNorm, ['graphic', 'graphics', 'graphic designer', 'admin', 'super'], true)): ?>
      <a href="graphic_tasks.php" class="nav-link <?php echo is_active('graphic_tasks.php') ? 'active' : ''; ?>">
        <i class="fas fa-list-check"></i>
        <span>Graphic Tasks</span>
      </a>
      <?php endif; ?>
      <a href="calendar.php" class="nav-link <?php echo is_active('calendar.php') ? 'active' : ''; ?>">
        <i class="fas fa-calendar"></i>
        <span>Calendar</span>
      </a>
      <a href="activities.php" class="nav-link <?php echo is_active('activities.php') ? 'active' : ''; ?>">
        <i class="fas fa-clock-rotate-left"></i>
        <span>Activities</span>
      </a>
    </div>
    
    <?php if ($canSeeReports): ?>
    <div class="nav-section">
      <div class="nav-section-title">Management</div>
      <a href="reports.php" class="nav-link <?php echo is_active('reports.php') ? 'active' : ''; ?>">
        <i class="fas fa-chart-line"></i>
        <span>Reports</span>
      </a>
      <?php if (in_array($roleNorm, ['admin', 'super'], true)): ?>
      <a href="users.php" class="nav-link <?php echo is_active('users.php') ? 'active' : ''; ?>">
        <i class="fas fa-users"></i>
        <span>Users</span>
      </a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </nav>

  <div class="sidebar-footer">
    Version 2.0<br>Event Management System
  </div>
</aside>

<?php if (false): ?>

<!-- Sidebar -->
<aside class="sidebar" id="appSidebar" style="background: linear-gradient(180deg, #1e293b 0%, #0f172a 100%); color: white;">
  <!-- Close button for mobile -->
  <button class="sidebar-close-btn" aria-label="Close menu">
    <i class="fas fa-times"></i>
  </button>
  
  <div class="brand">
    <img src="Logo.png" alt="EventPro" style="width:48px; height:48px; object-fit:cover;">
    <div>
      <div class="brand-title">EVENT</div>
      <div class="brand-sub">Management System</div>
    </div>
  </div>
  
  <nav class="nav-menu">
    <?php if ($role === 'super'): ?>
      <a href="super_dashboard.php" class="nav-item <?php echo is_active('super_dashboard.php') ? 'active' : '';?>">
        <i class="fas fa-chart-pie"></i>
        <span>Dashboard</span>
      </a>
      <a href="super_events.php" class="nav-item <?php echo is_active('super_events.php') ? 'active' : '';?>">
        <i class="fas fa-list"></i>
        <span>Events</span>
      </a>
      <a href="calendar.php" class="nav-item <?php echo is_active('calendar.php') ? 'active' : '';?>">
        <i class="fas fa-calendar-alt"></i>
        <span>Calendar</span>
      </a>
      <a href="activities.php" class="nav-item <?php echo is_active('activities.php') ? 'active' : '';?>">
        <i class="fas fa-clock-rotate-left"></i>
        <span>Activities</span>
      </a>
      <a href="users.php" class="nav-item <?php echo is_active('users.php') ? 'active' : '';?>">
        <i class="fas fa-users"></i>
        <span>Users</span>
      </a>
      <a href="reports.php" class="nav-item <?php echo (is_active('reports.php') && (!isset($query['type']) || $query['type'] === 'overview')) ? 'active' : '';?>">
        <i class="fas fa-chart-bar"></i>
        <span>Reports</span>
      </a>
    <?php else: ?>
      <a href="<?php echo htmlspecialchars($homeDashboard); ?>" class="nav-item <?php echo is_active(basename($homeDashboard)) ? 'active' : '';?>">
        <i class="fas fa-chart-pie"></i>
        <span>Dashboard</span>
      </a>
      <a href="events.php" class="nav-item <?php echo is_active('events.php') ? 'active' : '';?>">
        <i class="fas fa-list"></i>
        <span>Events</span>
      </a>
      <a href="calendar.php" class="nav-item <?php echo is_active('calendar.php') ? 'active' : '';?>">
        <i class="fas fa-calendar-alt"></i>
        <span>Calendar</span>
      </a>
      <a href="activities.php" class="nav-item <?php echo is_active('activities.php') ? 'active' : '';?>">
        <i class="fas fa-clock-rotate-left"></i>
        <span>Activities</span>
      </a>
      <a href="reports.php" class="nav-item <?php echo (is_active('reports.php') && (!isset($query['type']) || $query['type'] === 'overview')) ? 'active' : '';?>">
        <i class="fas fa-chart-bar"></i>
        <span>Reports</span>
      </a>
      <?php if (in_array($role, ['super', 'admin'], true)): ?>
        <a href="users.php" class="nav-item <?php echo is_active('users.php') ? 'active' : '';?>">
          <i class="fas fa-users"></i>
          <span>Users</span>
        </a>
      <?php endif; ?>
    <?php endif; ?>
  </nav>
  
  <div class="sidebar-footer" style="text-align: center;">
    Version 2.0<br>Event Management System
  </div>
</aside>

<!-- Backdrop Overlay -->
<div id="sidebarBackdrop"></div>

<?php endif; ?>

<script>
  document.addEventListener('DOMContentLoaded', function() {
    window.__profileDropdownBound = true;
    const toggle = document.getElementById('mobileSidebarToggle');
    const sidebar = document.getElementById('appSidebar');
    const backdrop = document.getElementById('sidebarBackdrop');
    
    if (!toggle || !sidebar || !backdrop) return;
    
    function openSidebar(){
      document.body.classList.add('sidebar-open');
      toggle.setAttribute('aria-expanded', 'true');
    }
    
    function closeSidebar(){
      document.body.classList.remove('sidebar-open');
      toggle.setAttribute('aria-expanded', 'false');
      try { toggle.focus(); } catch (e) {}
    }
    
    function getDropdownForProfile(profile) {
      if (!profile) return null;
      if (profile.nextElementSibling && profile.nextElementSibling.classList && profile.nextElementSibling.classList.contains('profile-dropdown')) {
        return profile.nextElementSibling;
      }
      const inner = profile.querySelector('.profile-dropdown');
      if (inner) return inner;
      if (profile.id === 'userProfile') {
        const legacy = document.getElementById('headerProfileDropdown');
        if (legacy) return legacy;
      }
      if (profile.id === 'userProfileMenu') {
        const side = document.getElementById('sidebarProfileDropdown');
        if (side) return side;
      }
      return null;
    }

    function usesHiddenClass(dropdown) {
      if (!dropdown) return false;
      if (dropdown.id === 'headerProfileDropdown') return true;
      return dropdown.classList.contains('hidden');
    }

    function closeDropdown(dropdown) {
      if (!dropdown) return;
      dropdown.classList.remove('show');
      if (usesHiddenClass(dropdown)) {
        dropdown.classList.add('hidden');
      }
    }

    function openDropdown(dropdown) {
      if (!dropdown) return;
      dropdown.classList.add('show');
      if (usesHiddenClass(dropdown)) {
        dropdown.classList.remove('hidden');
      }
    }

    // Toggle profile dropdown
    function toggleProfileDropdown(e, profile) {
      const dropdown = getDropdownForProfile(profile);
      if (!dropdown) return;
      if (e) {
        if (!e.target.closest('a')) {
          e.preventDefault();
        }
        e.stopPropagation();
      }

      const isOpen = dropdown.classList.contains('show') || (usesHiddenClass(dropdown) ? !dropdown.classList.contains('hidden') : false);

      // Close all dropdowns first
      const allDropdowns = document.querySelectorAll('.profile-dropdown, #headerProfileDropdown');
      allDropdowns.forEach(dd => closeDropdown(dd));

      if (!isOpen) {
        openDropdown(dropdown);
      }
    }
    
    // Close dropdowns when clicking outside
    document.addEventListener('click', function(e) {
      let insideAny = false;
      const userProfiles = document.querySelectorAll('.user-profile');
      userProfiles.forEach((profile) => {
        const dropdown = getDropdownForProfile(profile);
        if (profile.contains(e.target) || (dropdown && dropdown.contains(e.target))) {
          insideAny = true;
        }
      });

      if (!insideAny) {
        const allDropdowns = document.querySelectorAll('.profile-dropdown, #headerProfileDropdown');
        allDropdowns.forEach(dd => closeDropdown(dd));
      }
    });

    // Close on Escape key
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') {
        const allDropdowns = document.querySelectorAll('.profile-dropdown, #headerProfileDropdown');
        allDropdowns.forEach(dd => closeDropdown(dd));
      }
    });
    
    // Add event listeners to all profile toggles
    const userProfiles = document.querySelectorAll('.user-profile');
    userProfiles.forEach((profile) => {
      const dropdown = getDropdownForProfile(profile);
      if (!dropdown) return;

      dropdown.addEventListener('click', function(e) {
        e.stopPropagation();
      });

      profile.style.cursor = 'pointer';
      profile.addEventListener('click', (e) => {
        if (e.target.closest('a')) return;
        toggleProfileDropdown(e, profile);
      });

      const chevron = profile.querySelector('.fa-chevron-down, .fa-caret-down');
      if (chevron) {
        chevron.style.pointerEvents = 'auto';
        chevron.addEventListener('click', (e) => {
          toggleProfileDropdown(e, profile);
        });
      }
    });
    
    // Event listeners
    toggle.addEventListener('click', function(e) {
      e.preventDefault();
      e.stopPropagation();
      if (document.body.classList.contains('sidebar-open')) closeSidebar();
      else openSidebar();
    }, { passive: false });
    
    toggle.addEventListener('touchend', function(e) {
      e.preventDefault();
      e.stopPropagation();
      if (document.body.classList.contains('sidebar-open')) closeSidebar();
      else openSidebar();
    }, { passive: false });
    
    const closeBtn = document.querySelector('.sidebar-close-btn');
    if (closeBtn) {
      closeBtn.addEventListener('click', closeSidebar);
    }
    
    backdrop.addEventListener('click', function() { closeSidebar(); });
    
    // Close sidebar when clicking on nav links (mobile)
    const navLinks = document.querySelectorAll('.nav-link');
    navLinks.forEach(function(link) {
      link.addEventListener('click', function() {
        if (window.innerWidth < 768) {
          closeSidebar();
        }
      });
    });
    
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape' && document.body.classList.contains('sidebar-open')) closeSidebar();
    });
    
    // Handle window resize
    window.addEventListener('resize', function() {
      if (window.innerWidth > 640) {
        closeSidebar();
      }
    });
    
  })();
</script>

<script>
  // Reserved for future header-specific profile logic.
  // Currently, all profile dropdowns (header + sidebar) are handled by the
  // unified script above that uses `.user-profile` and `.profile-dropdown`.
</script>