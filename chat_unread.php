<?php
/* FleetGo — lightweight unread chat count for nav badges */
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

if (isset($_COOKIE['fleetgo_session_admin'])) {
  session_name('fleetgo_session_admin');
} elseif (isset($_COOKIE['fleetgo_session_user'])) {
  session_name('fleetgo_session_user');
} else {
  session_name('fleetgo_session_guest');
}
session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/chat.php';

$role = strtolower((string)($_SESSION['role'] ?? ''));
$userId = (int)($_SESSION['user_id'] ?? 0);

if ($userId <= 0 || !in_array($role, ['admin', 'user'], true)) {
  echo json_encode(['ok' => false, 'count' => 0]);
  exit;
}

$count = $role === 'admin'
  ? chat_admin_unread_count($conn)
  : chat_user_unread_count($conn, $userId);

echo json_encode(['ok' => true, 'count' => $count, 'role' => $role]);
