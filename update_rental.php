<?php
session_start();

/* --- Database Connection --- */
$DB_HOST = "127.0.0.1";
$DB_USER = "root";
$DB_PASS = "";
$DB_NAME = "fleet_rental_db";
$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
$conn->set_charset("utf8mb4");

if ($conn->connect_errno) {
  http_response_code(500);
  exit("❌ Database connection failed.");
}

/* --- Admin check (simple version) --- */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
  exit("❌ Unauthorized access.");
}

/* --- Inputs --- */
$rental_id = (int)($_POST['rental_id'] ?? 0);
$new_status = $_POST['status'] ?? '';
$new_end = $_POST['end_date'] ?? '';

if (!$rental_id) {
  exit("❌ Missing rental ID.");
}

/* --- Fetch current rental details --- */
$res = $conn->query("SELECT * FROM rentals WHERE id=$rental_id");
if (!$res || $res->num_rows == 0) {
  exit("❌ Rental not found.");
}
$r = $res->fetch_assoc();
$vehicle_id = $r['vehicle_id'];
$customer_id = $r['customer_id'];
$old_end = $r['end_date'];

/* --- Update logic --- */
if ($new_status) {
  $stmt = $conn->prepare("UPDATE rentals SET status=? WHERE id=?");
  $stmt->bind_param("si", $new_status, $rental_id);
  $stmt->execute();
  $stmt->close();

  // Notify user about status change
  $msg = "Your booking for Vehicle #$vehicle_id was updated to '$new_status'.";
  $conn->query("INSERT INTO notifications (user_id, vehicle_id, message) VALUES ($customer_id, $vehicle_id, '$msg')");
}

if (!empty($new_end) && $new_end !== $old_end) {
  $stmt = $conn->prepare("UPDATE rentals SET end_date=? WHERE id=?");
  $stmt->bind_param("si", $new_end, $rental_id);
  $stmt->execute();
  $stmt->close();

  /* --- Detect affected future bookings --- */
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
    $uid = $row['customer_id'];
    $msg = "⚠️ The vehicle you booked (ID $vehicle_id) is delayed — the current renter extended their booking until $new_end.";
    $conn->query("INSERT INTO notifications (user_id, vehicle_id, message) VALUES ($uid, $vehicle_id, '$msg')");
  }

  $find->close();
}

echo "✅ Rental record updated successfully.";
$conn->close();
?>
