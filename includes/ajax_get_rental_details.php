<?php
/* =========================================================
   ajax_get_rental_details.php — AJAX endpoint for getting rental details
   ========================================================= */

// Start session
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
session_name('fleetgo_session_admin');
session_start();

require_once __DIR__ . '/db.php';

// Disable all error output for clean JSON
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Clear any existing output buffer
if (ob_get_level()) {
    ob_clean();
}

// Check authentication
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

// Only handle GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

$rental_id = (int)($_GET['rental_id'] ?? 0);
if($rental_id){
    $rental = $conn->query("
        SELECT r.*, v.make_model, v.plate_no, u.full_name, u.contact_no
        FROM rentals r
        JOIN vehicles v ON v.id = r.vehicle_id
        JOIN users u ON u.id = r.customer_id
        WHERE r.id = $rental_id
    ")->fetch_assoc();
    
    if($rental){
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'data' => $rental]);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Rental not found']);
    }
} else {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid rental ID']);
}

$conn->close();
exit;
?>
