<?php
/* ============================================================
   FleetGo — My Rentals (Session-Safe for User Role)
============================================================ */

/* ---------- SESSION FIX ---------- */
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
session_name('fleetgo_session_user');
session_start();

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/payment_methods.php';
ensure_rental_waitlist_status($conn);

/* Ensure cancel_reason columns exist for user cancellations */
try {
  $col = $conn->query("SHOW COLUMNS FROM rentals LIKE 'cancel_reason'");
  if (!$col || $col->num_rows === 0) {
    $conn->query("ALTER TABLE rentals ADD COLUMN cancel_reason TEXT NULL DEFAULT NULL AFTER status");
  }
  $col2 = $conn->query("SHOW COLUMNS FROM rentals LIKE 'cancelled_at'");
  if (!$col2 || $col2->num_rows === 0) {
    $conn->query("ALTER TABLE rentals ADD COLUMN cancelled_at DATETIME NULL DEFAULT NULL AFTER cancel_reason");
  }
} catch (Throwable $e) { /* ignore */ }

/* --- Access Control --- */
if (!isset($_SESSION['user_id'])) {
  header("Location: login.php");
  exit;
}

// Redirect admins to their dashboard
if (($_SESSION['role'] ?? '') === 'admin') {
    header("Location: dashboard.php");
    exit;
}

// Only allow users to access this page
if (($_SESSION['role'] ?? '') !== 'user') {
    header("Location: login.php");
    exit;
}

/* --- User session --- */
$userID   = (int)$_SESSION['user_id'];
$userName = $_SESSION['user_name'] ?? 'Guest';

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
define('VEH_IMG_URL', 'assets/vehicles');

// ===== FLEETGO FRMMS POLICIES =====
// Get fuel charge rates and washing rates
$fuelRates = null;
$washingRates = null;

