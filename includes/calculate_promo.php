<?php
/* ============================================================
   FleetGo — Calculate Promotional Discount (AJAX Endpoint)
============================================================ */

/* ---------- SESSION FIX ---------- */
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
session_name('fleetgo_session_user');
session_start();

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/promo_calculator.php';

header('Content-Type: application/json');

/* ==========================
   AUTH CHECK
========================== */
if (!isset($_SESSION['user_id'])) {
  echo json_encode([
    'error' => true,
    'message' => "Please log in to calculate promotional discounts."
  ]);
  exit;
}

$user_id = (int)$_SESSION['user_id'];

/* ==========================
   VALIDATION
========================== */
$vehicle_id = (int)($_POST['vehicle_id'] ?? 0);
$start_date = trim($_POST['start_date'] ?? '');
$end_date = trim($_POST['end_date'] ?? '');
$rate_type = trim($_POST['rate_type'] ?? 'CDO');

if (!$vehicle_id || !$start_date || !$end_date) {
  echo json_encode([
    'error' => true,
    'message' => 'Missing required information.'
  ]);
  exit;
}

$start = strtotime($start_date);
$end = strtotime($end_date);
if (!$start || !$end || $end < $start) {
  echo json_encode([
    'error' => true,
    'message' => 'Invalid date range.'
  ]);
  exit;
}

/* ==========================
   GET VEHICLE RATE
========================== */
$stmt = $conn->prepare("SELECT daily_rate_cdo, daily_rate_outside_cdo FROM vehicles WHERE id = ?");
$stmt->bind_param("i", $vehicle_id);
$stmt->execute();
$vehicle = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$vehicle) {
  echo json_encode([
    'error' => true,
    'message' => 'Vehicle not found.'
  ]);
  exit;
}

// Get base rate based on location
$base_rate = getVehicleRate($vehicle_id, $rate_type);
$rental_days = max(1, round(($end - $start) / 86400, 1));

if ($base_rate <= 0) {
  echo json_encode([
    'error' => true,
    'message' => 'Invalid vehicle rate.'
  ]);
  exit;
}

/* ==========================
   CALCULATE PROMOTIONAL RATE
========================== */
$promo_data = calculatePromotionalRate($user_id, $vehicle_id, $rental_days, $rate_type, $base_rate);

/* ==========================
   RETURN RESULTS
========================== */
echo json_encode([
  'success' => true,
  'original_cost' => $base_rate * $rental_days,
  'total_cost' => $promo_data['total_cost'],
  'promo_applied' => $promo_data['promo_applied'],
  'promo_discount' => $promo_data['promo_discount'],
  'discount_percent' => $promo_data['discount_percent'],
  'rental_days' => $rental_days,
  'base_rate' => $base_rate
]);

$conn->close();
?>
