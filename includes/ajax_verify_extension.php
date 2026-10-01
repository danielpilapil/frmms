<?php
/* ============================================================
   FleetGo — Admin approve / reject rental extension request
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

$request_id = (int)($_POST['request_id'] ?? 0);
$action = strtolower(trim((string)($_POST['action'] ?? '')));

if ($request_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid extension request.']);
    exit;
}

if (!in_array($action, ['approve', 'reject'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid action.']);
    exit;
}

try {
    $conn->query("
      CREATE TABLE IF NOT EXISTS rental_extension_requests (
        id INT(11) NOT NULL AUTO_INCREMENT,
        rental_id INT(11) NOT NULL,
        customer_id INT(11) NOT NULL,
        old_end_date DATE NOT NULL,
        requested_end_date DATE NOT NULL,
        status ENUM('PENDING','APPROVED','REJECTED') NOT NULL DEFAULT 'PENDING',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        reviewed_at DATETIME NULL DEFAULT NULL,
        PRIMARY KEY (id),
        KEY idx_rental_status (rental_id, status),
        KEY idx_status (status)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $stmt = $conn->prepare("
        SELECT er.*, r.vehicle_id, r.status AS rental_status, r.end_date AS current_end,
               v.make_model
        FROM rental_extension_requests er
        JOIN rentals r ON r.id = er.rental_id
        LEFT JOIN vehicles v ON v.id = r.vehicle_id
        WHERE er.id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $request_id);
    $stmt->execute();
    $req = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$req) {
        echo json_encode(['success' => false, 'message' => 'Extension request not found.']);
        exit;
    }

    if (strtoupper((string)$req['status']) !== 'PENDING') {
        echo json_encode(['success' => false, 'message' => 'This extension request was already reviewed.']);
        exit;
    }

    $newStatus = ($action === 'approve') ? 'APPROVED' : 'REJECTED';
    $rental_id = (int)$req['rental_id'];
    $vID = (int)$req['vehicle_id'];
    $customer_id = (int)$req['customer_id'];
    $newEnd = (string)$req['requested_end_date'];
    $model = $req['make_model'] ?? 'Vehicle';
    $modelEsc = $conn->real_escape_string($model);
    $newEndFmt = date('M d, Y', strtotime($newEnd));

    $conn->begin_transaction();

    $upd = $conn->prepare("UPDATE rental_extension_requests SET status = ?, reviewed_at = NOW() WHERE id = ? AND status = 'PENDING'");
    $upd->bind_param('si', $newStatus, $request_id);
    if (!$upd->execute() || $upd->affected_rows < 1) {
        $upd->close();
        throw new Exception('Could not update extension request.');
    }
    $upd->close();

    if ($action === 'approve') {
        if (strtolower((string)$req['rental_status']) !== 'ongoing') {
            throw new Exception('Rental is no longer ongoing.');
        }

        // Cancel overlapping future reservations
        $conflicts = $conn->prepare("
            SELECT id, customer_id, start_date
            FROM rentals
            WHERE vehicle_id = ?
              AND id <> ?
              AND status IN ('waitlist','pending','reserved')
              AND start_date <= ?
        ");
        $conflicts->bind_param('iis', $vID, $rental_id, $newEnd);
        $conflicts->execute();
        $conflictRows = $conflicts->get_result()->fetch_all(MYSQLI_ASSOC);
        $conflicts->close();

        require_once __DIR__ . '/notification_manager.php';
        foreach ($conflictRows as $c) {
            $rid2 = (int)$c['id'];
            $cid = (int)$c['customer_id'];
            $startC = $c['start_date'];
            $conn->query("UPDATE rentals SET status='cancelled' WHERE id=$rid2");
            $warn = "Your booking for <b>{$modelEsc}</b> (starting <b>{$startC}</b>) was cancelled because the previous renter extended until <b>{$newEndFmt}</b>.";
            createNotificationIfNotExists($conn, $cid, $vID, $warn, false);
        }

        $ext = $conn->prepare("UPDATE rentals SET end_date = ? WHERE id = ?");
        $ext->bind_param('si', $newEnd, $rental_id);
        if (!$ext->execute()) {
            $ext->close();
            throw new Exception('Failed to update rental end date.');
        }
        $ext->close();

        $conn->query("UPDATE vehicles SET current_status='rented' WHERE id=$vID");

        $msg = "Your extension for <b>{$modelEsc}</b> was approved. New end date: <b>{$newEndFmt}</b>.";
        createNotificationIfNotExists($conn, $customer_id, $vID, $msg, false);
    } else {
        require_once __DIR__ . '/notification_manager.php';
        $msg = "Your extension request for <b>{$modelEsc}</b> until <b>{$newEndFmt}</b> was rejected.";
        createNotificationIfNotExists($conn, $customer_id, $vID, $msg, false);
    }

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => $action === 'approve' ? 'Extension approved.' : 'Extension rejected.',
        'status' => $newStatus,
        'new_end_date' => $action === 'approve' ? $newEnd : null
    ]);
} catch (Throwable $e) {
    if ($conn->errno) {
        $conn->rollback();
    }
    echo json_encode(['success' => false, 'message' => 'Failed to review extension: ' . $e->getMessage()]);
}
