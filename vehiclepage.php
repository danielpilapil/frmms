<?php
/* ============================================================
   FleetGo — Vehicle Page (Public Access with User Features)
============================================================ */

/* ---------- SESSION FIX ---------- */
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
session_name('fleetgo_session_user');
session_start();

require_once __DIR__ . '/includes/db.php';

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

/* ==========================
  FETCH VEHICLES (Search / Filters / Sorting)
========================== */
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

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
$available_only = get_int('available_only', 0) === 1;
$q = get_str('q');
$sort = get_str('sort', 'recommended');

$dateRangeValid = false;
if ($start_date !== '' && $end_date !== '') {
    $sd = strtotime($start_date);
    $ed = strtotime($end_date);
    if ($sd && $ed && $ed >= $sd) {
        $dateRangeValid = true;
    }
}

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

if ($available_only) {
    $where[] = "v.current_status = 'available'";
    $where[] = "NOT EXISTS (\
        SELECT 1 FROM rentals rx\
        WHERE rx.vehicle_id = v.id\
          AND rx.status IN ('pending','ongoing','reserved')\
          AND ((rx.status = 'reserved' AND rx.end_date >= CURDATE()) OR rx.status IN ('pending','ongoing'))\
    )";
}

if ($dateRangeValid) {
    $where[] = "v.current_status NOT IN ('maintenance','scheduled_maintenance','inspection','unavailable')";
    $where[] = "NOT EXISTS (\
        SELECT 1 FROM rentals rconf\
        WHERE rconf.vehicle_id = v.id\
          AND rconf.status IN ('pending','ongoing','reserved')\
          AND (\
            (rconf.status IN ('pending','ongoing'))\
            OR (rconf.status = 'reserved' AND rconf.end_date >= CURDATE())\
          )\
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
elseif ($sort === 'recent') $orderBy = "v.created_at DESC, v.id DESC";

$sql = "
    SELECT
        v.id, v.make_model, v.vehicle_type, v.seats, v.transmission,
        v.daily_rate, v.current_status, v.photo, v.created_at,
        COALESCE(rc.total, 0) AS rental_count,
        ar.status AS active_rental_status,
        ar.end_date AS active_end_date
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
          AND r2.status IN ('ongoing','reserved','pending')
          AND ((r2.status = 'reserved' AND r2.end_date >= CURDATE()) OR r2.status IN ('ongoing','pending'))
        ORDER BY FIELD(r2.status,'ongoing','reserved','pending'), r2.end_date DESC, r2.id DESC
        LIMIT 1
    )
";

