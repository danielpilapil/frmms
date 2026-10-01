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

function report_period_labels(): array {
  return [
    '7d' => 'Last 7 days',
    '3m' => 'Last 3 months',
    '6m' => 'Last 6 months',
    '9m' => 'Last 9 months',
    '1y' => 'Last 1 year',
    'custom' => 'Custom range',
  ];
}

function report_normalize_period(string $period, string $default = '3m'): string {
  $p = strtolower(trim($period));
  $aliases = [
    'week' => '7d',
    '30d' => '3m', 'month' => '3m', 'quarter' => '3m',
    '365d' => '1y', 'year' => '1y',
  ];
  $p = $aliases[$p] ?? $p;
  return in_array($p, ['7d', '3m', '6m', '9m', '1y', 'custom'], true) ? $p : $default;
}

function earnings_period_bounds(string $period): array {
  $today = new DateTimeImmutable('today');
  $period = report_normalize_period($period);
  switch ($period) {
    case '7d': $from = $today->modify('-6 days'); break;
    case '6m': $from = $today->modify('-6 months')->modify('+1 day'); break;
    case '9m': $from = $today->modify('-9 months')->modify('+1 day'); break;
    case '1y': $from = $today->modify('-1 year')->modify('+1 day'); break;
    default:
      $period = '3m';
      $from = $today->modify('-3 months')->modify('+1 day');
  }
  return [$period, $from->format('Y-m-d'), $today->format('Y-m-d')];
}

