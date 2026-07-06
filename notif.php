<?php
/* =========================================
   notif.php — FleetGo User Notifications
   Enhanced Version with Professor Features
   ========================================= */

/* ---------- SESSION FIX ---------- */
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
if (isset($_COOKIE['fleetgo_session_user'])) session_name('fleetgo_session_user');
else session_name('fleetgo_session_guest');
session_start();

require_once __DIR__ . '/includes/db.php';

/* --- Access Control --- */
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$userID = (int)$_SESSION['user_id'];

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function h($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}

/* ---------- AJAX: fetch notifications ---------- */
if(isset($_GET['fetch'])){
  $page=(int)($_GET['page']??1);
  $limit=10;
  $offset=($page-1)*$limit;
  
  $stmt=$conn->prepare("
    SELECT n.*, v.make_model, v.plate_no, u.full_name as customer_name
    FROM notifications n
    LEFT JOIN vehicles v ON v.id=n.vehicle_id
    LEFT JOIN users u ON u.id=n.user_id
    WHERE n.user_id = ?
    ORDER BY n.created_at DESC
    LIMIT ? OFFSET ?
  ");
  $stmt->bind_param("iii",$userID,$limit,$offset);
  $stmt->execute();
  $notifications=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  
  header('Content-Type:application/json');
  echo json_encode($notifications);
  exit;
}

/* ---------- AJAX: mark as read ---------- */
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='mark_read'){
  $id=(int)$_POST['id'];
  $stmt=$conn->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?");
  $stmt->bind_param("ii",$id,$userID);
  $stmt->execute();
  echo json_encode(['success'=>true]);
  exit;
}

/* ---------- AJAX: mark all as read ---------- */
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='mark_all_read'){
  $stmt=$conn->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?");
  $stmt->bind_param("i",$userID);
  $stmt->execute();
  echo json_encode(['success'=>true]);
  exit;
}

$total_notifications=$conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=?");
$total_notifications->bind_param("i",$userID);
$total_notifications->execute();
$total_notifications = $total_notifications->get_result()->fetch_row()[0];

