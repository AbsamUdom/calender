<?php
require __DIR__ . '/auth.php';

require_login();
$db = get_db();
$user = current_user();
$redirect = 'users.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $redirect);
    exit;
}

verify_csrf_or_abort();
$currentRole = strtolower(trim((string)($user['role'] ?? 'user')));
$currentRole = str_replace(['_', '-'], ' ', $currentRole);
$currentRole = preg_replace('/\s+/', ' ', $currentRole);
if (in_array($currentRole, ['super admin', 'superadministrator', 'super administrator'], true)) {
    $currentRole = 'super';
}

$id = (int)($_POST['id'] ?? 0);
$statusValue = (string)($_POST['is_active'] ?? '');
if (!in_array($currentRole, ['admin', 'super'], true)) {
    flash_add('error', 'Only administrators can change account status');
    header('Location: ' . $redirect);
    exit;
}
if ($id <= 0 || !in_array($statusValue, ['0', '1'], true)) {
    flash_add('error', 'Invalid account status request');
    header('Location: ' . $redirect);
    exit;
}

$isActive = (int)$statusValue;
if ($id === (int)($user['id'] ?? 0) && $isActive === 0) {
    flash_add('error', 'You cannot deactivate your own account');
    header('Location: ' . $redirect);
    exit;
}

try {
    $targetStmt = $db->prepare('SELECT name, role, is_active FROM users WHERE id = ?');
    $targetStmt->execute([$id]);
    $target = $targetStmt->fetch(PDO::FETCH_ASSOC);
    if (!$target) {
        throw new RuntimeException('User not found');
    }
    if ($currentRole !== 'super' && ($target['role'] ?? '') === 'super') {
        throw new RuntimeException('Only a super admin can change a super admin account');
    }
    if ($isActive === 0 && ($target['role'] ?? '') === 'super') {
        $activeSuperCount = (int)$db->query("SELECT COUNT(*) FROM users WHERE role = 'super' AND is_active = 1")->fetchColumn();
        if ($activeSuperCount <= 1) {
            throw new RuntimeException('Cannot deactivate the last active super admin');
        }
    }

    $statusStmt = $db->prepare('UPDATE users SET is_active = ? WHERE id = ?');
    $statusStmt->execute([$isActive, $id]);

    $verifyStmt = $db->prepare('SELECT is_active FROM users WHERE id = ?');
    $verifyStmt->execute([$id]);
    $savedStatus = $verifyStmt->fetchColumn();
    if ($savedStatus === false || (int)$savedStatus !== $isActive) {
        throw new RuntimeException('Account status could not be updated');
    }

    db_log_activity((int)($user['id'] ?? 0), $isActive ? 'user_activate' : 'user_deactivate', json_encode(['id' => $id, 'name' => $target['name'] ?? '']));
    flash_add('success', $isActive ? 'User activated successfully' : 'User deactivated successfully');
} catch (Throwable $e) {
    error_log('[user_status.php] ' . $e->getMessage());
    flash_add('error', $e instanceof RuntimeException ? $e->getMessage() : 'Account status could not be updated');
}

header('Location: ' . $redirect);
exit;
