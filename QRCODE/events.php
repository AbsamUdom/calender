<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if required files exist
$db_file = __DIR__ . '/db.php';
$auth_file = __DIR__ . '/auth.php';

if (!file_exists($db_file)) {
    die("Error: Database configuration file (db.php) not found.");
}

if (!file_exists($auth_file)) {
    die("Error: Authentication file (auth.php) not found.");
}

try {
    require_once $db_file;
    require_once $auth_file;
} catch (Exception $e) {
    die("Error loading required files: " . $e->getMessage());
}

// Optional: enable PhpSpreadsheet if installed
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require __DIR__ . '/vendor/autoload.php';
}

// Handle logout
if (isset($_GET['logout'])) {
    if (function_exists('logout_user')) {
        logout_user();
    }
    header('Location: login.php');
    exit;
}

// Check if user is logged in
if (!function_exists('require_login')) {
    die("Error: Authentication functions not available.");
}

require_login();

// Ensure uploads directory exists for event assets
$eventUploadDir = __DIR__ . '/uploads/events';
if (!is_dir($eventUploadDir)) {
    @mkdir($eventUploadDir, 0755, true);
}

if (!function_exists('handle_event_file_upload')) {
    function handle_event_file_upload(string $fieldName, string $uploadDir): ?string {
        if (!isset($_FILES[$fieldName]) || !is_array($_FILES[$fieldName])) {
            return null;
        }
        $file = $_FILES[$fieldName];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new Exception('Failed to upload file for ' . str_replace('_', ' ', $fieldName));
        }

        $allowedExtensions = ['pdf','doc','docx','xls','xlsx','ppt','pptx','png','jpg','jpeg','gif'];
        $originalName = $file['name'] ?? 'file';
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($extension && !in_array($extension, $allowedExtensions, true)) {
            throw new Exception('Unsupported file type for ' . str_replace('_', ' ', $fieldName));
        }

        $baseName = preg_replace('/[^a-zA-Z0-9-_]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
        if ($baseName === '') {
            $baseName = 'event_file';
        }
        $uniqueSuffix = time() . '_' . bin2hex(random_bytes(4));
        $newFileName = $baseName . '_' . $uniqueSuffix . ($extension ? '.' . $extension : '');
        $targetPath = rtrim($uploadDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $newFileName;

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            throw new Exception('Unable to save file for ' . str_replace('_', ' ', $fieldName));
        }

        return 'uploads/events/' . $newFileName;
    }
}

// Initialize variables with safe defaults
$db = null;
$user = [];
$isSuper = false;
$error = '';
$info = '';
$modePreference = null;
$events = [];
$editEvent = null;
$formSticky = null;
$eventId = 0;
$operationSupervisorOnlyForm = false;

