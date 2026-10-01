<?php
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$DB_HOST="127.0.0.1"; $DB_USER="root"; $DB_PASS=""; $DB_NAME="fleet_rental_db";
$conn=new mysqli($DB_HOST,$DB_USER,$DB_PASS,$DB_NAME);
$conn->set_charset("utf8mb4");

function h($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}

// Handle AJAX Rental History
if(isset($_GET['ajax']) && $_GET['ajax']==='rentals' && isset($_GET['id'])){
    $user_id=(int)$_GET['id'];
    
    try {
        $stmt=$conn->prepare("
            SELECT 
                r.*,
                v.make_model,
                v.plate_no
            FROM rentals r 
            LEFT JOIN vehicles v ON v.id = r.vehicle_id 
            WHERE r.customer_id = ? 
            ORDER BY r.start_date DESC
        ");
        $stmt->bind_param("i",$user_id);
        $stmt->execute();
        $rentals=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        header('Content-Type: application/json');
        echo json_encode(['success'=>true,'rentals'=>$rentals]);
        exit;
    } catch(Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['success'=>false,'message'=>'Rental history failed to load.']);
        exit;
    }
}

// Handle Admin Actions
$flash="";
if($_SERVER['REQUEST_METHOD']==='POST'){
    $id=(int)($_POST['id']??0);
    $action=$_POST['action']??'';
    
    // APPROVE USER
    if($action==='approve' && $id>0){
        $stmt=$conn->prepare("
            UPDATE users SET 
                verification_status='verified',
                profile_status='approved',
                verified_at=NOW(),
                rejection_reason=NULL
            WHERE id=?
        ");
        $stmt->bind_param("i",$id);
        $stmt->execute();
        $flash="✅ Customer approved successfully!";
    }
    
    // REJECT USER
    if($action==='reject' && $id>0){
        $reason=trim($_POST['reason']??'');
        $stmt=$conn->prepare("
            UPDATE users SET 
                verification_status='rejected',
                profile_status='rejected',
                rejection_reason=?
            WHERE id=?
        ");
        $stmt->bind_param("si",$reason,$id);
        $stmt->execute();
        $flash="❌ Customer rejected!";
    }
    
    // BLACKLIST USER
    if($action==='blacklist' && $id>0){
        $reason=trim($_POST['reason']??'');
        $stmt=$conn->prepare("
            UPDATE users SET 
                status='blacklisted',
                blacklist_reason=?
            WHERE id=?
        ");
        $stmt->bind_param("si",$reason,$id);
        $stmt->execute();
        $flash="🚫 Customer blacklisted!";
    }
    
    redirect:
    if($flash && !headers_sent()) {
        header("Location: customers_all.php?flash=".urlencode($flash));
        exit;
    }
}

// Get filters
$q=trim($_GET['q']??'');
$verification_status=trim($_GET['verification_status']??'');
$profile_status=trim($_GET['profile_status']??'');
$status=trim($_GET['status']??'');

// Build query
$where="WHERE u.role='user'";
$params=[]; $types='';

if($q!==''){
    $where.=" AND (u.full_name LIKE ? OR u.email LIKE ?)";
    $params[]=$q; $params[]=$q; $types.='ss';
}
if($verification_status!==''){
    $where.=" AND u.verification_status=?";
    $params[]=$verification_status; $types.='s';
}
if($profile_status!==''){
    $where.=" AND u.profile_status=?";
    $params[]=$profile_status; $types.='s';
}
if($status!==''){
    $where.=" AND u.status=?";
    $params[]=$status; $types.='s';
}

// Main query
$sql="
    SELECT 
        u.*,
        (SELECT COUNT(*) FROM rentals r WHERE r.customer_id = u.id) AS rentals_count,
        (SELECT MAX(r.start_date) FROM rentals r WHERE r.customer_id = u.id) AS last_rental,
        (SELECT GROUP_CONCAT(CONCAT(doc_type, ':', file_path) SEPARATOR '|') 
         FROM user_documents ud WHERE ud.user_id = u.id) AS documents
    FROM users u
    $where
    ORDER BY u.created_at DESC, u.full_name ASC
";

$stmt=$conn->prepare($sql);
if($types) $stmt->bind_param($types,...$params);
$stmt->execute();
$users=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Step 1: Verify admin is using correct DB connection and selected user id
error_log("=== ADMIN DOCUMENT FETCH DEBUG ===");

// Step 2: Fix SQL query - use proper ordering and prepared statements
// Fetch documents for each user
foreach($users as $key => $user) {
    error_log("Processing user ID: " . $user['id'] . " - Name: " . $user['full_name']);
    
    $docStmt = $conn->prepare("
        SELECT doc_type, file_path 
        FROM user_documents 
        WHERE user_id = ? 
        ORDER BY uploaded_at DESC
    ");
    $docStmt->bind_param("i", $user['id']);
    $docStmt->execute();
    $documents = $docStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $docStmt->close();
    
    // Debug: Log document fetching
    error_log("Found " . count($documents) . " documents for user ID: " . $user['id']);
    
    // Step 3: Normalize doc_type mapping and fix file paths
    $users[$key]['documents'] = [];
    foreach($documents as $doc) {
        // Step 4: Fix file_path - convert backslashes to forward slashes and ensure relative path
        $originalPath = $doc['file_path'];
        $fixedPath = str_replace('\\', '/', $originalPath);
        
        // Remove any leading slashes to ensure relative path
        $fixedPath = ltrim($fixedPath, '/');
        
        // Step 5: Log mapping for debugging
        error_log("Original path: '{$originalPath}' -> Fixed path: '{$fixedPath}'");
        
        $users[$key]['documents'][$doc['doc_type']] = $fixedPath;
    }
    
    // Debug: Verify documents were added to user array
    error_log("User {$user['id']} documents array: " . json_encode($users[$key]['documents']));
}

// Step 6: Add comprehensive diagnostic queries
error_log("=== COMPREHENSIVE DATABASE DIAGNOSTICS ===");

// Test 1: Check if user_documents table exists and has data
$tableCheck = $conn->query("SHOW TABLES LIKE 'user_documents'");
if ($tableCheck->num_rows === 0) {
    error_log("ERROR: user_documents table does not exist!");
} else {
    error_log("SUCCESS: user_documents table exists");
}

$totalDocs = $conn->query("SELECT COUNT(*) as total FROM user_documents");
$totalResult = $totalDocs->fetch_assoc();
error_log("Total documents in user_documents table: " . $totalResult['total']);

// Test 2: Check sample data from user_documents
$sampleDocs = $conn->query("SELECT * FROM user_documents LIMIT 3");
$sampleData = $sampleDocs->fetch_all(MYSQLI_ASSOC);
error_log("Sample user_documents data: " . json_encode($sampleData));

// Test 3: Check users table document columns
$usersWithDocs = $conn->query("SELECT id, full_name, profile_photo, license_photo, valid_id_photo FROM users LIMIT 3");
$usersData = $usersWithDocs->fetch_all(MYSQLI_ASSOC);
error_log("Sample users with document columns: " . json_encode($usersData));

// Test 4: Check specific user documents
if (!empty($users[0]['id'])) {
    $specificDocs = $conn->prepare("SELECT doc_type, file_path FROM user_documents WHERE user_id = ? ORDER BY uploaded_at DESC");
    $specificDocs->bind_param("i", $users[0]['id']);
    $specificDocs->execute();
    $specificData = $specificDocs->get_result()->fetch_all(MYSQLI_ASSOC);
    error_log("Specific user documents for ID {$users[0]['id']}: " . json_encode($specificData));
}

error_log("=== END DIAGNOSTICS ===");

// KPIs
$total_users = $conn->query("SELECT COUNT(*) FROM users WHERE role='user'")->fetch_row()[0];
$verified = $conn->query("SELECT COUNT(*) FROM users WHERE role='user' AND verification_status='verified'")->fetch_row()[0];
$pending = $conn->query("SELECT COUNT(*) FROM users WHERE role='user' AND verification_status='pending'")->fetch_row()[0];
$blacklisted = $conn->query("SELECT COUNT(*) FROM users WHERE role='user' AND status='blacklisted'")->fetch_row()[0];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Customer Verification • FleetGo Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--bg:#0b0d10;--card:#101419;--text:#f2f6fa;--muted:#9aa6b3;--brand:#5dd0ff;--brand2:#7cffc7;--ok:#7cffc7;--warn:#ffd166;--bad:#ff6b6b;--radius:12px;--radius-sm:10px;--shadow:0 10px 28px rgba(0,0,0,.45);--glass-bg:rgba(16,20,25,.92);--glass-border:rgba(255,255,255,.1);--border:rgba(255,255,255,.1);--text-secondary:#9aa6b3;--text-muted:#7a8794;--text-primary:#f2f6fa;--gradient:linear-gradient(135deg,#5dd0ff,#7cffc7);--nav-h:72px;}
body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,system-ui,sans-serif;letter-spacing:0.01em;}
body.modal-open{overflow:hidden;}
.container{max-width:1400px;margin:0 auto;padding:20px;}
.header{display:flex;justify-content:space-between;align-items:center;margin-bottom:30px;}
.header h1{font-size:2rem;font-weight:800;margin:0;}
.header p{color:var(--muted);margin:0;}
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:30px;}
.kpi-card{background:var(--glass-bg);border:1px solid var(--glass-border);border-radius:var(--radius);padding:20px;text-align:center;}
.kpi-value{font-size:2rem;font-weight:800;color:var(--brand);margin-bottom:4px;}
.kpi-label{color:var(--muted);font-size:.9rem;}
.filters{display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap;}
.filters input,.filters select{padding:10px 16px;border-radius:8px;border:1px solid var(--glass-border);background:rgba(13,17,22,.8);color:var(--text);font-size:.9rem;}
.table-container{background:var(--glass-bg);border:1px solid var(--glass-border);border-radius:var(--radius);overflow:hidden;}
.table{width:100%;border-collapse:collapse;}
.table th{background:rgba(93,208,255,.05);color:var(--text);font-weight:600;font-size:.85rem;text-transform:uppercase;letter-spacing:.5px;padding:12px 16px;text-align:left;}
.table td{padding:12px 16px;color:var(--text);font-size:.9rem;border-bottom:1px solid rgba(255,255,255,.05);}
.table tr:hover td{background:rgba(93,208,255,.05);}
.customer-info{display:flex;align-items:center;gap:12px;}
.avatar{width:40px;height:40px;border-radius:50%;background:var(--gradient);display:flex;align-items:center;justify-content:center;color:#041f2a;font-weight:800;font-size:.9rem;flex-shrink:0}
.document-status{display:flex;flex-direction:column;gap:2px;}
.document-status .doc-indicator{display:flex;align-items:center;gap:4px;font-size:0.8rem;}
.document-status .doc-icons{font-size:0.75rem;color:var(--muted);margin-top:2px;}
.document-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-bottom:16px;}
.document-item{background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:var(--radius-sm);padding:16px;}
.document-item.missing{background:rgba(255,255,255,.02);border:1px dashed var(--border);}
.document-header{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:10px;}
.document-summary{background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:var(--radius-sm);padding:16px;}
.profile-section{background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:14px;padding:18px 20px;}
.profile-section h4{margin:0 0 14px 0;padding-bottom:10px;border-bottom:1px solid var(--border);color:var(--brand);font-weight:700;font-size:1rem;display:flex;align-items:center;gap:8px;}
.profile-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px 20px;}
.profile-field{min-width:0;}
.profile-field.full{grid-column:1 / -1;}
.profile-field .k{display:block;color:var(--muted);font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px;}
.profile-field .v{display:block;color:var(--text);font-size:.95rem;font-weight:600;line-height:1.45;word-break:break-word;}
.document-sides{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:8px;}
.document-side{display:flex;flex-direction:column;min-width:0;}
.side-label{font-weight:600;color:var(--text-secondary);font-size:0.85rem;margin-bottom:4px;text-transform:uppercase;letter-spacing:.5px;}
.missing-side{padding:20px;border:2px dashed var(--border);border-radius:6px;text-align:center;color:var(--text-muted);background:rgba(255,255,255,.02);font-size:0.85rem;}
.badge{padding:4px 8px;border-radius:999px;font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.5px;}
.badge-green{background:rgba(124,255,199,.2);color:var(--ok);border:1px solid rgba(124,255,199,.3);}
.badge-yellow{background:rgba(255,209,102,.2);color:var(--warn);border:1px solid rgba(255,209,102,.3);}
.badge-red{background:rgba(255,107,107,.2);color:var(--bad);border:1px solid rgba(255,107,107,.3);}
.actions{display:flex;gap:8px;}
.btn{padding:6px 12px;border-radius:6px;border:0;cursor:pointer;font-size:.8rem;font-weight:500;transition:all .2s;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;}
.btn-approve{background:var(--ok);color:#04121b;}
.btn-reject{background:var(--warn);color:#04121b;}
.btn-blacklist{background:var(--bad);color:#fff;}
.btn-view{background:var(--brand);color:#04121b;}
.btn-message{background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;}
.btn-message:hover{filter:brightness(1.08);}
.modal{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.8);display:none;align-items:center;justify-content:center;z-index:1000;}
.modal.open{display:flex;}
.modal-content{background:var(--glass-bg);padding:24px;border-radius:var(--radius);width:100%;max-width:500px;max-height:90vh;overflow:auto;}
.modal h3{margin:0 0 16px 0;color:var(--text);}
.modal input, .modal textarea{width:100%;padding:10px;border-radius:6px;border:1px solid var(--glass-border);background:rgba(13,17,22,.8);color:var(--text);margin-bottom:12px;}
.modal-actions{display:flex;gap:12px;justify-content:flex-end;}

/* Centered customer details modal (replaces right drawer) */
.slide-backdrop{
  position:fixed;inset:0;
  background:rgba(0,0,0,.72);backdrop-filter:blur(10px);
  display:none;z-index:10040;
}
.slide-backdrop.open{display:block;}
.slide-panel{
  position:fixed;inset:0;z-index:10050;
  display:none;align-items:center;justify-content:center;
  padding:max(20px, calc(var(--nav-h) * .35)) 16px 24px;
  overflow-y:auto;overscroll-behavior:contain;
  pointer-events:none;
}
.slide-panel.open{display:flex;pointer-events:auto;}
.slide-panel-dialog{
  width:min(860px,96vw);
  max-height:min(90vh,920px);
  background:linear-gradient(145deg,#0f141a,#151b24);
  border:1px solid rgba(93,208,255,.22);
  border-radius:20px;
  box-shadow:0 28px 60px rgba(0,0,0,.55), 0 0 0 1px rgba(93,208,255,.08);
  display:flex;flex-direction:column;
  overflow:hidden;
  margin:auto;
  transform:translateY(10px) scale(.98);
  opacity:0;
  transition:transform .28s ease, opacity .28s ease;
}
.slide-panel.open .slide-panel-dialog{
  transform:translateY(0) scale(1);
  opacity:1;
}
.slide-header{
  padding:18px 22px;
  border-bottom:1px solid var(--glass-border);
  display:flex;justify-content:space-between;align-items:center;gap:16px;
  flex-shrink:0;background:rgba(15,20,26,.95);
}
.slide-header h3{
  margin:0;font-size:1.25rem;font-weight:800;letter-spacing:-.02em;
  color:var(--text);min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
}
.slide-close{
  background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);
  color:var(--muted);font-size:1.25rem;cursor:pointer;padding:0;
  border-radius:10px;width:36px;height:36px;flex-shrink:0;
  display:inline-flex;align-items:center;justify-content:center;
  transition:background .2s, color .2s, border-color .2s;
}
.slide-close:hover{background:rgba(255,255,255,.1);color:var(--text);border-color:var(--brand);}
.slide-body{padding:18px 22px 24px;overflow-y:auto;flex:1;min-height:0;}
.tabs{display:flex;gap:4px;border-bottom:1px solid var(--glass-border);margin-bottom:18px;}
.tab{padding:10px 14px;background:transparent;border:0;color:var(--muted);cursor:pointer;font-weight:600;font-size:.9rem;border-bottom:2px solid transparent;margin-bottom:-1px;}
.tab.active{color:var(--text);border-bottom-color:var(--brand);}
.tab-content{display:none;}
.tab-content.active{display:block;}
.toast{position:fixed;top:20px;right:20px;background:var(--glass-bg);color:var(--text);padding:16px 20px;border-radius:var(--radius);border:1px solid var(--glass-border);z-index:1001;}
@media (max-width:720px){
  .profile-grid,.document-grid,.document-sides{grid-template-columns:1fr;}
  .slide-panel{padding:12px;}
  .slide-header,.slide-body{padding-left:16px;padding-right:16px;}
}
</style>
</head>
<body>
<?php 
// Include navbar with error handling
if(file_exists('includes/navbar.php')) {
    include 'includes/navbar.php';
} else {
    // Fallback navbar if file doesn't exist
    echo '<nav style="background:#101419;padding:15px;border-bottom:1px solid #333;">
    <div style="max-width:1400px;margin:0 auto;display:flex;justify-content:space-between;align-items:center;">
    <h3 style="color:#5dd0ff;margin:0;">FleetGo Admin</h3>
    <a href="index.php" style="color:#f2f6fa;text-decoration:none;">Dashboard</a>
    </div></nav>';
}
?>
<div class="container">
<?php if(isset($_GET['flash'])): ?>
<div class="toast"><?=h($_GET['flash'])?></div>
<?php endif; ?>

<div class="header">
<div>
<h1>Customer Verification</h1>
<p>Manage customer accounts and verification status</p>
</div>
</div>

<div class="kpi-grid">
<div class="kpi-card">
<div class="kpi-value"><?=$total_users?></div>
<div class="kpi-label">Total Users</div>
</div>
<div class="kpi-card">
<div class="kpi-value"><?=$verified?></div>
<div class="kpi-label">Verified</div>
</div>
<div class="kpi-card">
<div class="kpi-value"><?=$pending?></div>
<div class="kpi-label">Pending</div>
</div>
<div class="kpi-card">
<div class="kpi-value"><?=$blacklisted?></div>
<div class="kpi-label">Blacklisted</div>
</div>
</div>

<div class="filters">
<input type="text" placeholder="Search name or email..." value="<?=h($q)?>" onkeyup="window.location.href='customers_all.php?q='+this.value+'&verification_status=<?=h($verification_status)?>&profile_status=<?=h($profile_status)?>&status=<?=h($status)?>'">
<select onchange="window.location.href='customers_all.php?q=<?=h($q)?>&verification_status='+this.value+'&profile_status=<?=h($profile_status)?>&status=<?=h($status)?>'">
<option value="">All Verification Status</option>
<option value="unverified" <?=$verification_status==='unverified'?'selected':''?>>Unverified</option>
<option value="pending" <?=$verification_status==='pending'?'selected':''?>>Pending</option>
<option value="verified" <?=$verification_status==='verified'?'selected':''?>>Verified</option>
<option value="rejected" <?=$verification_status==='rejected'?'selected':''?>>Rejected</option>
</select>
<select onchange="window.location.href='customers_all.php?q=<?=h($q)?>&verification_status=<?=h($verification_status)?>&profile_status=<?=h($profile_status)?>&status='+this.value">
<option value="">All Account Status</option>
<option value="active" <?=$status==='active'?'selected':''?>>Active</option>
<option value="inactive" <?=$status==='inactive'?'selected':''?>>Inactive</option>
<option value="blacklisted" <?=$status==='blacklisted'?'selected':''?>>Blacklisted</option>
</select>
</div>

<div class="table-container">
<table class="table">
<thead>
<tr>
<th>Customer</th>
<th>Contact</th>
<th>Documents</th>
<th>Verification</th>
<th>Account Status</th>
<th>Rentals</th>
<th>Actions</th>
</tr>
</thead>
<tbody>
<?php foreach($users as $user): ?>
<tr>
<td>
<div class="customer-info">
<div class="avatar"><?=strtoupper(substr($user['full_name'],0,2))?></div>
<div>
<div><?=h($user['full_name'])?></div>
<small style="color:var(--muted)"><?=h($user['email'])?></small>
</div>
</div>
</td>
<td>
<div><?=h($user['contact_no']?:$user['phone']?:'Not set')?></div>
<?php if($user['city']): ?>
<small style="color:var(--muted)"><?=h($user['city'])?></small>
<?php endif; ?>
</td>
<td>
<div class="document-status">
<?php 
$docCount = 0;
if($user['profile_photo']) $docCount++;

if($user['documents']) {
    if(isset($user['documents']['License Front'])) $docCount++;
    if(isset($user['documents']['License Back'])) $docCount++;
    if(isset($user['documents']['ID Front'])) $docCount++;
    if(isset($user['documents']['ID Back'])) $docCount++;
}
?>
<div style="display:flex;align-items:center;gap:8px;">
<span style="color:<?=($docCount >= 5 ? 'var(--success)' : ($docCount > 0 ? 'var(--warning)' : 'var(--error)'))?>;">
<?=($docCount >= 5 ? '✓' : ($docCount > 0 ? '⚠' : '✗'))?>
</span>
<span style="font-size:0.85rem;"><?=$docCount?>/5 docs</span>
</div>
<div style="font-size:0.75rem;color:var(--muted);margin-top:2px;">
<?php if($user['profile_photo']) echo '📷 ' ?>
<?php 
if(isset($user['documents']['License Front'])) echo '📄 ';
if(isset($user['documents']['License Back'])) echo '📄 ';
if(isset($user['documents']['ID Front'])) echo '📋 ';
if(isset($user['documents']['ID Back'])) echo '📋 ';
?>
<?php if($docCount === 0) echo 'No docs' ?>
</div>
</div>
</td>
<td>
<span class="badge badge-<?=($user['verification_status']==='verified'?'green':($user['verification_status']==='pending'?'yellow':'red'))?>">
<?=h($user['verification_status'])?>
</span>
</td>
<td>
<span class="badge badge-<?=($user['status']==='active'?'green':'red')?>">
<?=h($user['status'])?>
</span>
</td>
<td><?=$user['rentals_count']?> rentals</td>
<td>
<div class="actions">
<button class="btn btn-view" onclick="openSlidePanel(<?=$user['id']?>)">View</button>
<a class="btn btn-message" href="messages.php?user_id=<?=(int)$user['id']?>">Message</a>
<?php if($user['profile_status']==='pending_approval'): ?>
<button class="btn btn-approve" onclick="approveUser(<?=$user['id']?>)">Approve</button>
<button class="btn btn-reject" onclick="openRejectModal(<?=$user['id']?>)">Reject</button>
<?php endif; ?>
<?php if($user['status']!=='blacklisted'): ?>
<button class="btn btn-blacklist" onclick="openBlacklistModal(<?=$user['id']?>)">Blacklist</button>
<?php endif; ?>
</div>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>

<!-- Customer Details Modal -->
<div class="slide-backdrop" onclick="closeSlidePanel()"></div>
<div class="slide-panel" id="customerSlidePanel" onclick="if(event.target===this)closeSlidePanel()">
  <div class="slide-panel-dialog" role="dialog" aria-modal="true" aria-labelledby="slideTitle">
    <div class="slide-header">
      <h3 id="slideTitle">Customer Details</h3>
      <button type="button" class="slide-close" onclick="closeSlidePanel()" aria-label="Close">×</button>
    </div>
    <div class="slide-body">
      <div class="tabs">
        <button type="button" class="tab active" data-tab="profile" onclick="switchTab('profile', this)">Profile</button>
        <button type="button" class="tab" data-tab="documents" onclick="switchTab('documents', this)">Documents</button>
        <button type="button" class="tab" data-tab="rentals" onclick="switchTab('rentals', this)">Rental History</button>
      </div>
      <div id="profile-tab" class="tab-content active"></div>
      <div id="documents-tab" class="tab-content"></div>
      <div id="rentals-tab" class="tab-content"></div>
    </div>
  </div>
</div>

<!-- Reject Modal -->
<div id="rejectModal" class="modal">
<div class="modal-content">
<h3>Reject Customer</h3>
<form method="post">
<input type="hidden" name="action" value="reject">
<input type="hidden" name="id" id="rejectId">
<textarea name="reason" placeholder="Reason for rejection..." required style="min-height:100px;"></textarea>
<div class="modal-actions">
<button type="button" onclick="closeModal('rejectModal')" style="background:var(--glass-border);color:var(--text);">Cancel</button>
<button type="submit" class="btn btn-reject">Reject</button>
</div>
</form>
</div>
</div>

<!-- Blacklist Modal -->
<div id="blacklistModal" class="modal">
<div class="modal-content">
<h3>Blacklist Customer</h3>
<form method="post">
<input type="hidden" name="action" value="blacklist">
<input type="hidden" name="id" id="blacklistId">
<textarea name="reason" placeholder="Reason for blacklisting..." required style="min-height:100px;"></textarea>
<div class="modal-actions">
<button type="button" onclick="closeModal('blacklistModal')" style="background:var(--glass-border);color:var(--text);">Cancel</button>
<button type="submit" class="btn btn-blacklist">Blacklist</button>
</div>
</form>
</div>
</div>

<script>
// Helper function to build correct image URLs
function getImageUrl(filePath) {
    // If path already starts with http or is absolute, return as-is
    if (filePath.startsWith('http') || filePath.startsWith('/')) {
        return filePath;
    }
    // Otherwise, add /frmms/ prefix for relative paths
    return '/frmms/' + filePath.replace(/^\/+/, '');
}

// Test simple data first
const testUser = {
    id: 1,
    full_name: "Test User",
    documents: {
        "License Front": "uploads/test.jpg",
        "License Back": "uploads/test_back.jpg"
    }
};
console.log('Test user data:', testUser);
console.log('Test image URLs:', {
    'License Front': getImageUrl(testUser.documents['License Front']),
    'License Back': getImageUrl(testUser.documents['License Back'])
});

const users = <?=json_encode($users, JSON_PRETTY_PRINT)?>;

// Debug: Log users data
console.log('Users data:', users);
console.log('Users JSON string:', '<?=json_encode($users)?>');

// Test document access
if (users.length > 0) {
    console.log('First user:', users[0]);
    console.log('First user documents:', users[0].documents);
}

function openSlidePanel(userId) {
    console.log('=== VIEW BUTTON CLICKED ===');
    console.log('Looking for user ID:', userId);
    console.log('Available users:', users.map(u => ({id: u.id, name: u.full_name, hasDocs: !!u.documents})));
    
    const user = users.find(u => u.id == userId);
    if(!user) {
        console.log('ERROR: User not found for ID:', userId);
        return;
    }
    
    // Debug: Log user and documents
    console.log('Found user:', user);
    console.log('User documents:', user.documents);
    console.log('User documents type:', typeof user.documents);
    console.log('User documents keys:', user.documents ? Object.keys(user.documents) : 'NULL');
    
    document.getElementById('slideTitle').textContent = user.full_name;
    
    // Profile tab
    const esc = (v) => String(v ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    const field = (label, value, full = false) =>
      `<div class="profile-field${full ? ' full' : ''}"><span class="k">${label}</span><span class="v">${value || 'Not set'}</span></div>`;

    document.getElementById('profile-tab').innerHTML = `
        <div style="display:grid;gap:16px;">
            <div class="profile-section">
                <h4>👤 Basic Information</h4>
                <div class="profile-grid">
                    ${field('Full Name', esc(user.full_name))}
                    ${field('Email Address', esc(user.email))}
                    ${field('Contact Number', esc(user.contact_no || user.phone))}
                    ${field('City', esc(user.city))}
                    ${field('Complete Address', esc(user.address), true)}
                </div>
            </div>

            <div class="profile-section">
                <h4>📄 License &amp; ID Information</h4>
                <div class="profile-grid">
                    ${field("Driver's License Number", `<span style="font-family:ui-monospace,monospace;">${esc(user.driver_license_no) || 'Not set'}</span>`)}
                    ${field('License Expiry Date', user.driver_license_expiry
                      ? `<span style="color:${new Date(user.driver_license_expiry) > new Date() ? 'var(--ok)' : 'var(--bad)'};font-weight:700;">${esc(new Date(user.driver_license_expiry).toLocaleDateString('en-US', {year: 'numeric', month: 'long', day: 'numeric'}))}</span>${new Date(user.driver_license_expiry) <= new Date() ? '<br><small style="color:var(--bad);">⚠️ EXPIRED</small>' : ''}`
                      : 'Not set')}
                    ${field('ID Type', esc(user.id_type))}
                    ${field('ID Number', `<span style="font-family:ui-monospace,monospace;">${esc(user.id_number) || 'Not set'}</span>`)}
                </div>
            </div>

            <div class="profile-section">
                <h4>🚨 Emergency Contact</h4>
                <div class="profile-grid">
                    ${field('Emergency Contact Name', esc(user.emergency_name))}
                    ${field('Emergency Contact Phone', esc(user.emergency_phone))}
                </div>
            </div>

            <div class="profile-section">
                <h4>📊 Account Status</h4>
                <div class="profile-grid">
                    ${field('Account Status', `<span class="badge badge-${user.status === 'active' ? 'green' : 'red'}">${esc(user.status)}</span>`)}
                </div>
            </div>

            ${(user.rejection_reason || user.blacklist_reason || user.notes) ? `
                <div class="profile-section">
                    <h4>📝 Additional Information</h4>
                    <div class="profile-grid">
                      ${user.rejection_reason ? field('Rejection Reason', `<span style="color:var(--bad);">${esc(user.rejection_reason)}</span>`, true) : ''}
                      ${user.blacklist_reason ? field('Blacklist Reason', `<span style="color:var(--bad);">${esc(user.blacklist_reason)}</span>`, true) : ''}
                      ${user.notes ? field('Notes', esc(user.notes), true) : ''}
                    </div>
                </div>
            ` : ''}
        </div>
    `;
    
    // Documents tab - Step 5: Fix file_path → image URL with proper error handling
    document.getElementById('documents-tab').innerHTML = `
        <div style="display:grid;gap:20px;">
            <div class="document-grid">
                ${user.profile_photo ? `
                    <div class="document-item">
                        <div class="document-header">
                            <strong>📷 Profile Photo</strong>
                            <button class="btn btn-view" onclick="viewImage('${getImageUrl(user.profile_photo)}', 'Profile Photo')" style="padding:4px 8px;font-size:0.75rem;">View Full</button>
                        </div>
                        <img src="${getImageUrl(user.profile_photo)}" style="width:100%;height:200px;object-fit:cover;border-radius:8px;margin-top:8px;cursor:pointer;" onclick="viewImage('${getImageUrl(user.profile_photo)}', 'Profile Photo')" onerror="this.style.display='none'; this.nextElementSibling.style.display='block'; console.error('Profile photo failed to load:', '${getImageUrl(user.profile_photo)}');">
                        <div style="display:none;color:var(--error);padding:20px;text-align:center;border:2px dashed var(--border);border-radius:8px;margin-top:8px;">❌ Image failed to load</div>
                    </div>
                ` : `
                    <div class="document-item missing">
                        <strong>📷 Profile Photo</strong>
                        <div style="color:var(--muted);margin-top:8px;">No profile photo uploaded</div>
                    </div>
                `}
                
                <div class="document-item">
                    <div class="document-header">
                        <strong>📄 Driver's License</strong>
                    </div>
                    <div class="document-sides">
                        <div class="document-side">
                            <div class="side-label">Front</div>
                            ${user.documents && user.documents['License Front'] ? `
                                <img src="${getImageUrl(user.documents['License Front'])}" style="width:100%;height:180px;object-fit:cover;border-radius:6px;margin-top:4px;cursor:pointer;" onclick="viewImage('${getImageUrl(user.documents['License Front'])}', 'License Front')" onerror="this.style.display='none'; this.nextElementSibling.style.display='block'; console.error('License Front failed to load:', '${getImageUrl(user.documents['License Front'])}');">
                                <div style="display:none;color:var(--error);padding:20px;text-align:center;border:2px dashed var(--border);border-radius:6px;margin-top:4px;">❌ Failed to load</div>
                            ` : `
                                <div class="missing-side">No front photo</div>
                            `}
                        </div>
                        <div class="document-side">
                            <div class="side-label">Back</div>
                            ${user.documents && user.documents['License Back'] ? `
                                <img src="${getImageUrl(user.documents['License Back'])}" style="width:100%;height:180px;object-fit:cover;border-radius:6px;margin-top:4px;cursor:pointer;" onclick="viewImage('${getImageUrl(user.documents['License Back'])}', 'License Back')" onerror="this.style.display='none'; this.nextElementSibling.style.display='block'; console.error('License Back failed to load:', '${getImageUrl(user.documents['License Back'])}');">
                                <div style="display:none;color:var(--error);padding:20px;text-align:center;border:2px dashed var(--border);border-radius:6px;margin-top:4px;">❌ Failed to load</div>
                            ` : `
                                <div class="missing-side">No back photo</div>
                            `}
                        </div>
                    </div>
                </div>
                
                <div class="document-item">
                    <div class="document-header">
                        <strong>📋 Valid ID</strong>
                    </div>
                    <div class="document-sides">
                        <div class="document-side">
                            <div class="side-label">Front</div>
                            ${user.documents && user.documents['ID Front'] ? `
                                <img src="${getImageUrl(user.documents['ID Front'])}" style="width:100%;height:180px;object-fit:cover;border-radius:6px;margin-top:4px;cursor:pointer;" onclick="viewImage('${getImageUrl(user.documents['ID Front'])}', 'ID Front')" onerror="this.style.display='none'; this.nextElementSibling.style.display='block'; console.error('ID Front failed to load:', '${getImageUrl(user.documents['ID Front'])}');">
                                <div style="display:none;color:var(--error);padding:20px;text-align:center;border:2px dashed var(--border);border-radius:6px;margin-top:4px;">❌ Failed to load</div>
                            ` : `
                                <div class="missing-side">No front photo</div>
                            `}
                        </div>
                        <div class="document-side">
                            <div class="side-label">Back</div>
                            ${user.documents && user.documents['ID Back'] ? `
                                <img src="${getImageUrl(user.documents['ID Back'])}" style="width:100%;height:180px;object-fit:cover;border-radius:6px;margin-top:4px;cursor:pointer;" onclick="viewImage('${getImageUrl(user.documents['ID Back'])}', 'ID Back')" onerror="this.style.display='none'; this.nextElementSibling.style.display='block'; console.error('ID Back failed to load:', '${getImageUrl(user.documents['ID Back'])}');">
                                <div style="display:none;color:var(--error);padding:20px;text-align:center;border:2px dashed var(--border);border-radius:6px;margin-top:4px;">❌ Failed to load</div>
                            ` : `
                                <div class="missing-side">No back photo</div>
                            `}
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="document-summary" style="margin-top:20px;">
                <h4 style="margin-bottom:12px;">Document Summary</h4>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;">
                    <div style="text-align:center;padding:12px;background:rgba(93,208,255,.05);border-radius:8px;">
                        <div style="font-size:1.5rem;font-weight:800;color:var(--brand);">
                            ${user.profile_photo ? '✓' : '✗'}
                        </div>
                        <div style="font-size:0.85rem;color:var(--muted);">Profile Photo</div>
                    </div>
                    <div style="text-align:center;padding:12px;background:rgba(93,208,255,.05);border-radius:8px;">
                        <div style="font-size:1.5rem;font-weight:800;color:var(--brand);">
                            ${user.documents && user.documents['License Front'] ? '✓' : '✗'}
                        </div>
                        <div style="font-size:0.85rem;color:var(--muted);">License (Front)</div>
                    </div>
                    <div style="text-align:center;padding:12px;background:rgba(93,208,255,.05);border-radius:8px;">
                        <div style="font-size:1.5rem;font-weight:800;color:var(--brand);">
                            ${user.documents && user.documents['License Back'] ? '✓' : '✗'}
                        </div>
                        <div style="font-size:0.85rem;color:var(--muted);">License (Back)</div>
                    </div>
                    <div style="text-align:center;padding:12px;background:rgba(93,208,255,.05);border-radius:8px;">
                        <div style="font-size:1.5rem;font-weight:800;color:var(--brand);">
                            ${user.documents && user.documents['ID Front'] ? '✓' : '✗'}
                        </div>
                        <div style="font-size:0.85rem;color:var(--muted);">Valid ID (Front)</div>
                    </div>
                    <div style="text-align:center;padding:12px;background:rgba(93,208,255,.05);border-radius:8px;">
                        <div style="font-size:1.5rem;font-weight:800;color:var(--brand);">
                            ${user.documents && user.documents['ID Back'] ? '✓' : '✗'}
                        </div>
                        <div style="font-size:0.85rem;color:var(--muted);">Valid ID (Back)</div>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    // Load rental history
    loadRentalHistory(userId);

    // Reset to Profile tab
    document.querySelectorAll('.slide-panel .tab').forEach(t => t.classList.toggle('active', t.dataset.tab === 'profile'));
    document.querySelectorAll('.slide-panel .tab-content').forEach(t => t.classList.toggle('active', t.id === 'profile-tab'));

    document.querySelector('.slide-panel').classList.add('open');
    document.querySelector('.slide-backdrop').classList.add('open');
    document.body.classList.add('modal-open');
}

function closeSlidePanel() {
    document.querySelector('.slide-panel').classList.remove('open');
    document.querySelector('.slide-backdrop').classList.remove('open');
    document.body.classList.remove('modal-open');
}

function switchTab(tabName, btn) {
    document.querySelectorAll('.slide-panel .tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.slide-panel .tab-content').forEach(t => t.classList.remove('active'));
    (btn || document.querySelector(`.slide-panel .tab[data-tab="${tabName}"]`))?.classList.add('active');
    document.getElementById(tabName + '-tab')?.classList.add('active');
}

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && document.querySelector('.slide-panel.open')) closeSlidePanel();
});

async function loadRentalHistory(userId) {
    const response = await fetch(`customers_all.php?ajax=rentals&id=${userId}`);
    const data = await response.json();
    
    if(data.success && data.rentals.length > 0) {
        let html = '<div style="display:grid;gap:12px;">';
        data.rentals.forEach(rental => {
            html += `
                <div style="padding:12px;background:rgba(93,208,255,.05);border-radius:8px;">
                    <div><strong>Vehicle:</strong> ${rental.make_model} (${rental.plate_no})</div>
                    <div><strong>Period:</strong> ${rental.start_date} to ${rental.end_date}</div>
                    <div><strong>Status:</strong> ${rental.status}</div>
                    <div><strong>Total:</strong> ₱${rental.total_cost || 0}</div>
                </div>
            `;
        });
        html += '</div>';
        document.getElementById('rentals-tab').innerHTML = html;
    } else {
        document.getElementById('rentals-tab').innerHTML = '<p style="color:var(--muted);text-align:center;">No rental history found.</p>';
    }
}

function approveUser(userId) {
    if(confirm('Approve this customer?')) {
        const form = document.createElement('form');
        form.method = 'post';
        form.innerHTML = '<input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="' + userId + '">';
        document.body.appendChild(form);
        form.submit();
    }
}

function deleteCustomer(id) {
    if (confirm('Are you sure you want to delete this customer? This action cannot be undone.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="${id}">`;
        document.body.appendChild(form);
        form.submit();
    }
}

function openRejectModal(userId) {
    document.getElementById('rejectId').value = userId;
    document.getElementById('rejectModal').classList.add('open');
}

function openBlacklistModal(userId) {
    document.getElementById('blacklistId').value = userId;
    document.getElementById('blacklistModal').classList.add('open');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('open');
}

// Hide toast after 3 seconds
setTimeout(() => {
    const toast = document.querySelector('.toast');
    if(toast) toast.style.display = 'none';
}, 3000);

// Image Viewer Modal
function viewImage(imageSrc, title) {
    // Remove existing modal if any
    const existingModal = document.getElementById('imageViewerModal');
    if (existingModal) {
        existingModal.remove();
    }
    
    // Create modal
    const modal = document.createElement('div');
    modal.id = 'imageViewerModal';
    modal.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.9);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 10000;
        cursor: pointer;
    `;
    
    modal.innerHTML = `
        <div style="position: relative; max-width: 90%; max-height: 90%; text-align: center;">
            <div style="color: white; font-size: 1.2rem; margin-bottom: 16px; font-weight: 600;">${title}</div>
            <img src="${imageSrc}" style="max-width: 100%; max-height: 80vh; border-radius: 8px; box-shadow: 0 10px 40px rgba(0,0,0,0.5);">
            <button onclick="this.closest('#imageViewerModal').remove()" style="
                position: absolute;
                top: -40px;
                right: 0;
                background: rgba(255, 255, 255, 0.2);
                border: 1px solid rgba(255, 255, 255, 0.3);
                color: white;
                font-size: 1.5rem;
                width: 40px;
                height: 40px;
                border-radius: 50%;
                cursor: pointer;
                display: flex;
                align-items: center;
                justify-content: center;
            ">×</button>
        </div>
    `;
    
    modal.onclick = function(e) {
        if (e.target === modal) {
            modal.remove();
        }
    };
    
    document.body.appendChild(modal);
}
</script>
</body>
</html>
