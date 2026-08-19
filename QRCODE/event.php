<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/header.php';

// Check if event ID is provided
if (!isset($_GET['id'])) {
    set_flash_message('No event specified', 'danger');
    redirect('events_manage.php');
}

$event_id = (int)$_GET['id'];
$db = get_db();
$user = current_user();

// Get event details
$stmt = $db->prepare("
    SELECT e.*, 
           u1.name as created_by_name,
           u2.name as assigned_to_name,
           u2.email as assigned_to_email
    FROM events e
    LEFT JOIN users u1 ON e.created_by = u1.id
    LEFT JOIN users u2 ON e.assigned_to = u2.id
    WHERE e.id = ?
");
$stmt->execute([$event_id]);
$event = $stmt->fetch();

if (!$event) {
    set_flash_message('Event not found', 'danger');
    redirect('events_manage.php');
}

// Check if user has permission to view this event
$can_edit = in_array($user['role'], ['admin', 'super']) || $event['assigned_to'] == $user['id'];

// Get event attendees count
$attendees_count = $db->query("SELECT COUNT(*) FROM event_attendees WHERE event_id = $event_id")->fetchColumn();

// Get recent activities for this event
$activities = $db->prepare("
    SELECT a.*, u.name as user_name
    FROM activities a
    LEFT JOIN users u ON a.user_id = u.id
    WHERE (a.action_type = 'event_updated' OR a.action_type = 'event_created')
    AND a.entity_type = 'event' AND a.entity_id = ?
    ORDER BY a.created_at DESC
    LIMIT 5
");
$activities->execute([$event_id]);
$recent_activities = $activities->fetchAll();
?>

<div class="container-fluid">
    <!-- Event Header -->
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <div>
            <h1 class="h2"><?php echo htmlspecialchars($event['name']); ?></h1>
            <div class="d-flex align-items-center">
                <?php 
                $status_class = [
                    'draft' => 'bg-secondary',
                    'published' => 'bg-success',
                    'cancelled' => 'bg-danger'
                ][$event['status']] ?? 'bg-secondary';
                ?>
                <span class="badge <?php echo $status_class; ?> me-2">
                    <?php echo ucfirst($event['status']); ?>
                </span>
                <?php if ($event['client']): ?>
                    <span class="text-muted">
                        <i class="fas fa-user-tie me-1"></i> <?php echo htmlspecialchars($event['client']); ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>
        <div class="btn-toolbar mb-2 mb-md-0">
            <div class="btn-group me-2">
                <a href="events_manage.php" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Events
                </a>
                <?php if ($can_edit): ?>
                    <a href="event_edit.php?id=<?php echo $event_id; ?>" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-edit"></i> Edit
                    </a>
                <?php endif; ?>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()">
                    <i class="fas fa-print"></i> Print
                </button>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Main Content -->
        <div class="col-lg-8">
            <!-- Event Details Card -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Event Details</h5>
                </div>
                <div class="card-body">
                    <?php if ($event['description']): ?>
                        <div class="mb-4">
                            <h6>Description</h6>
                            <p class="text-muted"><?php echo nl2br(htmlspecialchars($event['description'])); ?></p>
                        </div>
                    <?php endif; ?>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <h6>When</h6>
                            <p class="text-muted">
                                <i class="far fa-calendar-alt me-2"></i> 
                                <?php echo date('F j, Y', strtotime($event['event_date'])); ?>
                                <?php if ($event['start_time']): ?>
                                    <br>
                                    <i class="far fa-clock me-2"></i> 
                                    <?php echo date('g:i A', strtotime($event['start_time'])); ?>
                                    <?php if ($event['end_time']): ?>
                                        - <?php echo date('g:i A', strtotime($event['end_time'])); ?>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </p>
                        </div>
                        <div class="col-md-6">
                            <h6>Where</h6>
                            <p class="text-muted">
                                <i class="fas fa-map-marker-alt me-2"></i>
                                <?php echo $event['location'] ? htmlspecialchars($event['location']) : 'Location not specified'; ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Attendees Section -->
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Attendees (<?php echo $attendees_count; ?>)</h5>
                    <a href="event_attendees.php?event_id=<?php echo $event_id; ?>" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-users me-1"></i> Manage Attendees
                    </a>
                </div>
                <div class="card-body">
                    <?php if ($attendees_count > 0): ?>
                        <?php
                        // Get recent attendees
                        $recent_attendees = $db->query("
                            SELECT * FROM event_attendees 
                            WHERE event_id = $event_id 
                            ORDER BY created_at DESC 
                            LIMIT 5
                        ")->fetchAll();
                        ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($recent_attendees as $attendee): ?>
                                <div class="list-group-item d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="mb-1"><?php echo htmlspecialchars($attendee['name']); ?></h6>
                                        <small class="text-muted">
                                            <?php if ($attendee['email']): ?>
                                                <?php echo htmlspecialchars($attendee['email']); ?>
                                            <?php endif; ?>
                                            <?php if ($attendee['phone']): ?>
                                                <?php echo $attendee['email'] ? ' • ' : ''; ?>
                                                <?php echo htmlspecialchars($attendee['phone']); ?>
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                    <span class="badge bg-<?php echo $attendee['checked_in'] ? 'success' : 'secondary'; ?> rounded-pill">
                                        <?php echo $attendee['checked_in'] ? 'Checked In' : 'Registered'; ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($attendees_count > 5): ?>
                            <div class="text-center mt-3">
                                <a href="event_attendees.php?event_id=<?php echo $event_id; ?>" class="btn btn-sm btn-outline-primary">
                                    View All Attendees
                                </a>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <div class="mb-3">
                                <i class="fas fa-users fa-3x text-muted"></i>
                            </div>
                            <h5>No attendees yet</h5>
                            <p class="text-muted">Start by adding attendees to this event.</p>
                            <a href="event_attendees.php?event_id=<?php echo $event_id; ?>" class="btn btn-primary">
                                <i class="fas fa-user-plus me-1"></i> Add Attendees
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="col-lg-4">
            <!-- Event Actions -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Event Actions</h5>
                </div>
                <div class="list-group list-group-flush">
                    <a href="event_checkin.php?event_id=<?php echo $event_id; ?>" class="list-group-item list-group-item-action">
                        <i class="fas fa-user-check me-2"></i> Check-in Attendees
                    </a>
                    <a href="event_attendees.php?event_id=<?php echo $event_id; ?>" class="list-group-item list-group-item-action">
                        <i class="fas fa-users me-2"></i> Manage Attendees
                    </a>
                    <a href="event_reports.php?event_id=<?php echo $event_id; ?>" class="list-group-item list-group-item-action">
                        <i class="fas fa-chart-bar me-2"></i> View Reports
                    </a>
                    <a href="event_export.php?event_id=<?php echo $event_id; ?>" class="list-group-item list-group-item-action">
                        <i class="fas fa-file-export me-2"></i> Export Data
                    </a>
                    <?php if ($can_edit): ?>
                        <a href="event_edit.php?id=<?php echo $event_id; ?>" class="list-group-item list-group-item-action">
                            <i class="fas fa-edit me-2"></i> Edit Event
                        </a>
                        <button type="button" class="list-group-item list-group-item-action text-danger" 
                                data-bs-toggle="modal" data-bs-target="#deleteEventModal">
                            <i class="fas fa-trash-alt me-2"></i> Delete Event
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Event Team -->
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Event Team</h5>
                    <?php if ($can_edit): ?>
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#assignTeamModal">
                            <i class="fas fa-user-plus"></i>
                        </button>
                    <?php endif; ?>
                </div>
                <div class="list-group list-group-flush">
                    <?php if ($event['assigned_to_name']): ?>
                        <div class="list-group-item">
                            <div class="d-flex align-items-center">
                                <div class="flex-shrink-0">
                                    <div class="avatar-sm">
                                        <span class="avatar-title rounded-circle bg-primary text-white">
                                            <?php echo strtoupper(substr($event['assigned_to_name'], 0, 1)); ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="flex-grow-1 ms-3">
                                    <h6 class="mb-1"><?php echo htmlspecialchars($event['assigned_to_name']); ?></h6>
                                    <small class="text-muted">Event Organizer</small>
                                </div>
                                <?php if ($event['assigned_to_email']): ?>
                                    <a href="mailto:<?php echo htmlspecialchars($event['assigned_to_email']); ?>" class="text-muted">
                                        <i class="fas fa-envelope"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-3">
                            <p class="text-muted mb-0">No team members assigned</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent Activity -->
            <?php if (!empty($recent_activities)): ?>
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Recent Activity</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="list-group list-group-flush">
                            <?php foreach ($recent_activities as $activity): ?>
                                <div class="list-group-item">
                                    <div class="d-flex">
                                        <div class="flex-shrink-0">
                                            <div class="avatar-xs">
                                                <span class="avatar-title rounded-circle bg-light text-primary">
                                                    <?php echo strtoupper(substr($activity['user_name'], 0, 1)); ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="flex-grow-1 ms-2">
                                            <h6 class="mb-1"><?php echo htmlspecialchars($activity['user_name']); ?></h6>
                                            <p class="mb-0 text-muted small">
                                                <?php 
                                                $action_text = '';
                                                switch ($activity['action_type']) {
                                                    case 'event_created':
                                                        $action_text = 'created this event';
                                                        break;
                                                    case 'event_updated':
                                                        $action_text = 'updated event details';
                                                        break;
                                                    default:
                                                        $action_text = 'performed an action';
                                                }
                                                echo $action_text;
                                                ?>
                                            </p>
                                            <small class="text-muted">
                                                <i class="far fa-clock me-1"></i> 
                                                <?php echo time_elapsed_string($activity['created_at']); ?>
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Delete Event Modal -->
<div class="modal fade" id="deleteEventModal" tabindex="-1" aria-labelledby="deleteEventModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteEventModalLabel">Delete Event</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete this event? This action cannot be undone.</p>
                <p class="mb-0"><strong>Event:</strong> <?php echo htmlspecialchars($event['name']); ?></p>
                <?php if ($attendees_count > 0): ?>
                    <div class="alert alert-warning mt-3">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        This event has <?php echo $attendees_count; ?> attendees. Deleting the event will also remove all attendee data.
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form action="event_delete.php" method="post" style="display: inline;">
                    <input type="hidden" name="id" value="<?php echo $event_id; ?>">
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash-alt me-1"></i> Delete Event
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Assign Team Modal -->
<div class="modal fade" id="assignTeamModal" tabindex="-1" aria-labelledby="assignTeamModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="assignTeamModalLabel">Assign Team Member</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="event_assign_team.php" method="post">
                <div class="modal-body">
                    <input type="hidden" name="event_id" value="<?php echo $event_id; ?>">
                    <div class="mb-3">
                        <label for="team_member" class="form-label">Select Team Member</label>
                        <select class="form-select" id="team_member" name="user_id" required>
                            <option value="">Select a team member</option>
                            <?php
                            $team_members = $db->query("
                                SELECT id, name, role 
                                FROM users 
                                WHERE role IN ('sales') AND is_active = 1
                                ORDER BY name
                            ")->fetchAll();
                            
                            foreach ($team_members as $member):
                                $selected = $member['id'] == $event['assigned_to'] ? 'selected' : '';
                                echo "<option value=\"" . (int)$member['id'] . "\" " . $selected . ">" . htmlspecialchars($member['name']) . " (" . htmlspecialchars($member['role']) . ")</option>";
                            endforeach;
                            ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="role" class="form-label">Role</label>
                        <select class="form-select" id="role" name="role" required>
                            <option value="organizer">Organizer</option>
                            <option value="assistant">Assistant</option>
                            <option value="staff">Staff</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Assign</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize any tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Handle QR code generation for event check-in
    var qrCodeBtn = document.getElementById('generateQrCode');
    if (qrCodeBtn) {
        qrCodeBtn.addEventListener('click', function() {
            // In a real app, this would generate a QR code for the event check-in URL
            var eventCheckinUrl = window.location.origin + '/event_checkin.php?event_id=<?php echo $event_id; ?>';
            alert('QR Code would be generated for: ' + eventCheckinUrl);
            // Actual QR code generation would go here using a library like qrcode.js
        });
    }
});
</script>

<?php 
// Helper function to format time elapsed
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

require_once __DIR__ . '/includes/footer.php'; 
?>