try {
    // Get database connection
    if (!function_exists('get_db')) {
        throw new Exception("Database connection function not found");
    }
    
    $db = get_db();
    if (!$db) {
        throw new Exception("Failed to connect to database");
    }

    // Get current user
    if (!function_exists('current_user')) {
        throw new Exception("User authentication function not found");
    }
    
    $user = current_user();
    if (!$user) {
        throw new Exception("User not authenticated");
    }

    $role = strtolower($user['role'] ?? 'user');
    $isSuper = ($role === 'super');
    $canManageEvents = in_array($role, ['sales', 'operation', 'admin', 'super'], true);
    $canAssignSupervisor = in_array($role, ['sales', 'operation', 'admin', 'super'], true);

    // Preload list of coordinators (sales users)
    $coordinators = [];
    try {
        $coStmt = $db->query("SELECT id, name, email FROM users WHERE role = 'sales' AND is_active = 1 ORDER BY name");
        $coordinators = $coStmt ? $coStmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Exception $e) {
        $coordinators = [];
    }

    // Preload list of supervisors (for assignment)
    $supervisors = [];
    if ($canAssignSupervisor) {
        try {
            $supStmt = $db->query("SELECT id, name, email FROM users WHERE LOWER(role) = 'supervisor' AND is_active = 1 ORDER BY name");
            $supervisors = $supStmt ? $supStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Exception $e) {
            // If users table is missing or query fails, keep supervisors list empty
            $supervisors = [];
        }
    }

    $graphicUsers = [];
    try {
        $gStmt = $db->query("SELECT id, name, email FROM users WHERE role LIKE 'graphic%' AND is_active = 1 ORDER BY name");
        $graphicUsers = $gStmt ? $gStmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Exception $e) {
        $graphicUsers = [];
    }

} catch (Exception $e) {
    $error = "Initialization error: " . $e->getMessage();
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $db) {
    try {
        // Verify CSRF token if function exists
        if (function_exists('verify_csrf_or_abort')) {
            verify_csrf_or_abort();
        }

        $parseEventDate = function ($raw) {
            $raw = trim((string)$raw);
            if ($raw === '') {
                return '';
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/', $raw)) {
                return substr($raw, 0, 10);
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
                return $raw;
            }
            if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $raw, $m)) {
                $d = (int)$m[1];
                $mo = (int)$m[2];
                $y = (int)$m[3];
                if (checkdate($mo, $d, $y)) {
                    return sprintf('%04d-%02d-%02d', $y, $mo, $d);
                }
            }
            return '__invalid__';
        };

        $requireActiveUsers = function ($ids, $team) use ($db) {
            $ids = array_values(array_unique(array_filter(array_map('intval', is_array($ids) ? $ids : []), fn($id) => $id > 0)));
            if (!$ids) {
                return;
            }
            $roleConditions = [
                'sales' => "role = 'sales'",
                'graphic' => "role LIKE 'graphic%'",
                'supervisor' => "LOWER(role) = 'supervisor'",
            ];
            if (!isset($roleConditions[$team])) {
                throw new Exception('Invalid assignment team');
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE id IN ($placeholders) AND is_active = 1 AND " . $roleConditions[$team]);
            $stmt->execute($ids);
            if ((int)$stmt->fetchColumn() !== count($ids)) {
                throw new Exception('One or more selected team members are inactive or invalid');
            }
        };

        if (!$canManageEvents && (isset($_POST['create_event']) || isset($_POST['update_event']) || isset($_POST['import_events']))) {
            throw new Exception('You do not have permission to modify events');
        }

        if (isset($_POST['update_supervisor_only'])) {
            if ($role !== 'operation') {
                throw new Exception('You do not have permission to edit supervisors');
            }

            $ev_id = (int)($_POST['ev_id'] ?? 0);
            $ev_supervisors = $_POST['ev_supervisors'] ?? [];
            $ev_supervisor = '';

            if ($ev_id <= 0) {
                throw new Exception('Invalid event');
            }

            if (!empty($ev_supervisors) && is_array($ev_supervisors)) {
                $supervisorIds = array_values(array_unique(array_filter(array_map('intval', $ev_supervisors), function ($v) { return $v > 0; })));
                if (!empty($supervisorIds)) {
                    try {
                        $placeholders = implode(',', array_fill(0, count($supervisorIds), '?'));
                        $supN = $db->prepare("SELECT name FROM users WHERE id IN ($placeholders) AND LOWER(role) = 'supervisor'");
                        $supN->execute($supervisorIds);
                        $supNames = $supN->fetchAll(PDO::FETCH_COLUMN) ?: [];
                        $ev_supervisor = implode(', ', array_map('trim', $supNames));
                    } catch (Exception $e) {
                        $ev_supervisor = '';
                    }
                }
            }

            if (function_exists('mb_strtoupper')) {
                $ev_supervisor = mb_strtoupper($ev_supervisor, 'UTF-8');
            } else {
                $ev_supervisor = strtoupper($ev_supervisor);
            }

            if (empty($ev_supervisors) || !is_array($ev_supervisors)) {
                throw new Exception('Please select at least one supervisor');
            }

            $supervisorIds = array_values(array_unique(array_filter(array_map('intval', $ev_supervisors), function ($v) { return $v > 0; })));
            if (empty($supervisorIds)) {
                throw new Exception('Please select at least one supervisor');
            }
            $requireActiveUsers($supervisorIds, 'supervisor');

            $stmt = $db->prepare('UPDATE events SET supervisor = ?, supervisors = ? WHERE id = ?');
            $stmt->execute([$ev_supervisor, json_encode($supervisorIds), $ev_id]);
            $info = 'Supervisors updated successfully.';

        } elseif (isset($_POST['create_event'])) {
            $ev_name = trim($_POST['ev_name'] ?? '');
            $ev_date_raw = trim($_POST['ev_date'] ?? '');
            $ev_end_date_raw = trim($_POST['ev_end_date'] ?? '');
            $ev_location = trim($_POST['ev_location'] ?? '');
            $ev_client = trim($_POST['ev_client'] ?? '');
            
            // Handle multiple coordinators
            $ev_coordinators = $_POST['ev_coordinators'] ?? [];
            $ev_coordinator_id = 0;
            $ev_coordinator = '';
            if (!empty($ev_coordinators) && is_array($ev_coordinators)) {
                // For backward compatibility, store the first coordinator as coordinator_id and coordinator
                $ev_coordinator_id = (int)($ev_coordinators[0] ?? 0);
                if ($ev_coordinator_id > 0) {
                    try {
                        $coN = $db->prepare("SELECT name FROM users WHERE id = ? AND role = 'sales' LIMIT 1");
                        $coN->execute([$ev_coordinator_id]);
                        $ev_coordinator = (string)($coN->fetchColumn() ?? '');
                    } catch (Exception $e) {
                        $ev_coordinator = '';
                    }
                }
            }
            
            // Handle multiple graphics users
            $ev_graphics_users = $_POST['ev_graphics_users'] ?? [];
            $ev_graphics_user_id = 0;
            $ev_graphics = '';
            if (!empty($ev_graphics_users) && is_array($ev_graphics_users)) {
                // For backward compatibility, store the first graphics user
                $ev_graphics_user_id = (int)($ev_graphics_users[0] ?? 0);
                if ($ev_graphics_user_id > 0) {
                    try {
                        $gN = $db->prepare("SELECT name FROM users WHERE id = ? AND role = 'graphic' LIMIT 1");
                        $gN->execute([$ev_graphics_user_id]);
                        $ev_graphics = (string)($gN->fetchColumn() ?? '');
                    } catch (Exception $e) {
                        $ev_graphics = '';
                    }
                }
            }
            
            // Handle multiple supervisors
            $ev_supervisors = $_POST['ev_supervisors'] ?? [];
            $ev_supervisor = trim($_POST['ev_supervisor'] ?? '');
            if (!empty($ev_supervisors) && is_array($ev_supervisors)) {
                // For backward compatibility, create comma-separated list
                $supervisorIds = array_filter($ev_supervisors, 'is_numeric');
                if (!empty($supervisorIds)) {
                    try {
                        $placeholders = str_repeat('?,', count($supervisorIds) - 1) . '?';
                        $supN = $db->prepare("SELECT name FROM users WHERE id IN ($placeholders) AND role = 'supervisor'");
                        $supN->execute($supervisorIds);
                        $supNames = $supN->fetchAll(PDO::FETCH_COLUMN);
                        $ev_supervisor = implode(', ', $supNames);
                    } catch (Exception $e) {
                        $ev_supervisor = '';
                    }
                }
            }

            $requireActiveUsers($ev_coordinators, 'sales');
            $requireActiveUsers($ev_graphics_users, 'graphic');
            $requireActiveUsers($ev_supervisors, 'supervisor');
            
            $ev_suppliers = trim($_POST['ev_suppliers'] ?? '');
            $ev_head = trim($_POST['ev_head'] ?? '');
            $ev_remarks = trim($_POST['ev_remarks'] ?? '');
            $ev_status = trim($_POST['ev_status'] ?? '');
            $ev_client_status = trim($_POST['ev_client_status'] ?? '');
            $ev_efd = trim($_POST['ev_efd'] ?? '');

            if ($ev_coordinator_id <= 0) {
                $error = 'At least one coordinator is required';
                $modePreference = 'form';
            }

            // Supervisor selection is allowed for roles covered by $canAssignSupervisor

            // Normalize main text fields to uppercase before saving
            if (function_exists('mb_strtoupper')) {
                $ev_name          = mb_strtoupper($ev_name, 'UTF-8');
                $ev_location      = mb_strtoupper($ev_location, 'UTF-8');
                $ev_client        = mb_strtoupper($ev_client, 'UTF-8');
                $ev_coordinator   = mb_strtoupper($ev_coordinator, 'UTF-8');
                $ev_graphics      = mb_strtoupper($ev_graphics, 'UTF-8');
                $ev_supervisor    = mb_strtoupper($ev_supervisor, 'UTF-8');
                $ev_suppliers     = mb_strtoupper($ev_suppliers, 'UTF-8');
                $ev_head          = mb_strtoupper($ev_head, 'UTF-8');
                $ev_remarks       = mb_strtoupper($ev_remarks, 'UTF-8');
                $ev_status        = mb_strtoupper($ev_status, 'UTF-8');
                $ev_client_status = mb_strtoupper($ev_client_status, 'UTF-8');
            } else {
                $ev_name          = strtoupper($ev_name);
                $ev_location      = strtoupper($ev_location);
                $ev_client        = strtoupper($ev_client);
                $ev_coordinator   = strtoupper($ev_coordinator);
                $ev_graphics      = strtoupper($ev_graphics);
                $ev_supervisor    = strtoupper($ev_supervisor);
                $ev_suppliers     = strtoupper($ev_suppliers);
                $ev_head          = strtoupper($ev_head);
                $ev_remarks       = strtoupper($ev_remarks);
            }

            // Normalize dates for DB
            $ev_date_db_in = $parseEventDate($ev_date_raw);
            if ($ev_date_db_in === '__invalid__') {
                $error = 'Invalid date format. Use DD/MM/YYYY';
                $modePreference = 'form';
            }
            $ev_end_date_db_in = $parseEventDate($ev_end_date_raw);
            if ($error === '' && $ev_end_date_db_in === '__invalid__') {
                $error = 'Invalid end date format. Use DD/MM/YYYY';
                $modePreference = 'form';
            }

            $ev_date_db = ($ev_date_db_in !== '') ? $ev_date_db_in : null;
            $ev_end_date_db = ($ev_end_date_db_in !== '') ? $ev_end_date_db_in : null;

            // Prevent duplicates: same name + date (or same name when date is empty)
            if ($error === '' && $ev_name !== '') {
                try {
                    if ($ev_date_db !== null) {
                        $dup = $db->prepare('SELECT id FROM events WHERE name = ? AND date = ? LIMIT 1');
                        $dup->execute([$ev_name, $ev_date_db]);
                    } else {
                        $dup = $db->prepare("SELECT id FROM events WHERE name = ? AND (date IS NULL OR date = '') LIMIT 1");
                        $dup->execute([$ev_name]);
                    }
                    if ($dup->fetchColumn()) {
                        $error = 'This event already exists (same name and date)';
                        $modePreference = 'form';
                    }
                } catch (Exception $e) {
                    // If duplicate check fails, do not block creation
                }
            }
            $ev_amount = (float)($_POST['ev_amount'] ?? 0);
            $ev_advance = (float)($_POST['ev_advance'] ?? 0);
            $ev_balance = $ev_amount - $ev_advance;
            $quoteFile = null;
            $checklistFile = null;
            $mockupFile = null;
            try {
                // Sales can attach quote & checklist only; mockup is managed via Graphic workflow
                $quoteFile = handle_event_file_upload('ev_quote_file', $eventUploadDir);
                $checklistFile = handle_event_file_upload('ev_checklist_file', $eventUploadDir);
                if (!in_array($role, ['sales'], true)) {
                    $mockupFile = handle_event_file_upload('ev_mockup_file', $eventUploadDir);
                }
            } catch (Exception $uploadEx) {
                $error = $uploadEx->getMessage();
                $modePreference = 'form';
            }
            
            if (empty($ev_name)) {
                $error = 'Event name is required';
                $modePreference = 'form';
            }

            if (!$error) {
                try {
                    $auto_secret = '';
                    $currentUserId = (int)($user['id'] ?? 0);
                    $stmt = $db->prepare('INSERT INTO events(name,date,end_date,location,secret_code,client,coordinator_id,coordinator,coordinators,graphics,graphics_users,supervisor,supervisors,head,remarks,status,client_status,efd,amount,advance,balance,quote_file,checklist_file,mockup_file,user_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
                    $stmt->execute([
                        $ev_name,
                        $ev_date_db,
                        $ev_end_date_db,
                        $ev_location,
                        $auto_secret,
                        $ev_client,
                        $ev_coordinator_id,
                        $ev_coordinator,
                        json_encode(array_filter($ev_coordinators, 'is_numeric')),
                        $ev_graphics,
                        json_encode(array_filter($ev_graphics_users, 'is_numeric')),
                        $ev_supervisor,
                        json_encode(array_filter($ev_supervisors, 'is_numeric')),
                        $ev_head,
                        $ev_remarks,
                        $ev_status,
                        $ev_client_status,
                        $ev_efd,
                        $ev_amount,
                        $ev_advance,
                        $ev_balance,
                        $quoteFile,
                        $checklistFile,
                        $mockupFile,
                        $currentUserId
                    ]);
                    $newEventId = (int)$db->lastInsertId();

                    // Auto-create graphic_requests for all selected graphic designers
                    if (!empty($ev_graphics_users) && is_array($ev_graphics_users) && $newEventId > 0) {
                        try {
                            foreach ($ev_graphics_users as $graphicsUserId) {
                                $graphicsUserId = (int)$graphicsUserId;
                                if ($graphicsUserId > 0) {
                                    $existGr = $db->prepare("SELECT id FROM graphic_requests WHERE event_id = ? AND assigned_to = ? AND status NOT IN ('completed','cancelled')");
                                    $existGr->execute([$newEventId, $graphicsUserId]);
                                    if (!$existGr->fetch()) {
                                        $grIns = $db->prepare("INSERT INTO graphic_requests (event_id, requested_by, assigned_to, assigned_by, status, assigned_at, notes) VALUES (?, ?, ?, ?, 'assigned', NOW(), 'Auto-assigned from event creation')");
                                        $grIns->execute([$newEventId, $user['id'], $graphicsUserId, $user['id']]);
                                    }
                                }
                            }
                        } catch (Exception $e) {
                            // Don't block event creation if graphic requests fail
                        }
                    }

                    try {
                        $log = $db->prepare('INSERT INTO activities(user_id, action, details) VALUES (?,?,?)');
                        $log->execute([($user['id'] ?? null), 'event_create', json_encode(['name'=>$ev_name,'date'=>$ev_date_db,'client'=>$ev_client])]);
                    } catch (Exception $e) {
                        // Log error but don't stop execution
                    }
                    header('Location: events.php?mode=list&success=1');
                    exit;
                } catch (Exception $ex) {
                    $error = "Failed to create event: " . $ex->getMessage();
                    $modePreference = 'form';
                }
            }

            if ($error !== '') {
                $formSticky = [
                    'name' => $ev_name,
                    'date_input' => $ev_date_raw,
                    'end_date_input' => $ev_end_date_raw,
                    'location' => $ev_location,
                    'client' => $ev_client,
                    'coordinator_id' => $ev_coordinator_id,
                    'coordinators' => $ev_coordinators,
                    'graphics_users' => $ev_graphics_users,
                    'supervisor' => $ev_supervisor,
                    'supervisors' => $ev_supervisors,
                    'suppliers' => $ev_suppliers,
                    'head' => $ev_head,
                    'remarks' => $ev_remarks,
                    'status' => $ev_status,
                    'client_status' => $ev_client_status,
                    'efd' => $ev_efd,
                    'amount' => $_POST['ev_amount'] ?? '',
                    'advance' => $_POST['ev_advance'] ?? '',
                ];
            }
        } elseif (isset($_POST['update_event'])) {
            $ev_id = (int)($_POST['ev_id'] ?? 0);
            $ev_name = trim($_POST['ev_name'] ?? '');
            $ev_date_raw = trim($_POST['ev_date'] ?? '');
            $ev_end_date_raw = trim($_POST['ev_end_date'] ?? '');
            $ev_location = trim($_POST['ev_location'] ?? '');
            $ev_client = trim($_POST['ev_client'] ?? '');
            
            // Handle multiple coordinators
            $ev_coordinators = $_POST['ev_coordinators'] ?? [];
            $ev_coordinator_id = 0;
            $ev_coordinator = '';
            if (!empty($ev_coordinators) && is_array($ev_coordinators)) {
                // For backward compatibility, store the first coordinator as coordinator_id and coordinator
                $ev_coordinator_id = (int)($ev_coordinators[0] ?? 0);
                if ($ev_coordinator_id > 0) {
                    try {
                        $coN = $db->prepare("SELECT name FROM users WHERE id = ? AND role = 'sales' LIMIT 1");
                        $coN->execute([$ev_coordinator_id]);
                        $ev_coordinator = (string)($coN->fetchColumn() ?? '');
                    } catch (Exception $e) {
                        $ev_coordinator = '';
                    }
                }
            }
            
            // Handle multiple graphics users
            $ev_graphics_users = $_POST['ev_graphics_users'] ?? [];
            $ev_graphics_user_id = 0;
            $ev_graphics = '';
            if (!empty($ev_graphics_users) && is_array($ev_graphics_users)) {
                // For backward compatibility, store the first graphics user
                $ev_graphics_user_id = (int)($ev_graphics_users[0] ?? 0);
                if ($ev_graphics_user_id > 0) {
                    try {
                        $gN = $db->prepare("SELECT name FROM users WHERE id = ? AND role = 'graphic' LIMIT 1");
                        $gN->execute([$ev_graphics_user_id]);
                        $ev_graphics = (string)($gN->fetchColumn() ?? '');
                    } catch (Exception $e) {
                        $ev_graphics = '';
                    }
                }
            }
            
            // Handle multiple supervisors
            $ev_supervisors = $_POST['ev_supervisors'] ?? [];
            $ev_supervisor = trim($_POST['ev_supervisor'] ?? '');
            if (!empty($ev_supervisors) && is_array($ev_supervisors)) {
                // For backward compatibility, create comma-separated list
                $supervisorIds = array_filter($ev_supervisors, 'is_numeric');
                if (!empty($supervisorIds)) {
                    try {
                        $placeholders = str_repeat('?,', count($supervisorIds) - 1) . '?';
                        $supN = $db->prepare("SELECT name FROM users WHERE id IN ($placeholders) AND role = 'supervisor'");
                        $supN->execute($supervisorIds);
                        $supNames = $supN->fetchAll(PDO::FETCH_COLUMN);
                        $ev_supervisor = implode(', ', $supNames);
                    } catch (Exception $e) {
                        $ev_supervisor = '';
                    }
                }
            }

            $requireActiveUsers($ev_coordinators, 'sales');
            $requireActiveUsers($ev_graphics_users, 'graphic');
            $requireActiveUsers($ev_supervisors, 'supervisor');
            
            $ev_suppliers = trim($_POST['ev_suppliers'] ?? '');
            $ev_head = trim($_POST['ev_head'] ?? '');
            $ev_remarks = trim($_POST['ev_remarks'] ?? '');
            $ev_status = trim($_POST['ev_status'] ?? '');
            $ev_client_status = trim($_POST['ev_client_status'] ?? '');
            $ev_efd = trim($_POST['ev_efd'] ?? '');

            if ($ev_coordinator_id <= 0) {
                $error = 'At least one coordinator is required';
                $modePreference = 'form';
            }

            // Supervisor selection is allowed for roles covered by $canAssignSupervisor

            // Normalize main text fields to uppercase before updating
            if (function_exists('mb_strtoupper')) {
                $ev_name          = mb_strtoupper($ev_name, 'UTF-8');
                $ev_location      = mb_strtoupper($ev_location, 'UTF-8');
                $ev_client        = mb_strtoupper($ev_client, 'UTF-8');
                $ev_coordinator   = mb_strtoupper($ev_coordinator, 'UTF-8');
                $ev_graphics      = mb_strtoupper($ev_graphics, 'UTF-8');
                $ev_supervisor    = mb_strtoupper($ev_supervisor, 'UTF-8');
                $ev_suppliers     = mb_strtoupper($ev_suppliers, 'UTF-8');
                $ev_head          = mb_strtoupper($ev_head, 'UTF-8');
                $ev_remarks       = mb_strtoupper($ev_remarks, 'UTF-8');
                $ev_status        = mb_strtoupper($ev_status, 'UTF-8');
                $ev_client_status = mb_strtoupper($ev_client_status, 'UTF-8');
            } else {
                $ev_name          = strtoupper($ev_name);
                $ev_location      = strtoupper($ev_location);
                $ev_client        = strtoupper($ev_client);
                $ev_coordinator   = strtoupper($ev_coordinator);
                $ev_graphics      = strtoupper($ev_graphics);
                $ev_supervisor    = strtoupper($ev_supervisor);
                $ev_suppliers     = strtoupper($ev_suppliers);
                $ev_head          = strtoupper($ev_head);
                $ev_remarks       = strtoupper($ev_remarks);
                $ev_status        = strtoupper($ev_status);
                $ev_client_status = strtoupper($ev_client_status);
            }

            // Normalize dates
            $ev_date_db_in = $parseEventDate($ev_date_raw);
            if ($ev_date_db_in === '__invalid__') {
                $error = 'Invalid date format. Use DD/MM/YYYY';
                $modePreference = 'form';
            }
            $ev_end_date_db_in = $parseEventDate($ev_end_date_raw);
            if ($error === '' && $ev_end_date_db_in === '__invalid__') {
                $error = 'Invalid end date format. Use DD/MM/YYYY';
                $modePreference = 'form';
            }

            $ev_date_db = ($ev_date_db_in !== '') ? $ev_date_db_in : null;
            $ev_end_date_db = ($ev_end_date_db_in !== '') ? $ev_end_date_db_in : null;

            // Prevent duplicates on update (excluding this event)
            if ($error === '' && $ev_id > 0 && $ev_name !== '') {
                try {
                    if ($ev_date_db !== null) {
                        $dup = $db->prepare('SELECT id FROM events WHERE name = ? AND date = ? AND id <> ? LIMIT 1');
                        $dup->execute([$ev_name, $ev_date_db, $ev_id]);
                    } else {
                        $dup = $db->prepare("SELECT id FROM events WHERE name = ? AND (date IS NULL OR date = '') AND id <> ? LIMIT 1");
                        $dup->execute([$ev_name, $ev_id]);
                    }
                    if ($dup->fetchColumn()) {
                        $error = 'This event already exists (same name and date)';
                        $modePreference = 'form';
                    }
                } catch (Exception $e) {
                    // If duplicate check fails, do not block update
                }
            }
            $ev_amount = (float)($_POST['ev_amount'] ?? 0);
            $ev_advance = (float)($_POST['ev_advance'] ?? 0);
            $ev_balance = $ev_amount - $ev_advance;
            $quoteFile = null;
            $checklistFile = null;
            $mockupFile = null;
            try {
                $quoteFile = handle_event_file_upload('ev_quote_file', $eventUploadDir);
                $checklistFile = handle_event_file_upload('ev_checklist_file', $eventUploadDir);
                $mockupFile = handle_event_file_upload('ev_mockup_file', $eventUploadDir);
            } catch (Exception $uploadEx) {
                $error = $uploadEx->getMessage();
                $modePreference = 'form';
            }
            
            if ($ev_id > 0 && !empty($ev_name)) {
                try {
                    $existingFiles = $db->prepare('SELECT quote_file, checklist_file, mockup_file, graphics FROM events WHERE id = ?');
                    $existingFiles->execute([$ev_id]);
                    $fileRow = $existingFiles->fetch(PDO::FETCH_ASSOC) ?: [];

                    $stmt = $db->prepare('UPDATE events SET name=?, date=?, end_date=?, location=?, client=?, coordinator_id=?, coordinator=?, coordinators=?, graphics=?, graphics_users=?, supervisor=?, supervisors=?, head=?, remarks=?, status=?, client_status=?, efd=?, amount=?, advance=?, balance=?, quote_file=?, checklist_file=?, mockup_file=? WHERE id=?');
                    $stmt->execute([
                        $ev_name,
                        $ev_date_db,
                        $ev_end_date_db,
                        $ev_location,
                        $ev_client,
                        $ev_coordinator_id,
                        $ev_coordinator,
                        json_encode(array_filter($ev_coordinators, 'is_numeric')),
                        $ev_graphics,
                        json_encode(array_filter($ev_graphics_users, 'is_numeric')),
                        $ev_supervisor,
                        json_encode(array_filter($ev_supervisors, 'is_numeric')),
                        $ev_head,
                        $ev_remarks,
                        $ev_status,
                        $ev_client_status,
                        $ev_efd,
                        $ev_amount,
                        $ev_advance,
                        $ev_balance,
                        $quoteFile ?: ($fileRow['quote_file'] ?? null),
                        $checklistFile ?: ($fileRow['checklist_file'] ?? null),
                        $mockupFile ?: ($fileRow['mockup_file'] ?? null),
                        $ev_id
                    ]);
                    // Auto-manage graphic_request when graphic designer selection changes
                    $oldGraphics = strtoupper(trim((string)($fileRow['graphics'] ?? '')));

                    if ($ev_graphics !== $oldGraphics) {
                        try {
                            // Cancel old active graphic request if graphic changed or removed
                            if ($oldGraphics !== '') {
                                $cancelOld = $db->prepare("UPDATE graphic_requests SET status = 'cancelled' WHERE event_id = ? AND status NOT IN ('completed','cancelled')");
                                $cancelOld->execute([$ev_id]);
                            }
                            // Create new graphic request for the newly selected graphic
                            if ($ev_graphics_user_id > 0) {
                                $existGr = $db->prepare("SELECT id FROM graphic_requests WHERE event_id = ? AND status NOT IN ('completed','cancelled')");
                                $existGr->execute([$ev_id]);
                                if (!$existGr->fetch()) {
                                    $grIns = $db->prepare("INSERT INTO graphic_requests (event_id, requested_by, assigned_to, assigned_by, status, assigned_at, notes) VALUES (?, ?, ?, ?, 'assigned', NOW(), 'Auto-assigned from event update')");
                                    $grIns->execute([$ev_id, $user['id'], $ev_graphics_user_id, $user['id']]);
                                }
                            }
                        } catch (Exception $e) {
                            // Don't block event update if graphic request fails
                        }
                    }

                    try {
                        $log = $db->prepare('INSERT INTO activities(user_id, action, details) VALUES (?,?,?)');
                        $log->execute([($user['id'] ?? null), 'event_update', json_encode(['id'=>$ev_id,'name'=>$ev_name])]);
                    } catch (Exception $e) {
                        // Log error but continue
                    }
                    $info = 'Event updated successfully';
                } catch (Exception $ex) {
                    $error = 'Failed to update event: ' . $ex->getMessage();
                    $modePreference = 'form';
                }
            } else {
                $error = 'Event ID and name are required';
                $modePreference = 'form';
            }

            if ($error !== '') {
                $formSticky = [
                    'id' => $ev_id,
                    'name' => $ev_name,
                    'date_input' => $ev_date_raw,
                    'end_date_input' => $ev_end_date_raw,
                    'location' => $ev_location,
                    'client' => $ev_client,
                    'coordinator_id' => $ev_coordinator_id,
                    'coordinators' => $ev_coordinators,
                    'graphics_users' => $ev_graphics_users,
                    'supervisor' => $ev_supervisor,
                    'supervisors' => $ev_supervisors,
                    'suppliers' => $ev_suppliers,
                    'head' => $ev_head,
                    'remarks' => $ev_remarks,
                    'status' => $ev_status,
                    'client_status' => $ev_client_status,
                    'efd' => $ev_efd,
                    'amount' => $_POST['ev_amount'] ?? '',
                    'advance' => $_POST['ev_advance'] ?? '',
                ];
            }
        } elseif (isset($_POST['import_events'])) {
            if (isset($_FILES['events_csv']) && $_FILES['events_csv']['error'] === UPLOAD_ERR_OK) {
                $csvFile = $_FILES['events_csv']['tmp_name'];
                $detectedMime = null;
                if (function_exists('mime_content_type')) {
                    $detectedMime = @mime_content_type($csvFile);
                } elseif (function_exists('finfo_open')) {
                    $f = @finfo_open(FILEINFO_MIME_TYPE);
                    if ($f) { 
                        $detectedMime = @finfo_file($f, $csvFile); 
                        @finfo_close($f); 
                    }
                }
                $originalName = $_FILES['events_csv']['name'] ?? '';
                $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                $allowedMimes = [
                    'text/csv','text/plain','application/csv',
                    'application/vnd.ms-excel',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                ];
                
                if (!in_array($detectedMime, $allowedMimes, true) && !in_array($ext, ['csv','xls','xlsx'], true)) {
                    $error = 'Invalid file type. Please upload a valid CSV or Excel file.';
                } else {
                    $imported = 0;
                    $errors = 0;
                    $rows = [];
                    $cols = ['name','date','end_date','location','client','coordinator','graphics','supervisor','head','remarks','status','secret_code','client_status','efd','amount','advance','balance'];
                    
                    if ($ext === 'csv') {
                        if (($handle = fopen($csvFile, 'r')) !== false) {
                            $firstLine = fgets($handle);
                            if ($firstLine === false) { 
                                fclose($handle); 
                                $error = 'Empty CSV file'; 
                            } else {
                                $firstLine = preg_replace('/^\xEF\xBB\xBF/u', '', $firstLine);
                                $comma = substr_count($firstLine, ',');
                                $semi = substr_count($firstLine, ';');
                                $delim = ($semi > $comma) ? ';' : ',';
                                $header = str_getcsv($firstLine, $delim);
                                $map = [];
                                if ($header && is_array($header)) {
                                    foreach ($header as $i => $h) { 
                                        $h = preg_replace('/^\xEF\xBB\xBF/u', '', $h);
                                        $map[strtolower(trim($h))] = $i; 
                                    }
                                }
                                while (($row = fgetcsv($handle, 0, $delim)) !== false) {
                                    $vals = [];
                                    foreach ($cols as $c) {
                                        $idx = $map[$c] ?? null;
                                        $vals[$c] = ($idx !== null && isset($row[$idx])) ? trim((string)$row[$idx]) : '';
                                    }
                                    $rows[] = $vals;
                                }
                                fclose($handle);
                            }
                        } else {
                            $error = 'Unable to read uploaded CSV file';
                        }
                    } else {
                        if (class_exists('PhpOffice\\PhpSpreadsheet\\IOFactory')) {
                            try {
                                $readerType = ($ext === 'xlsx') ? 'Xlsx' : 'Xls';
                                $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader($readerType);
                                $spreadsheet = $reader->load($csvFile);
                                $sheet = $spreadsheet->getActiveSheet();
                                $header = [];
                                foreach ($sheet->getRowIterator() as $rowIndex => $row) {
                                    $cellIterator = $row->getCellIterator();
                                    $cellIterator->setIterateOnlyExistingCells(false);
                                    $cells = [];
                                    foreach ($cellIterator as $cell) { 
                                        $cells[] = trim((string)$cell->getValue()); 
                                    }
                                    if ($rowIndex === 1) {
                                        foreach ($cells as $i => $h) { 
                                            $header[strtolower(trim($h))] = $i; 
                                        }
                                        continue;
                                    }
                                    if (!$header) { continue; }
                                    $vals = [];
                                    foreach ($cols as $c) {
                                        $idx = $header[$c] ?? null;
                                        $value = ($idx !== null && isset($cells[$idx])) ? $cells[$idx] : '';
                                        if (($c === 'date' || $c === 'end_date') && $value !== '' && is_numeric($value) && class_exists('\\PhpOffice\\PhpSpreadsheet\\Shared\\Date')) {
                                            try {
                                                $dt = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$value);
                                                $value = $dt ? $dt->format('Y-m-d') : (string)$value;
                                            } catch (Exception $e) {}
                                        }
                                        $vals[$c] = trim((string)$value);
                                    }
                                    $rows[] = $vals;
                                }
                            } catch (Exception $e) {
                                $error = 'Unable to read Excel file. Please convert to CSV and try again.';
                            }
                        } else {
                            $error = 'Excel import requires PhpSpreadsheet. Please install it or upload a CSV file instead.';
                        }
                    }

                    if (!$error && !empty($rows)) {
                        $currentUserId = (int)($user['id'] ?? 0);
                        foreach ($rows as $vals) {
                            $ev_name = $vals['name'];
                            if (empty($ev_name)) { 
                                $errors++; 
                                continue; 
                            }
                            $ev_date = $vals['date'];
                            $ev_location = $vals['location'];
                            $ev_client = $vals['client'];
                            $ev_coordinator = $vals['coordinator'];
                            $ev_graphics = $vals['graphics'];
                            $ev_suppliers = $vals['suppliers'];
                            $ev_head = $vals['head'];
                            $ev_remarks = $vals['remarks'];
                            $ev_status = $vals['status'];
                            $ev_secret = '';
                            $ev_end_date = $vals['end_date'] ?? '';
                            $ev_client_status = $vals['client_status'] ?? '';
                            $ev_efd = $vals['efd'] ?? '';
                            $ev_amount = (float)($vals['amount'] ?? 0);
                            $ev_advance = (float)($vals['advance'] ?? 0);
                            $ev_balance = $vals['balance'] !== '' ? (float)$vals['balance'] : ($ev_amount - $ev_advance);

                            // Normalize main text fields to uppercase before import insert
                            if (function_exists('mb_strtoupper')) {
                                $ev_name          = mb_strtoupper($ev_name, 'UTF-8');
                                $ev_location      = mb_strtoupper($ev_location, 'UTF-8');
                                $ev_client        = mb_strtoupper($ev_client, 'UTF-8');
                                $ev_coordinator   = mb_strtoupper($ev_coordinator, 'UTF-8');
                                $ev_graphics      = mb_strtoupper($ev_graphics, 'UTF-8');
                                $ev_suppliers     = mb_strtoupper($ev_suppliers, 'UTF-8');
                                $ev_head          = mb_strtoupper($ev_head, 'UTF-8');
                                $ev_remarks       = mb_strtoupper($ev_remarks, 'UTF-8');
                                $ev_status        = mb_strtoupper($ev_status, 'UTF-8');
                                $ev_client_status = mb_strtoupper($ev_client_status, 'UTF-8');
                            } else {
                                $ev_name          = strtoupper($ev_name);
                                $ev_location      = strtoupper($ev_location);
                                $ev_client        = strtoupper($ev_client);
                                $ev_coordinator   = strtoupper($ev_coordinator);
                                $ev_graphics      = strtoupper($ev_graphics);
                                $ev_suppliers     = strtoupper($ev_suppliers);
                                $ev_head          = strtoupper($ev_head);
                                $ev_remarks       = strtoupper($ev_remarks);
                                $ev_status        = strtoupper($ev_status);
                                $ev_client_status = strtoupper($ev_client_status);
                            }
                            
                            try {
                                $stmt = $db->prepare('INSERT INTO events(name,date,end_date,location,secret_code,client,coordinator,graphics,suppliers,head,remarks,status,client_status,efd,amount,advance,balance,user_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
                                $stmt->execute([$ev_name, $ev_date, $ev_end_date, $ev_location, $ev_secret, $ev_client, $ev_coordinator, $ev_graphics, $ev_suppliers, $ev_head, $ev_remarks, $ev_status, $ev_client_status, $ev_efd, $ev_amount, $ev_advance, $ev_balance, $currentUserId]);
                                $imported++;
                            } catch (Exception $e) { 
                                $errors++; 
                            }
                        }
                        
                        if ($imported > 0) {
                            $info = "Imported $imported events" . ($errors ? ", $errors rows skipped" : '');
                            try {
                                $log = $db->prepare('INSERT INTO activities(user_id, action, details) VALUES (?,?,?)');
                                $log->execute([($user['id'] ?? null), 'event_import', json_encode(['imported'=>$imported,'errors'=>$errors])]);
                            } catch (Exception $e) {}
                        } else {
                            $error = $errors > 0 ? 'No rows imported. All rows failed validation or insert.' : 'No valid data found in the uploaded file';
                        }
                    } elseif (!$error) {
                        $error = 'No valid data found in the uploaded file';
                    }
                }
            } else {
                $error = 'Please upload a valid CSV or Excel file';
            }
        }
        
    } catch (Exception $e) {
        $error = "Form processing error: " . $e->getMessage();
        $modePreference = 'form';
    }
}

