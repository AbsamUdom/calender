<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';

if (isset($_GET['logout'])) {
    logout_user();
    header('Location: login.php');
    exit;
}

require_login();
$db = get_db();
$user = current_user();
$role = strtolower((string)($user['role'] ?? 'user'));

if (!in_array($role, ['sales', 'admin', 'super'], true)) {
    header('Location: index.php');
    exit;
}

$isAdmin = in_array($role, ['admin', 'super'], true);
$pageTitle = ($role === 'sales') ? 'Quote Status' : 'Quote Status';

$eventUploadDir = __DIR__ . '/uploads/events';
if (!is_dir($eventUploadDir)) {
    @mkdir($eventUploadDir, 0755, true);
}

function handle_quote_upload(string $fieldName, string $uploadDir): ?string {
    if (!isset($_FILES[$fieldName]) || !is_array($_FILES[$fieldName])) {
        return null;
    }
    $file = $_FILES[$fieldName];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new Exception('Failed to upload quote file');
    }

    $allowedExtensions = ['pdf','doc','docx','xls','xlsx','ppt','pptx','png','jpg','jpeg','gif'];
    $originalName = $file['name'] ?? 'file';
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($extension && !in_array($extension, $allowedExtensions, true)) {
        throw new Exception('Unsupported file type for quote');
    }

    $baseName = preg_replace('/[^a-zA-Z0-9-_]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
    if ($baseName === '') {
        $baseName = 'quote_file';
    }
    $uniqueSuffix = time() . '_' . bin2hex(random_bytes(4));
    $newFileName = $baseName . '_' . $uniqueSuffix . ($extension ? '.' . $extension : '');
    $targetPath = rtrim($uploadDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $newFileName;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        throw new Exception('Unable to save quote file');
    }

    return 'uploads/events/' . $newFileName;
}

$quoteFilter = strtolower((string)($_GET['quote_status'] ?? 'all'));
$allowedQuoteFilters = ['all', 'pending', 'approved', 'rejected'];
if (!in_array($quoteFilter, $allowedQuoteFilters, true)) {
    $quoteFilter = 'all';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_abort();

    $action = $_POST['action'] ?? '';
    if ($action === 'reupload_quote') {
        $eventId = (int)($_POST['event_id'] ?? 0);
        try {
            if ($eventId <= 0) {
                throw new Exception('Invalid event');
            }

            $evStmt = $db->prepare('SELECT id, user_id, quote_status, quote_file FROM events WHERE id = ?');
            $evStmt->execute([$eventId]);
            $eventRow = $evStmt->fetch(PDO::FETCH_ASSOC);
            if (!$eventRow) {
                throw new Exception('Event not found');
            }

            if (!$isAdmin && (int)$eventRow['user_id'] !== (int)($user['id'] ?? 0)) {
                throw new Exception('Access denied');
            }

            $currentStatus = strtolower((string)($eventRow['quote_status'] ?? 'pending'));
            if ($currentStatus !== 'rejected') {
                throw new Exception('Re-upload is only allowed for rejected quotes');
            }

            $newQuotePath = handle_quote_upload('quote_file', $eventUploadDir);
            if (empty($newQuotePath)) {
                throw new Exception('Please select a file to upload');
            }

            $oldQuote = (string)($eventRow['quote_file'] ?? '');
            if ($oldQuote !== '') {
                $oldDiskPath = __DIR__ . '/' . ltrim($oldQuote, '/');
                if (is_file($oldDiskPath)) {
                    @unlink($oldDiskPath);
                }
            }

            $upd = $db->prepare("UPDATE events SET quote_file = ?, quote_status = 'pending', quote_finance_comment = NULL WHERE id = ?");
            $upd->execute([$newQuotePath, $eventId]);

            flash_add('success', 'Quote re-uploaded successfully. Status reset to pending.');
        } catch (Exception $e) {
            flash_add('error', $e->getMessage());
        }
    }

    $redirect = 'quote_status.php';
    if ($quoteFilter !== 'all') {
        $redirect .= '?quote_status=' . urlencode($quoteFilter);
    }
    header('Location: ' . $redirect);
    exit;
}

