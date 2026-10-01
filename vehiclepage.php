<?php
/* ============================================================
   FleetGo — Vehicle Page (Public Access with User Features)
============================================================ */

/* ---------- SESSION FIX ---------- */
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
session_name('fleetgo_session_user');
session_start();

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/vehicle_promo.php';
require_once __DIR__ . '/includes/gemini.php';
vehicle_promo_ensure_columns($conn);
gemini_ensure_listing_column($conn);

/* --- Access Control --- */
// This page is public but has user-specific features
$isLoggedIn = isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'user';
$userName = $_SESSION['user_name'] ?? 'Guest';

// ===== FLEETGO FRMMS POLICIES =====
// Get user profile status for approval restriction
$isApproved = false;
if ($isLoggedIn) {
    $stmt = $conn->prepare("SELECT profile_status FROM users WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $userProfile = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $isApproved = ($userProfile['profile_status'] ?? '') === 'approved';
}

/* --- Track recent views (AJAX) --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax'] ?? '') == '1' && ($_POST['action'] ?? '') === 'mark_view') {
    header('Content-Type: application/json');
    if (!$isLoggedIn) {
        echo json_encode(['success' => false]);
        exit;
    }
    $vid = (int)($_POST['vehicle_id'] ?? 0);
    if ($vid <= 0) {
        echo json_encode(['success' => false]);
        exit;
    }
    try {
        $recentViewsEnabled = (bool)($conn->query("SHOW TABLES LIKE 'user_recent_views'")->fetch_assoc());
        if ($recentViewsEnabled) {
            $stmt = $conn->prepare("
                INSERT INTO user_recent_views (user_id, vehicle_id, viewed_at)
                VALUES (?, ?, NOW())
                ON DUPLICATE KEY UPDATE viewed_at = NOW()
            ");
            $stmt->bind_param('ii', $_SESSION['user_id'], $vid);
            $stmt->execute();
            $stmt->close();
        }
    } catch (Throwable $e) {
    }
    echo json_encode(['success' => true]);
    exit;
}

/* --- Fetch full vehicle details for customer modal --- */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['ajax'] ?? '') == '1' && ($_GET['action'] ?? '') === 'get_vehicle') {
    header('Content-Type: application/json');
    $vid = (int)($_GET['vehicle_id'] ?? 0);
    if ($vid <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid vehicle']);
        exit;
    }
    try {
        $stmt = $conn->prepare("SELECT * FROM vehicles WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $vid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            echo json_encode(['success' => false, 'error' => 'Vehicle not found']);
            exit;
        }
        $rows = [$row];
        hydrate_vehicle_fuel_levels($conn, $rows);
        $row = attach_promo_fields_to_vehicle($rows[0]);
        $row['img'] = vehicle_img($row);
        $row['fuel_level_label'] = format_fuel_level_label($row['fuel_level'] ?? '');
        echo json_encode(['success' => true, 'vehicle' => $row]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => 'Failed to load vehicle']);
    }
    exit;
}

/* --- Live promo prices for browse cards --- */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['ajax'] ?? '') == '1' && ($_GET['action'] ?? '') === 'promo_prices') {
    header('Content-Type: application/json');
    $raw = trim((string)($_GET['ids'] ?? ''));
    $ids = array_values(array_unique(array_filter(array_map('intval', preg_split('/[,\s]+/', $raw) ?: []), fn($id) => $id > 0)));
    if (!$ids) {
        echo json_encode(['success' => true, 'prices' => []]);
        exit;
    }
    $ids = array_slice($ids, 0, 80);
    try {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));
        $sql = "
            SELECT id, daily_rate, daily_rate_cdo, promo_discount_type, promo_discount_value,
                   promo_starts_at, promo_ends_at
            FROM vehicles WHERE id IN ($in)
        ";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            // Fallback without schedule columns
            $stmt = $conn->prepare("
                SELECT id, daily_rate, daily_rate_cdo, promo_discount_type, promo_discount_value
                FROM vehicles WHERE id IN ($in)
            ");
        }
        $stmt->bind_param($types, ...$ids);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $prices = [];
        foreach ($rows as $row) {
            $p = vehicle_promo_pricing($row);
            $prices[(string)$row['id']] = [
                'html' => render_vehicle_price_html($row),
                'effective' => $p['effective'],
                'base' => $p['base'],
                'has_promo' => $p['has_promo'],
                'label' => $p['label'],
            ];
        }
        echo json_encode(['success' => true, 'prices' => $prices, 'ts' => time()]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'prices' => [], 'error' => 'price_load_failed']);
    }
    exit;
}

/* --- Occupied dates for booking calendar (admin-approved rentals) --- */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['ajax'] ?? '') == '1' && ($_GET['action'] ?? '') === 'occupied_dates') {
    header('Content-Type: application/json');
    $vid = (int)($_GET['vehicle_id'] ?? 0);
    if ($vid <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid vehicle', 'ranges' => []]);
        exit;
    }
    $ranges = [];
    try {
        // Same statuses the booking conflict check blocks
        $stmt = $conn->prepare("
            SELECT start_date, end_date, status,
                   COALESCE(start_time, '00:00:00') AS start_time,
                   COALESCE(end_time, '23:59:59') AS end_time
            FROM rentals
            WHERE vehicle_id = ?
              AND status IN ('ongoing', 'reserved')
              AND end_date >= CURDATE()
            ORDER BY start_date ASC
        ");
        $stmt->bind_param('i', $vid);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            // Normalize to Y-m-d in case column is DATETIME
            $start = substr((string)($row['start_date'] ?? ''), 0, 10);
            $end = substr((string)($row['end_date'] ?? ''), 0, 10);
            if ($start === '' || $end === '') continue;
            $ranges[] = [
                'start' => $start,
                'end' => $end,
                'status' => (string)($row['status'] ?? ''),
                'start_time' => substr((string)$row['start_time'], 0, 8),
                'end_time' => substr((string)$row['end_time'], 0, 8),
            ];
        }
        $stmt->close();
        echo json_encode(['success' => true, 'ranges' => $ranges]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => 'Failed to load dates', 'ranges' => []]);
    }
    exit;
}

/* ==========================
  FETCH VEHICLES (Search / Filters / Sorting)
========================== */
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function format_fuel_level_label($level) {
  $fl = strtolower(trim((string)$level));
  if ($fl === '1/2') $fl = 'half';
  $map = [
    'full' => 'Full',
    '3/4' => '3/4 Tank',
    'half' => 'Half Tank',
    '1/4' => '1/4 Tank',
    'empty' => 'Empty',
  ];
  return $map[$fl] ?? '';
}

