<?php
session_start();

/* --- Database Connection --- */
require_once __DIR__ . '/db.php';

/* --- Admin check --- */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
  exit("❌ Unauthorized access.");
}

/* --- Inputs --- */
$rental_id = (int)($_POST['rental_id'] ?? 0);
$new_status = trim($_POST['status'] ?? '');
$new_end = trim($_POST['end_date'] ?? '');

if (!$rental_id) exit("❌ Missing rental ID.");

/* --- Fetch rental details --- */
$res = $conn->prepare("SELECT * FROM rentals WHERE id=?");
$res->bind_param("i", $rental_id);
$res->execute();
$r = $res->get_result()->fetch_assoc();
$res->close();

if (!$r) exit("❌ Rental not found.");

$vehicle_id = (int)$r['vehicle_id'];
$customer_id = (int)$r['customer_id'];
$old_end = $r['end_date'];

/* --- Vehicle info --- */
$vehInfo = $conn->prepare("SELECT make_model, plate_no FROM vehicles WHERE id=? LIMIT 1");
$vehInfo->bind_param("i", $vehicle_id);
$vehInfo->execute();
$veh = $vehInfo->get_result()->fetch_assoc();
$vehInfo->close();

$vehLabel = $veh ? "{$veh['make_model']} (Plate {$veh['plate_no']})" : "Vehicle #$vehicle_id";

/* ==========================================================
   STATUS UPDATE LOGIC
========================================================== */
if ($new_status !== '') {
  $stmt = $conn->prepare("UPDATE rentals SET status=? WHERE id=?");
  $stmt->bind_param("si", $new_status, $rental_id);
  $stmt->execute();
  $stmt->close();

  // Send per-user notification (only renter)
  $msg = "📢 Booking for <b>{$vehLabel}</b> updated to <b>" . ucfirst($new_status) . "</b>.";
  require_once __DIR__ . '/notification_manager.php';
  createNotificationIfNotExists($conn, $customer_id, $vehicle_id, $msg);
  $n->close();

  // Auto adjust vehicle availability
  $vehStat = 'available';
  if ($new_status === 'ongoing') $vehStat = 'rented';
  elseif ($new_status === 'reserved') $vehStat = 'reserved';
  elseif (in_array($new_status, ['completed', 'cancelled'])) {
    $next = $conn->query("
      SELECT id FROM rentals 
      WHERE vehicle_id=$vehicle_id 
        AND status='reserved' 
        AND start_date>CURDATE() 
      ORDER BY start_date ASC LIMIT 1
    ")->fetch_assoc();
    $vehStat = $next ? 'reserved' : 'available';
  }
  $conn->query("UPDATE vehicles SET current_status='$vehStat' WHERE id=$vehicle_id");
}

/* ==========================================================
   EXTEND RENTAL LOGIC
========================================================== */
if (!empty($new_end) && $new_end !== $old_end) {
  $stmt = $conn->prepare("UPDATE rentals SET end_date=? WHERE id=?");
  $stmt->bind_param("si", $new_end, $rental_id);
  $stmt->execute();
  $stmt->close();

  // Notify main renter
  $msgMain = "✅ Your rental for <b>{$vehLabel}</b> was extended. New return date: <b>{$new_end}</b>.";
  require_once __DIR__ . '/notification_manager.php';
  createNotificationIfNotExists($conn, $customer_id, $vehicle_id, $msgMain);
  $stmtN->close();

  // Find conflicting future rentals
  $find = $conn->prepare("
    SELECT id, customer_id, start_date
    FROM rentals
    WHERE vehicle_id=? 
      AND status IN ('waitlist','pending','reserved')
      AND start_date BETWEEN ? AND ?
  ");
  $find->bind_param("iss", $vehicle_id, $old_end, $new_end);
  $find->execute();
  $affected = $find->get_result();

  while ($row = $affected->fetch_assoc()) {
    $uid = (int)$row['customer_id'];
    $rid = (int)$row['id'];
    $startC = $row['start_date'];

    // Cancel conflicting rental
    $upd = $conn->prepare("UPDATE rentals SET status='cancelled' WHERE id=?");
    $upd->bind_param("i", $rid);
    $upd->execute();
    $upd->close();

    // Notify affected user
    $msg = "⚠️ Your booking for <b>{$vehLabel}</b> (starting <b>{$startC}</b>) was cancelled because the current renter extended until <b>{$new_end}</b>.";
    require_once __DIR__ . '/notification_manager.php';
    createNotificationIfNotExists($conn, $uid, $vehicle_id, $msg);
    $stmtC->close();
  }

  $find->close();

  // Ensure vehicle stays rented
  $conn->query("UPDATE vehicles SET current_status='rented' WHERE id=$vehicle_id");
}

echo "✅ Rental record updated successfully.";
$conn->close();
?>
