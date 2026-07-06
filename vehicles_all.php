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
  return'vehicles/sedan.jpg';
}

/* ---------- AJAX: fetch single ---------- */
if(isset($_GET['get'])){
  $id=(int)$_GET['get'];
  $stmt=$conn->prepare("SELECT * FROM vehicles WHERE id=? LIMIT 1");
  $stmt->bind_param('i',$id);$stmt->execute();
  $r=$stmt->get_result()->fetch_assoc();
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
  $year=(int)($_POST['year']??0);
  $odo=(float)($_POST['odometer']??0);
  $rate_cdo=(float)($_POST['daily_rate_cdo']??0);
  $rate_outside=(float)($_POST['daily_rate_outside_cdo']??0);
  $trans=clean_str($_POST['transmission']??'MT');
  $comfort=clean_str($_POST['comfort_level']??'Standard');
  $fuel=clean_str($_POST['fuel_type']??'');
  $vehicle_condition=clean_str($_POST['vehicle_condition']??($_POST['condition_status']??''));
  $category=clean_str($_POST['category']??'');
  
  $allowedVehicleTypes = ['Sedan','SUV','Van','Pickup Truck','Motorcycle','Hatchback','Crossover','Minivan'];
  $allowedOwnershipTypes = ['Personal','Company'];
  $allowedVehicleConditions = ['Excellent','Good','Fair','Poor'];
  $allowedCategories = ['Economy','Standard','Premium','Luxury'];
  
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

  if($id){
    // Always include photo field in UPDATE to handle both removal and new upload
    $sql = "UPDATE vehicles SET plate_no=?,chassis_number=?,engine_number=?,maker=?,model=?,make_model=?,vehicle_type=?,seats=?,year=?,odometer=?,daily_rate=?,daily_rate_cdo=?,daily_rate_outside_cdo=?,transmission=?,comfort_level=?,fuel_type=?";
    $types = "sssssss iiddddsss";
    $types = str_replace(' ','',$types);
    $params = [$plate,$chassis,$engine,$maker,$model,$make_model,$type,$seats,$year,$odo,$daily_rate,$rate_cdo,$rate_outside,$trans,$comfort,$fuel];

    if($has_ownership_type){
      $sql .= ",ownership_type=?";
      $types .= "s";
      $params[] = $ownership_type;
    } elseif($has_classification){
      $sql .= ",classification=?";
      $types .= "s";
      $params[] = $ownership_type;
    }

    if($has_vehicle_condition){
      $sql .= ",vehicle_condition=?";
      $types .= "s";
      $params[] = $vehicle_condition;
    } elseif($has_condition_status){
      $sql .= ",condition_status=?";
      $types .= "s";
      $params[] = $vehicle_condition;
    }
    if($has_category){
      $sql .= ",category=?";
      $types .= "s";
      $params[] = ($category !== '' ? $category : null);
    }
    $sql .= ",photo=? WHERE id=?";
    $types .= "si";
    $params[] = $photo;
    $params[] = $id;
    $stmt=$conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
  }else{
    $status='available';
    $cols = "plate_no,chassis_number,engine_number,maker,model,make_model,vehicle_type,seats,year,odometer,daily_rate,daily_rate_cdo,daily_rate_outside_cdo,transmission,comfort_level,fuel_type";
    $vals = "?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?";
    $types = "sssssssiiddddsss";
    $params = [$plate,$chassis,$engine,$maker,$model,$make_model,$type,$seats,$year,$odo,$daily_rate,$rate_cdo,$rate_outside,$trans,$comfort,$fuel];

    if($has_ownership_type){
      $cols .= ",ownership_type";
      $vals .= ",?";
      $types .= "s";
      $params[] = $ownership_type;
    } elseif($has_classification){
      $cols .= ",classification";
      $vals .= ",?";
      $types .= "s";
      $params[] = $ownership_type;
    }

    if($has_vehicle_condition){
      $cols .= ",vehicle_condition";
      $vals .= ",?";
      $types .= "s";
      $params[] = $vehicle_condition;
    } elseif($has_condition_status){
      $cols .= ",condition_status";
      $vals .= ",?";
      $types .= "s";
      $params[] = $vehicle_condition;
    }
    if($has_category){
      $cols .= ",category";
      $vals .= ",?";
      $types .= "s";
      $params[] = ($category !== '' ? $category : null);
    }
    $cols .= ",current_status,photo";
    $vals .= ",?,?";
    $types .= "ss";
    $params[] = $status;
    $params[] = $photo;
    $stmt=$conn->prepare("INSERT INTO vehicles($cols) VALUES($vals)");
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
.modal-bg{position:fixed;inset:0;background:rgba(0,0,0,.8);backdrop-filter:blur(16px);visibility:hidden;opacity:0;transition:all .4s cubic-bezier(0.4,0,0.2,1);z-index:1000;padding:20px;}
.modal-bg.open{visibility:visible;opacity:1;}
.modal{background:linear-gradient(145deg,#0f141a,#1a1f2e);border:1px solid rgba(93,208,255,.2);border-radius:24px;
 width:min(800px,94vw);max-height:90vh;overflow-y:auto;box-shadow:0 25px 50px rgba(0,0,0,.6),0 0 0 1px rgba(93,208,255,.1);position:relative;transform:scale(.9) translateY(20px);transition:all .4s cubic-bezier(0.4,0,0.2,1);}
.modal-bg.open .modal{transform:scale(1) translateY(0);}
.modal::before{content:'';position:absolute;top:0;left:0;right:0;height:4px;background:linear-gradient(90deg,var(--brand),var(--brand2));border-radius:24px 24px 0 0;}
.modal::after{content:'';position:absolute;inset:0;border-radius:24px;background:linear-gradient(145deg,rgba(93,208,255,.05),transparent);pointer-events:none;}
.modal-header{display:flex;justify-content:space-between;align-items:center;padding:32px 32px 0;margin-bottom:8px;position:relative;z-index:1;}
.modal h2{margin:0;font-weight:800;font-size:1.75rem;color:var(--text);display:flex;align-items:center;gap:12px;letter-spacing:-.02em;}
.modal h2::before{content:'';}
.modal-close{background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);color:var(--muted);font-size:1.25rem;cursor:pointer;padding:8px;border-radius:8px;transition:all .3s ease;width:36px;height:36px;display:flex;align-items:center;justify-content:center;}
.modal-close:hover{background:rgba(255,255,255,.1);color:var(--text);transform:scale(1.05);border-color:var(--brand);}
.modal form{padding:0 32px 32px;position:relative;z-index:1;}
.modal-section{margin-bottom:32px;background:rgba(255,255,255,.02);border-radius:16px;padding:24px;border:1px solid rgba(255,255,255,.05);transition:all .3s ease;}
.modal-section:hover{background:rgba(255,255,255,.03);border-color:rgba(93,208,255,.1);}
.modal-section-title{font-weight:700;color:var(--text);margin-bottom:20px;font-size:1.1rem;display:flex;align-items:center;gap:10px;letter-spacing:-.01em;}
.modal-section-title::before{content:'▸';color:var(--brand);font-size:1.2rem;transition:transform .3s ease;}
.modal-section:hover .modal-section-title::before{transform:translateX(2px);}
.modal-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:20px;}
label{display:block;margin:12px 0 6px;font-weight:600;color:var(--text);font-size:.95rem;letter-spacing:-.01em;}
input,select{width:100%;padding:14px 18px;border-radius:12px;border:1px solid rgba(255,255,255,.15);
 background:rgba(13,17,22,.8);color:var(--text);outline:none;font-size:.95rem;transition:all .3s ease;font-weight:500;}
input:focus,select:focus{border-color:var(--brand);box-shadow:0 0 0 4px rgba(93,208,255,.2),0 0 20px rgba(93,208,255,.1);background:rgba(13,17,22,.95);}
input:hover,select:hover{border-color:rgba(93,208,255,.3);background:rgba(13,17,22,.9);}
.modal-actions{display:flex;gap:16px;justify-content:flex-end;padding-top:32px;border-top:1px solid rgba(255,255,255,.08);margin-top:32px;}
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
.form-field{position:relative;}
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

function openModal(){modalBg.classList.add('open');document.body.style.overflow='hidden';}
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
      document.getElementById('f_year').value = v.year || '';
      document.getElementById('f_odo').value = v.odometer || '';
      document.getElementById('f_trans').value = v.transmission || '';
      document.getElementById('f_comfort').value = v.comfort_level || '';
      document.getElementById('f_fuel').value = v.fuel_type || '';
      document.getElementById('f_rate_cdo').value = v.daily_rate_cdo || '';
      document.getElementById('f_rate_outside').value = v.daily_rate_outside_cdo || '';
      
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
      const fallback = (v.vehicle_type && String(v.vehicle_type).toLowerCase().includes('suv')) ? 'assets/vehicles/suv.jpg' : 'assets/vehicles/sedan.jpg';
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
      document.getElementById('quickViewModal').classList.add('open');
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