$unread_count=$conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
$unread_count->bind_param("i",$userID);
$unread_count->execute();
$unread_count = $unread_count->get_result()->fetch_row()[0];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>FleetGo • My Notifications</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
:root{
 --bg:#0b0d10;--card:#101419;--text:#f2f6fa;--muted:#9aa6b3;
 --brand:#5dd0ff;--brand2:#7cffc7;--radius:18px;--shadow:0 10px 28px rgba(0,0,0,.45);
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,system-ui,sans-serif}
h1{font-weight:800;margin:24px 0 10px;display:flex;align-items:center;gap:.5rem;}
h1::before{content:"🔔";}
.wrap{max-width:1280px;margin:0 auto;padding:24px;}
.btn{cursor:pointer;border:0;border-radius:10px;padding:10px 14px;font-weight:700;transition:.2s;}
.btn-primary{background:linear-gradient(90deg,var(--brand),var(--brand2));color:#04121b;box-shadow:0 6px 18px rgba(93,208,255,.25);}
.btn-primary:hover{box-shadow:0 8px 24px rgba(124,255,199,.3);transform:translateY(-2px);}
.btn-dark{background:#1a2833;color:#d9e8f2;border:1px solid rgba(255,255,255,.08);}
.btn-danger{background:#3b1a1a;color:#ffc9c9;}
.btn-success{background:#1a3b1a;color:#c9ffc9;}

.notification-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;padding:16px;background:var(--card);border-radius:var(--radius);box-shadow:var(--shadow);}
.notification-stats{display:flex;gap:16px;}
.stat-item{text-align:center;}
.stat-label{color:var(--muted);font-size:.8rem;margin-bottom:4px;}
.stat-value{color:var(--brand2);font-size:1.2rem;font-weight:700;}

.notification-list{display:flex;flex-direction:column;gap:12px;}
.notification-item{background:var(--card);border-radius:var(--radius);padding:16px;box-shadow:var(--shadow);transition:.3s;border-left:4px solid transparent;}
.notification-item:hover{transform:translateY(-2px);box-shadow:0 12px 32px rgba(93,208,255,.15);}
.notification-item.unread{border-left-color:var(--brand);background:linear-gradient(90deg,rgba(93,208,255,.05),var(--card));}
.notification-item.system{border-left-color:var(--brand2);}
.notification-item.booking{border-left-color:#ffd166;}
.notification-item.maintenance{border-left-color:#ff6b6b;}

.notification-header-item{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px;}
.notification-title{font-weight:700;font-size:1rem;color:var(--text);}
.notification-time{color:var(--muted);font-size:.8rem;}
.notification-message{color:var(--muted);line-height:1.5;margin-bottom:8px;}
.notification-meta{display:flex;gap:12px;font-size:.8rem;color:var(--muted);}
.notification-actions{display:flex;gap:8px;margin-top:12px;}
.notification-actions .btn{font-size:.8rem;padding:6px 12px;}

.loading{text-align:center;padding:40px;color:var(--muted);}
.load-more{text-align:center;margin-top:20px;}
.empty-state{text-align:center;padding:60px 20px;color:var(--muted);}
.empty-state h3{margin:0 0 8px;color:var(--text);}
.empty-state p{margin:0;}

.badge{padding:.2rem .6rem;border-radius:999px;font-weight:700;font-size:.74rem;text-transform:capitalize;}
.green{background:rgba(124,255,199,.15);color:#7cffc7;}
.yellow{background:rgba(255,209,102,.15);color:#ffd166;}
.red{background:rgba(255,107,107,.15);color:#ff7b7b;}
.blue{background:rgba(93,208,255,.15);color:#5dd0ff;}
</style>
</head>
<body>
<?php if(file_exists(__DIR__.'/includes/user_navbar.php')) include __DIR__.'/includes/user_navbar.php'; ?>
<div class="wrap">
  <h1>My Notifications</h1>
  
  <div class="notification-header">
    <div class="notification-stats">
      <div class="stat-item">
        <div class="stat-label">Total</div>
        <div class="stat-value"><?= number_format($total_notifications) ?></div>
      </div>
      <div class="stat-item">
        <div class="stat-label">Unread</div>
        <div class="stat-value"><?= number_format($unread_count) ?></div>
      </div>
    </div>
    <div>
      <button class="btn btn-success" onclick="markAllRead()">Mark All Read</button>
    </div>
  </div>

  <div id="notificationList" class="notification-list">
    <div class="loading">Loading notifications...</div>
  </div>
  
  <div id="loadMore" class="load-more" style="display:none;">
    <button class="btn btn-dark" onclick="loadMore()">Load More</button>
  </div>
</div>

<script>
let currentPage = 1;
let isLoading = false;

function loadNotifications(page = 1, append = false) {
  if (isLoading) return;
  isLoading = true;
  
  const list = document.getElementById('notificationList');
  if (!append) {
    list.innerHTML = '<div class="loading">Loading notifications...</div>';
  }
  
  fetch(`?fetch=1&page=${page}`)
    .then(r => r.json())
    .then(notifications => {
      if (notifications.length === 0 && page === 1) {
        list.innerHTML = `
          <div class="empty-state">
            <h3>No notifications yet</h3>
            <p>You'll see your booking updates and notifications here.</p>
          </div>
        `;
        return;
      }
      
      if (append) {
        list.innerHTML += notifications.map(renderNotification).join('');
      } else {
        list.innerHTML = notifications.map(renderNotification).join('');
      }
      
      const loadMoreBtn = document.getElementById('loadMore');
      if (notifications.length === 10) {
        loadMoreBtn.style.display = 'block';
      } else {
        loadMoreBtn.style.display = 'none';
      }
      
      currentPage = page;
    })
    .catch(err => {
      console.error('Error loading notifications:', err);
      if (!append) {
        list.innerHTML = '<div class="empty-state"><h3>Error loading notifications</h3><p>Please refresh the page.</p></div>';
      }
    })
    .finally(() => {
      isLoading = false;
    });
}

function renderNotification(notif) {
  const time = new Date(notif.created_at).toLocaleString();
  const isUnread = !notif.is_read;
  const type = getNotificationType(notif.message);
  
  // Determine if this is a vehicle-related notification
  const isVehicleRelated = isVehicleNotification(notif.message);
  
  // Only show vehicle info if it's actually a vehicle-related notification AND has vehicle data
  const shouldShowVehicle = isVehicleRelated && notif.make_model && notif.plate_no;
  
  return `
    <div class="notification-item ${isUnread ? 'unread' : ''} ${type}">
      <div class="notification-header-item">
        <div class="notification-title">${isVehicleRelated ? 'Vehicle Notification' : 'System Notification'}</div>
        <div class="notification-time">${time}</div>
      </div>
      <div class="notification-message">${notif.message}</div>
      <div class="notification-meta">
        ${shouldShowVehicle ? `<span>Vehicle: ${notif.make_model} (${notif.plate_no})</span>` : ''}
        <span class="badge ${isUnread ? 'blue' : 'green'}">${isUnread ? 'Unread' : 'Read'}</span>
      </div>
      <div class="notification-actions">
        ${isUnread ? `<button class="btn btn-dark" onclick="markAsRead(${notif.id})">Mark Read</button>` : ''}
      </div>
    </div>
  `;
}

function getNotificationType(message) {
  if (message.includes('booking') || message.includes('rental')) return 'booking';
  if (message.includes('maintenance')) return 'maintenance';
  return 'system';
}

function isVehicleNotification(message) {
  // Check if the message contains vehicle-related keywords
  const vehicleKeywords = [
    'booking', 'rental', 'returned', 'maintenance', 
    'plate', 'honda', 'toyota', 'mazda', 'nissan', 'ford',
    'sedan', 'suv', 'pickup', 'motorcycle', 'car',
    'vehicle', 'booked', 'rented', 'return'
  ];
  
  // Exclude welcome/profile messages
  const excludeKeywords = [
    'welcome', 'profile', 'approved', 'registered', 'logged in'
  ];
  
  const lowerMessage = message.toLowerCase();
  
  // If it contains exclusion keywords, it's not a vehicle notification
  if (excludeKeywords.some(keyword => lowerMessage.includes(keyword))) {
    return false;
  }
  
  return vehicleKeywords.some(keyword => lowerMessage.includes(keyword));
}

function markAsRead(id) {
  fetch('', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: `action=mark_read&id=${id}`
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      loadNotifications(1);
    }
  });
}

function markAllRead() {
  if (!confirm('Mark all notifications as read?')) return;
  
  fetch('', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: 'action=mark_all_read'
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      loadNotifications(1);
    }
  });
}

function loadMore() {
  loadNotifications(currentPage + 1, true);
}

// Load initial notifications
loadNotifications(1);
</script>
</body>
</html>