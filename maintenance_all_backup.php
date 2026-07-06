<?php
/* ============================================================
   maintenance_all.php — FleetGo Maintenance Workflow Module
   Full maintenance workflow with issues, jobs, and history
   Based on new SQL schema (fleet_rental_db 2025)
   ============================================================ */

/* ---------- SESSION & SECURITY ---------- */
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

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

define('VEH_IMG_DIR', __DIR__.'/assets/vehicles');
define('VEH_IMG_URL', 'assets/vehicles');

function type_fallback($type){
    $t=strtolower($type ?? '');
    if(strpos($t,'motor')!==false)return 'vehicles/motorcycle.jpg';
    if(strpos($t,'pickup')!==false)return 'vehicles/pickup.jpg';
    if(strpos($t,'suv')!==false)return 'vehicles/suv.jpg';
    if(strpos($t,'van')!==false)return 'vehicles/minivan.jpg';
    return 'vehicles/sedan.jpg';
}

function status_color($status){
    $colors = [
        'reported' => 'blue', 'scheduled' => 'yellow', 'approved' => 'purple',
        'in_progress' => 'orange', 'completed' => 'green', 'cancelled' => 'red',
        'failed_inspection' => 'darkred', 'open' => 'blue', 'converted' => 'purple', 'resolved' => 'green'
    ];
    return $colors[$status] ?? 'gray';
}

function priority_color($priority){
    $colors = ['low' => 'gray', 'medium' => 'yellow', 'high' => 'orange', 'critical' => 'red'];
    return $colors[$priority] ?? 'gray';
}