function hydrate_vehicle_fuel_levels(mysqli $conn, array &$rows) {
  if (empty($rows)) return;
  static $hasFuelCol = null;
  if ($hasFuelCol === null) {
    try {
      $chk = $conn->query("SHOW COLUMNS FROM vehicles LIKE 'fuel_level'");
      $hasFuelCol = $chk && $chk->num_rows > 0;
    } catch (Throwable $e) {
      $hasFuelCol = false;
    }
  }
  if (!$hasFuelCol) {
    foreach ($rows as &$row) {
      $row['fuel_level'] = $row['fuel_level'] ?? null;
    }
    unset($row);
    return;
  }

  $needIds = [];
  foreach ($rows as $row) {
    if (empty($row['fuel_level'])) {
      $needIds[] = (int)($row['id'] ?? 0);
    }
  }
  $needIds = array_values(array_filter(array_unique($needIds)));
  if (empty($needIds)) return;

  $fuelByVehicle = [];
  try {
    $in = implode(',', array_fill(0, count($needIds), '?'));
    $types = str_repeat('i', count($needIds));
    $stmt = $conn->prepare("
      SELECT r.vehicle_id, ri.fuel_level
      FROM return_inspections ri
      INNER JOIN rentals r ON r.id = ri.rental_id
      INNER JOIN (
        SELECT r2.vehicle_id, MAX(ri2.id) AS max_id
        FROM return_inspections ri2
        INNER JOIN rentals r2 ON r2.id = ri2.rental_id
        WHERE r2.vehicle_id IN ($in)
          AND ri2.fuel_level IS NOT NULL AND ri2.fuel_level <> ''
        GROUP BY r2.vehicle_id
      ) latest ON latest.max_id = ri.id
    ");
    if ($stmt) {
      $stmt->bind_param($types, ...$needIds);
      $stmt->execute();
      $res = $stmt->get_result();
      while ($fr = $res->fetch_assoc()) {
        $fl = (string)($fr['fuel_level'] ?? '');
        if ($fl === '1/2') $fl = 'half';
        $fuelByVehicle[(int)$fr['vehicle_id']] = $fl;
      }
      $stmt->close();
    }
  } catch (Throwable $e) {
    // ignore
  }

  foreach ($rows as &$row) {
    if (empty($row['fuel_level'])) {
      $vid = (int)($row['id'] ?? 0);
      if (isset($fuelByVehicle[$vid])) {
        $row['fuel_level'] = $fuelByVehicle[$vid];
      }
    }
  }
  unset($row);
}

function get_str($k, $default = '') {
    $v = $_GET[$k] ?? $default;
    if (!is_string($v)) return $default;
    return trim($v);
}
function get_int($k, $default = 0) {
    $v = $_GET[$k] ?? $default;
    return is_numeric($v) ? (int)$v : (int)$default;
}
function get_float($k, $default = null) {
    $v = $_GET[$k] ?? $default;
    if ($v === '' || $v === null) return $default;
    return is_numeric($v) ? (float)$v : $default;
}

$start_date = get_str('start_date');
$end_date = get_str('end_date');
$filter_type = get_str('vehicle_type');
$filter_trans = get_str('transmission');
$filter_seats = get_int('seats', 0);
$min_price = get_float('min_price');
$max_price = get_float('max_price');
$q = get_str('q');
$sort = get_str('sort', 'recommended');
$page = max(1, get_int('page', 1));
$perPage = 9;

$dateRangeValid = false;
if ($start_date !== '' && $end_date !== '') {
    $sd = strtotime($start_date);
    $ed = strtotime($end_date);
    if ($sd && $ed && $ed >= $sd) {
        $dateRangeValid = true;
    }
}

$activeFilterCount = 0;
if ($start_date !== '') $activeFilterCount++;
if ($end_date !== '') $activeFilterCount++;
if ($filter_type !== '') $activeFilterCount++;
if ($q !== '') $activeFilterCount++;
if ($filter_trans !== '') $activeFilterCount++;
if ($filter_seats > 0) $activeFilterCount++;
if ($min_price !== null) $activeFilterCount++;
if ($max_price !== null) $activeFilterCount++;

// Filter option lists
$typeOptions = [];
$transOptions = [];
$seatsOptions = [];
try {
    $typeOptions = $conn->query("SELECT DISTINCT vehicle_type FROM vehicles WHERE vehicle_type <> '' ORDER BY vehicle_type ASC")->fetch_all(MYSQLI_ASSOC);
    $transOptions = $conn->query("SELECT DISTINCT transmission FROM vehicles WHERE transmission <> '' ORDER BY transmission ASC")->fetch_all(MYSQLI_ASSOC);
    $seatsOptions = $conn->query("SELECT DISTINCT seats FROM vehicles WHERE seats IS NOT NULL ORDER BY seats ASC")->fetch_all(MYSQLI_ASSOC);
} catch (Throwable $e) {
    $typeOptions = [];
    $transOptions = [];
    $seatsOptions = [];
}

$where = [];
$params = [];
$types = '';

if ($q !== '') {
    $where[] = "(v.make_model LIKE ? OR v.vehicle_type LIKE ? OR v.maker LIKE ? OR v.model LIKE ?)";
    $like = '%' . $q . '%';
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
    $types .= 'ssss';
}
if ($filter_type !== '') {
    $where[] = "v.vehicle_type = ?";
    $params[] = $filter_type;
    $types .= 's';
}
if ($filter_trans !== '') {
    $where[] = "v.transmission = ?";
    $params[] = $filter_trans;
    $types .= 's';
}
if ($filter_seats > 0) {
    $where[] = "v.seats = ?";
    $params[] = $filter_seats;
    $types .= 'i';
}
if ($min_price !== null) {
    $where[] = "v.daily_rate >= ?";
    $params[] = $min_price;
    $types .= 'd';
}
if ($max_price !== null) {
    $where[] = "v.daily_rate <= ?";
    $params[] = $max_price;
    $types .= 'd';
}

if ($dateRangeValid) {
    $where[] = "v.current_status NOT IN ('maintenance','scheduled_maintenance','inspection','unavailable')";
    $where[] = "NOT EXISTS (\
        SELECT 1 FROM rentals rconf\
        WHERE rconf.vehicle_id = v.id\
          AND rconf.status IN ('ongoing','reserved')\
          AND rconf.start_date <= ?\
          AND rconf.end_date >= ?\
    )";
    $params[] = $end_date; $params[] = $start_date;
    $types .= 'ss';
}

$orderBy = "v.make_model ASC";
if ($sort === 'price_asc') $orderBy = "v.daily_rate ASC, v.make_model ASC";
elseif ($sort === 'price_desc') $orderBy = "v.daily_rate DESC, v.make_model ASC";
elseif ($sort === 'popular') $orderBy = "rental_count DESC, v.make_model ASC";
elseif ($sort === 'recent') $orderBy = "v.id DESC";

$fromSql = "
    FROM vehicles v
    LEFT JOIN (
        SELECT vehicle_id, COUNT(*) AS total
        FROM rentals
        GROUP BY vehicle_id
    ) rc ON rc.vehicle_id = v.id
    LEFT JOIN rentals ar ON ar.id = (
        SELECT r2.id
        FROM rentals r2
        WHERE r2.vehicle_id = v.id
          AND r2.status IN ('ongoing','reserved')
          AND r2.end_date >= CURDATE()
        ORDER BY FIELD(r2.status,'ongoing','reserved'), r2.end_date DESC, r2.id DESC
        LIMIT 1
    )
";
$whereSql = !empty($where) ? (' WHERE ' . implode(' AND ', $where)) : '';

$totalVehicles = 0;
try {
    $countSql = "SELECT COUNT(*) AS c " . $fromSql . $whereSql;
    $countStmt = $conn->prepare($countSql);
    if ($countStmt && $types !== '') {
        $countStmt->bind_param($types, ...$params);
    }
    if ($countStmt) {
        $countStmt->execute();
        $totalVehicles = (int)($countStmt->get_result()->fetch_assoc()['c'] ?? 0);
        $countStmt->close();
    }
} catch (Throwable $e) {
    $totalVehicles = 0;
}

$totalPages = max(1, (int)ceil($totalVehicles / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$sql = "
    SELECT
        v.id, v.make_model, v.vehicle_type, v.seats, v.max_capacity_kg, v.transmission,
        v.fuel_level, v.daily_rate, v.daily_rate_cdo, v.daily_rate_outside_cdo,
        v.promo_discount_type, v.promo_discount_value, v.promo_starts_at, v.promo_ends_at,
        v.current_status, v.photo, v.listing_description, v.id AS created_at,
        COALESCE(rc.total, 0) AS rental_count,
        ar.status AS active_rental_status,
        ar.end_date AS active_end_date
    " . $fromSql . $whereSql . "
    ORDER BY $orderBy
    LIMIT ? OFFSET ?
";

$vehicles = [];
try {
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if ($types !== '') {
            $bindTypes = $types . 'ii';
            $bindParams = array_merge($params, [$perPage, $offset]);
            $stmt->bind_param($bindTypes, ...$bindParams);
        } else {
            $stmt->bind_param('ii', $perPage, $offset);
        }
        $stmt->execute();
        $vehicles = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
} catch (Throwable $e) {
    error_log('vehiclepage vehicles query failed: ' . $e->getMessage());
    try {
        $all = $conn->query("
            SELECT id, make_model, vehicle_type, seats, max_capacity_kg, transmission,
                   fuel_level, daily_rate, daily_rate_cdo, daily_rate_outside_cdo,
                   promo_discount_type, promo_discount_value, promo_starts_at, promo_ends_at,
                   current_status, photo, id AS created_at,
                   0 AS rental_count, NULL AS active_rental_status, NULL AS active_end_date
            FROM vehicles
            ORDER BY make_model ASC
        ")->fetch_all(MYSQLI_ASSOC);
        $totalVehicles = count($all);
        $totalPages = max(1, (int)ceil($totalVehicles / $perPage));
        if ($page > $totalPages) $page = $totalPages;
        $offset = ($page - 1) * $perPage;
        $vehicles = array_slice($all, $offset, $perPage);
    } catch (Throwable $e2) {
        $vehicles = [];
        error_log('vehiclepage fallback query failed: ' . $e2->getMessage());
    }
}
hydrate_vehicle_fuel_levels($conn, $vehicles);

function browse_page_url(int $pageNum): string {
    $q = $_GET;
    $q['page'] = $pageNum;
    return 'vehiclepage.php?' . http_build_query($q);
}

// Recently viewed
$recentViewed = [];
try {
    if ($isLoggedIn) {
        $recentViewsEnabled = (bool)($conn->query("SHOW TABLES LIKE 'user_recent_views'")->fetch_assoc());
        if ($recentViewsEnabled) {
            $stmt = $conn->prepare("
                SELECT v.*
                FROM user_recent_views urv
                JOIN vehicles v ON v.id = urv.vehicle_id
                WHERE urv.user_id = ?
                ORDER BY urv.viewed_at DESC
                LIMIT 5
            ");
            $stmt->bind_param('i', $_SESSION['user_id']);
            $stmt->execute();
            $recentViewed = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }
    }
} catch (Throwable $e) {
    $recentViewed = [];
}
hydrate_vehicle_fuel_levels($conn, $recentViewed);

// Popular vehicles
$popularVehicles = [];
try {
    $popularRows = $conn->query("
        SELECT vehicle_id, COUNT(*) AS total
        FROM rentals
        GROUP BY vehicle_id
        ORDER BY total DESC
        LIMIT 5
    ")->fetch_all(MYSQLI_ASSOC);

    if (!empty($popularRows)) {
        $ids = array_map(fn($r) => (int)$r['vehicle_id'], $popularRows);
        $counts = [];
        foreach ($popularRows as $r) $counts[(int)$r['vehicle_id']] = (int)$r['total'];

        $in = implode(',', array_fill(0, count($ids), '?'));
        $t = str_repeat('i', count($ids));
        $stmt = $conn->prepare("SELECT * FROM vehicles WHERE id IN ($in)");
        $stmt->bind_param($t, ...$ids);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($rows as $row) {
            $row['_rental_count'] = $counts[(int)$row['id']] ?? 0;
            $popularVehicles[] = $row;
        }
        usort($popularVehicles, function($a,$b){
            return ((int)($b['_rental_count'] ?? 0)) <=> ((int)($a['_rental_count'] ?? 0));
        });
    }
} catch (Throwable $e) {
    $popularVehicles = [];
}
hydrate_vehicle_fuel_levels($conn, $popularVehicles);

/* ==========================
  HELPER FUNCTIONS
========================== */
function vehicle_img($row){
  $photo = trim((string)($row['photo'] ?? ''));
  if ($photo !== '' && is_file(__DIR__ . '/assets/vehicles/' . $photo)) {
    return 'assets/vehicles/' . h($photo);
  }
  return 'assets/vehicles/images.jpeg';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>FleetGo — Browse Vehicles</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link href="assets/css/fleetgo-shared.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<style>
  .flatpickr-calendar {
    background: #151a21;
    border: 1px solid rgba(255,255,255,0.1);
    box-shadow: 0 16px 40px rgba(0,0,0,0.45);
  }
  .flatpickr-months .flatpickr-month,
  .flatpickr-current-month .flatpickr-monthDropdown-months,
  .flatpickr-weekday,
  .flatpickr-day { color: #e8eef5; }
  /* Native <select> options use a light OS menu — force readable text */
  .flatpickr-monthDropdown-months option,
  .flatpickr-monthDropdown-months option:checked,
  .numInputWrapper span,
  select.flatpickr-monthDropdown-months option {
    color: #000 !important;
    background-color: #fff !important;
  }
  .flatpickr-day.flatpickr-disabled,
  .flatpickr-day.flatpickr-disabled:hover {
    color: rgba(156,163,175,0.45) !important;
    text-decoration: line-through;
    cursor: not-allowed;
  }
  .flatpickr-day.selected,
  .flatpickr-day.startRange,
  .flatpickr-day.endRange {
    background: #5dd0ff;
    border-color: #5dd0ff;
    color: #04121b;
  }
  .flatpickr-day:hover { background: rgba(93,208,255,0.15); }
  .book-occupied-hint {
    margin: -4px 0 12px;
    font-size: 0.8rem;
    font-weight: 600;
    color: rgba(156,163,175,0.95);
  }
</style>
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#0b0d10;
  --surface:#101419;
  --surface-2:#1a1d26;
  --text:#f2f6fa;
  --muted:#9ca3af;
  --border:rgba(255,255,255,0.10);
  --shadow:0 14px 30px rgba(0,0,0,0.35);
  --shadow-2:0 24px 70px rgba(0,0,0,0.45);
  --radius:18px;
  --radius-sm:12px;
  --brand:#5dd0ff;
  --brand-2:#7cffc7;
  --success:#10b981;
  --danger:#ef4444;
  --neutral:#6b7280;
  --gradient:linear-gradient(135deg,var(--brand),var(--brand-2));
}

body{font-family:Inter,system-ui,sans-serif;background:var(--bg);color:var(--text);line-height:1.55;min-height:100vh;}

.fg-browse{max-width:1560px;margin:0 auto;padding:28px 24px 80px;}

.hero{
  background:linear-gradient(135deg,rgba(11,13,16,.92),rgba(16,20,25,.85)),
    url('assets/herobanner1.jpg') center/cover;
  border:1px solid var(--border);
  border-radius:24px;
  padding:34px 26px;
  box-shadow:var(--shadow);
}
.hero h1{font-size:clamp(1.8rem,2.8vw,2.5rem);letter-spacing:-0.6px;font-weight:900;}
.hero p{color:rgba(242,246,250,0.80);margin-top:8px;max-width:720px;font-weight:650;}

.browse-toolbar{
  margin-top:18px;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  flex-wrap:wrap;
}
.browse-toolbar .results-meta{display:flex;align-items:baseline;gap:10px;flex-wrap:wrap;}
.filter-toggle{
  display:inline-flex;
  align-items:center;
  gap:10px;
  padding:11px 16px;
  border-radius:12px;
  border:1px solid rgba(255,255,255,0.14);
  background:rgba(255,255,255,0.04);
  color:var(--text);
  font-weight:900;
  font-size:0.9rem;
  cursor:pointer;
  font-family:inherit;
  transition:all .15s ease;
}
.filter-toggle:hover{
  border-color:rgba(93,208,255,0.35);
  background:rgba(93,208,255,0.08);
}
.filter-toggle[aria-expanded="true"]{
  border-color:rgba(93,208,255,0.45);
  background:rgba(93,208,255,0.12);
  color:var(--brand);
}
.filter-toggle .chev{
  width:0;height:0;
  border-left:5px solid transparent;
  border-right:5px solid transparent;
  border-top:6px solid currentColor;
  transition:transform .2s ease;
}
.filter-toggle[aria-expanded="true"] .chev{ transform:rotate(180deg); }
.filter-count{
  min-width:22px;height:22px;padding:0 7px;
  border-radius:999px;
  background:linear-gradient(135deg,var(--brand),var(--brand2));
  color:#04121b;
  font-size:0.75rem;font-weight:1000;
  display:inline-flex;align-items:center;justify-content:center;
}
.filter-count:empty,.filter-count[data-count="0"]{ display:none; }

.filter-panel{
  margin-top:12px;
  background:var(--surface);
  border:1px solid var(--border);
  border-radius:20px;
  box-shadow:var(--shadow);
  overflow:hidden;
  max-height:0;
  opacity:0;
  transition:max-height .28s ease, opacity .2s ease, margin .2s ease, padding .2s ease;
  padding:0 18px;
}
.filter-panel.open{
  max-height:900px;
  opacity:1;
  padding:18px;
  margin-top:12px;
}
.filter-grid{
  display:grid;
  grid-template-columns:repeat(4, minmax(0, 1fr));
  gap:14px;
  align-items:end;
}
.filter-grid .field-span-2{ grid-column:span 2; }
.filter-grid .price-row{ display:grid; grid-template-columns:1fr 1fr; gap:10px; }
.filter-panel-actions{
  display:flex;
  gap:10px;
  flex-wrap:wrap;
  margin-top:16px;
  padding-top:14px;
  border-top:1px solid rgba(255,255,255,0.08);
}
.filter-panel-actions .btn{ min-width:120px; }
.toggle{display:flex;align-items:center;gap:10px;font-weight:900;color:rgba(242,246,250,0.78);padding-bottom:4px;}
.toggle input{width:16px;height:16px;}

.field label{display:block;font-size:.82rem;color:rgba(242,246,250,0.78);font-weight:600;margin-bottom:6px;}
.control{
  width:100%;
  padding:12px 12px;
  border:1px solid rgba(255,255,255,0.14);
  border-radius:12px;
  background:rgba(16,20,25,0.90);
  font-family:Inter, system-ui, sans-serif;
  font-size:0.95rem;
  font-weight:400;
  color:var(--text);
  outline:none;
  transition:box-shadow .15s ease,border-color .15s ease;
}
.control:focus{border-color:rgba(93,208,255,0.65);box-shadow:0 0 0 4px rgba(93,208,255,0.12);}
select.control,
.sort select.control{
  font-family:Inter, system-ui, sans-serif;
  font-weight:400;
  font-size:0.95rem;
}
select.control option{
  font-family:Inter, system-ui, sans-serif;
  font-weight:400;
}
.sort label{font-size:.82rem;color:rgba(242,246,250,0.78);font-weight:600;}

.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;border-radius:12px;padding:12px 14px;font-weight:900;border:1px solid transparent;cursor:pointer;text-decoration:none;transition:transform .12s ease, box-shadow .12s ease, background .12s ease, border-color .12s ease;}
.btn:active{transform:translateY(1px);}
.btn-primary{background:var(--gradient);color:#041b22;box-shadow:0 12px 26px rgba(93,208,255,0.18);}
.btn-primary:hover{box-shadow:0 16px 34px rgba(93,208,255,0.24);}
.btn-secondary{background:rgba(255,255,255,0.04);border-color:rgba(255,255,255,0.14);color:rgba(242,246,250,0.92);}
.btn-secondary:hover{border-color:rgba(93,208,255,0.35);box-shadow:0 12px 24px rgba(0,0,0,0.25);}
.btn-ghost{background:transparent;border-color:rgba(255,255,255,0.14);color:rgba(242,246,250,0.78);}
.btn-ghost:hover{background:rgba(255,255,255,0.04);}
.btn-disabled{background:rgba(255,255,255,0.05);border-color:rgba(255,255,255,0.10);color:rgba(242,246,250,0.45);cursor:not-allowed;box-shadow:none;}

.layout{display:block;margin-top:18px;}
.card-ui{background:var(--surface);border:1px solid var(--border);border-radius:20px;box-shadow:var(--shadow);}

.results-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 16px;margin-bottom:14px;flex-wrap:wrap;}
.results-meta{display:flex;align-items:baseline;gap:10px;flex-wrap:wrap;}
.count{font-weight:1000;letter-spacing:-0.3px;}
.subcount{color:rgba(242,246,250,0.70);font-weight:850;font-size:.9rem;}
.sort{display:flex;align-items:center;gap:10px;}

.grid{display:grid;grid-template-columns:repeat(auto-fill, minmax(330px, 1fr));gap:16px;}
.grid-compact{grid-template-columns:repeat(auto-fill, minmax(280px, 1fr));}
.v-card{border-radius:20px;overflow:hidden;background:var(--surface);border:1px solid rgba(255,255,255,0.10);box-shadow:var(--shadow);transition:transform .18s ease, box-shadow .18s ease;display:flex;flex-direction:column;}
.v-card:hover{transform:translateY(-3px);box-shadow:var(--shadow-2);}
.v-imgwrap{position:relative;overflow:hidden;height:190px;background:var(--surface-2);}
.v-img{width:100%;height:100%;object-fit:cover;transform:scale(1.01);transition:transform .35s ease;}
.v-card:hover .v-img{transform:scale(1.06);}
.badge{position:absolute;left:12px;top:12px;padding:7px 10px;border-radius:999px;font-weight:1000;font-size:.75rem;letter-spacing:.4px;text-transform:uppercase;color:#fff;}
.b-available{background:rgba(22,163,74,0.92);}
.b-rented{background:rgba(220,38,38,0.92);}
.b-maint{background:rgba(107,114,128,0.92);}
.v-body{padding:14px 14px 12px;display:flex;flex-direction:column;gap:10px;flex:1;}
.v-title{font-weight:1000;letter-spacing:-0.4px;font-size:1.06rem;line-height:1.25;}
.v-desc{margin-top:10px;color:var(--muted,#9aa6b3);font-size:.86rem;line-height:1.45;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;}
.v-price{font-weight:1100;font-size:1.2rem;letter-spacing:-0.4px;}
.v-price span{font-size:.85rem;color:rgba(242,246,250,0.62);font-weight:900;}
.v-price.has-promo{display:flex;flex-direction:column;align-items:flex-start;gap:4px;}
.v-price .promo-pill{
  display:inline-flex;align-items:center;
  padding:4px 9px;border-radius:999px;
  background:linear-gradient(135deg,rgba(93,208,255,.95),rgba(124,255,199,.9));
  color:#041b22;font-size:.68rem;font-weight:1000;letter-spacing:.35px;text-transform:uppercase;
}
.v-price .price-now{font-weight:1100;font-size:1.2rem;letter-spacing:-0.4px;color:#7cffc7;}
.v-price .price-now span{font-size:.85rem;color:rgba(124,255,199,.75);font-weight:900;}
.v-price .price-was{font-size:.82rem;font-weight:800;color:rgba(242,246,250,0.45);text-decoration:line-through;}
.v-meta{display:flex;gap:8px;flex-wrap:wrap;color:rgba(242,246,250,0.72);font-weight:850;font-size:.9rem;}
.chip{display:inline-flex;align-items:center;gap:6px;padding:7px 10px;border-radius:999px;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.10);}
.v-actions{display:flex;gap:10px;align-items:center;margin-top:auto;padding-top:6px;}
.v-actions .btn{flex:1;}
.iconbtn{width:44px;flex:0 0 44px;display:inline-flex;align-items:center;justify-content:center;border-radius:12px;border:1px solid rgba(255,255,255,0.14);background:rgba(255,255,255,0.04);color:rgba(242,246,250,0.92);cursor:pointer;}
.iconbtn:hover{border-color:rgba(93,208,255,0.35);box-shadow:0 14px 26px rgba(0,0,0,0.35);}

.section-title{margin-top:26px;margin-bottom:10px;display:flex;align-items:baseline;justify-content:space-between;gap:12px;}
.section-title h2{font-size:1.25rem;font-weight:1100;letter-spacing:-0.4px;}
.section-title .hint{color:rgba(242,246,250,0.65);font-weight:850;font-size:.9rem;}

.pagination{
  display:flex;
  align-items:center;
  justify-content:center;
  flex-wrap:wrap;
  gap:8px;
  margin:28px 0 8px;
}
.pagination a,
.pagination span{
  min-width:40px;
  height:40px;
  padding:0 12px;
  border-radius:12px;
  border:1px solid rgba(255,255,255,0.14);
  background:rgba(255,255,255,0.04);
  color:rgba(242,246,250,0.92);
  font-family:Inter, system-ui, sans-serif;
  font-size:0.9rem;
  font-weight:600;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  text-decoration:none;
  transition:all .15s ease;
}
.pagination a:hover{
  border-color:rgba(93,208,255,0.4);
  background:rgba(93,208,255,0.1);
  color:#5dd0ff;
}
.pagination .active{
  background:linear-gradient(135deg, #5dd0ff, #7cffc7);
  border-color:transparent;
  color:#04121b !important;
  font-weight:800;
  box-shadow:0 8px 20px rgba(93,208,255,0.25);
}
.pagination .disabled{
  opacity:0.4;
  pointer-events:none;
}
.pagination-info{
  text-align:center;
  margin-top:8px;
  color:rgba(242,246,250,0.6);
  font-size:0.85rem;
  font-weight:500;
}

/* No horizontal scrolling sections (use aligned grids instead) */

.empty{display:grid;place-items:center;text-align:center;padding:40px 18px;background:var(--surface);border:1px dashed rgba(255,255,255,0.20);border-radius:20px;box-shadow:var(--shadow);}
.empty .ico{width:58px;height:58px;border-radius:18px;background:rgba(93,208,255,0.10);display:grid;place-items:center;color:rgba(93,208,255,0.95);margin-bottom:12px;}
.empty h3{font-size:1.2rem;font-weight:1100;letter-spacing:-0.4px;}
.empty p{margin-top:6px;color:rgba(242,246,250,0.65);font-weight:800;max-width:520px;}
.empty .actions{margin-top:14px;display:flex;gap:10px;justify-content:center;flex-wrap:wrap;}

/* Modal (booking + details) */
.modal{
  position:fixed;inset:0;background:rgba(0,0,0,.72);display:none;
  align-items:center;justify-content:center;z-index:10050;
  backdrop-filter:blur(10px);padding:16px;
  overflow:hidden;
}
.modal.show{display:flex}
.modal-content{
  background:var(--surface);padding:20px;border-radius:20px;
  max-width:560px;width:92%;
  max-height:min(92vh, 860px);
  margin:0;
  overflow:auto;
  box-shadow:var(--shadow-2);border:1px solid var(--border);position:relative;
}
#detailsModal .modal-content{
  width:min(860px, 96vw);
  max-width:860px;
  max-height:min(92vh, 820px);
  display:flex;
  flex-direction:column;
  overflow:hidden;
  padding:20px 22px;
}
#detailsModal #detailsTitle{
  margin:0 0 16px;
  font-size:1.25rem;
  font-weight:1100;
  letter-spacing:-0.4px;
}
#detailsModal #detailsBody{
  flex:1 1 auto;
  overflow:auto;
  min-height:0;
  padding-right:2px;
}
#detailsModal .details-actions{
  flex:0 0 auto;
  margin-top:14px;
  display:flex;
  gap:10px;
  padding-top:12px;
  border-top:1px solid rgba(255,255,255,0.08);
}
.view-car-sheet{
  width:min(460px, 96vw);
  max-width:460px;
  display:flex;
  flex-direction:column;
  gap:12px;
  padding:16px;
  overflow:hidden;
  position:relative;
}
.view-car-sheet img{
  width:100%;
  height:230px;
  object-fit:cover;
  border-radius:16px;
  border:1px solid rgba(255,255,255,0.10);
  background:rgba(255,255,255,0.03);
}
.view-car-close{
  position:absolute;
  top:24px;
  right:24px;
  width:36px;
  height:36px;
  border:0;
  border-radius:999px;
  background:rgba(0,0,0,.55);
  color:#fff;
  font-size:1.3rem;
  cursor:pointer;
}
#viewCarTitle{margin:0;font-size:1.25rem;font-weight:900;}
#viewCarMeta{color:rgba(242,246,250,.72);font-weight:700;line-height:1.45;}
#viewCarPrice{font-size:1.15rem;font-weight:900;color:#7cffc7;}
#viewCarBook{width:100%;margin-top:4px;}
#detailsModal .details-grid{
  display:grid;
  grid-template-columns:minmax(220px,0.95fr) minmax(280px,1.15fr);
  gap:24px;
  align-items:start;
}
#detailsModal .details-media{
  position:sticky;
  top:0;
}
#detailsModal .details-media img{
  width:100%;
  height:260px;
  object-fit:cover;
  border-radius:16px;
  border:1px solid rgba(255,255,255,0.10);
  background:rgba(255,255,255,0.03);
  display:block;
}
#detailsModal .details-media-caption{
  margin-top:10px;
  color:rgba(242,246,250,0.62);
  font-size:0.85rem;
  font-weight:700;
}
#detailsModal .details-specs{
  display:flex;
  flex-direction:column;
  gap:18px;
}
#detailsModal .details-section-title{
  margin:0 0 8px;
  font-size:0.72rem;
  font-weight:900;
  letter-spacing:0.55px;
  text-transform:uppercase;
  color:rgba(242,246,250,0.55);
}
#detailsModal .details-list{
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:10px;
}
#detailsModal .detail-item{
  background:rgba(255,255,255,0.035);
  border:1px solid rgba(255,255,255,0.08);
  border-radius:12px;
  padding:10px 12px;
  min-width:0;
}
#detailsModal .detail-item.wide{
  grid-column:1 / -1;
}
#detailsModal .detail-item .k{
  display:block;
  color:rgba(242,246,250,0.58);
  font-size:0.72rem;
  font-weight:800;
  letter-spacing:0.3px;
  margin-bottom:4px;
}
#detailsModal .detail-item .v{
  display:block;
  color:var(--text);
  font-size:0.95rem;
  font-weight:800;
  line-height:1.3;
  word-break:break-word;
}
#detailsModal .detail-item .v.accent{
  color:var(--brand-2);
}
@media (max-width: 760px){
  #detailsModal .details-grid{
    grid-template-columns:1fr;
  }
  #detailsModal .details-media{position:static;}
  #detailsModal .details-media img{
    height:220px;
  }
  #detailsModal .details-list{
    grid-template-columns:1fr;
  }
}
#bookModal .modal-content{
  width:min(680px, 96vw);
  max-width:680px;
  height:min(92vh, 820px);
  max-height:min(92vh, 820px);
  padding:0;
  display:flex;
  flex-direction:column;
  overflow:hidden;
}
#bookModal .book-modal-head{
  flex:0 0 auto;
  padding:18px 22px 10px;
  border-bottom:1px solid rgba(255,255,255,0.08);
}
#bookModal .book-modal-head h3{margin:0;}
#bookModal #bookForm{
  display:flex;
  flex-direction:column;
  flex:1 1 auto;
  min-height:0;
  overflow:hidden;
}
#bookModal .book-modal-body{
  flex:1 1 auto;
  min-height:0;
  overflow-y:auto;
  overscroll-behavior:contain;
  -webkit-overflow-scrolling:touch;
  padding:14px 22px 10px;
}
#bookModal .book-modal-footer{
  flex:0 0 auto;
  padding:12px 22px 18px;
  border-top:1px solid rgba(255,255,255,0.10);
  background:rgba(16,20,25,0.98);
}
#bookModal .book-modal-footer .booking-accept{margin:0 0 12px;}
#bookModal .booking-fuel-text{
  width:100%;
  padding:12px;
  border:1px solid rgba(255,255,255,0.14);
  border-radius:12px;
  font-size:1rem;
  font-weight:800;
  color:var(--text);
  background:rgba(16,20,25,0.90);
  line-height:1.4;
  user-select:none;
  pointer-events:none;
  caret-color:transparent;
  outline:none;
}
#bookModal .book-modal-footer button[type="submit"]{
  margin:0;
  display:block;
}
#bookModal #msgBox{margin-top:8px;margin-bottom:0;min-height:0;}
#bookModal #msgBox .alert.success{background:rgba(255,209,102,.14);border-color:rgba(255,209,102,.45);color:#ffe7a3;text-align:left;font-weight:700;line-height:1.45;}
.modal-content h3{margin:0 0 14px;font-size:1.2rem;font-weight:1100;letter-spacing:-0.4px;}
.modal-content label{display:block;font-weight:900;margin-top:10px;color:rgba(242,246,250,0.80);font-size:.85rem;}
.modal-content input{width:100%;padding:12px 12px;margin-top:6px;border:1px solid rgba(255,255,255,0.14);border-radius:12px;font-size:1rem;background:rgba(16,20,25,0.90);color:var(--text);}
.modal-content input:focus{border-color:rgba(93,208,255,0.65);outline:none;box-shadow:0 0 0 4px rgba(93,208,255,0.12);}
.modal-content button[type="submit"]{margin-top:12px;margin-bottom:8px;padding:12px 14px;border:0;border-radius:12px;font-weight:1000;width:100%;background:var(--gradient);color:#041b22;cursor:pointer;font-size:1rem;box-shadow:0 12px 26px rgba(93,208,255,0.18);transition:all .15s ease;}
.modal-content button[type="submit"]:hover{box-shadow:0 16px 34px rgba(93,208,255,0.24);}
#msgBox{min-height:1.2em;margin-top:8px;margin-bottom:4px;}

@media (max-width: 640px){
  #bookModal .modal-content{
    width:100%;
    max-width:100%;
    height:min(96vh, 900px);
    max-height:min(96vh, 900px);
    border-radius:16px;
  }
}

