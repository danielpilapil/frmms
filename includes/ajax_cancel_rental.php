<?php
/* ============================================================
   FleetGo — Customer cancels their own rental (with reason)
============================================================ */
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
session_name('fleetgo_session_user');
session_start();

require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'user') {
    echo json_encode(['success' => false, 'message' => 'Please log in to cancel a booking.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$rental_id = (int)($_POST['rental_id'] ?? 0);
$reason = trim((string)($_POST['cancel_reason'] ?? ''));

if ($rental_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid rental.']);
    exit;
}

if ($reason === '') {
    echo json_encode(['success' => false, 'message' => 'Please provide a reason for cancelling.']);
    exit;
}

$reasonLen = function_exists('mb_strlen') ? mb_strlen($reason) : strlen($reason);

if ($reasonLen < 5) {
    echo json_encode(['success' => false, 'message' => 'Cancellation reason must be at least 5 characters.']);
    exit;
}

if ($reasonLen > 500) {
    echo json_encode(['success' => false, 'message' => 'Cancellation reason must be 500 characters or less.']);
    exit;
}

/**
 * History trigger used to reject pending→cancelled because old_status ENUM
 * did not include pending/reserved. Widen columns so cancel can persist.
 */
function ensure_rental_status_history_allows_pending(mysqli $conn): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $conn->query("
            ALTER TABLE rental_status_history
              MODIFY COLUMN old_status VARCHAR(32) NULL DEFAULT NULL,
              MODIFY COLUMN new_status VARCHAR(32) NOT NULL
        ");
    } catch (Throwable $e) {
        // Table may not exist on older installs — ignore
    }
}

try {
    // Ensure cancel_reason columns exist
    $col = $conn->query("SHOW COLUMNS FROM rentals LIKE 'cancel_reason'");
    if (!$col || $col->num_rows === 0) {
        $conn->query("ALTER TABLE rentals ADD COLUMN cancel_reason TEXT NULL DEFAULT NULL AFTER status");
    }
    $col2 = $conn->query("SHOW COLUMNS FROM rentals LIKE 'cancelled_at'");
    if (!$col2 || $col2->num_rows === 0) {
        $conn->query("ALTER TABLE rentals ADD COLUMN cancelled_at DATETIME NULL DEFAULT NULL AFTER cancel_reason");
    }

    ensure_rental_status_history_allows_pending($conn);

    $stmt = $conn->prepare("
        SELECT r.id, r.customer_id, r.vehicle_id, r.status, v.make_model, v.plate_no
        FROM rentals r
        LEFT JOIN vehicles v ON v.id = r.vehicle_id
        WHERE r.id = ? AND r.customer_id = ?
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
    // Users may cancel before the trip starts (pending / reserved only)
    if (!in_array($status, ['waitlist', 'pending', 'reserved'], true)) {
        echo json_encode([
            'success' => false,
            'message' => 'Only waitlist, pending, or reserved bookings can be cancelled. Ongoing rentals must be returned through the admin.',
        ]);
        exit;
    }

    $conn->begin_transaction();

    $upd = $conn->prepare("
        UPDATE rentals
        SET status = 'cancelled',
            cancel_reason = ?,
            cancelled_at = NOW()
        WHERE id = ? AND customer_id = ? AND status IN ('waitlist','pending','reserved')
    ");
    $upd->bind_param('sii', $reason, $rental_id, $user_id);
    $ok = $upd->execute();
    $affected = (int)$upd->affected_rows;
    $updErr = $upd->error;
    $upd->close();

    if (!$ok || $affected < 1) {
        // Retry once after repairing history schema (common trigger failure)
        ensure_rental_status_history_allows_pending($conn);
        $upd2 = $conn->prepare("
            UPDATE rentals
            SET status = 'cancelled',
                cancel_reason = ?,
                cancelled_at = NOW()
            WHERE id = ? AND customer_id = ? AND status IN ('waitlist','pending','reserved')
        ");
        $upd2->bind_param('sii', $reason, $rental_id, $user_id);
        $ok2 = $upd2->execute();
        $affected2 = (int)$upd2->affected_rows;
        $updErr = $upd2->error ?: $updErr;
        $upd2->close();

        if (!$ok2 || $affected2 < 1) {
            $conn->rollback();
            $hint = $updErr !== '' ? (' ' . $updErr) : '';
            echo json_encode([
                'success' => false,
                'message' => 'Could not cancel this rental.' . ($hint !== '' ? ' (' . $hint . ')' : ' It may have already changed status.'),
            ]);
            exit;
        }
    }

    // Free the vehicle if it was reserved for this booking
    $vehicle_id = (int)$rental['vehicle_id'];
    if ($vehicle_id > 0) {
        $hold = $conn->prepare("
            SELECT COUNT(*) AS c FROM rentals
            WHERE vehicle_id = ?
              AND id <> ?
              AND status IN ('ongoing','reserved')
              AND end_date >= CURDATE()
        ");
        $hold->bind_param('ii', $vehicle_id, $rental_id);
        $hold->execute();
        $otherHolds = (int)($hold->get_result()->fetch_assoc()['c'] ?? 0);
        $hold->close();

        if ($otherHolds === 0) {
            $vUpd = $conn->prepare("UPDATE vehicles SET current_status = 'available' WHERE id = ? AND current_status IN ('reserved','rented')");
            $vUpd->bind_param('i', $vehicle_id);
            $vUpd->execute();
            $vUpd->close();
        }
    }

    $conn->commit();

    // Confirm persisted status
    $check = $conn->prepare("SELECT status FROM rentals WHERE id = ? AND customer_id = ? LIMIT 1");
    $check->bind_param('ii', $rental_id, $user_id);
    $check->execute();
    $finalStatus = strtolower((string)($check->get_result()->fetch_assoc()['status'] ?? ''));
    $check->close();
    if ($finalStatus !== 'cancelled') {
        echo json_encode(['success' => false, 'message' => 'Cancellation did not save. Please try again.']);
        exit;
    }

    $vehicleName = (string)($rental['make_model'] ?? 'vehicle');
    $plate = (string)($rental['plate_no'] ?? '');
    $reasonSafe = htmlspecialchars($reason, ENT_QUOTES, 'UTF-8');
    $msg = "You cancelled booking <b>#{$rental_id}</b> for <b>{$vehicleName}</b>"
        . ($plate !== '' ? " ({$plate})" : '')
        . ". Reason: {$reasonSafe}";

    require_once __DIR__ . '/notification_manager.php';
    createNotificationIfNotExists($conn, $user_id, $vehicle_id, $msg, false);

    $admins = $conn->query("SELECT id FROM users WHERE role = 'admin' LIMIT 20");
    if ($admins) {
        $userName = (string)($_SESSION['user_name'] ?? 'Customer');
        $adminMsg = "<b>" . htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') . "</b> cancelled rental <b>#{$rental_id}</b> ({$vehicleName})"
            . ($plate !== '' ? " · {$plate}" : '')
            . ". Reason: {$reasonSafe}";
        while ($a = $admins->fetch_assoc()) {
            createNotificationIfNotExists($conn, (int)$a['id'], $vehicle_id, $adminMsg, false);
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Booking cancelled successfully.',
        'rental_id' => $rental_id,
    ]);
} catch (Throwable $e) {
    try { @$conn->rollback(); } catch (Throwable $ignore) {}
    // One more attempt: repair history schema then update outside a failed txn
    try {
        ensure_rental_status_history_allows_pending($conn);
        $fix = $conn->prepare("
            UPDATE rentals
            SET status = 'cancelled', cancel_reason = ?, cancelled_at = NOW()
            WHERE id = ? AND customer_id = ? AND status IN ('waitlist','pending','reserved')
        ");
        $fix->bind_param('sii', $reason, $rental_id, $user_id);
        if ($fix->execute() && $fix->affected_rows > 0) {
            $fix->close();
            echo json_encode([
                'success' => true,
                'message' => 'Booking cancelled successfully.',
                'rental_id' => $rental_id,
            ]);
            exit;
        }
        $fix->close();
    } catch (Throwable $e2) {}

    echo json_encode(['success' => false, 'message' => 'Cancellation failed. Please try again.']);
}
