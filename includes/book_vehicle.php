<?php
/* ============================================================
   FleetGo — Book Vehicle (Session-Safe for User Role)
============================================================ */

/* ---------- SESSION FIX ---------- */
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
session_name('fleetgo_session_user');
session_start();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/promo_calculator.php';

/* ==========================
   AUTH CHECK
========================== */
if (!isset($_SESSION['user_id'])) {
  header('Content-Type: application/json');
  echo json_encode([
    'error' => true,
    'message' => "⚠️ Please log in to book a vehicle."
  ]);
  exit;
}

// Block admins from booking vehicles
if (($_SESSION['role'] ?? '') === 'admin') {
  header('Content-Type: application/json');
  echo json_encode([
    'error' => true,
    'message' => "⚠️ Admins cannot book vehicles. Please use the admin dashboard.",
    'redirect' => 'dashboard.php'
  ]);
  exit;
}

// Only allow users to book vehicles
if (($_SESSION['role'] ?? '') !== 'user') {
  header('Content-Type: application/json');
  echo json_encode([
    'error' => true,
    'message' => "⚠️ Only users can book vehicles.",
    'redirect' => 'login.php'
  ]);
  exit;
}

$user_id = (int)$_SESSION['user_id'];

/* ==========================
   PROFILE APPROVAL CHECK
========================== */
$stmt = $conn->prepare("
  SELECT status, email, contact_no, address, license_photo, valid_id_photo, profile_status, verification_status
  FROM users WHERE id = ?
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Fetch user documents (new storage)
$userDocuments = [];
$docStmt = $conn->prepare("SELECT doc_type, file_path FROM user_documents WHERE user_id = ?");
$docStmt->bind_param("i", $user_id);
$docStmt->execute();
$documents = $docStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$docStmt->close();
foreach ($documents as $doc) {
  if (!empty($doc['doc_type'])) {
    $userDocuments[$doc['doc_type']] = $doc['file_path'] ?? '';
  }
}

$missing = [];
$required_fields = [
  'email' => 'Email address',
  'contact_no' => 'Contact number', 
  'address' => 'Address',
  'license_photo' => 'Driver\'s license photo',
  'valid_id_photo' => 'Valid ID photo'
];

foreach ($required_fields as $field => $label) {
  if ($field === 'license_photo') {
    $hasLicenseFront = !empty(trim($user['license_photo'] ?? '')) || !empty(trim($userDocuments['License Front'] ?? ''));
    if (!$hasLicenseFront) {
      $missing[] = $label;
    }
    continue;
  }

  if ($field === 'valid_id_photo') {
    $hasIdFront = !empty(trim($user['valid_id_photo'] ?? '')) || !empty(trim($userDocuments['ID Front'] ?? ''));
    if (!$hasIdFront) {
      $missing[] = $label;
    }
    continue;
  }

  if (empty(trim($user[$field] ?? ''))) {
    $missing[] = $label;
  }
}

$profile_status = $user['profile_status'] ?? 'incomplete';
$verification_status = $user['verification_status'] ?? 'unverified';
$account_status = $user['status'] ?? '';

/* --- Incomplete profile --- */
if (!empty($missing)) {
  $missing_list = implode(', ', $missing);
  $message = "⚠️ Please complete your profile first. Missing: " . $missing_list;

  header('Content-Type: application/json');
  echo json_encode([
    'error' => true,
    'redirect' => 'userprofile.php',
    'message' => $message
  ]);
  exit;
}

/* --- Profile not approved --- */
if ($profile_status !== 'approved') {
  $status_messages = [
    'incomplete' => 'Please complete your profile first.',
    'pending_approval' => 'Your profile is pending admin approval. Please wait for approval.',
    'rejected' => 'Your profile was rejected. Please update your information and resubmit for approval.'
  ];
  
  $message = "⚠️ " . ($status_messages[$profile_status] ?? 'Your profile needs admin approval.');

  header('Content-Type: application/json');
  echo json_encode([
    'error' => true,
    'redirect' => 'userprofile.php',
    'message' => $message
  ]);
  exit;
}

/* --- Verification not completed --- */
if ($verification_status !== 'verified') {
  $ver_messages = [
    'unverified' => 'Please submit your profile for verification first.',
    'pending' => 'Your verification is pending. Please wait for admin review.',
    'pending_approval' => 'Your verification is pending. Please wait for admin review.',
    'rejected' => 'Your verification was rejected. Please update your information and resubmit.'
  ];

  $message = "⚠️ " . ($ver_messages[$verification_status] ?? 'Please complete your profile verification first.');

  header('Content-Type: application/json');
  echo json_encode([
    'error' => true,
    'redirect' => 'userprofile.php',
    'message' => $message
  ]);
  exit;
}

/* --- Account inactive --- */
if ($account_status !== 'active') {
  header('Content-Type: application/json');
  echo json_encode([
    'error' => true,
    'message' => "⚠️ Your account is not active. Please contact support."
  ]);
  exit;
}

/* ==========================
   BOOKING VALIDATION
========================== */
$vehicle_id = (int)($_POST['vehicle_id'] ?? 0);
$start_date = trim($_POST['start_date'] ?? '');
$end_date   = trim($_POST['end_date'] ?? '');
$start_time = trim($_POST['start_time'] ?? '08:00:00'); // User-selected pickup time
$end_time   = trim($_POST['end_time'] ?? '18:00:00');   // User-selected return time
$rate_type  = trim($_POST['rate_type'] ?? 'CDO'); // CDO or Outside-CDO

if (!$vehicle_id || !$start_date || !$end_date || !$start_time || !$end_time) {
  header('Content-Type: application/json');
  echo json_encode(['error' => true, 'message' => '⚠️ Missing booking information.']);
  exit;
}

// Validate date and time format
$start = strtotime($start_date);
$end   = strtotime($end_date);
if (!$start || !$end || $end < $start) {
  header('Content-Type: application/json');
  echo json_encode(['error' => true, 'message' => '⚠️ Invalid date range selected.']);
  exit;
}

// Validate that end date/time is after start date/time
$start_datetime = strtotime($start_date . ' ' . $start_time);
$end_datetime = strtotime($end_date . ' ' . $end_time);
if ($end_datetime <= $start_datetime) {
  header('Content-Type: application/json');
  echo json_encode(['error' => true, 'message' => '⚠️ Return time must be after pickup time.']);
  exit;
}

/* ==========================
   CONFLICT CHECK
========================== */
$conflict_sql = "
  SELECT id, status, start_date, start_time, end_date, end_time
  FROM rentals
  WHERE vehicle_id = ?
    AND status IN ('pending','ongoing','reserved')
    AND (
      (start_date < ? OR (start_date = ? AND start_time <= ?)) 
      AND 
      (end_date > ? OR (end_date = ? AND end_time >= ?))
    )
";
$conflict = $conn->prepare($conflict_sql);
$conflict->bind_param("isssiss", $vehicle_id, $end_date, $end_date, $end_time, $start_date, $start_date, $start_time);
$conflict->execute();
$res = $conflict->get_result();
$conflicts = $res->fetch_all(MYSQLI_ASSOC);
$conflict->close();

if (count($conflicts) > 0) {
  $extended = false;
  foreach ($conflicts as $row) {
    $diffHours = abs(strtotime('now') - strtotime($row['end_date'])) / 3600;
    if ($diffHours < 24 && strtolower($row['status']) === 'ongoing') {
      $extended = true;
      break;
    }
  }

  $msg = $extended
    ? "⚠️ This vehicle has been recently extended by another user and is no longer available for those dates."
    : "⚠️ This vehicle is not available for the selected dates.";

  header('Content-Type: application/json');
  echo json_encode(['error' => true, 'message' => $msg]);
  exit;
}

/* ==========================
   MAINTENANCE BLOCKING CHECK
========================== */
$maintenance_check = $conn->prepare("
  SELECT m.id, m.status, m.schedule_date, m.maintenance_category, m.description
  FROM maintenance m
  WHERE m.vehicle_id = ?
    AND m.status IN ('reported', 'scheduled', 'approved', 'in_progress')
    AND (
      (m.schedule_date <= ? AND m.status IN ('scheduled', 'approved'))
      OR m.status IN ('reported', 'in_progress')
    )
  LIMIT 1
");
$maintenance_check->bind_param("is", $vehicle_id, $end_date);
$maintenance_check->execute();
$maintenance_result = $maintenance_check->get_result()->fetch_assoc();
$maintenance_check->close();

if ($maintenance_result) {
  $status_msg = [
    'reported' => 'reported for maintenance',
    'scheduled' => 'scheduled for maintenance on ' . date('M d, Y', strtotime($maintenance_result['schedule_date'])),
    'approved' => 'approved for maintenance',
    'in_progress' => 'currently under maintenance'
  ];
  
  $msg = "⚠️ This vehicle is " . ($status_msg[$maintenance_result['status']] ?? 'under maintenance') . " and is not available for booking.";
  
  header('Content-Type: application/json');
  echo json_encode(['error' => true, 'message' => $msg]);
  exit;
}

/* ==========================
   GET VEHICLE RATE + INFO
========================== */
$stmt = $conn->prepare("SELECT daily_rate_cdo, daily_rate_outside_cdo, maker, model, make_model FROM vehicles WHERE id = ?");
$stmt->bind_param("i", $vehicle_id);
$stmt->execute();
$stmt->bind_result($rate_cdo, $rate_outside, $maker, $model, $make_model);
$stmt->fetch();
$stmt->close();

if (!$rate_cdo && !$rate_outside) {
  header('Content-Type: application/json');
  echo json_encode(['error' => true, 'message' => '⚠️ Vehicle not found.']);
  exit;
}

// Get base rate based on location
$base_rate = getVehicleRate($vehicle_id, $rate_type);

// Calculate rental days based on actual datetime difference
$start_datetime = strtotime($start_date . ' ' . $start_time);
$end_datetime = strtotime($end_date . ' ' . $end_time);

// FIXED: Use round instead of ceil to prevent overcharging
$rental_days = max(1, round(($end_datetime - $start_datetime) / 86400, 1));

// Calculate promotional rate
$promo_data = calculatePromotionalRate($user_id, $vehicle_id, $rental_days, $rate_type, $base_rate);

// Use display name
$display_name = ($maker && $model) ? "$maker $model" : $make_model;

/* ==========================
   CALCULATE DOWNPAYMENT & BALANCE
========================== */
$total_cost = $promo_data['total_cost'];

// Policy: downpayment is 50% of BASE rental cost only (rate × duration)
$rate_amount = (float)($promo_data['applied_rate'] ?? 0);
if ($rate_amount <= 0) {
  $rate_amount = (float)$base_rate;
}
$base_amount = $rate_amount * (float)$rental_days;
$downpayment = round($base_amount * 0.50, 2);
$balance_due = round($total_cost - $downpayment, 2);

/* ==========================
   INSERT RENTAL
========================== */
$insert = $conn->prepare("
  INSERT INTO rentals (
    vehicle_id, customer_id, start_date, start_time, end_date, end_time,
    daily_rate, applied_rate, rate_type, 
    promo_applied, promo_discount, total_cost, 
    downpayment, balance_due, status, created_at
  ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
");
$insert->bind_param("iissssddssdddd", 
  $vehicle_id, $user_id, $start_date, $start_time, $end_date, $end_time, // User-selected pickup and return times
  $base_rate, $promo_data['applied_rate'], $rate_type,
  $promo_data['promo_applied'], $promo_data['promo_discount'], $total_cost,
  $downpayment, $balance_due
);
if (!$insert->execute()) {
  header('Content-Type: application/json');
  echo json_encode(['error' => true, 'message' => '❌ Booking failed. Please try again.']);
  exit;
}
$insert->close();

/* ==========================
   ADD NOTIFICATIONS
========================== */
// Booking notification
$message = "📝 Your booking for <b>" . htmlspecialchars($display_name) . "</b> has been submitted and is pending admin approval.";
require_once __DIR__ . '/notification_manager.php';
createNotificationIfNotExists($conn, $user_id, $vehicle_id, $message);

// Promotional notification
createPromoNotification($user_id, $vehicle_id, $promo_data);

/* ==========================
   SUCCESS RESPONSE
========================== */
$successMsg = "✅ Booking successful! Please wait for admin approval.";
if ($promo_data['promo_applied']) {
  $successMsg .= " You received a {$promo_data['discount_percent']}% discount!";
}
$successMsg .= " Downpayment: ₱" . number_format($downpayment, 2) . " | Balance: ₱" . number_format($balance_due, 2);

header('Content-Type: application/json');
echo json_encode([
  'success' => true, 
  'message' => $successMsg,
  'promo_data' => $promo_data,
  'downpayment' => $downpayment,
  'balance_due' => $balance_due
]);
exit;
?>
