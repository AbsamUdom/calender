<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/header.php';

// Check if user is logged in and has appropriate role
$user = current_user();
if (!$user || !in_array($user['role'], ['sales', 'admin', 'super'], true)) {
    set_flash_message('You do not have permission to access this page', 'danger');
    redirect('index.php');
}

$db = get_db();
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

// Build the WHERE clause for filtering
$where = [];
$params = [];

// Search filter
if (!empty($_GET['search'])) {
    $search = trim($_GET['search']);
    $where[] = "(e.name LIKE :search OR e.description LIKE :search OR e.location LIKE :search)";
    $params[':search'] = "%$search%";
}

// Status filter
if (!empty($_GET['status']) && in_array($_GET['status'], ['upcoming', 'past', 'draft', 'published', 'cancelled'])) {
    if ($_GET['status'] === 'upcoming') {
        $where[] = "e.event_date >= CURDATE()";
    } elseif ($_GET['status'] === 'past') {
        $where[] = "e.event_date < CURDATE()";
    } else {
        $where[] = "e.status = :status";
        $params[':status'] = $_GET['status'];
    }
}

// Assigned to filter
if (!empty($_GET['assigned_to'])) {
    $where[] = "e.assigned_to = :assigned_to";
    $params[':assigned_to'] = (int)$_GET['assigned_to'];
}

// Build the final WHERE clause
$where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Get total count for pagination
$count_sql = "SELECT COUNT(*) as total FROM events e $where_clause";
$stmt = $db->prepare($count_sql);
$stmt->execute($params);
$total_events = $stmt->fetch()['total'];
$total_pages = ceil($total_events / $per_page);

// Get events with pagination
$sql = "SELECT e.*, u.name as assigned_to_name 
        FROM events e 
        LEFT JOIN users u ON e.assigned_to = u.id 
        $where_clause 
        ORDER BY e.event_date DESC, e.created_at DESC 
        LIMIT :offset, :per_page";

$stmt = $db->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->bindValue(':per_page', $per_page, PDO::PARAM_INT);
$stmt->execute();
$events = $stmt->fetchAll();