/* ===== OPTIMIZED DATE & TIME INPUTS ===== */

/* Input Groups */
 .modal-content .input-group{
  position:relative;margin-bottom:12px;
  background:rgba(255,255,255,0.03);border-radius:16px;
  padding:14px;border:1px solid rgba(255,255,255,0.08);
  transition:all .2s ease;backdrop-filter:blur(8px);
 }

 .modal-content .input-group:hover{border-color:rgba(93,208,255,0.22);background:rgba(93,208,255,0.03);}

 .modal-content .input-group:focus-within{border-color:rgba(93,208,255,0.45);background:rgba(93,208,255,0.05);box-shadow:0 0 0 3px rgba(93,208,255,.10);}

/* Labels */
 .modal-content label{display:flex;align-items:center;gap:8px;font-weight:1000;margin-bottom:10px;color:rgba(242,246,250,0.78);font-size:.85rem;text-transform:uppercase;letter-spacing:0.6px;}

 .modal-content label::before{content:"";width:7px;height:7px;background:var(--gradient);border-radius:50%;}

/* Date & Time Inputs */
 .modal-content input[type="date"],
 .modal-content input[type="time"],
 .modal-content select{width:100%;padding:12px 12px;border:1px solid rgba(255,255,255,0.14);border-radius:12px;font-size:1rem;font-weight:800;color:var(--text);background:rgba(16,20,25,0.90);transition:all .2s ease;}

