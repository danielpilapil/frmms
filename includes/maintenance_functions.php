<?php
/* =========================================================================
   maintenance_functions.php — FleetGo Maintenance Helper Functions
   - Smart maintenance scheduling
   - Cost calculation helpers
   - Vehicle status management
   ========================================================================= */

// Prevent any output from this file
if (ob_get_level()) {
  ob_clean();
}

// Smart maintenance scheduling helper function
function scheduleSmartMaintenance($conn, $vehicle_id, $maintenance_type, $cleanliness_level = null, $notes = '') {
  try {
    // Validate inputs
    if (!$conn) {
      throw new Exception("Database connection not available");
    }
    
    if (!$vehicle_id || $vehicle_id <= 0) {
      throw new Exception("Invalid vehicle ID");
    }
    
    $conn->begin_transaction();
    
    // Get vehicle information
    $vehicle = $conn->query("SELECT vehicle_type, make_model, plate_no FROM vehicles WHERE id = $vehicle_id")->fetch_assoc();
    if (!$vehicle) {
      throw new Exception("Vehicle not found");
    }
    
    $vehicle_type = $vehicle['vehicle_type'] ?? 'sedan';
    $washing_id = 0;
    $washing_cost = 0;
    $cost = 0;
    $estimated_cost = null;
    $service_center = 'Auto-scheduled';
    $schedule_date = date('Y-m-d');
    $status = 'scheduled';
    
    // Determine washing requirements and costs based on cleanliness level
    if ($cleanliness_level === 'very_dirty' || $maintenance_type === 'Carwash') {
      // Check if washing_types table exists
      $table_check = $conn->query("SHOW TABLES LIKE 'washing_types'")->fetch_assoc();
      
      if ($table_check) {
        // Get the highest washing rate for this vehicle type (most thorough cleaning)
        $washing_stmt = $conn->prepare("
          SELECT id, washing_name, washing_rate 
          FROM washing_types 
          WHERE vehicle_type = ? 
          ORDER BY washing_rate DESC 
          LIMIT 1
        ");
        $washing_stmt->bind_param("s", $vehicle_type);
        $washing_stmt->execute();
        $washing_result = $washing_stmt->get_result()->fetch_assoc();
        $washing_stmt->close();
      } else {
        $washing_result = null;
      }
      
      if ($washing_result) {
        $washing_id = (int)$washing_result['id'];
        $washing_cost = (float)$washing_result['washing_rate'];
        $service_center = "Auto-scheduled - {$washing_result['washing_name']}";
        $notes = $notes ?: "Auto-scheduled {$maintenance_type} - {$washing_result['washing_name']} required";
        
        // Set cost for carwash maintenance (avoid double counting)
        if ($maintenance_type === 'Carwash') {
          $cost = $washing_cost; // Set actual cost for carwash
          $estimated_cost = $washing_cost; // Also set estimated cost for compatibility
          $washing_cost = 0; // Set washing_cost to 0 to avoid double counting in display
        }
      }
    }
    
    // Calculate estimated costs for different maintenance types
    if ($maintenance_type === 'Preventive') {
      // Base preventive maintenance cost by vehicle type
      $preventive_costs = [
        'sedan' => 2500,
        'suv' => 3000,
        'van' => 3500,
        'truck' => 4000,
        'motorcycle' => 1500
      ];
      $estimated_cost = $preventive_costs[$vehicle_type] ?? 2500;
      $service_center = 'Auto-scheduled - Preventive Service';
      $notes = $notes ?: "Auto-scheduled preventive maintenance based on vehicle type";
    } elseif ($maintenance_type === 'Corrective') {
      // Base corrective maintenance cost (will be updated when actual work is done)
      $corrective_costs = [
        'sedan' => 1500,
        'suv' => 2000,
        'van' => 2500,
        'truck' => 3000,
        'motorcycle' => 1000
      ];
      $estimated_cost = $corrective_costs[$vehicle_type] ?? 1500;
      $service_center = 'Auto-scheduled - Corrective Service';
      $notes = $notes ?: "Auto-scheduled corrective maintenance - cost to be determined";
    }
    
    // Check for existing maintenance of the same category for today to prevent duplicates
    $existing_check = $conn->prepare("
      SELECT id FROM maintenance 
      WHERE vehicle_id = ? AND maintenance_category = ? AND schedule_date = ? 
      AND status IN ('scheduled', 'in_progress', 'reported', 'approved')
      LIMIT 1
    ");
    $existing_check->bind_param("iss", $vehicle_id, $maintenance_type, $schedule_date);
    $existing_check->execute();
    $existing_result = $existing_check->get_result()->fetch_assoc();
    $existing_check->close();
    
    if ($existing_result) {
      // Maintenance already exists for today, return existing record info
      $conn->rollback();
      return [
        'success' => false,
        'message' => "Maintenance of type '$maintenance_type' already exists for this vehicle today",
        'existing_id' => $existing_result['id']
      ];
    }
    
    // Insert maintenance record with new schema fields
    $category = strtolower((string)$maintenance_type) === 'carwash' ? 'cleaning' : strtolower((string)$maintenance_type);
    $validCategories = ['preventive','corrective','emergency','cleaning'];
    if ($category === '' || !in_array($category, $validCategories, true)) {
      $category = 'corrective';
    }
    $priority = 'medium';
    $source = 'manual';
    
    $stmt = $conn->prepare("
      INSERT INTO maintenance (
        vehicle_id, maintenance_category, priority_level, schedule_date, status, description, 
        washing_id, washing_cost, service_center, cost, estimated_cost, source_type, reported_date
      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->bind_param(
      "isssssidsddss",
      $vehicle_id,
      $category,
      $priority,
      $schedule_date,
      $status,
      $notes,
      $washing_id,
      $washing_cost,
      $service_center,
      $cost,
      $estimated_cost,
      $source
    );
    $stmt->execute();
    $maintenance_id = $conn->insert_id;
    $stmt->close();
    
    // Update vehicle status if maintenance is scheduled for today
    if ($schedule_date === date('Y-m-d')) {
      $conn->query("UPDATE vehicles SET current_status='maintenance' WHERE id=$vehicle_id");
    }
    
    // Create maintenance notification for admin (if session is available)
    if (isset($_SESSION['user_id']) && $_SESSION['user_id']) {
      $vehicle_info = $conn->query("SELECT make_model, plate_no FROM vehicles WHERE id=$vehicle_id")->fetch_assoc();
      $vehicle_name = $vehicle_info['make_model'] ?? 'Vehicle';
      $plate_no = $vehicle_info['plate_no'] ?? '';
      $message = "🔧 Auto-scheduled maintenance for <b>$vehicle_name</b> (Plate: $plate_no) - $maintenance_type";
      
      // Include notification manager if not already included
      if (!function_exists('createNotificationIfNotExists')) {
        require_once __DIR__ . '/notification_manager.php';
      }
      createNotificationIfNotExists($conn, $_SESSION['user_id'], $vehicle_id, $message);
    }
    
    $conn->commit();
    
    return [
      'success' => true,
      'maintenance_id' => $maintenance_id,
      'washing_cost' => $washing_cost,
      'cost' => $cost,
      'estimated_cost' => $estimated_cost,
      'total_estimated_cost' => ($cost ?? $estimated_cost ?? 0) + $washing_cost,
      'message' => "Maintenance scheduled successfully"
    ];
    
  } catch (Exception $e) {
    $conn->rollback();
    return [
      'success' => false,
      'message' => "Failed to schedule maintenance: " . $e->getMessage()
    ];
  }
}

// Function to sync vehicle statuses based on active maintenance
function syncVehicleStatuses($conn) {
  $vehicles = $conn->query("SELECT id FROM vehicles")->fetch_all(MYSQLI_ASSOC);
  
  foreach($vehicles as $vehicle) {
    $vid = $vehicle['id'];
    
    // Check for active maintenance (new status values)
    $activeMaintenance = $conn->query("
      SELECT COUNT(*) as count FROM maintenance 
      WHERE vehicle_id=$vid AND status IN ('in_progress','scheduled','reported','approved') 
      AND schedule_date <= CURDATE()
    ")->fetch_assoc();
    
    if($activeMaintenance['count'] > 0) {
      // Has active maintenance - check if in_progress to set correct status
      $inProgress = $conn->query("
        SELECT COUNT(*) as count FROM maintenance 
        WHERE vehicle_id=$vid AND status = 'in_progress'
      ")->fetch_assoc();
      
      $newStatus = $inProgress['count'] > 0 ? 'maintenance' : 'scheduled_maintenance';
      $conn->query("UPDATE vehicles SET current_status='$newStatus' WHERE id=$vid AND current_status NOT IN ('rented', 'reserved')");
    } else {
      // No active maintenance, check for reservations
      $next = $conn->query("SELECT id FROM rentals WHERE vehicle_id=$vid AND status='reserved' AND start_date>CURDATE() ORDER BY start_date ASC LIMIT 1")->fetch_assoc();
      $vehStat = $next ? 'reserved' : 'available';
      $conn->query("UPDATE vehicles SET current_status='$vehStat' WHERE id=$vid AND current_status NOT IN ('rented', 'maintenance', 'scheduled_maintenance', 'inspection')");
    }
  }
}

// Function to get maintenance suggestions
function getMaintenanceSuggestions($c){
  $sql="SELECT v.id, v.make_model, v.plate_no, v.odometer, v.current_status,
               mr.km_interval, mr.months_interval, mr.last_ref_odometer, mr.last_ref_date,
               DATEDIFF(CURDATE(), COALESCE(mr.last_ref_date, v.created_at)) as days_since_last,
               (v.odometer - COALESCE(mr.last_ref_odometer, 0)) as km_since_last,
               CASE 
                 WHEN mr.id IS NULL THEN 'First service needed'
                 WHEN (v.odometer - COALESCE(mr.last_ref_odometer, 0)) >= COALESCE(mr.km_interval, 5000) 
                      AND DATEDIFF(CURDATE(), COALESCE(mr.last_ref_date, v.created_at)) >= COALESCE(mr.months_interval, 3) * 30 
                 THEN 'Mileage & time overdue'
                 WHEN (v.odometer - COALESCE(mr.last_ref_odometer, 0)) >= COALESCE(mr.km_interval, 5000) 
                 THEN 'Mileage overdue'
                 WHEN DATEDIFF(CURDATE(), COALESCE(mr.last_ref_date, v.created_at)) >= COALESCE(mr.months_interval, 3) * 30 
                 THEN 'Time overdue'
                 ELSE 'Due soon'
               END as reason,
               CASE 
                 WHEN mr.id IS NULL THEN 'high'
                 WHEN (v.odometer - COALESCE(mr.last_ref_odometer, 0)) >= COALESCE(mr.km_interval, 5000) 
                      AND DATEDIFF(CURDATE(), COALESCE(mr.last_ref_date, v.created_at)) >= COALESCE(mr.months_interval, 3) * 30 
                 THEN 'high'
                 WHEN (v.odometer - COALESCE(mr.last_ref_odometer, 0)) >= COALESCE(mr.km_interval, 5000) 
                      OR DATEDIFF(CURDATE(), COALESCE(mr.last_ref_date, v.created_at)) >= COALESCE(mr.months_interval, 3) * 30 
                 THEN 'medium'
                 ELSE 'low'
               END as priority
        FROM vehicles v
        LEFT JOIN maintenance_rules mr ON mr.vehicle_id = v.id
        WHERE v.current_status = 'available'
        AND (
          (mr.km_interval IS NOT NULL AND (v.odometer - COALESCE(mr.last_ref_odometer, 0)) >= mr.km_interval) OR
          (mr.months_interval IS NOT NULL AND DATEDIFF(CURDATE(), COALESCE(mr.last_ref_date, v.created_at)) >= (mr.months_interval * 30)) OR
          (mr.id IS NULL AND v.odometer > 10000) OR
          (mr.id IS NULL AND DATEDIFF(CURDATE(), v.created_at) >= 90)
        )
        ORDER BY 
          CASE 
            WHEN mr.id IS NULL THEN 1
            WHEN (v.odometer - COALESCE(mr.last_ref_odometer, 0)) >= COALESCE(mr.km_interval, 5000) 
                 AND DATEDIFF(CURDATE(), COALESCE(mr.last_ref_date, v.created_at)) >= COALESCE(mr.months_interval, 3) * 30 
            THEN 2
            WHEN (v.odometer - COALESCE(mr.last_ref_odometer, 0)) >= COALESCE(mr.km_interval, 5000) 
                 OR DATEDIFF(CURDATE(), COALESCE(mr.last_ref_date, v.created_at)) >= COALESCE(mr.months_interval, 3) * 30 
            THEN 3
            ELSE 4
          END,
          (v.odometer - COALESCE(mr.last_ref_odometer, 0)) DESC";
  return $c->query($sql)->fetch_all(MYSQLI_ASSOC);
}

// Predictive helpers
function months_since($dateYmd){
  if(!$dateYmd) return null;
  try{
    $d1=new DateTime($dateYmd); $d2=new DateTime('today');
    $diff=$d1->diff($d2); return $diff->y*12+$diff->m+($diff->d>0?1:0);
  }catch(Throwable $e){ return null; }
}

function compute_due($veh,$rule){
  // returns [due_km,due_time,near_km,near_time,delta_km,km_int,ms,mo_int]
  $due_km=$due_time=$near_km=$near_time=false;
  $delta_km=null; $km_int=null; $ms=null; $mo_int=null;
  if($veh && $rule){
    $odo=(int)$veh['odometer']; $ref=(int)($rule['last_ref_odometer']??0);
    $km_int=(int)($rule['km_interval']??0);
    if($km_int>0){
      $delta_km=max(0,$odo-$ref);
      $due_km  = $delta_km >= $km_int;
      $near_km = $delta_km >= (int)floor($km_int*0.90);
    }
    $mo_int=(int)($rule['months_interval']??0);
    if($mo_int>0){
      $ms=months_since($rule['last_ref_date']);
      if($ms!==null){
        $due_time  = $ms >= $mo_int;
        $near_time = $ms >= max(1,(int)floor($mo_int*0.90));
      }
    }
  }
  return [$due_km,$due_time,$near_km,$near_time,$delta_km,$km_int,$ms,$mo_int];
}

function getMaintenanceHistory($c, $vehicle_id){
  $sql="SELECT m.*, v.make_model, v.plate_no 
        FROM maintenance m 
        JOIN vehicles v ON v.id = m.vehicle_id 
        WHERE m.vehicle_id = ? 
        ORDER BY m.schedule_date DESC 
        LIMIT 5";
  $stmt = $c->prepare($sql);
  $stmt->bind_param("i", $vehicle_id);
  $stmt->execute();
  return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>
