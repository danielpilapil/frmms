<?php
/* FleetGo — Chat helpers + schema */

if (!function_exists('chat_ensure_tables')) {
  function chat_ensure_tables(mysqli $conn): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $conn->query("
      CREATE TABLE IF NOT EXISTS chat_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sender_id INT NOT NULL,
        sender_role ENUM('admin','user') NOT NULL,
        recipient_id INT NULL DEFAULT NULL,
        body TEXT NOT NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_sender (sender_id),
        INDEX idx_recipient (recipient_id),
        INDEX idx_created (created_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
  }
}

if (!function_exists('chat_socket_url')) {
  function chat_socket_url(): string {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $hostname = preg_replace('/:\\d+$/', '', $host);
    if ($hostname === '' || $hostname === '127.0.0.1') $hostname = 'localhost';
    return 'http://' . $hostname . ':3001';
  }
}

/** Unread customer → admin messages */
if (!function_exists('chat_admin_unread_count')) {
  function chat_admin_unread_count(mysqli $conn): int {
    try {
      chat_ensure_tables($conn);
      $res = $conn->query("SELECT COUNT(*) AS c FROM chat_messages WHERE sender_role='user' AND is_read=0");
      if ($res && ($row = $res->fetch_assoc())) {
        return (int)($row['c'] ?? 0);
      }
    } catch (Throwable $e) {}
    return 0;
  }
}

/** Unread admin → customer messages */
if (!function_exists('chat_user_unread_count')) {
  function chat_user_unread_count(mysqli $conn, int $userId): int {
    if ($userId <= 0) return 0;
    try {
      chat_ensure_tables($conn);
      $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM chat_messages WHERE sender_role='admin' AND recipient_id=? AND is_read=0");
      $stmt->bind_param('i', $userId);
      $stmt->execute();
      $row = $stmt->get_result()->fetch_assoc();
      $stmt->close();
      return (int)($row['c'] ?? 0);
    } catch (Throwable $e) {}
    return 0;
  }
}
