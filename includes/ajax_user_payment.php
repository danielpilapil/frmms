<?php
/* ============================================================
   FleetGo — Customer payment submission with receipt photo
============================================================ */
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
session_name('fleetgo_session_user');
session_start();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/payment_methods.php';
ensure_rental_waitlist_status($conn);

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'user') {
    echo json_encode(['success' => false, 'message' => 'Please log in to submit a payment.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$rental_id = (int)($_POST['rental_id'] ?? 0);
$amount = (float)($_POST['amount'] ?? 0);
$method = strtoupper(trim((string)($_POST['payment_method'] ?? '')));
$reference_no = trim((string)($_POST['reference_no'] ?? ''));
$notes = trim((string)($_POST['notes'] ?? ''));

if ($rental_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid rental.']);
    exit;
}

if ($amount <= 0) {
    echo json_encode(['success' => false, 'message' => 'Amount must be greater than 0.']);
    exit;
}

$payment_method_id = (int)($_POST['payment_method_id'] ?? 0);
$selected = $payment_method_id > 0 ? pm_get($conn, $payment_method_id, true) : null;
if (!$selected) {
    echo json_encode(['success' => false, 'message' => 'Please select a valid payment method.']);
    exit;
}
$method = (string)$selected['provider_code'];

if (empty($_FILES['receipt_photo']['name'])) {
    echo json_encode(['success' => false, 'message' => 'Please upload a photo of your payment receipt.']);
    exit;
}

try {
    $stmt = $conn->prepare("
        SELECT id, customer_id, status, downpayment, total_cost, balance_due
        FROM rentals
        WHERE id = ? AND customer_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('ii', $rental_id, $user_id);
    $stmt->execute();
    $rental = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$rental) {
        echo json_encode(['success' => false, 'message' => 'Rental not found.']);
        exit;
    }

    $status = strtolower((string)($rental['status'] ?? ''));
    if (!in_array($status, ['waitlist', 'pending', 'reserved'], true)) {
        echo json_encode(['success' => false, 'message' => 'Payments can only be submitted for waitlist, pending, or reserved bookings.']);
        exit;
    }

    // Sum already submitted / posted downpayments
    $paidStmt = $conn->prepare("
        SELECT COALESCE(SUM(amount), 0) AS paid_amount
        FROM rental_payments
        WHERE rental_id = ?
          AND payment_type = 'DOWNPAYMENT'
          AND status IN ('PENDING', 'POSTED')
    ");
    $paidStmt->bind_param('i', $rental_id);
    $paidStmt->execute();
    $paid_amount = (float)($paidStmt->get_result()->fetch_assoc()['paid_amount'] ?? 0);
    $paidStmt->close();

    $due = (float)($rental['downpayment'] ?? 0);
    if ($due <= 0) {
        $due = round(((float)($rental['total_cost'] ?? 0)) * 0.5, 2);
    }
    $remaining = max(0, round($due - $paid_amount, 2));

    if ($remaining <= 0) {
        echo json_encode(['success' => false, 'message' => 'Downpayment has already been submitted for this booking.']);
        exit;
    }

    if ($amount > $remaining + 0.01) {
        echo json_encode(['success' => false, 'message' => 'Amount exceeds the remaining downpayment of ₱' . number_format($remaining, 2) . '.']);
        exit;
    }

    // Upload receipt photo
    $uploadDir = __DIR__ . '/../uploads/payment_receipts/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $file = $_FILES['receipt_photo'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'Failed to upload receipt photo. Please try again.']);
        exit;
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    if (!in_array($ext, $allowedExt, true)) {
        echo json_encode(['success' => false, 'message' => 'Receipt photo must be JPG, PNG, WEBP, or GIF.']);
        exit;
    }

    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'Receipt photo must be 5MB or smaller.']);
        exit;
    }

    $newName = 'receipt_' . $rental_id . '_' . $user_id . '_' . time() . '.' . $ext;
    $destAbs = $uploadDir . $newName;
    $destRel = 'uploads/payment_receipts/' . $newName;

    if (!move_uploaded_file($file['tmp_name'], $destAbs)) {
        echo json_encode(['success' => false, 'message' => 'Could not save receipt photo.']);
        exit;
    }

    $payment_type = 'DOWNPAYMENT';
    $payment_status = 'PENDING';

    $ins = $conn->prepare("
        INSERT INTO rental_payments
            (rental_id, payment_type, amount, payment_method, proof_image, reference_no, notes, status, paid_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $ins->bind_param(
        'isdsssss',
        $rental_id,
        $payment_type,
        $amount,
        $method,
        $destRel,
        $reference_no,
        $notes,
        $payment_status
    );

    if (!$ins->execute()) {
        @unlink($destAbs);
        throw new Exception('Failed to save payment.');
    }
    $payment_id = (int)$conn->insert_id;
    $ins->close();

    if ($status === 'waitlist') {
        $move = $conn->prepare("UPDATE rentals SET status = 'pending' WHERE id = ? AND customer_id = ? AND status = 'waitlist'");
        $move->bind_param('ii', $rental_id, $user_id);
        $move->execute();
        $move->close();
    }

    if ($payment_id <= 0) {
        // Fallback if AUTO_INCREMENT was broken
        $lookup = $conn->prepare("SELECT payment_id FROM rental_payments WHERE rental_id = ? AND proof_image = ? ORDER BY payment_id DESC LIMIT 1");
        $lookup->bind_param('is', $rental_id, $destRel);
        $lookup->execute();
        $payment_id = (int)($lookup->get_result()->fetch_assoc()['payment_id'] ?? 0);
        $lookup->close();
    }

    // Notify customer (admin notification center also lists these)
    $veh = $conn->query("SELECT make_model FROM vehicles v JOIN rentals r ON r.vehicle_id = v.id WHERE r.id = " . (int)$rental_id . " LIMIT 1")->fetch_assoc();
    $vehicleName = $veh['make_model'] ?? 'your booking';
    $amountFmt = number_format($amount, 2);
    $msg = "Payment receipt of <b>₱{$amountFmt}</b> submitted for <b>{$vehicleName}</b> (Rental #{$rental_id}). Your booking is now pending admin approval.";
    require_once __DIR__ . '/notification_manager.php';
    $vehIdStmt = $conn->prepare("SELECT vehicle_id FROM rentals WHERE id = ? LIMIT 1");
    $vehIdStmt->bind_param('i', $rental_id);
    $vehIdStmt->execute();
    $vehicle_id = (int)($vehIdStmt->get_result()->fetch_assoc()['vehicle_id'] ?? 0);
    $vehIdStmt->close();
    createNotificationIfNotExists($conn, $user_id, $vehicle_id, $msg, false);

    echo json_encode([
        'success' => true,
        'message' => 'Payment receipt submitted. Your booking is now pending admin approval.',
        'payment_id' => $payment_id,
        'remaining' => max(0, round($remaining - $amount, 2))
    ]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Payment failed: ' . $e->getMessage()]);
}
