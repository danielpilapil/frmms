<?php
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
session_name('fleetgo_session_admin');
session_start();

require_once __DIR__ . '/db.php';

error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

if (ob_get_level()) {
    ob_clean();
}

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$rental_id = (int)($_POST['rental_id'] ?? 0);
$amount = (float)($_POST['amount'] ?? 0);
$method = strtoupper(trim((string)($_POST['payment_method'] ?? '')));

if ($rental_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid rental ID']);
    exit;
}

if ($amount <= 0) {
    echo json_encode(['success' => false, 'message' => 'Amount must be greater than 0']);
    exit;
}

$allowed_methods = ['CASH', 'GCASH'];
if (!in_array($method, $allowed_methods, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid payment method']);
    exit;
}

try {
    $stmt = $conn->prepare("SELECT id, status, downpayment FROM rentals WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $rental_id);
    $stmt->execute();
    $rental = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$rental) {
        echo json_encode(['success' => false, 'message' => 'Rental not found']);
        exit;
    }

    $status = strtolower((string)($rental['status'] ?? ''));
    if ($status !== 'pending') {
        echo json_encode(['success' => false, 'message' => 'Payments can only be recorded for pending rentals']);
        exit;
    }

    $conn->begin_transaction();

    $payment_type = 'DOWNPAYMENT';
    $payment_status = 'POSTED';

    $ins = $conn->prepare("INSERT INTO rental_payments (rental_id, payment_type, amount, status, paid_at) VALUES (?, ?, ?, ?, NOW())");
    $ins->bind_param('isds', $rental_id, $payment_type, $amount, $payment_status);
    if (!$ins->execute()) {
        throw new Exception('Failed to record payment');
    }
    $ins->close();

    $paid_row = $conn->query("SELECT paid_amount FROM vw_rental_payment_summary WHERE rental_id = $rental_id")->fetch_assoc();
    $paid_amount = (float)($paid_row['paid_amount'] ?? 0);

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Payment recorded successfully',
        'paid_amount' => $paid_amount
    ]);
    exit;
} catch (Exception $e) {
    if ($conn->errno) {
        $conn->rollback();
    }
    echo json_encode(['success' => false, 'message' => 'Failed to record payment']);
    exit;
}
