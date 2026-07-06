<?php
/* =========================================================
   ajax_receipt.php — Fetch receipt data for completed rentals
   ========================================================= */

// Disable error display for AJAX requests
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Clear any existing output buffer
if (ob_get_level()) {
    ob_clean();
}

// Start output buffering early to prevent headers being sent
ob_start();

require_once __DIR__ . '/db.php';

// Handle session for user authentication
// First, try to use existing session if it's the right type
if (session_status() === PHP_SESSION_ACTIVE) {
  // Session is already active, check if it's a user session
  if (session_name() === 'fleetgo_session_user' || session_name() === 'fleetgo_session') {
    // Good, we have a user session
  } else {
    // Wrong session type, try to change it
    try {
      session_write_close();
      session_name('fleetgo_session_user');
      session_start();
    } catch (Exception $e) {
      error_log("Session change failed: " . $e->getMessage());
    }
  }
} else {
  // No active session, start a new one
  try {
    session_name('fleetgo_session_user');
    session_start();
  } catch (Exception $e) {
    error_log("Session start failed: " . $e->getMessage());
  }
}

// Check if this is an AJAX request
$isAjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') 
          || isset($_GET['ajax_request']);

if (!$isAjax) {
    header('Content-Type: application/json');
  echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

// Check if user is logged in (either admin or user)
if (!isset($_SESSION['user_id'])) {
  ob_clean();
    header('Content-Type: application/json');
  echo json_encode([
    'success' => false, 
    'error' => 'Authentication required. Please log in to view receipts.',
    'session_info' => [
      'session_active' => session_status() === PHP_SESSION_ACTIVE,
      'session_name' => session_name(),
      'session_id' => session_id(),
      'session_data' => $_SESSION
    ]
  ]);
    exit;
}

// Get rental ID
$rentalId = (int)($_GET['rental_id'] ?? 0);

if (!$rentalId) {
  header('Content-Type: application/json');
  echo json_encode(['success' => false, 'error' => 'Rental ID required']);
  exit;
}

try {
  $userId = (int)$_SESSION['user_id'];
  $userRole = $_SESSION['role'] ?? '';
  
  // Build query with access control
  if ($userRole === 'admin') {
    // Admin can view any receipt
    $accessCondition = "r.id = ? AND r.status = 'completed'";
    $params = [$rentalId];
    $paramTypes = "i";
  } else {
    // User can only view their own receipts
    $accessCondition = "r.id = ? AND r.customer_id = ? AND r.status = 'completed'";
    $params = [$rentalId, $userId];
    $paramTypes = "ii";
  }
  
  // Get comprehensive rental and return data
  $stmt = $conn->prepare("
        SELECT 
            r.*,
      v.make_model,
      v.plate_no,
      v.vehicle_type,
            u.full_name as customer_name,
      ri.fuel_level,
      ri.cleanliness,
      ri.damage_report,
      ri.damage_fee,
      ri.additional_notes,
      ri.penalty_amount,
      ri.final_cost,
      ri.created_at as return_date,
      wt.washing_name,
      rr.actual_return_date,
      rr.actual_return_time,
      rr.return_condition,
      rr.odometer_return
        FROM rentals r
        JOIN vehicles v ON v.id = r.vehicle_id
        JOIN users u ON u.id = r.customer_id
    LEFT JOIN return_inspections ri ON ri.rental_id = r.id
    LEFT JOIN washing_types wt ON wt.id = r.washing_id
        LEFT JOIN rental_returns rr ON rr.rental_id = r.id
    WHERE $accessCondition
  ");
    
  $stmt->bind_param($paramTypes, ...$params);
    $stmt->execute();
  $rental = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
  if (!$rental) {
    throw new Exception('Rental not found, not completed, or access denied');
  }
  
  // Calculate additional fees - use the same logic as myrentals.php
  $lateFee = 0;
  $fuelCharge = 0;
  $washingCost = (float)($rental['washing_cost'] ?? 0);
  $damageFee = (float)($rental['damage_fee'] ?? 0);
  $conditionAdjustment = 0;
  
  $dailyRate = (float)$rental['daily_rate'];
  
  // Calculate late fee using actual return date and time
  $actualReturnDate = $rental['actual_return_date'] ?? $rental['return_date'];
  $actualReturnTime = $rental['actual_return_time'] ?? '12:00:00';
  $expectedEndDate = $rental['end_date'];
  $expectedEndTime = $rental['end_time'] ?? '18:00:00'; // Use actual stored end_time
  
  if ($actualReturnDate) {
    $actualReturnDateTime = strtotime($actualReturnDate . ' ' . $actualReturnTime);
    $expectedReturnDateTime = strtotime($expectedEndDate . ' ' . $expectedEndTime);
    $hoursLate = max(0, ($actualReturnDateTime - $expectedReturnDateTime) / 3600);
    $lateFee = ($dailyRate / 24) * $hoursLate * 1.25; // FleetGo Late Fee Policy
  }
  
  // Calculate fuel charge using fuel_charge_rates table - match myrentals.php logic
  $fuelLevel = $rental['fuel_level'] ?? 'full';
  $vehicleType = $rental['vehicle_type'];
  
  if ($fuelLevel !== 'full') {
    // Get fuel rates for this vehicle type
    $fuelStmt = $conn->prepare("SELECT * FROM fuel_charge_rates WHERE vehicle_type = ? LIMIT 1");
    $fuelStmt->bind_param("s", $vehicleType);
    $fuelStmt->execute();
    $fuelRates = $fuelStmt->get_result()->fetch_assoc();
    $fuelStmt->close();
    
    if ($fuelRates) {
      switch($fuelLevel) {
        case 'empty':
          $fuelCharge = (float)($fuelRates['empty_rate'] ?? 0);
          break;
        case '1/4':
          $fuelCharge = (float)($fuelRates['quarter_rate'] ?? 0);
          break;
        case 'half':
          $fuelCharge = (float)($fuelRates['half_rate'] ?? 0);
          break;
        case '3/4':
          $fuelCharge = (float)($fuelRates['three_quarter_rate'] ?? 0);
          break;
        default:
          $fuelCharge = 0;
          break;
      }
    }
  }
  
  // Calculate condition adjustment - use same logic as myrentals.php
  $returnCondition = $rental['return_condition'] ?? 'Good';
  switch($returnCondition) {
    case 'Poor':
      $conditionAdjustment = $dailyRate * 0.5; // 50% of daily rate
      break;
    case 'Fair':
      $conditionAdjustment = $dailyRate * 0.2; // 20% of daily rate
      break;
    case 'Good':
    case 'Excellent':
    default:
      $conditionAdjustment = 0;
      break;
  }
  
  // Calculate total cost (remaining balance + additional charges)
  $days = max(1, round((strtotime($rental['end_date']) - strtotime($rental['start_date'])) / 86400, 1));
  $baseRentalCost = $days * $dailyRate;
  $totalAdditionalCharges = $lateFee + $fuelCharge + $washingCost + $damageFee + $conditionAdjustment;
  $originalBalance = (float)($rental['balance_due'] ?? 0);
  $finalCost = $originalBalance + $totalAdditionalCharges;
  
  // Calculate payment status
  $paymentStatus = '';
  
  if ($totalAdditionalCharges <= 0 && $finalCost <= 0) {
    $paymentStatus = 'Fully Paid - No Additional Charges';
  } elseif ($totalAdditionalCharges > 0 && $finalCost <= 0) {
    $paymentStatus = 'Fully Paid - Additional Charges Waived';
  } elseif ($finalCost <= $originalBalance) {
    $remaining = $originalBalance - $finalCost;
    if ($remaining > 0) {
      $paymentStatus = "Balance Remaining - ₱" . number_format($remaining, 2) . " credit";
    } else {
      $paymentStatus = 'Fully Paid - Original Balance Covered';
    }
  } else {
    $additionalDue = $finalCost - $originalBalance;
    $paymentStatus = "Additional Charges Due - ₱" . number_format($additionalDue, 2) . " extra";
  }
  
  // Calculate detailed breakdown for transparency
  $rentalDays = max(1, round((strtotime($rental['end_date']) - strtotime($rental['start_date'])) / 86400, 1));
  $baseRentalCost = $rentalDays * $dailyRate;
  
  // Calculate hours late for transparency (use the same calculation as above)
  $hoursLate = 0;
  if ($actualReturnDate) {
    $actualReturnDateTime = strtotime($actualReturnDate . ' ' . $actualReturnTime);
    $expectedReturnDateTime = strtotime($expectedEndDate . ' ' . $expectedEndTime);
    $hoursLate = max(0, ($actualReturnDateTime - $expectedReturnDateTime) / 3600);
  }
  
  // Prepare detailed receipt data with full transparency
  $receiptData = [
    'rental_id' => $rentalId,
            'vehicle_model' => $rental['make_model'],
            'plate_no' => $rental['plate_no'],
            'customer_name' => $rental['customer_name'],
            'start_date' => $rental['start_date'],
            'end_date' => $rental['end_date'],
    'actual_return_date' => $rental['actual_return_date'] ?? $rental['return_date'],
    'actual_return_time' => $rental['actual_return_time'] ?? '12:00:00',
    'odometer_return' => (int)($rental['odometer_return'] ?? 0),
    'fuel_level' => $fuelLevel,
    'return_condition' => $returnCondition,
    'cleanliness' => $rental['cleanliness'] ?? 'clean',
    'damage_report' => $rental['damage_report'] ?? '',
    'damage_fee' => $damageFee,
    'washing_id' => (int)($rental['washing_id'] ?? 0),
    'washing_cost' => $washingCost,
    'washing_type' => $rental['washing_name'] ?? null,
    'penalty_amount' => $lateFee,
    'fuel_penalty' => $fuelCharge,
    'condition_adjustment' => $conditionAdjustment,
    'total_additional_charges' => $totalAdditionalCharges,
    'late_return_fee' => $lateFee, // Explicit late return fee
    'original_balance' => $originalBalance,
    'daily_rate' => $dailyRate,
    'total_cost' => $baseRentalCost,
    'downpayment' => (float)($rental['downpayment'] ?? 0),
    'final_cost' => $finalCost,
    'payment_status' => $paymentStatus,
    
    // Detailed breakdown for transparency
    'rental_days' => $rentalDays,
    'base_rental_cost' => $baseRentalCost,
    'hours_late' => $hoursLate,
    'late_fee_calculation' => $hoursLate > 0 ? "($hoursLate hrs × ₱" . number_format($dailyRate/24, 2) . "/hr × 1.25) = ₱" . number_format($lateFee, 2) : "No late fee",
    'fuel_charge_calculation' => $fuelCharge > 0 ? "₱" . number_format($fuelCharge, 2) . " (" . ucfirst($fuelLevel) . " tank)" : "No fuel charge (Full tank)",
    'condition_calculation' => $conditionAdjustment > 0 ? "₱" . number_format($conditionAdjustment, 2) . " (" . $returnCondition . " condition)" : "No condition adjustment (Good condition)",
    'washing_calculation' => $washingCost > 0 ? "₱" . number_format($washingCost, 2) . " (" . ($rental['washing_name'] ?? 'Washing service') . ")" : "No washing charge",
    'damage_calculation' => $damageFee > 0 ? "₱" . number_format($damageFee, 2) . " (Damage fee)" : "No damage charges"
  ];
  
  // Clear output buffer and return JSON
  ob_clean();
        header('Content-Type: application/json');
  echo json_encode([
    'success' => true,
    'data' => $receiptData
  ]);
  
} catch (Exception $e) {
  ob_clean();
    header('Content-Type: application/json');
  echo json_encode([
    'success' => false,
    'error' => $e->getMessage()
  ]);
}

$conn->close();
?>