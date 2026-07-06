<?php
/* =========================================================
   rentals_all.php — FleetGo Admin Panel (Focused Rentals)
   - Clean UI (dark + glass + gradient KPIs)
   - Status flow: pending → reserved → ongoing → completed/cancelled
   - Auto transition reserved→ongoing when today is in range
   - Safe Extend: cancels overlapping future reservations before changing end_date
   - Notifications still written (for System Logs page), but no bell UI here
   ========================================================= */

/* ---------- SESSION FIX ---------- */
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
session_name('fleetgo_session_admin');
session_start();

require_once __DIR__ . '/includes/db.php';

// Global error suppression for AJAX requests
if (isset($_POST['ajax_request']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest')) {
  error_reporting(0);
  ini_set('display_errors', 0);
  ini_set('log_errors', 1);
}

/* ===== AJAX: check return due notifications ===== */
if(isset($_GET['ajax']) && $_GET['ajax']==='check_return_due_notifications'){
  header('Content-Type: application/json');
  
  $notifications = [];
  
  // Check for overdue rentals (past due date)
  $overdue_query = "
    SELECT r.id, r.end_date, r.end_time, u.full_name as customer_name, v.make_model, v.plate_no
    FROM rentals r
    JOIN users u ON u.id = r.customer_id
    JOIN vehicles v ON v.id = r.vehicle_id
    WHERE r.status = 'ongoing'
    AND CONCAT(r.end_date, ' ', IFNULL(r.end_time, '18:00:00')) < NOW()
  ";
  $overdue_result = $conn->query($overdue_query);
  while($row = $overdue_result->fetch_assoc()) {
    $notifications[] = [
      'type' => 'overdue',
      'message' => "OVERDUE: Rental #{$row['id']} - {$row['customer_name']} ({$row['make_model']} - {$row['plate_no']}) was due on {$row['end_date']}",
      'rental_id' => $row['id'],
      'customer' => $row['customer_name'],
      'vehicle' => $row['make_model'],
      'plate' => $row['plate_no'],
      'due_date' => $row['end_date']
    ];
  }
  
  // Check for rentals due today (within 24 hours)
  $due_today_query = "
    SELECT r.id, r.end_date, r.end_time, u.full_name as customer_name, v.make_model, v.plate_no,
           TIMESTAMPDIFF(HOUR, NOW(), CONCAT(r.end_date, ' ', IFNULL(r.end_time, '18:00:00'))) as hours_until_due
    FROM rentals r
    JOIN users u ON u.id = r.customer_id
    JOIN vehicles v ON v.id = r.vehicle_id
    WHERE r.status = 'ongoing'
    AND CONCAT(r.end_date, ' ', IFNULL(r.end_time, '18:00:00')) BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 24 HOUR)
  ";
  $due_today_result = $conn->query($due_today_query);
  while($row = $due_today_result->fetch_assoc()) {
    $hours_until = (int)$row['hours_until_due'];
    $time_text = $hours_until <= 0 ? 'now' : "in {$hours_until} hour" . ($hours_until != 1 ? 's' : '');
    
    $notifications[] = [
      'type' => 'due_today',
      'message' => "DUE TODAY: Rental #{$row['id']} - {$row['customer_name']} ({$row['make_model']} - {$row['plate_no']}) due {$time_text}",
      'rental_id' => $row['id'],
      'customer' => $row['customer_name'],
      'vehicle' => $row['make_model'],
      'plate' => $row['plate_no'],
      'due_date' => $row['end_date'],
      'hours_until' => $hours_until
    ];
  }
  
  // Check for rentals due soon (within 72 hours but not within 24)
  $due_soon_query = "
    SELECT r.id, r.end_date, r.end_time, u.full_name as customer_name, v.make_model, v.plate_no,
           TIMESTAMPDIFF(HOUR, NOW(), CONCAT(r.end_date, ' ', IFNULL(r.end_time, '18:00:00'))) as hours_until_due
    FROM rentals r
    JOIN users u ON u.id = r.customer_id
    JOIN vehicles v ON v.id = r.vehicle_id
    WHERE r.status = 'ongoing'
    AND CONCAT(r.end_date, ' ', IFNULL(r.end_time, '18:00:00')) BETWEEN DATE_ADD(NOW(), INTERVAL 24 HOUR) AND DATE_ADD(NOW(), INTERVAL 72 HOUR)
  ";
  $due_soon_result = $conn->query($due_soon_query);
  while($row = $due_soon_result->fetch_assoc()) {
    $days_until = ceil((int)$row['hours_until_due'] / 24);
    
    $notifications[] = [
      'type' => 'due_soon',
      'message' => "DUE SOON: Rental #{$row['id']} - {$row['customer_name']} ({$row['make_model']} - {$row['plate_no']}) due in {$days_until} day" . ($days_until != 1 ? 's' : ''),
      'rental_id' => $row['id'],
      'customer' => $row['customer_name'],
      'vehicle' => $row['make_model'],
      'plate' => $row['plate_no'],
      'due_date' => $row['end_date'],
      'days_until' => $days_until
    ];
  }
  
  echo json_encode([
    'success' => true,
    'notifications' => $notifications,
    'count' => count($notifications)
  ]);
  exit;
}

/* ===== AJAX: get return summary for return modal (after auth check) ===== */
if(isset($_GET['ajax']) && $_GET['ajax']==='get_return_summary'){
  error_reporting(0);
  ini_set('display_errors', 0);
  if (ob_get_level()) {
    ob_clean();
  }

  $rental_id = intval($_GET['rental_id']);
  
  // Get rental, payment summary, and vehicle info
  $stmt = $conn->prepare("
      SELECT r.*, v.current_odometer, v.fuel_type, 
             COALESCE(ps.paid_amount,0) as paid_amount,
             r.base_amount, r.start_date, r.end_date, r.start_time, r.end_time,
             v.current_status as vehicle_status
      FROM rentals r
      LEFT JOIN vehicles v ON r.vehicle_id = v.id
      LEFT JOIN vw_rental_payment_summary ps ON r.id = ps.rental_id
      WHERE r.id = ?
  ");
  $stmt->bind_param("i", $rental_id);
  $stmt->execute();
  $rental = $stmt->get_result()->fetch_assoc();
  
  if(!$rental){
      echo json_encode(['error'=>'Rental not found']);
      exit;
  }
  
  // Get washing types and fuel rates for preview
  $wash_types = [];
  $wash_res = $conn->query("SELECT id, type, cost FROM washing_types ORDER BY type");
  while($row = $wash_res->fetch_assoc()){
      $wash_types[] = $row;
  }
  
  $fuel_rates = [];
  $fuel_res = $conn->query("SELECT fuel_type, rate_per_liter FROM fuel_rates ORDER BY fuel_type");
  while($row = $fuel_res->fetch_assoc()){
      $fuel_rates[$row['fuel_type']] = $row['rate_per_liter'];
  }
  
  echo json_encode([
      'start_odometer' => $rental['start_odometer'],
      'return_odometer' => $rental['return_odometer'],
      'paid_amount' => floatval($rental['paid_amount']),
      'base_amount' => floatval($rental['base_amount']),
      'start_date' => $rental['start_date'],
      'end_date' => $rental['end_date'],
      'start_time' => $rental['start_time'],
      'end_time' => $rental['end_time'],
      'fuel_type' => $rental['fuel_type'],
      'vehicle_status' => $rental['vehicle_status'],
      'washing_types' => $wash_types,
      'fuel_rates' => $fuel_rates
  ]);
  exit;
}

/* --- Access Control --- */
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    // For AJAX requests, return JSON error instead of redirect
    if(isset($_GET['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Authentication required']);
        exit;
    }
    header("Location: login.php");
    exit;
}

/* ===== AJAX: get washing types for return modal (after auth check) ===== */
if(isset($_GET['ajax']) && $_GET['ajax']==='get_washing_types'){
  // Disable error display for AJAX requests
  error_reporting(0);
  ini_set('display_errors', 0);
  
  // Clear any existing output buffer
  if (ob_get_level()) {
    ob_clean();
  }
  
  $vehicle_type = $_GET['vehicle_type'] ?? '';
  
  try {
    if($vehicle_type && $vehicle_type !== 'all') {
      // Get washing types for specific vehicle type
      $stmt = $conn->prepare("SELECT id, washing_name, washing_rate, vehicle_type FROM washing_types WHERE vehicle_type = ? ORDER BY washing_rate ASC");
      $stmt->bind_param("s", $vehicle_type);
      $stmt->execute();
      $result = $stmt->get_result();
      $washing_types = $result->fetch_all(MYSQLI_ASSOC);
    } else {
      // Get all washing types if no specific type or 'all' requested
      $result = $conn->query("SELECT id, washing_name, washing_rate, vehicle_type FROM washing_types ORDER BY vehicle_type, washing_rate ASC");
      $washing_types = $result->fetch_all(MYSQLI_ASSOC);
    }
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'washing_types' => $washing_types]);
    exit;
  } catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Failed to fetch washing types: ' . $e->getMessage()]);
    exit;
  }
}

/* ===== AJAX: get fuel rates for return modal (after auth check) ===== */
if(isset($_GET['ajax']) && $_GET['ajax']==='get_fuel_rates'){
  // Disable error display for AJAX requests
  error_reporting(0);
  ini_set('display_errors', 0);
  
  // Clear any existing output buffer
  if (ob_get_level()) {
    ob_clean();
  }
  
  $vehicle_type = $_GET['vehicle_type'] ?? '';
  
  try {
    if($vehicle_type) {
      // Get fuel rates for specific vehicle type
      $stmt = $conn->prepare("SELECT * FROM fuel_charge_rates WHERE vehicle_type = ? LIMIT 1");
      $stmt->bind_param("s", $vehicle_type);
      $stmt->execute();
      $result = $stmt->get_result();
      $fuel_rates = $result->fetch_assoc();
      
      if ($fuel_rates) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'fuel_rates' => $fuel_rates]);
      } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'No fuel rates found for vehicle type: ' . $vehicle_type]);
      }
    } else {
      header('Content-Type: application/json');
      echo json_encode(['success' => false, 'error' => 'Vehicle type required']);
    }
    exit;
  } catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Failed to fetch fuel rates: ' . $e->getMessage()]);
    exit;
  }
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Disable error display for AJAX requests to prevent HTML output
if(isset($_GET['ajax'])) {
  error_reporting(0);
  ini_set('display_errors', 0);
  ini_set('log_errors', 1);
  
  // Clear any existing output buffer
  if (ob_get_level()) {
    ob_clean();
  }
}

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/**
 * Calculate payment status based on rental balance and additional charges
 * @param float $original_balance Original balance due before return
 * @param float $final_cost Final amount due after return inspection
 * @param float $additional_charges Total additional charges (late fees, fuel, washing, etc.)
 * @return string Payment status with clear explanation
 */
function calculatePaymentStatus($original_balance, $final_cost, $additional_charges) {
    // If no additional charges and final cost is zero or negative
    if ($additional_charges <= 0 && $final_cost <= 0) {
        return 'Fully Paid - No Additional Charges';
    }
    
    // If there are additional charges but final cost is still zero or negative
    if ($additional_charges > 0 && $final_cost <= 0) {
        return 'Fully Paid - Additional Charges Waived';
    }
    
    // If final cost is less than or equal to original balance
    if ($final_cost <= $original_balance) {
        $remaining = $original_balance - $final_cost;
        if ($remaining > 0) {
            return "Balance Remaining - ₱" . number_format($remaining, 2) . " credit";
        } else {
            return 'Fully Paid - Original Balance Covered';
        }
    }
    
    // If final cost exceeds original balance
    $additional_due = $final_cost - $original_balance;
    return "Additional Charges Due - ₱" . number_format($additional_due, 2) . " extra";
}

$today = date('Y-m-d');

/* -------- Auto-transition (reserved -> ongoing when date hits) -------- */
// DISABLED: Auto-transition removed to prevent automatic completion on page refresh
// $conn->query("
//   UPDATE rentals
//   SET status='ongoing'
//   WHERE status='reserved' AND start_date <= CURDATE() AND end_date >= CURDATE()
// ");

/* -------- Helpers -------- */
function fetchRentals($c){
  $sql="SELECT r.*, v.make_model, v.plate_no, v.vehicle_type, u.full_name,
               COALESCE(ps.paid_amount, 0) AS paid_amount
        FROM rentals r
        JOIN vehicles v ON v.id=r.vehicle_id
        JOIN users u ON u.id=r.customer_id
        LEFT JOIN vw_rental_payment_summary ps ON ps.rental_id = r.id
        ORDER BY r.start_date DESC, r.id DESC";
  return $c->query($sql)->fetch_all(MYSQLI_ASSOC);
}

function fetchCurrentRentalsFromView($c){
  $sql="SELECT * FROM view_current_rentals ORDER BY start_date DESC, rental_id DESC";
  return $c->query($sql)->fetch_all(MYSQLI_ASSOC);
}

function fetchAvailableVehiclesFromView($c){
  // SUBQUERY: Multi-row subquery to filter available vehicles by excluding booked ones
  $sql="SELECT * FROM vehicles 
        WHERE current_status = 'available' 
        AND id NOT IN ( -- SUBQUERY: Exclude vehicles that are currently rented
            SELECT vehicle_id FROM rentals 
            WHERE status IN ('approved', 'ongoing', 'reserved') 
            AND CURDATE() BETWEEN start_date AND end_date
        )
        ORDER BY make_model ASC";
  return $c->query($sql)->fetch_all(MYSQLI_ASSOC);
}
function hnum($n,$d=2){ return number_format((float)$n,$d); }

/* KPI quick queries - using views for current data */
$kpi_pending = (int)($conn->query("SELECT COUNT(*) c FROM rentals WHERE status='pending'")->fetch_assoc()['c'] ?? 0);
$kpi_active = (int)($conn->query("SELECT COUNT(*) c FROM view_current_rentals WHERE status='ongoing'")->fetch_assoc()['c'] ?? 0);
$kpi_advance = (int)($conn->query("SELECT COUNT(*) c FROM view_current_rentals WHERE status='reserved' AND start_date>CURDATE()")->fetch_assoc()['c'] ?? 0);
$kpi_due_today = (int)($conn->query("SELECT COUNT(*) c FROM view_current_rentals WHERE status='ongoing' AND end_date=CURDATE()")->fetch_assoc()['c'] ?? 0);
$kpi_available = (int)($conn->query("SELECT COUNT(*) c FROM vehicles v\n  WHERE v.current_status = 'available'\n    AND v.id NOT IN (\n      SELECT r.vehicle_id\n      FROM rentals r\n      WHERE r.status IN ('approved', 'ongoing', 'reserved')\n        AND CURDATE() BETWEEN r.start_date AND r.end_date\n    )")->fetch_assoc()['c'] ?? 0);
$kpi_cancel_mo = (int)($conn->query("SELECT COUNT(*) c FROM rentals WHERE status='cancelled' AND DATE_FORMAT(start_date,'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m')")->fetch_assoc()['c'] ?? 0);

