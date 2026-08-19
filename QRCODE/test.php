<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Test database connection
try {
    require __DIR__ . '/db.php';
    $db = get_db();
    echo "<p style='color: green;'>✅ Database connection successful!</p>";
    
    // Test query
    $stmt = $db->query("SELECT DATABASE() as db");
    $dbName = $stmt->fetch(PDO::FETCH_ASSOC)['db'];
    echo "<p>Connected to database: <strong>$dbName</strong></p>";
    
} catch (PDOException $e) {
    die("<p style='color: red;'>❌ Database connection failed: " . $e->getMessage() . "</p>");
}

// Test required files
$requiredFiles = [
    'db.php' => file_exists(__DIR__ . '/db.php'),
    'auth.php' => file_exists(__DIR__ . '/auth.php'),
    'config.php' => file_exists(__DIR__ . '/config.php')
];

echo "<h2>Required Files:</h2>";
echo "<ul>";
foreach ($requiredFiles as $file => $exists) {
    $status = $exists ? "✅" : "❌";
    echo "<li>$status $file</li>";
}
echo "</ul>";

// Test PHP version
echo "<h2>PHP Version: " . phpversion() . "</h2>";

// Test session
session_start();
if (session_status() === PHP_SESSION_ACTIVE) {
    echo "<p style='color: green;'>✅ Sessions are working</p>";
} else {
    echo "<p style='color: red;'>❌ Sessions are not working</p>";
}
?>

<h2>PHP Info</h2>
<?php
// Uncomment the line below to see detailed PHP configuration
// phpinfo();
?>

<style>
    body {
        font-family: Arial, sans-serif;
        line-height: 1.6;
        margin: 20px;
        max-width: 800px;
        margin: 0 auto;
        padding: 20px;
    }
    h1, h2 {
        color: #333;
    }
    ul {
        list-style: none;
        padding: 0;
    }
    li {
        padding: 5px 0;
    }
</style>
