<?php
require_once __DIR__ . '/config.php';

if (!function_exists('get_db')) {
function get_db() {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    $charset = 'utf8mb4';
    $dsn = "mysql:host={$GLOBALS['DB_HOST']};dbname={$GLOBALS['DB_NAME']};charset=$charset";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    try {
        $pdo = new PDO($dsn, $GLOBALS['DB_USER'], $GLOBALS['DB_PASS'], $options);
    } catch (PDOException $e) {
        @ini_set('log_errors', '1');
        @error_log('[DB] Connection failed: ' . $e->getMessage());
        if (isset($_GET['debug']) && $_GET['debug'] == '1') {
            http_response_code(500);
            echo 'Database connection failed: ' . htmlspecialchars($e->getMessage());
        } else {
            http_response_code(500);
            echo 'A server error occurred. Please try again later.';
        }
        exit;
    }

    // Initialize database schema
    db_initialize_database_schema($pdo);
    
    // Fix database schema and foreign key constraints
    db_fix_database_schema($pdo);
    
    return $pdo;
}
}

function db_initialize_database_schema($pdo) {
    try {
        // Create users table first
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(20) NOT NULL DEFAULT 'user',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        if (!db_has_column($pdo, 'users', 'phone')) {
            $pdo->exec("ALTER TABLE users ADD COLUMN phone VARCHAR(50) NULL AFTER email");
        }
        if (!db_has_column($pdo, 'users', 'is_active')) {
            $pdo->exec("ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER role");
        }

        // Ensure default user exists
        db_ensure_default_user($pdo);

        // Create events table
        $pdo->exec("CREATE TABLE IF NOT EXISTS events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            date DATE NULL,
            end_date DATE NULL,
            location VARCHAR(255) NULL,
            secret_code VARCHAR(64) NOT NULL,
            client VARCHAR(255) NULL,
            coordinator_id INT NULL,
            coordinator VARCHAR(255) NULL,
            graphics VARCHAR(255) NULL,
            supervisor VARCHAR(255) NULL,
            head VARCHAR(255) NULL,
            remarks TEXT NULL,
            status VARCHAR(50) NULL,
            client_status VARCHAR(50) NULL,
            efd VARCHAR(50) NULL,
            amount DECIMAL(12,2) NULL,
            advance DECIMAL(12,2) NULL,
            balance DECIMAL(12,2) NULL,
            quote_file VARCHAR(255) NULL,
            checklist_file VARCHAR(255) NULL,
            mockup_file VARCHAR(255) NULL,
            quote_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            quote_reviewed_by INT NULL,
            quote_reviewed_at DATETIME NULL,
            quote_finance_comment TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_events_user_id (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        if (!db_has_column($pdo, 'events', 'coordinator_id')) {
            $pdo->exec("ALTER TABLE events ADD COLUMN coordinator_id INT NULL AFTER client");
        }

        // Add columns for multiple selections
        if (!db_has_column($pdo, 'events', 'coordinators')) {
            $pdo->exec("ALTER TABLE events ADD COLUMN coordinators JSON NULL AFTER coordinator");
        }
        if (!db_has_column($pdo, 'events', 'graphics_users')) {
            $pdo->exec("ALTER TABLE events ADD COLUMN graphics_users JSON NULL AFTER graphics");
        }
        if (!db_has_column($pdo, 'events', 'supervisors')) {
            $pdo->exec("ALTER TABLE events ADD COLUMN supervisors JSON NULL AFTER supervisor");
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS release_orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_id INT NOT NULL,
            uploaded_by INT NOT NULL,
            file_path VARCHAR(255) NULL,
            ro_from VARCHAR(255) NULL,
            ro_to VARCHAR(255) NULL,
            ro_date DATE NULL,
            status ENUM('draft','submitted','approved','rejected') NOT NULL DEFAULT 'draft',
            submitted_at DATETIME NULL,
            reviewed_by INT NULL,
            reviewed_at DATETIME NULL,
            finance_comment TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_release_orders_event (event_id),
            INDEX idx_release_orders_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS release_order_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            release_order_id INT NOT NULL,
            item_no INT NULL,
            particulars VARCHAR(255) NULL,
            qty VARCHAR(50) NULL,
            comment VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ro_items_ro_id (release_order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        try {
            $pdo->exec("ALTER TABLE release_orders MODIFY COLUMN file_path VARCHAR(255) NULL");
        } catch (Throwable $e) {
            // ignore
        }

        if (!db_has_column($pdo, 'release_orders', 'ro_from')) {
            $pdo->exec("ALTER TABLE release_orders ADD COLUMN ro_from VARCHAR(255) NULL");
        }
        if (!db_has_column($pdo, 'release_orders', 'ro_to')) {
            $pdo->exec("ALTER TABLE release_orders ADD COLUMN ro_to VARCHAR(255) NULL");
        }
        if (!db_has_column($pdo, 'release_orders', 'ro_date')) {
            $pdo->exec("ALTER TABLE release_orders ADD COLUMN ro_date DATE NULL");
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS purchase_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_id INT NULL,
            requested_by INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            amount DECIMAL(12,2) NULL,
            description TEXT NULL,
            status ENUM('draft','acct_submitted','finance_submitted','approved','rejected') NOT NULL DEFAULT 'draft',
            submitted_at DATETIME NULL,
            reviewed_by INT NULL,
            reviewed_at DATETIME NULL,
            finance_comment TEXT NULL,
            accountant_reviewed_by INT NULL,
            accountant_reviewed_at DATETIME NULL,
            accountant_comment TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_purchase_requests_event (event_id),
            INDEX idx_purchase_requests_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS purchase_request_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            purchase_request_id INT NOT NULL,
            item_no INT NULL,
            material VARCHAR(255) NULL,
            color VARCHAR(100) NULL,
            size VARCHAR(100) NULL,
            quantity DECIMAL(12,2) NULL,
            unit_price DECIMAL(12,2) NULL,
            line_total DECIMAL(12,2) NULL,
            supplier VARCHAR(255) NULL,
            accountant_decision ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            accountant_comment TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_pr_items_pr_id (purchase_request_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        if (!db_has_column($pdo, 'purchase_request_items', 'color')) {
            $pdo->exec("ALTER TABLE purchase_request_items ADD COLUMN color VARCHAR(100) NULL AFTER material");
        }
        if (!db_has_column($pdo, 'purchase_request_items', 'size')) {
            $pdo->exec("ALTER TABLE purchase_request_items ADD COLUMN size VARCHAR(100) NULL AFTER color");
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS rental_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_id INT NULL,
            requested_by INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            amount_exc DECIMAL(12,2) NULL,
            vat_amount DECIMAL(12,2) NULL,
            amount_inc DECIMAL(12,2) NULL,
            amount_paid DECIMAL(12,2) NULL,
            balance DECIMAL(12,2) NULL,
            status ENUM('draft','submitted','approved','rejected') NOT NULL DEFAULT 'draft',
            submitted_at DATETIME NULL,
            reviewed_by INT NULL,
            reviewed_at DATETIME NULL,
            finance_comment TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_rental_requests_event (event_id),
            INDEX idx_rental_requests_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS rental_request_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            rental_request_id INT NOT NULL,
            item_no INT NULL,
            supplier VARCHAR(255) NULL,
            item VARCHAR(255) NULL,
            amount_exc DECIMAL(12,2) NULL,
            vat_amount DECIMAL(12,2) NULL,
            amount_inc DECIMAL(12,2) NULL,
            amount_paid DECIMAL(12,2) NULL,
            balance DECIMAL(12,2) NULL,
            remarks VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_rental_items_req_id (rental_request_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Job Forms workflow (Operation -> Coordinator -> Finance)
        $pdo->exec("CREATE TABLE IF NOT EXISTS job_forms (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_id INT NOT NULL,
            prepared_by INT NOT NULL,
            coordinator_id INT NOT NULL,
            status ENUM('draft','submitted','coordinator_approved','coordinator_rejected','finance_approved','finance_rejected') NOT NULL DEFAULT 'draft',
            submitted_at DATETIME NULL,
            labour_rows LONGTEXT NULL,
            job_form_no VARCHAR(50) NULL,
            supervisor_incharge VARCHAR(255) NULL,
            a_supervisor VARCHAR(255) NULL,
            received_by VARCHAR(255) NULL,
            given_by VARCHAR(255) NULL,
            approved_by VARCHAR(255) NULL,
            coordinator_reviewed_by INT NULL,
            coordinator_reviewed_at DATETIME NULL,
            coordinator_comment TEXT NULL,
            finance_reviewed_by INT NULL,
            finance_reviewed_at DATETIME NULL,
            finance_comment TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_job_forms_event (event_id),
            INDEX idx_job_forms_status (status),
            INDEX idx_job_forms_coordinator (coordinator_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        if (!db_has_column($pdo, 'job_forms', 'labour_rows')) {
            $pdo->exec("ALTER TABLE job_forms ADD COLUMN labour_rows LONGTEXT NULL AFTER submitted_at");
        }

        if (!db_has_column($pdo, 'job_forms', 'job_form_no')) {
            $pdo->exec("ALTER TABLE job_forms ADD COLUMN job_form_no VARCHAR(50) NULL AFTER labour_rows");
        }
        if (!db_has_column($pdo, 'job_forms', 'supervisor_incharge')) {
            $pdo->exec("ALTER TABLE job_forms ADD COLUMN supervisor_incharge VARCHAR(255) NULL AFTER job_form_no");
        }
        if (!db_has_column($pdo, 'job_forms', 'a_supervisor')) {
            $pdo->exec("ALTER TABLE job_forms ADD COLUMN a_supervisor VARCHAR(255) NULL AFTER supervisor_incharge");
        }
        if (!db_has_column($pdo, 'job_forms', 'received_by')) {
            $pdo->exec("ALTER TABLE job_forms ADD COLUMN received_by VARCHAR(255) NULL AFTER a_supervisor");
        }
        if (!db_has_column($pdo, 'job_forms', 'given_by')) {
            $pdo->exec("ALTER TABLE job_forms ADD COLUMN given_by VARCHAR(255) NULL AFTER received_by");
        }
        if (!db_has_column($pdo, 'job_forms', 'approved_by')) {
            $pdo->exec("ALTER TABLE job_forms ADD COLUMN approved_by VARCHAR(255) NULL AFTER given_by");
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS job_form_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            job_form_id INT NOT NULL,
            item_no INT NULL,
            item_name VARCHAR(255) NULL,
            qty DECIMAL(12,2) NULL,
            notes VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_job_form_items_job_form (job_form_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // If purchase_requests table existed before, ensure status enum + accountant columns exist.
        try {
            $pdo->exec("ALTER TABLE purchase_requests MODIFY COLUMN status ENUM('draft','acct_submitted','finance_submitted','approved','rejected') NOT NULL DEFAULT 'draft'");
        } catch (Throwable $e) {
            // ignore
        }

        if (!db_has_column($pdo, 'purchase_requests', 'accountant_reviewed_by')) {
            $pdo->exec("ALTER TABLE purchase_requests ADD COLUMN accountant_reviewed_by INT NULL");
        }
        if (!db_has_column($pdo, 'purchase_requests', 'accountant_reviewed_at')) {
            $pdo->exec("ALTER TABLE purchase_requests ADD COLUMN accountant_reviewed_at DATETIME NULL");
        }
        if (!db_has_column($pdo, 'purchase_requests', 'accountant_comment')) {
            $pdo->exec("ALTER TABLE purchase_requests ADD COLUMN accountant_comment TEXT NULL");
        }

        if (!db_has_column($pdo, 'purchase_request_items', 'accountant_decision')) {
            $pdo->exec("ALTER TABLE purchase_request_items ADD COLUMN accountant_decision ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending'");
        }
        if (!db_has_column($pdo, 'purchase_request_items', 'accountant_comment')) {
            $pdo->exec("ALTER TABLE purchase_request_items ADD COLUMN accountant_comment TEXT NULL");
        }

        if (!db_has_column($pdo, 'purchase_requests', 'department')) {
            $pdo->exec("ALTER TABLE purchase_requests ADD COLUMN department VARCHAR(50) NULL");
        }

        // Store receiving tracking (minimal fields)
        if (!db_has_column($pdo, 'purchase_requests', 'receiving_status')) {
            $pdo->exec("ALTER TABLE purchase_requests ADD COLUMN receiving_status ENUM('pending','partial','received') NOT NULL DEFAULT 'pending'");
        }
        if (!db_has_column($pdo, 'purchase_requests', 'receiving_updated_by')) {
            $pdo->exec("ALTER TABLE purchase_requests ADD COLUMN receiving_updated_by INT NULL");
        }
        if (!db_has_column($pdo, 'purchase_requests', 'receiving_updated_at')) {
            $pdo->exec("ALTER TABLE purchase_requests ADD COLUMN receiving_updated_at DATETIME NULL");
        }
        if (!db_has_column($pdo, 'purchase_requests', 'receiving_comment')) {
            $pdo->exec("ALTER TABLE purchase_requests ADD COLUMN receiving_comment TEXT NULL");
        }

        // Create attendees table
        $pdo->exec("CREATE TABLE IF NOT EXISTS attendees (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NULL,
            phone VARCHAR(50) NULL,
            token VARCHAR(64) NOT NULL UNIQUE,
            attended TINYINT(1) DEFAULT 0,
            attended_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            event_id INT NULL,
            invite_token VARCHAR(64) UNIQUE NULL,
            auth_token VARCHAR(64) UNIQUE NULL,
            rsvp_confirmed TINYINT(1) DEFAULT 0,
            rsvp_at DATETIME NULL,
            INDEX idx_attendees_event_id (event_id),
            INDEX idx_attendees_token (token)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Activities audit table with additional columns
        $pdo->exec("CREATE TABLE IF NOT EXISTS activities (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            action VARCHAR(100) NOT NULL,
            details TEXT NULL,
            ip_address VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_activities_user_id (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        if (!db_has_column($pdo, 'activities', 'ip_address')) {
            $pdo->exec("ALTER TABLE activities ADD COLUMN ip_address VARCHAR(45) NULL");
        }
        if (!db_has_column($pdo, 'activities', 'user_agent')) {
            $pdo->exec("ALTER TABLE activities ADD COLUMN user_agent VARCHAR(255) NULL");
        }

        // Graphic requests table - for Sales to request graphic support
        $pdo->exec("CREATE TABLE IF NOT EXISTS graphic_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_id INT NOT NULL,
            requested_by INT NOT NULL,
            assigned_to INT NULL,
            assigned_by INT NULL,
            status ENUM('pending', 'assigned', 'in_progress', 'completed', 'cancelled') DEFAULT 'pending',
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            assigned_at DATETIME NULL,
            started_at DATETIME NULL,
            completed_at DATETIME NULL,
            INDEX idx_graphic_requests_event (event_id),
            INDEX idx_graphic_requests_assigned (assigned_to),
            INDEX idx_graphic_requests_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        // Add started_at column if missing
        if (!db_has_column($pdo, 'graphic_requests', 'started_at')) {
            $pdo->exec("ALTER TABLE graphic_requests ADD COLUMN started_at DATETIME NULL AFTER assigned_at");
        }

        // Supervisor requests table - for Sales to request supervisor support
        $pdo->exec("CREATE TABLE IF NOT EXISTS supervisor_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_id INT NOT NULL,
            requested_by INT NOT NULL,
            assigned_to INT NULL,
            assigned_by INT NULL,
            status ENUM('pending', 'assigned', 'in_progress', 'completed', 'cancelled') DEFAULT 'pending',
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            assigned_at DATETIME NULL,
            started_at DATETIME NULL,
            completed_at DATETIME NULL,
            INDEX idx_supervisor_requests_event (event_id),
            INDEX idx_supervisor_requests_assigned (assigned_to),
            INDEX idx_supervisor_requests_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Print tasks table - for tracking items/jobs that need printing (from Graphic or Sales)
        $pdo->exec("CREATE TABLE IF NOT EXISTS print_tasks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_id INT NOT NULL,
            task_type VARCHAR(50) NULL,
            source ENUM('sales','graphic','other') NOT NULL DEFAULT 'graphic',
            title VARCHAR(255) NOT NULL,
            qty INT NULL,
            status ENUM('received','in_progress','printed','delivered') NOT NULL DEFAULT 'received',
            notes TEXT NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_by INT NULL,
            updated_at DATETIME NULL,
            INDEX idx_print_tasks_event (event_id),
            INDEX idx_print_tasks_status (status),
            INDEX idx_print_tasks_type (task_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS supervisor_reports (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_id INT NOT NULL,
            supervisor_user_id INT NOT NULL,
            execution_status ENUM('not_started','in_progress','completed') NOT NULL DEFAULT 'not_started',
            report_text TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_supervisor_reports_event (event_id),
            INDEX idx_supervisor_reports_supervisor (supervisor_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Event actions table - for tracking event cancellations and postponements
        $pdo->exec("CREATE TABLE IF NOT EXISTS event_actions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_id INT NOT NULL,
            action_type ENUM('cancel', 'postpone') NOT NULL,
            requested_by INT NOT NULL,
            comment TEXT NULL,
            status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
            reviewed_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            reviewed_at DATETIME NULL,
            INDEX idx_event_actions_event (event_id),
            INDEX idx_event_actions_status (status),
            INDEX idx_event_actions_type (action_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS cashier_expenses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_id INT NOT NULL,
            source_type VARCHAR(30) NOT NULL DEFAULT 'manual',
            source_id INT NULL,
            description TEXT NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            status ENUM('unpaid','paid') NOT NULL DEFAULT 'unpaid',
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            paid_at DATETIME NULL,
            UNIQUE KEY uq_cashier_expense_source (source_type, source_id),
            INDEX idx_cashier_expense_event (event_id),
            INDEX idx_cashier_expense_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS cashier_payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            expense_id INT NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            payment_method VARCHAR(30) NOT NULL,
            payee VARCHAR(255) NOT NULL,
            reference_no VARCHAR(100) NULL,
            payment_date DATE NOT NULL,
            paid_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_cashier_payment_expense (expense_id),
            INDEX idx_cashier_payment_date (payment_date),
            INDEX idx_cashier_payment_user (paid_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $descriptionColumn = $pdo->query("SHOW COLUMNS FROM cashier_expenses LIKE 'description'")->fetch(PDO::FETCH_ASSOC);
        if ($descriptionColumn && stripos((string)($descriptionColumn['Type'] ?? ''), 'text') === false) {
            $pdo->exec("ALTER TABLE cashier_expenses MODIFY description TEXT NOT NULL");
        }
        $paymentUniqueIndex = $pdo->query("SHOW INDEX FROM cashier_payments WHERE Key_name = 'uq_cashier_payment_expense'")->fetch(PDO::FETCH_ASSOC);
        if ($paymentUniqueIndex) {
            $pdo->exec("ALTER TABLE cashier_payments DROP INDEX uq_cashier_payment_expense");
        }
        $paymentExpenseIndex = $pdo->query("SHOW INDEX FROM cashier_payments WHERE Key_name = 'idx_cashier_payment_expense'")->fetch(PDO::FETCH_ASSOC);
        if (!$paymentExpenseIndex) {
            $pdo->exec("ALTER TABLE cashier_payments ADD INDEX idx_cashier_payment_expense (expense_id)");
        }

        // Add any missing columns to events table
        db_add_missing_columns($pdo);

    } catch (Throwable $e) {
        @error_log('[DB] Schema initialization failed: ' . $e->getMessage());
    }
}

function db_fix_database_schema($pdo) {
    // Skip foreign key operations - they cause issues on some MySQL versions
    // The tables work fine without explicit foreign key constraints
    return;
}

function db_ensure_default_user($pdo) {
    // Check if any user exists
    $stmt = $pdo->query("SELECT id FROM users LIMIT 1");
    $user = $stmt->fetch();
    
    if (!$user) {
        // Create a default admin user
        $default_password = password_hash('admin123', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)");
        $stmt->execute(['Administrator', 'admin@example.com', $default_password, 'admin']);
        
        error_log('Default administrator account created');
        return $pdo->lastInsertId();
    }
    
    return $user['id'];
}

function db_add_missing_columns($pdo) {
    $columns = [
        'supervisor' => 'VARCHAR(255) NULL',
        'head' => 'VARCHAR(255) NULL',
        'efd' => 'VARCHAR(50) NULL',
        'amount' => 'DECIMAL(12,2) NULL',
        'advance' => 'DECIMAL(12,2) NULL',
        'balance' => 'DECIMAL(12,2) NULL',
        'quote_file' => 'VARCHAR(255) NULL',
        'checklist_file' => 'VARCHAR(255) NULL',
        'mockup_file' => 'VARCHAR(255) NULL',
        'quote_status' => "ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending'",
        'quote_reviewed_by' => 'INT NULL',
        'quote_reviewed_at' => 'DATETIME NULL',
        'quote_finance_comment' => 'TEXT NULL',
        'user_id' => 'INT NOT NULL',

        'production_status' => "ENUM('queued','printing','in_production','ready','delivered') NOT NULL DEFAULT 'queued'",
        'production_updated_by' => 'INT NULL',
        'production_updated_at' => 'DATETIME NULL',
        'production_comment' => 'TEXT NULL',

        'execution_status' => "ENUM('not_started','in_progress','completed') NOT NULL DEFAULT 'not_started'",
        'execution_updated_by' => 'INT NULL',
        'execution_updated_at' => 'DATETIME NULL',
        'execution_notes' => 'TEXT NULL'
    ];
    
    foreach ($columns as $column => $definition) {
        try {
            if (!db_has_column($pdo, 'events', $column)) {
                if ($column === 'user_id') {
                    // Special handling for user_id column
                    $pdo->exec("ALTER TABLE events ADD COLUMN user_id INT NULL");
                    $default_user = $pdo->query("SELECT id FROM users LIMIT 1")->fetch();
                    if ($default_user) {
                        $pdo->exec("UPDATE events SET user_id = {$default_user['id']} WHERE user_id IS NULL");
                    }
                    $pdo->exec("ALTER TABLE events MODIFY COLUMN user_id INT NOT NULL");
                } else {
                    $pdo->exec("ALTER TABLE events ADD COLUMN $column $definition");
                }
                error_log("Added column $column to events table");
            }
        } catch (PDOException $e) {
            error_log("Error adding column $column: " . $e->getMessage());
        }
    }
}

function db_has_column(PDO $pdo, string $table, string $column): bool {
    $sql = 'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :table AND COLUMN_NAME = :col';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':db' => $GLOBALS['DB_NAME'],
        ':table' => $table,
        ':col' => $column,
    ]);
    return (int)$stmt->fetchColumn() > 0;
}

function db_create_token() {
    return bin2hex(random_bytes(16));
}

// Database-specific functions only - ALL with db_ prefix
function db_create_event($event_data) {
    $pdo = get_db();
    
    // Ensure user_id is valid
    if (empty($event_data['user_id'])) {
        throw new Exception("user_id is required for event creation");
    }
    
    // Verify the user exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND is_active = 1");
    $stmt->execute([$event_data['user_id']]);
    if (!$stmt->fetch()) {
        throw new Exception("Invalid user_id: User is inactive or does not exist");
    }
    
    // Generate secret code if not provided
    if (empty($event_data['secret_code'])) {
        $event_data['secret_code'] = db_create_token();
    }
    
    // Now create the event
    $sql = "INSERT INTO events (user_id, name, date, end_date, location, secret_code, client, coordinator, graphics, supervisor, head, remarks, status, client_status, efd, amount, advance, balance, quote_file, checklist_file, mockup_file) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $event_data['user_id'],
        $event_data['name'] ?? '',
        $event_data['date'] ?? null,
        $event_data['end_date'] ?? null,
        $event_data['location'] ?? '',
        $event_data['secret_code'],
        $event_data['client'] ?? '',
        $event_data['coordinator'] ?? '',
        $event_data['graphics'] ?? '',
        $event_data['supervisor'] ?? '',
        $event_data['head'] ?? '',
        $event_data['remarks'] ?? '',
        $event_data['status'] ?? 'pending',
        $event_data['client_status'] ?? '',
        $event_data['efd'] ?? '',
        $event_data['amount'] ?? 0,
        $event_data['advance'] ?? 0,
        $event_data['balance'] ?? 0,
        $event_data['quote_file'] ?? null,
        $event_data['checklist_file'] ?? null,
        $event_data['mockup_file'] ?? null
    ]);
    
    $event_id = $pdo->lastInsertId();
    
    // Log the activity
    db_log_activity($event_data['user_id'], 'create_event', "Created event: {$event_data['name']} (ID: $event_id)");
    
    return $event_id;
}

function db_log_activity($user_id, $action, $details = null) {
    try {
        $pdo = get_db();
        $stmt = $pdo->prepare("
            INSERT INTO activities (user_id, action, details, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?)
        ");
        if (!$stmt) {
            throw new Exception('Failed to prepare activity insert statement');
        }
        return $stmt->execute([
            $user_id,
            $action,
            $details,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);
    } catch (Throwable $e) {
        error_log('[DB] Failed to log activity: ' . $e->getMessage());
        return false;
    }
}

function db_attendee_url($token) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    return $scheme . '://' . $host . $base . '/confirm.php?token=' . urlencode($token);
}

function db_invite_url($inviteToken) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    return $scheme . '://' . $host . $base . '/invite.php?invite=' . urlencode($inviteToken);
}

function db_auth_url($authToken) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    return $scheme . '://' . $host . $base . '/confirm.php?auth=' . urlencode($authToken);
}

function db_get_user_events($user_id) {
    $pdo = get_db();
    $stmt = $pdo->prepare("SELECT * FROM events WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll();
}

function db_get_event_by_id($event_id) {
    $pdo = get_db();
    $stmt = $pdo->prepare("SELECT e.*, u.name as user_name FROM events e LEFT JOIN users u ON e.user_id = u.id WHERE e.id = ?");
    $stmt->execute([$event_id]);
    return $stmt->fetch();
}

function db_get_all_events() {
    $pdo = get_db();
    $stmt = $pdo->query("SELECT e.*, u.name as user_name FROM events e LEFT JOIN users u ON e.user_id = u.id ORDER BY e.created_at DESC");
    return $stmt->fetchAll();
}

function db_update_event($event_id, $event_data) {
    $pdo = get_db();
    
    $sql = "UPDATE events SET 
            name = ?, date = ?, end_date = ?, location = ?, client = ?, 
            coordinator = ?, graphics = ?, supervisor = ?, head = ?, 
            remarks = ?, status = ?, client_status = ?, efd = ?, 
            amount = ?, advance = ?, balance = ? 
            WHERE id = ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $event_data['name'] ?? '',
        $event_data['date'] ?? null,
        $event_data['end_date'] ?? null,
        $event_data['location'] ?? '',
        $event_data['client'] ?? '',
        $event_data['coordinator'] ?? '',
        $event_data['graphics'] ?? '',
        $event_data['supervisor'] ?? '',
        $event_data['head'] ?? '',
        $event_data['remarks'] ?? '',
        $event_data['status'] ?? 'pending',
        $event_data['client_status'] ?? '',
        $event_data['efd'] ?? '',
        $event_data['amount'] ?? 0,
        $event_data['advance'] ?? 0,
        $event_data['balance'] ?? 0,
        $event_id
    ]);
    
    return $stmt->rowCount();
}

function db_delete_event($event_id) {
    $pdo = get_db();
    $event_id = (int)$event_id;
    if ($event_id <= 0) {
        return 0;
    }

    try {
        $ownsTx = !$pdo->inTransaction();
        if ($ownsTx) {
            $pdo->beginTransaction();
        }

        $pdo->prepare("DELETE cp FROM cashier_payments cp JOIN cashier_expenses ce ON ce.id = cp.expense_id WHERE ce.event_id = ?")
            ->execute([$event_id]);
        $pdo->prepare("DELETE FROM cashier_expenses WHERE event_id = ?")
            ->execute([$event_id]);

        $pdo->prepare("DELETE roi FROM release_order_items roi JOIN release_orders ro ON roi.release_order_id = ro.id WHERE ro.event_id = ?")
            ->execute([$event_id]);
        $pdo->prepare("DELETE FROM release_orders WHERE event_id = ?")
            ->execute([$event_id]);

        $pdo->prepare("DELETE pri FROM purchase_request_items pri JOIN purchase_requests pr ON pri.purchase_request_id = pr.id WHERE pr.event_id = ?")
            ->execute([$event_id]);
        $pdo->prepare("DELETE FROM purchase_requests WHERE event_id = ?")
            ->execute([$event_id]);

        $pdo->prepare("DELETE rri FROM rental_request_items rri JOIN rental_requests rr ON rri.rental_request_id = rr.id WHERE rr.event_id = ?")
            ->execute([$event_id]);
        $pdo->prepare("DELETE FROM rental_requests WHERE event_id = ?")
            ->execute([$event_id]);

        $pdo->prepare("DELETE jfi FROM job_form_items jfi JOIN job_forms jf ON jfi.job_form_id = jf.id WHERE jf.event_id = ?")
            ->execute([$event_id]);
        $pdo->prepare("DELETE FROM job_forms WHERE event_id = ?")
            ->execute([$event_id]);

        $stmt = $pdo->prepare("DELETE FROM events WHERE id = ?");
        $stmt->execute([$event_id]);

        if ($ownsTx) {
            $pdo->commit();
        }
        return $stmt->rowCount();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function db_delete_user($user_id) {
    $pdo = get_db();
    $user_id = (int)$user_id;
    if ($user_id <= 0) {
        return 0;
    }

    try {
        $ownsTx = !$pdo->inTransaction();
        if ($ownsTx) {
            $pdo->beginTransaction();
        }

        $targetStmt = $pdo->prepare("SELECT role, is_active FROM users WHERE id = ? FOR UPDATE");
        $targetStmt->execute([$user_id]);
        $targetUser = $targetStmt->fetch(PDO::FETCH_ASSOC);
        if (!$targetUser) {
            throw new RuntimeException('User not found');
        }
        if ((int)($targetUser['is_active'] ?? 1) === 1) {
            throw new RuntimeException('Deactivate the user before permanently deleting the account');
        }
        if (($targetUser['role'] ?? '') === 'super') {
            $superRows = $pdo->query("SELECT id FROM users WHERE role = 'super' FOR UPDATE")->fetchAll(PDO::FETCH_COLUMN);
            if (count($superRows) <= 1) {
                throw new RuntimeException('Cannot delete the last super user');
            }
        }

        $st = $pdo->prepare("SELECT id FROM users WHERE id <> ? AND is_active = 1 ORDER BY (role='super') DESC, id ASC LIMIT 1 FOR UPDATE");
        $st->execute([$user_id]);
        $fallbackUserId = (int)($st->fetchColumn() ?: 0);
        if ($fallbackUserId <= 0) {
            throw new RuntimeException('An active fallback user is required before this account can be deleted');
        }

        // Preserve activities while removing the deleted user reference
        $pdo->prepare("UPDATE activities SET user_id = NULL WHERE user_id = ?")->execute([$user_id]);

        // Events created by this user: reassign to fallback user (events.user_id is NOT NULL)
        if ($fallbackUserId > 0) {
            try { $pdo->prepare("UPDATE events SET user_id = ? WHERE user_id = ?")->execute([$fallbackUserId, $user_id]); } catch (Throwable $e) {}
        }

        // NULL-out optional references in events (if column is nullable)
        try { $pdo->prepare("UPDATE events SET coordinator_id = NULL WHERE coordinator_id = ?")->execute([$user_id]); } catch (Throwable $e) {}
        try { $pdo->prepare("UPDATE events SET quote_reviewed_by = NULL WHERE quote_reviewed_by = ?")->execute([$user_id]); } catch (Throwable $e) {}
        try { $pdo->prepare("UPDATE events SET production_updated_by = NULL WHERE production_updated_by = ?")->execute([$user_id]); } catch (Throwable $e) {}
        try { $pdo->prepare("UPDATE events SET execution_updated_by = NULL WHERE execution_updated_by = ?")->execute([$user_id]); } catch (Throwable $e) {}
        try {
            $eventAssignments = $pdo->query("SELECT id, coordinators, graphics_users, supervisors FROM events")->fetchAll(PDO::FETCH_ASSOC);
            $updateAssignments = $pdo->prepare("UPDATE events SET coordinators = ?, graphics_users = ?, supervisors = ? WHERE id = ?");
            foreach ($eventAssignments as $eventAssignment) {
                $values = [];
                $changed = false;
                foreach (['coordinators', 'graphics_users', 'supervisors'] as $column) {
                    $ids = json_decode((string)($eventAssignment[$column] ?? ''), true);
                    $ids = is_array($ids) ? array_values(array_unique(array_map('intval', $ids))) : [];
                    $filteredIds = array_values(array_filter($ids, fn($id) => $id !== $user_id));
                    $changed = $changed || count($filteredIds) !== count($ids);
                    $values[] = json_encode($filteredIds);
                }
                if ($changed) {
                    $values[] = (int)$eventAssignment['id'];
                    $updateAssignments->execute($values);
                }
            }
        } catch (Throwable $e) {}

        // Release orders uploaded by user: reassign to fallback user (uploaded_by is typically NOT NULL)
        if ($fallbackUserId > 0) {
            try { $pdo->prepare("UPDATE release_orders SET uploaded_by = ? WHERE uploaded_by = ?")->execute([$fallbackUserId, $user_id]); } catch (Throwable $e) {}
        }

        // NULL-out reviewer references where possible
        try { $pdo->prepare("UPDATE release_orders SET reviewed_by = NULL WHERE reviewed_by = ?")->execute([$user_id]); } catch (Throwable $e) {}

        // Purchase requests requested by user: reassign to fallback user (requested_by is typically NOT NULL)
        if ($fallbackUserId > 0) {
            try { $pdo->prepare("UPDATE purchase_requests SET requested_by = ? WHERE requested_by = ?")->execute([$fallbackUserId, $user_id]); } catch (Throwable $e) {}
        }

        // NULL-out reviewer references where possible
        try { $pdo->prepare("UPDATE purchase_requests SET reviewed_by = NULL WHERE reviewed_by = ?")->execute([$user_id]); } catch (Throwable $e) {}
        try { $pdo->prepare("UPDATE purchase_requests SET accountant_reviewed_by = NULL WHERE accountant_reviewed_by = ?")->execute([$user_id]); } catch (Throwable $e) {}
        try { $pdo->prepare("UPDATE purchase_requests SET receiving_updated_by = NULL WHERE receiving_updated_by = ?")->execute([$user_id]); } catch (Throwable $e) {}

        // Rental requests: reassign requester (NOT NULL) and NULL reviewers where possible
        if ($fallbackUserId > 0) {
            try { $pdo->prepare("UPDATE rental_requests SET requested_by = ? WHERE requested_by = ?")->execute([$fallbackUserId, $user_id]); } catch (Throwable $e) {}
        }
        try { $pdo->prepare("UPDATE rental_requests SET reviewed_by = NULL WHERE reviewed_by = ?")->execute([$user_id]); } catch (Throwable $e) {}

        // Graphic requests: reassign requester (NOT NULL), NULL assignment fields when possible
        if ($fallbackUserId > 0) {
            try { $pdo->prepare("UPDATE graphic_requests SET requested_by = ? WHERE requested_by = ?")->execute([$fallbackUserId, $user_id]); } catch (Throwable $e) {}
        }
        try { $pdo->prepare("UPDATE graphic_requests SET assigned_to = NULL WHERE assigned_to = ?")->execute([$user_id]); } catch (Throwable $e) {}
        try { $pdo->prepare("UPDATE graphic_requests SET assigned_by = NULL WHERE assigned_by = ?")->execute([$user_id]); } catch (Throwable $e) {}

        // Supervisor requests: reassign requester (NOT NULL), NULL assignment fields when possible
        if ($fallbackUserId > 0) {
            try { $pdo->prepare("UPDATE supervisor_requests SET requested_by = ? WHERE requested_by = ?")->execute([$fallbackUserId, $user_id]); } catch (Throwable $e) {}
        }
        try { $pdo->prepare("UPDATE supervisor_requests SET assigned_to = NULL WHERE assigned_to = ?")->execute([$user_id]); } catch (Throwable $e) {}
        try { $pdo->prepare("UPDATE supervisor_requests SET assigned_by = NULL WHERE assigned_by = ?")->execute([$user_id]); } catch (Throwable $e) {}

        // Supervisor reports: reassign supervisor_user_id (NOT NULL)
        if ($fallbackUserId > 0) {
            try { $pdo->prepare("UPDATE supervisor_reports SET supervisor_user_id = ? WHERE supervisor_user_id = ?")->execute([$fallbackUserId, $user_id]); } catch (Throwable $e) {}
        }

        // Event actions: reassign requested_by (NOT NULL); null out reviewer references where possible
        if ($fallbackUserId > 0) {
            try { $pdo->prepare("UPDATE event_actions SET requested_by = ? WHERE requested_by = ?")->execute([$fallbackUserId, $user_id]); } catch (Throwable $e) {}
        }
        try {
            $pdo->prepare("UPDATE event_actions SET reviewed_by = NULL WHERE reviewed_by = ?")->execute([$user_id]);
        } catch (Throwable $e) {}

        // Preserve print tasks while removing the deleted user references
        try { $pdo->prepare("UPDATE print_tasks SET created_by = NULL WHERE created_by = ?")->execute([$user_id]); } catch (Throwable $e) {}
        try { $pdo->prepare("UPDATE print_tasks SET updated_by = NULL WHERE updated_by = ?")->execute([$user_id]); } catch (Throwable $e) {}

        // Job forms prepared by / assigned to this user: reassign (prepared_by/coordinator_id are NOT NULL)
        if ($fallbackUserId > 0) {
            try { $pdo->prepare("UPDATE job_forms SET prepared_by = ? WHERE prepared_by = ?")->execute([$fallbackUserId, $user_id]); } catch (Throwable $e) {}
            try { $pdo->prepare("UPDATE job_forms SET coordinator_id = ? WHERE coordinator_id = ?")->execute([$fallbackUserId, $user_id]); } catch (Throwable $e) {}
        }

        // NULL reviewer references in job_forms (if nullable)
        try { $pdo->prepare("UPDATE job_forms SET coordinator_reviewed_by = NULL WHERE coordinator_reviewed_by = ?")->execute([$user_id]); } catch (Throwable $e) {}
        try { $pdo->prepare("UPDATE job_forms SET finance_reviewed_by = NULL WHERE finance_reviewed_by = ?")->execute([$user_id]); } catch (Throwable $e) {}

        // Generic fallback cleanup for any additional tables/columns not covered above.
        // Best-effort: try to NULL references in nullable columns so the user row can be deleted.
        try {
            $candidateCols = [
                'user_id',
                'created_by',
                'updated_by',
                'paid_by',
                'requested_by',
                'reviewed_by',
                'uploaded_by',
                'assigned_to',
                'assigned_by',
                'prepared_by',
                'coordinator_id',
                'coordinator_reviewed_by',
                'finance_reviewed_by',
                'accountant_reviewed_by',
                'receiving_updated_by',
                'quote_reviewed_by',
                'production_updated_by',
                'execution_updated_by',
                'supervisor_user_id'
            ];
            $in = implode(',', array_fill(0, count($candidateCols), '?'));
            $stmtCols = $pdo->prepare(
                "SELECT TABLE_NAME, COLUMN_NAME, IS_NULLABLE\n" .
                "FROM INFORMATION_SCHEMA.COLUMNS\n" .
                "WHERE TABLE_SCHEMA = ?\n" .
                "  AND COLUMN_NAME IN ($in)\n" .
                "  AND TABLE_NAME <> 'users'"
            );
            $stmtCols->execute(array_merge([$GLOBALS['DB_NAME']], $candidateCols));
            $cols = $stmtCols->fetchAll(PDO::FETCH_ASSOC);
            foreach ($cols as $c) {
                $table = $c['TABLE_NAME'] ?? '';
                $col = $c['COLUMN_NAME'] ?? '';
                $nullable = strtoupper((string)($c['IS_NULLABLE'] ?? 'YES')) === 'YES';
                if ($table === '' || $col === '') {
                    continue;
                }
                if ($nullable) {
                    try {
                        $pdo->prepare("UPDATE `$table` SET `$col` = NULL WHERE `$col` = ?")->execute([$user_id]);
                    } catch (Throwable $e) {}
                }
            }
        } catch (Throwable $e) {
            // ignore
        }

        // Delete the user only after related references have been cleaned.
        // Keep cleanup and the final delete in the same transaction.
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $deleted = $stmt->rowCount();
        if ($deleted !== 1) {
            throw new RuntimeException('The user record could not be deleted');
        }

        if ($ownsTx && $pdo->inTransaction()) {
            $pdo->commit();
        }
        return $deleted;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

// Database query functions only - ALL with db_ prefix
function db_get_user_by_id($user_id) {
    $pdo = get_db();
    $stmt = $pdo->prepare("SELECT id, name, email, role, is_active FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    return $stmt->fetch();
}

function db_get_user_by_email($email) {
    $pdo = get_db();
    $stmt = $pdo->prepare("SELECT id, name, email, password_hash, role, is_active FROM users WHERE email = ?");
    $stmt->execute([$email]);
    return $stmt->fetch();
}

function db_verify_user_password($email, $password) {
    $user = db_get_user_by_email($email);
    if ($user && (int)($user['is_active'] ?? 1) === 1 && password_verify($password, $user['password_hash'])) {
        return $user;
    }
    return false;
}

function db_is_user_active($user_id) {
    $pdo = get_db();
    $stmt = $pdo->prepare("SELECT 1 FROM users WHERE id = ? AND is_active = 1");
    $stmt->execute([(int)$user_id]);
    return (bool)$stmt->fetchColumn();
}

function db_create_user($user_data) {
    $pdo = get_db();
    
    $sql = "INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $user_data['name'],
        $user_data['email'],
        password_hash($user_data['password'], PASSWORD_DEFAULT),
        $user_data['role'] ?? 'user'
    ]);
    
    return $pdo->lastInsertId();
}

function db_get_activities($limit = 50) {
    $pdo = get_db();
    $stmt = $pdo->prepare("
        SELECT a.*, u.name as user_name 
        FROM activities a 
        LEFT JOIN users u ON a.user_id = u.id 
        ORDER BY a.created_at DESC 
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    return $stmt->fetchAll();
}