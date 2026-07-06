<?php
/* =========================================
   dashboard.php — FleetGo Rental Management Dashboard
   Professional Modern Edition
   ========================================= */

/* ---------- SESSION FIX ---------- */
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
session_name('fleetgo_session_admin');
session_start();

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/forecast_demand.php';
require_once __DIR__ . '/includes/forecast_maintenance.php';
require_once __DIR__ . '/includes/forecast_utilization.php';

/* --- Access Control --- */
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: login.php");
    exit;
}

/* ---------- AJAX Endpoints for Dynamic Updates ---------- */
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    try {
        switch ($_GET['ajax']) {
            case 'kpi_data':
                $kpiData = [
                    'totalVehicles' => (int)scalar($conn,"SELECT COUNT(*) FROM vehicles"),
                    'availableToday' => (int)scalar($conn,"SELECT COUNT(*) FROM vehicles WHERE current_status='available'"),
                    'activeRentals' => (int)scalar($conn,"SELECT COUNT(*) FROM rentals WHERE status IN('pending','reserved','ongoing')"),
                    'maintDue' => (int)scalar($conn,"SELECT COUNT(*) FROM maintenance WHERE status IN('scheduled','in_progress') AND schedule_date=CURDATE()"),
                    'todayRevenue' => (float)scalar($conn,"SELECT COALESCE(SUM(total_cost), 0) FROM rentals WHERE DATE(created_at) = CURDATE() AND status = 'completed'"),
                    'monthlyRevenue' => (float)scalar($conn,"SELECT COALESCE(SUM(total_cost), 0) FROM rentals WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE()) AND status = 'completed'"),
                    'utilizationRate' => $totalVehicles > 0 ? round(($activeRentals / $totalVehicles) * 100, 1) : 0,
                    'urgentMaintenance' => (int)scalar($conn,"SELECT COUNT(*) FROM maintenance WHERE status = 'scheduled' AND schedule_date <= CURDATE()"),
                    'overdueRentals' => (int)scalar($conn,"SELECT COUNT(*) FROM rentals WHERE status = 'ongoing' AND end_date < CURDATE()"),
                    'pendingApprovals' => (int)scalar($conn,"SELECT COUNT(*) FROM users WHERE profile_status = 'pending_approval'"),
                    'timestamp' => time()
                ];
                echo json_encode($kpiData);
                break;
                
            case 'recent_rentals':
                $recentRentalsQuery = "
                    SELECT r.id, r.created_at, r.start_date, r.end_date, r.status,
                           u.full_name as customer_name,
                           CONCAT(v.make_model, ' (', v.plate_no, ')') as vehicle_name
                    FROM rentals r
                    LEFT JOIN users u ON u.id = r.customer_id
                    LEFT JOIN vehicles v ON v.id = r.vehicle_id
                    WHERE r.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                    ORDER BY r.end_date ASC, r.id DESC
                    LIMIT 8
                ";
                
                $result = $conn->query($recentRentalsQuery);
                $rentals = [];
                
                if ($result) {
                    while ($row = $result->fetch_assoc()) {
                        $rentals[] = [
                            'id' => $row['id'],
                            'customer_name' => $row['customer_name'],
                            'vehicle_name' => $row['vehicle_name'],
                            'start_date' => $row['start_date'],
                            'end_date' => $row['end_date'],
                            'status' => $row['status'],
                            'created_at' => $row['created_at']
                        ];
                    }
                    $result->free();
                }
                
                header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
                header('Pragma: no-cache');
                echo json_encode(['success' => true, 'data' => $rentals]);
                break;
                
            case 'maintenance_forecast':
                $maintenanceForecastQuery = "
                    SELECT m.id, v.make_model, v.plate_no, m.type, m.schedule_date, m.status,
                           CASE 
                               WHEN m.schedule_date <= CURDATE() THEN 'OVERDUE'
                               WHEN DATEDIFF(m.schedule_date, CURDATE()) <= 7 THEN 'DUE SOON'
                               ELSE 'OK'
                           END as urgency,
                           DATEDIFF(m.schedule_date, CURDATE()) as days_until
                    FROM maintenance m
                    LEFT JOIN vehicles v ON v.id = m.vehicle_id
                    WHERE m.status IN ('scheduled', 'in_progress')
                    ORDER BY m.schedule_date ASC
                    LIMIT 5
                ";
                
                $result = $conn->query($maintenanceForecastQuery);
                $maintenance = [];
                
                if ($result) {
                    while ($row = $result->fetch_assoc()) {
                        $maintenance[] = [
                            'id' => $row['id'],
                            'vehicle_name' => $row['make_model'] . ' (' . $row['plate_no'] . ')',
                            'type' => $row['type'],
                            'schedule_date' => $row['schedule_date'],
                            'status' => $row['status'],
                            'urgency' => $row['urgency'],
                            'days_until' => $row['days_until']
                        ];
                    }
                    $result->free();
                }
                
                echo json_encode(['success' => true, 'data' => $maintenance]);
                break;
                
            case 'forecast_data':
                $forecastData = [
                    'monthlyLabels' => $monthlyLabels ?? [],
                    'monthlyCounts' => $monthlyCounts ?? [],
                    'forecastLabel' => $forecastLabel ?? 'Next Month',
                    'forecastValue' => $forecastValue ?? 0,
                    'peakMonthLabel' => $peakMonthLabel ?? 'N/A',
                    'peakMonthCount' => $peakMonthCount ?? 0
                ];
                
                echo json_encode(['success' => true, 'data' => $forecastData]);
                break;
                
            default:
                throw new Exception('Invalid AJAX endpoint');
        }
        
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
            'message' => 'Server error occurred'
        ]);
    }
    
    exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function scalar($c,$sql,$fallback=0){
  try{$r=$c->query($sql);if($r&&($row=$r->fetch_row()))return $row[0]??$fallback;}catch(Throwable $e){}
  return $fallback;
}
function qsafe($c,$sql){try{return $c->query($sql);}catch(Throwable $e){return false;}}
function h($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}

