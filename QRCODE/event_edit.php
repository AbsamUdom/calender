<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/header.php';

$db = get_db();
$user = current_user();
$is_edit = isset($_GET['id']);
$event_id = $is_edit ? (int)$_GET['id'] : null;

// Check permissions
if ($is_edit) {
    // For editing, user must be admin, super, or the assigned user
    $stmt = $db->prepare("SELECT * FROM events WHERE id = ?");
    $stmt->execute([$event_id]);
    $event = $stmt->fetch();
    
    if (!$event) {
        set_flash_message('Event not found', 'danger');
        redirect('events_manage.php');
    }
    
    if (!in_array($user['role'], ['admin', 'super']) && $event['assigned_to'] != $user['id']) {
        set_flash_message('You do not have permission to edit this event', 'danger');
        redirect('events_manage.php');
    }
} else {
    // For new events, user must be sales, admin, or super
    if (!in_array($user['role'], ['sales', 'admin', 'super'], true)) {
        set_flash_message('You do not have permission to create events', 'danger');
        redirect('events_manage.php');
    }
    $event = [
        'name' => '',
        'description' => '',
        'event_date' => date('Y-m-d'),
        'start_time' => '',
        'end_time' => '',
        'location' => '',
        'client' => '',
        'status' => 'draft',
        'assigned_to' => $user['id']
    ];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        set_flash_message('Invalid CSRF token', 'danger');
        redirect('events_manage.php');
    }
    
    // Get and validate form data
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $event_date = $_POST['event_date'] ?? '';
    $start_time = !empty($_POST['start_time']) ? $_POST['start_time'] : null;
    $end_time = !empty($_POST['end_time']) ? $_POST['end_time'] : null;
    $location = trim($_POST['location'] ?? '');
    $client = trim($_POST['client'] ?? '');
    $status = in_array($_POST['status'] ?? '', ['draft', 'published', 'cancelled']) ? $_POST['status'] : 'draft';
    $assigned_to = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : null;
    
    // Basic validation
    $errors = [];
    if (empty($name)) {
        $errors[] = 'Event name is required';
    }
    if (empty($event_date)) {
        $errors[] = 'Event date is required';
    } elseif (!strtotime($event_date)) {
        $errors[] = 'Invalid event date';
    }
    
    if ($start_time && !preg_match('/^\d{2}:\d{2}$/', $start_time)) {
        $errors[] = 'Invalid start time format (use HH:MM)';
    }
    
    if ($end_time && !preg_match('/^\d{2}:\d{2}$/', $end_time)) {
        $errors[] = 'Invalid end time format (use HH:MM)';
    }
    
    if ($start_time && $end_time && strtotime($start_time) >= strtotime($end_time)) {
        $errors[] = 'End time must be after start time';
    }
    
    // If no validation errors, save the event
    if (empty($errors)) {
        try {
            $event_data = [
                'name' => $name,
                'description' => $description,
                'event_date' => $event_date,
                'start_time' => $start_time,
                'end_time' => $end_time,
                'location' => $location,
                'client' => $client,
                'status' => $status,
                'assigned_to' => $assigned_to,
                'updated_at' => date('Y-m-d H:i:s')
            ];
            
            if ($is_edit) {
                // Update existing event
                $stmt = $db->prepare("
                    UPDATE events 
                    SET name = :name, 
                        description = :description, 
                        event_date = :event_date, 
                        start_time = :start_time, 
                        end_time = :end_time, 
                        location = :location, 
                        client = :client, 
                        status = :status, 
                        assigned_to = :assigned_to,
                        updated_at = :updated_at
                    WHERE id = :id
                
                // Log activity
                db_log_activity($user['id'], 'event_updated', 'Updated event: ' . $name);
                
                set_flash_message('Event updated successfully', 'success');
            } else {
                // Create new event
                $event_data['created_by'] = $user['id'];
                $event_data['created_at'] = date('Y-m-d H:i:s');
                
                $columns = implode(', ', array_keys($event_data));
                $placeholders = ':' . implode(', :', array_keys($event_data));
                
                $stmt = $db->prepare("INSERT INTO events ($columns) VALUES ($placeholders)");
                
                // Log activity
                db_log_activity($user['id'], 'event_created', 'Created new event: ' . $name);
                
                set_flash_message('Event created successfully', 'success');
            }
            
            $stmt->execute($event_data);
            
            if (!$is_edit) {
                $event_id = $db->lastInsertId();
            }
            
            redirect('event.php?id=' . $event_id);
            
        } catch (PDOException $e) {
            error_log('Database error: ' . $e->getMessage());
            $errors[] = 'An error occurred while saving the event';
        }
    }
}

