<?php
/* ============================================================
   maintenance_all.php — FleetGo Complete Maintenance Workflow
   Vehicle-Centered Fleet Maintenance Management System
   Version 2.0 — Full Workflow with Visual Progress, Timeline, Alerts
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

/* ---------- HELPERS ---------- */
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
        'failed_inspection' => 'darkred', 'open' => 'blue', 'converted' => 'purple', 
        'resolved' => 'green', 'inspection' => 'teal'
    ];
    return $colors[$status] ?? 'gray';
}

function priority_color($priority){
    $colors = ['low' => 'gray', 'medium' => 'yellow', 'high' => 'orange', 'critical' => 'red'];
    return $colors[$priority] ?? 'gray';
}

function blocking_reason($status){
    $reasons = [
        'maintenance' => '🔧 Maintenance in progress',
        'scheduled_maintenance' => '📅 Scheduled maintenance',
        'inspection' => '🔍 Inspection pending',
        'unavailable' => '⛔ Vehicle unavailable'
    ];
    return $reasons[$status] ?? 'Blocked from rental';
}

/* ---------- AJAX ENDPOINTS ---------- */
// Get maintenance job details with full history
if(isset($_GET['ajax']) && $_GET['ajax'] === 'get_maintenance' && isset($_GET['id'])){
    header('Content-Type: application/json');
    $id = (int)$_GET['id'];
    
    // Get job details
    $stmt = $conn->prepare("
        SELECT m.*, v.make_model, v.plate_no, v.odometer, v.vehicle_type, v.photo, 
               v.current_status as vehicle_status, v.year, v.seats, v.transmission
        FROM maintenance m
        JOIN vehicles v ON v.id = m.vehicle_id
        WHERE m.id = ?
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $job = $stmt->get_result()->fetch_assoc();
    
    // Get status history
    $hist_stmt = $conn->prepare("
        SELECT h.*, u.full_name as changed_by_name
        FROM maintenance_status_history h
        LEFT JOIN users u ON u.id = h.changed_by
        WHERE h.maintenance_id = ? ORDER BY h.changed_at ASC
    ");
    $hist_stmt->bind_param("i", $id);
    $hist_stmt->execute();
    $history = $hist_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Get linked issue
    $issue = null;
    if($job['source_reference_id'] && $job['source_type'] === 'rental_return'){
        $issue = $conn->query("
            SELECT * FROM maintenance_issues WHERE id = {$job['source_reference_id']}
        ")->fetch_assoc();
    }
    
    echo json_encode(['success' => true, 'job' => $job, 'history' => $history, 'linked_issue' => $issue]);
    exit;
}

// Get vehicle maintenance profile
if(isset($_GET['ajax']) && $_GET['ajax'] === 'get_vehicle_profile' && isset($_GET['vehicle_id'])){
    header('Content-Type: application/json');
    $vid = (int)$_GET['vehicle_id'];
    
    // Vehicle info
    $vehicle = $conn->query("
        SELECT v.*, 
               (SELECT MAX(completed_date) FROM maintenance WHERE vehicle_id = v.id AND status = 'completed') as last_maintenance
        FROM vehicles v WHERE v.id = $vid
    ")->fetch_assoc();
    
    // Maintenance rules
    $rules = $conn->query("SELECT * FROM maintenance_rules WHERE vehicle_id = $vid LIMIT 1")->fetch_assoc();
    
    // Stats
    $stats = [
        'total_jobs' => (int)$conn->query("SELECT COUNT(*) FROM maintenance WHERE vehicle_id = $vid")->fetch_row()[0],
        'completed_jobs' => (int)$conn->query("SELECT COUNT(*) FROM maintenance WHERE vehicle_id = $vid AND status = 'completed'")->fetch_row()[0],
        'total_cost' => (float)$conn->query("SELECT COALESCE(SUM(cost), 0) FROM maintenance WHERE vehicle_id = $vid AND status = 'completed'")->fetch_row()[0],
        'open_issues' => (int)$conn->query("SELECT COUNT(*) FROM maintenance_issues WHERE vehicle_id = $vid AND status = 'open'")->fetch_row()[0],
        'active_maintenance' => (int)$conn->query("SELECT COUNT(*) FROM maintenance WHERE vehicle_id = $vid AND status IN ('scheduled', 'approved', 'in_progress')")->fetch_row()[0]
    ];
    
    // History
    $history = $conn->query("
        SELECT m.*, 
               (SELECT COUNT(*) FROM maintenance_status_history WHERE maintenance_id = m.id) as status_changes
        FROM maintenance m WHERE m.vehicle_id = $vid ORDER BY m.created_at DESC LIMIT 20
    ")->fetch_all(MYSQLI_ASSOC);
    
    // Open issues
    $issues = $conn->query("
        SELECT mi.*, r.start_date as rental_start, r.end_date as rental_end
        FROM maintenance_issues mi
        LEFT JOIN rentals r ON r.id = mi.rental_id
        WHERE mi.vehicle_id = $vid AND mi.status = 'open'
        ORDER BY mi.reported_at DESC
    ")->fetch_all(MYSQLI_ASSOC);
    
    // Active maintenance
    $active = $conn->query("
        SELECT m.* FROM maintenance m 
        WHERE m.vehicle_id = $vid AND m.status IN ('scheduled', 'approved', 'in_progress')
        ORDER BY m.schedule_date ASC
    ")->fetch_all(MYSQLI_ASSOC);
    
    echo json_encode([
        'success' => true, 
        'vehicle' => $vehicle, 
        'rules' => $rules, 
        'stats' => $stats,
        'history' => $history,
        'issues' => $issues,
        'active' => $active
    ]);
    exit;
}

// Get upcoming maintenance alerts
if(isset($_GET['ajax']) && $_GET['ajax'] === 'get_upcoming'){
    header('Content-Type: application/json');
    
    // Vehicles near odometer limit
    $near_odometer = $conn->query("
        SELECT v.*, mr.km_interval, mr.last_ref_odometer,
               (v.odometer - mr.last_ref_odometer) as km_since,
               (mr.km_interval - (v.odometer - mr.last_ref_odometer)) as km_remaining
        FROM vehicles v
        JOIN maintenance_rules mr ON mr.vehicle_id = v.id
        WHERE v.current_status IN ('available', 'rented')
          AND mr.km_interval IS NOT NULL
          AND (v.odometer - mr.last_ref_odometer) >= (mr.km_interval * 0.8)
        ORDER BY km_since DESC
        LIMIT 10
    ")->fetch_all(MYSQLI_ASSOC);
    
    // Vehicles near time limit
    $near_time = $conn->query("
        SELECT v.*, mr.months_interval, mr.last_ref_date,
               DATEDIFF(CURDATE(), mr.last_ref_date) as days_since,
               (mr.months_interval * 30 - DATEDIFF(CURDATE(), mr.last_ref_date)) as days_remaining
        FROM vehicles v
        JOIN maintenance_rules mr ON mr.vehicle_id = v.id
        WHERE v.current_status IN ('available', 'rented')
          AND mr.months_interval IS NOT NULL
          AND DATEDIFF(CURDATE(), mr.last_ref_date) >= (mr.months_interval * 30 * 0.8)
        ORDER BY days_since DESC
        LIMIT 10
    ")->fetch_all(MYSQLI_ASSOC);
    
    echo json_encode(['success' => true, 'near_odometer' => $near_odometer, 'near_time' => $near_time]);
    exit;
}

// Get issues
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
               ri.cleanliness, ri.damage_report, u.full_name as reported_by_name
        FROM maintenance_issues mi
        JOIN vehicles v ON v.id = mi.vehicle_id
        LEFT JOIN rentals r ON r.id = mi.rental_id
        LEFT JOIN return_inspections ri ON ri.id = mi.inspection_id
        LEFT JOIN users u ON u.id = mi.reported_by
        WHERE $where
        ORDER BY FIELD(mi.status, 'open', 'converted', 'resolved', 'cancelled'),
                FIELD(mi.severity, 'critical', 'high', 'medium', 'low'), 
                mi.reported_at DESC
        LIMIT 100
    ";
    $issues = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
    echo json_encode(['success' => true, 'issues' => $issues]);
    exit;
}

// Get vehicles for selection
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
        $v['blocking_reason'] = in_array($v['current_status'], ['maintenance', 'scheduled_maintenance', 'inspection', 'unavailable']) 
            ? blocking_reason($v['current_status']) : null;
        $data[] = $v;
    }
    echo json_encode(['success' => true, 'vehicles' => $data]);
    exit;
}

// Get single issue
if(isset($_GET['ajax']) && $_GET['ajax'] === 'get_issue' && isset($_GET['id'])){
    header('Content-Type: application/json');
    $id = (int)$_GET['id'];
    $stmt = $conn->prepare("
        SELECT mi.*, v.make_model, v.plate_no, v.photo, v.vehicle_type, v.current_status
        FROM maintenance_issues mi
        JOIN vehicles v ON v.id = mi.vehicle_id
        WHERE mi.id = ?
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    echo json_encode(['success' => true, 'data' => $result]);
    exit;
}

/* ---------- POST HANDLERS ---------- */
$toast = '';
$toastType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax'])) {
    $action = $_POST['action'] ?? '';
    
    try {
        switch($action) {
            case 'create_job':
                $vehicle_id = (int)($_POST['vehicle_id'] ?? 0);
                $category = trim((string)($_POST['maintenance_category'] ?? ''));
                $validCategories = ['preventive','corrective','emergency','cleaning'];
                if ($category === '' || !in_array(strtolower($category), $validCategories, true)) {
                    $category = 'corrective';
                } else {
                    $category = strtolower($category);
                }
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
                
                $toast = 'Maintenance job created successfully';
                break;
                
            case 'update_status':
                $id = (int)($_POST['id'] ?? 0);
                $new_status = $_POST['status'] ?? '';
                $remarks = $_POST['remarks'] ?? '';
                $actual_cost = (float)($_POST['actual_cost'] ?? 0);
                
                $valid_statuses = ['reported','scheduled','approved','in_progress','completed','cancelled','failed_inspection'];
                if(!in_array($new_status, $valid_statuses)) throw new Exception("Invalid status");
                
                $current = $conn->query("SELECT status, vehicle_id FROM maintenance WHERE id = $id")->fetch_assoc();
                if(!$current) throw new Exception("Maintenance record not found");
                
                $old_status = $current['status'];
                
                // Build update
                $update_fields = ["status = '$new_status'"];
                if($new_status === 'in_progress') $update_fields[] = "started_at = NOW()";
                if($new_status === 'completed') {
                    $update_fields[] = "completed_at = NOW()";
                    $update_fields[] = "completed_date = CURDATE()";
                    if($actual_cost > 0) $update_fields[] = "cost = $actual_cost";
                }
                
                $conn->query("UPDATE maintenance SET " . implode(', ', $update_fields) . " WHERE id = $id");
                
                // Log status change
                $user_id = $_SESSION['user_id'] ?? null;
                $hist_stmt = $conn->prepare("
                    INSERT INTO maintenance_status_history (maintenance_id, old_status, new_status, changed_by, remarks, changed_at)
                    VALUES (?, ?, ?, ?, ?, NOW())
                ");
                $hist_stmt->bind_param("issis", $id, $old_status, $new_status, $user_id, $remarks);
                $hist_stmt->execute();
                
                $toast = "Status updated to " . ucfirst(str_replace('_', ' ', $new_status));
                break;
                
            case 'report_issue':
                $vehicle_id = (int)($_POST['vehicle_id'] ?? 0);
                $rental_id = !empty($_POST['rental_id']) ? (int)$_POST['rental_id'] : null;
                $inspection_id = !empty($_POST['inspection_id']) ? (int)$_POST['inspection_id'] : null;
                $issue_type = $_POST['issue_type'] ?? 'other';
                $severity = $_POST['severity'] ?? 'medium';
                $description = $_POST['description'] ?? '';
                $reported_by = $_SESSION['user_id'] ?? null;
                
                $stmt = $conn->prepare("
                    INSERT INTO maintenance_issues (vehicle_id, rental_id, inspection_id, issue_source, issue_type,
                        severity, issue_description, reported_by, reported_at, status)
                    VALUES (?, ?, ?, 'manual', ?, ?, ?, ?, NOW(), 'open')
                ");
                $stmt->bind_param("iiisssi", 
                    $vehicle_id, $rental_id, $inspection_id,
                    $issue_type, $severity, $description, $reported_by
                );
                $stmt->execute();
                
                // Update vehicle status
                $conn->query("UPDATE vehicles SET current_status = 'inspection' WHERE id = $vehicle_id AND current_status NOT IN ('rented', 'maintenance')");
                
                $toast = 'Issue reported successfully';
                break;
                
            case 'resolve_issue':
                $issue_id = (int)($_POST['issue_id'] ?? 0);
                $resolution_notes = $_POST['resolution_notes'] ?? '';
                
                $conn->query("UPDATE maintenance_issues SET status = 'resolved' WHERE id = $issue_id");
                
                // Check if vehicle has other open issues
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
                
            case 'convert_issue':
                $issue_id = (int)($_POST['issue_id'] ?? 0);
                $category = trim((string)($_POST['maintenance_category'] ?? ''));
                $validCategories = ['preventive','corrective','emergency','cleaning'];
                if ($category === '' || !in_array(strtolower($category), $validCategories, true)) {
                    $category = 'corrective';
                } else {
                    $category = strtolower($category);
                }
                $priority = $_POST['priority_level'] ?? 'medium';
                $schedule_date = $_POST['schedule_date'] ?? date('Y-m-d');
                $assigned_to = $_POST['assigned_to'] ?? '';
                $service_center = $_POST['service_center'] ?? '';
                $estimated_cost = (float)($_POST['estimated_cost'] ?? 0);
                
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
                    $issue['issue_description'], $issue_id,
                    $assigned_to, $service_center, $estimated_cost
                );
                $stmt->execute();
                
                $conn->query("UPDATE maintenance_issues SET status = 'converted' WHERE id = $issue_id");
                $conn->query("UPDATE vehicles SET current_status = 'scheduled_maintenance' WHERE id = {$issue['vehicle_id']} AND current_status NOT IN ('rented', 'maintenance')");
                
                $toast = 'Issue converted to maintenance job';
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

/* ---------- DATA FETCHING ---------- */
// Dashboard statistics
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
    'converted_issues' => (int)($conn->query("SELECT COUNT(*) FROM maintenance_issues WHERE status = 'converted'")->fetch_row()[0]),
    'upcoming_maintenance' => (int)($conn->query("
        SELECT COUNT(*) FROM maintenance WHERE status = 'scheduled' AND schedule_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    ")->fetch_row()[0])
];

// Get vehicles near maintenance limit (for dashboard card)
$near_limit = $conn->query("
    SELECT v.*, mr.km_interval, mr.last_ref_odometer,
           (v.odometer - mr.last_ref_odometer) as km_since,
           (mr.km_interval - (v.odometer - mr.last_ref_odometer)) as km_remaining,
           DATEDIFF(CURDATE(), mr.last_ref_date) as days_since
    FROM vehicles v
    JOIN maintenance_rules mr ON mr.vehicle_id = v.id
    WHERE v.current_status IN ('available', 'rented')
      AND (
          (mr.km_interval IS NOT NULL AND (v.odometer - mr.last_ref_odometer) >= (mr.km_interval * 0.85))
          OR
          (mr.months_interval IS NOT NULL AND DATEDIFF(CURDATE(), mr.last_ref_date) >= (mr.months_interval * 30 * 0.85))
      )
    ORDER BY km_since DESC, days_since DESC
    LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

// Main maintenance list
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
    SELECT m.*, v.make_model, v.plate_no, v.photo, v.vehicle_type, v.current_status as vehicle_status,
           v.odometer, v.year
    FROM maintenance m
    JOIN vehicles v ON v.id = m.vehicle_id
    WHERE " . implode(" AND ", $where) . "
    ORDER BY FIELD(m.status, 'in_progress', 'scheduled', 'reported', 'approved', 'completed', 'cancelled', 'failed_inspection'),
        FIELD(m.priority_level, 'critical', 'high', 'medium', 'low'), 
        m.schedule_date ASC
    LIMIT 100
";
$maintenance_list = $conn->query($sql);

// Issues list
$issue_where = ["1=1"];
if(!empty($_GET['issue_status'])) $issue_where[] = "mi.status = '{$_GET['issue_status']}'";
if(!empty($_GET['issue_severity'])) $issue_where[] = "mi.severity = '{$_GET['issue_severity']}'";

$issues_sql = "
    SELECT mi.*, v.make_model, v.plate_no, v.photo, v.vehicle_type
    FROM maintenance_issues mi
    JOIN vehicles v ON v.id = mi.vehicle_id
    WHERE " . implode(" AND ", $issue_where) . "
    ORDER BY FIELD(mi.status, 'open', 'converted', 'resolved', 'cancelled'),
        FIELD(mi.severity, 'critical', 'high', 'medium', 'low'), 
        mi.reported_at DESC
    LIMIT 50
";
$issues_list = $conn->query($issues_sql);

// Workflow steps for visual indicator
$workflow_steps = ['reported', 'scheduled', 'approved', 'in_progress', 'completed'];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fleet Maintenance Management - FleetGo</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --bg-primary: #0f1115; --bg-secondary: #1a1d23; --bg-card: #22262e; --bg-hover: #2c3039;
            --border-color: #3f4450; --text-primary: #ffffff; --text-secondary: #b4b9c2; --text-muted: #6b7280;
            --accent-blue: #3b82f6; --accent-green: #10b981; --accent-yellow: #f59e0b; --accent-red: #ef4444;
            --accent-purple: #8b5cf6; --accent-orange: #f97316; --accent-teal: #14b8a6;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; 
            background: linear-gradient(135deg, var(--bg-primary) 0%, var(--bg-secondary) 100%); 
            color: var(--text-primary); line-height: 1.6; min-height: 100vh; }
        .container { max-width: 1600px; margin: 0 auto; padding: 24px; }
        .page-header { display: flex; justify-content: space-between; align-items: center; 
            margin-bottom: 24px; padding-bottom: 16px; border-bottom: 1px solid var(--border-color); }
        .page-title h1 { font-size: 28px; font-weight: 800; 
            background: linear-gradient(135deg, #ffffff, var(--accent-blue)); 
            -webkit-background-clip: text; -webkit-text-fill-color: transparent; 
            margin-bottom: 4px; display: flex; align-items: center; gap: 12px; }
        .page-title p { color: var(--text-muted); font-size: 14px; }
        .header-actions { display: flex; gap: 10px; }
        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; 
            border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; 
            border: none; transition: all 0.2s; text-decoration: none; }
        .btn-primary { background: linear-gradient(135deg, var(--accent-blue), #2563eb); 
            color: white; box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3); }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(59, 130, 246, 0.4); }
        .btn-secondary { background: var(--bg-card); color: var(--text-secondary); border: 1px solid var(--border-color); }
        .btn-secondary:hover { background: var(--bg-hover); color: var(--text-primary); }
        .btn-success { background: linear-gradient(135deg, var(--accent-green), #059669); color: white; }
        .btn-warning { background: linear-gradient(135deg, var(--accent-yellow), #d97706); color: white; }
        .btn-danger { background: linear-gradient(135deg, var(--accent-red), #dc2626); color: white; }
        .btn-purple { background: linear-gradient(135deg, var(--accent-purple), #7c3aed); color: white; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        .stats-grid { display: grid; grid-template-columns: repeat(8, 1fr); gap: 12px; margin-bottom: 20px; }
        .stat-card { background: linear-gradient(135deg, var(--bg-card) 0%, var(--bg-hover) 100%); 
            border: 1px solid var(--border-color); border-radius: 10px; padding: 14px 16px; 
            display: flex; align-items: center; gap: 12px; transition: all 0.3s ease; }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0,0,0,0.3); }
        .stat-icon { width: 40px; height: 40px; border-radius: 10px; 
            display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
        .stat-icon.blue { background: rgba(59,130,246,0.2); color: #60a5fa; }
        .stat-icon.yellow { background: rgba(245,158,11,0.2); color: #fbbf24; }
        .stat-icon.green { background: rgba(16,185,129,0.2); color: #34d399; }
        .stat-icon.red { background: rgba(239,68,68,0.2); color: #f87171; }
        .stat-icon.purple { background: rgba(139,92,246,0.2); color: #a78bfa; }
        .stat-icon.orange { background: rgba(249,115,22,0.2); color: #fb923c; }
        .stat-icon.teal { background: rgba(20,184,166,0.2); color: #2dd4bf; }
        .stat-content h3 { font-size: 24px; font-weight: 700; color: var(--text-primary); line-height: 1; }
        .stat-content p { font-size: 11px; color: var(--text-muted); font-weight: 500; text-transform: uppercase; margin-top: 2px; }
        .alerts-section { margin-bottom: 16px; }
        .alert-card { background: linear-gradient(135deg, rgba(245,158,11,0.1), rgba(245,158,11,0.05));
            border: 1px solid rgba(245,158,11,0.3); border-radius: 8px; padding: 12px 14px;
            display: flex; align-items: center; gap: 12px; margin-bottom: 8px; }
        .alert-card.critical { background: linear-gradient(135deg, rgba(239,68,68,0.1), rgba(239,68,68,0.05));
            border-color: rgba(239,68,68,0.3); }
        .alert-icon { width: 32px; height: 32px; border-radius: 8px; background: rgba(245,158,11,0.2); 
            display: flex; align-items: center; justify-content: center; color: var(--accent-yellow); font-size: 14px; flex-shrink: 0; }
        .alert-card.critical .alert-icon { background: rgba(239,68,68,0.2); color: var(--accent-red); }
        .alert-content { flex: 1; min-width: 0; }
        .alert-title { font-weight: 600; font-size: 13px; margin-bottom: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .alert-desc { font-size: 12px; color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .tabs { display: flex; gap: 4px; margin-bottom: 16px; background: var(--bg-card);
            padding: 4px; border-radius: 10px; border: 1px solid var(--border-color); width: fit-content; }
        .tab { padding: 8px 18px; font-weight: 600; font-size: 13px; color: var(--text-muted); cursor: pointer;
            border-radius: 8px; transition: all 0.2s; display: flex; align-items: center; gap: 8px; }
        .tab:hover { color: var(--text-primary); background: rgba(255,255,255,0.05); }
        .tab.active { color: white; background: linear-gradient(135deg, var(--accent-blue), #2563eb);
            box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3); }
        .tab-count { background: rgba(255,255,255,0.2); padding: 2px 8px; border-radius: 10px; font-size: 11px; }
        .filters-card { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 10px; padding: 14px; margin-bottom: 14px; }
        .filters-form { display: flex; gap: 12px; align-items: end; }
        .form-group { display: flex; flex-direction: column; gap: 6px; flex: 1; }
        .form-group label { font-size: 11px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; }
        .form-control { padding: 8px 12px; border: 1px solid var(--border-color); border-radius: 8px;
            font-size: 13px; background: var(--bg-secondary); color: var(--text-primary); }
        .form-control:focus { outline: none; border-color: var(--accent-blue); box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2); }
        .table-card { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 10px; overflow: hidden; }
        .table-header { padding: 12px 16px; border-bottom: 1px solid var(--border-color); background: var(--bg-secondary); }
        .table-header h2 { font-size: 14px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th { padding: 10px 12px; text-align: left; font-size: 11px; font-weight: 700; color: var(--text-muted);
            text-transform: uppercase; background: var(--bg-secondary); border-bottom: 2px solid var(--border-color); white-space: nowrap; }
        td { padding: 10px 12px; border-bottom: 1px solid var(--border-color); color: var(--text-secondary); }
        tbody tr:hover { background: rgba(59, 130, 246, 0.05); }
        .badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px;
            border-radius: 20px; font-size: 12px; font-weight: 600; }
        .badge-blue { background: rgba(59,130,246,0.2); color: #60a5fa; }
        .badge-yellow { background: rgba(245,158,11,0.2); color: #fbbf24; }
        .badge-green { background: rgba(16,185,129,0.2); color: #34d399; }
        .badge-red { background: rgba(239,68,68,0.2); color: #f87171; }
        .badge-purple { background: rgba(139,92,246,0.2); color: #a78bfa; }
        .badge-orange { background: rgba(249,115,22,0.2); color: #fb923c; }
        .badge-gray { background: rgba(107,114,128,0.2); color: #9ca3af; }
        .badge-teal { background: rgba(20,184,166,0.2); color: #2dd4bf; }
        .vehicle-cell { display: flex; align-items: center; gap: 10px; cursor: pointer; }
        .vehicle-cell:hover .vehicle-name { color: var(--accent-blue); }
        .vehicle-thumb { width: 36px; height: 36px; border-radius: 8px; object-fit: cover; border: 1px solid var(--border-color); }
        .vehicle-info { display: flex; flex-direction: column; }
        .vehicle-name { font-weight: 600; color: var(--text-primary); font-size: 13px; }
        .vehicle-plate { font-size: 11px; color: var(--text-muted); font-family: monospace; }
        .vehicle-blocked { font-size: 11px; color: var(--accent-red); margin-top: 2px; display: flex; align-items: center; gap: 4px; }
        .workflow-progress { display: flex; align-items: center; gap: 4px; transform: scale(0.85); transform-origin: left; }
        .workflow-step { display: flex; flex-direction: column; align-items: center; gap: 3px; }
        .step-dot { width: 14px; height: 14px; border-radius: 50%; background: var(--border-color); border: 2px solid var(--border-color); }
        .step-dot.active { background: var(--accent-blue); border-color: var(--accent-blue); box-shadow: 0 0 10px rgba(59,130,246,0.5); }
        .step-dot.completed { background: var(--accent-green); border-color: var(--accent-green); }
        .step-label { font-size: 9px; color: var(--text-muted); text-transform: uppercase; font-weight: 600; white-space: nowrap; }
        .step-connector { width: 16px; height: 2px; background: var(--border-color); margin-bottom: 14px; }
        .step-connector.completed { background: var(--accent-green); }
        .actions { display: flex; gap: 6px; }
        .action-btn { width: 32px; height: 32px; border-radius: 6px; display: flex; align-items: center; justify-content: center;
            cursor: pointer; border: none; font-size: 13px; color: white; transition: all 0.2s; }
        .action-btn:hover { transform: scale(1.1); }
        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.8); display: none; align-items: center; justify-content: center;
            z-index: 9999; backdrop-filter: blur(8px); padding: 20px; }
        .modal-overlay.active { display: flex; }
        .modal { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 16px; 
            width: 100%; max-width: 900px; max-height: 90vh; overflow-y: auto; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); }
        .modal-xl { max-width: 1100px; }
        .modal-lg { max-width: 800px; }
        .modal-sm { max-width: 500px; }
        .modal-header { padding: 16px 20px; border-bottom: 1px solid var(--border-color); 
            display: flex; justify-content: space-between; align-items: center; background: var(--bg-secondary); }
        .modal-header h3 { font-size: 16px; font-weight: 700; display: flex; align-items: center; gap: 10px; }
        .modal-close { width: 32px; height: 32px; border-radius: 8px; border: none; background: var(--bg-card); 
            color: var(--text-muted); cursor: pointer; font-size: 18px; display: flex; align-items: center; justify-content: center; }
        .modal-close:hover { background: var(--accent-red); color: white; }
        .modal-body { padding: 20px; }
        .modal-footer { padding: 14px 20px; border-top: 1px solid var(--border-color); 
            display: flex; justify-content: flex-end; gap: 10px; background: var(--bg-secondary); }
        .vehicle-profile-header { display: grid; grid-template-columns: 160px 1fr; gap: 20px; margin-bottom: 20px; }
        .vehicle-profile-image { width: 100%; height: 120px; object-fit: cover; border-radius: 10px; border: 1px solid var(--border-color); }
        .vehicle-profile-info h2 { font-size: 20px; font-weight: 700; margin-bottom: 8px; }
        .vehicle-meta { display: flex; gap: 16px; margin-bottom: 14px; flex-wrap: wrap; font-size: 13px; color: var(--text-muted); }
        .vehicle-stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; }
        .vehicle-stat { background: var(--bg-secondary); padding: 12px; border-radius: 8px; text-align: center; }
        .vehicle-stat-value { font-size: 18px; font-weight: 700; }
        .vehicle-stat-label { font-size: 11px; color: var(--text-muted); text-transform: uppercase; }
        .timeline { position: relative; padding-left: 24px; }
        .timeline::before { content: ''; position: absolute; left: 7px; top: 0; bottom: 0; width: 2px; background: var(--border-color); }
        .timeline-item { position: relative; margin-bottom: 14px; padding: 14px; background: var(--bg-secondary); border-radius: 8px; }
        .timeline-item::before { content: ''; position: absolute; left: -20px; top: 18px; width: 10px; height: 10px; border-radius: 50%; background: var(--accent-blue); border: 2px solid var(--bg-card); }
        .timeline-date { font-size: 12px; color: var(--text-muted); margin-bottom: 4px; }
        .timeline-title { font-weight: 600; font-size: 14px; margin-bottom: 4px; }
        .timeline-desc { font-size: 13px; color: var(--text-secondary); }
        .vehicle-selection { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 12px; margin-bottom: 16px; max-height: 300px; overflow-y: auto; }
        .vehicle-card { background: var(--bg-secondary); border: 2px solid var(--border-color); border-radius: 10px; overflow: hidden; cursor: pointer; transition: all 0.3s ease; position: relative; }
        .vehicle-card:hover { transform: translateY(-3px); border-color: var(--accent-blue); }
        .vehicle-card.selected { border-color: var(--accent-blue); box-shadow: 0 0 0 3px rgba(59,130,246,0.3); }
        .vehicle-card.selected::after { content: '\\f00c'; font-family: 'Font Awesome 6 Free'; font-weight: 900; position: absolute; top: 8px; right: 8px; width: 24px; height: 24px; background: var(--accent-blue); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; }
        .vehicle-card-image { width: 100%; height: 90px; object-fit: cover; }
        .vehicle-card-body { padding: 10px; }
        .vehicle-card-title { font-weight: 600; font-size: 13px; margin-bottom: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .vehicle-card-plate { font-size: 11px; color: var(--text-muted); font-family: monospace; }
        .form-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; }
        .form-full { grid-column: 1 / -1; }
        .section-divider { margin: 20px 0; padding-top: 20px; border-top: 1px solid var(--border-color); }
        .section-title { font-size: 12px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; margin-bottom: 12px; display: flex; align-items: center; gap: 8px; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .toast { position: fixed; top: 20px; right: 20px; padding: 14px 20px; border-radius: 10px; color: white; font-weight: 600; 
            box-shadow: 0 10px 25px rgba(0,0,0,0.4); z-index: 10000; }
        .toast.success { background: linear-gradient(135deg, var(--accent-green), #059669); }
        .toast.error { background: linear-gradient(135deg, var(--accent-red), #dc2626); }
        .cost-display { font-family: 'Inter', monospace; font-weight: 600; }
        .cost-estimated { color: var(--accent-yellow); }
        .cost-actual { color: var(--accent-green); }
        @media (max-width: 1200px) { .stats-grid { grid-template-columns: repeat(4, 1fr); } }
        @media (max-width: 900px) { .stats-grid { grid-template-columns: repeat(2, 1fr); } .vehicle-stats-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 600px) { .container { padding: 16px; } .page-header { flex-direction: column; gap: 12px; } 
            .stats-grid { grid-template-columns: repeat(2, 1fr); } .form-grid { grid-template-columns: 1fr; }
            .vehicle-profile-header { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <?php include __DIR__.'/includes/navbar.php'; ?>
    <div class="container">
        <?php if ($toast): ?>
            <div class="toast <?= $toastType ?>"><i class="fas fa-<?= $toastType === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i> <?= h($toast) ?></div>
        <?php endif; ?>
        <!-- Toast Container -->
        <div class="toast-container" id="toastContainer"></div>

        <!-- Page Header -->
        <header class="page-header">
            <div class="page-title">
                <h1>
                    <i class="fas fa-wrench"></i>
                    Fleet Maintenance
                </h1>
                <p>Track, schedule, and manage vehicle maintenance workflows</p>
            </div>
            <div class="header-actions">
                <button class="btn btn-secondary" onclick="showAlerts()">
                    <i class="fas fa-bell"></i>
                    Alerts
                </button>
                <button class="btn btn-secondary" onclick="openModal('reportIssueModal')">
                    <i class="fas fa-exclamation-triangle"></i>
                    Report Issue
                </button>
                <button class="btn btn-primary" onclick="openModal('createJobModal')">
                    <i class="fas fa-plus"></i>
                    New Job
                </button>
            </div>
        </header>

        <!-- Dashboard Stats -->
        <section class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon info">
                    <i class="fas fa-clipboard-list"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?= number_format($stats['active_maintenance']) ?></div>
                    <div class="stat-label">Active Jobs</div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon warning">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?= number_format($stats['scheduled']) ?></div>
                    <div class="stat-label">Scheduled</div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon accent">
                    <i class="fas fa-tools"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?= number_format($stats['in_progress']) ?></div>
                    <div class="stat-label">In Progress</div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon danger">
                    <i class="fas fa-exclamation-circle"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?= number_format($stats['overdue']) ?></div>
                    <div class="stat-label">Overdue</div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon info">
                    <i class="fas fa-car"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?= number_format($stats['under_maintenance']) ?></div>
                    <div class="stat-label">Affected</div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon success">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?= number_format($stats['completed_month']) ?></div>
                    <div class="stat-label">Completed</div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon warning">
                    <i class="fas fa-calendar-alt"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?= number_format($stats['upcoming_maintenance']) ?></div>
                    <div class="stat-label">Due Soon</div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon danger">
                    <i class="fas fa-bug"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?= number_format($stats['open_issues']) ?></div>
                    <div class="stat-label">Issues</div>
                </div>
            </div>
        </section>

        <!-- Upcoming Alerts Section -->
        <div id="upcomingAlerts" class="alerts-section" style="display: none;">
            <!-- Populated by JS -->
        </div>

        <!-- Near Limit Vehicles -->
        <?php if (!empty($near_limit)): ?>
        <div class="alerts-section">
            <?php foreach ($near_limit as $v): 
                $km_pct = isset($v['km_interval']) && $v['km_interval'] > 0 
                    ? round(($v['km_since'] / $v['km_interval']) * 100, 0) 
                    : 0;
                $is_critical = $km_pct >= 95 || $v['km_remaining'] <= 500;
            ?>
                <div class="alert-card <?= $is_critical ? 'critical' : '' ?>">
                    <div class="alert-icon"><i class="fas fa-<?= $is_critical ? 'exclamation-triangle' : 'bell' ?>"></i></div>
                    <div class="alert-content">
                        <div class="alert-title"><?= h($v['make_model']) ?> (<?= h($v['plate_no']) ?>)</div>
                        <div class="alert-desc">
                            <?php if ($v['km_remaining'] !== null && $v['km_interval']): ?>
                                Due in <?= number_format($v['km_remaining']) ?> km (<?= $km_pct ?>% of interval)
                            <?php endif; ?>
                            <?php if ($v['days_since'] && $v['days_since'] > 0): ?>
                                • <?= round($v['days_since']/30, 1) ?> months since last service
                            <?php endif; ?>
                        </div>
                    </div>
                    <button class="btn btn-sm btn-primary" onclick="quickSchedule(<?= $v['id'] ?>)">Schedule</button>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Tabs -->
        <div class="tabs">
            <div class="tab active" onclick="switchTab('jobs')">
                <i class="fas fa-wrench"></i> Maintenance Jobs
                <span class="tab-count"><?= $maintenance_list->num_rows ?></span>
            </div>
            <div class="tab" onclick="switchTab('issues')">
                <i class="fas fa-exclamation-triangle"></i> Issues
                <span class="tab-count"><?= $issues_list->num_rows ?></span>
            </div>
        </div>

        <!-- Jobs Tab -->
        <div id="jobs-tab" class="tab-content active">
            <div class="filters-card">
                <form method="GET" class="filters-form">
                    <div class="form-group">
                        <label>Search</label>
                        <input type="text" name="search" class="form-control" placeholder="Vehicle, plate, or description..." value="<?= h($search) ?>">
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" class="form-control">
                            <option value="">All Status</option>
                            <option value="reported" <?= $status_filter === 'reported' ? 'selected' : '' ?>>Reported</option>
                            <option value="scheduled" <?= $status_filter === 'scheduled' ? 'selected' : '' ?>>Scheduled</option>
                            <option value="approved" <?= $status_filter === 'approved' ? 'selected' : '' ?>>Approved</option>
                            <option value="in_progress" <?= $status_filter === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                            <option value="completed" <?= $status_filter === 'completed' ? 'selected' : '' ?>>Completed</option>
                            <option value="cancelled" <?= $status_filter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Category</label>
                        <select name="category" class="form-control">
                            <option value="">All Categories</option>
                            <option value="preventive" <?= $category_filter === 'preventive' ? 'selected' : '' ?>>Preventive</option>
                            <option value="corrective" <?= $category_filter === 'corrective' ? 'selected' : '' ?>>Corrective</option>
                            <option value="emergency" <?= $category_filter === 'emergency' ? 'selected' : '' ?>>Emergency</option>
                            <option value="cleaning" <?= $category_filter === 'cleaning' ? 'selected' : '' ?>>Cleaning</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Priority</label>
                        <select name="priority" class="form-control">
                            <option value="">All Priorities</option>
                            <option value="critical" <?= $priority_filter === 'critical' ? 'selected' : '' ?>>Critical</option>
                            <option value="high" <?= $priority_filter === 'high' ? 'selected' : '' ?>>High</option>
                            <option value="medium" <?= $priority_filter === 'medium' ? 'selected' : '' ?>>Medium</option>
                            <option value="low" <?= $priority_filter === 'low' ? 'selected' : '' ?>>Low</option>
                        </select>
                    </div>
                    <div class="form-group" style="display: flex; gap: 8px;">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i></button>
                        <a href="?" class="btn btn-secondary">Clear</a>
                    </div>
                </form>
            </div>

            <div class="table-card">
                <div class="table-header">
                    <h2><i class="fas fa-list"></i> Maintenance Jobs</h2>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Vehicle</th>
                            <th>Workflow</th>
                            <th>Category</th>
                            <th>Priority</th>
                            <th>Description</th>
                            <th>Assigned To</th>
                            <th>Schedule Date</th>
                            <th>Status</th>
                            <th>Cost</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = $maintenance_list->fetch_assoc()): 
                            $img = $row['photo'] ? (VEH_IMG_URL.'/'.$row['photo']) : type_fallback($row['vehicle_type']);
                            $statusClass = status_color($row['status']);
                            $priorityClass = priority_color($row['priority_level']);
                            $is_blocked = in_array($row['vehicle_status'], ['maintenance', 'scheduled_maintenance', 'inspection', 'unavailable']);
                            
                            // Calculate workflow step
                            $current_step = array_search($row['status'], $workflow_steps);
                            if ($current_step === false) $current_step = -1;
                        ?>
                            <tr>
                                <td>#<?= $row['id'] ?></td>
                                <td>
                                    <div class="vehicle-cell" onclick="openVehicleProfile(<?= $row['vehicle_id'] ?>)">
                                        <img src="<?= h($img) ?>" alt="" class="vehicle-thumb">
                                        <div class="vehicle-info">
                                            <div class="vehicle-name"><?= h($row['make_model']) ?></div>
                                            <div class="vehicle-plate"><?= h($row['plate_no']) ?></div>
                                            <?php if ($is_blocked): ?>
                                                <div class="vehicle-blocked">
                                                    <i class="fas fa-ban"></i> 
                                                    <?= blocking_reason($row['vehicle_status']) ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="workflow-progress">
                                        <?php foreach ($workflow_steps as $i => $step): 
                                            $is_completed = $i < $current_step;
                                            $is_active = $i === $current_step;
                                        ?>
                                            <div class="workflow-step">
                                                <div class="step-dot <?= $is_completed ? 'completed' : ($is_active ? 'active' : '') ?>"></div>
                                                <div class="step-label"><?= ucfirst($step) ?></div>
                                            </div>
                                            <?php if ($i < count($workflow_steps) - 1): ?>
                                                <div class="step-connector <?= $i < $current_step ? 'completed' : '' ?>"></div>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                                <td><span class="badge badge-<?= $statusClass ?>"><?= ucfirst($row['maintenance_category']) ?></span></td>
                                <td><span class="badge badge-<?= $priorityClass ?>"><?= ucfirst($row['priority_level']) ?></span></td>
                                <td><?= h(substr($row['description'] ?? 'No description', 0, 40)) ?><?= strlen($row['description'] ?? '') > 40 ? '...' : '' ?></td>
                                <td><?= h($row['assigned_to'] ?: 'Unassigned') ?></td>
                                <td><?= date('M d, Y', strtotime($row['schedule_date'])) ?></td>
                                <td><span class="badge badge-<?= $statusClass ?>"><?= ucfirst(str_replace('_', ' ', $row['status'])) ?></span></td>
                                <td class="cost-display">
                                    <?php if ($row['cost'] > 0): ?>
                                        <span class="cost-actual">₱<?= number_format($row['cost'], 0) ?></span>
                                    <?php elseif ($row['estimated_cost'] > 0): ?>
                                        <span class="cost-estimated">~₱<?= number_format($row['estimated_cost'], 0) ?></span>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted);">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="actions">
                                        <button class="action-btn btn-primary" onclick="viewJobDetails(<?= $row['id'] ?>)" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <?php if ($row['status'] !== 'completed' && $row['status'] !== 'cancelled'): ?>
                                            <button class="action-btn btn-warning" onclick="updateStatus(<?= $row['id'] ?>, '<?= $row['status'] ?>')" title="Update Status">
                                                <i class="fas fa-exchange-alt"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Issues Tab -->
        <div id="issues-tab" class="tab-content">
            <div class="filters-card">
                <form method="GET" class="filters-form">
                    <div class="form-group">
                        <label>Issue Status</label>
                        <select name="issue_status" class="form-control">
                            <option value="">All</option>
                            <option value="open" <?= ($_GET['issue_status'] ?? '') === 'open' ? 'selected' : '' ?>>Open</option>
                            <option value="converted" <?= ($_GET['issue_status'] ?? '') === 'converted' ? 'selected' : '' ?>>Converted</option>
                            <option value="resolved" <?= ($_GET['issue_status'] ?? '') === 'resolved' ? 'selected' : '' ?>>Resolved</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Severity</label>
                        <select name="issue_severity" class="form-control">
                            <option value="">All</option>
                            <option value="critical" <?= ($_GET['issue_severity'] ?? '') === 'critical' ? 'selected' : '' ?>>Critical</option>
                            <option value="high" <?= ($_GET['issue_severity'] ?? '') === 'high' ? 'selected' : '' ?>>High</option>
                            <option value="medium" <?= ($_GET['issue_severity'] ?? '') === 'medium' ? 'selected' : '' ?>>Medium</option>
                            <option value="low" <?= ($_GET['issue_severity'] ?? '') === 'low' ? 'selected' : '' ?>>Low</option>
                        </select>
                    </div>
                    <div class="form-group" style="display: flex; gap: 8px; align-items: flex-end; grid-column: -3 / -1;">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Filter</button>
                        <a href="?" class="btn btn-secondary">Clear</a>
                    </div>
                </form>
            </div>

            <div class="table-card">
                <div class="table-header">
                    <h2><i class="fas fa-exclamation-triangle"></i> Maintenance Issues</h2>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Vehicle</th>
                            <th>Issue Type</th>
                            <th>Severity</th>
                            <th>Description</th>
                            <th>Source</th>
                            <th>Reported</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($issue = $issues_list->fetch_assoc()): 
                            $img = $issue['photo'] ? (VEH_IMG_URL.'/'.$issue['photo']) : type_fallback($issue['vehicle_type']);
                            $severityClass = priority_color($issue['severity']);
                            $issueStatusClass = status_color($issue['status']);
                        ?>
                            <tr>
                                <td>#<?= $issue['id'] ?></td>
                                <td>
                                    <div class="vehicle-cell" onclick="openVehicleProfile(<?= $issue['vehicle_id'] ?>)">
                                        <img src="<?= h($img) ?>" alt="" class="vehicle-thumb">
                                        <div class="vehicle-info">
                                            <div class="vehicle-name"><?= h($issue['make_model']) ?></div>
                                            <div class="vehicle-plate"><?= h($issue['plate_no']) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td><?= ucfirst(str_replace('_', ' ', $issue['issue_type'])) ?></td>
                                <td><span class="badge badge-<?= $severityClass ?>"><?= ucfirst($issue['severity']) ?></span></td>
                                <td><?= h(substr($issue['issue_description'], 0, 40)) ?><?= strlen($issue['issue_description']) > 40 ? '...' : '' ?></td>
                                <td><?= ucfirst(str_replace('_', ' ', $issue['issue_source'])) ?></td>
                                <td><?= date('M d, Y', strtotime($issue['reported_at'])) ?></td>
                                <td><span class="badge badge-<?= $issueStatusClass ?>"><?= ucfirst($issue['status']) ?></span></td>
                                <td>
                                    <div class="actions">
                                        <?php if ($issue['status'] === 'open'): ?>
                                            <button class="action-btn btn-purple" onclick="convertIssue(<?= $issue['id'] ?>)" title="Convert to Job">
                                                <i class="fas fa-exchange-alt"></i>
                                            </button>
                                            <button class="action-btn btn-success" onclick="resolveIssue(<?= $issue['id'] ?>)" title="Resolve">
                                                <i class="fas fa-check"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- MODALS -->
    
    <!-- Create Job Modal -->
    <div class="modal-overlay" id="createJobModal">
        <div class="modal modal-lg">
            <div class="modal-header">
                <h3><i class="fas fa-plus"></i> Create Maintenance Job</h3>
                <button class="modal-close" onclick="closeModal('createJobModal')">&times;</button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <input type="hidden" name="action" value="create_job">
                    <input type="hidden" name="vehicle_id" id="jobVehicleId" required>
                    
                    <div class="section-title"><i class="fas fa-car"></i> Select Vehicle</div>
                    <div class="form-group">
                        <input type="text" class="form-control" id="vehicleSearch" placeholder="Search vehicles by name or plate..." onkeyup="searchVehicles(this.value)">
                    </div>
                    <div class="vehicle-selection" id="vehicleSelection">
                        <p style="text-align: center; color: var(--text-muted); padding: 40px;">
                            <i class="fas fa-search" style="font-size: 24px; margin-bottom: 8px; display: block;"></i>
                            Type to search vehicles...
                        </p>
                    </div>
                    
                    <div class="section-divider">
                        <div class="section-title"><i class="fas fa-info-circle"></i> Job Details</div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Category *</label>
                                <select name="maintenance_category" class="form-control" required>
                                    <option value="preventive">Preventive</option>
                                    <option value="corrective" selected>Corrective</option>
                                    <option value="emergency">Emergency</option>
                                    <option value="cleaning">Cleaning</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Priority *</label>
                                <select name="priority_level" class="form-control" required>
                                    <option value="low">Low</option>
                                    <option value="medium" selected>Medium</option>
                                    <option value="high">High</option>
                                    <option value="critical">Critical</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Schedule Date *</label>
                                <input type="date" name="schedule_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Assigned To</label>
                                <input type="text" name="assigned_to" class="form-control" placeholder="Mechanic name">
                            </div>
                            <div class="form-group">
                                <label>Service Center</label>
                                <input type="text" name="service_center" class="form-control" placeholder="Service center name">
                            </div>
                            <div class="form-group">
                                <label>Estimated Cost (₱)</label>
                                <input type="number" name="estimated_cost" class="form-control" placeholder="0.00" step="0.01">
                            </div>
                            <div class="form-group">
                                <label>Labor Hours</label>
                                <input type="number" name="labor_hours" class="form-control" placeholder="0.00" step="0.25">
                            </div>
                            <div class="form-group">
                                <label>Next Due Date</label>
                                <input type="date" name="next_due_date" class="form-control">
                            </div>
                        </div>
                        <div class="form-group form-full" style="margin-top: 16px;">
                            <label>Description</label>
                            <textarea name="description" class="form-control" rows="3" placeholder="Describe the maintenance work needed..."></textarea>
                        </div>
                        <div class="form-group form-full" style="margin-top: 16px;">
                            <label>Parts Used</label>
                            <textarea name="parts_used" class="form-control" rows="2" placeholder="List parts to be used..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('createJobModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="createJobBtn" disabled>
                        <i class="fas fa-plus"></i> Create Job
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Report Issue Modal -->
    <div class="modal-overlay" id="reportIssueModal">
        <div class="modal">
            <div class="modal-header">
                <h3><i class="fas fa-exclamation-triangle"></i> Report Maintenance Issue</h3>
                <button class="modal-close" onclick="closeModal('reportIssueModal')">&times;</button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <input type="hidden" name="action" value="report_issue">
                    
                    <div class="form-group">
                        <label>Vehicle *</label>
                        <select name="vehicle_id" class="form-control" required>
                            <option value="">Select Vehicle</option>
                            <?php 
                            $vehicles = $conn->query("SELECT id, make_model, plate_no, current_status FROM vehicles ORDER BY make_model ASC");
                            while ($v = $vehicles->fetch_assoc()):
                            ?>
                                <option value="<?= $v['id'] ?>">
                                    <?= h($v['make_model']) ?> (<?= h($v['plate_no']) ?>)
                                    <?php if (in_array($v['current_status'], ['maintenance', 'scheduled_maintenance', 'inspection'])): ?>
                                        - [<?= $v['current_status'] ?>]
                                    <?php endif; ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="form-grid" style="margin-top: 16px;">
                        <div class="form-group">
                            <label>Issue Type *</label>
                            <select name="issue_type" class="form-control" required>
                                <option value="mechanical">Mechanical</option>
                                <option value="electrical">Electrical</option>
                                <option value="body_damage">Body Damage</option>
                                <option value="cleaning">Cleaning</option>
                                <option value="tire">Tire</option>
                                <option value="engine">Engine</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Severity *</label>
                            <select name="severity" class="form-control" required>
                                <option value="low">Low</option>
                                <option value="medium" selected>Medium</option>
                                <option value="high">High</option>
                                <option value="critical">Critical</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group form-full" style="margin-top: 16px;">
                        <label>Issue Description *</label>
                        <textarea name="description" class="form-control" rows="4" placeholder="Describe the issue in detail..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('reportIssueModal')">Cancel</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-exclamation-triangle"></i> Report Issue
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Job Details Modal -->
    <div class="modal-overlay" id="jobDetailsModal">
        <div class="modal modal-xl">
            <div class="modal-header">
                <h3><i class="fas fa-clipboard-list"></i> Job Details</h3>
                <button class="modal-close" onclick="closeModal('jobDetailsModal')">&times;</button>
            </div>
            <div class="modal-body" id="jobDetailsContent">
                <p style="text-align: center; color: var(--text-muted); padding: 40px;">
                    <i class="fas fa-spinner fa-spin" style="font-size: 24px; margin-bottom: 16px; display: block;"></i>
                    Loading job details...
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('jobDetailsModal')">Close</button>
            </div>
        </div>
    </div>

    <!-- Vehicle Profile Modal -->
    <div class="modal-overlay" id="vehicleProfileModal">
        <div class="modal modal-xl">
            <div class="modal-header">
                <h3><i class="fas fa-car"></i> Vehicle Maintenance Profile</h3>
                <button class="modal-close" onclick="closeModal('vehicleProfileModal')">&times;</button>
            </div>
            <div class="modal-body" id="vehicleProfileContent">
                <p style="text-align: center; color: var(--text-muted); padding: 40px;">
                    <i class="fas fa-spinner fa-spin" style="font-size: 24px; margin-bottom: 16px; display: block;"></i>
                    Loading vehicle profile...
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('vehicleProfileModal')">Close</button>
            </div>
        </div>
    </div>

    <!-- Update Status Modal -->
    <div class="modal-overlay" id="updateStatusModal">
        <div class="modal modal-sm">
            <div class="modal-header">
                <h3><i class="fas fa-exchange-alt"></i> Update Status</h3>
                <button class="modal-close" onclick="closeModal('updateStatusModal')">&times;</button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="id" id="statusJobId">
                    
                    <div class="form-group">
                        <label>New Status *</label>
                        <select name="status" class="form-control" required id="newStatusSelect">
                            <option value="reported">Reported</option>
                            <option value="scheduled">Scheduled</option>
                            <option value="approved">Approved</option>
                            <option value="in_progress">In Progress</option>
                            <option value="completed">Completed</option>
                            <option value="cancelled">Cancelled</option>
                            <option value="failed_inspection">Failed Inspection</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin-top: 16px;">
                        <label>Remarks</label>
                        <textarea name="remarks" class="form-control" rows="3" placeholder="Add remarks about this status change..."></textarea>
                    </div>
                    <div class="form-group" id="actualCostGroup" style="margin-top: 16px; display: none;">
                        <label>Actual Cost (₱)</label>
                        <input type="number" name="actual_cost" class="form-control" placeholder="0.00" step="0.01">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('updateStatusModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Update Status
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Convert Issue Modal -->
    <div class="modal-overlay" id="convertIssueModal">
        <div class="modal">
            <div class="modal-header">
                <h3><i class="fas fa-exchange-alt"></i> Convert Issue to Job</h3>
                <button class="modal-close" onclick="closeModal('convertIssueModal')">&times;</button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <input type="hidden" name="action" value="convert_issue">
                    <input type="hidden" name="issue_id" id="convertIssueId">
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Category</label>
                            <select name="maintenance_category" class="form-control">
                                <option value="corrective" selected>Corrective</option>
                                <option value="preventive">Preventive</option>
                                <option value="emergency">Emergency</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Priority</label>
                            <select name="priority_level" class="form-control" id="convertPriority">
                                <option value="low">Low</option>
                                <option value="medium" selected>Medium</option>
                                <option value="high">High</option>
                                <option value="critical">Critical</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Schedule Date</label>
                            <input type="date" name="schedule_date" class="form-control" value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="form-group">
                            <label>Assigned To</label>
                            <input type="text" name="assigned_to" class="form-control" placeholder="Technician name">
                        </div>
                    </div>
                    <div class="form-group form-full" style="margin-top: 16px;">
                        <label>Service Center</label>
                        <input type="text" name="service_center" class="form-control" placeholder="Service center name">
                    </div>
                    <div class="form-group form-full" style="margin-top: 16px;">
                        <label>Estimated Cost (₱)</label>
                        <input type="number" name="estimated_cost" class="form-control" placeholder="0.00" step="0.01">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('convertIssueModal')">Cancel</button>
                    <button type="submit" class="btn btn-purple">
                        <i class="fas fa-exchange-alt"></i> Convert to Job
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- JAVASCRIPT -->
    <script>
        // Tab switching
        function switchTab(tab) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
            
            if (tab === 'jobs') {
                document.querySelector('.tab:nth-child(1)').classList.add('active');
                document.getElementById('jobs-tab').classList.add('active');
            } else {
                document.querySelector('.tab:nth-child(2)').classList.add('active');
                document.getElementById('issues-tab').classList.add('active');
            }
        }

        // Modal functions
        function openModal(modalId) {
            document.getElementById(modalId).classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
            document.body.style.overflow = '';
        }

        // Vehicle search for create job
        let selectedVehicle = null;

        function searchVehicles(query) {
            if (query.length < 2) return;
            
            fetch(`?ajax=get_vehicles&search=${encodeURIComponent(query)}`)
                .then(r => r.json())
                .then(data => {
                    if (data.success) renderVehicles(data.vehicles);
                });
        }

        function renderVehicles(vehicles) {
            const container = document.getElementById('vehicleSelection');
            if (vehicles.length === 0) {
                container.innerHTML = '<p style="text-align: center; color: var(--text-muted); padding: 40px;">No vehicles found</p>';
                return;
            }
            
            container.innerHTML = vehicles.map(v => {
                const isBlocked = v.blocking_reason !== null;
                return `
                    <div class="vehicle-card ${selectedVehicle == v.id ? 'selected' : ''} ${isBlocked ? 'blocked' : ''}" 
                         onclick="${isBlocked ? '' : `selectVehicle(${v.id})`}" data-id="${v.id}">
                        ${isBlocked ? `<div class="vehicle-card-blocked">${v.blocking_reason}</div>` : ''}
                        <img src="${v.image}" alt="${v.make_model}" class="vehicle-card-image" onerror="this.src='assets/vehicles/sedan.jpg'">
                        <div class="vehicle-card-body">
                            <div class="vehicle-card-title">${v.make_model}</div>
                            <div class="vehicle-card-plate">${v.plate_no}</div>
                            <span class="badge badge-${v.current_status === 'available' ? 'green' : v.current_status === 'rented' ? 'red' : 'yellow'}" style="margin-top: 4px; font-size: 10px;">
                                ${v.current_status}
                            </span>
                        </div>
                    </div>
                `;
            }).join('');
        }

        function selectVehicle(id) {
            selectedVehicle = id;
            document.getElementById('jobVehicleId').value = id;
            document.getElementById('createJobBtn').disabled = false;
            
            document.querySelectorAll('.vehicle-card').forEach(card => {
                card.classList.toggle('selected', parseInt(card.dataset.id) === id);
            });
        }

        // View job details with timeline
        function viewJobDetails(id) {
            openModal('jobDetailsModal');
            const content = document.getElementById('jobDetailsContent');
            content.innerHTML = '<p style="text-align: center; color: var(--text-muted); padding: 40px;"><i class="fas fa-spinner fa-spin" style="font-size: 24px; margin-bottom: 16px; display: block;"></i>Loading...</p>';
            
            fetch(`?ajax=get_maintenance&id=${id}`)
                .then(r => r.json())
                .then(data => {
                    if (data.success && data.job) {
                        const m = data.job;
                        const img = m.photo ? `assets/vehicles/${m.photo}` : `assets/vehicles/${type_fallback(m.vehicle_type)}`;
                        
                        // Build workflow progress
                        const workflowSteps = ['reported', 'scheduled', 'approved', 'in_progress', 'completed'];
                        const currentStep = workflowSteps.indexOf(m.status);
                        
                        let workflowHtml = '<div class="workflow-progress" style="margin: 20px 0; justify-content: center;">';
                        workflowSteps.forEach((step, i) => {
                            const isCompleted = i < currentStep;
                            const isActive = i === currentStep;
                            workflowHtml += `
                                <div class="workflow-step">
                                    <div class="step-dot ${isCompleted ? 'completed' : (isActive ? 'active' : '')}"></div>
                                    <div class="step-label">${step.replace('_', ' ')}</div>
                                </div>
                            `;
                            if (i < workflowSteps.length - 1) {
                                workflowHtml += `<div class="step-connector ${i < currentStep ? 'completed' : ''}"></div>`;
                            }
                        });
                        workflowHtml += '</div>';
                        
                        // Build timeline
                        let timelineHtml = '';
                        if (data.history && data.history.length > 0) {
                            timelineHtml = '<div class="section-divider"><div class="section-title"><i class="fas fa-history"></i> Status Timeline</div><div class="timeline">' + 
                                data.history.map(h => `
                                    <div class="timeline-item">
                                        <div class="timeline-date">${new Date(h.changed_at).toLocaleString()}</div>
                                        <div class="timeline-title">${h.old_status} → ${h.new_status}</div>
                                        ${h.remarks ? `<div class="timeline-desc">${h.remarks}</div>` : ''}
                                        ${h.changed_by_name ? `<div class="timeline-desc" style="font-size: 11px; color: var(--text-muted);">By: ${h.changed_by_name}</div>` : ''}
                                    </div>
                                `).join('') + '</div></div>';
                        }
                        
                        content.innerHTML = `
                            <div class="vehicle-profile-header">
                                <div>
                                    <img src="${img}" alt="${m.make_model}" class="vehicle-profile-image" onerror="this.src='assets/vehicles/sedan.jpg'">
                                </div>
                                <div class="vehicle-profile-info">
                                    <h2>${m.make_model}</h2>
                                    <div class="vehicle-meta">
                                        <div class="vehicle-meta-item"><i class="fas fa-hashtag"></i> ${m.plate_no}</div>
                                        <div class="vehicle-meta-item"><i class="fas fa-tachometer-alt"></i> ${parseInt(m.odometer).toLocaleString()} km</div>
                                        <div class="vehicle-meta-item"><i class="fas fa-calendar"></i> ${m.year}</div>
                                    </div>
                                    ${workflowHtml}
                                </div>
                            </div>
                            
                            <div class="form-grid" style="margin-bottom: 20px;">
                                <div class="vehicle-stat">
                                    <div class="vehicle-stat-value" style="color: var(--accent-${status_color(m.status)})">${m.status.replace('_', ' ')}</div>
                                    <div class="vehicle-stat-label">Status</div>
                                </div>
                                <div class="vehicle-stat">
                                    <div class="vehicle-stat-value">${m.maintenance_category}</div>
                                    <div class="vehicle-stat-label">Category</div>
                                </div>
                                <div class="vehicle-stat">
                                    <div class="vehicle-stat-value" style="color: var(--accent-${priority_color(m.priority_level)})">${m.priority_level}</div>
                                    <div class="vehicle-stat-label">Priority</div>
                                </div>
                                <div class="vehicle-stat">
                                    <div class="vehicle-stat-value">₱${parseFloat(m.cost || m.estimated_cost || 0).toLocaleString()}</div>
                                    <div class="vehicle-stat-label">${m.cost > 0 ? 'Actual Cost' : 'Est. Cost'}</div>
                                </div>
                            </div>
                            
                            <div class="form-grid">
                                <div class="form-group">
                                    <label>Assigned To</label>
                                    <input type="text" class="form-control" value="${m.assigned_to || 'Unassigned'}" readonly>
                                </div>
                                <div class="form-group">
                                    <label>Service Center</label>
                                    <input type="text" class="form-control" value="${m.service_center || '-'}" readonly>
                                </div>
                                <div class="form-group">
                                    <label>Schedule Date</label>
                                    <input type="text" class="form-control" value="${new Date(m.schedule_date).toLocaleDateString()}" readonly>
                                </div>
                                <div class="form-group">
                                    <label>Labor Hours</label>
                                    <input type="text" class="form-control" value="${m.labor_hours || '-'}" readonly>
                                </div>
                            </div>
                            
                            <div class="form-group form-full" style="margin-top: 16px;">
                                <label>Description</label>
                                <textarea class="form-control" rows="3" readonly>${m.description || 'No description'}</textarea>
                            </div>
                            
                            ${m.parts_used ? `
                                <div class="form-group form-full" style="margin-top: 16px;">
                                    <label>Parts Used</label>
                                    <textarea class="form-control" rows="2" readonly>${m.parts_used}</textarea>
                                </div>
                            ` : ''}
                            
                            ${m.notes ? `
                                <div class="form-group form-full" style="margin-top: 16px;">
                                    <label>Notes</label>
                                    <textarea class="form-control" rows="2" readonly>${m.notes}</textarea>
                                </div>
                            ` : ''}
                            
                            ${timelineHtml}
                        `;
                    }
                });
        }

        // Open vehicle profile
        function openVehicleProfile(vehicleId) {
            openModal('vehicleProfileModal');
            const content = document.getElementById('vehicleProfileContent');
            content.innerHTML = '<p style="text-align: center; color: var(--text-muted); padding: 40px;"><i class="fas fa-spinner fa-spin" style="font-size: 24px; margin-bottom: 16px; display: block;"></i>Loading vehicle profile...</p>';
            
            fetch(`?ajax=get_vehicle_profile&vehicle_id=${vehicleId}`)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        const v = data.vehicle;
                        const img = v.photo ? `assets/vehicles/${v.photo}` : `assets/vehicles/${type_fallback(v.vehicle_type)}`;
                        
                        // Build maintenance history
                        let historyHtml = '<p style="color: var(--text-muted); text-align: center; padding: 20px;">No maintenance history</p>';
                        if (data.history && data.history.length > 0) {
                            historyHtml = `<table style="width: 100%; font-size: 13px;">
                                <thead><tr><th>Date</th><th>Category</th><th>Status</th><th>Cost</th></tr></thead>
                                <tbody>${data.history.map(h => `
                                    <tr>
                                        <td>${new Date(h.schedule_date).toLocaleDateString()}</td>
                                        <td><span class="badge badge-${status_color(h.status)}">${h.maintenance_category}</span></td>
                                        <td><span class="badge badge-${status_color(h.status)}">${h.status}</span></td>
                                        <td>₱${parseFloat(h.cost || 0).toLocaleString()}</td>
                                    </tr>
                                `).join('')}</tbody>
                            </table>`;
                        }
                        
                        // Build issues
                        let issuesHtml = '<p style="color: var(--text-muted); text-align: center; padding: 20px;">No open issues</p>';
                        if (data.issues && data.issues.length > 0) {
                            issuesHtml = `<table style="width: 100%; font-size: 13px;">
                                <thead><tr><th>Type</th><th>Severity</th><th>Description</th><th>Reported</th></tr></thead>
                                <tbody>${data.issues.map(i => `
                                    <tr>
                                        <td>${i.issue_type}</td>
                                        <td><span class="badge badge-${priority_color(i.severity)}">${i.severity}</span></td>
                                        <td>${i.issue_description.substring(0, 50)}...</td>
                                        <td>${new Date(i.reported_at).toLocaleDateString()}</td>
                                    </tr>
                                `).join('')}</tbody>
                            </table>`;
                        }
                        
                        content.innerHTML = `
                            <div class="vehicle-profile-header">
                                <div>
                                    <img src="${img}" alt="${v.make_model}" class="vehicle-profile-image" onerror="this.src='assets/vehicles/sedan.jpg'">
                                </div>
                                <div class="vehicle-profile-info">
                                    <h2>${v.make_model}</h2>
                                    <div class="vehicle-meta">
                                        <div class="vehicle-meta-item"><i class="fas fa-hashtag"></i> ${v.plate_no}</div>
                                        <div class="vehicle-meta-item"><i class="fas fa-tachometer-alt"></i> ${parseInt(v.odometer).toLocaleString()} km</div>
                                        <div class="vehicle-meta-item"><i class="fas fa-calendar"></i> ${v.year}</div>
                                        <div class="vehicle-meta-item"><i class="fas fa-info-circle"></i> ${v.current_status}</div>
                                    </div>
                                    <div class="vehicle-stats-grid">
                                        <div class="vehicle-stat">
                                            <div class="vehicle-stat-value">${data.stats.total_jobs}</div>
                                            <div class="vehicle-stat-label">Total Jobs</div>
                                        </div>
                                        <div class="vehicle-stat">
                                            <div class="vehicle-stat-value">${data.stats.completed_jobs}</div>
                                            <div class="vehicle-stat-label">Completed</div>
                                        </div>
                                        <div class="vehicle-stat">
                                            <div class="vehicle-stat-value" style="color: var(--accent-green)">₱${data.stats.total_cost.toLocaleString()}</div>
                                            <div class="vehicle-stat-label">Total Cost</div>
                                        </div>
                                        <div class="vehicle-stat">
                                            <div class="vehicle-stat-value" style="color: ${data.stats.open_issues > 0 ? 'var(--accent-red)' : 'var(--accent-green)'}">${data.stats.open_issues}</div>
                                            <div class="vehicle-stat-label">Open Issues</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="tabs" style="margin-top: 24px;">
                                <div class="tab active" onclick="switchProfileTab('history')">Maintenance History</div>
                                <div class="tab" onclick="switchProfileTab('issues')">Open Issues (${data.stats.open_issues})</div>
                            </div>
                            
                            <div id="profile-history" class="tab-content active" style="margin-top: 16px;">
                                ${historyHtml}
                            </div>
                            <div id="profile-issues" class="tab-content" style="margin-top: 16px;">
                                ${issuesHtml}
                            </div>
                        `;
                    }
                });
        }

        function switchProfileTab(tab) {
            document.querySelectorAll('#vehicleProfileContent .tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('#vehicleProfileContent .tab-content').forEach(t => t.classList.remove('active'));
            
            if (tab === 'history') {
                document.querySelector('#vehicleProfileContent .tab:nth-child(1)').classList.add('active');
                document.getElementById('profile-history').classList.add('active');
            } else {
                document.querySelector('#vehicleProfileContent .tab:nth-child(2)').classList.add('active');
                document.getElementById('profile-issues').classList.add('active');
            }
        }

        // Update status
        function updateStatus(id, currentStatus) {
            document.getElementById('statusJobId').value = id;
            document.getElementById('newStatusSelect').value = currentStatus;
            openModal('updateStatusModal');
        }

        document.getElementById('newStatusSelect')?.addEventListener('change', function() {
            const costGroup = document.getElementById('actualCostGroup');
            if (costGroup) {
                costGroup.style.display = this.value === 'completed' ? 'block' : 'none';
            }
        });

        // Convert issue
        function convertIssue(issueId) {
            document.getElementById('convertIssueId').value = issueId;
            openModal('convertIssueModal');
        }

        // Resolve issue
        function resolveIssue(issueId) {
            if (confirm('Mark this issue as resolved?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="resolve_issue">
                    <input type="hidden" name="issue_id" value="${issueId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Load upcoming maintenance alerts
        function loadUpcoming() {
            const alertsSection = document.getElementById('upcomingAlerts');
            const isVisible = alertsSection.style.display !== 'none';
            
            if (isVisible) {
                alertsSection.style.display = 'none';
                return;
            }
            
            alertsSection.innerHTML = '<p style="text-align: center; color: var(--text-muted); padding: 20px;"><i class="fas fa-spinner fa-spin"></i> Loading alerts...</p>';
            alertsSection.style.display = 'block';
            
            fetch('?ajax=get_upcoming')
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        let html = '';
                        
                        if (data.near_odometer && data.near_odometer.length > 0) {
                            html += '<div class="section-title"><i class="fas fa-tachometer-alt"></i> Near Odometer Limit</div>';
                            data.near_odometer.forEach(v => {
                                const kmPct = Math.round((v.km_since / v.km_interval) * 100);
                                html += `
                                    <div class="alert-card ${kmPct >= 95 ? 'critical' : ''}">
                                        <div class="alert-icon"><i class="fas fa-${kmPct >= 95 ? 'exclamation-triangle' : 'tachometer-alt'}"></i></div>
                                        <div class="alert-content">
                                            <div class="alert-title">${v.make_model} (${v.plate_no})</div>
                                            <div class="alert-desc">
                                                ${v.km_remaining} km remaining (${kmPct}% of ${v.km_interval} km interval)
                                                • Odometer: ${parseInt(v.odometer).toLocaleString()} km
                                            </div>
                                        </div>
                                        <button class="btn btn-sm btn-primary" onclick="quickSchedule(${v.id})">Schedule</button>
                                    </div>
                                `;
                            });
                        }
                        
                        if (data.near_time && data.near_time.length > 0) {
                            html += '<div class="section-title" style="margin-top: 16px;"><i class="fas fa-calendar-alt"></i> Near Time Limit</div>';
                            data.near_time.forEach(v => {
                                const monthsSince = Math.round(v.days_since / 30);
                                const monthsInterval = v.months_interval;
                                const pct = Math.round((monthsSince / monthsInterval) * 100);
                                html += `
                                    <div class="alert-card ${pct >= 95 ? 'critical' : ''}">
                                        <div class="alert-icon"><i class="fas fa-${pct >= 95 ? 'exclamation-triangle' : 'calendar-alt'}"></i></div>
                                        <div class="alert-content">
                                            <div class="alert-title">${v.make_model} (${v.plate_no})</div>
                                            <div class="alert-desc">
                                                ${monthsSince} months since last service (${pct}% of ${monthsInterval} month interval)
                                                • Last service: ${new Date(v.last_ref_date).toLocaleDateString()}
                                            </div>
                                        </div>
                                        <button class="btn btn-sm btn-primary" onclick="quickSchedule(${v.id})">Schedule</button>
                                    </div>
                                `;
                            });
                        }
                        
                        if (html === '') {
                            html = '<p style="text-align: center; color: var(--text-muted); padding: 20px;"><i class="fas fa-check-circle" style="color: var(--accent-green); margin-right: 8px;"></i>No upcoming maintenance alerts</p>';
                        }
                        
                        alertsSection.innerHTML = html;
                    }
                });
        }

        // Quick schedule from alert
        function quickSchedule(vehicleId) {
            openModal('createJobModal');
            document.getElementById('vehicleSearch').value = '';
            document.getElementById('vehicleSelection').innerHTML = '<p style="text-align: center; color: var(--text-muted); padding: 40px;"><i class="fas fa-spinner fa-spin"></i> Loading vehicle...</p>';
            
            fetch(`?ajax=get_vehicles`)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        const vehicle = data.vehicles.find(v => v.id === vehicleId);
                        if (vehicle) {
                            renderVehicles([vehicle]);
                            selectVehicle(vehicleId);
                        }
                    }
                });
        }

        // Helpers
        function type_fallback(type) {
            const t = (type || '').toLowerCase();
            if (t.includes('motor')) return 'vehicles/motorcycle.jpg';
            if (t.includes('pickup')) return 'vehicles/pickup.jpg';
            if (t.includes('suv')) return 'vehicles/suv.jpg';
            if (t.includes('van')) return 'vehicles/minivan.jpg';
            return 'vehicles/sedan.jpg';
        }

        function status_color(status) {
            const colors = { 
                'reported': 'blue', 
                'scheduled': 'yellow', 
                'approved': 'purple',
                'in_progress': 'orange', 
                'completed': 'green', 
                'cancelled': 'red',
                'failed_inspection': 'red', 
                'open': 'blue', 
                'converted': 'purple', 
                'resolved': 'green' 
            };
            return colors[status] || 'gray';
        }

        function priority_color(priority) {
            const colors = {
                'low': 'gray', 
                'medium': 'yellow', 
                'high': 'orange', 
                'critical': 'red'
            };
            return colors[priority] || 'gray';
        }

        // Close modals on outside click
        document.querySelectorAll('.modal-overlay').forEach(overlay => {
            overlay.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.remove('active');
                    document.body.style.overflow = '';
                }
            });
        });

        // Toast auto-hide
        setTimeout(() => {
            const toast = document.querySelector('.toast');
            if (toast) {
                toast.style.opacity = '0';
                toast.style.transform = 'translateX(400px)';
                setTimeout(() => toast.remove(), 300);
            }
        }, 5000);
    </script>
</body>
</html>
<?php $conn->close(); ?>
