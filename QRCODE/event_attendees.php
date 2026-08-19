<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/header.php';

// Check if event ID is provided
if (!isset($_GET['event_id'])) {
    set_flash_message('No event specified', 'danger');
    redirect('events_manage.php');
}

$event_id = (int)$_GET['event_id'];
$db = get_db();
$user = current_user();

// Get event details
$stmt = $db->prepare("SELECT * FROM events WHERE id = ?");
$stmt->execute([$event_id]);
$event = $stmt->fetch();

if (!$event) {
    set_flash_message('Event not found', 'danger');
    redirect('events_manage.php');
}

// Check if user has permission to view this event's attendees
$can_edit = in_array($user['role'], ['admin', 'super']) || $event['assigned_to'] == $user['id'];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        set_flash_message('Invalid CSRF token', 'danger');
        redirect("event_attendees.php?event_id=$event_id");
    }
    
    // Handle bulk actions
    if (isset($_POST['bulk_action']) && !empty($_POST['attendee_ids'])) {
        $attendee_ids = array_map('intval', $_POST['attendee_ids']);
        $placeholders = rtrim(str_repeat('?,', count($attendee_ids)), ',');
        
        try {
            $db->beginTransaction();
            
            switch ($_POST['bulk_action']) {
                case 'check_in':
                    $stmt = $db->prepare("UPDATE event_attendees SET checked_in = 1, checked_in_at = NOW() WHERE id IN ($placeholders) AND event_id = ?");
                    $params = array_merge($attendee_ids, [$event_id]);
                    $stmt->execute($params);
                    $count = $stmt->rowCount();
                    set_flash_message("Checked in $count attendees", 'success');
                    break;
                    
                case 'check_out':
                    $stmt = $db->prepare("UPDATE event_attendees SET checked_in = 0, checked_in_at = NULL WHERE id IN ($placeholders) AND event_id = ?");
                    $params = array_merge($attendee_ids, [$event_id]);
                    $stmt->execute($params);
                    $count = $stmt->rowCount();
                    set_flash_message("Checked out $count attendees", 'success');
                    break;
                    
                case 'delete':
                    $stmt = $db->prepare("DELETE FROM event_attendees WHERE id IN ($placeholders) AND event_id = ?");
                    $params = array_merge($attendee_ids, [$event_id]);
                    $stmt->execute($params);
                    $count = $stmt->rowCount();
                    set_flash_message("Deleted $count attendees", 'success');
                    break;
                    
                case 'export':
                    // Export functionality would go here
                    // This is a placeholder that would be implemented with a separate export script
                    set_flash_message('Export functionality coming soon', 'info');
                    break;
            }
            
            $db->commit();
            
        } catch (Exception $e) {
            $db->rollBack();
            error_log('Error processing bulk action: ' . $e->getMessage());
            set_flash_message('An error occurred while processing the request', 'danger');
        }
        
        redirect("event_attendees.php?event_id=$event_id");
    }
    
    // Handle single attendee add/edit
    if (isset($_POST['action'])) {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $company = trim($_POST['company'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        
        // Basic validation
        $errors = [];
        if (empty($name)) {
            $errors[] = 'Name is required';
        }
        
        if (empty($errors)) {
            try {
                $db->beginTransaction();
                
                if ($_POST['action'] === 'add') {
                    // Add new attendee
                    $stmt = $db->prepare("
                        INSERT INTO event_attendees 
                        (event_id, name, email, phone, company, notes, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, NOW())
                    
                    $stmt->execute([$event_id, $name, $email, $phone, $company, $notes]);
                    $attendee_id = $db->lastInsertId();
                    
                    // Log activity
                    db_log_activity($user['id'], 'attendee_added', "Added attendee: $name to event: {$event['name']}");
                    
                    set_flash_message('Attendee added successfully', 'success');
                    
                } elseif ($_POST['action'] === 'edit' && !empty($_POST['attendee_id'])) {
                    // Update existing attendee
                    $attendee_id = (int)$_POST['attendee_id'];
                    
                    $stmt = $db->prepare("
                        UPDATE event_attendees 
                        SET name = ?, email = ?, phone = ?, company = ?, notes = ?, updated_at = NOW()
                        WHERE id = ? AND event_id = ?
                    
                    $stmt->execute([$name, $email, $phone, $company, $notes, $attendee_id, $event_id]);
                    
                    // Log activity
                    db_log_activity($user['id'], 'attendee_updated', "Updated attendee: $name for event: {$event['name']}");
                    
                    set_flash_message('Attendee updated successfully', 'success');
                }
                
                $db->commit();
                
            } catch (PDOException $e) {
                $db->rollBack();
                error_log('Error saving attendee: ' . $e->getMessage());
                $errors[] = 'An error occurred while saving the attendee';
            }
            
            if (empty($errors)) {
                redirect("event_attendees.php?event_id=$event_id");
            }
        }
    }
}

// Handle attendee deletion
if (isset($_GET['delete_attendee']) && is_numeric($_GET['delete_attendee'])) {
    if (!$can_edit) {
        set_flash_message('You do not have permission to delete attendees', 'danger');
        redirect("event_attendees.php?event_id=$event_id");
    }
    
    $attendee_id = (int)$_GET['delete_attendee'];
    
    try {
        // Get attendee name for logging
        $stmt = $db->prepare("SELECT name FROM event_attendees WHERE id = ? AND event_id = ?");
        $stmt->execute([$attendee_id, $event_id]);
        $attendee = $stmt->fetch();
        
        if ($attendee) {
            $stmt = $db->prepare("DELETE FROM event_attendees WHERE id = ? AND event_id = ?");
            $stmt->execute([$attendee_id, $event_id]);
            
            // Log activity
            db_log_activity($user['id'], 'attendee_deleted', "Deleted attendee: {$attendee['name']} from event: {$event['name']}");
            
            set_flash_message('Attendee deleted successfully', 'success');
        } else {
            set_flash_message('Attendee not found', 'warning');
        }
        
    } catch (PDOException $e) {
        error_log('Error deleting attendee: ' . $e->getMessage());
        set_flash_message('An error occurred while deleting the attendee', 'danger');
    }
    
    redirect("event_attendees.php?event_id=$event_id");
}

// Get search and filter parameters
$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? 'all';
$sort = $_GET['sort'] ?? 'name';
$order = $_GET['order'] ?? 'asc';

// Build the query
$where = ["a.event_id = ?"];
$params = [$event_id];

// Apply search filter
if (!empty($search)) {
    $where[] = "(a.name LIKE ? OR a.email LIKE ? OR a.phone LIKE ? OR a.company LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
}

// Apply status filter
if ($status === 'checked_in') {
    $where[] = "a.checked_in = 1";
} elseif ($status === 'not_checked_in') {
    $where[] = "(a.checked_in = 0 OR a.checked_in IS NULL)";
}

// Build the WHERE clause
$where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Get total count for pagination
$count_sql = "SELECT COUNT(*) as total FROM event_attendees a $where_clause";
$stmt = $db->prepare($count_sql);
$stmt->execute($params);
total_attendees = $stmt->fetch()['total'];

// Pagination
$per_page = 20;
$total_pages = ceil($total_attendees / $per_page);
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page = max(1, min($page, $total_pages));
$offset = ($page - 1) * $per_page;

// Validate and sanitize sort/order
$valid_columns = ['name', 'email', 'company', 'checked_in', 'created_at'];
$sort = in_array($sort, $valid_columns) ? $sort : 'name';
$order = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';

// Get attendees with pagination and sorting
$sql = "SELECT a.* FROM event_attendees a 
        $where_clause 
        ORDER BY $sort $order 
        LIMIT ? OFFSET ?";

$params[] = $per_page;
$params[] = $offset;

$stmt = $db->prepare($sql);
$stmt->execute($params);
$attendees = $stmt->fetchAll();

// Generate CSRF token if not exists
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <div>
            <h1 class="h2">
                <a href="event.php?id=<?php echo $event_id; ?>" class="text-decoration-none text-dark">
                    <?php echo htmlspecialchars($event['name']); ?>
                </a>
                <small class="text-muted">/</small> Attendees
            </h1>
            <p class="mb-0">
                <?php 
                $checked_in = $db->query("SELECT COUNT(*) FROM event_attendees WHERE event_id = $event_id AND checked_in = 1")->fetchColumn();
                echo "$total_attendees total attendees • $checked_in checked in";
                ?>
            </p>
        </div>
        <div class="btn-toolbar mb-2 mb-md-0">
            <button type="button" class="btn btn-sm btn-outline-secondary me-2" data-bs-toggle="modal" data-bs-target="#addAttendeeModal">
                <i class="fas fa-user-plus me-1"></i> Add Attendee
            </button>
            <a href="event.php?id=<?php echo $event_id; ?>" class="btn btn-sm btn-outline-secondary">
                <i class="fas fa-arrow-left me-1"></i> Back to Event
            </a>
        </div>
    </div>

    <!-- Filters and Search -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="get" class="row g-3">
                <input type="hidden" name="event_id" value="<?php echo $event_id; ?>">
                
                <div class="col-md-5">
                    <div class="input-group">
                        <input type="text" class="form-control" name="search" placeholder="Search attendees..." 
                               value="<?php echo htmlspecialchars($search); ?>">
                        <button class="btn btn-outline-secondary" type="submit">
                            <i class="fas fa-search"></i>
                        </button>
                        <?php if (!empty($search)): ?>
                            <a href="event_attendees.php?event_id=<?php echo $event_id; ?>" class="btn btn-outline-secondary">
                                <i class="fas fa-times"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <select name="status" class="form-select" onchange="this.form.submit()">
                        <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All Attendees</option>
                        <option value="checked_in" <?php echo $status === 'checked_in' ? 'selected' : ''; ?>>Checked In Only</option>
                        <option value="not_checked_in" <?php echo $status === 'not_checked_in' ? 'selected' : ''; ?>>Not Checked In</option>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <select name="sort" class="form-select" onchange="this.form.submit()">
                        <option value="name" <?php echo $sort === 'name' ? 'selected' : ''; ?>>Sort by Name</option>
                        <option value="company" <?php echo $sort === 'company' ? 'selected' : ''; ?>>Sort by Company</option>
                        <option value="checked_in" <?php echo $sort === 'checked_in' ? 'selected' : ''; ?>>Sort by Status</option>
                        <option value="created_at" <?php echo $sort === 'created_at' ? 'selected' : ''; ?>>Sort by Date</option>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <select name="order" class="form-select" onchange="this.form.submit()">
                        <option value="asc" <?php echo $order === 'asc' ? 'selected' : ''; ?>>Ascending</option>
                        <option value="desc" <?php echo $order === 'desc' ? 'selected' : ''; ?>>Descending</option>
                    </select>
                </div>
            </form>
        </div>
    </div>

    <?php if (empty($attendees)): ?>
        <div class="card">
            <div class="card-body text-center py-5">
                <div class="mb-3">
                    <i class="fas fa-users fa-4x text-muted"></i>
                </div>
                <h3>No attendees found</h3>
                <p class="text-muted">
                    <?php echo !empty($search) ? 'No attendees match your search criteria.' : 'Get started by adding attendees to this event.'; ?>
                </p>
                <button type="button" class="btn btn-primary mt-3" data-bs-toggle="modal" data-bs-target="#addAttendeeModal">
                    <i class="fas fa-user-plus me-1"></i> Add Attendee
                </button>
            </div>
        </div>
    <?php else: ?>
        <form method="post" id="attendeesForm">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            
            <!-- Bulk Actions -->
            <div class="row mb-3">
                <div class="col-md-8">
                    <div class="btn-group" role="group">
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="selectAllBtn">
                            <i class="far fa-square me-1"></i> Select All
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="deselectAllBtn">
                            <i class="far fa-check-square me-1"></i> Deselect All
                        </button>
                    </div>
                    
                    <div class="btn-group ms-2" role="group">
                        <select name="bulk_action" class="form-select form-select-sm" style="width: auto;">
                            <option value="">Bulk Actions</option>
                            <option value="check_in">Check In</option>
                            <option value="check_out">Check Out</option>
                            <?php if ($can_edit): ?>
                                <option value="delete">Delete</option>
                            <?php endif; ?>
                            <option value="export">Export</option>
                        </select>
                        <button type="submit" class="btn btn-sm btn-outline-primary">
                            Apply
                        </button>
                    </div>
                </div>
                
                <div class="col-md-4 text-end">
                    <div class="btn-group" role="group">
                        <a href="event_checkin.php?event_id=<?php echo $event_id; ?>" class="btn btn-sm btn-success">
                            <i class="fas fa-user-check me-1"></i> Check-In Station
                        </a>
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#importAttendeesModal">
                            <i class="fas fa-upload me-1"></i> Import
                        </button>
                        <a href="event_export.php?event_id=<?php echo $event_id; ?>" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-download me-1"></i> Export
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Attendees Table -->
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th width="40">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="selectAllCheckbox">
                                    </div>
                                </th>
                                <th>Name</th>
                                <th>Contact</th>
                                <th>Company</th>
                                <th>Status</th>
                                <th>Registered</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($attendees as $attendee): ?>
                                <tr>
                                    <td>
                                        <div class="form-check">
                                            <input class="form-check-input attendee-checkbox" type="checkbox" 
                                                   name="attendee_ids[]" value="<?php echo $attendee['id']; ?>">
                                        </div>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="avatar-sm me-2">
                                                <span class="avatar-title rounded-circle bg-light text-dark">
                                                    <?php echo strtoupper(substr($attendee['name'], 0, 1)); ?>
                                                </span>
                                            </div>
                                            <div>
                                                <div class="fw-medium"><?php echo htmlspecialchars($attendee['name']); ?></div>
                                                <small class="text-muted">ID: <?php echo $attendee['id']; ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($attendee['email']): ?>
                                            <div><i class="far fa-envelope me-1 text-muted"></i> <?php echo htmlspecialchars($attendee['email']); ?></div>
                                        <?php endif; ?>
                                        <?php if ($attendee['phone']): ?>
                                            <div class="text-muted small"><i class="fas fa-phone me-1"></i> <?php echo htmlspecialchars($attendee['phone']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $attendee['company'] ? htmlspecialchars($attendee['company']) : '—'; ?></td>
                                    <td>
                                        <?php if ($attendee['checked_in']): ?>
                                            <span class="badge bg-success">
                                                <i class="fas fa-check-circle me-1"></i> Checked In
                                            </span>
                                            <?php if ($attendee['checked_in_at']): ?>
                                                <div class="text-muted small">
                                                    <?php echo date('M j, g:i a', strtotime($attendee['checked_in_at'])); ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Not Checked In</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="small text-muted">
                                            <?php echo date('M j, Y', strtotime($attendee['created_at'])); ?>
                                        </div>
                                        <div class="small text-muted">
                                            <?php echo date('g:i a', strtotime($attendee['created_at'])); ?>
                                        </div>
                                    </td>
                                    <td class="text-end">
                                        <div class="btn-group btn-group-sm">
                                            <?php if ($can_edit): ?>
                                                <button type="button" class="btn btn-sm btn-outline-primary edit-attendee" 
                                                        data-id="<?php echo $attendee['id']; ?>"
                                                        data-name="<?php echo htmlspecialchars($attendee['name']); ?>"
                                                        data-email="<?php echo htmlspecialchars($attendee['email']); ?>"
                                                        data-phone="<?php echo htmlspecialchars($attendee['phone']); ?>"
                                                        data-company="<?php echo htmlspecialchars($attendee['company']); ?>"
                                                        data-notes="<?php echo htmlspecialchars($attendee['notes']); ?>">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <a href="event_attendees.php?event_id=<?php echo $event_id; ?>&delete_attendee=<?php echo $attendee['id']; ?>" 
                                                   class="btn btn-sm btn-outline-danger"
                                                   onclick="return confirm('Are you sure you want to delete this attendee? This cannot be undone.');">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            <?php endif; ?>
                                            <a href="#" class="btn btn-sm btn-outline-secondary">
                                                <i class="fas fa-ellipsis-v"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="card-footer">
                        <nav aria-label="Page navigation">
                            <ul class="pagination justify-content-center mb-0">
                                <?php
                                $query_params = [
                                    'event_id' => $event_id,
                                    'search' => $search,
                                    'status' => $status,
                                    'sort' => $sort,
                                    'order' => $order
                                ];
                                
                                // Previous page link
                                if ($page > 1) {
                                    $prev_params = array_merge($query_params, ['page' => $page - 1]);
                                    echo '<li class="page-item"><a class="page-link" href="?' . http_build_query($prev_params) . '">&laquo; Previous</a></li>';
                                }
                                
                                // Page numbers
                                $start_page = max(1, $page - 2);
                                $end_page = min($total_pages, $page + 2);
                                
                                if ($start_page > 1) {
                                    $first_params = array_merge($query_params, ['page' => 1]);
                                    echo '<li class="page-item"><a class="page-link" href="?' . http_build_query($first_params) . '">1</a></li>';
                                    if ($start_page > 2) {
                                        echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                    }
                                }
                                
                                for ($i = $start_page; $i <= $end_page; $i++) {
                                    $page_params = array_merge($query_params, ['page' => $i]);
                                    $active = $i == $page ? ' active' : '';
                                    echo '<li class="page-item' . $active . '"><a class="page-link" href="?' . http_build_query($page_params) . '">' . $i . '</a></li>';
                                }
                                
                                if ($end_page < $total_pages) {
                                    if ($end_page < $total_pages - 1) {
                                        echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                    }
                                    $last_params = array_merge($query_params, ['page' => $total_pages]);
                                    echo '<li class="page-item"><a class="page-link" href="?' . http_build_query($last_params) . '">' . $total_pages . '</a></li>';
                                }
                                
                                // Next page link
                                if ($page < $total_pages) {
                                    $next_params = array_merge($query_params, ['page' => $page + 1]);
                                    echo '<li class="page-item"><a class="page-link" href="?' . http_build_query($next_params) . '">Next &raquo;</a></li>';
                                }
                                ?>
                            </ul>
                        </nav>
                        <div class="text-center text-muted small mt-2">
                            Showing <?php echo ($offset + 1); ?> to <?php echo min($offset + $per_page, $total_attendees); ?> of <?php echo $total_attendees; ?> entries
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </form>
    <?php endif; ?>
</div>

<!-- Add Attendee Modal -->
<div class="modal fade" id="addAttendeeModal" tabindex="-1" aria-labelledby="addAttendeeModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" id="addAttendeeForm">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                
                <div class="modal-header">
                    <h5 class="modal-title" id="addAttendeeModalLabel">Add Attendee</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="name" class="form-label">Full Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="name" name="name" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="email" class="form-label">Email</label>
                                <input type="email" class="form-control" id="email" name="email">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="phone" class="form-label">Phone</label>
                                <input type="tel" class="form-control" id="phone" name="phone">
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="company" class="form-label">Company</label>
                        <input type="text" class="form-control" id="company" name="company">
                    </div>
                    <div class="mb-0">
                        <label for="notes" class="form-label">Notes</label>
                        <textarea class="form-control" id="notes" name="notes" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Attendee</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Attendee Modal -->
<div class="modal fade" id="editAttendeeModal" tabindex="-1" aria-labelledby="editAttendeeModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" id="editAttendeeForm">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="attendee_id" id="edit_attendee_id">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                
                <div class="modal-header">
                    <h5 class="modal-title" id="editAttendeeModalLabel">Edit Attendee</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_name" class="form-label">Full Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="edit_name" name="name" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="edit_email" class="form-label">Email</label>
                                <input type="email" class="form-control" id="edit_email" name="email">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="edit_phone" class="form-label">Phone</label>
                                <input type="tel" class="form-control" id="edit_phone" name="phone">
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="edit_company" class="form-label">Company</label>
                        <input type="text" class="form-control" id="edit_company" name="company">
                    </div>
                    <div class="mb-0">
                        <label for="edit_notes" class="form-label">Notes</label>
                        <textarea class="form-control" id="edit_notes" name="notes" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Import Attendees Modal -->
<div class="modal fade" id="importAttendeesModal" tabindex="-1" aria-labelledby="importAttendeesModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="importAttendeesModalLabel">Import Attendees</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    Download our <a href="templates/attendees_import_template.csv">CSV template</a> and fill in the attendee information.
                </div>
                <form id="importForm" action="event_import_attendees.php" method="post" enctype="multipart/form-data">
                    <input type="hidden" name="event_id" value="<?php echo $event_id; ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    
                    <div class="mb-3">
                        <label for="csv_file" class="form-label">CSV File <span class="text-danger">*</span></label>
                        <input class="form-control" type="file" id="csv_file" name="csv_file" accept=".csv" required>
                        <div class="form-text">File must be in CSV format with columns: name, email, phone, company</div>
                    </div>
                    
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="skip_duplicates" name="skip_duplicates" checked>
                        <label class="form-check-label" for="skip_duplicates">
                            Skip duplicate emails
                        </label>
                    </div>
                    
                    <div class="progress mb-3 d-none" id="importProgress">
                        <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%"></div>
                    </div>
                    
                    <div id="importResult" class="d-none">
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle me-2"></i>
                            <span id="importSuccessCount">0</span> attendees imported successfully.
                        </div>
                        <div class="alert alert-warning d-none" id="importWarnings">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <div id="warningMessages"></div>
                        </div>
                    </div>
                    
                    <div class="d-flex justify-content-between">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="importButton">
                            <i class="fas fa-upload me-1"></i> Import
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Handle select all checkboxes
    const selectAllCheckbox = document.getElementById('selectAllCheckbox');
    const attendeeCheckboxes = document.querySelectorAll('.attendee-checkbox');
    const selectAllBtn = document.getElementById('selectAllBtn');
    const deselectAllBtn = document.getElementById('deselectAllBtn');
    
    function updateSelectAllCheckbox() {
        const allChecked = Array.from(attendeeCheckboxes).every(checkbox => checkbox.checked);
        selectAllCheckbox.checked = allChecked && attendeeCheckboxes.length > 0;
    }
    
    selectAllCheckbox.addEventListener('change', function() {
        const isChecked = this.checked;
        attendeeCheckboxes.forEach(checkbox => {
            checkbox.checked = isChecked;
        });
    });
    
    selectAllBtn.addEventListener('click', function() {
        attendeeCheckboxes.forEach(checkbox => {
            checkbox.checked = true;
        });
        updateSelectAllCheckbox();
    });
    
    deselectAllBtn.addEventListener('click', function() {
        attendeeCheckboxes.forEach(checkbox => {
            checkbox.checked = false;
        });
        updateSelectAllCheckbox();
    });
    
    attendeeCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', updateSelectAllCheckbox);
    });
    
    // Handle edit attendee modal
    const editAttendeeModal = new bootstrap.Modal(document.getElementById('editAttendeeModal'));
    const editButtons = document.querySelectorAll('.edit-attendee');
    
    editButtons.forEach(button => {
        button.addEventListener('click', function() {
            const id = this.getAttribute('data-id');
            const name = this.getAttribute('data-name');
            const email = this.getAttribute('data-email');
            const phone = this.getAttribute('data-phone');
            const company = this.getAttribute('data-company');
            const notes = this.getAttribute('data-notes');
            
            document.getElementById('edit_attendee_id').value = id;
            document.getElementById('edit_name').value = name || '';
            document.getElementById('edit_email').value = email || '';
            document.getElementById('edit_phone').value = phone || '';
            document.getElementById('edit_company').value = company || '';
            document.getElementById('edit_notes').value = notes || '';
            
            editAttendeeModal.show();
        });
    });
    
    // Handle form submission with confirmation for bulk actions
    const attendeesForm = document.getElementById('attendeesForm');
    if (attendeesForm) {
        attendeesForm.addEventListener('submit', function(e) {
            const bulkAction = this.querySelector('select[name="bulk_action"]');
            const selectedCheckboxes = this.querySelectorAll('input[name="attendee_ids[]"]:checked');
            
            if (bulkAction && bulkAction.value && selectedCheckboxes.length > 0) {
                if (bulkAction.value === 'delete' && !confirm('Are you sure you want to delete the selected attendees? This cannot be undone.')) {
                    e.preventDefault();
                    return false;
                }
                
                if (bulkAction.value === 'export') {
                    // Handle export (this would be implemented in a separate export script)
                    e.preventDefault();
                    alert('Export functionality would be implemented here');
                    return false;
                }
                
                return true;
            } else if (bulkAction && bulkAction.value) {
                // No checkboxes selected
                e.preventDefault();
                alert('Please select at least one attendee');
                return false;
            }
            
            // If no bulk action selected, allow form submission for regular actions
            return true;
        });
    }
    
    // Handle import form submission with AJAX
    const importForm = document.getElementById('importForm');
    if (importForm) {
        importForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const importButton = document.getElementById('importButton');
            const importProgress = document.getElementById('importProgress');
            const importResult = document.getElementById('importResult');
            const importSuccessCount = document.getElementById('importSuccessCount');
            const importWarnings = document.getElementById('importWarnings');
            const warningMessages = document.getElementById('warningMessages');
            
            // Reset UI
            importButton.disabled = true;
            importButton.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Importing...';
            importProgress.classList.remove('d-none');
            importResult.classList.add('d-none');
            
            // Show progress bar
            const progressBar = importProgress.querySelector('.progress-bar');
            progressBar.style.width = '0%';
            progressBar.setAttribute('aria-valuenow', '0');
            
            // Simulate progress (in a real app, this would be handled by server-sent events or polling)
            let progress = 0;
            const progressInterval = setInterval(() => {
                progress += 5;
                if (progress > 90) clearInterval(progressInterval);
                progressBar.style.width = progress + '%';
                progressBar.setAttribute('aria-valuenow', progress);
            }, 100);
            
            // Submit form via AJAX
            fetch(this.action, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                clearInterval(progressInterval);
                progressBar.style.width = '100%';
                progressBar.setAttribute('aria-valuenow', '100');
                
                if (data.success) {
                    importSuccessCount.textContent = data.imported_count || 0;
                    
                    if (data.warnings && data.warnings.length > 0) {
                        warningMessages.innerHTML = '';
                        data.warnings.forEach(warning => {
                            warningMessages.innerHTML += `<div>${warning}</div>`;
                        });
                        importWarnings.classList.remove('d-none');
                    } else {
                        importWarnings.classList.add('d-none');
                    }
                    
                    importResult.classList.remove('d-none');
                    
                    // Reload page after a short delay
                    setTimeout(() => {
                        window.location.reload();
                    }, 2000);
                } else {
                    alert('Error: ' + (data.message || 'An unknown error occurred'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while importing. Please try again.');
            })
            .finally(() => {
                importButton.disabled = false;
                importButton.innerHTML = '<i class="fas fa-upload me-1"></i> Import';
                clearInterval(progressInterval);
            });
        });
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
