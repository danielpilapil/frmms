<?php
/* ============================================
   FleetGo Notification Helper Functions
   ============================================ */

/**
 * Transform user-centric messages to admin-friendly ones
 */
function transformMessageForAdmin($message, $customerName) {
  $customerName = $customerName ?: 'Customer';
  
  $transformations = [
    'Your booking' => $customerName . "'s booking",
    'your booking' => $customerName . "'s booking",
    'Your rental' => $customerName . "'s rental", 
    'your rental' => $customerName . "'s rental",
    'Your vehicle' => $customerName . "'s vehicle",
    'your vehicle' => $customerName . "'s vehicle",
    'You have' => $customerName . ' has',
    'you have' => $customerName . ' has',
    'You can' => $customerName . ' can',
    'you can' => $customerName . ' can',
    'You will' => $customerName . ' will',
    'you will' => $customerName . ' will',
    'You are' => $customerName . ' is',
    'you are' => $customerName . ' is',
    'Your account' => $customerName . "'s account",
    'your account' => $customerName . "'s account",
    'Your request' => $customerName . "'s request",
    'your request' => $customerName . "'s request",
    'Your extension' => $customerName . "'s extension",
    'your extension' => $customerName . "'s extension",
    'You received' => $customerName . ' received',
    'you received' => $customerName . ' received',
    'You got' => $customerName . ' got',
    'you got' => $customerName . ' got',
    'You saved' => $customerName . ' saved',
    'you saved' => $customerName . ' saved',
    'You are eligible' => $customerName . ' is eligible',
    'you are eligible' => $customerName . ' is eligible',
    'You qualify' => $customerName . ' qualifies',
    'you qualify' => $customerName . ' qualifies',
  ];
  
  $transformedMessage = $message;
  foreach ($transformations as $userPhrase => $adminPhrase) {
    $transformedMessage = str_ireplace($userPhrase, $adminPhrase, $transformedMessage);
  }
  
  return $transformedMessage;
}

/**
 * Get notification statistics
 */
function getNotificationStats($conn) {
  $stats = [];
  
  // Total notifications
  $stmt = $conn->prepare("SELECT COUNT(*) as total FROM notifications");
  $stmt->execute();
  $stats['total'] = (int)$stmt->get_result()->fetch_assoc()['total'];
  $stmt->close();
  
  // Unread notifications
  $stmt = $conn->prepare("SELECT COUNT(*) as unread FROM notifications WHERE is_read = 0");
  $stmt->execute();
  $stats['unread'] = (int)$stmt->get_result()->fetch_assoc()['unread'];
  $stmt->close();
  
  return $stats;
}

/**
 * Get notifications with search and pagination
 */
function getNotifications($conn, $search = '', $page = 1, $limit = 20, $filter = '') {
  $offset = ($page - 1) * $limit;
  $where = "WHERE 1=1";
  $params = [];
  $types = "";
  
  if ($search !== "") {
    $where .= " AND (u.full_name LIKE CONCAT('%',?,'%')
                OR v.make_model LIKE CONCAT('%',?,'%')
                OR n.message LIKE CONCAT('%',?,'%'))";
    $params = [$search, $search, $search];
    $types = "sss";
  }
  
  if ($filter === 'promotions') {
    $where .= " AND (n.message LIKE '%promotion%' OR n.message LIKE '%discount%' OR n.message LIKE '%savings%')";
  }
  
  // Count total for pagination
  $count_sql = "SELECT COUNT(*) as total FROM notifications n
                LEFT JOIN users u ON n.user_id = u.id
                LEFT JOIN vehicles v ON n.vehicle_id = v.id
                $where";
  $stmt = $conn->prepare($count_sql);
  if ($types) $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $total = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
  $stmt->close();
  
  // Fetch notifications
  $sql = "SELECT n.*, u.full_name, u.email, u.role, u.contact_no,
                 v.make_model, v.plate_no, v.vehicle_type, v.daily_rate, v.current_status
          FROM notifications n
          LEFT JOIN users u ON n.user_id = u.id
          LEFT JOIN vehicles v ON n.vehicle_id = v.id
          $where
          ORDER BY n.created_at DESC
          LIMIT ? OFFSET ?";
  
  $types .= "ii";
  $params[] = $limit;
  $params[] = $offset;
  
  $stmt = $conn->prepare($sql);
  $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $result = $stmt->get_result();
  
  return [
    'notifications' => $result,
    'total' => $total,
    'pages' => max(1, ceil($total / $limit))
  ];
}

/**
 * Mark all notifications as read
 */
function markAllAsRead($conn) {
  $stmt = $conn->prepare("UPDATE notifications SET is_read = 1");
  $stmt->execute();
  $stmt->close();
}
?>