/* ---------- Data Queries for Charts ---------- */

// 1. Monthly Rental Demand (Last 12 months)
$monthlyDemandQuery = "
    SELECT DATE_FORMAT(start_date, '%Y-%m') as month, COUNT(*) as count
    FROM rentals
    WHERE start_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY month
    ORDER BY month ASC
";
$monthlyDemandData = [];
try {
    $result = $conn->query($monthlyDemandQuery);
    while ($row = $result->fetch_assoc()) {
        $monthlyDemandData[] = $row;
    }
} catch (Throwable $e) {}

// 2. Monthly Revenue Trend
$monthlyRevenueQuery = "
    SELECT DATE_FORMAT(created_at, '%Y-%m') as month, COALESCE(SUM(total_cost), 0) as revenue
    FROM rentals
    WHERE status = 'completed' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY month
    ORDER BY month ASC
";
$monthlyRevenueData = [];
try {
    $result = $conn->query($monthlyRevenueQuery);
    while ($row = $result->fetch_assoc()) {
        $monthlyRevenueData[] = $row;
    }
} catch (Throwable $e) {}

// 3. Maintenance Frequency Trend (Monthly)
$maintenanceTrendQuery = "
    SELECT DATE_FORMAT(schedule_date, '%Y-%m') as month, COUNT(*) as count
    FROM maintenance
    WHERE schedule_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY month
    ORDER BY month ASC
";
$maintenanceTrendData = [];
try {
    $result = $conn->query($maintenanceTrendQuery);
    while ($row = $result->fetch_assoc()) {
        $maintenanceTrendData[] = $row;
    }
} catch (Throwable $e) {}

// 4. Peak Rental Days (Day of week analysis)
$peakDaysQuery = "
    SELECT DAYNAME(start_date) as day_name, COUNT(*) as count
    FROM rentals
    WHERE start_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY day_name
    ORDER BY count DESC
";
$peakDaysData = [];
try {
    $result = $conn->query($peakDaysQuery);
    while ($row = $result->fetch_assoc()) {
        $peakDaysData[] = $row;
    }
} catch (Throwable $e) {}

// 5. Most Rented Vehicles (Top 10)
$mostRentedQuery = "
    SELECT v.make_model, v.plate_no, COUNT(r.id) as rental_count
    FROM rentals r
    JOIN vehicles v ON v.id = r.vehicle_id
    GROUP BY r.vehicle_id, v.make_model, v.plate_no
    ORDER BY rental_count DESC
    LIMIT 10
";
$mostRentedData = [];
try {
    $result = $conn->query($mostRentedQuery);
    while ($row = $result->fetch_assoc()) {
        $mostRentedData[] = $row;
    }
} catch (Throwable $e) {}

// 6. Revenue by Day (Last 30 days)
$dailyRevenueQuery = "
    SELECT DATE(created_at) as date, COALESCE(SUM(total_cost), 0) as revenue
    FROM rentals
    WHERE status = 'completed' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY DATE(created_at)
    ORDER BY date ASC
";
$dailyRevenueData = [];
try {
    $result = $conn->query($dailyRevenueQuery);
    while ($row = $result->fetch_assoc()) {
        $dailyRevenueData[] = $row;
    }
} catch (Throwable $e) {}