// Older database backups may not contain this policy table yet. Ensure it
// exists before reading the current rates so the rentals page remains usable.
$conn->query("
    CREATE TABLE IF NOT EXISTS fuel_charge_rates (
        id INT(11) NOT NULL AUTO_INCREMENT,
        vehicle_type VARCHAR(50) NOT NULL,
        empty_rate DECIMAL(10,2) DEFAULT 2000.00,
        quarter_rate DECIMAL(10,2) DEFAULT 1500.00,
        half_rate DECIMAL(10,2) DEFAULT 1000.00,
        three_quarter_rate DECIMAL(10,2) DEFAULT 500.00,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");
$stmt = $conn->prepare("SELECT * FROM fuel_charge_rates ORDER BY id DESC LIMIT 1");
$stmt->execute();
$fuelRates = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ⚠️ SUGGESTION: Create washing table if not exists
// CREATE TABLE washing (
//     id INT PRIMARY KEY AUTO_INCREMENT,
//     light_wash DECIMAL(10,2) DEFAULT 200.00,
//     full_wash DECIMAL(10,2) DEFAULT 400.00,
//     interior_exterior DECIMAL(10,2) DEFAULT 600.00,
//     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
// );
// Keep compatibility with database backups created before washing rates existed.
$conn->query("
    CREATE TABLE IF NOT EXISTS washing_types (
        id INT(11) NOT NULL AUTO_INCREMENT,
        vehicle_type VARCHAR(50) NOT NULL,
        washing_name VARCHAR(100) NOT NULL,
        washing_rate DECIMAL(10,2) NOT NULL,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");
$stmt = $conn->prepare("SELECT * FROM washing_types ORDER BY id DESC LIMIT 1");
$stmt->execute();
$washingRates = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Extension requests (user submits → admin approves)
$conn->query("
  CREATE TABLE IF NOT EXISTS rental_extension_requests (
    id INT(11) NOT NULL AUTO_INCREMENT,
    rental_id INT(11) NOT NULL,
    customer_id INT(11) NOT NULL,
    old_end_date DATE NOT NULL,
    requested_end_date DATE NOT NULL,
    status ENUM('PENDING','APPROVED','REJECTED') NOT NULL DEFAULT 'PENDING',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_rental_status (rental_id, status),
    KEY idx_status (status)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

// Function to calculate late fee
function calculateLateFee($dailyRate, $hoursLate) {
    return ($dailyRate / 24) * $hoursLate * 1.25;
}

/* ==========================
   HANDLE USER EXTEND (POST) — creates pending request
========================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['extend_rental_id'], $_POST['new_end'])) {
  $rid    = (int)$_POST['extend_rental_id'];
  $newEnd = trim($_POST['new_end']);

  $stmt = $conn->prepare("
    SELECT r.id, r.vehicle_id, r.customer_id, r.start_date, r.end_date, r.status, v.make_model
    FROM rentals r
    JOIN vehicles v ON v.id = r.vehicle_id
    WHERE r.id = ? AND r.customer_id = ?
    LIMIT 1
  ");
  $stmt->bind_param("ii", $rid, $userID);
  $stmt->execute();
  $r = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$r) {
    $_SESSION['flash_error'] = "Rental not found.";
    header("Location: myrentals.php");
    exit;
  }

  if (strtolower($r['status']) !== 'ongoing') {
    $_SESSION['flash_error'] = "Only ongoing rentals can be extended.";
    header("Location: myrentals.php");
    exit;
  }

  $oldEnd = $r['end_date'];
  if (!$newEnd || strtotime($newEnd) <= strtotime($oldEnd)) {
    $_SESSION['flash_error'] = "New end date must be later than the current end date.";
    header("Location: myrentals.php");
    exit;
  }

  // Block duplicate pending requests
  $chk = $conn->prepare("SELECT id FROM rental_extension_requests WHERE rental_id = ? AND status = 'PENDING' LIMIT 1");
  $chk->bind_param('i', $rid);
  $chk->execute();
  $existing = $chk->get_result()->fetch_assoc();
  $chk->close();
  if ($existing) {
    $_SESSION['flash_error'] = "An extension request is already pending for this rental.";
    header("Location: myrentals.php");
    exit;
  }

  $ins = $conn->prepare("
    INSERT INTO rental_extension_requests
      (rental_id, customer_id, old_end_date, requested_end_date, status, created_at)
    VALUES (?, ?, ?, ?, 'PENDING', NOW())
  ");
  $ins->bind_param('iiss', $rid, $userID, $oldEnd, $newEnd);
  if (!$ins->execute()) {
    $ins->close();
    $_SESSION['flash_error'] = "Could not submit extension request. Please try again.";
    header("Location: myrentals.php");
    exit;
  }
  $ins->close();

  $vID = (int)$r['vehicle_id'];
  $model = $r['make_model'] ?? 'Vehicle';
  $modelEsc = $conn->real_escape_string($model);
  $newEndFmt = date('M d, Y', strtotime($newEnd));

  require_once __DIR__ . '/includes/notification_manager.php';
  $msgSelf = "Extension request submitted for <b>{$modelEsc}</b> until <b>{$newEndFmt}</b>. Waiting for admin approval.";
  createNotificationIfNotExists($conn, $userID, $vID, $msgSelf, false);

  $_SESSION['flash_success'] = "Extension request submitted until {$newEndFmt}. Waiting for admin approval.";
  header("Location: myrentals.php");
  exit;
}

 /* --- Filters --- */
 $statusFilter = strtolower(trim((string)($_GET['status'] ?? 'all')));
 $allowedStatus = ['all','waitlist','pending','reserved','ongoing','completed','cancelled'];
 if (!in_array($statusFilter, $allowedStatus, true)) {
   $statusFilter = 'all';
 }
 $q = trim($_GET['q'] ?? '');

/* --- Build query --- */
// SUBQUERY: Scalar subqueries for rental details and status calculations
$sql = "
  SELECT r.*, v.make_model, v.vehicle_type, v.plate_no, v.photo,
         (SELECT TIMESTAMPDIFF(HOUR, NOW(), r.end_date)) AS remaining_hours, -- SUBQUERY: Calculate remaining hours
         0 AS total_extensions,
         (SELECT DATEDIFF(r.end_date, r.start_date)) AS rental_duration_days, -- SUBQUERY: Calculate rental duration
         (SELECT CASE -- SUBQUERY: Determine detailed rental status
            WHEN r.status = 'ongoing' AND r.end_date < CURDATE() THEN 'OVERDUE'
            WHEN r.status = 'ongoing' AND r.end_date = CURDATE() THEN 'DUE_TODAY'
            WHEN r.status = 'ongoing' AND r.end_date > CURDATE() THEN 'ACTIVE'
            ELSE r.status
          END) AS rental_status_detailed
  FROM rentals r
  LEFT JOIN vehicles v ON r.vehicle_id = v.id
  WHERE r.customer_id = ?
";
$params = [$userID];
$types  = 'i';

 if ($statusFilter !== 'all') {
  $sql .= " AND LOWER(CAST(r.status AS CHAR)) = ?";
  $params[] = $statusFilter;
  $types   .= 's';
 }
if ($q !== '') {
  $sql .= " AND (v.make_model LIKE CONCAT('%', ?, '%') OR v.plate_no LIKE CONCAT('%', ?, '%'))";
  $params[] = $q;
  $params[] = $q;
  $types   .= 'ss';
}
$sql .= " ORDER BY r.start_date DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();
$rentals = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Payment summary per rental (pending + posted downpayments)
$paymentByRental = [];
$pendingExtensionsByRental = [];
if (!empty($rentals)) {
  $ids = array_map(function($r) { return (int)$r['id']; }, $rentals);
  $ids = array_values(array_filter($ids, function($id) { return $id > 0; }));
  if ($ids) {
    $in = implode(',', $ids);
    $payRes = $conn->query("
      SELECT rental_id,
             COALESCE(SUM(CASE WHEN status IN ('PENDING','POSTED') THEN amount ELSE 0 END), 0) AS paid_or_pending,
             COALESCE(SUM(CASE WHEN status = 'PENDING' THEN amount ELSE 0 END), 0) AS pending_amount,
             COALESCE(SUM(CASE WHEN status = 'POSTED' THEN amount ELSE 0 END), 0) AS posted_amount,
             MAX(CASE WHEN status = 'PENDING' THEN 1 ELSE 0 END) AS has_pending_proof
      FROM rental_payments
      WHERE rental_id IN ($in)
        AND payment_type = 'DOWNPAYMENT'
      GROUP BY rental_id
    ");
    if ($payRes) {
      while ($row = $payRes->fetch_assoc()) {
        $paymentByRental[(int)$row['rental_id']] = $row;
      }
    }

    $extRes = $conn->query("
      SELECT id, rental_id, old_end_date, requested_end_date, status, created_at
      FROM rental_extension_requests
      WHERE rental_id IN ($in)
        AND status = 'PENDING'
      ORDER BY created_at DESC, id DESC
    ");
    if ($extRes) {
      while ($row = $extRes->fetch_assoc()) {
        $rid = (int)$row['rental_id'];
        if (!isset($pendingExtensionsByRental[$rid])) {
          $pendingExtensionsByRental[$rid] = $row;
        }
      }
    }
  }
}

// SUBQUERY: User statistics with scalar subqueries for customer dashboard
$userStats = $conn->query("
    SELECT 
        (SELECT COUNT(*) FROM rentals WHERE customer_id = $userID) AS total_rentals, -- SUBQUERY: Count total rentals for user
        (SELECT COUNT(*) FROM rentals WHERE customer_id = $userID AND status = 'ongoing') AS active_rentals, -- SUBQUERY: Count active rentals for user
        (SELECT COUNT(*) FROM rentals WHERE customer_id = $userID AND status = 'completed') AS completed_rentals, -- SUBQUERY: Count completed rentals for user
        (SELECT SUM(total_cost) FROM rentals WHERE customer_id = $userID AND status = 'completed') AS total_spent, -- SUBQUERY: Calculate total spent by user
        (SELECT AVG(DATEDIFF(end_date, start_date)) FROM rentals WHERE customer_id = $userID AND status = 'completed') AS avg_rental_duration, -- SUBQUERY: Calculate average rental duration for user
        0 AS total_extensions_requested
")->fetch_assoc();

  $flash_success = $_SESSION['flash_success'] ?? '';
  $flash_error   = $_SESSION['flash_error'] ?? '';
  unset($_SESSION['flash_success'], $_SESSION['flash_error']);

 $statusCounts = ['waitlist'=>0,'pending'=>0,'reserved'=>0,'ongoing'=>0,'completed'=>0,'cancelled'=>0,'conflict_pending'=>0];
 try {
   $stmt = $conn->prepare("SELECT LOWER(CAST(status AS CHAR)) AS st, COUNT(*) AS c FROM rentals WHERE customer_id = ? GROUP BY LOWER(CAST(status AS CHAR))");
   $stmt->bind_param('i', $userID);
   $stmt->execute();
   $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
   $stmt->close();
   foreach ($rows as $row) {
     $st = strtolower(trim((string)($row['st'] ?? '')));
     if ($st === '') continue;
     if (!isset($statusCounts[$st])) $statusCounts[$st] = 0;
     $statusCounts[$st] = (int)($row['c'] ?? 0);
   }
 } catch (Throwable $e) {
 }?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>My Rentals — FleetGo</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<link href="assets/css/fleetgo-shared.css" rel="stylesheet">
</head>
<body>

<?php include __DIR__.'/includes/user_navbar.php'; ?>

<style>
:root{
  --bg:#0b0d10;--card:#101419;--card-hover:#1a1d26;
  --brand:#5dd0ff;--brand2:#7cffc7;--accent:#6366f1;
  --success:#10b981;--warning:#f59e0b;--error:#ef4444;
  --text-primary:#f2f6fa;--text-secondary:#9ca3af;--text-muted:#6b7280;
  --border:rgba(255,255,255,0.08);--border-hover:rgba(255,255,255,0.15);
  --radius:16px;--radius-sm:8px;
  --shadow:0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
  --shadow-lg:0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
  --shadow-xl:0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
  --gradient:linear-gradient(135deg,var(--brand),var(--brand2));
}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:Inter,system-ui,sans-serif;background:var(--bg);margin:0;color:var(--text-primary);line-height:1.6;}
a{text-decoration:none;color:inherit;}

/* ===== HERO SECTION ===== */
.hero{
  background:linear-gradient(135deg,rgba(11,13,16,.95),rgba(16,20,25,.9));
  color:var(--text-primary);padding:80px 0;text-align:center;position:relative;overflow:hidden;
}
.hero::before{
  content:"";position:absolute;inset:0;
  background:url('assets/banner1.jpg') center/cover;opacity:.2;z-index:-1;
  animation:heroFloat 20s ease-in-out infinite;
}
@keyframes heroFloat{
  0%,100%{transform:scale(1) rotate(0deg)}
  50%{transform:scale(1.05) rotate(1deg)}
}
.hero-content{
  max-width:920px;margin:0 auto;padding:0 18px;position:relative;z-index:2;
}
.hero h1{
  font-size:clamp(2.5rem,6vw,4rem);font-weight:900;margin-bottom:20px;
  background:var(--gradient);
  -webkit-background-clip:text;-webkit-text-fill-color:transparent;
  animation:fadeInUp 1s ease-out;
}
.hero p{
  font-size:1.2rem;color:var(--text-secondary);max-width:600px;margin:0 auto 40px;
  animation:fadeInUp 1s ease-out 0.3s both;
}

/* ===== STATS BAR ===== */
.stats-bar{
  display:flex;gap:40px;justify-content:center;margin:40px 0;flex-wrap:wrap;
  background:rgba(255,255,255,.1);padding:20px;border-radius:var(--radius-sm);
  backdrop-filter:blur(10px);border:1px solid rgba(255,255,255,.2);
}
.stat-item{
  text-align:center;padding:16px 24px;background:rgba(255,255,255,.1);
  border-radius:var(--radius-sm);border:1px solid rgba(255,255,255,.2);
  transition:all .3s ease;min-width:120px;
}
.stat-item:hover{
  background:rgba(255,255,255,.2);transform:translateY(-2px);
}
.stat-number{
  font-size:2rem;font-weight:900;color:var(--text-primary);display:block;margin-bottom:4px;
  text-shadow:0 2px 4px rgba(0,0,0,.3);
}
.stat-label{
  color:var(--text-secondary);font-size:.85rem;font-weight:600;
  text-transform:uppercase;letter-spacing:.5px;
}

/* ===== ENHANCED FILTERS ===== */
.filter-section{
  background:var(--card);padding:24px;border-radius:var(--radius);
  margin:32px 0;box-shadow:var(--shadow);
  border:1px solid var(--border);
}
.page-head{
  display:flex;gap:16px;align-items:flex-end;justify-content:space-between;
  margin:0 0 14px;
}
.page-title{font-size:1.4rem;font-weight:900;letter-spacing:.2px}
.page-sub{color:var(--text-secondary);font-weight:600;font-size:.95rem;margin-top:4px}
.mini-kpis{display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end}
.mini-kpi{background:rgba(255,255,255,.06);border:1px solid var(--border);border-radius:999px;padding:8px 12px;font-weight:800;font-size:.85rem;color:var(--text-primary)}
.mini-kpi span{color:var(--text-secondary);font-weight:700;margin-right:6px}

.tabs{
  display:flex;gap:10px;flex-wrap:wrap;align-items:center;
  margin:16px 0 0;
}
.tab{
  display:inline-flex;align-items:center;gap:10px;
  padding:10px 14px;border-radius:999px;
  border:1px solid var(--border);
  background:rgba(255,255,255,.04);
  color:var(--text-primary);
  font-weight:800;font-size:.9rem;
  transition:all .25s ease;
}
.tab:hover{border-color:var(--border-hover);background:rgba(255,255,255,.07);transform:translateY(-1px)}
.tab.active{background:linear-gradient(135deg, rgba(93,208,255,.18), rgba(124,255,199,.14));border-color:rgba(93,208,255,.45);color:var(--brand2)}
.tab-count{display:inline-flex;align-items:center;justify-content:center;min-width:28px;height:22px;padding:0 8px;border-radius:999px;background:rgba(0,0,0,.25);border:1px solid var(--border);color:var(--text-secondary);font-weight:900;font-size:.8rem}
.tab.active .tab-count{color:var(--text-primary);border-color:rgba(255,255,255,.18)}

.rentals-grid{display:flex;flex-direction:column;gap:14px;margin-top:22px}
.rental-card{
  display:grid;
  grid-template-columns: 180px minmax(0, 1fr) minmax(250px, 290px);
  align-items:stretch;
  gap:0;
  background:var(--card);
  border:1px solid var(--border);
  border-radius:var(--radius);
  overflow:hidden;
  box-shadow:var(--shadow);
  transition:all .25s ease;
  position:relative;
}
.rental-card:hover{transform:translateY(-2px);border-color:var(--border-hover);background:var(--card-hover);box-shadow:var(--shadow-lg)}
.rental-card.is-ongoing{outline:2px solid rgba(16,185,129,.25);box-shadow:0 0 0 6px rgba(16,185,129,.06), var(--shadow-lg)}
.rental-media{position:relative;min-height:150px;background:#07090c}
.rental-media img{width:100%;height:100%;object-fit:cover;display:block}
.rental-body{
  padding:18px 16px;
  display:flex;flex-direction:column;gap:12px;
  min-width:0;
}
.rental-top{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}
.rental-top > div:first-child{min-width:0;flex:1}
.rental-name{font-size:1.15rem;font-weight:900;line-height:1.25;word-break:break-word}
.rental-meta{color:var(--text-secondary);font-weight:700;font-size:.9rem;margin-top:4px;line-height:1.35}
.rental-dates{display:flex;flex-wrap:wrap;gap:10px 16px;color:var(--text-secondary);font-weight:700;font-size:.9rem}
.date-chip{display:inline-flex;align-items:center;gap:8px;line-height:1.3}
.date-ico{width:18px;height:18px;opacity:.85;flex-shrink:0}
.status-badge{
  display:inline-flex;align-items:center;justify-content:center;gap:6px;
  padding:7px 12px;border-radius:999px;
  font-weight:900;font-size:.72rem;
  text-transform:uppercase;letter-spacing:.55px;
  border:1px solid transparent;
  white-space:nowrap;flex-shrink:0;
  line-height:1.2;
}
.status-badge.waitlist{background:rgba(148,163,184,.16);border-color:rgba(148,163,184,.34);color:#e2e8f0}
.status-badge.pending{background:rgba(245,158,11,.14);border-color:rgba(245,158,11,.28);color:#ffd084}
.status-badge.reserved{background:rgba(99,102,241,.16);border-color:rgba(99,102,241,.30);color:#c7d2fe}
.status-badge.ongoing{background:rgba(16,185,129,.16);border-color:rgba(16,185,129,.30);color:#a7f3d0}
.status-badge.completed{background:rgba(148,163,184,.12);border-color:rgba(148,163,184,.20);color:#e2e8f0}
.status-badge.cancelled{background:rgba(239,68,68,.15);border-color:rgba(239,68,68,.28);color:#fecaca}
.status-badge.extension-pending{background:rgba(245,158,11,.16);border-color:rgba(245,158,11,.32);color:#ffd084}
.extension-note{
  margin:0;padding:10px 12px;border-radius:10px;
  font-weight:800;font-size:.82rem;color:#ffd084;line-height:1.4;
  background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.22);
}
.extension-note.is-cancel{
  color:#fecaca;background:rgba(239,68,68,.08);border-color:rgba(239,68,68,.28);
}
.hold-reminder{
  margin:0 0 16px;padding:14px 16px;border-radius:12px;
  background:rgba(255,209,102,.12);border:1px solid rgba(255,209,102,.4);
  color:#ffe7a3;font-weight:700;line-height:1.45;
}
.hold-reminder strong{display:block;margin-bottom:4px;color:#fff;}

.rental-side{
  padding:18px 16px;
  display:flex;flex-direction:column;gap:16px;justify-content:space-between;
  border-left:1px solid var(--border);background:rgba(255,255,255,.02);
  min-width:0;
}
.price-block{
  display:flex;flex-direction:column;align-items:flex-end;gap:8px;
  text-align:right;min-width:0;
}
.price{font-size:1.25rem;font-weight:900;line-height:1.2}
.price-sub{color:var(--text-secondary);font-weight:700;font-size:.85rem;line-height:1.4}
.price-sub b{color:var(--text-primary);font-weight:900}
.promo-row,
.pay-due-row{
  display:flex;flex-wrap:wrap;align-items:center;justify-content:flex-end;
  gap:8px;width:100%;
}
.price-was{
  color:var(--text-secondary);font-weight:800;font-size:.85rem;
  text-decoration:line-through;line-height:1.3;
}
.pay-due-row .price-sub{width:100%;text-align:right}
.ends{color:var(--text-secondary);font-weight:700;font-size:.86rem;line-height:1.35;margin:0}
.card-actions{
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:8px;
  width:100%;
  margin-top:0;
}
.card-actions .btn{
  width:100%;
  min-height:42px;
  padding:10px 12px;
  box-sizing:border-box;
  white-space:nowrap;
}
.card-actions .btn-span,
.card-actions .btn-pay-now,
.card-actions .btn:only-child{
  grid-column:1 / -1;
}
.btn{
  display:inline-flex;align-items:center;justify-content:center;gap:8px;
  padding:10px 12px;border-radius:12px;
  font-weight:900;font-size:.9rem;
  border:1px solid var(--border);
  background:rgba(255,255,255,.04);
  color:var(--text-primary);
  cursor:pointer;
  transition:all .2s ease;
  line-height:1.2;
}
.btn:hover{border-color:var(--border-hover);background:rgba(255,255,255,.06);transform:translateY(-1px)}
.btn.primary{background:var(--gradient);border-color:transparent;color:#041b22}
.btn.danger{background:linear-gradient(135deg, rgba(239,68,68,.18), rgba(239,68,68,.10));border-color:rgba(239,68,68,.30);color:#fecaca}
.btn[disabled]{opacity:.55;cursor:not-allowed;transform:none}

.promo-badge{
  display:inline-flex;align-items:center;
  background:var(--gradient);
  color:#041b22;padding:4px 9px;border-radius:8px;
  font-size:.68rem;font-weight:800;text-transform:uppercase;
  letter-spacing:.4px;line-height:1.2;white-space:nowrap;
}

.pay-modal{
  text-align:left;
  max-width:520px;
  max-height:min(90vh, 860px);
  overflow-y:auto;
  overscroll-behavior:contain;
  -webkit-overflow-scrolling:touch;
}
.pay-modal .form-row{margin-bottom:14px}
.pay-modal label{display:block;font-weight:800;font-size:.82rem;color:var(--text-secondary);margin-bottom:6px;text-transform:uppercase;letter-spacing:.4px}
.pay-modal input[type=text],
.pay-modal input[type=number],
.pay-modal select,
.pay-modal textarea{
  width:100%;padding:12px;border-radius:12px;border:1px solid var(--border);
  background:rgba(255,255,255,.03);color:var(--text-primary);font-weight:700;
}
.pay-modal select option,
.pay-modal select optgroup{
  color:#111;
  background:#fff;
}
.pay-modal input:focus,
.pay-modal select:focus,
.pay-modal textarea:focus{outline:none;border-color:var(--brand);box-shadow:0 0 0 3px rgba(93,208,255,.12)}
.pay-upload{
  border:2px dashed rgba(255,255,255,.14);border-radius:14px;padding:18px;text-align:center;
  background:rgba(255,255,255,.02);cursor:pointer;transition:all .2s ease;
}
.pay-upload:hover{border-color:rgba(93,208,255,.35);background:rgba(93,208,255,.05)}
.pay-upload input{display:none}
.pay-upload-title{font-weight:900;margin-top:8px}
.pay-upload-sub{color:var(--text-secondary);font-size:.85rem;font-weight:700;margin-top:4px}
.pay-upload-preview{margin-top:12px;display:none}
.pay-upload-preview img{max-width:100%;max-height:120px;object-fit:contain;border-radius:12px;border:1px solid var(--border)}
.pay-summary{
  display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;
  padding:12px 14px;border-radius:12px;border:1px solid var(--border);background:rgba(93,208,255,.06);
  margin-bottom:14px;font-weight:800;
}
.pay-msg{margin-top:10px;font-weight:800;text-align:center}
.pay-method-info{
  margin-top:10px;padding:18px 16px;border-radius:14px;
  border:1px solid rgba(93,208,255,.25);background:rgba(93,208,255,.05);
  display:flex;flex-direction:column;align-items:center;gap:14px;text-align:center;
}
.pay-method-info[hidden]{display:none}
.pay-method-qr-wrap{display:flex;flex-direction:column;align-items:center;gap:10px}
.pay-method-qr{
  display:block;width:min(240px, 70vw);height:auto;aspect-ratio:1 / 1;
  padding:10px;border-radius:14px;background:#fff;object-fit:contain;cursor:zoom-in;
}
.pay-qr-download{
  display:inline-flex;align-items:center;gap:6px;
  padding:8px 14px;border-radius:10px;text-decoration:none;
  font-size:.82rem;font-weight:800;color:#041b22;
  background:linear-gradient(135deg,var(--brand),var(--brand2, #7cffc7));
}
.pay-qr-download:hover{filter:brightness(1.05)}
.pay-method-details{width:100%;display:grid;gap:8px;justify-items:center}
.pay-method-number{justify-content:center}
.pay-method-provider{font-weight:900;font-size:1rem}
.pay-method-label{font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.4px;color:var(--text-secondary)}
.pay-method-number{
  display:flex;align-items:center;gap:8px;flex-wrap:wrap;
  font-family:ui-monospace,Consolas,monospace;font-size:1.05rem;font-weight:800;word-break:break-all;
}
.pay-copy-btn{
  border:1px solid var(--border);background:rgba(255,255,255,.05);color:var(--text-primary);
  border-radius:8px;padding:4px 10px;font-size:.75rem;font-weight:800;cursor:pointer;font-family:inherit;
}
.pay-copy-btn:hover{border-color:rgba(93,208,255,.4)}
.pay-method-name{color:var(--text-secondary);font-size:.85rem;font-weight:700}
.pay-method-hint{color:var(--text-secondary);font-size:.78rem;font-weight:700}

@media (max-width: 980px){
  .rental-card{grid-template-columns: 160px minmax(0, 1fr);}
  .rental-side{grid-column:1 / -1;border-left:none;border-top:1px solid var(--border)}
  .price-block{align-items:flex-start;text-align:left}
  .promo-row,.pay-due-row{justify-content:flex-start}
  .pay-due-row .price-sub{text-align:left}
}
@media (max-width: 560px){
  .page-head{flex-direction:column;align-items:flex-start}
  .mini-kpis{justify-content:flex-start}
  .rental-card{grid-template-columns: 1fr;}
  .rental-media{min-height:200px}
  .card-actions{grid-template-columns:1fr}
  .card-actions .btn-span,
  .card-actions .btn-pay-now,
  .card-actions .btn:only-child{grid-column:auto}
}
.filter-bar{
  display:flex;flex-wrap:wrap;gap:16px;align-items:center;justify-content:center;
}
.search-box{
  flex:1;min-width:250px;position:relative;
}
.search-input{
  width:100%;padding:12px 16px 12px 44px;border:2px solid var(--border);
  border-radius:var(--radius-sm);font-size:1rem;transition:all .3s ease;
  background:var(--card);color:var(--text-primary);
}
.search-input:focus{
  border-color:var(--brand);box-shadow:0 0 0 4px rgba(93,208,255,.1);outline:none;
}
.search-icon{
  position:absolute;left:16px;top:50%;transform:translateY(-50%);
  color:var(--text-secondary);display:flex;align-items:center;justify-content:center;
}
.search-icon svg{
  width:20px;height:20px;
}
.filter-select{
  padding:12px 16px;border:2px solid var(--border);border-radius:var(--radius-sm);
  background:var(--card);font-weight:600;cursor:pointer;transition:all .3s ease;
  color:var(--text-primary);
}
.filter-select:focus{
  border-color:var(--brand);outline:none;
}
.filter-btn{
  padding:12px 24px;border:0;border-radius:var(--radius-sm);
  background:var(--gradient);
  color:#041b22;font-weight:800;cursor:pointer;transition:all .3s ease;
  box-shadow:var(--shadow);
}
.filter-btn:hover{
  transform:translateY(-2px);box-shadow:0 8px 20px rgba(93,208,255,.35);
}

/* ===== PROFESSIONAL RENTAL TABLE ===== */
.main{max-width:1220px;margin:0 auto;padding:40px 18px;}
.rentals-table{
  background:var(--card);border-radius:var(--radius-sm);overflow:hidden;
  box-shadow:var(--shadow);margin-top:32px;
}
.table-header{
  background:var(--gradient);
  color:#041b22;padding:20px;font-weight:800;font-size:1.1rem;
  text-transform:uppercase;letter-spacing:.5px;
}
.rental-item{
  display:grid;grid-template-columns:80px 1fr auto auto auto auto;
  gap:20px;align-items:center;padding:20px;border-bottom:1px solid var(--border);
  transition:all .3s ease;
}
.rental-item:last-child{border-bottom:none}
.rental-item:hover{
  background:var(--card-hover);transform:translateX(4px);
}
.rental-image{
  width:60px;height:40px;object-fit:cover;border-radius:6px;
  box-shadow:0 2px 8px rgba(0,0,0,.1);
}
.rental-info h4{
  font-size:1.1rem;font-weight:700;color:var(--text-primary);margin-bottom:4px;
}
.rental-info p{
  color:var(--text-secondary);font-size:.9rem;margin:0;
}
.rental-dates{
  text-align:center;color:var(--text-secondary);font-size:.9rem;
}
.rental-cost{
  text-align:center;font-weight:700;color:var(--text-primary);font-size:1rem;
}
.cost-with-promo{
  display:flex;flex-direction:column;align-items:center;gap:4px;
}
.final-cost{
  font-size:1.1rem;font-weight:800;color:var(--success);
}
.original-cost{
  font-size:.85rem;color:var(--text-secondary);text-decoration:line-through;
}
.rental-item .status-badge{
  padding:6px 12px;border-radius:var(--radius);font-weight:700;font-size:.8rem;
  text-transform:uppercase;letter-spacing:.5px;text-align:center;
}
.rental-item .status-badge.active{
  background:rgba(16,185,129,.15);color:var(--success);
}
.rental-item .status-badge.completed{
  background:rgba(93,208,255,.15);color:var(--brand);
}
.rental-item .status-badge.cancelled{
  background:rgba(239,68,68,.15);color:var(--error);
}
.rental-actions{
  display:flex;gap:8px;justify-content:center;
}
.extend-btn{
  background:var(--gradient);
  color:#041b22;border:0;border-radius:var(--radius-sm);padding:8px 16px;
  font-weight:700;font-size:.85rem;cursor:pointer;transition:all .3s ease;
}
.extend-btn:hover{
  transform:translateY(-1px);box-shadow:0 4px 12px rgba(93,208,255,.3);
}

/* ===== ENHANCED MODAL ===== */
.modal-bg{
  position:fixed;inset:0;background:rgba(0,0,0,.7);
  display:none;align-items:center;justify-content:center;z-index:10050;
  backdrop-filter:blur(8px);
  overflow-y:auto;
  padding:24px 12px;
}
.modal-bg.show{display:flex !important;animation:fadeIn 0.3s ease;}
.modal{
  background:var(--card);
  color:var(--text-primary);border-radius:var(--radius);padding:32px;
  width:90%;max-width:480px;box-shadow:var(--shadow-xl);
  text-align:center;position:relative;animation:slideUp 0.3s ease;
  border:2px solid var(--border);
  margin:auto;
}
@keyframes slideUp{
  from{transform:translateY(30px);opacity:0}
  to{transform:translateY(0);opacity:1}
}
.modal h2{
  margin:0 0 16px;color:var(--brand);font-weight:900;font-size:1.8rem;
  background:var(--gradient);
  -webkit-background-clip:text;-webkit-text-fill-color:transparent;
}
.modal p{
  margin-bottom:16px;color:var(--text-secondary);font-size:1rem;
}
.modal input[type=date]{
  width:100%;background:var(--card-hover);border:2px solid var(--border);
  border-radius:var(--radius-sm);padding:12px;color:var(--text-primary);font-size:1rem;
  text-align:center;transition:all .3s ease;
}
.modal input[type=date]:focus{
  border-color:var(--brand);box-shadow:0 0 0 4px rgba(93,208,255,.1);
  outline:none;
}
.modal .actions{
  display:flex;gap:16px;justify-content:center;margin-top:24px;
}
.modal .btn{
  border:0;cursor:pointer;font-weight:800;border-radius:12px;
  padding:12px 24px;font-size:1rem;transition:all .3s ease;
}
.btn-extend{
  background:linear-gradient(135deg,var(--warning),#ffb347);
  color:#2a1d00;box-shadow:var(--shadow);
}
.btn-extend:hover{
  transform:translateY(-2px);box-shadow:0 8px 20px rgba(255,209,102,.35);
}
.btn-cancel{
  background:linear-gradient(135deg,var(--error),#ff5252);
  color:#fff;box-shadow:var(--shadow);
}
.btn-cancel:hover{
  transform:translateY(-2px);box-shadow:0 8px 20px rgba(255,107,107,.35);
}

/* ===== ENHANCED BUTTONS ===== */
.btn-inline{
  position:absolute;top:16px;right:16px;
  background:var(--gradient);
  color:#041b22;font-weight:800;border:0;border-radius:var(--radius-sm);
  padding:8px 16px;cursor:pointer;transition:all .3s ease;
  box-shadow:var(--shadow);
}
.btn-inline:hover{
  transform:translateY(-2px);box-shadow:0 6px 16px rgba(93,208,255,.35);
}

/* ===== TOAST NOTIFICATIONS ===== */
.toast{
  position:fixed;top:80px;right:20px;
  background:var(--gradient);
  color:#041f2a;padding:16px 20px;border-radius:var(--radius-sm);
  font-weight:800;box-shadow:var(--shadow);
  z-index:999;animation:slideInRight 0.3s ease;
}
.toast.err{
  background:linear-gradient(135deg,var(--error),#ff5252);
  color:#fff;box-shadow:var(--shadow);
}
@keyframes slideInRight{
  from{transform:translateX(100%);opacity:0}
  to{transform:translateX(0);opacity:1}
}

/* ===== EMPTY STATE ===== */
.empty{
  text-align:center;color:var(--text-secondary);padding:80px 20px;
  background:var(--card);border-radius:var(--radius);
  box-shadow:var(--shadow);
}
.empty-icon{
  width:80px;height:80px;margin:0 auto 24px;display:flex;align-items:center;justify-content:center;
  background:var(--gradient);
  border-radius:var(--radius);color:#041b22;opacity:.8;
}
.empty-icon svg{
  width:48px;height:48px;
}
.empty h3{
  font-size:1.5rem;margin-bottom:12px;color:var(--text-primary);
}
.empty p{
  color:var(--text-secondary);margin-bottom:24px;font-size:1rem;
}
.empty a{
  display:inline-block;
  background:var(--gradient);
  color:#041b22;padding:12px 24px;border-radius:var(--radius-sm);
  text-decoration:none;font-weight:700;transition:all .3s ease;
  box-shadow:var(--shadow);
}
.empty a:hover{
  transform:translateY(-2px);box-shadow:0 8px 20px rgba(93,208,255,.35);
}

/* ===== ANIMATIONS ===== */
@keyframes fadeIn{
  from{opacity:0}
  to{opacity:1}
}
@keyframes fadeInUp{
  from{opacity:0;transform:translateY(30px)}
  to{opacity:1;transform:translateY(0)}
}

/* ===== SUMMARY DROPDOWN ===== */
.summary-dropdown{
  margin-top:16px;border-radius:var(--radius-sm);overflow:hidden;
  background:var(--card-hover);border:1px solid var(--border);
  transition:all .3s ease;
}
.summary-toggle{
  width:100%;display:flex;align-items:center;justify-content:space-between;
  padding:16px 20px;background:var(--card);border:0;cursor:pointer;
  color:var(--text-primary);font-weight:700;font-size:1rem;
  transition:all .3s ease;position:relative;
}
.summary-toggle:hover{
  background:var(--card-hover);transform:translateY(-1px);
  box-shadow:0 4px 12px rgba(0,0,0,.1);
}
.summary-title{
  display:flex;align-items:center;gap:8px;color:var(--brand);
}
.summary-title svg{
  color:var(--success);
}
.summary-total{
  font-size:1.1rem;font-weight:800;color:var(--text-primary);
  display:flex;align-items:center;gap:8px;
}
.additional-badge{
  background:linear-gradient(135deg,var(--warning),#ffb347);
  color:#2a1d00;padding:2px 8px;border-radius:var(--radius-sm);
  font-size:.75rem;font-weight:800;text-transform:uppercase;
  letter-spacing:.5px;
}
.summary-arrow{
  color:var(--text-secondary);transition:transform .3s ease;
}
.summary-toggle.active .summary-arrow{
  transform:rotate(180deg);
}
.summary-content{
  max-height:0;overflow:hidden;transition:max-height .4s ease;
  background:var(--card);border-top:1px solid var(--border);
}
.summary-content.expanded{
  max-height:800px;
}
.summary-section{
  padding:20px;border-bottom:1px solid var(--border);
}
.summary-section:last-child{
  border-bottom:none;
}
.summary-section h5{
  color:var(--brand);font-weight:800;font-size:1rem;
  margin:0 0 12px;text-transform:uppercase;letter-spacing:.5px;
}
.summary-line{
  display:flex;justify-content:space-between;align-items:center;
  padding:8px 0;border-bottom:1px solid rgba(255,255,255,.05);
  font-size:.95rem;
}
.summary-line:last-child{
  border-bottom:none;
}
.summary-line span:first-child{
  color:var(--text-secondary);font-weight:600;
}
.summary-line span:last-child{
  color:var(--text-primary);font-weight:700;
}
.summary-line.charge span:last-child{
  color:var(--error);
}
.summary-line.promo span:last-child{
  color:var(--success);
}
.summary-line.total{
  background:var(--gradient);
  color:#041b22;padding:12px 16px;border-radius:var(--radius-sm);
  margin-top:8px;font-weight:800;font-size:1.1rem;
}
.summary-line.total span:last-child{
  color:#041b22;
}
.total-section{
  background:rgba(93,208,255,.05);border-radius:var(--radius-sm);
  margin:8px 0 0;
}
.btn-receipt{
  background:linear-gradient(135deg,var(--brand),var(--brand2));
  color:#041b22;border:0;border-radius:var(--radius-sm);padding:8px 16px;
  font-weight:700;font-size:.85rem;cursor:pointer;transition:all .3s ease;
  box-shadow:var(--shadow);
}
.btn-receipt:hover{
  transform:translateY(-1px);box-shadow:0 4px 12px rgba(93,208,255,.3);
}

/* ===== RECEIPT MODAL STYLES ===== */
.receipt-modal{
  width:min(600px,94vw);
  max-height:90vh;
  overflow-y:auto;
  background:linear-gradient(180deg,#ffffff,#f8fafc);
  color:#1e293b;
  border:2px solid #e2e8f0;
  position:relative;
  z-index:1001;
}
.receipt-header{
  text-align:center;
  padding-bottom:20px;
  border-bottom:2px solid #e2e8f0;
  margin-bottom:20px;
}
.receipt-header h2{
  color:#1e40af;
  margin:0 0 15px;
  font-size:1.6rem;
}
.receipt-info{
  display:grid;
  grid-template-columns:1fr 1fr 1fr;
  gap:15px;
  font-size:.9rem;
  color:#64748b;
}
.receipt-info div{
  text-align:center;
  padding:8px;
  background:#f1f5f9;
  border-radius:8px;
}
.receipt-body{
  margin-bottom:20px;
}
.receipt-section{
  margin-bottom:25px;
  padding:15px;
  background:#ffffff;
  border-radius:12px;
  border:1px solid #e2e8f0;
  box-shadow:0 2px 4px rgba(0,0,0,0.05);
}
.receipt-section h3{
  margin:0 0 15px;
  color:#1e40af;
  font-size:1.1rem;
  border-bottom:1px solid #e2e8f0;
  padding-bottom:8px;
}
.condition-grid{
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:12px;
}
.condition-item{
  display:flex;
  justify-content:space-between;
  align-items:center;
  padding:8px 12px;
  background:#f8fafc;
  border-radius:8px;
  border:1px solid #e2e8f0;
}
.condition-item .label{
  font-weight:600;
  color:#374151;
}
.condition-item .value{
  font-weight:700;
  color:#1e40af;
}
.damage-report{
  background:#fef3c7;
  border:1px solid #f59e0b;
  border-radius:8px;
  padding:12px;
  color:#92400e;
  font-style:italic;
}
.cost-breakdown{
  background:#f8fafc;
  border-radius:8px;
  padding:15px;
}
.cost-item{
  display:flex;
  justify-content:space-between;
  align-items:center;
  padding:8px 0;
  border-bottom:1px solid #e2e8f0;
}
.cost-item:last-child{
  border-bottom:none;
}
.cost-item.total{
  font-weight:800;
  font-size:1.1rem;
  color:#1e40af;
  background:#e0f2fe;
  padding:12px;
  border-radius:8px;
}
.rental-summary{
  display:flex;
  flex-direction:column;
  gap:8px;
  margin-bottom:15px;
}
.summary-item{
  display:flex;
  justify-content:space-between;
  align-items:center;
  padding:8px 0;
  border-bottom:1px solid #f0f0f0;
}
.summary-item:last-child{
  border-bottom:none;
}
.payment-status{
  display:flex;
  justify-content:space-between;
  align-items:center;
  padding:15px 0;
  border-top:2px solid #e0e0e0;
  margin-top:10px;
}
.payment-status .label{
  font-weight:600;
  color:#333;
}
.payment-status .value{
  font-weight:700;
  font-size:1.1em;
}
.payment-status .value.status{
  padding:4px 12px;
  border-radius:20px;
  font-size:0.9em;
  font-weight:600;
}
.payment-status .value.status.fully-paid{
  background-color:#d4edda;
  color:#155724;
}
.payment-status .value.status.balance-remaining{
  background-color:#d1ecf1;
  color:#0c5460;
}
.payment-status .value.status.additional-charges-due{
  background-color:#f8d7da;
  color:#721c24;
  margin-top:10px;
}
.payment-status .value.status.fully-paid-additional-charges-waived{
  background-color:#d4edda;
  color:#155724;
}
.payment-status .value.status.fully-paid-original-balance-covered{
  background-color:#d4edda;
  color:#155724;
}
.cost-item .label{
  font-weight:600;
  color:#374151;
}
.cost-item .value{
  font-weight:700;
  color:#1e40af;
}
.cost-item .value.penalty{
  color:#dc2626;
}
.cost-item .value.breakdown{
  color:#64748b;
  font-size:.85rem;
  font-style:italic;
}
.cost-item .value.penalty{
  color:#dc2626;
  font-weight:700;
}
.cost-item .value.zero{
  color:#10b981;
  font-style:italic;
}
.cost-item .value.zero::after{
  content:" ✓";
  color:#10b981;
}
.cost-divider{
  height:2px;
  background:linear-gradient(90deg,transparent,#e2e8f0,transparent);
  margin:10px 0;
}
.receipt-footer{
  border-top:2px solid #e2e8f0;
  padding-top:20px;
}
.receipt-actions{
  display:flex;
  gap:10px;
  justify-content:center;
  margin-bottom:15px;
}
.receipt-note{
  text-align:center;
  color:#64748b;
  font-size:.85rem;
  font-style:italic;
}
.receipt-note p{
  margin:0;
  padding:10px;
  background:#f1f5f9;
  border-radius:8px;
}

/* Print Styles */
@media print {
  .receipt-modal{
    width:100% !important;
    max-height:none !important;
    background:white !important;
    color:black !important;
    border:none !important;
    box-shadow:none !important;
  }
  .receipt-actions{
    display:none !important;
  }
  .receipt-note{
    display:none !important;
  }
  .receipt-section{
    break-inside:avoid;
    box-shadow:none !important;
    border:1px solid #ccc !important;
  }
}

/* ===== FOOTER ===== */
.footer{
  background:var(--bg);color:var(--text-primary);padding:32px 0;margin-top:80px;
  text-align:center;border-top:2px solid var(--brand);
}
.footer a{
  color:var(--brand2);text-decoration:none;font-weight:700;margin:0 8px;
  transition:color .3s ease;
}
.footer a:hover{color:var(--brand);}
</style>

<!-- ===== HERO SECTION ===== -->
<section class="hero">
  <div class="hero-content">
    <h1>My Rentals</h1>
    <p>Manage your bookings and track your trips</p>
    
    <!-- ===== STATISTICS BAR ===== -->
    <div class="stats-bar">
      <div class="stat-item">
        <span class="stat-number" id="totalRentals">0</span>
        <span class="stat-label">Total Rentals</span>
      </div>
      <div class="stat-item">
        <span class="stat-number" id="activeRentals">0</span>
        <span class="stat-label">Active Now</span>
      </div>
      <div class="stat-item">
        <span class="stat-number" id="completedRentals">0</span>
        <span class="stat-label">Completed</span>
      </div>
      <div class="stat-item">
        <span class="stat-number" id="totalSpent">₱0</span>
        <span class="stat-label">Total Spent</span>
      </div>
    </div>
  </div>
</section>

<div class="main">
  <?php if($flash_success): ?><div class="toast"><?= h($flash_success) ?></div><?php endif; ?>
  <?php if($flash_error): ?><div class="toast err"><?= h($flash_error) ?></div><?php endif; ?>
  <?php if((int)($statusCounts['waitlist'] ?? 0) > 0): ?>
    <div class="hold-reminder" role="status">
      <strong>Your booking is on hold</strong>
      You still have not paid. Submit your downpayment receipt to move the booking to pending. Until then it stays on hold and will not be approved.
    </div>
  <?php endif; ?>

  <!-- ===== ENHANCED FILTERS ===== -->
  <div class="filter-section">
    <div class="page-head">
      <div>
        <div class="page-title">My Rentals</div>
        <div class="page-sub">Manage your bookings and track your trips</div>
      </div>
      <div class="mini-kpis" aria-label="Rental summary">
        <div class="mini-kpi"><span>Total</span><?= (int)array_sum($statusCounts) ?></div>
        <div class="mini-kpi"><span>Active</span><?= (int)($userStats['active_rentals'] ?? ($statusCounts['ongoing'] ?? 0)) ?></div>
        <div class="mini-kpi"><span>Completed</span><?= (int)($userStats['completed_rentals'] ?? ($statusCounts['completed'] ?? 0)) ?></div>
      </div>
    </div>

    <div class="tabs" role="tablist" aria-label="Rental status tabs">
      <a class="tab <?= $statusFilter==='all'?'active':'' ?>" href="myrentals.php?status=all<?= $q!=='' ? '&q='.urlencode($q) : '' ?>" role="tab" aria-selected="<?= $statusFilter==='all'?'true':'false' ?>">
        All
        <span class="tab-count"><?= (int)array_sum($statusCounts) ?></span>
      </a>
      <a class="tab <?= $statusFilter==='waitlist'?'active':'' ?>" href="myrentals.php?status=waitlist<?= $q!=='' ? '&q='.urlencode($q) : '' ?>" role="tab" aria-selected="<?= $statusFilter==='waitlist'?'true':'false' ?>">
        Waitlist
        <span class="tab-count"><?= (int)($statusCounts['waitlist'] ?? 0) ?></span>
      </a>
      <a class="tab <?= $statusFilter==='pending'?'active':'' ?>" href="myrentals.php?status=pending<?= $q!=='' ? '&q='.urlencode($q) : '' ?>" role="tab" aria-selected="<?= $statusFilter==='pending'?'true':'false' ?>">
        Pending
        <span class="tab-count"><?= (int)($statusCounts['pending'] ?? 0) ?></span>
      </a>
      <a class="tab <?= $statusFilter==='reserved'?'active':'' ?>" href="myrentals.php?status=reserved<?= $q!=='' ? '&q='.urlencode($q) : '' ?>" role="tab" aria-selected="<?= $statusFilter==='reserved'?'true':'false' ?>">
        Reserved
        <span class="tab-count"><?= (int)($statusCounts['reserved'] ?? 0) ?></span>
      </a>
      <a class="tab <?= $statusFilter==='ongoing'?'active':'' ?>" href="myrentals.php?status=ongoing<?= $q!=='' ? '&q='.urlencode($q) : '' ?>" role="tab" aria-selected="<?= $statusFilter==='ongoing'?'true':'false' ?>">
        Ongoing
        <span class="tab-count"><?= (int)($statusCounts['ongoing'] ?? 0) ?></span>
      </a>
      <a class="tab <?= $statusFilter==='completed'?'active':'' ?>" href="myrentals.php?status=completed<?= $q!=='' ? '&q='.urlencode($q) : '' ?>" role="tab" aria-selected="<?= $statusFilter==='completed'?'true':'false' ?>">
        Completed
        <span class="tab-count"><?= (int)($statusCounts['completed'] ?? 0) ?></span>
      </a>
      <a class="tab <?= $statusFilter==='cancelled'?'active':'' ?>" href="myrentals.php?status=cancelled<?= $q!=='' ? '&q='.urlencode($q) : '' ?>" role="tab" aria-selected="<?= $statusFilter==='cancelled'?'true':'false' ?>">
        Cancelled
        <span class="tab-count"><?= (int)($statusCounts['cancelled'] ?? 0) ?></span>
      </a>
    </div>

    <form class="filter-bar" method="get" style="margin-top:16px">
      <input type="hidden" name="status" value="<?= h($statusFilter) ?>">
      <div class="search-box">
        <div class="search-icon">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="11" cy="11" r="8"/>
            <path d="M21 21l-4.35-4.35"/>
          </svg>
        </div>
        <input type="text" name="q" class="search-input" placeholder="Search by vehicle or plate..." value="<?= h($q) ?>">
      </div>
      <button type="submit" class="filter-btn">Search</button>
    </form>
  </div>

  <?php if(!$rentals): ?>
    <div class="empty">
      <div class="empty-icon">
        <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
          <path d="M19 17h2l.64-2.54A2 2 0 0 0 19.65 12H4.35a2 2 0 0 0-1.99 2.46L3 17h2"/>
          <path d="M8 17h8"/>
          <path d="M3 9h18v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V9z"/>
        </svg>
      </div>
      <?php if ($statusFilter === 'cancelled'): ?>
        <h3>No Cancelled Bookings</h3>
        <p>Cancelled rentals will show up here once you cancel a pending or reserved booking.</p>
      <?php elseif ($statusFilter !== 'all'): ?>
        <h3>No <?= h(ucfirst($statusFilter)) ?> Rentals</h3>
        <p>Nothing matches this filter right now.</p>
        <a href="myrentals.php?status=all">View all rentals</a>
      <?php else: ?>
        <h3>No Rentals Yet</h3>
        <p>You haven't made any bookings yet. Start your journey by exploring our amazing fleet!</p>
        <a href="vehiclepage.php">Browse Vehicles</a>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="rentals-grid">
      <?php foreach($rentals as $r):
        $status = strtolower((string)($r['status'] ?? ''));
        $photo  = $r['photo'] ? (VEH_IMG_URL.'/'.$r['photo']) : (VEH_IMG_URL.'/01.jpg');
        $days = max(1, round((strtotime($r['end_date']) - strtotime($r['start_date'])) / 86400, 1));
        if (empty($r['make_model'])) $r['make_model'] = 'Vehicle';
        if (empty($r['plate_no'])) $r['plate_no'] = '—';
        if (empty($r['vehicle_type'])) $r['vehicle_type'] = '—';

        $baseRentalCost = $days * (float)$r['daily_rate'];
        $originalCost = $baseRentalCost;
        if ($status === 'completed') {
          $totalCost = $baseRentalCost;
        } else {
          $totalCost = isset($r['total_cost']) && $r['total_cost'] > 0 ? (float)$r['total_cost'] : $baseRentalCost;
        }
        $hasPromo = isset($r['promo_applied']) && !empty($r['promo_applied']);
        $promoDiscount = isset($r['promo_discount']) ? (float)$r['promo_discount'] : 0;

        $startTs = strtotime((string)$r['start_date']);
        $endTs = strtotime((string)$r['end_date']);
        $nowTs = time();
        $endsInDays = ($endTs && $endTs > $nowTs) ? (int)ceil(($endTs - $nowTs) / 86400) : null;

        $statusLabel = ucfirst($status);
        if ($status === 'ongoing') $statusLabel = 'Ongoing';
        if ($status === 'reserved') $statusLabel = 'Reserved';
        if ($status === 'waitlist') $statusLabel = 'Waitlist';
        if ($status === 'pending') $statusLabel = 'Pending';
        if ($status === 'completed') $statusLabel = 'Completed';
        if ($status === 'cancelled') $statusLabel = 'Cancelled';

        $statusClass = in_array($status, ['waitlist','pending','reserved','ongoing','completed','cancelled'], true) ? $status : 'completed';
        $isOngoing = ($status === 'ongoing');
        $cancelReason = trim((string)($r['cancel_reason'] ?? ''));
        $canCancel = in_array($status, ['waitlist', 'pending', 'reserved'], true);

        $downpaymentDue = isset($r['downpayment']) && (float)$r['downpayment'] > 0
          ? (float)$r['downpayment']
          : round($totalCost * 0.5, 2);
        $payInfo = $paymentByRental[(int)$r['id']] ?? null;
        $paidOrPending = (float)($payInfo['paid_or_pending'] ?? 0);
        $hasPendingProof = !empty($payInfo['has_pending_proof']);
        $remainingDownpayment = max(0, round($downpaymentDue - $paidOrPending, 2));
        $canPayNow = in_array($status, ['waitlist', 'pending', 'reserved'], true) && $remainingDownpayment > 0 && !$hasPendingProof;
        $pendingExt = $pendingExtensionsByRental[(int)$r['id']] ?? null;
        $hasPendingExtension = !empty($pendingExt);
      ?>
      <div class="rental-card <?= $isOngoing ? 'is-ongoing' : '' ?>" data-rental-id="<?= (int)$r['id'] ?>" data-status="<?= h($status) ?>" data-total="<?= number_format($totalCost,2,'.','') ?>" data-downpayment="<?= number_format($downpaymentDue,2,'.','') ?>" data-remaining="<?= number_format($remainingDownpayment,2,'.','') ?>" onclick="if(event.target.closest('button,a')) return; openRentalDetails(<?= (int)$r['id'] ?>);">
        <div class="rental-media">
          <img src="<?= h($photo) ?>" alt="Vehicle">
        </div>

        <div class="rental-body">
          <div class="rental-top">
            <div>
              <div class="rental-name"><?= h($r['make_model']) ?></div>
              <div class="rental-meta"><?= h($r['plate_no']) ?> • <?= h($r['vehicle_type']) ?></div>
            </div>
            <div class="status-badge <?= $hasPendingExtension ? 'extension-pending' : h($statusClass) ?>">
              <?= $hasPendingExtension ? 'Extension Pending' : h($statusLabel) ?>
            </div>
          </div>

          <div class="rental-dates">
            <div class="date-chip">
              <svg class="date-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
              Start: <?= date('M d, Y', $startTs) ?>
            </div>
            <div class="date-chip">
              <svg class="date-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10H3"></path><path d="M16 2v6"></path><path d="M8 2v6"></path><path d="M3 10v10a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V10"></path></svg>
              End: <?= date('M d, Y', $endTs) ?>
            </div>
          </div>
          <?php if($hasPendingExtension): ?>
            <div class="extension-note">
              Requested extension until <?= date('M d, Y', strtotime((string)$pendingExt['requested_end_date'])) ?> — waiting for admin approval
            </div>
          <?php endif; ?>
          <?php if($status === 'waitlist'): ?>
            <div class="extension-note">On hold until you pay. Submit your receipt to move this booking to pending.</div>
          <?php endif; ?>
          <?php if($status === 'cancelled' && $cancelReason !== ''): ?>
            <div class="extension-note is-cancel">
              Cancelled<?= !empty($r['cancelled_at']) ? ' on '.date('M d, Y', strtotime((string)$r['cancelled_at'])) : '' ?>:
              <?= h($cancelReason) ?>
            </div>
          <?php endif; ?>
        </div>

        <div class="rental-side">
          <div class="price-block">
            <div class="price">₱<?= number_format($totalCost,2) ?></div>
            <div class="price-sub">₱<?= number_format((float)$r['daily_rate'],2) ?>/day • <?= (int)$days ?> day<?= (int)$days===1?'':'s' ?></div>
            <?php if($hasPromo): ?>
              <div class="promo-row">
                <span class="promo-badge"><?= h($r['promo_applied']) ?></span>
                <span class="price-was">₱<?= number_format($originalCost,2) ?></span>
              </div>
            <?php endif; ?>
            <?php if(in_array($status, ['waitlist','pending','reserved'], true)): ?>
              <div class="pay-due-row">
                <div class="price-sub">Downpayment due: <b>₱<?= number_format($remainingDownpayment > 0 ? $remainingDownpayment : 0, 2) ?></b></div>
                <?php if($hasPendingProof): ?>
                  <span class="promo-badge">Receipt Under Review</span>
                <?php elseif($remainingDownpayment <= 0): ?>
                  <span class="promo-badge">Downpayment Submitted</span>
                <?php endif; ?>
              </div>
            <?php endif; ?>
            <?php if($status === 'ongoing' && $endsInDays !== null): ?>
              <div class="ends">Ends in <?= (int)$endsInDays ?> day<?= (int)$endsInDays===1?'':'s' ?></div>
            <?php elseif($status === 'completed'): ?>
              <div class="ends">Completed on <?= date('M d, Y', $endTs) ?></div>
            <?php endif; ?>
          </div>

          <div class="card-actions">
            <button class="btn primary" type="button" onclick="openRentalDetails(<?= (int)$r['id'] ?>)">View Details</button>
            <?php if($canCancel): ?>
              <button class="btn danger" type="button"
                data-rental-id="<?= (int)$r['id'] ?>"
                data-vehicle-name="<?= h($r['make_model']) ?>"
                onclick="event.preventDefault(); event.stopPropagation(); openCancelModal(this); return false;">
                Cancel
              </button>
            <?php endif; ?>
            <?php if($canPayNow): ?>
              <button class="btn primary btn-pay-now btn-span" type="button"
                data-rental-id="<?= (int)$r['id'] ?>"
                data-vehicle-name="<?= h($r['make_model']) ?>"
                data-amount="<?= number_format($remainingDownpayment, 2, '.', '') ?>"
                onclick="event.preventDefault(); event.stopPropagation(); openPayModal(this); return false;">
                Pay Now
              </button>
            <?php elseif(in_array($status, ['waitlist','pending','reserved'], true) && $hasPendingProof): ?>
              <button class="btn btn-span" type="button" disabled>Receipt Submitted</button>
            <?php elseif(in_array($status, ['waitlist','pending','reserved'], true) && $remainingDownpayment <= 0): ?>
              <button class="btn btn-span" type="button" disabled>Paid</button>
            <?php endif; ?>
            <?php if($status === 'ongoing' && $hasPendingExtension): ?>
              <button class="btn btn-span" type="button" disabled>Extension Pending</button>
            <?php elseif($status === 'ongoing'): ?>
              <button class="btn" type="button" onclick="openExtendModal(<?= (int)$r['id'] ?>, '<?= h($r['end_date']) ?>')">Extend</button>
            <?php endif; ?>
            <?php if($status === 'completed'): ?>
              <button class="btn" type="button" onclick="viewReceipt(<?= (int)$r['id'] ?>)">Receipt</button>
            <?php endif; ?>
          </div>
        </div>
      </div>
      
      <!-- Return Summary Breakdown for Completed Rentals -->
      <?php if($status === 'completed'): ?>
      <?php
        // Get return inspection details and rental return data
        $stmt = $conn->prepare("
            SELECT 
                ri.*,
                rr.actual_return_date,
                rr.actual_return_time,
                rr.return_condition as rental_return_condition
            FROM return_inspections ri
            LEFT JOIN rental_returns rr ON rr.rental_id = ri.rental_id
            WHERE ri.rental_id = ? 
            ORDER BY ri.created_at DESC 
            LIMIT 1
        ");
        $stmt->bind_param("i", $r['id']);
        $stmt->execute();
        $returnInspection = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        // Calculate fees
        $lateFee = 0;
        $fuelCharge = 0;
        $washingFee = 0;
        $damageFee = 0;
        $conditionAdjustment = 0;
        
        if ($returnInspection) {
            // Calculate late fee using actual return date/time from rental_returns table
            $actualReturnDate = $returnInspection['actual_return_date'] ?? $r['end_date'];
            $actualReturnTime = $returnInspection['actual_return_time'] ?? '23:59:59';
            $actualReturnDateTime = $actualReturnDate . ' ' . $actualReturnTime;
            $expectedReturnDateTime = $r['end_date'] . ' ' . ($r['end_time'] ?? '18:00:00'); // Use actual stored end_time
            
            $actualTime = strtotime($actualReturnDateTime);
            $expectedTime = strtotime($expectedReturnDateTime);
            $hoursLate = max(0, ($actualTime - $expectedTime) / 3600);
            $lateFee = calculateLateFee($r['daily_rate'], $hoursLate);
            
            // Calculate fuel charge using fuel_level and vehicle type
            if (isset($returnInspection['fuel_level'])) {
                $fuelLevel = $returnInspection['fuel_level'];
                $vehicleType = $r['vehicle_type'];
                
                // Get fuel rates for this vehicle type
                $fuelStmt = $conn->prepare("SELECT * FROM fuel_charge_rates WHERE vehicle_type = ? LIMIT 1");
                $fuelStmt->bind_param("s", $vehicleType);
                $fuelStmt->execute();
                $fuelRates = $fuelStmt->get_result()->fetch_assoc();
                $fuelStmt->close();
                
                if ($fuelRates) {
                    if ($fuelLevel === 'empty') $fuelCharge = (float)($fuelRates['empty_rate'] ?? 0);
                    elseif ($fuelLevel === '1/4') $fuelCharge = (float)($fuelRates['quarter_rate'] ?? 0);
                    elseif ($fuelLevel === 'half') $fuelCharge = (float)($fuelRates['half_rate'] ?? 0);
                    elseif ($fuelLevel === '3/4') $fuelCharge = (float)($fuelRates['three_quarter_rate'] ?? 0);
                }
            }
            
            // Calculate washing fee using cleanliness and vehicle type
            if (isset($returnInspection['cleanliness'])) {
                $cleanliness = $returnInspection['cleanliness'];
                $vehicleType = $r['vehicle_type'];
                
                // Get washing rates for this vehicle type
                $washingStmt = $conn->prepare("SELECT * FROM washing_types WHERE vehicle_type = ? LIMIT 1");
                $washingStmt->bind_param("s", $vehicleType);
                $washingStmt->execute();
                $washingRates = $washingStmt->get_result()->fetch_assoc();
                $washingStmt->close();
                
                if ($washingRates) {
                    if ($cleanliness === 'dirty') $washingFee = (float)($washingRates['washing_rate'] ?? 0);
                    elseif ($cleanliness === 'very_dirty') $washingFee = (float)($washingRates['washing_rate'] ?? 0);
                    // Clean returns don't need washing fee
                }
            }
            
            // Calculate condition adjustment based on return condition
            $returnCondition = $returnInspection['rental_return_condition'] ?? $returnInspection['return_condition'] ?? 'Good';
            switch($returnCondition) {
                case 'Poor':
                    $conditionAdjustment = $r['daily_rate'] * 0.5; // 50% of daily rate
                    break;
                case 'Fair':
                    $conditionAdjustment = $r['daily_rate'] * 0.2; // 20% of daily rate
                    break;
                case 'Good':
                case 'Excellent':
                default:
                    $conditionAdjustment = 0; // No adjustment for good condition
                    break;
            }
            
            // Get additional fees from return inspection
            $damageFee = (float)($returnInspection['damage_fee'] ?? 0);
        }
        
        $totalFees = $lateFee + $fuelCharge + $washingFee + $damageFee + $conditionAdjustment;
        $finalTotal = $totalCost + $totalFees;
        $hasAdditionalCharges = $totalFees > 0;
        
        // Debug logging
        error_log("Rental {$r['id']} - Base Cost: $totalCost, Late Fee: $lateFee, Fuel: $fuelCharge, Washing: $washingFee, Damage: $damageFee, Condition: $conditionAdjustment, Total Fees: $totalFees, Final Total: $finalTotal");
        if ($returnInspection) {
            error_log("Rental {$r['id']} - Actual Return: {$actualReturnDateTime}, Expected: {$expectedReturnDateTime}, Hours Late: $hoursLate, Daily Rate: {$r['daily_rate']}");
        }
        
      ?>
      <div class="summary-dropdown" data-rental-id="<?= $r['id'] ?>">
        <button class="summary-toggle" onclick="toggleSummary(<?= $r['id'] ?>)">
          <span class="summary-title">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M9 11l3 3 8-8"/>
              <path d="M21 12c0 4.97-4.03 9-9 9s-9-4.03-9-9 4.03-9 9-9 9 4.03 9 9z"/>
            </svg>
            Return Summary
          </span>
          <span class="summary-total">
            ₱<?= number_format($finalTotal, 2) ?>
            <?php if ($hasAdditionalCharges): ?>
              <span class="additional-badge">+₱<?= number_format($totalFees, 2) ?></span>
            <?php endif; ?>
          </span>
          <svg class="summary-arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="6,9 12,15 18,9"/>
          </svg>
        </button>
        <div class="summary-content" id="summary-<?= $r['id'] ?>">
          <div class="summary-section">
            <h5>Rental Details</h5>
            <div class="summary-line">
              <span>Base Rental Cost:</span>
              <span>₱<?= number_format($totalCost, 2) ?></span>
            </div>
            <div class="summary-line">
              <span>Rental Period:</span>
              <span><?= date('M d, Y', strtotime($r['start_date'])) ?> - <?= date('M d, Y', strtotime($r['end_date'])) ?></span>
            </div>
            <div class="summary-line">
              <span>Daily Rate:</span>
              <span>₱<?= number_format($r['daily_rate'], 2) ?></span>
            </div>
            <?php if ($hasPromo): ?>
            <div class="summary-line promo">
              <span>Promo Applied:</span>
              <span><?= h($r['promo_applied']) ?> (-₱<?= number_format($promoDiscount, 2) ?>)</span>
            </div>
            <?php endif; ?>
          </div>
          
          <?php if ($hasAdditionalCharges || $lateFee > 0): ?>
          <div class="summary-section">
            <h5>Additional Charges</h5>
            <?php if ($lateFee > 0): ?>
            <div class="summary-line charge">
              <span>Late Return Fee:</span>
              <span>₱<?= number_format($lateFee, 2) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($fuelCharge > 0): ?>
            <div class="summary-line charge">
              <span>Fuel Charge:</span>
              <span>₱<?= number_format($fuelCharge, 2) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($washingFee > 0): ?>
            <div class="summary-line charge">
              <span>Washing Fee:</span>
              <span>₱<?= number_format($washingFee, 2) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($damageFee > 0): ?>
            <div class="summary-line charge">
              <span>Damage Fee:</span>
              <span>₱<?= number_format($damageFee, 2) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($conditionAdjustment > 0): ?>
            <div class="summary-line charge">
              <span>Condition Adjustment:</span>
              <span>₱<?= number_format($conditionAdjustment, 2) ?></span>
            </div>
            <?php endif; ?>
          </div>
          <?php endif; ?>
          
          <div class="summary-section total-section">
            <div class="summary-line total">
              <span>Final Total:</span>
              <span>₱<?= number_format($finalTotal, 2) ?></span>
            </div>
            <?php if ($returnInspection): ?>
            <div class="summary-line">
              <span>Return Date:</span>
              <span><?= date('M d, Y H:i', strtotime($returnInspection['created_at'])) ?></span>
            </div>
            <?php endif; ?>
            <div class="summary-line" style="margin-top: 12px; padding-top: 12px; border-top: 1px solid rgba(255,255,255,.1);">
              <button class="btn btn-receipt" onclick="viewReceipt(<?= $r['id'] ?>)" data-rental-id="<?= $r['id'] ?>" title="View detailed receipt with all charges">
                🧾 View Detailed Receipt
              </button>
            </div>
          </div>
        </div>
      </div>
      <?php endif; ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<!-- ===== DETAILS MODAL (UI-ONLY) ===== -->
<div class="modal-bg" id="detailsModal" style="display:none;">
  <div class="modal" style="text-align:left;max-width:720px">
    <button class="btn-inline" type="button" onclick="closeDetailsModal()">Close</button>
    <h2 id="detailsTitle">Rental Details</h2>
    <p id="detailsSub" style="margin-top:6px"></p>
    <div id="detailsBody" style="margin-top:18px"></div>
  </div>
</div>

<!-- ===== EXTEND MODAL ===== -->
<div class="modal-bg" id="extendModal">
  <div class="modal">
    <h2>Request Extension</h2>
    <p>Current end date: <b id="oldEndUser"></b></p>
    <form method="post" id="extendForm">
      <input type="hidden" name="extend_rental_id" id="extendRentalId">
      <label for="new_end">Select new return date:</label>
      <input type="date" name="new_end" id="new_end" required>
      <div class="actions">
        <button class="btn btn-extend" type="submit">Submit Request</button>
        <button class="btn btn-cancel" type="button" onclick="closeExtendModal()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- ===== PAY NOW MODAL ===== -->
<div class="modal-bg" id="payModal">
  <div class="modal pay-modal">
    <h2>Pay Downpayment</h2>
    <p style="margin-top:-6px">Submit payment for <b id="payVehicleName"></b></p>
    <div class="pay-summary">
      <div>Amount due: <span id="payAmountDue">₱0.00</span></div>
      <div style="color:var(--text-secondary);font-size:.9rem">Upload a clear photo of your receipt</div>
    </div>
    <form id="payForm" enctype="multipart/form-data">
      <input type="hidden" name="rental_id" id="payRentalId">
      <div class="form-row">
        <label for="payAmount">Payment Amount</label>
        <input type="number" name="amount" id="payAmount" min="1" step="0.01" required>
      </div>
      <div class="form-row">
        <label for="payMethod">Payment Method</label>
        <?php $customerPaymentMethods = pm_list($conn, true); ?>
        <?php if ($customerPaymentMethods): ?>
          <select name="payment_method_id" id="payMethod" required>
            <option value="">Select method</option>
            <?php foreach (['ewallet' => 'E-wallets', 'bank' => 'Banks'] as $pmType => $pmGroup): ?>
              <?php $pmGroupItems = array_filter($customerPaymentMethods, fn($m) => $m['provider_type'] === $pmType); ?>
              <?php if ($pmGroupItems): ?>
                <optgroup label="<?= htmlspecialchars($pmGroup) ?>">
                  <?php foreach ($pmGroupItems as $pm): ?>
                    <option value="<?= (int)$pm['id'] ?>"><?= htmlspecialchars($pm['provider_name']) ?></option>
                  <?php endforeach; ?>
                </optgroup>
              <?php endif; ?>
            <?php endforeach; ?>
          </select>
        <?php else: ?>
          <select name="payment_method_id" id="payMethod" required disabled>
            <option value="">No payment methods available yet</option>
          </select>
          <div class="pay-method-hint" style="margin-top:8px">FleetGo hasn't set up any bank or e-wallet accounts yet. Please check back later or message support.</div>
        <?php endif; ?>
        <div class="pay-method-info" id="payMethodInfo" hidden>
          <div class="pay-method-qr-wrap" id="payMethodQrWrap">
            <a id="payMethodQrLink" target="_blank" rel="noopener"><img class="pay-method-qr" id="payMethodQr" alt="Payment QR code"></a>
            <a class="pay-qr-download" id="payMethodQrDownload" download>&#8595; Download QR</a>
          </div>
          <div class="pay-method-details">
            <div class="pay-method-provider" id="payMethodProvider"></div>
            <div>
              <div class="pay-method-label">Account Number</div>
              <div class="pay-method-number">
                <span id="payMethodNumber"></span>
                <button type="button" class="pay-copy-btn" id="payMethodCopy">Copy</button>
              </div>
            </div>
            <div class="pay-method-name" id="payMethodName"></div>
            <div class="pay-method-hint" id="payMethodHint">Scan the QR or send to the account number, then upload your receipt below.</div>
          </div>
        </div>
      </div>
      <div class="form-row">
        <label for="payReference">Reference No. (optional)</label>
        <input type="text" name="reference_no" id="payReference" placeholder="e.g. GCash ref / OR number">
      </div>
      <div class="form-row">
        <label>Receipt Photo</label>
        <label class="pay-upload" for="payReceiptPhoto">
          <input type="file" name="receipt_photo" id="payReceiptPhoto" accept="image/*" required>
          <div style="font-size:1.4rem">⬆</div>
          <div class="pay-upload-title">Attach Receipt Photo</div>
          <div class="pay-upload-sub">JPG, PNG, WEBP, or GIF • Max 5MB</div>
          <div class="pay-upload-preview" id="payReceiptPreview" hidden>
            <img id="payReceiptImg" alt="Receipt preview" style="display:none">
            <div class="pay-upload-sub" id="payReceiptName"></div>
          </div>
        </label>
      </div>
      <div class="form-row">
        <label for="payNotes">Notes (optional)</label>
        <textarea name="notes" id="payNotes" rows="2" placeholder="Any note for the admin"></textarea>
      </div>
      <div class="actions">
        <button class="btn primary" type="submit" id="paySubmitBtn">Submit Payment</button>
        <button class="btn" type="button" onclick="closePayModal()">Cancel</button>
      </div>
      <div class="pay-msg" id="payMsg"></div>
    </form>
  </div>
</div>

<!-- ===== CANCEL BOOKING MODAL ===== -->
<div class="modal-bg" id="cancelModal">
  <div class="modal pay-modal">
    <h2>Cancel Booking</h2>
    <p style="margin-top:-6px">Cancel rental for <b id="cancelVehicleName">this vehicle</b></p>
    <form id="cancelForm">
      <input type="hidden" name="rental_id" id="cancelRentalId" value="">
      <div class="form-row">
        <label for="cancelReason">Reason for cancellation <span style="color:var(--error)">*</span></label>
        <textarea name="cancel_reason" id="cancelReason" rows="4" maxlength="500" required
          placeholder="Tell us why you need to cancel (min. 5 characters)"></textarea>
        <div style="margin-top:6px;font-size:.8rem;color:var(--text-secondary);font-weight:700">
          <span id="cancelReasonCount">0</span>/500
        </div>
      </div>
      <div class="actions">
        <button class="btn danger" type="submit" id="cancelSubmitBtn">Confirm Cancel</button>
        <button class="btn" type="button" onclick="closeCancelModal()">Keep Booking</button>
      </div>
      <div class="pay-msg" id="cancelMsg"></div>
    </form>
  </div>
</div>

<!-- ===== RECEIPT MODAL ===== -->
<div class="modal-bg" id="receiptModal">
  <div class="modal receipt-modal">
    <div class="receipt-header">
      <h2>Return Receipt</h2>
      <div class="receipt-info">
        <div id="receiptVehicleInfo"></div>
        <div id="receiptCustomerInfo"></div>
        <div id="receiptDateInfo"></div>
      </div>
    </div>
    
    <div class="receipt-body">
      <div class="receipt-section">
        <h3>Vehicle Condition</h3>
        <div class="condition-grid">
          <div class="condition-item">
            <span class="label">Fuel Level:</span>
            <span class="value" id="receiptFuelLevel"></span>
          </div>
          <div class="condition-item">
            <span class="label">Vehicle Condition:</span>
            <span class="value" id="receiptCondition"></span>
          </div>
          <div class="condition-item">
            <span class="label">Cleanliness:</span>
            <span class="value" id="receiptCleanliness"></span>
          </div>
          <div class="condition-item">
            <span class="label">Odometer:</span>
            <span class="value" id="receiptOdometer"></span>
          </div>
          <div class="condition-item" id="washingItem" style="display:none;">
            <span class="label">Washing Type:</span>
            <span class="value" id="receiptWashingType"></span>
          </div>
        </div>
      </div>

      <div class="receipt-section" id="damageSection" style="display:none;">
        <h3>Issues & Damage</h3>
        <div class="damage-report" id="receiptDamageReport"></div>
      </div>

      <div class="receipt-section">
        <h3>Rental Summary</h3>
        <div class="rental-summary">
          <div class="summary-item">
            <span class="label">Daily Rate:</span>
            <span class="value" id="receiptDailyRate"></span>
          </div>
          <div class="summary-item">
            <span class="label">Total Rental Cost:</span>
            <span class="value" id="receiptTotalCost"></span>
          </div>
          <div class="summary-item">
            <span class="label">Downpayment (50%):</span>
            <span class="value" id="receiptDownpayment"></span>
          </div>
        </div>
      </div>

      <div class="receipt-section">
        <h3>Rental Cost Breakdown</h3>
        <div class="cost-breakdown">
          <div class="cost-item">
            <span class="label">Daily Rate:</span>
            <span class="value" id="receiptDailyRate"></span>
          </div>
          <div class="cost-item">
            <span class="label">Rental Days:</span>
            <span class="value" id="receiptRentalDays"></span>
          </div>
          <div class="cost-item">
            <span class="label">Base Rental Cost:</span>
            <span class="value" id="receiptBaseRentalCost"></span>
          </div>
          <div class="cost-item">
            <span class="label">Downpayment (50%):</span>
            <span class="value" id="receiptDownpayment"></span>
          </div>
          <div class="cost-item">
            <span class="label">Original Balance Due:</span>
            <span class="value" id="receiptOriginalBalance"></span>
          </div>
        </div>
      </div>

      <div class="receipt-section">
        <h3>Additional Charges (Always Shown for Transparency)</h3>
        <div class="cost-breakdown">
          <div class="cost-item">
            <span class="label">⏰ Late Return Fee:</span>
            <span class="value penalty" id="receiptPenalty"></span>
          </div>
          <div class="cost-item">
            <span class="label">Late Fee Calculation:</span>
            <span class="value breakdown" id="receiptLateFeeBreakdown"></span>
          </div>
          <div class="cost-item">
            <span class="label">⛽ Fuel Charge:</span>
            <span class="value penalty" id="receiptFuelPenalty"></span>
          </div>
          <div class="cost-item">
            <span class="label">Fuel Charge Details:</span>
            <span class="value breakdown" id="receiptFuelCalculation"></span>
          </div>
          <div class="cost-item">
            <span class="label">🧽 Washing Cost:</span>
            <span class="value" id="receiptWashingCost"></span>
          </div>
          <div class="cost-item">
            <span class="label">Washing Details:</span>
            <span class="value breakdown" id="receiptWashingCalculation"></span>
          </div>
          <div class="cost-item">
            <span class="label">Condition Adjustment:</span>
            <span class="value penalty" id="receiptConditionAdjustment"></span>
          </div>
          <div class="cost-item">
            <span class="label">Condition Details:</span>
            <span class="value breakdown" id="receiptConditionCalculation"></span>
          </div>
          <div class="cost-item">
            <span class="label">Damage Fee:</span>
            <span class="value" id="receiptDamageFee"></span>
          </div>
          <div class="cost-item">
            <span class="label">Damage Details:</span>
            <span class="value breakdown" id="receiptDamageCalculation"></span>
          </div>
          <div class="cost-divider"></div>
          <div class="cost-item total">
            <span class="label">Total Additional Charges:</span>
            <span class="value" id="receiptTotalAdditionalCharges"></span>
          </div>
          <div class="cost-item total">
            <span class="label">Final Amount Due:</span>
            <span class="value" id="receiptFinalCost"></span>
          </div>
          <div class="payment-status">
            <span class="label">Payment Status:</span>
            <span class="value status" id="receiptPaymentStatus"></span>
          </div>
        </div>
      </div>
    </div>

    <div class="receipt-footer">
      <div class="receipt-actions">
        <button class="btn btn-primary" onclick="printReceipt()">Print Receipt</button>
        <button class="btn btn-secondary" onclick="closeReceiptModal()">Close</button>
      </div>
      <div class="receipt-note">
        <p>Thank you for using FleetGo! Please keep this receipt for your records.</p>
      </div>
    </div>
  </div>
</div>

<footer class="footer">
  © <?= date('Y') ?> FleetGo Rentals • <a href="#">Terms</a> • <a href="#">Privacy</a> • <a href="#">Contact</a>
</footer>

<script>
// ===== ENHANCED MY RENTALS PAGE =====
document.addEventListener('DOMContentLoaded', function() {
  const extendModal = document.getElementById('extendModal');
  const oldEndUser = document.getElementById('oldEndUser');
  const extendIdInp = document.getElementById('extendRentalId');
  const newEndInp = document.getElementById('new_end');
  const rows = Array.from(document.querySelectorAll('.rental-card'));
  
  // ===== STATISTICS CALCULATION =====
  function updateStats() {
    const totalRentals = rows.length;
    const activeRentals = rows.filter(r => (r.dataset.status||'') === 'ongoing').length;
    const completedRentals = rows.filter(r => (r.dataset.status||'') === 'completed').length;
    const totalSpent = rows.reduce((sum, r) => sum + (parseFloat(r.dataset.total||'0')||0), 0);
    animateCounter('totalRentals', totalRentals, 0);
    animateCounter('activeRentals', activeRentals, 0);
    animateCounter('completedRentals', completedRentals, 0);
    animateCounter('totalSpent', Math.round(totalSpent), 0, '₱');
  }
  
  function animateCounter(elementId, target, start, prefix = '') {
    const element = document.getElementById(elementId);
    if (!element) return;
    
    const duration = 2000;
    const startTime = performance.now();
    
    function updateCounter(currentTime) {
      const elapsed = currentTime - startTime;
      const progress = Math.min(elapsed / duration, 1);
      const current = Math.round(start + (target - start) * progress);
      element.textContent = prefix + current;
      
      if (progress < 1) {
        requestAnimationFrame(updateCounter);
      }
    }
    requestAnimationFrame(updateCounter);
  }
  
  // ===== ENHANCED CARD INTERACTIONS =====
  rows.forEach((row, index) => {
    // Staggered animation on load
    row.style.opacity = '0';
    row.style.transform = 'translateY(30px)';
    setTimeout(() => {
      row.style.transition = 'all 0.6s cubic-bezier(0.4,0,0.2,1)';
      row.style.opacity = '1';
      row.style.transform = 'translateY(0)';
    }, index * 100);
    
    // Enhanced hover effects
    row.addEventListener('mouseenter', function() {
      this.style.transform = 'translateY(-2px)';
      this.style.boxShadow = '0 10px 28px rgba(0,0,0,.08)';
    });
    
    row.addEventListener('mouseleave', function() {
      this.style.transform = 'translateY(0)';
      this.style.boxShadow = '0 8px 25px rgba(0,0,0,.08)';
    });
  });
  
  // ===== MODAL CONTROLS =====
  function openExtendModal(rid, oldEnd) {
    extendModal.classList.add('show');
    document.body.style.overflow = 'hidden';
    oldEndUser.textContent = oldEnd;
    extendIdInp.value = rid;
    newEndInp.min = oldEnd;
    newEndInp.value = oldEnd;
    
    // Focus on date input
    setTimeout(() => {
      newEndInp.focus();
    }, 300);
  }
  
  function closeExtendModal() {
    extendModal.classList.remove('show');
    document.body.style.overflow = 'auto';
  }
  
  // Event listeners
  extendModal.addEventListener('click', e => {
    if (e.target === extendModal) closeExtendModal();
  });
  
  // Receipt modal event listeners
  const receiptModalEl = document.getElementById('receiptModal');
  if (receiptModalEl) {
    receiptModalEl.addEventListener('click', e => {
      if (e.target === receiptModalEl) closeReceiptModal();
    });
  }
  
  // Keyboard support
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && extendModal.classList.contains('show')) {
      closeExtendModal();
    }
  });
  
  // ===== TOAST NOTIFICATIONS =====
  const toasts = document.querySelectorAll('.toast');
  toasts.forEach(toast => {
    setTimeout(() => {
      toast.style.animation = 'slideOutRight 0.3s ease forwards';
      setTimeout(() => toast.remove(), 300);
    }, 5000);
  });
  
  // ===== SCROLL ANIMATIONS =====
  const observerOptions = {
    threshold: 0.1,
    rootMargin: '0px 0px -50px 0px'
  };
  
  const observer = new IntersectionObserver(function(entries) {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        entry.target.style.animation = 'fadeInUp 0.6s ease-out forwards';
      }
    });
  }, observerOptions);
  
  // Observe section elements
  document.querySelectorAll('.filter-section, .rentals-grid').forEach(section => {
    observer.observe(section);
  });
  
  // ===== PARALLAX EFFECT =====
  window.addEventListener('scroll', function() {
    const scrolled = window.pageYOffset;
    const hero = document.querySelector('.hero');
    if (hero) {
      hero.style.transform = `translateY(${scrolled * 0.3}px)`;
    }
  });
  
  // ===== INITIALIZE =====
  updateStats();
  
  // ===== SMOOTH SCROLLING =====
  document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function (e) {
      e.preventDefault();
      const target = document.querySelector(this.getAttribute('href'));
      if (target) {
        target.scrollIntoView({
          behavior: 'smooth',
          block: 'start'
        });
      }
    });
  });
});

// ===== GLOBAL FUNCTIONS =====
function openExtendModal(rid, oldEnd) {
  const extendModal = document.getElementById('extendModal');
  const oldEndUser = document.getElementById('oldEndUser');
  const extendIdInp = document.getElementById('extendRentalId');
  const newEndInp = document.getElementById('new_end');
  
  extendModal.classList.add('show');
  document.body.style.overflow = 'hidden';
  oldEndUser.textContent = oldEnd;
  extendIdInp.value = rid;
  newEndInp.min = oldEnd;
  newEndInp.value = oldEnd;
  
  setTimeout(() => newEndInp.focus(), 300);
}

function closeExtendModal() {
  const extendModal = document.getElementById('extendModal');
  extendModal.classList.remove('show');
  document.body.style.overflow = 'auto';
}

// ===== SUMMARY DROPDOWN TOGGLE =====
function toggleSummary(rentalId) {
  const content = document.getElementById(`summary-${rentalId}`);
  const toggle = content.previousElementSibling;
  
  if (content.classList.contains('expanded')) {
    // Collapse
    content.classList.remove('expanded');
    toggle.classList.remove('active');
    content.style.maxHeight = '0';
  } else {
    // Expand
    content.classList.add('expanded');
    toggle.classList.add('active');
    content.style.maxHeight = content.scrollHeight + 'px';
  }
}

// ===== TOAST NOTIFICATIONS =====
function showToast(message, isSuccess = true) {
  // Remove existing toast if any
  const existingToast = document.querySelector('.toast');
  if (existingToast) {
    existingToast.remove();
  }
  
  const toast = document.createElement('div');
  toast.className = `toast ${isSuccess ? 'toast-success' : 'toast-error'}`;
  toast.textContent = message;
  toast.style.cssText = `
    position: fixed;
    top: 20px;
    right: 20px;
    padding: 12px 20px;
    border-radius: 8px;
    color: white;
    font-weight: 500;
    z-index: 10000;
    animation: slideIn 0.3s ease-out;
    ${isSuccess ? 'background: linear-gradient(135deg, #10b981, #059669);' : 'background: linear-gradient(135deg, #ef4444, #dc2626);'}
  `;
  
  // Add animation styles if not already added
  if (!document.getElementById('toast-styles')) {
    const styles = document.createElement('style');
    styles.id = 'toast-styles';
    styles.textContent = `
      @keyframes slideIn {
        from { transform: translateX(100%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
      }
      @keyframes slideOut {
        from { transform: translateX(0); opacity: 1; }
        to { transform: translateX(100%); opacity: 0; }
      }
    `;
    document.head.appendChild(styles);
  }
  
  document.body.appendChild(toast);
  
  // Auto remove after 3 seconds
  setTimeout(() => {
    toast.style.animation = 'slideOut 0.3s ease-in';
    setTimeout(() => toast.remove(), 300);
  }, 3000);
}

// ===== RECEIPT MODAL FUNCTIONS =====
const receiptModal = document.getElementById('receiptModal');

// Make viewReceipt globally accessible for USER
window.viewReceipt = function(rentalId) {
  console.log('=== USER VIEW RECEIPT CALLED ===');
  console.log('User viewReceipt called with rentalId:', rentalId);
  
  // Check if receipt modal exists
  const receiptModal = document.getElementById('receiptModal');
  if (!receiptModal) {
    console.error('Receipt modal not found!');
    showToast('Receipt modal not found. Please refresh the page.', false);
    return;
  }
  
  // Show loading toast
  showToast('Loading receipt...', true);
  
  // Fetch receipt data for the specified rental using user-specific endpoint
  fetch(`includes/ajax_receipt.php?rental_id=${rentalId}&t=${Date.now()}`, {
    method: 'GET',
    credentials: 'same-origin',
    headers: {
      'Accept': 'application/json',
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest'
    }
  })
    .then(response => {
      console.log('User receipt response status:', response.status);
      console.log('User receipt response headers:', response.headers);
      
      if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
      }
      
      // Check if response is JSON
      const contentType = response.headers.get('content-type');
      console.log('User receipt Content-Type:', contentType);
      
      if (!contentType || !contentType.includes('application/json')) {
        return response.text().then(text => {
          console.log('User receipt Non-JSON response:', text);
          throw new Error('Response is not JSON. Content-Type: ' + contentType);
        });
      }
      
      return response.json();
    })
    .then(data => {
      console.log('User receipt data received:', data);
      if(data.success) {
        console.log('Showing user receipt modal with data:', data.data);
        showReceiptModal(data.data);
      } else {
        console.error('User receipt fetch failed:', data.error);
        showToast('Error: ' + (data.error || 'Failed to load receipt'), false);
      }
    })
    .catch(error => {
      console.error('Error fetching user receipt:', error);
      showToast('Error loading receipt: ' + error.message, false);
      
      // Fallback: Show basic receipt with available data
      console.log('Showing fallback receipt with basic data');
      const basicReceiptData = {
        rental_id: rentalId,
        vehicle_model: 'Vehicle',
        plate_no: 'N/A',
        customer_name: 'Customer',
        start_date: 'N/A',
        end_date: 'N/A',
        actual_return_date: 'N/A',
        actual_return_time: 'N/A',
        odometer_return: 0,
        fuel_level: 'full',
        return_condition: 'Good',
        cleanliness: 'clean',
        damage_report: '',
        damage_fee: 0,
        washing_cost: 0,
        washing_type: null,
        penalty_amount: 0,
        fuel_penalty: 0,
        condition_adjustment: 0,
        total_additional_charges: 0,
        original_balance: 0,
        daily_rate: 0,
        total_cost: 0,
        downpayment: 0,
        final_cost: 0,
        payment_status: 'Unable to load receipt data'
      };
      showReceiptModal(basicReceiptData);
    });
}

function showReceiptModal(receiptData){
  console.log('=== SHOW RECEIPT MODAL ===');
  console.log('Receipt data:', receiptData);
  
  // Check if modal exists
  const modal = document.getElementById('receiptModal');
  if (!modal) {
    console.error('Receipt modal not found!');
    showToast('Receipt modal not found. Please refresh the page.', false);
    return;
  }
  
  // Populate receipt data
  document.getElementById('receiptVehicleInfo').innerHTML = `<strong>${receiptData.vehicle_model}</strong><br>Plate: ${receiptData.plate_no}`;
  document.getElementById('receiptCustomerInfo').innerHTML = `<strong>Customer:</strong><br>${receiptData.customer_name}`;
  const returnDateTime = formatDateTime(receiptData.actual_return_date, receiptData.actual_return_time);
  document.getElementById('receiptDateInfo').innerHTML = `<strong>Return Date:</strong><br>${returnDateTime}`;
  
  document.getElementById('receiptFuelLevel').textContent = formatFuelLevel(receiptData.fuel_level);
  document.getElementById('receiptCondition').textContent = receiptData.return_condition;
  document.getElementById('receiptCleanliness').textContent = receiptData.cleanliness.replace('_', ' ').toUpperCase();
  document.getElementById('receiptOdometer').textContent = receiptData.odometer_return.toLocaleString() + ' km';
  
  // Show washing information if available
  if(receiptData.washing_cost > 0){
    document.getElementById('washingItem').style.display = 'flex';
    document.getElementById('receiptWashingType').textContent = receiptData.washing_type || 'Washing Service';
  } else {
    document.getElementById('washingItem').style.display = 'none';
  }
  
  // Show damage section if there's damage
  if(receiptData.damage_report && receiptData.damage_report.trim() !== ''){
    document.getElementById('damageSection').style.display = 'block';
    document.getElementById('receiptDamageReport').textContent = receiptData.damage_report;
  } else {
    document.getElementById('damageSection').style.display = 'none';
  }
  
  // Show rental summary with detailed breakdown
  if(receiptData.daily_rate) {
    document.getElementById('receiptDailyRate').textContent = '₱' + parseFloat(receiptData.daily_rate).toLocaleString('en-US', {minimumFractionDigits: 2});
  }
  if(receiptData.rental_days) {
    document.getElementById('receiptRentalDays').textContent = receiptData.rental_days + ' days';
  }
  if(receiptData.base_rental_cost) {
    document.getElementById('receiptBaseRentalCost').textContent = '₱' + parseFloat(receiptData.base_rental_cost).toLocaleString('en-US', {minimumFractionDigits: 2});
  }
  if(receiptData.downpayment) {
    document.getElementById('receiptDownpayment').textContent = '₱' + parseFloat(receiptData.downpayment).toLocaleString('en-US', {minimumFractionDigits: 2});
  }
  
  // Cost breakdown
  document.getElementById('receiptOriginalBalance').textContent = '₱' + parseFloat(receiptData.original_balance).toLocaleString('en-US', {minimumFractionDigits: 2});
  
  // Always show all charges for transparency (even if 0)
  // Late Return Fee
  const penaltyAmount = parseFloat(receiptData.penalty_amount || 0);
  document.getElementById('receiptPenalty').textContent = '₱' + penaltyAmount.toLocaleString('en-US', {minimumFractionDigits: 2});
  document.getElementById('receiptLateFeeBreakdown').textContent = receiptData.late_fee_calculation || 'No late fee';
  if (penaltyAmount === 0) {
    document.getElementById('receiptPenalty').className = 'value zero';
  } else {
    document.getElementById('receiptPenalty').className = 'value penalty';
  }
  
  // Fuel Charge
  const fuelAmount = parseFloat(receiptData.fuel_penalty || 0);
  document.getElementById('receiptFuelPenalty').textContent = '₱' + fuelAmount.toLocaleString('en-US', {minimumFractionDigits: 2});
  document.getElementById('receiptFuelCalculation').textContent = receiptData.fuel_charge_calculation || 'No fuel charge (Full tank)';
  if (fuelAmount === 0) {
    document.getElementById('receiptFuelPenalty').className = 'value zero';
  } else {
    document.getElementById('receiptFuelPenalty').className = 'value penalty';
  }
  
  // Washing Cost
  const washingAmount = parseFloat(receiptData.washing_cost || 0);
  document.getElementById('receiptWashingCost').textContent = '₱' + washingAmount.toLocaleString('en-US', {minimumFractionDigits: 2});
  document.getElementById('receiptWashingCalculation').textContent = receiptData.washing_calculation || 'No washing charge';
  if (washingAmount === 0) {
    document.getElementById('receiptWashingCost').className = 'value zero';
  } else {
    document.getElementById('receiptWashingCost').className = 'value';
  }
  
  // Condition Adjustment
  const conditionAmount = parseFloat(receiptData.condition_adjustment || 0);
  document.getElementById('receiptConditionAdjustment').textContent = '₱' + conditionAmount.toLocaleString('en-US', {minimumFractionDigits: 2});
  document.getElementById('receiptConditionCalculation').textContent = receiptData.condition_calculation || 'No condition adjustment (Good condition)';
  if (conditionAmount === 0) {
    document.getElementById('receiptConditionAdjustment').className = 'value zero';
  } else {
    document.getElementById('receiptConditionAdjustment').className = 'value penalty';
  }
  
  // Damage Fee
  const damageAmount = parseFloat(receiptData.damage_fee || 0);
  document.getElementById('receiptDamageFee').textContent = '₱' + damageAmount.toLocaleString('en-US', {minimumFractionDigits: 2});
  document.getElementById('receiptDamageCalculation').textContent = receiptData.damage_calculation || 'No damage charges';
  if (damageAmount === 0) {
    document.getElementById('receiptDamageFee').className = 'value zero';
  } else {
    document.getElementById('receiptDamageFee').className = 'value';
  }
  
  // Total Additional Charges
  document.getElementById('receiptTotalAdditionalCharges').textContent = '₱' + parseFloat(receiptData.total_additional_charges || 0).toLocaleString('en-US', {minimumFractionDigits: 2});
  
  document.getElementById('receiptFinalCost').textContent = '₱' + parseFloat(receiptData.final_cost).toLocaleString('en-US', {minimumFractionDigits: 2});
  
  // Show payment status
  if(receiptData.payment_status) {
    document.getElementById('receiptPaymentStatus').textContent = receiptData.payment_status;
    const statusElement = document.getElementById('receiptPaymentStatus');
    statusElement.className = 'value status ' + receiptData.payment_status.toLowerCase().replace(/\s+/g, '-');
  }
  
  receiptModal.style.display = 'flex';
  console.log('Receipt modal should now be visible');
}

// Test function to verify modal works
window.testReceipt = function() {
  console.log('Testing receipt modal...');
  const testData = {
    rental_id: 1,
    vehicle_model: 'Test Vehicle',
    plate_no: 'TEST123',
    customer_name: 'Test Customer',
    start_date: '2024-01-01',
    end_date: '2024-01-03',
    actual_return_date: '2024-01-03',
    actual_return_time: '12:00:00',
    odometer_return: 1000,
    fuel_level: 'full',
    return_condition: 'Good',
    cleanliness: 'clean',
    damage_report: '',
    damage_fee: 0,
    washing_cost: 0,
    washing_type: null,
    penalty_amount: 0,
    fuel_penalty: 0,
    condition_adjustment: 0,
    total_additional_charges: 0,
    original_balance: 1000,
    daily_rate: 500,
    total_cost: 1000,
    downpayment: 500,
    final_cost: 1000,
    payment_status: 'Fully Paid - No Additional Charges',
    rental_days: 2,
    base_rental_cost: 1000,
    hours_late: 0,
    late_fee_calculation: 'No late fee',
    fuel_charge_calculation: 'No fuel charge (Full tank)',
    condition_calculation: 'No condition adjustment (Good condition)',
    washing_calculation: 'No washing charge',
    damage_calculation: 'No damage charges'
  };
  showReceiptModal(testData);
};

// Debug function to test receipt with charges
window.testReceiptWithCharges = function() {
  console.log('Testing USER receipt modal with charges...');
  const testData = {
    rental_id: 1,
    vehicle_model: 'Test Vehicle',
    plate_no: 'TEST123',
    customer_name: 'Test Customer',
    start_date: '2024-01-01',
    end_date: '2024-01-03',
    actual_return_date: '2024-01-04',
    actual_return_time: '14:00:00',
    odometer_return: 1000,
    fuel_level: 'half',
    return_condition: 'Fair',
    cleanliness: 'dirty',
    damage_report: 'Minor scratches on bumper',
    damage_fee: 500,
    washing_cost: 200,
    washing_type: 'Full Wash',
    penalty_amount: 125,
    fuel_penalty: 100,
    condition_adjustment: 200,
    total_additional_charges: 1025,
    original_balance: 1000,
    daily_rate: 500,
    total_cost: 1000,
    downpayment: 500,
    final_cost: 2025,
    payment_status: 'Additional Charges Due - ₱1,025.00 extra',
    rental_days: 2,
    base_rental_cost: 1000,
    hours_late: 14,
    late_fee_calculation: '(14 hrs × ₱20.83/hr × 1.25)',
    fuel_charge_calculation: '₱100.00 (Half tank)',
    condition_calculation: '₱200.00 (Fair condition)',
    washing_calculation: '₱200.00 (Full Wash)',
    damage_calculation: '₱500.00 (Damage fee)'
  };
  showReceiptModal(testData);
};

// Test function to verify user receipt AJAX endpoint
window.testUserReceiptAjax = function(rentalId = 1) {
  console.log('Testing USER receipt AJAX endpoint for rental:', rentalId);
  viewReceipt(rentalId);
};

function closeReceiptModal(){ 
  console.log('Closing receipt modal');
  receiptModal.style.display = 'none';
}

function printReceipt(){
  window.print();
}

function formatFuelLevel(level){
  const levels = {
    'full': 'Full Tank',
    '3/4': '3/4 Tank',
    'half': 'Half Tank',
    '1/4': '1/4 Tank',
    'empty': 'Empty'
  };
  return levels[level] || 'Full Tank';
}

function formatDateTime(date, time){
  const dateObj = new Date(date + 'T' + time);
  const options = { 
    year: 'numeric', 
    month: 'long', 
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    hour12: true
  };
  return dateObj.toLocaleDateString('en-US', options);
}

// ===== ADDITIONAL ANIMATIONS =====
const style = document.createElement('style');
style.textContent = `
  @keyframes slideOutRight {
    from { transform: translateX(0); opacity: 1; }
    to { transform: translateX(100%); opacity: 0; }
  }
`;
document.head.appendChild(style);

function closeDetailsModal(){
  const m = document.getElementById('detailsModal');
  if(!m) return;
  m.style.display = 'none';
  document.body.style.overflow = 'auto';
}

function openRentalDetails(rentalId){
  const card = document.querySelector(`.rental-card[data-rental-id="${Number(rentalId)}"]`);
  const m = document.getElementById('detailsModal');
  if(!m){
    return;
  }

  const found = card;

  const title = document.getElementById('detailsTitle');
  const sub = document.getElementById('detailsSub');
  const body = document.getElementById('detailsBody');
  if(title) title.textContent = 'Rental Details';
  if(sub) sub.textContent = '';
  if(body) body.innerHTML = '<div style="color:var(--text-secondary);font-weight:700">Loading…</div>';

  // UI-only details (no extra DB calls): read from visible card text
  if(found){
    const name = found.querySelector('.rental-name')?.textContent?.trim() || 'Vehicle';
    const meta = found.querySelector('.rental-meta')?.textContent?.trim() || '';
    const status = found.querySelector('.status-badge')?.textContent?.trim() || '';
    const price = found.querySelector('.price')?.textContent?.trim() || '';
    const dates = Array.from(found.querySelectorAll('.date-chip')).map(x => x.textContent.trim());

    if(title) title.textContent = name;
    if(sub) sub.textContent = meta;
    if(body){
      body.innerHTML = `
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:14px">
          <span class="status-badge" style="margin:0">${status}</span>
          <span style="color:var(--text-secondary);font-weight:800">${price}</span>
        </div>
        <div style="display:flex;flex-direction:column;gap:8px;color:var(--text-secondary);font-weight:700">
          ${dates.map(d => `<div>${d}</div>`).join('')}
        </div>
      `;
    }
  }

  m.style.display = 'flex';
  document.body.style.overflow = 'hidden';
}

function openPayModal(btnOrId, vehicleName, amountDue){
  const modal = document.getElementById('payModal');
  const form = document.getElementById('payForm');
  if(!modal || !form){
    alert('Payment form could not be opened. Please refresh the page.');
    return;
  }

  let rentalId = btnOrId;
  let name = vehicleName;
  let amount = amountDue;
  if (btnOrId && typeof btnOrId === 'object' && btnOrId.nodeType === 1) {
    rentalId = btnOrId.getAttribute('data-rental-id');
    name = btnOrId.getAttribute('data-vehicle-name');
    amount = btnOrId.getAttribute('data-amount');
  }

  document.getElementById('payRentalId').value = String(rentalId || '');
  document.getElementById('payVehicleName').textContent = name || 'this vehicle';
  document.getElementById('payAmountDue').textContent = '₱' + Number(amount || 0).toLocaleString('en-US', {minimumFractionDigits: 2});
  document.getElementById('payAmount').value = Number(amount || 0).toFixed(2);
  document.getElementById('payAmount').max = Number(amount || 0).toFixed(2);
  document.getElementById('payMethod').value = '';
  renderPayMethodInfo('');
  document.getElementById('payReference').value = '';
  document.getElementById('payNotes').value = '';
  document.getElementById('payReceiptPhoto').value = '';
  document.getElementById('payMsg').textContent = '';
  const preview = document.getElementById('payReceiptPreview');
  const img = document.getElementById('payReceiptImg');
  preview.hidden = true;
  preview.style.display = 'none';
  img.removeAttribute('src');
  img.style.display = 'none';
  document.getElementById('payReceiptName').textContent = '';
  document.getElementById('paySubmitBtn').disabled = Object.keys(PAY_METHODS).length === 0;

  if (modal.parentElement !== document.body) {
    document.body.appendChild(modal);
  }
  modal.classList.add('show');
  modal.style.display = 'flex';
  document.body.style.overflow = 'hidden';
}

function closePayModal(){
  const modal = document.getElementById('payModal');
  if(!modal) return;
  modal.classList.remove('show');
  modal.style.display = 'none';
  document.body.style.overflow = 'auto';
}

function openCancelModal(btn){
  const modal = document.getElementById('cancelModal');
  const form = document.getElementById('cancelForm');
  if(!modal || !form){
    alert('Cancel form could not be opened. Please refresh the page.');
    return;
  }
  const rentalId = btn.getAttribute('data-rental-id') || '';
  const name = btn.getAttribute('data-vehicle-name') || 'this vehicle';
  document.getElementById('cancelRentalId').value = String(rentalId);
  document.getElementById('cancelVehicleName').textContent = name;
  document.getElementById('cancelReason').value = '';
  document.getElementById('cancelReasonCount').textContent = '0';
  document.getElementById('cancelMsg').textContent = '';
  document.getElementById('cancelSubmitBtn').disabled = false;

  if (modal.parentElement !== document.body) {
    document.body.appendChild(modal);
  }
  modal.classList.add('show');
  modal.style.display = 'flex';
  document.body.style.overflow = 'hidden';
  setTimeout(() => document.getElementById('cancelReason')?.focus(), 50);
}

function closeCancelModal(){
  const modal = document.getElementById('cancelModal');
  if(!modal) return;
  modal.classList.remove('show');
  modal.style.display = 'none';
  document.body.style.overflow = 'auto';
}

const cancelReasonEl = document.getElementById('cancelReason');
if (cancelReasonEl) {
  cancelReasonEl.addEventListener('input', function(){
    const n = (this.value || '').length;
    const c = document.getElementById('cancelReasonCount');
    if (c) c.textContent = String(n);
  });
}

const cancelForm = document.getElementById('cancelForm');
if (cancelForm) {
  cancelForm.addEventListener('submit', async function(e){
    e.preventDefault();
    const msg = document.getElementById('cancelMsg');
    const btn = document.getElementById('cancelSubmitBtn');
    const reason = (document.getElementById('cancelReason')?.value || '').trim();
    const rentalId = document.getElementById('cancelRentalId')?.value || '';

    if (!rentalId) {
      if (msg) msg.textContent = 'Invalid rental.';
      return;
    }
    if (reason.length < 5) {
      if (msg) msg.textContent = 'Please enter a reason (at least 5 characters).';
      document.getElementById('cancelReason')?.focus();
      return;
    }

    if (!confirm('Cancel this booking? This cannot be undone.')) return;

    btn.disabled = true;
    if (msg) msg.textContent = 'Cancelling…';

    try {
      const fd = new FormData(cancelForm);
      const res = await fetch('includes/ajax_cancel_rental.php', { method: 'POST', body: fd });
      const data = await res.json();
      if (data && data.success) {
        if (msg) msg.textContent = data.message || 'Cancelled.';
        showToast(data.message || 'Booking cancelled.', true);
        setTimeout(() => { window.location.href = 'myrentals.php?status=cancelled'; }, 800);
      } else {
        if (msg) msg.textContent = (data && data.message) ? data.message : 'Cancellation failed.';
        showToast((data && data.message) || 'Cancellation failed.', false);
        btn.disabled = false;
      }
    } catch (err) {
      if (msg) msg.textContent = 'Network error. Please try again.';
      showToast('Network error. Please try again.', false);
      btn.disabled = false;
    }
  });
}

window.openCancelModal = openCancelModal;
window.closeCancelModal = closeCancelModal;

document.addEventListener('click', function(e){
  const btn = e.target.closest('.btn-pay-now');
  if(!btn) return;
  e.preventDefault();
  e.stopPropagation();
  openPayModal(btn);
}, true);

const PAY_METHODS = <?= json_encode(array_column(array_map(fn($m) => [
  'id' => (int)$m['id'],
  'provider' => $m['provider_name'],
  'type' => $m['provider_type'],
  'account_number' => $m['account_number'],
  'account_name' => (string)($m['account_name'] ?? ''),
  'qr' => (string)($m['qr_image'] ?? ''),
], $customerPaymentMethods ?? []), null, 'id'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

function renderPayMethodInfo(id){
  const box = document.getElementById('payMethodInfo');
  if (!box) return;
  const m = PAY_METHODS[String(id)] || PAY_METHODS[Number(id)];
  if (!m) { box.hidden = true; return; }

  const qr = document.getElementById('payMethodQr');
  const qrLink = document.getElementById('payMethodQrLink');
  const qrWrap = document.getElementById('payMethodQrWrap');
  const qrDownload = document.getElementById('payMethodQrDownload');
  if (m.qr) {
    qr.src = m.qr;
    qrLink.href = m.qr;
    const ext = (m.qr.split('.').pop() || 'png').split('?')[0];
    qrDownload.href = m.qr;
    qrDownload.setAttribute('download', m.provider.replace(/[^a-z0-9]+/gi, '_') + '_QR.' + ext);
    qrWrap.style.display = '';
  } else {
    qr.removeAttribute('src');
    qrLink.removeAttribute('href');
    qrDownload.removeAttribute('href');
    qrWrap.style.display = 'none';
  }
  document.getElementById('payMethodProvider').textContent = m.provider;
  document.getElementById('payMethodNumber').textContent = m.account_number;
  const nameEl = document.getElementById('payMethodName');
  nameEl.textContent = m.account_name ? ('Account name: ' + m.account_name) : '';
  nameEl.style.display = m.account_name ? '' : 'none';
  document.getElementById('payMethodHint').textContent = m.qr
    ? 'Scan the QR or send to the account number, then upload your receipt below.'
    : 'Send to the account number above, then upload your receipt below.';
  const copyBtn = document.getElementById('payMethodCopy');
  if (copyBtn) copyBtn.textContent = 'Copy';
  box.hidden = false;
}

document.getElementById('payMethod')?.addEventListener('change', function(){
  renderPayMethodInfo(this.value);
});

document.getElementById('payMethodCopy')?.addEventListener('click', async function(){
  const num = document.getElementById('payMethodNumber')?.textContent || '';
  if (!num) return;
  try {
    await navigator.clipboard.writeText(num);
    this.textContent = 'Copied';
  } catch (e) {
    this.textContent = 'Copy failed';
  }
  setTimeout(() => { this.textContent = 'Copy'; }, 1500);
});

const payReceiptPhoto = document.getElementById('payReceiptPhoto');
if (payReceiptPhoto) {
  payReceiptPhoto.addEventListener('change', function(){
    const file = this.files && this.files[0];
    const preview = document.getElementById('payReceiptPreview');
    const img = document.getElementById('payReceiptImg');
    const name = document.getElementById('payReceiptName');
    if(!file){
      preview.hidden = true;
      preview.style.display = 'none';
      img.removeAttribute('src');
      img.style.display = 'none';
      return;
    }
    name.textContent = file.name;
    const reader = new FileReader();
    reader.onload = function(ev){
      img.src = ev.target.result;
      img.style.display = 'block';
      preview.hidden = false;
      preview.style.display = 'block';
    };
    reader.readAsDataURL(file);
  });
}

const payForm = document.getElementById('payForm');
if (payForm) {
  payForm.addEventListener('submit', async function(e){
    e.preventDefault();
    const msg = document.getElementById('payMsg');
    const btn = document.getElementById('paySubmitBtn');
    const fileInput = document.getElementById('payReceiptPhoto');

    if(!fileInput.files || !fileInput.files.length){
      msg.style.color = '#fecaca';
      msg.textContent = 'Please upload a receipt photo.';
      return;
    }

    btn.disabled = true;
    msg.style.color = 'var(--text-secondary)';
    msg.textContent = 'Submitting payment...';

    try {
      const endpoint = <?= json_encode(rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/') . '/includes/ajax_user_payment.php') ?>;
      const res = await fetch(endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        body: new FormData(this)
      });
      if (!res.ok) {
        throw new Error('HTTP ' + res.status);
      }
      const data = await res.json();
      if(!data.success){
        msg.style.color = '#fecaca';
        msg.textContent = data.message || 'Payment submission failed.';
        btn.disabled = false;
        return;
      }
      msg.style.color = '#a7f3d0';
      msg.textContent = data.message || 'Payment submitted.';
      setTimeout(() => window.location.reload(), 1200);
    } catch (err) {
      msg.style.color = '#fecaca';
      msg.textContent = 'Network error. Please try again.';
      btn.disabled = false;
    }
  });
}

const payModalEl = document.getElementById('payModal');
if (payModalEl) {
  if (payModalEl.parentElement !== document.body) {
    document.body.appendChild(payModalEl);
  }
  payModalEl.addEventListener('click', function(e){
    if(e.target === this) closePayModal();
  });
}

window.openPayModal = openPayModal;
window.closePayModal = closePayModal;

window.openRentalDetails = openRentalDetails;
window.closeDetailsModal = closeDetailsModal;
</script>

</body>
</html>
<?php $conn->close(); ?>
