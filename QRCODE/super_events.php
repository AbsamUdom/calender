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
if (($user['role'] ?? 'user') !== 'super') {
  header('Location: index.php');
  exit;
}

// Render the same Events UI/logic, but only accessible to super admin
require __DIR__ . '/events.php';