// Load events data (single unified dataset; all UI search is client-side)
if ($db) {
    try {
        $stmt = $db->query('SELECT * FROM events ORDER BY id DESC');
        $events = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Exception $e) {
        $error = "Error loading events: " . $e->getMessage();
        $events = [];
    }

    // Handle edit mode
    $eventId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    
    if (isset($_GET['action']) && $_GET['action'] === 'edit' && $eventId > 0) {
        if (!$canManageEvents) {
            $error = 'You do not have permission to edit events';
            $editEvent = null;
        } else {
        try {
            $stmt = $db->prepare('SELECT * FROM events WHERE id = ?');
            $stmt->execute([$eventId]);
            $editEvent = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $error = "Error loading event for editing: " . $e->getMessage();
        }
        }
    } elseif (isset($_GET['action']) && $_GET['action'] === 'edit_supervisor' && $eventId > 0) {
        if ($role !== 'operation') {
            $error = 'You do not have permission to edit supervisors';
            $editEvent = null;
        } else {
            try {
                $stmt = $db->prepare('SELECT * FROM events WHERE id = ?');
                $stmt->execute([$eventId]);
                $editEvent = $stmt->fetch(PDO::FETCH_ASSOC);
                $operationSupervisorOnlyForm = $editEvent ? true : false;
                if (!$editEvent) {
                    $error = 'Event not found';
                }
            } catch (Exception $e) {
                $error = "Error loading event: " . $e->getMessage();
            }
        }
    }

}

// Handle delete action
if ($isSuper && $db && isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    try {
        $id = (int)$_GET['id'];
        if (function_exists('db_delete_event') && db_delete_event($id)) {
            try {
                $log = $db->prepare('INSERT INTO activities(user_id, action, details) VALUES (?,?,?)');
                $log->execute([($user['id'] ?? null), 'event_delete', json_encode(['id'=>$id])]);
            } catch (Exception $e) {
                // Log error but continue
            }
            $info = 'Event deleted successfully';
        } else {
            $error = 'Failed to delete event';
        }
        header('Location: events.php');
        exit;
    } catch (Exception $e) {
        $error = "Delete error: " . $e->getMessage();
    }
}

// Determine which section to show
$mode = $_GET['mode'] ?? null;
if ($modePreference) { 
    $mode = $modePreference; 
}

if ($formSticky && $mode === 'form') {
    $editEvent = array_merge($editEvent ?: [], [
        'id' => $formSticky['id'] ?? ($editEvent['id'] ?? null),
        'name' => $formSticky['name'] ?? ($editEvent['name'] ?? ''),
        'location' => $formSticky['location'] ?? ($editEvent['location'] ?? ''),
        'client' => $formSticky['client'] ?? ($editEvent['client'] ?? ''),
        'suppliers' => $formSticky['suppliers'] ?? ($editEvent['suppliers'] ?? ''),
        'head' => $formSticky['head'] ?? ($editEvent['head'] ?? ''),
        'remarks' => $formSticky['remarks'] ?? ($editEvent['remarks'] ?? ''),
        'status' => $formSticky['status'] ?? ($editEvent['status'] ?? ''),
        'client_status' => $formSticky['client_status'] ?? ($editEvent['client_status'] ?? ''),
        'efd' => $formSticky['efd'] ?? ($editEvent['efd'] ?? ''),
        'amount' => $formSticky['amount'] ?? ($editEvent['amount'] ?? ''),
        'advance' => $formSticky['advance'] ?? ($editEvent['advance'] ?? ''),
        '__date_input' => $formSticky['date_input'] ?? '',
        '__end_date_input' => $formSticky['end_date_input'] ?? '',
    ]);
}
if ($operationSupervisorOnlyForm) {
    $mode = 'form';
} elseif ($editEvent) {
    $mode = 'form';
} elseif ($eventId > 0 && !isset($_GET['action'])) {
    $mode = 'details';
}

if (!$canManageEvents && $mode === 'form') {
    $mode = 'list';
}

if (!in_array($mode, ['form','list','details'], true)) {
    $mode = 'list';
}