.modal-content input[type="time"]{
  letter-spacing:1px;
}

 .modal-content input[type="date"]:hover,
 .modal-content input[type="time"]:hover,
 .modal-content select:hover{border-color:rgba(93,208,255,0.25);}

 .modal-content input[type="date"]:focus,
 .modal-content input[type="time"]:focus,
 .modal-content select:focus{border-color:rgba(93,208,255,0.65);outline:none;box-shadow:0 0 0 3px rgba(93,208,255,.12);}

/* Date/Time Rows */
.modal-content .date-time-row{
  display:grid;grid-template-columns:1fr 1fr;gap:12px;
  margin-bottom:12px;
}

.modal-content .date-time-row .input-group{
  margin-bottom:0;
}

.policy-trigger{
  width:100%;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  margin:4px 0 14px;
  padding:14px 16px;
  border:1px solid rgba(255,255,255,0.10);
  border-radius:14px;
  background:rgba(255,255,255,0.03);
  color:var(--text);
  cursor:pointer;
  text-align:left;
  transition:background .15s ease, border-color .15s ease;
}
.policy-trigger:hover{background:rgba(93,208,255,0.05);border-color:rgba(93,208,255,0.30);}
.policy-trigger__text{display:flex;flex-direction:column;gap:3px;min-width:0;}
.policy-trigger__title{font-weight:900;font-size:.92rem;}
.policy-trigger__sub{font-weight:700;font-size:.78rem;color:rgba(242,246,250,0.6);}
.policy-trigger__status{
  flex:0 0 auto;
  padding:5px 10px;
  border-radius:999px;
  font-size:.72rem;
  font-weight:900;
  letter-spacing:.3px;
  text-transform:uppercase;
  color:#fca5a5;
  background:rgba(239,68,68,0.12);
  border:1px solid rgba(239,68,68,0.30);
}
.policy-trigger.is-accepted{border-color:rgba(124,255,199,0.35);}
.policy-trigger.is-accepted .policy-trigger__status{
  color:#7cffc7;
  background:rgba(124,255,199,0.10);
  border-color:rgba(124,255,199,0.35);
}

