<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/forecast_utilization.php';

function h($v) {
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function scalar(mysqli $conn, string $sql) {
  $res = $conn->query($sql);
  if (!$res) { return 0; }
  $row = $res->fetch_row();
  return $row ? ($row[0] ?? 0) : 0;
}

$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-t');
$type = $_GET['type'] ?? 'All';

// ===== AJAX endpoints =====
if (isset($_GET['ajax'])) {
  $action = $_GET['ajax'];

  if ($action === 'export_data') {
    $exportType = $_GET['type'] ?? 'rentals';
    $fromDate = $_GET['from'] ?? date('Y-m-01');
    $toDate = $_GET['to'] ?? date('Y-m-t');

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="fleetgo_report_' . $exportType . '_' . $fromDate . '_to_' . $toDate . '.csv"');

    if ($exportType === 'rentals') {
      $data = $conn->query("SELECT r.id, v.make_model, v.plate_no, u.full_name AS customer, r.start_date, r.end_date, r.status, r.total_cost, r.downpayment, r.balance_due FROM rentals r JOIN vehicles v ON v.id = r.vehicle_id JOIN users u ON u.id = r.customer_id WHERE DATE(r.start_date) BETWEEN '$fromDate' AND '$toDate' ORDER BY r.id DESC");
      echo "ID,Vehicle,Plate,Customer,Start Date,End Date,Status,Total Cost,Downpayment,Balance Due\n";
      while ($row = $data->fetch_assoc()) {
        echo implode(',', array_map(fn($v) => '"' . str_replace('"', '""', (string)$v) . '"', $row)) . "\n";
      }
    } elseif ($exportType === 'maintenance') {
      $data = $conn->query("SELECT m.id, v.make_model, v.plate_no, m.maintenance_category AS maintenance_category, m.schedule_date, (COALESCE(m.cost,0)+COALESCE(m.estimated_cost,0)+COALESCE(m.washing_cost,0)) AS total_cost, m.status, m.notes FROM maintenance m JOIN vehicles v ON v.id = m.vehicle_id WHERE DATE(m.schedule_date) BETWEEN '$fromDate' AND '$toDate' ORDER BY m.id DESC");
      echo "ID,Vehicle,Plate,Category,Schedule Date,Total Cost,Status,Notes\n";
      while ($row = $data->fetch_assoc()) {
        echo implode(',', array_map(fn($v) => '"' . str_replace('"', '""', (string)$v) . '"', $row)) . "\n";
      }
    } elseif ($exportType === 'returns') {
      $data = $conn->query("SELECT ri.rental_id, v.make_model, v.plate_no, u.full_name AS customer, ri.fuel_level, ri.cleanliness, ri.carwash_fee, ri.damage_fee, rr.penalty_amount, rr.final_cost, ri.created_at FROM return_inspections ri LEFT JOIN rental_returns rr ON rr.rental_id = ri.rental_id JOIN rentals r ON r.id = ri.rental_id JOIN vehicles v ON v.id = r.vehicle_id JOIN users u ON u.id = r.customer_id WHERE DATE(ri.created_at) BETWEEN '$fromDate' AND '$toDate' ORDER BY ri.created_at DESC");
      echo "Rental ID,Vehicle,Plate,Customer,Fuel Level,Cleanliness,Carwash Fee,Damage Fee,Penalty,Final Cost,Return Date\n";
      while ($row = $data->fetch_assoc()) {
        echo implode(',', array_map(fn($v) => '"' . str_replace('"', '""', (string)$v) . '"', $row)) . "\n";
      }
    }
    exit;
  }

  if ($action === 'quick_stats') {
    $fromDate = $_GET['from'] ?? date('Y-m-01');
    $toDate = $_GET['to'] ?? date('Y-m-t');

    $fleetTotalAjax = (int)scalar($conn, "SELECT COUNT(*) FROM vehicles");
    $availableCountAjax = (int)scalar($conn, "SELECT COUNT(*) FROM vehicles WHERE current_status='available'");
    $availabilityRateAjax = $fleetTotalAjax > 0 ? (($availableCountAjax / $fleetTotalAjax) * 100) : 0;

    $maintenanceBacklogAjax = (int)scalar($conn, "SELECT COUNT(*) FROM maintenance WHERE status <> 'completed'");
    $avgRentalDaysAjax = (float)scalar($conn, "SELECT AVG(DATEDIFF(end_date, start_date) + 1) FROM rentals WHERE DATE(start_date) BETWEEN '$fromDate' AND '$toDate'");
    if ($avgRentalDaysAjax <= 0) { $avgRentalDaysAjax = 0; }

    $avgUtilizationAjax = 0;
    if (!empty($utilizationTable)) {
      $sum = 0;
      $n = 0;
      foreach ($utilizationTable as $row) {
        $sum += (float)$row['utilization_rate'];
        $n++;
      }
      $avgUtilizationAjax = $n > 0 ? ($sum / $n) : 0;
    }

    echo json_encode([
      'availability_rate' => $availabilityRateAjax,
      'avg_utilization_rate' => $avgUtilizationAjax,
      'maintenance_backlog' => $maintenanceBacklogAjax,
      'avg_rental_days' => $avgRentalDaysAjax,
    ]);
    exit;
  }
}

// ===== KPIs =====
$fleetTotal = (int)scalar($conn, "SELECT COUNT(*) FROM vehicles");
$availableCount = (int)scalar($conn, "SELECT COUNT(*) FROM vehicles WHERE current_status='available'");
$maintenanceBacklogCount = (int)scalar($conn, "SELECT COUNT(*) FROM maintenance WHERE status <> 'completed'");
$activeRentalsCount = (int)scalar($conn, "SELECT COUNT(*) FROM rentals WHERE status IN ('ongoing','reserved') AND DATE(start_date) <= '$to' AND DATE(end_date) >= '$from'");

$avgRentalDays = (float)scalar($conn, "SELECT AVG(DATEDIFF(end_date, start_date) + 1) FROM rentals WHERE DATE(start_date) BETWEEN '$from' AND '$to'");
if ($avgRentalDays <= 0) { $avgRentalDays = 0; }

$availabilityRate = $fleetTotal > 0 ? (($availableCount / $fleetTotal) * 100) : 0;

$avgUtilizationRate = 0;
if (!empty($utilizationTable)) {
  $sum = 0;
  $n = 0;
  foreach ($utilizationTable as $row) {
    $sum += (float)$row['utilization_rate'];
    $n++;
  }
  $avgUtilizationRate = $n > 0 ? ($sum / $n) : 0;
}

$fleetStatusData = [];
$res = $conn->query("SELECT current_status, COUNT(*) AS count FROM vehicles GROUP BY current_status ORDER BY count DESC");
while ($row = $res->fetch_assoc()) {
  $fleetStatusData[] = ['status' => $row['current_status'] ?: 'unknown', 'count' => (int)$row['count']];
}

$maintenanceBacklogByStatus = [];
$res = $conn->query("SELECT status, COUNT(*) AS count FROM maintenance GROUP BY status ORDER BY count DESC");
while ($row = $res->fetch_assoc()) {
  $maintenanceBacklogByStatus[] = ['status' => $row['status'] ?: 'unknown', 'count' => (int)$row['count']];
}

$topUtilizationVehicles = [];
if (!empty($utilizationTable)) {
  $topUtilizationVehicles = array_slice($utilizationTable, 0, 10);
}

// ===== Tables =====
$rentals = $conn->query("SELECT r.id, v.make_model, v.plate_no, u.full_name AS customer_name, r.start_date, r.end_date, r.total_cost, r.downpayment, r.balance_due, r.status FROM rentals r JOIN vehicles v ON v.id=r.vehicle_id JOIN users u ON u.id=r.customer_id WHERE DATE(r.start_date) BETWEEN '$from' AND '$to' ORDER BY r.id DESC");
$maintenance = $conn->query("SELECT m.id, v.make_model, v.plate_no, m.maintenance_category, m.schedule_date, (COALESCE(m.cost,0)+COALESCE(m.estimated_cost,0)+COALESCE(m.washing_cost,0)) AS total_cost, m.status FROM maintenance m JOIN vehicles v ON v.id=m.vehicle_id WHERE DATE(m.schedule_date) BETWEEN '$from' AND '$to' ORDER BY m.id DESC");
$returnInspections = $conn->query("SELECT ri.rental_id, ri.fuel_level, ri.cleanliness, ri.carwash_fee, ri.damage_fee, ri.created_at, rr.actual_return_date, rr.actual_return_time, rr.return_condition, rr.penalty_amount, rr.final_cost, v.make_model, v.plate_no, u.full_name AS customer_name, r.start_date, r.end_date FROM return_inspections ri LEFT JOIN rental_returns rr ON rr.rental_id=ri.rental_id JOIN rentals r ON r.id=ri.rental_id JOIN vehicles v ON v.id=r.vehicle_id JOIN users u ON u.id=r.customer_id WHERE DATE(ri.created_at) BETWEEN '$from' AND '$to' ORDER BY ri.created_at DESC");

$forecastDisplayMonth = date('F Y', strtotime('+1 month'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>FleetGo Reports & Analytics</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
:root {
  --bg: #0b0d10;
  --card: #101419;
  --card-hover: #1a1d26;
  --brand: #5dd0ff;
  --brand2: #7cffc7;
  --accent: #6366f1;
  --success: #10b981;
  --warning: #f59e0b;
  --error: #ef4444;
  --text-primary: #f2f6fa;
  --text-secondary: #9ca3af;
  --text-muted: #6b7280;
  --border: rgba(255,255,255,0.08);
  --border-hover: rgba(255,255,255,0.15);
  --radius: 16px;
  --radius-sm: 10px;
  --shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
  --shadow-soft: 0 2px 12px rgba(0, 0, 0, 0.2);
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
  font-family: 'Inter', system-ui, -apple-system, sans-serif;
  background: var(--bg);
  color: var(--text-primary);
  line-height: 1.6;
  min-height: 100vh;
}
.reports-container { max-width: 1600px; margin: 0 auto; padding: 24px 32px; }
.reports-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:28px; padding-bottom:20px; border-bottom:1px solid var(--border); }
.reports-title { font-size:1.75rem; font-weight:700; background: linear-gradient(135deg, var(--brand), var(--brand2)); -webkit-background-clip:text; -webkit-text-fill-color:transparent; background-clip:text; letter-spacing:-0.02em; }
.reports-subtitle { color: var(--text-secondary); font-size:0.875rem; margin-top:4px; }
.header-actions { display:flex; gap:12px; }
.card { background: linear-gradient(135deg, #161a20 0%, #1c2128 100%); border:1px solid var(--border); border-radius: var(--radius); padding:24px; box-shadow: var(--shadow-soft); transition: all 0.25s ease; }
.card:hover { border-color: var(--border-hover); box-shadow: var(--shadow); }
.section-header { display:flex; align-items:center; gap:12px; margin-bottom:20px; }
.section-title { font-size:1.125rem; font-weight:600; color:var(--text-primary); display:flex; align-items:center; gap:10px; }
.section-icon { width:36px; height:36px; border-radius:10px; display:flex; align-items:center; justify-content:center; background: linear-gradient(135deg, var(--brand), var(--brand2)); color:#04121b; font-size:0.9rem; }
.section-divider { flex:1; height:1px; background: linear-gradient(90deg, var(--border), transparent); }
.filters-card { margin-bottom:28px; padding:20px 24px; }
.filters-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:16px; align-items:end; }
.filter-group { display:flex; flex-direction:column; gap:6px; }
.filter-label { font-size:0.75rem; font-weight:500; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.5px; }
input, select { background: var(--bg); color: var(--text-primary); border:1px solid var(--border); padding:12px 16px; border-radius: var(--radius-sm); font-family:inherit; font-size:0.875rem; transition: all 0.2s ease; width:100%; }
input:focus, select:focus { outline:none; border-color: var(--brand); box-shadow:0 0 0 3px rgba(93, 208, 255, 0.1); }
.btn { display:inline-flex; align-items:center; justify-content:center; gap:8px; padding:12px 20px; border-radius: var(--radius-sm); font-family:inherit; font-size:0.875rem; font-weight:600; cursor:pointer; transition: all 0.2s ease; border:none; }
.btn-primary { background: linear-gradient(135deg, var(--brand), var(--brand2)); color:#04121b; }
.btn-secondary { background: var(--accent); color:white; }
.btn-outline { background: transparent; border:1px solid var(--border); color:var(--text-secondary); }
.btn-sm { padding:8px 14px; font-size:0.8rem; }
.quick-filters { display:flex; gap:8px; flex-wrap:wrap; margin-top:16px; }
.summary-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap:16px; margin-bottom:28px; }
.summary-card { background: linear-gradient(135deg, #161a20 0%, #1c2128 100%); border:1px solid var(--border); border-radius: var(--radius); padding:20px; display:flex; flex-direction:column; transition: all 0.25s ease; }
.summary-card.primary { background: linear-gradient(135deg, rgba(93, 208, 255, 0.08), rgba(124, 255, 199, 0.05)); border-color: rgba(93, 208, 255, 0.3); }
.summary-header { display:flex; align-items:center; gap:12px; margin-bottom:12px; }
.summary-icon { width:44px; height:44px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:1.2rem; background: linear-gradient(135deg, var(--brand), var(--brand2)); color:#04121b; }
.summary-title { font-size:0.75rem; font-weight:500; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.5px; }
.summary-value { font-size:1.5rem; font-weight:700; color:var(--brand2); margin-top:4px; line-height:1.2; }
.forecast-section { margin-bottom:28px; }
.forecast-card { display:flex; align-items:center; gap:32px; padding:28px 32px; }
.forecast-stat { display:flex; align-items:center; gap:20px; padding-right:32px; border-right:1px solid var(--border); }
.forecast-icon { width:64px; height:64px; border-radius:16px; background: linear-gradient(135deg, var(--accent), #8b5cf6); display:flex; align-items:center; justify-content:center; font-size:1.75rem; color:white; flex-shrink:0; }
.forecast-value { font-size:2.5rem; font-weight:800; color:var(--text-primary); line-height:1; }
.forecast-label { font-size:0.875rem; color:var(--text-secondary); margin-top:6px; }
.insight-title { font-size:1rem; font-weight:600; color:var(--text-primary); margin-bottom:8px; }
.insight-text { font-size:0.9375rem; color:var(--text-secondary); line-height:1.6; }
.insight-highlight { color:var(--brand2); font-weight:600; }
.trend-section { margin-bottom:28px; }
.charts-grid { display:grid; grid-template-columns: repeat(2, 1fr); gap:20px; }
.chart-widget { background: linear-gradient(135deg, #161a20 0%, #1c2128 100%); border:1px solid var(--border); border-radius: var(--radius); padding:20px; transition: all 0.25s ease; }
.chart-widget.full-width { grid-column: 1 / -1; }
.chart-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; }
.chart-title { font-size:0.9375rem; font-weight:600; color:var(--text-primary); }
.chart-badge { font-size:0.7rem; padding:4px 10px; border-radius:20px; background: rgba(93, 208, 255, 0.12); color: var(--brand); font-weight:500; }
.chart-container { position:relative; height:240px; }
.chart-container.small { height:200px; }
.financial-charts { display:grid; grid-template-columns: 2fr 1fr; gap:20px; }
.data-section { margin-bottom:28px; }
.table-container { background: linear-gradient(135deg, #161a20 0%, #1c2128 100%); border:1px solid var(--border); border-radius: var(--radius); overflow:hidden; box-shadow: var(--shadow-soft); }
.data-table { width:100%; border-collapse:collapse; font-size:0.875rem; }
.data-table th { background: rgba(0, 0, 0, 0.3); color: var(--brand2); font-weight:600; text-transform:uppercase; letter-spacing:0.5px; padding:16px; text-align:left; border-bottom:1px solid var(--border); font-size:0.7rem; }
.data-table td { padding:14px 16px; border-bottom:1px solid var(--border); color: var(--text-primary); }
.data-table tr:hover { background: rgba(255, 255, 255, 0.02); }
.status-badge { display:inline-flex; align-items:center; padding:5px 12px; border-radius:20px; font-size:0.7rem; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; }
.status-completed { background: rgba(16, 185, 129, 0.12); color: var(--success); border:1px solid rgba(16, 185, 129, 0.2); }
.status-ongoing, .status-reserved { background: rgba(245, 158, 11, 0.12); color: var(--warning); border:1px solid rgba(245, 158, 11, 0.2); }
.status-pending { background: rgba(99, 102, 241, 0.12); color: var(--accent); border:1px solid rgba(99, 102, 241, 0.2); }
.status-cancelled { background: rgba(239, 68, 68, 0.12); color: var(--error); border:1px solid rgba(239, 68, 68, 0.2); }
.export-section { margin-top:32px; }
.export-grid { display:flex; gap:12px; flex-wrap:wrap; }
.modal { display:none; position:fixed; inset:0; background: rgba(0,0,0,0.85); z-index:10000; backdrop-filter: blur(5px); }
.modal.active { display:flex; align-items:center; justify-content:center; padding:20px; }
.modal-content { background: var(--card); border:1px solid var(--border); border-radius: var(--radius); width:100%; max-width: 1000px; max-height: 85vh; overflow-y:auto; box-shadow: var(--shadow); }
.modal-header { padding:20px 24px; border-bottom:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; }
.modal-title { font-size:1.25rem; font-weight:600; color:var(--text-primary); display:flex; align-items:center; gap:10px; }
.modal-close { background:none; border:none; color:var(--text-secondary); font-size:1.5rem; cursor:pointer; width:36px; height:36px; border-radius:8px; display:flex; align-items:center; justify-content:center; }
.modal-body { padding:24px; }
.metrics-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:16px; }
.metric-card { background: var(--bg); border:1px solid var(--border); border-radius: var(--radius-sm); padding:18px; position:relative; overflow:hidden; }
.metric-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; background: linear-gradient(90deg, var(--brand), var(--brand2)); }
.metric-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; }
.metric-label { font-size:0.7rem; font-weight:500; color: var(--text-secondary); text-transform: uppercase; letter-spacing:0.5px; }
.metric-value { font-size:1.5rem; font-weight:700; color: var(--brand2); margin:0; }
.metric-change { font-size:0.75rem; font-weight:500; margin-top:6px; }
.metric-change.positive { color: var(--success); }
.metric-change.negative { color: var(--error); }
.toast { position: fixed; top: 20px; right: 20px; padding: 14px 18px; border-radius: 10px; color: white; font-weight: 500; font-size: 0.875rem; z-index: 10001; box-shadow: 0 8px 25px rgba(0, 0, 0, 0.4); }
.toast-success { background: linear-gradient(135deg, rgba(16, 185, 129, 0.95), rgba(5, 150, 105, 0.95)); border: 1px solid rgba(16, 185, 129, 0.3); }
.toast-error { background: linear-gradient(135deg, rgba(239, 68, 68, 0.95), rgba(220, 38, 38, 0.95)); border: 1px solid rgba(239, 68, 68, 0.3); }
@media (max-width: 1200px) { .charts-grid { grid-template-columns: 1fr; } .chart-widget.full-width { grid-column: 1; } .financial-charts { grid-template-columns: 1fr; } }
@media (max-width: 768px) { .reports-container { padding: 16px; } .reports-header { flex-direction: column; align-items: flex-start; gap: 16px; } .summary-grid { grid-template-columns: 1fr; } .forecast-card { flex-direction: column; text-align: center; gap: 20px; } .forecast-stat { border-right: none; border-bottom: 1px solid var(--border); padding-right: 0; padding-bottom: 20px; flex-direction: column; } .filters-grid { grid-template-columns: 1fr; } .table-container { overflow-x:auto; } }
</style>
</head>
<body>
<?php include __DIR__ . '/includes/navbar.php'; ?>

<div class="reports-container">
  <div class="reports-header">
    <div>
      <h1 class="reports-title">Operations Performance Reports</h1>
      <p class="reports-subtitle">Operational KPIs and fleet performance summaries for day-to-day management</p>
    </div>
    <div class="header-actions">
      <button class="btn btn-secondary btn-sm" onclick="refreshStats()"><i class="fas fa-sync-alt"></i> Refresh</button>
      <button class="btn btn-outline btn-sm" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
    </div>
  </div>

  <div class="card filters-card">
    <div class="section-header">
      <h2 class="section-title"><span class="section-icon"><i class="fas fa-filter"></i></span>Report Filters</h2>
    </div>
    <form method="get">
      <div class="filters-grid">
        <div class="filter-group">
          <label class="filter-label">From Date</label>
          <input type="date" name="from" id="from" value="<?=h($from)?>">
        </div>
        <div class="filter-group">
          <label class="filter-label">To Date</label>
          <input type="date" name="to" id="to" value="<?=h($to)?>">
        </div>
        <div class="filter-group">
          <label class="filter-label">Report Type</label>
          <select name="type" id="type">
            <option value="All" <?= $type==='All'?'selected':'' ?>>All Reports</option>
            <option value="Rentals" <?= $type==='Rentals'?'selected':'' ?>>Rentals Only</option>
            <option value="Maintenance" <?= $type==='Maintenance'?'selected':'' ?>>Maintenance Only</option>
            <option value="Returns" <?= $type==='Returns'?'selected':'' ?>>Returns Only</option>
          </select>
        </div>
        <div class="filter-group">
          <label class="filter-label">&nbsp;</label>
          <button type="submit" class="btn btn-primary" style="width:100%"><i class="fas fa-chart-line"></i> Generate Report</button>
        </div>
      </div>
      <div class="quick-filters">
        <button type="button" class="btn btn-outline btn-sm" onclick="setQuickFilter('today')">Today</button>
        <button type="button" class="btn btn-outline btn-sm" onclick="setQuickFilter('week')">This Week</button>
        <button type="button" class="btn btn-outline btn-sm" onclick="setQuickFilter('month')">This Month</button>
        <button type="button" class="btn btn-outline btn-sm" onclick="setQuickFilter('year')">This Year</button>
      </div>
    </form>
  </div>

  <div class="summary-grid">
    <div class="summary-card primary">
      <div class="summary-header"><div class="summary-icon"><i class="fas fa-check-circle"></i></div><div><div class="summary-title">Fleet Availability</div><div class="summary-value" id="fleetAvailability"><?=number_format($availabilityRate,1)?>%</div></div></div>
    </div>
    <div class="summary-card">
      <div class="summary-header"><div class="summary-icon"><i class="fas fa-tachometer-alt"></i></div><div><div class="summary-title">Avg Utilization (30d)</div><div class="summary-value" id="avgUtilization"><?=number_format($avgUtilizationRate,0)?>%</div></div></div>
    </div>
    <div class="summary-card">
      <div class="summary-header"><div class="summary-icon"><i class="fas fa-tools"></i></div><div><div class="summary-title">Maintenance Backlog</div><div class="summary-value" id="maintenanceBacklog"><?=number_format($maintenanceBacklogCount)?></div></div></div>
    </div>
    <div class="summary-card">
      <div class="summary-header"><div class="summary-icon"><i class="fas fa-clock"></i></div><div><div class="summary-title">Avg Rental Duration</div><div class="summary-value" id="avgRentalDays"><?=number_format($avgRentalDays,1)?> days</div></div></div>
    </div>
  </div>

  <section class="trend-section">
    <div class="section-header">
      <h2 class="section-title"><span class="section-icon"><i class="fas fa-clipboard-list"></i></span>Operations Overview</h2>
      <div class="section-divider"></div>
      <button class="btn btn-primary btn-sm" onclick="openKPIModal()"><i class="fas fa-th-large"></i> View All Metrics</button>
    </div>
    <div class="charts-grid">
      <div class="chart-widget">
        <div class="chart-header"><h3 class="chart-title">Fleet Status Mix</h3><span class="chart-badge">Current</span></div>
        <div class="chart-container"><canvas id="fleetStatusChart"></canvas></div>
      </div>
      <div class="chart-widget">
        <div class="chart-header"><h3 class="chart-title">Top Utilized Vehicles</h3><span class="chart-badge">30 Days</span></div>
        <div class="chart-container"><canvas id="topUtilizationChart"></canvas></div>
      </div>
      <div class="chart-widget">
        <div class="chart-header"><h3 class="chart-title">Maintenance Workload</h3><span class="chart-badge">By Status</span></div>
        <div class="chart-container"><canvas id="maintenanceWorkloadChart"></canvas></div>
      </div>
    </div>
  </section>

  <section class="data-section">
    <div class="section-header"><h2 class="section-title"><span class="section-icon"><i class="fas fa-tachometer-alt"></i></span>Vehicle Utilization Report (Last 30 Days)</h2></div>
    <div class="table-container">
      <table class="data-table">
        <thead><tr><th>Vehicle</th><th>Rented Days (30d)</th><th>Utilization %</th><th>Status</th><th>Forecast Next Month</th></tr></thead>
        <tbody>
          <?php if(!empty($utilizationTable)): ?>
            <?php foreach($utilizationTable as $row): $status=$row['status_label']; $badgeClass='status-'.strtolower($status); ?>
            <tr>
              <td><strong><?=h($row['identifier'])?></strong></td>
              <td><?=number_format((int)$row['total_rented_days_last30'])?></td>
              <td><?= (int)$row['utilization_rate'] ?>%</td>
              <td><span class="status-badge <?=h($badgeClass)?>"><?=h($status)?></span></td>
              <td><?= (int)$row['forecast_utilization_next'] ?>%</td>
            </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr><td colspan="5" style="text-align:center;padding:40px;color:var(--text-muted)">No utilization data available.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <?php if($type==='All' || $type==='Rentals'): ?>
  <section class="data-section">
    <div class="section-header"><h2 class="section-title"><span class="section-icon"><i class="fas fa-car"></i></span>Rentals Summary</h2></div>
    <div class="table-container">
      <table class="data-table">
        <thead><tr><th>ID</th><th>Vehicle</th><th>Plate</th><th>Customer</th><th>Start</th><th>End</th><th>Total</th><th>Downpayment</th><th>Balance</th><th>Status</th></tr></thead>
        <tbody>
          <?php while($r=$rentals->fetch_assoc()): ?>
          <tr>
            <td><strong>#<?=h($r['id'])?></strong></td>
            <td><?=h($r['make_model'])?></td>
            <td><code style="background:rgba(255,255,255,0.05);padding:2px 6px;border-radius:4px;font-size:0.8rem"><?=h($r['plate_no'])?></code></td>
            <td><?=h($r['customer_name'])?></td>
            <td><?=date('M j, Y', strtotime($r['start_date']))?></td>
            <td><?=date('M j, Y', strtotime($r['end_date']))?></td>
            <td><strong>₱<?=number_format((float)$r['total_cost'],2)?></strong></td>
            <td>₱<?=number_format((float)($r['downpayment']??0),2)?></td>
            <td>₱<?=number_format((float)($r['balance_due']??0),2)?></td>
            <td><span class="status-badge status-<?=h(strtolower($r['status']))?>"><?=h(ucfirst($r['status']))?></span></td>
          </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  </section>
  <?php endif; ?>

  <?php if($type==='All' || $type==='Returns'): ?>
  <section class="data-section">
    <div class="section-header"><h2 class="section-title"><span class="section-icon"><i class="fas fa-clipboard-check"></i></span>Return Inspections</h2></div>
    <div class="table-container">
      <table class="data-table">
        <thead><tr><th>Customer</th><th>Vehicle</th><th>Rental Period</th><th>Return Date</th><th>Fuel</th><th>Condition</th><th>Penalty</th><th>Final Cost</th></tr></thead>
        <tbody>
          <?php while($ri=$returnInspections->fetch_assoc()): ?>
          <tr>
            <td><strong><?=h($ri['customer_name'])?></strong></td>
            <td><?=h($ri['make_model'])?> <code style="font-size:0.75rem">(<?=h($ri['plate_no'])?>)</code></td>
            <td><?=date('M j', strtotime($ri['start_date']))?> - <?=date('M j', strtotime($ri['end_date']))?></td>
            <td><?= $ri['actual_return_date'] ? date('M j, Y', strtotime($ri['actual_return_date'])) : date('M j, Y', strtotime($ri['created_at'])) ?></td>
            <td><?=h($ri['fuel_level'])?></td>
            <td><?=h($ri['return_condition'] ?? '')?></td>
            <td>₱<?=number_format((float)($ri['penalty_amount']??0),2)?></td>
            <td><strong>₱<?=number_format((float)($ri['final_cost']??0),2)?></strong></td>
          </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  </section>
  <?php endif; ?>

  <?php if($type==='All' || $type==='Maintenance'): ?>
  <section class="data-section">
    <div class="section-header"><h2 class="section-title"><span class="section-icon"><i class="fas fa-tools"></i></span>Maintenance Summary</h2></div>
    <div class="table-container">
      <table class="data-table">
        <thead><tr><th>ID</th><th>Vehicle</th><th>Plate</th><th>Category</th><th>Schedule Date</th><th>Total Cost</th><th>Status</th></tr></thead>
        <tbody>
          <?php while($m=$maintenance->fetch_assoc()): ?>
          <tr>
            <td><strong>#<?=h($m['id'])?></strong></td>
            <td><?=h($m['make_model'])?></td>
            <td><code style="background:rgba(255,255,255,0.05);padding:2px 6px;border-radius:4px;font-size:0.8rem"><?=h($m['plate_no'])?></code></td>
            <td><?=h($m['maintenance_category'] ?? 'N/A')?></td>
            <td><?=date('M j, Y', strtotime($m['schedule_date']))?></td>
            <td><strong>₱<?=number_format((float)$m['total_cost'],2)?></strong></td>
            <td><span class="status-badge status-<?=h(strtolower($m['status']))?>"><?=h(ucfirst($m['status']))?></span></td>
          </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  </section>
  <?php endif; ?>

  <section class="export-section card">
    <div class="section-header"><h2 class="section-title"><span class="section-icon"><i class="fas fa-download"></i></span>Export Data</h2></div>
    <div class="export-grid">
      <button class="btn btn-secondary" onclick="exportData('rentals')"><i class="fas fa-file-csv"></i> Export Rentals CSV</button>
      <button class="btn btn-secondary" onclick="exportData('maintenance')"><i class="fas fa-file-csv"></i> Export Maintenance CSV</button>
      <button class="btn btn-secondary" onclick="exportData('returns')"><i class="fas fa-file-csv"></i> Export Returns CSV</button>
      <button class="btn btn-outline" onclick="window.print()"><i class="fas fa-print"></i> Print Full Report</button>
    </div>
  </section>
</div>

<div id="kpiModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h2 class="modal-title"><i class="fas fa-th-large"></i> All Metrics Dashboard</h2>
      <button class="modal-close" onclick="closeKPIModal()">&times;</button>
    </div>
    <div class="modal-body">
      <div class="metrics-grid">
        <div class="metric-card"><div class="metric-header"><span class="metric-label">Fleet Size</span><i class="fas fa-car" style="color:var(--brand)"></i></div><p class="metric-value"><?=number_format($fleetTotal)?></p><div class="metric-change positive">Total vehicles</div></div>
        <div class="metric-card"><div class="metric-header"><span class="metric-label">Available Vehicles</span><i class="fas fa-check" style="color:var(--success)"></i></div><p class="metric-value"><?=number_format($availableCount)?></p><div class="metric-change positive">Ready for rent</div></div>
        <div class="metric-card"><div class="metric-header"><span class="metric-label">Active Rentals</span><i class="fas fa-route" style="color:var(--accent)"></i></div><p class="metric-value"><?=number_format($activeRentalsCount)?></p><div class="metric-change positive">Ongoing/reserved</div></div>
        <div class="metric-card"><div class="metric-header"><span class="metric-label">Availability Rate</span><i class="fas fa-chart-pie" style="color:var(--brand2)"></i></div><p class="metric-value"><?=number_format($availabilityRate,1)?>%</p><div class="metric-change positive">Available / fleet</div></div>
        <div class="metric-card"><div class="metric-header"><span class="metric-label">Avg Utilization (30d)</span><i class="fas fa-tachometer-alt" style="color:var(--warning)"></i></div><p class="metric-value"><?=number_format($avgUtilizationRate,0)?>%</p><div class="metric-change positive">Average by vehicle</div></div>
        <div class="metric-card"><div class="metric-header"><span class="metric-label">Maintenance Backlog</span><i class="fas fa-tools" style="color:var(--error)"></i></div><p class="metric-value"><?=number_format($maintenanceBacklogCount)?></p><div class="metric-change negative">Not completed</div></div>
        <div class="metric-card"><div class="metric-header"><span class="metric-label">Avg Rental Duration</span><i class="fas fa-clock" style="color:var(--accent)"></i></div><p class="metric-value"><?=number_format($avgRentalDays,1)?> days</p><div class="metric-change positive">Within selected range</div></div>
      </div>
    </div>
  </div>
</div>

<script>
const fleetStatusData = <?= json_encode($fleetStatusData) ?>;
const maintenanceBacklogByStatus = <?= json_encode($maintenanceBacklogByStatus) ?>;
const topUtilizationVehicles = <?= json_encode($topUtilizationVehicles) ?>;

Chart.defaults.color = '#9ca3af';
Chart.defaults.borderColor = 'rgba(255,255,255,0.05)';
Chart.defaults.font.family = "'Inter', system-ui, sans-serif";

function showEmpty(canvas, title){
  if(!canvas) return;
  canvas.parentElement.innerHTML = `<div style="text-align:center;padding:48px 24px;color:var(--text-muted)"><div style="font-size:1rem;font-weight:600;color:var(--text-secondary)">${title}</div></div>`;
}

const fleetStatusCtx = document.getElementById('fleetStatusChart');
if(fleetStatusCtx && fleetStatusData.length){
  new Chart(fleetStatusCtx, {
    type: 'doughnut',
    data: {
      labels: fleetStatusData.map(x => x.status),
      datasets: [{
        data: fleetStatusData.map(x => x.count),
        backgroundColor: ['#10b981','#f59e0b','#6366f1','#ef4444','#5dd0ff','#7cffc7','#8b5cf6'],
        borderWidth: 0
      }]
    },
    options: { responsive:true, maintainAspectRatio:false, plugins:{ legend:{ position:'bottom' } } }
  });
} else { showEmpty(fleetStatusCtx, 'No fleet status data available'); }

const topUtilizationCtx = document.getElementById('topUtilizationChart');
if(topUtilizationCtx && topUtilizationVehicles.length){
  new Chart(topUtilizationCtx, {
    type: 'bar',
    data: {
      labels: topUtilizationVehicles.map(x => x.identifier),
      datasets: [{
        label: 'Utilization %',
        data: topUtilizationVehicles.map(x => x.utilization_rate),
        backgroundColor: 'rgba(93, 208, 255, 0.6)',
        borderRadius: 4
      }]
    },
    options: { indexAxis:'y', responsive:true, maintainAspectRatio:false, plugins:{ legend:{ display:false } }, scales:{ x:{ beginAtZero:true, max:100 } } }
  });
} else { showEmpty(topUtilizationCtx, 'No utilization data available'); }

const maintenanceWorkloadCtx = document.getElementById('maintenanceWorkloadChart');
if(maintenanceWorkloadCtx && maintenanceBacklogByStatus.length){
  new Chart(maintenanceWorkloadCtx, {
    type: 'bar',
    data: {
      labels: maintenanceBacklogByStatus.map(x => x.status),
      datasets: [{
        label: 'Tasks',
        data: maintenanceBacklogByStatus.map(x => x.count),
        backgroundColor: 'rgba(245, 158, 11, 0.7)',
        borderRadius: 4
      }]
    },
    options: { responsive:true, maintainAspectRatio:false, plugins:{ legend:{ display:false } }, scales:{ y:{ beginAtZero:true } } }
  });
} else { showEmpty(maintenanceWorkloadCtx, 'No maintenance workload data available'); }

function setQuickFilter(period){
  const today = new Date();
  let from, to;
  switch(period){
    case 'today': from=to=today.toISOString().split('T')[0]; break;
    case 'week': { const ws=new Date(today); ws.setDate(today.getDate()-today.getDay()); from=ws.toISOString().split('T')[0]; to=today.toISOString().split('T')[0]; break; }
    case 'month': from=new Date(today.getFullYear(), today.getMonth(), 1).toISOString().split('T')[0]; to=new Date(today.getFullYear(), today.getMonth()+1, 0).toISOString().split('T')[0]; break;
    case 'year': from=new Date(today.getFullYear(), 0, 1).toISOString().split('T')[0]; to=new Date(today.getFullYear(), 11, 31).toISOString().split('T')[0]; break;
  }
  document.getElementById('from').value = from;
  document.getElementById('to').value = to;
  setTimeout(()=>{ window.location.href = `reports.php?from=${from}&to=${to}&type=${document.getElementById('type').value}`; }, 50);
}

async function refreshStats(){
  const from = document.getElementById('from').value;
  const to = document.getElementById('to').value;
  try {
    const res = await fetch(`reports.php?ajax=quick_stats&from=${from}&to=${to}`);
    const stats = await res.json();
    if(document.getElementById('fleetAvailability')) document.getElementById('fleetAvailability').textContent = (parseFloat(stats.availability_rate||0)).toFixed(1) + '%';
    if(document.getElementById('avgUtilization')) document.getElementById('avgUtilization').textContent = (parseFloat(stats.avg_utilization_rate||0)).toFixed(0) + '%';
    if(document.getElementById('maintenanceBacklog')) document.getElementById('maintenanceBacklog').textContent = parseInt(stats.maintenance_backlog||0).toLocaleString();
    if(document.getElementById('avgRentalDays')) document.getElementById('avgRentalDays').textContent = (parseFloat(stats.avg_rental_days||0)).toFixed(1) + ' days';
    showToast('Stats refreshed successfully', true);
  } catch(e){ showToast('Failed to refresh stats', false); }
}

function exportData(t){
  const from = document.getElementById('from').value;
  const to = document.getElementById('to').value;
  window.open(`reports.php?ajax=export_data&type=${t}&from=${from}&to=${to}`, '_blank');
}

function openKPIModal(){ document.getElementById('kpiModal').classList.add('active'); document.body.style.overflow='hidden'; }
function closeKPIModal(){ document.getElementById('kpiModal').classList.remove('active'); document.body.style.overflow='auto'; }
document.getElementById('kpiModal').addEventListener('click', (e)=>{ if(e.target.id==='kpiModal') closeKPIModal(); });
document.addEventListener('keydown', (e)=>{ if(e.key==='Escape') closeKPIModal(); });

function showToast(message, ok){
  const existing = document.querySelector('.toast');
  if(existing) existing.remove();
  const t = document.createElement('div');
  t.className = 'toast ' + (ok ? 'toast-success' : 'toast-error');
  t.textContent = message;
  document.body.appendChild(t);
  setTimeout(()=>{ t.remove(); }, 2500);
}
</script>

</body>
</html>
<?php $conn->close(); ?>