$whereParts = [];
$params = [];
if (!$isAdmin) {
    $currentUserId = (int)($user['id'] ?? 0);
    $whereParts[] = '(user_id = ? OR coordinator_id = ? OR coordinators REGEXP ?)';
    $params[] = $currentUserId;
    $params[] = $currentUserId;
    $params[] = '(^|\\[|,)\\s*' . $currentUserId . '\\s*(,|\\])';
}
if ($quoteFilter !== 'all') {
    $whereParts[] = 'LOWER(COALESCE(quote_status, \'pending\')) = ?';
    $params[] = $quoteFilter;
}
$whereSql = '';
if (!empty($whereParts)) {
    $whereSql = 'WHERE ' . implode(' AND ', $whereParts);
}

$countWhereParts = [];
$countParams = [];
if (!$isAdmin) {
    $currentUserId = (int)($user['id'] ?? 0);
    $countWhereParts[] = '(user_id = ? OR coordinator_id = ? OR coordinators REGEXP ?)';
    $countParams[] = $currentUserId;
    $countParams[] = $currentUserId;
    $countParams[] = '(^|\\[|,)\\s*' . $currentUserId . '\\s*(,|\\])';
}
$countWhereSql = '';
if (!empty($countWhereParts)) {
    $countWhereSql = 'WHERE ' . implode(' AND ', $countWhereParts);
}

$countsStmt = $db->prepare("\n    SELECT\n        SUM(CASE WHEN LOWER(COALESCE(quote_status, 'pending')) = 'pending' THEN 1 ELSE 0 END) AS pending_count,\n        SUM(CASE WHEN LOWER(COALESCE(quote_status, 'pending')) = 'approved' THEN 1 ELSE 0 END) AS approved_count,\n        SUM(CASE WHEN LOWER(COALESCE(quote_status, 'pending')) = 'rejected' THEN 1 ELSE 0 END) AS rejected_count\n    FROM events\n    " . $countWhereSql . "\n");
$countsStmt->execute($countParams);
$counts = $countsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$quotePendingCount = (int)($counts['pending_count'] ?? 0);
$quoteApprovedCount = (int)($counts['approved_count'] ?? 0);
$quoteRejectedCount = (int)($counts['rejected_count'] ?? 0);

$listStmt = $db->prepare("\n    SELECT id, name, client, date, quote_file, quote_status, quote_finance_comment\n    FROM events\n    " . $whereSql . "\n    ORDER BY created_at DESC, date DESC\n");
$listStmt->execute($params);
$events = $listStmt->fetchAll(PDO::FETCH_ASSOC);

$flashes = flash_consume();
$displayName = $user['name'] ?? $user['email'] ?? 'User';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo htmlspecialchars($pageTitle); ?></title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="style.css">
  <style>
    @media (max-width: 768px) {
      .quote-status-page .top-bar {
        margin-bottom: 1rem;
      }

      .quote-status-page .page-title h1 {
        padding-left: 0.5rem;
      }

      .quote-status-page .page-title p {
        padding-left: 0.5rem;
      }
    }
  </style>
