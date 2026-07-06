<?php
/* =========================================================
   ajax_approve_rental.php — AJAX endpoint for approving rentals
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
        // Get rental details
        $rental = $conn->query("SELECT * FROM rentals WHERE id = $rental_id")->fetch_assoc();
        if($rental){
            // Payment policy gate:
            // - required_down is stored on rentals.downpayment at booking time (50% of base)
            // - paid_amount comes from vw_rental_payment_summary (POSTED only)
            $required_down = (float)($rental['downpayment'] ?? 0);
            $paid_row = $conn->query("SELECT paid_amount FROM vw_rental_payment_summary WHERE rental_id = $rental_id")->fetch_assoc();
            $paid_amount = (float)($paid_row['paid_amount'] ?? 0);

            if ($paid_amount + 0.00001 < $required_down) {
                $conn->rollback();
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'message' => 'Downpayment not met. Required: ₱' . number_format($required_down, 2) . ' | Paid: ₱' . number_format($paid_amount, 2)
                ]);
                exit;
            }

            $today = date('Y-m-d');
            $status = (strtotime($rental['start_date']) <= strtotime($today)) ? 'ongoing' : 'reserved';

            $conn->query("UPDATE rentals SET status='$status' WHERE id=$rental_id");
            
            // Update vehicle status
            $vehStat = ($status === 'ongoing') ? 'rented' : 'reserved';
            $conn->query("UPDATE vehicles SET current_status='$vehStat' WHERE id={$rental['vehicle_id']}");
            
            // Send notification
            $msg = "Booking for <b>{$rental['make_model']}</b> updated to <b>$status</b>.";
            require_once __DIR__ . '/notification_manager.php';
            createNotificationIfNotExists($conn, $rental['customer_id'], $rental['vehicle_id'], $msg);
            
            $conn->commit();
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Rental approved successfully']);
        } else {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Rental not found']);
        }
    } catch (Exception $e) {
        $conn->rollback();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Failed to approve rental']);
    }
} else {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid rental ID']);
}

$conn->close();
exit;
?>
