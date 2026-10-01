<?php
/* =========================================
   vehicles_all.php — FleetGo Vehicle Management
   Final Enhanced Version (Presentation Ready)
   ========================================= */

/* ---------- SESSION FIX ---------- */
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
session_name('fleetgo_session_admin');
session_start();

require_once __DIR__ . '/includes/db.php';

/* --- Access Control --- */
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: login.php");
    exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

define('VEH_IMG_DIR', __DIR__.'/assets/vehicles');
define('VEH_IMG_URL', 'assets/vehicles');
if(!is_dir(VEH_IMG_DIR)) mkdir(VEH_IMG_DIR,0777,true);
function h($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function clean_str($v){
  $v = (string)$v;
  $v = trim($v);
  $v = preg_replace('/\s+/',' ',$v);
  return $v;
}
function column_exists(mysqli $conn, string $table, string $column): bool {
  $stmt = $conn->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
  $stmt->bind_param('ss', $table, $column);
  $stmt->execute();
  $res = $stmt->get_result()->fetch_row();
  return !empty($res);
}
function unlink_silent($p){if(is_file($p))@unlink($p);}
function type_fallback($type){
  $t=strtolower($type);
  if(strpos($t,'motor')!==false)return'vehicles/motorcycle.jpg';
  if(strpos($t,'pickup')!==false)return'vehicles/pickup.jpg';
  if(strpos($t,'suv')!==false)return'vehicles/suv.jpg';
  if(strpos($t,'van')!==false)return'vehicles/minivan.jpg';
  return 'vehicles/images.jpeg';
}

// Ensure vehicle columns used by the admin form exist
$vehicleSchema = [
  'chassis_number' => "ADD COLUMN chassis_number VARCHAR(64) NULL DEFAULT NULL AFTER plate_no",
  'engine_number' => "ADD COLUMN engine_number VARCHAR(64) NULL DEFAULT NULL AFTER chassis_number",
  'ownership_type' => "ADD COLUMN ownership_type VARCHAR(32) NULL DEFAULT 'Personal' AFTER make_model",
  'vehicle_condition' => "ADD COLUMN vehicle_condition VARCHAR(32) NULL DEFAULT NULL AFTER vehicle_type",
  'category' => "ADD COLUMN category VARCHAR(32) NULL DEFAULT NULL AFTER vehicle_condition",
  'fuel_type' => "ADD COLUMN fuel_type VARCHAR(32) NULL DEFAULT NULL AFTER transmission",
  'max_capacity_kg' => "ADD COLUMN max_capacity_kg DECIMAL(10,2) NULL DEFAULT NULL AFTER seats",
  'fuel_level' => "ADD COLUMN fuel_level ENUM('full','3/4','half','1/4','empty') NULL DEFAULT NULL AFTER fuel_type",
  'promo_discount_type' => "ADD COLUMN promo_discount_type ENUM('none','percent','fixed') NOT NULL DEFAULT 'none' AFTER daily_rate_outside_cdo",
  'promo_discount_value' => "ADD COLUMN promo_discount_value DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER promo_discount_type",
  'promo_starts_at' => "ADD COLUMN promo_starts_at DATETIME NULL DEFAULT NULL AFTER promo_discount_value",
  'promo_ends_at' => "ADD COLUMN promo_ends_at DATETIME NULL DEFAULT NULL AFTER promo_starts_at",
  'listing_description' => "ADD COLUMN listing_description TEXT NULL DEFAULT NULL",
];
foreach ($vehicleSchema as $col => $ddl) {
  if (!column_exists($conn, 'vehicles', $col)) {
    try {
      $conn->query("ALTER TABLE vehicles $ddl");
    } catch (Throwable $e) {
      error_log("vehicles schema ensure failed for {$col}: " . $e->getMessage());
    }
  }
}
// Seed fuel_level from the latest return inspection when still empty
if (column_exists($conn, 'vehicles', 'fuel_level')) {
  try {
    $conn->query("
      UPDATE vehicles v
      INNER JOIN (
        SELECT r.vehicle_id, ri.fuel_level
        FROM return_inspections ri
        INNER JOIN rentals r ON r.id = ri.rental_id
        INNER JOIN (
          SELECT r2.vehicle_id, MAX(ri2.id) AS max_id
          FROM return_inspections ri2
          INNER JOIN rentals r2 ON r2.id = ri2.rental_id
          WHERE ri2.fuel_level IS NOT NULL AND ri2.fuel_level <> ''
          GROUP BY r2.vehicle_id
        ) latest ON latest.max_id = ri.id
      ) src ON src.vehicle_id = v.id
      SET v.fuel_level = src.fuel_level
      WHERE v.fuel_level IS NULL
    ");
  } catch (Throwable $e) {
    error_log('fuel_level backfill failed: ' . $e->getMessage());
  }
}
// Expand vehicle_type enum if needed so form values save cleanly
try {
  $conn->query("ALTER TABLE vehicles MODIFY COLUMN vehicle_type ENUM('SUV','Sedan','Motorcycle','Hatchback','Pickup Truck','Crossover','Minivan','Van','Other') NULL");
} catch (Throwable $e) {
  // ignore if already compatible
}

/* ---------- AJAX: fetch single ---------- */
if(isset($_GET['get'])){
  $id=(int)$_GET['get'];
  $stmt=$conn->prepare("SELECT * FROM vehicles WHERE id=? LIMIT 1");
  $stmt->bind_param('i',$id);$stmt->execute();
  $r=$stmt->get_result()->fetch_assoc();
  $stmt->close();

  // Prefer stored vehicle fuel_level; fallback to latest return inspection / rental return
  if ($r && (empty($r['fuel_level']) || $r['fuel_level'] === null)) {
    $fuelFromReturn = null;
    try {
      $fuelStmt = $conn->prepare("
        SELECT ri.fuel_level
        FROM return_inspections ri
        JOIN rentals r2 ON r2.id = ri.rental_id
        WHERE r2.vehicle_id = ?
          AND ri.fuel_level IS NOT NULL
          AND ri.fuel_level <> ''
        ORDER BY ri.created_at DESC, ri.id DESC
        LIMIT 1
      ");
      $fuelStmt->bind_param('i', $id);
      $fuelStmt->execute();
      $fuelRow = $fuelStmt->get_result()->fetch_assoc();
      $fuelStmt->close();
      if (!empty($fuelRow['fuel_level'])) {
        $fuelFromReturn = $fuelRow['fuel_level'];
      }
    } catch (Throwable $e) {
      // ignore fallback errors
    }
    if ($fuelFromReturn === null) {
      try {
        $fuelStmt = $conn->prepare("
          SELECT rr.fuel_level
          FROM rental_returns rr
          JOIN rentals r2 ON r2.id = rr.rental_id
          WHERE r2.vehicle_id = ?
            AND rr.fuel_level IS NOT NULL
            AND rr.fuel_level <> ''
          ORDER BY rr.created_at DESC, rr.id DESC
          LIMIT 1
        ");
        $fuelStmt->bind_param('i', $id);
        $fuelStmt->execute();
        $fuelRow = $fuelStmt->get_result()->fetch_assoc();
        $fuelStmt->close();
        if (!empty($fuelRow['fuel_level'])) {
          $fuelFromReturn = $fuelRow['fuel_level'];
        }
      } catch (Throwable $e) {
        // ignore
      }
    }
    if ($fuelFromReturn !== null) {
      // Normalize legacy rental_returns value "1/2" → "half"
      if ($fuelFromReturn === '1/2') {
        $fuelFromReturn = 'half';
      }
      $allowed = ['full','3/4','half','1/4','empty'];
      if (in_array($fuelFromReturn, $allowed, true)) {
        $r['fuel_level'] = $fuelFromReturn;
      }
    }
  }

  header('Content-Type:application/json');echo json_encode($r??[]);exit;
}

/* ---------- test vehicle submission ---------- */
if(isset($_POST['test_vehicle'])){
  echo "<!-- DEBUG: Test vehicle endpoint reached -->\n";
  echo "POST data: " . print_r($_POST, true);
  echo "FILES data: " . print_r($_FILES, true);
  exit;
}

/* ---------- save ---------- */
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['form_type']??'')==='save'){
  error_log("=== VEHICLE SAVE HANDLER CALLED ===");
  error_log("POST data: " . print_r($_POST, true));
  
  $id=(int)($_POST['id']??0);
  $plate=clean_str($_POST['plate_no']??'');
  $chassis=clean_str($_POST['chassis_number']??'');
  $engine=clean_str($_POST['engine_number']??'');
  $maker=clean_str($_POST['maker']??'');
  $model=clean_str($_POST['model']??'');
  $ownership_type=clean_str($_POST['ownership_type']??($_POST['classification']??'Personal'));
  $type=clean_str($_POST['vehicle_type']??'Sedan');
  $seats=(int)($_POST['seats']??0);
  $max_capacity_kg = ($_POST['max_capacity_kg'] ?? '') === '' ? null : (float)$_POST['max_capacity_kg'];
  $year=(int)($_POST['year']??0);
  $odo=(float)($_POST['odometer']??0);
  $rate_cdo=(float)($_POST['daily_rate_cdo']??0);
  $rate_outside=(float)($_POST['daily_rate_outside_cdo']??0);
  $promo_discount_type = strtolower(trim((string)($_POST['promo_discount_type'] ?? 'none')));
  if (!in_array($promo_discount_type, ['none', 'percent', 'fixed'], true)) {
    $promo_discount_type = 'none';
  }
  $promo_discount_value = (float)($_POST['promo_discount_value'] ?? 0);
  $promo_start_date = trim((string)($_POST['promo_start_date'] ?? ''));
  $promo_start_time = trim((string)($_POST['promo_start_time'] ?? '00:00'));
  $promo_end_date = trim((string)($_POST['promo_end_date'] ?? ''));
  $promo_end_time = trim((string)($_POST['promo_end_time'] ?? '23:59'));
  $promo_starts_at = null;
  $promo_ends_at = null;

  if ($promo_discount_type === 'none' || $promo_discount_value <= 0) {
    $promo_discount_type = 'none';
    $promo_discount_value = 0.0;
  }
  if ($promo_discount_type === 'percent' && $promo_discount_value > 100) {
    $promo_discount_value = 100.0;
  }

  if ($promo_discount_type !== 'none') {
    if ($promo_start_date === '' || $promo_end_date === '') {
      $_SESSION['flash_error'] = 'Please set promo start and end date/time.';
      header("Location: ".$_SERVER['PHP_SELF']); exit;
    }
    if (!preg_match('/^\d{2}:\d{2}/', $promo_start_time)) $promo_start_time = '00:00';
    if (!preg_match('/^\d{2}:\d{2}/', $promo_end_time)) $promo_end_time = '23:59';
    $promo_starts_at = $promo_start_date . ' ' . substr($promo_start_time, 0, 5) . ':00';
    $promo_ends_at = $promo_end_date . ' ' . substr($promo_end_time, 0, 5) . ':00';
    $startTs = strtotime($promo_starts_at);
    $endTs = strtotime($promo_ends_at);
    if (!$startTs || !$endTs || $endTs <= $startTs) {
      $_SESSION['flash_error'] = 'Promo end date/time must be after the start date/time.';
      header("Location: ".$_SERVER['PHP_SELF']); exit;
    }
  }
  $trans=clean_str($_POST['transmission']??'MT');
  $comfort=clean_str($_POST['comfort_level']??'Standard');
  $fuel=clean_str($_POST['fuel_type']??'');
  $fuel_level=clean_str($_POST['fuel_level']??'');
  $vehicle_condition=clean_str($_POST['vehicle_condition']??($_POST['condition_status']??''));
  $category=clean_str($_POST['category']??'');
  
  $allowedVehicleTypes = ['Sedan','SUV','Van','Pickup Truck','Motorcycle','Hatchback','Crossover','Minivan'];
  $allowedOwnershipTypes = ['Personal','Company'];
  $allowedVehicleConditions = ['Excellent','Good','Fair','Poor'];
  $allowedCategories = ['Economy','Standard','Premium','Luxury'];
  $allowedFuelLevels = ['full','3/4','half','1/4','empty'];
  if ($fuel_level !== '' && !in_array($fuel_level, $allowedFuelLevels, true)) {
    $fuel_level = '';
  }
  if ($fuel_level === '') {
    $fuel_level = null;
  }
  
  $cond_upper = strtoupper($vehicle_condition);
  if ($vehicle_condition === '' && $cond_upper !== '') {
    $vehicle_condition = $cond_upper;
  }
  if (in_array($cond_upper, ['EXCELLENT','GOOD','FAIR','POOR'], true) && !in_array($vehicle_condition, $allowedVehicleConditions, true)) {
    $vehicle_condition = ucfirst(strtolower($cond_upper));
  }
  
  if ($plate === '' || $maker === '' || $model === '' || $ownership_type === '' || $type === '' || $vehicle_condition === '') {
    $_SESSION['flash_error'] = 'Please fill in all required fields.';
    header("Location: ".$_SERVER['PHP_SELF']);exit;
  }
  if (!in_array($type, $allowedVehicleTypes, true)) {
    $_SESSION['flash_error'] = 'Invalid Vehicle Type selected.';
    header("Location: ".$_SERVER['PHP_SELF']);exit;
  }
  if (!in_array($ownership_type, $allowedOwnershipTypes, true)) {
    $_SESSION['flash_error'] = 'Invalid Ownership Type selected.';
    header("Location: ".$_SERVER['PHP_SELF']);exit;
  }
  if (!in_array($vehicle_condition, $allowedVehicleConditions, true)) {
    $_SESSION['flash_error'] = 'Invalid Vehicle Condition selected.';
    header("Location: ".$_SERVER['PHP_SELF']);exit;
  }
  if ($category !== '' && !in_array($category, $allowedCategories, true)) {
    $_SESSION['flash_error'] = 'Invalid Category selected.';
    header("Location: ".$_SERVER['PHP_SELF']);exit;
  }
  if ($max_capacity_kg !== null && $max_capacity_kg <= 0) {
    $_SESSION['flash_error'] = 'Maximum Capacity (KG) must be greater than 0.';
    header("Location: ".$_SERVER['PHP_SELF']);exit;
  }
  
  // Create make_model field (required by database)
  $make_model = clean_str($maker . ' ' . $model);
  
  // Set daily_rate (use CDO rate as default)
  $daily_rate = $rate_cdo > 0 ? $rate_cdo : $rate_outside;

  $photo=null;
  $remove_photo = ($_POST['remove_photo'] ?? '0') === '1';
  
  // Handle photo removal
  if($remove_photo && $id){
    $old=$conn->query("SELECT photo FROM vehicles WHERE id=$id")->fetch_assoc();
    if(!empty($old['photo'])){
      unlink_silent(VEH_IMG_DIR.'/'.$old['photo']);
      $photo = null; // Explicitly set to null
    }
  }
  // Handle new photo upload
  elseif(!empty($_FILES['photo']['name'])){
    $ext=strtolower(pathinfo($_FILES['photo']['name'],PATHINFO_EXTENSION));
    if(in_array($ext,['jpg','jpeg','png','webp'])){
      $photo=uniqid('veh_',true).'.'.$ext;
      move_uploaded_file($_FILES['photo']['tmp_name'],VEH_IMG_DIR.'/'.$photo);
    }
  }

  // Keep existing photo if not changing it
  if($id && $photo===null && !$remove_photo && empty($_FILES['photo']['name'])){
    $old=$conn->query("SELECT photo FROM vehicles WHERE id=$id")->fetch_assoc();
    if(!empty($old['photo'])){
      $photo = $old['photo'];
    }
  }

  $has_category = column_exists($conn, 'vehicles', 'category');
  $has_condition_status = column_exists($conn, 'vehicles', 'condition_status');
  $has_vehicle_condition = column_exists($conn, 'vehicles', 'vehicle_condition');
  $has_ownership_type = column_exists($conn, 'vehicles', 'ownership_type');
  $has_classification = column_exists($conn, 'vehicles', 'classification');
  $has_max_capacity_kg = column_exists($conn, 'vehicles', 'max_capacity_kg');
  $has_chassis = column_exists($conn, 'vehicles', 'chassis_number');
  $has_engine = column_exists($conn, 'vehicles', 'engine_number');
  $has_fuel_type = column_exists($conn, 'vehicles', 'fuel_type');
  $has_fuel_level = column_exists($conn, 'vehicles', 'fuel_level');
  $has_promo_type = column_exists($conn, 'vehicles', 'promo_discount_type');
  $has_promo_value = column_exists($conn, 'vehicles', 'promo_discount_value');
  $has_promo_starts = column_exists($conn, 'vehicles', 'promo_starts_at');
  $has_promo_ends = column_exists($conn, 'vehicles', 'promo_ends_at');
  $has_listing = column_exists($conn, 'vehicles', 'listing_description');
  $listing_description = trim((string)($_POST['listing_description'] ?? ''));
  if (function_exists('mb_substr')) {
    $listing_description = mb_substr($listing_description, 0, 2000);
  } else {
    $listing_description = substr($listing_description, 0, 2000);
  }

  if($id){
    $sets = [];
    $types = '';
    $params = [];

    $sets[] = 'plate_no=?'; $types .= 's'; $params[] = $plate;
    if ($has_chassis) { $sets[] = 'chassis_number=?'; $types .= 's'; $params[] = $chassis; }
    if ($has_engine) { $sets[] = 'engine_number=?'; $types .= 's'; $params[] = $engine; }
    $sets[] = 'maker=?'; $types .= 's'; $params[] = $maker;
    $sets[] = 'model=?'; $types .= 's'; $params[] = $model;
    $sets[] = 'make_model=?'; $types .= 's'; $params[] = $make_model;
    $sets[] = 'vehicle_type=?'; $types .= 's'; $params[] = $type;
    $sets[] = 'seats=?'; $types .= 'i'; $params[] = $seats;
    $sets[] = 'year=?'; $types .= 'i'; $params[] = $year;
    $sets[] = 'odometer=?'; $types .= 'd'; $params[] = $odo;
    $sets[] = 'daily_rate=?'; $types .= 'd'; $params[] = $daily_rate;
    $sets[] = 'daily_rate_cdo=?'; $types .= 'd'; $params[] = $rate_cdo;
    $sets[] = 'daily_rate_outside_cdo=?'; $types .= 'd'; $params[] = $rate_outside;
    if ($has_promo_type) { $sets[] = 'promo_discount_type=?'; $types .= 's'; $params[] = $promo_discount_type; }
    if ($has_promo_value) { $sets[] = 'promo_discount_value=?'; $types .= 'd'; $params[] = $promo_discount_value; }
    if ($has_promo_starts) { $sets[] = 'promo_starts_at=?'; $types .= 's'; $params[] = $promo_starts_at; }
    if ($has_promo_ends) { $sets[] = 'promo_ends_at=?'; $types .= 's'; $params[] = $promo_ends_at; }
    $sets[] = 'transmission=?'; $types .= 's'; $params[] = $trans;
    $sets[] = 'comfort_level=?'; $types .= 's'; $params[] = $comfort;
    if ($has_listing) { $sets[] = 'listing_description=?'; $types .= 's'; $params[] = $listing_description; }
    if ($has_fuel_type) { $sets[] = 'fuel_type=?'; $types .= 's'; $params[] = $fuel; }
    if ($has_fuel_level) { $sets[] = 'fuel_level=?'; $types .= 's'; $params[] = $fuel_level; }

    if($has_ownership_type){
      $sets[] = 'ownership_type=?'; $types .= 's'; $params[] = $ownership_type;
    } elseif($has_classification){
      $sets[] = 'classification=?'; $types .= 's'; $params[] = $ownership_type;
    }

    if($has_vehicle_condition){
      $sets[] = 'vehicle_condition=?'; $types .= 's'; $params[] = $vehicle_condition;
    } elseif($has_condition_status){
      $sets[] = 'condition_status=?'; $types .= 's'; $params[] = $vehicle_condition;
    }
    if($has_category){
      $sets[] = 'category=?'; $types .= 's'; $params[] = ($category !== '' ? $category : null);
    }
    if($has_max_capacity_kg){
      $sets[] = 'max_capacity_kg=?'; $types .= 's';
      $params[] = ($max_capacity_kg === null ? null : number_format($max_capacity_kg, 2, '.', ''));
    }

    $sets[] = 'photo=?'; $types .= 's'; $params[] = $photo;
    $types .= 'i'; $params[] = $id;

    $sql = 'UPDATE vehicles SET ' . implode(',', $sets) . ' WHERE id=?';
    $stmt=$conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
  }else{
    $status='available';
    $cols = [];
    $vals = [];
    $types = '';
    $params = [];

    $cols[] = 'plate_no'; $vals[] = '?'; $types .= 's'; $params[] = $plate;
    if ($has_chassis) { $cols[] = 'chassis_number'; $vals[] = '?'; $types .= 's'; $params[] = $chassis; }
    if ($has_engine) { $cols[] = 'engine_number'; $vals[] = '?'; $types .= 's'; $params[] = $engine; }
    $cols[] = 'maker'; $vals[] = '?'; $types .= 's'; $params[] = $maker;
    $cols[] = 'model'; $vals[] = '?'; $types .= 's'; $params[] = $model;
    $cols[] = 'make_model'; $vals[] = '?'; $types .= 's'; $params[] = $make_model;
    $cols[] = 'vehicle_type'; $vals[] = '?'; $types .= 's'; $params[] = $type;
    $cols[] = 'seats'; $vals[] = '?'; $types .= 'i'; $params[] = $seats;
    $cols[] = 'year'; $vals[] = '?'; $types .= 'i'; $params[] = $year;
    $cols[] = 'odometer'; $vals[] = '?'; $types .= 'd'; $params[] = $odo;
    $cols[] = 'daily_rate'; $vals[] = '?'; $types .= 'd'; $params[] = $daily_rate;
    $cols[] = 'daily_rate_cdo'; $vals[] = '?'; $types .= 'd'; $params[] = $rate_cdo;
    $cols[] = 'daily_rate_outside_cdo'; $vals[] = '?'; $types .= 'd'; $params[] = $rate_outside;
    if ($has_promo_type) { $cols[] = 'promo_discount_type'; $vals[] = '?'; $types .= 's'; $params[] = $promo_discount_type; }
    if ($has_promo_value) { $cols[] = 'promo_discount_value'; $vals[] = '?'; $types .= 'd'; $params[] = $promo_discount_value; }
    if ($has_promo_starts) { $cols[] = 'promo_starts_at'; $vals[] = '?'; $types .= 's'; $params[] = $promo_starts_at; }
    if ($has_promo_ends) { $cols[] = 'promo_ends_at'; $vals[] = '?'; $types .= 's'; $params[] = $promo_ends_at; }
    $cols[] = 'transmission'; $vals[] = '?'; $types .= 's'; $params[] = $trans;
    $cols[] = 'comfort_level'; $vals[] = '?'; $types .= 's'; $params[] = $comfort;
    if ($has_listing) { $cols[] = 'listing_description'; $vals[] = '?'; $types .= 's'; $params[] = $listing_description; }
    if ($has_fuel_type) { $cols[] = 'fuel_type'; $vals[] = '?'; $types .= 's'; $params[] = $fuel; }
    if ($has_fuel_level) { $cols[] = 'fuel_level'; $vals[] = '?'; $types .= 's'; $params[] = $fuel_level; }

    if($has_ownership_type){
      $cols[] = 'ownership_type'; $vals[] = '?'; $types .= 's'; $params[] = $ownership_type;
    } elseif($has_classification){
      $cols[] = 'classification'; $vals[] = '?'; $types .= 's'; $params[] = $ownership_type;
    }

    if($has_vehicle_condition){
      $cols[] = 'vehicle_condition'; $vals[] = '?'; $types .= 's'; $params[] = $vehicle_condition;
    } elseif($has_condition_status){
      $cols[] = 'condition_status'; $vals[] = '?'; $types .= 's'; $params[] = $vehicle_condition;
    }
    if($has_category){
      $cols[] = 'category'; $vals[] = '?'; $types .= 's'; $params[] = ($category !== '' ? $category : null);
    }
    if($has_max_capacity_kg){
      $cols[] = 'max_capacity_kg'; $vals[] = '?'; $types .= 's';
      $params[] = ($max_capacity_kg === null ? null : number_format($max_capacity_kg, 2, '.', ''));
    }

    $cols[] = 'current_status'; $vals[] = '?'; $types .= 's'; $params[] = $status;
    $cols[] = 'photo'; $vals[] = '?'; $types .= 's'; $params[] = $photo;

    $stmt=$conn->prepare('INSERT INTO vehicles('.implode(',', $cols).') VALUES('.implode(',', $vals).')');
    $stmt->bind_param($types, ...$params);
  }
  
  try {
    $result = $stmt->execute();
    error_log("Database execute result: " . ($result ? 'SUCCESS' : 'FAILED'));
    
    if ($result) {
      error_log("Vehicle saved successfully, redirecting...");
      header("Location: ".$_SERVER['PHP_SELF']);exit;
    } else {
      error_log("Database error: " . $stmt->error);
      echo "Error saving vehicle: " . $stmt->error;
      exit;
    }
  } catch (Exception $e) {
    error_log("Exception during vehicle save: " . $e->getMessage());
    echo "Exception saving vehicle: " . $e->getMessage();
    exit;
  }
}

/* ---------- delete ---------- */
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['form_type']??'')==='delete'){
  $id=(int)$_POST['delete_id'];
  $old=$conn->query("SELECT photo FROM vehicles WHERE id=$id")->fetch_assoc();
  if(!empty($old['photo']))unlink_silent(VEH_IMG_DIR.'/'.$old['photo']);
  $conn->query("DELETE FROM vehicles WHERE id=$id");
  header("Location: ".$_SERVER['PHP_SELF']);exit;
}