function compute_earnings_dss(mysqli $conn, string $from, string $to, string $period, int $vehicleId = 0): array {
  $fromEsc = $conn->real_escape_string($from);
  $toEsc = $conn->real_escape_string($to);
  $vehClause = $vehicleId > 0 ? (' AND vehicle_id = ' . (int)$vehicleId) : '';
  $vehClauseR = $vehicleId > 0 ? (' AND r.vehicle_id = ' . (int)$vehicleId) : '';

  $summary = [
    'total_rentals' => 0,
    'completed_rentals' => 0,
    'active_rentals' => 0,
    'cancelled_rentals' => 0,
    'total_earnings' => 0.0,
    'completed_earnings' => 0.0,
    'avg_earning' => 0.0,
    'collected_payments' => 0.0,
    'total_expenses' => 0.0,
    'net_income' => 0.0,
    'maintenance_jobs' => 0,
  ];

  $res = $conn->query("
    SELECT
      COUNT(*) AS total_rentals,
      COUNT(CASE WHEN status = 'completed' THEN 1 END) AS completed_rentals,
      COUNT(CASE WHEN status IN ('ongoing','reserved') THEN 1 END) AS active_rentals,
      COUNT(CASE WHEN status = 'cancelled' THEN 1 END) AS cancelled_rentals,
      COALESCE(SUM(CASE WHEN status <> 'cancelled' THEN total_cost ELSE 0 END), 0) AS total_earnings,
      COALESCE(SUM(CASE WHEN status = 'completed' THEN total_cost ELSE 0 END), 0) AS completed_earnings,
      COALESCE(AVG(CASE WHEN status <> 'cancelled' THEN total_cost END), 0) AS avg_earning
    FROM rentals
    WHERE DATE(created_at) BETWEEN '$fromEsc' AND '$toEsc'
    $vehClause
  ");
  if ($res && ($row = $res->fetch_assoc())) {
    $summary['total_rentals'] = (int)$row['total_rentals'];
    $summary['completed_rentals'] = (int)$row['completed_rentals'];
    $summary['active_rentals'] = (int)$row['active_rentals'];
    $summary['cancelled_rentals'] = (int)$row['cancelled_rentals'];
    $summary['total_earnings'] = (float)$row['total_earnings'];
    $summary['completed_earnings'] = (float)$row['completed_earnings'];
    $summary['avg_earning'] = (float)$row['avg_earning'];
  }

  // Maintenance treated as liability / expense for the same period
  $vehClauseM = $vehicleId > 0 ? (' AND m.vehicle_id = ' . (int)$vehicleId) : '';
  $maintCostExpr = "(CASE WHEN COALESCE(m.cost,0) > 0 THEN m.cost ELSE COALESCE(m.estimated_cost,0) END) + COALESCE(m.washing_cost,0)";
  try {
    $mRes = $conn->query("
      SELECT
        COUNT(*) AS job_count,
        COALESCE(SUM($maintCostExpr), 0) AS total_expenses
      FROM maintenance m
      WHERE DATE(m.schedule_date) BETWEEN '$fromEsc' AND '$toEsc'
      $vehClauseM
    ");
    if ($mRes && ($mrow = $mRes->fetch_assoc())) {
      $summary['maintenance_jobs'] = (int)$mrow['job_count'];
      $summary['total_expenses'] = (float)$mrow['total_expenses'];
    }
  } catch (Throwable $e) {
    $summary['total_expenses'] = 0.0;
    $summary['maintenance_jobs'] = 0;
  }
  $summary['net_income'] = $summary['total_earnings'] - $summary['total_expenses'];

  try {
    $chk = $conn->query("SHOW TABLES LIKE 'rental_payments'");
    if ($chk && $chk->num_rows > 0) {
      $paySql = "
        SELECT COALESCE(SUM(p.amount), 0) AS collected
        FROM rental_payments p
        INNER JOIN rentals r ON r.id = p.rental_id
        WHERE p.status = 'POSTED'
          AND DATE(p.paid_at) BETWEEN '$fromEsc' AND '$toEsc'
          $vehClauseR
      ";
      $pay = $conn->query($paySql);
      if ($pay && ($prow = $pay->fetch_assoc())) {
        $summary['collected_payments'] = (float)$prow['collected'];
      }
    }
  } catch (Throwable $e) {
    // ignore
  }

  $fromDt = new DateTimeImmutable($from);
  $toDt = new DateTimeImmutable($to);
  $days = (int)$fromDt->diff($toDt)->days + 1;
  $prevTo = $fromDt->modify('-1 day');
  $prevFrom = $prevTo->modify('-' . ($days - 1) . ' days');
  $prevFromEsc = $conn->real_escape_string($prevFrom->format('Y-m-d'));
  $prevToEsc = $conn->real_escape_string($prevTo->format('Y-m-d'));
  $prevEarnings = (float)scalar($conn, "
    SELECT COALESCE(SUM(CASE WHEN status <> 'cancelled' THEN total_cost ELSE 0 END), 0)
    FROM rentals
    WHERE DATE(created_at) BETWEEN '$prevFromEsc' AND '$prevToEsc'
    $vehClause
  ");
  $prevRentals = (int)scalar($conn, "
    SELECT COUNT(*) FROM rentals
    WHERE DATE(created_at) BETWEEN '$prevFromEsc' AND '$prevToEsc'
    $vehClause
  ");
  $prevExpenses = 0.0;
  try {
    $prevExpenses = (float)scalar($conn, "
      SELECT COALESCE(SUM($maintCostExpr), 0)
      FROM maintenance m
      WHERE DATE(m.schedule_date) BETWEEN '$prevFromEsc' AND '$prevToEsc'
      $vehClauseM
    ");
  } catch (Throwable $e) {
    $prevExpenses = 0.0;
  }
  $prevNet = $prevEarnings - $prevExpenses;

  $earnChange = $prevEarnings > 0
    ? ((($summary['total_earnings'] - $prevEarnings) / $prevEarnings) * 100)
    : ($summary['total_earnings'] > 0 ? 100.0 : 0.0);
  $rentalChange = $prevRentals > 0
    ? ((($summary['total_rentals'] - $prevRentals) / $prevRentals) * 100)
    : ($summary['total_rentals'] > 0 ? 100.0 : 0.0);
  $expenseChange = $prevExpenses > 0
    ? ((($summary['total_expenses'] - $prevExpenses) / $prevExpenses) * 100)
    : ($summary['total_expenses'] > 0 ? 100.0 : 0.0);
  $netChange = $prevNet != 0.0
    ? ((($summary['net_income'] - $prevNet) / abs($prevNet)) * 100)
    : ($summary['net_income'] != 0.0 ? 100.0 : 0.0);

  $useMonthly = ($days > 92) || in_array($period, ['6m', '9m', '1y'], true);
  $trend = [];
  if ($useMonthly) {
    $tRes = $conn->query("
      SELECT DATE_FORMAT(created_at, '%Y-%m') AS bucket,
             COALESCE(SUM(CASE WHEN status <> 'cancelled' THEN total_cost ELSE 0 END), 0) AS earnings,
             COUNT(*) AS rentals
      FROM rentals
      WHERE DATE(created_at) BETWEEN '$fromEsc' AND '$toEsc'
      $vehClause
      GROUP BY DATE_FORMAT(created_at, '%Y-%m')
      ORDER BY bucket ASC
    ");
  } else {
    $tRes = $conn->query("
      SELECT DATE(created_at) AS bucket,
             COALESCE(SUM(CASE WHEN status <> 'cancelled' THEN total_cost ELSE 0 END), 0) AS earnings,
             COUNT(*) AS rentals
      FROM rentals
      WHERE DATE(created_at) BETWEEN '$fromEsc' AND '$toEsc'
      $vehClause
      GROUP BY DATE(created_at)
      ORDER BY bucket ASC
    ");
  }
  if ($tRes) {
    while ($t = $tRes->fetch_assoc()) {
      $label = $useMonthly
        ? date('M Y', strtotime($t['bucket'] . '-01'))
        : date('M j', strtotime($t['bucket']));
      $trend[] = [
        'label' => $label,
        'earnings' => (float)$t['earnings'],
        'rentals' => (int)$t['rentals'],
      ];
    }
  }

  $topVehicles = [];
  $vRes = $conn->query("
    SELECT v.id, v.make_model, v.plate_no,
           COUNT(r.id) AS rental_count,
           COALESCE(SUM(CASE WHEN r.status <> 'cancelled' THEN r.total_cost ELSE 0 END), 0) AS earnings
    FROM rentals r
    JOIN vehicles v ON v.id = r.vehicle_id
    WHERE DATE(r.created_at) BETWEEN '$fromEsc' AND '$toEsc'
    $vehClauseR
    GROUP BY v.id, v.make_model, v.plate_no
    ORDER BY earnings DESC
    LIMIT 8
  ");
  if ($vRes) {
    while ($v = $vRes->fetch_assoc()) {
      $topVehicles[] = [
        'vehicle' => trim(($v['make_model'] ?? '') . ' (' . ($v['plate_no'] ?? '') . ')'),
        'rental_count' => (int)$v['rental_count'],
        'earnings' => (float)$v['earnings'],
      ];
    }
  }

  $periodLabels = report_period_labels();

  return [
    'period' => $period,
    'period_label' => $periodLabels[$period] ?? 'Custom range',
    'from' => $from,
    'to' => $to,
    'vehicle_id' => $vehicleId,
    'summary' => $summary,
    'prev_earnings' => $prevEarnings,
    'prev_rentals' => $prevRentals,
    'prev_expenses' => $prevExpenses,
    'prev_net_income' => $prevNet,
    'earnings_change_pct' => $earnChange,
    'rentals_change_pct' => $rentalChange,
    'expenses_change_pct' => $expenseChange,
    'net_income_change_pct' => $netChange,
    'trend' => $trend,
    'top_vehicles' => $topVehicles,
  ];
}

$type = $_GET['type'] ?? 'All';

// Unified period / vehicle filter (shared across all tabs)
$periodParam = strtolower(trim((string)($_GET['period'] ?? $_GET['earn_period'] ?? '')));
if ($periodParam === '' && (isset($_GET['from']) || isset($_GET['to']))) {
  $periodParam = 'custom';
}
$periodParam = report_normalize_period($periodParam);

$filterVehicleId = (int)($_GET['vehicle'] ?? $_GET['earn_vehicle'] ?? 0);
$fromParam = trim((string)($_GET['from'] ?? $_GET['earn_from'] ?? ''));
$toParam = trim((string)($_GET['to'] ?? $_GET['earn_to'] ?? ''));

if ($periodParam === 'custom' && $fromParam !== '' && $toParam !== '' && strtotime($fromParam) && strtotime($toParam) && $toParam >= $fromParam) {
  $from = $fromParam;
  $to = $toParam;
} else {
  if ($periodParam === 'custom') $periodParam = '3m';
  [$periodParam, $from, $to] = earnings_period_bounds($periodParam);
}

$earnPeriodParam = $periodParam;
$earnFrom = $from;
$earnTo = $to;
$earnVehicleId = $filterVehicleId;
$fromEsc = $conn->real_escape_string($from);
$toEsc = $conn->real_escape_string($to);
$vehClauseR = $filterVehicleId > 0 ? (' AND r.vehicle_id = ' . (int)$filterVehicleId) : '';
$vehClauseM = $filterVehicleId > 0 ? (' AND m.vehicle_id = ' . (int)$filterVehicleId) : '';
$vehClausePlain = $filterVehicleId > 0 ? (' AND vehicle_id = ' . (int)$filterVehicleId) : '';

// ===== AJAX endpoints =====
if (isset($_GET['ajax'])) {
  $action = $_GET['ajax'];

  if ($action === 'earnings_dss') {
    header('Content-Type: application/json');
    $p = report_normalize_period((string)($_GET['period'] ?? $_GET['earn_period'] ?? '3m'));
    $vid = (int)($_GET['vehicle'] ?? $_GET['earn_vehicle'] ?? 0);
    $ef = trim((string)($_GET['from'] ?? $_GET['earn_from'] ?? ''));
    $et = trim((string)($_GET['to'] ?? $_GET['earn_to'] ?? ''));
    if ($p === 'custom' && $ef !== '' && $et !== '' && strtotime($ef) && strtotime($et) && $et >= $ef) {
      echo json_encode(compute_earnings_dss($conn, $ef, $et, 'custom', $vid));
    } else {
      [$p, $efb, $etb] = earnings_period_bounds($p);
      echo json_encode(compute_earnings_dss($conn, $efb, $etb, $p, $vid));
    }
    exit;
  }

  if ($action === 'export_data') {
    $exportType = $_GET['type'] ?? 'rentals';
    $fromDate = $conn->real_escape_string(trim((string)($_GET['from'] ?? $from)));
    $toDate = $conn->real_escape_string(trim((string)($_GET['to'] ?? $to)));
    $exportVid = (int)($_GET['vehicle'] ?? $filterVehicleId);
    $exportVehR = $exportVid > 0 ? (' AND r.vehicle_id = ' . $exportVid) : '';
    $exportVehM = $exportVid > 0 ? (' AND m.vehicle_id = ' . $exportVid) : '';

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="fleetgo_report_' . $exportType . '_' . $fromDate . '_to_' . $toDate . '.csv"');

    if ($exportType === 'rentals') {
      $data = $conn->query("SELECT r.id, v.make_model, v.plate_no, u.full_name AS customer, r.start_date, r.end_date, r.status, r.total_cost, r.downpayment, r.balance_due FROM rentals r JOIN vehicles v ON v.id = r.vehicle_id JOIN users u ON u.id = r.customer_id WHERE DATE(r.start_date) BETWEEN '$fromDate' AND '$toDate'$exportVehR ORDER BY r.id DESC");
      echo "ID,Vehicle,Plate,Customer,Start Date,End Date,Status,Total Cost,Downpayment,Balance Due\n";
      while ($row = $data->fetch_assoc()) {
        echo implode(',', array_map(fn($v) => '"' . str_replace('"', '""', (string)$v) . '"', $row)) . "\n";
      }
    } elseif ($exportType === 'maintenance') {
      $exportCost = "(CASE WHEN COALESCE(m.cost,0) > 0 THEN m.cost ELSE COALESCE(m.estimated_cost,0) END) + COALESCE(m.washing_cost,0)";
      $data = $conn->query("SELECT m.id, v.make_model, v.plate_no, m.description, m.maintenance_category AS maintenance_category, m.schedule_date, $exportCost AS total_cost, m.status, m.notes FROM maintenance m JOIN vehicles v ON v.id = m.vehicle_id WHERE DATE(m.schedule_date) BETWEEN '$fromDate' AND '$toDate'$exportVehM ORDER BY m.id DESC");
      echo "ID,Vehicle,Plate,Category,Schedule Date,Total Cost,Status,Notes\n";
      while ($row = $data->fetch_assoc()) {
        echo implode(',', array_map(fn($v) => '"' . str_replace('"', '""', (string)$v) . '"', $row)) . "\n";
      }
    } elseif ($exportType === 'returns') {
      $data = $conn->query("SELECT ri.rental_id, v.make_model, v.plate_no, u.full_name AS customer, ri.fuel_level, ri.cleanliness, ri.carwash_fee, ri.damage_fee, rr.penalty_amount, rr.final_cost, ri.created_at FROM return_inspections ri LEFT JOIN rental_returns rr ON rr.rental_id = ri.rental_id JOIN rentals r ON r.id = ri.rental_id JOIN vehicles v ON v.id = r.vehicle_id JOIN users u ON u.id = r.customer_id WHERE DATE(ri.created_at) BETWEEN '$fromDate' AND '$toDate'$exportVehR ORDER BY ri.created_at DESC");
      echo "Rental ID,Vehicle,Plate,Customer,Fuel Level,Cleanliness,Carwash Fee,Damage Fee,Penalty,Final Cost,Return Date\n";
      while ($row = $data->fetch_assoc()) {
        echo implode(',', array_map(fn($v) => '"' . str_replace('"', '""', (string)$v) . '"', $row)) . "\n";
      }
    }
    exit;
  }

  if ($action === 'quick_stats') {
    $fromDate = $conn->real_escape_string(trim((string)($_GET['from'] ?? $from)));
    $toDate = $conn->real_escape_string(trim((string)($_GET['to'] ?? $to)));
    $qsVid = (int)($_GET['vehicle'] ?? $filterVehicleId);
    $qsVeh = $qsVid > 0 ? (' AND vehicle_id = ' . $qsVid) : '';
    $qsVehV = $qsVid > 0 ? (' AND id = ' . $qsVid) : '';

    $fleetTotalAjax = (int)scalar($conn, "SELECT COUNT(*) FROM vehicles WHERE 1=1$qsVehV");
    $availableCountAjax = (int)scalar($conn, "SELECT COUNT(*) FROM vehicles WHERE current_status='available'$qsVehV");
    $availabilityRateAjax = $fleetTotalAjax > 0 ? (($availableCountAjax / $fleetTotalAjax) * 100) : 0;

    $maintenanceBacklogAjax = (int)scalar($conn, "SELECT COUNT(*) FROM maintenance WHERE status <> 'completed'$qsVeh");
    $avgRentalDaysAjax = (float)scalar($conn, "SELECT AVG(DATEDIFF(end_date, start_date) + 1) FROM rentals WHERE DATE(start_date) BETWEEN '$fromDate' AND '$toDate'$qsVeh");
    if ($avgRentalDaysAjax <= 0) { $avgRentalDaysAjax = 0; }

    $avgUtilizationAjax = 0;
    if (!empty($utilizationTable)) {
      $sum = 0;
      $n = 0;
      foreach ($utilizationTable as $row) {
        if ($qsVid > 0 && (int)($row['vehicle_id'] ?? 0) !== $qsVid) continue;
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
$fleetVehSql = $filterVehicleId > 0 ? (' WHERE id = ' . (int)$filterVehicleId) : '';
$fleetTotal = (int)scalar($conn, "SELECT COUNT(*) FROM vehicles$fleetVehSql");
$availableCount = (int)scalar($conn, "SELECT COUNT(*) FROM vehicles WHERE current_status='available'" . ($filterVehicleId > 0 ? (' AND id = ' . (int)$filterVehicleId) : ''));
$maintenanceBacklogCount = (int)scalar($conn, "SELECT COUNT(*) FROM maintenance WHERE status <> 'completed'$vehClausePlain");
$activeRentalsCount = (int)scalar($conn, "SELECT COUNT(*) FROM rentals WHERE status IN ('ongoing','reserved') AND DATE(start_date) <= '$toEsc' AND DATE(end_date) >= '$fromEsc'$vehClausePlain");

$avgRentalDays = (float)scalar($conn, "SELECT AVG(DATEDIFF(end_date, start_date) + 1) FROM rentals WHERE DATE(start_date) BETWEEN '$fromEsc' AND '$toEsc'$vehClausePlain");
if ($avgRentalDays <= 0) { $avgRentalDays = 0; }

$availabilityRate = $fleetTotal > 0 ? (($availableCount / $fleetTotal) * 100) : 0;

$avgUtilizationRate = 0;
if (!empty($utilizationTable)) {
  if ($filterVehicleId > 0) {
    $utilizationTable = array_filter($utilizationTable, fn($row) => (int)($row['vehicle_id'] ?? 0) === $filterVehicleId);
    $utilizationTable = array_values($utilizationTable);
  }
  $sum = 0;
  $n = 0;
  foreach ($utilizationTable as $row) {
    $sum += (float)$row['utilization_rate'];
    $n++;
  }
  $avgUtilizationRate = $n > 0 ? ($sum / $n) : 0;
}

$fleetStatusData = [];
$fleetStatusSql = $filterVehicleId > 0
  ? ("SELECT current_status, COUNT(*) AS count FROM vehicles WHERE id = " . (int)$filterVehicleId . " GROUP BY current_status ORDER BY count DESC")
  : "SELECT current_status, COUNT(*) AS count FROM vehicles GROUP BY current_status ORDER BY count DESC";
$res = $conn->query($fleetStatusSql);
while ($row = $res->fetch_assoc()) {
  $fleetStatusData[] = ['status' => $row['current_status'] ?: 'unknown', 'count' => (int)$row['count']];
}

$maintenanceBacklogByStatus = [];
$res = $conn->query("SELECT status, COUNT(*) AS count FROM maintenance WHERE 1=1$vehClausePlain GROUP BY status ORDER BY count DESC");
while ($row = $res->fetch_assoc()) {
  $maintenanceBacklogByStatus[] = ['status' => $row['status'] ?: 'unknown', 'count' => (int)$row['count']];
}

$topUtilizationVehicles = [];
if (!empty($utilizationTable)) {
  $topUtilizationVehicles = array_slice($utilizationTable, 0, 10);
}

// ===== Tables =====
$rentals = $conn->query("SELECT r.id, v.make_model, v.plate_no, u.full_name AS customer_name, r.start_date, r.end_date, r.total_cost, r.downpayment, r.balance_due, r.status FROM rentals r JOIN vehicles v ON v.id=r.vehicle_id JOIN users u ON u.id=r.customer_id WHERE DATE(r.start_date) BETWEEN '$fromEsc' AND '$toEsc'$vehClauseR ORDER BY r.id DESC");

$maintCostSql = "(CASE WHEN COALESCE(m.cost,0) > 0 THEN m.cost ELSE COALESCE(m.estimated_cost,0) END) + COALESCE(m.washing_cost,0)";
$maintenanceRows = [];
try {
  $maintRes = $conn->query("
    SELECT m.id, v.make_model, v.plate_no, m.maintenance_category, m.description, m.schedule_date,
           $maintCostSql AS total_cost, m.status
    FROM maintenance m
    JOIN vehicles v ON v.id = m.vehicle_id
    WHERE DATE(m.schedule_date) BETWEEN '$fromEsc' AND '$toEsc'
    $vehClauseM
    ORDER BY m.schedule_date DESC, m.id DESC
  ");
  if ($maintRes) {
    while ($row = $maintRes->fetch_assoc()) {
      $maintenanceRows[] = $row;
    }
  }
} catch (Throwable $e) {
  $maintenanceRows = [];
}

$maintKpis = [
  'total_expense' => 0.0,
  'completed_expense' => 0.0,
  'job_count' => count($maintenanceRows),
  'completed_count' => 0,
  'open_count' => 0,
  'cancelled_count' => 0,
  'avg_cost' => 0.0,
];
$maintByCategory = [];
$maintByStatus = [];
$maintByVehicle = [];
$maintByMonth = [];

foreach ($maintenanceRows as $m) {
  $cost = (float)($m['total_cost'] ?? 0);
  $st = strtolower(trim((string)($m['status'] ?? '')));
  $cat = strtolower(trim((string)($m['maintenance_category'] ?? 'other'))) ?: 'other';
  $vehKey = trim(($m['make_model'] ?? '') . ' (' . ($m['plate_no'] ?? '') . ')');
  $monthKey = date('Y-m', strtotime((string)$m['schedule_date']));

  $maintKpis['total_expense'] += $cost;
  if ($st === 'completed') {
    $maintKpis['completed_expense'] += $cost;
    $maintKpis['completed_count']++;
  } elseif ($st === 'cancelled') {
    $maintKpis['cancelled_count']++;
  } else {
    $maintKpis['open_count']++;
  }

  $maintByCategory[$cat] = ($maintByCategory[$cat] ?? 0) + $cost;
  $maintByStatus[$st ?: 'unknown'] = ($maintByStatus[$st ?: 'unknown'] ?? 0) + 1;
  $maintByVehicle[$vehKey] = ($maintByVehicle[$vehKey] ?? 0) + $cost;
  $maintByMonth[$monthKey] = ($maintByMonth[$monthKey] ?? 0) + $cost;
}

if ($maintKpis['job_count'] > 0) {
  $maintKpis['avg_cost'] = $maintKpis['total_expense'] / $maintKpis['job_count'];
}

arsort($maintByVehicle);
$maintTopVehicles = array_slice($maintByVehicle, 0, 8, true);
ksort($maintByMonth);

$maintCategoryChart = [];
foreach ($maintByCategory as $label => $amount) {
  $maintCategoryChart[] = ['label' => ucfirst($label), 'amount' => round((float)$amount, 2)];
}
$maintStatusChart = [];
foreach ($maintByStatus as $label => $count) {
  $maintStatusChart[] = ['label' => ucfirst($label), 'count' => (int)$count];
}
$maintMonthChart = [];
foreach ($maintByMonth as $ym => $amount) {
  $maintMonthChart[] = [
    'label' => date('M Y', strtotime($ym . '-01')),
    'amount' => round((float)$amount, 2),
  ];
}
$maintVehicleChart = [];
foreach ($maintTopVehicles as $label => $amount) {
  $maintVehicleChart[] = ['label' => $label, 'amount' => round((float)$amount, 2)];
}

$maintCategories = array_keys($maintByCategory);
sort($maintCategories);
$maintStatuses = array_keys($maintByStatus);
sort($maintStatuses);

$returnInspections = $conn->query("SELECT ri.rental_id, ri.fuel_level, ri.cleanliness, ri.carwash_fee, ri.damage_fee, ri.created_at, rr.actual_return_date, rr.actual_return_time, rr.return_condition, rr.penalty_amount, rr.final_cost, v.make_model, v.plate_no, u.full_name AS customer_name, r.start_date, r.end_date FROM return_inspections ri LEFT JOIN rental_returns rr ON rr.rental_id=ri.rental_id JOIN rentals r ON r.id=ri.rental_id JOIN vehicles v ON v.id=r.vehicle_id JOIN users u ON u.id=r.customer_id WHERE DATE(ri.created_at) BETWEEN '$fromEsc' AND '$toEsc'$vehClauseR ORDER BY ri.created_at DESC");

$forecastDisplayMonth = date('F Y', strtotime('+1 month'));

$earningsActive = compute_earnings_dss($conn, $earnFrom, $earnTo, $earnPeriodParam, $earnVehicleId);

$earnVehicles = [];
try {
  $evRes = $conn->query("SELECT id, make_model, plate_no FROM vehicles ORDER BY make_model ASC, plate_no ASC");
  if ($evRes) {
    while ($ev = $evRes->fetch_assoc()) {
      $earnVehicles[] = $ev;
    }
  }
} catch (Throwable $e) {
  $earnVehicles = [];
}

$periodLabelsUi = report_period_labels();
$periodLabelActive = $periodLabelsUi[$periodParam] ?? 'Custom range';

$tabParam = strtolower(trim((string)($_GET['tab'] ?? '')));
$typeToTab = [
  'Rentals' => 'rentals',
  'Maintenance' => 'maintenance',
  'Returns' => 'returns',
];
$allowedTabs = ['earnings', 'operations', 'utilization', 'rentals', 'returns', 'maintenance'];
if ($tabParam !== '' && in_array($tabParam, $allowedTabs, true)) {
  $activeTab = $tabParam;
} elseif (isset($typeToTab[$type])) {
  $activeTab = $typeToTab[$type];
} else {
  $activeTab = 'earnings';
}

$reportTitles = [
  'earnings' => 'Earnings',
  'operations' => 'Operations Overview',
  'utilization' => 'Vehicle Utilization',
  'rentals' => 'Rentals Summary',
  'returns' => 'Return Inspections',
  'maintenance' => 'Maintenance Summary',
];
$reportSubtitles = [
  'earnings' => 'Earnings, maintenance expenses (liability), and net income — filter by period and vehicle',
  'operations' => 'Operational KPIs and fleet performance summaries for day-to-day management',
  'utilization' => 'Vehicle usage rates and next-month forecast based on recent rental activity',
  'rentals' => 'Rental bookings, payments, and status for the selected date range',
  'returns' => 'Return inspection results, fuel levels, penalties, and final costs',
  'maintenance' => 'Total maintenance expenses, KPIs, and charts for the selected period',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= h($reportTitles[$activeTab] ?? 'Earnings') ?> — FleetGo Reports</title>
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
.summary-grid { display:grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap:16px; margin-bottom:24px; }
#panel-maintenance .summary-grid { grid-template-columns: repeat(5, minmax(0, 1fr)); }
#panel-earnings .summary-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); margin-bottom:24px; }
.report-filters-card { margin-bottom:24px; overflow:visible; }
.earn-period-label { font-weight:500; color:var(--text-muted); }
.dss-grid{
  display:grid;
  grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr);
  gap:16px;
  margin-top:0;
  width:100%;
}
#panel-earnings .dss-grid .chart-widget{
  min-width:0;
  width:100%;
  overflow:hidden;
}
#panel-earnings .dss-grid .table-container{
  max-width:100%;
}
.charts-grid { display:grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap:16px; }
.charts-grid .chart-widget { min-width:0; }
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

/* Report tabs — top of page, under navbar */
.report-tabs-bar{
  z-index:50;
  background:rgba(11,13,16,0.96);
  border-bottom:1px solid var(--border);
  padding:0 32px;
}
.report-tabs{
  max-width:1600px;
  margin:0 auto;
  display:flex;
  gap:6px;
  overflow-x:auto;
  scrollbar-width:thin;
  padding:12px 0;
}
.report-tab{
  flex:0 0 auto;
  display:inline-flex;
  align-items:center;
  gap:8px;
  padding:10px 16px;
  border-radius:12px;
  border:1px solid transparent;
  background:transparent;
  color:var(--text-secondary);
  font-family:inherit;
  font-size:0.875rem;
  font-weight:600;
  cursor:pointer;
  white-space:nowrap;
  transition:all 0.2s ease;
}
.report-tab:hover{
  color:var(--text-primary);
  background:rgba(255,255,255,0.04);
  border-color:var(--border);
}
.report-tab.active{
  color:#04121b;
  background:linear-gradient(135deg, var(--brand), var(--brand2));
  border-color:transparent;
  box-shadow:0 8px 20px rgba(93,208,255,0.18);
}
.report-tab i{ font-size:0.85rem; opacity:0.9; }
.tab-panel{ display:none; }
.tab-panel.active{ display:block; animation: tabFade .2s ease; }
@keyframes tabFade{
  from{ opacity:0; transform:translateY(6px); }
  to{ opacity:1; transform:translateY(0); }
}
.earnings-toolbar{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:14px;
  flex-wrap:wrap;
}
.earn-toolbar-left{
  display:flex;
  align-items:center;
  gap:12px;
  flex-wrap:wrap;
  min-width:0;
}
.earn-filter-wrap{ position:relative; }
.earn-icon-btn{
  width:42px; height:42px;
  display:inline-flex; align-items:center; justify-content:center;
  border-radius:12px;
  border:1px solid var(--border);
  background:rgba(255,255,255,0.04);
  color:var(--brand);
  cursor:pointer;
  transition:all .2s ease;
}
.earn-icon-btn:hover, .earn-icon-btn.open{
  border-color:rgba(93,208,255,0.45);
  background:rgba(93,208,255,0.1);
  box-shadow:0 0 0 3px rgba(93,208,255,0.1);
}
.earn-filter-menu{
  position:absolute;
  top:calc(100% + 8px);
  left:0;
  z-index:40;
  width:min(320px, 86vw);
  padding:12px;
  border-radius:14px;
  border:1px solid var(--border);
  background:#151a21;
  box-shadow:var(--shadow);
  display:none;
}
.earn-filter-menu.open{ display:block; }
.earn-filter-menu .preset-btn{
  width:100%;
  text-align:left;
  margin-bottom:6px;
  padding:10px 12px;
  border-radius:10px;
  border:1px solid transparent;
  background:rgba(255,255,255,0.03);
  color:var(--text-primary);
  font-weight:600;
  font-size:0.875rem;
  cursor:pointer;
}
.earn-filter-menu .preset-btn:hover,
.earn-filter-menu .preset-btn.active{
  border-color:rgba(93,208,255,0.35);
  background:rgba(93,208,255,0.1);
  color:var(--brand);
}
.earn-custom-block{
  margin-top:10px;
  padding-top:10px;
  border-top:1px solid var(--border);
  display:grid;
  gap:8px;
}
.earn-custom-block label{
  font-size:0.7rem;
  font-weight:600;
  color:var(--text-secondary);
  text-transform:uppercase;
  letter-spacing:0.4px;
}
.earn-custom-block input[type="date"]{
  padding:10px 12px;
}
.earn-range-chip{
  font-size:0.85rem;
  font-weight:600;
  color:var(--text-secondary);
  padding:8px 12px;
  border-radius:999px;
  border:1px solid var(--border);
  background:rgba(255,255,255,0.03);
}
.earn-vehicle-select{
  min-width:220px;
  max-width:320px;
  width:auto;
  padding:10px 14px;
  border-radius:12px;
}
.earn-search-wrap{
  position:relative;
  flex:1 1 220px;
  min-width:180px;
  max-width:360px;
}
.earn-search-wrap > i{
  position:absolute;
  left:14px;
  top:50%;
  transform:translateY(-50%);
  color:var(--text-muted);
  font-size:0.85rem;
  pointer-events:none;
}
.earn-search-wrap input{
  width:100%;
  padding:11px 14px 11px 38px;
  border-radius:12px;
  border:1px solid var(--border);
  background:rgba(255,255,255,0.04);
  color:var(--text-primary);
  font:inherit;
  font-size:0.875rem;
}
.earn-search-wrap input::placeholder{ color:var(--text-muted); }
.earn-search-wrap input:focus{
  outline:none;
  border-color:rgba(93,208,255,0.45);
  box-shadow:0 0 0 3px rgba(93,208,255,0.1);
}
.earnings-insight{
  margin-top:8px; font-size:0.8rem; font-weight:600;
}
.earnings-insight.up{ color:var(--success); }
.earnings-insight.down{ color:var(--error); }
.earnings-insight.flat{ color:var(--text-muted); }
@media (max-width: 1100px){
  .summary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  #panel-earnings .summary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  #panel-maintenance .summary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .charts-grid { grid-template-columns: 1fr; }
  .dss-grid{ grid-template-columns:1fr; }
  .charts-grid { grid-template-columns:1fr; }
  .earn-vehicle-select{ min-width:160px; max-width:100%; flex:1; }
  .earn-search-wrap{ max-width:100%; flex:1 1 100%; }
}

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
@media (max-width: 768px) {
  .reports-container { padding: 16px; }
  .report-tabs-bar { padding: 0 16px; }
  .reports-header { flex-direction: column; align-items: flex-start; gap: 16px; }
  .summary-grid { grid-template-columns: 1fr; }
  .forecast-card { flex-direction: column; text-align: center; gap: 20px; }
  .forecast-stat { border-right: none; border-bottom: 1px solid var(--border); padding-right: 0; padding-bottom: 20px; flex-direction: column; }
  .filters-grid { grid-template-columns: 1fr; }
  .table-container { overflow-x:auto; }
}
</style>
</head>
<body>
<?php include __DIR__ . '/includes/navbar.php'; ?>

<nav class="report-tabs-bar" aria-label="Report sections">
  <div class="report-tabs" role="tablist">
    <button type="button" class="report-tab <?= $activeTab === 'earnings' ? 'active' : '' ?>" role="tab" aria-selected="<?= $activeTab === 'earnings' ? 'true' : 'false' ?>" data-tab="earnings">
      <i class="fas fa-coins"></i> Earnings
    </button>
    <button type="button" class="report-tab <?= $activeTab === 'operations' ? 'active' : '' ?>" role="tab" aria-selected="<?= $activeTab === 'operations' ? 'true' : 'false' ?>" data-tab="operations">
      <i class="fas fa-clipboard-list"></i> Operations Overview
    </button>
    <button type="button" class="report-tab <?= $activeTab === 'utilization' ? 'active' : '' ?>" role="tab" aria-selected="<?= $activeTab === 'utilization' ? 'true' : 'false' ?>" data-tab="utilization">
      <i class="fas fa-tachometer-alt"></i> Vehicle Utilization
    </button>
    <button type="button" class="report-tab <?= $activeTab === 'rentals' ? 'active' : '' ?>" role="tab" aria-selected="<?= $activeTab === 'rentals' ? 'true' : 'false' ?>" data-tab="rentals">
      <i class="fas fa-car"></i> Rentals Summary
    </button>
    <button type="button" class="report-tab <?= $activeTab === 'returns' ? 'active' : '' ?>" role="tab" aria-selected="<?= $activeTab === 'returns' ? 'true' : 'false' ?>" data-tab="returns">
      <i class="fas fa-clipboard-check"></i> Return Inspections
    </button>
    <button type="button" class="report-tab <?= $activeTab === 'maintenance' ? 'active' : '' ?>" role="tab" aria-selected="<?= $activeTab === 'maintenance' ? 'true' : 'false' ?>" data-tab="maintenance">
      <i class="fas fa-tools"></i> Maintenance Summary
    </button>
  </div>
</nav>

<div class="reports-container">
  <div class="reports-header">
    <div>
      <h1 class="reports-title" id="reportsPageTitle"><?= h($reportTitles[$activeTab] ?? 'Earnings') ?></h1>
      <p class="reports-subtitle" id="reportsPageSubtitle"><?= h($reportSubtitles[$activeTab] ?? $reportSubtitles['earnings']) ?></p>
    </div>
    <div class="header-actions">
      <button class="btn btn-secondary btn-sm" onclick="refreshStats()"><i class="fas fa-sync-alt"></i> Refresh</button>
      <button class="btn btn-outline btn-sm" type="button" onclick="summarizeReport()"><i class="fas fa-wand-magic-sparkles"></i> Summarize</button>
      <button class="btn btn-outline btn-sm" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
    </div>
  </div>

  <div id="geminiReportBox" class="card" hidden style="margin:0 0 16px;padding:16px 18px;white-space:pre-wrap;line-height:1.55;"></div>

  <div class="card filters-card report-filters-card" id="sharedFiltersCard">
    <div class="earnings-toolbar">
      <div class="earn-toolbar-left">
        <div class="earn-filter-wrap">
          <button type="button" class="earn-icon-btn" id="earnFilterBtn" aria-label="Filter by date" aria-expanded="false">
            <i class="fas fa-filter"></i>
          </button>
          <div class="earn-filter-menu" id="earnFilterMenu" role="menu">
<?php foreach (['7d', '3m', '6m', '9m', '1y'] as $presetKey): ?>
            <button type="button" class="preset-btn <?= $periodParam === $presetKey ? 'active' : '' ?>" data-earn-preset="<?= $presetKey ?>"><?= h($periodLabelsUi[$presetKey]) ?></button>
<?php endforeach; ?>
            <div class="earn-custom-block">
              <label for="earnFrom">From</label>
              <input type="date" id="earnFrom" value="<?= h($from) ?>">
              <label for="earnTo">To</label>
              <input type="date" id="earnTo" value="<?= h($to) ?>">
              <button type="button" class="btn btn-primary btn-sm" id="earnApplyCustom" style="width:100%;margin-top:4px;">Apply custom range</button>
            </div>
          </div>
        </div>
        <div class="earn-range-chip" id="earnRangeNote">
          <?= h(date('M j, Y', strtotime($from)) . ' – ' . date('M j, Y', strtotime($to))) ?>
          <span class="earn-period-label" id="earnPeriodLabel"> · <?= h($periodLabelActive) ?></span>
        </div>
        <div class="earn-search-wrap">
          <i class="fas fa-search" aria-hidden="true"></i>
          <input type="search" id="reportSearchInput" placeholder="Search vehicles, plates, customers…" autocomplete="off" aria-label="Search reports">
        </div>
      </div>
      <select id="earnVehicleSelect" class="earn-vehicle-select" aria-label="Filter by vehicle">
        <option value="0">All vehicles</option>
        <?php foreach ($earnVehicles as $ev): ?>
          <option value="<?= (int)$ev['id'] ?>" <?= $filterVehicleId === (int)$ev['id'] ? 'selected' : '' ?>>
            <?= h(($ev['make_model'] ?? '') . ' — ' . ($ev['plate_no'] ?? '')) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <div class="tab-panel <?= $activeTab === 'earnings' ? 'active' : '' ?>" id="panel-earnings" role="tabpanel" data-panel="earnings">

    <div class="summary-grid">
      <div class="summary-card primary">
        <div class="summary-header">
          <div class="summary-icon"><i class="fas fa-coins"></i></div>
          <div>
            <div class="summary-title">Total Earnings</div>
            <div class="summary-value" id="earnTotalEarnings">₱<?= number_format($earningsActive['summary']['total_earnings'], 2) ?></div>
            <div class="earnings-insight <?= $earningsActive['earnings_change_pct'] > 0 ? 'up' : ($earningsActive['earnings_change_pct'] < 0 ? 'down' : 'flat') ?>" id="earnEarningsChange">
              <?php
                $ec = $earningsActive['earnings_change_pct'];
                echo ($ec > 0 ? '▲ ' : ($ec < 0 ? '▼ ' : '● ')) . number_format(abs($ec), 1) . '% vs previous period';
              ?>
            </div>
          </div>
        </div>
      </div>
      <div class="summary-card">
        <div class="summary-header">
          <div class="summary-icon"><i class="fas fa-car-side"></i></div>
          <div>
            <div class="summary-title">Total Rentals</div>
            <div class="summary-value" id="earnTotalRentals"><?= number_format($earningsActive['summary']['total_rentals']) ?></div>
            <div class="earnings-insight <?= $earningsActive['rentals_change_pct'] > 0 ? 'up' : ($earningsActive['rentals_change_pct'] < 0 ? 'down' : 'flat') ?>" id="earnRentalsChange">
              <?php
                $rc = $earningsActive['rentals_change_pct'];
                echo ($rc > 0 ? '▲ ' : ($rc < 0 ? '▼ ' : '● ')) . number_format(abs($rc), 1) . '% vs previous period';
              ?>
            </div>
          </div>
        </div>
      </div>
      <div class="summary-card">
        <div class="summary-header">
          <div class="summary-icon"><i class="fas fa-receipt"></i></div>
          <div>
            <div class="summary-title">Avg Booking Value</div>
            <div class="summary-value" id="earnAvgValue">₱<?= number_format($earningsActive['summary']['avg_earning'], 2) ?></div>
            <div class="earnings-insight flat" id="earnCompletedNote"><?= number_format($earningsActive['summary']['completed_rentals']) ?> completed</div>
          </div>
        </div>
      </div>
      <div class="summary-card">
        <div class="summary-header">
          <div class="summary-icon"><i class="fas fa-wallet"></i></div>
          <div>
            <div class="summary-title">Payments Collected</div>
            <div class="summary-value" id="earnCollected">₱<?= number_format($earningsActive['summary']['collected_payments'], 2) ?></div>
            <div class="earnings-insight flat" id="earnActiveNote"><?= number_format($earningsActive['summary']['active_rentals']) ?> active now</div>
          </div>
        </div>
      </div>
      <div class="summary-card">
        <div class="summary-header">
          <div class="summary-icon" style="background:rgba(239,68,68,.15);color:#fca5a5;"><i class="fas fa-tools"></i></div>
          <div>
            <div class="summary-title">Total Expenses</div>
            <div class="summary-value" id="earnTotalExpenses" style="color:#fca5a5;">₱<?= number_format($earningsActive['summary']['total_expenses'] ?? 0, 2) ?></div>
            <div class="earnings-insight <?= ($earningsActive['expenses_change_pct'] ?? 0) > 0 ? 'down' : (($earningsActive['expenses_change_pct'] ?? 0) < 0 ? 'up' : 'flat') ?>" id="earnExpensesChange">
              <?php
                $xc = (float)($earningsActive['expenses_change_pct'] ?? 0);
                $mj = (int)($earningsActive['summary']['maintenance_jobs'] ?? 0);
                echo ($xc > 0 ? '▲ ' : ($xc < 0 ? '▼ ' : '● ')) . number_format(abs($xc), 1) . '% vs previous · ' . number_format($mj) . ' maint. jobs';
              ?>
            </div>
          </div>
        </div>
      </div>
      <div class="summary-card primary">
        <div class="summary-header">
          <div class="summary-icon"><i class="fas fa-chart-line"></i></div>
          <div>
            <div class="summary-title">Total Income (Net)</div>
            <div class="summary-value" id="earnNetIncome" style="color:<?= (($earningsActive['summary']['net_income'] ?? 0) >= 0) ? 'var(--brand2)' : '#fca5a5' ?>;">
              ₱<?= number_format($earningsActive['summary']['net_income'] ?? 0, 2) ?>
            </div>
            <div class="earnings-insight <?= ($earningsActive['net_income_change_pct'] ?? 0) > 0 ? 'up' : (($earningsActive['net_income_change_pct'] ?? 0) < 0 ? 'down' : 'flat') ?>" id="earnNetChange">
              <?php
                $nc = (float)($earningsActive['net_income_change_pct'] ?? 0);
                echo ($nc > 0 ? '▲ ' : ($nc < 0 ? '▼ ' : '● ')) . number_format(abs($nc), 1) . '% vs previous · earnings − expenses';
              ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="dss-grid">
      <div class="chart-widget">
        <div class="chart-header">
          <h3 class="chart-title">Earnings Trend</h3>
          <span class="chart-badge" id="earnTrendBadge"><?= h($earningsActive['period_label']) ?></span>
        </div>
        <div class="chart-container"><canvas id="earningsTrendChart"></canvas></div>
      </div>
      <div class="chart-widget">
        <div class="chart-header">
          <h3 class="chart-title">Top Earning Vehicles</h3>
          <span class="chart-badge">DSS</span>
        </div>
        <div class="table-container" style="max-height:320px;overflow:auto;">
          <table class="data-table">
            <thead><tr><th>Vehicle</th><th>Rentals</th><th>Earnings</th></tr></thead>
            <tbody id="earnTopVehiclesBody">
              <?php if (!empty($earningsActive['top_vehicles'])): ?>
                <?php foreach ($earningsActive['top_vehicles'] as $tv): ?>
                <tr>
                  <td><strong><?= h($tv['vehicle']) ?></strong></td>
                  <td><?= number_format($tv['rental_count']) ?></td>
                  <td><strong>₱<?= number_format($tv['earnings'], 2) ?></strong></td>
                </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr><td colspan="3" style="text-align:center;padding:28px;color:var(--text-muted)">No earnings data for this period.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <div class="tab-panel <?= $activeTab === 'operations' ? 'active' : '' ?>" id="panel-operations" role="tabpanel" data-panel="operations">
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
  </div>

  <div class="tab-panel <?= $activeTab === 'utilization' ? 'active' : '' ?>" id="panel-utilization" role="tabpanel" data-panel="utilization">
    <section class="data-section">
      <div class="section-header"><h2 class="section-title"><span class="section-icon"><i class="fas fa-tachometer-alt"></i></span>Vehicle Utilization Report <span style="font-weight:500;color:var(--text-secondary);font-size:0.85rem;">(rolling 30 days)</span></h2></div>
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
  </div>

  <div class="tab-panel <?= $activeTab === 'rentals' ? 'active' : '' ?>" id="panel-rentals" role="tabpanel" data-panel="rentals">
    <section class="data-section">
      <div class="section-header"><h2 class="section-title"><span class="section-icon"><i class="fas fa-car"></i></span>Rentals Summary</h2></div>
      <div class="table-container">
        <table class="data-table">
          <thead><tr><th>ID</th><th>Vehicle</th><th>Plate</th><th>Customer</th><th>Start</th><th>End</th><th>Total</th><th>Downpayment</th><th>Balance</th><th>Status</th></tr></thead>
          <tbody>
            <?php if ($rentals && $rentals->num_rows > 0): ?>
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
            <?php else: ?>
              <tr><td colspan="10" style="text-align:center;padding:40px;color:var(--text-muted)">No rentals found for this date range.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </div>

  <div class="tab-panel <?= $activeTab === 'returns' ? 'active' : '' ?>" id="panel-returns" role="tabpanel" data-panel="returns">
    <section class="data-section">
      <div class="section-header"><h2 class="section-title"><span class="section-icon"><i class="fas fa-clipboard-check"></i></span>Return Inspections</h2></div>
      <div class="table-container">
        <table class="data-table">
          <thead><tr><th>Customer</th><th>Vehicle</th><th>Rental Period</th><th>Return Date</th><th>Fuel</th><th>Condition</th><th>Penalty</th><th>Final Cost</th></tr></thead>
          <tbody>
            <?php if ($returnInspections && $returnInspections->num_rows > 0): ?>
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
            <?php else: ?>
              <tr><td colspan="8" style="text-align:center;padding:40px;color:var(--text-muted)">No return inspections found for this date range.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </div>

  <div class="tab-panel <?= $activeTab === 'maintenance' ? 'active' : '' ?>" id="panel-maintenance" role="tabpanel" data-panel="maintenance">
    <div class="summary-grid">
      <div class="summary-card primary">
        <div class="summary-header">
            <div class="summary-icon"><i class="fas fa-coins"></i></div>
          <div>
            <div class="summary-title">Total Expenses</div>
            <div class="summary-value">₱<?= number_format($maintKpis['total_expense'], 2) ?></div>
          </div>
        </div>
      </div>
      <div class="summary-card">
        <div class="summary-header">
          <div class="summary-icon"><i class="fas fa-check-circle"></i></div>
          <div>
            <div class="summary-title">Completed Spend</div>
            <div class="summary-value">₱<?= number_format($maintKpis['completed_expense'], 2) ?></div>
          </div>
        </div>
      </div>
      <div class="summary-card">
        <div class="summary-header">
          <div class="summary-icon"><i class="fas fa-clipboard-list"></i></div>
          <div>
            <div class="summary-title">Jobs Logged</div>
            <div class="summary-value"><?= number_format($maintKpis['job_count']) ?></div>
          </div>
        </div>
      </div>
      <div class="summary-card">
        <div class="summary-header">
          <div class="summary-icon"><i class="fas fa-hourglass-half"></i></div>
          <div>
            <div class="summary-title">Open / In Progress</div>
            <div class="summary-value"><?= number_format($maintKpis['open_count']) ?></div>
          </div>
        </div>
      </div>
      <div class="summary-card">
        <div class="summary-header">
          <div class="summary-icon"><i class="fas fa-calculator"></i></div>
          <div>
            <div class="summary-title">Avg Cost / Job</div>
            <div class="summary-value">₱<?= number_format($maintKpis['avg_cost'], 2) ?></div>
          </div>
        </div>
      </div>
    </div>

    <div class="card filters-card" style="margin-bottom:20px;padding:14px 16px;">
      <div class="earnings-toolbar" style="gap:10px;">
        <div class="earn-toolbar-left" style="flex-wrap:wrap;">
          <select id="maintCategoryFilter" class="earn-vehicle-select" aria-label="Filter by category" style="min-width:160px;">
            <option value="">All categories</option>
            <?php foreach ($maintCategories as $cat): ?>
              <option value="<?= h($cat) ?>"><?= h(ucfirst($cat)) ?></option>
            <?php endforeach; ?>
          </select>
          <select id="maintStatusFilter" class="earn-vehicle-select" aria-label="Filter by status" style="min-width:160px;">
            <option value="">All statuses</option>
            <?php foreach ($maintStatuses as $st): ?>
              <option value="<?= h($st) ?>"><?= h(ucfirst($st)) ?></option>
            <?php endforeach; ?>
          </select>
          <span class="earn-range-chip" id="maintFilterHint">Showing all records in range</span>
        </div>
      </div>
    </div>

    <div class="charts-grid" style="margin-bottom:24px;">
      <div class="chart-widget">
        <div class="chart-header">
          <div class="chart-title">Expenses by Category</div>
          <span class="chart-badge">Cost</span>
        </div>
        <div class="chart-container"><canvas id="maintCategoryChart"></canvas></div>
      </div>
      <div class="chart-widget">
        <div class="chart-header">
          <div class="chart-title">Jobs by Status</div>
          <span class="chart-badge">Count</span>
        </div>
        <div class="chart-container"><canvas id="maintStatusChart"></canvas></div>
      </div>
      <div class="chart-widget">
        <div class="chart-header">
          <div class="chart-title">Top Vehicles by Cost</div>
          <span class="chart-badge">Top 8</span>
        </div>
        <div class="chart-container"><canvas id="maintVehicleChart"></canvas></div>
      </div>
    </div>

    <div class="chart-widget" style="margin-bottom:24px;">
      <div class="chart-header">
        <div class="chart-title">Maintenance Spend Over Time</div>
        <span class="chart-badge">Monthly</span>
      </div>
      <div class="chart-container"><canvas id="maintMonthChart"></canvas></div>
    </div>

    <section class="data-section">
      <div class="section-header">
        <h2 class="section-title"><span class="section-icon"><i class="fas fa-tools"></i></span>Maintenance Summary</h2>
        <div style="color:var(--text-muted);font-size:.85rem;font-weight:600;">
          Total: <strong style="color:var(--brand2)">₱<?= number_format($maintKpis['total_expense'], 2) ?></strong>
        </div>
      </div>
      <div class="table-container">
        <table class="data-table" id="maintSummaryTable">
          <thead>
            <tr>
              <th>ID</th>
              <th>Vehicle</th>
              <th>Plate</th>
              <th>Type</th>
              <th>Category</th>
              <th>Schedule Date</th>
              <th>Total Cost</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!empty($maintenanceRows)): ?>
              <?php foreach ($maintenanceRows as $m):
                $cat = strtolower(trim((string)($m['maintenance_category'] ?? '')));
                $st = strtolower(trim((string)($m['status'] ?? '')));
                $typeLabel = trim((string)($m['description'] ?? '')) ?: ucfirst($cat);
              ?>
              <tr data-category="<?= h($cat) ?>" data-status="<?= h($st) ?>">
                <td><strong>#<?= (int)$m['id'] ?></strong></td>
                <td><?= h($m['make_model']) ?></td>
                <td><code style="background:rgba(255,255,255,0.05);padding:2px 6px;border-radius:4px;font-size:0.8rem"><?= h($m['plate_no']) ?></code></td>
                <td><?= h($typeLabel) ?></td>
                <td><?= h(ucfirst($cat ?: 'N/A')) ?></td>
                <td><?= date('M j, Y', strtotime($m['schedule_date'])) ?></td>
                <td><strong>₱<?= number_format((float)$m['total_cost'], 2) ?></strong></td>
                <td><span class="status-badge status-<?= h($st) ?>"><?= h(ucfirst($st)) ?></span></td>
              </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr><td colspan="8" style="text-align:center;padding:40px;color:var(--text-muted)">No maintenance records found for this date range.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </div>

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
const maintCategoryChartData = <?= json_encode($maintCategoryChart) ?>;
const maintStatusChartData = <?= json_encode($maintStatusChart) ?>;
const maintMonthChartData = <?= json_encode($maintMonthChart) ?>;
const maintVehicleChartData = <?= json_encode($maintVehicleChart) ?>;
const earningsActiveData = <?= json_encode($earningsActive) ?>;
let currentEarnPeriod = <?= json_encode($earnPeriodParam) ?>;
let currentEarnFrom = <?= json_encode($earnFrom) ?>;
let currentEarnTo = <?= json_encode($earnTo) ?>;
let currentEarnVehicle = <?= json_encode((string)$earnVehicleId) ?>;

Chart.defaults.color = '#9ca3af';
Chart.defaults.borderColor = 'rgba(255,255,255,0.05)';
Chart.defaults.font.family = "'Inter', system-ui, sans-serif";

const reportCharts = [];
let earningsTrendChart = null;

function money(n){
  return '₱' + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function formatRange(from, to){
  const opts = { month: 'short', day: 'numeric', year: 'numeric' };
  try {
    return new Date(from + 'T00:00:00').toLocaleDateString(undefined, opts)
      + ' – '
      + new Date(to + 'T00:00:00').toLocaleDateString(undefined, opts);
  } catch(e) {
    return from + ' – ' + to;
  }
}

function changeInsight(el, pct){
  if (!el) return;
  const n = Number(pct || 0);
  el.classList.remove('up', 'down', 'flat');
  el.classList.add(n > 0 ? 'up' : (n < 0 ? 'down' : 'flat'));
  const arrow = n > 0 ? '▲ ' : (n < 0 ? '▼ ' : '● ');
  el.textContent = arrow + Math.abs(n).toFixed(1) + '% vs previous period';
}

function showEmpty(canvas, title){
  if(!canvas) return;
  canvas.parentElement.innerHTML = '<div style="text-align:center;padding:48px 24px;color:var(--text-muted)"><div style="font-size:1rem;font-weight:600;color:var(--text-secondary)">' + title + '</div></div>';
}

function syncFilterUrl(tabId){
  const url = new URL(window.location.href);
  url.searchParams.set('tab', tabId || getActiveReportTab());
  url.searchParams.set('period', currentEarnPeriod);
  url.searchParams.set('vehicle', String(currentEarnVehicle || '0'));
  url.searchParams.set('from', currentEarnFrom);
  url.searchParams.set('to', currentEarnTo);
  url.searchParams.delete('earn_period');
  url.searchParams.delete('earn_vehicle');
  url.searchParams.delete('earn_from');
  url.searchParams.delete('earn_to');
  url.searchParams.delete('type');
  window.history.replaceState({}, '', url);
}

function getActiveReportTab(){
  return document.querySelector('.report-tab.active')?.dataset.tab || 'earnings';
}

function applyReportFilter(opts = {}){
  const period = opts.period || currentEarnPeriod || '3m';
  const vehicle = opts.vehicle != null ? String(opts.vehicle) : String(currentEarnVehicle || '0');
  const from = opts.from || currentEarnFrom;
  const to = opts.to || currentEarnTo;
  const tab = getActiveReportTab();

  currentEarnPeriod = period;
  currentEarnVehicle = vehicle;
  if (from) currentEarnFrom = from;
  if (to) currentEarnTo = to;

  if (tab === 'earnings') {
    window.__reportsFilterDirty = true;
    loadEarningsDss({ period, vehicle, from, to });
    return;
  }

  const params = new URLSearchParams({
    tab,
    period,
    vehicle,
    from: currentEarnFrom,
    to: currentEarnTo
  });
  window.location.href = 'reports.php?' + params.toString();
}

function applyEarningsData(data){
  if (!data) return;
  currentEarnPeriod = data.period || currentEarnPeriod;
  currentEarnFrom = data.from || currentEarnFrom;
  currentEarnTo = data.to || currentEarnTo;

  document.querySelectorAll('[data-earn-preset]').forEach(btn => {
    btn.classList.toggle('active', btn.dataset.earnPreset === currentEarnPeriod);
  });

  const fromInput = document.getElementById('earnFrom');
  const toInput = document.getElementById('earnTo');
  if (fromInput) fromInput.value = currentEarnFrom;
  if (toInput) toInput.value = currentEarnTo;

  const s = data.summary || {};
  const setText = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
  setText('earnTotalEarnings', money(s.total_earnings));
  setText('earnTotalRentals', Number(s.total_rentals || 0).toLocaleString());
  setText('earnAvgValue', money(s.avg_earning));
  setText('earnCollected', money(s.collected_payments));
  setText('earnTotalExpenses', money(s.total_expenses));
  const netEl = document.getElementById('earnNetIncome');
  if (netEl) {
    netEl.textContent = money(s.net_income);
    netEl.style.color = Number(s.net_income || 0) >= 0 ? 'var(--brand2)' : '#fca5a5';
  }
  setText('earnCompletedNote', Number(s.completed_rentals || 0).toLocaleString() + ' completed');
  setText('earnActiveNote', Number(s.active_rentals || 0).toLocaleString() + ' active now');
  const expNote = document.getElementById('earnExpensesChange');
  if (expNote) {
    // For expenses, up is worse (liability), so invert colors via changeInsight then annotate
    changeInsight(expNote, data.expenses_change_pct);
    const base = expNote.textContent.replace(/\s*·.*$/, '');
    expNote.textContent = base + ' · ' + Number(s.maintenance_jobs || 0).toLocaleString() + ' maint. jobs';
    // Flip up/down colors: higher expenses = down (bad)
    if (Number(data.expenses_change_pct || 0) > 0) {
      expNote.classList.remove('up'); expNote.classList.add('down');
    } else if (Number(data.expenses_change_pct || 0) < 0) {
      expNote.classList.remove('down'); expNote.classList.add('up');
    }
  }
  changeInsight(document.getElementById('earnNetChange'), data.net_income_change_pct);
  const netNote = document.getElementById('earnNetChange');
  if (netNote) {
    const base = netNote.textContent.replace(/\s*·.*$/, '');
    netNote.textContent = base + ' · earnings − expenses';
  }
  const rangeEl = document.getElementById('earnRangeNote');
  if (rangeEl) {
    const label = data.period_label ? (' · ' + data.period_label) : '';
    rangeEl.innerHTML = formatRange(data.from, data.to) + '<span class="earn-period-label" id="earnPeriodLabel">' + label + '</span>';
  }
  setText('earnTrendBadge', data.period_label || currentEarnPeriod);
  changeInsight(document.getElementById('earnEarningsChange'), data.earnings_change_pct);
  changeInsight(document.getElementById('earnRentalsChange'), data.rentals_change_pct);

  const tbody = document.getElementById('earnTopVehiclesBody');
  if (tbody) {
    if (data.top_vehicles && data.top_vehicles.length) {
      tbody.innerHTML = data.top_vehicles.map(tv => (
        '<tr><td><strong>' + String(tv.vehicle || '').replace(/</g,'&lt;') + '</strong></td><td>' + Number(tv.rental_count || 0).toLocaleString() + '</td><td><strong>' + money(tv.earnings) + '</strong></td></tr>'
      )).join('');
    } else {
      tbody.innerHTML = '<tr><td colspan="3" style="text-align:center;padding:28px;color:var(--text-muted)">No earnings data for this period.</td></tr>';
    }
  }

  let ctx = document.getElementById('earningsTrendChart');
  if (!ctx) {
    const wrap = document.querySelector('#panel-earnings .chart-container');
    if (wrap) {
      wrap.innerHTML = '<canvas id="earningsTrendChart"></canvas>';
      ctx = document.getElementById('earningsTrendChart');
      earningsTrendChart = null;
    }
  }
  if (ctx && typeof Chart !== 'undefined') {
    const labels = (data.trend || []).map(t => t.label);
    const earnings = (data.trend || []).map(t => t.earnings);
    const rentals = (data.trend || []).map(t => t.rentals);
    if (earningsTrendChart) {
      earningsTrendChart.destroy();
      earningsTrendChart = null;
    }
    if (labels.length) {
      earningsTrendChart = new Chart(ctx, {
        type: 'bar',
        data: {
          labels,
          datasets: [
            {
              label: 'Earnings (₱)',
              data: earnings,
              backgroundColor: 'rgba(93,208,255,0.75)',
              borderColor: '#5dd0ff',
              borderWidth: 1,
              borderRadius: 6,
              yAxisID: 'y'
            },
            {
              label: 'Rentals',
              data: rentals,
              backgroundColor: 'rgba(124,255,199,0.75)',
              borderColor: '#7cffc7',
              borderWidth: 1,
              borderRadius: 6,
              yAxisID: 'y1'
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          interaction: { mode: 'index', intersect: false },
          plugins: { legend: { position: 'bottom' } },
          scales: {
            x: { grid: { display: false } },
            y: { beginAtZero: true, ticks: { callback: (v) => '₱' + Number(v).toLocaleString() } },
            y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, ticks: { precision: 0 } }
          }
        }
      });
    } else {
      showEmpty(ctx, 'No earnings trend for this period');
    }
  }

  syncFilterUrl(getActiveReportTab());
  applyReportSearch(document.getElementById('reportSearchInput')?.value || '');
}

async function loadEarningsDss(opts = {}){
  const period = opts.period || currentEarnPeriod || '3m';
  const vehicle = opts.vehicle != null ? String(opts.vehicle) : String(currentEarnVehicle || '0');
  const from = opts.from || currentEarnFrom;
  const to = opts.to || currentEarnTo;
  currentEarnPeriod = period;
  currentEarnVehicle = vehicle;
  if (from) currentEarnFrom = from;
  if (to) currentEarnTo = to;

  const params = new URLSearchParams({
    ajax: 'earnings_dss',
    period,
    vehicle
  });
  if (period === 'custom') {
    params.set('from', from);
    params.set('to', to);
  }

  try {
    const res = await fetch('reports.php?' + params.toString());
    const data = await res.json();
    applyEarningsData(data);
  } catch (e) {
    showToast('Failed to load earnings data', false);
  }
}

function closeEarnFilterMenu(){
  const menu = document.getElementById('earnFilterMenu');
  const btn = document.getElementById('earnFilterBtn');
  if (menu) menu.classList.remove('open');
  if (btn) {
    btn.classList.remove('open');
    btn.setAttribute('aria-expanded', 'false');
  }
}

const fleetStatusCtx = document.getElementById('fleetStatusChart');
if(fleetStatusCtx && fleetStatusData.length){
  reportCharts.push(new Chart(fleetStatusCtx, {
    type: 'doughnut',
    data: {
      labels: fleetStatusData.map(x => x.status),
      datasets: [{ data: fleetStatusData.map(x => x.count), backgroundColor: ['#10b981','#f59e0b','#6366f1','#ef4444','#5dd0ff','#7cffc7','#8b5cf6'], borderWidth: 0 }]
    },
    options: { responsive:true, maintainAspectRatio:false, plugins:{ legend:{ position:'bottom' } } }
  }));
} else { showEmpty(fleetStatusCtx, 'No fleet status data available'); }

const topUtilizationCtx = document.getElementById('topUtilizationChart');
if(topUtilizationCtx && topUtilizationVehicles.length){
  reportCharts.push(new Chart(topUtilizationCtx, {
    type: 'bar',
    data: {
      labels: topUtilizationVehicles.map(x => x.identifier),
      datasets: [{ label: 'Utilization %', data: topUtilizationVehicles.map(x => x.utilization_rate), backgroundColor: 'rgba(93, 208, 255, 0.6)', borderRadius: 4 }]
    },
    options: { indexAxis:'y', responsive:true, maintainAspectRatio:false, plugins:{ legend:{ display:false } }, scales:{ x:{ beginAtZero:true, max:100 } } }
  }));
} else { showEmpty(topUtilizationCtx, 'No utilization data available'); }

const maintenanceWorkloadCtx = document.getElementById('maintenanceWorkloadChart');
if(maintenanceWorkloadCtx && maintenanceBacklogByStatus.length){
  reportCharts.push(new Chart(maintenanceWorkloadCtx, {
    type: 'bar',
    data: {
      labels: maintenanceBacklogByStatus.map(x => x.status),
      datasets: [{ label: 'Tasks', data: maintenanceBacklogByStatus.map(x => x.count), backgroundColor: 'rgba(245, 158, 11, 0.7)', borderRadius: 4 }]
    },
    options: { responsive:true, maintainAspectRatio:false, plugins:{ legend:{ display:false } }, scales:{ y:{ beginAtZero:true } } }
  }));
} else { showEmpty(maintenanceWorkloadCtx, 'No maintenance workload data available'); }

const maintCatCtx = document.getElementById('maintCategoryChart');
if (maintCatCtx && maintCategoryChartData.length) {
  reportCharts.push(new Chart(maintCatCtx, {
    type: 'doughnut',
    data: {
      labels: maintCategoryChartData.map(x => x.label),
      datasets: [{
        data: maintCategoryChartData.map(x => x.amount),
        backgroundColor: ['#5dd0ff','#7cffc7','#f59e0b','#a78bfa','#ef4444','#34d399'],
        borderWidth: 0
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { position: 'bottom' },
        tooltip: {
          callbacks: {
            label: (ctx) => ` ₱${Number(ctx.raw || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
          }
        }
      }
    }
  }));
} else { showEmpty(maintCatCtx, 'No category expense data'); }

const maintStatusCtx = document.getElementById('maintStatusChart');
if (maintStatusCtx && maintStatusChartData.length) {
  reportCharts.push(new Chart(maintStatusCtx, {
    type: 'doughnut',
    data: {
      labels: maintStatusChartData.map(x => x.label),
      datasets: [{
        data: maintStatusChartData.map(x => x.count),
        backgroundColor: ['#34d399','#f59e0b','#5dd0ff','#a78bfa','#ef4444','#94a3b8'],
        borderWidth: 0
      }]
    },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
  }));
} else { showEmpty(maintStatusCtx, 'No status data'); }

const maintVehicleCtx = document.getElementById('maintVehicleChart');
if (maintVehicleCtx && maintVehicleChartData.length) {
  reportCharts.push(new Chart(maintVehicleCtx, {
    type: 'bar',
    data: {
      labels: maintVehicleChartData.map(x => x.label),
      datasets: [{
        label: 'Expense',
        data: maintVehicleChartData.map(x => x.amount),
        backgroundColor: 'rgba(93, 208, 255, 0.65)',
        borderRadius: 4
      }]
    },
    options: {
      indexAxis: 'y',
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: { x: { beginAtZero: true } }
    }
  }));
} else { showEmpty(maintVehicleCtx, 'No vehicle expense data'); }

const maintMonthCtx = document.getElementById('maintMonthChart');
if (maintMonthCtx && maintMonthChartData.length) {
  reportCharts.push(new Chart(maintMonthCtx, {
    type: 'line',
    data: {
      labels: maintMonthChartData.map(x => x.label),
      datasets: [{
        label: 'Spend (₱)',
        data: maintMonthChartData.map(x => x.amount),
        borderColor: '#7cffc7',
        backgroundColor: 'rgba(124, 255, 199, 0.15)',
        fill: true,
        tension: 0.35,
        pointRadius: 4
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: { y: { beginAtZero: true } }
    }
  }));
} else { showEmpty(maintMonthCtx, 'No monthly spend data'); }

function applyMaintenanceTableFilters(){
  const cat = (document.getElementById('maintCategoryFilter')?.value || '').toLowerCase();
  const st = (document.getElementById('maintStatusFilter')?.value || '').toLowerCase();
  const q = (document.getElementById('reportSearchInput')?.value || '').trim().toLowerCase();
  const tbody = document.querySelector('#maintSummaryTable tbody');
  const hint = document.getElementById('maintFilterHint');
  if (!tbody) return;

  let visible = 0;
  let totalCost = 0;
  tbody.querySelectorAll('tr').forEach(row => {
    if (row.querySelector('td[colspan]') || row.dataset.searchEmpty) {
      row.style.display = 'none';
      return;
    }
    const rowCat = (row.dataset.category || '').toLowerCase();
    const rowSt = (row.dataset.status || '').toLowerCase();
    const text = row.textContent.toLowerCase();
    const matchCat = !cat || rowCat === cat;
    const matchSt = !st || rowSt === st;
    const matchQ = !q || text.includes(q);
    const show = matchCat && matchSt && matchQ;
    row.style.display = show ? '' : 'none';
    if (show) {
      visible++;
      const costText = row.children[6]?.textContent || '';
      const num = parseFloat(costText.replace(/[^\d.-]/g, ''));
      if (!Number.isNaN(num)) totalCost += num;
    }
  });

  let emptyRow = tbody.querySelector('tr[data-maint-empty]');
  if (visible === 0) {
    if (!emptyRow) {
      emptyRow = document.createElement('tr');
      emptyRow.dataset.maintEmpty = '1';
      emptyRow.innerHTML = '<td colspan="8" style="text-align:center;padding:28px;color:var(--text-muted)">No matching maintenance records.</td>';
      tbody.appendChild(emptyRow);
    }
    emptyRow.style.display = '';
  } else if (emptyRow) {
    emptyRow.style.display = 'none';
  }

  if (hint) {
    hint.textContent = visible === 0
      ? 'No matching records'
      : `Showing ${visible} job${visible === 1 ? '' : 's'} · ₱${totalCost.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
  }
}

document.getElementById('maintCategoryFilter')?.addEventListener('change', applyMaintenanceTableFilters);
document.getElementById('maintStatusFilter')?.addEventListener('change', applyMaintenanceTableFilters);

applyEarningsData(earningsActiveData);

const earnFilterBtn = document.getElementById('earnFilterBtn');
const earnFilterMenu = document.getElementById('earnFilterMenu');
if (earnFilterBtn && earnFilterMenu) {
  earnFilterBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    const open = !earnFilterMenu.classList.contains('open');
    earnFilterMenu.classList.toggle('open', open);
    earnFilterBtn.classList.toggle('open', open);
    earnFilterBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
  });
  earnFilterMenu.addEventListener('click', (e) => e.stopPropagation());
  document.addEventListener('click', () => closeEarnFilterMenu());
}
document.querySelectorAll('[data-earn-preset]').forEach(btn => {
  btn.addEventListener('click', () => {
    applyReportFilter({ period: btn.dataset.earnPreset });
    closeEarnFilterMenu();
  });
});
const earnApplyCustom = document.getElementById('earnApplyCustom');
if (earnApplyCustom) {
  earnApplyCustom.addEventListener('click', () => {
    const from = document.getElementById('earnFrom')?.value || '';
    const to = document.getElementById('earnTo')?.value || '';
    if (!from || !to || to < from) {
      showToast('Please choose a valid date range', false);
      return;
    }
    applyReportFilter({ period: 'custom', from, to });
    closeEarnFilterMenu();
  });
}
const earnVehicleSelect = document.getElementById('earnVehicleSelect');
if (earnVehicleSelect) {
  earnVehicleSelect.addEventListener('change', () => {
    applyReportFilter({ vehicle: earnVehicleSelect.value });
  });
}

function applyReportSearch(query){
  const q = String(query || '').trim().toLowerCase();

  const select = document.getElementById('earnVehicleSelect');
  if (select) {
    const selected = select.value;
    Array.from(select.options).forEach((opt, idx) => {
      if (idx === 0) {
        opt.hidden = false;
        return;
      }
      const match = !q || opt.textContent.toLowerCase().includes(q);
      const keepSelected = opt.value === selected;
      opt.hidden = !(match || keepSelected);
    });
  }

  const activePanel = document.querySelector('.tab-panel.active');
  if (!activePanel) return;

  activePanel.querySelectorAll('.data-table tbody').forEach(tbody => {
    let visible = 0;
    tbody.querySelectorAll('tr').forEach(row => {
      if (row.querySelector('td[colspan]')) {
        row.style.display = q ? 'none' : '';
        return;
      }
      const text = row.textContent.toLowerCase();
      const show = !q || text.includes(q);
      row.style.display = show ? '' : 'none';
      if (show) visible++;
    });

    let emptyRow = tbody.querySelector('tr[data-search-empty]');
    if (q && visible === 0) {
      if (!emptyRow) {
        const cols = tbody.closest('table')?.querySelectorAll('thead th').length || 1;
        emptyRow = document.createElement('tr');
        emptyRow.dataset.searchEmpty = '1';
        emptyRow.innerHTML = '<td colspan="' + cols + '" style="text-align:center;padding:28px;color:var(--text-muted)">No matching results.</td>';
        tbody.appendChild(emptyRow);
      }
      emptyRow.style.display = '';
    } else if (emptyRow) {
      emptyRow.style.display = 'none';
    }
  });
}

const reportSearchInput = document.getElementById('reportSearchInput');
if (reportSearchInput) {
  reportSearchInput.addEventListener('input', () => {
    if (document.querySelector('.tab-panel.active')?.id === 'panel-maintenance') {
      applyMaintenanceTableFilters();
    } else {
      applyReportSearch(reportSearchInput.value);
    }
  });
  reportSearchInput.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      reportSearchInput.value = '';
      if (document.querySelector('.tab-panel.active')?.id === 'panel-maintenance') {
        applyMaintenanceTableFilters();
      } else {
        applyReportSearch('');
      }
      reportSearchInput.blur();
    }
  });
}

function switchReportTab(tabId){
  if (tabId !== 'earnings' && window.__reportsFilterDirty) {
    const params = new URLSearchParams({
      tab: tabId,
      period: currentEarnPeriod,
      vehicle: String(currentEarnVehicle || '0'),
      from: currentEarnFrom,
      to: currentEarnTo
    });
    window.location.href = 'reports.php?' + params.toString();
    return;
  }

  const tabMeta = {
    earnings: { title: 'Earnings', subtitle: 'Earnings, maintenance expenses (liability), and net income — filter by period and vehicle' },
    operations: { title: 'Operations Overview', subtitle: 'Operational KPIs and fleet performance summaries for day-to-day management' },
    utilization: { title: 'Vehicle Utilization', subtitle: 'Vehicle usage rates and next-month forecast based on recent rental activity' },
    rentals: { title: 'Rentals Summary', subtitle: 'Rental bookings, payments, and status for the selected date range' },
    returns: { title: 'Return Inspections', subtitle: 'Return inspection results, fuel levels, penalties, and final costs' },
    maintenance: { title: 'Maintenance Summary', subtitle: 'Total maintenance expenses, KPIs, and charts for the selected period' }
  };

  document.querySelectorAll('.report-tab').forEach(btn => {
    const active = btn.dataset.tab === tabId;
    btn.classList.toggle('active', active);
    btn.setAttribute('aria-selected', active ? 'true' : 'false');
  });
  document.querySelectorAll('.tab-panel').forEach(panel => {
    panel.classList.toggle('active', panel.dataset.panel === tabId);
  });

  const meta = tabMeta[tabId] || tabMeta.earnings;
  const titleEl = document.getElementById('reportsPageTitle');
  const subtitleEl = document.getElementById('reportsPageSubtitle');
  if (titleEl) titleEl.textContent = meta.title;
  if (subtitleEl) subtitleEl.textContent = meta.subtitle;
  document.title = meta.title + ' — FleetGo Reports';

  syncFilterUrl(tabId);

  if (tabId === 'maintenance') {
    applyMaintenanceTableFilters();
    setTimeout(() => { reportCharts.forEach(c => { try { c.resize(); } catch(e) {} }); }, 50);
  } else {
    applyReportSearch(document.getElementById('reportSearchInput')?.value || '');
  }

  if (tabId === 'operations') {
    setTimeout(() => { reportCharts.forEach(c => { try { c.resize(); } catch(e) {} }); }, 50);
  }
  if (tabId === 'earnings') {
    setTimeout(() => { try { if (earningsTrendChart) earningsTrendChart.resize(); } catch(e) {} }, 50);
  }
}

document.querySelectorAll('.report-tab').forEach(btn => {
  btn.addEventListener('click', () => switchReportTab(btn.dataset.tab));
});

async function summarizeReport(){
  const box = document.getElementById('geminiReportBox');
  if (!box) return;
  box.hidden = false;
  box.textContent = 'Writing summary…';
  try {
    const res = await fetch('includes/ajax_gemini.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({
        action: 'report_summary',
        from: currentEarnFrom,
        to: currentEarnTo,
        vehicle_id: Number(currentEarnVehicle || 0),
        tab: (typeof getActiveReportTab === 'function') ? getActiveReportTab() : 'earnings'
      })
    });
    const data = await res.json();
    box.textContent = (data && data.ok) ? data.text : ((data && data.error) || 'Could not write the summary.');
  } catch (e) {
    box.textContent = 'Could not reach Gemini.';
  }
}

async function refreshStats(){
  try {
    const params = new URLSearchParams({
      ajax: 'quick_stats',
      from: currentEarnFrom,
      to: currentEarnTo,
      vehicle: String(currentEarnVehicle || '0')
    });
    const res = await fetch('reports.php?' + params.toString());
    const stats = await res.json();
    if(document.getElementById('fleetAvailability')) document.getElementById('fleetAvailability').textContent = (parseFloat(stats.availability_rate||0)).toFixed(1) + '%';
    if(document.getElementById('avgUtilization')) document.getElementById('avgUtilization').textContent = (parseFloat(stats.avg_utilization_rate||0)).toFixed(0) + '%';
    if(document.getElementById('maintenanceBacklog')) document.getElementById('maintenanceBacklog').textContent = parseInt(stats.maintenance_backlog||0).toLocaleString();
    if(document.getElementById('avgRentalDays')) document.getElementById('avgRentalDays').textContent = (parseFloat(stats.avg_rental_days||0)).toFixed(1) + ' days';
    if (getActiveReportTab() === 'earnings') {
      await loadEarningsDss();
    }
    showToast('Stats refreshed successfully', true);
  } catch(e){ showToast('Failed to refresh stats', false); }
}

function exportData(t){
  const params = new URLSearchParams({
    ajax: 'export_data',
    type: t,
    from: currentEarnFrom,
    to: currentEarnTo,
    vehicle: String(currentEarnVehicle || '0')
  });
  window.open('reports.php?' + params.toString(), '_blank');
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
