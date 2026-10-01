<?php
/* ============================================================
   FleetGo — Admin verify / reject customer payment receipt
============================================================ */
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
session_name('fleetgo_session_admin');
session_start();

require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$payment_id = (int)($_POST['payment_id'] ?? 0);
$action = strtolower(trim((string)($_POST['action'] ?? '')));

if ($payment_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid payment.']);
    exit;
}

if (!in_array($action, ['approve', 'reject'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid action.']);
    exit;
}

try {
    $stmt = $conn->prepare("
        SELECT rp.payment_id, rp.rental_id, rp.amount, rp.status, rp.payment_type,
               r.customer_id, r.vehicle_id, r.downpayment, r.status AS rental_status,
               v.make_model
        FROM rental_payments rp
        JOIN rentals r ON r.id = rp.rental_id
        LEFT JOIN vehicles v ON v.id = r.vehicle_id
        WHERE rp.payment_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $payment_id);
    $stmt->execute();
    $payment = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$payment) {
        echo json_encode(['success' => false, 'message' => 'Payment not found.']);
        exit;
    }

    if (strtoupper((string)$payment['status']) !== 'PENDING') {
        echo json_encode(['success' => false, 'message' => 'This payment was already reviewed.']);
        exit;
    }

    $newStatus = ($action === 'approve') ? 'POSTED' : 'REJECTED';

    $conn->begin_transaction();

    $upd = $conn->prepare("UPDATE rental_payments SET status = ? WHERE payment_id = ? AND status = 'PENDING'");
    $upd->bind_param('si', $newStatus, $payment_id);
    if (!$upd->execute() || $upd->affected_rows < 1) {
        $upd->close();
        throw new Exception('Could not update payment status.');
    }
    $upd->close();

    $paid_row = $conn->query("SELECT paid_amount FROM vw_rental_payment_summary WHERE rental_id = " . (int)$payment['rental_id'])->fetch_assoc();
    $paid_amount = (float)($paid_row['paid_amount'] ?? 0);
    $required_down = (float)($payment['downpayment'] ?? 0);

    $conn->commit();

    $vehicle = $payment['make_model'] ?: 'your vehicle';
    $amountFmt = number_format((float)$payment['amount'], 2);
    if ($action === 'approve') {
        $msg = "Your payment of <b>₱{$amountFmt}</b> for <b>{$vehicle}</b> was verified.";
    } else {
        $msg = "Your payment receipt of <b>₱{$amountFmt}</b> for <b>{$vehicle}</b> was rejected. Please submit a new receipt.";
    }

    require_once __DIR__ . '/notification_manager.php';
    createNotificationIfNotExists(
        $conn,
        (int)$payment['customer_id'],
        (int)$payment['vehicle_id'],
        $msg,
        false
    );

    echo json_encode([
        'success' => true,
        'message' => $action === 'approve' ? 'Payment verified and posted.' : 'Payment receipt rejected.',
        'status' => $newStatus,
        'paid_amount' => $paid_amount,
        'required_down' => $required_down,
        'ready_for_approval' => ($paid_amount + 0.00001 >= $required_down)
    ]);
} catch (Throwable $e) {
    if ($conn->errno) {
        $conn->rollback();
    }
    echo json_encode(['success' => false, 'message' => 'Failed to review payment: ' . $e->getMessage()]);
}
