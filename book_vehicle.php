<?php
session_start();

/* ==========================
   DATABASE CONNECTION
========================== */
$DB_HOST = "127.0.0.1";
$DB_USER = "root";
$DB_PASS = "";
$DB_NAME = "fleet_rental_db";
$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
$conn->set_charset("utf8mb4");

if ($conn->connect_errno) {
  http_response_code(500);
  die("❌ Database connection failed.");
}

/* ==========================
   AUTH CHECK
========================== */
if (!isset($_SESSION['user_id'])) {
  die("⚠️ Please log in to book a vehicle.");
}

$user_id    = (int)$_SESSION['user_id'];
$vehicle_id = (int)($_POST['vehicle_id'] ?? 0);
$start_date = trim($_POST['start_date'] ?? '');
$end_date   = trim($_POST['end_date'] ?? '');

if (!$vehicle_id || !$start_date || !$end_date) {
  die("⚠️ Missing booking information.");
}

/* ==========================
   VALIDATE DATES
========================== */
$start = strtotime($start_date);
$end   = strtotime($end_date);
if (!$start || !$end || $end < $start) {
  die("⚠️ Invalid date range selected.");
}

/* ==========================
   CHECK CONFLICTS
   (Allow same-day handoff, block overlaps)
========================== */
$conflict_sql = "
  SELECT id, status, end_date
  FROM rentals
  WHERE vehicle_id = ?
    AND status IN ('active','pending','ongoing','reserved')
    AND (start_date < ? AND end_date > ?)
";
$conflict = $conn->prepare($conflict_sql);
$conflict->bind_param("iss", $vehicle_id, $end_date, $start_date);
$conflict->execute();
$res = $conflict->get_result();
$conflicts = $res->fetch_all(MYSQLI_ASSOC);
$conflict->close();

if (count($conflicts) > 0) {
  // Check if one of them has been recently extended
  $extended = false;
  foreach ($conflicts as $row) {
    $diffHours = abs(strtotime('now') - strtotime($row['end_date'])) / 3600;
    if ($diffHours < 24 && in_array(strtolower($row['status']), ['ongoing','active'])) {
      $extended = true;
      break;
    }
  }

  if ($extended) {
    die("⚠️ Sorry, this vehicle has been recently extended by another user and is no longer available for the selected dates.");
  } else {
    die("⚠️ Sorry, this vehicle is not available for the selected dates.");
  }
}

/* ==========================
   GET VEHICLE RATE + INFO
========================== */
$stmt = $conn->prepare("SELECT daily_rate, make_model FROM vehicles WHERE id = ?");
$stmt->bind_param("i", $vehicle_id);
$stmt->execute();
$stmt->bind_result($rate, $model);
$stmt->fetch();
$stmt->close();

if (!$rate) die("⚠️ Vehicle not found.");

/* ==========================
   INSERT RENTAL RECORD
========================== */
$insert = $conn->prepare("
  INSERT INTO rentals (vehicle_id, customer_id, start_date, end_date, daily_rate, status, created_at)
  VALUES (?, ?, ?, ?, ?, 'waitlist', NOW())
");
$insert->bind_param("iissd", $vehicle_id, $user_id, $start_date, $end_date, $rate);
if (!$insert->execute()) {
  die("❌ Booking failed. Please try again.");
}
$insert->close();

/* ==========================
   ADD NOTIFICATION
========================== */
$message = "⏳ Your booking for <b>" . htmlspecialchars($model) . "</b> is on hold until you pay. Submit your downpayment receipt to move it to pending.";
$notif = $conn->prepare("
  INSERT INTO notifications (user_id, vehicle_id, message, created_at, is_read)
  VALUES (?, ?, ?, NOW(), 0)
");
$notif->bind_param("iis", $user_id, $vehicle_id, $message);
$notif->execute();
$notif->close();

/* ==========================
   SUCCESS
========================== */
echo "Your booking is on hold until you pay. Submit your downpayment receipt or it will stay on hold and will not be approved.";

$conn->close();
?>
