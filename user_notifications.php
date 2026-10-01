<?php
session_start();
$DB_HOST = "127.0.0.1";
$DB_USER = "root";
$DB_PASS = "";
$DB_NAME = "fleet_rental_db";
$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
$conn->set_charset("utf8mb4");
if ($conn->connect_errno) {
  http_response_code(500);
  echo json_encode(["error" => "DB connection failed."]);
  exit;
}

if (!isset($_SESSION['user_id'])) {
  http_response_code(403);
  echo json_encode(["error" => "Unauthorized"]);
  exit;
}

$userID = (int)$_SESSION['user_id'];
header("Content-Type: application/json");

/* ==========================
   FETCH MODE
========================== */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'fetch') {

  // ✅ Fetch only user-facing notifications (skip admin/system logs)
  $res = $conn->query("
    SELECT id, message, created_at, is_read
    FROM notifications
    WHERE user_id = $userID
      AND message NOT LIKE '%booking request was submitted%'   /* hide admin logs */
      AND message NOT LIKE '%System%'                          /* hide system updates */
      AND message NOT LIKE '%admin%'                           /* hide admin tags */
    ORDER BY created_at DESC
    LIMIT 15
  ");

  $html = '<style>
  /* === User Notification Dropdown — Light Theme === */
  ul.user-notif-list {
    list-style:none;margin:0;padding:0;width:100%;
    background:#fff;border-radius:12px;
    box-shadow:0 10px 30px rgba(0,0,0,0.15);
    overflow:hidden;
  }
  ul.user-notif-list li {
    padding:12px 14px;border-bottom:1px solid rgba(0,0,0,0.06);
    background:#fff;transition:background 0.2s;
  }
  ul.user-notif-list li.unread {
    background:#f0fbff;border-left:4px solid #5dd0ff;
  }
  ul.user-notif-list li.read {
    background:#fff;opacity:0.9;
  }
  ul.user-notif-list li:hover {
    background:#e9f8ff;
  }
  ul.user-notif-list li .msg {
    font-size:0.9rem;line-height:1.4;color:#0b0d10;
  }
  ul.user-notif-list li .notif-meta {
    font-size:0.75rem;color:#666;margin-top:4px;
  }
  ul.user-notif-list li.empty {
    padding:14px;text-align:center;color:#999;
  }
  </style>';

  $items = [];
  while ($n = $res->fetch_assoc()) {
    $cls = $n['is_read'] ? 'read' : 'unread';
    $time = date("M j, Y g:i A", strtotime($n['created_at']));
    $items[] = "
      <li class='{$cls}'>
        <div class='msg'>{$n['message']}</div>
        <div class='notif-meta'>{$time}</div>
      </li>";
  }

  if (!$items) {
    $html .= "<ul class='user-notif-list'><li class='empty'>No notifications yet.</li></ul>";
  } else {
    $html .= "<ul class='user-notif-list'>" . implode('', $items) . "</ul>";
  }

  $countRes = $conn->query("
    SELECT COUNT(*) AS c 
    FROM notifications 
    WHERE user_id=$userID AND is_read=0
      AND message NOT LIKE '%booking request was submitted%'
      AND message NOT LIKE '%System%'
      AND message NOT LIKE '%admin%'
  ");
  $count = $countRes ? (int)$countRes->fetch_assoc()['c'] : 0;

  echo json_encode(["html" => $html, "count" => $count]);
  exit;
}

/* ==========================
   MARK AS READ MODE
========================== */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'mark') {
  $conn->query("UPDATE notifications SET is_read=1 WHERE user_id=$userID");
  echo json_encode(["ok" => true]);
  exit;
}

/* ==========================
   DEFAULT
========================== */
echo json_encode(["error" => "Invalid request"]);
?>
