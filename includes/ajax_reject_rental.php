<?php
/* =========================================================
   ajax_reject_rental.php — AJAX endpoint for rejecting rentals
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

// Only handle POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

$rental_id = (int)($_POST['rental_id'] ?? 0);
if($rental_id){
    $conn->begin_transaction();
    try {
        $rental = $conn->query("SELECT * FROM rentals WHERE id = $rental_id")->fetch_assoc();
        if($rental){
            $conn->query("UPDATE rentals SET status='cancelled' WHERE id=$rental_id");
            
            // Update vehicle status to available
            $conn->query("UPDATE vehicles SET current_status='available' WHERE id={$rental['vehicle_id']}");
            
            // Send notification
            $msg = "Booking for <b>{$rental['make_model']}</b> has been rejected.";
            require_once __DIR__ . '/notification_manager.php';
            createNotificationIfNotExists($conn, $rental['customer_id'], $rental['vehicle_id'], $msg);
            
            $conn->commit();
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Rental rejected successfully']);
        } else {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Rental not found']);
        }
    } catch (Exception $e) {
        $conn->rollback();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Failed to reject rental']);
    }
} else {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid rental ID']);
}

$conn->close();
exit;
?>