// Get vehicles with availability information (fixed to prevent duplicates)
$rows=$conn->query("
  SELECT DISTINCT v.*, 
    r.start_date as rental_start,
    r.end_date as rental_end,
    r.status as rental_status,
    m.schedule_date as maintenance_date,
    m.status as maintenance_status
  FROM vehicles v
  LEFT JOIN (
    SELECT DISTINCT r1.vehicle_id, r1.start_date, r1.end_date, r1.status
    FROM rentals r1
    WHERE r1.status IN ('ongoing', 'reserved', 'pending')
    AND r1.id = (
      SELECT r2.id 
      FROM rentals r2 
      WHERE r2.vehicle_id = r1.vehicle_id 
      AND r2.status IN ('ongoing', 'reserved', 'pending')
      ORDER BY r2.start_date DESC 
      LIMIT 1
    )
  ) r ON v.id = r.vehicle_id
  LEFT JOIN (
    SELECT DISTINCT m1.vehicle_id, m1.schedule_date, m1.status
    FROM maintenance m1
    WHERE m1.status IN ('scheduled', 'in_progress')
    AND m1.id = (
      SELECT m2.id 
      FROM maintenance m2 
      WHERE m2.vehicle_id = m1.vehicle_id 
      AND m2.status IN ('scheduled', 'in_progress')
      ORDER BY m2.schedule_date DESC 
      LIMIT 1
    )
  ) m ON v.id = m.vehicle_id
  ORDER BY v.make_model ASC
");

// Handle flash messages
$flash_success = $_SESSION['flash_success'] ?? '';
$flash_error = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>FleetGo • Vehicles</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
:root{
 --bg:#0b0d10;--card:#101419;--text:#f2f6fa;--muted:#9aa6b3;
 --brand:#5dd0ff;--brand2:#7cffc7;--radius:18px;--shadow:0 10px 28px rgba(0,0,0,.45);
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,system-ui,sans-serif}
h1{font-weight:800;margin:0 0 4px 0;font-size:2rem;color:var(--text);display:flex;align-items:center;gap:.5rem;}
h1::before{content:'';}
.btn{cursor:pointer;border:0;border-radius:12px;padding:12px 20px;font-weight:700;transition:all .3s cubic-bezier(0.4,0,0.2,1);position:relative;overflow:hidden;font-size:.95rem;letter-spacing:-.01em;}
.btn::before{content:'';position:absolute;top:0;left:-100%;width:100%;height:100%;background:linear-gradient(90deg,transparent,rgba(255,255,255,.1),transparent);transition:left .6s ease;}
.btn:hover::before{left:100%;}
.btn-primary{background:linear-gradient(135deg,var(--brand),var(--brand2));color:#04121b;box-shadow:0 8px 24px rgba(93,208,255,.3),0 0 0 1px rgba(93,208,255,.2);border:1px solid rgba(93,208,255,.3);}
.btn-primary:hover{box-shadow:0 12px 32px rgba(93,208,255,.4),0 0 0 1px rgba(93,208,255,.4);transform:translateY(-3px);background:linear-gradient(135deg,var(--brand2),var(--brand));}
.btn-primary:active{transform:translateY(-1px);box-shadow:0 6px 16px rgba(93,208,255,.3);}
.btn-dark{background:linear-gradient(135deg,#1a2833,#2a3843);color:#d9e8f2;border:1px solid rgba(255,255,255,.1);box-shadow:0 4px 12px rgba(0,0,0,.3);}
.btn-dark:hover{background:linear-gradient(135deg,#2a3843,#3a4853);transform:translateY(-2px);box-shadow:0 8px 20px rgba(0,0,0,.4);border-color:rgba(93,208,255,.2);}
.btn-danger{background:linear-gradient(135deg,#3b1a1a,#4b2a2a);color:#ffc9c9;border:1px solid rgba(255,107,107,.2);box-shadow:0 4px 12px rgba(255,107,107,.2);}
.btn-danger:hover{background:linear-gradient(135deg,#4b2a2a,#5b3a3a);transform:translateY(-2px);box-shadow:0 8px 20px rgba(255,107,107,.3);}
.wrap{max-width:1280px;margin:0 auto;padding:24px;}
.toast{position:fixed;top:20px;right:20px;padding:16px 20px;border-radius:12px;font-weight:700;z-index:1000;animation:slideIn 0.3s ease;max-width:400px;}
.toast.success{background:linear-gradient(90deg,#7cffc7,#5dd0ff);color:#04121b;}
.toast.error{background:linear-gradient(90deg,#ff7b7b,#ff6b6b);color:#fff;}
@keyframes slideIn{from{transform:translateX(100%);}to{transform:translateX(0);}}

/* Header Styles */
.page-header{margin-bottom:32px;}
.header-content{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;margin-bottom:16px;}
.header-title h1{font-size:2rem;margin:0 0 4px 0;}
.header-subtitle{color:var(--muted);font-size:1rem;font-weight:500;margin:0;}

/* Filters Styles */
.filters-section{margin-bottom:24px;}
.filters-toggle{display:flex;align-items:center;gap:8px;padding:8px 16px;background:rgba(93,208,255,.1);border:1px solid rgba(93,208,255,.2);border-radius:8px;color:var(--brand);cursor:pointer;transition:all .3s ease;font-weight:600;}
.filters-toggle:hover{background:rgba(93,208,255,.15);border-color:var(--brand);}
.filters-icon{font-size:1rem;}
.filters-text{font-size:.9rem;}
.filters-arrow{font-size:.8rem;transition:transform .3s ease;}
.filters-toggle.active .filters-arrow{transform:rotate(180deg);}
.filters-content{max-height:0;overflow:hidden;transition:max-height .3s ease;}
.filters-content.open{max-height:200px;}
.filters-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;padding:16px 0;}
.filter-select{padding:10px 14px;border-radius:8px;border:1px solid rgba(255,255,255,.1);background:var(--card);color:var(--text);outline:none;font-size:.9rem;transition:all .3s ease;}
.filter-select:focus{border-color:var(--brand);box-shadow:0 0 0 3px rgba(93,208,255,.2);}

/* Table Styles */
.table-wrap{background:linear-gradient(180deg,#101419,#0b1016);border:1px solid rgba(255,255,255,.08);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden;}
.vehicles-table{width:100%;border-collapse:separate;border-spacing:0;}
.vehicles-table thead th{font-size:.8rem;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);text-align:left;padding:14px 18px;background:rgba(255,255,255,.02);border-bottom:1px solid rgba(255,255,255,.08);}
.vehicles-table tbody td{padding:14px 18px;border-bottom:1px solid rgba(255,255,255,.06);font-size:.95rem;vertical-align:middle;}
.vehicles-table tbody tr{transition:background .2s ease;}
.vehicles-table tbody tr:hover{background:rgba(93,208,255,.04);}
.col-plate{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;font-weight:700;color:var(--text);}
.make-model{font-weight:800;color:var(--text);}
.subtle{color:var(--muted);font-weight:600;font-size:.85rem;}
.status-pill{display:inline-flex;align-items:center;gap:8px;padding:6px 12px;border-radius:999px;font-weight:800;font-size:.8rem;text-transform:capitalize;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.04);}
.status-pill.available{border-color:rgba(124,255,199,.35);color:#7cffc7;background:rgba(124,255,199,.08);}
.status-pill.rented{border-color:rgba(255,209,102,.35);color:#ffd166;background:rgba(255,209,102,.08);}
.status-pill.maintenance{border-color:rgba(255,107,107,.35);color:#ff7b7b;background:rgba(255,107,107,.08);}
.cat-badge{display:inline-flex;align-items:center;padding:4px 10px;border-radius:999px;font-size:.75rem;font-weight:800;border:1px solid rgba(93,208,255,.25);background:rgba(93,208,255,.08);color:var(--brand);}
.row-actions{display:flex;gap:8px;justify-content:flex-end;}
.row-actions .btn{padding:9px 12px;border-radius:10px;font-size:.85rem;}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:24px;margin-top:24px;}
.card{background:linear-gradient(180deg,#101419,#0b1016);border:1px solid rgba(255,255,255,.08);
 border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden;display:flex;flex-direction:column;transition:all .4s cubic-bezier(0.4,0,0.2,1);position:relative;}
.card:hover{transform:translateY(-8px);box-shadow:0 20px 40px rgba(93,208,255,.3), 0 0 0 1px rgba(93,208,255,.2);}
.card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,var(--brand),var(--brand2));opacity:0;transition:opacity .3s;}
.card:hover::before{opacity:1;}
.card .pic{height:200px;background:#0c1116;position:relative;overflow:hidden;}
.card img{width:100%;height:100%;object-fit:cover;transition:transform .3s ease;}
.card:hover img{transform:scale(1.05);}
.card .body{padding:20px;flex:1;display:flex;flex-direction:column;gap:16px;}

/* Card Header */
.card-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px;}
.title{font-weight:800;font-size:1.2rem;color:var(--text);line-height:1.3;}
.year-badge{background:linear-gradient(135deg,var(--brand),var(--brand2));color:#04121b;padding:4px 12px;border-radius:20px;font-weight:700;font-size:.8rem;}

/* Status & Price Overlays */
.status-overlay{position:absolute;top:12px;left:12px;z-index:2;}
.price-overlay{position:absolute;top:12px;right:12px;z-index:2;}
.badge{padding:4px 12px;border-radius:20px;font-weight:700;font-size:.75rem;text-transform:uppercase;letter-spacing:.5px;backdrop-filter:blur(10px);}
.badge.green{background:linear-gradient(135deg,#00ff88,#00cc66);color:#04121b;}
.badge.yellow{background:linear-gradient(135deg,#ffaa00,#cc8800);color:#04121b;}
.badge.red{background:linear-gradient(135deg,#ff0044,#cc0000);color:#fff;}
.price-tag{background:rgba(0,0,0,.8);color:var(--brand2);padding:6px 12px;border-radius:20px;font-weight:700;font-size:.85rem;backdrop-filter:blur(10px);}

/* Enhanced Vehicle Info */
.vehicle-info{background:rgba(93,208,255,.05);border:1px solid rgba(93,208,255,.1);border-radius:12px;padding:12px;position:relative;}
.vehicle-info::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:linear-gradient(180deg,var(--brand),var(--brand2));border-radius:0 2px 2px 0;}
.info-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;}
.info-row:last-child{margin-bottom:0;}
.info-label{color:var(--muted);font-size:.85rem;font-weight:600;}
.info-value{color:var(--text);font-weight:700;font-size:.9rem;}
.info-value.plate{background:rgba(93,208,255,.1);padding:2px 8px;border-radius:6px;font-family:monospace;}

/* Enhanced Identifiers */
.identifiers{background:rgba(124,255,199,.05);border:1px solid rgba(124,255,199,.1);border-radius:12px;padding:12px;position:relative;}
.identifiers::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:linear-gradient(180deg,var(--brand2),var(--brand));border-radius:0 2px 2px 0;}
.identifier-header{color:var(--brand2);font-weight:700;font-size:.85rem;margin-bottom:8px;text-transform:uppercase;letter-spacing:.5px;display:flex;align-items:center;gap:8px;}
.identifier-header::before{content:'🔧';font-size:.9rem;}
.identifier-item{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;}
.identifier-item:last-child{margin-bottom:0;}
.identifier-label{color:var(--muted);font-size:.8rem;font-weight:600;}
.identifier-value{color:var(--text);font-weight:700;font-size:.85rem;font-family:monospace;}

/* Vehicle Stats */
.vehicle-stats{display:grid;grid-template-columns:1fr 1fr;gap:8px;}
.stat-item{display:flex;align-items:center;gap:6px;background:rgba(255,255,255,.03);padding:8px;border-radius:8px;border:1px solid rgba(255,255,255,.05);}
.stat-icon{font-size:1rem;}
.stat-label{color:var(--muted);font-size:.75rem;font-weight:600;flex:1;}
.stat-value{color:var(--text);font-weight:700;font-size:.8rem;}

/* Enhanced Status Badges */
.badge{padding:.4rem 1rem;border-radius:20px;font-weight:700;font-size:.75rem;text-transform:capitalize;backdrop-filter:blur(10px);display:flex;align-items:center;gap:4px;box-shadow:0 2px 8px rgba(0,0,0,.2);}
.green{background:rgba(124,255,199,.15);color:#7cffc7;border:1px solid rgba(124,255,199,.4);}
.green::before{content:'🟢';font-size:.8rem;}
.yellow{background:rgba(255,209,102,.15);color:#ffd166;border:1px solid rgba(255,209,102,.4);}
.yellow::before{content:'🟡';font-size:.8rem;}
.red{background:rgba(255,107,107,.15);color:#ff7b7b;border:1px solid rgba(255,107,107,.4);}
.red::before{content:'🔴';font-size:.8rem;}

/* Enhanced Pricing Info */
.pricing-info{background:rgba(93,208,255,.05);border:1px solid rgba(93,208,255,.1);border-radius:12px;padding:12px;position:relative;}
.pricing-info::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:linear-gradient(180deg,var(--brand),var(--brand2));border-radius:0 2px 2px 0;}
.pricing-header{color:var(--brand);font-weight:700;font-size:.85rem;margin-bottom:8px;text-transform:uppercase;letter-spacing:.5px;display:flex;align-items:center;gap:8px;}
.pricing-header::before{content:'💰';font-size:.9rem;}
.pricing-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;}
.pricing-row:last-child{margin-bottom:0;}
.pricing-label{color:var(--muted);font-size:.8rem;font-weight:600;}
.pricing-value{color:var(--brand2);font-weight:700;font-size:.85rem;}
.pricing-concise{color:var(--brand2);font-weight:700;font-size:.9rem;text-align:center;padding:8px 0;}

/* Availability Info */
.availability-info{background:rgba(124,255,199,.05);border:1px solid rgba(124,255,199,.1);border-radius:12px;padding:12px;}
.availability-header{color:var(--brand2);font-weight:700;font-size:.85rem;margin-bottom:8px;text-transform:uppercase;letter-spacing:.5px;}
.availability-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;}
.availability-row:last-child{margin-bottom:0;}
.availability-label{color:var(--muted);font-size:.8rem;font-weight:600;}
.availability-value{font-weight:700;font-size:.85rem;}
.availability-value.available{color:#7cffc7;}
.availability-value.rented{color:#ffd166;}
.availability-value.maintenance{color:#ff7b7b;}

/* Enhanced Actions */
.actions{display:flex;gap:8px;margin-top:auto;padding-top:16px;border-top:1px solid rgba(255,255,255,.05);opacity:0;transform:translateY(10px);transition:all .3s ease;}
.card:hover .actions{opacity:1;transform:translateY(0);}
.actions .btn{flex:1;display:flex;align-items:center;justify-content:center;gap:6px;padding:10px 16px;font-size:.85rem;transition:all .2s ease;}
.actions .btn:hover{transform:translateY(-2px);box-shadow:0 4px 12px rgba(0,0,0,.3);}
.btn-icon{font-size:1rem;}
.modal-bg{
  position:fixed;inset:0;
  background:rgba(0,0,0,.8);backdrop-filter:blur(16px);
  visibility:hidden;opacity:0;
  transition:all .4s cubic-bezier(0.4,0,0.2,1);
  z-index:10050;
  padding:24px 16px;
  display:flex;align-items:center;justify-content:center;
  overflow-y:auto;overscroll-behavior:contain;
}
.modal-bg.open{visibility:visible;opacity:1;}
.modal{
  background:linear-gradient(145deg,#0f141a,#1a1f2e);
  border:1px solid rgba(93,208,255,.2);border-radius:24px;
  width:min(920px,96vw);max-height:min(92vh,980px);
  overflow-y:auto;overflow-x:hidden;
  box-shadow:0 25px 50px rgba(0,0,0,.6),0 0 0 1px rgba(93,208,255,.1);
  position:relative;margin:auto;flex-shrink:0;
  transform:scale(.96) translateY(12px);
  transition:transform .35s cubic-bezier(0.4,0,0.2,1), opacity .35s ease;
  scrollbar-width:thin;
  scrollbar-color:rgba(255,255,255,.28) transparent;
}
.modal::-webkit-scrollbar{width:8px}
.modal::-webkit-scrollbar-track{background:transparent}
.modal::-webkit-scrollbar-thumb{background:rgba(255,255,255,.28);border-radius:8px}
.modal::-webkit-scrollbar-thumb:hover{background:rgba(255,255,255,.4)}
.modal-bg.open .modal{transform:scale(1) translateY(0);}
.modal::before{content:'';position:absolute;top:0;left:0;right:0;height:4px;background:linear-gradient(90deg,var(--brand),var(--brand2));border-radius:24px 24px 0 0;}
.modal::after{content:'';position:absolute;inset:0;border-radius:24px;background:linear-gradient(145deg,rgba(93,208,255,.05),transparent);pointer-events:none;}
.modal-header{display:flex;justify-content:space-between;align-items:center;padding:28px 28px 0;margin-bottom:8px;position:relative;z-index:1;}
.modal h2{margin:0;font-weight:800;font-size:1.75rem;color:var(--text);display:flex;align-items:center;gap:12px;letter-spacing:-.02em;}
.modal h2::before{content:'';}
.modal-close{background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);color:var(--muted);font-size:1.25rem;cursor:pointer;padding:8px;border-radius:8px;transition:all .3s ease;width:36px;height:36px;display:flex;align-items:center;justify-content:center;}
.modal-close:hover{background:rgba(255,255,255,.1);color:var(--text);transform:scale(1.05);border-color:var(--brand);}
.modal form{padding:0 28px 28px;position:relative;z-index:1;}
.modal-section{margin-bottom:20px;background:rgba(255,255,255,.02);border-radius:16px;padding:20px;border:1px solid rgba(255,255,255,.05);transition:all .3s ease;}
.modal-section:hover{background:rgba(255,255,255,.03);border-color:rgba(93,208,255,.1);}
.modal-section-title{font-weight:700;color:var(--text);margin-bottom:16px;font-size:1.05rem;display:flex;align-items:center;gap:10px;letter-spacing:-.01em;}
.modal-section-title::before{content:'▸';color:var(--brand);font-size:1.2rem;transition:transform .3s ease;}
.modal-section:hover .modal-section-title::before{transform:translateX(2px);}
.modal-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px 18px;}
@media (max-width:640px){
  .modal-grid{grid-template-columns:1fr}
  .modal-header,.modal form{padding-left:18px;padding-right:18px}
  .modal-section{padding:16px}
}
label{display:block;margin:12px 0 6px;font-weight:600;color:var(--text);font-size:.95rem;letter-spacing:-.01em;}
.form-field label{margin:0 0 8px}
.form-field{position:relative;min-width:0}
input,select,textarea{width:100%;padding:14px 18px;border-radius:12px;border:1px solid rgba(255,255,255,.15);
 background:rgba(13,17,22,.8);color:var(--text);outline:none;font-size:.95rem;transition:all .3s ease;font-weight:500;font-family:inherit;}
input:focus,select:focus,textarea:focus{border-color:var(--brand);box-shadow:0 0 0 4px rgba(93,208,255,.2),0 0 20px rgba(93,208,255,.1);background:rgba(13,17,22,.95);}
input:hover,select:hover,textarea:hover{border-color:rgba(93,208,255,.3);background:rgba(13,17,22,.9);}
textarea{min-height:96px;resize:vertical;}
.modal-actions{display:flex;gap:16px;justify-content:flex-end;flex-wrap:wrap;padding-top:24px;border-top:1px solid rgba(255,255,255,.08);margin-top:24px;}
.validation-error{color:#ff6b6b;font-size:.85rem;margin-top:6px;display:none;padding:8px 12px;background:rgba(255,107,107,.1);border:1px solid rgba(255,107,107,.2);border-radius:8px;font-weight:500;animation:shake .5s ease;}
.validation-error.show{display:block;}
.loading-spinner{display:none;width:20px;height:20px;border:2px solid rgba(255,255,255,.2);border-top:2px solid var(--brand);border-radius:50%;animation:spin 1s linear infinite;filter:drop-shadow(0 0 4px rgba(93,208,255,.5));}
@keyframes spin{0%{transform:rotate(0deg);}100%{transform:rotate(360deg);}}
@keyframes shake{0%,100%{transform:translateX(0);}25%{transform:translateX(-5px);}75%{transform:translateX(5px);}}

/* Enhanced form field states */
input.error,select.error{border-color:#ff6b6b;background:rgba(255,107,107,.05);box-shadow:0 0 0 3px rgba(255,107,107,.2);}
input.success,select.success{border-color:#00ff88;background:rgba(0,255,136,.05);box-shadow:0 0 0 3px rgba(0,255,136,.2);}

/* Floating label effect */
.floating-label{position:relative;}
.floating-label input:focus ~ label,
.floating-label input:not(:placeholder-shown) ~ label,
.floating-label select:focus ~ label{transform:translateY(-25px) scale(.85);color:var(--brand);}

/* Form field enhancements */
.form-field label::after{content:'';position:absolute;bottom:-2px;left:0;width:0;height:2px;background:var(--brand);transition:width .3s ease;}
.form-field:focus-within label::after{width:100%;}

/* Enhanced Photo Manager */
.photo-manager{display:grid;grid-template-columns:1fr;gap:24px;}
.current-photo-container{background:rgba(255,255,255,.02);border:1px solid rgba(255,255,255,.05);border-radius:16px;padding:20px;transition:all .3s ease;}
.current-photo-container:hover{background:rgba(255,255,255,.03);border-color:rgba(93,208,255,.1);}
.photo-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;}
.photo-title{font-weight:600;color:var(--text);font-size:1rem;}
.btn-remove-photo{background:rgba(255,107,107,.1);border:1px solid rgba(255,107,107,.2);color:#ff6b6b;padding:6px 12px;border-radius:8px;font-size:.85rem;cursor:pointer;transition:all .3s ease;display:flex;align-items:center;gap:4px;}
.btn-remove-photo:hover{background:rgba(255,107,107,.2);border-color:rgba(255,107,107,.3);transform:translateY(-1px);}
.photo-display{position:relative;width:100%;height:200px;border-radius:12px;overflow:hidden;background:rgba(13,17,22,.5);border:1px solid rgba(255,255,255,.1);display:flex;align-items:center;justify-content:center;}
.photo-placeholder{text-align:center;color:var(--muted);display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;}
.placeholder-icon{font-size:2.5rem;margin-bottom:12px;opacity:.6;font-weight:300;letter-spacing:2px;text-transform:uppercase;}
.placeholder-text{font-weight:600;margin-bottom:6px;color:var(--text);font-size:1.1rem;}
.placeholder-subtext{font-size:.9rem;opacity:.7;line-height:1.4;}
.photo-display img{width:100%;height:100%;object-fit:cover;}

.upload-section{background:rgba(255,255,255,.02);border:1px solid rgba(255,255,255,.05);border-radius:16px;padding:20px;transition:all .3s ease;}
.upload-section:hover{background:rgba(255,255,255,.03);border-color:rgba(93,208,255,.1);}
.upload-header{margin-bottom:16px;}
.upload-title{font-weight:600;color:var(--text);font-size:1rem;display:block;margin-bottom:4px;}
.upload-subtitle{font-size:.85rem;color:var(--muted);opacity:.7;}
.upload-area{border:2px dashed rgba(93,208,255,.3);border-radius:12px;padding:32px;text-align:center;cursor:pointer;transition:all .3s ease;background:rgba(93,208,255,.02);position:relative;overflow:hidden;}
.upload-area:hover{border-color:var(--brand);background:rgba(93,208,255,.05);transform:translateY(-2px);}
.upload-area::before{content:'';position:absolute;top:0;left:0;right:0;bottom:0;background:linear-gradient(45deg,transparent 30%,rgba(93,208,255,.05) 50%,transparent 70%);opacity:0;transition:opacity .3s ease;}
.upload-area:hover::before{opacity:1;}
.upload-icon{font-size:2.5rem;margin-bottom:16px;color:var(--brand);opacity:.8;transition:all .3s ease;font-weight:300;letter-spacing:2px;text-transform:uppercase;}
.upload-area:hover .upload-icon{transform:scale(1.1);opacity:1;}
.upload-text{font-weight:600;color:var(--text);margin-bottom:6px;font-size:1.1rem;}
.upload-subtext{font-size:.9rem;color:var(--muted);opacity:.7;line-height:1.4;}

.preview-container{background:rgba(255,255,255,.02);border:1px solid rgba(93,208,255,.2);border-radius:16px;padding:20px;margin-top:16px;animation:slideIn .3s ease;}
.preview-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;}
.preview-title{font-weight:600;color:var(--text);font-size:1rem;}
.btn-cancel-preview{background:transparent;border:1px solid rgba(255,255,255,.1);color:var(--muted);width:28px;height:28px;border-radius:6px;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all .2s ease;}
.btn-cancel-preview:hover{background:rgba(255,255,255,.1);color:var(--text);}
.preview-display{position:relative;width:100%;height:200px;border-radius:12px;overflow:hidden;background:rgba(13,17,22,.5);border:1px solid rgba(255,255,255,.1);}
.preview-display img{width:100%;height:100%;object-fit:cover;transition:transform .3s ease;}
.preview-overlay{position:absolute;top:0;left:0;right:0;bottom:0;background:linear-gradient(to bottom,transparent 60%,rgba(0,0,0,.8) 100%);display:flex;align-items:flex-end;justify-content:center;padding:16px;opacity:0;transition:opacity .3s ease;}
.preview-display:hover .preview-overlay{opacity:1;}
.preview-actions{display:flex;gap:8px;}
.btn-preview-zoom,.btn-preview-remove{background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.2);color:var(--text);padding:6px 12px;border-radius:6px;font-size:.8rem;cursor:pointer;transition:all .2s ease;display:flex;align-items:center;gap:4px;}
.btn-preview-zoom:hover,.btn-preview-remove:hover{background:rgba(255,255,255,.2);transform:translateY(-1px);}


/* Zoom Modal */
.zoom-modal{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.9);display:flex;align-items:center;justify-content:center;z-index:2000;opacity:0;visibility:hidden;transition:all .3s ease;}
.zoom-modal.open{opacity:1;visibility:visible;}
.zoom-modal img{max-width:90vw;max-height:90vh;border-radius:12px;box-shadow:0 20px 40px rgba(0,0,0,.5);transform:scale(.9);transition:transform .3s ease;}
.zoom-modal.open img{transform:scale(1);}
.zoom-modal-close{position:absolute;top:20px;right:20px;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.2);color:var(--text);width:40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all .2s ease;}
.zoom-modal-close:hover{background:rgba(255,255,255,.2);transform:scale(1.1);}

/* Expandable Card Details */
.card-expandable{margin-top:16px;border-top:1px solid rgba(255,255,255,.05);padding-top:16px;}
.expand-toggle{width:100%;display:flex;align-items:center;justify-content:space-between;padding:10px 16px;background:rgba(93,208,255,.05);border:1px solid rgba(93,208,255,.1);border-radius:8px;color:var(--brand);cursor:pointer;transition:all .3s ease;font-weight:600;font-size:.9rem;}
.expand-toggle:hover{background:rgba(93,208,255,.1);border-color:var(--brand);transform:translateY(-1px);}
.expand-arrow{font-size:.8rem;transition:transform .3s ease;}
.expand-toggle.active .expand-arrow{transform:rotate(180deg);}
.expand-content{max-height:0;overflow:hidden;transition:max-height .4s ease;}
.expand-content.open{max-height:500px;margin-top:12px;}
.details-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:16px;}
.detail-item{display:flex;flex-direction:column;gap:4px;}
.detail-label{font-size:.8rem;color:var(--muted);font-weight:500;text-transform:uppercase;letter-spacing:.5px;}
.detail-value{font-size:.9rem;color:var(--text);font-weight:600;}
.card-actions{display:flex;gap:8px;padding-top:12px;border-top:1px solid rgba(255,255,255,.05);}
.btn-sm{padding:8px 12px;font-size:.85rem;border-radius:8px;}
.btn-sm span{margin-right:4px;}
</style>
</head>
<body>
<?php if(file_exists(__DIR__.'/includes/navbar.php')) include __DIR__.'/includes/navbar.php'; ?>
<div class="wrap">
  
  <?php if($flash_success): ?>
    <div class="toast success"><?= h($flash_success) ?></div>
  <?php endif; ?>
  
  <?php if($flash_error): ?>
    <div class="toast error"><?= h($flash_error) ?></div>
  <?php endif; ?>
  
  <!-- Header Section -->
<div class="page-header">
  <div class="header-content">
    <div class="header-title">
      <h1>Vehicle Records</h1>
      <p class="header-subtitle">Vehicle Management</p>
    </div>
    <button class="btn btn-primary" id="btnAdd">+ Add Vehicle</button>
  </div>
  
  <!-- Collapsible Filters -->
  <div class="filters-section">
    <button class="filters-toggle" id="filtersToggle">
      <span class="filters-icon">🔍</span>
      <span class="filters-text">Filters</span>
      <span class="filters-arrow">▼</span>
    </button>
    <div class="filters-content" id="filtersContent">
      <div class="filters-grid">
        <select id="statusFilter" class="filter-select">
          <option value="">All Status</option>
          <option value="available">🟢 Available</option>
          <option value="rented">🟡 Rented</option>
          <option value="maintenance">🔴 Maintenance</option>
        </select>
        <select id="ownershipFilter" class="filter-select">
          <option value="">All Ownership</option>
          <option value="Personal">Personal</option>
          <option value="Company">Company</option>
        </select>
        <select id="typeFilter" class="filter-select">
          <option value="">All Types</option>
          <option value="Sedan">Sedan</option>
          <option value="SUV">SUV</option>
          <option value="Hatchback">Hatchback</option>
          <option value="Crossover">Crossover</option>
          <option value="Pickup Truck">Pickup Truck</option>
          <option value="Van">Van</option>
          <option value="Minivan">Minivan</option>
          <option value="Motorcycle">Motorcycle</option>
        </select>
      </div>
    </div>
  </div>
</div>

  <div class="table-wrap">
    <table class="vehicles-table" id="vehiclesTable">
      <thead>
        <tr>
          <th style="width:84px;">Photo</th>
          <th style="width:160px;">Plate No</th>
          <th>Make + Model</th>
          <th style="width:160px;">Vehicle Type</th>
          <th style="width:160px;">Ownership Type</th>
          <th style="width:160px;">Status</th>
          <th style="width:220px;text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php while($v=$rows->fetch_assoc()):
        $st=strtolower((string)($v['current_status'] ?? ''));
        $stClass = in_array($st, ['available','rented','maintenance'], true) ? $st : 'maintenance';
        $type = (string)($v['vehicle_type'] ?? '');
        $own = (string)($v['ownership_type'] ?? ($v['classification'] ?? ''));
        $img = !empty($v['photo']) ? (VEH_IMG_URL.'/'.$v['photo']) : type_fallback($type);
      ?>
        <tr class="vehicle-row"
            data-id="<?=$v['id']?>"
            data-status="<?=h($st)?>"
            data-type="<?=h($type)?>"
            data-ownership="<?=h($own)?>">
          <td onclick="quickView(<?=$v['id']?>)">
            <img src="<?=h($img)?>" alt="<?=h($v['make_model'] ?? '')?>" style="width:54px;height:38px;object-fit:cover;border-radius:10px;border:1px solid rgba(255,255,255,.1);display:block;">
          </td>
          <td class="col-plate" onclick="quickView(<?=$v['id']?>)"><?=h($v['plate_no'])?></td>
          <td onclick="quickView(<?=$v['id']?>)">
            <div class="make-model"><?=h($v['make_model'] ?? trim(($v['maker'] ?? '').' '.($v['model'] ?? '')))?></div>
            <?php if(!empty($v['category'])): ?>
              <div style="margin-top:6px;"><span class="cat-badge"><?=h($v['category'])?></span></div>
            <?php endif; ?>
          </td>
          <td class="subtle"><?=h($type)?></td>
          <td class="subtle"><?=h($own)?></td>
          <td>
            <span class="status-pill <?=$stClass?>"><?=h($st)?></span>
          </td>
          <td>
            <div class="row-actions">
              <button class="btn btn-dark btn-edit" data-id="<?=$v['id']?>" type="button">Edit</button>
              <button class="btn btn-primary" type="button" onclick="quickView(<?=$v['id']?>)">View</button>
              <form method="post" onsubmit="return confirm('Delete this vehicle?')">
                <input type="hidden" name="form_type" value="delete">
                <input type="hidden" name="delete_id" value="<?=$v['id']?>">
                <button class="btn btn-danger" type="submit">Delete</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- MODAL -->
<div class="modal-bg" id="modalBg">
  <div class="modal">
    <div class="modal-header">
      <h2 id="modalTitle">Add Vehicle</h2>
      <button class="modal-close" id="modalClose">&times;</button>
    </div>
    <form method="post" enctype="multipart/form-data" id="vehForm">
      <input type="hidden" name="form_type" value="save"><input type="hidden" name="id" id="f_id"><input type="hidden" name="remove_photo" id="removePhotoFlag" value="0">
      
      <div class="modal-section">
        <div class="modal-section-title">🔍 Vehicle Identification</div>
        <div class="modal-grid">
          <div class="form-field">
            <label>Plate Number *</label>
            <input name="plate_no" id="f_plate" required placeholder="e.g., ABC-1234">
            <div class="validation-error" id="plate_error">Plate number is required</div>
          </div>
          <div class="form-field">
            <label>Chassis Number</label>
            <input name="chassis_number" id="f_chassis" placeholder="Enter chassis number (optional)">
          </div>
          <div class="form-field">
            <label>Engine Number</label>
            <input name="engine_number" id="f_engine" placeholder="Enter engine number (optional)">
          </div>
        </div>
      </div>
      
      <div class="modal-section">
        <div class="modal-section-title">Vehicle Information</div>
        <div class="modal-grid">
          <div class="form-field">
            <label>Maker</label>
            <select name="maker" id="f_maker" required>
              <option value="">Select Maker</option>
              <option value="Toyota">Toyota</option>
              <option value="Honda">Honda</option>
              <option value="Nissan">Nissan</option>
              <option value="Mitsubishi">Mitsubishi</option>
              <option value="Suzuki">Suzuki</option>
              <option value="Yamaha">Yamaha</option>
              <option value="Kawasaki">Kawasaki</option>
              <option value="Other">Other</option>
            </select>
          </div>
          <div class="form-field">
            <label>Model</label>
            <input name="model" id="f_model" required placeholder="e.g., Vios, City, Fortuner">
          </div>
          <div class="form-field">
            <label>Ownership Type</label>
            <select name="ownership_type" id="f_classification" required>
              <option value="Personal">Personal</option>
              <option value="Company">Company</option>
            </select>
          </div>
          <div class="form-field">
            <label>Vehicle Type</label>
            <select name="vehicle_type" id="f_type" required>
              <option value="">Select Type</option>
              <option value="Sedan">Sedan</option>
              <option value="SUV">SUV</option>
              <option value="Pickup Truck">Pickup Truck</option>
              <option value="Van">Van</option>
              <option value="Minivan">Minivan</option>
              <option value="Motorcycle">Motorcycle</option>
              <option value="Hatchback">Hatchback</option>
              <option value="Crossover">Crossover</option>
            </select>
          </div>
          <div class="form-field">
            <label>Vehicle Condition</label>
            <select name="vehicle_condition" id="f_vehicle_condition" required>
              <option value="">Select Condition</option>
              <option value="Excellent">Excellent</option>
              <option value="Good">Good</option>
              <option value="Fair">Fair</option>
              <option value="Poor">Poor</option>
            </select>
          </div>
          <div class="form-field">
            <label>Category (Optional)</label>
            <select name="category" id="f_category">
              <option value="">None</option>
              <option value="Economy">Economy</option>
              <option value="Standard">Standard</option>
              <option value="Premium">Premium</option>
              <option value="Luxury">Luxury</option>
            </select>
          </div>
          <div class="form-field">
            <label>Seats</label>
            <select name="seats" id="f_seats" required>
              <option value="">Select Seats</option>
              <option value="2">2</option>
              <option value="4">4</option>
              <option value="5">5</option>
              <option value="7">7</option>
              <option value="10">10</option>
              <option value="12">12</option>
              <option value="15">15</option>
            </select>
          </div>
          <div class="form-field">
            <label>Maximum Capacity (KG)</label>
            <input type="number" name="max_capacity_kg" id="f_max_capacity_kg" min="1" step="1" placeholder="e.g. 500">
            <small style="display:block;margin-top:6px;color:var(--muted);font-weight:600;">Total passenger/load weight capacity (e.g. Vios ≈ 500 KG)</small>
          </div>
        </div>
      </div>
      
      <div class="modal-section">
        <div class="modal-section-title">⚙️ Technical Details</div>
        <div class="modal-grid">
          <div class="form-field">
            <label>Year</label>
            <select name="year" id="f_year" required>
              <option value="">Select Year</option>
              <?php for($y = date('Y'); $y >= 2010; $y--): ?>
                <option value="<?= $y ?>"><?= $y ?></option>
              <?php endfor; ?>
            </select>
          </div>
          <div class="form-field">
            <label>Odometer (km)</label>
            <input name="odometer" id="f_odo" type="number" min="0" step="1" required placeholder="Enter odometer reading">
          </div>
          <div class="form-field">
            <label>Transmission</label>
            <select name="transmission" id="f_trans" required>
              <option value="">Select Transmission</option>
              <option value="MT">Manual (MT)</option>
              <option value="AT">Automatic (AT)</option>
              <option value="CVT">CVT</option>
            </select>
          </div>
          <div class="form-field">
            <label>Comfort Level</label>
            <select name="comfort_level" id="f_comfort" required>
              <option value="">Select Level</option>
              <option value="Economy">Economy</option>
              <option value="Standard">Standard</option>
              <option value="Comfort">Comfort</option>
              <option value="Premium">Premium</option>
              <option value="Luxury">Luxury</option>
            </select>
          </div>
          <div class="form-field">
            <label>Fuel Type</label>
            <select name="fuel_type" id="f_fuel">
              <option value="">Select Fuel</option>
            <option value="Gasoline">Gasoline</option>
            <option value="Diesel">Diesel</option>
            <option value="Hybrid">Hybrid</option>
            <option value="Electric">Electric</option>
            </select>
          </div>
          <div class="form-field">
            <label>Fuel Level</label>
            <select name="fuel_level" id="f_fuel_level">
              <option value="">Select Fuel Level</option>
              <option value="full">Full</option>
              <option value="3/4">3/4 Tank</option>
              <option value="half">Half Tank</option>
              <option value="1/4">1/4 Tank</option>
              <option value="empty">Empty</option>
            </select>
            <small style="display:block;margin-top:6px;color:var(--muted);font-weight:600;">Auto-updates from the latest vehicle return</small>
          </div>
        </div>
      </div>
      
      <div class="modal-section">
        <div class="modal-section-title">💰 Pricing</div>
        <div class="modal-grid">
          <div class="form-field">
            <label>Daily Rate - CDO (₱)</label>
            <input name="daily_rate_cdo" id="f_rate_cdo" type="number" min="0" step="50" required placeholder="Enter CDO rate">
          </div>
          <div class="form-field">
            <label>Daily Rate - Outside CDO (₱)</label>
            <input name="daily_rate_outside_cdo" id="f_rate_outside" type="number" min="0" step="50" required placeholder="Enter outside CDO rate">
          </div>
          <div class="form-field">
            <label>Promo Discount Type</label>
            <select name="promo_discount_type" id="f_promo_type">
              <option value="none">None</option>
              <option value="percent">Percentage (%)</option>
              <option value="fixed">Fixed amount (₱ / day)</option>
            </select>
          </div>
          <div class="form-field">
            <label id="f_promo_value_label">Promo Discount Value</label>
            <input name="promo_discount_value" id="f_promo_value" type="number" min="0" step="0.01" value="0" placeholder="0">
            <div class="field-hint" id="f_promo_hint" style="margin-top:6px;font-size:.8rem;font-weight:600;color:var(--muted);">Leave as None / 0 for no vehicle promo.</div>
          </div>
          <div class="form-field promo-schedule-field">
            <label>Promo Start Date</label>
            <input type="date" name="promo_start_date" id="f_promo_start_date">
          </div>
          <div class="form-field promo-schedule-field">
            <label>Promo Start Time</label>
            <input type="time" name="promo_start_time" id="f_promo_start_time" value="00:00">
          </div>
          <div class="form-field promo-schedule-field">
            <label>Promo End Date</label>
            <input type="date" name="promo_end_date" id="f_promo_end_date">
          </div>
          <div class="form-field promo-schedule-field">
            <label>Promo End Time</label>
            <input type="time" name="promo_end_time" id="f_promo_end_time" value="23:59">
            <div class="field-hint" style="margin-top:6px;font-size:.8rem;font-weight:600;color:var(--muted);">Promo only applies between start and end (inclusive).</div>
          </div>
        </div>
      </div>

      <div class="modal-section">
        <div class="modal-section-title">Listing description</div>
        <div class="form-field">
          <label for="f_listing">Shown to customers on Browse Cars</label>
          <textarea name="listing_description" id="f_listing" maxlength="2000" placeholder="A short description of this vehicle"></textarea>
          <button class="btn btn-dark" type="button" id="btnGeminiDesc" style="margin-top:10px;">Write with Gemini</button>
        </div>
      </div>
      
      <div class="modal-section">
        <div class="modal-section-title">Vehicle Photo</div>
        <div class="photo-manager">
          <!-- Current Photo Display -->
          <div class="current-photo-container" id="currentPhotoContainer">
            <div class="photo-header">
              <span class="photo-title">Current Photo</span>
              <button type="button" class="btn-remove-photo" onclick="removeCurrentPhoto()" id="removePhotoBtn">
                Remove
              </button>
            </div>
            <div class="photo-display" id="currentPhotoDisplay">
              <div class="photo-placeholder" id="currentPhotoPlaceholder">
                <div class="placeholder-icon">Vehicle</div>
                <div class="placeholder-text">No photo uploaded</div>
                <div class="placeholder-subtext">Click below to add a photo</div>
              </div>
              <img id="currentImg" style="display:none;" alt="Current vehicle photo">
            </div>
          </div>
          
          <!-- Upload Section -->
          <div class="upload-section">
            <div class="upload-header">
              <span class="upload-title">Upload New Photo</span>
              <span class="upload-subtitle">or drag and drop</span>
            </div>
            <div class="upload-area" id="uploadArea" onclick="document.getElementById('photoInput').click()">
              <input type="file" name="photo" id="photoInput" accept="image/*" onchange="previewPhoto(this)" style="display:none;">
              <div class="upload-content">
                <div class="upload-text">Click to upload or drag & drop</div>
                <div class="upload-subtext">JPG, PNG, GIF (Max 5MB)</div>
              </div>
            </div>
            
            <!-- Preview Section -->
            <div class="preview-container" id="previewContainer" style="display:none;">
              <div class="preview-header">
                <span class="preview-title">New Photo Preview</span>
                <button type="button" class="btn-cancel-preview" onclick="cancelPreview()">
                  Cancel
                </button>
              </div>
              <div class="preview-display">
                <img id="previewImg" alt="Photo preview">
                <div class="preview-overlay">
                  <div class="preview-actions">
                    <button type="button" class="btn-preview-zoom" onclick="zoomPreview()">
                      Zoom
                    </button>
                    <button type="button" class="btn-preview-remove" onclick="cancelPreview()">
                      Remove
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>
          
                  </div>
      </div>

      <div class="modal-actions">
        <button class="btn btn-dark" type="button" id="btnCancel">Cancel</button>
        <button class="btn btn-primary" type="submit" id="saveBtn">
          <span class="loading-spinner" id="saveSpinner"></span>
          <span id="saveText">Save Vehicle</span>
        </button>
      </div>
    </form>
  </div>
</div>

<!-- QUICK VIEW MODAL -->
<div class="modal-bg" id="quickViewModal">
  <div class="modal" style="width:min(900px,94vw);">
    <div class="modal-header">
      <h2 id="quickViewTitle">Vehicle Details</h2>
      <button class="modal-close" type="button" id="quickViewClose">&times;</button>
    </div>
    <div id="quickViewContent" style="padding:0 32px 24px;">
      <!-- Content will be populated by JavaScript -->
    </div>
    <div class="modal-actions" style="padding:0 32px 32px;border-top:1px solid rgba(255,255,255,.08);margin-top:0;">
      <button class="btn btn-primary" id="editFromQuickView">Edit Vehicle</button>
      <button class="btn btn-dark" id="closeQuickView">Close</button>
    </div>
  </div>
</div>

<!-- ZOOM MODAL -->
<div class="zoom-modal" id="zoomModal" onclick="closeZoom(event)">
  <img id="zoomImg" src="" alt="Zoomed photo">
  <button class="zoom-modal-close" onclick="closeZoom(event)">&times;</button>
</div>

<script>
const modalBg=document.getElementById('modalBg');
const modalTitle=document.getElementById('modalTitle');
const modalClose=document.getElementById('modalClose');
const btnAdd=document.getElementById('btnAdd');
const btnCancel=document.getElementById('btnCancel');
const f_id=document.getElementById('f_id');
const saveBtn=document.getElementById('saveBtn');
const saveSpinner=document.getElementById('saveSpinner');
const saveText=document.getElementById('saveText');

// Filter toggle
const filtersToggle=document.getElementById('filtersToggle');
const filtersContent=document.getElementById('filtersContent');

function openModal(){
  if (modalBg && modalBg.parentElement !== document.body) {
    document.body.appendChild(modalBg);
  }
  modalBg.classList.add('open');
  document.body.style.overflow='hidden';
}
function closeModal(){modalBg.classList.remove('open');document.body.style.overflow='auto';}

// Filter toggle functionality
if (filtersToggle && filtersContent) {
  filtersToggle.addEventListener('click', function() {
    const isOpen = filtersContent.classList.contains('open');
    if (isOpen) {
      filtersContent.classList.remove('open');
      filtersToggle.classList.remove('active');
    } else {
      filtersContent.classList.add('open');
      filtersToggle.classList.add('active');
    }
  });
}

// Enhanced Photo Management Functions
function previewPhoto(input) {
  const previewContainer = document.getElementById('previewContainer');
  const previewImg = document.getElementById('previewImg');
  const uploadArea = document.getElementById('uploadArea');
  
  if (input.files && input.files[0]) {
    const file = input.files[0];
    
    // Validate file type
    if (!file.type.match('image.*')) {
      alert('Please select an image file');
      return;
    }
    
    // Validate file size (5MB max)
    if (file.size > 5 * 1024 * 1024) {
      alert('File size must be less than 5MB');
      return;
    }
    
    const reader = new FileReader();
    reader.onload = function(e) {
      previewImg.src = e.target.result;
      previewContainer.style.display = 'block';
      uploadArea.style.display = 'none';
      
      // Add animation effect
      previewContainer.style.animation = 'none';
      setTimeout(() => {
        previewContainer.style.animation = 'slideIn .3s ease';
      }, 10);
    };
    reader.readAsDataURL(file);
  }
}

function cancelPreview() {
  const previewContainer = document.getElementById('previewContainer');
  const uploadArea = document.getElementById('uploadArea');
  const photoInput = document.getElementById('photoInput');
  
  previewContainer.style.display = 'none';
  uploadArea.style.display = 'block';
  photoInput.value = '';
  
  // Reset preview image
  const previewImg = document.getElementById('previewImg');
  previewImg.src = '';
}

function zoomPreview() {
  const previewImg = document.getElementById('previewImg');
  const zoomModal = document.getElementById('zoomModal');
  const zoomImg = document.getElementById('zoomImg');
  
  if (previewImg.src) {
    zoomImg.src = previewImg.src;
    zoomModal.classList.add('open');
    document.body.style.overflow = 'hidden';
  }
}

function closeZoom(event) {
  if (event.target.id === 'zoomModal' || event.target.id === 'zoomModalClose') {
    const zoomModal = document.getElementById('zoomModal');
    zoomModal.classList.remove('open');
    document.body.style.overflow = 'auto';
  }
}

function removeCurrentPhoto() {
  const currentImg = document.getElementById('currentImg');
  const currentPhotoPlaceholder = document.getElementById('currentPhotoPlaceholder');
  const removeBtn = document.getElementById('removePhotoBtn');
  const removePhotoFlag = document.getElementById('removePhotoFlag');
  
  // Hide current image and show placeholder
  currentImg.style.display = 'none';
  currentPhotoPlaceholder.style.display = 'flex';
  removeBtn.style.display = 'none';
  
  // Set flag to remove photo
  if (removePhotoFlag) {
    removePhotoFlag.value = '1';
  }
  
  // Clear the photo input if it exists
  const photoInput = document.getElementById('photoInput');
  if (photoInput) {
    photoInput.value = '';
  }
  
  // Show confirmation
  showToast('Photo will be removed when you save', 'info');
}

function loadCurrentPhoto(photoUrl) {
  const currentImg = document.getElementById('currentImg');
  const currentPhotoPlaceholder = document.getElementById('currentPhotoPlaceholder');
  const removeBtn = document.getElementById('removePhotoBtn');
  const removePhotoFlag = document.getElementById('removePhotoFlag');
  
  // Reset removal flag
  if (removePhotoFlag) {
    removePhotoFlag.value = '0';
  }
  
  if (photoUrl) {
    currentImg.src = photoUrl;
    currentImg.style.display = 'block';
    currentPhotoPlaceholder.style.display = 'none';
    removeBtn.style.display = 'flex';
  } else {
    currentImg.style.display = 'none';
    currentPhotoPlaceholder.style.display = 'flex';
    removeBtn.style.display = 'none';
  }
}

// Drag and drop functionality
function setupDragAndDrop() {
  const uploadArea = document.getElementById('uploadArea');
  
  if (!uploadArea) return;
  
  ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
    uploadArea.addEventListener(eventName, preventDefaults, false);
  });
  
  ['dragenter', 'dragover'].forEach(eventName => {
    uploadArea.addEventListener(eventName, () => {
      uploadArea.classList.add('drag-over');
    }, false);
  });
  
  ['dragleave', 'drop'].forEach(eventName => {
    uploadArea.addEventListener(eventName, () => {
      uploadArea.classList.remove('drag-over');
    }, false);
  });
  
  uploadArea.addEventListener('drop', handleDrop, false);
}

function preventDefaults(e) {
  e.preventDefault();
  e.stopPropagation();
}

function handleDrop(e) {
  const dt = e.dataTransfer;
  const files = dt.files;
  
  if (files.length > 0) {
    const photoInput = document.getElementById('photoInput');
    photoInput.files = files;
    previewPhoto(photoInput);
  }
}

// Initialize drag and drop when DOM is ready
document.addEventListener('DOMContentLoaded', setupDragAndDrop);

// Form validation
function validateForm() {
  console.log('=== VALIDATE FORM CALLED ===');
  
  const plate = document.getElementById('f_plate').value.trim();
  const plateError = document.getElementById('plate_error');
  const maker = document.getElementById('f_maker').value.trim();
  const model = document.getElementById('f_model').value.trim();
  const ownership = document.getElementById('f_classification').value.trim();
  const type = document.getElementById('f_type').value.trim();
  const condEl = document.getElementById('f_vehicle_condition');
  const condition = condEl ? condEl.value.trim() : '';
  
  console.log('Plate value:', plate);
  console.log('Plate error element:', plateError);
  
  if (!plate) {
    console.log('Validation failed: Plate is empty');
    plateError.classList.add('show');
    return false;
  } else {
    console.log('Validation passed: Plate has value');
    plateError.classList.remove('show');
  }
  if (!maker || !model || !ownership || !type || !condition) {
    alert('Please fill in all required fields.');
    return false;
  }

  const promoType = document.getElementById('f_promo_type')?.value || 'none';
  if (promoType === 'percent' || promoType === 'fixed') {
    const promoVal = parseFloat(document.getElementById('f_promo_value')?.value || '0');
    const sd = document.getElementById('f_promo_start_date')?.value || '';
    const st = document.getElementById('f_promo_start_time')?.value || '00:00';
    const ed = document.getElementById('f_promo_end_date')?.value || '';
    const et = document.getElementById('f_promo_end_time')?.value || '23:59';
    if (!(promoVal > 0)) {
      alert('Enter a promo discount value greater than 0, or set Promo Type to None.');
      return false;
    }
    if (!sd || !ed) {
      alert('Please set the promo start and end date/time.');
      return false;
    }
    const start = new Date(`${sd}T${st || '00:00'}`);
    const end = new Date(`${ed}T${et || '23:59'}`);
    if (isNaN(start.getTime()) || isNaN(end.getTime()) || end <= start) {
      alert('Promo end date/time must be after the start date/time.');
      return false;
    }
  }
  return true;
}

// Show loading state
function showLoading() {
  saveSpinner.style.display = 'inline-block';
  saveText.textContent = 'Saving...';
  saveBtn.disabled = true;
}

// Hide loading state
function hideLoading() {
  saveSpinner.style.display = 'none';
  saveText.textContent = 'Save';
  saveBtn.disabled = false;
}

// Show toast notification
function showToast(message, type = 'success') {
  const toast = document.createElement('div');
  toast.className = `toast ${type}`;
  toast.textContent = message;
  document.body.appendChild(toast);
  
  setTimeout(() => {
    toast.style.animation = 'slideOut 0.3s ease forwards';
    setTimeout(() => toast.remove(), 300);
  }, 3000);
}

// Test vehicle submission
function testVehicleSubmission() {
  console.log('=== TEST VEHICLE SUBMISSION ===');
  
  const form = document.getElementById('vehForm');
  const formData = new FormData(form);
  
  console.log('Form data:');
  for (let [key, value] of formData.entries()) {
    console.log(key + ': ' + value);
  }
  
  // Add test flag
  formData.append('test_vehicle', '1');
  
  // Submit to test endpoint
  fetch('vehicles_all.php', {
    method: 'POST',
    body: formData
  })
  .then(response => response.text())
  .then(data => {
    console.log('Test response:', data);
    alert('Test submitted! Check console for response.');
  })
  .catch(error => {
    console.log('Test error:', error);
    alert('Test failed: ' + error.message);
  });
}

// Filter functionality (search removed)
function filterVehicles() {
  const statusFilter = document.getElementById('statusFilter').value;
  const ownershipFilter = document.getElementById('ownershipFilter') ? document.getElementById('ownershipFilter').value : '';
  const typeFilter = document.getElementById('typeFilter').value;
  const rows = document.querySelectorAll('.vehicle-row');
  
  rows.forEach(row => {
    const status = String(row.dataset.status || '').toLowerCase();
    const type = String(row.dataset.type || '');
    const ownership = String(row.dataset.ownership || '');
    
    const matchesStatus = !statusFilter || status === String(statusFilter).toLowerCase();
    const matchesType = !typeFilter || type === typeFilter;
    const matchesOwnership = !ownershipFilter || ownership === ownershipFilter;
    
    row.style.display = (matchesStatus && matchesType && matchesOwnership) ? '' : 'none';
  });
}

// Toggle card details expansion
function toggleDetails(vehicleId, event) {
  event.stopPropagation();
  
  const toggle = document.getElementById(`details-${vehicleId}`);
  const content = document.getElementById(`content-${vehicleId}`);
  const arrow = toggle.querySelector('.expand-arrow');
  const text = toggle.querySelector('.expand-text');
  
  if (content.classList.contains('open')) {
    content.classList.remove('open');
    toggle.classList.remove('active');
    arrow.style.transform = 'rotate(0deg)';
    text.textContent = 'View Details';
  } else {
    content.classList.add('open');
    toggle.classList.add('active');
    arrow.style.transform = 'rotate(180deg)';
    text.textContent = 'Hide Details';
  }
}

// Edit vehicle function
function editVehicle(vehicleId, event) {
  event.stopPropagation();
  
  // Open modal and load vehicle data
  openModal();
  loadVehicleData(vehicleId);
}

// View full details function
function viewDetails(vehicleId, event) {
  event.stopPropagation();
  // Trigger the existing quick view functionality
  quickView(vehicleId);
}

// Load vehicle data for editing (helper function)
function loadVehicleData(vehicleId) {
  // Set modal title
  modalTitle.textContent = 'Edit Vehicle';
  modalTitle.style.color = 'var(--brand)';
  f_id.value = vehicleId;
  
  // Fetch vehicle data
  fetch('?get=' + vehicleId)
    .then(r => r.json())
    .then(v => {
      if(!v.id) return alert('Vehicle not found');
      
      // Populate form fields
      document.getElementById('f_plate').value = v.plate_no || '';
      document.getElementById('f_chassis').value = v.chassis_number || '';
      document.getElementById('f_engine').value = v.engine_number || '';
      document.getElementById('f_maker').value = v.maker || '';
      document.getElementById('f_model').value = v.model || '';
      document.getElementById('f_classification').value = v.ownership_type || v.classification || '';
      document.getElementById('f_type').value = v.vehicle_type || '';
      const cond = (v.vehicle_condition || v.condition_status || '');
      if (document.getElementById('f_vehicle_condition')) {
        if (['EXCELLENT','GOOD','FAIR','POOR'].includes(String(cond).toUpperCase())) {
          document.getElementById('f_vehicle_condition').value = String(cond).charAt(0).toUpperCase() + String(cond).slice(1).toLowerCase();
        } else {
          document.getElementById('f_vehicle_condition').value = cond || '';
        }
      }
      if (document.getElementById('f_category')) {
        document.getElementById('f_category').value = v.category || '';
      }
      document.getElementById('f_seats').value = v.seats || '';
      if (document.getElementById('f_max_capacity_kg')) {
        document.getElementById('f_max_capacity_kg').value = (v.max_capacity_kg !== null && v.max_capacity_kg !== undefined && v.max_capacity_kg !== '') ? v.max_capacity_kg : '';
      }
      document.getElementById('f_year').value = v.year || '';
      document.getElementById('f_odo').value = v.odometer || '';
      document.getElementById('f_trans').value = v.transmission || '';
      document.getElementById('f_comfort').value = v.comfort_level || '';
      document.getElementById('f_fuel').value = v.fuel_type || '';
      if (document.getElementById('f_fuel_level')) {
        document.getElementById('f_fuel_level').value = v.fuel_level || '';
      }
      document.getElementById('f_rate_cdo').value = v.daily_rate_cdo || '';
      document.getElementById('f_rate_outside').value = v.daily_rate_outside_cdo || '';
      if (document.getElementById('f_listing')) {
        document.getElementById('f_listing').value = v.listing_description || '';
      }
      setPromoDiscountFields(v.promo_discount_type, v.promo_discount_value, v.promo_starts_at, v.promo_ends_at);
      
      // Show current photo if exists
      if (v.photo) {
        loadCurrentPhoto('assets/vehicles/' + v.photo);
      } else {
        loadCurrentPhoto(null);
      }
      
      // Reset preview section
      const previewContainer = document.getElementById('previewContainer');
      const uploadArea = document.getElementById('uploadArea');
      if (previewContainer) previewContainer.style.display = 'none';
      if (uploadArea) uploadArea.style.display = 'block';
    })
    .catch(error => {
      console.error('Error loading vehicle data:', error);
      alert('Error loading vehicle data');
    });
}

// Event listeners
btnAdd.onclick=()=>{
  modalTitle.textContent='Add Vehicle';
  modalTitle.style.color = 'var(--brand)';
  document.getElementById('vehForm').reset();
  f_id.value='';
  setPromoDiscountFields('none', 0, '', '');
  
  // Reset photo section
  const previewContainer = document.getElementById('previewContainer');
  const uploadArea = document.getElementById('uploadArea');
  const currentPhotoPlaceholder = document.getElementById('currentPhotoPlaceholder');
  const currentImg = document.getElementById('currentImg');
  const removeBtn = document.getElementById('removePhotoBtn');
  
  if (previewContainer) previewContainer.style.display = 'none';
  if (uploadArea) uploadArea.style.display = 'block';
  if (currentPhotoPlaceholder) currentPhotoPlaceholder.style.display = 'flex';
  if (currentImg) currentImg.style.display = 'none';
  if (removeBtn) removeBtn.style.display = 'none';
  
  openModal();
}

const btnGeminiDesc = document.getElementById('btnGeminiDesc');
if (btnGeminiDesc) {
  btnGeminiDesc.addEventListener('click', async function () {
    const field = document.getElementById('f_listing');
    const original = btnGeminiDesc.textContent;
    btnGeminiDesc.disabled = true;
    btnGeminiDesc.textContent = 'Writing…';
    try {
      const res = await fetch('includes/ajax_gemini.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({
          action: 'vehicle_description',
          vehicle: {
            maker: document.getElementById('f_maker').value,
            model: document.getElementById('f_model').value,
            type: document.getElementById('f_type').value,
            year: document.getElementById('f_year').value,
            seats: document.getElementById('f_seats').value,
            transmission: document.getElementById('f_trans').value,
            comfort: document.getElementById('f_comfort').value,
            fuel: document.getElementById('f_fuel').value,
            category: document.getElementById('f_category') ? document.getElementById('f_category').value : '',
            condition: document.getElementById('f_vehicle_condition').value,
            daily_rate_cdo: document.getElementById('f_rate_cdo').value,
            daily_rate_outside_cdo: document.getElementById('f_rate_outside').value
          }
        })
      });
      const data = await res.json();
      if (data && data.ok) {
        field.value = data.text;
      } else {
        alert((data && data.error) || 'Could not write a description.');
      }
    } catch (err) {
      alert('Could not reach Gemini.');
    } finally {
      btnGeminiDesc.disabled = false;
      btnGeminiDesc.textContent = original;
    }
  });
}

btnCancel.onclick=closeModal;
modalClose.onclick=closeModal;
modalBg.onclick=e=>{if(e.target===modalBg)closeModal();}

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape' && modalBg.classList.contains('open')) {
    closeModal();
  }
  if (e.key === 'Enter' && modalBg.classList.contains('open') && e.ctrlKey) {
    document.getElementById('vehForm').submit();
  }
});

// Auto-calculate outside rate when CDO rate changes
document.getElementById('f_rate_cdo').addEventListener('input', function() {
  const cdoRate = parseFloat(this.value) || 0;
  if (cdoRate > 0) {
    const outsideRate = Math.round(cdoRate * 1.2); // 20% markup for outside CDO
    document.getElementById('f_rate_outside').value = outsideRate;
  }
});

function splitDateTime(dt){
  if (!dt) return { date: '', time: '' };
  const s = String(dt).replace('T', ' ').trim();
  const parts = s.split(' ');
  const date = (parts[0] || '').slice(0, 10);
  let time = (parts[1] || '').slice(0, 5);
  if (time && time.length === 4) time = '0' + time;
  return { date, time };
}

function syncPromoDiscountUI(){
  const typeEl = document.getElementById('f_promo_type');
  const valueEl = document.getElementById('f_promo_value');
  const labelEl = document.getElementById('f_promo_value_label');
  const hintEl = document.getElementById('f_promo_hint');
  const scheduleFields = document.querySelectorAll('.promo-schedule-field input');
  if (!typeEl || !valueEl) return;
  const t = typeEl.value || 'none';
  const enabled = t === 'percent' || t === 'fixed';

  scheduleFields.forEach(el => {
    el.disabled = !enabled;
    el.required = enabled;
  });

  if (t === 'percent') {
    valueEl.disabled = false;
    valueEl.max = '100';
    valueEl.step = '0.01';
    valueEl.placeholder = 'e.g. 10';
    if (labelEl) labelEl.textContent = 'Promo Discount (%)';
    if (hintEl) hintEl.textContent = 'Percent off the daily rate (max 100%).';
  } else if (t === 'fixed') {
    valueEl.disabled = false;
    valueEl.removeAttribute('max');
    valueEl.step = '1';
    valueEl.placeholder = 'e.g. 200';
    if (labelEl) labelEl.textContent = 'Promo Discount (₱ / day)';
    if (hintEl) hintEl.textContent = 'Fixed peso amount deducted from the daily rate.';
  } else {
    valueEl.disabled = true;
    valueEl.value = '0';
    valueEl.removeAttribute('max');
    valueEl.placeholder = '0';
    if (labelEl) labelEl.textContent = 'Promo Discount Value';
    if (hintEl) hintEl.textContent = 'Leave as None / 0 for no vehicle promo.';
    const sd = document.getElementById('f_promo_start_date');
    const st = document.getElementById('f_promo_start_time');
    const ed = document.getElementById('f_promo_end_date');
    const et = document.getElementById('f_promo_end_time');
    if (sd) sd.value = '';
    if (st) st.value = '00:00';
    if (ed) ed.value = '';
    if (et) et.value = '23:59';
  }
}

function setPromoDiscountFields(type, value, startsAt, endsAt){
  const typeEl = document.getElementById('f_promo_type');
  const valueEl = document.getElementById('f_promo_value');
  if (!typeEl || !valueEl) return;
  let t = String(type || 'none').toLowerCase();
  if (!['none','percent','fixed'].includes(t)) t = 'none';
  const v = parseFloat(value);
  typeEl.value = t;
  valueEl.value = (!isFinite(v) || v < 0) ? '0' : String(v);

  const start = splitDateTime(startsAt);
  const end = splitDateTime(endsAt);
  const sd = document.getElementById('f_promo_start_date');
  const st = document.getElementById('f_promo_start_time');
  const ed = document.getElementById('f_promo_end_date');
  const et = document.getElementById('f_promo_end_time');
  if (sd) sd.value = start.date || '';
  if (st) st.value = start.time || '00:00';
  if (ed) ed.value = end.date || '';
  if (et) et.value = end.time || '23:59';

  syncPromoDiscountUI();
}

const promoTypeEl = document.getElementById('f_promo_type');
if (promoTypeEl) {
  promoTypeEl.addEventListener('change', syncPromoDiscountUI);
  syncPromoDiscountUI();
}

// Filter event listeners (search removed)
document.getElementById('statusFilter').addEventListener('change', filterVehicles);
if (document.getElementById('ownershipFilter')) {
  document.getElementById('ownershipFilter').addEventListener('change', filterVehicles);
}
document.getElementById('typeFilter').addEventListener('change', filterVehicles);

// Form submission with loading state
document.getElementById('vehForm').addEventListener('submit', function(e) {
  console.log('=== FORM SUBMISSION EVENT ===');
  console.log('Form element:', this);
  
  if (!validateForm()) {
    console.log('Form validation failed, preventing submission');
    e.preventDefault();
    return;
  }
  
  console.log('Form validation passed, showing loading state');
  showLoading();
});

// Auto-hide toasts
document.querySelectorAll('.toast').forEach(toast => {
  setTimeout(() => {
    toast.style.animation = 'slideOut 0.3s ease forwards';
    setTimeout(() => toast.remove(), 300);
  }, 5000);
});

// Edit vehicle functionality
document.querySelectorAll('.btn-edit').forEach(btn=>{
  btn.onclick=()=>{
    fetch('?get='+btn.dataset.id).then(r=>r.json()).then(v=>{
      if(!v.id)return alert('Vehicle not found');
      
      // Debug: Log the vehicle data to console
      console.log('Vehicle data from database:', v);
      
      modalTitle.textContent='Edit Vehicle';
      modalTitle.style.color = 'var(--brand)';
      f_id.value=v.id;
      document.getElementById('f_plate').value=v.plate_no||'';
      
      // Debug chassis and engine numbers
      console.log('Chassis number from DB:', v.chassis_number);
      console.log('Chassis no from DB:', v.chassis_no);
      console.log('Engine number from DB:', v.engine_number);
      console.log('Engine no from DB:', v.engine_no);
      
      document.getElementById('f_chassis').value=v.chassis_number||v.chassis_no||'';
      document.getElementById('f_engine').value=v.engine_number||v.engine_no||'';
      
      // Handle dropdown selections properly
      const makerSelect = document.getElementById('f_maker');
      console.log('Maker value from DB:', v.maker);
      if (v.maker && v.maker.trim() !== '') {
        for (let option of makerSelect.options) {
          if (option.value === v.maker) {
            option.selected = true;
            console.log('Selected maker option:', option.value);
            break;
          }
        }
      }
      
      document.getElementById('f_model').value=v.model||'';
      
      const classificationSelect = document.getElementById('f_classification');
      const ownershipVal = v.ownership_type || v.classification || '';
      if (ownershipVal) {
        for (let option of classificationSelect.options) {
          if (option.value === ownershipVal) {
            option.selected = true;
            break;
          }
        }
      }
      
      const typeSelect = document.getElementById('f_type');
      if (v.vehicle_type) {
        for (let option of typeSelect.options) {
          if (option.value === v.vehicle_type) {
            option.selected = true;
            break;
          }
        }
      }
      
      const seatsSelect = document.getElementById('f_seats');
      if (v.seats) {
        for (let option of seatsSelect.options) {
          if (option.value === v.seats.toString()) {
            option.selected = true;
            break;
          }
        }
      }
      if (document.getElementById('f_max_capacity_kg')) {
        document.getElementById('f_max_capacity_kg').value = (v.max_capacity_kg !== null && v.max_capacity_kg !== undefined && v.max_capacity_kg !== '') ? v.max_capacity_kg : '';
      }
      
      const yearSelect = document.getElementById('f_year');
      if (v.year) {
        for (let option of yearSelect.options) {
          if (option.value === v.year.toString()) {
            option.selected = true;
            break;
          }
        }
      }
      
      document.getElementById('f_odo').value=v.odometer||0;
      document.getElementById('f_rate_cdo').value=v.daily_rate_cdo||v.daily_rate||0;
      document.getElementById('f_rate_outside').value=v.daily_rate_outside_cdo||0;
      if (document.getElementById('f_listing')) {
        document.getElementById('f_listing').value = v.listing_description || '';
      }
      setPromoDiscountFields(v.promo_discount_type, v.promo_discount_value, v.promo_starts_at, v.promo_ends_at);
      
      const transSelect = document.getElementById('f_trans');
      console.log('Transmission value from DB:', v.transmission);
      if (v.transmission && v.transmission.trim() !== '') {
        for (let option of transSelect.options) {
          if (option.value === v.transmission) {
            option.selected = true;
            console.log('Selected transmission option:', option.value);
            break;
          }
        }
      }
      
      const comfortSelect = document.getElementById('f_comfort');
      if (v.comfort_level) {
        for (let option of comfortSelect.options) {
          if (option.value === v.comfort_level) {
            option.selected = true;
            break;
          }
        }
      }
      
      const fuelSelect = document.getElementById('f_fuel');
      if (v.fuel_type) {
        for (let option of fuelSelect.options) {
          if (option.value === v.fuel_type) {
            option.selected = true;
            break;
          }
        }
      }
      
      // Show current photo if exists
      if (v.photo) {
        loadCurrentPhoto('assets/vehicles/' + v.photo);
      } else {
        loadCurrentPhoto(null);
      }
      
      // Reset preview section
      const previewContainer = document.getElementById('previewContainer');
      const uploadArea = document.getElementById('uploadArea');
      if (previewContainer) previewContainer.style.display = 'none';
      if (uploadArea) uploadArea.style.display = 'block';
      
      openModal();
    });
  };
});

// Quick view functionality
function quickView(vehicleId) {
  fetch('?get=' + vehicleId)
    .then(r => r.json())
    .then(v => {
      if (!v.id) return alert('Vehicle not found');

      const photoUrl = v.photo ? ('assets/vehicles/' + v.photo) : null;
      const fallback = 'assets/vehicles/images.jpeg';
      const imgUrl = photoUrl || fallback;
      
      const content = `
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;">
          <div>
            <img src="${imgUrl}" 
                 style="width:100%;height:320px;object-fit:cover;border-radius:12px;border:1px solid rgba(255,255,255,.1);">
          </div>
          <div>
            <h3 style="margin:0 0 16px;color:var(--brand);">${v.make_model || ((v.maker || 'Unknown') + ' ' + (v.model || ''))}</h3>
            <div style="display:grid;gap:12px;">
              <div style="display:flex;justify-content:space-between;">
                <span style="color:var(--muted);">Plate Number:</span>
                <span style="color:var(--text);font-weight:600;">${v.plate_no}</span>
              </div>
              <div style="display:flex;justify-content:space-between;">
                <span style="color:var(--muted);">Vehicle Type:</span>
                <span style="color:var(--text);font-weight:600;">${v.vehicle_type}</span>
              </div>
              <div style="display:flex;justify-content:space-between;">
                <span style="color:var(--muted);">Ownership Type:</span>
                <span style="color:var(--text);font-weight:600;">${v.ownership_type || v.classification || 'Personal'}</span>
              </div>
              ${(v.category ? `
              <div style="display:flex;justify-content:space-between;">
                <span style="color:var(--muted);">Category:</span>
                <span style="color:var(--text);font-weight:600;">${v.category}</span>
              </div>` : '')}
              <div style="display:flex;justify-content:space-between;">
                <span style="color:var(--muted);">Vehicle Condition:</span>
                <span style="color:var(--text);font-weight:600;">${v.vehicle_condition || v.condition_status || ''}</span>
              </div>
              <div style="display:flex;justify-content:space-between;">
                <span style="color:var(--muted);">Year:</span>
                <span style="color:var(--text);font-weight:600;">${v.year}</span>
              </div>
              <div style="display:flex;justify-content:space-between;">
                <span style="color:var(--muted);">Seats:</span>
                <span style="color:var(--text);font-weight:600;">${v.seats}</span>
              </div>
              <div style="display:flex;justify-content:space-between;">
                <span style="color:var(--muted);">Max Capacity:</span>
                <span style="color:var(--text);font-weight:600;">${v.max_capacity_kg ? (Number(v.max_capacity_kg).toLocaleString() + ' KG') : '—'}</span>
              </div>
              <div style="display:flex;justify-content:space-between;">
                <span style="color:var(--muted);">Fuel Level:</span>
                <span style="color:var(--text);font-weight:600;">${(() => {
                  const fl = String(v.fuel_level || '').toLowerCase();
                  if (fl === 'full') return 'Full';
                  if (fl === '3/4') return '3/4 Tank';
                  if (fl === 'half') return 'Half Tank';
                  if (fl === '1/4') return '1/4 Tank';
                  if (fl === 'empty') return 'Empty';
                  return fl || '—';
                })()}</span>
              </div>
              <div style="display:flex;justify-content:space-between;">
                <span style="color:var(--muted);">Transmission:</span>
                <span style="color:var(--text);font-weight:600;">${v.transmission}</span>
              </div>
              <div style="display:flex;justify-content:space-between;">
                <span style="color:var(--muted);">Comfort Level:</span>
                <span style="color:var(--text);font-weight:600;">${v.comfort_level}</span>
              </div>
              <div style="display:flex;justify-content:space-between;">
                <span style="color:var(--muted);">Odometer:</span>
                <span style="color:var(--text);font-weight:600;">${v.odometer ? v.odometer.toLocaleString() : 'N/A'} km</span>
              </div>
              <div style="display:flex;justify-content:space-between;">
                <span style="color:var(--muted);">Daily Rate (CDO):</span>
                <span style="color:var(--brand2);font-weight:700;">₱${(v.daily_rate_cdo || v.daily_rate || 0).toLocaleString()}</span>
              </div>
              <div style="display:flex;justify-content:space-between;">
                <span style="color:var(--muted);">Daily Rate (Outside):</span>
                <span style="color:var(--brand2);font-weight:700;">₱${(v.daily_rate_outside_cdo || (v.daily_rate_cdo || v.daily_rate || 0) * 1.2).toLocaleString()}</span>
              </div>
              ${(() => {
                const pt = String(v.promo_discount_type || 'none').toLowerCase();
                const pv = Number(v.promo_discount_value || 0);
                if (!pv || pt === 'none') {
                  return `<div style="display:flex;justify-content:space-between;">
                    <span style="color:var(--muted);">Promo Discount:</span>
                    <span style="color:var(--text);font-weight:600;">None</span>
                  </div>`;
                }
                const label = pt === 'percent'
                  ? `${pv}% off`
                  : `₱${pv.toLocaleString(undefined,{minimumFractionDigits:2})} / day`;
                const fmt = (dt) => {
                  if (!dt) return '—';
                  const d = new Date(String(dt).replace(' ', 'T'));
                  if (isNaN(d.getTime())) return String(dt);
                  return d.toLocaleString();
                };
                const start = fmt(v.promo_starts_at);
                const end = fmt(v.promo_ends_at);
                const now = Date.now();
                const s = v.promo_starts_at ? new Date(String(v.promo_starts_at).replace(' ', 'T')).getTime() : NaN;
                const e = v.promo_ends_at ? new Date(String(v.promo_ends_at).replace(' ', 'T')).getTime() : NaN;
                const active = !isNaN(s) && !isNaN(e) && now >= s && now <= e;
                return `<div style="display:flex;justify-content:space-between;gap:12px;">
                  <span style="color:var(--muted);">Promo Discount:</span>
                  <span style="color:var(--brand2);font-weight:700;text-align:right;">${label}${active ? ' · Active' : ' · Scheduled'}</span>
                </div>
                <div style="display:flex;justify-content:space-between;gap:12px;">
                  <span style="color:var(--muted);">Promo Window:</span>
                  <span style="color:var(--text);font-weight:600;text-align:right;font-size:.9rem;">${start} → ${end}</span>
                </div>`;
              })()}
              <div style="display:flex;justify-content:space-between;">
                <span style="color:var(--muted);">Status:</span>
                <span class="badge ${v.current_status === 'available' ? 'green' : (v.current_status === 'rented' ? 'yellow' : 'red')}">${v.current_status}</span>
              </div>
            </div>
          </div>
        </div>
      `;
      
      document.getElementById('quickViewContent').innerHTML = content;
      document.getElementById('editFromQuickView').onclick = () => {
        closeQuickView();
        // Trigger edit for this vehicle
        const editBtn = document.querySelector(`.btn-edit[data-id="${vehicleId}"]`);
        if (editBtn) editBtn.click();
      };
      const qv = document.getElementById('quickViewModal');
      if (qv && qv.parentElement !== document.body) document.body.appendChild(qv);
      qv.classList.add('open');
      document.body.style.overflow = 'hidden';
    });
}

function closeQuickView() {
  document.getElementById('quickViewModal').classList.remove('open');
  document.body.style.overflow = 'auto';
}

// Export functionality
function exportVehicleList() {
  const vehicles = [];
  document.querySelectorAll('.vehicle-row').forEach(row => {
    if (row.style.display !== 'none') {
      const makeModel = (row.querySelector('.make-model') ? row.querySelector('.make-model').textContent : '').trim();
      const plate = (row.querySelector('.col-plate') ? row.querySelector('.col-plate').textContent : '').trim();
      const status = String(row.dataset.status || '').trim();
      const type = String(row.dataset.type || '').trim();
      vehicles.push({ title: makeModel, plate, status, type });
    }
  });
  
  const csvContent = "data:text/csv;charset=utf-8," 
    + "Vehicle,Plate Number,Status,Type\n"
    + vehicles.map(v => `${v.title},${v.plate},${v.status},${v.type}`).join("\n");
  
  const encodedUri = encodeURI(csvContent);
  const link = document.createElement("a");
  link.setAttribute("href", encodedUri);
  link.setAttribute("download", "fleetgo_vehicles.csv");
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  
  showToast('Vehicle list exported successfully!', 'success');
}

// Event listeners for new features
if (document.getElementById('btnExport')) {
  document.getElementById('btnExport').onclick = exportVehicleList;
}
if (document.getElementById('closeQuickView')) {
  document.getElementById('closeQuickView').onclick = closeQuickView;
}
if (document.getElementById('quickViewClose')) {
  document.getElementById('quickViewClose').onclick = closeQuickView;
}
if (document.getElementById('quickViewModal')) {
  document.getElementById('quickViewModal').onclick = e => {
    if (e.target.id === 'quickViewModal') closeQuickView();
  };
}

// Filter event listeners
if (document.getElementById('statusFilter')) {
  document.getElementById('statusFilter').addEventListener('change', filterVehicles);
}
if (document.getElementById('ownershipFilter')) {
  document.getElementById('ownershipFilter').addEventListener('change', filterVehicles);
}
if (document.getElementById('typeFilter')) {
  document.getElementById('typeFilter').addEventListener('change', filterVehicles);
}

// Prevent card click when clicking on action buttons
document.querySelectorAll('.actions').forEach(actions => {
  actions.onclick = e => e.stopPropagation();
});

// Add slideOut animation
const style = document.createElement('style');
style.textContent = `
  @keyframes slideOut {
    from { transform: translateX(0); opacity: 1; }
    to { transform: translateX(100%); opacity: 0; }
  }
  .card { cursor: pointer; }
  .card:hover { cursor: pointer; }
`;
document.head.appendChild(style);
</script>
</body>
</html>
