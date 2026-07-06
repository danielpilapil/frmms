<?php
/* ============================================================
   FleetGo — Pricing Page (Public Access with User Features)
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
// Get current promotions with proper conditional logic
// Based on actual database schema - promotions table has: id, promo_name, promo_type, discount_percent, min_days, min_bookings, is_active, created_at, updated_at
$stmt = $conn->prepare("
    SELECT * FROM promotions 
    WHERE is_active = 1 
    ORDER BY discount_percent DESC, created_at DESC
");
$stmt->execute();
$active_promotions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Since the promotions table doesn't have date fields, we'll show all active promotions
$upcoming_promotions = [];
$expired_promotions = [];

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function formatComfort($val){
  $raw = trim((string)$val);
  if ($raw === '') return 'Standard';
  // If it's already a non-numeric label, keep it
  if (!ctype_digit($raw)) return $raw;
  // Map common numeric levels to labels
  switch ((int)$raw) {
    case 2: return 'Luxury';
    case 1: return 'Premium';
    default: return 'Standard';
  }
}
function slug($v){
  $v = strtolower((string)$v);
  $v = preg_replace('/[^a-z0-9]+/','-',$v);
  return trim($v,'-');
}
define('VEH_IMG_URL', 'assets/vehicles');

/* ===== Fetch all available vehicles with rates ===== */
$sql = "
  SELECT id, plate_no, make_model, vehicle_type, transmission,
         comfort_level, year, odometer, photo, daily_rate
  FROM vehicles
  WHERE current_status = 'available'
  ORDER BY vehicle_type, make_model ASC
";
$res = $conn->query($sql);
$vehicles = [];
while($r = $res->fetch_assoc()) {
  $vehicles[$r['vehicle_type']][] = $r;
}

/* ===== DISCOUNT FEATURES ===== */
// Get user's rental history for real loyalty data
$userRentalCount = 0;
$userTotalSpent = 0;
$userClassification = 'Personal';
$userBusinessDiscount = 0;

