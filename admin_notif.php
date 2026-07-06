<?php
/* ============================================================
   FleetGo • Admin Notification Center
   ============================================================ */

// Start session and check admin access
if (session_status() === PHP_SESSION_NONE) {
  session_name('fleetgo_session_admin');
  session_start();
}

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
  header("Location: login.php");
  exit;
}

// Include dependencies
require_once 'includes/db.php';
require_once 'includes/notification_helpers.php';

function h($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}

$adminId = (int)($_SESSION['user_id'] ?? 0);

$conn->query("CREATE TABLE IF NOT EXISTS admin_notification_reads (
  admin_id INT NOT NULL,
  notification_id INT NOT NULL,
  read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (admin_id, notification_id),
  INDEX (notification_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

function adminNotifCategory($message) {
  $m = strtolower((string)$message);
  if (strpos($m, 'maintenance') !== false || strpos($m, 'overdue') !== false) return 'maintenance';
  if (strpos($m, 'booking') !== false || strpos($m, 'rental') !== false || strpos($m, 'returned') !== false) return 'rentals';
  if (strpos($m, 'payment') !== false || strpos($m, 'paid') !== false || strpos($m, 'refund') !== false) return 'payments';
  if (strpos($m, 'welcome') !== false || strpos($m, 'registered') !== false || strpos($m, 'profile') !== false || strpos($m, 'approved') !== false) return 'users';
  return 'system';
}

// Helper function to check if notification is vehicle-related
function isVehicleNotification($message) {
  // Check if the message contains vehicle-related keywords
  $vehicleKeywords = [
    'booking', 'rental', 'returned', 'maintenance', 
    'plate', 'honda', 'toyota', 'mazda', 'nissan', 'ford',
    'sedan', 'suv', 'pickup', 'motorcycle', 'car',
    'vehicle', 'booked', 'rented', 'return'
  ];
  
  // Exclude welcome/profile messages
  $excludeKeywords = [
    'welcome', 'profile', 'approved', 'registered', 'logged in'
  ];
  
  $lowerMessage = strtolower($message);
  
  // If it contains exclusion keywords, it's not a vehicle notification
  foreach ($excludeKeywords as $keyword) {
    if (strpos($lowerMessage, $keyword) !== false) {
      return false;
    }
  }
  
  // Check for vehicle keywords
  foreach ($vehicleKeywords as $keyword) {
    if (strpos($lowerMessage, $keyword) !== false) {
      return true;
    }
  }
  
  return false;
}

/* =========================================================
   AJAX ENDPOINTS FOR NOTIFICATION BELL
========================================================= */
if (isset($_GET['ajax'])) {
  header("Content-Type: application/json");
  
  if ($_GET['ajax'] === 'fetch') {
    $limit = isset($_GET['limit']) ? max(0, (int)$_GET['limit']) : 20;
    $offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;

    $cat = trim($_GET['cat'] ?? '');
    $whereCat = '';
    $catParam = null;
    if ($cat !== '' && in_array($cat, ['system','rentals','maintenance','payments','users'], true)) {
      $whereCat = " AND n.message LIKE ?";
      $like = '%';
      if ($cat === 'maintenance') $like = '%maintenance%';
      elseif ($cat === 'rentals') $like = '%booking%';
      elseif ($cat === 'payments') $like = '%payment%';
      elseif ($cat === 'users') $like = '%welcome%';
      else $like = '%';
      $catParam = $like;
    }

    // Lightweight unread count only
    if ($limit === 0) {
      $sql = "SELECT COUNT(*) AS c
              FROM notifications n
              LEFT JOIN admin_notification_reads ar
                ON ar.notification_id = n.id AND ar.admin_id = ?
              WHERE ar.notification_id IS NULL" . ($whereCat ? $whereCat : '');
      if ($catParam !== null) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("is", $adminId, $catParam);
      } else {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $adminId);
      }
      $stmt->execute();
      $countRes = $stmt->get_result();
      $count = $countRes ? (int)$countRes->fetch_assoc()['c'] : 0;
      $stmt->close();
      echo json_encode(["html" => '', "count" => $count, "hasMore" => false]);
      exit;
    }

    $pageLimit = $limit + 1;
    $sql = "
      SELECT n.id, n.message, n.created_at,
             CASE WHEN ar.notification_id IS NULL THEN 0 ELSE 1 END AS admin_is_read,
             u.full_name, v.make_model
      FROM notifications n
      LEFT JOIN users u ON n.user_id = u.id
      LEFT JOIN vehicles v ON n.vehicle_id = v.id
      LEFT JOIN admin_notification_reads ar
        ON ar.notification_id = n.id AND ar.admin_id = ?
      WHERE 1=1" . ($whereCat ? $whereCat : '') . "
      ORDER BY n.created_at DESC
      LIMIT ? OFFSET ?
    ";
    if ($catParam !== null) {
      $stmt = $conn->prepare($sql);
      $stmt->bind_param("isii", $adminId, $catParam, $pageLimit, $offset);
    } else {
      $stmt = $conn->prepare($sql);
      $stmt->bind_param("iii", $adminId, $pageLimit, $offset);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $hasMore = count($rows) > $limit;
    if ($hasMore) array_pop($rows);

    $itemsHtml = '';
    foreach ($rows as $n) {
      $cls = ((int)$n['admin_is_read'] === 1) ? 'notif-item read' : 'notif-item unread';
      $time = date("M j, Y g:i A", strtotime($n['created_at']));
      $msg = htmlspecialchars(transformMessageForAdmin($n['message'], $n['full_name']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
      $customer = $n['full_name'] ? htmlspecialchars($n['full_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : 'System';
      $vehicle = $n['make_model'] ? htmlspecialchars($n['make_model'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '';

      // Check if this is a vehicle-related notification
      $isVehicleRelated = isVehicleNotification($msg);
      $shouldShowVehicle = $isVehicleRelated && $vehicle;

      $itemsHtml .= "
        <li class=\"$cls\" data-id=\"".(int)$n['id']."\">
          <div class=\"msg\">$msg</div>
          <div class=\"notif-meta\">$time" . ($customer !== 'System' ? " • $customer" : '') . ($shouldShowVehicle ? " • $vehicle" : '') . "</div>
        </li>
      ";
    }

    if ($itemsHtml === '') $itemsHtml = "<li class=\"empty\">No notifications yet.</li>";

    $sql2 = "SELECT COUNT(*) AS c
             FROM notifications n
             LEFT JOIN admin_notification_reads ar
               ON ar.notification_id = n.id AND ar.admin_id = ?
             WHERE ar.notification_id IS NULL";
    $stmt2 = $conn->prepare($sql2);
    $stmt2->bind_param("i", $adminId);
    $stmt2->execute();
    $countRes = $stmt2->get_result();
    $count = $countRes ? (int)$countRes->fetch_assoc()['c'] : 0;
    $stmt2->close();

    echo json_encode(["html" => $itemsHtml, "count" => $count, "hasMore" => $hasMore]);
    $stmt->close();
    exit;
  }

  if ($_GET['ajax'] === 'mark') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
      $stmt = $conn->prepare("INSERT IGNORE INTO admin_notification_reads (admin_id, notification_id) VALUES (?, ?)");
      $stmt->bind_param("ii", $adminId, $id);
      $stmt->execute();
      $stmt->close();
    } else {
      $conn->query("INSERT IGNORE INTO admin_notification_reads (admin_id, notification_id)
                    SELECT $adminId, n.id FROM notifications n");
    }
    echo json_encode(["ok" => true]);
    exit;
  }

  echo json_encode(["error" => "Invalid request"]);
  exit;
}

/* =========================================================
   MAIN PAGE LOGIC
========================================================= */

// Handle mark all as read
if (isset($_POST['mark_all'])) {
  $conn->query("INSERT IGNORE INTO admin_notification_reads (admin_id, notification_id)
                SELECT $adminId, n.id FROM notifications n");
  header("Location: " . $_SERVER['PHP_SELF']);
  exit;
}

// Get search and pagination parameters
$search = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$filter = $_GET['filter'] ?? '';
$cat = $_GET['cat'] ?? 'all';

$cats = [
  'all' => 'All',
  'system' => 'System',
  'rentals' => 'Rentals',
  'maintenance' => 'Maintenance',
  'payments' => 'Payments',
  'users' => 'Users'
];

// Admin-scoped statistics (does not touch user is_read)
$stmt = $conn->prepare("SELECT COUNT(*) AS total FROM notifications");
$stmt->execute();
$statsTotal = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) AS unread
                        FROM notifications n
                        LEFT JOIN admin_notification_reads ar
                          ON ar.notification_id=n.id AND ar.admin_id=?
                        WHERE ar.notification_id IS NULL");
$stmt->bind_param('i', $adminId);
$stmt->execute();
$statsUnread = (int)($stmt->get_result()->fetch_assoc()['unread'] ?? 0);
$stmt->close();

$where = "WHERE 1=1";
$params = [];
$types = '';

if ($search !== '') {
  $where .= " AND (u.full_name LIKE CONCAT('%',?,'%') OR v.make_model LIKE CONCAT('%',?,'%') OR n.message LIKE CONCAT('%',?,'%'))";
  $params[] = $search; $params[] = $search; $params[] = $search;
  $types .= 'sss';
}

if ($cat !== '' && $cat !== 'all' && isset($cats[$cat])) {
  $where .= " AND n.message LIKE ?";
  $like = '%';
  if ($cat === 'maintenance') $like = '%maintenance%';
  elseif ($cat === 'rentals') $like = '%booking%';
  elseif ($cat === 'payments') $like = '%payment%';
  elseif ($cat === 'users') $like = '%welcome%';
  else $like = '%';
  $params[] = $like;
  $types .= 's';
}

$limit = 20;
$offset = ($page - 1) * $limit;

$countSql = "SELECT COUNT(*) AS total
             FROM notifications n
             LEFT JOIN users u ON n.user_id=u.id
             LEFT JOIN vehicles v ON n.vehicle_id=v.id
             $where";
$stmt = $conn->prepare($countSql);
if ($types !== '') $stmt->bind_param($types, ...$params);
$stmt->execute();
$total = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
$stmt->close();
$pages = max(1, (int)ceil($total / $limit));

$sql = "SELECT n.*, u.full_name, u.role, v.make_model, v.plate_no,
               CASE WHEN ar.notification_id IS NULL THEN 0 ELSE 1 END AS admin_is_read
        FROM notifications n
        LEFT JOIN users u ON n.user_id=u.id
        LEFT JOIN vehicles v ON n.vehicle_id=v.id
        LEFT JOIN admin_notification_reads ar
          ON ar.notification_id=n.id AND ar.admin_id=?
        $where
        ORDER BY n.created_at DESC
        LIMIT ? OFFSET ?";

$stmt = $conn->prepare($sql);
$bindTypes = 'i' . $types . 'ii';
$bindParams = array_merge([$adminId], $params, [$limit, $offset]);
$stmt->bind_param($bindTypes, ...$bindParams);
$stmt->execute();
$notificationsRes = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Admin Notifications • FleetGo</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
    :root {
      --bg: #0b0d10;
      --card: #101419;
      --text: #f2f6fa;
      --muted: #9aa6b3;
      --brand: #5dd0ff;
      --brand2: #7cffc7;
      --radius: 16px;
    }
    
    body {
      margin: 0;
      background: var(--bg);
      color: var(--text);
      font-family: Inter, system-ui, sans-serif;
      overflow-x: hidden;
    }
    
    .wrap {
      max-width: 1200px;
      margin: auto;
      padding: 28px;
    }
    
    h1 {
      font-size: 2rem;
      font-weight: 800;
      margin-bottom: 1rem;
      background: linear-gradient(90deg, var(--brand), var(--brand2));
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
    }
    
    .header-actions {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 2rem;
      gap: 1rem;
      flex-wrap: wrap;
    }
    
    .stats {
      display: flex;
      gap: 1rem;
      flex-wrap: nowrap;
      justify-content: space-between;
    }
    
    .stat {
      flex: 1;
      background: rgba(16, 20, 25, .9);
      border-radius: var(--radius);
      padding: 1rem;
      text-align: center;
      border: 1px solid rgba(255, 255, 255, .05);
      box-shadow: 0 8px 20px rgba(0, 0, 0, .4);
      min-width: 0;
    }
    
    .stat h2 {
      margin: 0;
      font-size: 1.8rem;
      font-weight: 800;
      background: linear-gradient(90deg, var(--brand), var(--brand2));
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
    }
    
    .stat span {
      font-size: .85rem;
      color: var(--muted);
    }
    
    .action-buttons {
      display: flex;
      gap: .5rem;
      flex-wrap: wrap;
      align-items: center;
    }
    
    .search-section {
      display: flex;
      gap: .5rem;
      flex: 1;
      max-width: 400px;
    }
    
    .input {
      flex: 1;
      padding: .7rem .9rem;
      border-radius: var(--radius);
      border: 1px solid rgba(255, 255, 255, .12);
      background: #0d1116;
      color: var(--text);
    }
    
    .btn {
      background: linear-gradient(90deg, var(--brand), var(--brand2));
      border: none;
      border-radius: var(--radius);
      color: #001319;
      padding: .7rem 1.1rem;
      font-weight: 700;
      cursor: pointer;
      transition: .25s;
    }
    
    .btn:hover {
      opacity: .9;
      transform: translateY(-1px);
    }
    
    .btn-secondary {
      background: rgba(255, 255, 255, .1) !important;
      color: var(--text) !important;
      border: 1px solid rgba(255, 255, 255, .2) !important;
    }
    
    .btn-secondary:hover {
      background: rgba(255, 255, 255, .15) !important;
      transform: translateY(-1px);
    }
    
    .btn-active {
      background: linear-gradient(90deg, var(--brand), var(--brand2)) !important;
      color: #001319 !important;
      font-weight: 700;
    }
    
    .filter-buttons {
      display: flex;
      gap: .5rem;
    }

    .tabs {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      align-items: center;
      justify-content: flex-start;
      margin-top: 14px;
      width: 100%;
    }

    .tab {
      display: inline-flex;
      gap: 10px;
      align-items: center;
      padding: .55rem .9rem;
      border-radius: 999px;
      background: rgba(255,255,255,.06);
      border: 1px solid rgba(255,255,255,.10);
      color: var(--text);
      text-decoration: none;
      font-weight: 800;
      transition: .2s;
    }

    .tab:hover {
      transform: translateY(-1px);
      border-color: rgba(93,208,255,.25);
      background: rgba(93,208,255,.06);
    }

    .tab.active {
      background: linear-gradient(90deg, rgba(93,208,255,.20), rgba(124,255,199,.14));
      border-color: rgba(93,208,255,.35);
    }

    .filter-indicator {
      margin-bottom: 1rem;
      text-align: center;
    }
    
    .filter-badge {
      background: linear-gradient(135deg, #ffd700, #ffed4e);
      color: #1a1a1a;
      padding: .5rem 1rem;
      border-radius: 20px;
      font-size: .85rem;
      font-weight: 600;
      display: inline-block;
      box-shadow: 0 2px 8px rgba(255, 215, 0, .3);
    }
    
    .notifications-list {
      display: flex;
      flex-direction: column;
      gap: .8rem;
    }
    
    .notification-card {
      background: var(--card);
      padding: 1rem 1.25rem;
      border-radius: 12px;
      border: 1px solid rgba(255, 255, 255, .06);
      border-left: 3px solid var(--brand2);
      transition: all .25s ease;
      box-shadow: 0 2px 8px rgba(0, 0, 0, .2);
      position: relative;
      margin-bottom: .75rem;
    }
    
    .notification-card.unread {
      border-left: 3px solid var(--brand);
      box-shadow: 0 3px 12px rgba(93, 208, 255, .12);
      background: linear-gradient(135deg, var(--card) 0%, rgba(93, 208, 255, .015) 100%);
    }
    
    .notification-card.unread::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 1px;
      background: linear-gradient(90deg, var(--brand), var(--brand2));
      border-radius: 12px 12px 0 0;
    }
    
    .notification-card:hover {
      transform: translateY(-1px);
      background: #131820;
      box-shadow: 0 4px 16px rgba(0, 0, 0, .3);
      border-color: rgba(255, 255, 255, .1);
    }
    
    .notification-card.unread:hover {
      box-shadow: 0 4px 20px rgba(93, 208, 255, .15);
    }
    
    .notification-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      margin-bottom: .6rem;
      gap: .75rem;
    }
    
    .notification-header > div:first-child {
      flex: 1;
      min-width: 0;
    }
    
    .notification-meta {
      font-size: .75rem;
      color: var(--muted);
      white-space: nowrap;
      flex-shrink: 0;
      text-align: right;
      opacity: .8;
    }
    
    .notification-user {
      display: flex;
      align-items: center;
      gap: .4rem;
      flex-wrap: wrap;
      margin-bottom: .2rem;
    }
    
    .notification-user b {
      color: var(--text);
      font-weight: 600;
      font-size: .95rem;
    }
    
    .notification-vehicle {
      color: var(--muted);
      font-size: .8rem;
      margin-bottom: .2rem;
      opacity: .9;
    }
    
    .notification-message {
      line-height: 1.5;
      word-wrap: break-word;
      color: var(--text);
      font-size: .9rem;
      background: rgba(255, 255, 255, .015);
      padding: .6rem .75rem;
      border-radius: 6px;
      border-left: 2px solid rgba(255, 255, 255, .08);
      margin-top: .4rem;
    }
    
    .notification-card.unread .notification-message {
      background: rgba(93, 208, 255, .03);
      border-left-color: var(--brand);
    }
    
    /* Modal */
    .notif-modal{position:fixed;inset:0;display:none;align-items:center;justify-content:center;background:rgba(0,0,0,.65);backdrop-filter:blur(6px);z-index:9999;padding:24px;}
    .notif-modal.open{display:flex;}
    .notif-modal .panel{width:min(720px,96vw);background:linear-gradient(180deg,#101419,#0b1016);border:1px solid rgba(255,255,255,.10);border-radius:18px;box-shadow:0 24px 80px rgba(0,0,0,.6);overflow:hidden;}
    .notif-modal .panel-h{display:flex;align-items:center;justify-content:space-between;padding:16px 18px;border-bottom:1px solid rgba(255,255,255,.08);}
    .notif-modal .panel-h h3{margin:0;font-size:1rem;font-weight:900;letter-spacing:.2px;}
    .notif-modal .x{cursor:pointer;border:1px solid rgba(255,255,255,.10);background:rgba(255,255,255,.06);color:var(--text);width:40px;height:36px;border-radius:12px;font-weight:900;}
    .notif-modal .panel-b{padding:18px;display:grid;gap:12px;}
    .kv{display:flex;justify-content:space-between;gap:12px;color:var(--muted);font-size:.85rem;}
    .kv b{color:var(--text);font-weight:800;}
    .fullmsg{white-space:pre-wrap;line-height:1.55;color:var(--text);background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:14px;}
    .panel-actions{display:flex;justify-content:flex-end;gap:10px;padding:16px 18px;border-top:1px solid rgba(255,255,255,.08);}
</style>
</head>
<body>
<?php if(file_exists(__DIR__.'/includes/navbar.php')) include __DIR__.'/includes/navbar.php'; ?>
  
<div class="wrap">
<h1>Admin Notifications</h1>

    <div class="header-actions">
<div class="stats">
        <div class="stat">
          <h2><?= $statsTotal ?></h2>
          <span>Total Notifications</span>
        </div>
        <div class="stat">
          <h2><?= $statsUnread ?></h2>
          <span>Unread</span>
        </div>
</div>

      <div class="tabs" aria-label="Notification Categories">
        <?php foreach($cats as $k=>$label):
          $q = array_merge($_GET, ['cat' => $k, 'page' => 1]);
          if ($k === 'all') unset($q['cat']);
          $active = ($k === 'all' && ($cat === 'all' || $cat === '' )) || ($k !== 'all' && $cat === $k);
        ?>
          <a class="tab <?= $active ? 'active' : '' ?>" href="?<?= http_build_query($q) ?>">
            <span><?= h($label) ?></span>
          </a>
        <?php endforeach; ?>
      </div>

      <div class="action-buttons">
        <form class="search-section" method="get">
          <input class="input" type="text" name="q" placeholder="Search notifications..." value="<?= h($search) ?>">
    <button class="btn" type="submit">Search</button>
  </form>
        <div class="filter-buttons">
          <a href="?<?= http_build_query(array_diff_key($_GET, ['filter' => ''])) ?>" 
             class="btn <?= ($filter ?? '') === '' ? 'btn-active' : 'btn-secondary' ?>">
            All
          </a>
        </div>
        <form method="post" style="margin: 0;">
          <button class="btn btn-secondary" type="submit" name="mark_all">Mark All as Read</button>
  </form>
      </div>
</div>

    <div class="notifications-list">
      <?php if($notificationsRes && $notificationsRes->num_rows): while($r = $notificationsRes->fetch_assoc()):
  $roleClass = 'role-' . strtolower($r['role'] ?? 'user');
?>
        <div class="notification-card <?= ((int)($r['admin_is_read'] ?? 0) === 1) ? '' : 'unread' ?>" role="button" tabindex="0"
             onclick="openNotifModal(this)"
             data-id="<?= (int)$r['id'] ?>"
             data-read="<?= (int)($r['admin_is_read'] ?? 0) ?>"
             data-time="<?= h(date("M d, Y • h:i A", strtotime($r['created_at']))) ?>"
             data-user="<?= h($r['full_name'] ?: 'System') ?>"
             data-role="<?= h($r['role'] ?: '') ?>"
             data-vehicle="<?= h($r['make_model'] ?: '') ?>"
             data-message="<?= h(transformMessageForAdmin($r['message'], $r['full_name'])) ?>">
          <div class="notification-header">
     <div>
              <div class="notification-user">
                <b><?= h($r['full_name'] ?: 'System') ?></b>
       <?php if($r['role']): ?>
                  <span class="role-badge <?= $roleClass ?>"><?= h($r['role']) ?></span>
                <?php endif; ?>
              </div>
              <?php 
              $isVehicleRelated = isVehicleNotification($r['message']);
              if($r['make_model'] && $isVehicleRelated): ?>
                <div class="notification-vehicle">→ <?= h($r['make_model']) ?></div>
              <?php endif; ?>
            </div>
            <div class="notification-meta"><?= date("M d, Y • h:i A", strtotime($r['created_at'])) ?></div>
          </div>
          <div class="notification-message">
            <?= nl2br(transformMessageForAdmin($r['message'], $r['full_name'])) ?>
   </div>
          <?php if(((int)($r['admin_is_read'] ?? 0) === 0)): ?>
            <div style="margin-top:.75rem;display:flex;justify-content:flex-end;">
              <button class="btn btn-secondary" type="button" onclick="event.stopPropagation(); markAdminRead(<?= (int)$r['id'] ?>)">Mark as Seen</button>
            </div>
          <?php endif; ?>
 </div>
<?php endwhile; else: ?>
        <div class="empty-state">
          <h3>No notifications found</h3>
          <p>Try adjusting your search criteria or check back later.</p>
        </div>
<?php endif; ?>
</div>

    <?php if($pages > 1): ?>
<div class="pagination">
        <?php for($i = 1; $i <= $pages; $i++): ?>
          <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>" 
             class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
<?php endfor; ?>
</div>
<?php endif; ?>
</div>

<script>
function markAdminRead(id){
  fetch('admin_notif.php?ajax=mark',{
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'id='+encodeURIComponent(id)
  }).then(r=>r.json()).then(()=>location.reload());
}

const notifModal = document.createElement('div');
notifModal.className = 'notif-modal';
notifModal.id = 'notifModal';
notifModal.innerHTML = `
  <div class="panel" role="dialog" aria-modal="true" aria-label="Notification details">
    <div class="panel-h">
      <h3 id="nmTitle">Notification</h3>
      <button class="x" type="button" id="nmClose">✕</button>
    </div>
    <div class="panel-b">
      <div class="kv"><span>Time</span><b id="nmTime"></b></div>
      <div class="kv"><span>User</span><b id="nmUser"></b></div>
      <div class="kv" id="nmVehicleRow" style="display:none;"><span>Vehicle</span><b id="nmVehicle"></b></div>
      <div class="fullmsg" id="nmMsg"></div>
    </div>
    <div class="panel-actions">
      <button class="btn btn-secondary" type="button" id="nmMark" style="display:none;">Mark as Seen</button>
      <button class="btn" type="button" id="nmClose2">Close</button>
    </div>
  </div>
`;
document.body.appendChild(notifModal);

function closeNotifModal(){
  notifModal.classList.remove('open');
  document.body.style.overflow = 'auto';
}

document.getElementById('nmClose').onclick = closeNotifModal;
document.getElementById('nmClose2').onclick = closeNotifModal;
notifModal.addEventListener('click', (e)=>{ if(e.target === notifModal) closeNotifModal(); });
document.addEventListener('keydown', (e)=>{ if(e.key === 'Escape' && notifModal.classList.contains('open')) closeNotifModal(); });

function openNotifModal(card){
  const id = parseInt(card.dataset.id || '0', 10);
  const isRead = parseInt(card.dataset.read || '0', 10) === 1;
  const time = card.dataset.time || '';
  const user = card.dataset.user || 'System';
  const role = card.dataset.role || '';
  const vehicle = card.dataset.vehicle || '';
  const msg = card.dataset.message || '';

  document.getElementById('nmTime').textContent = time;
  document.getElementById('nmUser').textContent = role ? (user + ' (' + role + ')') : user;
  document.getElementById('nmMsg').textContent = msg;

  const vRow = document.getElementById('nmVehicleRow');
  if (vehicle) {
    vRow.style.display = '';
    document.getElementById('nmVehicle').textContent = vehicle;
  } else {
    vRow.style.display = 'none';
    document.getElementById('nmVehicle').textContent = '';
  }

  const markBtn = document.getElementById('nmMark');
  if (!isRead && id > 0) {
    markBtn.style.display = '';
    markBtn.onclick = ()=> markAdminRead(id);
  } else {
    markBtn.style.display = 'none';
    markBtn.onclick = null;
  }

  notifModal.classList.add('open');
  document.body.style.overflow = 'hidden';
}

// Animate counters
    document.querySelectorAll('.stat h2').forEach(el => {
      const target = +el.textContent;
      let current = 0;

      const step = Math.max(1, Math.floor(target / 40));
      const interval = setInterval(() => {
        current += step;
        if (current >= target) {
          current = target;
          clearInterval(interval);
        }
        el.textContent = current;
      }, 25);
});
</script>

</body>
</html>
<?php 
if ($notificationsRes) $notificationsRes->free();
$conn->close(); 
?>