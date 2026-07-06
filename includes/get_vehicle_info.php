<?php
/* ============================================================
   FleetGo • Get Vehicle Info (AJAX Endpoint)
   - Returns JSON data for selected vehicle
   ============================================================ */

// Start session and check admin access
if (session_status() === PHP_SESSION_NONE) {
  if (isset($_COOKIE['fleetgo_session_admin'])) session_name('fleetgo_session_admin');
  else session_name('fleetgo_session_guest');
  session_start();
}

// Check if user is admin
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
  http_response_code(403);
  echo json_encode(['error' => 'Unauthorized']);
  exit;
}

// Set JSON header
header('Content-Type: application/json');

// Check if vehicle_id is provided
if (!isset($_GET['vehicle_id']) || !is_numeric($_GET['vehicle_id'])) {
  http_response_code(400);
  echo json_encode(['error' => 'Invalid vehicle ID']);
  exit;
}

$vehicle_id = (int)$_GET['vehicle_id'];

// Database connection
require_once __DIR__ . '/db.php';

try {
  // Fetch vehicle information
  $stmt = $conn->prepare("
    SELECT id, plate_no, chassis_number, engine_number, make_model, vehicle_type, 
           seats, year, odometer, daily_rate, transmission, comfort_level, 
           current_status, photo
    FROM vehicles 
    WHERE id = ?
    LIMIT 1
  ");
  
  $stmt->bind_param("i", $vehicle_id);
  $stmt->execute();
  $result = $stmt->get_result();
  $vehicle = $result->fetch_assoc();
  $stmt->close();
  
  if (!$vehicle) {
    http_response_code(404);
    echo json_encode(['error' => 'Vehicle not found']);
    exit;
  }
  
  // Return vehicle data
  echo json_encode([
    'success' => true,
    'vehicle' => $vehicle
  ]);
  
} catch (Exception $e) {
  http_response_code(500);
  echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
} finally {
  $conn->close();
}
?>
