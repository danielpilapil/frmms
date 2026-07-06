<?php
// Disable error reporting to prevent HTML output
error_reporting(0);
ini_set('display_errors', 0);

// Start output buffering to catch any unexpected output
ob_start();

require_once 'includes/db.php';

// Clear any output that might have been generated
ob_clean();

// Set JSON header
header('Content-Type: application/json');

try {
  // Test endpoint
  if($_GET['action'] === 'test') {
    echo json_encode(['success' => true, 'message' => 'AJAX endpoint working', 'db_status' => $conn->ping()]);
    exit;
  }
  
  if(isset($_GET['action'])) {
    if($_GET['action'] === 'get_user_details' && isset($_GET['id'])) {
      $userId = (int)$_GET['id'];
      $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
      $stmt->bind_param("i", $userId);
      $stmt->execute();
      $user = $stmt->get_result()->fetch_assoc();
      
      if($user) {
        echo json_encode(['success' => true, 'user' => $user]);
      } else {
        echo json_encode(['success' => false, 'message' => 'User not found']);
      }
      exit;
    }
    
    if($_GET['action'] === 'notify_user' && $_SERVER['REQUEST_METHOD'] === 'POST') {
      $input = json_decode(file_get_contents('php://input'), true);
      
      if(!$input) {
        echo json_encode(['success' => false, 'message' => 'Invalid JSON input']);
        exit;
      }
      
      $userId = (int)($input['user_id'] ?? 0);
      $message = trim($input['message'] ?? '');
      
      // Validate user exists
      $user_check = $conn->prepare("SELECT id FROM users WHERE id = ?");
      $user_check->bind_param("i", $userId);
      $user_check->execute();
      $user_exists = $user_check->get_result()->fetch_assoc();
      
      if($userId && $message && $user_exists) {
        // Get first available vehicle ID for system notifications
        $vehicle_result = $conn->query("SELECT id FROM vehicles LIMIT 1");
        $vehicle_id = 1; // Default fallback
        if($vehicle_result && $row = $vehicle_result->fetch_assoc()) {
          $vehicle_id = $row['id'];
        }
        
        require_once __DIR__ . '/includes/notification_manager.php';
        $result = createNotificationIfNotExists($conn, $userId, $vehicle_id, $message);
        
        if($result) {
          echo json_encode(['success' => true, 'message' => 'Notification sent successfully']);
        } else {
          echo json_encode(['success' => false, 'message' => 'Failed to send notification']);
        }
      } else {
        if(!$userId) {
          echo json_encode(['success' => false, 'message' => 'Invalid user ID']);
        } elseif(!$message) {
          echo json_encode(['success' => false, 'message' => 'Message is required']);
        } elseif(!$user_exists) {
          echo json_encode(['success' => false, 'message' => 'User not found']);
        } else {
          echo json_encode(['success' => false, 'message' => 'Invalid data']);
        }
      }
      exit;
    }
  }

  echo json_encode(['success' => false, 'message' => 'Invalid action']);
  
} catch (Exception $e) {
  echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
} finally {
  $conn->close();
}
?>
