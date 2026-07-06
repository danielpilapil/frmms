<?php
/* ============================================================
   FleetGo — User Dashboard (Session-Safe for User Role)
============================================================ */

/* ---------- SESSION FIX ---------- */
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
session_name('fleetgo_session_user');
session_start();

require_once __DIR__ . '/includes/db.php';

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

$isLoggedIn = true;
$userId   = (int)$_SESSION['user_id'];
$userName = $_SESSION['user_name'] ?? 'Guest';

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function scalar($c, $sql, $fallback = 0){
  try{
    $r = $c->query($sql);
    if($r && ($row = $r->fetch_row())) return $row[0] ?? $fallback;
  } catch (Throwable $e) {}
  return $fallback;
}

function vehicle_img($row){
  $photo = trim((string)($row['photo'] ?? ''));
  if ($photo !== '') return 'assets/vehicles/'.h($photo);
  $type = strtolower((string)($row['vehicle_type'] ?? ''));
  if (strpos($type,'suv') !== false) return 'assets/vehicles/suv.jpg';
  if (strpos($type,'van') !== false) return 'assets/vehicles/van.jpg';
  if (strpos($type,'pickup') !== false) return 'assets/vehicles/pickup.jpg';
  if (strpos($type,'motor') !== false) return 'assets/vehicles/motorcycle.jpg';
  if (strpos($type,'hatch') !== false) return 'assets/vehicles/hatchback.jpg';
  if (strpos($type,'cross') !== false) return 'assets/vehicles/crossover.jpg';
  if (strpos($type,'scooter') !== false) return 'assets/vehicles/motorcycle.jpg';
  if (strpos($type,'mini') !== false) return 'assets/vehicles/van.jpg';
  return 'assets/vehicles/sedan.jpg';
}