// Get team members for filter
$team_members = $db->query("SELECT id, name FROM users WHERE role IN ('sales') ORDER BY name")->fetchAll();
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2">Manage Events</h1>
        <div class="btn-toolbar mb-2 mb-md-0">
            <a href="event_edit.php" class="btn btn-sm btn-outline-primary">
                <i class="fas fa-plus"></i> New Event
            </a>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="get" class="row g-3">
                <div class="col-md-4">
                    <label for="search" class="form-label">Search</label>
                    <input type="text" class="form-control" id="search" name="search" 
                           value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>" 
                           placeholder="Search events...">
                </div>
                <div class="col-md-3">
                    <label for="status" class="form-label">Status</label>
                    <select class="form-select" id="status" name="status">
                        <option value="">All Statuses</option>
                        <option value="upcoming" <?php echo (isset($_GET['status']) && $_GET['status'] === 'upcoming') ? 'selected' : ''; ?>>Upcoming</option>
                        <option value="past" <?php echo (isset($_GET['status']) && $_GET['status'] === 'past') ? 'selected' : ''; ?>>Past</option>
                        <option value="draft" <?php echo (isset($_GET['status']) && $_GET['status'] === 'draft') ? 'selected' : ''; ?>>Draft</option>
                        <option value="published" <?php echo (isset($_GET['status']) && $_GET['status'] === 'published') ? 'selected' : ''; ?>>Published</option>
                        <option value="cancelled" <?php echo (isset($_GET['status']) && $_GET['status'] === 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="assigned_to" class="form-label">Assigned To</label>
                    <select class="form-select" id="assigned_to" name="assigned_to">
                        <option value="">All Team Members</option>
                        <?php foreach ($team_members as $member): ?>
                            <option value="<?php echo $member['id']; ?>" 
                                <?php echo (isset($_GET['assigned_to']) && $_GET['assigned_to'] == $member['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($member['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary me-2">Filter</button>
                    <a href="events_manage.php" class="btn btn-outline-secondary">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Events Table -->
    <div class="card">
        <div class="card-body">
            <?php if (empty($events)): ?>
                <div class="alert alert-info">No events found.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Date</th>
                                <th>Location</th>
                                <th>Status</th>
                                <th>Assigned To</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($events as $event): 
                                $event_date = new DateTime($event['event_date']);
                                $today = new DateTime();
                                $is_past = $event_date < $today;
                                $status_class = [
                                    'draft' => 'bg-secondary',
                                    'published' => $is_past ? 'bg-success' : 'bg-primary',
                                    'cancelled' => 'bg-danger'
                                ][$event['status']] ?? 'bg-secondary';
                            ?>
                                <tr>
                                    <td>
                                        <a href="event.php?id=<?php echo $event['id']; ?>" class="text-decoration-none">
                                            <?php echo htmlspecialchars($event['name']); ?>
                                        </a>
                                        <?php if (!empty($event['client'])): ?>
                                            <div class="text-muted small"><?php echo htmlspecialchars($event['client']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo $event_date->format('M j, Y'); ?>
                                        <?php if (!empty($event['start_time'])): ?>
                                            <div class="text-muted small">
                                                <?php echo date('g:i A', strtotime($event['start_time'])); ?>
                                                <?php if (!empty($event['end_time'])): ?>
                                                    - <?php echo date('g:i A', strtotime($event['end_time'])); ?>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo !empty($event['location']) ? htmlspecialchars($event['location']) : '—'; ?></td>
                                    <td>
                                        <span class="badge <?php echo $status_class; ?>">
                                            <?php echo ucfirst($event['status']); ?>
                                            <?php if ($event['status'] === 'published' && $is_past): ?>
                                                (Past)
                                            <?php endif; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($event['assigned_to_name'])): ?>
                                            <?php echo htmlspecialchars($event['assigned_to_name']); ?>
                                        <?php else: ?>
                                            <span class="text-muted">Unassigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="event_edit.php?id=<?php echo $event['id']; ?>" 
                                               class="btn btn-outline-primary" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="event.php?id=<?php echo $event['id']; ?>" 
                                               class="btn btn-outline-secondary" title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <?php if (in_array($user['role'], ['admin', 'super'])): ?>
                                                <button type="button" class="btn btn-outline-danger delete-event" 
                                                        data-id="<?php echo $event['id']; ?>" 
                                                        data-name="<?php echo htmlspecialchars($event['name']); ?>"
                                                        title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <nav aria-label="Page navigation" class="mt-4">
                        <ul class="pagination justify-content-center">
                            <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>" aria-label="First">
                                        <span aria-hidden="true">&laquo;&laquo;</span>
                                    </a>
                                </li>
                                <li class="page-item">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" aria-label="Previous">
                                        <span aria-hidden="true">&laquo;</span>
                                    </a>
                                </li>
                            <?php endif; ?>
                            
                            <?php
                            $start = max(1, $page - 2);
                            $end = min($total_pages, $page + 2);
                            
                            if ($start > 1) {
                                echo '<li class="page-item"><a class="page-link" href="?' . http_build_query(array_merge($_GET, ['page' => 1])) . '">1</a></li>';
                                if ($start > 2) {
                                    echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                }
                            }
                            
                            for ($i = $start; $i <= $end; $i++): ?>
                                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                            <?php endfor; ?>
                            
                            <?php if ($end < $total_pages): ?>
                                if ($end < $total_pages - 1) {
                                    echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                }
                                echo '<li class="page-item"><a class="page-link" href="?' . http_build_query(array_merge($_GET, ['page' => $total_pages])) . '">' . $total_pages . '</a></li>';
                            <?php endif; ?>
                            
                            <?php if ($page < $total_pages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" aria-label="Next">
                                        <span aria-hidden="true">&raquo;</span>
                                    </a>
                                </li>
                                <li class="page-item">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>" aria-label="Last">
                                        <span aria-hidden="true">&raquo;&raquo;</span>
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                <?php endif; ?>
                
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteModalLabel">Confirm Deletion</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                Are you sure you want to delete the event "<span id="eventToDelete"></span>"? This action cannot be undone.
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form id="deleteForm" method="post" action="event_delete.php" style="display: inline;">
                    <input type="hidden" name="id" id="deleteEventId">
                    <button type="submit" class="btn btn-danger">Delete</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Handle delete button clicks
    document.querySelectorAll('.delete-event').forEach(button => {
        button.addEventListener('click', function() {
            const eventId = this.dataset.id;
            const eventName = this.dataset.name;
            
            document.getElementById('eventToDelete').textContent = eventName;
            document.getElementById('deleteEventId').value = eventId;
            
            const modal = new bootstrap.Modal(document.getElementById('deleteModal'));
            modal.show();
        });
    });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
