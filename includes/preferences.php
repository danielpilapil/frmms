<?php
// preferences.php — User notification preferences API (GET/POST)
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
session_name('fleetgo_session_user');
session_start();

header('Content-Type: application/json');

require_once __DIR__ . '/includes/db.php';

if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? '') !== 'user')) {
  http_response_code(401);
  echo json_encode(['error' => 'unauthorized']);
  exit;
}

$userId = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $stmt = $conn->prepare("SELECT email, sms, news FROM user_preferences WHERE user_id=?");
  $stmt->bind_param('i', $userId);
  $stmt->execute();
  $res = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$res) { $res = ['email' => 1, 'sms' => 0, 'news' => 0]; }
  echo json_encode($res);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $raw = file_get_contents('php://input');
  $data = json_decode($raw, true);
  if (!is_array($data)) { $data = $_POST; }
  $email = !empty($data['email']) ? 1 : 0;
  $sms   = !empty($data['sms']) ? 1 : 0;
  $news  = !empty($data['news']) ? 1 : 0;

  $sql = "INSERT INTO user_preferences(user_id,email,sms,news)
          VALUES(?,?,?,?)
          ON DUPLICATE KEY UPDATE email=VALUES(email), sms=VALUES(sms), news=VALUES(news)";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param('iiii', $userId, $email, $sms, $news);
  $stmt->execute();
  $stmt->close();
  echo json_encode(['ok' => true]);
  exit;
}

http_response_code(405);
echo json_encode(['error' => 'method_not_allowed']);