// ===== FLEETGO FRMMS POLICIES =====
// Get user profile status for conditional display
$stmt = $conn->prepare("SELECT profile_status FROM users WHERE id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$userProfile = $stmt->get_result()->fetch_assoc();
$stmt->close();

$isApproved = ($userProfile['profile_status'] ?? '') === 'approved';

// Check for welcome notification (first-time user)
$welcome_stmt = $conn->prepare("
    SELECT id, message, created_at 
    FROM notifications 
    WHERE user_id = ? AND message LIKE '%Welcome to FleetGo%' 
    ORDER BY created_at DESC 
    LIMIT 1
");
$welcome_stmt->bind_param("i", $userId);
$welcome_stmt->execute();
$welcomeNotification = $welcome_stmt->get_result()->fetch_assoc();
$welcome_stmt->close();

$favoritesEnabled = false;
$recentViewsEnabled = false;
try {
  $favoritesEnabled = (bool)($conn->query("SHOW TABLES LIKE 'user_favorites'")->fetch_assoc());
  $recentViewsEnabled = (bool)($conn->query("SHOW TABLES LIKE 'user_recent_views'")->fetch_assoc());
} catch (Throwable $e) {
  $favoritesEnabled = false;
  $recentViewsEnabled = false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
  header('Content-Type: application/json');
  $action = (string)($_POST['action'] ?? '');
  $vehicleId = (int)($_POST['vehicle_id'] ?? 0);

  try {
    if ($vehicleId <= 0) {
      echo json_encode(['success' => false, 'message' => 'Invalid vehicle']);
      exit;
    }

    if ($action === 'toggle_favorite') {
      if (!$favoritesEnabled) {
        echo json_encode(['success' => false, 'message' => 'Favorites not available']);
        exit;
      }
      $stmt = $conn->prepare("SELECT id FROM user_favorites WHERE user_id = ? AND vehicle_id = ? LIMIT 1");
      $stmt->bind_param('ii', $userId, $vehicleId);
      $stmt->execute();
      $existing = $stmt->get_result()->fetch_assoc();
      $stmt->close();

      if ($existing) {
        $stmt = $conn->prepare("DELETE FROM user_favorites WHERE user_id = ? AND vehicle_id = ? LIMIT 1");
        $stmt->bind_param('ii', $userId, $vehicleId);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => true, 'favorited' => false]);
        exit;
      }

      $stmt = $conn->prepare("INSERT INTO user_favorites (user_id, vehicle_id) VALUES (?, ?)");
      $stmt->bind_param('ii', $userId, $vehicleId);
      $stmt->execute();
      $stmt->close();
      echo json_encode(['success' => true, 'favorited' => true]);
      exit;
    }

    if ($action === 'mark_view') {
      if (!$recentViewsEnabled) {
        echo json_encode(['success' => false, 'message' => 'Recent views not available']);
        exit;
      }
      $stmt = $conn->prepare("\
        INSERT INTO user_recent_views (user_id, vehicle_id, viewed_at)
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE viewed_at = NOW()
      ");
      $stmt->bind_param('ii', $userId, $vehicleId);
      $stmt->execute();
      $stmt->close();
      echo json_encode(['success' => true]);
      exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
  } catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Server error']);
    exit;
  }
}

// Favorites and recently viewed
$favoriteIds = [];
$favoriteVehicles = [];
$recentVehicles = [];

if ($favoritesEnabled) {
  try {
    $stmt = $conn->prepare("SELECT vehicle_id FROM user_favorites WHERE user_id = ? ORDER BY created_at DESC LIMIT 200");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($rows as $r) $favoriteIds[(int)$r['vehicle_id']] = true;
  } catch (Throwable $e) {
    $favoriteIds = [];
  }

  try {
    $stmt = $conn->prepare("\
      SELECT v.id, v.make_model, v.vehicle_type, v.seats, v.transmission, v.photo, v.current_status, v.daily_rate
      FROM user_favorites uf
      JOIN vehicles v ON v.id = uf.vehicle_id
      WHERE uf.user_id = ?
      ORDER BY uf.created_at DESC
      LIMIT 6
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $favoriteVehicles = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
  } catch (Throwable $e) {
    $favoriteVehicles = [];
  }
}

if ($recentViewsEnabled) {
  try {
    $stmt = $conn->prepare("\
      SELECT v.id, v.make_model, v.vehicle_type, v.seats, v.transmission, v.photo, v.current_status, v.daily_rate
      FROM (
        SELECT vehicle_id, MAX(viewed_at) AS last_viewed
        FROM user_recent_views
        WHERE user_id = ?
        GROUP BY vehicle_id
        ORDER BY last_viewed DESC
        LIMIT 6
      ) rv
      JOIN vehicles v ON v.id = rv.vehicle_id
      ORDER BY rv.last_viewed DESC
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $recentVehicles = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
  } catch (Throwable $e) {
    $recentVehicles = [];
  }
}

/* ==========================
   DASHBOARD DATA
========================== */

// KPI cards
$availableVehicles = (int)scalar($conn, "SELECT COUNT(*) FROM vehicles WHERE current_status = 'available'", 0);

$ongoingRentalCount = 0;
try{
  $stmt = $conn->prepare("SELECT COUNT(*) FROM rentals WHERE customer_id = ? AND status = 'ongoing'");
  $stmt->bind_param('i', $userId);
  $stmt->execute();
  $ongoingRentalCount = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
  $stmt->close();
} catch (Throwable $e) {}

$loyaltyPoints = 0;
try{
  $stmt = $conn->prepare("SELECT loyalty_points FROM users WHERE id = ? LIMIT 1");
  $stmt->bind_param('i', $userId);
  $stmt->execute();
  $loyaltyPoints = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
  $stmt->close();
} catch (Throwable $e) {}

$unreadNotifCount = 0;
try{
  $stmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
  $stmt->bind_param('i', $userId);
  $stmt->execute();
  $unreadNotifCount = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
  $stmt->close();
} catch (Throwable $e) {}

// Current ongoing rental card
$currentRental = null;
try{
  $stmt = $conn->prepare("\
    SELECT r.id, r.start_date, r.end_date, r.total_cost, r.daily_rate, r.applied_rate, r.status,
           v.id AS vehicle_id, v.make_model, v.vehicle_type, v.seats, v.transmission, v.photo, v.current_status
    FROM rentals r
    JOIN vehicles v ON v.id = r.vehicle_id
    WHERE r.customer_id = ? AND r.status = 'ongoing'
    ORDER BY r.start_date DESC, r.id DESC
    LIMIT 1
  ");
  $stmt->bind_param('i', $userId);
  $stmt->execute();
  $currentRental = $stmt->get_result()->fetch_assoc();
  $stmt->close();
} catch (Throwable $e) {}

// Latest 5 notifications
$latestNotifs = [];
try{
  $stmt = $conn->prepare("\
    SELECT id, vehicle_id, message, created_at, is_read
    FROM notifications
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 5
  ");
  $stmt->bind_param('i', $userId);
  $stmt->execute();
  $latestNotifs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
} catch (Throwable $e) {}

// Available today (main booking cards)
$availableToday = [];
try{
  $sql = "\
    SELECT v.id, v.make_model, v.vehicle_type, v.seats, v.transmission, v.photo, v.current_status, v.daily_rate
    FROM vehicles v
    WHERE v.current_status = 'available'
    ORDER BY v.make_model ASC
    LIMIT 8
  ";
  $r = $conn->query($sql);
  if ($r) $availableToday = $r->fetch_all(MYSQLI_ASSOC);
} catch (Throwable $e) {}

// Recommended for you (based on user's most common past vehicle_type)
$recommended = [];
try{
  $stmt = $conn->prepare("\
    SELECT v.vehicle_type, COUNT(*) AS cnt
    FROM rentals r
    JOIN vehicles v ON v.id = r.vehicle_id
    WHERE r.customer_id = ?
    GROUP BY v.vehicle_type
    ORDER BY cnt DESC
    LIMIT 1
  ");
  $stmt->bind_param('i', $userId);
  $stmt->execute();
  $pref = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if ($pref && !empty($pref['vehicle_type'])) {
    $vehicleType = (string)$pref['vehicle_type'];
    $stmt = $conn->prepare("\
      SELECT id, make_model, vehicle_type, seats, transmission, photo, current_status, daily_rate
      FROM vehicles
      WHERE vehicle_type = ?
      ORDER BY current_status = 'available' DESC, make_model ASC
      LIMIT 3
    ");
    $stmt->bind_param('s', $vehicleType);
    $stmt->execute();
    $recommended = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
  }
} catch (Throwable $e) {}

// Popular vehicles (top 5 by rentals)
$popular = [];
try{
  $sql = "\
    SELECT v.id, v.make_model, v.vehicle_type, v.seats, v.transmission, v.photo, v.current_status, v.daily_rate,
           t.total
    FROM (
      SELECT vehicle_id, COUNT(*) AS total
      FROM rentals
      GROUP BY vehicle_id
      ORDER BY total DESC
      LIMIT 5
    ) t
    JOIN vehicles v ON v.id = t.vehicle_id
    ORDER BY t.total DESC
  ";
  $r = $conn->query($sql);
  if ($r) $popular = $r->fetch_all(MYSQLI_ASSOC);
} catch (Throwable $e) {}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>FleetGo — Reliable Car Rentals</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link href="assets/css/fleetgo-shared.css" rel="stylesheet">
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
*{box-sizing:border-box;margin:0;padding:0}
body{
  font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,sans-serif;
  background:var(--bg);
  color:var(--text-primary);line-height:1.6;scroll-behavior:smooth;
  position:relative;min-height:100vh;
}
body::before{
  content:"";position:fixed;top:0;left:0;right:0;bottom:0;
  background:url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grid" width="20" height="20" patternUnits="userSpaceOnUse"><path d="M 20 0 L 0 0 0 20" fill="none" stroke="rgba(93,208,255,0.03)" stroke-width="0.5"/></pattern></defs><rect width="100" height="100" fill="url(%23grid)"/></svg>');
  pointer-events:none;z-index:0;
}

/* ===== DASHBOARD HERO ===== */
.dash-hero{
  background:linear-gradient(135deg,rgba(11,13,16,.95),rgba(16,20,25,.88));
  border-bottom:1px solid var(--border);
  position:relative;overflow:hidden;
}
.dash-hero::before{
  content:"";position:absolute;inset:0;
  background:url('assets/banner1.jpg') center/cover;opacity:.14;z-index:0;
  transform:scale(1.02);
}
.dash-hero-inner{
  position:relative;z-index:1;
  max-width:1240px;margin:0 auto;
  padding:56px 24px 28px;
}
.dash-title{
  font-size:clamp(1.9rem,4vw,3rem);
  font-weight:900;letter-spacing:-1px;
  margin-bottom:10px;
}
.dash-sub{
  color:var(--text-secondary);
  max-width:820px;
  margin-bottom:18px;
}
.dash-grid{
  display:grid;
  grid-template-columns:1.35fr .65fr;
  gap:18px;
  align-items:start;
}
.dash-search{
  background:rgba(16,20,25,.9);
  border:1px solid var(--border);
  border-radius:var(--radius);
  box-shadow:var(--shadow);
  padding:18px;
  backdrop-filter:blur(16px);
}
.search-row{
  display:grid;
  grid-template-columns:1fr 1fr 1fr auto;
  gap:12px;
}
.field{
  width:100%;
  background:var(--card);
  border:1px solid var(--border);
  border-radius:12px;
  color:var(--text-primary);
  padding:12px 14px;
  font-weight:600;
}
.field:focus{outline:none;border-color:var(--brand);box-shadow:0 0 0 4px rgba(93,208,255,.12)}
.btn-search{
  background:var(--gradient);
  border:0;
  border-radius:12px;
  padding:12px 18px;
  font-weight:900;
  cursor:pointer;
  color:#041b22;
  box-shadow:0 8px 24px rgba(93,208,255,.25);
  transition:all .25s ease;
  white-space:nowrap;
}
.btn-search:hover{transform:translateY(-2px);box-shadow:0 12px 32px rgba(93,208,255,.35)}
.stats-grid{
  display:grid;
  grid-template-columns:repeat(2, minmax(0,1fr));
  gap:12px;
}
.stat-card{
  background:rgba(16,20,25,.9);
  border:1px solid var(--border);
  border-radius:var(--radius);
  padding:16px;
  box-shadow:var(--shadow);
  backdrop-filter:blur(16px);
}
.stat-label{color:var(--text-secondary);font-size:.78rem;font-weight:800;text-transform:uppercase;letter-spacing:.7px;margin-bottom:6px}
.stat-value{font-size:1.6rem;font-weight:900;letter-spacing:-.5px}
.stat-sub{color:var(--text-muted);font-size:.9rem;margin-top:2px}

/* ===== DASHBOARD SECTIONS ===== */
.dash-section{max-width:1240px;margin:0 auto;padding:26px 24px;position:relative;z-index:1}
.row{display:grid;grid-template-columns:1fr 380px;gap:18px;align-items:start}
.panel{
  background:var(--card);
  border:1px solid var(--border);
  border-radius:var(--radius);
  box-shadow:var(--shadow);
  overflow:hidden;
}
.panel-h{
  padding:16px 18px;
  border-bottom:1px solid var(--border);
  display:flex;align-items:center;justify-content:space-between;gap:10px;
}
.panel-h h3{font-size:1.05rem;font-weight:900;margin:0;color:var(--text-primary)}
.panel-b{padding:16px 18px}
.badge{
  display:inline-flex;align-items:center;gap:6px;
  padding:6px 12px;border-radius:999px;
  font-weight:900;font-size:.75rem;letter-spacing:.6px;
  text-transform:uppercase;
  border:1px solid rgba(255,255,255,.1);
}
.badge.green{background:rgba(16,185,129,.15);color:#7cffc7}
.badge.yellow{background:rgba(245,158,11,.15);color:#ffd166}
.badge.red{background:rgba(239,68,68,.15);color:#ff7b7b}
.badge.blue{background:rgba(93,208,255,.15);color:#5dd0ff}
.badge.purple{background:rgba(99,102,241,.16);color:#a5b4fc}
.pill{background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:999px;padding:6px 10px;font-weight:800;font-size:.78rem;color:var(--text-secondary)}
.btn{
  display:inline-flex;align-items:center;justify-content:center;
  width:100%;
  border-radius:12px;
  padding:10px 12px;
  font-weight:900;
  text-decoration:none;
  cursor:pointer;
  border:1px solid var(--border);
  background:rgba(255,255,255,.02);
  color:var(--text-primary);
  transition:all .2s ease;
}
.btn:hover{transform:translateY(-1px);border-color:var(--border-hover)}
.btn.primary{background:var(--gradient);border-color:transparent;color:#041b22}
.btn.disabled{opacity:.55;cursor:not-allowed;transform:none}
.btn.disabled:hover{transform:none}

/* ===== NOTIFICATIONS LIST ===== */
.notif-list{display:flex;flex-direction:column;gap:10px}
.notif-item{
  border:1px solid var(--border);
  background:rgba(255,255,255,.02);
  border-radius:14px;
  padding:12px;
}
.notif-item.unread{border-color:rgba(93,208,255,.35);background:rgba(93,208,255,.06)}
.notif-msg{color:var(--text-secondary);font-size:.92rem;line-height:1.5}
.notif-meta{margin-top:8px;display:flex;justify-content:space-between;gap:10px;align-items:center;color:var(--text-muted);font-size:.8rem}

/* ===== HERO SLIDER ===== */
.hero-slider{
  position:relative;overflow:hidden;height:600px;
  box-shadow:0 10px 40px rgba(0,0,0,.2);
}
.slide{
  position:absolute;inset:0;opacity:0;transition:opacity 1s ease-in-out;
  background-size:cover;background-position:center;
}
.slide.active{opacity:1;z-index:1}
.slide-overlay{
  position:absolute;inset:0;
  background:linear-gradient(180deg,rgba(11,13,16,.5),rgba(11,13,16,.85));
  display:flex;align-items:center;justify-content:center;
}
.slide-content{
  text-align:center;color:#fff;max-width:900px;padding:40px;
  animation:slideUp 1s ease;
}
@keyframes slideUp{
  from{opacity:0;transform:translateY(40px)}
  to{opacity:1;transform:translateY(0)}
}
.slide-content h1{
  font-size:clamp(2.5rem,6vw,4.5rem);font-weight:900;
  letter-spacing:-1px;margin-bottom:24px;line-height:1.1;
  text-shadow:0 4px 20px rgba(0,0,0,.6);
}
.slide-content .accent{
  background:var(--gradient);
  -webkit-background-clip:text;-webkit-text-fill-color:transparent;
  background-clip:text;display:inline-block;
  filter:drop-shadow(0 0 20px rgba(93,208,255,.5));
}
.slide-content p{
  font-size:1.3rem;color:#e8f2f7;margin-bottom:32px;
  line-height:1.8;text-shadow:0 2px 10px rgba(0,0,0,.5);
}
.hero-cta{
  display:inline-block;background:var(--gradient);
  color:#041b22;font-weight:800;padding:16px 40px;font-size:1.1rem;
  border-radius:var(--radius-sm);text-decoration:none;
  box-shadow:0 8px 24px rgba(93,208,255,.4);transition:all .3s ease;
  position:relative;overflow:hidden;
}
.hero-cta::before{
  content:"";position:absolute;top:0;left:-100%;width:100%;height:100%;
  background:linear-gradient(90deg,transparent,rgba(255,255,255,.4),transparent);
  transition:left .6s ease;
}
.hero-cta:hover{
  transform:translateY(-3px) scale(1.05);
  box-shadow:0 12px 32px rgba(93,208,255,.5);
}
.hero-cta:hover::before{left:100%}

/* Slider Controls */
.slider-btn{
  position:absolute;top:50%;transform:translateY(-50%);
  background:rgba(255,255,255,.15);backdrop-filter:blur(10px);
  border:2px solid rgba(255,255,255,.3);color:#fff;
  width:60px;height:60px;border-radius:50%;font-size:2rem;
  cursor:pointer;transition:all .3s ease;z-index:10;
  display:flex;align-items:center;justify-content:center;
}
.slider-btn:hover{
  background:var(--gradient);border-color:var(--brand);
  color:#041b22;transform:translateY(-50%) scale(1.1);
}
.slider-btn.prev{left:30px}
.slider-btn.next{right:30px}

/* Slider Dots */
.slider-dots{
  position:absolute;bottom:30px;left:50%;transform:translateX(-50%);
  display:flex;gap:12px;z-index:10;
}
.dot{
  width:14px;height:14px;border-radius:50%;
  background:rgba(255,255,255,.4);cursor:pointer;
  transition:all .3s ease;border:2px solid transparent;
}
.dot.active{
  background:var(--brand);width:40px;border-radius:20px;
  box-shadow:0 0 20px rgba(93,208,255,.6);
}
.dot:hover{background:rgba(255,255,255,.7)}

/* ===== ENHANCED SECTION ===== */
.section{
  max-width:1240px;margin:0 auto;padding:80px 24px;position:relative;z-index:1;
}
.section h2{
  text-align:center;font-size:2.5rem;font-weight:900;
  margin-bottom:16px;letter-spacing:-1px;
  background:var(--gradient);-webkit-background-clip:text;
  -webkit-text-fill-color:transparent;
  color:var(--text-primary);
}
.section-subtitle{
  text-align:center;color:var(--text-secondary);font-size:1.1rem;
  margin-bottom:50px;max-width:600px;margin-left:auto;margin-right:auto;
}

/* ===== ENHANCED FEATURED MAIN ===== */
.featured-main{
  position:relative;border-radius:var(--radius);overflow:hidden;
  box-shadow:var(--shadow-xl);margin-bottom:40px;
  transition:all .4s ease;
  background:var(--card);
  border:1px solid var(--border);
}
.featured-main:hover{
  transform:translateY(-8px);box-shadow:0 30px 80px rgba(0,0,0,.2);
}
.featured-img{
  width:100%;height:400px;object-fit:cover;display:block;
  filter:brightness(90%);transition:all .6s ease;
}
.featured-main:hover .featured-img{
  transform:scale(1.05);filter:brightness(100%);
}
.featured-overlay{
  position:absolute;inset:0;
  background:linear-gradient(180deg,rgba(11,13,16,.2),rgba(11,13,16,.85));
  display:flex;flex-direction:column;justify-content:flex-end;
  padding:40px;color:var(--text-primary);
}
.featured-overlay h3{
  font-size:2rem;font-weight:900;margin-bottom:12px;
  text-shadow:0 2px 10px rgba(0,0,0,.5);
}
.featured-overlay p{
  color:#e8f2f7;font-size:1.05rem;margin-bottom:24px;
  max-width:600px;line-height:1.7;text-shadow:0 1px 5px rgba(0,0,0,.3);
}
.cta-feature{
  display:inline-block;background:var(--gradient);
  color:#041b22;font-weight:800;padding:14px 32px;font-size:1rem;
  border-radius:var(--radius-sm);text-decoration:none;
  box-shadow:0 8px 24px rgba(93,208,255,.3);transition:all .3s ease;
  position:relative;overflow:hidden;
}
.cta-feature::before{
  content:"";position:absolute;top:0;left:-100%;width:100%;height:100%;
  background:linear-gradient(90deg,transparent,rgba(255,255,255,.3),transparent);
  transition:left .5s ease;
}
.cta-feature:hover{
  transform:translateY(-3px);box-shadow:0 12px 32px rgba(93,208,255,.4);
}
.cta-feature:hover::before{left:100%}

/* ===== ENHANCED FEATURE CARDS ===== */
.featured-cards{
  display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));
  gap:28px;
}
.feature-card{
  background:var(--card);border-radius:var(--radius);overflow:hidden;
  box-shadow:var(--shadow);transition:all .4s ease;
  border:1px solid var(--border);position:relative;
}
.feature-card::before{
  content:"";position:absolute;top:0;left:0;right:0;height:4px;
  background:var(--gradient);transform:scaleX(0);transition:transform .3s ease;
}
.feature-card:hover::before{transform:scaleX(1)}
.feature-card:hover{
  transform:translateY(-10px) scale(1.02);
  box-shadow:var(--shadow-lg);
  border-color:var(--brand);
}
.f-thumb{
  width:100%;height:200px;object-fit:cover;
  filter:brightness(95%);transition:all .4s ease;
}
.feature-card:hover .f-thumb{
  filter:brightness(105%);transform:scale(1.05);
}
.f-body{padding:24px}
.f-body h4{
  font-size:1.3rem;font-weight:800;margin-bottom:10px;
  color:var(--text-primary);transition:color .3s ease;
}
.feature-card:hover .f-body h4{
  background:var(--gradient);-webkit-background-clip:text;
  -webkit-text-fill-color:transparent;
}
.f-body p{
  font-size:.95rem;color:var(--text-secondary);margin-bottom:16px;line-height:1.7;
}
.f-link{
  color:var(--brand);font-weight:700;text-decoration:none;
  font-size:.95rem;display:inline-flex;align-items:center;gap:6px;
  transition:all .3s ease;
}
.f-link::after{
  content:"→";transition:transform .3s ease;
}
.f-link:hover{color:var(--brand2)}
.f-link:hover::after{transform:translateX(4px)}

/* ===== ENHANCED FOOTER ===== */
.footer{
  background:linear-gradient(180deg,var(--bg),var(--card));
  color:var(--text-primary);padding:40px 24px;margin-top:80px;
  text-align:center;border-top:3px solid var(--brand);
  font-size:1rem;position:relative;z-index:1;
  box-shadow:var(--shadow-xl);
}
.footer a{
  color:var(--brand2);text-decoration:none;font-weight:700;
  margin:0 10px;transition:all .3s ease;
}
.footer a:hover{color:var(--brand);text-shadow:0 0 10px rgba(93,208,255,.5)}

/* ===== DASHBOARD STYLES ===== */
.quick-actions{
  display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));
  gap:24px;margin-bottom:40px;
}
.action-card{
  background:var(--card);border-radius:var(--radius);padding:32px;
  border:2px solid var(--border);transition:all .4s ease;
  display:flex;align-items:center;gap:20px;
  position:relative;overflow:hidden;
}
.action-card::before{
  content:"";position:absolute;top:0;left:0;right:0;height:4px;
  background:var(--gradient);transform:scaleX(0);transition:transform .3s ease;
}
.action-card:hover::before{transform:scaleX(1)}
.action-card:hover{
  transform:translateY(-8px);box-shadow:var(--shadow-lg);
  border-color:var(--brand);
}
.action-card.primary{border-color:var(--brand);}
.action-card.secondary{border-color:var(--accent);}
.action-card.accent{border-color:var(--brand2);}
.action-icon{
  font-size:3rem;flex-shrink:0;
  filter:drop-shadow(0 4px 8px rgba(0,0,0,.2));
}
.action-content{
  flex:1;min-width:0;
}
.action-content h3{
  font-size:1.4rem;font-weight:800;margin-bottom:8px;
  color:var(--text-primary);
}
.action-content p{
  color:var(--text-secondary);margin-bottom:16px;
  font-size:.95rem;line-height:1.6;
}
.btn-grad{
  display:inline-block;background:var(--gradient);
  color:#041b22;font-weight:800;padding:12px 24px;
  border-radius:var(--radius-sm);text-decoration:none;
  box-shadow:0 4px 12px rgba(93,208,255,.3);
  transition:all .3s ease;font-size:.9rem;
}
.btn-grad:hover{
  transform:translateY(-2px);box-shadow:0 8px 20px rgba(93,208,255,.4);
}
.btn-outline{
  display:inline-block;border:2px solid var(--brand);
  color:var(--brand);font-weight:700;padding:10px 22px;
  border-radius:var(--radius-sm);text-decoration:none;
  transition:all .3s ease;font-size:.9rem;
}
.btn-outline:hover{
  background:var(--brand);color:#041b22;
  transform:translateY(-2px);
}

/* ===== APPROVAL NOTICE ===== */
.approval-notice{
  background:linear-gradient(135deg, #fef3c7, #fde68a);
  border:2px solid #f59e0b;
  border-radius:var(--radius);
  margin:24px auto;
  max-width:800px;
  box-shadow:0 8px 32px rgba(245,158,11,.2);
  animation:slideDown 0.6s ease;
}
@keyframes slideDown{
  from{opacity:0;transform:translateY(-20px)}
  to{opacity:1;transform:translateY(0)}
}
@keyframes slideUp{
  from{opacity:1;transform:translateY(0)}
  to{opacity:0;transform:translateY(-20px)}
}
.approval-content{
  display:flex;align-items:center;gap:20px;
  padding:24px;position:relative;overflow:hidden;
}
.approval-content::before{
  content:"";position:absolute;top:0;left:0;right:0;bottom:0;
  background:linear-gradient(45deg,transparent,rgba(255,255,255,.1),transparent);
  transform:translateX(-100%);animation:shimmer 3s infinite;
}
@keyframes shimmer{
  0%{transform:translateX(-100%)}
  100%{transform:translateX(100%)}
}
.approval-icon{
  font-size:2.5rem;flex-shrink:0;
  filter:drop-shadow(0 2px 4px rgba(0,0,0,.2));
}
.approval-text{
  flex:1;min-width:0;
}
.approval-text h3{
  color:#92400e;font-size:1.3rem;font-weight:800;
  margin-bottom:8px;text-shadow:0 1px 2px rgba(0,0,0,.1);
}
.approval-text p{
  color:#a16207;font-size:.95rem;line-height:1.5;
  margin:0;
}
.btn-approval{
  background:linear-gradient(135deg,#f59e0b,#d97706);
  color:#fff;font-weight:700;padding:12px 24px;
  border-radius:var(--radius-sm);text-decoration:none;
  box-shadow:0 4px 12px rgba(245,158,11,.4);
  transition:all .3s ease;font-size:.9rem;
  white-space:nowrap;
}
.btn-approval:hover{
  background:linear-gradient(135deg,#d97706,#b45309);
  transform:translateY(-2px);box-shadow:0 6px 16px rgba(245,158,11,.5);
}

/* ===== WELCOME NOTICE ===== */
.welcome-notice{
  background:linear-gradient(135deg, #dbeafe, #bfdbfe);
  border:2px solid #3b82f6;
  border-radius:var(--radius);
  margin:24px auto;
  max-width:800px;
  box-shadow:0 8px 32px rgba(59,130,246,.2);
  animation:slideDown 0.6s ease;
}
.welcome-content{
  display:flex;align-items:center;gap:20px;
  padding:24px;position:relative;overflow:hidden;
}
.welcome-content::before{
  content:"";position:absolute;top:0;left:0;right:0;bottom:0;
  background:linear-gradient(45deg,transparent,rgba(255,255,255,.1),transparent);
  transform:translateX(-100%);animation:shimmer 3s infinite;
}
.welcome-icon{
  font-size:2.5rem;flex-shrink:0;
  filter:drop-shadow(0 2px 4px rgba(0,0,0,.2));
  animation:bounce 2s infinite;
}
@keyframes bounce{
  0%,20%,50%,80%,100%{transform:translateY(0)}
  40%{transform:translateY(-10px)}
  60%{transform:translateY(-5px)}
}
.welcome-text{
  flex:1;min-width:0;
}
.welcome-text h3{
  color:#1e40af;font-size:1.3rem;font-weight:800;
  margin-bottom:8px;text-shadow:0 1px 2px rgba(0,0,0,.1);
}
.welcome-text p{
  color:#1e3a8a;font-size:.95rem;line-height:1.5;
  margin:0;
}
.btn-welcome-dismiss{
  background:linear-gradient(135deg,#3b82f6,#2563eb);
  color:#fff;font-weight:700;padding:12px 24px;
  border-radius:var(--radius-sm);border:none;
  box-shadow:0 4px 12px rgba(59,130,246,.4);
  transition:all .3s ease;font-size:.9rem;
  white-space:nowrap;cursor:pointer;
}
.btn-welcome-dismiss:hover{
  background:linear-gradient(135deg,#2563eb,#1d4ed8);
  transform:translateY(-2px);box-shadow:0 6px 16px rgba(59,130,246,.5);
}

.cards{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}
.v-card{
  background:var(--card);
  border:1px solid var(--border);
  border-radius:var(--radius);
  overflow:hidden;
  transition:all .25s ease;
  box-shadow:var(--shadow);
}
.v-card:hover{transform:translateY(-6px);border-color:var(--border-hover);box-shadow:var(--shadow-lg)}
.v-img{width:100%;height:180px;object-fit:cover;display:block}
.v-body{padding:14px}
.v-title{font-weight:900;font-size:1.05rem;margin:0 0 8px;letter-spacing:-.3px}
.v-meta{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px}
.v-price{font-weight:900;font-size:1.15rem;margin-bottom:10px}
.v-actions{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.v-top{display:flex;justify-content:space-between;gap:10px;align-items:flex-start}
.fav-btn{
  width:40px;height:40px;border-radius:12px;
  border:1px solid var(--border);
  background:rgba(255,255,255,.02);
  color:var(--text-primary);
  cursor:pointer;
  font-weight:900;
  display:inline-flex;align-items:center;justify-content:center;
  transition:all .2s ease;
  flex-shrink:0;
}
.fav-btn:hover{transform:translateY(-1px);border-color:var(--border-hover)}
.fav-btn.active{border-color:rgba(245,158,11,.4);background:rgba(245,158,11,.12);color:#ffd166}
.fav-btn.disabled{opacity:.5;cursor:not-allowed;transform:none}

/* ===== RESPONSIVE ===== */
@media(max-width:768px){
  .dash-hero-inner{padding:44px 20px 22px}
  .dash-grid{grid-template-columns:1fr;gap:14px}
  .search-row{grid-template-columns:1fr 1fr;}
  .stats-grid{grid-template-columns:repeat(2, minmax(0,1fr));}
  .dash-section{padding:20px}
  .row{grid-template-columns:1fr;}
  .cards{grid-template-columns:1fr;}
  .quick-actions{grid-template-columns:1fr;gap:16px}
  .action-card{flex-direction:column;text-align:center;padding:24px}
  .approval-content{flex-direction:column;text-align:center;gap:16px}
  .approval-text h3{font-size:1.1rem}
  .approval-text p{font-size:.9rem}
  .welcome-content{flex-direction:column;text-align:center;gap:16px}
  .welcome-text h3{font-size:1.1rem}
  .welcome-text p{font-size:.9rem}
}
</style>
</head>
<body>

<?php include __DIR__.'/includes/user_navbar.php'; ?>

<!-- ===== HERO SLIDER ===== -->
<section class="dash-hero">
  <div class="dash-hero-inner">
    <div class="dash-title">Welcome, <?= h($userName) ?> 👋</div>
    <div class="dash-sub">Search your dates, explore available cars, and manage your rentals — all in one place.</div>

    <div class="dash-grid">
      <form class="dash-search" method="get" action="vehiclepage.php">
        <div class="search-row">
          <input class="field" type="date" name="start_date" required>
          <input class="field" type="date" name="end_date" required>
          <select class="field" name="vehicle_type">
            <option value="">Any type</option>
            <option value="Sedan">Sedan</option>
            <option value="SUV">SUV</option>
            <option value="Van">Van</option>
            <option value="Pickup Truck">Pickup Truck</option>
            <option value="Motorcycle">Motorcycle</option>
            <option value="Hatchback">Hatchback</option>
            <option value="Crossover">Crossover</option>
            <option value="Minivan">Minivan</option>
            <option value="Scooter">Scooter</option>
          </select>
          <button class="btn-search" type="submit">Search</button>
        </div>
      </form>

      <div class="stats-grid">
        <div class="stat-card">
          <div class="stat-label">Available Vehicles</div>
          <div class="stat-value"><?= number_format($availableVehicles) ?></div>
          <div class="stat-sub">Ready to book</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Ongoing Rental</div>
          <div class="stat-value"><?= number_format($ongoingRentalCount) ?></div>
          <div class="stat-sub">Active right now</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Loyalty Points</div>
          <div class="stat-value"><?= number_format($loyaltyPoints) ?></div>
          <div class="stat-sub">Earn more when you rent</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Notifications</div>
          <div class="stat-value"><?= number_format($unreadNotifCount) ?></div>
          <div class="stat-sub">Unread updates</div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ===== WELCOME NOTIFICATION (For New Users) ===== -->
<?php if ($welcomeNotification): ?>
<div class="welcome-notice">
  <div class="welcome-content">
    <div class="welcome-icon">🎁</div>
    <div class="welcome-text">
      <h3>Welcome to FleetGo!</h3>
      <p><?= h($welcomeNotification['message']) ?></p>
    </div>
    <button class="btn-welcome-dismiss" onclick="dismissWelcome(<?= $welcomeNotification['id'] ?>)">✓ Got it!</button>
  </div>
</div>
<?php endif; ?>

<!-- ===== PROFILE APPROVAL NOTICE (Only for Unapproved Users) ===== -->
<?php if (!$isApproved): ?>
<div class="approval-notice">
  <div class="approval-content">
    <div class="approval-icon">⚠️</div>
    <div class="approval-text">
      <h3>Profile Approval Required</h3>
      <p>Your profile is pending approval. Complete your profile information to enable vehicle bookings.</p>
    </div>
    <a href="userprofile.php" class="btn-approval">Complete Profile</a>
  </div>
</div>
<?php endif; ?>

<!-- ===== USER DASHBOARD ===== -->
<section class="dash-section">
  <div class="quick-actions">
    <a class="action-card primary" href="vehiclepage.php" style="text-decoration:none;color:inherit;">
      <div class="action-icon">🚗</div>
      <div class="action-content">
        <h3>Book a Car</h3>
        <p>Browse available vehicles and book in minutes</p>
        <span class="btn-grad">Browse Fleet</span>
      </div>
    </a>

    <a class="action-card secondary" href="myrentals.php" style="text-decoration:none;color:inherit;">
      <div class="action-icon">📋</div>
      <div class="action-content">
        <h3>My Rentals</h3>
        <p>Track status: pending → approved → ongoing → completed</p>
        <span class="btn-outline">Open</span>
      </div>
    </a>

    <a class="action-card accent" href="notif.php" style="text-decoration:none;color:inherit;">
      <div class="action-icon">🔔</div>
      <div class="action-content">
        <h3>Notifications</h3>
        <p>See booking updates and system alerts</p>
        <span class="btn-outline">View</span>
      </div>
    </a>

    <a class="action-card" href="userprofile.php" style="text-decoration:none;color:inherit;">
      <div class="action-icon">👤</div>
      <div class="action-content">
        <h3>Profile</h3>
        <p>Complete details to unlock bookings if required</p>
        <span class="btn-outline">Manage</span>
      </div>
    </a>
  </div>

  <div class="row">
    <div>
      <?php if ($currentRental): ?>
        <div class="panel" style="margin-bottom:18px;">
          <div class="panel-h">
            <h3>Current Rental</h3>
            <span class="badge purple">ONGOING</span>
          </div>
          <div class="panel-b">
            <div style="display:grid;grid-template-columns:140px 1fr;gap:14px;align-items:center;">
              <img src="<?= h(vehicle_img($currentRental)) ?>" alt="<?= h($currentRental['make_model'] ?? '') ?>" style="width:140px;height:92px;object-fit:cover;border-radius:14px;border:1px solid var(--border);">
              <div>
                <div style="font-weight:900;font-size:1.15rem;letter-spacing:-.3px;"><?= h($currentRental['make_model'] ?? '') ?></div>
                <div style="color:var(--text-secondary);margin-top:4px;">
                  <?= h($currentRental['start_date'] ?? '') ?> → <?= h($currentRental['end_date'] ?? '') ?>
                </div>
                <div style="margin-top:10px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                  <span class="pill"><?= h($currentRental['vehicle_type'] ?? '') ?></span>
                  <span class="pill"><?= h($currentRental['transmission'] ?? '') ?></span>
                  <span class="pill"><?= (int)($currentRental['seats'] ?? 0) ?> seats</span>
                  <span class="pill">₱<?= number_format((float)($currentRental['total_cost'] ?? 0), 2) ?> total</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <div class="panel" style="margin-bottom:18px;">
        <div class="panel-h">
          <h3>Available Today</h3>
          <a href="vehiclepage.php" class="btn" style="width:auto;padding:8px 12px;">Browse All</a>
        </div>
        <div class="panel-b">
          <?php if (!empty($availableToday)): ?>
            <div class="cards">
              <?php foreach ($availableToday as $v):
                $status = strtolower(trim((string)($v['current_status'] ?? '')));
                $badge = $status === 'available' ? 'green' : ($status === 'rented' ? 'yellow' : ($status === 'maintenance' || $status === 'scheduled_maintenance' ? 'red' : 'blue'));
                $badgeText = $status === 'available' ? 'Available' : ucfirst(str_replace('_',' ', $status));
                $canBook = ($status === 'available') && $isApproved;
              ?>
                <article class="v-card">
                  <img class="v-img" src="<?= h(vehicle_img($v)) ?>" alt="<?= h($v['make_model'] ?? '') ?>">
                  <div class="v-body">
                    <div class="v-top">
                      <div style="min-width:0;">
                        <h4 class="v-title"><?= h($v['make_model'] ?? '') ?></h4>
                        <span class="badge <?= h($badge) ?>"><?= h($badgeText) ?></span>
                      </div>
                      <button class="fav-btn <?= !$favoritesEnabled ? 'disabled' : '' ?> <?= isset($favoriteIds[(int)$v['id']]) ? 'active' : '' ?>" type="button"
                        title="<?= $favoritesEnabled ? (isset($favoriteIds[(int)$v['id']]) ? 'Remove favorite' : 'Add to favorites') : 'Favorites not available' ?>"
                        data-fav="1" data-id="<?= (int)$v['id'] ?>" <?= !$favoritesEnabled ? 'disabled' : '' ?>>♥</button>
                    </div>
                    <div class="v-price">₱<?= number_format((float)($v['daily_rate'] ?? 0), 2) ?>/day</div>
                    <div class="v-meta">
                      <span class="pill"><?= h($v['transmission'] ?? '') ?></span>
                      <span class="pill"><?= (int)($v['seats'] ?? 0) ?> seats</span>
                      <span class="pill"><?= h($v['vehicle_type'] ?? '') ?></span>
                    </div>
                    <div class="v-actions">
                      <a class="btn js-view-link" href="vehiclepage.php" data-id="<?= (int)$v['id'] ?>"
                        data-name="<?= h($v['make_model'] ?? '') ?>"
                        data-type="<?= h($v['vehicle_type'] ?? '') ?>"
                        data-trans="<?= h($v['transmission'] ?? '') ?>"
                        data-seats="<?= (int)($v['seats'] ?? 0) ?>"
                        data-rate="<?= number_format((float)($v['daily_rate'] ?? 0), 2, '.', '') ?>"
                        data-status="<?= h($v['current_status'] ?? '') ?>">View Details</a>
                      <?php if ($canBook): ?>
                        <a class="btn primary js-book-link" href="vehiclepage.php" data-id="<?= (int)$v['id'] ?>" data-name="<?= h($v['make_model'] ?? '') ?>">Book Now</a>
                      <?php else: ?>
                        <button class="btn disabled" type="button" disabled><?= $isApproved ? 'Unavailable' : 'Profile Pending' ?></button>
                      <?php endif; ?>
                    </div>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div style="color:var(--text-secondary);">No vehicles are marked available today.</div>
          <?php endif; ?>
        </div>
      </div>

      <div class="panel" style="margin-bottom:18px;">
        <div class="panel-h">
          <h3>Recommended for You</h3>
          <span class="badge blue">Smart Picks</span>
        </div>
        <div class="panel-b">
          <?php if (!empty($recommended)): ?>
            <div class="cards">
              <?php foreach ($recommended as $v):
                $status = strtolower(trim((string)($v['current_status'] ?? '')));
                $badge = $status === 'available' ? 'green' : ($status === 'rented' ? 'yellow' : ($status === 'maintenance' || $status === 'scheduled_maintenance' ? 'red' : 'blue'));
                $badgeText = $status === 'available' ? 'Available' : ucfirst(str_replace('_',' ', $status));
                $canBook = ($status === 'available') && $isApproved;
              ?>
                <article class="v-card">
                  <img class="v-img" src="<?= h(vehicle_img($v)) ?>" alt="<?= h($v['make_model'] ?? '') ?>">
                  <div class="v-body">
                    <div class="v-top">
                      <div style="min-width:0;">
                        <h4 class="v-title"><?= h($v['make_model'] ?? '') ?></h4>
                        <span class="badge <?= h($badge) ?>"><?= h($badgeText) ?></span>
                      </div>
                      <button class="fav-btn <?= !$favoritesEnabled ? 'disabled' : '' ?> <?= isset($favoriteIds[(int)$v['id']]) ? 'active' : '' ?>" type="button"
                        title="<?= $favoritesEnabled ? (isset($favoriteIds[(int)$v['id']]) ? 'Remove favorite' : 'Add to favorites') : 'Favorites not available' ?>"
                        data-fav="1" data-id="<?= (int)$v['id'] ?>" <?= !$favoritesEnabled ? 'disabled' : '' ?>>♥</button>
                    </div>
                    <div class="v-price">₱<?= number_format((float)($v['daily_rate'] ?? 0), 2) ?>/day</div>
                    <div class="v-meta">
                      <span class="pill"><?= h($v['transmission'] ?? '') ?></span>
                      <span class="pill"><?= (int)($v['seats'] ?? 0) ?> seats</span>
                      <span class="pill"><?= h($v['vehicle_type'] ?? '') ?></span>
                    </div>
                    <div class="v-actions">
                      <a class="btn js-view-link" href="vehiclepage.php" data-id="<?= (int)$v['id'] ?>"
                        data-name="<?= h($v['make_model'] ?? '') ?>"
                        data-type="<?= h($v['vehicle_type'] ?? '') ?>"
                        data-trans="<?= h($v['transmission'] ?? '') ?>"
                        data-seats="<?= (int)($v['seats'] ?? 0) ?>"
                        data-rate="<?= number_format((float)($v['daily_rate'] ?? 0), 2, '.', '') ?>"
                        data-status="<?= h($v['current_status'] ?? '') ?>">View Details</a>
                      <?php if ($canBook): ?>
                        <a class="btn primary js-book-link" href="vehiclepage.php" data-id="<?= (int)$v['id'] ?>" data-name="<?= h($v['make_model'] ?? '') ?>">Book Now</a>
                      <?php else: ?>
                        <button class="btn disabled" type="button" disabled><?= $isApproved ? 'Unavailable' : 'Profile Pending' ?></button>
                      <?php endif; ?>
                    </div>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div style="color:var(--text-secondary);">Rent a vehicle to get personalized recommendations.</div>
          <?php endif; ?>
        </div>
      </div>

      <div class="panel">
        <div class="panel-h">
          <h3>Popular Vehicles</h3>
          <span class="badge yellow">Trending</span>
        </div>
        <div class="panel-b">
          <?php if (!empty($popular)): ?>
            <div class="cards">
              <?php foreach ($popular as $v):
                $status = strtolower(trim((string)($v['current_status'] ?? '')));
                $badge = $status === 'available' ? 'green' : ($status === 'rented' ? 'yellow' : ($status === 'maintenance' || $status === 'scheduled_maintenance' ? 'red' : 'blue'));
                $badgeText = $status === 'available' ? 'Available' : ucfirst(str_replace('_',' ', $status));
                $canBook = ($status === 'available') && $isApproved;
              ?>
                <article class="v-card">
                  <img class="v-img" src="<?= h(vehicle_img($v)) ?>" alt="<?= h($v['make_model'] ?? '') ?>">
                  <div class="v-body">
                    <div class="v-top">
                      <div style="min-width:0;">
                        <h4 class="v-title"><?= h($v['make_model'] ?? '') ?></h4>
                        <span class="badge <?= h($badge) ?>"><?= h($badgeText) ?></span>
                      </div>
                      <button class="fav-btn <?= !$favoritesEnabled ? 'disabled' : '' ?> <?= isset($favoriteIds[(int)$v['id']]) ? 'active' : '' ?>" type="button"
                        title="<?= $favoritesEnabled ? (isset($favoriteIds[(int)$v['id']]) ? 'Remove favorite' : 'Add to favorites') : 'Favorites not available' ?>"
                        data-fav="1" data-id="<?= (int)$v['id'] ?>" <?= !$favoritesEnabled ? 'disabled' : '' ?>>♥</button>
                    </div>
                    <div class="v-price">₱<?= number_format((float)($v['daily_rate'] ?? 0), 2) ?>/day</div>
                    <div class="v-meta">
                      <span class="pill"><?= h($v['transmission'] ?? '') ?></span>
                      <span class="pill"><?= (int)($v['seats'] ?? 0) ?> seats</span>
                      <span class="pill"><?= h($v['vehicle_type'] ?? '') ?></span>
                    </div>
                    <div class="v-actions">
                      <a class="btn js-view-link" href="vehiclepage.php" data-id="<?= (int)$v['id'] ?>"
                        data-name="<?= h($v['make_model'] ?? '') ?>"
                        data-type="<?= h($v['vehicle_type'] ?? '') ?>"
                        data-trans="<?= h($v['transmission'] ?? '') ?>"
                        data-seats="<?= (int)($v['seats'] ?? 0) ?>"
                        data-rate="<?= number_format((float)($v['daily_rate'] ?? 0), 2, '.', '') ?>"
                        data-status="<?= h($v['current_status'] ?? '') ?>">View Details</a>
                      <?php if ($canBook): ?>
                        <a class="btn primary js-book-link" href="vehiclepage.php" data-id="<?= (int)$v['id'] ?>" data-name="<?= h($v['make_model'] ?? '') ?>">Book Now</a>
                      <?php else: ?>
                        <button class="btn disabled" type="button" disabled><?= $isApproved ? 'Unavailable' : 'Profile Pending' ?></button>
                      <?php endif; ?>
                    </div>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div style="color:var(--text-secondary);">No popular vehicles data yet.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <aside>
      <div class="panel" style="margin-bottom:18px;">
        <div class="panel-h">
          <h3>Notifications</h3>
          <a href="notif.php" class="btn" style="width:auto;padding:8px 12px;">Open</a>
        </div>
        <div class="panel-b">
          <?php if (!empty($latestNotifs)): ?>
            <div class="notif-list">
              <?php foreach ($latestNotifs as $n):
                $isUnread = (int)($n['is_read'] ?? 0) === 0;
              ?>
                <div class="notif-item <?= $isUnread ? 'unread' : '' ?>">
                  <div class="notif-msg"><?= $n['message'] ?></div>
                  <div class="notif-meta">
                    <span><?= h($n['created_at'] ?? '') ?></span>
                    <span class="badge <?= $isUnread ? 'blue' : 'green' ?>"><?= $isUnread ? 'Unread' : 'Read' ?></span>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div style="color:var(--text-secondary);">No notifications yet.</div>
          <?php endif; ?>
        </div>
      </div>

      <div class="panel">
        <div class="panel-h">
          <h3>Tips</h3>
          <span class="badge green">Fast Flow</span>
        </div>
        <div class="panel-b" style="color:var(--text-secondary);">
          <div style="margin-bottom:10px;">Dashboard → View Vehicle → Book → Pending → Approved → Ongoing → Completed</div>
          <?php if (!$isApproved): ?>
            <div class="notif-item unread" style="margin:0;">
              <div style="font-weight:900;margin-bottom:6px;">Complete your profile to start renting</div>
              <div class="notif-msg">Your profile is not approved yet. Finish your profile details and wait for approval.</div>
              <div style="margin-top:10px;"><a class="btn primary" href="userprofile.php">Complete Profile</a></div>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($favoritesEnabled): ?>
      <div class="panel" style="margin-top:18px;">
        <div class="panel-h">
          <h3>Favorites</h3>
          <span class="badge yellow">Saved</span>
        </div>
        <div class="panel-b">
          <?php if (!empty($favoriteVehicles)): ?>
            <div class="notif-list">
              <?php foreach ($favoriteVehicles as $v): ?>
                <div class="notif-item">
                  <div style="display:flex;gap:12px;align-items:center;">
                    <img src="<?= h(vehicle_img($v)) ?>" alt="<?= h($v['make_model'] ?? '') ?>" style="width:60px;height:42px;object-fit:cover;border-radius:12px;border:1px solid var(--border);">
                    <div style="min-width:0;flex:1;">
                      <div style="font-weight:900;color:var(--text-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                        <?= h($v['make_model'] ?? '') ?>
                      </div>
                      <div style="color:var(--text-muted);font-size:.85rem;">₱<?= number_format((float)($v['daily_rate'] ?? 0), 2) ?>/day</div>
                    </div>
                    <a class="btn js-book-link" href="vehiclepage.php" data-id="<?= (int)$v['id'] ?>" data-name="<?= h($v['make_model'] ?? '') ?>" style="width:auto;">Book</a>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div style="color:var(--text-secondary);">Save vehicles to quickly access them later.</div>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($recentViewsEnabled): ?>
      <div class="panel" style="margin-top:18px;">
        <div class="panel-h">
          <h3>Recently Viewed</h3>
          <span class="badge blue">History</span>
        </div>
        <div class="panel-b">
          <?php if (!empty($recentVehicles)): ?>
            <div class="notif-list">
              <?php foreach ($recentVehicles as $v): ?>
                <div class="notif-item">
                  <div style="display:flex;gap:12px;align-items:center;">
                    <img src="<?= h(vehicle_img($v)) ?>" alt="<?= h($v['make_model'] ?? '') ?>" style="width:60px;height:42px;object-fit:cover;border-radius:12px;border:1px solid var(--border);">
                    <div style="min-width:0;flex:1;">
                      <div style="font-weight:900;color:var(--text-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                        <?= h($v['make_model'] ?? '') ?>
                      </div>
                      <div style="color:var(--text-muted);font-size:.85rem;"><?= h($v['vehicle_type'] ?? '') ?> • ₱<?= number_format((float)($v['daily_rate'] ?? 0), 2) ?>/day</div>
                    </div>
                    <a class="btn js-view-link" href="vehiclepage.php" data-id="<?= (int)$v['id'] ?>"
                      data-name="<?= h($v['make_model'] ?? '') ?>"
                      data-type="<?= h($v['vehicle_type'] ?? '') ?>"
                      data-trans="<?= h($v['transmission'] ?? '') ?>"
                      data-seats="<?= (int)($v['seats'] ?? 0) ?>"
                      data-rate="<?= number_format((float)($v['daily_rate'] ?? 0), 2, '.', '') ?>"
                      data-status="<?= h($v['current_status'] ?? '') ?>" style="width:auto;">Details</a>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div style="color:var(--text-secondary);">Your viewed vehicles will appear here.</div>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
    </aside>
  </div>
</section>

<!-- ===== FEATURED SECTION ===== -->

<!-- ===== FOOTER ===== -->
<footer class="footer">
  © <?= date('Y') ?> FleetGo Rentals • 
  <a href="#">Terms</a> • 
  <a href="#">Privacy</a> • 
  <a href="#">Contact</a>
</footer>

<script>
// Profile Menu Toggle
const t=document.getElementById('profileToggle');
const m=document.getElementById('profileMenu');
if(t&&m){
  t.addEventListener('click',e=>{e.stopPropagation();m.classList.toggle('show');});
  document.addEventListener('click',e=>{if(!m.contains(e.target))m.classList.remove('show');});
}

document.querySelectorAll('[data-fav]').forEach(btn => {
  btn.addEventListener('click', async () => {
    if (btn.hasAttribute('disabled')) return;
    const id = btn.dataset.id;
    if (!id) return;
    try {
      const res = await fetch('userpage.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `ajax=1&action=toggle_favorite&vehicle_id=${encodeURIComponent(id)}`
      });
      const data = await res.json();
      if (data && data.success) {
        if (data.favorited) btn.classList.add('active');
        else btn.classList.remove('active');
      }
    } catch (e) {}
  });
});

// Welcome Notification Dismiss
function dismissWelcome(notificationId) {
  fetch('notif.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: `action=mark_read&id=${notificationId}`
  })
  .then(response => response.json())
  .then(data => {
    if(data.success) {
      document.querySelector('.welcome-notice').style.animation = 'slideUp 0.5s ease forwards';
      setTimeout(() => {
        document.querySelector('.welcome-notice').remove();
      }, 500);
    }
  })
  .catch(error => console.error('Error dismissing welcome notification:', error));
}

// Track recent views before navigating
async function markViewThenGo(vehicleId, href){
  try {
    if (vehicleId) {
      await fetch('userpage.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `ajax=1&action=mark_view&vehicle_id=${encodeURIComponent(vehicleId)}`
      });
    }
  } catch (e) {}
  window.location.href = href;
}

document.querySelectorAll('.js-view-link, .js-book-link').forEach(a => {
  a.addEventListener('click', (e) => {
    e.preventDefault();
    const vid = a.dataset.id || '';
    const href = a.getAttribute('href') || 'vehiclepage.php';
    markViewThenGo(vid, href);
  });
});
</script>
</body>
</html>
<?php $conn->close(); ?>