// Render dashboard only if not AJAX request
if (!isset($_GET['ajax'])) {

$today=date('Y-m-d');

/* ---------- KPI Cards ---------- */
$totalVehicles=(int)scalar($conn,"SELECT COUNT(*) FROM vehicles");
$availableToday=(int)scalar($conn,"SELECT COUNT(*) FROM vehicles WHERE current_status='available'");
$activeRentals = (int)scalar($conn,"SELECT COUNT(*) FROM rentals WHERE status IN('pending','reserved','ongoing')");
$maintDue=(int)scalar($conn,"SELECT COUNT(*) FROM maintenance WHERE status IN('scheduled','in_progress') AND schedule_date=CURDATE()");
$overdueRentals=(int)scalar($conn,"SELECT COUNT(*) FROM rentals WHERE status = 'ongoing' AND end_date < CURDATE()");

// Initialize forecast variables from included files
$forecastValue = 0;
$peakMonthLabel = 'N/A';
$peakMonthCount = 0;
$monthlyLabels = [];
$monthlyCounts = [];
$overusedCount = 0;
$underusedCount = 0;
$topOverused = [];
$topUnderused = [];
$maintenanceOverdueCount = 0;
$maintenanceDueSoonCount = 0;

/* ---------- Dynamic Revenue & Performance Metrics ---------- */
$todayRevenue = (float)scalar($conn,"SELECT COALESCE(SUM(total_cost), 0) FROM rentals WHERE DATE(created_at) = CURDATE() AND status = 'completed'");
$monthlyRevenue = (float)scalar($conn,"SELECT COALESCE(SUM(total_cost), 0) FROM rentals WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE()) AND status = 'completed'");
$lastMonthRevenue = (float)scalar($conn,"SELECT COALESCE(SUM(total_cost), 0) FROM rentals WHERE MONTH(created_at) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND YEAR(created_at) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND status = 'completed'");

$avgRentalDuration = (float)scalar($conn,"SELECT COALESCE(AVG(total_days), 0) FROM rentals WHERE status = 'completed'");
$utilizationRate = $totalVehicles > 0 ? round(($activeRentals / $totalVehicles) * 100, 1) : 0;

/* ---------- Real-time Alerts & Notifications ---------- */
$alertSummary = $conn->query("
    SELECT 
        (SELECT COUNT(*) FROM maintenance WHERE status = 'scheduled' AND schedule_date <= CURDATE()) AS urgent_maintenance,
        (SELECT COUNT(*) FROM rentals WHERE status = 'ongoing' AND end_date < CURDATE()) AS overdue_rentals,
        (SELECT COUNT(*) FROM users WHERE profile_status = 'pending_approval') AS pending_approvals,
        (SELECT COUNT(*) FROM vehicles WHERE current_status = 'maintenance') AS vehicles_in_maintenance
")->fetch_assoc();

$urgentMaintenance = (int)($alertSummary['urgent_maintenance'] ?? 0);
$overdueRentals = (int)($alertSummary['overdue_rentals'] ?? 0);
$pendingApprovals = (int)($alertSummary['pending_approvals'] ?? 0);

/* ---------- Trend Calculations ---------- */
$lastMonth = date('Y-m', strtotime('-1 month'));
$currentMonth = date('Y-m');

$vehiclesLastMonth = (int)scalar($conn,"SELECT COUNT(*) FROM vehicles WHERE DATE_FORMAT(created_at,'%Y-%m')='$lastMonth'");
$vehiclesThisMonth = (int)scalar($conn,"SELECT COUNT(*) FROM vehicles WHERE DATE_FORMAT(created_at,'%Y-%m')='$currentMonth'");
$vehicleTrend = $vehiclesThisMonth > $vehiclesLastMonth ? 'up' : ($vehiclesThisMonth < $vehiclesLastMonth ? 'down' : 'neutral');

$rentalsLastMonth = (int)scalar($conn,"SELECT COUNT(*) FROM rentals WHERE DATE_FORMAT(start_date,'%Y-%m')='$lastMonth'");
$rentalsThisMonth = (int)scalar($conn,"SELECT COUNT(*) FROM rentals WHERE DATE_FORMAT(start_date,'%Y-%m')='$currentMonth'");
$rentalTrend = $rentalsThisMonth > $rentalsLastMonth ? 'up' : ($rentalsThisMonth < $rentalsLastMonth ? 'down' : 'neutral');

/* ---------- Recent Activity Data ---------- */
$recentRentals = qsafe($conn,"
    SELECT r.id, c.first_name, c.last_name, v.make_model, v.plate_no, 
           r.start_date, r.end_date, r.status
    FROM rentals r
    JOIN customers c ON c.id = r.customer_id
    JOIN vehicles v ON v.id = r.vehicle_id
    ORDER BY r.created_at DESC
    LIMIT 8
");

$recentMaintenance = qsafe($conn,"
    SELECT m.id, v.make_model, v.plate_no, m.type, m.schedule_date, m.status
    FROM maintenance m
    JOIN vehicles v ON v.id = m.vehicle_id
    ORDER BY m.created_at DESC
    LIMIT 5
");

$pageTitle = 'Dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - FleetGo Rental Management</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* ========================================
           Dashboard Design System - Modern Fleet Analytics
           ======================================== */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #1a1d23 0%, #2c3039 100%);
            color: #e2e8f0;
            min-height: 100vh;
            line-height: 1.5;
        }

        /* Main Container */
        .dashboard-container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 24px;
        }

        /* Header Section */
        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 28px;
            padding-bottom: 20px;
            border-bottom: 1px solid rgba(71, 85, 105, 0.3);
        }

        .dashboard-title {
            font-size: 1.75rem;
            font-weight: 700;
            background: linear-gradient(135deg, #ffffff, #94a3b8);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .header-subtitle {
            color: #64748b;
            font-size: 0.875rem;
            margin-top: 4px;
        }

        .refresh-btn {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            border: none;
            color: white;
            padding: 10px 18px;
            border-radius: 10px;
            cursor: pointer;
            font-size: 0.875rem;
            font-weight: 500;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .refresh-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }

        /* ========================================
           KPI Cards Section - Clean & Consistent
           ======================================== */
        .kpi-section {
            margin-bottom: 32px;
        }

        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
        }

        .kpi-card {
            background: linear-gradient(135deg, #2c3039 0%, #363a44 100%);
            border: 1px solid rgba(71, 85, 105, 0.4);
            border-radius: 16px;
            padding: 20px;
            display: flex;
            flex-direction: column;
            transition: all 0.25s ease;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            min-height: 140px;
        }

        .kpi-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.1), transparent);
        }

        .kpi-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.25);
            border-color: rgba(71, 85, 105, 0.6);
        }

        .kpi-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 16px;
        }

        .kpi-label {
            font-size: 0.75rem;
            color: #94a3b8;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .kpi-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            color: white;
            flex-shrink: 0;
        }

        .kpi-icon.primary { background: linear-gradient(135deg, #3b82f6, #2563eb); }
        .kpi-icon.success { background: linear-gradient(135deg, #10b981, #059669); }
        .kpi-icon.warning { background: linear-gradient(135deg, #f59e0b, #d97706); }
        .kpi-icon.danger { background: linear-gradient(135deg, #ef4444, #dc2626); }
        .kpi-icon.info { background: linear-gradient(135deg, #06b6d4, #0891b2); }

        .kpi-value {
            font-size: 1.75rem;
            font-weight: 700;
            color: #f8fafc;
            margin-bottom: 8px;
            line-height: 1.2;
        }

        .kpi-trend {
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 6px;
            font-weight: 500;
        }

        .kpi-trend.positive { color: #34d399; }
        .kpi-trend.negative { color: #f87171; }
        .kpi-trend.neutral { color: #94a3b8; }

        /* ========================================
           Forecast Insight Section
           ======================================== */
        .forecast-section {
            margin-bottom: 32px;
        }

        .section-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
        }

        .section-title {
            font-size: 1.125rem;
            font-weight: 600;
            color: #f8fafc;
        }

        .section-divider {
            flex: 1;
            height: 1px;
            background: linear-gradient(90deg, rgba(71, 85, 105, 0.5), transparent);
        }

        .forecast-card {
            background: linear-gradient(135deg, #2c3039 0%, #363a44 100%);
            border: 1px solid rgba(71, 85, 105, 0.4);
            border-radius: 16px;
            padding: 24px;
            display: flex;
            align-items: center;
            gap: 32px;
            transition: all 0.25s ease;
        }

        .forecast-card:hover {
            border-color: rgba(71, 85, 105, 0.6);
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
        }

        .forecast-stat {
            display: flex;
            align-items: center;
            gap: 16px;
            padding-right: 32px;
            border-right: 1px solid rgba(71, 85, 105, 0.3);
        }

        .forecast-icon {
            width: 56px;
            height: 56px;
            border-radius: 14px;
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
            flex-shrink: 0;
        }

        .forecast-value {
            font-size: 2.25rem;
            font-weight: 700;
            color: #f8fafc;
            line-height: 1;
        }

        .forecast-label {
            font-size: 0.875rem;
            color: #94a3b8;
            margin-top: 4px;
        }

        .forecast-insight {
            flex: 1;
        }

        .insight-title {
            font-size: 1rem;
            font-weight: 600;
            color: #e2e8f0;
            margin-bottom: 8px;
        }

        .insight-text {
            font-size: 0.9375rem;
            color: #94a3b8;
            line-height: 1.6;
        }

        .insight-highlight {
            color: #34d399;
            font-weight: 600;
        }

        /* ========================================
           Trend Analysis Section - Charts Grid
           ======================================== */
        .trend-section {
            margin-bottom: 32px;
        }

        .charts-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }

        .chart-widget {
            background: linear-gradient(135deg, #2c3039 0%, #363a44 100%);
            border: 1px solid rgba(71, 85, 105, 0.4);
            border-radius: 16px;
            padding: 20px;
            transition: all 0.25s ease;
        }

        .chart-widget:hover {
            border-color: rgba(71, 85, 105, 0.6);
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
        }

        .chart-widget.full-width {
            grid-column: 1 / -1;
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }

        .chart-title {
            font-size: 0.9375rem;
            font-weight: 600;
            color: #e2e8f0;
        }

        .chart-badge {
            font-size: 0.75rem;
            padding: 4px 10px;
            border-radius: 20px;
            background: rgba(59, 130, 246, 0.15);
            color: #60a5fa;
            font-weight: 500;
        }

        .chart-container {
            position: relative;
            height: 240px;
        }

        .chart-container.small {
            height: 200px;
        }

        /* ========================================
           Bottom Section - Activity & Alerts
           ======================================== */
        .bottom-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .panel-card {
            background: linear-gradient(135deg, #2c3039 0%, #363a44 100%);
            border: 1px solid rgba(71, 85, 105, 0.4);
            border-radius: 16px;
            padding: 20px;
            transition: all 0.25s ease;
        }

        .panel-card:hover {
            border-color: rgba(71, 85, 105, 0.6);
        }

        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }

        .panel-title {
            font-size: 0.9375rem;
            font-weight: 600;
            color: #e2e8f0;
        }

        .panel-body {
            max-height: 280px;
            overflow-y: auto;
        }

        .activity-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .activity-item {
            padding: 12px;
            background: rgba(15, 23, 42, 0.4);
            border-radius: 10px;
            font-size: 0.875rem;
            border-left: 3px solid #3b82f6;
            transition: all 0.2s ease;
        }

        .activity-item:hover {
            background: rgba(15, 23, 42, 0.6);
        }

        .activity-item.rental { border-left-color: #3b82f6; }
        .activity-item.maintenance { border-left-color: #f59e0b; }
        .activity-item.alert { border-left-color: #ef4444; }

        .activity-meta {
            font-size: 0.75rem;
            color: #64748b;
            margin-top: 4px;
        }

        .alert-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .alert-item {
            padding: 12px;
            border-radius: 10px;
            font-size: 0.875rem;
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }

        .alert-item.danger {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.2);
            color: #fca5a5;
        }

        .alert-item.warning {
            background: rgba(245, 158, 11, 0.1);
            border: 1px solid rgba(245, 158, 11, 0.2);
            color: #fcd34d;
        }

        .alert-item.info {
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid rgba(59, 130, 246, 0.2);
            color: #93c5fd;
        }

        .alert-icon {
            flex-shrink: 0;
            margin-top: 2px;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #64748b;
        }

        .empty-state i {
            font-size: 2rem;
            margin-bottom: 12px;
            opacity: 0.5;
        }

        /* ========================================
           Responsive Design
           ======================================== */
        @media (max-width: 1200px) {
            .charts-grid {
                grid-template-columns: 1fr;
            }
            
            .chart-widget.full-width {
                grid-column: 1;
            }
            
            .bottom-section {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .dashboard-container {
                padding: 16px;
            }
            
            .kpi-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .forecast-card {
                flex-direction: column;
                text-align: center;
                gap: 20px;
            }
            
            .forecast-stat {
                border-right: none;
                border-bottom: 1px solid rgba(71, 85, 105, 0.3);
                padding-right: 0;
                padding-bottom: 20px;
                flex-direction: column;
            }
            
            .chart-container {
                height: 200px;
            }
        }

        @media (max-width: 480px) {
            .kpi-grid {
                grid-template-columns: 1fr;
            }
            
            .dashboard-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 16px;
            }
        }

        /* ========================================
           Scrollbar Styling
           ======================================== */
        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }

        ::-webkit-scrollbar-track {
            background: rgba(15, 23, 42, 0.3);
            border-radius: 3px;
        }

        ::-webkit-scrollbar-thumb {
            background: #475569;
            border-radius: 3px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: #64748b;
        }
    </style>
</head>
<body>
<?php include __DIR__.'/includes/navbar.php'; ?>

<div class="dashboard-container">
    <!-- Header -->
    <div class="dashboard-header">
        <div>
            <h1 class="dashboard-title">Fleet Analytics Dashboard</h1>
            <div class="header-subtitle">Real-time insights and trends for your fleet</div>
        </div>
        <button class="refresh-btn" onclick="location.reload()">
            <i class="fas fa-sync-alt"></i>
            <span>Refresh Data</span>
        </button>
    </div>

    <!-- Forecast Insight Section -->
    <section class="forecast-section">
        <div class="section-header">
            <h2 class="section-title"><i class="fas fa-chart-line"></i> Insight</h2>
            <div class="section-divider"></div>
        </div>
        
        <div class="forecast-card">
            <div class="forecast-stat">
                <div class="forecast-icon">
                    <i class="fas fa-bullseye"></i>
                </div>
                <div>
                    <div class="forecast-value"><?= $forecastValue ?></div>
                    <div class="forecast-label">Predicted Rentals Next Month</div>
                </div>
            </div>
            <div class="forecast-insight">
                <div class="insight-title">Rental Demand Forecast</div>
                <div class="insight-text">
                    Based on historical averages, expected rental demand for <span class="insight-highlight"><?= date('F Y', strtotime('+1 month')) ?></span> 
                    is <span class="insight-highlight"><?= $forecastValue ?> bookings</span>. 
                    Peak month was <span class="insight-highlight"><?= $peakMonthLabel ?></span> with <?= $peakMonthCount ?> rentals.
                </div>
            </div>
        </div>
    </section>

    <!-- Trend Analysis Section -->
    <section class="trend-section">
        <div class="section-header">
            <h2 class="section-title"><i class="fas fa-chart-bar"></i> Trend Analysis</h2>
            <div class="section-divider"></div>
        </div>

        <div class="charts-grid">
            <!-- Monthly Rental Demand Chart -->
            <div class="chart-widget">
                <div class="chart-header">
                    <h3 class="chart-title">Monthly Rental Demand</h3>
                    <span class="chart-badge">12 Months</span>
                </div>
                <div class="chart-container">
                    <canvas id="rentalDemandChart"></canvas>
                </div>
            </div>

            <!-- Monthly Revenue Trend Chart -->
            <div class="chart-widget">
                <div class="chart-header">
                    <h3 class="chart-title">Monthly Revenue Trend</h3>
                    <span class="chart-badge">Revenue</span>
                </div>
                <div class="chart-container">
                    <canvas id="revenueTrendChart"></canvas>
                </div>
            </div>

            <!-- Maintenance Frequency Trend -->
            <div class="chart-widget">
                <div class="chart-header">
                    <h3 class="chart-title">Maintenance Frequency Trend</h3>
                    <span class="chart-badge">Maintenance</span>
                </div>
                <div class="chart-container">
                    <canvas id="maintenanceTrendChart"></canvas>
                </div>
            </div>

            <!-- Peak Rental Days -->
            <div class="chart-widget">
                <div class="chart-header">
                    <h3 class="chart-title">Peak Rental Days</h3>
                    <span class="chart-badge">By Day of Week</span>
                </div>
                <div class="chart-container small">
                    <canvas id="peakDaysChart"></canvas>
                </div>
            </div>

            <!-- Most Rented Vehicles - Full Width -->
            <div class="chart-widget full-width">
                <div class="chart-header">
                    <h3 class="chart-title">Most Rented Vehicles</h3>
                    <span class="chart-badge">Top 10</span>
                </div>
                <div class="chart-container small">
                    <canvas id="mostRentedChart"></canvas>
                </div>
            </div>
        </div>
    </section>

    <!-- Bottom Section: Recent Activity & Alerts -->
    <section class="bottom-section">
        <!-- Recent Activity Panel -->
        <div class="panel-card">
            <div class="panel-header">
                <h3 class="panel-title"><i class="fas fa-history"></i> Recent Activity</h3>
                <a href="rentals_all.php" style="font-size: 0.8rem; color: #60a5fa; text-decoration: none;">View All</a>
            </div>
            <div class="panel-body">
                <div class="activity-list">
                    <?php if (empty($recentRentals)): ?>
                        <div class="empty-state">
                            <i class="fas fa-inbox"></i>
                            <div>No recent activity</div>
                        </div>
                    <?php else: ?>
                        <?php foreach (array_slice($recentRentals, 0, 5) as $rental): ?>
                            <div class="activity-item rental">
                                <div>
                                    <strong>Rental #<?= $rental['id'] ?></strong> — 
                                    <?= h(($rental['first_name'] ?? '') . ' ' . ($rental['last_name'] ?? '')) ?>
                                    rented <strong><?= h($rental['make_model'] ?? 'Unknown') ?></strong>
                                </div>
                                <div class="activity-meta">
                                    <?= isset($rental['start_date']) ? date('M d, Y', strtotime($rental['start_date'])) : 'Unknown date' ?> • 
                                    Status: <?= ucfirst($rental['status'] ?? 'unknown') ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- System Alerts Panel -->
        <div class="panel-card">
            <div class="panel-header">
                <h3 class="panel-title"><i class="fas fa-bell"></i> System Alerts</h3>
                <span style="font-size: 0.8rem; color: #94a3b8;"><?= $overdueRentals + $maintDue ?> active</span>
            </div>
            <div class="panel-body">
                <div class="alert-list">
                    <?php if ($overdueRentals == 0 && $maintDue == 0): ?>
                        <div class="empty-state">
                            <i class="fas fa-check-circle" style="color: #34d399;"></i>
                            <div>All systems operational</div>
                        </div>
                    <?php endif; ?>

                    <?php if ($overdueRentals > 0): ?>
                        <div class="alert-item danger">
                            <div class="alert-icon"><i class="fas fa-exclamation-triangle"></i></div>
                            <div>
                                <strong>Overdue Rentals</strong>
                                <div style="font-size: 0.8rem; margin-top: 2px;"><?= $overdueRentals ?> rental(s) past due date</div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($maintDue > 0): ?>
                        <div class="alert-item warning">
                            <div class="alert-icon"><i class="fas fa-wrench"></i></div>
                            <div>
                                <strong>Maintenance Due Today</strong>
                                <div style="font-size: 0.8rem; margin-top: 2px;"><?= $maintDue ?> vehicle(s) scheduled</div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($availableToday < 3 && $totalVehicles > 0): ?>
                        <div class="alert-item info">
                            <div class="alert-icon"><i class="fas fa-info-circle"></i></div>
                            <div>
                                <strong>Low Availability</strong>
                                <div style="font-size: 0.8rem; margin-top: 2px;">Only <?= $availableToday ?> vehicle(s) available</div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>
</div>

<script>
// Chart Configuration
Chart.defaults.color = '#94a3b8';
Chart.defaults.borderColor = 'rgba(71, 85, 105, 0.3)';
Chart.defaults.font.family = "'Inter', -apple-system, BlinkMacSystemFont, sans-serif";

// Data from PHP
const monthlyDemandData = <?= json_encode($monthlyDemandData) ?>;
const monthlyRevenueData = <?= json_encode($monthlyRevenueData) ?>;
const maintenanceTrendData = <?= json_encode($maintenanceTrendData) ?>;
const peakDaysData = <?= json_encode($peakDaysData) ?>;
const mostRentedData = <?= json_encode($mostRentedData) ?>;
const dailyRevenueData = <?= json_encode($dailyRevenueData) ?>;

// 1. Monthly Rental Demand Chart
const rentalDemandCtx = document.getElementById('rentalDemandChart');
if (rentalDemandCtx && monthlyDemandData.length > 0) {
    new Chart(rentalDemandCtx, {
        type: 'line',
        data: {
            labels: monthlyDemandData.map(d => {
                const [year, month] = d.month.split('-');
                return `${month}/${year.slice(2)}`;
            }),
            datasets: [{
                label: 'Rentals',
                data: monthlyDemandData.map(d => d.count),
                borderColor: '#3b82f6',
                backgroundColor: 'rgba(59, 130, 246, 0.1)',
                fill: true,
                tension: 0.4,
                pointBackgroundColor: '#3b82f6',
                pointBorderColor: '#1e293b',
                pointBorderWidth: 2,
                pointRadius: 4,
                pointHoverRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(71, 85, 105, 0.2)' },
                    ticks: { color: '#64748b', font: { size: 11 } }
                },
                x: {
                    grid: { display: false },
                    ticks: { color: '#64748b', font: { size: 10 } }
                }
            }
        }
    });
} else if (rentalDemandCtx) {
    rentalDemandCtx.parentElement.innerHTML = '<div class="empty-state"><i class="fas fa-chart-line"></i><div>No rental data available</div></div>';
}

// 2. Monthly Revenue Trend Chart
const revenueTrendCtx = document.getElementById('revenueTrendChart');
if (revenueTrendCtx && monthlyRevenueData.length > 0) {
    new Chart(revenueTrendCtx, {
        type: 'line',
        data: {
            labels: monthlyRevenueData.map(d => {
                const [year, month] = d.month.split('-');
                return `${month}/${year.slice(2)}`;
            }),
            datasets: [{
                label: 'Revenue (₱)',
                data: monthlyRevenueData.map(d => d.revenue),
                borderColor: '#10b981',
                backgroundColor: 'rgba(16, 185, 129, 0.1)',
                fill: true,
                tension: 0.4,
                pointBackgroundColor: '#10b981',
                pointBorderColor: '#1e293b',
                pointBorderWidth: 2,
                pointRadius: 4,
                pointHoverRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: (context) => '₱' + context.parsed.y.toLocaleString()
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(71, 85, 105, 0.2)' },
                    ticks: {
                        color: '#64748b',
                        font: { size: 11 },
                        callback: (value) => '₱' + (value / 1000).toFixed(0) + 'k'
                    }
                },
                x: {
                    grid: { display: false },
                    ticks: { color: '#64748b', font: { size: 10 } }
                }
            }
        }
    });
} else if (revenueTrendCtx) {
    revenueTrendCtx.parentElement.innerHTML = '<div class="empty-state"><i class="fas fa-chart-line"></i><div>No revenue data available</div></div>';
}

// 3. Maintenance Frequency Trend Chart
const maintenanceTrendCtx = document.getElementById('maintenanceTrendChart');
if (maintenanceTrendCtx && maintenanceTrendData.length > 0) {
    new Chart(maintenanceTrendCtx, {
        type: 'bar',
        data: {
            labels: maintenanceTrendData.map(d => {
                const [year, month] = d.month.split('-');
                return `${month}/${year.slice(2)}`;
            }),
            datasets: [{
                label: 'Maintenance Events',
                data: maintenanceTrendData.map(d => d.count),
                backgroundColor: 'rgba(245, 158, 11, 0.7)',
                borderColor: '#f59e0b',
                borderWidth: 1,
                borderRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(71, 85, 105, 0.2)' },
                    ticks: { color: '#64748b', font: { size: 11 } }
                },
                x: {
                    grid: { display: false },
                    ticks: { color: '#64748b', font: { size: 10 } }
                }
            }
        }
    });
} else if (maintenanceTrendCtx) {
    maintenanceTrendCtx.parentElement.innerHTML = '<div class="empty-state"><i class="fas fa-wrench"></i><div>No maintenance data available</div></div>';
}

// 4. Peak Rental Days Chart
const peakDaysCtx = document.getElementById('peakDaysChart');
if (peakDaysCtx && peakDaysData.length > 0) {
    const dayOrder = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    const sortedData = peakDaysData.sort((a, b) => dayOrder.indexOf(a.day_name) - dayOrder.indexOf(b.day_name));
    
    new Chart(peakDaysCtx, {
        type: 'bar',
        data: {
            labels: sortedData.map(d => d.day_name.slice(0, 3)),
            datasets: [{
                label: 'Rentals',
                data: sortedData.map(d => d.count),
                backgroundColor: [
                    'rgba(139, 92, 246, 0.7)',  // Mon - Purple
                    'rgba(139, 92, 246, 0.7)',
                    'rgba(139, 92, 246, 0.7)',
                    'rgba(139, 92, 246, 0.7)',
                    'rgba(139, 92, 246, 0.8)',  // Fri - Darker
                    'rgba(236, 72, 153, 0.8)', // Sat - Pink
                    'rgba(236, 72, 153, 0.8)'  // Sun - Pink
                ],
                borderRadius: 4,
                borderSkipped: false
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(71, 85, 105, 0.2)' },
                    ticks: { color: '#64748b', font: { size: 11 } }
                },
                x: {
                    grid: { display: false },
                    ticks: { color: '#64748b', font: { size: 10 } }
                }
            }
        }
    });
} else if (peakDaysCtx) {
    peakDaysCtx.parentElement.innerHTML = '<div class="empty-state"><i class="fas fa-calendar"></i><div>No data available</div></div>';
}

// 5. Most Rented Vehicles Chart (Horizontal Bar)
const mostRentedCtx = document.getElementById('mostRentedChart');
if (mostRentedCtx && mostRentedData.length > 0) {
    new Chart(mostRentedCtx, {
        type: 'bar',
        data: {
            labels: mostRentedData.map(d => d.make_model.length > 25 ? d.make_model.slice(0, 25) + '...' : d.make_model),
            datasets: [{
                label: 'Rental Count',
                data: mostRentedData.map(d => d.rental_count),
                backgroundColor: 'rgba(6, 182, 212, 0.7)',
                borderColor: '#06b6d4',
                borderWidth: 1,
                borderRadius: 4
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                x: {
                    beginAtZero: true,
                    grid: { color: 'rgba(71, 85, 105, 0.2)' },
                    ticks: { color: '#64748b', font: { size: 11 } }
                },
                y: {
                    grid: { display: false },
                    ticks: { 
                        color: '#94a3b8', 
                        font: { size: 10 },
                        autoSkip: false
                    }
                }
            }
        }
    });
} else if (mostRentedCtx) {
    mostRentedCtx.parentElement.innerHTML = '<div class="empty-state"><i class="fas fa-car"></i><div>No vehicle rental data available</div></div>';
}
</script>
</body>
</html>
<?php 
} // Close the if (!isset($_GET['ajax'])) condition
$conn->close(); 
?>
