<?php
require_once __DIR__ . '/db.php';

try {
    $db = get_db();
    
    // Add role column if it doesn't exist
    $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS role VARCHAR(20) NOT NULL DEFAULT 'user'");
    
    // Update admin user (replace 'admin@example.com' with your admin email)
    $stmt = $db->prepare("UPDATE users SET role = 'admin' WHERE email = ?");
    $stmt->execute(['admin@example.com']);
    
    echo "Database updated successfully!\n";
    echo "Admin role has been set for admin@example.com\n";
    
} catch (PDOException $e) {
    die("Error updating database: " . $e->getMessage() . "\n");
}

echo "You can now access this file in your browser or run it via command line.\n";
?>