if (!empty($where)) {
    $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY $orderBy";

$vehicles = [];
try {
    $stmt = $conn->prepare($sql);
    if ($stmt && $types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    if ($stmt) {
        $stmt->execute();
        $vehicles = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
} catch (Throwable $e) {
    $vehicles = [];
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

/* ==========================
  HELPER FUNCTIONS
========================== */
function vehicle_img($row){
  $photo = trim((string)($row['photo'] ?? ''));
  if ($photo !== '') return 'assets/vehicles/'.h($photo);
  $type = strtolower($row['vehicle_type'] ?? '');
  if (strpos($type,'suv') !== false) return 'assets/vehicles/suv.jpg';
  if (strpos($type,'van') !== false) return 'assets/vehicles/van.jpg';
  if (strpos($type,'pickup') !== false) return 'assets/vehicles/pickup.jpg';
  if (strpos($type,'motor') !== false) return 'assets/vehicles/motorcycle.jpg';
  if (strpos($type,'hatch') !== false) return 'assets/vehicles/hatchback.jpg';
  if (strpos($type,'cross') !== false) return 'assets/vehicles/crossover.jpg';
  return 'assets/vehicles/sedan.jpg';
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

.search-card{margin-top:18px;background:rgba(16,20,25,0.92);border:1px solid var(--border);border-radius:20px;box-shadow:var(--shadow);padding:16px;backdrop-filter:blur(10px);}
.search-grid{display:grid;grid-template-columns:1.1fr 1.1fr 1fr auto;gap:12px;align-items:end;}
.field label{display:block;font-size:.82rem;color:rgba(242,246,250,0.78);font-weight:900;margin-bottom:6px;}
.control{width:100%;padding:12px 12px;border:1px solid rgba(255,255,255,0.14);border-radius:12px;background:rgba(16,20,25,0.90);font-weight:800;color:var(--text);outline:none;transition:box-shadow .15s ease,border-color .15s ease;}
.control:focus{border-color:rgba(93,208,255,0.65);box-shadow:0 0 0 4px rgba(93,208,255,0.12);}

.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;border-radius:12px;padding:12px 14px;font-weight:900;border:1px solid transparent;cursor:pointer;text-decoration:none;transition:transform .12s ease, box-shadow .12s ease, background .12s ease, border-color .12s ease;}
.btn:active{transform:translateY(1px);}
.btn-primary{background:var(--gradient);color:#041b22;box-shadow:0 12px 26px rgba(93,208,255,0.18);}
.btn-primary:hover{box-shadow:0 16px 34px rgba(93,208,255,0.24);}
.btn-secondary{background:rgba(255,255,255,0.04);border-color:rgba(255,255,255,0.14);color:rgba(242,246,250,0.92);}
.btn-secondary:hover{border-color:rgba(93,208,255,0.35);box-shadow:0 12px 24px rgba(0,0,0,0.25);}
.btn-ghost{background:transparent;border-color:rgba(255,255,255,0.14);color:rgba(242,246,250,0.78);}
.btn-ghost:hover{background:rgba(255,255,255,0.04);}
.btn-disabled{background:rgba(255,255,255,0.05);border-color:rgba(255,255,255,0.10);color:rgba(242,246,250,0.45);cursor:not-allowed;box-shadow:none;}

.layout{display:grid;grid-template-columns:360px minmax(0, 1fr);gap:18px;margin-top:22px;align-items:start;}
.filters{position:sticky;top:86px;}
.card-ui{background:var(--surface);border:1px solid var(--border);border-radius:20px;box-shadow:var(--shadow);}
.filters .card-ui{padding:16px;}
.filters h3{font-size:1.05rem;font-weight:1000;letter-spacing:-0.3px;}
.filter-group{margin-top:14px;}
.filter-row{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
.filter-actions{display:flex;gap:10px;margin-top:14px;}
.toggle{display:flex;align-items:center;gap:10px;margin-top:10px;font-weight:900;color:rgba(242,246,250,0.78);}
.toggle input{width:16px;height:16px;}

.results-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 16px;margin-bottom:14px;}
.results-meta{display:flex;align-items:baseline;gap:10px;flex-wrap:wrap;}
.count{font-weight:1000;letter-spacing:-0.3px;}
.subcount{color:rgba(242,246,250,0.70);font-weight:850;font-size:.9rem;}
.sort{display:flex;align-items:center;gap:10px;}
.sort label{font-size:.82rem;color:rgba(242,246,250,0.78);font-weight:900;}

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
.v-price{font-weight:1100;font-size:1.2rem;letter-spacing:-0.4px;}
.v-price span{font-size:.85rem;color:rgba(242,246,250,0.62);font-weight:900;}
.v-meta{display:flex;gap:8px;flex-wrap:wrap;color:rgba(242,246,250,0.72);font-weight:850;font-size:.9rem;}
.chip{display:inline-flex;align-items:center;gap:6px;padding:7px 10px;border-radius:999px;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.10);}
.v-actions{display:flex;gap:10px;align-items:center;margin-top:auto;padding-top:6px;}
.v-actions .btn{flex:1;}
.iconbtn{width:44px;flex:0 0 44px;display:inline-flex;align-items:center;justify-content:center;border-radius:12px;border:1px solid rgba(255,255,255,0.14);background:rgba(255,255,255,0.04);color:rgba(242,246,250,0.92);cursor:pointer;}
.iconbtn:hover{border-color:rgba(93,208,255,0.35);box-shadow:0 14px 26px rgba(0,0,0,0.35);}

.section-title{margin-top:26px;margin-bottom:10px;display:flex;align-items:baseline;justify-content:space-between;gap:12px;}
.section-title h2{font-size:1.25rem;font-weight:1100;letter-spacing:-0.4px;}
.section-title .hint{color:rgba(242,246,250,0.65);font-weight:850;font-size:.9rem;}

/* No horizontal scrolling sections (use aligned grids instead) */

.empty{display:grid;place-items:center;text-align:center;padding:40px 18px;background:var(--surface);border:1px dashed rgba(255,255,255,0.20);border-radius:20px;box-shadow:var(--shadow);}
.empty .ico{width:58px;height:58px;border-radius:18px;background:rgba(93,208,255,0.10);display:grid;place-items:center;color:rgba(93,208,255,0.95);margin-bottom:12px;}
.empty h3{font-size:1.2rem;font-weight:1100;letter-spacing:-0.4px;}
.empty p{margin-top:6px;color:rgba(242,246,250,0.65);font-weight:800;max-width:520px;}
.empty .actions{margin-top:14px;display:flex;gap:10px;justify-content:center;flex-wrap:wrap;}

/* Modal (booking + details) */
.modal{position:fixed;inset:0;background:rgba(0,0,0,.72);display:none;align-items:center;justify-content:center;z-index:300;backdrop-filter:blur(10px);}
.modal.show{display:flex}
.modal-content{background:var(--surface);padding:20px;border-radius:20px;max-width:560px;width:92%;box-shadow:var(--shadow-2);border:1px solid var(--border);position:relative;}
.modal-content h3{margin:0 0 14px;font-size:1.2rem;font-weight:1100;letter-spacing:-0.4px;}
.modal-content label{display:block;font-weight:900;margin-top:10px;color:rgba(242,246,250,0.80);font-size:.85rem;}
.modal-content input{width:100%;padding:12px 12px;margin-top:6px;border:1px solid rgba(255,255,255,0.14);border-radius:12px;font-size:1rem;background:rgba(16,20,25,0.90);color:var(--text);}
.modal-content input:focus{border-color:rgba(93,208,255,0.65);outline:none;box-shadow:0 0 0 4px rgba(93,208,255,0.12);}

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
 .modal-content input[type="time"]{width:100%;padding:12px 12px;border:1px solid rgba(255,255,255,0.14);border-radius:12px;font-size:1rem;font-weight:800;color:var(--text);background:rgba(16,20,25,0.90);transition:all .2s ease;}

.modal-content input[type="time"]{
  letter-spacing:1px;
}

 .modal-content input[type="date"]:hover,
 .modal-content input[type="time"]:hover{border-color:rgba(93,208,255,0.25);}

 .modal-content input[type="date"]:focus,
 .modal-content input[type="time"]:focus{border-color:rgba(93,208,255,0.65);outline:none;box-shadow:0 0 0 3px rgba(93,208,255,.12);}

/* Date/Time Rows */
.modal-content .date-time-row{
  display:grid;grid-template-columns:1fr 1fr;gap:16px;
  margin-bottom:20px;
}

.modal-content .date-time-row .input-group{
  margin-bottom:0;
}
 .modal-content button{margin-top:12px;padding:12px 14px;border:0;border-radius:12px;font-weight:1000;width:100%;background:var(--gradient);color:#041b22;cursor:pointer;font-size:1rem;box-shadow:0 12px 26px rgba(93,208,255,0.18);transition:all .15s ease;}
 .modal-content button:hover{box-shadow:0 16px 34px rgba(93,208,255,0.24);}
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
  .layout{grid-template-columns:1fr;}
  .filters{position:relative;top:auto;}
  .grid{grid-template-columns:repeat(auto-fill, minmax(320px, 1fr));}
  .grid-compact{grid-template-columns:repeat(auto-fill, minmax(260px, 1fr));}
}
@media (max-width: 860px){
  .grid{grid-template-columns:1fr;}
  .grid-compact{grid-template-columns:1fr;}
  .search-grid{grid-template-columns:1fr 1fr;}
  .search-grid .field:nth-child(3){grid-column:1/-1;}
  .search-grid .field:nth-child(4){grid-column:1/-1;}
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

      <div class="search-card">
        <div class="search-grid">
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
            <button class="btn btn-primary" type="submit">Search</button>
          </div>
        </div>
      </div>
    </section>

    <?php if ($isLoggedIn && !empty($recentViewed)): ?>
      <div class="section-title">
        <h2>Recently Viewed Vehicles</h2>
        <div class="hint">Based on your browsing</div>
      </div>
      <div class="grid grid-compact">
        <?php foreach ($recentViewed as $rv):
          $rvStatus = strtolower(trim((string)($rv['current_status'] ?? '')));
          $rvBadgeClass = $rvStatus === 'available' ? 'b-available' : (in_array($rvStatus, ['maintenance','scheduled_maintenance','inspection','unavailable']) ? 'b-maint' : 'b-rented');
          $rvBadgeText = $rvStatus === 'available' ? 'Available' : (in_array($rvStatus, ['maintenance','scheduled_maintenance','inspection','unavailable']) ? 'Maintenance' : 'Rented');
        ?>
          <article class="v-card">
            <div class="v-imgwrap">
              <img class="v-img" src="<?= h(vehicle_img($rv)) ?>" alt="<?= h($rv['make_model'] ?? '') ?>" onerror="this.src='assets/vehicles/sedan.jpg'">
              <span class="badge <?= h($rvBadgeClass) ?>"><?= h($rvBadgeText) ?></span>
            </div>
            <div class="v-body">
              <div class="v-title"><?= h($rv['make_model'] ?? '') ?></div>
              <div class="v-price">₱<?= number_format((float)($rv['daily_rate'] ?? 0), 2) ?> <span>/ day</span></div>
              <div class="v-meta">
                <span class="chip"><?= h($rv['transmission'] ?? '') ?></span>
                <span class="chip"><?= (int)($rv['seats'] ?? 0) ?> seats</span>
                <span class="chip"><?= h($rv['vehicle_type'] ?? '') ?></span>
              </div>
              <div class="v-actions">
                <button class="btn btn-secondary js-details" type="button"
                  data-id="<?= (int)$rv['id'] ?>"
                  data-name="<?= h($rv['make_model'] ?? '') ?>"
                  data-type="<?= h($rv['vehicle_type'] ?? '') ?>"
                  data-trans="<?= h($rv['transmission'] ?? '') ?>"
                  data-seats="<?= (int)($rv['seats'] ?? 0) ?>"
                  data-rate="<?= number_format((float)($rv['daily_rate'] ?? 0), 2, '.', '') ?>"
                  data-status="<?= h($rv['current_status'] ?? '') ?>"
                  data-img="<?= h(vehicle_img($rv)) ?>">View Details</button>
                <?php if (strtolower((string)($rv['current_status'] ?? '')) === 'available' && $isApproved): ?>
                  <button class="btn btn-primary btn-book" type="button" data-id="<?= (int)$rv['id'] ?>" data-name="<?= h($rv['make_model'] ?? '') ?>">Book Now</button>
                <?php else: ?>
                  <button class="btn btn-disabled" type="button" disabled><?= $isApproved ? 'Unavailable' : 'Profile Pending' ?></button>
                <?php endif; ?>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="layout">
      <aside class="filters">
        <div class="card-ui">
          <h3>Filters</h3>

          <div class="filter-group">
            <div class="field">
              <label for="q">Search</label>
              <input class="control" type="text" name="q" id="q" value="<?= h($q) ?>" placeholder="Make / model / type">
            </div>
          </div>

          <div class="filter-group">
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
          </div>

          <div class="filter-group">
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
          </div>

          <div class="filter-group">
            <div class="field">
              <label>Price range (₱ / day)</label>
              <div class="filter-row">
                <input class="control" type="number" name="min_price" value="<?= $min_price === null ? '' : h((string)$min_price) ?>" placeholder="Min" min="0" step="1">
                <input class="control" type="number" name="max_price" value="<?= $max_price === null ? '' : h((string)$max_price) ?>" placeholder="Max" min="0" step="1">
              </div>
            </div>
          </div>

          <div class="toggle">
            <input type="checkbox" name="available_only" value="1" id="available_only" <?= $available_only ? 'checked' : '' ?>>
            <label for="available_only" style="margin:0;text-transform:none;letter-spacing:0;font-size:.95rem;">Available only</label>
          </div>

          <div class="filter-actions">
            <button class="btn btn-primary" type="submit" style="flex:1;">Apply</button>
            <a class="btn btn-ghost" href="vehiclepage.php" style="flex:1;text-align:center;">Reset</a>
          </div>
        </div>
      </aside>

      <section>
        <div class="card-ui results-head">
          <div class="results-meta">
            <div class="count"><?= (int)count($vehicles) ?> vehicles</div>
            <div class="subcount"><?= $dateRangeValid ? ('for ' . h(date('M j', strtotime($start_date))) . ' → ' . h(date('M j', strtotime($end_date)))) : 'in the fleet' ?></div>
          </div>
          <div class="sort">
            <label for="sort">Sort</label>
            <select class="control" name="sort" id="sort" onchange="document.getElementById('browseForm').submit()" style="min-width:220px;">
              <option value="recommended" <?= $sort === 'recommended' ? 'selected' : '' ?>>Recommended</option>
              <option value="price_asc" <?= $sort === 'price_asc' ? 'selected' : '' ?>>Price: Low to High</option>
              <option value="price_desc" <?= $sort === 'price_desc' ? 'selected' : '' ?>>Price: High to Low</option>
              <option value="popular" <?= $sort === 'popular' ? 'selected' : '' ?>>Most Popular</option>
              <option value="recent" <?= $sort === 'recent' ? 'selected' : '' ?>>Recently Added</option>
            </select>
          </div>
        </div>

        <?php if (!empty($vehicles)): ?>
          <div class="grid">
            <?php foreach ($vehicles as $v):
              $status = strtolower(trim((string)($v['current_status'] ?? '')));
              $active = strtolower(trim((string)($v['active_rental_status'] ?? '')));

              $isMaint = in_array($status, ['maintenance','scheduled_maintenance','inspection','unavailable']);
              $isRentedNow = in_array($active, ['ongoing','reserved','pending']);

              // If user selected a date range, results are already filtered by conflicts;
              // badge should reflect current vehicle operational status (maintenance blocks rentals).
              if ($dateRangeValid) {
                $isAvailableForDates = ($status === 'available') && !$isMaint;
                $badgeClass = $isAvailableForDates ? 'b-available' : ($isMaint ? 'b-maint' : 'b-rented');
                $badgeText = $isAvailableForDates ? 'Available' : ($isMaint ? 'Maintenance' : 'Rented');
              } else {
                $badgeClass = ($status === 'available' && !$isRentedNow && !$isMaint) ? 'b-available' : ($isMaint ? 'b-maint' : 'b-rented');
                $badgeText = ($status === 'available' && !$isRentedNow && !$isMaint) ? 'Available' : ($isMaint ? 'Maintenance' : 'Rented');
              }

              $canBook = ($badgeText === 'Available') && $isLoggedIn && $isApproved;
            ?>
              <article class="v-card">
                <div class="v-imgwrap">
                  <img class="v-img" src="<?= h(vehicle_img($v)) ?>" alt="<?= h($v['make_model'] ?? '') ?>" onerror="this.src='assets/vehicles/sedan.jpg'">
                  <span class="badge <?= h($badgeClass) ?>"><?= h($badgeText) ?></span>
                </div>
                <div class="v-body">
                  <div>
                    <div class="v-title"><?= h($v['make_model'] ?? '') ?></div>
                    <div class="v-meta" style="margin-top:8px;">
                      <span class="chip"><?= h($v['transmission'] ?? '') ?></span>
                      <span class="chip"><?= (int)($v['seats'] ?? 0) ?> seats</span>
                      <span class="chip"><?= h($v['vehicle_type'] ?? '') ?></span>
                    </div>
                  </div>

                  <div class="v-price">₱<?= number_format((float)($v['daily_rate'] ?? 0), 2) ?> <span>/ day</span></div>

                  <div class="v-actions">
                    <button class="btn btn-secondary js-details" type="button"
                      data-id="<?= (int)$v['id'] ?>"
                      data-name="<?= h($v['make_model'] ?? '') ?>"
                      data-type="<?= h($v['vehicle_type'] ?? '') ?>"
                      data-trans="<?= h($v['transmission'] ?? '') ?>"
                      data-seats="<?= (int)($v['seats'] ?? 0) ?>"
                      data-rate="<?= number_format((float)($v['daily_rate'] ?? 0), 2, '.', '') ?>"
                      data-status="<?= h($v['current_status'] ?? '') ?>"
                      data-img="<?= h(vehicle_img($v)) ?>">View Details</button>

                    <?php if ($canBook): ?>
                      <button class="btn btn-primary btn-book" type="button" data-id="<?= (int)$v['id'] ?>" data-name="<?= h($v['make_model'] ?? '') ?>">Book Now</button>
                    <?php elseif (!$isLoggedIn && $badgeText === 'Available'): ?>
                      <a class="btn btn-primary" href="login.php">Login to Book</a>
                    <?php else: ?>
                      <button class="btn btn-disabled" type="button" disabled><?= $isApproved ? 'Unavailable' : 'Profile Pending' ?></button>
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
              $pvBadgeClass = $pvStatus === 'available' ? 'b-available' : (in_array($pvStatus, ['maintenance','scheduled_maintenance','inspection','unavailable']) ? 'b-maint' : 'b-rented');
              $pvBadgeText = $pvStatus === 'available' ? 'Available' : (in_array($pvStatus, ['maintenance','scheduled_maintenance','inspection','unavailable']) ? 'Maintenance' : 'Rented');
            ?>
              <article class="v-card">
                <div class="v-imgwrap">
                  <img class="v-img" src="<?= h(vehicle_img($pv)) ?>" alt="<?= h($pv['make_model'] ?? '') ?>" onerror="this.src='assets/vehicles/sedan.jpg'">
                  <span class="badge <?= h($pvBadgeClass) ?>"><?= h($pvBadgeText) ?></span>
                </div>
                <div class="v-body">
                  <div class="v-title"><?= h($pv['make_model'] ?? '') ?></div>
                  <div class="v-meta">
                    <span class="chip"><?= (int)($pv['_rental_count'] ?? 0) ?> rentals</span>
                    <span class="chip"><?= h($pv['transmission'] ?? '') ?></span>
                    <span class="chip"><?= h($pv['vehicle_type'] ?? '') ?></span>
                  </div>
                  <div class="v-price">₱<?= number_format((float)($pv['daily_rate'] ?? 0), 2) ?> <span>/ day</span></div>
                  <div class="v-actions">
                    <button class="btn btn-secondary js-details" type="button"
                      data-id="<?= (int)$pv['id'] ?>"
                      data-name="<?= h($pv['make_model'] ?? '') ?>"
                      data-type="<?= h($pv['vehicle_type'] ?? '') ?>"
                      data-trans="<?= h($pv['transmission'] ?? '') ?>"
                      data-seats="<?= (int)($pv['seats'] ?? 0) ?>"
                      data-rate="<?= number_format((float)($pv['daily_rate'] ?? 0), 2, '.', '') ?>"
                      data-status="<?= h($pv['current_status'] ?? '') ?>"
                      data-img="<?= h(vehicle_img($pv)) ?>">View Details</button>
                    <?php if (strtolower((string)($pv['current_status'] ?? '')) === 'available' && $isLoggedIn && $isApproved): ?>
                      <button class="btn btn-primary btn-book" type="button" data-id="<?= (int)$pv['id'] ?>" data-name="<?= h($pv['make_model'] ?? '') ?>">Book Now</button>
                    <?php else: ?>
                      <button class="btn btn-disabled" type="button" disabled><?= $isApproved ? 'Unavailable' : 'Profile Pending' ?></button>
                    <?php endif; ?>
                  </div>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
    </div>
  </form>
</main>

<!-- ===== DETAILS MODAL ===== -->
<div class="modal" id="detailsModal" aria-hidden="true">
  <div class="modal-content" role="dialog" aria-modal="true">
    <h3 id="detailsTitle">Vehicle Details</h3>
    <div id="detailsBody"></div>
    <div style="margin-top:14px;display:flex;gap:10px;">
      <button class="btn btn-secondary" type="button" id="detailsClose" style="flex:1;">Close</button>
      <button class="btn btn-primary" type="button" id="detailsBook" style="flex:1;display:none;">Book Now</button>
    </div>
  </div>
</div>

<!-- ===== BOOKING MODAL ===== -->
<div class="modal" id="bookModal">
  <div class="modal-content">
    <h3>Book <span id="vehName"></span></h3>
    <form id="bookForm">
      <input type="hidden" name="vehicle_id" id="vehID">
      <input type="hidden" name="rate_type" value="CDO">
      
      <div class="date-time-row">
        <div class="input-group">
          <label>Start Date</label>
          <input type="date" name="start_date" id="startDate" required>
        </div>
        
        <div class="input-group">
          <label>Start Time</label>
          <input type="time" name="start_time" id="startTime" value="08:00" required>
        </div>
      </div>
      
      <div class="date-time-row">
        <div class="input-group">
          <label>End Date</label>
          <input type="date" name="end_date" id="endDate" required>
        </div>
        
        <div class="input-group">
          <label>End Time</label>
          <input type="time" name="end_time" id="endTime" value="18:00" required>
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
      
      <button type="submit">Confirm Booking</button>
      <div id="msgBox"></div>
    </form>
  </div>
</div>

<footer style="text-align:center;color:rgba(242,246,250,0.62);font-weight:850;padding:22px 14px 40px;">
  © <?= date('Y') ?> FleetGo Rentals
</footer>

<script>
const modal = document.getElementById('bookModal');
const vehName = document.getElementById('vehName');
const vehID = document.getElementById('vehID');
const form = document.getElementById('bookForm');

// Keep date inputs consistent
const startInput = document.getElementById('start_date');
const endInput = document.getElementById('end_date');
if (startInput && endInput) {
  startInput.addEventListener('change', () => {
    if (startInput.value) endInput.min = startInput.value;
    if (endInput.value && startInput.value && endInput.value < startInput.value) endInput.value = '';
  });
}

document.querySelectorAll('.btn-book').forEach(btn => {
  btn.addEventListener('click', () => {
    vehName.textContent = btn.dataset.name;
    vehID.value = btn.dataset.id;
    
    // Prefill booking dates based on selected search dates when present
    const today = new Date().toISOString().split('T')[0];
    const minDate = btn.dataset.min || today;

    const selectedStart = (document.getElementById('start_date')?.value || '').trim();
    const selectedEnd = (document.getElementById('end_date')?.value || '').trim();

    // Always enforce minimum allowed date
    form.start_date.min = minDate;
    form.end_date.min = minDate;

    // If user searched by dates, carry them into booking modal
    if (selectedStart && selectedEnd && selectedEnd >= selectedStart) {
      form.start_date.value = (selectedStart >= minDate) ? selectedStart : minDate;
      form.end_date.min = form.start_date.value;
      form.end_date.value = (selectedEnd >= form.start_date.value) ? selectedEnd : '';
    } else {
      form.start_date.value = minDate;
      form.end_date.min = minDate;
      form.end_date.value = '';
    }
    
    // Set default times
    form.start_time.value = '08:00';
    form.end_time.value = '18:00';
    
    modal.classList.add('show');
    
    // Reset promotional preview
    document.getElementById('promoPreview').style.display = 'none';
  });
});

modal.addEventListener('click', e => { if (e.target === modal) modal.classList.remove('show'); });

// Details modal
const detailsModal = document.getElementById('detailsModal');
const detailsTitle = document.getElementById('detailsTitle');
const detailsBody = document.getElementById('detailsBody');
const detailsClose = document.getElementById('detailsClose');
const detailsBook = document.getElementById('detailsBook');

let lastDetails = null;

function openDetails(data) {
  lastDetails = data;
  if (detailsTitle) detailsTitle.textContent = data.name || 'Vehicle Details';
  if (detailsBody) {
    const safe = (v) => String(v || '').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    const img = safe(data.img || 'assets/vehicles/sedan.jpg');
    const status = safe(data.status || '');
    const rate = safe(data.rate || '0.00');
    detailsBody.innerHTML = `
      <div style="display:grid;grid-template-columns:1fr;gap:12px;">
        <img src="${img}" alt="${safe(data.name)}" style="width:100%;height:240px;object-fit:cover;border-radius:16px;border:1px solid rgba(255,255,255,0.10);background:rgba(255,255,255,0.03);" onerror="this.src='assets/vehicles/sedan.jpg'">
        <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:baseline;">
          <div style="font-weight:1100;font-size:1.15rem;letter-spacing:-0.4px;">${safe(data.name)}</div>
          <div style="font-weight:1100;font-size:1.2rem;">₱${rate} <span style=\"color:rgba(242,246,250,0.62);font-size:.85rem;font-weight:900;\">/ day</span></div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
          <span style="padding:7px 10px;border-radius:999px;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.10);font-weight:900;">${safe(data.type)}</span>
          <span style="padding:7px 10px;border-radius:999px;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.10);font-weight:900;">${safe(data.trans)}</span>
          <span style="padding:7px 10px;border-radius:999px;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.10);font-weight:900;">${safe(data.seats)} seats</span>
          <span style="padding:7px 10px;border-radius:999px;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.10);font-weight:900;">Status: ${status}</span>
        </div>
      </div>
    `;
  }

  if (detailsBook) {
    const canBook = String(data.canBook || '') === '1';
    detailsBook.style.display = canBook ? 'inline-flex' : 'none';
  }
  if (detailsModal) detailsModal.classList.add('show');
}

document.querySelectorAll('.js-details').forEach(btn => {
  btn.addEventListener('click', async () => {
    const payload = {
      id: btn.dataset.id || '',
      name: btn.dataset.name || '',
      type: btn.dataset.type || '',
      trans: btn.dataset.trans || '',
      seats: btn.dataset.seats || '',
      rate: btn.dataset.rate || '',
      status: btn.dataset.status || '',
      img: btn.dataset.img || '',
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
  detailsBook.addEventListener('click', () => {
    if (!lastDetails || !lastDetails.id) return;
    const match = document.querySelector(`.btn-book[data-id="${CSS.escape(String(lastDetails.id))}"]`);
    if (match) match.click();
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
        setTimeout(() => window.location.href = 'myrentals.php', 1500);
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
</script>

</body>
</html>
<?php
$conn->close();
?>