$opEvents = [];
$opFilters = [
    'month' => '',
    'status' => 'all',
    'quote_status' => 'all',
    'supervisor' => 'assigned'
];
if (($role ?? '') === 'operation' && $mode === 'list') {
    $opFilters['month'] = (string)($_GET['month'] ?? '');
    $opFilters['status'] = (string)($_GET['status'] ?? 'all');
    $opFilters['quote_status'] = (string)($_GET['quote_status'] ?? 'all');
    $opFilters['supervisor'] = (string)($_GET['supervisor'] ?? 'assigned');

    $opSupervisorById = [];
    if (!empty($supervisors) && is_array($supervisors)) {
        foreach ($supervisors as $su) {
            $sid = (int)($su['id'] ?? 0);
            if ($sid > 0) {
                $opSupervisorById[$sid] = (string)($su['name'] ?? ($su['email'] ?? ''));
            }
        }
    }

    $normalizeSupervisorText = function ($ev) use ($opSupervisorById) {
        $supText = trim((string)($ev['supervisor'] ?? ''));
        if ($supText !== '') {
            return $supText;
        }
        $raw = $ev['supervisors'] ?? null;
        if ($raw === null) {
            return '';
        }
        if (is_string($raw) && trim($raw) === '') {
            return '';
        }
        $decoded = null;
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
        } elseif (is_array($raw)) {
            $decoded = $raw;
        }
        if (!is_array($decoded)) {
            return '';
        }
        $names = [];
        foreach ($decoded as $id) {
            $id = (int)$id;
            if ($id > 0 && isset($opSupervisorById[$id]) && trim($opSupervisorById[$id]) !== '') {
                $names[] = trim($opSupervisorById[$id]);
            }
        }
        $names = array_values(array_unique(array_filter($names, function ($x) { return $x !== ''; })));
        return !empty($names) ? implode(', ', $names) : '';
    };

    $validSupervisor = ['all', 'assigned', 'unassigned'];
    if (!in_array($opFilters['supervisor'], $validSupervisor, true)) {
        $opFilters['supervisor'] = 'assigned';
    }

    $opMonth = $opFilters['month'];
    if ($opMonth !== '' && !preg_match('/^\d{4}-\d{2}$/', $opMonth)) {
        $opMonth = '';
        $opFilters['month'] = '';
    }

    $opStatus = strtolower(trim((string)$opFilters['status']));
    $opQuoteStatus = strtolower(trim((string)$opFilters['quote_status']));

    $opEvents = array_values(array_filter($events, function ($ev) use ($opMonth, $opStatus, $opQuoteStatus, $opFilters, $normalizeSupervisorText) {
        $supText = $normalizeSupervisorText($ev);
        $hasSupervisor = trim($supText) !== '';
        if ($opFilters['supervisor'] === 'assigned' && !$hasSupervisor) {
            return false;
        }
        if ($opFilters['supervisor'] === 'unassigned' && $hasSupervisor) {
            return false;
        }

        if ($opStatus !== '' && $opStatus !== 'all') {
            $evStatus = strtolower(trim((string)($ev['status'] ?? '')));
            if ($evStatus !== $opStatus) {
                return false;
            }
        }

        if ($opQuoteStatus !== '' && $opQuoteStatus !== 'all') {
            $qs = strtolower(trim((string)($ev['quote_status'] ?? 'pending')));
            if ($qs !== $opQuoteStatus) {
                return false;
            }
        }

        if ($opMonth !== '') {
            $d = (string)($ev['date'] ?? '');
            $d = trim($d);
            if ($d === '' || $d === '0000-00-00') {
                return false;
            }
            if (substr($d, 0, 7) !== $opMonth) {
                return false;
            }
        }

        return true;
    }));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Event Management | EventPro</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
  <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/buttons/2.2.2/css/buttons.bootstrap5.min.css">
  <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/responsive/2.2.9/css/responsive.bootstrap5.min.css">
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    /* Enhanced Table Styling */
    .table {
      --table-bg: #ffffff;
      --table-header-bg: #f8fafc;
      --table-border: #e2e8f0;
      --table-hover: #f1f5f9;
      --table-stripe: #f8fafc;
      --table-text: #1e293b;
      --table-text-muted: #64748b;
      --table-radius: 0.5rem;
      --table-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    }

    /* Base Table Styles */
    .table {
      width: 100%;
      border-collapse: separate;
      border-spacing: 0;
      background: var(--table-bg);
      border-radius: var(--table-radius);
      overflow: hidden;
      box-shadow: var(--table-shadow);
    }

    /* Table Header */
    .table thead th {
      background-color: var(--table-header-bg);
      color: var(--table-text);
      font-weight: 600;
      text-transform: uppercase;
      font-size: 0.75rem;
      letter-spacing: 0.05em;
      padding: 1rem 1.25rem;
      border-bottom: 2px solid var(--table-border);
      white-space: nowrap;
    }

    /* Table Cells */
    .table td {
      padding: 1rem 1.25rem;
      border-bottom: 1px solid var(--table-border);
      vertical-align: middle;
      color: var(--table-text);
      transition: all 0.2s ease;
    }

    /* Hover Effect */
    .table tbody tr {
      transition: all 0.2s ease;
    }

    .table tbody tr:hover {
      background-color: var(--table-hover);
    }

    /* Striped Rows */
    .table-striped tbody tr:nth-child(odd) {
      background-color: var(--table-stripe);
    }

    /* Status Badges */
    .badge {
      display: inline-flex;
      align-items: center;
      padding: 0.35em 0.65em;
      font-size: 0.75em;
      font-weight: 600;
      line-height: 1;
      text-align: center;
      white-space: nowrap;
      vertical-align: baseline;
      border-radius: 0.375rem;
      transition: all 0.2s ease;
    }

    .badge-primary {
      background-color: #e0f2fe;
      color: #0369a1;
    }

    .badge-success {
      background-color: #dcfce7;
      color: #15803d;
    }

    .badge-warning {
      background-color: #fef3c7;
      color: #b45309;
    }

    .badge-danger {
      background-color: #fee2e2;
      color: #b91c1c;
    }

    .badge-secondary {
      background-color: #f1f5f9;
      color: #475569;
    }

    /* Action Buttons */
    .btn-group {
      display: flex;
      gap: 0.5rem;
    }

    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      padding: 0.375rem 0.75rem;
      border-radius: 0.375rem;
      font-size: 0.875rem;
      font-weight: 500;
      line-height: 1.5;
      text-align: center;
      text-decoration: none;
      cursor: pointer;
      transition: all 0.2s ease;
      border: 1px solid transparent;
    }

    .btn-sm {
      padding: 0.25rem 0.5rem;
      font-size: 0.75rem;
      line-height: 1.5;
    }

    .btn-primary {
      background-color: #3b82f6;
      color: white;
    }

    .btn-primary:hover {
      background-color: #2563eb;
    }

    .btn-secondary {
      background-color: #e2e8f0;
      color: #1e293b;
    }

    .btn-secondary:hover {
      background-color: #cbd5e1;
    }

    .btn-danger {
      background-color: #ef4444;
      color: white;
    }

    .btn-danger:hover {
      background-color: #dc2626;
    }

    /* Responsive Table */
    .table-responsive {
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
      margin: 1rem 0;
      border-radius: var(--table-radius);
      box-shadow: var(--table-shadow);
    }

    /* DataTables Customization */
    .dataTables_wrapper {
      padding: 1rem 0;
    }

    /* Hide built-in DataTables search; we use our own Search Panel instead */
    .dataTables_wrapper .dataTables_filter {
      display: none;
    }

    .dataTables_wrapper .dataTables_filter input {
      border: 1px solid #d1d5db;
      border-radius: 0.375rem;
      padding: 0.5rem 0.75rem;
      margin-left: 0.5rem;
      transition: all 0.2s ease;
      box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
    }

    .dataTables_wrapper .dataTables_filter input:focus {
      outline: none;
      border-color: #3b82f6;
      box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }

    .dataTables_wrapper .dataTables_length select {
      border: 1px solid #d1d5db;
      border-radius: 0.375rem;
      padding: 0.25rem 1.5rem 0.25rem 0.5rem;
    }
    
    /* Arrange info and pagination nicely under the table */
    .dataTables_wrapper .dataTables_info,
    .dataTables_wrapper .dataTables_paginate {
      padding-top: 0.75rem;
      margin-top: 0.25rem;
    }
    
    .dataTables_wrapper .dataTables_info {
      float: none;
      font-size: 0.85rem;
      color: #64748b;
    }
    
    .dataTables_wrapper .dataTables_paginate {
      float: none;
    }

    .dataTables_wrapper > .row:last-child {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
      width: 100%;
      margin: 0;
      padding: 0.75rem 0.25rem 0;
    }

    .dataTables_wrapper > .row:last-child > [class*="col-"] {
      width: auto;
      max-width: 100%;
      padding: 0;
    }

    .dataTables_wrapper .dataTables_paginate ul.pagination {
      display: flex !important;
      flex-direction: row !important;
      align-items: center;
      justify-content: flex-end;
      flex-wrap: wrap;
      gap: 0.25rem;
      padding: 0;
      margin: 0;
      list-style: none;
    }

    .dataTables_wrapper .dataTables_paginate li.page-item {
      display: block;
      padding: 0;
      margin: 0;
    }

    .dataTables_wrapper .dataTables_paginate .page-link {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 2rem;
      height: 2rem;
      padding: 0.35rem 0.65rem;
      border: 1px solid #e2e8f0;
      border-radius: 0.5rem;
      background: #fff;
      color: #475569;
      text-decoration: none;
      line-height: 1;
    }

    .dataTables_wrapper .dataTables_paginate .page-item.active .page-link {
      border-color: #2563eb;
      background: #2563eb;
      color: #fff;
    }

    .dataTables_wrapper .dataTables_paginate .page-item.disabled .page-link {
      color: #94a3b8;
      background: #f8fafc;
      pointer-events: none;
    }
    
    .dataTables_wrapper .dataTables_paginate .paginate_button {
      padding: 0;
      margin-left: 0;
      border-radius: 0.5rem;
    }

    /* DataTables overrides */
    .dataTables_wrapper .dataTables_filter input {
      border: 1px solid #d1d5db;
      border-radius: 0.375rem;
      padding: 0.375rem 0.75rem;
    }
    
    .dataTables_wrapper .dataTables_length select {
      border: 1px solid #d1d5db;
      border-radius: 0.375rem;
      padding: 0.25rem 1.5rem 0.25rem 0.5rem;
    }
    
    .dataTables_wrapper .dataTables_paginate .paginate_button {
      padding: 0;
      margin-left: 0;
      border: 0;
      border-radius: 0.5rem;
    }
    
    .dataTables_wrapper .dataTables_paginate .paginate_button.current, 
    .dataTables_wrapper .dataTables_paginate .paginate_button.current:hover {
      background: #3b82f6;
      color: white !important;
      border-color: #3b82f6;
    }
    
    .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
      background: #f3f4f6;
      border-color: #d1d5db;
    }
    
    .btn-group .btn-sm {
      padding: 0.25rem 0.5rem;
      font-size: 0.75rem;
    }
    
    .table-responsive {
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
    }
    
    .dt-nowrap {
      white-space: nowrap;
    }
    
    /* Responsive table styles */
    @media screen and (max-width: 767px) {
      .dataTables_wrapper > .row:last-child {
        flex-direction: column;
        align-items: stretch;
      }

      .dataTables_wrapper > .row:last-child > [class*="col-"] {
        width: 100%;
      }

      .dataTables_wrapper .dataTables_info,
      .dataTables_wrapper .dataTables_paginate {
        text-align: center;
      }

      .dataTables_wrapper .dataTables_paginate ul.pagination {
        justify-content: center;
      }
      
      .dataTables_wrapper .dataTables_paginate .paginate_button {
        padding: 0;
        font-size: 0.75rem;
      }
    }
    
    :root {
      --primary: #3b82f6;
      --secondary: #8b5cf6;
      --success: #10b981;
      --warning: #f59e0b;
      --danger: #ef4444;
      --info: #06b6d4;
      --dark: #1f2937;
      --light: #f8fafc;
    }
    
    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }
    
    body {
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;
      background-color: #f1f5f9;
      color: #334155;
      line-height: 1.5;
      overflow-x: hidden;
    }
    
    .dashboard-container {
      display: flex;
      min-height: 100vh;
      width: 100%;
    }
    
    /* Sidebar Styles */
    .sidebar {
      width: 220px;
      background: linear-gradient(180deg, #1e293b 0%, #0f172a 100%);
      color: white;
      padding: 1.5rem 1rem;
      display: flex;
      flex-direction: column;
      position: fixed;
      height: 100vh;
      overflow-y: auto;
      z-index: 1000;
      transition: transform 0.3s ease;
    }
    
    .brand {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 0 0.5rem 1.5rem;
      border-bottom: 1px solid rgba(255,255,255,0.1);
      margin-bottom: 1.5rem;
    }
    
    .brand-title {
      font-weight: 700;
      font-size: 1.25rem;
      white-space: nowrap;
    }
    
    .nav-menu {
      display: flex;
      flex-direction: column;
      gap: 0.5rem;
      flex: 1;
    }
    
    .nav-item {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 0.75rem 1rem;
      border-radius: 8px;
      color: rgba(255,255,255,0.8);
      text-decoration: none;
      transition: all 0.2s;
      white-space: nowrap;
    }
    
    .nav-item:hover, .nav-item.active {
      background-color: rgba(255,255,255,0.1);
      color: white;
    }
    
    .sidebar-footer {
      margin-top: auto;
      padding-top: 1.5rem;
      border-top: 1px solid rgba(255,255,255,0.1);
      font-size: 0.75rem;
      opacity: 0.7;
      line-height: 1.4;
    }
    
    /* Mobile menu button */
    .mobile-menu-btn {
      display: none;
      position: fixed;
      top: 1rem;
      left: 1rem;
      z-index: 1001;
      background: #3b82f6;
      color: white;
      border: none;
      border-radius: 8px;
      padding: 0.75rem;
      cursor: pointer;
    }
    
    /* Main Content */
    .main-content {
      flex: 1;
      margin-left: 220px;
      padding: 1rem 1.5rem;
      width: calc(100% - 220px);
      min-height: 100vh;
      transition: all 0.3s ease;
    }
    
    /* Top Bar */
    .top-bar {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 2rem;
      padding-bottom: 1rem;
      border-bottom: 1px solid #e2e8f0;
      flex-wrap: nowrap;
      gap: 0.75rem;
    }
    
    .page-title h1 {
      font-size: clamp(1.5rem, 4vw, 1.75rem);
      font-weight: 700;
      color: #1e293b;
      line-height: 1.2;
    }
    
    .page-title p {
      color: #64748b;
      font-size: clamp(0.8rem, 2vw, 0.9rem);
    }
    
    .user-menu {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      flex-wrap: nowrap;
    }
    
    .date-display {
      background: white;
      padding: 0.5rem 1rem;
      border-radius: 8px;
      box-shadow: 0 1px 3px rgba(0,0,0,0.1);
      font-weight: 500;
      font-size: clamp(0.8rem, 2vw, 1rem);
      white-space: nowrap;
    }
    
    .user-profile {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      position: relative;
      cursor: pointer;
      flex-shrink: 0;
    }
    
    .avatar {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      background: linear-gradient(135deg, #3b82f6, #8b5cf6);
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      font-weight: 600;
      flex-shrink: 0;
    }
    
    .profile-dropdown {
      position: absolute;
      top: 100%;
      right: 0;
      background: white;
      border-radius: 8px;
      box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1);
      padding: 0.5rem;
      min-width: 180px;
      display: none;
      z-index: 100;
    }
    
    .profile-dropdown.show {
      display: block;
    }
    
    .profile-dropdown a {
      display: block;
      padding: 0.75rem 1rem;
      border-radius: 6px;
      color: #475569;
      text-decoration: none;
      transition: all 0.2s;
      font-size: 0.875rem;
    }
    
    .profile-dropdown a:hover {
      background-color: #f1f5f9;
      color: #3b82f6;
    }
    
    /* Content Sections */
    .content-section {
      background: white;
      border-radius: 12px;
      padding: clamp(1rem, 3vw, 1.5rem);
      margin-bottom: 1.5rem;
      box-shadow: 0 1px 3px rgba(0,0,0,0.1);
      width: 100%;
      overflow: hidden;
    }
    
    .section-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 1.5rem;
      padding-bottom: 1rem;
      border-bottom: 1px solid #e2e8f0;
      flex-wrap: wrap;
      gap: 0.75rem;
      width: 100%;
    }
    
    .section-title {
      font-size: clamp(1.1rem, 3vw, 1.25rem);
      font-weight: 600;
      color: #1e293b;
      line-height: 1.3;
    }
    
    .section-actions {
      display: inline-flex;
      gap: 0.5rem;
      align-items: center;
      flex-wrap: wrap;
      justify-content: flex-end;
    }
    
    /* Buttons */
    .btn {
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      padding: 0.5rem 1rem;
      border-radius: 6px;
      font-weight: 500;
      text-decoration: none;
      transition: all 0.2s;
      cursor: pointer;
      border: none;
      font-size: clamp(0.8rem, 2vw, 0.875rem);
      white-space: nowrap;
    }
    
    .btn-primary {
      background-color: #3b82f6;
      color: white;
    }
    
    .btn-primary:hover {
      background-color: #2563eb;
    }
    
    .btn-secondary {
      background-color: #f1f5f9;
      color: #475569;
    }
    
    .btn-secondary:hover {
      background-color: #e2e8f0;
    }
    
    .btn-success {
      background-color: #10b981;
      color: white;
    }
    
    .btn-success:hover {
      background-color: #059669;
    }
    
    .btn-danger {
      background-color: #ef4444;
      color: white;
    }
    
    .btn-danger:hover {
      background-color: #dc2626;
    }
    
    .btn-sm {
      padding: 0.25rem 0.6rem;
      font-size: 0.75rem;
    }
    
    /* Alerts */
    .alert {
      padding: 1rem;
      border-radius: 8px;
      margin-bottom: 1.5rem;
    }
    
    .alert-error {
      background-color: #fef2f2;
      border: 1px solid #fecaca;
      color: #dc2626;
    }
    
    .alert-success {
      background-color: #f0fdf4;
      border: 1px solid #bbf7d0;
      color: #16a34a;
    }
    
    /* Tables */
    .table-container {
      overflow-x: auto;
      border-radius: 8px;
      border: 1px solid #e2e8f0;
      width: 100%;
      -webkit-overflow-scrolling: touch;
    }
    
    .data-table {
      width: 100%;
      border-collapse: collapse;
    }
    
    .data-table th, .data-table td {
      padding: 0.75rem 1rem;
      border-bottom: 1px solid #e2e8f0;
      text-align: left;
      font-size: 0.9rem;
    }
    
    .data-table th {
      background-color: #f8fafc;
      font-weight: 600;
      color: #475569;
    }
    
    .data-table tr:hover {
      background-color: #f8fafc;
    }
    
    /* Badges */
    .badge {
      display: inline-flex;
      align-items: center;
      padding: 0.25rem 0.6rem;
      border-radius: 9999px;
      font-size: 0.75rem;
      font-weight: 600;
    }
    
    .badge-secondary {
      background-color: #e0e7ff;
      color: #3730a3;
    }
    
    .badge-warning {
      background-color: #fef3c7;
      color: #92400e;
    }
    
    /* Forms */
    .form-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
      gap: 1rem;
    }

    .event-form-grid {
      display: grid;
      grid-template-columns: 1fr;
      gap: 1rem;
      align-items: start;
    }

    @media (min-width: 768px) {
      .event-form-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
    }

    @media (min-width: 1024px) {
      .event-form-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
      }
    }

    .event-form-grid .form-group {
      margin-bottom: 0;
    }
    
    .form-group {
      margin-bottom: 1rem;
    }
    
    .form-label {
      display: block;
      margin-bottom: 0.5rem;
      font-weight: 500;
      color: #374151;
    }
    
    .form-input, .form-select {
      width: 100%;
      padding: 0.75rem;
      border: 1px solid #d1d5db;
      border-radius: 6px;
      font-size: 0.875rem;
      background-color: #fff;
    }

    .multi-select-container {
      display: flex;
      flex-direction: column;
      gap: 0.35rem;
    }

    .op-supervisor-only-form .event-form-grid input,
    .op-supervisor-only-form .event-form-grid select,
    .op-supervisor-only-form .event-form-grid textarea {
      pointer-events: none;
      background-color: #f8fafc;
    }

    .op-supervisor-only-form .event-form-grid input[readonly] {
      background-color: #f8fafc;
    }

    .op-supervisor-only-form .event-form-grid .multi-select-container,
    .op-supervisor-only-form .event-form-grid .multi-select-container * {
      pointer-events: none;
    }

    .op-supervisor-only-form .event-form-grid .form-group .ms[data-placeholder="Select supervisors"],
    .op-supervisor-only-form .event-form-grid .form-group .ms[data-placeholder="Select supervisors"] * {
      pointer-events: auto;
      background-color: #fff;
    }

    .op-supervisor-only-form .event-form-grid .form-group .ms[data-placeholder="Select supervisors"] .ms-value,
    .op-supervisor-only-form .event-form-grid .form-group .ms[data-placeholder="Select supervisors"] .ms-control,
    .op-supervisor-only-form .event-form-grid .form-group .ms[data-placeholder="Select supervisors"] .ms-search {
      background-color: #fff;
    }

    .op-supervisor-only-form button[type="submit"],
    .op-supervisor-only-form a.btn {
      pointer-events: auto;
    }

    .ms {
      position: relative;
      width: 100%;
      isolation: isolate;
    }

    .ms.open {
      z-index: 2147483646;
    }

    .ms select {
      display: none;
    }

    .ms-control {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 0.75rem;
      cursor: pointer;
      user-select: none;
      text-align: left;
      padding: 0.75rem;
      border: 1px solid #d1d5db;
      border-radius: 6px;
      background-color: #fff;
      font-size: 0.875rem;
    }

    .ms-control .ms-value {
      flex: 1;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      color: #111827;
    }

    .ms-control .ms-placeholder {
      color: #6b7280;
    }

    .ms-menu {
      position: absolute;
      left: 0;
      right: 0;
      top: calc(100% + 6px);
      background: #fff;
      border: 1px solid #d1d5db;
      border-radius: 14px;
      box-shadow: 0 10px 20px rgba(15,23,42,0.08);
      z-index: 2147483647;
      padding: 0.85rem;
      display: none;
    }

    .ms.open .ms-menu {
      display: block;
    }

    .ms-search {
      width: 100%;
      padding: 0.85rem 0.9rem;
      border: 1px solid #d1d5db;
      border-radius: 12px;
      font-size: 0.875rem;
      margin-bottom: 0.75rem;
      outline: none;
    }

    .ms-search:focus {
      border-color: #2563eb;
      box-shadow: 0 0 0 3px rgba(37,99,235,0.25);
    }

    .ms-options {
      max-height: 380px;
      overflow: auto;
      padding-right: 0.25rem;
    }

    .ms-option {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      padding: 0.6rem 0.35rem;
      border-radius: 10px;
      font-size: 0.875rem;
      color: #0f172a;
      cursor: pointer;
      user-select: none;
    }

    .ms-option:hover {
      background: #f8fafc;
    }

    .ms-option input[type="checkbox"] {
      width: 16px;
      height: 16px;
    }

    .form-select[multiple] {
      min-height: 46px;
      padding: 0.75rem;
      line-height: 1.35;
    }

    .form-help {
      display: block;
      margin-top: 0.35rem;
      font-size: 0.75rem;
      color: #6b7280;
      line-height: 1.4;
    }
    
    .form-input:focus, .form-select:focus {
      outline: none;
      border-color: #3b82f6;
      box-shadow: 0 0 0 3px rgba(59,130,246,0.1);
    }
    
    /* Horizontal details table */
    .horizontal-table {
      width: 100%;
      border-collapse: collapse;
    }
    
    .horizontal-table th {
      background-color: #f8fafc;
      padding: 0.75rem 1rem;
      text-align: left;
      font-weight: 600;
      color: #475569;
      border: 1px solid #e2e8f0;
      width: 220px;
    }
    
    .horizontal-table td {
      padding: 0.75rem 1rem;
      border: 1px solid #e2e8f0;
    }
    
    /* Mobile Responsive - delegate sidebar behavior to shared sidebar.php */
    @media (max-width: 1024px) {
      .main-content {
        margin-left: 0;
        width: 100%;
        padding: 1rem;
      }
    }
    
    @media (max-width: 768px) {
      .main-content {
        margin-left: 0;
        width: 100%;
        padding: 1rem;
        padding-top: 4rem;
      }
      
      .top-bar {
        flex-direction: column;
        align-items: flex-start;
        gap: 1rem;
      }

      .page-title {
        text-align: left;
        padding-left: 0.15rem;
        padding-right: 0;
      }
      
      .user-menu {
        width: 100%;
        justify-content: space-between;
      }
      
      .section-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 1rem;
      }
      
      .section-actions {
        width: 100%;
        justify-content: space-between;
      }
      
      .form-grid {
        grid-template-columns: 1fr;
      }
      
      .data-table th, .data-table td {
        padding: 0.5rem 0.75rem;
        font-size: 0.8rem;
      }
      
      .btn {
        padding: 0.4rem 0.7rem;
        font-size: 0.8rem;
      }
      
      .horizontal-table th, .horizontal-table td {
        padding: 0.5rem 0.75rem;
      }
    }
    
    @media (max-width: 640px) {
      .main-content {
        padding: 0.75rem;
        padding-top: 4rem;
      }
      
      .content-section {
        padding: 1rem;
        margin-bottom: 1rem;
      }
      
      .section-actions {
        flex-direction: column;
        width: 100%;
        gap: 0.5rem;
      }
      
      /* Header layout on mobile: keep date and user card on one row under the title */
      .top-bar {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.75rem;
      }
      
      .user-menu {
        display: flex;
        flex-direction: row;
        width: 100%;
        justify-content: space-between;
        align-items: center;
        gap: 0.5rem;
      }
    }
    
    @media (max-width: 480px) {
      .main-content {
        padding: 0.5rem;
        padding-top: 3.5rem;
      }
      
      .content-section {
        padding: 0.75rem;
        border-radius: 8px;
      }
      
      .date-display {
        padding: 0.5rem;
        font-size: 0.8rem;
      }
      
      /* Match Super Dashboard/index header on very small screens:
         keep date and user card on one line, spaced apart */
      .user-menu {
        flex-direction: row;
        justify-content: space-between;
        align-items: center;
        gap: 0.5rem;
      }
    }
    
    /* Print Styles */
    @media print {
      .sidebar, .mobile-menu-btn, .section-actions, .profile-dropdown {
        display: none !important;
      }
      
      .main-content {
        margin-left: 0;
        width: 100%;
        padding: 0;
      }
      
      .content-section {
        box-shadow: none;
        border: 1px solid #ccc;
        page-break-inside: avoid;
      }
    }
  </style>
 </head>
 <body>

  <div class="dashboard-container">
    <!-- Sidebar -->
    <?php $activePage = 'events.php'; include __DIR__ . '/sidebar.php'; ?>
    
    <!-- Main Content -->
    <main class="main-content">
      <!-- Top Bar -->
      <div class="top-bar">
        <div class="page-title">
          <h1>Event Management</h1>
          <p>Create and manage your events</p>
        </div>
        
        <div class="user-menu">
          <div class="date-display">
            <i class="far fa-calendar-alt mr-2"></i>
            <?php echo date('l, F j, Y'); ?>
          </div>
          
          <div class="user-profile" id="userProfile">
            <div class="avatar">
              <?php 
                $displayName = $user['name'] ?? ($user['email'] ?? 'U'); 
                $firstChar = function_exists('mb_substr') ? mb_substr($displayName, 0, 1) : substr($displayName, 0, 1);
                $initial = strtoupper($firstChar ?: 'U'); 
                echo htmlspecialchars($initial); 
              ?>
            </div>
            <div>
              <div style="font-weight: 600;"><?php echo htmlspecialchars($displayName); ?></div>
              <div style="font-size: 0.75rem; color: #64748b;"><?php echo get_role_label($user['role'] ?? 'user'); ?></div>
            </div>
            <i class="fas fa-chevron-down ml-2" style="color: #64748b;"></i>
            
            <div class="profile-dropdown" id="profileDropdown">
              <a href="profile.php"><i class="fas fa-user mr-2"></i> My Profile</a>
              <a href="settings.php"><i class="fas fa-cog mr-2"></i> Settings</a>
              <a href="?logout"><i class="fas fa-sign-out-alt mr-2"></i> Logout</a>
            </div>
          </div>
        </div>
      </div>
      
      <!-- Alerts -->
      <?php if ($error): ?>
        <div class="alert alert-error">
          <i class="fas fa-exclamation-circle mr-2"></i>
          <?php echo htmlspecialchars($error); ?>
        </div>
      <?php endif; ?>
      
      <?php if ($info): ?>
        <div class="alert alert-success">
          <i class="fas fa-check-circle mr-2"></i>
          <?php echo htmlspecialchars($info); ?>
        </div>
      <?php endif; ?>
      
      <!-- Debug info if needed -->
      <?php if (isset($_GET['debug'])): ?>
        <div class="content-section" style="background: #fffbeb;">
          <h3>Debug Information</h3>
          <p>Mode: <?php echo htmlspecialchars($mode); ?></p>
          <p>Events Count: <?php echo count($events); ?></p>
          <p>User: <?php echo htmlspecialchars($user['name'] ?? 'Unknown'); ?></p>
        </div>
      <?php endif; ?>
      
      <!-- Quick Actions (Form) -->
      <?php if ($mode === 'form'): ?>
      <div class="content-section">
        <div class="section-header">
          <h2 class="section-title"><?php echo $editEvent ? 'Edit Event' : 'Create New Event'; ?></h2>
          <div class="section-actions">
            <?php if ($isSuper): ?>
              <button type="button" class="btn btn-secondary" onclick="toggleImportSection()">
                <i class="fas fa-file-import mr-2"></i>
                Import Events
              </button>
            <?php endif; ?>
            <a href="events.php?mode=list" class="btn btn-secondary">
              <i class="fas fa-list mr-2"></i>
              View Events
            </a>
          </div>
        </div>
        
        <!-- Import Section (Initially Hidden) -->
        <?php if ($isSuper): ?>
        <div id="importSection" style="display: none; margin-bottom: 2rem; padding: 1.5rem; background: #f8fafc; border-radius: 8px;">
          <h3 class="text-lg font-semibold mb-4">Import Events from CSV/Excel</h3>
          <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <input type="hidden" name="import_events" value="1">
            <div class="form-group">
              <label class="form-label">Upload CSV/Excel File</label>
              <input type="file" name="events_csv" class="form-input" accept=".csv,.xls,.xlsx" required>
              <div class="text-xs text-gray-500 mt-1">Accepted: .csv (recommended), .xls, .xlsx. Columns: name, date, end_date (optional), location, client, coordinator, graphics, suppliers, head, remarks, status</div>
            </div>
            <div class="flex gap-2">
              <button type="submit" class="btn btn-success">
                <i class="fas fa-file-import mr-2"></i>
                Import Events
              </button>
              <button type="button" class="btn btn-secondary" onclick="toggleImportSection()">
                Cancel
              </button>
            </div>
          </form>
        </div>
        <?php endif; ?>
        
        <div>
          <form method="post" enctype="multipart/form-data" class="<?php echo $operationSupervisorOnlyForm ? 'op-supervisor-only-form' : ''; ?>">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <?php if ($editEvent): ?>
              <?php if ($operationSupervisorOnlyForm): ?>
                <input type="hidden" name="update_supervisor_only" value="1">
              <?php else: ?>
                <input type="hidden" name="update_event" value="1">
              <?php endif; ?>
              <input type="hidden" name="ev_id" value="<?php echo (int)($editEvent['id'] ?? 0); ?>">
            <?php else: ?>
              <input type="hidden" name="create_event" value="1">
            <?php endif; ?>

        <div>
          <div class="event-form-grid">
            <div class="form-group">
              <label class="form-label">Event Name *</label>
              <input type="text" name="ev_name" class="form-input" placeholder="Enter event name" 
                     value="<?php echo htmlspecialchars($editEvent['name'] ?? ''); ?>" required>
            </div>
            
            <div class="form-group">
              <label class="form-label">Date</label>
              <div style="position:relative;">
                <input type="text" id="evDateText" name="ev_date" class="form-input" placeholder="DD/MM/YYYY"
                       value="<?php echo htmlspecialchars(!empty($editEvent['__date_input']) ? $editEvent['__date_input'] : (!empty($editEvent['date']) ? date('d/m/Y', strtotime($editEvent['date'])) : '')); ?>">
                <input type="date" id="evDatePicker" value="<?php echo htmlspecialchars($editEvent['date'] ?? ''); ?>" style="position:absolute; right:0.35rem; top:50%; transform:translateY(-50%); width:2.25rem; height:2.25rem; opacity:0; cursor:pointer; z-index:3;">
                <button type="button" id="evDatePickerBtn" title="Select date" style="position:absolute; right:0.5rem; top:50%; transform:translateY(-50%); background:transparent; border:none; padding:0; cursor:pointer; color:#64748b;">
                  <i class="fas fa-calendar-alt"></i>
                </button>
              </div>
            </div>
            
            <div class="form-group">
              <label class="form-label">End Date (optional)</label>
              <div style="position:relative;">
                <input type="text" id="evEndDateText" name="ev_end_date" class="form-input" placeholder="DD/MM/YYYY"
                       value="<?php echo htmlspecialchars(!empty($editEvent['__end_date_input']) ? $editEvent['__end_date_input'] : (!empty($editEvent['end_date']) ? date('d/m/Y', strtotime($editEvent['end_date'])) : '')); ?>">
                <input type="date" id="evEndDatePicker" value="<?php echo htmlspecialchars($editEvent['end_date'] ?? ''); ?>" style="position:absolute; right:0.35rem; top:50%; transform:translateY(-50%); width:2.25rem; height:2.25rem; opacity:0; cursor:pointer; z-index:3;">
                <button type="button" id="evEndDatePickerBtn" title="Select end date" style="position:absolute; right:0.5rem; top:50%; transform:translateY(-50%); background:transparent; border:none; padding:0; cursor:pointer; color:#64748b;">
                  <i class="fas fa-calendar-alt"></i>
                </button>
              </div>
            </div>
            
            <div class="form-group">
              <label class="form-label">Location</label>
              <input type="text" name="ev_location" class="form-input" placeholder="Event location"
                     value="<?php echo htmlspecialchars($editEvent['location'] ?? ''); ?>">
            </div>
            
            <div class="form-group">
              <label class="form-label">Client</label>
              <input type="text" name="ev_client" class="form-input" placeholder="Client name"
                     value="<?php echo htmlspecialchars($editEvent['client'] ?? ''); ?>">
            </div>
            
            <div class="form-group">
              <label class="form-label">Coordinators</label>
              <div class="multi-select-container">
                <div class="ms" data-placeholder="Select coordinators">
                  <button type="button" class="ms-control form-input" aria-haspopup="listbox" aria-expanded="false">
                    <span class="ms-value ms-placeholder">Select coordinators</span>
                    <span aria-hidden="true">▾</span>
                  </button>
                  <div class="ms-menu" role="listbox">
                    <input type="text" class="ms-search" placeholder="Search..." autocomplete="off">
                    <div class="ms-options"></div>
                  </div>
                  <select name="ev_coordinators[]" id="ev_coordinators_select" class="form-select" multiple>
                    <?php
                      $storedCoordinatorIds = [];
                      if (!empty($editEvent['coordinators'])) {
                          $storedCoordinatorIds = json_decode($editEvent['coordinators'], true) ?: [];
                      } elseif (!empty($editEvent['coordinator_id'])) {
                          $storedCoordinatorIds = [(int)$editEvent['coordinator_id']];
                      }
                      foreach (($coordinators ?? []) as $co) {
                        $coId = (int)($co['id'] ?? 0);
                        $coName = (string)($co['name'] ?? ($co['email'] ?? ''));
                        $isSelected = in_array($coId, $storedCoordinatorIds);
                        echo '<option value="' . $coId . '" ' . ($isSelected ? 'selected' : '') . '>' . htmlspecialchars($coName) . '</option>';
                      }
                    ?>
                  </select>
                </div>
                <small class="form-help">You can select multiple coordinators</small>
              </div>
            </div>
            
            <div class="form-group">
              <label class="form-label">Graphics</label>
              <div class="multi-select-container">
                <div class="ms" data-placeholder="Select graphics">
                  <button type="button" class="ms-control form-input" aria-haspopup="listbox" aria-expanded="false">
                    <span class="ms-value ms-placeholder">Select graphics</span>
                    <span aria-hidden="true">▾</span>
                  </button>
                  <div class="ms-menu" role="listbox">
                    <input type="text" class="ms-search" placeholder="Search..." autocomplete="off">
                    <div class="ms-options"></div>
                  </div>
                  <select name="ev_graphics_users[]" id="ev_graphics_select" class="form-select" multiple>
                    <?php 
                      $storedGraphicsIds = [];
                      if (!empty($editEvent['graphics_users'])) {
                          $storedGraphicsIds = json_decode($editEvent['graphics_users'], true) ?: [];
                      } elseif (!empty($editEvent['graphics_user_id'])) {
                          $storedGraphicsIds = [(int)$editEvent['graphics_user_id']];
                      }
                      foreach (($graphicUsers ?? []) as $g):
                        $gName = $g['name'] ?? ($g['email'] ?? '');
                        $gId = (int)($g['id'] ?? 0);
                        $isSelected = in_array($gId, $storedGraphicsIds);
                    ?>
                      <option value="<?php echo $gId; ?>" <?php echo $isSelected ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($gName); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <small class="form-help">You can select multiple graphics</small>
              </div>
            </div>
            
            <div class="form-group">
              <label class="form-label">Supervisors</label>
              <?php if ($canAssignSupervisor && !empty($supervisors)): ?>
                <div class="multi-select-container">
                  <div class="ms" data-placeholder="Select supervisors">
                    <button type="button" class="ms-control form-input" aria-haspopup="listbox" aria-expanded="false">
                      <span class="ms-value ms-placeholder">Select supervisors</span>
                      <span aria-hidden="true">▾</span>
                    </button>
                    <div class="ms-menu" role="listbox">
                      <input type="text" class="ms-search" placeholder="Search..." autocomplete="off">
                      <div class="ms-options"></div>
                    </div>
                    <select name="ev_supervisors[]" id="ev_supervisors_select" class="form-select" multiple>
                      <?php 
                        $storedSupervisorIds = [];
                        if (!empty($editEvent['supervisors'])) {
                            $storedSupervisorIds = json_decode($editEvent['supervisors'], true) ?: [];
                        }
                        foreach ($supervisors as $sup):
                          $supId = (int)($sup['id'] ?? 0);
                          $supName = $sup['name'] ?? ($sup['email'] ?? '');
                          $isSelected = in_array($supId, $storedSupervisorIds);
                      ?>
                        <option value="<?php echo $supId; ?>" <?php echo $isSelected ? 'selected' : ''; ?>>
                          <?php echo htmlspecialchars($supName); ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <small class="form-help">You can select multiple supervisors</small>
                </div>
              <?php elseif ($canAssignSupervisor): ?>
                <input type="text" name="ev_supervisor" class="form-input" placeholder="Supervisor names (comma separated)"
                       value="<?php echo htmlspecialchars($editEvent['supervisor'] ?? ''); ?>">
                <small class="form-help">Enter multiple supervisor names separated by commas</small>
              <?php else: ?>
                <div class="px-3 py-2 bg-gray-100 border border-gray-300 rounded-lg text-gray-600 text-sm">
                  <?php if (!empty($editEvent['supervisor'])): ?>
                    <?php echo htmlspecialchars($editEvent['supervisor']); ?>
                  <?php else: ?>
                    <em>Supervisor will be assigned by Operation</em>
                  <?php endif; ?>
                </div>
                <input type="hidden" name="ev_supervisor" value="<?php echo htmlspecialchars($editEvent['supervisor'] ?? ''); ?>">
              <?php endif; ?>

            </div>

            <div class="form-group">
                  <label class="form-label">Head</label>
                  <input type="text" name="ev_head" class="form-input" placeholder="Head"
                         value="<?php echo htmlspecialchars($editEvent['head'] ?? ''); ?>">
                </div>
                
                <div class="form-group">
                  <label class="form-label">Status</label>
                  <select name="ev_status" class="form-select">
                    <option value="">Select status</option>
                    <option value="PLANNING" <?php echo ($editEvent['status'] ?? '') === 'PLANNING' ? 'selected' : ''; ?>>PLANNING</option>
                    <option value="CONFIRMED" <?php echo ($editEvent['status'] ?? '') === 'CONFIRMED' ? 'selected' : ''; ?>>CONFIRMED</option>
                    <option value="COMPLETED" <?php echo ($editEvent['status'] ?? '') === 'COMPLETED' ? 'selected' : ''; ?>>COMPLETED</option>
                  </select>
                </div>
                
                <div class="form-group">
                  <label class="form-label">Client Status</label>
                  <select name="ev_client_status" class="form-select">
                    <option value="">Select client status</option>
                    <option value="QUOTE_SENT" <?php echo ($editEvent['client_status'] ?? '') === 'QUOTE_SENT' ? 'selected' : ''; ?>>Quote sent client</option>
                    <option value="QUOTE_APPROVED" <?php echo ($editEvent['client_status'] ?? '') === 'QUOTE_APPROVED' ? 'selected' : ''; ?>>Quote approved</option>
                  </select>
                </div>

                <div class="form-group">
                  <label class="form-label">EFD</label>
                  <select name="ev_efd" class="form-select">
                    <option value="">Select EFD</option>
                    <option value="N.A" <?php echo ($editEvent['efd'] ?? '') === 'N.A' ? 'selected' : ''; ?>>N.A</option>
                    <option value="Issued" <?php echo ($editEvent['efd'] ?? '') === 'Issued' ? 'selected' : ''; ?>>Issued</option>
                    <option value="Not issued" <?php echo ($editEvent['efd'] ?? '') === 'Not issued' ? 'selected' : ''; ?>>Not issued</option>
                  </select>
                </div>

                <div class="form-group">
                  <label class="form-label">Amount</label>
                  <input type="number" step="0.01" name="ev_amount" id="ev_amount" class="form-input" placeholder="0.00"
                         value="<?php echo htmlspecialchars((string)($editEvent['amount'] ?? '')); ?>">
                </div>
                
                <div class="form-group">
                  <label class="form-label">Advance</label>
                  <input type="number" step="0.01" name="ev_advance" id="ev_advance" class="form-input" placeholder="0.00"
                         value="<?php echo htmlspecialchars((string)($editEvent['advance'] ?? '')); ?>">
                </div>
                
                <div class="form-group">
                  <label class="form-label">Balance</label>
                  <input type="number" step="0.01" name="ev_balance" id="ev_balance" class="form-input" placeholder="0.00" readonly
                         value="<?php 
                           $amt = isset($editEvent['amount']) ? (float)$editEvent['amount'] : 0; 
                           $adv = isset($editEvent['advance']) ? (float)$editEvent['advance'] : 0; 
                           $bal = isset($editEvent['balance']) ? (float)$editEvent['balance'] : ($amt - $adv);
                           echo htmlspecialchars(number_format((float)$bal, 2, '.', ''));
                         ?>">
                </div>
                
                <div class="form-group" style="grid-column: 1 / -1;">
                  <label class="form-label">Remarks</label>
                  <input type="text" name="ev_remarks" class="form-input" placeholder="Remarks"
                         value="<?php echo htmlspecialchars($editEvent['remarks'] ?? ''); ?>">
                </div>

                <?php 
                // Only sales, accountant, operation, admin, super can view/upload Quote
                $canViewQuote = in_array($user['role'] ?? '', ['sales', 'accountant', 'operation', 'admin', 'super'], true);
                if ($canViewQuote): 
                ?>
                <div class="form-group" style="grid-column: 1 / -1;">
                  <label class="form-label">Quote File (PDF, DOC, XLS, PNG, JPG)</label>
                  <input type="file" name="ev_quote_file" class="form-input" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.png,.jpg,.jpeg,.gif">
                  <?php if ($editEvent && !empty($editEvent['quote_file'])): ?>
                    <div class="text-sm mt-1">
                      Current file: <a href="<?php echo htmlspecialchars($editEvent['quote_file']); ?>" target="_blank" class="link-highlight">Download</a>
                    </div>
                  <?php endif; ?>
                </div>
                <?php endif; ?>

                <div class="form-group" style="grid-column: 1 / -1;">
                  <label class="form-label">Checklist File</label>
                  <input type="file" name="ev_checklist_file" class="form-input" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.png,.jpg,.jpeg,.gif">
                  <?php if ($editEvent && !empty($editEvent['checklist_file'])): ?>
                    <div class="text-sm mt-1">
                      Current file: <a href="<?php echo htmlspecialchars($editEvent['checklist_file']); ?>" target="_blank" class="link-highlight">Download</a>
                    </div>
                  <?php endif; ?>
                </div>

                <div class="form-group" style="grid-column: 1 / -1;">
                  <label class="form-label">Mockup File</label>
                  <input type="file" name="ev_mockup_file" class="form-input" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.png,.jpg,.jpeg,.gif">
                  <?php if ($editEvent && !empty($editEvent['mockup_file'])): ?>
                    <div class="text-sm mt-1">
                      Current file: <a href="<?php echo htmlspecialchars($editEvent['mockup_file']); ?>" target="_blank" class="link-highlight">Download</a>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
              
              <div class="flex gap-2 mt-4">
                <button type="submit" class="btn btn-primary">
                  <i class="fas <?php echo $editEvent ? 'fa-save' : 'fa-plus'; ?> mr-2"></i>
                  <?php echo $editEvent ? 'Update Event' : 'Create Event'; ?>
                </button>
                <?php if ($editEvent): ?>
                  <a href="events.php?mode=list" class="btn btn-secondary">Cancel</a>
                <?php endif; ?>
              </div>
            </div>
            </form>
          </div>
      </div>
      <?php endif; ?>

      <!-- All Events (List) -->
      <?php if ($mode === 'list'): ?>
      <?php if (in_array($role, ['finance', 'accountant'], true)): ?>
      <div class="content-section">
        <div class="section-header">
          <h2 class="section-title">Financial Compliance Overview</h2>
          <div class="section-actions" style="gap:0.5rem;">
            <input id="financeComplianceSearch" type="text" class="form-input" style="max-width:260px;" placeholder="Search event...">
            <div style="display:flex;gap:0.5rem;align-items:center;">
              <button id="financeCompliancePrev" type="button" class="btn btn-secondary">Previous</button>
              <span id="financeCompliancePageInfo" style="font-size:0.85rem;color:#64748b;"></span>
              <button id="financeComplianceNext" type="button" class="btn btn-secondary">Next</button>
            </div>
          </div>
        </div>
        <div class="table-container">
          <table class="data-table" id="financeComplianceTable">
            <thead>
            <tr>
              <th>#</th>
              <th>Event</th>
              <th>Client</th>
              <th>Amount</th>
              <th>Advance</th>
              <th>Balance</th>
              <th>Quote</th>
              <th>Checklist</th>
              <th>Mockup</th>
              <th>Quote Status</th>
              <th>Finance Comment</th>
              <th class="text-center">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php $rowNum = 1; foreach ($events as $e): ?>
              <tr>
                <td><?php echo (int)$rowNum; ?></td>
                <td><?php echo htmlspecialchars((string)($e['name'] ?? '')); ?></td>
                <td><?php echo htmlspecialchars((string)($e['client'] ?? '')); ?></td>
                <td><?php echo htmlspecialchars(number_format((float)($e['amount'] ?? 0), 2)); ?></td>
                <td><?php echo htmlspecialchars(number_format((float)($e['advance'] ?? 0), 2)); ?></td>
                <td><?php echo htmlspecialchars(number_format((float)($e['balance'] ?? ((float)($e['amount'] ?? 0) - (float)($e['advance'] ?? 0))), 2)); ?></td>
                <td>
                  <?php if (!empty($e['quote_file'])): ?>
                    <a class="link-highlight" target="_blank" href="<?php echo htmlspecialchars((string)$e['quote_file']); ?>">View</a>
                  <?php else: ?>
                    <span class="text-slate-400">-</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if (!empty($e['checklist_file'])): ?>
                    <a class="link-highlight" target="_blank" href="<?php echo htmlspecialchars((string)$e['checklist_file']); ?>">View</a>
                  <?php else: ?>
                    <span class="text-slate-400">-</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if (!empty($e['mockup_file'])): ?>
                    <a class="link-highlight" target="_blank" href="<?php echo htmlspecialchars((string)$e['mockup_file']); ?>">View</a>
                  <?php else: ?>
                    <span class="text-slate-400">-</span>
                  <?php endif; ?>
                </td>
                <td><?php echo htmlspecialchars((string)($e['quote_status'] ?? 'pending')); ?></td>
                <td><?php echo htmlspecialchars((string)($e['quote_finance_comment'] ?? '')); ?></td>
                <td class="text-center">
                  <a href="events.php?id=<?php echo (int)$e['id']; ?>&mode=details" class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-blue-50 text-blue-600 hover:bg-blue-100 transition-colors duration-200" title="View Details">
                    <i class="fas fa-eye text-sm"></i>
                  </a>
                </td>
              </tr>
            <?php $rowNum++; endforeach; ?>
            <?php if (empty($events)): ?>
              <tr><td colspan="12" style="text-align:center;color:#64748b;padding:2rem;">No events found</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>

      <?php if (($role ?? '') === 'operation'): ?>
      <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden w-full" style="margin-bottom: 1.5rem;">
        <div class="p-5 border-b border-gray-100 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h3 class="text-lg font-semibold text-gray-800">Events (Operation)</h3>
            <div class="mt-1 text-xs text-gray-500">
              Showing: <span class="font-semibold"><?php echo number_format(count($opEvents)); ?></span>
            </div>
          </div>
          <form method="get" class="flex items-center gap-2" style="flex-wrap: wrap; justify-content: flex-end;">
            <input type="hidden" name="mode" value="list">
            <select name="supervisor" class="appearance-none bg-white border border-gray-200 rounded-lg pl-3 pr-8 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
              <option value="all" <?php echo ($opFilters['supervisor'] === 'all') ? 'selected' : ''; ?>>All</option>
              <option value="assigned" <?php echo ($opFilters['supervisor'] === 'assigned') ? 'selected' : ''; ?>>With Supervisor</option>
              <option value="unassigned" <?php echo ($opFilters['supervisor'] === 'unassigned') ? 'selected' : ''; ?>>No Supervisor</option>
            </select>
            <select name="quote_status" class="appearance-none bg-white border border-gray-200 rounded-lg pl-3 pr-8 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
              <option value="all" <?php echo (strtolower((string)$opFilters['quote_status']) === 'all') ? 'selected' : ''; ?>>Quote: All</option>
              <option value="pending" <?php echo (strtolower((string)$opFilters['quote_status']) === 'pending') ? 'selected' : ''; ?>>Quote: Pending</option>
              <option value="approved" <?php echo (strtolower((string)$opFilters['quote_status']) === 'approved') ? 'selected' : ''; ?>>Quote: Approved</option>
              <option value="rejected" <?php echo (strtolower((string)$opFilters['quote_status']) === 'rejected') ? 'selected' : ''; ?>>Quote: Rejected</option>
            </select>
            <input type="month" name="month" value="<?php echo htmlspecialchars((string)($opFilters['month'] ?? '')); ?>" class="appearance-none bg-white border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500" />
            <input type="text" name="status" value="<?php echo htmlspecialchars((string)($opFilters['status'] ?? 'all')); ?>" class="appearance-none bg-white border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="Status (or all)" style="min-width: 150px;" />
            <button type="submit" class="inline-flex items-center px-4 py-2 rounded-lg text-sm font-medium text-white bg-blue-600 hover:bg-blue-700">Filter</button>
          </form>
        </div>
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-100">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">#</th>
                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Event</th>
                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Client</th>
                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Location</th>
                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Supervisor</th>
                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Date</th>
                <th class="px-5 py-3 text-center text-xs font-semibold text-gray-500 uppercase tracking-wider">View</th>
                <th class="px-5 py-3 text-center text-xs font-semibold text-gray-500 uppercase tracking-wider">Change</th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-100">
              <?php if (empty($opEvents)): ?>
                <tr>
                  <td colspan="8" class="px-5 py-8 text-center text-sm text-gray-500">No events found.</td>
                </tr>
              <?php else: ?>
                <?php $opNo = 1; foreach ($opEvents as $ev): ?>
                  <?php
                    $supText = '';
                    if (isset($normalizeSupervisorText) && is_callable($normalizeSupervisorText)) {
                        $supText = (string)$normalizeSupervisorText($ev);
                    } else {
                        $supText = trim((string)($ev['supervisor'] ?? ''));
                    }
                  ?>
                  <tr class="hover:bg-gray-50">
                    <td class="px-5 py-3 text-sm text-gray-600"><?php echo (int)$opNo; ?></td>
                    <td class="px-5 py-3 text-sm font-medium text-gray-900"><?php echo htmlspecialchars((string)($ev['name'] ?? '')); ?></td>
                    <td class="px-5 py-3 text-sm text-gray-700"><?php echo htmlspecialchars((string)($ev['client'] ?? '')); ?></td>
                    <td class="px-5 py-3 text-sm text-gray-700"><?php echo htmlspecialchars((string)($ev['location'] ?? '')); ?></td>
                    <td class="px-5 py-3 text-sm text-gray-700"><?php echo htmlspecialchars($supText !== '' ? $supText : '-'); ?></td>
                    <td class="px-5 py-3 text-sm text-gray-700"><?php echo !empty($ev['date']) ? htmlspecialchars((string)$ev['date']) : 'TBD'; ?></td>
                    <td class="px-5 py-3 text-center">
                      <a href="events.php?id=<?php echo (int)($ev['id'] ?? 0); ?>&mode=details" title="View Event Details" class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-blue-50 text-blue-600 hover:bg-blue-100 transition-colors duration-200">
                        <i class="fas fa-eye text-sm"></i>
                      </a>
                    </td>
                    <td class="px-5 py-3 text-center">
                      <a href="events.php?action=edit_supervisor&id=<?php echo (int)($ev['id'] ?? 0); ?>" title="Change Supervisor" class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-amber-50 text-amber-700 hover:bg-amber-100 transition-colors duration-200">
                        <i class="fas fa-pen-to-square text-sm"></i>
                      </a>
                    </td>
                  </tr>
                <?php $opNo++; endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <div class="px-5 py-4 border-t border-gray-100 bg-white">
          <a href="events.php?mode=list&supervisor=all" class="text-sm font-medium text-blue-600 hover:text-blue-800 inline-flex items-center gap-2">
            View all
            <i class="fas fa-arrow-down"></i>
          </a>
        </div>
      </div>
      <?php endif; ?>

      <?php if (in_array($role, ['store', 'production', 'graphic'], true) && !in_array($role, ['admin', 'super'], true)): ?>
      <div class="content-section">
        <div class="section-header">
          <h2 class="section-title">Events Files (Checklist / Mockup)</h2>
          <div class="section-actions" style="gap:0.5rem;">
            <input id="eventsFilesSearch" type="text" class="form-input" style="max-width:260px;" placeholder="Search event...">
            <div style="display:flex;gap:0.5rem;align-items:center;">
              <button id="eventsFilesPrev" type="button" class="btn btn-secondary">Previous</button>
              <span id="eventsFilesPageInfo" style="font-size:0.85rem;color:#64748b;"></span>
              <button id="eventsFilesNext" type="button" class="btn btn-secondary">Next</button>
            </div>
          </div>
        </div>
        <div class="table-container">
          <table class="data-table" id="eventsFilesTable">
            <thead>
            <tr>
              <th>#</th>
              <th>Event</th>
              <th>Date</th>
              <th>Client</th>
              <th>Checklist</th>
              <th>Mockup</th>
              <th class="text-center">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php
              $filesRow = 1;
              $eventsFiles = array_values(array_filter($events, function ($ev) {
                  return !empty($ev['checklist_file']) || !empty($ev['mockup_file']);
              }));
            ?>
            <?php foreach ($eventsFiles as $ev): ?>
              <tr>
                <td><?php echo (int)$filesRow; ?></td>
                <td><?php echo htmlspecialchars((string)($ev['name'] ?? '')); ?></td>
                <td><?php echo htmlspecialchars((string)($ev['date'] ?? '')); ?></td>
                <td><?php echo htmlspecialchars((string)($ev['client'] ?? '')); ?></td>
                <td>
                  <?php if (!empty($ev['checklist_file'])): ?>
                    <a class="link-highlight" target="_blank" href="<?php echo htmlspecialchars((string)$ev['checklist_file']); ?>">View</a>
                  <?php else: ?>
                    <span class="text-slate-400">-</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if (!empty($ev['mockup_file'])): ?>
                    <a class="link-highlight" target="_blank" href="<?php echo htmlspecialchars((string)$ev['mockup_file']); ?>">View</a>
                  <?php else: ?>
                    <span class="text-slate-400">-</span>
                  <?php endif; ?>
                </td>
                <td class="text-center">
                  <a href="events.php?id=<?php echo (int)$ev['id']; ?>&mode=details" class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-blue-50 text-blue-600 hover:bg-blue-100 transition-colors duration-200" title="View Details">
                    <i class="fas fa-eye text-sm"></i>
                  </a>
                </td>
              </tr>
              <?php $filesRow++; ?>
            <?php endforeach; ?>
            <?php if (empty($eventsFiles)): ?>
              <tr><td colspan="7" style="text-align:center;color:#64748b;padding:2rem;">No event files found</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>

      <?php if (!in_array($role, ['finance', 'accountant', 'store', 'production', 'graphic', 'operation'], true)): ?>
      <div class="content-section">
        <?php if (!empty($_GET['success'])): ?>
          <div class="mb-4 p-3 rounded-lg bg-green-50 border border-green-200 text-sm text-green-800 flex items-start">
            <span class="mt-0.5 mr-2"><i class="fas fa-check-circle"></i></span>
            <div>
              <p class="font-medium">Event created successfully.</p>
            </div>
          </div>
        <?php endif; ?>
        <div class="section-header">
          <h2 class="section-title">All Events</h2>
          <div class="section-actions">
            <?php if ($canManageEvents): ?>
            <a href="events.php?mode=form" class="btn btn-primary"><i class="fas fa-plus mr-1"></i> Add Event</a>
            <?php endif; ?>
            <button id="toggleFilters" class="btn btn-secondary"><i class="fas fa-search mr-1"></i> <?php echo $role === 'supervisor' ? 'Search Event Information' : 'Search'; ?></button>
          </div>
        </div>
        
        <!-- Search Panel -->
        <div id="searchPanel" class="bg-gray-50 p-4 rounded-lg mb-4" style="display: <?php echo $role === 'supervisor' ? 'block' : 'none'; ?>;">
          <h3 class="text-lg font-medium <?php echo $role === 'supervisor' ? 'mb-1' : 'mb-3'; ?>"><?php echo $role === 'supervisor' ? 'Search Any Event Information' : 'Search Events'; ?></h3>
          <?php if ($role === 'supervisor'): ?><p class="text-sm text-gray-500 mb-3">Search by event, client, location, coordinator, graphic, supervisor, status, remarks or date.</p><?php endif; ?>
          <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Search Text</label>
              <input type="text" id="globalSearch" class="form-input w-full" placeholder="<?php echo $role === 'supervisor' ? 'Search any event information...' : 'Search across all columns...'; ?>">
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Date Range</label>
              <div class="flex gap-2">
                <input type="date" id="minDate" class="form-input w-full" placeholder="From">
                <input type="date" id="maxDate" class="form-input w-full" placeholder="To">
              </div>
            </div>
            <div class="flex items-end gap-2">
              <button id="applySearch" class="btn btn-primary flex-1">
                <i class="fas fa-search mr-1"></i> Search
              </button>
              <button id="resetSearch" class="btn btn-secondary">
                <i class="fas fa-sync-alt mr-1"></i> Reset
              </button>
            </div>
          </div>
        </div>
        
        <div class="table-responsive">
          <table id="eventsTable" class="table table-striped table-hover" style="width:100%">
            <caption class="sr-only">List of all events with their details</caption>
            <thead>
              <tr>
                <th class="sticky top-0 bg-gray-50 z-10">Date</th>
                <th class="sticky top-0 bg-gray-50 z-10">Event</th>
                <th class="sticky top-0 bg-gray-50 z-10">Client</th>
                <th class="sticky top-0 bg-gray-50 z-10">Location</th>
                <th class="sticky top-0 bg-gray-50 z-10">Coordinator</th>
                <th class="text-center sticky top-0 bg-gray-50 z-10">Graphics</th>
                <th class="text-center sticky top-0 bg-gray-50 z-10">Supervisor</th>
                <th class="text-center sticky top-0 bg-gray-50 z-10">Amount Details</th>
                <th class="sticky top-0 bg-gray-50 z-10">Remarks</th>
                <th class="text-center sticky top-0 bg-gray-50 z-10">Status</th>
                <th class="text-center sticky top-0 bg-gray-50 z-10">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($events as $ev): 
                $start = !empty($ev['date']) ? $ev['date'] : null;
                $end = !empty($ev['end_date']) ? $ev['end_date'] : null;
                $isPast = $start && strtotime($end ?: $start) < time();
                $dateLabel = $start ? ($end && $end !== $start ? htmlspecialchars($start) . ' → ' . htmlspecialchars($end) : htmlspecialchars($start)) : 'TBD';
                $dayLabel = $start ? ( $end && $end !== $start ? date('l', strtotime($start)) . ' → ' . date('l', strtotime($end)) : date('l', strtotime($start)) ) : 'TBD';
              ?>
                <tr>
                  <td data-order="<?php echo $start ? strtotime($start) : ''; ?>">
                    <span data-raw-date="<?php echo htmlspecialchars($start ?: ''); ?>" style="display:none;"></span>
                    <div class="font-medium"><?php echo $dateLabel; ?></div>
                    <div class="text-xs text-gray-500"><?php echo $dayLabel; ?></div>
                  </td>
                  <td data-order="<?php echo htmlspecialchars($ev['name']); ?>">
                    <div class="font-medium"><?php echo htmlspecialchars($ev['name']); ?></div>
                  </td>
                  <td>
                    <div class="font-medium"><?php echo htmlspecialchars($ev['client'] ?? 'N/A'); ?></div>
                    <div class="text-xs text-gray-500"><?php echo !empty($ev['client_status']) ? htmlspecialchars($ev['client_status']) : 'No status'; ?></div>
                  </td>
                  <td>
                    <div class="font-medium"><?php echo !empty($ev['location']) ? htmlspecialchars($ev['location']) : 'TBA'; ?></div>
                    <div class="text-xs text-gray-500"><?php echo !empty($ev['efd']) ? 'EFD: ' . htmlspecialchars($ev['efd']) : 'No EFD'; ?></div>
                  </td>
                  <td>
                    <div class="font-medium"><?php echo htmlspecialchars($ev['coordinator'] ?? 'N/A'); ?></div>
                    <div class="text-xs text-gray-500"><?php echo htmlspecialchars($ev['head'] ?? 'No head'); ?></div>
                  </td>
                  <td class="text-center">
                    <?php if (!empty($ev['graphics'])): ?>
                      <?php echo htmlspecialchars($ev['graphics']); ?>
                    <?php else: ?>
                      <span class="text-gray-400">N/A</span>
                    <?php endif; ?>
                  </td>
                  <td class="text-center">
                    <?php if (!empty($ev['supervisor'])): ?>
                      <?php echo htmlspecialchars($ev['supervisor']); ?>
                    <?php else: ?>
                      <span class="text-gray-400">N/A</span>
                    <?php endif; ?>
                  </td>
                  <td class="whitespace-nowrap">
                    <div class="font-medium"><?php echo isset($ev['amount']) && $ev['amount'] !== '' ? 'Tsh ' . number_format((float)$ev['amount'], 0, '.', ',') : 'Tsh 0'; ?></div>
                    <div class="text-xs">
                      <span class="text-green-600">Adv: Tsh <?php echo isset($ev['advance']) && $ev['advance'] !== '' ? number_format((float)$ev['advance'], 0, '.', ',') : '0'; ?></span>
                      <span class="text-blue-600"> | Bal: Tsh <?php $amt=(float)($ev['amount'] ?? 0); $adv=(float)($ev['advance'] ?? 0); $bal = isset($ev['balance']) ? (float)$ev['balance'] : ($amt - $adv); echo number_format($bal, 0, '.', ','); ?></span>
                    </div>
                  </td>
                  <td class="max-w-xs">
                    <?php if (!empty($ev['remarks'])): ?>
                      <div class="truncate" title="<?php echo htmlspecialchars($ev['remarks']); ?>">
                        <?php echo htmlspecialchars(mb_strimwidth($ev['remarks'], 0, 30, '...')); ?>
                      </div>
                    <?php else: ?>
                      <span class="text-gray-400">N/A</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if ($isPast): ?>
                      <span class="badge badge-secondary">
                        <i class="fas fa-check-circle mr-1"></i> Completed
                      </span>
                    <?php else: ?>
                      <span class="badge badge-warning">
                        <i class="fas fa-calendar-alt mr-1"></i> Upcoming
                      </span>
                    <?php endif; ?>
                  </td>
                  <td class="text-center">
                    <div class="flex items-center justify-center space-x-1">
                      <a href="events.php?id=<?php echo (int)$ev['id']; ?>&mode=details" class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-blue-50 text-blue-600 hover:bg-blue-100 transition-colors duration-200" data-bs-toggle="tooltip" title="View Details">
                        <i class="fas fa-eye text-sm"></i>
                      </a>
                      <?php if ($canManageEvents): ?>
                      <a href="events.php?action=edit&id=<?php echo (int)$ev['id']; ?>" class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-gray-100 text-gray-600 hover:bg-gray-200 transition-colors duration-200" data-bs-toggle="tooltip" title="Edit Event">
                        <i class="fas fa-edit text-sm"></i>
                      </a>
                      <?php endif; ?>
                      <?php if ($isSuper): ?>
                        <a href="events.php?action=delete&id=<?php echo (int)$ev['id']; ?>" class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-red-50 text-red-600 hover:bg-red-100 transition-colors duration-200" onclick="return confirm('Are you sure you want to delete this event?')" data-bs-toggle="tooltip" title="Delete Event">
                          <i class="fas fa-trash text-sm"></i>
                        </a>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (empty($events)): ?>
                <tr>
                  <td colspan="17" class="text-center py-8 text-gray-500">
                    <i class="fas fa-calendar-times text-3xl mb-2 block"></i>
                    No events found. Create your first event above.
                  </td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>

      <?php endif; ?>

      <?php if ($eventId > 0 && $mode === 'details'): 
        $currentEvent = null;
        foreach ($events as $ev) {
          if ((int)$ev['id'] === $eventId) { $currentEvent = $ev; break; }
        }
      ?>
        <!-- Event Details - Horizontal Table Layout -->
        <div class="content-section">
          <div class="section-header">
            <h2 class="section-title">
              <?php echo htmlspecialchars($currentEvent['name'] ?? 'Event'); ?>
            </h2>
            <div class="section-actions">
              <a href="events.php?mode=list" class="btn btn-secondary">
                <i class="fas fa-arrow-left mr-2"></i> Back to Events
              </a>
              <a href="events.php?action=edit&id=<?php echo $eventId; ?>" class="btn btn-primary">
                <i class="fas fa-edit mr-2"></i> Edit Event
              </a>
            </div>
          </div>
          
          <!-- Horizontal Table for Event Details -->
          <div class="table-container">
            <table class="horizontal-table">
              <tbody>
                <tr>
                  <th>Event Name</th>
                  <td><?php echo htmlspecialchars($currentEvent['name'] ?? 'Not set'); ?></td>
                </tr>
                <tr>
                  <th>Date</th>
                  <td>
                    <?php 
                      $s = $currentEvent['date'] ?? '';
                      $e = $currentEvent['end_date'] ?? '';
                      if ($s === '') { echo 'Not set'; }
                      else if ($e && $e !== $s) { echo htmlspecialchars($s) . ' → ' . htmlspecialchars($e); }
                      else { echo htmlspecialchars($s); }
                    ?>
                  </td>
                </tr>
                <tr>
                  <th>Day</th>
                  <td>
                    <?php 
                      if (!empty($currentEvent['date'])) {
                        if (!empty($currentEvent['end_date']) && $currentEvent['end_date'] !== $currentEvent['date']) {
                          echo date('l', strtotime($currentEvent['date'])) . ' → ' . date('l', strtotime($currentEvent['end_date']));
                        } else {
                          echo date('l', strtotime($currentEvent['date']));
                        }
                      } else { echo 'Not set'; }
                    ?>
                  </td>
                </tr>
                <tr>
                  <th>Location</th>
                  <td><?php echo !empty($currentEvent['location']) ? htmlspecialchars($currentEvent['location']) : 'Not set'; ?></td>
                </tr>
                <tr>
                  <th>Client</th>
                  <td><?php echo !empty($currentEvent['client']) ? htmlspecialchars($currentEvent['client']) : 'Not set'; ?></td>
                </tr>
                <tr>
                  <th>Coordinators</th>
                  <td>
                    <?php 
                      $coordinatorNames = [];
                      if (!empty($currentEvent['coordinators'])) {
                          $coordinatorIds = json_decode($currentEvent['coordinators'], true) ?: [];
                          if (!empty($coordinatorIds)) {
                              $placeholders = str_repeat('?,', count($coordinatorIds) - 1) . '?';
                              $coStmt = $db->prepare("SELECT name FROM users WHERE id IN ($placeholders) AND role = 'sales'");
                              $coStmt->execute($coordinatorIds);
                              $coordinatorNames = $coStmt->fetchAll(PDO::FETCH_COLUMN);
                          }
                      }
                      if (!empty($coordinatorNames)) {
                          echo implode(', ', $coordinatorNames);
                      } elseif (!empty($currentEvent['coordinator'])) {
                          echo htmlspecialchars($currentEvent['coordinator']);
                      } else {
                          echo 'Not set';
                      }
                    ?>
                  </td>
                </tr>
                <tr>
                  <th>Graphics</th>
                  <td>
                    <?php 
                      $graphicsNames = [];
                      if (!empty($currentEvent['graphics_users'])) {
                          $graphicsIds = json_decode($currentEvent['graphics_users'], true) ?: [];
                          if (!empty($graphicsIds)) {
                              $placeholders = str_repeat('?,', count($graphicsIds) - 1) . '?';
                              $gStmt = $db->prepare("SELECT name FROM users WHERE id IN ($placeholders) AND role = 'graphic'");
                              $gStmt->execute($graphicsIds);
                              $graphicsNames = $gStmt->fetchAll(PDO::FETCH_COLUMN);
                          }
                      }
                      if (!empty($graphicsNames)) {
                          echo implode(', ', $graphicsNames);
                      } elseif (!empty($currentEvent['graphics'])) {
                          echo htmlspecialchars($currentEvent['graphics']);
                      } else {
                          echo 'Not set';
                      }
                    ?>
                  </td>
                </tr>
                <tr>
                  <th>Supervisors</th>
                  <td>
                    <?php 
                      $supervisorNames = [];
                      if (!empty($currentEvent['supervisors'])) {
                          $supervisorIds = json_decode($currentEvent['supervisors'], true) ?: [];
                          if (!empty($supervisorIds)) {
                              $placeholders = str_repeat('?,', count($supervisorIds) - 1) . '?';
                              $sStmt = $db->prepare("SELECT name FROM users WHERE id IN ($placeholders) AND role = 'supervisor'");
                              $sStmt->execute($supervisorIds);
                              $supervisorNames = $sStmt->fetchAll(PDO::FETCH_COLUMN);
                          }
                      }
                      if (!empty($supervisorNames)) {
                          echo implode(', ', $supervisorNames);
                      } elseif (!empty($currentEvent['supervisor'])) {
                          echo htmlspecialchars($currentEvent['supervisor']);
                      } else {
                          echo 'Not set';
                      }
                    ?>
                  </td>
                </tr>
                <tr>
                  <th>Head</th>
                  <td><?php echo !empty($currentEvent['head']) ? htmlspecialchars($currentEvent['head']) : 'Not set'; ?></td>
                </tr>
                <tr>
                  <th>Status</th>
                  <td><?php echo !empty($currentEvent['status']) ? htmlspecialchars($currentEvent['status']) : 'Not set'; ?></td>
                </tr>
                <tr>
                  <th>Remarks</th>
                  <td><?php echo !empty($currentEvent['remarks']) ? htmlspecialchars($currentEvent['remarks']) : 'No remarks'; ?></td>
                </tr>
                <?php 
                // Only sales, accountant, operation, admin, super can view Quote
                $canViewQuote = in_array($user['role'] ?? '', ['sales', 'accountant', 'finance', 'operation', 'admin', 'super'], true);
                if ($canViewQuote): 
                ?>
                <tr>
                  <th>Quote File</th>
                  <td>
                    <?php if (!empty($currentEvent['quote_file'])): ?>
                      <a href="<?php echo htmlspecialchars($currentEvent['quote_file']); ?>" target="_blank" class="link-highlight">View Quote</a>
                    <?php else: ?>
                      <span class="text-gray-400">Not uploaded</span>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php endif; ?>
                <tr>
                  <th>Checklist File</th>
                  <td>
                    <?php if (!empty($currentEvent['checklist_file'])): ?>
                      <a href="<?php echo htmlspecialchars($currentEvent['checklist_file']); ?>" target="_blank" class="link-highlight">View Checklist</a>
                    <?php else: ?>
                      <span class="text-gray-400">Not uploaded</span>
                    <?php endif; ?>
                  </td>
                </tr>
                <tr>
                  <th>Mockup File</th>
                  <td>
                    <?php if (!empty($currentEvent['mockup_file'])): ?>
                      <a href="<?php echo htmlspecialchars($currentEvent['mockup_file']); ?>" target="_blank" class="link-highlight">View Mockup</a>
                    <?php else: ?>
                      <span class="text-gray-400">Not uploaded</span>
                    <?php endif; ?>
                  </td>
                </tr>
                <tr>
                  <th>Client Status</th>
                  <td><?php echo !empty($currentEvent['client_status']) ? htmlspecialchars($currentEvent['client_status']) : 'Not set'; ?></td>
                </tr>
                <tr>
                  <th>EFD</th>
                  <td><?php echo !empty($currentEvent['efd']) ? htmlspecialchars($currentEvent['efd']) : 'Not set'; ?></td>
                </tr>
                <?php $canViewFinancials = in_array($role, ['finance', 'accountant', 'sales', 'admin', 'super'], true); if ($canViewFinancials): ?>
                <tr>
                  <th>Amount</th>
                  <td class="font-mono">
                    <?php 
                      $amount = isset($currentEvent['amount']) && $currentEvent['amount'] !== '' ? (float)$currentEvent['amount'] : 0;
                      echo 'Tsh ' . number_format($amount, 0, '.', ',');
                    ?>
                  </td>
                </tr>
                <tr>
                  <th>Advance</th>
                  <td class="font-mono text-green-600">
                    <?php 
                      $advance = isset($currentEvent['advance']) && $currentEvent['advance'] !== '' ? (float)$currentEvent['advance'] : 0;
                      echo 'Tsh ' . number_format($advance, 0, '.', ',');
                    ?>
                  </td>
                </tr>
                <tr>
                  <th>Balance</th>
                  <td class="font-mono font-semibold">
                    <?php 
                      $amt = isset($currentEvent['amount']) ? (float)$currentEvent['amount'] : 0; 
                      $adv = isset($currentEvent['advance']) ? (float)$currentEvent['advance'] : 0; 
                      $bal = isset($currentEvent['balance']) && $currentEvent['balance'] !== '' ? (float)$currentEvent['balance'] : ($amt - $adv);
                      echo 'Tsh ' . number_format($bal, 0, '.', ',');
                    ?>
                  </td>
                </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endif; ?>
    </main>
  </div>

  <!-- DataTables JS -->
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
  <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
  <script src="https://cdn.datatables.net/buttons/2.2.2/js/dataTables.buttons.min.js"></script>
  <script src="https://cdn.datatables.net/buttons/2.2.2/js/buttons.bootstrap5.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.1.3/jszip.min.js"></script>
  <script src="https://cdn.datatables.net/buttons/2.2.2/js/buttons.html5.min.js"></script>
  <script src="https://cdn.datatables.net/buttons/2.2.2/js/buttons.print.min.js"></script>
  <script src="https://cdn.datatables.net/responsive/2.2.9/js/dataTables.responsive.min.js"></script>
  <script src="https://cdn.datatables.net/responsive/2.2.9/js/responsive.bootstrap5.min.js"></script>
  <script>
    // Mobile menu functionality
    const mobileMenuBtn = document.getElementById('mobileMenuBtn');
    const sidebar = document.querySelector('.sidebar');
    
    if (mobileMenuBtn && sidebar) {
      mobileMenuBtn.addEventListener('click', function() {
        sidebar.classList.toggle('mobile-open');
      });
    }

    // Toggle profile dropdown
    if (!window.__profileDropdownBound) {
      const userProfile = document.getElementById('userProfile');
      if (userProfile) {
        userProfile.addEventListener('click', function() {
          document.getElementById('profileDropdown').classList.toggle('show');
        });
      }
    }

    // Initialize DataTable
    $(document).ready(function() {
      if (!$('#eventsTable').length) {
        return;
      }
      var table = $('#eventsTable').DataTable({
        responsive: true,
        dom: "<'row'<'col-sm-12 col-md-6'l><'col-sm-12 col-md-6'f>>" +
             "<'row'<'col-sm-12'tr>>" +
             "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
        pageLength: 25,
        order: [[0, 'desc']], // Sort by date descending by default
        buttons: [
          {
            extend: 'collection',
            text: '<i class="fas fa-download mr-1"></i> Export',
            buttons: [
              'copy',
              'excel',
              'csv',
              'pdf',
              'print'
            ]
          }
        ],
        columnDefs: [
          { responsivePriority: 1, targets: 0 }, // Date
          { responsivePriority: 2, targets: 1 }, // Event name
          { responsivePriority: 3, targets: 2 }, // Client
          { responsivePriority: 4, targets: -1 }, // Actions
          { orderable: false, targets: [-1] }, // Disable sorting only on actions column
          { className: 'dt-nowrap', targets: [0, 1, 4, 5, 6, 7, 8, 9, 10] } // Prevent text wrapping
        ],
        language: {
          search: "_INPUT_",
          searchPlaceholder: "Search events...",
          lengthMenu: "Show _MENU_ events per page",
          zeroRecords: "No matching events found",
          info: "Showing _START_ to _END_ of _TOTAL_ events",
          infoEmpty: "No events available",
          infoFiltered: "(filtered from _MAX_ total events)",
          paginate: {
            first: "First",
            last: "Last",
            next: "Next",
            previous: "Previous"
          }
        }
      });

      // Add custom search for date range using raw YYYY-MM-DD value from first column
      $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
        if (settings.nTable.id !== 'eventsTable') {
          return true; // do not affect other tables
        }

        var min = $('#minDate').val();
        var max = $('#maxDate').val();

        // If no date filters are set, do not filter anything out
        if (!min && !max) {
          return true;
        }

        var api  = new $.fn.dataTable.Api(settings);
        var cell = api.cell(dataIndex, 0).node();
        var raw  = $(cell).find('[data-raw-date]').data('raw-date') || '';

        // If row has no date but user is filtering by date, hide it
        if (!raw) {
          return false;
        }

        // All values are in YYYY-MM-DD format; string comparison works
        if (min && raw < min) {
          return false;
        }
        if (max && raw > max) {
          return false;
        }
        return true;
      });

      // Global search functionality
      $('#globalSearch').on('keyup', function() {
        table.search(this.value).draw();
      });

      // Date range filter
      $('#minDate, #maxDate').on('change', function() {
        table.draw();
      });

      // Apply search button
      $('#applySearch').on('click', function() {
        table.draw();
      });

      // Reset search
      $('#resetSearch').on('click', function() {
        $('#globalSearch').val('');
        $('#minDate').val('');
        $('#maxDate').val('');
        table.search('').draw();
      });
    });
    
    // Toggle search panel
    $('#toggleFilters').on('click', function() {
      $('#searchPanel').slideToggle();
    });
    
    // Close dropdown when clicking outside
    if (!window.__profileDropdownBound) {
      document.addEventListener('click', function(event) {
        const profile = document.getElementById('userProfile');
        const dropdown = document.getElementById('profileDropdown');
        
        if (!profile.contains(event.target)) {
          dropdown.classList.remove('show');
        }
      });
    }

    // Toggle import section
    function toggleImportSection() {
      const importSection = document.getElementById('importSection');
      if (importSection) {
        importSection.style.display = importSection.style.display === 'none' ? 'block' : 'none';
      }
    }
    
    // Auto-calc balance = amount - advance
    const amountEl = document.getElementById('ev_amount');
    const advanceEl = document.getElementById('ev_advance');
    const balanceEl = document.getElementById('ev_balance');
    
    function recalcBalance() {
      if (!amountEl || !advanceEl || !balanceEl) return;
      const amount = parseFloat(amountEl.value) || 0;
      const advance = parseFloat(advanceEl.value) || 0;
      const balance = amount - advance;
      balanceEl.value = balance.toFixed(2);
    }
    
    if (amountEl && advanceEl) {
      amountEl.addEventListener('input', recalcBalance);
      advanceEl.addEventListener('input', recalcBalance);
    }

    // Sync hidden graphic user ID when dropdown changes
    var gSel = document.getElementById('ev_graphics_select');
    var gHid = document.getElementById('ev_graphics_user_id');
    if (gSel && gHid) {
      gSel.addEventListener('change', function() {
        var opt = gSel.options[gSel.selectedIndex];
        gHid.value = opt ? (opt.getAttribute('data-uid') || '0') : '0';
      });
    }

    // Close mobile menu when clicking on a link
    document.querySelectorAll('.nav-item').forEach(item => {
      item.addEventListener('click', () => {
        sidebar.classList.remove('mobile-open');
      });
    });

    // Handle window resize
    window.addEventListener('resize', function() {
      if (window.innerWidth > 768) {
        sidebar.classList.remove('mobile-open');
      }
    });

    // Finance/Accountant compliance table: search + pagination
    (function () {
      const table = document.getElementById('financeComplianceTable');
      const search = document.getElementById('financeComplianceSearch');
      const prev = document.getElementById('financeCompliancePrev');
      const next = document.getElementById('financeComplianceNext');
      const pageInfo = document.getElementById('financeCompliancePageInfo');
      if (!table || !search || !prev || !next || !pageInfo) return;

      const allRows = Array.from(table.querySelectorAll('tbody tr'));
      const isEmptyRow = (row) => row.querySelectorAll('td').length === 1;
      const dataRows = allRows.filter(r => !isEmptyRow(r));

      let page = 1;
      const pageSize = 10;
      let filtered = dataRows;

      function apply() {
        const q = (search.value || '').trim().toLowerCase();
        filtered = dataRows.filter(r => (r.textContent || '').toLowerCase().includes(q));

        const totalPages = Math.max(1, Math.ceil(filtered.length / pageSize));
        if (page > totalPages) page = totalPages;
        if (page < 1) page = 1;

        dataRows.forEach(r => { r.style.display = 'none'; });
        const start = (page - 1) * pageSize;
        const end = start + pageSize;
        filtered.slice(start, end).forEach(r => { r.style.display = ''; });

        prev.disabled = page <= 1;
        next.disabled = page >= totalPages;
        pageInfo.textContent = 'Page ' + page + ' / ' + totalPages;
      }

      search.addEventListener('input', function () {
        page = 1;
        apply();
      });
      prev.addEventListener('click', function () {
        page -= 1;
        apply();
      });
      next.addEventListener('click', function () {
        page += 1;
        apply();
      });

      apply();
    })();

    // Events files table (Store/Production/Graphic/Operation): search + pagination
    (function () {
      const table = document.getElementById('eventsFilesTable');
      const search = document.getElementById('eventsFilesSearch');
      const prev = document.getElementById('eventsFilesPrev');
      const next = document.getElementById('eventsFilesNext');
      const pageInfo = document.getElementById('eventsFilesPageInfo');
      if (!table || !search || !prev || !next || !pageInfo) return;

      const allRows = Array.from(table.querySelectorAll('tbody tr'));
      const isEmptyRow = (row) => row.querySelectorAll('td').length === 1;
      const dataRows = allRows.filter(r => !isEmptyRow(r));

      let page = 1;
      const pageSize = 10;
      let filtered = dataRows;

      function apply() {
        const q = (search.value || '').trim().toLowerCase();
        filtered = dataRows.filter(r => (r.textContent || '').toLowerCase().includes(q));

        const totalPages = Math.max(1, Math.ceil(filtered.length / pageSize));
        if (page > totalPages) page = totalPages;
        if (page < 1) page = 1;

        dataRows.forEach(r => { r.style.display = 'none'; });
        const start = (page - 1) * pageSize;
        const end = start + pageSize;
        filtered.slice(start, end).forEach(r => { r.style.display = ''; });

        prev.disabled = page <= 1;
        next.disabled = page >= totalPages;
        pageInfo.textContent = 'Page ' + page + ' / ' + totalPages;
      }

      search.addEventListener('input', function () {
        page = 1;
        apply();
      });
      prev.addEventListener('click', function () {
        page -= 1;
        apply();
      });
      next.addEventListener('click', function () {
        page += 1;
        apply();
      });

      apply();
    })();
    
    (function () {
      const widgets = Array.from(document.querySelectorAll('.ms'));
      if (!widgets.length) return;

      function updateLabel(ms, select) {
        const selected = Array.from(select.selectedOptions || []).map(o => (o.textContent || '').trim()).filter(Boolean);
        const label = ms.querySelector('.ms-value');
        const placeholder = ms.getAttribute('data-placeholder') || 'Select';
        if (!label) return;
        if (!selected.length) {
          label.textContent = placeholder;
          label.classList.add('ms-placeholder');
        } else {
          label.textContent = selected.join(', ');
          label.classList.remove('ms-placeholder');
        }
      }

      function buildOptions(ms, select) {
        const optionsWrap = ms.querySelector('.ms-options');
        if (!optionsWrap) return;
        optionsWrap.innerHTML = '';

        const opts = Array.from(select.options || []).filter(o => (o.value || '').toString().trim() !== '');
        for (const opt of opts) {
          const row = document.createElement('label');
          row.className = 'ms-option';

          const cb = document.createElement('input');
          cb.type = 'checkbox';
          cb.value = opt.value;
          cb.checked = !!opt.selected;

          const text = document.createElement('span');
          text.textContent = (opt.textContent || '').trim();

          cb.addEventListener('change', function () {
            opt.selected = cb.checked;
            updateLabel(ms, select);
          });

          row.appendChild(cb);
          row.appendChild(text);
          optionsWrap.appendChild(row);
        }

        updateLabel(ms, select);
      }

      function filterOptions(ms, query) {
        const q = (query || '').trim().toLowerCase();
        const rows = Array.from(ms.querySelectorAll('.ms-option'));
        rows.forEach(r => {
          const t = (r.textContent || '').toLowerCase();
          r.style.display = t.includes(q) ? '' : 'none';
        });
      }

      widgets.forEach(ms => {
        const select = ms.querySelector('select');
        const control = ms.querySelector('.ms-control');
        const menu = ms.querySelector('.ms-menu');
        const search = ms.querySelector('.ms-search');
        if (!select || !control || !menu) return;

        buildOptions(ms, select);

        control.addEventListener('click', function () {
          const isOpen = ms.classList.toggle('open');
          control.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
          if (isOpen && search) {
            search.value = '';
            filterOptions(ms, '');
            search.focus();
          }
        });

        if (search) {
          search.addEventListener('input', function () {
            filterOptions(ms, search.value);
          });
        }

        select.addEventListener('change', function () {
          buildOptions(ms, select);
        });
      });

      document.addEventListener('click', function (e) {
        widgets.forEach(ms => {
          if (!ms.classList.contains('open')) return;
          if (ms.contains(e.target)) return;
          ms.classList.remove('open');
          const control = ms.querySelector('.ms-control');
          if (control) control.setAttribute('aria-expanded', 'false');
        });
      });
    })();

    // Calendar icon date picker for Event form (DD/MM/YYYY)
    (function() {
      function toDmy(iso) {
        if (!iso || typeof iso !== 'string') return '';
        var m = iso.match(/^(\d{4})-(\d{2})-(\d{2})$/);
        if (!m) return '';
        return m[3] + '/' + m[2] + '/' + m[1];
      }

      function wire(textId, pickerId, btnId) {
        var text = document.getElementById(textId);
        var picker = document.getElementById(pickerId);
        var btn = document.getElementById(btnId);
        if (!text || !picker || !btn) return;

        btn.addEventListener('click', function(e) {
          e.preventDefault();
          try {
            if (picker.showPicker) {
              picker.showPicker();
            } else {
              picker.click();
              picker.focus();
            }
          } catch (err) {
            picker.click();
            picker.focus();
          }
        });

        picker.addEventListener('change', function() {
          if (picker.value) {
            text.value = toDmy(picker.value);
          }
        });
      }

      wire('evDateText', 'evDatePicker', 'evDatePickerBtn');
      wire('evEndDateText', 'evEndDatePicker', 'evEndDatePickerBtn');
    })();
  </script>
</body>
</html>