/* ---------- AJAX Endpoints ---------- */
if(isset($_GET['ajax']) && $_GET['ajax'] === 'get_maintenance' && isset($_GET['id'])){
    header('Content-Type: application/json');
    $id = (int)$_GET['id'];
    $stmt = $conn->prepare("
        SELECT m.*, v.make_model, v.plate_no, v.odometer, v.vehicle_type, v.photo, v.current_status as vehicle_status
        FROM maintenance m
        JOIN vehicles v ON v.id = m.vehicle_id
        WHERE m.id = ?
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    $hist_stmt = $conn->prepare("SELECT * FROM maintenance_status_history WHERE maintenance_id = ? ORDER BY changed_at DESC");
    $hist_stmt->bind_param("i", $id);
    $hist_stmt->execute();
    $history = $hist_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    echo json_encode(['success' => true, 'data' => $result, 'history' => $history]);
    exit;
}

if(isset($_GET['ajax']) && $_GET['ajax'] === 'get_vehicles'){
    header('Content-Type: application/json');
    $search = $_GET['search'] ?? '';
    $where = "1=1";
    if($search) $where .= " AND (make_model LIKE '%$search%' OR plate_no LIKE '%$search%')";
    
    $vehicles = $conn->query("
        SELECT id, make_model, plate_no, vehicle_type, photo, current_status, year, odometer, daily_rate_cdo
        FROM vehicles WHERE $where ORDER BY make_model ASC LIMIT 50
    ");
    $data = [];
    while($v = $vehicles->fetch_assoc()){
        $v['image'] = $v['photo'] ? (VEH_IMG_URL.'/'.$v['photo']) : type_fallback($v['vehicle_type']);
        $data[] = $v;
    }
    echo json_encode(['success' => true, 'vehicles' => $data]);
    exit;
}

if(isset($_GET['ajax']) && $_GET['ajax'] === 'get_issues'){
    header('Content-Type: application/json');
    $vehicle_id = isset($_GET['vehicle_id']) ? (int)$_GET['vehicle_id'] : 0;
    $status = $_GET['status'] ?? '';
    
    $where = "1=1";
    if($vehicle_id) $where .= " AND mi.vehicle_id = $vehicle_id";
    if($status) $where .= " AND mi.status = '$status'";
    
    $sql = "
        SELECT mi.*, v.make_model, v.plate_no, v.photo, v.vehicle_type,
               r.start_date as rental_start, r.end_date as rental_end,
               ri.cleanliness, ri.damage_report
        FROM maintenance_issues mi
        JOIN vehicles v ON v.id = mi.vehicle_id
        LEFT JOIN rentals r ON r.id = mi.rental_id
        LEFT JOIN return_inspections ri ON ri.id = mi.inspection_id
        WHERE $where
        ORDER BY mi.reported_at DESC LIMIT 100
    ";
    $issues = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
    echo json_encode(['success' => true, 'issues' => $issues]);
    exit;
}

if(isset($_GET['ajax']) && $_GET['ajax'] === 'get_vehicle_history' && isset($_GET['vehicle_id'])){
    header('Content-Type: application/json');
    $vehicle_id = (int)$_GET['vehicle_id'];
    
    $jobs = $conn->query("
        SELECT m.*, (SELECT COUNT(*) FROM maintenance_status_history WHERE maintenance_id = m.id) as status_changes
        FROM maintenance m WHERE m.vehicle_id = $vehicle_id ORDER BY m.created_at DESC LIMIT 50
    ")->fetch_all(MYSQLI_ASSOC);
    
    $issues = $conn->query("
        SELECT * FROM maintenance_issues WHERE vehicle_id = $vehicle_id ORDER BY reported_at DESC LIMIT 50
    ")->fetch_all(MYSQLI_ASSOC);
    
    $rules = $conn->query("
        SELECT * FROM maintenance_rules WHERE vehicle_id = $vehicle_id LIMIT 1
    ")->fetch_assoc();
    
    echo json_encode(['success' => true, 'jobs' => $jobs, 'issues' => $issues, 'rules' => $rules]);
    exit;
}

if(isset($_POST['ajax']) && $_POST['ajax'] === 'convert_issue'){
    header('Content-Type: application/json');
    $issue_id = (int)($_POST['issue_id'] ?? 0);
    $category = $_POST['maintenance_category'] ?? 'corrective';
    $priority = $_POST['priority_level'] ?? 'medium';
    $schedule_date = $_POST['schedule_date'] ?? date('Y-m-d');
    $assigned_to = $_POST['assigned_to'] ?? '';
    $service_center = $_POST['service_center'] ?? '';
    $estimated_cost = (float)($_POST['estimated_cost'] ?? 0);
    $description = $_POST['description'] ?? '';
    
    try {
        $issue = $conn->query("SELECT * FROM maintenance_issues WHERE id = $issue_id")->fetch_assoc();
        if(!$issue) throw new Exception("Issue not found");
        
        $stmt = $conn->prepare("
            INSERT INTO maintenance (vehicle_id, reported_date, maintenance_category, priority_level,
                schedule_date, status, description, source_type, source_reference_id,
                assigned_to, service_center, estimated_cost, created_at)
            VALUES (?, NOW(), ?, ?, ?, 'scheduled', ?, 'rental_return', ?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param("issssisd", 
            $issue['vehicle_id'], $category, $priority, $schedule_date,
            $description ?: $issue['issue_description'], $issue_id,
            $assigned_to, $service_center, $estimated_cost
        );
        $stmt->execute();
        $maintenance_id = $conn->insert_id;
        
        $conn->query("UPDATE maintenance_issues SET status = 'converted' WHERE id = $issue_id");
        $conn->query("UPDATE vehicles SET current_status = 'scheduled_maintenance' WHERE id = {$issue['vehicle_id']} AND current_status NOT IN ('rented', 'maintenance')");
        
        echo json_encode(['success' => true, 'maintenance_id' => $maintenance_id]);
    } catch(Exception $e){
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

/* ---------- POST Handlers ---------- */
$toast = '';
$toastType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax'])) {
    $action = $_POST['action'] ?? '';
    
    try {
        switch($action) {
            case 'create_job':
                $vehicle_id = (int)($_POST['vehicle_id'] ?? 0);
                $category = $_POST['maintenance_category'] ?? 'corrective';
                $priority = $_POST['priority_level'] ?? 'medium';
                $schedule_date = $_POST['schedule_date'] ?? date('Y-m-d');
                $assigned_to = $_POST['assigned_to'] ?? '';
                $service_center = $_POST['service_center'] ?? '';
                $estimated_cost = (float)($_POST['estimated_cost'] ?? 0);
                $description = $_POST['description'] ?? '';
                $parts_used = $_POST['parts_used'] ?? '';
                $labor_hours = (float)($_POST['labor_hours'] ?? 0);
                $next_due_date = $_POST['next_due_date'] ?: null;
                $next_due_odometer = $_POST['next_due_odometer'] ? (float)$_POST['next_due_odometer'] : null;
                
                $stmt = $conn->prepare("
                    INSERT INTO maintenance (vehicle_id, reported_date, maintenance_category, priority_level,
                        schedule_date, status, description, assigned_to, service_center,
                        estimated_cost, parts_used, labor_hours, next_due_date, next_due_odometer, source_type, created_at)
                    VALUES (?, NOW(), ?, ?, ?, 'scheduled', ?, ?, ?, ?, ?, ?, ?, ?, 'manual', NOW())
                ");
                $stmt->bind_param("issssssddsds", 
                    $vehicle_id, $category, $priority, $schedule_date, $description,
                    $assigned_to, $service_center, $estimated_cost, $parts_used,
                    $labor_hours, $next_due_date, $next_due_odometer
                );
                $stmt->execute();
                
                $conn->query("UPDATE vehicles SET current_status = 'scheduled_maintenance' WHERE id = $vehicle_id AND current_status NOT IN ('rented', 'maintenance')");
                
                $toast = 'Maintenance job created successfully';
                break;
                
            case 'update_status':
                $id = (int)($_POST['id'] ?? 0);
                $new_status = $_POST['status'] ?? '';
                $remarks = $_POST['remarks'] ?? '';
                $actual_cost = (float)($_POST['actual_cost'] ?? 0);
                
                if(!in_array($new_status, ['reported','scheduled','approved','in_progress','completed','cancelled','failed_inspection'])){
                    throw new Exception("Invalid status");
                }
                
                $current = $conn->query("SELECT status, vehicle_id FROM maintenance WHERE id = $id")->fetch_assoc();
                if(!$current) throw new Exception("Maintenance record not found");
                
                $old_status = $current['status'];
                $vehicle_id = $current['vehicle_id'];
                
                $update_fields = "status = '$new_status'";
                if($new_status === 'in_progress') $update_fields .= ", started_at = NOW()";
                if($new_status === 'completed') $update_fields .= ", completed_at = NOW(), completed_date = CURDATE(), cost = $actual_cost";
                
                $conn->query("UPDATE maintenance SET $update_fields WHERE id = $id");
                
                $hist_stmt = $conn->prepare("
                    INSERT INTO maintenance_status_history (maintenance_id, old_status, new_status, changed_by, remarks)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $user_id = $_SESSION['user_id'] ?? null;
                $hist_stmt->bind_param("issis", $id, $old_status, $new_status, $user_id, $remarks);
                $hist_stmt->execute();
                
                $toast = "Status updated to " . ucfirst($new_status);
                break;
                
            case 'report_issue':
                $vehicle_id = (int)($_POST['vehicle_id'] ?? 0);
                $rental_id = !empty($_POST['rental_id']) ? (int)$_POST['rental_id'] : null;
                $inspection_id = !empty($_POST['inspection_id']) ? (int)$_POST['inspection_id'] : null;
                $issue_source = $_POST['issue_source'] ?? 'manual';
                $issue_type = $_POST['issue_type'] ?? 'other';
                $severity = $_POST['severity'] ?? 'medium';
                $description = $_POST['description'] ?? '';
                $reported_by = $_SESSION['user_id'] ?? null;
                
                $stmt = $conn->prepare("
                    INSERT INTO maintenance_issues (vehicle_id, rental_id, inspection_id, issue_source, issue_type,
                        severity, issue_description, reported_by, reported_at, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'open')
                ");
                $stmt->bind_param("iiissssi", 
                    $vehicle_id, $rental_id, $inspection_id, $issue_source,
                    $issue_type, $severity, $description, $reported_by
                );
                $stmt->execute();
                
                $conn->query("UPDATE vehicles SET current_status = 'inspection' WHERE id = $vehicle_id AND current_status NOT IN ('rented', 'maintenance')");
                
                $toast = 'Issue reported successfully';
                break;
                
            case 'resolve_issue':
                $issue_id = (int)($_POST['issue_id'] ?? 0);
                $conn->query("UPDATE maintenance_issues SET status = 'resolved' WHERE id = $issue_id");
                
                $issue = $conn->query("SELECT vehicle_id FROM maintenance_issues WHERE id = $issue_id")->fetch_assoc();
                if($issue){
                    $open_issues = $conn->query("
                        SELECT COUNT(*) as cnt FROM maintenance_issues 
                        WHERE vehicle_id = {$issue['vehicle_id']} AND status = 'open'
                    ")->fetch_assoc()['cnt'];
                    
                    if($open_issues == 0){
                        $active_maint = $conn->query("
                            SELECT COUNT(*) as cnt FROM maintenance 
                            WHERE vehicle_id = {$issue['vehicle_id']} AND status IN ('scheduled','in_progress')
                        ")->fetch_assoc()['cnt'];
                        
                        if($active_maint == 0){
                            $conn->query("UPDATE vehicles SET current_status = 'available' WHERE id = {$issue['vehicle_id']}");
                        }
                    }
                }
                
                $toast = 'Issue resolved';
                break;
                
            case 'update_job':
                $id = (int)($_POST['id'] ?? 0);
                $assigned_to = $_POST['assigned_to'] ?? '';
                $service_center = $_POST['service_center'] ?? '';
                $estimated_cost = (float)($_POST['estimated_cost'] ?? 0);
                $actual_cost = (float)($_POST['actual_cost'] ?? 0);
                $parts_used = $_POST['parts_used'] ?? '';
                $labor_hours = (float)($_POST['labor_hours'] ?? 0);
                $description = $_POST['description'] ?? '';
                $notes = $_POST['notes'] ?? '';
                $next_due_date = $_POST['next_due_date'] ?: null;
                $next_due_odometer = $_POST['next_due_odometer'] ? (float)$_POST['next_due_odometer'] : null;
                
                $stmt = $conn->prepare("
                    UPDATE maintenance SET assigned_to = ?, service_center = ?, estimated_cost = ?, cost = ?,
                        parts_used = ?, labor_hours = ?, description = ?, notes = ?,
                        next_due_date = ?, next_due_odometer = ? WHERE id = ?
                ");
                $stmt->bind_param("ssddssssdsi", 
                    $assigned_to, $service_center, $estimated_cost, $actual_cost,
                    $parts_used, $labor_hours, $description, $notes,
                    $next_due_date, $next_due_odometer, $id
                );
                $stmt->execute();
                
                $toast = 'Job details updated';
                break;
        }
    } catch (Exception $e) {
        $toast = 'Error: ' . $e->getMessage();
        $toastType = 'error';
    }
}

/* ---------- Dashboard Statistics ---------- */
$stats = [
    'active_maintenance' => (int)($conn->query("
        SELECT COUNT(*) FROM maintenance WHERE status IN ('reported','scheduled','approved','in_progress')
    ")->fetch_row()[0]),
    'scheduled' => (int)($conn->query("SELECT COUNT(*) FROM maintenance WHERE status = 'scheduled'")->fetch_row()[0]),
    'in_progress' => (int)($conn->query("SELECT COUNT(*) FROM maintenance WHERE status = 'in_progress'")->fetch_row()[0]),
    'overdue' => (int)($conn->query("
        SELECT COUNT(*) FROM maintenance WHERE status IN ('scheduled','reported') AND schedule_date < CURDATE()
    ")->fetch_row()[0]),
    'under_maintenance' => (int)($conn->query("
        SELECT COUNT(DISTINCT vehicle_id) FROM maintenance WHERE status IN ('scheduled','approved','in_progress')
    ")->fetch_row()[0]),
    'completed_month' => (int)($conn->query("
        SELECT COUNT(*) FROM maintenance WHERE status = 'completed' AND completed_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ")->fetch_row()[0]),
    'open_issues' => (int)($conn->query("SELECT COUNT(*) FROM maintenance_issues WHERE status = 'open'")->fetch_row()[0]),
    'converted_issues' => (int)($conn->query("SELECT COUNT(*) FROM maintenance_issues WHERE status = 'converted'")->fetch_row()[0])
];

/* ---------- Data Fetching ---------- */
$status_filter = $_GET['status'] ?? '';
$category_filter = $_GET['category'] ?? '';
$priority_filter = $_GET['priority'] ?? '';
$search = $_GET['search'] ?? '';

$where = ["1=1"];
if ($status_filter) $where[] = "m.status = '$status_filter'";
if ($category_filter) $where[] = "m.maintenance_category = '$category_filter'";
if ($priority_filter) $where[] = "m.priority_level = '$priority_filter'";
if ($search) $where[] = "(v.make_model LIKE '%$search%' OR v.plate_no LIKE '%$search%' OR m.description LIKE '%$search%')";

$sql = "
    SELECT m.*, v.make_model, v.plate_no, v.photo, v.vehicle_type, v.current_status as vehicle_status
    FROM maintenance m
    JOIN vehicles v ON v.id = m.vehicle_id
    WHERE " . implode(" AND ", $where) . "
    ORDER BY FIELD(m.status, 'in_progress', 'scheduled', 'reported', 'approved', 'completed', 'cancelled', 'failed_inspection'),
        FIELD(m.priority_level, 'critical', 'high', 'medium', 'low'), m.schedule_date ASC
    LIMIT 100
";
$maintenance_list = $conn->query($sql);

$issue_where = ["1=1"];
if(!empty($_GET['issue_status'])) $issue_where[] = "mi.status = '{$_GET['issue_status']}'";
if(!empty($_GET['issue_severity'])) $issue_where[] = "mi.severity = '{$_GET['issue_severity']}'";

$issues_sql = "
    SELECT mi.*, v.make_model, v.plate_no, v.photo, v.vehicle_type
    FROM maintenance_issues mi
    JOIN vehicles v ON v.id = mi.vehicle_id
    WHERE " . implode(" AND ", $issue_where) . "
    ORDER BY FIELD(mi.status, 'open', 'converted', 'resolved', 'cancelled'),
        FIELD(mi.severity, 'critical', 'high', 'medium', 'low'), mi.reported_at DESC
    LIMIT 50
";
$issues_list = $conn->query($issues_sql);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maintenance Management - FleetGo</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --bg-primary: #1a1d23;
            --bg-secondary: #22262e;
            --bg-card: #2c3039;
            --bg-hover: #363a44;
            --border-color: #3f4450;
            --text-primary: #ffffff;
            --text-secondary: #b4b9c2;
            --text-muted: #6b7280;
            --accent-blue: #3b82f6;
            --accent-green: #10b981;
            --accent-yellow: #f59e0b;
            --accent-red: #ef4444;
            --accent-purple: #8b5cf6;
            --accent-orange: #f97316;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: linear-gradient(135deg, var(--bg-primary) 0%, #2c3039 100%); color: var(--text-primary); line-height: 1.6; min-height: 100vh; }
        .container { max-width: 1600px; margin: 0 auto; padding: 24px; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 32px; padding-bottom: 20px; border-bottom: 2px solid transparent; border-image: linear-gradient(90deg, var(--accent-blue), var(--accent-green), var(--accent-yellow), var(--accent-red)); border-image-slice: 1; }
        .page-title h1 { font-size: 28px; font-weight: 700; background: linear-gradient(135deg, #ffffff, var(--text-secondary)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; margin-bottom: 8px; }
        .page-title p { color: var(--text-muted); font-size: 14px; }
        .header-actions { display: flex; gap: 12px; }
        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; border: none; transition: all 0.2s; text-decoration: none; }
        .btn-primary { background: linear-gradient(135deg, var(--accent-blue), #2563eb); color: white; box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3); }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(59, 130, 246, 0.4); }
        .btn-secondary { background: var(--bg-card); color: var(--text-secondary); border: 1px solid var(--border-color); }
        .btn-secondary:hover { background: var(--bg-hover); color: var(--text-primary); }
        .btn-success { background: linear-gradient(135deg, var(--accent-green), #059669); color: white; }
        .btn-danger { background: linear-gradient(135deg, var(--accent-red), #dc2626); color: white; }
        .btn-warning { background: linear-gradient(135deg, var(--accent-orange), #ea580c); color: white; }
        .btn-purple { background: linear-gradient(135deg, var(--accent-purple), #7c3aed); color: white; }
        .tabs { display: flex; gap: 8px; margin-bottom: 24px; border-bottom: 1px solid var(--border-color); padding-bottom: 8px; }
        .tab { padding: 12px 24px; font-weight: 600; color: var(--text-muted); cursor: pointer; border-radius: 8px 8px 0 0; transition: all 0.2s; position: relative; }
        .tab:hover { color: var(--text-primary); background: rgba(255,255,255,0.05); }
        .tab.active { color: var(--accent-blue); background: rgba(59,130,246,0.1); }
        .tab.active::after { content: ''; position: absolute; bottom: -9px; left: 0; right: 0; height: 2px; background: var(--accent-blue); }
        .tab-count { background: var(--bg-hover); color: var(--text-primary); padding: 2px 8px; border-radius: 12px; font-size: 12px; margin-left: 6px; }
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 32px; }
        .stat-card { background: linear-gradient(135deg, var(--bg-card) 0%, var(--bg-hover) 100%); border: 1px solid var(--border-color); border-radius: 12px; padding: 24px; display: flex; align-items: center; gap: 16px; transition: all 0.3s ease; }
        .stat-card:hover { transform: translateY(-4px); box-shadow: 0 8px 30px rgba(0,0,0,0.3); }
        .stat-icon { width: 56px; height: 56px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 24px; }
        .stat-icon.blue { background: linear-gradient(135deg, rgba(59,130,246,0.2), rgba(59,130,246,0.1)); color: #60a5fa; }
        .stat-icon.yellow { background: linear-gradient(135deg, rgba(245,158,11,0.2), rgba(245,158,11,0.1)); color: #fbbf24; }
        .stat-icon.green { background: linear-gradient(135deg, rgba(16,185,129,0.2), rgba(16,185,129,0.1)); color: #34d399; }
        .stat-icon.red { background: linear-gradient(135deg, rgba(239,68,68,0.2), rgba(239,68,68,0.1)); color: #f87171; }
        .stat-icon.purple { background: linear-gradient(135deg, rgba(139,92,246,0.2), rgba(139,92,246,0.1)); color: #a78bfa; }
        .stat-icon.orange { background: linear-gradient(135deg, rgba(249,115,22,0.2), rgba(249,115,22,0.1)); color: #fb923c; }
        .stat-content h3 { font-size: 32px; font-weight: 700; color: var(--text-primary); margin-bottom: 4px; }
        .stat-content p { font-size: 13px; color: var(--text-muted); font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px; }
        .filters-card { background: linear-gradient(135deg, var(--bg-card) 0%, var(--bg-hover) 100%); border: 1px solid var(--border-color); border-radius: 12px; padding: 24px; margin-bottom: 24px; }
        .filters-form { display: grid; grid-template-columns: 2fr 1fr 1fr 1fr auto; gap: 16px; align-items: end; }
        .form-group { display: flex; flex-direction: column; gap: 8px; }
        .form-group label { font-size: 12px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.5px; }
        .form-control { padding: 12px 16px; border: 1px solid var(--border-color); border-radius: 8px; font-size: 14px; background: var(--bg-secondary); color: var(--text-primary); transition: all 0.2s; }
        .form-control:focus { outline: none; border-color: var(--accent-blue); box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2); }
        .table-card { background: linear-gradient(135deg, var(--bg-card) 0%, var(--bg-hover) 100%); border: 1px solid var(--border-color); border-radius: 12px; overflow: hidden; }
        .table-header { display: flex; justify-content: space-between; align-items: center; padding: 20px 24px; border-bottom: 1px solid var(--border-color); background: var(--bg-secondary); }
        .table-header h2 { font-size: 18px; font-weight: 700; color: var(--text-primary); }
        table { width: 100%; border-collapse: collapse; }
        thead { background: var(--bg-secondary); }
        th { padding: 16px 20px; text-align: left; font-size: 12px; font-weight: 700; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid var(--border-color); }
        td { padding: 16px 20px; border-bottom: 1px solid var(--border-color); font-size: 14px; color: var(--text-secondary); }
        tbody tr:hover { background: rgba(59, 130, 246, 0.05); }
        .badge { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .badge-blue { background: rgba(59,130,246,0.2); color: #60a5fa; }
        .badge-yellow { background: rgba(245,158,11,0.2); color: #fbbf24; }
        .badge-green { background: rgba(16,185,129,0.2); color: #34d399; }
        .badge-red { background: rgba(239,68,68,0.2); color: #f87171; }
        .badge-purple { background: rgba(139,92,246,0.2); color: #a78bfa; }
        .badge-orange { background: rgba(249,115,22,0.2); color: #fb923c; }
        .badge-gray { background: rgba(107,114,128,0.2); color: #9ca3af; }
        .vehicle-info { display: flex; align-items: center; gap: 12px; }
        .vehicle-thumb { width: 48px; height: 48px; border-radius: 8px; object-fit: cover; border: 2px solid var(--border-color); }
        .vehicle-name { font-weight: 600; color: var(--text-primary); font-size: 14px; }
        .vehicle-plate { font-size: 12px; color: var(--text-muted); font-family: monospace; }
        .actions { display: flex; gap: 6px; }
        .btn-icon { width: 32px; height: 32px; border-radius: 6px; display: flex; align-items: center; justify-content: center; cursor: pointer; border: none; font-size: 13px; color: white; transition: all 0.2s; }
        .btn-icon:hover { transform: scale(1.1); }
        .btn-icon-sm { width: 28px; height: 28px; font-size: 12px; }
        .modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); display: none; align-items: center; justify-content: center; z-index: 9999; backdrop-filter: blur(8px); }
        .modal-overlay.active { display: flex; }
        .modal { background: linear-gradient(135deg, var(--bg-card) 0%, var(--bg-hover) 100%); border: 1px solid var(--border-color); border-radius: 16px; width: 90%; max-width: 1000px; max-height: 90vh; overflow-y: auto; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); }
        .modal-lg { max-width: 1200px; }
        .modal-sm { max-width: 600px; }
        .modal-header { padding: 24px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; background: var(--bg-secondary); }
        .modal-header h3 { font-size: 20px; font-weight: 700; color: var(--text-primary); }
        .modal-close { width: 36px; height: 36px; border-radius: 8px; border: none; background: var(--bg-card); color: var(--text-secondary); cursor: pointer; font-size: 18px; display: flex; align-items: center; justify-content: center; }
        .modal-close:hover { background: var(--accent-red); color: white; }
        .modal-body { padding: 24px; }
        .modal-footer { padding: 20px 24px; border-top: 1px solid var(--border-color); display: flex; justify-content: flex-end; gap: 12px; background: var(--bg-secondary); }
        .vehicle-selection { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px; max-height: 400px; overflow-y: auto; }
        .vehicle-card { background: var(--bg-secondary); border: 2px solid var(--border-color); border-radius: 12px; overflow: hidden; cursor: pointer; transition: all 0.3s ease; position: relative; }
        .vehicle-card:hover { transform: translateY(-4px); border-color: var(--accent-blue); }
        .vehicle-card.selected { border-color: var(--accent-blue); box-shadow: 0 0 0 3px rgba(59,130,246,0.3); }
        .vehicle-card.selected::after { content: '\f00c'; font-family: 'Font Awesome 6 Free'; font-weight: 900; position: absolute; top: 8px; right: 8px; width: 24px; height: 24px; background: var(--accent-blue); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; }
        .vehicle-card-image { width: 100%; height: 120px; object-fit: cover; }
        .vehicle-card-body { padding: 12px; }
        .vehicle-card-title { font-weight: 600; color: var(--text-primary); font-size: 14px; margin-bottom: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .vehicle-card-plate { font-size: 12px; color: var(--text-muted); font-family: monospace; }
        .vehicle-card-status { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: 600; text-transform: uppercase; margin-top: 4px; }
        .form-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; }
        .section-divider { margin: 24px 0; padding-top: 24px; border-top: 1px solid var(--border-color); }
        .section-title { font-size: 14px; font-weight: 700; color: var(--text-secondary); text-transform: uppercase; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .toast { position: fixed; top: 20px; right: 20px; padding: 16px 24px; border-radius: 8px; color: white; font-weight: 500; box-shadow: 0 4px 12px rgba(0,0,0,0.3); z-index: 10000; animation: slideIn 0.3s ease; }
        .toast.success { background: linear-gradient(135deg, var(--accent-green), #059669); }
        .toast.error { background: linear-gradient(135deg, var(--accent-red), #dc2626); }
        @keyframes slideIn { from { transform: translateX(400px); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        @media (max-width: 1024px) { .stats-grid { grid-template-columns: repeat(2, 1fr); } .form-grid { grid-template-columns: 1fr; } .filters-form { grid-template-columns: 1fr; } }
        @media (max-width: 768px) { .stats-grid { grid-template-columns: 1fr; } .vehicle-selection { grid-template-columns: repeat(2, 1fr); } }
    </style>
</head>
<body>
    <?php include __DIR__.'/includes/navbar.php'; ?>
    <div class="container">
        <?php if ($toast): ?>
            <div class="toast <?= $toastType ?>"><i class="fas fa-<?= $toastType === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i> <?= h($toast) ?></div>
        <?php endif; ?>
        
        <div class="page-header">
            <div class="page-title">
                <h1><i class="fas fa-wrench"></i> Maintenance Management</h1>
                <p>Full maintenance workflow with issues, jobs, and history</p>
            </div>
            <div class="header-actions">
                <button class="btn btn-primary" onclick="openModal('createJobModal')"><i class="fas fa-plus"></i> New Job</button>
                <button class="btn btn-warning" onclick="openModal('reportIssueModal')"><i class="fas fa-exclamation-triangle"></i> Report Issue</button>
                <button class="btn btn-secondary" onclick="location.reload()"><i class="fas fa-sync-alt"></i> Refresh</button>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card"><div class="stat-icon blue"><i class="fas fa-clipboard-list"></i></div><div class="stat-content"><h3><?= number_format($stats['active_maintenance']) ?></h3><p>Active Jobs</p></div></div>
            <div class="stat-card"><div class="stat-icon yellow"><i class="fas fa-clock"></i></div><div class="stat-content"><h3><?= number_format($stats['scheduled']) ?></h3><p>Scheduled</p></div></div>
            <div class="stat-card"><div class="stat-icon orange"><i class="fas fa-tools"></i></div><div class="stat-content"><h3><?= number_format($stats['in_progress']) ?></h3><p>In Progress</p></div></div>
            <div class="stat-card"><div class="stat-icon red"><i class="fas fa-exclamation-circle"></i></div><div class="stat-content"><h3><?= number_format($stats['overdue']) ?></h3><p>Overdue</p></div></div>
            <div class="stat-card"><div class="stat-icon purple"><i class="fas fa-car"></i></div><div class="stat-content"><h3><?= number_format($stats['under_maintenance']) ?></h3><p>Vehicles Affected</p></div></div>
            <div class="stat-card"><div class="stat-icon green"><i class="fas fa-check-circle"></i></div><div class="stat-content"><h3><?= number_format($stats['completed_month']) ?></h3><p>Completed (30d)</p></div></div>
            <div class="stat-card"><div class="stat-icon red"><i class="fas fa-bug"></i></div><div class="stat-content"><h3><?= number_format($stats['open_issues']) ?></h3><p>Open Issues</p></div></div>
            <div class="stat-card"><div class="stat-icon purple"><i class="fas fa-exchange-alt"></i></div><div class="stat-content"><h3><?= number_format($stats['converted_issues']) ?></h3><p>Converted to Jobs</p></div></div>
        </div>

        <div class="tabs">
            <div class="tab active" onclick="switchTab('jobs')"><i class="fas fa-wrench"></i> Maintenance Jobs<span class="tab-count"><?= $maintenance_list->num_rows ?></span></div>
            <div class="tab" onclick="switchTab('issues')"><i class="fas fa-exclamation-triangle"></i> Issues<span class="tab-count"><?= $issues_list->num_rows ?></span></div>
        </div>

        <div id="jobs-tab" class="tab-content active">
            <div class="filters-card">
                <form method="GET" class="filters-form">
                    <div class="form-group"><label>Search</label><input type="text" name="search" class="form-control" placeholder="Vehicle, plate, or description..." value="<?= h($search) ?>"></div>
                    <div class="form-group"><label>Status</label><select name="status" class="form-control"><option value="">All Status</option><option value="reported" <?= $status_filter === 'reported' ? 'selected' : '' ?>>Reported</option><option value="scheduled" <?= $status_filter === 'scheduled' ? 'selected' : '' ?>>Scheduled</option><option value="approved" <?= $status_filter === 'approved' ? 'selected' : '' ?>>Approved</option><option value="in_progress" <?= $status_filter === 'in_progress' ? 'selected' : '' ?>>In Progress</option><option value="completed" <?= $status_filter === 'completed' ? 'selected' : '' ?>>Completed</option><option value="cancelled" <?= $status_filter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option></select></div>
                    <div class="form-group"><label>Category</label><select name="category" class="form-control"><option value="">All Categories</option><option value="preventive" <?= $category_filter === 'preventive' ? 'selected' : '' ?>>Preventive</option><option value="corrective" <?= $category_filter === 'corrective' ? 'selected' : '' ?>>Corrective</option><option value="emergency" <?= $category_filter === 'emergency' ? 'selected' : '' ?>>Emergency</option><option value="cleaning" <?= $category_filter === 'cleaning' ? 'selected' : '' ?>>Cleaning</option></select></div>
                    <div class="form-group"><label>Priority</label><select name="priority" class="form-control"><option value="">All Priorities</option><option value="critical" <?= $priority_filter === 'critical' ? 'selected' : '' ?>>Critical</option><option value="high" <?= $priority_filter === 'high' ? 'selected' : '' ?>>High</option><option value="medium" <?= $priority_filter === 'medium' ? 'selected' : '' ?>>Medium</option><option value="low" <?= $priority_filter === 'low' ? 'selected' : '' ?>>Low</option></select></div>
                    <div class="form-group" style="display: flex; gap: 8px;"><button type="submit" class="btn btn-primary"><i class="fas fa-search"></i></button><a href="?" class="btn btn-secondary">Clear</a></div>
                </form>
            </div>

            <div class="table-card">
                <div class="table-header"><h2><i class="fas fa-list"></i> Maintenance Jobs</h2></div>
                <table>
                    <thead><tr><th>ID</th><th>Vehicle</th><th>Category</th><th>Priority</th><th>Description</th><th>Assigned To</th><th>Schedule Date</th><th>Status</th><th>Cost</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php while ($row = $maintenance_list->fetch_assoc()): 
                            $img = $row['photo'] ? (VEH_IMG_URL.'/'.$row['photo']) : type_fallback($row['vehicle_type']);
                            $statusClass = status_color($row['status']);
                            $priorityClass = priority_color($row['priority_level']);
                        ?>
                            <tr>
                                <td>#<?= $row['id'] ?></td>
                                <td><div class="vehicle-info"><img src="<?= h($img) ?>" alt="" class="vehicle-thumb"><div><div class="vehicle-name"><?= h($row['make_model']) ?></div><div class="vehicle-plate"><?= h($row['plate_no']) ?></div></div></div></td>
                                <td><span class="badge badge-<?= $statusClass ?>"><?= ucfirst($row['maintenance_category']) ?></span></td>
                                <td><span class="badge badge-<?= $priorityClass ?>"><?= ucfirst($row['priority_level']) ?></span></td>
                                <td><?= h(substr($row['description'] ?? 'No description', 0, 50)) ?><?= strlen($row['description'] ?? '') > 50 ? '...' : '' ?></td>
                                <td><?= h($row['assigned_to'] ?: 'Unassigned') ?></td>
                                <td><?= date('M d, Y', strtotime($row['schedule_date'])) ?></td>
                                <td><span class="badge badge-<?= $statusClass ?>"><i class="fas fa-circle" style="font-size: 8px;"></i> <?= ucfirst(str_replace('_', ' ', $row['status'])) ?></span></td>
                                <td><?php if ($row['cost'] > 0): ?>₱<?= number_format($row['cost'], 2) ?><?php elseif ($row['estimated_cost'] > 0): ?><small>Est: ₱<?= number_format($row['estimated_cost'], 2) ?></small><?php else: ?><small>-</small><?php endif; ?></td>
                                <td><div class="actions"><button class="btn-icon btn-icon-sm btn-secondary" onclick="viewDetails(<?= $row['id'] ?>)" title="View"><i class="fas fa-eye"></i></button><button class="btn-icon btn-icon-sm btn-primary" onclick="editJob(<?= $row['id'] ?>)" title="Edit"><i class="fas fa-edit"></i></button><?php if ($row['status'] !== 'completed' && $row['status'] !== 'cancelled'): ?><button class="btn-icon btn-icon-sm btn-warning" onclick="updateStatus(<?= $row['id'] ?>, '<?= $row['status'] ?>')" title="Update Status"><i class="fas fa-exchange-alt"></i></button><?php endif; ?></div></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div id="issues-tab" class="tab-content">
            <div class="filters-card">
                <form method="GET" class="filters-form">
                    <div class="form-group"><label>Issue Status</label><select name="issue_status" class="form-control"><option value="">All</option><option value="open" <?= ($_GET['issue_status'] ?? '') === 'open' ? 'selected' : '' ?>>Open</option><option value="converted" <?= ($_GET['issue_status'] ?? '') === 'converted' ? 'selected' : '' ?>>Converted</option><option value="resolved" <?= ($_GET['issue_status'] ?? '') === 'resolved' ? 'selected' : '' ?>>Resolved</option></select></div>
                    <div class="form-group"><label>Severity</label><select name="issue_severity" class="form-control"><option value="">All</option><option value="critical" <?= ($_GET['issue_severity'] ?? '') === 'critical' ? 'selected' : '' ?>>Critical</option><option value="high" <?= ($_GET['issue_severity'] ?? '') === 'high' ? 'selected' : '' ?>>High</option><option value="medium" <?= ($_GET['issue_severity'] ?? '') === 'medium' ? 'selected' : '' ?>>Medium</option><option value="low" <?= ($_GET['issue_severity'] ?? '') === 'low' ? 'selected' : '' ?>>Low</option></select></div>
                    <div class="form-group" style="display: flex; gap: 8px; align-items: flex-end;"><button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Filter</button><a href="?" class="btn btn-secondary">Clear</a></div>
                </form>
            </div>
            <div class="table-card">
                <div class="table-header"><h2><i class="fas fa-exclamation-triangle"></i> Maintenance Issues</h2></div>
                <table>
                    <thead><tr><th>ID</th><th>Vehicle</th><th>Issue Type</th><th>Severity</th><th>Description</th><th>Source</th><th>Reported</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php while ($issue = $issues_list->fetch_assoc()): 
                            $img = $issue['photo'] ? (VEH_IMG_URL.'/'.$issue['photo']) : type_fallback($issue['vehicle_type']);
                            $severityClass = priority_color($issue['severity']);
                            $issueStatusClass = status_color($issue['status']);
                        ?>
                            <tr>
                                <td>#<?= $issue['id'] ?></td>
                                <td><div class="vehicle-info"><img src="<?= h($img) ?>" alt="" class="vehicle-thumb"><div><div class="vehicle-name"><?= h($issue['make_model']) ?></div><div class="vehicle-plate"><?= h($issue['plate_no']) ?></div></div></div></td>
                                <td><?= ucfirst(str_replace('_', ' ', $issue['issue_type'])) ?></td>
                                <td><span class="badge badge-<?= $severityClass ?>"><?= ucfirst($issue['severity']) ?></span></td>
                                <td><?= h(substr($issue['issue_description'], 0, 50)) ?><?= strlen($issue['issue_description']) > 50 ? '...' : '' ?></td>
                                <td><?= ucfirst(str_replace('_', ' ', $issue['issue_source'])) ?></td>
                                <td><?= date('M d, Y', strtotime($issue['reported_at'])) ?></td>
                                <td><span class="badge badge-<?= $issueStatusClass ?>"><?= ucfirst($issue['status']) ?></span></td>
                                <td><div class="actions"><?php if ($issue['status'] === 'open'): ?><button class="btn-icon btn-icon-sm btn-purple" onclick="convertIssue(<?= $issue['id'] ?>)" title="Convert to Job"><i class="fas fa-exchange-alt"></i></button><button class="btn-icon btn-icon-sm btn-success" onclick="resolveIssue(<?= $issue['id'] ?>)" title="Resolve"><i class="fas fa-check"></i></button><?php endif; ?></div></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modals -->
    <div class="modal-overlay" id="createJobModal">
        <div class="modal modal-lg">
            <div class="modal-header"><h3><i class="fas fa-plus"></i> Create Maintenance Job</h3><button class="modal-close" onclick="closeModal('createJobModal')">&times;</button></div>
            <form method="POST" action="">
                <div class="modal-body">
                    <input type="hidden" name="action" value="create_job">
                    <input type="hidden" name="vehicle_id" id="jobVehicleId" required>
                    <div class="section-title"><i class="fas fa-car"></i> Select Vehicle</div>
                    <div class="form-group"><input type="text" class="form-control" id="vehicleSearch" placeholder="Search vehicles..." onkeyup="searchVehicles(this.value)"></div>
                    <div class="vehicle-selection" id="vehicleSelection"><p style="text-align: center; color: var(--text-muted); padding: 40px;">Type to search vehicles...</p></div>
                    <div class="section-divider">
                        <div class="section-title"><i class="fas fa-info-circle"></i> Job Details</div>
                        <div class="form-grid">
                            <div class="form-group"><label>Category *</label><select name="maintenance_category" class="form-control" required><option value="preventive">Preventive</option><option value="corrective">Corrective</option><option value="emergency">Emergency</option><option value="cleaning">Cleaning</option></select></div>
                            <div class="form-group"><label>Priority *</label><select name="priority_level" class="form-control" required><option value="low">Low</option><option value="medium" selected>Medium</option><option value="high">High</option><option value="critical">Critical</option></select></div>
                            <div class="form-group"><label>Schedule Date *</label><input type="date" name="schedule_date" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
                            <div class="form-group"><label>Assigned To</label><input type="text" name="assigned_to" class="form-control" placeholder="Technician name"></div>
                            <div class="form-group"><label>Service Center</label><input type="text" name="service_center" class="form-control" placeholder="Service center name"></div>
                            <div class="form-group"><label>Estimated Cost (₱)</label><input type="number" name="estimated_cost" class="form-control" placeholder="0.00" step="0.01"></div>
                            <div class="form-group"><label>Labor Hours</label><input type="number" name="labor_hours" class="form-control" placeholder="0.00" step="0.25"></div>
                            <div class="form-group"><label>Next Due Date</label><input type="date" name="next_due_date" class="form-control"></div>
                        </div>
                        <div class="form-group" style="margin-top: 16px;"><label>Description</label><textarea name="description" class="form-control" rows="3" placeholder="Describe the maintenance work needed..."></textarea></div>
                        <div class="form-group" style="margin-top: 16px;"><label>Parts Used</label><textarea name="parts_used" class="form-control" rows="2" placeholder="List parts to be used..."></textarea></div>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('createJobModal')">Cancel</button><button type="submit" class="btn btn-primary" id="createJobBtn" disabled><i class="fas fa-plus"></i> Create Job</button></div>
            </form>
        </div>
    </div>

    <div class="modal-overlay" id="reportIssueModal">
        <div class="modal">
            <div class="modal-header"><h3><i class="fas fa-exclamation-triangle"></i> Report Maintenance Issue</h3><button class="modal-close" onclick="closeModal('reportIssueModal')">&times;</button></div>
            <form method="POST" action="">
                <div class="modal-body">
                    <input type="hidden" name="action" value="report_issue">
                    <div class="form-group"><label>Vehicle *</label><select name="vehicle_id" class="form-control" required><option value="">Select Vehicle</option><?php $vehicles = $conn->query("SELECT id, make_model, plate_no FROM vehicles ORDER BY make_model ASC"); while ($v = $vehicles->fetch_assoc()): ?><option value="<?= $v['id'] ?>"><?= h($v['make_model']) ?> (<?= h($v['plate_no']) ?>)</option><?php endwhile; ?></select></div>
                    <div class="form-grid" style="margin-top: 16px;">
                        <div class="form-group"><label>Issue Type *</label><select name="issue_type" class="form-control" required><option value="mechanical">Mechanical</option><option value="electrical">Electrical</option><option value="body_damage">Body Damage</option><option value="cleaning">Cleaning</option><option value="tire">Tire</option><option value="engine">Engine</option><option value="other">Other</option></select></div>
                        <div class="form-group"><label>Severity *</label><select name="severity" class="form-control" required><option value="low">Low</option><option value="medium" selected>Medium</option><option value="high">High</option><option value="critical">Critical</option></select></div>
                    </div>
                    <div class="form-group" style="margin-top: 16px;"><label>Issue Description *</label><textarea name="description" class="form-control" rows="4" placeholder="Describe the issue in detail..." required></textarea></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('reportIssueModal')">Cancel</button><button type="submit" class="btn btn-warning"><i class="fas fa-exclamation-triangle"></i> Report Issue</button></div>
            </form>
        </div>
    </div>

    <div class="modal-overlay" id="viewJobModal">
        <div class="modal modal-lg">
            <div class="modal-header"><h3><i class="fas fa-eye"></i> Maintenance Job Details</h3><button class="modal-close" onclick="closeModal('viewJobModal')">&times;</button></div>
            <div class="modal-body" id="jobDetailsContent"><p style="text-align: center; color: var(--text-muted);"><i class="fas fa-spinner fa-spin"></i> Loading...</p></div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('viewJobModal')">Close</button></div>
        </div>
    </div>

    <div class="modal-overlay" id="updateStatusModal">
        <div class="modal modal-sm">
            <div class="modal-header"><h3><i class="fas fa-exchange-alt"></i> Update Status</h3><button class="modal-close" onclick="closeModal('updateStatusModal')">&times;</button></div>
            <form method="POST" action="">
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="id" id="statusJobId">
                    <div class="form-group"><label>New Status *</label><select name="status" class="form-control" required id="newStatusSelect"><option value="reported">Reported</option><option value="scheduled">Scheduled</option><option value="approved">Approved</option><option value="in_progress">In Progress</option><option value="completed">Completed</option><option value="cancelled">Cancelled</option><option value="failed_inspection">Failed Inspection</option></select></div>
                    <div class="form-group" style="margin-top: 16px;"><label>Remarks</label><textarea name="remarks" class="form-control" rows="3" placeholder="Add remarks about this status change..."></textarea></div>
                    <div class="form-group" id="actualCostGroup" style="margin-top: 16px; display: none;"><label>Actual Cost (₱)</label><input type="number" name="actual_cost" class="form-control" placeholder="0.00" step="0.01"></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('updateStatusModal')">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Status</button></div>
            </form>
        </div>
    </div>

    <script>
        function switchTab(tab) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
            if (tab === 'jobs') { document.querySelector('.tab:nth-child(1)').classList.add('active'); document.getElementById('jobs-tab').classList.add('active'); }
            else { document.querySelector('.tab:nth-child(2)').classList.add('active'); document.getElementById('issues-tab').classList.add('active'); }
        }
        function openModal(modalId) { document.getElementById(modalId).classList.add('active'); document.body.style.overflow = 'hidden'; }
        function closeModal(modalId) { document.getElementById(modalId).classList.remove('active'); document.body.style.overflow = ''; }
        
        let selectedVehicle = null;
        function searchVehicles(query) {
            if (query.length < 2) return;
            fetch(`?ajax=get_vehicles&search=${encodeURIComponent(query)}`).then(r => r.json()).then(data => {
                if (data.success) renderVehicles(data.vehicles);
            });
        }
        function renderVehicles(vehicles) {
            const container = document.getElementById('vehicleSelection');
            if (vehicles.length === 0) { container.innerHTML = '<p style="text-align: center; color: var(--text-muted);">No vehicles found</p>'; return; }
            container.innerHTML = vehicles.map(v => `
                <div class="vehicle-card ${selectedVehicle == v.id ? 'selected' : ''}" onclick="selectVehicle(${v.id})" data-id="${v.id}">
                    <img src="${v.image}" alt="${v.make_model}" class="vehicle-card-image" onerror="this.src='assets/vehicles/sedan.jpg'">
                    <div class="vehicle-card-body"><div class="vehicle-card-title">${v.make_model}</div><div class="vehicle-card-plate">${v.plate_no}</div><span class="vehicle-card-status badge-${v.current_status === 'available' ? 'green' : v.current_status === 'rented' ? 'red' : 'yellow'}">${v.current_status}</span></div>
                </div>
            `).join('');
        }
        function selectVehicle(id) {
            selectedVehicle = id; document.getElementById('jobVehicleId').value = id; document.getElementById('createJobBtn').disabled = false;
            document.querySelectorAll('.vehicle-card').forEach(card => card.classList.toggle('selected', parseInt(card.dataset.id) === id));
        }
        function viewDetails(id) {
            openModal('viewJobModal'); document.getElementById('jobDetailsContent').innerHTML = '<p style="text-align: center; color: var(--text-muted);"><i class="fas fa-spinner fa-spin"></i> Loading...</p>';
            fetch(`?ajax=get_maintenance&id=${id}`).then(r => r.json()).then(data => {
                if (data.success && data.data) {
                    const m = data.data; const img = m.photo ? `assets/vehicles/${m.photo}` : `assets/vehicles/${type_fallback(m.vehicle_type)}`;
                    let historyHtml = ''; if (data.history && data.history.length > 0) historyHtml = '<div class="timeline">' + data.history.map(h => `<div class="timeline-item"><div class="timeline-date">${new Date(h.changed_at).toLocaleString()}</div><div class="timeline-content"><strong>${h.old_status} → ${h.new_status}</strong>${h.remarks ? `<br><small>${h.remarks}</small>` : ''}</div></div>`).join('') + '</div>';
                    document.getElementById('jobDetailsContent').innerHTML = `
                        <div style="display: grid; grid-template-columns: 200px 1fr; gap: 24px;">
                            <div><img src="${img}" style="width: 100%; height: 150px; object-fit: cover; border-radius: 12px; border: 2px solid var(--border-color);" onerror="this.src='assets/vehicles/sedan.jpg'"><div style="margin-top: 16px; text-align: center;"><div style="font-weight: 700; font-size: 16px;">${m.make_model}</div><div style="color: var(--text-muted); font-family: monospace;">${m.plate_no}</div></div></div>
                            <div><div class="form-grid"><div><label style="color: var(--text-muted); font-size: 12px;">Status</label><p><span class="badge badge-${status_color(m.status)}">${m.status}</span></p></div><div><label style="color: var(--text-muted); font-size: 12px;">Category</label><p><span class="badge badge-blue">${m.maintenance_category}</span></p></div><div><label style="color: var(--text-muted); font-size: 12px;">Priority</label><p><span class="badge badge-${priority_color(m.priority_level)}">${m.priority_level}</span></p></div><div><label style="color: var(--text-muted); font-size: 12px;">Schedule Date</label><p>${new Date(m.schedule_date).toLocaleDateString()}</p></div><div><label style="color: var(--text-muted); font-size: 12px;">Assigned To</label><p>${m.assigned_to || 'Unassigned'}</p></div><div><label style="color: var(--text-muted); font-size: 12px;">Service Center</label><p>${m.service_center || '-'}</p></div><div><label style="color: var(--text-muted); font-size: 12px;">Estimated Cost</label><p>₱${parseFloat(m.estimated_cost || 0).toLocaleString('en-US', {minimumFractionDigits: 2})}</p></div><div><label style="color: var(--text-muted); font-size: 12px;">Actual Cost</label><p>₱${parseFloat(m.cost || 0).toLocaleString('en-US', {minimumFractionDigits: 2})}</p></div></div>
                                <div style="margin-top: 16px;"><label style="color: var(--text-muted); font-size: 12px;">Description</label><p style="background: var(--bg-secondary); padding: 12px; border-radius: 8px; margin-top: 4px;">${m.description || 'No description'}</p></div>
                                ${m.parts_used ? `<div style="margin-top: 16px;"><label style="color: var(--text-muted); font-size: 12px;">Parts Used</label><p style="background: var(--bg-secondary); padding: 12px; border-radius: 8px; margin-top: 4px;">${m.parts_used}</p></div>` : ''}
                                ${historyHtml ? `<div class="section-divider"><div class="section-title"><i class="fas fa-history"></i> Status History</div>${historyHtml}</div>` : ''}
                            </div>
                        </div>`;
                }
            });
        }
        function editJob(id) { viewDetails(id); }
        function updateStatus(id, currentStatus) { document.getElementById('statusJobId').value = id; document.getElementById('newStatusSelect').value = currentStatus; openModal('updateStatusModal'); }
        document.getElementById('newStatusSelect').addEventListener('change', function() { document.getElementById('actualCostGroup').style.display = this.value === 'completed' ? 'block' : 'none'; });
        function convertIssue(issueId) { document.getElementById('convertIssueId').value = issueId; fetch(`?ajax=get_issue&id=${issueId}`).then(r => r.json()).then(data => { if (data.success && data.data) { document.getElementById('convertDescription').value = data.data.issue_description; document.getElementById('convertPriority').value = data.data.severity; } }); openModal('convertIssueModal'); }
        function resolveIssue(issueId) { if (confirm('Mark this issue as resolved?')) { const form = document.createElement('form'); form.method = 'POST'; form.innerHTML = `<input type="hidden" name="action" value="resolve_issue"><input type="hidden" name="issue_id" value="${issueId}">`; document.body.appendChild(form); form.submit(); } }
        document.querySelectorAll('.modal-overlay').forEach(overlay => { overlay.addEventListener('click', function(e) { if (e.target === this) { this.classList.remove('active'); document.body.style.overflow = ''; } }); });
        setTimeout(() => { const toast = document.querySelector('.toast'); if (toast) { toast.style.opacity = '0'; toast.style.transform = 'translateX(400px)'; setTimeout(() => toast.remove(), 300); } }, 5000);
        function status_color(status) { const colors = { 'reported': 'blue', 'scheduled': 'yellow', 'approved': 'purple', 'in_progress': 'orange', 'completed': 'green', 'cancelled': 'red', 'failed_inspection': 'darkred', 'open': 'blue', 'converted': 'purple', 'resolved': 'green' }; return colors[status] || 'gray'; }
        function priority_color(priority) { const colors = {'low': 'gray', 'medium': 'yellow', 'high': 'orange', 'critical': 'red'}; return colors[priority] || 'gray'; }
        function type_fallback(type) { const t = (type || '').toLowerCase(); if (t.includes('motor')) return 'vehicles/motorcycle.jpg'; if (t.includes('pickup')) return 'vehicles/pickup.jpg'; if (t.includes('suv')) return 'vehicles/suv.jpg'; if (t.includes('van')) return 'vehicles/minivan.jpg'; return 'vehicles/sedan.jpg'; }
    </script>
</body>
</html>
<?php $conn->close(); ?>
