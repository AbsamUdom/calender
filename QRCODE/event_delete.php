<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

// Check if user is logged in and has appropriate role
$user = current_user();
if (!$user || !in_array($user['role'], ['admin', 'super'])) {
    set_flash_message('You do not have permission to delete events', 'danger');
    redirect('events_manage.php');
}

// Check if event ID is provided
if (!isset($_POST['id'])) {
    set_flash_message('No event specified', 'danger');
    redirect('events_manage.php');
}

$event_id = (int)$_POST['id'];
$db = get_db();

try {
    // Start transaction
    $db->beginTransaction();
    
    // Get event details for logging
    $stmt = $db->prepare("SELECT name FROM events WHERE id = ?");
    $stmt->execute([$event_id]);
    $event = $stmt->fetch();
    
    if (!$event) {
        throw new Exception('Event not found');
    }

    if (function_exists('db_delete_event')) {
        db_delete_event($event_id);
    } else {
        // Delete the event
        $stmt = $db->prepare("DELETE FROM events WHERE id = ?");
        $stmt->execute([$event_id]);
    }
    
    // Log the activity
    db_log_activity($user['id'], 'event_deleted', 'Deleted event: ' . $event['name']);
    
    // Commit transaction
    $db->commit();
    
    set_flash_message('Event deleted successfully', 'success');
    
} catch (Exception $e) {
    // Rollback transaction on error
    $db->rollBack();
    error_log('Error deleting event: ' . $e->getMessage());
    set_flash_message('An error occurred while deleting the event', 'danger');
}

redirect('events_manage.php');