/* Analytics Data */
// Top rented vehicle this month
// SUBQUERY: Multi-row subquery for vehicle analytics with nested filtering
$top_vehicle_result = $conn->query("
  SELECT v.make_model, COUNT(r.id) as rental_count 
  FROM vehicles v
  JOIN rentals r ON v.id = r.vehicle_id 
  WHERE DATE_FORMAT(r.start_date,'%Y-%m') = DATE_FORMAT(CURDATE(),'%Y-%m')
  AND r.status IN ('ongoing', 'completed', 'reserved')
  AND v.id IN ( -- SUBQUERY: Filter vehicles that have rentals this month
      SELECT vehicle_id FROM rentals 
      WHERE DATE_FORMAT(start_date,'%Y-%m') = DATE_FORMAT(CURDATE(),'%Y-%m')
      AND status IN ('ongoing', 'completed', 'reserved')
      GROUP BY vehicle_id 
      HAVING COUNT(*) > 0
  )
  GROUP BY v.id, v.make_model 
  ORDER BY rental_count DESC 
  LIMIT 1
");
// If there are no rentals this month, fetch_assoc() will return null.
// Normalize to a default array to avoid PHP notices when rendering the analytics card.
if ($top_vehicle_result) {
  $top_vehicle = $top_vehicle_result->fetch_assoc();
  if (!$top_vehicle) {
    $top_vehicle = ['make_model' => 'No data', 'rental_count' => 0];
  }
} else {
  $top_vehicle = ['make_model' => 'No data', 'rental_count' => 0];
}

// Average rental duration this month
$avg_duration_result = $conn->query("
  SELECT AVG(DATEDIFF(end_date, start_date)) as avg_days
  FROM rentals 
  WHERE DATE_FORMAT(start_date,'%Y-%m') = DATE_FORMAT(CURDATE(),'%Y-%m')
  AND status IN ('completed', 'ongoing')
");
$avg_duration = $avg_duration_result ? $avg_duration_result->fetch_assoc()['avg_days'] : 0;

// Monthly revenue
$monthly_revenue_result = $conn->query("
  SELECT SUM(daily_rate * DATEDIFF(end_date, start_date)) as total_revenue
  FROM rentals 
  WHERE DATE_FORMAT(start_date,'%Y-%m') = DATE_FORMAT(CURDATE(),'%Y-%m')
  AND status IN ('completed', 'ongoing')
");
$monthly_revenue = $monthly_revenue_result ? $monthly_revenue_result->fetch_assoc()['total_revenue'] : 0;

// Weekly trends
$pending_this_week = (int)($conn->query("SELECT COUNT(*) c FROM rentals WHERE status='pending' AND start_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)")->fetch_assoc()['c'] ?? 0);
$active_this_week = (int)($conn->query("SELECT COUNT(*) c FROM rentals WHERE status='ongoing' AND start_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)")->fetch_assoc()['c'] ?? 0);
$overdue_count = (int)($conn->query("SELECT COUNT(*) c FROM rentals WHERE status='ongoing' AND end_date < CURDATE()")->fetch_assoc()['c'] ?? 0);

/* -------- Status change & Extend (POST) -------- */
if($_SERVER['REQUEST_METHOD']==='POST'){
  /* -- Approve/Start/Complete/Cancel -- */
  if(isset($_POST['rental_id'],$_POST['vehicle_id'],$_POST['new_status']) && !isset($_POST['new_end'])){
    $rID=(int)$_POST['rental_id']; $vID=(int)$_POST['vehicle_id']; $ns=strtolower(trim($_POST['new_status']));
    $r=$conn->query("SELECT * FROM rentals WHERE id=$rID")->fetch_assoc();
    if(!$r){ exit("Rental not found."); }
    $cust=(int)$r['customer_id'];

    // Payment policy gate: only allow approval/start if downpayment requirement is met
    if (in_array($ns, ['ongoing','reserved'], true)) {
      $required_down = (float)($r['downpayment'] ?? 0);
      $paid_row = $conn->query("SELECT paid_amount FROM vw_rental_payment_summary WHERE rental_id = $rID")->fetch_assoc();
      $paid_amount = (float)($paid_row['paid_amount'] ?? 0);

      if ($paid_amount + 0.00001 < $required_down) {
        $msg = rawurlencode('Downpayment not met. Required: ₱' . number_format($required_down, 2) . ' | Paid: ₱' . number_format($paid_amount, 2));
        header("Location: rentals_all.php?error=downpayment_not_met&msg=$msg");
        exit;
      }
    }

    // Smart status logic: 
    // - If admin approves and start_date is today or past → 'ongoing'
    // - If admin approves and start_date is future → 'reserved'
    if($ns==='ongoing'){
      if(strtotime($r['start_date']) > strtotime($today)){
        $ns='reserved'; // Future booking should be reserved
      }
      // If start_date is today or past, keep as 'ongoing'
    }

    $stmt=$conn->prepare("UPDATE rentals SET status=? WHERE id=?");
    $stmt->bind_param("si",$ns,$rID);
    $stmt->execute();
    $stmt->close();

    // reflect to vehicles.current_status
    if($ns==='ongoing'){ $vehStat='rented'; }
    elseif(in_array($ns,['completed','cancelled'])){
      $next=$conn->query("SELECT id FROM rentals WHERE vehicle_id=$vID AND status='reserved' AND start_date>CURDATE() ORDER BY start_date ASC LIMIT 1")->fetch_assoc();
      $vehStat = $next ? 'reserved' : 'available';
      
      // Log vehicle availability change when completed/cancelled
      if($vehStat === 'available'){
        $veh = $conn->query("SELECT make_model, plate_no FROM vehicles WHERE id=$vID")->fetch_assoc();
        $model = $veh['make_model'] ?? 'Vehicle';
        $plate = $veh['plate_no'] ?? '';
        $statusMsg = "Vehicle $model (Plate: $plate) is now available for new bookings";
        require_once __DIR__ . '/includes/notification_manager.php';
        createNotificationIfNotExists($conn, $cust, $vID, $statusMsg);
      }
    } elseif($ns==='reserved'){ $vehStat='reserved'; }
    else { $vehStat='available'; }
    $conn->query("UPDATE vehicles SET current_status='$vehStat' WHERE id=$vID");

    // write notification for System Logs page (kept; no bell UI here)
    $veh=$conn->query("SELECT make_model FROM vehicles WHERE id=$vID")->fetch_assoc();
    $model=$veh['make_model'] ?? 'Vehicle';
    $modelEsc = $conn->real_escape_string($model);
    $msg="Booking for <b>$modelEsc</b> updated to <b>$ns</b>.";
    require_once __DIR__ . '/includes/notification_manager.php';
    createNotificationIfNotExists($conn, $cust, $vID, $msg);

    header("Location: rentals_all.php?ok=1"); exit;
  }

  /* -- Clear receipt data -- */
  if(isset($_GET['clear_receipt']) && $_GET['clear_receipt'] == '1'){
    unset($_SESSION['return_receipt_data']);
    exit('OK');
  }


  /* -- Debug AJAX detection -- */
  if(isset($_POST['debug_ajax'])){
    // Clear any output buffers
    while (ob_get_level()) {
      ob_end_clean();
    }
    
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    
    echo json_encode([
      'ajax_header' => isset($_SERVER['HTTP_X_REQUESTED_WITH']) ? $_SERVER['HTTP_X_REQUESTED_WITH'] : 'not_set',
      'ajax_param' => isset($_POST['ajax_request']) ? $_POST['ajax_request'] : 'not_set',
      'is_ajax' => (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') || isset($_POST['ajax_request']),
      'post_data' => $_POST,
      'headers' => getallheaders()
    ]);
    exit;
  }

  /* -- Test return form processing -- */
  if(isset($_POST['test_return'])){
    error_log("=== TEST RETURN REQUEST ===");
    error_log("POST data: " . print_r($_POST, true));
    
    // Clear any output buffers
    while (ob_get_level()) {
      ob_end_clean();
    }
    
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    
    echo json_encode([
      'success' => true,
      'message' => 'Test return processing successful',
      'test_data' => $_POST
    ]);
    exit;
  }

  // Helper functions for AJAX responses (defined globally to avoid scope issues)
  function sendAjaxResponse($success, $message, $redirect = null) {
    // Clear ALL output buffers multiple times to be sure
    while (ob_get_level()) { 
      ob_end_clean(); 
    }
    
    // Clear any existing output
    if (ob_get_level() > 0) {
      ob_clean();
    }
    
    // Set headers
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    
    $response = ['success' => $success, 'message' => $message];
    if ($redirect) $response['redirect'] = $redirect;
    
    // Log what we're about to send
    error_log("Sending AJAX response: " . json_encode($response));
    
    echo json_encode($response);
    exit;
  }

  function handleError($message, $errorParam = null) {
    $isAjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') 
              || isset($_POST['ajax_request']);
    
    if($isAjax) {
      sendAjaxResponse(false, $message);
    } else {
      $url = "rentals_all.php?error=" . ($errorParam ?: 'return_failed');
      if ($errorParam) $url .= "&msg=" . urlencode($message);
      header("Location: $url");
      exit;
    }
  }

  /* -- Return inspection handler -- */
  if(($_POST['form_type']??'')==='return_inspection'){
    // Log the request details
    error_log("=== RETURN INSPECTION REQUEST ===");
    error_log("AJAX Request: " . (isset($_POST['ajax_request']) ? 'YES' : 'NO'));
    error_log("X-Requested-With: " . ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? 'NOT SET'));
    error_log("Request Method: " . $_SERVER['REQUEST_METHOD']);
    
    // Process both normal form submits and AJAX submits
    
    // Additional check: ensure we have a valid rental ID and it's not a duplicate submission
    $rID = (int)($_POST['rental_id']??0);
    if (!$rID) {
      if (isset($_POST['ajax_request']) || isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        sendAjaxResponse(false, 'Invalid rental ID');
      } else {
        header("Location: rentals_all.php?error=invalid_rental");
        exit;
      }
    }
    
    // Start output buffering and clear any existing output
    while (ob_get_level()) {
      ob_end_clean();
    }
    ob_start();
    
    // Suppress any potential output
    ob_implicit_flush(false);
    
    error_log("=== RETURN INSPECTION STARTED ===");
    error_log("POST data received: " . print_r($_POST, true));
    $actual_return_date = $_POST['actual_return_date'] ?? date('Y-m-d');
    $actual_return_time = $_POST['actual_return_time'] ?? date('H:i:s');
    $return_condition = $_POST['return_condition'] ?? 'Good';
    $odometer_return = (int)($_POST['odometer_return']??0);
    $fuel_level = $_POST['fuel_level'] ?? 'full';
    $cleanliness = $_POST['cleanliness'] ?? 'clean';
    $damage_report = trim($_POST['damage_report']??'');
    $damage_fee = (float)($_POST['damage_fee']??0);
    $additional_notes = trim($_POST['additional_notes']??'');
    $washing_id = (int)($_POST['washing_type']??0);
    $washing_cost = (float)($_POST['washing_cost']??0);
    
    error_log("Return data - Rental ID: $rID, Washing ID: $washing_id, Washing Cost: $washing_cost");

    // Debug logging
    error_log("Return inspection - Rental ID: $rID, Odometer: $odometer_return");
    error_log("Return inspection - Fuel: $fuel_level, Cleanliness: $cleanliness");
    error_log("Return inspection - Damage: '$damage_report', Washing: $washing_cost, Damage Fee: $damage_fee");
    error_log("Return inspection - Additional Notes: '$additional_notes'");

    if(!$rID || !$odometer_return) {
      error_log("Return inspection failed - Missing data: rID=$rID, odometer=$odometer_return");
      handleError('Missing required data', 'missing_data');
    }
    
    error_log("Basic validation passed, proceeding with return process...");

    // Validate that the rental exists and is in the correct status
    $validate_stmt = $conn->prepare("SELECT id, status, vehicle_id FROM rentals WHERE id = ?");
    $validate_stmt->bind_param("i", $rID);
    $validate_stmt->execute();
    $rental_check = $validate_stmt->get_result()->fetch_assoc();
    $validate_stmt->close();
    
    if(!$rental_check) {
      error_log("Return inspection failed - Rental $rID not found");
      handleError('Rental not found', 'rental_not_found');
    }
    
    if(!in_array($rental_check['status'], ['ongoing', 'reserved'])) {
      error_log("Return inspection failed - Rental $rID status is '{$rental_check['status']}', expected 'ongoing' or 'reserved'");
      handleError('Invalid rental status: ' . $rental_check['status'], 'invalid_rental_status');
    }

    // Check if return already exists
    $existing_return = $conn->query("SELECT id FROM rental_returns WHERE rental_id = $rID")->fetch_assoc();
    if($existing_return) {
      error_log("Return inspection failed - Rental $rID already has a return record");
      handleError('Rental already returned', 'already_returned');
    }
    
    // Check if rental status is already completed (prevent duplicate processing)
    $rental_status_check = $conn->query("SELECT status FROM rentals WHERE id = $rID")->fetch_assoc();
    if($rental_status_check && $rental_status_check['status'] === 'completed') {
      error_log("Return inspection failed - Rental $rID is already completed");
      handleError('Rental is already completed', 'already_completed');
    }

    $conn->begin_transaction();
    try {
      // Get rental details
      $rental_stmt = $conn->prepare("
        SELECT r.*, v.make_model, v.plate_no, v.current_odometer as current_odometer, u.full_name as customer_name
        FROM rentals r 
        JOIN vehicles v ON v.id = r.vehicle_id 
        JOIN users u ON u.id = r.customer_id
        WHERE r.id = ?
      ");
      $rental_stmt->bind_param("i", $rID);
      $rental_stmt->execute();
      $rental = $rental_stmt->get_result()->fetch_assoc();
      $rental_stmt->close();

      if(!$rental) {
        throw new Exception("Rental not found");
      }

      // Odometer policy: validate only (distance/current_odometer handled by triggers)
      $start_odometer = $rental['start_odometer'];
      if ($start_odometer !== null) {
        $start_odometer = (float)$start_odometer;
        $return_odometer = (float)$odometer_return;
        if ($return_odometer < $start_odometer) {
          throw new Exception('Return odometer must be greater than or equal to start odometer');
        }
      }

      // Prevent double execution: if return_odometer already recorded, block
      if (!empty($rental['return_odometer'])) {
        throw new Exception('Return odometer already recorded');
      }

      // Enhanced rate calculation based on return condition
      $penalty_amount = 0;
      $fuel_penalty = 0;
      $condition_adjustment = 0;
      
      // 1. Calculate late return penalty using the FleetGo policy (matching myrentals.php logic)
      $expected_end = $rental['end_date'] . ' ' . ($rental['end_time'] ?? '18:00:00'); // Use 6 PM default like myrentals.php
      $actual_end = $actual_return_date . ' ' . $actual_return_time;
      
      if(strtotime($actual_end) > strtotime($expected_end)) {
        // FleetGo Late Fee Policy: (Daily Rate ÷ 24) × Hours Late × 1.25
        $daily_rate = (float)$rental['daily_rate'];
        $hours_late = (strtotime($actual_end) - strtotime($expected_end)) / 3600;
        $hourly_rate = $daily_rate / 24;
        $penalty_amount = round($hourly_rate * $hours_late * 1.25, 2);
        
        error_log("Late return calculation: Daily rate=$daily_rate, Hours late=$hours_late, Late fee=$penalty_amount");
      }
      
      // 2. Calculate fuel penalty based on fuel level and vehicle type (database-driven)
      $fuel_penalty = 0;
      if ($fuel_level !== 'full') {
        // Get vehicle type for fuel rate lookup
        $vehicle_stmt = $conn->prepare("SELECT vehicle_type FROM vehicles WHERE id = ?");
        $vehicle_stmt->bind_param("i", $rental['vehicle_id']);
        $vehicle_stmt->execute();
        $vehicle_type = $vehicle_stmt->get_result()->fetch_assoc()['vehicle_type'] ?? '';
        $vehicle_stmt->close();
        
        if ($vehicle_type) {
          // Get fuel rates for this vehicle type
          $fuel_stmt = $conn->prepare("SELECT * FROM fuel_charge_rates WHERE vehicle_type = ? LIMIT 1");
          $fuel_stmt->bind_param("s", $vehicle_type);
          $fuel_stmt->execute();
          $fuel_rates = $fuel_stmt->get_result()->fetch_assoc();
          $fuel_stmt->close();
          
          if ($fuel_rates) {
            switch($fuel_level) {
              case 'empty':
                $fuel_penalty = (float)($fuel_rates['empty_rate'] ?? 0);
                break;
              case '1/4':
                $fuel_penalty = (float)($fuel_rates['quarter_rate'] ?? 0);
                break;
              case 'half':
                $fuel_penalty = (float)($fuel_rates['half_rate'] ?? 0);
                break;
              case '3/4':
                $fuel_penalty = (float)($fuel_rates['three_quarter_rate'] ?? 0);
                break;
              default:
                $fuel_penalty = 0;
                break;
            }
          }
        }
      }
      
      // 3. Calculate condition-based adjustments
      switch($return_condition) {
        case 'Poor':
          $condition_adjustment = $daily_rate * 0.5; // 50% of daily rate for poor condition
          break;
        case 'Fair':
          $condition_adjustment = $daily_rate * 0.2; // 20% of daily rate for fair condition
          break;
        case 'Good':
        case 'Excellent':
        default:
          $condition_adjustment = 0; // No adjustment for good/excellent condition
          break;
      }
      
      // 4. Cleanliness-based logic (now handled by washing dropdown)
      // The washing cost is automatically calculated from the dropdown selection
      
      // 5. Calculate total additional charges (including washing cost)
      // Safety policy: recompute from base_amount + late_fee + washing_fee + damage_fee
      // Do NOT increment existing totals and do NOT include other dynamic charges here.
      $total_additional_charges = $penalty_amount + $washing_cost + $damage_fee;
      
      // 6. Fuel charge will be calculated automatically by trigger
      $fuel_charge = 0; // Will be set by trigger if fuel_level != 'Full'

      // 7. Recompute base amount and new total from scratch
      $rate_amount = (float)($rental['applied_rate'] ?? 0);
      if ($rate_amount <= 0) {
        $rate_amount = (float)($rental['daily_rate'] ?? 0);
      }

      $start_dt = strtotime(($rental['start_date'] ?? '') . ' ' . ($rental['start_time'] ?? '00:00:00'));
      $end_dt = strtotime(($rental['end_date'] ?? '') . ' ' . ($rental['end_time'] ?? '00:00:00'));
      $duration_days = 1;
      if ($start_dt && $end_dt && $end_dt > $start_dt) {
        $duration_days = max(1, round(($end_dt - $start_dt) / 86400, 1));
      }

      $base_amount = $rate_amount * (float)$duration_days;
      $new_total = $base_amount + $total_additional_charges;

      // Paid amount from payment summary view (POSTED only)
      $paid_row = $conn->query("SELECT paid_amount FROM vw_rental_payment_summary WHERE rental_id = $rID")->fetch_assoc();
      $paid_amount = (float)($paid_row['paid_amount'] ?? 0);

      $balance_due = $new_total - $paid_amount;
      if ($balance_due < 0) { $balance_due = 0; }

      error_log("Payment check - Rental $rID: base_amount=$base_amount, additional_charges=$total_additional_charges, new_total=$new_total, paid_amount=$paid_amount, balance_due=$balance_due");

      // Insert return inspection record with automatic fuel charge
      $carwash_fee = 0; // Set to 0 since washing is now handled separately
      $inspection_stmt = $conn->prepare("
        INSERT INTO return_inspections (
          rental_id, fuel_level, cleanliness, damage_report, 
          carwash_fee, fuel_charge, damage_fee, additional_notes, penalty_amount, final_cost
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
      ");
      if (!$inspection_stmt) {
        throw new Exception("Failed to prepare return_inspections insert: " . $conn->error);
      }
      $inspection_stmt->bind_param("isssdddsdd", 
        $rID, $fuel_level, $cleanliness, $damage_report, 
        $carwash_fee, $fuel_charge, $damage_fee, $additional_notes, $penalty_amount, $new_total
      );
      if (!$inspection_stmt->execute()) {
        throw new Exception("Failed to insert return_inspections: " . $inspection_stmt->error);
      }
      $inspection_stmt->close();

      // Insert into rental_returns table with all return data
      // Use a simpler approach to avoid trigger conflicts
      $return_stmt = $conn->prepare("
        INSERT INTO rental_returns (
          rental_id, actual_return_date, actual_return_time, return_condition,
          penalty_amount, final_cost, odometer_return, fuel_level, issues
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
      ");
      if (!$return_stmt) {
        throw new Exception("Failed to prepare rental_returns insert: " . $conn->error);
      }
      $return_stmt->bind_param("isssddiss", 
        $rID, $actual_return_date, $actual_return_time, $return_condition,
        $penalty_amount, $new_total, $odometer_return, $fuel_level, $damage_report
      );
      if (!$return_stmt->execute()) {
        // If insert fails due to duplicate, try update instead
        $return_stmt->close();
        $update_stmt = $conn->prepare("
          UPDATE rental_returns SET 
            actual_return_date = ?, actual_return_time = ?, return_condition = ?,
            penalty_amount = ?, final_cost = ?, odometer_return = ?, fuel_level = ?, issues = ?
          WHERE rental_id = ?
        ");
        if ($update_stmt) {
          $update_stmt->bind_param("sssddissi", 
            $actual_return_date, $actual_return_time, $return_condition,
            $penalty_amount, $new_total, $odometer_return, $fuel_level, $damage_report, $rID
          );
          if (!$update_stmt->execute()) {
            throw new Exception("Failed to update rental_returns: " . $update_stmt->error);
          }
          $update_stmt->close();
        } else {
          throw new Exception("Failed to prepare rental_returns update: " . $conn->error);
        }
      } else {
        $return_stmt->close();
      }

      // If balance remains, do NOT complete the rental and do NOT change vehicle status
      if ($balance_due > 0) {
        error_log("Return processed but final payment required. Rental $rID new_total=$new_total paid=$paid_amount balance_due=$balance_due");

        // A trigger may set status='completed' on return_inspections insert; revert it safely.
        $orig_status = $rental_check['status'] ?? ($rental['status'] ?? 'ongoing');
        $stmt = $conn->prepare("UPDATE rentals SET status=?, balance_due=?, washing_id=?, washing_cost=? WHERE id=?");
        if (!$stmt) {
          throw new Exception("Failed to prepare rental update: " . $conn->error);
        }
        $stmt->bind_param("sdidi", $orig_status, $balance_due, $washing_id, $washing_cost, $rID);
        if (!$stmt->execute()) {
          throw new Exception("Failed to execute rental update: " . $stmt->error);
        }
        $stmt->close();

        $conn->commit();

        // Check if this is an AJAX request
        $isAjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') 
                  || isset($_POST['ajax_request']);
        
        if($isAjax) {
          // AJAX request - return JSON error
          sendAjaxResponse(false, 'Final payment required before completion.');
        } else {
          // Regular form submission - redirect with error
          while (ob_get_level()) {
            ob_end_clean();
          }
          header("Location: rentals_all.php?error=payment_required&rental_id=".$rID);
          exit;
        }
      }

      // Fully paid: complete rental and make vehicle available
      error_log("Completing rental $rID. new_total=$new_total paid=$paid_amount");
      $stmt = $conn->prepare("UPDATE rentals SET status='completed', total_cost=?, balance_due=0, washing_id=?, washing_cost=? WHERE id=?");
      if (!$stmt) {
        throw new Exception("Failed to prepare rental completion update: " . $conn->error);
      }
      $stmt->bind_param("didi", $new_total, $washing_id, $washing_cost, $rID);
      if (!$stmt->execute()) {
        throw new Exception("Failed to execute rental completion update: " . $stmt->error);
      }
      $affected_rows = $stmt->affected_rows;
      $stmt->close();
      
      error_log("Rental update affected $affected_rows rows");
      
      // Debug: Check if the update worked
      $check_stmt = $conn->prepare("SELECT status FROM rentals WHERE id=?");
      $check_stmt->bind_param("i", $rID);
      $check_stmt->execute();
      $result = $check_stmt->get_result()->fetch_assoc();
      $check_stmt->close();
      
      error_log("Rental $rID status after update: " . ($result['status'] ?? 'NULL'));
      
      if($result['status'] !== 'completed') {
        throw new Exception("Failed to update rental status to completed. Current status: " . ($result['status'] ?? 'NULL'));
      }

      // Save return_odometer; triggers will compute distance_traveled and update vehicles.current_odometer
      $stmt = $conn->prepare("UPDATE rentals SET return_odometer=? WHERE id=? AND return_odometer IS NULL");
      $stmt->bind_param("di", $odometer_return, $rID);
      $stmt->execute();
      $stmt->close();

      // Auto-schedule carwash maintenance if very dirty
      if($cleanliness === 'very_dirty') {
        try {
          // Include the smart maintenance scheduling function
          if (!function_exists('scheduleSmartMaintenance')) {
            require_once __DIR__ . '/includes/maintenance_functions.php';
          }
          
          error_log("Attempting to auto-schedule carwash for vehicle {$rental['vehicle_id']}");
          
          // Use the smart scheduling function
          $result = scheduleSmartMaintenance($conn, $rental['vehicle_id'], 'Carwash', 'very_dirty', 'Auto-scheduled after very dirty return');
          
          if($result['success']) {
            error_log("Auto-scheduled carwash for vehicle {$rental['vehicle_id']}: Total ₱{$result['total_estimated_cost']} (Washing: ₱{$result['washing_cost']}, ID: {$result['maintenance_id']})");
          } else {
            error_log("Failed to auto-schedule carwash for vehicle {$rental['vehicle_id']}: {$result['message']}");
          }
        } catch (Exception $e) {
          error_log("Error in auto-scheduling carwash for vehicle {$rental['vehicle_id']}: " . $e->getMessage());
          error_log("Stack trace: " . $e->getTraceAsString());
          // Don't let maintenance scheduling errors break the return process
        }
      }

      // Update vehicle status to available (per policy)
      $vehUpdate = $conn->query("UPDATE vehicles SET current_status='available' WHERE id={$rental['vehicle_id']}");
      error_log("Vehicle status update result: " . ($vehUpdate ? 'success' : 'failed'));
      
      // Check final vehicle status
      $vehCheck = $conn->query("SELECT current_status FROM vehicles WHERE id={$rental['vehicle_id']}")->fetch_assoc();
      error_log("Vehicle {$rental['vehicle_id']} final status: " . ($vehCheck['current_status'] ?? 'NULL'));
      
      // Log the vehicle status change for system tracking
      $statusMsg = "Vehicle {$rental['make_model']} (Plate: {$rental['plate_no']}) returned and marked as available";
      require_once __DIR__ . '/includes/notification_manager.php';
      createNotificationIfNotExists($conn, $rental['customer_id'], $rental['vehicle_id'], $statusMsg);

      // Send notification
      $veh = $conn->query("SELECT make_model FROM vehicles WHERE id={$rental['vehicle_id']}")->fetch_assoc();
      $model = $veh['make_model'] ?? 'Vehicle';
      $modelEsc = $conn->real_escape_string($model);
      $msg = "Vehicle <b>$modelEsc</b> has been returned. Final cost: ₱" . number_format($new_total, 2);
      createNotificationIfNotExists($conn, $rental['customer_id'], $rental['vehicle_id'], $msg);

      $conn->commit();
      error_log("Return inspection completed successfully for rental $rID with late fee: $penalty_amount");
      
      // Final verification - check rental and vehicle status after commit
      $finalCheck = $conn->query("SELECT r.status as rental_status, v.current_status as vehicle_status FROM rentals r JOIN vehicles v ON v.id = r.vehicle_id WHERE r.id = $rID")->fetch_assoc();
      error_log("Final status check - Rental $rID: {$finalCheck['rental_status']}, Vehicle: {$finalCheck['vehicle_status']}");
      
      // Store comprehensive return data in session for receipt display
      $_SESSION['return_receipt_data'] = [
        'rental_id' => $rID,
        'vehicle_model' => $rental['make_model'],
        'plate_no' => $rental['plate_no'],
        'customer_name' => $rental['customer_name'] ?? 'Customer',
        'start_date' => $rental['start_date'],
        'end_date' => $rental['end_date'],
        'actual_return_date' => $actual_return_date,
        'actual_return_time' => $actual_return_time,
        'odometer_return' => $odometer_return,
        'fuel_level' => $fuel_level,
        'return_condition' => $return_condition,
        'cleanliness' => $cleanliness,
        'damage_report' => $damage_report,
        'damage_fee' => $damage_fee,
        'washing_id' => $washing_id,
        'washing_cost' => $washing_cost,
        'washing_type' => $washing_id > 0 ? $conn->query("SELECT washing_name FROM washing_types WHERE id = $washing_id")->fetch_assoc()['washing_name'] ?? 'Washing Service' : null,
        'penalty_amount' => $penalty_amount,
        'fuel_penalty' => $fuel_penalty,
        'condition_adjustment' => $condition_adjustment,
        'total_additional_charges' => $total_additional_charges,
        'original_balance' => $rental['balance_due'],
        'daily_rate' => $daily_rate,
        'total_cost' => $rental['total_cost'],
        'downpayment' => $rental['downpayment'],
        'final_cost' => $new_total,
        'payment_status' => calculatePaymentStatus($rental['balance_due'], $new_total, $total_additional_charges)
      ];
      
      error_log("Receipt data stored in session for rental $rID");
      
      // Check if this is an AJAX request
      $isAjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') 
                || isset($_POST['ajax_request']);

      if($isAjax) {
        // AJAX request - return JSON
        sendAjaxResponse(true, 'Return completed successfully', 'rentals_all.php?success=return_completed&rental_id='.$rID);
      } else {
        // Regular form submission - redirect
        while (ob_get_level()) {
          ob_end_clean();
        }
        header("Location: rentals_all.php?success=return_completed&rental_id=".$rID);
        exit;
      }

    } catch (Exception $e) {
      $conn->rollback();
      error_log("Return inspection failed for rental $rID: " . $e->getMessage());
      error_log("Stack trace: " . $e->getTraceAsString());
      
      // Check if this is an AJAX request
      $isAjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') 
                || isset($_POST['ajax_request']);
                
      if($isAjax) {
        // Output JSON error response
        sendAjaxResponse(false, 'Return failed: ' . $e->getMessage());
      } else {
        // Clear all output buffers
        while (ob_get_level()) {
          ob_end_clean();
        }
        
        // Check if it's a maintenance conflict error
        if (strpos($e->getMessage(), 'Maintenance conflicts') !== false) {
          header("Location: rentals_all.php?error=maintenance_conflict");
        } else {
          header("Location: rentals_all.php?error=return_failed&msg=" . urlencode($e->getMessage()));
        }
        exit;
      }
    }
  }

  /* -- Extend rental (safe: cancel overlaps then update) -- */
  if(isset($_POST['rental_id'],$_POST['vehicle_id'],$_POST['new_end'])){
    $rID=(int)$_POST['rental_id']; 
    $vID=(int)$_POST['vehicle_id']; 
    $newEnd=trim($_POST['new_end']);
    $r=$conn->query("SELECT * FROM rentals WHERE id=$rID")->fetch_assoc();
    if(!$r) exit("Rental not found.");

    $cust=(int)$r['customer_id']; 
    $oldEnd=$r['end_date'];
    if(!$newEnd || strtotime($newEnd) < strtotime($oldEnd)) exit("New end date must be later than current end date.");

    // cancel overlapping future reservations (pending/reserved that start before newEnd)
    $veh=$conn->query("SELECT make_model FROM vehicles WHERE id=$vID")->fetch_assoc();
    $model=$veh['make_model'] ?? 'Vehicle';
    $modelEsc = $conn->real_escape_string($model);

    $conflicts=$conn->query("
      SELECT id, customer_id, start_date
      FROM rentals
      WHERE vehicle_id=$vID
        AND id<>$rID
        AND status IN ('pending','reserved')
        AND start_date <= '$newEnd'
    ");
    while($c=$conflicts->fetch_assoc()){
      $rid2=(int)$c['id']; 
      $cid=(int)$c['customer_id'];
      $startC=$c['start_date'];
      $conn->query("UPDATE rentals SET status='cancelled' WHERE id=$rid2");
      $warn="Your booking for <b>$modelEsc</b> (starting <b>$startC</b>) was cancelled because the previous renter extended their booking until <b>$newEnd</b>.";
      require_once __DIR__ . '/includes/notification_manager.php';
      createNotificationIfNotExists($conn, $cid, $vID, $warn);
    }

    // now extend safely
    $stmt=$conn->prepare("UPDATE rentals SET end_date=? WHERE id=?");
    $stmt->bind_param("si",$newEnd,$rID);
    $stmt->execute(); $stmt->close();

    // notify extender
    $msgSelf="Your booking for <b>$modelEsc</b> was extended until <b>$newEnd</b>.";
    require_once __DIR__ . '/includes/notification_manager.php';
    createNotificationIfNotExists($conn, $cust, $vID, $msgSelf);

    $conn->query("UPDATE vehicles SET current_status='rented' WHERE id=$vID");

    header("Location: rentals_all.php?extended=1"); exit;
  }
}

/* -------- Render data -------- */
$rows = fetchRentals($conn);

// CRITICAL: Exit early for AJAX requests to prevent HTML output
if (isset($_POST['ajax_request']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest')) {
  // This is an AJAX request but we haven't handled it yet - return error
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['success' => false, 'message' => 'AJAX request not handled']);
  exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>FleetGo Admin — Rentals</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#07090c;--text:#f2f6fa;--muted:#9aa6b3;
  --card:#0f1318;--glass:#0f141a;--line:#17202a;
  --brand:#5dd0ff;--brand2:#7cffc7;
  --ok:#7cffc7;--warn:#ffd166;--bad:#ff6b6b;
  --radius:16px;--shadow:0 18px 44px rgba(0,0,0,.45);
  --glass-bg:rgba(16,20,25,.78);--glass-border:rgba(93,208,255,.12);
}
*{box-sizing:border-box}
body{
  margin:0;
  background:
    radial-gradient(1200px 600px at 20% 0%, rgba(93,208,255,.10), transparent 60%),
    radial-gradient(1000px 500px at 90% 10%, rgba(124,255,199,.08), transparent 55%),
    var(--bg);
  color:var(--text);
  font-family:Inter,system-ui,sans-serif;
}
a{text-decoration:none;color:inherit}
.wrap{max-width:1360px;margin:0 auto;padding:22px;}

.page-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin:12px 0 18px;flex-wrap:wrap}
.page-title{min-width:260px}
.page-title h1{margin:0;font-size:2rem;font-weight:900;letter-spacing:.2px;line-height:1.1}
.page-title .sub{margin-top:6px;color:var(--muted);font-size:.9rem;line-height:1.35}
.page-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}

.btn-top{
  background:rgba(255,255,255,.08);
  border:1px solid rgba(255,255,255,.18);
  color:var(--text);
  border-radius:10px;
  padding:10px 14px;
  font-weight:700;
  cursor:pointer;
  transition:all .2s ease;
}
.btn-top:hover{transform:translateY(-2px);box-shadow:0 10px 26px rgba(0,0,0,.35);border-color:rgba(93,208,255,.25)}
.btn-top.primary{background:linear-gradient(90deg,var(--brand),var(--brand2));color:#04121b;border:0}

.meta{color:var(--muted);font-size:.8rem;line-height:1.25;margin-top:6px}
.strong{font-weight:800}

/* Filter Toolbar */
.filterbar{
  background:var(--glass-bg);backdrop-filter:blur(10px);
  border:1px solid var(--glass-border);border-radius:var(--radius);padding:16px;
  margin-bottom:18px;
}
.filterbar-top{display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap}
.filterbar-top .left{flex:1;min-width:260px}
.filterbar-top .right{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.filterbar-filters{margin-top:12px;display:grid;grid-template-columns:220px 180px 180px 1fr;gap:12px;align-items:center}
.filters-hint{color:var(--muted);font-size:.82rem;text-align:right;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.filterbar input, .filterbar select{
  background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.2);
  border-radius:8px;padding:10px 12px;color:var(--text);font-size:.9rem;
  transition:all 0.2s ease;
}
.date-field{position:relative;display:flex;align-items:center}
.date-field .ico{position:absolute;left:10px;opacity:.85;color:var(--muted);font-size:.95rem;pointer-events:none}
.date-field input[type="date"]{padding-left:34px}
.filterbar input[type="date"]{
  background:linear-gradient(180deg, rgba(255,255,255,.10), rgba(255,255,255,.06));
  border-color:rgba(93,208,255,.18);
}
.filterbar input[type="date"]:focus{border-color:rgba(93,208,255,.55)}
.filterbar input[type="date"]::-webkit-calendar-picker-indicator{
  filter: invert(1);
  opacity:.85;
  cursor:pointer;
}
.filterbar select option{
  background:#111827;
  color:#e5e7eb;
}
.filterbar select optgroup{
  background:#111827;
  color:#e5e7eb;
}
.filterbar input:focus, .filterbar select:focus{
  outline:none;border-color:var(--brand);background:rgba(255,255,255,.12);
  box-shadow:0 0 0 3px rgba(93,208,255,.1);
}
.filterbar .btn{
  background:linear-gradient(90deg,var(--brand),var(--brand2));color:#04121b;
  border:none;border-radius:10px;padding:10px 16px;font-weight:800;cursor:pointer;
  transition:all 0.2s ease;
}
.filterbar .btn:hover{transform:translateY(-2px);box-shadow:0 10px 26px rgba(93,208,255,.22);}
.filterbar .btn.secondary{
  background:rgba(255,255,255,.08);color:var(--text);border:1px solid rgba(255,255,255,.18);
}

@media (max-width: 1100px) {
  .filterbar-filters{grid-template-columns:1fr 1fr;}
}

@media (max-width: 720px) {
  .filterbar-top{flex-direction:column;align-items:stretch}
  .filterbar-top .right{justify-content:stretch}
  .filterbar-top .right .btn{flex:1}
  .filterbar-filters{grid-template-columns:1fr;}
  .filters-hint{text-align:left;white-space:normal}
}

.card{
  background:var(--glass-bg);backdrop-filter:blur(10px);
  border:1px solid var(--glass-border);border-radius:var(--radius);padding:24px;
  box-shadow:var(--shadow);
}

/* Live summary bar (computed from current page rows; non-redundant) */
.summarybar{
  background:var(--glass-bg);backdrop-filter:blur(10px);
  border:1px solid var(--glass-border);border-radius:var(--radius);
  padding:12px 14px;margin:0 0 14px;
  display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap;
}
.summary-left{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.chip{
  display:inline-flex;align-items:center;gap:8px;
  padding:8px 10px;border-radius:999px;
  background:rgba(255,255,255,.06);
  border:1px solid rgba(255,255,255,.10);
  font-weight:800;font-size:.82rem;
}
.chip .lbl{color:var(--muted);font-weight:700}
.chip.bad{border-color:rgba(255,107,107,.25);background:rgba(255,107,107,.10)}
.summary-right{color:var(--muted);font-size:.82rem}
.table{width:100%;border-collapse:separate;border-spacing:0 12px}
.thead{
  display:grid;grid-template-columns: 260px 280px 220px 220px 240px;
  gap:0;color:var(--muted);font-size:.8rem;margin:6px 0;font-weight:600;text-transform:uppercase;
  letter-spacing:0.5px;padding:0 16px;
}
.row{
  display:grid;grid-template-columns: 260px 280px 220px 220px 240px;
  background:var(--glass-bg);backdrop-filter:blur(10px);
  border:1px solid var(--glass-border);border-radius:var(--radius);align-items:center;
  box-shadow:var(--shadow);overflow:visible;position:relative;padding:16px;
  transition:all 0.3s cubic-bezier(0.4,0,0.2,1);
}
.row:hover{
  transform:translateY(-2px);box-shadow:0 20px 40px rgba(0,0,0,.4);
  border-color:rgba(93,208,255,.2);background:rgba(16,20,25,.9);
}
.row:nth-child(even){background:rgba(16,20,25,.6);}
.row:nth-child(even):hover{background:rgba(16,20,25,.8);}

/* Status Badges */
.status{
  display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:20px;
  font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;
  border:1px solid;transition:all 0.2s ease;
}
.status.s-pending{
  background:rgba(255,165,0,.15);color:#ffd166;border-color:rgba(255,165,0,.3);
}
.status.s-reserved{
  background:rgba(93,208,255,.15);color:#5dd0ff;border-color:rgba(93,208,255,.3);
}
.status.s-ongoing{
  background:rgba(124,255,199,.15);color:#7cffc7;border-color:rgba(124,255,199,.3);
}
.status.s-completed{
  background:rgba(34,197,94,.15);color:#22c55e;border-color:rgba(34,197,94,.3);
}
.status.s-cancelled{
  background:rgba(239,68,68,.15);color:#ef4444;border-color:rgba(239,68,68,.3);
}

/* Action Buttons */
.btn{
  padding:6px 12px;border-radius:6px;font-size:.75rem;font-weight:600;cursor:pointer;
  transition:all 0.2s ease;border:none;text-decoration:none;display:inline-flex;align-items:center;gap:4px;
  white-space:nowrap;margin:2px;
}
.btn-approve{
  background:linear-gradient(90deg,var(--brand),var(--brand2));color:#04121b;
}
.btn-approve:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(93,208,255,.3);}
.btn[disabled],
.btn:disabled{
  opacity:.55;
  cursor:not-allowed;
  transform:none !important;
  box-shadow:none !important;
}
.pay-badge{
  display:inline-flex;
  align-items:center;
  padding:4px 10px;
  border-radius:999px;
  font-size:.7rem;
  font-weight:800;
  margin-top:6px;
  border:1px solid rgba(255,255,255,.08);
}
.pay-awaiting{background:rgba(255,165,0,.14);color:#ffd166;border-color:rgba(255,165,0,.25);}
.pay-partial{background:rgba(59,130,246,.16);color:#9edcff;border-color:rgba(59,130,246,.28);}
.pay-ready{background:rgba(16,185,129,.16);color:#7cffc7;border-color:rgba(16,185,129,.28);}

/* Record Payment */
.paybtn{
  background:rgba(255,255,255,.06);
  color:var(--text);
  border:1px solid rgba(255,255,255,.10);
}
.paybtn:hover{background:rgba(255,255,255,.09)}
.pay-modal{width:min(520px,94vw)}
.pay-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:12px 0 4px}
.pay-card{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:10px 12px}
.pay-card .k{color:var(--muted);font-size:.75rem;font-weight:700}
.pay-card .v{color:var(--text);font-size:.95rem;font-weight:900;margin-top:4px}
.pay-field{margin-top:10px}
.pay-field label{display:block;font-weight:700;font-size:.8rem;color:var(--muted);margin-bottom:6px}
.pay-input{display:flex;align-items:center;gap:8px;padding:10px 12px;border-radius:12px;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.18)}
.pay-input span{color:var(--muted);font-weight:900}
.pay-input input{flex:1;background:transparent;border:0;outline:0;color:var(--text);font-size:1rem;font-weight:900}
.pay-help{color:var(--muted);font-size:.78rem;margin-top:8px}
.btn-cancel{
  background:rgba(239,68,68,.2);color:#ef4444;border:1px solid rgba(239,68,68,.3);
}
.btn-cancel:hover{background:rgba(239,68,68,.3);transform:translateY(-2px);}
.btn-return{
  background:rgba(34,197,94,.2);color:#22c55e;border:1px solid rgba(34,197,94,.3);
}
.btn-return:hover{background:rgba(34,197,94,.3);transform:translateY(-2px);}
.btn-receipt{
  background:rgba(168,85,247,.2);color:#a855f7;border:1px solid rgba(168,85,247,.3);
  padding:6px 12px;font-size:.75rem;
}
.btn-receipt:hover{background:rgba(168,85,247,.3);transform:translateY(-2px);}

/* Financial Display */
.financial{
  text-align:right;font-family:'Inter',monospace;
}
.financial .total{font-weight:700;color:var(--text);font-size:1rem;}
.financial .down{color:var(--brand2);font-size:.85rem;}
.financial .balance{color:var(--muted);font-size:.8rem;}

/* Responsive Design */
@media (max-width: 1200px) {
  .thead, .row {
    grid-template-columns: 220px 240px 200px 200px 220px;
    font-size: .8rem;
  }
}

@media (max-width: 992px) {
  .thead, .row {
    grid-template-columns: 1fr;
    font-size: .75rem;
  }
  .cell {
    padding: 8px;
  }
}

@media (max-width: 768px) {
  .wrap {
    padding: 16px;
  }
  h1 {
    font-size: 1.5rem;
  }
  .filterbar {
    padding: 16px;
  }
  .thead, .row {
    grid-template-columns: 1fr;
    gap: 8px;
  }
  .thead {
    display: none;
  }
  .row {
    display: block;
    margin-bottom: 12px;
    padding: 12px;
  }
  .cell {
    display: flex;
    justify-content: space-between;
    padding: 8px 12px;
    border-right: none;
    border-bottom: 1px solid rgba(255,255,255,.06);
  }
  .cell:last-child {
    border-bottom: none;
  }
  .cell::before {
    content: attr(data-label);
    font-weight: 600;
    color: var(--muted);
    margin-right: 12px;
  }
}
.cell{padding:12px;border-right:1px solid rgba(255,255,255,.06);position:relative;z-index:1} .cell:last-child{border-right:0;overflow:visible}
.cell .btn{margin:2px;display:inline-block;white-space:nowrap}
.cell:last-child{display:flex;flex-direction:column;gap:8px;align-items:flex-start;min-height:100px;overflow:visible;position:relative;width:auto}
.cell:last-child .status{margin-bottom:4px;width:auto;word-wrap:break-word;line-height:1.2}
.cell:last-child .btn{margin-top:4px;margin-bottom:2px}
.muted{color:var(--muted);font-size:.85rem}

.actions-stack{display:flex;flex-direction:column;gap:6px;margin-top:10px;min-width:160px}
.actions-stack form{margin:0}
.actions-stack .btn{width:fit-content}

/* Details Drawer */
.drawer-bg{position:fixed;inset:0;background:rgba(5,10,15,.55);backdrop-filter:blur(6px);display:none;z-index:1100}
.drawer{
  position:fixed;top:0;right:0;height:100vh;width:min(420px,92vw);
  background:linear-gradient(180deg,#0f141a,#0b0f14);
  border-left:1px solid rgba(255,255,255,.10);
  box-shadow:-24px 0 60px rgba(0,0,0,.55);
  transform:translateX(100%);
  transition:transform .25s ease;
  z-index:1101;
  display:flex;flex-direction:column;
}
.drawer-bg.open{display:block}
.drawer.open{transform:translateX(0)}
.drawer-head{padding:16px 16px 12px;border-bottom:1px solid rgba(255,255,255,.08);display:flex;align-items:flex-start;justify-content:space-between;gap:10px}
.drawer-title{font-weight:900;font-size:1.05rem;line-height:1.1}
.drawer-sub{color:var(--muted);font-size:.85rem;margin-top:6px;line-height:1.3}
.drawer-close{background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.14);color:var(--text);border-radius:10px;padding:8px 10px;cursor:pointer;font-weight:800}
.drawer-body{padding:14px 16px;overflow:auto}
.kv{display:flex;justify-content:space-between;gap:10px;padding:10px 0;border-bottom:1px solid rgba(255,255,255,.06)}
.kv .k{color:var(--muted);font-size:.82rem;font-weight:700}
.kv .v{font-weight:800}
.drawer-actions{padding:14px 16px;border-top:1px solid rgba(255,255,255,.08);display:flex;gap:10px;flex-wrap:wrap}
.drawer-actions .btn{flex:1}

.status{
  display:inline-flex;align-items:center;gap:6px;padding:.28rem .6rem;border-radius:999px;font-weight:800;font-size:.72rem;
  margin-bottom:6px;width:auto;word-wrap:break-word;line-height:1.2;
}
.s-pending,.s-reserved{background:rgba(93,208,255,.14);color:#87e1ff}
.s-ongoing{background:rgba(255,209,102,.18);color:#ffd166}
.s-completed{background:rgba(124,255,199,.18);color:#7cffc7}
.s-cancelled{background:rgba(255,107,107,.18);color:#ff9b9b}
.future{font-size:.72rem;background:rgba(93,208,255,.10);color:#7ad8ff;padding:.12rem .4rem;border-radius:6px}
.countdown{font-size:.72rem;color:#88a8bd}

.btn{border:0;cursor:pointer;border-radius:10px;padding:.5rem .8rem;font-weight:800;font-size:.8rem;transition:all 0.2s ease;position:relative;z-index:1}
.btn:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,0.3)}
.btn-approve{background:linear-gradient(90deg,var(--brand),var(--brand2));color:#04171f}
.btn-cancel{background:#3a1c1c;color:#ffc9c9}
.btn-start{background:#22425a;color:#9edcff;border:1px solid rgba(255,255,255,.08)}
.btn-complete{background:#18321f;color:#7cffc7;border:1px solid rgba(255,255,255,.08)}
.btn-extend{background:#3a2b12;color:#ffd166;border:1px solid rgba(255,255,255,.08)}
.btn-receipt{background:#0f2a3a;color:#7dd3fc;border:1px solid rgba(255,255,255,.08);margin-top:8px;display:block;width:fit-content}

.badgeOK{background:rgba(124,255,199,.15);color:#7cffc7;border:1px solid rgba(124,255,199,.25)}
.badgeWARN{background:rgba(255,209,102,.15);color:#ffd166;border:1px solid rgba(255,209,102,.25)}
.badgeBAD{background:rgba(255,107,107,.15);color:#ff9b9b;border:1px solid rgba(255,107,107,.25)}

.toast{
  position:fixed;top:18px;right:18px;background:linear-gradient(180deg,#0e1620,#0a0f14);
  border:1px solid rgba(124,255,199,.35);color:#cfe6f6;border-radius:12px;padding:10px 14px;z-index:10001;
  box-shadow:0 14px 34px rgba(0,0,0,.5);display:none
}

/* Modal */
.modal-bg{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(5,10,15,.65);backdrop-filter:blur(6px);display:none;align-items:center;justify-content:center;z-index:1000}
.modal{
  background:linear-gradient(180deg,#0f141a,#0b0f14);
  border:1px solid rgba(255,255,255,.12);border-radius:16px;padding:22px;width:min(440px,94vw);
  box-shadow:var(--shadow);text-align:center;position:relative;z-index:1001;
  max-height:90vh;overflow-y:auto;
}

/* Return Modal Styles */
.return-modal{
  width:min(500px,94vw);
  text-align:left;
  max-height:90vh;
  overflow-y:auto;
}
.return-modal h2{
  text-align:center;
  margin-bottom:20px;
  color:#7cffc7;
  font-size:1.4rem;
}
.form-row{
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:12px;
  margin-bottom:16px;
}
.form-group{
  display:flex;
  flex-direction:column;
}
.form-group label{
  font-size:.85rem;
  font-weight:600;
  color:#9ca3af;
  margin-bottom:6px;
}
.form-group input,
.form-group select,
.form-group textarea{
  background:rgba(255,255,255,.08);
  border:1px solid rgba(255,255,255,.25);
  border-radius:8px;
  padding:10px 12px;
  color:#fff;
  font-size:.9rem;
  transition:all .2s;
}
.form-group select{
  background:rgba(255,255,255,.1);
  border:1px solid rgba(255,255,255,.3);
  color:#fff;
}
.form-group select option{
  background:#1e293b;
  color:#fff;
  padding:8px;
}
.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus{
  outline:none;
  border-color:#3b82f6;
  background:rgba(255,255,255,.12);
  box-shadow:0 0 0 3px rgba(59,130,246,.1);
}
.form-group input::placeholder,
.form-group textarea::placeholder{
  color:#6b7280;
}
.form-group textarea{
  resize:vertical;
  min-height:60px;
}
.return-modal .actions{
  margin-top:20px;
  display:flex;
  gap:10px;
  justify-content:center;
}

/* Receipt Modal Styles */
.receipt-modal{
  width:min(600px,94vw);
  max-height:90vh;
  overflow-y:auto;
  background:linear-gradient(180deg,#ffffff,#f8fafc);
  color:#1e293b;
  border:2px solid #e2e8f0;
  position:relative;
  z-index:1001;
}
.receipt-header{
  text-align:center;
  padding-bottom:20px;
  border-bottom:2px solid #e2e8f0;
  margin-bottom:20px;
}
.receipt-header h2{
  color:#1e40af;
  margin:0 0 15px;
  font-size:1.6rem;
}
.receipt-info{
  display:grid;
  grid-template-columns:1fr 1fr 1fr;
  gap:15px;
  font-size:.9rem;
  color:#64748b;
}
.receipt-info div{
  text-align:center;
  padding:8px;
  background:#f1f5f9;
  border-radius:8px;
}
.receipt-body{
  margin-bottom:20px;
}
.receipt-section{
  margin-bottom:25px;
  padding:15px;
  background:#ffffff;
  border-radius:12px;
  border:1px solid #e2e8f0;
  box-shadow:0 2px 4px rgba(0,0,0,0.05);
}
.receipt-section h3{
  margin:0 0 15px;
  color:#1e40af;
  font-size:1.1rem;
  border-bottom:1px solid #e2e8f0;
  padding-bottom:8px;
}
.condition-grid{
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:12px;
}
.condition-item{
  display:flex;
  justify-content:space-between;
  align-items:center;
  padding:8px 12px;
  background:#f8fafc;
  border-radius:8px;
  border:1px solid #e2e8f0;
}
.condition-item .label{
  font-weight:600;
  color:#374151;
}
.condition-item .value{
  font-weight:700;
  color:#1e40af;
}
.damage-report{
  background:#fef3c7;
  border:1px solid #f59e0b;
  border-radius:8px;
  padding:12px;
  color:#92400e;
  font-style:italic;
}
.cost-breakdown{
  background:#f8fafc;
  border-radius:8px;
  padding:15px;
}
.cost-item{
  display:flex;
  justify-content:space-between;
  align-items:center;
  padding:8px 0;
  border-bottom:1px solid #e2e8f0;
}
.cost-item:last-child{
  border-bottom:none;
}
.cost-item.total{
  font-weight:800;
  font-size:1.1rem;
  color:#1e40af;
  background:#e0f2fe;
  padding:12px;
  border-radius:8px;
}
.rental-summary{
  display:flex;
  flex-direction:column;
  gap:8px;
  margin-bottom:15px;
}
.summary-item{
  display:flex;
  justify-content:space-between;
  align-items:center;
  padding:8px 0;
  border-bottom:1px solid #f0f0f0;
}
.summary-item:last-child{
  border-bottom:none;
}
.payment-status{
  display:flex;
  justify-content:space-between;
  align-items:center;
  padding:15px 0;
  border-top:2px solid #e0e0e0;
  margin-top:10px;
}
.payment-status .label{
  font-weight:600;
  color:#333;
}
.payment-status .value{
  font-weight:700;
  font-size:1.1em;
}
.payment-status .value.status{
  padding:4px 12px;
  border-radius:20px;
  font-size:0.9em;
  font-weight:600;
}
.payment-status .value.status.fully-paid{
  background-color:#d4edda;
  color:#155724;
}
.payment-status .value.status.balance-remaining{
  background-color:#d1ecf1;
  color:#0c5460;
}
.payment-status .value.status.additional-charges-due{
  background-color:#f8d7da;
  color:#721c24;
  margin-top:10px;
}

/* Return Due Indicator */
.return-due-indicator{
  position:absolute;
  top:5px;
  right:5px;
  padding:3px 8px;
  border-radius:12px;
  font-size:11px;
  font-weight:600;
  text-transform:uppercase;
  letter-spacing:0.5px;
  animation:pulse 2s infinite;
}
.return-due-indicator.due-soon{
  background-color:#fef3c7;
  color:#92400e;
  border:1px solid #fbbf24;
}
.return-due-indicator.due-today{
  background-color:#fee2e2;
  color:#991b1b;
  border:1px solid #f87171;
  animation:pulse 1s infinite;
}
.return-due-indicator.overdue{
  background-color:#fecaca;
  color:#7f1d1d;
  border:1px solid #ef4444;
  animation:pulse 0.5s infinite;
}
@keyframes pulse{
  0%, 100%{ opacity:1; }
  50%{ opacity:0.7; }
}
.rental-row{
  position:relative;
}

/* Notification Banner Styles */
.notification-banner{
  background:linear-gradient(90deg, #dc2626, #ef4444);
  color:white;
  padding:12px 20px;
  margin-bottom:20px;
  border-radius:8px;
  box-shadow:0 4px 12px rgba(220,38,38,0.3);
  animation:slideDown 0.3s ease-out;
}
.notification-banner.overdue{
  background:linear-gradient(90deg, #dc2626, #ef4444);
}
.notification-banner.due-soon{
  background:linear-gradient(90deg, #d97706, #f59e0b);
}
.notification-content{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
}
.notification-icon{
  font-size:18px;
  flex-shrink:0;
}
.notification-text{
  flex:1;
  font-weight:500;
}
.notification-close{
  background:rgba(255,255,255,0.2);
  border:none;
  color:white;
  width:24px;
  height:24px;
  border-radius:50%;
  cursor:pointer;
  font-size:16px;
  display:flex;
  align-items:center;
  justify-content:center;
  transition:background 0.2s;
}
.notification-close:hover{
  background:rgba(255,255,255,0.3);
}
@keyframes slideDown{
  from{
    transform:translateY(-100%);
    opacity:0;
  }
  to{
    transform:translateY(0);
    opacity:1;
  }
}
.payment-status .value.status.fully-paid-additional-charges-waived{
  background-color:#d4edda;
  color:#155724;
}
.payment-status .value.status.fully-paid-original-balance-covered{
  background-color:#d4edda;
  color:#155724;
}
.cost-item .label{
  font-weight:600;
  color:#374151;
}
.cost-item .value{
  font-weight:700;
  color:#1e40af;
}
.cost-item .value.penalty{
  color:#dc2626;
}
.cost-item .value.breakdown{
  color:#64748b;
  font-size:.85rem;
  font-style:italic;
}
.cost-divider{
  height:2px;
  background:linear-gradient(90deg,transparent,#e2e8f0,transparent);
  margin:10px 0;
}
.receipt-footer{
  border-top:2px solid #e2e8f0;
  padding-top:20px;
}
.receipt-actions{
  display:flex;
  gap:10px;
  justify-content:center;
  margin-bottom:15px;
}
.receipt-note{
  text-align:center;
  color:#64748b;
  font-size:.85rem;
  font-style:italic;
}
.receipt-note p{
  margin:0;
  padding:10px;
  background:#f1f5f9;
  border-radius:8px;
}

/* Print Styles */
@media print {
  .receipt-modal{
    width:100% !important;
    max-height:none !important;
    background:white !important;
    color:black !important;
    border:none !important;
    box-shadow:none !important;
  }
  .receipt-actions{
    display:none !important;
  }
  .receipt-note{
    display:none !important;
  }
  .receipt-section{
    break-inside:avoid;
    box-shadow:none !important;
    border:1px solid #ccc !important;
  }
}

.btn-extend {
  background: linear-gradient(135deg, #f59e0b, #d97706);
  color: white;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-weight: 600;
  padding: 8px 14px;
  border-radius: 8px;
  box-shadow: 0 2px 8px rgba(245, 158, 11, 0.3);
  transition: all 0.2s ease;
}

.btn-extend:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 16px rgba(245, 158, 11, 0.4);
}

.btn-extend .btn-icon {
  font-size: 12px;
}

/* Enhanced Calendar Styles */
.enhanced-calendar-container {
  background: rgba(15, 23, 42, 0.6);
  border: 2px solid rgba(71, 85, 105, 0.4);
  border-radius: 12px;
  padding: 16px;
  margin-top: 10px;
}

.calendar-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 12px;
  padding-bottom: 12px;
  border-bottom: 1px solid rgba(71, 85, 105, 0.3);
}

.calendar-nav {
  width: 32px;
  height: 32px;
  border: none;
  background: rgba(30, 41, 59, 0.8);
  color: #e2e8f0;
  border-radius: 8px;
  cursor: pointer;
  font-size: 16px;
  font-weight: bold;
  transition: all 0.2s ease;
  display: flex;
  align-items: center;
  justify-content: center;
}

.calendar-nav:hover {
  background: rgba(245, 158, 11, 0.2);
  color: #fbbf24;
}

.calendar-title {
  display: flex;
  gap: 8px;
  align-items: center;
}

.calendar-select {
  background: rgba(30, 41, 59, 0.8);
  border: 1px solid rgba(71, 85, 105, 0.4);
  border-radius: 6px;
  color: #e2e8f0;
  padding: 6px 10px;
  font-size: 0.9rem;
  cursor: pointer;
  outline: none;
  transition: all 0.2s ease;
}

.calendar-select:focus {
  border-color: #f59e0b;
  box-shadow: 0 0 0 2px rgba(245, 158, 11, 0.2);
}

.calendar-wrapper {
  margin-bottom: 12px;
}

.calendar-weekdays {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  gap: 4px;
  margin-bottom: 8px;
}

.calendar-weekdays span {
  text-align: center;
  font-size: 0.75rem;
  font-weight: 600;
  color: #64748b;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  padding: 8px 0;
}

.calendar-days {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  gap: 4px;
}

.calendar-day {
  aspect-ratio: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  border: none;
  background: transparent;
  color: #e2e8f0;
  font-size: 0.9rem;
  font-weight: 500;
  border-radius: 8px;
  cursor: pointer;
  transition: all 0.2s ease;
  position: relative;
}

.calendar-day:hover:not(.disabled):not(.selected) {
  background: rgba(245, 158, 11, 0.15);
  color: #fbbf24;
}

.calendar-day.today {
  background: rgba(59, 130, 246, 0.15);
  color: #60a5fa;
  font-weight: 600;
}

.calendar-day.today::after {
  content: '';
  position: absolute;
  bottom: 4px;
  left: 50%;
  transform: translateX(-50%);
  width: 4px;
  height: 4px;
  background: #60a5fa;
  border-radius: 50%;
}

.calendar-day.selected {
  background: linear-gradient(135deg, #f59e0b, #d97706);
  color: white;
  font-weight: 600;
  box-shadow: 0 4px 12px rgba(245, 158, 11, 0.35);
}

.calendar-day.disabled {
  color: #475569;
  cursor: not-allowed;
  text-decoration: line-through;
  opacity: 0.5;
}

.calendar-day.other-month {
  color: #334155;
  opacity: 0.6;
}

.selected-date-display {
  background: rgba(30, 41, 59, 0.8);
  border: 1px solid rgba(71, 85, 105, 0.4);
  border-radius: 8px;
  padding: 10px 14px;
  text-align: center;
  font-weight: 500;
  color: #e2e8f0;
  margin-bottom: 8px;
}

.selected-date-display .no-date {
  color: #64748b;
  font-style: italic;
}

.selected-date-display .date-value {
  color: #34d399;
  font-weight: 600;
}

.calendar-day.extension-range {
  background: rgba(16, 185, 129, 0.15);
  border-radius: 0;
}

.calendar-day.extension-start {
  background: rgba(16, 185, 129, 0.3);
  border-radius: 8px 0 0 8px;
}

.calendar-day.extension-end {
  background: rgba(16, 185, 129, 0.3);
  border-radius: 0 8px 8px 0;
}

.extend-modal .modal-header {
  text-align: center;
  padding: 24px 24px 16px;
  border-bottom: 1px solid rgba(71, 85, 105, 0.3);
}

.extend-modal .modal-icon {
  width: 56px;
  height: 56px;
  background: linear-gradient(135deg, #f59e0b, #d97706);
  border-radius: 14px;
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 0 auto 16px;
  box-shadow: 0 8px 24px rgba(245, 158, 11, 0.3);
}

.extend-modal .modal-icon svg {
  width: 28px;
  height: 28px;
  color: white;
}

.extend-modal h2 {
  margin: 0 0 6px;
  font-size: 1.4rem;
  font-weight: 700;
  color: #f8fafc;
  letter-spacing: -0.02em;
}

.extend-modal .modal-subtitle {
  margin: 0;
  color: #94a3b8;
  font-size: 0.9rem;
}

/* Rental Info Card */
.rental-info-card {
  margin: 20px 24px;
  background: rgba(15, 23, 42, 0.6);
  border: 1px solid rgba(71, 85, 105, 0.4);
  border-radius: 12px;
  overflow: hidden;
}

.rental-info-header {
  background: rgba(30, 41, 59, 0.8);
  padding: 10px 16px;
  border-bottom: 1px solid rgba(71, 85, 105, 0.3);
}

.rental-info-title {
  font-size: 0.75rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: #64748b;
}

.rental-info-body {
  padding: 12px 16px;
}

.info-row {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding: 10px 0;
  border-bottom: 1px solid rgba(71, 85, 105, 0.2);
}

.info-row:last-child {
  border-bottom: none;
  padding-bottom: 0;
}

.info-row:first-child {
  padding-top: 0;
}

.info-icon {
  width: 28px;
  height: 28px;
  background: rgba(30, 41, 59, 0.8);
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 14px;
  flex-shrink: 0;
}

.info-content {
  flex: 1;
  min-width: 0;
}

.info-content label {
  display: block;
  font-size: 0.75rem;
  color: #64748b;
  margin-bottom: 2px;
  font-weight: 500;
}

.info-content span {
  display: block;
  font-size: 0.95rem;
  color: #e2e8f0;
  font-weight: 500;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.info-content .highlight-date {
  color: #fbbf24;
  font-weight: 600;
}

.info-content .highlight-rate {
  color: #34d399;
  font-weight: 600;
}

/* Form Section */
.extend-form {
  padding: 0 24px 24px;
}

.form-section {
  margin-bottom: 20px;
}

.form-label {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 0.9rem;
  font-weight: 600;
  color: #e2e8f0;
  margin-bottom: 10px;
}

.label-icon {
  font-size: 16px;
}

.date-input-wrapper {
  position: relative;
}

.date-input {
  width: 100%;
  padding: 12px 16px;
  background: rgba(15, 23, 42, 0.6);
  border: 2px solid rgba(71, 85, 105, 0.4);
  border-radius: 10px;
  color: #f8fafc;
  font-size: 1rem;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.2s ease;
}

.date-input:focus {
  outline: none;
  border-color: #f59e0b;
  box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.15);
}

.date-input::-webkit-calendar-picker-indicator {
  filter: invert(1);
  opacity: 0.7;
  cursor: pointer;
}

.input-hint {
  font-size: 0.8rem;
  color: #64748b;
  margin-top: 6px;
  padding-left: 4px;
}

/* Extension Summary */
.extension-summary {
  background: linear-gradient(135deg, rgba(16, 185, 129, 0.15), rgba(5, 150, 105, 0.1));
  border: 1px solid rgba(16, 185, 129, 0.3);
  border-radius: 12px;
  padding: 16px;
  margin-bottom: 20px;
  animation: fadeIn 0.3s ease;
}

@keyframes fadeIn {
  from { opacity: 0; transform: translateY(-8px); }
  to { opacity: 1; transform: translateY(0); }
}

.summary-header {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 0.85rem;
  font-weight: 600;
  color: #34d399;
  margin-bottom: 12px;
  padding-bottom: 10px;
  border-bottom: 1px solid rgba(16, 185, 129, 0.2);
}

.summary-body {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.summary-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 0.9rem;
  color: #cbd5e1;
}

.summary-row.total {
  padding-top: 8px;
  border-top: 1px solid rgba(16, 185, 129, 0.2);
  font-weight: 600;
}

.summary-value {
  color: #e2e8f0;
  font-weight: 500;
}

.summary-value.highlight {
  color: #34d399;
  font-size: 1.1rem;
  font-weight: 700;
}

/* Actions */
.extend-modal .actions {
  display: flex;
  gap: 12px;
  margin-top: 8px;
}

.extend-modal .actions .btn {
  flex: 1;
  padding: 12px 20px;
  font-size: 0.95rem;
  font-weight: 600;
  border-radius: 10px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
}

.extend-modal .actions .btn-extend {
  background: linear-gradient(135deg, #f59e0b, #d97706);
  box-shadow: 0 4px 16px rgba(245, 158, 11, 0.35);
}

.extend-modal .actions .btn-extend:hover {
  transform: translateY(-2px);
  box-shadow: 0 6px 20px rgba(245, 158, 11, 0.45);
}

.extend-modal .actions .btn-cancel {
  background: rgba(71, 85, 105, 0.5);
  color: #94a3b8;
  border: 1px solid rgba(71, 85, 105, 0.6);
}

.extend-modal .actions .btn-cancel:hover {
  background: rgba(71, 85, 105, 0.7);
  color: #e2e8f0;
}

/* Extend Button in Table */
.actions-stack .btn-extend {
  background: linear-gradient(135deg, #f59e0b, #d97706);
  color: white;
  font-weight: 600;
  padding: 7px 14px;
  border-radius: 8px;
  font-size: 0.85rem;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  box-shadow: 0 2px 8px rgba(245, 158, 11, 0.25);
  transition: all 0.2s ease;
  border: none;
  cursor: pointer;
}

.actions-stack .btn-extend::before {
  content: "↻";
  font-size: 12px;
}

.actions-stack .btn-extend:hover {
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(245, 158, 11, 0.35);
}
</style>
</head>
<body>

<?php include __DIR__.'/includes/navbar.php'; ?>

<div class="wrap">
  <div class="page-head">
    <div class="page-title">
      <h1>Rentals</h1>
      <div class="sub">Track approvals, active rentals, returns, extensions, and receipts — all in one place.</div>
    </div>
  </div>

  <!-- Filter Toolbar -->
  <div class="filterbar">
    <div class="filterbar-top">
      <div class="left">
        <input type="text" id="searchInput" placeholder="Search customer, vehicle, plate, or rental #..." style="width:100%">
      </div>
      <div class="right">
        <button class="btn secondary" type="button" onclick="resetFilters()">Reset</button>
        <button class="btn" type="button" onclick="exportRentals()">Export</button>
      </div>
    </div>

    <div class="filterbar-filters">
      <select id="statusFilter">
        <option value="">All Status</option>
        <option value="pending">Pending</option>
        <option value="reserved">Reserved</option>
        <option value="ongoing">Ongoing</option>
        <option value="completed">Completed</option>
        <option value="cancelled">Cancelled</option>
      </select>
      <div class="date-field">
        <span class="ico">📅</span>
        <input type="date" id="startDateFilter" placeholder="Start Date">
      </div>
      <div class="date-field">
        <span class="ico">📅</span>
        <input type="date" id="endDateFilter" placeholder="End Date">
      </div>
      <div class="filters-hint">Filters apply automatically</div>
    </div>
  </div>

  <div class="summarybar" id="summaryBar">
    <div class="summary-left">
      <div class="chip"><span class="lbl">Shown</span> <span id="sumShown">0</span></div>
      <div class="chip"><span class="lbl">Total Value</span> <span id="sumTotal">₱0.00</span></div>
      <div class="chip"><span class="lbl">Balance Due</span> <span id="sumBalance">₱0.00</span></div>
      <div class="chip bad"><span class="lbl">Overdue</span> <span id="sumOverdue">0</span></div>
    </div>
    <div class="summary-right" id="sumHint">Based on current filters</div>
  </div>

  <!-- Rentals table -->
  <div class="card">
    <div class="thead">
      <div><b>Customer</b></div>
      <div><b>Vehicle</b></div>
      <div><b>Schedule</b></div>
      <div><b>Cost</b></div>
      <div><b>Status / Actions</b></div>
    </div>

    <div id="rows">
      <?php
      if(!$rows){
        echo '<div class="row"><div class="cell" style="grid-column:1/-1">No rentals yet.</div></div>';
      } else {
        foreach($rows as $r){
          $st = strtolower($r['status']);
          $isAdvance = ($st==='reserved' && strtotime($r['start_date'])>strtotime($today));
          
          // Return due indicator logic
          $return_indicator = '';
          $return_indicator_class = '';
          if ($st === 'ongoing') {
            $end_datetime = $r['end_date'] . ' ' . ($r['end_time'] ?? '18:00:00');
            $end_timestamp = strtotime($end_datetime);
            $current_timestamp = time();
            $hours_until_due = ($end_timestamp - $current_timestamp) / 3600;
            
            if ($hours_until_due < 0) {
              // Overdue
              $return_indicator = 'OVERDUE';
              $return_indicator_class = 'overdue';
            } elseif ($hours_until_due <= 24) {
              // Due today or within 24 hours
              $return_indicator = $hours_until_due <= 0 ? 'DUE TODAY' : 'DUE TODAY';
              $return_indicator_class = 'due-today';
            } elseif ($hours_until_due <= 72) {
              // Due within 3 days (72 hours)
              $return_indicator = 'DUE SOON';
              $return_indicator_class = 'due-soon';
            }
          }
          
          $days = max(1, round((strtotime($r['end_date'])-strtotime($r['start_date']))/86400, 1));
          $total_amount = (float)($r['total_cost'] ?? 0);
          if ($total_amount <= 0) {
            $total_amount = $days * (float)($r['daily_rate'] ?? 0);
          }
          $required_down = (float)($r['downpayment'] ?? 0);
          $paid_amount = (float)($r['paid_amount'] ?? 0);
          $balance_calc = $total_amount - $paid_amount;
          if ($balance_calc < 0) { $balance_calc = 0; }
          $sclass = 's-'.$st;
          $drawerData = [
            'id' => (int)$r['id'],
            'customer' => (string)($r['full_name'] ?? ''),
            'vehicle' => (string)($r['make_model'] ?? ''),
            'plate' => (string)($r['plate_no'] ?? ''),
            'vehicle_id' => (int)($r['vehicle_id'] ?? 0),
            'vehicle_type' => (string)($r['vehicle_type'] ?? ''),
            'start_date' => (string)($r['start_date'] ?? ''),
            'end_date' => (string)($r['end_date'] ?? ''),
            'end_time' => (string)($r['end_time'] ?? ''),
            'daily_rate' => (float)($r['daily_rate'] ?? 0),
            'downpayment' => (float)$required_down,
            'paid_amount' => (float)$paid_amount,
            'balance_due' => (float)$balance_calc,
            'total' => (float)$total_amount,
            'days' => (int)$days,
            'status' => (string)$st,
          ];
          $drawerDataEsc = htmlspecialchars(json_encode($drawerData), ENT_QUOTES, 'UTF-8');
          
          // Debug: Show rental status in console (removed to prevent HTML interference)

          echo '<div class="row rental-row" data-rental="'.$drawerDataEsc.'" data-start-date="'.h($r['start_date']).'" data-end-date="'.h($r['end_date']).'" '.($isAdvance ? 'data-start="'.h($r['start_date']).'"' : '').'>';
          
          // Add return due indicator if applicable
          if ($return_indicator) {
            echo '<div class="return-due-indicator '.$return_indicator_class.'">'.$return_indicator.'</div>';
          }
          echo '<div class="cell" data-label="Customer"><b>'.h($r['full_name']).'</b><div class="muted">Rental #'.(int)$r['id'].'</div></div>';
          echo '<div class="cell" data-label="Vehicle"><div><b>'.h($r['make_model']).'</b></div><div class="muted">Plate: '.h($r['plate_no']).'</div></div>';
          echo '<div class="cell" data-label="Schedule"><div><b>'.h($r['start_date']).'</b> → <b>'.h($r['end_date']).'</b></div><div class="muted">'.(int)$days.' day(s)</div></div>';
          echo '<div class="cell financial" data-label="Cost">';
          echo '<div class="total">₱'.hnum($total_amount,2).'</div>';
          echo '<div class="balance">Rate: ₱'.hnum($r['daily_rate'],2).'/day</div>';
          echo '<div class="down">Required Down: ₱'.hnum($required_down,2).' • Paid: ₱'.hnum($paid_amount,2).' • Balance: ₱'.hnum($balance_calc,2).'</div>';
          echo '</div>';
          echo '<div class="cell" data-label="Status">';

          // Status chip + advance hints
          echo '<div class="status '.$sclass.'">'.ucfirst($st);
          if($isAdvance) echo ' <span class="future">Advance</span> <span class="countdown" data-start="'.h($r['start_date']).'">—</span>';
          echo '</div>';

          // Payment readiness badges (pending rentals)
          if($st==='pending'){
            if ($paid_amount <= 0.00001) {
              echo '<div class="pay-badge pay-awaiting">Awaiting Downpayment</div>';
            } elseif ($paid_amount + 0.00001 < $required_down) {
              echo '<div class="pay-badge pay-partial">Partial Downpayment</div>';
            } else {
              echo '<div class="pay-badge pay-ready">Ready for Approval</div>';
            }
          }

          // Actions
          echo '<div class="actions-stack">';
          echo '<button class="btn btn-secondary" type="button" onclick="openRentalDrawer(this)">View</button>';
          if($st==='pending'){
            // Determine initial status based on start date
            $initialStatus = (strtotime($r['start_date']) > strtotime($today)) ? 'reserved' : 'ongoing';
            $canApprove = ($paid_amount + 0.00001 >= $required_down);
            echo '<button class="btn paybtn" type="button" onclick="openPaymentModal('.(int)$r['id'].','.(float)$required_down.','.(float)$paid_amount.')">Record Payment</button>';
            echo '<form method="post"><input type="hidden" name="rental_id" value="'.(int)$r['id'].'">';
            echo '<input type="hidden" name="vehicle_id" value="'.(int)$r['vehicle_id'].'">';
            echo '<input type="hidden" name="new_status" value="'.$initialStatus.'">';
            echo '<button class="btn btn-approve" '.($canApprove?'':'disabled title="Downpayment not met"').'>Approve</button></form>';

            echo '<form method="post"><input type="hidden" name="rental_id" value="'.(int)$r['id'].'">';
            echo '<input type="hidden" name="vehicle_id" value="'.(int)$r['vehicle_id'].'">';
            echo '<input type="hidden" name="new_status" value="cancelled">';
            echo '<button class="btn btn-cancel">Cancel</button></form>';
          } elseif($st==='reserved' && !$isAdvance){
            echo '<form method="post"><input type="hidden" name="rental_id" value="'.(int)$r['id'].'">';
            echo '<input type="hidden" name="vehicle_id" value="'.(int)$r['vehicle_id'].'">';
            echo '<input type="hidden" name="new_status" value="ongoing">';
            echo '<button class="btn btn-approve">Start Rental</button></form>';

            echo '<form method="post"><input type="hidden" name="rental_id" value="'.(int)$r['id'].'">';
            echo '<input type="hidden" name="vehicle_id" value="'.(int)$r['vehicle_id'].'">';
            echo '<input type="hidden" name="new_status" value="cancelled">';
            echo '<button class="btn btn-cancel">Cancel</button></form>';
          } elseif($st==='reserved' && $isAdvance){
            echo '<span class="muted">Awaiting start</span>';
            echo '<form method="post"><input type="hidden" name="rental_id" value="'.(int)$r['id'].'">';
            echo '<input type="hidden" name="vehicle_id" value="'.(int)$r['vehicle_id'].'">';
            echo '<input type="hidden" name="new_status" value="cancelled">';
            echo '<button class="btn btn-cancel">Cancel</button></form>';
          } elseif($st==='ongoing'){
            $returnBtn = '<button class="btn btn-return" type="button" onclick="openReturnModal('.(int)$r['id'].',\''.h($r['make_model']).'\',\''.h($r['plate_no']).'\',\''.h($r['end_date']).'\',\''.h($r['end_time']??'23:59:59').'\','.(int)$r['vehicle_id'].',\''.h($r['vehicle_type']).'\')">Return</button>';
            echo $returnBtn;

            echo '<button class="btn btn-extend" type="button" onclick="openExtendModalFromRow(this)">Extend</button>';
          } elseif($st==='completed'){
            echo '<span class="muted" style="color:#7cffc7;font-weight:600;font-size:.7rem;">Completed</span>';
            echo '<button class="btn btn-receipt" type="button" onclick="viewReceipt('.(int)$r['id'].')">Receipt</button>';
          } else {
            echo '<span class="muted">—</span>';
          }
          echo '</div>'; // actions

          echo   '</div>'; // status/actions cell
          echo '</div>';   // row
        }
      }
      ?>
    </div>
  </div>
</div>

<!-- Details Drawer -->
<div class="drawer-bg" id="rentalDrawerBg" onclick="closeRentalDrawer()"></div>
<div class="drawer" id="rentalDrawer" role="dialog" aria-modal="true" aria-label="Rental details">
  <div class="drawer-head">
    <div>
      <div class="drawer-title" id="drawerTitle">Rental</div>
      <div class="drawer-sub" id="drawerSub">—</div>
    </div>
    <button class="drawer-close" type="button" onclick="closeRentalDrawer()">Close</button>
  </div>
  <div class="drawer-body" id="drawerBody"></div>
  <div class="drawer-actions" id="drawerActions"></div>
</div>

<!-- Toast -->
<div id="toast" class="toast"></div>

<!-- Record Payment Modal -->
<div class="modal-bg" id="paymentModal">
  <div class="modal pay-modal">
    <h2>Record Payment</h2>
    <div class="pay-grid">
      <div class="pay-card"><div class="k">Required Down</div><div class="v" id="payReq">₱0.00</div></div>
      <div class="pay-card"><div class="k">Paid</div><div class="v" id="payPaid">₱0.00</div></div>
      <div class="pay-card"><div class="k">This Payment</div><div class="v" id="payThis">₱0.00</div></div>
      <div class="pay-card"><div class="k">Remaining to Approve</div><div class="v" id="payRemain">₱0.00</div></div>
    </div>
    <form id="paymentForm" onsubmit="return submitPayment(event)">
      <input type="hidden" id="paymentRentalId" name="rental_id" value="">
      <input type="hidden" id="paymentAmount" name="amount" value="">

      <div class="pay-field">
        <label for="paymentAmountDisplay">Amount</label>
        <div class="pay-input">
          <span>₱</span>
          <input type="text" id="paymentAmountDisplay" inputmode="decimal" autocomplete="off" placeholder="0.00" required>
        </div>
      </div>

      <div class="pay-field">
        <label for="paymentMethod">Payment Method</label>
        <select id="paymentMethod" name="payment_method" required>
          <option value="Cash">Cash</option>
          <option value="GCash">GCash</option>
        </select>
      </div>

      <div class="pay-help" id="paymentHint">—</div>

      <div class="actions" style="margin-top:14px">
        <button class="btn btn-approve" type="submit" id="paymentSubmitBtn">Save Payment</button>
        <button type="button" class="btn btn-cancel" onclick="closePaymentModal()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Extend Modal (Redesigned) -->
<div class="modal-bg" id="extendModal">
  <div class="modal extend-modal">
    <div class="modal-header">
      <div class="modal-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
          <path d="M16 12h4m-2-2v4"/>
        </svg>
      </div>
      <h2>Extend Rental</h2>
      <p class="modal-subtitle">Extend the rental period for this vehicle</p>
    </div>

    <div class="rental-info-card">
      <div class="rental-info-header">
        <span class="rental-info-title">Rental Details</span>
      </div>
      <div class="rental-info-body">
        <div class="info-row">
          <div class="info-icon">👤</div>
          <div class="info-content">
            <label>Customer</label>
            <span id="extendCustomerName">—</span>
          </div>
        </div>
        <div class="info-row">
          <div class="info-icon">🚗</div>
          <div class="info-content">
            <label>Vehicle</label>
            <span id="extendVehicleInfo">—</span>
          </div>
        </div>
        <div class="info-row">
          <div class="info-icon">📅</div>
          <div class="info-content">
            <label>Current End Date</label>
            <span id="extendCurrentEnd" class="highlight-date">—</span>
          </div>
        </div>
        <div class="info-row">
          <div class="info-icon">💰</div>
          <div class="info-content">
            <label>Daily Rate</label>
            <span id="extendDailyRate" class="highlight-rate">—</span>
          </div>
        </div>
      </div>
    </div>

    <form method="post" id="extendForm" class="extend-form">
      <input type="hidden" name="rental_id" id="modalRental">
      <input type="hidden" name="vehicle_id" id="modalVehicle">

      <div class="form-section">
        <label for="new_end" class="form-label">
          <span class="label-icon">📅</span>
          New Return Date
        </label>
        <div class="enhanced-calendar-container">
          <!-- Calendar Header -->
          <div class="calendar-header">
            <button type="button" class="calendar-nav" id="prevMonth">‹</button>
            <div class="calendar-title">
              <select id="monthSelect" class="calendar-select"></select>
              <select id="yearSelect" class="calendar-select"></select>
            </div>
            <button type="button" class="calendar-nav" id="nextMonth">›</button>
          </div>
          
          <!-- Calendar Grid -->
          <div class="calendar-wrapper">
            <div class="calendar-weekdays">
              <span>Sun</span><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span>
            </div>
            <div class="calendar-days" id="calendarDays"></div>
          </div>
          
          <!-- Selected Date Display -->
          <div class="selected-date-display" id="selectedDateDisplay">
            <span class="no-date">Select a date from the calendar</span>
          </div>
          
          <!-- Hidden Input -->
          <input type="hidden" name="new_end" id="new_end" required>
          <div class="input-hint">Dates before the current end date are disabled</div>
        </div>
      </div>

      <div class="extension-summary" id="extensionSummary" style="display: none;">
        <div class="summary-header">
          <span class="summary-icon">📊</span>
          Extension Summary
        </div>
        <div class="summary-body">
          <div class="summary-row">
            <span>Additional Days</span>
            <span id="additionalDays" class="summary-value">0 days</span>
          </div>
          <div class="summary-row total">
            <span>Additional Cost</span>
            <span id="additionalCost" class="summary-value highlight">₱0.00</span>
          </div>
        </div>
      </div>

      <div class="actions">
        <button type="submit" class="btn btn-extend">
          <span class="btn-icon">✓</span>
          Confirm Extension
        </button>
        <button type="button" class="btn btn-cancel" onclick="closeModal()">
          <span class="btn-icon">✕</span>
          Cancel
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Return Inspection Modal -->
<div class="modal-bg" id="returnModal">
  <div class="modal return-modal">
    <h2 id="returnModalTitle">Return Vehicle</h2>
    
    <!-- Inspection Form -->
    <form method="post" id="returnForm">
      <input type="hidden" name="form_type" value="return_inspection">
      <input type="hidden" name="rental_id" id="returnRentalId">
      <input type="hidden" name="vehicle_type" id="returnVehicleType">
      <input type="hidden" id="returnStartOdometer" value="">
      <input type="hidden" id="returnHasOdometer" value="0">
      <input type="hidden" id="returnPaidAmount" value="0">
      <input type="hidden" id="returnBaseAmount" value="0">
      <input type="hidden" id="returnDailyRate" value="0">
      <input type="hidden" id="returnExpectedEnd" value="">
      
      <div class="pay-card" style="margin:12px 0;">
        <div class="k" style="margin-bottom:8px;">Summary</div>
        <div class="pay-grid" style="margin:0;">
          <div class="pay-card"><div class="k">Start Odometer</div><div class="v" id="sumStartOdo">—</div></div>
          <div class="pay-card"><div class="k">Return Odometer</div><div class="v" id="sumReturnOdo">—</div></div>
          <div class="pay-card"><div class="k">Estimated Distance</div><div class="v" id="sumDistance">—</div></div>
          <div class="pay-card"><div class="k">Late Fee</div><div class="v" id="sumLateFee">₱0.00</div></div>
          <div class="pay-card"><div class="k">Washing Fee</div><div class="v" id="sumWashFee">₱0.00</div></div>
          <div class="pay-card"><div class="k">Damage Fee</div><div class="v" id="sumDamageFee">₱0.00</div></div>
          <div class="pay-card"><div class="k">Final Total</div><div class="v" id="sumFinalTotal">₱0.00</div></div>
          <div class="pay-card"><div class="k">Paid</div><div class="v" id="sumPaid">₱0.00</div></div>
          <div class="pay-card"><div class="k">Remaining Balance</div><div class="v" id="sumRemaining">₱0.00</div></div>
        </div>
        <div class="pay-help" id="returnSummaryHint" style="margin-top:10px;">This is an estimate for review only.</div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label>Return Date</label>
          <input type="date" name="actual_return_date" id="actualReturnDate" value="<?= date('Y-m-d') ?>" required>
        </div>
        <div class="form-group">
          <label>Return Time</label>
          <input type="time" name="actual_return_time" id="actualReturnTime" value="12:00" min="00:00" max="23:59" required>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label>Return Odometer (km)</label>
          <input type="number" name="odometer_return" id="odometerReturn" required placeholder="Enter return odometer reading">
        </div>
        <div class="form-group">
          <label>Fuel Level</label>
          <select name="fuel_level" id="fuelLevel" required onchange="calculateFuelCharge()">
            <option value="full" selected>Full</option>
            <option value="3/4">3/4 Tank</option>
            <option value="half">Half Tank</option>
            <option value="1/4">1/4 Tank</option>
            <option value="empty">Empty</option>
          </select>
          <div id="fuelChargeDisplay" style="margin-top: 8px; padding: 8px; background: rgba(93, 208, 255, 0.1); border-radius: 6px; border-left: 3px solid var(--brand); display: none;">
            <span style="color: var(--brand); font-weight: 600;">Fuel Charge: ₱<span id="fuelChargeAmount">0.00</span></span>
          </div>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label>Vehicle Condition</label>
          <select name="return_condition" required>
            <option value="Excellent">Excellent</option>
            <option value="Good" selected>Good</option>
            <option value="Fair">Fair</option>
            <option value="Poor">Poor</option>
          </select>
        </div>
        <div class="form-group">
          <label>Cleanliness</label>
          <select name="cleanliness" id="cleanliness" required>
            <option value="clean" selected>Clean</option>
            <option value="dirty">Dirty</option>
            <option value="very_dirty">Very Dirty</option>
          </select>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label>Washing Type</label>
          <select name="washing_type" id="washingType" onchange="updateWashCost()">
            <option value="">Select washing type...</option>
          </select>
        </div>
        <div class="form-group">
          <label>Washing Cost</label>
          <input type="number" name="washing_cost" id="washingCost" value="0" step="0.01" min="0" readonly style="background-color: #2a2a2a; color: #5dd0ff; font-weight: 600;">
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label>
            <input type="checkbox" id="veryDirtyCheck" onchange="autoSelectWashing()"> 
            Very Dirty - Auto-select highest washing rate
          </label>
        </div>
        <div class="form-group">
          <div id="washSummary" style="color: #5dd0ff; font-weight: 600; margin-top: 8px;"></div>
        </div>
      </div>

      <div class="form-group">
        <label>Issues or Damage</label>
        <textarea name="damage_report" id="damageReport" rows="2" placeholder="Describe any damage or issues found (optional)"></textarea>
      </div>

      <div class="form-group">
        <label>Additional Notes</label>
        <textarea name="additional_notes" id="additionalNotes" rows="2" placeholder="Any additional notes or observations (optional)"></textarea>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label>Damage Fee (₱)</label>
          <input type="number" step="0.01" name="damage_fee" id="damageFee" value="0" min="0" placeholder="0.00">
        </div>
        <div class="form-group">
          <!-- Empty div for layout balance -->
        </div>
      </div>

      <div class="actions">
        <button type="submit" class="btn btn-approve" id="submitReturnBtn">
          <span id="submitText">Complete Return</span>
          <span id="submitSpinner" style="display: none;">⏳ Processing...</span>
        </button>
        <button type="button" class="btn btn-cancel" onclick="closeReturnModal()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Return Receipt Modal -->
<div class="modal-bg" id="receiptModal">
  <div class="modal receipt-modal">
    <div class="receipt-header">
      <h2>Return Receipt</h2>
      <div class="receipt-info">
        <div id="receiptVehicleInfo"></div>
        <div id="receiptCustomerInfo"></div>
        <div id="receiptDateInfo"></div>
      </div>
    </div>
    
    <div class="receipt-body">
      <div class="receipt-section">
        <h3>Vehicle Condition</h3>
        <div class="condition-grid">
          <div class="condition-item">
            <span class="label">Fuel Level:</span>
            <span class="value" id="receiptFuelLevel"></span>
          </div>
          <div class="condition-item">
            <span class="label">Vehicle Condition:</span>
            <span class="value" id="receiptCondition"></span>
          </div>
          <div class="condition-item">
            <span class="label">Cleanliness:</span>
            <span class="value" id="receiptCleanliness"></span>
          </div>
          <div class="condition-item">
            <span class="label">Odometer:</span>
            <span class="value" id="receiptOdometer"></span>
          </div>
          <div class="condition-item" id="washingItem" style="display:none;">
            <span class="label">Washing Type:</span>
            <span class="value" id="receiptWashingType"></span>
          </div>
        </div>
      </div>

      <div class="receipt-section" id="damageSection" style="display:none;">
        <h3>Issues & Damage</h3>
        <div class="damage-report" id="receiptDamageReport"></div>
      </div>

      <div class="receipt-section">
        <h3>Rental Summary</h3>
        <div class="rental-summary">
          <div class="summary-item">
            <span class="label">Daily Rate:</span>
            <span class="value" id="receiptDailyRate"></span>
          </div>
          <div class="summary-item">
            <span class="label">Total Rental Cost:</span>
            <span class="value" id="receiptTotalCost"></span>
          </div>
          <div class="summary-item">
            <span class="label">Downpayment (50%):</span>
            <span class="value" id="receiptDownpayment"></span>
          </div>
        </div>
      </div>

      <div class="receipt-section">
        <h3>Charges Breakdown</h3>
        <div class="cost-breakdown">
          <div class="cost-item">
            <span class="label">Original Balance Due:</span>
            <span class="value" id="receiptOriginalBalance"></span>
          </div>
          <div class="cost-item" id="penaltyItem" style="display:none;">
            <span class="label">Late Return Fee:</span>
            <span class="value penalty" id="receiptPenalty"></span>
          </div>
          <div class="cost-item" id="lateFeeBreakdown" style="display:none;">
            <span class="label">Late Fee Calculation:</span>
            <span class="value breakdown" id="receiptLateFeeBreakdown"></span>
          </div>
          <div class="cost-item" id="washingCostItem" style="display:none;">
            <span class="label">Washing Cost:</span>
            <span class="value" id="receiptWashingCost"></span>
          </div>
          <div class="cost-item" id="fuelPenaltyItem" style="display:none;">
            <span class="label">Fuel Charge:</span>
            <span class="value penalty" id="receiptFuelPenalty"></span>
          </div>
          <div class="cost-item" id="conditionItem" style="display:none;">
            <span class="label">Condition Adjustment:</span>
            <span class="value penalty" id="receiptConditionAdjustment"></span>
          </div>
          <div class="cost-item" id="carwashItem" style="display:none;">
            <span class="label">Carwash Fee:</span>
            <span class="value" id="receiptCarwashFee"></span>
          </div>
          <div class="cost-item" id="damageItem" style="display:none;">
            <span class="label">Damage Fee:</span>
            <span class="value" id="receiptDamageFee"></span>
          </div>
          <div class="cost-divider"></div>
          <div class="cost-item total">
            <span class="label">Final Amount Due:</span>
            <span class="value" id="receiptFinalCost"></span>
          </div>
          <div class="payment-status">
            <span class="label">Payment Status:</span>
            <span class="value status" id="receiptPaymentStatus"></span>
          </div>
        </div>
      </div>
    </div>

    <div class="receipt-footer">
      <div class="receipt-actions">
        <button class="btn btn-primary" onclick="printReceipt()">Print Receipt</button>
        <button class="btn btn-secondary" onclick="closeReceiptModal()">Close</button>
      </div>
      <div class="receipt-note">
        <p>Thank you for using FleetGo! Please keep this receipt for your records.</p>
      </div>
    </div>
  </div>
</div>

<script>
/* ===== Admin Notifications for Return Due Rentals ===== */
function checkReturnDueNotifications() {
  // Check for rentals that are due soon or overdue
  fetch('?ajax=check_return_due_notifications')
    .then(response => response.json())
    .then(data => {
      if(data.success && data.notifications && data.notifications.length > 0) {
        data.notifications.forEach(notification => {
          // Show toast notification for each return due rental
          showToast(notification.message, notification.type === 'overdue' ? false : true);
          
          // Also create a persistent notification if needed
          if(notification.type === 'overdue') {
            createPersistentNotification(notification);
          }
        });
      }
    })
    .catch(error => {
      console.error('Error checking return due notifications:', error);
    });
}

function createPersistentNotification(notification) {
  // Create a persistent notification banner at the top of the page
  const banner = document.createElement('div');
  banner.className = 'notification-banner overdue';
  banner.innerHTML = `
    <div class="notification-content">
      <span class="notification-icon">⚠️</span>
      <span class="notification-text">${notification.message}</span>
      <button class="notification-close" onclick="this.parentElement.parentElement.remove()">×</button>
    </div>
  `;
  
  // Insert at the top of the page
  const container = document.querySelector('.container');
  if(container) {
    container.insertBefore(banner, container.firstChild);
  }
}

// Check notifications on page load
document.addEventListener('DOMContentLoaded', function() {
  // Check return due notifications
  setTimeout(checkReturnDueNotifications, 1000);
  
  // Check every 5 minutes for new notifications
  setInterval(checkReturnDueNotifications, 300000);
});

/* Toasts */
const toastEl=document.getElementById('toast');
function showToast(msg, ok=true){
  toastEl.textContent=msg;
  toastEl.style.borderColor = ok ? 'rgba(124,255,199,.35)' : 'rgba(255,107,107,.45)';
  toastEl.style.display='block';
  setTimeout(()=> toastEl.style.display='none', 2400);
}
(function bootToast(){
  const u=new URLSearchParams(location.search);
  if(u.get('ok')) showToast('Status updated successfully');
  if(u.get('extended')) showToast('Rental extended successfully');
  if(u.get('error')==='downpayment_not_met') {
    const m = u.get('msg') || 'Downpayment not met.';
    showToast(decodeURIComponent(m), false);
  }
  if(u.get('success')==='return_completed') {
    showToast('Return inspection completed successfully!');
    // Show receipt modal if data is available
    <?php if(isset($_SESSION['return_receipt_data'])): ?>
    console.log('Receipt data available:', <?= json_encode($_SESSION['return_receipt_data']) ?>);
    setTimeout(() => {
      showReceiptModal(<?= json_encode($_SESSION['return_receipt_data']) ?>);
    }, 500);
    <?php else: ?>
    console.log('No receipt data found in session');
    <?php endif; ?>
  }
  if(u.get('error')==='missing_data') showToast('Missing required data (rental ID or odometer)', false);
  if(u.get('error')==='rental_not_found') showToast('Rental not found. Please refresh the page.', false);
  if(u.get('error')==='invalid_rental_status') {
    const status = u.get('status') || 'unknown';
    showToast(`Cannot return rental with status: ${status}. Only ongoing rentals can be returned.`, false);
  }
  if(u.get('error')==='already_returned') {
    showToast('This rental has already been returned. Please refresh the page.', false);
  }
  if(u.get('error')==='maintenance_conflict') {
    showToast('Cannot complete return due to maintenance conflict. Please check maintenance schedule.', false);
  }
  if(u.get('error')==='return_failed') {
    const errorMsg = u.get('msg') || 'Return inspection failed';
    showToast(errorMsg, false);
  }
  if(u.get('error')==='payment_required') {
    showToast('Final payment required before completion. Please record payment first.', false);
  }
})();

/* Filter and Search Functions */
function money(n){
  const num = Number(n || 0);
  return '₱' + num.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

/* Record Payment modal */
const paymentModal = document.getElementById('paymentModal');
function openPaymentModal(rentalId, requiredDown = 0, paidAmount = 0){
  if(!paymentModal) return;
  paymentModal.style.display = 'flex';
  document.getElementById('paymentRentalId').value = String(rentalId || '');
  document.getElementById('paymentAmount').value = '';
  const display = document.getElementById('paymentAmountDisplay');
  if(display) display.value = '';
  document.getElementById('paymentMethod').value = 'Cash';
  paymentModal.dataset.requiredDown = String(Number(requiredDown || 0));
  paymentModal.dataset.paidAmount = String(Number(paidAmount || 0));

  const reqEl = document.getElementById('payReq');
  const paidEl = document.getElementById('payPaid');
  const thisEl = document.getElementById('payThis');
  const remEl = document.getElementById('payRemain');
  if(reqEl) reqEl.textContent = money(requiredDown);
  if(paidEl) paidEl.textContent = money(paidAmount);
  if(thisEl) thisEl.textContent = money(0);
  const remain0 = Math.max(0, Number(requiredDown || 0) - Number(paidAmount || 0));
  if(remEl) remEl.textContent = money(remain0);

  const hint = document.getElementById('paymentHint');
  if(hint){
    hint.textContent = `Required Down: ${money(requiredDown)} • Paid: ${money(paidAmount)}`;
  }
}
function closePaymentModal(){
  if(!paymentModal) return;
  paymentModal.style.display = 'none';
}
window.addEventListener('click', e => { if(e.target === paymentModal) closePaymentModal(); });

function parseMoneyInput(v){
  const s = String(v || '').replace(/[^0-9.]/g,'');
  if(!s) return 0;
  const parts = s.split('.');
  const clean = parts.length > 1 ? (parts[0] + '.' + parts.slice(1).join('')) : parts[0];
  const n = Number(clean);
  return Number.isFinite(n) ? n : 0;
}

function formatMoneyInput(n){
  const num = Number(n || 0);
  if(!Number.isFinite(num)) return '';
  return num.toLocaleString(undefined,{minimumFractionDigits:2, maximumFractionDigits:2});
}

function updatePaymentPreview(){
  if(!paymentModal) return;
  const requiredDown = Number(paymentModal.dataset.requiredDown || 0);
  const paidAmount = Number(paymentModal.dataset.paidAmount || 0);
  const display = document.getElementById('paymentAmountDisplay');
  const raw = parseMoneyInput(display ? display.value : '');
  const thisEl = document.getElementById('payThis');
  const remEl = document.getElementById('payRemain');
  if(thisEl) thisEl.textContent = money(raw);
  const remaining = Math.max(0, requiredDown - (paidAmount + raw));
  if(remEl) remEl.textContent = money(remaining);
  const hidden = document.getElementById('paymentAmount');
  if(hidden) hidden.value = String(raw);
}

document.addEventListener('input', function(e){
  if(e.target && e.target.id === 'paymentAmountDisplay'){
    updatePaymentPreview();
  }
});

document.addEventListener('blur', function(e){
  if(e.target && e.target.id === 'paymentAmountDisplay'){
    const n = parseMoneyInput(e.target.value);
    e.target.value = n ? formatMoneyInput(n) : '';
    updatePaymentPreview();
  }
}, true);

async function submitPayment(event){
  event.preventDefault();
  const form = event.target;
  updatePaymentPreview();
  const formData = new FormData(form);
  const btn = document.getElementById('paymentSubmitBtn');
  if(btn) btn.disabled = true;

  try {
    const res = await fetch('includes/ajax_record_payment.php', {
      method: 'POST',
      body: formData
    });
    const data = await res.json();
    if(data.success){
      showToast(data.message || 'Payment recorded', true);
      closePaymentModal();
      await refreshRentals();
    } else {
      showToast(data.message || 'Failed to record payment', false);
    }
  } catch(e) {
    showToast('Failed to record payment. Please try again.', false);
  } finally {
    if(btn) btn.disabled = false;
  }
  return false;
}

function parseRowRental(row){
  const raw = row.getAttribute('data-rental') || '{}';
  try { return JSON.parse(raw); } catch(e) { return {}; }
}

function updateSummary(){
  const rows = Array.from(document.querySelectorAll('.row')).filter(r => r.style.display !== 'none');
  let shown = 0;
  let totalValue = 0;
  let totalBalance = 0;
  let overdue = 0;
  const todayStr = new Date().toISOString().slice(0,10);

  rows.forEach(row => {
    const r = parseRowRental(row);
    shown += 1;
    totalValue += Number(r.total || 0);
    totalBalance += Number(r.balance_due || 0);
    if((r.status || '').toLowerCase() === 'ongoing' && (r.end_date || '') && (r.end_date < todayStr)) {
      overdue += 1;
    }
  });

  const shownEl = document.getElementById('sumShown');
  const totalEl = document.getElementById('sumTotal');
  const balEl = document.getElementById('sumBalance');
  const overdueEl = document.getElementById('sumOverdue');

  if(shownEl) shownEl.textContent = String(shown);
  if(totalEl) totalEl.textContent = money(totalValue);
  if(balEl) balEl.textContent = money(totalBalance);
  if(overdueEl) overdueEl.textContent = String(overdue);
}

function openRentalDrawer(btn){
  const row = btn?.closest('.row');
  if(!row) return;
  const raw = row.getAttribute('data-rental') || '{}';
  let r = {};
  try { r = JSON.parse(raw); } catch(e) { r = {}; }

  document.getElementById('drawerTitle').textContent = `Rental #${r.id || ''}`;
  document.getElementById('drawerSub').textContent = `${r.customer || ''} • ${r.vehicle || ''} (${r.plate || ''})`;

  const body = document.getElementById('drawerBody');
  body.innerHTML = `
    <div class="kv"><div class="k">Status</div><div class="v">${(r.status || '').toString().toUpperCase()}</div></div>
    <div class="kv"><div class="k">Schedule</div><div class="v">${r.start_date || ''} → ${r.end_date || ''}</div></div>
    <div class="kv"><div class="k">Days</div><div class="v">${r.days || 0}</div></div>
    <div class="kv"><div class="k">Daily Rate</div><div class="v">${money(r.daily_rate)}</div></div>
    <div class="kv"><div class="k">Total</div><div class="v">${money(r.total)}</div></div>
    <div class="kv"><div class="k">Required Down</div><div class="v">${money(r.downpayment)}</div></div>
    <div class="kv"><div class="k">Paid</div><div class="v">${money(r.paid_amount)}</div></div>
    <div class="kv"><div class="k">Balance</div><div class="v">${money(r.balance_due)}</div></div>
    <div class="kv"><div class="k">Vehicle Type</div><div class="v">${r.vehicle_type || '—'}</div></div>
  `;

  const actions = document.getElementById('drawerActions');
  actions.innerHTML = '';
  const closeBtn = document.createElement('button');
  closeBtn.className = 'btn btn-secondary';
  closeBtn.type = 'button';
  closeBtn.textContent = 'Close';
  closeBtn.onclick = closeRentalDrawer;
  actions.appendChild(closeBtn);

  document.getElementById('rentalDrawerBg').classList.add('open');
  document.getElementById('rentalDrawer').classList.add('open');
}

function closeRentalDrawer(){
  document.getElementById('rentalDrawerBg')?.classList.remove('open');
  document.getElementById('rentalDrawer')?.classList.remove('open');
}

document.addEventListener('keydown', function(e){
  if(e.key === 'Escape') closeRentalDrawer();
});

function filterByStatus(status) {
  document.getElementById('statusFilter').value = status;
  applyFilters();
}

function applyFilters() {
  const searchTerm = document.getElementById('searchInput').value.toLowerCase();
  const statusFilter = document.getElementById('statusFilter').value;
  const startDateFilter = document.getElementById('startDateFilter').value;
  const endDateFilter = document.getElementById('endDateFilter').value;
  
  const rows = document.querySelectorAll('.row');
  
  rows.forEach(row => {
    const customer = row.querySelector('[data-label="Customer"]')?.textContent.toLowerCase() || '';
    const vehicle = row.querySelector('[data-label="Vehicle"]')?.textContent.toLowerCase() || '';
    const status = row.querySelector('.status')?.textContent.toLowerCase() || '';
    const startDate = row.getAttribute('data-start-date') || '';
    const endDate = row.getAttribute('data-end-date') || '';
    
    const matchesSearch = !searchTerm || customer.includes(searchTerm) || vehicle.includes(searchTerm);
    const matchesStatus = !statusFilter || status.includes(statusFilter);
    const matchesStartDate = !startDateFilter || startDate >= startDateFilter;
    const matchesEndDate = !endDateFilter || endDate <= endDateFilter;
    
    row.style.display = (matchesSearch && matchesStatus && matchesStartDate && matchesEndDate) ? '' : 'none';
  });

  updateSummary();
}

function resetFilters() {
  document.getElementById('searchInput').value = '';
  document.getElementById('statusFilter').value = '';
  document.getElementById('startDateFilter').value = '';
  document.getElementById('endDateFilter').value = '';
  
  // Show all rows
  document.querySelectorAll('.row').forEach(row => row.style.display = '');

  updateSummary();
}

function exportRentals() {
  const visibleRows = Array.from(document.querySelectorAll('.row')).filter(row => row.style.display !== 'none');
  const csvData = [];
  
  // Add header
  csvData.push(['Customer', 'Vehicle', 'Plate', 'Start Date', 'End Date', 'Rate', 'Total', 'Required Down', 'Paid', 'Balance', 'Status']);
  
  // Add data rows
  visibleRows.forEach(row => {
    const customer = row.querySelector('[data-label="Customer"]')?.textContent || '';
    const vehicle = row.querySelector('[data-label="Vehicle"]')?.textContent || '';
    const plate = (row.querySelector('[data-label="Vehicle"]')?.textContent.match(/Plate:\s*([^\n\r]+)/i)?.[1] || '').trim();
    const startDate = row.getAttribute('data-start-date') || '';
    const endDate = row.getAttribute('data-end-date') || '';
    const costText = row.querySelector('[data-label="Cost"]')?.textContent || '';
    const rate = (costText.match(/Rate:\s*₱?([0-9,\.]+)/i)?.[1] || '').trim();
    const total = (costText.match(/₱\s*([0-9,\.]+)/)?.[1] || '').trim();
    const requiredDown = (costText.match(/Required\s*Down:\s*₱?([0-9,\.]+)/i)?.[1] || '').trim();
    const paid = (costText.match(/Paid:\s*₱?([0-9,\.]+)/i)?.[1] || '').trim();
    const bal = (costText.match(/Balance:\s*₱?([0-9,\.]+)/i)?.[1] || '').trim();
    const status = row.querySelector('.status')?.textContent || '';
    
    csvData.push([customer, vehicle, plate, startDate, endDate, rate, total, requiredDown, paid, bal, status]);
  });
  
  // Convert to CSV and download
  const csvContent = csvData.map(row => row.map(cell => `"${cell}"`).join(',')).join('\n');
  const blob = new Blob([csvContent], { type: 'text/csv' });
  const url = window.URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = `rentals_export_${new Date().toISOString().split('T')[0]}.csv`;
  a.click();
  window.URL.revokeObjectURL(url);
}

// Add event listeners
document.addEventListener('DOMContentLoaded', function() {
  // Search input
  document.getElementById('searchInput').addEventListener('input', applyFilters);
  
  // Filter dropdowns
  document.getElementById('statusFilter').addEventListener('change', applyFilters);
  document.getElementById('startDateFilter').addEventListener('change', applyFilters);
  document.getElementById('endDateFilter').addEventListener('change', applyFilters);

  updateSummary();
});

/* Extend modal */
const modal=document.getElementById('extendModal');
// Open extend modal with data from the database
function openExtendModalFromRow(btn) {
  const row = btn.closest('.row');
  const rowData = JSON.parse(row.dataset.rental);
  const rentalId = rowData.id;

  // Show loading state
  showToast('Loading rental details...', true);

  // Fetch fresh rental details from database
  fetch(`includes/ajax_get_rental_details.php?rental_id=${rentalId}&t=${Date.now()}`)
    .then(response => {
      if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
      }
      return response.json();
    })
    .then(result => {
      if (result.success && result.data) {
        const data = result.data;

        // Populate modal fields with fresh data from database
        document.getElementById('modalRental').value = data.id;
        document.getElementById('modalVehicle').value = data.vehicle_id;
        document.getElementById('extendCustomerName').textContent = data.full_name || '—';
        document.getElementById('extendVehicleInfo').textContent = `${data.make_model || '—'} (${data.plate_no || '—'})`;
        document.getElementById('extendCurrentEnd').textContent = data.end_date;
        document.getElementById('extendDailyRate').textContent = '₱' + parseFloat(data.daily_rate || 0).toLocaleString();

        // Set minimum date to day after current end date
        const currentEnd = new Date(data.end_date);
        currentEnd.setDate(currentEnd.getDate() + 1);
        const minDate = currentEnd.toISOString().split('T')[0];
        
        // Update calendar with min date
        updateCalendarMinDate(minDate);

        // Store daily rate for calculations
        window.extendDailyRate = parseFloat(data.daily_rate || 0);
        window.extendCurrentEnd = data.end_date;

        // Reset summary
        document.getElementById('extensionSummary').style.display = 'none';

        // Show modal
        document.getElementById('extendModal').style.display = 'flex';
      } else {
        showToast(result.message || 'Failed to load rental details', false);
      }
    })
    .catch(error => {
      console.error('Error fetching rental details:', error);
      showToast('Error loading rental details. Please try again.', false);

      // Fallback: use row data if fetch fails
      document.getElementById('modalRental').value = rowData.id;
      document.getElementById('modalVehicle').value = rowData.vehicle_id;
      document.getElementById('extendCustomerName').textContent = rowData.customer;
      document.getElementById('extendVehicleInfo').textContent = rowData.vehicle + ' (' + rowData.plate + ')';
      document.getElementById('extendCurrentEnd').textContent = rowData.end_date;
      document.getElementById('extendDailyRate').textContent = '₱' + parseFloat(rowData.daily_rate).toLocaleString();

      const currentEnd = new Date(rowData.end_date);
      currentEnd.setDate(currentEnd.getDate() + 1);
      const minDate = currentEnd.toISOString().split('T')[0];
      
      // Update calendar with min date
      updateCalendarMinDate(minDate);

      window.extendDailyRate = rowData.daily_rate;
      window.extendCurrentEnd = rowData.end_date;
      document.getElementById('extensionSummary').style.display = 'none';
      document.getElementById('extendModal').style.display = 'flex';
    });
}

// Calculate extension summary when date changes
document.getElementById('new_end')?.addEventListener('change', function() {
  calculateExtensionSummary();
});

function calculateExtensionSummary() {
  const newEndDate = new Date(document.getElementById('new_end').value);
  const currentEnd = new Date(window.extendCurrentEnd);

  if (newEndDate <= currentEnd) {
    showToast('New end date must be after current end date', false);
    document.getElementById('extensionSummary').style.display = 'none';
    return;
  }

  const diffTime = Math.abs(newEndDate - currentEnd);
  const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
  const additionalCost = diffDays * (window.extendDailyRate || 0);

  document.getElementById('additionalDays').textContent = diffDays + ' day' + (diffDays !== 1 ? 's' : '');
  document.getElementById('additionalCost').textContent = '₱' + additionalCost.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
  document.getElementById('extensionSummary').style.display = 'block';
}

// Enhanced Calendar Widget
let calendarCurrentDate = new Date();
let calendarSelectedDate = null;
let calendarMinDate = null;

function initCalendar() {
  const monthSelect = document.getElementById('monthSelect');
  const yearSelect = document.getElementById('yearSelect');
  
  // Populate month dropdown
  const months = ['January', 'February', 'March', 'April', 'May', 'June', 
                  'July', 'August', 'September', 'October', 'November', 'December'];
  months.forEach((month, index) => {
    const option = document.createElement('option');
    option.value = index;
    option.textContent = month;
    monthSelect.appendChild(option);
  });
  
  // Populate year dropdown (current year ± 5)
  const currentYear = new Date().getFullYear();
  for (let year = currentYear - 5; year <= currentYear + 5; year++) {
    const option = document.createElement('option');
    option.value = year;
    option.textContent = year;
    yearSelect.appendChild(option);
  }
  
  // Event listeners
  monthSelect.addEventListener('change', () => {
    calendarCurrentDate.setMonth(parseInt(monthSelect.value));
    renderCalendar();
  });
  
  yearSelect.addEventListener('change', () => {
    calendarCurrentDate.setFullYear(parseInt(yearSelect.value));
    renderCalendar();
  });
  
  document.getElementById('prevMonth').addEventListener('click', () => {
    calendarCurrentDate.setMonth(calendarCurrentDate.getMonth() - 1);
    renderCalendar();
  });
  
  document.getElementById('nextMonth').addEventListener('click', () => {
    calendarCurrentDate.setMonth(calendarCurrentDate.getMonth() + 1);
    renderCalendar();
  });
}

function renderCalendar() {
  const year = calendarCurrentDate.getFullYear();
  const month = calendarCurrentDate.getMonth();
  
  // Update dropdowns
  document.getElementById('monthSelect').value = month;
  document.getElementById('yearSelect').value = year;
  
  const firstDay = new Date(year, month, 1).getDay();
  const daysInMonth = new Date(year, month + 1, 0).getDate();
  const daysInPrevMonth = new Date(year, month, 0).getDate();
  
  const calendarDays = document.getElementById('calendarDays');
  calendarDays.innerHTML = '';
  
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  
  // Previous month days
  for (let i = firstDay - 1; i >= 0; i--) {
    const day = daysInPrevMonth - i;
    const button = createDayButton(day, true);
    calendarDays.appendChild(button);
  }
  
  // Current month days
  for (let day = 1; day <= daysInMonth; day++) {
    const date = new Date(year, month, day);
    const button = createDayButton(day, false, date);
    
    // Check if today
    if (date.getTime() === today.getTime()) {
      button.classList.add('today');
    }
    
    // Check if selected
    if (calendarSelectedDate && date.getTime() === calendarSelectedDate.getTime()) {
      button.classList.add('selected');
    }
    
    // Check if disabled (before min date)
    if (calendarMinDate && date < calendarMinDate) {
      button.classList.add('disabled');
    }
    
    // Highlight extension range
    if (window.extendCurrentEnd && calendarSelectedDate) {
      const currentEnd = new Date(window.extendCurrentEnd);
      if (date > currentEnd && date < calendarSelectedDate) {
        button.classList.add('extension-range');
      } else if (date.getTime() === currentEnd.getTime()) {
        button.classList.add('extension-start');
      } else if (date.getTime() === calendarSelectedDate.getTime()) {
        button.classList.add('extension-end');
      }
    }
    
    button.addEventListener('click', () => selectDate(date));
    calendarDays.appendChild(button);
  }
  
  // Next month days to fill grid
  const totalCells = Math.ceil((firstDay + daysInMonth) / 7) * 7;
  const remainingCells = totalCells - (firstDay + daysInMonth);
  for (let day = 1; day <= remainingCells; day++) {
    const button = createDayButton(day, true);
    calendarDays.appendChild(button);
  }
}

function createDayButton(day, isOtherMonth, date) {
  const button = document.createElement('button');
  button.type = 'button';
  button.className = 'calendar-day';
  if (isOtherMonth) button.classList.add('other-month');
  button.textContent = day;
  return button;
}

function selectDate(date) {
  if (calendarMinDate && date < calendarMinDate) {
    showToast('Cannot select date before current end date', false);
    return;
  }
  
  calendarSelectedDate = date;
  const dateString = date.toISOString().split('T')[0];
  
  // Update hidden input
  document.getElementById('new_end').value = dateString;
  
  // Update display
  const display = document.getElementById('selectedDateDisplay');
  const formattedDate = date.toLocaleDateString('en-US', { 
    weekday: 'long', 
    year: 'numeric', 
    month: 'long', 
    day: 'numeric' 
  });
  display.innerHTML = `<span class="date-value">${formattedDate}</span>`;
  
  // Re-render to update highlighting
  renderCalendar();
  
  // Calculate extension summary
  calculateExtensionSummary();
}

// Update calendar min date when modal opens
function updateCalendarMinDate(minDate) {
  calendarMinDate = minDate ? new Date(minDate) : null;
  if (calendarMinDate) {
    calendarMinDate.setHours(0, 0, 0, 0);
  }
  calendarSelectedDate = null;
  document.getElementById('new_end').value = '';
  document.getElementById('selectedDateDisplay').innerHTML = '<span class="no-date">Select a date from the calendar</span>';
  calendarCurrentDate = new Date();
  renderCalendar();
}

// Initialize calendar on page load
document.addEventListener('DOMContentLoaded', initCalendar);

function closeModal() {
  document.getElementById('extendModal').style.display = 'none';
}

window.addEventListener('click', e => {
  if (e.target === document.getElementById('extendModal')) closeModal();
});

// Legacy openModal function (kept for compatibility)
function openModal(rentalId, vehicleId, vehicleModel, currentEnd) {
  modal.style.display='flex';
  document.getElementById('modalRental').value=rentalId;
  document.getElementById('modalVehicle').value=vehId;
  document.getElementById('oldEnd').textContent=oldEnd;
  const dateInput=document.getElementById('new_end');
  dateInput.min=currentEnd;
  dateInput.value=currentEnd;
  dateInput.min=oldEnd;
  dateInput.value=oldEnd;
}
function closeModal(){ modal.style.display='none'; }
window.addEventListener('click',e=>{ if(e.target===modal) closeModal(); });

/* Return modal */
const returnModal=document.getElementById('returnModal');
function openReturnModal(rentalId, vehicleModel, plateNo, expectedEndDate, expectedEndTime, vehicleId = null, vehicleType = null){
  console.log('Opening return modal for rental:', rentalId, 'vehicle:', vehicleId, 'type:', vehicleType);
  returnModal.style.display='flex';
  document.getElementById('returnRentalId').value = rentalId;
  document.getElementById('returnVehicleType').value = vehicleType || '';
  document.getElementById('returnModalTitle').textContent = `Return ${vehicleModel} (${plateNo})`;
  
  // Clear any existing error messages
  const existingError = document.getElementById('returnError');
  if (existingError) {
    existingError.remove();
  }
  
  // Set expected return date/time as default
  document.getElementById('actualReturnDate').value = expectedEndDate;
  
  // Fix time format - ensure it's in HH:MM format
  let timeValue = expectedEndTime;
  if (timeValue && timeValue !== '23:59:59') {
    // Convert to proper time format
    if (timeValue.includes(':')) {
      const timeParts = timeValue.split(':');
      timeValue = timeParts[0] + ':' + timeParts[1];
    }
  } else {
    timeValue = '12:00'; // Default to noon if no time specified
  }
  document.getElementById('actualReturnTime').value = timeValue;
  
  // Reset form fields
  document.getElementById('odometerReturn').value = '';
  document.getElementById('fuelLevel').value = '';
  document.getElementById('cleanliness').value = '';
  document.getElementById('damageReport').value = '';
  document.getElementById('damageFee').value = '';
  document.getElementById('additionalNotes').value = '';
  document.getElementById('washingType').innerHTML = '<option value="">Select washing type...</option>';
  document.getElementById('washingCost').value = '0';
  document.getElementById('veryDirtyCheck').checked = false;
  
  // Load return summary (start odometer, paid, base amount) for validation + preview
  fetch(`?ajax=get_return_summary&rental_id=${encodeURIComponent(String(rentalId))}`)
    .then(r => r.json())
    .then(data => {
      if(data.error) {
        console.error('Error loading return summary:', data.error);
        return;
      }
      const s = data;
      const startOdo = (s.start_odometer === null || typeof s.start_odometer === 'undefined') ? null : Number(s.start_odometer);
      const hasReturn = (s.return_odometer !== null && typeof s.return_odometer !== 'undefined');

      document.getElementById('returnStartOdometer').value = (startOdo === null) ? '' : String(startOdo);
      document.getElementById('returnHasOdometer').value = hasReturn ? '1' : '0';
      document.getElementById('returnPaidAmount').value = String(Number(s.paid_amount || 0));
      document.getElementById('returnBaseAmount').value = String(Number(s.base_amount || 0));
      document.getElementById('returnDailyRate').value = String(Number(s.daily_rate || 0));
      document.getElementById('returnExpectedEnd').value = `${s.end_date || ''} ${((s.end_time || '') + '').slice(0,5) || '18:00'}`;

      const sumStart = document.getElementById('sumStartOdo');
      const sumPaid = document.getElementById('sumPaid');
      if(sumStart) sumStart.textContent = (startOdo === null) ? '—' : String(startOdo);
      if(sumPaid) sumPaid.textContent = money(Number(s.paid_amount || 0));

      const odoInput = document.getElementById('odometerReturn');
      if(odoInput && startOdo !== null) {
        odoInput.min = String(startOdo);
      }

      // Populate washing types if available
      if(s.washing_types && s.washing_types.length > 0) {
        const washSelect = document.getElementById('washingType');
        washSelect.innerHTML = '<option value="">Select washing type...</option>';
        s.washing_types.forEach(wash => {
          const option = document.createElement('option');
          option.value = wash.id;
          option.textContent = `${wash.type} - ₱${wash.cost}`;
          option.dataset.cost = wash.cost;
          washSelect.appendChild(option);
        });
      }

      // Store fuel rates for calculations
      if(s.fuel_rates) {
        window.returnFuelRates = s.fuel_rates;
      }

      updateReturnSummaryPreview();
      if(hasReturn) {
        const hint = document.getElementById('returnSummaryHint');
        if(hint) hint.textContent = 'Return already recorded. Submission is disabled.';
        const btn = document.getElementById('submitReturnBtn');
        if(btn) btn.disabled = true;
      }
    })
    .catch(e => {
      console.error('Failed to load return summary:', e);
    });

  // Load washing types based on vehicle info (no odometer math here)
  if (vehicleId) {
    fetch(`includes/get_vehicle_info.php?vehicle_id=${vehicleId}`)
      .then(response => response.json())
      .then(data => {
        if(data.success && data.vehicle) {
          if(data.vehicle.vehicle_type) {
            document.getElementById('returnVehicleType').value = data.vehicle.vehicle_type;
            console.log('Vehicle type set to:', data.vehicle.vehicle_type);
            loadWashingTypesForReturn(data.vehicle.vehicle_type);
          }
        } else {
          console.log('Vehicle info fetch failed:', data.error || 'Unknown error');
          loadWashingTypesForReturn('all');
        }
      })
      .catch(e => {
        console.log('Could not fetch vehicle info:', e);
        loadWashingTypesForReturn('all');
      });
  } else {
    loadWashingTypesForReturn('all');
  }

  // Calculate initial fuel charge
  setTimeout(() => {
    calculateFuelCharge();
  }, 500);
}

function calcLateFeePreview(){
  const expectedStr = document.getElementById('returnExpectedEnd')?.value || '';
  const date = document.getElementById('actualReturnDate')?.value || '';
  const time = document.getElementById('actualReturnTime')?.value || '';
  if(!expectedStr || !date || !time) return 0;

  const expected = new Date(expectedStr.replace(' ', 'T'));
  const actual = new Date(`${date}T${time}`);
  if(isNaN(expected.getTime()) || isNaN(actual.getTime())) return 0;
  if(actual <= expected) return 0;
  const hoursLate = (actual.getTime() - expected.getTime()) / 3600000;
  const dailyRate = Number(document.getElementById('returnDailyRate')?.value || 0);
  if(!dailyRate || hoursLate <= 0) return 0;
  const hourly = dailyRate / 24;
  return Math.round((hourly * hoursLate * 1.25) * 100) / 100;
}

function updateReturnSummaryPreview(){
  const startOdoVal = document.getElementById('returnStartOdometer')?.value;
  const startOdo = startOdoVal ? Number(startOdoVal) : null;
  const returnOdo = Number(document.getElementById('odometerReturn')?.value || 0);

  const washFee = Number(document.getElementById('washingCost')?.value || 0);
  const dmgFee = Number(document.getElementById('damageFee')?.value || 0);
  const baseAmount = Number(document.getElementById('returnBaseAmount')?.value || 0);
  const paid = Number(document.getElementById('returnPaidAmount')?.value || 0);
  const lateFee = calcLateFeePreview();

  const sumReturn = document.getElementById('sumReturnOdo');
  const sumDist = document.getElementById('sumDistance');
  const sumLate = document.getElementById('sumLateFee');
  const sumWash = document.getElementById('sumWashFee');
  const sumDmg = document.getElementById('sumDamageFee');
  const sumTotal = document.getElementById('sumFinalTotal');
  const sumRem = document.getElementById('sumRemaining');

  if(sumReturn) sumReturn.textContent = returnOdo > 0 ? String(returnOdo) : '—';
  if(sumDist) {
    if(startOdo !== null && returnOdo > 0) {
      const dist = returnOdo - startOdo;
      sumDist.textContent = (dist >= 0) ? `${dist.toFixed(1)} km` : '—';
    } else {
      sumDist.textContent = '—';
    }
  }
  if(sumLate) sumLate.textContent = money(lateFee);
  if(sumWash) sumWash.textContent = money(washFee);
  if(sumDmg) sumDmg.textContent = money(dmgFee);

  const finalTotal = baseAmount + lateFee + washFee + dmgFee;
  const remaining = Math.max(0, finalTotal - paid);
  if(sumTotal) sumTotal.textContent = money(finalTotal);
  if(sumRem) sumRem.textContent = money(remaining);
}

document.addEventListener('input', function(e){
  if(!returnModal) return;
  const id = e.target && e.target.id;
  if(['odometerReturn','washingCost','damageFee','actualReturnDate','actualReturnTime'].includes(id)) {
    updateReturnSummaryPreview();
  }
});

document.addEventListener('change', function(e){
  if(!returnModal) return;
  const id = e.target && e.target.id;
  if(['washingType','actualReturnDate','actualReturnTime'].includes(id)) {
    updateReturnSummaryPreview();
  }
});

// Frontend validation + double-submit protection for normal form submission
document.getElementById('returnForm')?.addEventListener('submit', function(e){
  const hasOdo = document.getElementById('returnHasOdometer')?.value === '1';
  if(hasOdo){
    e.preventDefault();
    alert('Return already recorded.');
    return;
  }

  const startVal = document.getElementById('returnStartOdometer')?.value;
  const startOdo = startVal ? Number(startVal) : null;
  const returnOdo = Number(document.getElementById('odometerReturn')?.value || 0);
  if(startOdo !== null && returnOdo && returnOdo < startOdo){
    e.preventDefault();
    alert('Return odometer must be greater than or equal to start odometer');
    return;
  }

  const btn = document.getElementById('submitReturnBtn');
  const txt = document.getElementById('submitText');
  const spn = document.getElementById('submitSpinner');
  if(btn) btn.disabled = true;
  if(txt) txt.style.display = 'none';
  if(spn) spn.style.display = 'inline';
});
function closeReturnModal(){ returnModal.style.display='none'; }

window.addEventListener('click',e=>{ if(e.target===returnModal) closeReturnModal(); });

/* Washing functionality */
// Load washing types for return modal based on vehicle type
async function loadWashingTypesForReturn(vehicleType) {
  console.log('=== LOADING WASHING TYPES ===');
  console.log('Vehicle type:', vehicleType);
  
  try {
    const url = `?ajax=get_washing_types&vehicle_type=${vehicleType}`;
    console.log('Fetching from URL:', url);
    
    const response = await fetch(url, {
      credentials: 'same-origin', // Include session cookies
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json'
      }
    });
    
    console.log('Response status:', response.status);
    console.log('Response headers:', response.headers);
    
    if (!response.ok) {
      throw new Error(`HTTP error! status: ${response.status}`);
    }
    
    const data = await response.json();
    console.log('Washing types response:', data);
    
    const washSelect = document.getElementById('washingType');
    if (!washSelect) {
      console.error('Washing type select element not found!');
      return;
    }
    
    // Clear existing options
    washSelect.innerHTML = '<option value="">Select washing type...</option>';
    
    // Add washing options
    if (data.success && data.washing_types && data.washing_types.length > 0) {
      console.log(`Found ${data.washing_types.length} washing types`);
      data.washing_types.forEach((wash, index) => {
        console.log(`Adding option ${index + 1}:`, wash);
        const option = document.createElement('option');
        option.value = wash.id;
        // Show vehicle type if loading all types, otherwise just show name and price
        if (vehicleType === 'all' && wash.vehicle_type) {
          option.textContent = `${wash.washing_name} (${wash.vehicle_type}) — ₱${wash.washing_rate}`;
        } else {
          option.textContent = `${wash.washing_name} — ₱${wash.washing_rate}`;
        }
        option.dataset.rate = wash.washing_rate;
        washSelect.appendChild(option);
      });
      console.log(`Successfully loaded ${data.washing_types.length} washing types for ${vehicleType}`);
    } else {
      console.log('No washing types found. Data:', data);
      // Add a fallback option if no washing types found
      const option = document.createElement('option');
      option.value = '';
      option.textContent = data.error || 'No washing options available';
      option.disabled = true;
      washSelect.appendChild(option);
      console.log('Added fallback option:', data.error || 'No washing options available');
    }
  } catch (error) {
    console.error('Error loading washing types:', error);
    // Add error option
    const washSelect = document.getElementById('washingType');
    if (washSelect) {
      washSelect.innerHTML = '<option value="">Error loading washing types</option>';
    }
  }
}

function updateWashCost() {
  const washSelect = document.getElementById('washingType');
  const washCost = document.getElementById('washingCost');
  const washSummary = document.getElementById('washSummary');
  
  const selectedOption = washSelect.selectedOptions[0];
  const cost = selectedOption ? parseFloat(selectedOption.dataset.cost) || 0 : 0;
  
  washCost.value = cost;
  washSummary.innerHTML = cost > 0 ? `Total Wash Cost: ₱${cost.toFixed(2)}` : '';
}

function autoSelectWashing() {
  const veryDirtyCheck = document.getElementById('veryDirtyCheck');
  const washSelect = document.getElementById('washingType');
  
  if (veryDirtyCheck.checked) {
    // Find the highest cost option
    let highestCost = 0;
    let highestOption = null;
    
    for (let option of washSelect.options) {
      if (option.value && option.dataset.cost) {
        const cost = parseFloat(option.dataset.cost);
        if (cost > highestCost) {
          highestCost = cost;
          highestOption = option;
        }
      }
    }
    
    if (highestOption) {
      washSelect.value = highestOption.value;
      updateWashCost();
    }
  } else {
    // Uncheck very dirty, reset washing selection
    washSelect.value = '';
    updateWashCost();
  }
}

/* Receipt modal */
const receiptModal=document.getElementById('receiptModal');
function showReceiptModal(receiptData){
  // Populate receipt data
  document.getElementById('receiptVehicleInfo').innerHTML = `<strong>${receiptData.vehicle_model}</strong><br>Plate: ${receiptData.plate_no}`;
  document.getElementById('receiptCustomerInfo').innerHTML = `<strong>Customer:</strong><br>${receiptData.customer_name}`;
  const returnDateTime = formatDateTime(receiptData.actual_return_date, receiptData.actual_return_time);
  document.getElementById('receiptDateInfo').innerHTML = `<strong>Return Date:</strong><br>${returnDateTime}`;
  
  document.getElementById('receiptFuelLevel').textContent = formatFuelLevel(receiptData.fuel_level);
  document.getElementById('receiptCondition').textContent = receiptData.return_condition;
  document.getElementById('receiptCleanliness').textContent = receiptData.cleanliness.replace('_', ' ').toUpperCase();
  document.getElementById('receiptOdometer').textContent = receiptData.odometer_return.toLocaleString() + ' km';
  
  // Show washing information if available
  if(receiptData.washing_cost > 0){
    document.getElementById('washingItem').style.display = 'flex';
    document.getElementById('receiptWashingType').textContent = receiptData.washing_type || 'Washing Service';
  } else {
    document.getElementById('washingItem').style.display = 'none';
  }
  
  // Show damage section if there's damage
  if(receiptData.damage_report && receiptData.damage_report.trim() !== ''){
    document.getElementById('damageSection').style.display = 'block';
    document.getElementById('receiptDamageReport').textContent = receiptData.damage_report;
  } else {
    document.getElementById('damageSection').style.display = 'none';
  }
  
  // Show rental summary
  if(receiptData.daily_rate) {
    document.getElementById('receiptDailyRate').textContent = '₱' + parseFloat(receiptData.daily_rate).toLocaleString('en-US', {minimumFractionDigits: 2});
  }
  if(receiptData.total_cost) {
    document.getElementById('receiptTotalCost').textContent = '₱' + parseFloat(receiptData.total_cost).toLocaleString('en-US', {minimumFractionDigits: 2});
  }
  if(receiptData.downpayment) {
    document.getElementById('receiptDownpayment').textContent = '₱' + parseFloat(receiptData.downpayment).toLocaleString('en-US', {minimumFractionDigits: 2});
  }
  
  // Cost breakdown
  document.getElementById('receiptOriginalBalance').textContent = '₱' + parseFloat(receiptData.original_balance).toLocaleString('en-US', {minimumFractionDigits: 2});
  
  // Show penalty if any (late return fee)
  if(receiptData.penalty_amount > 0 || receiptData.late_return_fee > 0){
    document.getElementById('penaltyItem').style.display = 'flex';
    const lateFee = parseFloat(receiptData.late_return_fee || receiptData.penalty_amount) || 0;
    document.getElementById('receiptPenalty').textContent = '₱' + lateFee.toLocaleString('en-US', {minimumFractionDigits: 2});
    
    // Show late fee breakdown using the calculation from the server
    document.getElementById('lateFeeBreakdown').style.display = 'flex';
    document.getElementById('receiptLateFeeBreakdown').textContent = receiptData.late_fee_calculation || 'Late fee calculation';
  } else {
    document.getElementById('penaltyItem').style.display = 'none';
    document.getElementById('lateFeeBreakdown').style.display = 'none';
  }
  
  // Show fuel penalty if any
  if(receiptData.fuel_penalty > 0){
    document.getElementById('fuelPenaltyItem').style.display = 'flex';
    document.getElementById('receiptFuelPenalty').textContent = '₱' + parseFloat(receiptData.fuel_penalty).toLocaleString('en-US', {minimumFractionDigits: 2});
  } else {
    document.getElementById('fuelPenaltyItem').style.display = 'none';
  }
  
  // Show washing cost if any
  if(receiptData.washing_cost > 0){
    document.getElementById('washingCostItem').style.display = 'flex';
    document.getElementById('receiptWashingCost').textContent = '₱' + parseFloat(receiptData.washing_cost).toLocaleString('en-US', {minimumFractionDigits: 2});
  } else {
    document.getElementById('washingCostItem').style.display = 'none';
  }
  
  // Show condition adjustment if any
  if(receiptData.condition_adjustment > 0){
    document.getElementById('conditionItem').style.display = 'flex';
    document.getElementById('receiptConditionAdjustment').textContent = '₱' + parseFloat(receiptData.condition_adjustment).toLocaleString('en-US', {minimumFractionDigits: 2});
  } else {
    document.getElementById('conditionItem').style.display = 'none';
  }
  
  // Show carwash fee if any
  if(receiptData.carwash_fee > 0){
    document.getElementById('carwashItem').style.display = 'flex';
    document.getElementById('receiptCarwashFee').textContent = '₱' + parseFloat(receiptData.carwash_fee).toLocaleString('en-US', {minimumFractionDigits: 2});
  } else {
    document.getElementById('carwashItem').style.display = 'none';
  }
  
  // Show damage fee if any
  if(receiptData.damage_fee > 0){
    document.getElementById('damageItem').style.display = 'flex';
    document.getElementById('receiptDamageFee').textContent = '₱' + parseFloat(receiptData.damage_fee).toLocaleString('en-US', {minimumFractionDigits: 2});
  } else {
    document.getElementById('damageItem').style.display = 'none';
  }
  
  document.getElementById('receiptFinalCost').textContent = '₱' + parseFloat(receiptData.final_cost).toLocaleString('en-US', {minimumFractionDigits: 2});
  
  // Show payment status
  if(receiptData.payment_status) {
    document.getElementById('receiptPaymentStatus').textContent = receiptData.payment_status;
    const statusElement = document.getElementById('receiptPaymentStatus');
    statusElement.className = 'value status ' + receiptData.payment_status.toLowerCase().replace(/\s+/g, '-');
  }
  
  receiptModal.style.display = 'flex';
}

function closeReceiptModal(){ 
  console.log('Closing receipt modal');
  receiptModal.style.display = 'none';
  // Just clear session data, no need to refresh the entire page
  fetch('rentals_all.php?clear_receipt=1', {method: 'POST'}).then(() => {
    console.log('Receipt session data cleared');
  }).catch(e => {
    console.log('Error clearing receipt data:', e);
  });
}

function viewReceipt(rentalId) {
  console.log('=== ADMIN VIEW RECEIPT CALLED ===');
  console.log('Admin viewReceipt called with rentalId:', rentalId);
  
  // Check if any modals are open and close them first
  const returnModal = document.getElementById('returnModal');
  const receiptModal = document.getElementById('receiptModal');
  
  if (returnModal && returnModal.style.display === 'flex') {
    console.log('Closing return modal first');
    returnModal.style.display = 'none';
  }
  
  // Show loading toast
  showToast('Loading receipt...', true);
  
  // Fetch receipt data for the specified rental using admin-specific endpoint
  fetch(`includes/ajax_admin_receipt.php?rental_id=${rentalId}&t=${Date.now()}`, {
    method: 'GET',
    credentials: 'same-origin',
    headers: {
      'Accept': 'application/json',
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest'
    }
  })
    .then(response => {
      console.log('Admin receipt response status:', response.status);
      
      if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
      }
      
      // Check if response is JSON
      const contentType = response.headers.get('content-type');
      console.log('Admin receipt Content-Type:', contentType);
      
      if (!contentType || !contentType.includes('application/json')) {
        return response.text().then(text => {
          console.log('Admin receipt Non-JSON response:', text);
          throw new Error('Response is not JSON. Content-Type: ' + contentType);
        });
      }
      
      return response.json();
    })
    .then(data => {
      console.log('Admin receipt data received:', data);
      if(data.success) {
        console.log('Showing admin receipt modal with data:', data.data);
        showReceiptModal(data.data);
      } else {
        console.error('Admin receipt fetch failed:', data.error);
        showToast('Error: ' + (data.error || 'Failed to load receipt'), false);
      }
    })
    .catch(error => {
      console.error('Error fetching admin receipt:', error);
      showToast('Error loading receipt: ' + error.message, false);
    });
}

function printReceipt(){
  window.print();
}

// Test function to verify admin receipt AJAX endpoint
window.testAdminReceiptAjax = function(rentalId = 1) {
  console.log('Testing ADMIN receipt AJAX endpoint for rental:', rentalId);
  viewReceipt(rentalId);
};



function formatFuelLevel(level){
  const levels = {
    'full': 'Full Tank',
    '3/4': '3/4 Tank',
    'half': 'Half Tank',
    '1/4': '1/4 Tank',
    'empty': 'Empty'
  };
  return levels[level] || 'Full Tank';
}

function formatDateTime(date, time){
  const dateObj = new Date(date + 'T' + time);
  const options = { 
    year: 'numeric', 
    month: 'long', 
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    hour12: true
  };
  return dateObj.toLocaleDateString('en-US', options);
}

function validateReturnForm(){
  console.log('=== VALIDATE RETURN FORM CALLED ===');
  
  const odometerInput = document.getElementById('odometerReturn');
  const rentalId = document.getElementById('returnRentalId').value;
  
  console.log('Return form submitted with rental ID:', rentalId);
  console.log('Odometer value:', odometerInput.value);
  
  // Basic validation - only check essential fields
  if (!rentalId) {
    alert('Error: Rental ID is missing. Please try again.');
    return false;
  }
  
  if (!odometerInput.value || odometerInput.value <= 0) {
    alert('Please enter a valid odometer reading.');
    return false;
  }
  
  console.log('Form validation passed, submitting...');
  return true;
}

function testFormSubmission() {
  console.log('=== TEST FORM SUBMISSION ===');
  
  const form = document.getElementById('returnForm');
  const formData = new FormData(form);
  
  // Add AJAX request flag
  formData.append('ajax_request', '1');
  
  console.log('Form data:');
  for (let [key, value] of formData.entries()) {
    console.log(key + ': ' + value);
  }
  
  // Submit form data to see what happens
  fetch('rentals_all.php', {
    method: 'POST',
    body: formData,
    headers: {
      'X-Requested-With': 'XMLHttpRequest'
    }
  })
  .then(response => {
    console.log('Response status:', response.status);
    const contentType = response.headers.get('content-type');
    
    if (contentType && contentType.includes('application/json')) {
      return response.json().then(data => {
        console.log('JSON Response data:', data);
        if (data.success) {
          alert('SUCCESS: ' + data.message);
          if (data.redirect) {
            window.location.href = data.redirect;
          }
        } else {
          alert('FAILED: ' + data.message);
        }
      });
    } else {
      return response.text().then(data => {
        console.log('HTML Response data:', data);
        alert('Form submitted! Status: ' + response.status + '\nGot HTML response (page redirect).');
      });
    }
  })
  .catch(error => {
    console.log('Form submission error:', error);
    alert('Form submission failed: ' + error.message);
  });
}

function debugAjaxDetection() {
  console.log('=== DEBUG AJAX DETECTION ===');
  
  // Test AJAX detection
  const formData = new FormData();
  formData.append('debug_ajax', '1');
  formData.append('ajax_request', '1');
  
  fetch('rentals_all.php', {
    method: 'POST',
    body: formData,
    headers: {
      'X-Requested-With': 'XMLHttpRequest'
    }
  })
  .then(response => {
    console.log('Debug response status:', response.status);
    const contentType = response.headers.get('content-type');
    console.log('Debug response content-type:', contentType);
    
    if (contentType && contentType.includes('application/json')) {
      return response.json().then(data => {
        console.log('Debug JSON Response:', data);
        alert('AJAX Detection Test: ' + JSON.stringify(data, null, 2));
      });
    } else {
      return response.text().then(data => {
        console.log('Debug HTML Response:', data);
        alert('AJAX Detection Test: Got HTML instead of JSON\nLength: ' + data.length + '\nFirst 200 chars: ' + data.substring(0, 200));
      });
    }
  })
  .catch(error => {
    console.log('Debug AJAX test error:', error);
    alert('AJAX Detection Test failed: ' + error.message);
  });
}

function calculateFuelCharge() {
  const fuelLevel = document.getElementById('fuelLevel').value;
  const fuelChargeDisplay = document.getElementById('fuelChargeDisplay');
  const fuelChargeAmount = document.getElementById('fuelChargeAmount');
  
  if (fuelLevel === 'full') {
    fuelChargeDisplay.style.display = 'none';
    return;
  }
  
  // Get fuel type from vehicle info
  const fuelType = document.getElementById('returnVehicleType').value;
  console.log('Calculating fuel charge for fuel type:', fuelType, 'fuel level:', fuelLevel);
  
  // Use the fuel rates that were loaded with the return summary
  const fuelRates = window.returnFuelRates || {};
  
  if (fuelRates[fuelType]) {
    const ratePerLiter = parseFloat(fuelRates[fuelType]);
    let charge = 0;
    
    // Calculate charge based on how much fuel is missing
    switch(fuelLevel) {
      case 'empty':
        charge = ratePerLiter * 50; // Assume 50L tank
        break;
      case '1/4':
        charge = ratePerLiter * 37.5; // 75% missing
        break;
      case 'half':
        charge = ratePerLiter * 25; // 50% missing
        break;
      case '3/4':
        charge = ratePerLiter * 12.5; // 25% missing
        break;
    }
    
    console.log('Fuel charge calculated:', charge);
    
    if (charge > 0) {
      fuelChargeAmount.textContent = charge.toFixed(2);
      fuelChargeDisplay.style.display = 'block';
    } else {
      fuelChargeDisplay.style.display = 'none';
    }
  } else {
    console.log('No fuel rate found for fuel type:', fuelType);
    fuelChargeDisplay.style.display = 'none';
  }
}

function submitReturnForm(event) {
  console.log('=== SUBMIT RETURN FORM ===');
  
  // Validate form first
  if (!validateReturnForm()) {
    event.preventDefault();
    return false;
  }
  
  // Show loading state
  const submitBtn = document.getElementById('submitReturnBtn');
  const submitText = document.getElementById('submitText');
  const submitSpinner = document.getElementById('submitSpinner');
  
  submitBtn.disabled = true;
  submitText.style.display = 'none';
  submitSpinner.style.display = 'inline';
  
  console.log('Submitting return form via normal POST...');
  
  // Allow normal form submission to proceed
  // No event.preventDefault() - let the form submit normally
}

function resetSubmitButton() {
  const submitBtn = document.getElementById('submitReturnBtn');
  const submitText = document.getElementById('submitText');
  const submitSpinner = document.getElementById('submitSpinner');
  
  submitBtn.disabled = false;
  submitText.style.display = 'inline';
  submitSpinner.style.display = 'none';
}

function showReturnError(message) {
  // Create or update error message
  let errorDiv = document.getElementById('returnError');
  if (!errorDiv) {
    errorDiv = document.createElement('div');
    errorDiv.id = 'returnError';
    errorDiv.style.cssText = 'background: #ff5d5d; color: white; padding: 12px; border-radius: 6px; margin: 10px 0; font-size: 0.9rem;';
    document.getElementById('returnForm').insertBefore(errorDiv, document.querySelector('.actions'));
  }
  errorDiv.textContent = message;
  
  // Auto-hide after 5 seconds
  setTimeout(() => {
    if (errorDiv) errorDiv.remove();
  }, 5000);
}


window.addEventListener('click',e=>{ if(e.target===receiptModal) closeReceiptModal(); });

// Handle URL parameters for success/error messages
function handleUrlParams() {
  const urlParams = new URLSearchParams(window.location.search);
  
  if (urlParams.get('success') === 'return_completed') {
    const rentalId = urlParams.get('rental_id');
    showToast(`Vehicle return completed successfully! Rental #${rentalId} has been processed.`, 'success');
    
    // Clean URL
    window.history.replaceState({}, document.title, window.location.pathname);
  }
  
  if (urlParams.get('error')) {
    const error = urlParams.get('error');
    let message = 'An error occurred during return processing.';
    
    switch(error) {
      case 'missing_data':
        message = 'Missing required data. Please try again.';
        break;
      case 'rental_not_found':
        message = 'Rental not found. Please refresh and try again.';
        break;
      case 'invalid_rental_status':
        message = 'Invalid rental status. This rental cannot be returned.';
        break;
      case 'already_returned':
        message = 'This rental has already been returned.';
        break;
      case 'maintenance_conflict':
        message = 'Cannot return vehicle - maintenance conflict detected.';
        break;
      case 'return_failed':
        message = 'Return processing failed. Please try again.';
        break;
    }
    
    showToast(`${message}`, 'error');
    
    // Clean URL
    window.history.replaceState({}, document.title, window.location.pathname);
  }
}

// Call URL parameter handler on page load
document.addEventListener('DOMContentLoaded', handleUrlParams);

// Note: Form resubmission protection is now handled server-side

// Global debug function for testing AJAX
window.testAjaxDebug = function() {
  console.log('=== AJAX DEBUG TEST ===');
  
  const formData = new FormData();
  formData.append('debug_ajax', '1');
  formData.append('ajax_request', '1');
  
  fetch('rentals_all.php', {
    method: 'POST',
    body: formData,
    headers: {
      'X-Requested-With': 'XMLHttpRequest'
    }
  })
  .then(response => {
    console.log('Response status:', response.status);
    const contentType = response.headers.get('content-type');
    console.log('Content-Type:', contentType);
    
    if (contentType && contentType.includes('application/json')) {
      return response.json().then(data => {
        console.log('JSON Response:', data);
        alert('AJAX Test SUCCESS: ' + JSON.stringify(data, null, 2));
      });
    } else {
      return response.text().then(data => {
        console.log('HTML Response:', data);
        alert('AJAX Test FAILED: Got HTML instead of JSON\nLength: ' + data.length + '\nFirst 200 chars: ' + data.substring(0, 200));
      });
    }
  })
  .catch(error => {
    console.log('Error:', error);
    alert('AJAX Test ERROR: ' + error.message);
  });
};

// Test function for return form specifically
window.testReturnForm = function() {
  console.log('=== TESTING RETURN FORM AJAX ===');
  
  // Create a test form data
  const formData = new FormData();
  formData.append('form_type', 'return_inspection');
  formData.append('rental_id', '1'); // Use a test rental ID
  formData.append('odometer_return', '1000');
  formData.append('ajax_request', '1');
  
  fetch('rentals_all.php', {
    method: 'POST',
    body: formData,
    headers: {
      'X-Requested-With': 'XMLHttpRequest'
    }
  })
  .then(response => {
    console.log('Return form response status:', response.status);
    const contentType = response.headers.get('content-type');
    console.log('Return form content-type:', contentType);
    
    if (contentType && contentType.includes('application/json')) {
      return response.json().then(data => {
        console.log('Return form JSON Response:', data);
        alert('Return Form Test SUCCESS: ' + JSON.stringify(data, null, 2));
      });
    } else {
      return response.text().then(data => {
        console.log('Return form HTML Response:', data);
        alert('Return Form Test FAILED: Got HTML instead of JSON\nLength: ' + data.length + '\nFirst 200 chars: ' + data.substring(0, 200));
      });
    }
  })
  .catch(error => {
    console.log('Return form error:', error);
    alert('Return Form Test ERROR: ' + error.message);
  });
};

// Simple test function
window.testSimple = function() {
  console.log('=== SIMPLE TEST ===');
  
  const formData = new FormData();
  formData.append('test_return', '1');
  formData.append('ajax_request', '1');
  
  fetch('rentals_all.php', {
    method: 'POST',
    body: formData,
    headers: {
      'X-Requested-With': 'XMLHttpRequest'
    }
  })
  .then(response => {
    console.log('Simple test response status:', response.status);
    const contentType = response.headers.get('content-type');
    console.log('Simple test content-type:', contentType);
    
    if (contentType && contentType.includes('application/json')) {
      return response.json().then(data => {
        console.log('Simple test JSON Response:', data);
        alert('Simple Test SUCCESS: ' + JSON.stringify(data, null, 2));
      });
    } else {
      return response.text().then(data => {
        console.log('Simple test HTML Response:', data);
        alert('Simple Test FAILED: Got HTML instead of JSON\nLength: ' + data.length + '\nFirst 200 chars: ' + data.substring(0, 200));
      });
    }
  })
  .catch(error => {
    console.log('Simple test error:', error);
    alert('Simple Test ERROR: ' + error.message);
  });
};

/* Auto-refresh rentals every 15s and keep Advance countdown */
async function refreshRentals(){
  try{
    const r=await fetch('rentals_all.php?ajax=rentals',{cache:'no-store'});
    const j=await r.json();
    document.getElementById('rows').innerHTML=j.tbody;
    attachCountdowns();
  }catch(e){}
}
setInterval(refreshRentals,15000);


/* ===== Enhanced Automated Click-Based Actions ===== */

// Automated rental approval
async function approveRental(rentalId) {
  if (!confirm('Are you sure you want to approve this rental?')) return;
  
  try {
    const formData = new FormData();
    formData.append('rental_id', rentalId);
    
    const response = await fetch('includes/ajax_approve_rental.php', {
      method: 'POST',
      body: formData
    });
    
    const result = await response.json();
    
    if (result.success) {
      showToast(result.message, true);
      // Refresh the rentals table
      await refreshRentals();
    } else {
      showToast(result.message, false);
    }
  } catch (error) {
    showToast('Failed to approve rental. Please try again.', false);
  }
}

// Automated rental rejection
async function rejectRental(rentalId) {
  if (!confirm('Are you sure you want to reject this rental?')) return;
  
  try {
    const formData = new FormData();
    formData.append('rental_id', rentalId);
    
    const response = await fetch('includes/ajax_reject_rental.php', {
      method: 'POST',
      body: formData
    });
    
    const result = await response.json();
    
    if (result.success) {
      showToast(result.message, true);
      // Refresh the rentals table
      await refreshRentals();
    } else {
      showToast(result.message, false);
    }
  } catch (error) {
    showToast('Failed to reject rental. Please try again.', false);
  }
}

// Enhanced extend rental with pre-filled modal
async function extendRental(rentalId) {
  try {
    // Get rental details
    const response = await fetch(`includes/ajax_get_rental_details.php?rental_id=${rentalId}`);
    const result = await response.json();
    
    if (result.success) {
      const rental = result.data;
      openExtendModal(rental);
    } else {
      showToast('Failed to load rental details', false);
    }
  } catch (error) {
    showToast('Failed to load rental details', false);
  }
}

// Enhanced extend modal with pre-filled data
function openExtendModal(rental) {
  const modal = document.getElementById('extendModal');
  if (!modal) {
    // Create extend modal if it doesn't exist
    createExtendModal();
  }
  
  // Pre-fill the modal with rental data
  document.getElementById('extendRentalId').value = rental.id;
  document.getElementById('extendVehicleId').value = rental.vehicle_id;
  document.getElementById('extendCustomerName').textContent = rental.full_name;
  document.getElementById('extendVehicleInfo').textContent = `${rental.make_model} (${rental.plate_no})`;
  document.getElementById('extendCurrentEnd').textContent = rental.end_date;
  document.getElementById('extendNewEnd').value = rental.end_date;
  document.getElementById('extendDailyRate').textContent = `₱${parseFloat(rental.daily_rate).toLocaleString()}`;
  
  // Show modal
  document.getElementById('extendModal').style.display = 'flex';
}

// Create extend modal dynamically
function createExtendModal() {
  const modalHTML = `
    <div class="modal-bg" id="extendModal">
      <div class="modal extend-modal">
        <h2>Extend Rental</h2>
        
        <div class="rental-info">
          <div class="info-item">
            <label>Customer:</label>
            <span id="extendCustomerName"></span>
          </div>
          <div class="info-item">
            <label>Vehicle:</label>
            <span id="extendVehicleInfo"></span>
          </div>
          <div class="info-item">
            <label>Current End Date:</label>
            <span id="extendCurrentEnd"></span>
          </div>
          <div class="info-item">
            <label>Daily Rate:</label>
            <span id="extendDailyRate"></span>
          </div>
        </div>
        
        <form id="extendForm" onsubmit="submitExtend(event)">
          <input type="hidden" id="extendRentalId" name="rental_id">
          <input type="hidden" id="extendVehicleId" name="vehicle_id">
          
          <div class="form-group">
            <label>New End Date</label>
            <input type="date" id="extendNewEnd" name="new_end" required>
          </div>
          
          <div class="form-group">
            <label>Extension Reason (Optional)</label>
            <textarea name="extension_reason" rows="3" placeholder="Reason for extension..."></textarea>
          </div>
          
          <div class="actions">
            <button type="submit" class="btn btn-approve">Extend Rental</button>
            <button type="button" class="btn btn-cancel" onclick="closeExtendModal()">Cancel</button>
          </div>
        </form>
      </div>
    </div>
  `;
  
  document.body.insertAdjacentHTML('beforeend', modalHTML);
}

// Submit extend form
async function submitExtend(event) {
  event.preventDefault();
  
  const formData = new FormData(event.target);
  const rentalId = formData.get('rental_id');
  const newEnd = formData.get('new_end');
  
  if (!newEnd || newEnd <= formData.get('current_end')) {
    showToast('New end date must be after current end date', false);
    return;
  }
  
  try {
    const response = await fetch('rentals_all.php', {
      method: 'POST',
      body: formData
    });
    
    if (response.ok) {
      showToast('Rental extended successfully', true);
      closeExtendModal();
      await refreshRentals();
    } else {
      showToast('Failed to extend rental', false);
    }
  } catch (error) {
    showToast('Failed to extend rental', false);
  }
}

// Close extend modal
function closeExtendModal() {
  document.getElementById('extendModal').style.display = 'none';
}

// Enhanced toast notifications
function showToast(message, isSuccess = true) {
  const toast = document.createElement('div');
  toast.className = `toast ${isSuccess ? 'toast-success' : 'toast-error'}`;
  toast.textContent = message;
  
  // Add toast styles if not already added
  if (!document.getElementById('toast-styles')) {
    const styles = document.createElement('style');
    styles.id = 'toast-styles';
    styles.textContent = `
      .toast {
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 12px 20px;
        border-radius: 8px;
        color: white;
        font-weight: 500;
        z-index: 10000;
        animation: slideIn 0.3s ease-out;
      }
      .toast-success {
        background: linear-gradient(135deg, #10b981, #059669);
      }
      .toast-error {
        background: linear-gradient(135deg, #ef4444, #dc2626);
      }
      @keyframes slideIn {
        from { transform: translateX(100%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
      }
    `;
    document.head.appendChild(styles);
  }
  
  document.body.appendChild(toast);
  
  // Auto remove after 3 seconds
  setTimeout(() => {
    toast.style.animation = 'slideOut 0.3s ease-in';
    setTimeout(() => toast.remove(), 300);
  }, 3000);
}

// Lightweight AJAX endpoint to reuse server renderer (kept from your previous build)
</script>

<?php
/* ===== Inline AJAX endpoint (kept minimal) ===== */
if(isset($_GET['ajax']) && $_GET['ajax']==='rentals'){
  // DISABLED: Auto-transition removed to prevent automatic completion on AJAX refresh
  // $conn->query("
  //   UPDATE rentals
  //   SET status='ongoing'
  //   WHERE status='reserved' AND start_date <= CURDATE() AND end_date >= CURDATE()
  // ");
  $rows = fetchRentals($conn);

  // re-render rows HTML server-side (same layout)
  ob_start();
  if(!$rows){
    echo '<div class="row"><div class="cell" style="grid-column:1/-1">No rentals yet.</div></div>';
  } else {
    foreach($rows as $r){
      $st = strtolower($r['status']);
      $isAdvance = ($st==='reserved' && strtotime($r['start_date'])>strtotime($today));
      
      // Return due indicator logic for AJAX
      $return_indicator = '';
      $return_indicator_class = '';
      if ($st === 'ongoing') {
        $end_datetime = $r['end_date'] . ' ' . ($r['end_time'] ?? '18:00:00');
        $end_timestamp = strtotime($end_datetime);
        $current_timestamp = time();
        $hours_until_due = ($end_timestamp - $current_timestamp) / 3600;
        
        if ($hours_until_due < 0) {
          // Overdue
          $return_indicator = 'OVERDUE';
          $return_indicator_class = 'overdue';
        } elseif ($hours_until_due <= 24) {
          // Due today or within 24 hours
          $return_indicator = $hours_until_due <= 0 ? 'DUE TODAY' : 'DUE TODAY';
          $return_indicator_class = 'due-today';
        } elseif ($hours_until_due <= 72) {
          // Due within 3 days (72 hours)
          $return_indicator = 'DUE SOON';
          $return_indicator_class = 'due-soon';
        }
      }
      
      $days = max(1, round((strtotime($r['end_date'])-strtotime($r['start_date']))/86400, 1));
      $total_amount = (float)($r['total_cost'] ?? 0);
      if ($total_amount <= 0) {
        $total_amount = $days * (float)($r['daily_rate'] ?? 0);
      }
      $required_down = (float)($r['downpayment'] ?? 0);
      $paid_amount = (float)($r['paid_amount'] ?? 0);
      $balance_calc = $total_amount - $paid_amount;
      if ($balance_calc < 0) { $balance_calc = 0; }
      $sclass = 's-'.$st;

      echo '<div class="row rental-row" '.($isAdvance ? 'data-start="'.h($r['start_date']).'"' : '').'>';
      
      // Add return due indicator if applicable
      if ($return_indicator) {
        echo '<div class="return-due-indicator '.$return_indicator_class.'">'.$return_indicator.'</div>';
      }
      
      echo   '<div class="cell" data-label="Customer"><b>'.h($r['full_name']).'</b></div>';
      echo   '<div class="cell" data-label="Vehicle"><div>'.h($r['make_model']).'</div><div class="muted">#'.(int)$r['id'].'</div></div>';
      echo   '<div class="cell" data-label="Plate No">'.h($r['plate_no']).'</div>';
      echo   '<div class="cell" data-label="Start Date">'.h($r['start_date']).'</div>';
      echo   '<div class="cell" data-label="End Date">'.h($r['end_date']).'</div>';
      echo   '<div class="cell" data-label="Daily Rate">₱'.number_format((float)$r['daily_rate'],2).'</div>';
      echo   '<div class="cell" data-label="Total Cost"><b>₱'.number_format((float)$total_amount,2).'</b></div>';
      echo   '<div class="cell" data-label="Payment">';

      echo '<div class="status '.$sclass.'">'.ucfirst($st);
      if($isAdvance) echo ' <span class="future">Advance</span> <span class="countdown" data-start="'.h($r['start_date']).'">—</span>';
      echo '</div>';

      if($st==='pending'){
        if ($paid_amount <= 0.00001) {
          echo '<div class="pay-badge pay-awaiting">Awaiting Downpayment</div>';
        } elseif ($paid_amount + 0.00001 < $required_down) {
          echo '<div class="pay-badge pay-partial">Partial Downpayment</div>';
        } else {
          echo '<div class="pay-badge pay-ready">Ready for Approval</div>';
        }
      }

      echo '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px">';
      if($st==='pending'){
        $canApprove = ($paid_amount + 0.00001 >= $required_down);
        echo '<button class="btn btn-secondary" onclick="openPaymentModal('.(int)$r['id'].','.(float)$required_down.','.(float)$paid_amount.')">Record Payment</button>';
        echo '<button class="btn btn-approve" '.($canApprove?'':'disabled title="Downpayment not met"'). ' onclick="approveRental('.(int)$r['id'].')">Approve</button>';
        echo '<button class="btn btn-cancel" onclick="rejectRental('.(int)$r['id'].')">Reject</button>';
      } elseif($st==='reserved' && strtotime($r['start_date'])<=DateTime::createFromFormat('Y-m-d',date('Y-m-d'))->getTimestamp()){
        echo '<button class="btn btn-start" onclick="approveRental('.(int)$r['id'].')">Start</button>';
        echo '<button class="btn btn-cancel" onclick="rejectRental('.(int)$r['id'].')">Cancel</button>';
      } elseif($st==='reserved'){
        echo '<span class="muted">Awaiting start</span>';
        echo '<button class="btn btn-cancel" onclick="rejectRental('.(int)$r['id'].')">Cancel</button>';
      } elseif($st==='ongoing'){
        echo '<button class="btn btn-return" onclick="openReturnModal('.(int)$r['id'].',\''.h($r['make_model']).'\',\''.h($r['plate_no']).'\',\''.h($r['end_date']).'\',\''.h($r['end_time']??'23:59:59').'\','.(int)$r['vehicle_id'].',\''.h($r['vehicle_type']).'\')">Return</button>';
        echo '<button class="btn btn-extend" onclick="extendRental('.(int)$r['id'].')">Extend</button>';
      } elseif($st==='completed'){
        echo '<span class="muted" style="color:#7cffc7;font-weight:600;">Completed & Available</span>';
        echo '<button class="btn btn-receipt" onclick="viewReceipt('.(int)$r['id'].')">View Receipt</button>';
      } else {
        echo '<span class="muted">—</span>';
      }
      echo '</div>';

      echo   '</div>';
      echo '</div>';
    }
  }
  $html = ob_get_clean();
  header('Content-Type: application/json'); echo json_encode(['tbody'=>$html]); exit;
}


?>

<script>
/* Countdown badges for Advance rows */
let countdownTimer=null;
function attachCountdowns(){
  if(countdownTimer) clearInterval(countdownTimer);
  const rows=document.querySelectorAll('#rows .row[data-start]');
  function tick(){
    const now=new Date();
    rows.forEach(tr=>{
      const d=tr.getAttribute('data-start');
      if(!d) return;
      const target=new Date(d+"T00:00:00");
      const diff=target-now;
      const el=tr.querySelector('.countdown');
      if(!el) return;
      if(diff<=0){ el.textContent='Starts today'; }
      else{
        const days=Math.floor(diff/86400000);
        const hrs=Math.floor((diff%86400000)/3600000);
        const mins=Math.floor((diff%3600000)/60000);
        el.textContent=`• Starts in ${days}d ${hrs}h ${mins}m`;
      }
    });
  }
  tick(); countdownTimer=setInterval(tick,60000);
}
attachCountdowns();
</script>
</body>
</html>
<?php $conn->close(); ?>