if ($isLoggedIn) {
  // Get rental history
  $stmt = $conn->prepare("
    SELECT COUNT(*) as rental_count, COALESCE(SUM(total_cost), 0) as total_spent
    FROM rentals 
    WHERE customer_id = ? AND status = 'completed'
  ");
  $stmt->bind_param("i", $_SESSION['user_id']);
  $stmt->execute();
  $result = $stmt->get_result()->fetch_assoc();
  $userRentalCount = (int)$result['rental_count'];
  $userTotalSpent = (float)$result['total_spent'];
  $stmt->close();
  
  // Get user data from users table
  $stmt = $conn->prepare("SELECT role, loyalty_points FROM users WHERE id = ?");
  $stmt->bind_param("i", $_SESSION['user_id']);
  $stmt->execute();
  $user = $stmt->get_result()->fetch_assoc();
  if ($user) {
    // Use loyalty points for additional discounts
    $userLoyaltyPoints = (int)($user['loyalty_points'] ?? 0);
    $userClassification = 'Personal'; // Default classification
    $userBusinessDiscount = 0; // No business discount system implemented yet
  }
  $stmt->close();
}

// Calculate real loyalty tier based on actual system data
$loyaltyTier = 'New';
$loyaltyDiscount = 0;
if ($userRentalCount >= 10 || $userTotalSpent >= 50000) {
  $loyaltyTier = 'Gold';
  $loyaltyDiscount = 15;
} elseif ($userRentalCount >= 5 || $userTotalSpent >= 25000) {
  $loyaltyTier = 'Silver';
  $loyaltyDiscount = 10;
} elseif ($userRentalCount >= 2 || $userTotalSpent >= 10000) {
  $loyaltyTier = 'Bronze';
  $loyaltyDiscount = 5;
}

// Get real long-term discounts from promotions table
$longTermPromotions = [];
$stmt = $conn->prepare("
  SELECT * FROM promotions 
  WHERE is_active = 1 
  AND promo_type = 'Long-Term'
  ORDER BY min_days ASC
");
$stmt->execute();
$longTermPromotions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Function to calculate real discounted price using system logic
function calculateRealDiscountedPrice($originalPrice, $rentalDays, $customerId, $vehicleId, $rateType = 'CDO') {
  global $conn;
  
  try {
    // Use the actual promotional calculator
    require_once __DIR__ . '/includes/promo_calculator.php';
    
    $promoData = calculatePromotionalRate($customerId, $vehicleId, $rentalDays, $rateType, $originalPrice);
    
    return [
      'discounted_price' => $promoData['applied_rate'],
      'discount_percent' => $promoData['discount_percent'],
      'promo_applied' => $promoData['promo_applied'],
      'total_savings' => $promoData['promo_discount']
    ];
  } catch (Exception $e) {
    // Fallback if promotional calculator fails
    return [
      'discounted_price' => $originalPrice,
      'discount_percent' => 0,
      'promo_applied' => null,
      'total_savings' => 0
    ];
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>FleetGo — Rates & Offers</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&display=swap" rel="stylesheet">
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
body{font-family:Inter,system-ui,sans-serif;background:var(--bg);color:var(--text-primary);line-height:1.6;}
a{text-decoration:none;color:inherit}

/* ===== NAVBAR ===== */
.header{position:sticky;top:0;z-index:10;background:linear-gradient(180deg,rgba(16,20,25,.96),rgba(16,20,25,.9));backdrop-filter:blur(8px);}
.nav{max-width:1220px;margin:0 auto;padding:14px 18px;display:flex;align-items:center;justify-content:space-between;gap:16px}
.brand{display:flex;align-items:center;gap:.7rem;color:#e6f2fb;font-weight:900;}
.brand .mark{width:36px;height:36px;border-radius:10px;display:grid;place-items:center;background:linear-gradient(135deg,var(--brand),var(--brand-2));color:#06202c;font-weight:900}
.navlinks{display:flex;gap:1.2rem;align-items:center}
.navlinks a{color:#cfe6f6;font-weight:700;font-size:.95rem;opacity:.9;transition:.25s}
.navlinks a:hover{opacity:1;text-shadow:0 0 10px rgba(93,208,255,.4)}
.btn-cta{background:linear-gradient(135deg,var(--brand),var(--brand-2));color:#041f2a;font-weight:800;border:0;border-radius:8px;padding:.55rem .9rem;box-shadow:0 5px 14px rgba(93,208,255,.25);transition:.18s;}
.btn-cta:hover{transform:translateY(-2px);box-shadow:0 10px 20px rgba(93,208,255,.35)}

/* ===== HERO ===== */
.hero{
  position:relative;text-align:center;color:var(--text-primary);overflow:hidden;
  background:linear-gradient(135deg,rgba(11,13,16,.95),rgba(16,20,25,.9));
  padding:100px 0;min-height:60vh;display:flex;align-items:center;
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
.hero-inner{
  padding:0 18px;max-width:920px;margin:auto;position:relative;z-index:2;
}
.hero h1{
  font-size:clamp(2.5rem,6vw,4.5rem);font-weight:900;margin-bottom:20px;
  text-shadow:0 4px 20px rgba(0,0,0,.3);
  animation:fadeInUp 1s ease-out;
}
.hero h1 .accent{
  background:var(--gradient);
  -webkit-background-clip:text;-webkit-text-fill-color:transparent;
  background-clip:text;display:inline-block;
  animation:pulse 2s ease-in-out infinite;
}
@keyframes pulse{
  0%,100%{transform:scale(1)}
  50%{transform:scale(1.05)}
}
.hero p{
  font-size:1.2rem;color:var(--text-secondary);max-width:700px;margin:0 auto 32px;
  line-height:1.7;text-shadow:0 2px 10px rgba(0,0,0,.4);
  animation:fadeInUp 1s ease-out 0.3s both;
}
@keyframes fadeInUp{
  from{opacity:0;transform:translateY(30px)}
  to{opacity:1;transform:translateY(0)}
}

/* ===== FLEET OVERVIEW ===== */
.fleet-overview{
  display:flex;gap:40px;justify-content:center;margin:40px 0;flex-wrap:wrap;
  background:rgba(255,255,255,.1);padding:20px;border-radius:12px;
  backdrop-filter:blur(10px);border:1px solid rgba(255,255,255,.2);
}
.overview-item{
  text-align:center;padding:16px 24px;background:rgba(255,255,255,.1);
  border-radius:8px;border:1px solid rgba(255,255,255,.2);
  transition:all .3s ease;min-width:120px;
}
.overview-item:hover{
  background:rgba(255,255,255,.2);transform:translateY(-2px);
}
.overview-number{
  font-size:2rem;font-weight:900;color:#fff;display:block;margin-bottom:4px;
  text-shadow:0 2px 4px rgba(0,0,0,.3);
}
.overview-label{
  color:rgba(255,255,255,.8);font-size:.85rem;font-weight:600;
  text-transform:uppercase;letter-spacing:.5px;
}

/* filter tabs removed */

/* ===== PRICING ===== */
.section{max-width:1280px;margin:0 auto;padding:60px 24px}
.section h2{
  text-align:center;font-size:2.8rem;font-weight:900;margin-bottom:20px;
  background:var(--gradient);
  -webkit-background-clip:text;-webkit-text-fill-color:transparent;
  letter-spacing:-1px;
  color:var(--text-primary);
}
.section-subtitle{
  text-align:center;color:var(--text-secondary);font-size:1.15rem;
  margin-bottom:40px;line-height:1.7;
}

/* ===== TYPE INDEX (Sticky) ===== */
.type-index{position:sticky;top:64px;z-index:5;background:var(--card);
  border:2px solid var(--border);border-radius:var(--radius-sm);padding:10px;
  display:flex;gap:10px;flex-wrap:wrap;justify-content:center;
  box-shadow:var(--shadow);}
.type-link{padding:10px 16px;border:2px solid var(--border);border-radius:var(--radius-sm);
  color:var(--text-primary);font-weight:700;transition:.25s;background:var(--card);}
.type-link:hover{border-color:var(--brand);color:var(--brand);transform:translateY(-1px)}

/* ===== RATES TABLE ===== */
.rates-section{margin-top:40px}
.rates-section h3{font-size:1.6rem;margin:12px 0 12px;font-weight:900;color:var(--text-primary)}
.rates-wrap{background:var(--card);border:2px solid var(--border);
  border-radius:var(--radius-sm);overflow:hidden;box-shadow:var(--shadow)}
.rates-table{width:100%;border-collapse:collapse}
.rates-table thead th{background:linear-gradient(90deg,rgba(93,208,255,.12),rgba(124,255,199,.12));
  color:var(--text-primary);text-align:left;font-weight:900;padding:14px}
.rates-table tbody tr{border-top:1px solid var(--border)}
.rates-table tbody tr:nth-child(odd){background:var(--card-hover)}
.rates-table td{padding:14px;color:var(--text-primary);font-weight:600}
.thumb-cell{display:flex;align-items:center;gap:12px}
.thumb-cell img{width:72px;height:48px;object-fit:cover;border-radius:var(--radius-sm);box-shadow:var(--shadow);border:1px solid var(--border)}
.thumb-title{font-weight:900;color:var(--text-primary)}
.rate-pill{font-weight:900;color:#041b22;background:var(--gradient);
  padding:6px 12px;border-radius:999px;display:inline-block}

/* ===== PAGINATION ===== */
.pagination{
  display:flex;justify-content:center;align-items:center;
  gap:12px;margin-top:50px;
}
.page-btn{
  padding:12px 20px;border:2px solid var(--border);border-radius:var(--radius-sm);
  background:var(--card);font-weight:700;cursor:pointer;
  transition:all .3s ease;font-size:.95rem;color:var(--text-primary);
  min-width:48px;text-align:center;
}
.page-btn:hover:not(:disabled){
  border-color:var(--brand);background:rgba(93,208,255,.08);
  color:var(--brand);transform:translateY(-2px);
}
.page-btn.active{
  background:var(--gradient);color:#041b22;border-color:var(--brand);
  box-shadow:var(--shadow);
}
.page-btn:disabled{
  opacity:.4;cursor:not-allowed;
}
.page-info{
  color:var(--text-secondary);font-weight:600;font-size:.95rem;
}

/* ===== LOADING STATE ===== */
.loading{
  text-align:center;padding:60px 20px;color:var(--text-secondary);
}
.loading::after{
  content:"";display:inline-block;width:40px;height:40px;
  border:4px solid var(--border);border-top-color:var(--brand);
  border-radius:50%;animation:spin 1s linear infinite;
  margin-left:12px;vertical-align:middle;
}
@keyframes spin{
  to{transform:rotate(360deg)}
}

/* ===== EMPTY STATE ===== */
.empty-state{
  text-align:center;padding:80px 20px;
}
.empty-state h3{
  font-size:1.5rem;color:var(--text-secondary);margin-bottom:12px;
}
.empty-state p{
  color:var(--text-muted);font-size:1rem;
}

/* ===== ENHANCED MODAL ===== */
.modal{
  position:fixed;inset:0;background:rgba(0,0,0,.7);
  display:none;align-items:center;justify-content:center;z-index:200;
  backdrop-filter:blur(8px);
}
.modal.show{display:flex;animation:fadeIn 0.3s ease;}
.modal-content{
  background:var(--card);
  color:var(--text-primary);padding:2rem;border-radius:var(--radius);
  max-width:500px;width:90%;box-shadow:var(--shadow-xl);
  border:2px solid var(--border);
  position:relative;animation:slideUp 0.3s ease;
}
@keyframes slideUp{
  from{transform:translateY(30px);opacity:0}
  to{transform:translateY(0);opacity:1}
}
.modal-content img{
  width:100%;border-radius:var(--radius-sm);margin-bottom:1.2rem;
  box-shadow:var(--shadow);
}
.modal-content h3{
  margin:0 0 .8rem;font-size:1.6rem;font-weight:900;
  background:var(--gradient);
  -webkit-background-clip:text;-webkit-text-fill-color:transparent;
}
.modal-content p{
  font-size:1rem;color:var(--text-secondary);margin:.4rem 0;padding:8px 0;
  border-bottom:1px solid var(--border);
}
.modal-content p:last-child{border-bottom:none}
.close-btn{
  background:var(--gradient);
  color:#041b22;border:0;border-radius:50%;width:36px;height:36px;
  font-weight:900;cursor:pointer;position:absolute;top:20px;right:20px;
  font-size:1.2rem;transition:all .3s ease;
}
.close-btn:hover{
  transform:scale(1.1);box-shadow:0 4px 12px rgba(93,208,255,.4);
}
.modal-footer{
  margin-top:1.5rem;text-align:center;
}
.modal-footer a{
  display:inline-block;
  background:var(--gradient);
  color:#041b22;padding:.8rem 1.5rem;border-radius:var(--radius-sm);
  text-decoration:none;font-weight:800;font-size:1rem;
  transition:all .3s ease;box-shadow:var(--shadow);
}
.modal-footer a:hover{
  transform:translateY(-2px);box-shadow:0 12px 25px rgba(93,208,255,.4);
}

/* ===== LOADING ANIMATIONS ===== */
@keyframes fadeIn{
  from{opacity:0}
  to{opacity:1}
}
@keyframes fadeInUp{
  from{opacity:0;transform:translateY(30px)}
  to{opacity:1;transform:translateY(0)}
}

/* ===== PROMOTIONAL SECTIONS ===== */
.promotions-section{
  background:linear-gradient(135deg,rgba(93,208,255,.05),rgba(124,255,199,.05));
  border:2px solid rgba(93,208,255,.2);border-radius:var(--radius);padding:40px 24px;
  margin:40px 0;position:relative;overflow:hidden;
}
.promotions-section::before{
  content:'';position:absolute;top:0;left:0;right:0;height:4px;
  background:var(--gradient);border-radius:var(--radius) var(--radius) 0 0;
}
.promotions-header{
  text-align:center;margin-bottom:32px;
}
.promotions-header h2{
  font-size:2.5rem;font-weight:900;margin-bottom:12px;
  background:var(--gradient);
  -webkit-background-clip:text;-webkit-text-fill-color:transparent;
}
.promotions-header p{
  color:var(--text-secondary);font-size:1.1rem;max-width:600px;margin:0 auto;
}

/* ===== PROMO CARDS ===== */
.promo-grid{
  display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:24px;
  margin:32px 0;
}
.promo-card{
  background:var(--card);border:2px solid var(--border);border-radius:var(--radius);
  padding:24px;position:relative;transition:all .3s ease;overflow:hidden;
}
.promo-card:hover{
  transform:translateY(-4px);box-shadow:var(--shadow-xl);
  border-color:var(--brand);
}
.promo-card::before{
  content:'';position:absolute;top:0;left:0;right:0;height:3px;
  background:var(--gradient);opacity:0;transition:opacity .3s;
}
.promo-card:hover::before{opacity:1;}

.promo-card.active{
  border-color:var(--brand);box-shadow:0 0 20px rgba(93,208,255,.3);
}
.promo-card.active::before{opacity:1;}

.promo-card.upcoming{
  opacity:.8;border-style:dashed;
}
.promo-card.expired{
  opacity:.5;filter:grayscale(.3);
}

.promo-header{
  display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px;
}
.promo-title{
  font-size:1.4rem;font-weight:900;color:var(--text-primary);margin:0;
}
.promo-badge{
  padding:6px 12px;border-radius:20px;font-weight:700;font-size:.8rem;
  text-transform:uppercase;letter-spacing:.5px;
}
.promo-badge.active{
  background:linear-gradient(135deg,var(--brand),var(--brand2));color:#041b22;
}
.promo-badge.upcoming{
  background:rgba(255,209,102,.2);color:#ffd166;border:1px solid rgba(255,209,102,.4);
}
.promo-badge.expired{
  background:rgba(107,114,128,.2);color:#6b7280;border:1px solid rgba(107,114,128,.4);
}

.promo-discount{
  font-size:2.5rem;font-weight:900;color:var(--brand2);margin:8px 0;
  text-shadow:0 2px 4px rgba(0,0,0,.2);
}
.promo-conditions{
  color:var(--text-secondary);font-size:.9rem;margin:8px 0;
  display:flex;align-items:center;gap:8px;
}
.promo-conditions::before{
  content:'📋';font-size:1rem;
}
.promo-validity{
  color:var(--text-muted);font-size:.85rem;margin:8px 0;
  display:flex;align-items:center;gap:8px;
}
.promo-validity::before{
  content:'📅';font-size:.9rem;
}
.promo-ends-soon{
  background:linear-gradient(135deg,#ff6b6b,#ff8e8e);color:#fff;
  padding:4px 8px;border-radius:12px;font-size:.75rem;font-weight:700;
  margin-left:8px;animation:pulse 2s infinite;
}
@keyframes pulse{
  0%,100%{opacity:1}
  50%{opacity:.7}
}

/* ===== MARKETING COPY ===== */
.marketing-banner{
  background:linear-gradient(135deg,rgba(93,208,255,.1),rgba(124,255,199,.1));
  border:2px solid rgba(93,208,255,.3);border-radius:var(--radius);
  padding:32px;text-align:center;margin:40px 0;position:relative;
  overflow:hidden;
}
.marketing-banner::before{
  content:'';position:absolute;top:-50%;left:-50%;width:200%;height:200%;
  background:radial-gradient(circle,rgba(93,208,255,.1),transparent);
  animation:rotate 20s linear infinite;
}
@keyframes rotate{
  from{transform:rotate(0deg)}
  to{transform:rotate(360deg)}
}
.marketing-banner h3{
  font-size:2rem;font-weight:900;margin-bottom:16px;
  background:var(--gradient);
  -webkit-background-clip:text;-webkit-text-fill-color:transparent;
}
.marketing-banner p{
  color:var(--text-secondary);font-size:1.1rem;max-width:800px;margin:0 auto;
}

/* ===== FILTER TABS ===== */
.promo-tabs{
  display:flex;gap:12px;justify-content:center;margin:32px 0;flex-wrap:wrap;
}
.promo-tab{
  padding:12px 24px;border:2px solid var(--border);border-radius:var(--radius-sm);
  background:var(--card);color:var(--text-primary);font-weight:700;
  cursor:pointer;transition:all .3s ease;position:relative;
}
.promo-tab:hover{
  border-color:var(--brand);color:var(--brand);transform:translateY(-2px);
}
.promo-tab.active{
  background:var(--gradient);color:#041b22;border-color:var(--brand);
  box-shadow:var(--shadow);
}

/* ===== DISCOUNT FEATURES ===== */
.discount-banner{
  background:linear-gradient(135deg,#ff6b6b,#ff8e8e,#ffa8a8);
  color:#fff;padding:20px;border-radius:var(--radius);margin:20px 0;
  text-align:center;position:relative;overflow:hidden;
  box-shadow:0 8px 32px rgba(255,107,107,.3);
  animation:pulse 3s ease-in-out infinite;
}
.discount-banner::before{
  content:'';position:absolute;top:0;left:-100%;width:100%;height:100%;
  background:linear-gradient(90deg,transparent,rgba(255,255,255,.2),transparent);
  animation:shimmer 2s infinite;
}
@keyframes shimmer{0%{left:-100%}100%{left:100%}}
.discount-banner h3{
  font-size:1.8rem;font-weight:900;margin-bottom:8px;text-shadow:0 2px 4px rgba(0,0,0,.3);
}
.discount-banner p{
  font-size:1.1rem;opacity:.95;margin:0;
}

.loyalty-section{
  background:linear-gradient(135deg,rgba(255,215,0,.1),rgba(255,193,7,.1));
  border:2px solid rgba(255,215,0,.3);border-radius:var(--radius);
  padding:24px;margin:24px 0;position:relative;
}
.loyalty-section::before{
  content:'';position:absolute;top:0;left:0;right:0;height:4px;
  background:linear-gradient(90deg,#ffd700,#ffed4e,#ffd700);
  border-radius:var(--radius) var(--radius) 0 0;
}
.loyalty-header{
  display:flex;align-items:center;gap:16px;margin-bottom:16px;
}
.loyalty-icon{
  width:48px;height:48px;border-radius:50%;display:flex;align-items:center;justify-content:center;
  font-size:1.5rem;font-weight:900;
}
.loyalty-icon.bronze{background:linear-gradient(135deg,#cd7f32,#daa520);color:#fff;}
.loyalty-icon.silver{background:linear-gradient(135deg,#c0c0c0,#e5e5e5);color:#333;}
.loyalty-icon.gold{background:linear-gradient(135deg,#ffd700,#ffed4e);color:#333;}
.loyalty-info h3{
  margin:0;font-size:1.4rem;font-weight:900;color:var(--text-primary);
}
.loyalty-info p{
  margin:4px 0 0;color:var(--text-secondary);font-size:.9rem;
}
.loyalty-benefits{
  display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-top:16px;
}
.loyalty-benefit{
  background:rgba(255,255,255,.05);padding:12px;border-radius:var(--radius-sm);
  border:1px solid rgba(255,255,255,.1);text-align:center;
}
.loyalty-benefit .benefit-icon{
  font-size:1.5rem;margin-bottom:8px;display:block;
}
.loyalty-benefit .benefit-title{
  font-weight:700;color:var(--text-primary);margin-bottom:4px;
}
.loyalty-benefit .benefit-desc{
  font-size:.8rem;color:var(--text-secondary);
}

.long-term-section{
  background:linear-gradient(135deg,rgba(93,208,255,.1),rgba(124,255,199,.1));
  border:2px solid rgba(93,208,255,.3);border-radius:var(--radius);
  padding:24px;margin:24px 0;
}
.long-term-grid{
  display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:16px;margin-top:16px;
}
.long-term-card{
  background:var(--card);border:2px solid var(--border);border-radius:var(--radius-sm);
  padding:16px;text-align:center;transition:all .3s ease;position:relative;
}
.long-term-card:hover{
  transform:translateY(-4px);box-shadow:var(--shadow-lg);border-color:var(--brand);
}
.long-term-card .days{
  font-size:1.8rem;font-weight:900;color:var(--brand);margin-bottom:4px;
}
.long-term-card .label{
  font-size:.9rem;color:var(--text-secondary);margin-bottom:8px;
}
.long-term-card .discount{
  font-size:1.2rem;font-weight:900;color:var(--brand2);
  background:rgba(124,255,199,.2);padding:4px 8px;border-radius:12px;
}

.special-offers{
  display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:16px;margin:20px 0;
}
.special-offer{
  background:var(--card);border:2px solid var(--border);border-radius:var(--radius);
  padding:20px;position:relative;overflow:hidden;transition:all .3s ease;
}
.special-offer:hover{
  transform:translateY(-2px);box-shadow:var(--shadow-lg);border-color:var(--brand);
}
.special-offer::before{
  content:'';position:absolute;top:0;left:0;right:0;height:3px;
  background:var(--gradient);opacity:0;transition:opacity .3s;
}
.special-offer:hover::before{opacity:1;}
.special-offer .offer-badge{
  position:absolute;top:12px;right:12px;background:var(--gradient);
  color:#041b22;padding:4px 8px;border-radius:12px;font-size:.7rem;
  font-weight:900;text-transform:uppercase;
}
.special-offer h4{
  margin:0 0 8px;font-size:1.2rem;font-weight:900;color:var(--text-primary);
}
.special-offer p{
  margin:0;color:var(--text-secondary);font-size:.9rem;
}

.price-comparison{
  background:var(--card);border:2px solid var(--border);border-radius:var(--radius);
  padding:20px;margin:20px 0;
}
.price-comparison h4{
  margin:0 0 16px;font-size:1.3rem;font-weight:900;color:var(--text-primary);
  text-align:center;
}
.comparison-grid{
  display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:12px;
}
.comparison-item{
  text-align:center;padding:12px;background:var(--card-hover);
  border-radius:var(--radius-sm);border:1px solid var(--border);
}
.comparison-item .days{
  font-size:1.1rem;font-weight:700;color:var(--text-primary);margin-bottom:4px;
}
.comparison-item .original{
  font-size:.9rem;color:var(--text-muted);text-decoration:line-through;margin-bottom:2px;
}
.comparison-item .discounted{
  font-size:1rem;font-weight:900;color:var(--brand2);
}


/* ===== FOOTER ===== */
.footer{background:var(--bg);color:var(--text-primary);padding:24px 0;margin-top:60px;text-align:center;border-top:2px solid var(--brand)}
.footer a{color:var(--brand2);text-decoration:none;font-weight:700;margin:0 6px}
.footer a:hover{text-decoration:underline}
</style>
</head>
<body>

<?php include __DIR__.'/includes/user_navbar.php'; ?>
<!-- ===== HERO ===== -->
<section class="hero">
  <div class="hero-inner">
    <h1>FleetGo <span class="accent">Rental Rates</span></h1>
    <p>Discover our premium fleet with transparent pricing, real-time availability, and instant booking</p>
    
    <!-- filter tabs removed -->
  </div>
</section>

<!-- ===== MARKETING BANNER ===== -->
<section class="marketing-banner">
  <h3>The longer you rent, the more you save!</h3>
  <p>🌟 Exclusive holiday promos now active — don't miss out! 💚 Earn loyalty discounts with every trip!</p>
</section>

<!-- ===== ACTIVE PROMOTIONS BANNER ===== -->
<?php if (!empty($active_promotions)): ?>
<div class="discount-banner">
  <h3>🎉 Active Promotions!</h3>
  <p><?= h($active_promotions[0]['promo_name']) ?> - Save up to <?= h($active_promotions[0]['discount_percent']) ?>% on your next rental!</p>
</div>
<?php endif; ?>

<!-- ===== LOYALTY PROGRAM ===== -->
<?php if ($isLoggedIn): ?>
<section class="section">
  <div class="loyalty-section">
    <div class="loyalty-header">
      <div class="loyalty-icon <?= strtolower($loyaltyTier) ?>">
        <?= $loyaltyTier === 'Gold' ? '👑' : ($loyaltyTier === 'Silver' ? '🥈' : '🥉') ?>
      </div>
      <div class="loyalty-info">
        <h3><?= $loyaltyTier ?> Member - <?= $userName ?></h3>
        <p><?= $userRentalCount ?> rentals • ₱<?= number_format($userTotalSpent, 0) ?> total spent</p>
      </div>
    </div>
    
    <div class="loyalty-benefits">
      <div class="loyalty-benefit">
        <span class="benefit-icon">💰</span>
        <div class="benefit-title"><?= $loyaltyDiscount ?>% Member Discount</div>
        <div class="benefit-desc">Applied automatically to all rentals</div>
      </div>
      <div class="loyalty-benefit">
        <span class="benefit-icon">⚡</span>
        <div class="benefit-title">Priority Booking</div>
        <div class="benefit-desc">Get first access to new vehicles</div>
      </div>
      <div class="loyalty-benefit">
        <span class="benefit-icon">🎁</span>
        <div class="benefit-title">Exclusive Offers</div>
        <div class="benefit-desc">Special promotions just for you</div>
      </div>
      <div class="loyalty-benefit">
        <span class="benefit-icon">📞</span>
        <div class="benefit-title">VIP Support</div>
        <div class="benefit-desc">Dedicated customer service line</div>
      </div>
    </div>
  </div>
</section>
<?php else: ?>
<section class="section">
  <div class="loyalty-section">
    <div class="loyalty-header">
      <div class="loyalty-icon bronze">👤</div>
      <div class="loyalty-info">
        <h3>Join Our Loyalty Program</h3>
        <p>Sign up today and start earning rewards with every rental!</p>
      </div>
    </div>
    
    <div class="loyalty-benefits">
      <div class="loyalty-benefit">
        <span class="benefit-icon">🥉</span>
        <div class="benefit-title">Bronze (2+ rentals)</div>
        <div class="benefit-desc">5% discount on all rentals</div>
      </div>
      <div class="loyalty-benefit">
        <span class="benefit-icon">🥈</span>
        <div class="benefit-title">Silver (5+ rentals)</div>
        <div class="benefit-desc">10% discount + priority booking</div>
      </div>
      <div class="loyalty-benefit">
        <span class="benefit-icon">👑</span>
        <div class="benefit-title">Gold (10+ rentals)</div>
        <div class="benefit-desc">15% discount + VIP benefits</div>
      </div>
    </div>
    
    <div style="text-align:center;margin-top:20px;">
      <a href="login.php" class="btn-cta" style="display:inline-block;padding:12px 24px;">Sign Up Now</a>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- ===== LONG-TERM RENTAL DISCOUNTS ===== -->
<?php if (!empty($longTermPromotions)): ?>
<section class="section">
  <div class="long-term-section">
    <h2 style="text-align:center;margin-bottom:16px;">📅 Long-Term Rental Discounts</h2>
    <p style="text-align:center;color:var(--text-secondary);margin-bottom:24px;">
      The longer you rent, the more you save! Perfect for extended trips and business needs.
    </p>
    
    <div class="long-term-grid">
      <?php foreach($longTermPromotions as $promo): ?>
      <div class="long-term-card">
        <div class="days"><?= h($promo['min_days']) ?>+ Days</div>
        <div class="label"><?= h($promo['promo_name']) ?></div>
        <div class="discount"><?= h($promo['discount_percent']) ?>% OFF</div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- ===== SPECIAL OFFERS ===== -->
<?php if (!empty($active_promotions) && count($active_promotions) > 1): ?>
<section class="section">
  <h2 style="text-align:center;margin-bottom:16px;">🎯 Current Special Offers</h2>
  <p style="text-align:center;color:var(--text-secondary);margin-bottom:24px;">
    Active promotions from our database
  </p>
  
  <div class="special-offers">
    <?php foreach($active_promotions as $promo): ?>
    <div class="special-offer">
      <div class="offer-badge"><?= h($promo['promo_type']) ?></div>
      <h4><?= h($promo['promo_name']) ?></h4>
      <p>Minimum <?= h($promo['min_days']) ?> days rental</p>
      <div style="margin-top:12px;font-size:1.5rem;font-weight:900;color:var(--brand2);">
        Save <?= h($promo['discount_percent']) ?>%
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>


<!-- ===== PROMOTIONAL SECTIONS ===== -->
<?php if (!empty($active_promotions) || !empty($upcoming_promotions)): ?>
<section class="section">
  <div class="promotions-section">
    <div class="promotions-header">
      <h2>🎁 Enticing Promotions</h2>
      <p>Discover our exclusive offers and save big on your next rental adventure</p>
    </div>
    
           <!-- Filter Tabs -->
           <div class="promo-tabs">
             <div class="promo-tab active" data-filter="active">🔹 Active Promos</div>
           </div>
    
           <!-- Active Promotions -->
           <div class="promo-content" id="active-promos">
             <?php if (!empty($active_promotions)): ?>
               <div class="promo-grid">
                 <?php foreach ($active_promotions as $promo): ?>
                 <div class="promo-card active">
                   <div class="promo-header">
                     <h3 class="promo-title"><?= h($promo['promo_name']) ?></h3>
                     <span class="promo-badge active">Active</span>
                   </div>
                   <div class="promo-discount"><?= h($promo['discount_percent']) ?>% OFF</div>
                   <div class="promo-conditions">
                     Applies to rentals ≥ <?= h($promo['min_days']) ?> days
                     <?php if ($promo['min_bookings'] > 0): ?>
                       • <?= h($promo['min_bookings']) ?>+ previous bookings required
                     <?php endif; ?>
                   </div>
                   <div class="promo-validity">
                     <?= h($promo['promo_type']) ?> promotion
                   </div>
                 </div>
                 <?php endforeach; ?>
               </div>
             <?php else: ?>
               <div style="text-align:center;padding:40px;color:var(--text-secondary);">
                 <h3>No active promotions</h3>
                 <p>Check back soon for exciting offers!</p>
               </div>
             <?php endif; ?>
           </div>
    
    <!-- Note: Upcoming and Expired sections removed since promotions table doesn't have date fields -->
    
    <div style="text-align:center;margin-top:32px;padding:20px;background:rgba(93,208,255,.1);border-radius:var(--radius-sm);">
      <p style="color:var(--text-secondary);font-weight:600;">
        <strong>Note:</strong> Active promotions apply automatically during booking when conditions are met.
      </p>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- ===== VEHICLE RATES ===== -->
<section class="section">
  <h2>Our Fleet Rates</h2>
  <p class="section-subtitle">Transparent pricing for all vehicle types • No hidden fees • Book with confidence</p>

  <!-- ===== TYPE INDEX ===== -->
  <?php $types = array_keys($vehicles); if ($types): ?>
  <nav class="type-index">
    <?php foreach($types as $type): $id = 'type-'.slug($type); ?>
      <a class="type-link" href="#<?= h($id) ?>"><?= h($type) ?></a>
    <?php endforeach; ?>
  </nav>
  <?php endif; ?>

  <!-- ===== RATE TABLES ===== -->
  <?php if(empty($vehicles)): ?>
    <div class="empty-state">
      <h3>No vehicles available</h3>
      <p>Check back soon for our latest fleet additions</p>
    </div>
  <?php else: ?>
    <?php foreach($vehicles as $type => $group): $id = 'type-'.slug($type); $dataType = slug($type); ?>
      <div class="rates-section" id="<?= h($id) ?>" data-type="<?= h($dataType) ?>">
        <h3><?= h($type) ?></h3>
        <div class="rates-wrap">
          <table class="rates-table" aria-label="<?= h($type) ?> rates">
            <thead>
              <tr>
                <th style="width:44%">Vehicle</th>
                <th style="width:14%">Transmission</th>
                <th style="width:14%">Comfort</th>
                <th style="width:10%">Year</th>
                <th style="width:18%">Daily Rate</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach($group as $v): 
              $originalRate = (float)$v['daily_rate'];
              $rate = number_format($originalRate, 2); 
              $photo = $v['photo'] ? (VEH_IMG_URL . '/' . $v['photo']) : 'assets/vehicles/default.jpg';
              
              // Calculate real discounts using system logic
              $discountData = null;
              $effectiveDiscount = 0;
              $discountedRate = $originalRate;
              
              if ($isLoggedIn) {
                // Use real promotional calculator for 7-day rental (example)
                $discountData = calculateRealDiscountedPrice($originalRate, 7, $_SESSION['user_id'], $v['id']);
                $discountedRate = $discountData['discounted_price'];
                $effectiveDiscount = $discountData['discount_percent'];
              }
            ?>
              <tr data-vehicle-row>
                <td>
                  <div class="thumb-cell">
                    <img src="<?= h($photo) ?>" alt="<?= h($v['make_model']) ?>">
                    <span class="thumb-title"><?= h($v['make_model']) ?></span>
                  </div>
                </td>
                <td><?= h($v['transmission']) ?></td>
                <td><?= h(formatComfort($v['comfort_level'])) ?></td>
                <td><?= h($v['year']) ?></td>
                <td>
                  <?php if ($effectiveDiscount > 0 && $discountData): ?>
                    <div style="text-align:center;">
                      <div style="font-size:.8rem;color:var(--text-muted);text-decoration:line-through;margin-bottom:2px;">
                        ₱<?= $rate ?>/day
                      </div>
                      <span class="rate-pill" style="background:linear-gradient(135deg,var(--brand2),var(--success));">
                        ₱<?= number_format($discountedRate, 2) ?>/day
                      </span>
                      <div style="font-size:.7rem;color:var(--brand2);font-weight:700;margin-top:2px;">
                        Save <?= $effectiveDiscount ?>% (<?= h($discountData['promo_applied']) ?>)
                      </div>
                    </div>
                  <?php else: ?>
                    <span class="rate-pill">₱<?= $rate ?>/day</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
    </div>
  <?php endforeach; ?>
  <?php endif; ?>

  <!-- pagination removed -->
</section>

<!-- modal removed -->

<footer class="footer">
  © <?= date('Y') ?> FleetGo Rentals • 
  <a href="#">Terms</a> • 
  <a href="#">Privacy</a> • 
  <a href="#">Contact</a>
</footer>

<script>
// ===== ENHANCED PRICING PAGE (Tables, no counters) =====
document.addEventListener('DOMContentLoaded', function() {
  // no animated counters needed anymore
  
  // Type index links handled by smooth scrolling below
  
  // removed card interactions and modal
  
  // removed modal handlers
  
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
  document.querySelectorAll('.section').forEach(section => {
    observer.observe(section);
  });
  
  // ===== INITIALIZE =====
  // ===== PARALLAX EFFECT =====
  window.addEventListener('scroll', function() {
    const scrolled = window.pageYOffset;
    const hero = document.querySelector('.hero');
    if (hero) {
      hero.style.transform = `translateY(${scrolled * 0.5}px)`;
    }
  });
});

// ===== PROMO FILTER TABS =====
document.querySelectorAll('.promo-tab').forEach(tab => {
  tab.addEventListener('click', function() {
    // Remove active class from all tabs
    document.querySelectorAll('.promo-tab').forEach(t => t.classList.remove('active'));
    // Add active class to clicked tab
    this.classList.add('active');
    
    // Hide all promo content
    document.querySelectorAll('.promo-content').forEach(content => {
      content.style.display = 'none';
    });
    
    // Show selected content
    const filter = this.dataset.filter;
    const targetContent = document.getElementById(filter + '-promos');
    if (targetContent) {
      targetContent.style.display = 'block';
    }
  });
});

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

</script>

</body>
</html>
<?php $conn->close(); ?>