// Get team members for assignee dropdown
$team_members = $db->query("SELECT id, name FROM users WHERE role IN ('sales') ORDER BY name")->fetchAll();

// Generate CSRF token if not exists
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2"><?php echo $is_edit ? 'Edit Event' : 'Create New Event'; ?></h1>
        <div class="btn-toolbar mb-2 mb-md-0">
            <a href="events_manage.php" class="btn btn-sm btn-outline-secondary">
                <i class="fas fa-arrow-left"></i> Back to Events
            </a>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <form method="post" id="eventForm">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                
                <div class="row mb-3">
                    <div class="col-md-8">
                        <label for="name" class="form-label">Event Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="name" name="name" 
                               value="<?php echo htmlspecialchars($event['name']); ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label for="status" class="form-label">Status</label>
                        <select class="form-select" id="status" name="status" required>
                            <option value="draft" <?php echo $event['status'] === 'draft' ? 'selected' : ''; ?>>Draft</option>
                            <option value="published" <?php echo $event['status'] === 'published' ? 'selected' : ''; ?>>Published</option>
                            <option value="cancelled" <?php echo $event['status'] === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-12">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="3"><?php echo htmlspecialchars($event['description']); ?></textarea>
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-4">
                        <label for="event_date" class="form-label">Event Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="event_date" name="event_date" 
                               value="<?php echo htmlspecialchars($event['event_date']); ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label for="start_time" class="form-label">Start Time</label>
                        <input type="time" class="form-control" id="start_time" name="start_time" 
                               value="<?php echo !empty($event['start_time']) ? date('H:i', strtotime($event['start_time'])) : ''; ?>">
                    </div>
                    <div class="col-md-4">
                        <label for="end_time" class="form-label">End Time</label>
                        <input type="time" class="form-control" id="end_time" name="end_time" 
                               value="<?php echo !empty($event['end_time']) ? date('H:i', strtotime($event['end_time'])) : ''; ?>">
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-6">
                        <label for="location" class="form-label">Location</label>
                        <input type="text" class="form-control" id="location" name="location" 
                               value="<?php echo htmlspecialchars($event['location']); ?>">
                    </div>
                    <div class="col-md-6">
                        <label for="client" class="form-label">Client</label>
                        <input type="text" class="form-control" id="client" name="client" 
                               value="<?php echo htmlspecialchars($event['client']); ?>">
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-6">
                        <label for="assigned_to" class="form-label">Assigned To</label>
                        <select class="form-select" id="assigned_to" name="assigned_to">
                            <option value="">Unassigned</option>
                            <?php foreach ($team_members as $member): ?>
                                <option value="<?php echo $member['id']; ?>" 
                                    <?php echo (isset($event['assigned_to']) && $event['assigned_to'] == $member['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($member['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                    <a href="<?php echo $is_edit ? 'event.php?id=' . $event_id : 'events_manage.php'; ?>" 
                       class="btn btn-outline-secondary me-md-2">
                        Cancel
                    </a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i> Save Event
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Form validation
    const form = document.getElementById('eventForm');
    
    form.addEventListener('submit', function(e) {
        // Client-side validation
        const startTime = document.getElementById('start_time').value;
        const endTime = document.getElementById('end_time').value;
        
        if (startTime && endTime && startTime >= endTime) {
            e.preventDefault();
            alert('End time must be after start time');
            return false;
        }
        
        return true;
    });
    
    // Set minimum date to today
    const today = new Date().toISOString().split('T')[0];
    document.getElementById('event_date').min = today;
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