</head>
<body class="quote-status-page">
  <div class="dashboard-container">
    <?php $activePage = 'quote_status.php'; include __DIR__ . '/sidebar.php'; ?>

    <main class="main-content">
      <div class="top-bar">
        <div class="page-title">
          <h1><?php echo htmlspecialchars($pageTitle); ?></h1>
          <p>Track quote approvals and re-upload rejected quotes</p>
        </div>

        <div class="user-menu">
          <div class="date-display">
            <i class="far fa-calendar-alt mr-2"></i>
            <?php echo date('l, F j, Y'); ?>
          </div>

          <div class="user-profile" id="userProfile">
            <div class="avatar"><?php echo htmlspecialchars(strtoupper(mb_substr($displayName, 0, 1))); ?></div>
            <div style="min-width: 0; max-width: 160px; overflow: hidden;">
              <div style="font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?php echo htmlspecialchars($displayName); ?></div>
              <div style="font-size: 0.75rem; color: #64748b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?php echo function_exists('get_role_label') ? get_role_label($user['role'] ?? 'user') : htmlspecialchars((string)($user['role'] ?? 'user')); ?></div>
            </div>
            <i class="fas fa-chevron-down ml-2" style="color: #64748b;"></i>
            <div class="profile-dropdown" id="profileDropdown">
              <a href="profile.php"><i class="fas fa-user"></i> My Profile</a>
              <a href="?logout" style="color:#dc2626;"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
          </div>
        </div>
      </div>

        <?php foreach ($flashes as $type => $msgs): ?>
          <?php foreach ($msgs as $msg): ?>
            <div class="mb-6 p-4 rounded-lg flex items-center gap-3 shadow-sm <?php echo $type === 'success' ? 'bg-green-50 text-green-700 border border-green-200' : ($type === 'warning' ? 'bg-yellow-50 text-yellow-700 border border-yellow-200' : 'bg-red-50 text-red-700 border border-red-200'); ?>">
              <i class="fas <?php echo $type === 'success' ? 'fa-check-circle' : ($type === 'warning' ? 'fa-exclamation-triangle' : 'fa-times-circle'); ?> text-xl"></i>
              <span class="font-medium"><?php echo htmlspecialchars($msg); ?></span>
            </div>
          <?php endforeach; ?>
        <?php endforeach; ?>

        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
          <div class="p-6 border-b border-gray-100 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <div>
              <div class="text-xs text-gray-500">
                <span class="mr-3">Pending: <span class="font-semibold"><?php echo (int)$quotePendingCount; ?></span></span>
                <span class="mr-3">Approved: <span class="font-semibold"><?php echo (int)$quoteApprovedCount; ?></span></span>
                <span>Rejected: <span class="font-semibold"><?php echo (int)$quoteRejectedCount; ?></span></span>
              </div>
            </div>
            <form method="get" class="flex items-center gap-2">
              <select name="quote_status" class="rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm">
                <option value="all" <?php echo $quoteFilter === 'all' ? 'selected' : ''; ?>>All</option>
                <option value="pending" <?php echo $quoteFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                <option value="approved" <?php echo $quoteFilter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                <option value="rejected" <?php echo $quoteFilter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
              </select>
              <button type="submit" class="bg-blue-600 text-white py-2 px-3 rounded-lg hover:bg-blue-700 transition font-medium text-sm">Filter</button>
            </form>
          </div>

          <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-100">
              <thead class="bg-gray-50">
                <tr>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Event</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Client</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Date</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Quote</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Finance Comment</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Re-upload</th>
                </tr>
              </thead>
              <tbody class="bg-white divide-y divide-gray-100">
                <?php if (empty($events)): ?>
                  <tr>
                    <td colspan="6" class="px-6 py-10 text-center text-sm text-gray-500">No events found.</td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($events as $ev): ?>
                    <?php $qs = strtolower((string)($ev['quote_status'] ?? 'pending')); ?>
                    <tr class="hover:bg-gray-50">
                      <td class="px-6 py-4 text-sm font-medium text-gray-900"><?php echo htmlspecialchars((string)($ev['name'] ?? '')); ?></td>
                      <td class="px-6 py-4 text-sm text-gray-700"><?php echo htmlspecialchars((string)($ev['client'] ?? '-')); ?></td>
                      <td class="px-6 py-4 text-sm text-gray-700"><?php echo !empty($ev['date']) ? htmlspecialchars(date('M j, Y', strtotime($ev['date']))) : '-'; ?></td>
                      <td class="px-6 py-4 text-sm">
                        <div class="flex items-center gap-3">
                          <?php if ($qs === 'approved'): ?>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-green-100 text-green-800">Approved</span>
                          <?php elseif ($qs === 'rejected'): ?>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-red-100 text-red-800">Rejected</span>
                          <?php else: ?>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-100 text-amber-800">Pending</span>
                          <?php endif; ?>

                          <?php if (!empty($ev['quote_file'])): ?>
                            <a href="<?php echo htmlspecialchars((string)$ev['quote_file']); ?>" target="_blank" class="text-xs text-blue-600 hover:text-blue-800 font-medium">View</a>
                          <?php else: ?>
                            <span class="text-xs text-gray-400">No file</span>
                          <?php endif; ?>
                        </div>
                      </td>
                      <td class="px-6 py-4 text-xs text-gray-600" style="white-space: pre-wrap;">
                        <?php echo htmlspecialchars((string)($ev['quote_finance_comment'] ?? '')); ?>
                      </td>
                      <td class="px-6 py-4">
                        <?php if ($qs === 'rejected'): ?>
                          <form method="post" enctype="multipart/form-data" class="flex items-center gap-2">
                            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                            <input type="hidden" name="action" value="reupload_quote">
                            <input type="hidden" name="event_id" value="<?php echo (int)$ev['id']; ?>">
                            <input type="file" name="quote_file" required class="text-xs" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.png,.jpg,.jpeg,.gif">
                            <button type="submit" class="bg-purple-600 text-white px-3 py-2 rounded-lg hover:bg-purple-700 transition text-xs font-semibold">Upload</button>
                          </form>
                        <?php else: ?>
                          <span class="text-xs text-gray-400">-</span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
    </main>
  </div>
  <script src="main.js"></script>
</body>
</html>