.policy-modal{z-index:10060;}
.policy-modal .policy-modal-content{
  width:min(720px, 96vw);
  max-width:720px;
  max-height:min(90vh, 820px);
  padding:0;
  display:flex;
  flex-direction:column;
  overflow:hidden;
}
.policy-modal-head{
  flex:0 0 auto;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  padding:18px 22px 12px;
  border-bottom:1px solid rgba(255,255,255,0.08);
}
.policy-modal-head h3{margin:0;}
.policy-modal-close{
  width:34px;height:34px;
  border-radius:10px;
  border:1px solid rgba(255,255,255,0.12);
  background:rgba(255,255,255,0.04);
  color:var(--text);
  font-size:1.3rem;
  line-height:1;
  cursor:pointer;
}
.policy-modal-close:hover{background:rgba(255,255,255,0.08);}
.policy-modal-body{
  flex:1 1 auto;
  min-height:0;
  overflow-y:auto;
  overscroll-behavior:contain;
  padding:6px 22px 16px;
  color:rgba(242,246,250,0.78);
  font-size:.88rem;
  line-height:1.6;
}
.policy-section + .policy-section{
  margin-top:18px;
  padding-top:14px;
  border-top:1px solid rgba(255,255,255,0.08);
}
.policy-section__title{
  margin:12px 0 4px !important;
  font-size:1.05rem !important;
  color:var(--text);
}
.policy-section h4{
  margin:14px 0 6px;
  color:rgba(93,208,255,0.95);
  font-size:.9rem;
  font-weight:1000;
}
.policy-section p{margin:0 0 8px;}
.policy-section ul{margin:0 0 8px;padding-left:18px;}
.policy-section li{margin-bottom:4px;}
.policy-modal-footer{
  flex:0 0 auto;
  padding:14px 22px 18px;
  border-top:1px solid rgba(255,255,255,0.10);
  background:rgba(16,20,25,0.98);
}
.policy-modal-footer .booking-accept{margin:0 0 12px;}
.policy-modal-actions{display:flex;justify-content:flex-end;gap:10px;}
.policy-btn{
  padding:11px 18px;
  border-radius:12px;
  font-weight:900;
  font-size:.92rem;
  cursor:pointer;
  border:1px solid transparent;
  transition:opacity .15s ease, background .15s ease;
}
.policy-btn--ghost{background:rgba(255,255,255,0.04);border-color:rgba(255,255,255,0.14);color:var(--text);}
.policy-btn--ghost:hover{background:rgba(255,255,255,0.08);}
.policy-btn--primary{background:var(--gradient);color:#041b22;}
.policy-btn--primary:disabled{opacity:.45;cursor:not-allowed;}

.booking-accept{
  display:flex;
  align-items:flex-start;
  gap:10px;
  margin:0 0 8px;
  padding:12px 14px;
  border-radius:14px;
  border:1px solid rgba(255,255,255,0.10);
  background:rgba(255,255,255,0.03);
}
.booking-accept input[type="checkbox"]{
  width:18px;
  height:18px;
  margin:2px 0 0;
  flex:0 0 auto;
  accent-color:#5dd0ff;
  cursor:pointer;
}
.booking-accept label{
  margin:0;
  text-transform:none;
  letter-spacing:0;
  font-size:.86rem;
  font-weight:700;
  line-height:1.45;
  color:rgba(242,246,250,0.86);
  cursor:pointer;
}
.booking-accept label::before{display:none;}

 .modal-content button[type="submit"]{margin-top:12px;padding:12px 14px;border:0;border-radius:12px;font-weight:1000;width:100%;background:var(--gradient);color:#041b22;cursor:pointer;font-size:1rem;box-shadow:0 12px 26px rgba(93,208,255,0.18);transition:all .15s ease;}
 .modal-content button[type="submit"]:hover{box-shadow:0 16px 34px rgba(93,208,255,0.24);}
 .alert{margin-top:12px;padding:12px;border-radius:12px;font-weight:900;text-align:center;background:rgba(15,23,42,0.04);border:1px solid rgba(15,23,42,0.08);}
 .alert.success{background:rgba(22,163,74,0.10);border-color:rgba(22,163,74,0.18);color:rgba(22,101,52,0.95);}
 .alert.error{background:rgba(220,38,38,0.10);border-color:rgba(220,38,38,0.18);color:rgba(153,27,27,0.95);}

 .promo-preview {background: rgba(93,208,255,0.06);border: 1px solid rgba(93,208,255,0.20);border-radius: 14px;padding: 14px;margin: 12px 0;animation: slideIn 0.3s ease;}

 .promo-header {font-weight: 1100;color: rgba(93,208,255,0.95);margin-bottom: 10px;text-align: center;font-size: .95rem;}

.cost-breakdown {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.cost-line {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 4px 0;
}

 .cost-line.total {border-top: 1px solid rgba(15,23,42,0.12);padding-top: 8px;margin-top: 8px;font-weight: 1100;font-size: 1.05rem;color: rgba(22,101,52,0.95);}

 .cost-line.promo-discount {color: rgba(153,27,27,0.95);font-weight: 1000;}

 .promo-message {background: rgba(15,23,42,0.04);border-radius: 12px;padding: 10px 12px;margin-top: 10px;font-size: .9rem;color: rgba(15,23,42,0.72);text-align: center;font-weight: 800;}

@keyframes slideIn {
  from { opacity: 0; transform: translateY(-10px); }
  to { opacity: 1; transform: translateY(0); }
}

@media (max-width: 1100px){
  .fg-browse{padding:22px 18px 70px;}
  .filter-grid{grid-template-columns:repeat(2, minmax(0, 1fr));}
  .filter-grid .field-span-2{ grid-column:span 2; }
  .grid{grid-template-columns:repeat(auto-fill, minmax(320px, 1fr));}
  .grid-compact{grid-template-columns:repeat(auto-fill, minmax(260px, 1fr));}
}
@media (max-width: 860px){
  .grid{grid-template-columns:1fr;}
  .grid-compact{grid-template-columns:1fr;}
  .filter-grid{grid-template-columns:1fr;}
  .filter-grid .field-span-2{ grid-column:auto; }
}
</style>
</head>
<body>

<?php include __DIR__.'/includes/user_navbar.php'; ?>

<main class="fg-browse">
  <form method="get" action="vehiclepage.php" id="browseForm">
    <section class="hero">
      <h1>Browse Vehicles</h1>
      <p>Find the right vehicle for your next trip</p>
    </section>

    <?php if ($isLoggedIn && !empty($recentViewed)): ?>
      <div class="section-title">
        <h2>Recently Viewed Vehicles</h2>
        <div class="hint">Based on your browsing</div>
      </div>
      <div class="grid grid-compact">
            <?php foreach ($recentViewed as $rv):
          $rvStatus = strtolower(trim((string)($rv['current_status'] ?? '')));
          $rvMaint = in_array($rvStatus, ['maintenance','scheduled_maintenance','inspection','unavailable']);
          $rvCanBook = !$rvMaint && $isApproved;
          $rvPromo = vehicle_promo_pricing($rv);
        ?>
          <article class="v-card">
            <div class="v-imgwrap">
              <img class="v-img" src="<?= h(vehicle_img($rv)) ?>" alt="<?= h($rv['make_model'] ?? '') ?>" onerror="this.src='assets/vehicles/images.jpeg'">
            </div>
            <div class="v-body">
              <div class="v-title"><?= h($rv['make_model'] ?? '') ?></div>
              <?= render_vehicle_price_html($rv) ?>
              <div class="v-meta">
                <span class="chip"><?= h($rv['transmission'] ?? '') ?></span>
                <span class="chip"><?= (int)($rv['seats'] ?? 0) ?> seats</span>
                <?php if (!empty($rv['max_capacity_kg'])): ?>
                  <span class="chip"><?= number_format((float)$rv['max_capacity_kg'], 0) ?> KG Maximum Capacity</span>
                <?php endif; ?>
                <?php $rvFuelLabel = format_fuel_level_label($rv['fuel_level'] ?? ''); if ($rvFuelLabel !== ''): ?>
                  <span class="chip">Fuel: <?= h($rvFuelLabel) ?></span>
                <?php endif; ?>
                <span class="chip"><?= h($rv['vehicle_type'] ?? '') ?></span>
              </div>
              <div class="v-actions">
                <button class="btn btn-secondary js-details" type="button"
                  data-id="<?= (int)$rv['id'] ?>"
                  data-name="<?= h($rv['make_model'] ?? '') ?>"
                  data-type="<?= h($rv['vehicle_type'] ?? '') ?>"
                  data-trans="<?= h($rv['transmission'] ?? '') ?>"
                  data-seats="<?= (int)($rv['seats'] ?? 0) ?>"
                  data-capacity="<?= h($rv['max_capacity_kg'] ?? '') ?>"
                  data-fuel="<?= h($rv['fuel_level'] ?? '') ?>"
                  data-rate="<?= number_format($rvPromo['effective'], 2, '.', '') ?>"
                  data-base-rate="<?= number_format($rvPromo['base'], 2, '.', '') ?>"
                  data-promo="<?= $rvPromo['has_promo'] ? '1' : '0' ?>"
                  data-promo-label="<?= h($rvPromo['label']) ?>"
                  data-status="<?= h($rv['current_status'] ?? '') ?>"
                  data-maint="<?= $rvMaint ? '1' : '0' ?>"
                  data-img="<?= h(vehicle_img($rv)) ?>">View Details</button>
                <?php if ($rvCanBook): ?>
                  <button class="btn btn-primary btn-book" type="button" data-id="<?= (int)$rv['id'] ?>" data-name="<?= h($rv['make_model'] ?? '') ?>" data-seats="<?= (int)($rv['seats'] ?? 0) ?>" data-fuel="<?= h($rv['fuel_level'] ?? '') ?>">Book Now</button>
                <?php elseif ($rvMaint): ?>
                  <button class="btn btn-disabled" type="button" disabled>Maintenance</button>
                <?php else: ?>
                  <button class="btn btn-disabled" type="button" disabled>Profile Pending</button>
                <?php endif; ?>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="layout">
      <div class="browse-toolbar">
        <button type="button" class="filter-toggle" id="filterToggle" aria-expanded="<?= $activeFilterCount > 0 ? 'true' : 'false' ?>" aria-controls="filterPanel">
          <span>Filters</span>
          <span class="filter-count" id="filterCount" data-count="<?= (int)$activeFilterCount ?>"><?= $activeFilterCount > 0 ? (int)$activeFilterCount : '' ?></span>
          <span class="chev" aria-hidden="true"></span>
        </button>
        <div class="sort">
          <label for="sort">Sort</label>
          <select class="control" name="sort" id="sort" onchange="document.getElementById('browseForm').submit()" style="min-width:200px;">
            <option value="recommended" <?= $sort === 'recommended' ? 'selected' : '' ?>>Recommended</option>
            <option value="price_asc" <?= $sort === 'price_asc' ? 'selected' : '' ?>>Price: Low to High</option>
            <option value="price_desc" <?= $sort === 'price_desc' ? 'selected' : '' ?>>Price: High to Low</option>
            <option value="popular" <?= $sort === 'popular' ? 'selected' : '' ?>>Most Popular</option>
            <option value="recent" <?= $sort === 'recent' ? 'selected' : '' ?>>Recently Added</option>
          </select>
        </div>
      </div>

      <div class="filter-panel<?= $activeFilterCount > 0 ? ' open' : '' ?>" id="filterPanel">
        <div class="filter-grid">
          <div class="field">
            <label for="start_date">Start date</label>
            <input class="control" type="date" name="start_date" id="start_date" value="<?= h($start_date) ?>">
          </div>
          <div class="field">
            <label for="end_date">End date</label>
            <input class="control" type="date" name="end_date" id="end_date" value="<?= h($end_date) ?>">
          </div>
          <div class="field">
            <label for="vehicle_type">Vehicle type</label>
            <select class="control" name="vehicle_type" id="vehicle_type">
              <option value="">Any type</option>
              <?php foreach ($typeOptions as $opt):
                $val = (string)($opt['vehicle_type'] ?? '');
                if ($val === '') continue;
              ?>
                <option value="<?= h($val) ?>" <?= $filter_type === $val ? 'selected' : '' ?>><?= h($val) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label for="transmission">Transmission</label>
            <select class="control" name="transmission" id="transmission">
              <option value="">Any</option>
              <?php foreach ($transOptions as $opt):
                $val = (string)($opt['transmission'] ?? '');
                if ($val === '') continue;
              ?>
                <option value="<?= h($val) ?>" <?= $filter_trans === $val ? 'selected' : '' ?>><?= h($val) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field field-span-2">
            <label for="q">Search</label>
            <input class="control" type="text" name="q" id="q" value="<?= h($q) ?>" placeholder="Make / model / type">
          </div>
          <div class="field">
            <label for="seats">Seats</label>
            <select class="control" name="seats" id="seats">
              <option value="0">Any</option>
              <?php foreach ($seatsOptions as $opt):
                $val = (int)($opt['seats'] ?? 0);
                if ($val <= 0) continue;
              ?>
                <option value="<?= (int)$val ?>" <?= $filter_seats === $val ? 'selected' : '' ?>><?= (int)$val ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label>Price range (₱ / day)</label>
            <div class="price-row">
              <input class="control" type="number" name="min_price" value="<?= $min_price === null ? '' : h((string)$min_price) ?>" placeholder="Min" min="0" step="1">
              <input class="control" type="number" name="max_price" value="<?= $max_price === null ? '' : h((string)$max_price) ?>" placeholder="Max" min="0" step="1">
            </div>
          </div>
        </div>
        <div class="filter-panel-actions">
          <button class="btn btn-primary" type="submit">Apply filters</button>
          <a class="btn btn-ghost" href="vehiclepage.php">Reset</a>
        </div>
      </div>

      <section style="margin-top:16px;">
        <div class="card-ui results-head">
          <div class="results-meta">
            <div class="count"><?= (int)$totalVehicles ?> vehicles</div>
            <div class="subcount">
              <?php if ($totalVehicles > 0): ?>
                showing <?= (int)($offset + 1) ?>–<?= (int)min($offset + count($vehicles), $totalVehicles) ?>
                <?= $dateRangeValid ? (' · ' . h(date('M j', strtotime($start_date))) . ' → ' . h(date('M j', strtotime($end_date)))) : '' ?>
              <?php else: ?>
                <?= $dateRangeValid ? ('for ' . h(date('M j', strtotime($start_date))) . ' → ' . h(date('M j', strtotime($end_date)))) : 'in the fleet' ?>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <?php if (!empty($vehicles)): ?>
          <div class="grid">
            <?php foreach ($vehicles as $v):
              $status = strtolower(trim((string)($v['current_status'] ?? '')));
              $isMaint = in_array($status, ['maintenance','scheduled_maintenance','inspection','unavailable']);
              $canBook = !$isMaint && $isLoggedIn && $isApproved;
              $vPromo = vehicle_promo_pricing($v);
            ?>
              <article class="v-card">
                <div class="v-imgwrap">
                  <img class="v-img" src="<?= h(vehicle_img($v)) ?>" alt="<?= h($v['make_model'] ?? '') ?>" onerror="this.src='assets/vehicles/images.jpeg'">
                </div>
                <div class="v-body">
                  <div>
                    <div class="v-title"><?= h($v['make_model'] ?? '') ?></div>
                    <div class="v-meta" style="margin-top:8px;">
                      <span class="chip"><?= h($v['transmission'] ?? '') ?></span>
                      <span class="chip"><?= (int)($v['seats'] ?? 0) ?> seats</span>
                      <?php if (!empty($v['max_capacity_kg'])): ?>
                        <span class="chip"><?= number_format((float)$v['max_capacity_kg'], 0) ?> KG Maximum Capacity</span>
                      <?php endif; ?>
                      <?php $vFuelLabel = format_fuel_level_label($v['fuel_level'] ?? ''); if ($vFuelLabel !== ''): ?>
                        <span class="chip">Fuel: <?= h($vFuelLabel) ?></span>
                      <?php endif; ?>
                      <span class="chip"><?= h($v['vehicle_type'] ?? '') ?></span>
                    </div>
                    <?php if (trim((string)($v['listing_description'] ?? '')) !== ''): ?>
                      <p class="v-desc"><?= h($v['listing_description']) ?></p>
                    <?php endif; ?>
                  </div>

                  <?= render_vehicle_price_html($v) ?>

                  <div class="v-actions">
                    <button class="btn btn-secondary js-details" type="button"
                      data-id="<?= (int)$v['id'] ?>"
                      data-name="<?= h($v['make_model'] ?? '') ?>"
                      data-type="<?= h($v['vehicle_type'] ?? '') ?>"
                      data-trans="<?= h($v['transmission'] ?? '') ?>"
                      data-seats="<?= (int)($v['seats'] ?? 0) ?>"
                      data-capacity="<?= h($v['max_capacity_kg'] ?? '') ?>"
                      data-fuel="<?= h($v['fuel_level'] ?? '') ?>"
                      data-rate="<?= number_format($vPromo['effective'], 2, '.', '') ?>"
                      data-base-rate="<?= number_format($vPromo['base'], 2, '.', '') ?>"
                      data-promo="<?= $vPromo['has_promo'] ? '1' : '0' ?>"
                      data-promo-label="<?= h($vPromo['label']) ?>"
                      data-status="<?= h($v['current_status'] ?? '') ?>"
                      data-maint="<?= $isMaint ? '1' : '0' ?>"
                      data-img="<?= h(vehicle_img($v)) ?>">View Details</button>

                    <?php if ($canBook): ?>
                      <button class="btn btn-primary btn-book" type="button" data-id="<?= (int)$v['id'] ?>" data-name="<?= h($v['make_model'] ?? '') ?>" data-seats="<?= (int)($v['seats'] ?? 0) ?>" data-fuel="<?= h($v['fuel_level'] ?? '') ?>">Book Now</button>
                    <?php elseif (!$isLoggedIn && !$isMaint): ?>
                      <a class="btn btn-primary" href="login.php">Login to Book</a>
                    <?php elseif ($isMaint): ?>
                      <button class="btn btn-disabled" type="button" disabled>Maintenance</button>
                    <?php else: ?>
                      <button class="btn btn-disabled" type="button" disabled>Profile Pending</button>
                    <?php endif; ?>

                    <button class="iconbtn" type="button" title="Save for later" aria-label="Save">
                      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M20.8 4.6c-1.5-1.6-4-1.6-5.5 0L12 8l-3.3-3.4c-1.5-1.6-4-1.6-5.5 0-1.6 1.7-1.6 4.4 0 6.1l8.8 9.3 8.8-9.3c1.6-1.7 1.6-4.4 0-6.1z"/>
                      </svg>
                    </button>
                  </div>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="empty">
            <div class="ico">
              <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M10 2h4"/>
                <path d="M12 14v-4"/>
                <path d="M12 18h.01"/>
                <path d="M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z"/>
              </svg>
            </div>
            <h3>No vehicles available for selected dates</h3>
            <p>Try changing your dates or filters to see more options.</p>
            <div class="actions">
              <a class="btn btn-primary" href="vehiclepage.php">Reset Filters</a>
            </div>
          </div>
        <?php endif; ?>

        <?php if (!empty($popularVehicles)): ?>
          <div class="section-title">
            <h2>Popular Vehicles</h2>
            <div class="hint">Most booked in FleetGo</div>
          </div>
          <div class="grid grid-compact">
            <?php foreach ($popularVehicles as $pv):
              $pvStatus = strtolower(trim((string)($pv['current_status'] ?? '')));
              $pvMaint = in_array($pvStatus, ['maintenance','scheduled_maintenance','inspection','unavailable']);
              $pvCanBook = !$pvMaint && $isLoggedIn && $isApproved;
              $pvPromo = vehicle_promo_pricing($pv);
            ?>
              <article class="v-card">
                <div class="v-imgwrap">
                  <img class="v-img" src="<?= h(vehicle_img($pv)) ?>" alt="<?= h($pv['make_model'] ?? '') ?>" onerror="this.src='assets/vehicles/images.jpeg'">
                </div>
                <div class="v-body">
                  <div class="v-title"><?= h($pv['make_model'] ?? '') ?></div>
                  <div class="v-meta">
                    <span class="chip"><?= (int)($pv['_rental_count'] ?? 0) ?> rentals</span>
                    <span class="chip"><?= h($pv['transmission'] ?? '') ?></span>
                    <span class="chip"><?= (int)($pv['seats'] ?? 0) ?> seats</span>
                    <?php if (!empty($pv['max_capacity_kg'])): ?>
                      <span class="chip"><?= number_format((float)$pv['max_capacity_kg'], 0) ?> KG Maximum Capacity</span>
                    <?php endif; ?>
                    <?php $pvFuelLabel = format_fuel_level_label($pv['fuel_level'] ?? ''); if ($pvFuelLabel !== ''): ?>
                      <span class="chip">Fuel: <?= h($pvFuelLabel) ?></span>
                    <?php endif; ?>
                    <span class="chip"><?= h($pv['vehicle_type'] ?? '') ?></span>
                  </div>
                  <?= render_vehicle_price_html($pv) ?>
                  <div class="v-actions">
                    <button class="btn btn-secondary js-details" type="button"
                      data-id="<?= (int)$pv['id'] ?>"
                      data-name="<?= h($pv['make_model'] ?? '') ?>"
                      data-type="<?= h($pv['vehicle_type'] ?? '') ?>"
                      data-trans="<?= h($pv['transmission'] ?? '') ?>"
                      data-seats="<?= (int)($pv['seats'] ?? 0) ?>"
                      data-capacity="<?= h($pv['max_capacity_kg'] ?? '') ?>"
                      data-fuel="<?= h($pv['fuel_level'] ?? '') ?>"
                      data-rate="<?= number_format($pvPromo['effective'], 2, '.', '') ?>"
                      data-base-rate="<?= number_format($pvPromo['base'], 2, '.', '') ?>"
                      data-promo="<?= $pvPromo['has_promo'] ? '1' : '0' ?>"
                      data-promo-label="<?= h($pvPromo['label']) ?>"
                      data-status="<?= h($pv['current_status'] ?? '') ?>"
                      data-maint="<?= $pvMaint ? '1' : '0' ?>"
                      data-img="<?= h(vehicle_img($pv)) ?>">View Details</button>
                    <?php if ($pvCanBook): ?>
                      <button class="btn btn-primary btn-book" type="button" data-id="<?= (int)$pv['id'] ?>" data-name="<?= h($pv['make_model'] ?? '') ?>" data-seats="<?= (int)($pv['seats'] ?? 0) ?>" data-fuel="<?= h($pv['fuel_level'] ?? '') ?>">Book Now</button>
                    <?php elseif (!$isLoggedIn && !$pvMaint): ?>
                      <a class="btn btn-primary" href="login.php">Login to Book</a>
                    <?php elseif ($pvMaint): ?>
                      <button class="btn btn-disabled" type="button" disabled>Maintenance</button>
                    <?php else: ?>
                      <button class="btn btn-disabled" type="button" disabled>Profile Pending</button>
                    <?php endif; ?>
                  </div>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <?php if ($totalVehicles > 0 && $totalPages > 1): ?>
          <nav class="pagination" aria-label="Vehicle pages">
            <?php if ($page > 1): ?>
              <a href="<?= h(browse_page_url($page - 1)) ?>" aria-label="Previous page">‹</a>
            <?php else: ?>
              <span class="disabled" aria-disabled="true">‹</span>
            <?php endif; ?>

            <?php
              $window = 2;
              $startPage = max(1, $page - $window);
              $endPage = min($totalPages, $page + $window);
              if ($startPage > 1) {
                  echo '<a href="' . h(browse_page_url(1)) . '">1</a>';
                  if ($startPage > 2) echo '<span class="disabled">…</span>';
              }
              for ($p = $startPage; $p <= $endPage; $p++) {
                  if ($p === $page) {
                      echo '<span class="active" aria-current="page">' . $p . '</span>';
                  } else {
                      echo '<a href="' . h(browse_page_url($p)) . '">' . $p . '</a>';
                  }
              }
              if ($endPage < $totalPages) {
                  if ($endPage < $totalPages - 1) echo '<span class="disabled">…</span>';
                  echo '<a href="' . h(browse_page_url($totalPages)) . '">' . $totalPages . '</a>';
              }
            ?>

            <?php if ($page < $totalPages): ?>
              <a href="<?= h(browse_page_url($page + 1)) ?>" aria-label="Next page">›</a>
            <?php else: ?>
              <span class="disabled" aria-disabled="true">›</span>
            <?php endif; ?>
          </nav>
          <div class="pagination-info">Page <?= (int)$page ?> of <?= (int)$totalPages ?></div>
        <?php endif; ?>
      </section>
    </div>
  </form>
</main>

<div class="modal" id="viewCarModal" aria-hidden="true">
  <div class="modal-content view-car-sheet" role="dialog" aria-modal="true" aria-labelledby="viewCarTitle">
    <button type="button" class="view-car-close" id="viewCarClose" aria-label="Close">&times;</button>
    <img id="viewCarImg" alt="">
    <h3 id="viewCarTitle">Vehicle</h3>
    <div id="viewCarMeta"></div>
    <div id="viewCarPrice"></div>
    <button class="btn btn-primary" type="button" id="viewCarBook">Book Now</button>
  </div>
</div>

<!-- ===== DETAILS MODAL ===== -->
<div class="modal" id="detailsModal" aria-hidden="true">
  <div class="modal-content" role="dialog" aria-modal="true">
    <h3 id="detailsTitle">Vehicle Details</h3>
    <div id="detailsBody"></div>
    <div class="details-actions">
      <button class="btn btn-secondary" type="button" id="detailsClose" style="flex:1;">Close</button>
      <button class="btn btn-primary" type="button" id="detailsBook" style="flex:1;display:none;">Book Now</button>
    </div>
  </div>
</div>

<!-- ===== BOOKING MODAL ===== -->
<div class="modal" id="bookModal">
  <div class="modal-content">
    <div class="book-modal-head">
      <h3>Book <span id="vehName"></span></h3>
    </div>
    <form id="bookForm">
      <div class="book-modal-body">
      <input type="hidden" name="vehicle_id" id="vehID">
      <input type="hidden" name="rate_type" value="CDO">
      
      <div class="date-time-row">
        <div class="input-group">
          <label>Start Date</label>
          <input type="text" name="start_date" id="startDate" class="book-date-input" placeholder="Select start date" required autocomplete="off">
        </div>
        
        <div class="input-group">
          <label>Start Time</label>
          <input type="time" name="start_time" id="startTime" value="08:00" required>
        </div>
      </div>
      
      <div class="date-time-row">
        <div class="input-group">
          <label>End Date</label>
          <input type="text" name="end_date" id="endDate" class="book-date-input" placeholder="Select end date" required autocomplete="off">
        </div>
        
        <div class="input-group">
          <label>End Time</label>
          <input type="time" name="end_time" id="endTime" value="18:00" required>
        </div>
      </div>
      <div class="book-occupied-hint" id="bookOccupiedHint">Dates already approved for this vehicle cannot be selected.</div>

      <div class="input-group" id="bookingFuelGroup">
        <label>Fuel Level</label>
        <div id="bookingFuelDisplay" class="booking-fuel-text" aria-live="polite">Not recorded</div>
        <input type="hidden" name="fuel_level_acknowledged" id="bookingFuelLevel" value="" tabindex="-1">
        <div id="bookingFuelHint" style="margin-top:8px;font-size:.8rem;font-weight:700;color:rgba(156,163,175,.95);">
          Return the vehicle with the same fuel level shown above.
        </div>
      </div>
      
      <!-- Promotional Preview -->
      <div id="promoPreview" class="promo-preview" style="display:none;">
        <div class="promo-header">🎉 Promotional Discount Preview</div>
        <div class="cost-breakdown">
          <div class="cost-line">
            <span>Base Cost:</span>
            <span id="baseCost">₱0.00</span>
          </div>
          <div class="cost-line promo-discount" id="discountLine" style="display:none;">
            <span id="promoName">Discount:</span>
            <span id="discountAmount">-₱0.00</span>
          </div>
          <div class="cost-line total">
            <span>Total Cost:</span>
            <span id="totalCost">₱0.00</span>
          </div>
        </div>
        <div id="promoMessage" class="promo-message"></div>
      </div>

      <button type="button" class="policy-trigger" id="policyTrigger" aria-haspopup="dialog" aria-controls="policyModal">
        <span class="policy-trigger__text">
          <span class="policy-trigger__title">Terms and Agreement &amp; Privacy Policy</span>
          <span class="policy-trigger__sub">Tap to read and accept before booking</span>
        </span>
        <span class="policy-trigger__status" id="policyStatus">Required</span>
      </button>
      <input type="hidden" name="accept_terms" id="acceptTermsValue" value="0">

      <div class="modal policy-modal" id="policyModal" role="dialog" aria-modal="true" aria-labelledby="policyModalTitle">
        <div class="modal-content policy-modal-content">
          <div class="policy-modal-head">
            <h3 id="policyModalTitle">Terms and Agreement &amp; Privacy Policy</h3>
            <button type="button" class="policy-modal-close" id="policyModalClose" aria-label="Close">&times;</button>
          </div>
          <div class="policy-modal-body">
          <section class="policy-section">
            <h3 class="policy-section__title">Terms and Agreement</h3>
            <h4>1. Introduction</h4>
            <p>Welcome to our Car Booking System. These Terms of Agreement govern the use of our system and the booking of vehicles through our platform.</p>
            <p>By proceeding with a booking, you acknowledge that you have read, understood, and agreed to comply with the following terms and conditions.</p>

            <h4>2. Booking Requirements</h4>
            <p>Customers must provide accurate and complete information when making a reservation. Customers must also meet the applicable age, driver's license, and other eligibility requirements before renting a vehicle.</p>
            <p>Providing false or misleading information may result in the cancellation of a booking.</p>

            <h4>3. Booking Confirmation</h4>
            <p>All bookings are subject to vehicle availability and approval by the administrator.</p>
            <p>Submitting a booking request does not automatically guarantee a reservation. A booking will be considered confirmed only after it has been approved by the system administrator and any required payment or deposit has been completed.</p>

            <h4>4. Payment Terms</h4>
            <p>Customers must pay the applicable rental fees and other charges associated with their booking.</p>
            <p>The total rental cost, payment method, deposit requirements, and any additional fees will be communicated to the customer before the booking is finalized.</p>
            <p>Failure to complete the required payment within the specified period may result in the cancellation of the reservation.</p>

            <h4>5. Cancellation and Refund Policy</h4>
            <p>Customers who wish to cancel their booking must notify the administrator through the designated communication channels.</p>
            <p>Cancellation fees and refund eligibility, if applicable, will depend on the cancellation policy provided at the time of booking.</p>
            <p>Refunds, when approved, will be processed using the applicable payment method and within the specified processing period.</p>

            <h4>6. Vehicle Use and Customer Responsibilities</h4>
            <p>Customers agree to use the rented vehicle responsibly and in accordance with applicable traffic laws and regulations.</p>
            <p>Customers are responsible for:</p>
            <ul>
              <li>Holding a valid driver's license and meeting rental eligibility requirements.</li>
              <li>Using the vehicle only for lawful purposes.</li>
              <li>Taking reasonable care of the rented vehicle.</li>
              <li>Returning the vehicle on the agreed date, time, and location.</li>
              <li>Reporting any accident, damage, or mechanical issue immediately.</li>
            </ul>
            <p>Customers may be held responsible for damage, loss, penalties, or additional charges arising from their actions, subject to the rental agreement and applicable laws.</p>

            <h4>7. Late Returns</h4>
            <p>Customers must return the vehicle at the agreed date and time.</p>
            <p>Late returns may result in additional charges, provided that the applicable fees and conditions have been disclosed to the customer before the booking is finalized.</p>

            <h4>8. Booking Modifications</h4>
            <p>Requests to change the booking date, rental duration, or selected vehicle are subject to vehicle availability and administrator approval.</p>
            <p>Additional charges may apply if the modification changes the total rental cost.</p>

            <h4>9. Right to Cancel or Reject a Booking</h4>
            <p>The system administrator reserves the right to reject or cancel a booking if the customer fails to meet the rental requirements, provides inaccurate information, fails to complete the required payment, or if the selected vehicle becomes unavailable.</p>
            <p>Any applicable refund will be handled in accordance with the disclosed cancellation and refund policy and applicable laws.</p>

            <h4>10. Limitation of Responsibility</h4>
            <p>The system is intended to facilitate vehicle reservations and booking management.</p>
            <p>The system provider shall not be responsible for service interruptions, technical errors, or circumstances beyond its reasonable control. Nothing in these terms shall exclude responsibilities that cannot legally be excluded under applicable law.</p>

            <h4>11. Changes to These Terms</h4>
            <p>We reserve the right to modify these Terms of Agreement when necessary. Updated terms will be posted on the system.</p>
            <p>Customers may be required to review and accept the updated terms before making future bookings.</p>

            <h4>12. Acceptance of Terms</h4>
            <p>By checking the agreement box and proceeding with a booking, you confirm that:</p>
            <ul>
              <li>You have read and understood these Terms of Agreement.</li>
              <li>The information you provided is accurate and complete.</li>
              <li>You agree to comply with the booking requirements, payment conditions, and vehicle rental rules stated above.</li>
            </ul>
            <p>If you do not agree with these terms, please do not proceed with your booking.</p>
          </section>

          <section class="policy-section">
            <h3 class="policy-section__title">Privacy Policy</h3>
            <h4>1. Introduction</h4>
            <p>Welcome to our Car Booking System. We value your privacy and are committed to protecting your personal information. This Privacy Policy explains how we collect, use, store, and protect your information when you use our system to book a vehicle.</p>

            <h4>2. Information We Collect</h4>
            <p>When using our system, we may collect the following information:</p>
            <ul>
              <li>Full Name</li>
              <li>Contact Number</li>
              <li>Email Address</li>
              <li>Address</li>
              <li>Driver's License Information, if required</li>
              <li>Booking Details, including selected vehicle, rental date, and return date</li>
              <li>Payment Information and Transaction Records</li>
            </ul>

            <h4>3. How We Use Your Information</h4>
            <p>The information collected will be used for the following purposes:</p>
            <ul>
              <li>To process and confirm your car booking.</li>
              <li>To verify your identity and eligibility to rent a vehicle.</li>
              <li>To communicate with you regarding your reservation.</li>
              <li>To process payments and maintain transaction records.</li>
              <li>To provide customer support.</li>
              <li>To improve the functionality and security of our system.</li>
            </ul>

            <h4>4. Data Privacy and Protection</h4>
            <p>We are committed to protecting your personal information against unauthorized access, misuse, disclosure, or alteration. We will implement reasonable security measures to safeguard the information stored in our system.</p>
            <p>Your personal information will only be accessed by authorized personnel when necessary for booking management and related services.</p>

            <h4>5. Sharing of Information</h4>
            <p>We do not sell or rent your personal information to third parties. However, your information may be shared with authorized personnel or service providers when necessary to process your booking, complete payments, or comply with legal obligations.</p>

            <h4>6. Data Retention</h4>
            <p>Your personal information will be retained only for as long as necessary to fulfill the purposes stated in this policy, comply with legal obligations, and maintain transaction records.</p>

            <h4>7. Your Privacy Rights</h4>
            <p>You have the right to request access to, correction of, or deletion of your personal information, subject to applicable laws and legitimate record-retention requirements.</p>
            <p>For privacy-related concerns or requests, please contact us through our official contact information.</p>

            <h4>8. Changes to This Privacy Policy</h4>
            <p>We reserve the right to update this Privacy Policy when necessary. Any changes will be posted on our system.</p>

            <h4>9. Contact Information</h4>
            <p>If you have questions or concerns regarding this Privacy Policy, you may contact us through our official contact channels.</p>
            <p>By using our Car Booking System, you acknowledge that you have read and understood this Privacy Policy.</p>
          </section>
          </div>
          <div class="policy-modal-footer">
            <div class="booking-accept">
              <input type="checkbox" id="acceptTerms">
              <label for="acceptTerms">I have read, understood, and accept the Terms and Agreement and Privacy Policy.</label>
            </div>
            <div class="policy-modal-actions">
              <button type="button" class="policy-btn policy-btn--ghost" id="policyCancel">Close</button>
              <button type="button" class="policy-btn policy-btn--primary" id="policyAccept" disabled>Accept &amp; Continue</button>
            </div>
          </div>
        </div>
      </div>
      </div>

      <div class="book-modal-footer">
        <div class="booking-accept">
          <input type="checkbox" name="accept_fuel_policy" id="acceptFuelPolicy" value="1" required>
          <label for="acceptFuelPolicy">I understand that the fuel level should be the same before and after the rent.</label>
        </div>
        <button type="submit">Confirm Booking</button>
        <div id="msgBox"></div>
      </div>
    </form>
  </div>
</div>

<footer style="text-align:center;color:rgba(242,246,250,0.62);font-weight:850;padding:22px 14px 40px;">
  © <?= date('Y') ?> FleetGo Rentals
</footer>

<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
(function(){
  const toggle = document.getElementById('filterToggle');
  const panel = document.getElementById('filterPanel');
  if (toggle && panel) {
    toggle.addEventListener('click', () => {
      const open = !panel.classList.contains('open');
      panel.classList.toggle('open', open);
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }
})();

const modal = document.getElementById('bookModal');
const vehName = document.getElementById('vehName');
const vehID = document.getElementById('vehID');
const form = document.getElementById('bookForm');

let occupiedRanges = [];
let startPicker = null;
let endPicker = null;

function ymdLocal(d) {
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
}

function parseYmd(s) {
  const p = String(s || '').split('-').map(Number);
  if (p.length !== 3 || !p[0]) return null;
  return new Date(p[0], p[1] - 1, p[2]);
}

function isDateOccupied(date) {
  const t = new Date(date.getFullYear(), date.getMonth(), date.getDate()).getTime();
  return occupiedRanges.some(r => {
    const a = parseYmd(r.start);
    const b = parseYmd(r.end);
    if (!a || !b) return false;
    return t >= a.getTime() && t <= b.getTime();
  });
}

/** Inclusive day walk — any occupied day in range blocks the selection. */
function rangeOverlapsOccupied(startStr, endStr) {
  const a = parseYmd(startStr);
  const b = parseYmd(endStr);
  if (!a || !b || b < a) return false;
  const cursor = new Date(a.getFullYear(), a.getMonth(), a.getDate());
  const end = new Date(b.getFullYear(), b.getMonth(), b.getDate());
  while (cursor <= end) {
    if (isDateOccupied(cursor)) return true;
    cursor.setDate(cursor.getDate() + 1);
  }
  return false;
}

function refreshPickerDisabled() {
  if (startPicker) startPicker.set('disable', [isDateOccupied]);
  if (endPicker) endPicker.set('disable', [isDateOccupied]);
  if (startPicker) startPicker.redraw();
  if (endPicker) endPicker.redraw();
}

function destroyBookPickers() {
  if (startPicker) { startPicker.destroy(); startPicker = null; }
  if (endPicker) { endPicker.destroy(); endPicker = null; }
}

function initBookPickers(minDate, defaultStart, defaultEnd) {
  destroyBookPickers();
  const common = {
    dateFormat: 'Y-m-d',
    minDate: minDate || 'today',
    disable: [isDateOccupied],
    allowInput: false,
    disableMobile: true,
    onDayCreate(_dObj, _dStr, _fp, dayElem) {
      if (isDateOccupied(dayElem.dateObj)) {
        dayElem.classList.add('flatpickr-disabled');
        dayElem.title = 'Already booked';
      }
    }
  };

  startPicker = flatpickr('#startDate', {
    ...common,
    defaultDate: defaultStart && !isDateOccupied(parseYmd(defaultStart) || new Date(0)) ? defaultStart : null,
    onChange(selected) {
      if (!selected[0] || !endPicker) return;
      const startStr = ymdLocal(selected[0]);
      if (isDateOccupied(selected[0])) {
        startPicker.clear();
        return;
      }
      endPicker.set('minDate', startStr);
      const endVal = form.end_date.value;
      if (endVal && (endVal < startStr || rangeOverlapsOccupied(startStr, endVal))) {
        endPicker.clear();
      }
      validateDateTime();
      calculatePromo();
    }
  });

  endPicker = flatpickr('#endDate', {
    ...common,
    minDate: defaultStart || minDate || 'today',
    defaultDate: defaultEnd && !rangeOverlapsOccupied(defaultStart || minDate, defaultEnd) ? defaultEnd : null,
    onChange(selected) {
      if (selected[0] && isDateOccupied(selected[0])) {
        endPicker.clear();
        return;
      }
      const startStr = form.start_date.value;
      const endStr = selected[0] ? ymdLocal(selected[0]) : '';
      if (startStr && endStr && rangeOverlapsOccupied(startStr, endStr)) {
        endPicker.clear();
        return;
      }
      validateDateTime();
      calculatePromo();
    }
  });

  refreshPickerDisabled();
}

async function loadOccupiedDates(vehicleId) {
  occupiedRanges = [];
  try {
    const res = await fetch(`vehiclepage.php?ajax=1&action=occupied_dates&vehicle_id=${encodeURIComponent(vehicleId)}`);
    const json = await res.json();
    if (json && json.success && Array.isArray(json.ranges)) {
      occupiedRanges = json.ranges.map(r => ({
        ...r,
        start: String(r.start || '').slice(0, 10),
        end: String(r.end || '').slice(0, 10),
      })).filter(r => r.start && r.end);
    }
  } catch (e) {}
  const hint = document.getElementById('bookOccupiedHint');
  if (hint) {
    hint.textContent = occupiedRanges.length
      ? `Grayed-out dates are already booked for this vehicle (${occupiedRanges.length} booking${occupiedRanges.length > 1 ? 's' : ''}) and cannot be selected.`
      : 'All upcoming dates are currently open for this vehicle.';
  }
  refreshPickerDisabled();
}

async function openBookingModal({ id, name, seats, fuel, min }) {
  vehName.textContent = name || '';
  vehID.value = id || '';
  
  const today = ymdLocal(new Date());
  const minDate = min || today;

  const selectedStart = (document.getElementById('start_date')?.value || '').trim();
  const selectedEnd = (document.getElementById('end_date')?.value || '').trim();

  await loadOccupiedDates(id);

  let startVal = minDate;
  let endVal = '';
  if (selectedStart && selectedEnd && selectedEnd >= selectedStart) {
    startVal = (selectedStart >= minDate) ? selectedStart : minDate;
    endVal = (selectedEnd >= startVal) ? selectedEnd : '';
    if (endVal && rangeOverlapsOccupied(startVal, endVal)) {
      startVal = minDate;
      endVal = '';
    } else if (isDateOccupied(parseYmd(startVal) || new Date())) {
      startVal = minDate;
      endVal = '';
      let cursor = parseYmd(minDate) || new Date();
      for (let i = 0; i < 366; i++) {
        if (!isDateOccupied(cursor)) {
          startVal = ymdLocal(cursor);
          break;
        }
        cursor = new Date(cursor.getFullYear(), cursor.getMonth(), cursor.getDate() + 1);
      }
    }
  } else if (isDateOccupied(parseYmd(minDate) || new Date())) {
    let cursor = parseYmd(minDate) || new Date();
    for (let i = 0; i < 366; i++) {
      if (!isDateOccupied(cursor)) {
        startVal = ymdLocal(cursor);
        break;
      }
      cursor = new Date(cursor.getFullYear(), cursor.getMonth(), cursor.getDate() + 1);
    }
  }

  initBookPickers(minDate, startVal, endVal || null);
  
  form.start_time.value = '08:00';
  form.end_time.value = '18:00';

  const fuelDisplay = document.getElementById('bookingFuelDisplay');
  const fuelHidden = document.getElementById('bookingFuelLevel');
  const setFuelUI = (level, label) => {
    const text = label || formatFuelLabel(level) || 'Not recorded';
    if (fuelDisplay) fuelDisplay.textContent = text;
    if (fuelHidden) fuelHidden.value = level || '';
  };
  setFuelUI(fuel || '', formatFuelLabel(fuel || ''));
  try {
    const res = await fetch(`vehiclepage.php?ajax=1&action=get_vehicle&vehicle_id=${encodeURIComponent(id || '')}`);
    const json = await res.json();
    if (json && json.success && json.vehicle) {
      const fl = json.vehicle.fuel_level || '';
      const label = json.vehicle.fuel_level_label || formatFuelLabel(fl) || 'Not recorded';
      setFuelUI(fl, label);
    }
  } catch (e) {}

  setPolicyAccepted(false);
  const acceptFuel = document.getElementById('acceptFuelPolicy');
  if (acceptFuel) acceptFuel.checked = false;
  
  modal.classList.add('show');
  const bookBody = modal.querySelector('.book-modal-body');
  if (bookBody) bookBody.scrollTop = 0;
  document.body.style.overflow = 'hidden';
  
  document.getElementById('promoPreview').style.display = 'none';
  const msgBox = document.getElementById('msgBox');
  if (msgBox) msgBox.innerHTML = '';
}

document.querySelectorAll('.btn-book').forEach(btn => {
  btn.addEventListener('click', async () => {
    await openBookingModal({
      id: btn.dataset.id,
      name: btn.dataset.name,
      seats: btn.dataset.seats,
      fuel: btn.dataset.fuel,
      min: btn.dataset.min
    });
  });
});

const policyModal = document.getElementById('policyModal');
const policyTrigger = document.getElementById('policyTrigger');
const policyStatus = document.getElementById('policyStatus');
const policyCheckbox = document.getElementById('acceptTerms');
const policyAcceptBtn = document.getElementById('policyAccept');
const acceptTermsValue = document.getElementById('acceptTermsValue');

// The booking modal clips its content, so the policy modal must live at body level
if (policyModal) document.body.appendChild(policyModal);

function setPolicyAccepted(accepted) {
  if (acceptTermsValue) acceptTermsValue.value = accepted ? '1' : '0';
  if (policyCheckbox) policyCheckbox.checked = accepted;
  if (policyAcceptBtn) policyAcceptBtn.disabled = !accepted;
  if (policyTrigger) policyTrigger.classList.toggle('is-accepted', accepted);
  if (policyStatus) policyStatus.textContent = accepted ? 'Accepted ✓' : 'Required';
}

function openPolicyModal() {
  if (!policyModal) return;
  if (policyCheckbox) policyCheckbox.checked = acceptTermsValue?.value === '1';
  if (policyAcceptBtn) policyAcceptBtn.disabled = !policyCheckbox?.checked;
  policyModal.classList.add('show');
  const body = policyModal.querySelector('.policy-modal-body');
  if (body) body.scrollTop = 0;
}

function closePolicyModal() {
  if (policyModal) policyModal.classList.remove('show');
}

policyTrigger?.addEventListener('click', openPolicyModal);
document.getElementById('policyModalClose')?.addEventListener('click', closePolicyModal);
document.getElementById('policyCancel')?.addEventListener('click', closePolicyModal);
policyCheckbox?.addEventListener('change', () => {
  if (policyAcceptBtn) policyAcceptBtn.disabled = !policyCheckbox.checked;
  if (!policyCheckbox.checked) setPolicyAccepted(false);
});
policyAcceptBtn?.addEventListener('click', () => {
  if (!policyCheckbox?.checked) return;
  setPolicyAccepted(true);
  closePolicyModal();
  const msgBox = document.getElementById('msgBox');
  if (msgBox && msgBox.textContent.includes('Terms and Agreement')) msgBox.innerHTML = '';
});
policyModal?.addEventListener('click', e => {
  if (e.target === policyModal) closePolicyModal();
});
document.addEventListener('keydown', e => {
  if (e.key === 'Escape' && policyModal?.classList.contains('show')) {
    e.stopPropagation();
    closePolicyModal();
  }
}, true);

modal.addEventListener('click', e => {
  if (e.target === modal) {
    modal.classList.remove('show');
    document.body.style.overflow = '';
    destroyBookPickers();
  }
});

// Details modal
const detailsModal = document.getElementById('detailsModal');
const detailsTitle = document.getElementById('detailsTitle');
const detailsBody = document.getElementById('detailsBody');
const detailsClose = document.getElementById('detailsClose');
const detailsBook = document.getElementById('detailsBook');

let lastDetails = null;

function formatMoney(n) {
  const num = Number(n || 0);
  return num.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function formatFuelLabel(level) {
  const fl = String(level || '').toLowerCase();
  const map = { full: 'Full', '3/4': '3/4 Tank', half: 'Half Tank', '1/4': '1/4 Tank', empty: 'Empty', '1/2': 'Half Tank' };
  return map[fl] || '';
}

function detailItem(label, value, opts = {}) {
  const safe = (v) => String(v ?? '').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  const display = (value === null || value === undefined || value === '') ? '—' : value;
  const valueHtml = opts.html ? display : safe(display);
  const wide = opts.wide ? ' wide' : '';
  const accent = opts.accent ? ' accent' : '';
  return `
    <div class="detail-item${wide}">
      <span class="k">${safe(label)}</span>
      <span class="v${accent}">${valueHtml}</span>
    </div>
  `;
}

function renderVehicleDetails(v, fallback = {}) {
  const safe = (val) => String(val || '').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  const name = v.make_model || fallback.name || 'Vehicle Details';
  const img = safe(v.img || fallback.img || 'assets/vehicles/images.jpeg');
  const ownership = v.ownership_type || v.classification || '';
  const condition = v.vehicle_condition || v.condition_status || '';
  const fuelLabel = v.fuel_level_label || formatFuelLabel(v.fuel_level) || '—';
  const rateCdo = v.daily_rate_cdo ?? v.daily_rate ?? fallback.rate ?? 0;
  const rateOutside = v.daily_rate_outside_cdo ?? (Number(rateCdo) * 1.2);
  const promo = v.promo_pricing || null;
  const hasPromo = !!(promo && promo.has_promo);
  const promoLabel = hasPromo ? (promo.label || 'Promo') : '';
  const rateCdoEffective = hasPromo ? promo.effective : rateCdo;
  const capacity = v.max_capacity_kg ? (Number(v.max_capacity_kg).toLocaleString() + ' KG') : '—';
  const odo = (v.odometer !== null && v.odometer !== undefined && v.odometer !== '')
    ? (Number(v.odometer).toLocaleString() + ' km')
    : '—';
  const typeLabel = v.vehicle_type || fallback.type || '';

  const cdoRateHtml = hasPromo
    ? `<span style="color:#7cffc7">₱${formatMoney(rateCdoEffective)}</span> <span style="text-decoration:line-through;opacity:.55;font-size:.9em;margin-left:6px;">₱${formatMoney(rateCdo)}</span>`
    : ('₱' + formatMoney(rateCdo));

  return `
    <div class="details-grid">
      <div class="details-media">
        <img src="${img}" alt="${safe(name)}" onerror="this.src='assets/vehicles/images.jpeg'">
        <div class="details-media-caption">${safe(typeLabel || name)}</div>
      </div>
      <div class="details-specs">
        <div>
          <div class="details-section-title">Vehicle</div>
          <div class="details-list">
            ${detailItem('Plate Number', v.plate_no)}
            ${detailItem('Vehicle Type', typeLabel || '—')}
            ${detailItem('Ownership', ownership || '—')}
            ${v.category ? detailItem('Category', v.category) : ''}
            ${detailItem('Condition', condition || '—')}
            ${detailItem('Year', v.year || '—')}
          </div>
        </div>
        <div>
          <div class="details-section-title">Capacity & Fuel</div>
          <div class="details-list">
            ${detailItem('Seats', v.seats ?? fallback.seats ?? '—')}
            ${detailItem('Max Capacity', capacity)}
            ${detailItem('Fuel Type', v.fuel_type || '—')}
            ${detailItem('Fuel Level', fuelLabel)}
            ${detailItem('Transmission', v.transmission || fallback.trans || '—')}
            ${detailItem('Comfort', v.comfort_level || '—')}
            ${detailItem('Odometer', odo, { wide: true })}
          </div>
        </div>
        <div>
          <div class="details-section-title">Rates</div>
          <div class="details-list">
            ${detailItem('Daily Rate (CDO)', cdoRateHtml, { accent: true, html: true })}
            ${detailItem('Daily Rate (Outside)', '₱' + formatMoney(rateOutside), { accent: true, html: true })}
            ${hasPromo ? detailItem('Vehicle Promo', promoLabel, { accent: true }) : ''}
          </div>
        </div>
      </div>
    </div>
  `;
}

async function openDetails(data) {
  lastDetails = data;
  if (detailsTitle) detailsTitle.textContent = data.name || 'Vehicle Details';
  if (detailsBody) {
    detailsBody.innerHTML = `<div style="padding:28px;text-align:center;color:var(--muted);font-weight:800;">Loading vehicle details…</div>`;
  }
  if (detailsBook) {
    const isMaint = String(data.maint || '') === '1' || ['maintenance','scheduled_maintenance','inspection','unavailable'].includes(String(data.status || '').toLowerCase());
    const canBook = String(data.canBook || '') === '1' && !isMaint;
    detailsBook.style.display = canBook ? 'inline-flex' : 'none';
  }
  if (detailsModal) detailsModal.classList.add('show');

  let vehicle = null;
  try {
    if (data.id) {
      const res = await fetch(`vehiclepage.php?ajax=1&action=get_vehicle&vehicle_id=${encodeURIComponent(data.id)}`);
      const json = await res.json();
      if (json && json.success && json.vehicle) {
        vehicle = json.vehicle;
      }
    }
  } catch (e) {}

  if (!vehicle) {
    vehicle = {
      make_model: data.name,
      vehicle_type: data.type,
      transmission: data.trans,
      seats: data.seats,
      max_capacity_kg: data.capacity,
      fuel_level: data.fuel,
      daily_rate: data.rate,
      current_status: data.status,
      img: data.img
    };
  }

  lastDetails = { ...data, ...vehicle, id: data.id || vehicle.id };
  if (detailsTitle) detailsTitle.textContent = vehicle.make_model || data.name || 'Vehicle Details';
  if (detailsBody) detailsBody.innerHTML = renderVehicleDetails(vehicle, data);
}

document.querySelectorAll('.js-details').forEach(btn => {
  btn.addEventListener('click', async () => {
    const payload = {
      id: btn.dataset.id || '',
      name: btn.dataset.name || '',
      type: btn.dataset.type || '',
      trans: btn.dataset.trans || '',
      seats: btn.dataset.seats || '',
      capacity: btn.dataset.capacity || '',
      fuel: btn.dataset.fuel || '',
      rate: btn.dataset.rate || '',
      status: btn.dataset.status || '',
      img: btn.dataset.img || '',
      maint: btn.dataset.maint || '0',
      canBook: (btn.parentElement && btn.parentElement.querySelector('.btn-book')) ? '1' : '0'
    };

    try {
      if (payload.id) {
        await fetch('vehiclepage.php', {
          method: 'POST',
          headers: {'Content-Type': 'application/x-www-form-urlencoded'},
          body: `ajax=1&action=mark_view&vehicle_id=${encodeURIComponent(payload.id)}`
        });
      }
    } catch (e) {}
    openDetails(payload);
  });
});

if (detailsClose) detailsClose.addEventListener('click', () => detailsModal && detailsModal.classList.remove('show'));
if (detailsModal) detailsModal.addEventListener('click', (e) => { if (e.target === detailsModal) detailsModal.classList.remove('show'); });
if (detailsBook) {
  detailsBook.addEventListener('click', async () => {
    if (!lastDetails || !lastDetails.id) return;
    detailsModal && detailsModal.classList.remove('show');
    await openBookingModal({
      id: String(lastDetails.id),
      name: lastDetails.make_model || lastDetails.name || '',
      seats: lastDetails.seats || 1,
      fuel: lastDetails.fuel_level || lastDetails.fuel || ''
    });
  });
}

// Date and time validation
document.getElementById('startDate').addEventListener('change', function() {
  const startDate = this.value;
  const endDateInput = document.getElementById('endDate');
  
  if (startDate) {
    endDateInput.min = startDate;
    if (endDateInput.value && endDateInput.value <= startDate) {
      endDateInput.value = '';
    }
  }
  validateDateTime();
  calculatePromo();
});

document.getElementById('endDate').addEventListener('change', function() {
  const endDate = this.value;
  const startDate = document.getElementById('startDate').value;
  
  if (endDate && startDate && endDate <= startDate) {
    alert('End date must be after start date');
    this.value = '';
    return;
  }
  validateDateTime();
  calculatePromo();
});

document.getElementById('startTime').addEventListener('change', validateDateTime);
document.getElementById('endTime').addEventListener('change', validateDateTime);

function validateDateTime() {
  const startDate = document.getElementById('startDate').value;
  const startTime = document.getElementById('startTime').value;
  const endDate = document.getElementById('endDate').value;
  const endTime = document.getElementById('endTime').value;
  
  if (startDate && endDate && rangeOverlapsOccupied(startDate, endDate)) {
    alert('Your selected dates include days that are already approved/reserved for this vehicle. Please choose free dates.');
    if (endPicker) endPicker.clear();
    return false;
  }

  if (startDate && endDate && startTime && endTime) {
    const startDateTime = new Date(startDate + 'T' + startTime);
    const endDateTime = new Date(endDate + 'T' + endTime);
    
    if (endDateTime <= startDateTime) {
      alert('End date/time must be after start date/time');
      document.getElementById('endTime').value = '';
      return false;
    }
  }
  return true;
}

async function calculatePromo() {
  const startDate = document.getElementById('startDate').value;
  const startTime = document.getElementById('startTime').value;
  const endDate = document.getElementById('endDate').value;
  const endTime = document.getElementById('endTime').value;
  const vehicleId = document.getElementById('vehID').value;
  
  if (!startDate || !endDate || !startTime || !endTime || !vehicleId) return;
  
  const start = new Date(startDate + 'T' + startTime);
  const end = new Date(endDate + 'T' + endTime);
  const days = Math.ceil((end - start) / (1000 * 60 * 60 * 24));
  
  if (days <= 0) return;
  
  try {
    const response = await fetch('includes/calculate_promo.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: `vehicle_id=${vehicleId}&start_date=${startDate}&start_time=${startTime}&end_date=${endDate}&end_time=${endTime}&rate_type=CDO`
    });
    
    const data = await response.json();
    
    if (data.success) {
      displayPromoPreview(data);
    }
  } catch (error) {
    console.error('Error calculating promo:', error);
  }
}

function displayPromoPreview(data) {
  const preview = document.getElementById('promoPreview');
  const baseCost = document.getElementById('baseCost');
  const discountLine = document.getElementById('discountLine');
  const promoName = document.getElementById('promoName');
  const discountAmount = document.getElementById('discountAmount');
  const totalCost = document.getElementById('totalCost');
  const promoMessage = document.getElementById('promoMessage');
  
  baseCost.textContent = `₱${data.original_cost.toFixed(2)}`;
  totalCost.textContent = `₱${data.total_cost.toFixed(2)}`;
  
  if (data.promo_applied) {
    discountLine.style.display = 'flex';
    promoName.textContent = `${data.promo_applied}:`;
    discountAmount.textContent = `-₱${data.promo_discount.toFixed(2)}`;
    promoMessage.textContent = `🎉 You'll save ₱${data.promo_discount.toFixed(2)} with this promotion!`;
  } else {
    discountLine.style.display = 'none';
    promoMessage.textContent = 'No promotional discounts available for this booking.';
  }
  
  preview.style.display = 'block';
}

/* ===== ENHANCED BOOKING LOGIC ===== */
form.addEventListener('submit', async e => {
  e.preventDefault();
  const msgBox = document.getElementById('msgBox');
  if (acceptTermsValue?.value !== '1') {
    msgBox.innerHTML = '<div class="alert error">Please read and accept the Terms and Agreement and Privacy Policy before booking.</div>';
    openPolicyModal();
    return;
  }

  const acceptFuel = document.getElementById('acceptFuelPolicy');
  if (!acceptFuel || !acceptFuel.checked) {
    msgBox.innerHTML = '<div class="alert error">Please confirm that you understand the fuel level must be the same before and after the rent.</div>';
    acceptFuel?.focus();
    return;
  }

  if (!validateDateTime()) {
    msgBox.innerHTML = '<div class="alert error">Please choose free dates that are not already approved for this vehicle.</div>';
    return;
  }

  msgBox.innerHTML = '<div class="alert">Processing...</div>';

  try {
    const res = await fetch('includes/book_vehicle.php', { method: 'POST', body: new FormData(form) });
    const type = res.headers.get('content-type') || '';

    if (type.includes('application/json')) {
      const data = await res.json();

      // ⚠️ Handle incomplete profile
      if (data.redirect) {
        msgBox.innerHTML = `<div class="alert error">${data.message}</div>`;
        setTimeout(() => window.location.href = data.redirect, 2000);
        return;
      }

      // ⚠️ Handle errors
      if (data.error) {
        msgBox.innerHTML = `<div class="alert error">${data.message}</div>`;
        return;
      }

      // ✅ Handle success
      if (data.success) {
        msgBox.innerHTML = `<div class="alert success">${data.message}</div>`;
        setTimeout(() => window.location.href = 'myrentals.php', 4500);
        return;
      }
    }

    // fallback for plain text (non-JSON responses)
    const text = await res.text();
    if (text.includes('✅')) {
      msgBox.innerHTML = `<div class="alert success">${text}</div>`;
      setTimeout(() => window.location.href = 'myrentals.php', 1500);
    } else {
      msgBox.innerHTML = `<div class="alert error">${text}</div>`;
    }
  } catch (err) {
    console.error(err);
    msgBox.innerHTML = `<div class="alert error">❌ Error submitting booking.</div>`;
  }
});

/* Live-refresh vehicle promo prices while browsing */
async function refreshBrowsePromoPrices(){
  const nodes = Array.from(document.querySelectorAll('[data-vehicle-price]'));
  if (!nodes.length) return;
  const ids = [...new Set(nodes.map(n => n.getAttribute('data-vehicle-price')).filter(Boolean))];
  if (!ids.length) return;
  try {
    const res = await fetch(`vehiclepage.php?ajax=1&action=promo_prices&ids=${encodeURIComponent(ids.join(','))}`);
    const json = await res.json();
    if (!json || !json.success || !json.prices) return;
    nodes.forEach(node => {
      const id = String(node.getAttribute('data-vehicle-price') || '');
      const info = json.prices[id];
      if (!info || !info.html) return;
      const wrap = document.createElement('div');
      wrap.innerHTML = info.html.trim();
      const next = wrap.firstElementChild;
      if (next) node.replaceWith(next);
      const btn = document.querySelector(`.js-details[data-id="${id}"]`);
      if (btn) {
        btn.setAttribute('data-rate', Number(info.effective || 0).toFixed(2));
        btn.setAttribute('data-base-rate', Number(info.base || 0).toFixed(2));
        btn.setAttribute('data-promo', info.has_promo ? '1' : '0');
        btn.setAttribute('data-promo-label', info.label || '');
      }
    });
  } catch (e) {}
}
refreshBrowsePromoPrices();
setInterval(refreshBrowsePromoPrices, 5000);

(function setupViewCarModal() {
  const viewModal = document.getElementById('viewCarModal');
  const viewImg = document.getElementById('viewCarImg');
  const viewTitle = document.getElementById('viewCarTitle');
  const viewMeta = document.getElementById('viewCarMeta');
  const viewPrice = document.getElementById('viewCarPrice');
  const viewBook = document.getElementById('viewCarBook');
  const viewClose = document.getElementById('viewCarClose');
  let viewVehicle = null;

  function closeViewCar() {
    if (!viewModal) return;
    viewModal.classList.remove('show');
    viewModal.setAttribute('aria-hidden', 'true');
    if (!document.getElementById('bookModal')?.classList.contains('show')) {
      document.body.style.overflow = '';
    }
  }

  function openViewCar(v) {
    viewVehicle = v;
    if (viewTitle) viewTitle.textContent = v.make_model || 'Vehicle';
    if (viewImg) {
      viewImg.src = v.img || 'assets/vehicles/images.jpeg';
      viewImg.alt = v.make_model || 'Vehicle';
    }
    const bits = [v.vehicle_type, v.transmission, v.seats ? (v.seats + ' seats') : ''].filter(Boolean);
    if (viewMeta) viewMeta.textContent = bits.join(' · ');
    const promo = v.promo_pricing || {};
    const now = Number(promo.has_promo ? promo.effective : (v.daily_rate_cdo || v.daily_rate || 0));
    if (viewPrice) {
      viewPrice.textContent = '₱' + formatMoney(now) + ' / day'
        + (promo.has_promo && promo.label ? ' · ' + promo.label : '');
    }
    if (viewModal) {
      viewModal.classList.add('show');
      viewModal.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';
    }
  }

  if (viewClose) viewClose.addEventListener('click', closeViewCar);
  if (viewModal) viewModal.addEventListener('click', (e) => { if (e.target === viewModal) closeViewCar(); });
  if (viewBook) {
    viewBook.addEventListener('click', async () => {
      if (!viewVehicle) return;
      closeViewCar();
      await openBookingModal({
        id: viewVehicle.id,
        name: viewVehicle.make_model || '',
        seats: viewVehicle.seats || '',
        fuel: viewVehicle.fuel_level || ''
      });
    });
  }
  if (viewImg) viewImg.addEventListener('error', () => { viewImg.src = 'assets/vehicles/images.jpeg'; });

  const bookId = new URLSearchParams(window.location.search).get('book');
  if (!bookId) return;
  fetch('vehiclepage.php?ajax=1&action=get_vehicle&vehicle_id=' + encodeURIComponent(bookId))
    .then((res) => res.json())
    .then((json) => {
      if (json && json.success && json.vehicle) openViewCar(json.vehicle);
    })
    .catch(() => {});
})();
document.addEventListener('visibilitychange', () => {
  if (!document.hidden) refreshBrowsePromoPrices();
});
</script>

</body>
</html>
<?php
$conn->close();
?>
