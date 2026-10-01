<?php
/* ============================================================
   FleetGo — User Profile with Verification Flow
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

/* --- User session --- */
$userID   = (int)$_SESSION['user_id'];
$userName = $_SESSION['user_name'] ?? 'Guest';

if ($userID <= 0) {
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit;
}

/* ---------- BOOKING GATE FUNCTION ---------- */
function isUserVerified($user) {
    return (
        $user['status'] === 'active' &&
        $user['profile_status'] === 'approved' &&
        $user['verification_status'] === 'verified'
    );
}

// Get user profile data
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $userID);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit;
}

// Keep session display name in sync with DB
$_SESSION['user_name'] = $user['full_name'];
$userName = $user['full_name'];

/* ==========================
   PROFILE UPDATE HANDLER
========================== */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    /* ---------- SAVE PROFILE PHOTO ONLY ---------- */
    if (isset($_POST['action']) && $_POST['action'] === 'save_profile_photo') {
        $uploadDir = "uploads/profiles/";
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

        if (empty($_FILES['profile_photo']['name'])) {
            $_SESSION['flash_error'] = "Please choose a profile picture first.";
            header("Location: userprofile.php");
            exit;
        }

        $ext = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
            $_SESSION['flash_error'] = "Invalid image type. Use JPG, PNG, WEBP, or GIF.";
            header("Location: userprofile.php");
            exit;
        }

        if (!empty($_FILES['profile_photo']['error']) && (int)$_FILES['profile_photo']['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['flash_error'] = "Upload failed. Please try a smaller image.";
            header("Location: userprofile.php");
            exit;
        }

        $newName = $uploadDir . "profile_photo_{$userID}_" . time() . "." . $ext;
        if (!move_uploaded_file($_FILES['profile_photo']['tmp_name'], $newName)) {
            $_SESSION['flash_error'] = "Could not save profile picture. Please try again.";
            header("Location: userprofile.php");
            exit;
        }

        $stmt = $conn->prepare("UPDATE users SET profile_photo = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param("si", $newName, $userID);
        if ($stmt->execute()) {
            $_SESSION['flash_message'] = "Profile picture saved!";
        } else {
            $_SESSION['flash_error'] = "Failed to save profile picture.";
        }
        $stmt->close();
        header("Location: userprofile.php");
        exit;
    }

    if (isset($_POST['action']) && $_POST['action'] === 'update_profile') {
        $email = trim($_POST['email'] ?? '');
        $contact_no = trim($_POST['contact_no'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $driver_license_no = trim($_POST['driver_license_no'] ?? '');
        $driver_license_expiry = trim($_POST['driver_license_expiry'] ?? '');
        $id_type = trim($_POST['id_type'] ?? '');
        $id_number = trim($_POST['id_number'] ?? '');
        $emergency_name = trim($_POST['emergency_name'] ?? '');
        $emergency_phone = trim($_POST['emergency_phone'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        // Normalize phone numbers (UI formats as 09XX XXX XXXX with spaces)
        $contact_no = preg_replace('/\D+/', '', $contact_no);
        if ($contact_no !== '' && !preg_match('/^09\d{9}$/', $contact_no)) {
            $_SESSION['flash_message'] = "Please enter a valid Philippine mobile number (09XX XXX XXXX).";
            header("Location: userprofile.php");
            exit;
        }
        $emergency_phone = preg_replace('/\D+/', '', $emergency_phone);
        
        $uploadDir = "uploads/profiles/";
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

        $fields = []; $types = ""; $values = [];
        
        // Basic info fields - always include to save empty values too
        foreach (['email', 'contact_no', 'city', 'address'] as $f) {
            $val = $$f;
            $fields[] = "$f=?"; 
            $types .= "s"; 
            $values[] = $val; 
        }
        
        // Verification fields - always include to save empty values too
        foreach (['driver_license_no', 'driver_license_expiry', 'id_type', 'id_number', 'emergency_name', 'notes'] as $f) {
            $val = $$f;
            $fields[] = "$f=?"; 
            $types .= "s"; 
            $values[] = $val; 
        }

        // Handle profile photo in users table
        if (!empty($_FILES['profile_photo']['name'])) {
            $ext = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) {
                $newName = $uploadDir . "profile_photo_{$userID}_" . time() . "." . $ext;
                if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $newName)) {
                    $fields[] = "profile_photo=?"; 
                    $types .= "s"; 
                    $values[] = $newName;
                }
            }
        }

        // Handle document uploads to user_documents table
        $documentTypes = [
            'license_photo_front' => 'License Front',
            'license_photo_back' => 'License Back', 
            'valid_id_photo_front' => 'ID Front',
            'valid_id_photo_back' => 'ID Back'
        ];
        
        foreach ($documentTypes as $field => $docType) {
            if (!empty($_FILES[$field]['name'])) {
                $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) {
                    $newName = $uploadDir . "{$field}_{$userID}_" . time() . "." . $ext;
                    if (move_uploaded_file($_FILES[$field]['tmp_name'], $newName)) {
                        // Debug: Log the insert attempt
                        error_log("Inserting document: user_id=$userID, doc_type=$docType, file_path=$newName");
                        
                        // Insert into user_documents table
                        $docStmt = $conn->prepare("
                            INSERT INTO user_documents (user_id, doc_type, file_path, uploaded_at) 
                            VALUES (?, ?, ?, NOW())
                        ");
                        $docStmt->bind_param("iss", $userID, $docType, $newName);
                        
                        if ($docStmt->execute()) {
                            error_log("Successfully inserted document: $docType for user $userID");
                        } else {
                            error_log("Failed to insert document: " . $docStmt->error);
                        }
                        $docStmt->close();
                    }
                }
            }
        }

        // Always update the profile with current form data
$fields[] = "updated_at=NOW()";

$sql = "UPDATE users SET " . implode(",", $fields) . " WHERE id=?";
$types .= "i"; 
$values[] = $userID;

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$values);
if ($stmt->execute()) {
    $_SESSION['flash_message'] = "Profile updated successfully!";
} else {
    $_SESSION['flash_message'] = "Failed to update profile. Please try again. Error: " . $stmt->error;
}
$stmt->close();

        header("Location: userprofile.php");
        exit;
    }
    
    /* ---------- SUBMIT FOR VERIFICATION ---------- */
    if (isset($_POST['action']) && $_POST['action'] === 'submit_verification') {
        // Fetch latest user data (and docs) for accurate validation
        $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->bind_param("i", $userID);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $userDocuments = [];
        $docStmt = $conn->prepare("
            SELECT doc_type, file_path
            FROM user_documents
            WHERE user_id = ?
            ORDER BY uploaded_at DESC
        ");
        $docStmt->bind_param("i", $userID);
        $docStmt->execute();
        $documents = $docStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $docStmt->close();
        foreach ($documents as $doc) {
            $userDocuments[$doc['doc_type']] = $doc['file_path'];
        }

        // Check required fields
        $required_fields = ['driver_license_no', 'driver_license_expiry', 'id_type', 'id_number'];
        $missing_fields = [];
        
        foreach ($required_fields as $field) {
            if (empty(trim($user[$field] ?? ''))) {
                $missing_fields[] = ucfirst(str_replace('_', ' ', $field));
            }
        }
        
        // Check required photos - accept either legacy users columns or user_documents
        $hasLicensePhoto = !empty($user['license_photo']) || !empty($userDocuments['License Front']);
        $hasValidIdPhoto = !empty($user['valid_id_photo']) || !empty($userDocuments['ID Front']);

        // License must not be expired
        if (!empty($user['driver_license_expiry'])) {
            try {
                $expiryDate = new DateTime($user['driver_license_expiry']);
                $today = new DateTime('today');
                if ($expiryDate < $today) {
                    $missing_fields[] = "Driver license is expired";
                }
            } catch (Exception $e) {
                $missing_fields[] = "Invalid driver license expiry date";
            }
        }

        if (!$hasLicensePhoto) {
            $missing_fields[] = "License Photo (Front)";
        }
        if (!$hasValidIdPhoto) {
            $missing_fields[] = "Valid ID Photo (Front)";
        }
        
        if (!empty($missing_fields)) {
            error_log("VALIDATION FAILED - Missing fields: " . implode(', ', $missing_fields));
            $_SESSION['flash_error'] = "Please complete the following required fields before submitting: " . implode(', ', $missing_fields);
        } else {
            error_log("VALIDATION PASSED - All required fields present");
            // Update status to pending approval
            $stmt = $conn->prepare("
                UPDATE users SET 
                    profile_status = 'pending_approval',
                    verification_status = 'pending',
                    submitted_at = NOW()
                WHERE id = ?
            ");
            $stmt->bind_param("i", $userID);
            if ($stmt->execute()) {
                error_log("VERIFICATION SUBMISSION SUCCESS - User ID: $userID");
                $_SESSION['flash_message'] = "Your profile has been submitted for verification! We'll review it within 24-48 hours.";
            } else {
                error_log("VERIFICATION SUBMISSION FAILED - User ID: $userID, Error: " . $stmt->error);
                $_SESSION['flash_error'] = "Failed to submit verification. Please try again. Error: " . $stmt->error;
            }
            $stmt->close();
        }
        
        error_log("REDIRECTING TO userprofile.php");
        header("Location: userprofile.php");
        exit;
    }
}

/* --- Handle flash messages --- */
$flash = $_SESSION['flash_message'] ?? "";
$flash_error = $_SESSION['flash_error'] ?? "";
unset($_SESSION['flash_message']);
unset($_SESSION['flash_error']);

/* --- Refresh user data --- */
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $userID);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

/* --- Fetch user documents from user_documents table --- */
$userDocuments = [];
$docStmt = $conn->prepare("
    SELECT doc_type, file_path 
    FROM user_documents 
    WHERE user_id = ?
");
$docStmt->bind_param("i", $userID);
$docStmt->execute();
$documents = $docStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$docStmt->close();

// Convert to key-value array for easy access
foreach($documents as $doc) {
    $userDocuments[$doc['doc_type']] = $doc['file_path'];
}

/* --- Check verification status --- */
$profile_status = $user['profile_status'] ?? 'incomplete';
$verification_status = $user['verification_status'] ?? 'unverified';
$is_verified = isUserVerified($user);
$hasLicenseFrontForUi = (!empty($user['license_photo']) || !empty($userDocuments['License Front']));
$hasIdFrontForUi = (!empty($user['valid_id_photo']) || !empty($userDocuments['ID Front']));
$licenseNotExpiredForUi = true;
if (!empty($user['driver_license_expiry'])) {
    try {
        $expiryDateUi = new DateTime($user['driver_license_expiry']);
        $todayUi = new DateTime('today');
        $licenseNotExpiredForUi = $expiryDateUi >= $todayUi;
    } catch (Exception $e) {
        $licenseNotExpiredForUi = false;
    }
}
$can_submit = (
    !empty($user['driver_license_no']) &&
    !empty($user['driver_license_expiry']) &&
    !empty($user['id_type']) &&
    !empty($user['id_number']) &&
    $hasLicenseFrontForUi &&
    $hasIdFrontForUi &&
    $licenseNotExpiredForUi
);

/* --- Fetch rental statistics --- */
$stats = [
    'total_rentals' => 0,
    'completed_rentals' => 0,
    'total_spent' => 0,
    'current_rentals' => 0
];

$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_rentals,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_rentals,
        SUM(CASE WHEN status IN ('ongoing', 'reserved') THEN 1 ELSE 0 END) as current_rentals
    FROM rentals 
    WHERE customer_id = ?
");
$stmt->bind_param("i", $userID);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
if ($result) {
    $stats = array_merge($stats, $result);
}
$stmt->close();

// Calculate total spent
$stmt = $conn->prepare("
    SELECT SUM(total_cost) as total_spent
    FROM rentals 
    WHERE customer_id = ? AND status = 'completed'
");
$stmt->bind_param("i", $userID);
$stmt->execute();
$spent_result = $stmt->get_result()->fetch_assoc();
$stats['total_spent'] = $spent_result['total_spent'] ?? 0;
$stmt->close();

/* --- Fetch recent rental history --- */
$rentalHistory = [];
$stmt = $conn->prepare("
    SELECT r.*, v.make_model, v.plate_no, v.vehicle_type, v.photo as vehicle_photo
    FROM rentals r
    JOIN vehicles v ON v.id = r.vehicle_id
    WHERE r.customer_id = ?
    ORDER BY r.created_at DESC
    LIMIT 5
");
$stmt->bind_param("i", $userID);
$stmt->execute();
$rentalHistory = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>My Profile | FleetGo</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
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
  --glass:rgba(255,255,255,.05);--glass-border:rgba(255,255,255,.1);
}
*{box-sizing:border-box;margin:0;padding:0}
body{
  font-family:"Inter",system-ui,sans-serif;
  background:var(--bg);
  color:var(--text-primary);min-height:100vh;position:relative;
}
body::before{
  content:"";position:fixed;top:0;left:0;right:0;bottom:0;
  background:url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grid" width="10" height="10" patternUnits="userSpaceOnUse"><path d="M 10 0 L 0 0 0 10" fill="none" stroke="rgba(93,208,255,0.03)" stroke-width="0.5"/></pattern></defs><rect width="100" height="100" fill="url(%23grid)"/></svg>');
  pointer-events:none;z-index:-1;
}
a{text-decoration:none;color:inherit}

 .verification-card{display:none;}
 .profile-badges{display:none;}

 .section-cards{display:grid;gap:14px;}
 .section-card{
   width:100%;
   display:flex;
   align-items:center;
   justify-content:space-between;
   gap:12px;
   padding:18px 20px;
   cursor:pointer;
   background:var(--card);
   border:1px solid var(--border);
   border-radius:var(--radius);
   box-shadow:var(--shadow-lg);
   color:var(--text-primary);
   text-align:left;
   transition:transform .18s ease, border-color .18s ease, box-shadow .18s ease, background .18s ease;
 }
 .section-card:hover{
   border-color:rgba(93,208,255,.35);
   box-shadow:0 10px 28px rgba(0,0,0,.28), 0 0 0 1px rgba(93,208,255,.12);
   transform:translateY(-1px);
 }
 .section-card:active{
   transform:scale(.985);
 }
 .section-card__title{
   display:flex;
   align-items:center;
   gap:10px;
   font-weight:900;
   letter-spacing:-.01em;
   font-size:1.05rem;
 }
 .section-card__meta{
   color:var(--text-secondary);
   font-weight:700;
   font-size:.9rem;
 }
 .section-card__icon{
   width:34px;height:34px;
   border-radius:10px;
   display:grid;place-items:center;
   background:rgba(93,208,255,.08);
   border:1px solid rgba(93,208,255,.18);
 }
 .section-card__chevron{
   opacity:.9;
   transition:transform .2s ease;
 }
 .section-card:hover .section-card__chevron{transform:translateX(3px);}

 .section-modal{
   position:fixed;
   inset:0;
   z-index:10000;
   display:flex;
   align-items:stretch;
   justify-content:center;
   padding:12px;
   opacity:0;
   visibility:hidden;
   pointer-events:none;
   transition:opacity .22s ease, visibility .22s ease;
 }
 .section-modal.is-open{
   opacity:1;
   visibility:visible;
   pointer-events:auto;
 }
 .section-modal__backdrop{
   position:absolute;
   inset:0;
   background:rgba(0,0,0,.78);
   backdrop-filter:blur(6px);
 }
 .section-modal__dialog{
   position:relative;
   z-index:1;
   width:min(1100px, calc(100vw - 24px));
   height:calc(100vh - 24px);
   height:calc(100dvh - 24px);
   max-height:calc(100vh - 24px);
   max-height:calc(100dvh - 24px);
   display:flex;
   flex-direction:column;
   background:rgba(16,20,25,.98);
   border:1px solid var(--border);
   border-radius:18px;
   box-shadow:var(--shadow-xl), 0 0 0 1px rgba(93,208,255,.08);
   overflow:hidden;
   min-height:0;
   transform:translateY(18px) scale(.98);
   opacity:0;
   transition:transform .28s cubic-bezier(.22,1,.36,1), opacity .22s ease;
 }
 .section-modal.is-open .section-modal__dialog{
   transform:translateY(0) scale(1);
   opacity:1;
 }
 .section-modal.is-closing .section-modal__dialog{
   transform:translateY(12px) scale(.98);
   opacity:0;
 }
 .section-modal__header{
   display:flex;
   align-items:center;
   justify-content:space-between;
   gap:12px;
   padding:14px 18px;
   border-bottom:1px solid var(--border);
   flex:0 0 auto;
 }
 .section-modal__title{
   display:flex;
   align-items:center;
   gap:10px;
   font-weight:900;
   font-size:1.1rem;
   color:var(--text-primary);
 }
 .section-modal__close{
   width:40px;height:40px;
   border-radius:12px;
   border:1px solid var(--border);
   background:rgba(255,255,255,.03);
   color:var(--text-secondary);
   font-size:1.4rem;
   line-height:1;
   cursor:pointer;
   transition:background .15s ease, color .15s ease, border-color .15s ease;
 }
 .section-modal__close:hover{
   background:rgba(93,208,255,.08);
   border-color:rgba(93,208,255,.28);
   color:var(--text-primary);
 }
 .section-modal__body{
   flex:1 1 auto;
   min-height:0;
   height:100%;
   overflow-x:hidden;
   overflow-y:auto;
   -webkit-overflow-scrolling:touch;
   overscroll-behavior:contain;
   padding:18px 18px 32px;
   scrollbar-gutter:stable;
 }
 .section-modal__body .form-actions{
   position:sticky;
   bottom:0;
   z-index:2;
   margin-top:18px;
   margin-bottom:0;
   padding:14px 0 6px;
   background:linear-gradient(180deg, rgba(16,20,25,0), rgba(16,20,25,.98) 24%, rgba(16,20,25,1));
 }
 .section-modal__body .file-upload-label{
   min-height:72px;
   padding:12px;
   gap:6px;
 }
 .section-modal__body .file-upload-icon{
   width:22px;
   height:22px;
 }
 .section-modal__body .document-upload-item{
   padding:12px;
 }
 .section-modal__body .document-upload-grid{
   gap:12px;
 }
 .section-modal__body .form-group textarea{
   min-height:72px;
 }

 .image-modal{
   position:fixed;
   inset:0;
   display:none;
   align-items:center;
   justify-content:center;
   background:rgba(0,0,0,.78);
   z-index:10001;
 }
 .image-modal.open{display:flex;}
 .image-modal-content{
   width:min(92vw,980px);
   max-height:86vh;
   background:rgba(16,20,25,.85);
   border:1px solid var(--border);
   border-radius:18px;
   overflow:hidden;
   box-shadow:var(--shadow-xl);
 }
 .image-modal-header{
   display:flex;
   align-items:center;
   justify-content:space-between;
   gap:12px;
   padding:14px 16px;
   border-bottom:1px solid var(--border);
 }
 .image-modal-title{font-weight:900;color:var(--text-primary);}
 .image-modal-close{
   background:transparent;
   border:0;
   color:var(--text-secondary);
   font-size:1.6rem;
   cursor:pointer;
 }
 .image-modal-body{padding:14px;}
 .image-modal-body img{
   width:100%;
   height:auto;
   max-height:72vh;
   object-fit:contain;
   border-radius:14px;
   border:1px solid var(--border);
   background:rgba(255,255,255,.02);
 }

 body.modal-open{overflow:hidden;}

 .mobile-actionbar{
   position:fixed;
   left:0;right:0;bottom:0;
   display:none;
   gap:10px;
   padding:12px 14px;
   background:rgba(16,20,25,.92);
   border-top:1px solid var(--border);
   backdrop-filter:blur(16px);
   z-index:9998;
 }
 .mobile-actionbar .btn{flex:1;min-width:0;padding:12px 14px;font-size:.95rem;}
 .mobile-actionbar .btn-secondary{border-width:1px;}

/* ===== CONTAINER ===== */
.container{
  max-width:1200px;margin:80px auto;padding:40px;
  background:var(--card);border-radius:var(--radius);
  box-shadow:var(--shadow-xl),0 0 0 1px var(--border);
  position:relative;overflow:visible;
}
.container::before{
  content:"";position:absolute;top:0;left:0;right:0;height:4px;
  background:var(--gradient);border-radius:var(--radius) var(--radius) 0 0;
}
h2{
  font-size:2.2rem;color:var(--text-primary);margin-bottom:8px;
  background:var(--gradient);-webkit-background-clip:text;
  -webkit-text-fill-color:transparent;font-weight:900;
  text-align:center;margin-bottom:32px;
}
 
 .container > h2{
   letter-spacing:-.02em;
 }
 
 .profile-section + .profile-section{
   margin-top:18px;
 }

/* ===== VERIFICATION STATUS CARD ===== */
.verification-card{
  background:var(--card);border:1px solid var(--border);
  border-radius:var(--radius);padding:24px;margin-bottom:24px;
  box-shadow:var(--shadow-lg);position:relative;overflow:hidden;
}
.verification-card::before{
  content:"";position:absolute;top:0;left:0;right:0;height:3px;
  background:var(--gradient);border-radius:var(--radius) var(--radius) 0 0;
}
.verification-header{
  display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;
}
.verification-title{
  font-weight:800;font-size:1.3rem;color:var(--text-primary);
}
.status-badge{
  padding:6px 12px;border-radius:999px;font-weight:700;font-size:.8rem;
  text-transform:uppercase;letter-spacing:.5px;
}
.status-badge.incomplete{background:rgba(245,158,11,.15);color:var(--warning);}
.status-badge.pending{background:rgba(59,130,246,.15);color:#3b82f6;}
.status-badge.approved{background:rgba(16,185,129,.15);color:var(--success);}
.status-badge.rejected{background:rgba(239,68,68,.15);color:var(--error);}

/* ===== FLASH MESSAGES ===== */
.flash-message{
  padding:16px 24px;border-radius:var(--radius);margin-bottom:24px;
  text-align:center;font-weight:600;position:relative;overflow:hidden;
  backdrop-filter:blur(10px);border:1px solid;
}
.flash-success{
  background:linear-gradient(135deg,rgba(16,185,129,.1),rgba(5,150,105,.1));
  color:#10b981;border-color:rgba(16,185,129,.3);
  box-shadow:0 4px 20px rgba(16,185,129,.1);
}
.flash-error{
  background:linear-gradient(135deg,rgba(239,68,68,.1),rgba(220,38,38,.1));
  color:#ef4444;border-color:rgba(239,68,68,.3);
  box-shadow:0 4px 20px rgba(239,68,68,.1);
}

/* ===== PROFILE HEADER ===== */
.profile-header{
  background:var(--card);border:1px solid var(--border);
  border-radius:var(--radius);padding:32px;margin-bottom:24px;
  box-shadow:var(--shadow-lg);position:relative;overflow:hidden;
}
.profile-header::before{
  content:"";position:absolute;top:0;left:0;right:0;height:4px;
  background:var(--gradient);border-radius:var(--radius) var(--radius) 0 0;
}
.profile-avatar-section{
  display:flex;align-items:center;gap:24px;
}
.profile-avatar-section{
  flex-wrap:wrap;
}
.profile-avatar{
  position:relative;width:120px;height:120px;flex-shrink:0;
}
.avatar-image{
  width:100%;height:100%;border-radius:50%;object-fit:cover;
  border:4px solid var(--brand);box-shadow:0 8px 24px rgba(93,208,255,.3);
}
.avatar-placeholder{
  width:100%;height:100%;border-radius:50%;
  background:var(--gradient);display:flex;align-items:center;justify-content:center;
  color:#041f2a;font-size:2.5rem;font-weight:900;
  border:4px solid var(--brand);box-shadow:0 8px 24px rgba(93,208,255,.3);
}
.avatar-overlay{
  position:absolute;bottom:0;right:0;width:36px;height:36px;
  background:var(--brand);border-radius:50%;display:flex;align-items:center;
  justify-content:center;border:3px solid var(--card);cursor:pointer;
  transition:all .3s ease;box-shadow:0 4px 12px rgba(93,208,255,.4);
}
.avatar-overlay:hover{
  transform:scale(1.1);box-shadow:0 6px 20px rgba(93,208,255,.6);
}
.avatar-edit-btn{
  background:none;border:none;color:#041f2a;font-size:1rem;cursor:pointer;
  padding:0;margin:0;
}
.avatar-save-wrap{
  display:flex;flex-direction:column;align-items:flex-start;gap:10px;margin-top:4px;
}
.btn-save-photo{
  display:none;align-items:center;justify-content:center;gap:8px;
  padding:10px 16px;border-radius:12px;border:0;cursor:pointer;
  background:var(--gradient);color:#041f2a;font-weight:900;font-size:.9rem;
  box-shadow:0 8px 20px rgba(93,208,255,.28);
  transition:transform .18s ease, box-shadow .18s ease, opacity .18s ease;
}
.btn-save-photo.is-visible{display:inline-flex;}
.btn-save-photo:hover{transform:translateY(-1px);box-shadow:0 10px 24px rgba(93,208,255,.4);}
.btn-save-photo:disabled{opacity:.65;cursor:wait;transform:none;}
.avatar-save-hint{
  display:none;color:var(--text-secondary);font-size:.82rem;font-weight:600;
}
.avatar-save-hint.is-visible{display:block;}
.profile-info{
  flex:1;
}
.profile-info h3{
  font-size:1.8rem;font-weight:800;color:var(--text-primary);
  margin:0 0 8px 0;background:var(--gradient);
  -webkit-background-clip:text;-webkit-text-fill-color:transparent;
}
.profile-email{
  color:var(--text-secondary);font-size:1rem;margin:0 0 16px 0;
}
.profile-badges{
  display:flex;gap:12px;flex-wrap:wrap;
}
.profile-badge{
  padding:6px 12px;border-radius:999px;font-size:.8rem;
  font-weight:700;text-transform:uppercase;letter-spacing:.5px;
}
.profile-badge.incomplete{background:rgba(245,158,11,.15);color:var(--warning);}
.profile-badge.pending_approval{background:rgba(59,130,246,.15);color:#3b82f6;}
.profile-badge.approved{background:rgba(16,185,129,.15);color:var(--success);}
.profile-badge.rejected{background:rgba(239,68,68,.15);color:var(--error);}
.profile-badge.unverified{background:rgba(107,114,128,.15);color:var(--text-muted);}
.profile-badge.pending{background:rgba(59,130,246,.15);color:#3b82f6;}
.profile-badge.verified{background:rgba(16,185,129,.15);color:var(--success);}

/* ===== PROFILE SECTIONS ===== */
.profile-section{
  background:var(--card);border:1px solid var(--border);
  border-radius:var(--radius);padding:32px;margin-bottom:24px;
  box-shadow:var(--shadow-lg);position:relative;overflow:hidden;
}
.profile-section::before{
  content:"";position:absolute;top:0;left:0;width:4px;height:100%;
  background:var(--gradient);border-radius:0 4px 4px 0;
}
.section-title{
  font-size:1.4rem;font-weight:800;color:var(--text-primary);
  margin-bottom:24px;background:var(--gradient);
  -webkit-background-clip:text;-webkit-text-fill-color:transparent;
}
.form-grid{
  display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));
  gap:20px;
}
.form-grid{
  align-items:start;
}
.form-group{
  display:flex;flex-direction:column;
}
.form-group label{
  font-weight:600;color:var(--text-secondary);margin-bottom:8px;
  font-size:.9rem;text-transform:uppercase;letter-spacing:.5px;
}
.form-group input,
.form-group select,
.form-group textarea{
  padding:12px 16px;border-radius:var(--radius-sm);
  border:2px solid var(--border);background:var(--card);
  color:var(--text-primary);font-size:1rem;transition:all .3s ease;
}
.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus{
  border-color:var(--brand);outline:none;
  box-shadow:0 0 0 4px rgba(93,208,255,.1);
}
.form-group textarea{min-height:100px;resize:vertical;}

/* ===== FILE UPLOAD ===== */
.file-upload{
  position:relative;display:block;width:100%;
}
.file-upload input[type="file"]{
  position:absolute;opacity:0;width:100%;height:100%;cursor:pointer;inset:0;z-index:2;
}
.file-upload-label{
  display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;
  min-height:110px;padding:18px 16px;border:2px dashed var(--border);
  border-radius:var(--radius-sm);text-align:center;cursor:pointer;
  transition:all .3s ease;background:var(--card);
  user-select:none;font-weight:700;color:var(--text-primary);
}
.file-upload-label:hover{
  border-color:var(--brand);background:rgba(93,208,255,.05);
}
.file-upload-label.has-file{
  border-style:solid;border-color:rgba(93,208,255,.35);
  background:rgba(93,208,255,.06);
}
.file-upload-icon{
  width:28px;height:28px;color:var(--brand);flex-shrink:0;
}
.file-upload-title{
  font-size:0.95rem;font-weight:800;letter-spacing:.01em;
}
.file-upload-side{
  font-size:0.75rem;font-weight:700;color:var(--text-muted);
  text-transform:uppercase;letter-spacing:.06em;
}
.file-name{
  margin-top:10px;padding:10px 12px;border-radius:var(--radius-sm);
  border:1px solid var(--border);background:rgba(255,255,255,.03);
  color:var(--text-secondary);font-size:0.85rem;font-weight:600;
  word-break:break-all;line-height:1.4;
}
.file-name.is-empty{display:none;}
.document-upload-grid{
  display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:20px;
}
.document-upload-item{
  display:flex;flex-direction:column;padding:16px;
  border-radius:var(--radius);border:1px solid var(--border);
  background:rgba(255,255,255,.02);
  box-shadow:inset 0 1px 0 rgba(255,255,255,.03);
}

/* ===== BUTTONS ===== */
.btn{
  background:var(--gradient);color:#041f2a;font-weight:800;
  border:0;border-radius:var(--radius-sm);padding:14px 28px;cursor:pointer;
  transition:all .3s ease;font-size:1rem;position:relative;overflow:hidden;
}
.btn::before{
  content:"";position:absolute;top:0;left:-100%;width:100%;height:100%;
  background:linear-gradient(90deg,transparent,rgba(255,255,255,.3),transparent);
  transition:left .5s ease;
}
.btn:hover{
  transform:translateY(-2px);box-shadow:0 8px 24px rgba(93,208,255,.3);
}
.btn:hover::before{left:100%}
.btn-secondary{
  background:var(--card);color:var(--text-primary);border:2px solid var(--border);
}
.btn-secondary:hover{
  background:var(--glass);border-color:var(--brand);
}
.btn-success{
  background:linear-gradient(135deg,var(--success),#059669);color:#fff;
}
.btn-success:hover{box-shadow:0 8px 24px rgba(16,185,129,.3);}
.btn-warning{
  background:linear-gradient(135deg,var(--warning),#d97706);color:#000;
}
.btn-warning:hover{box-shadow:0 8px 24px rgba(245,158,11,.3);}
.btn:disabled{
  opacity:.5;cursor:not-allowed;transform:none;
}
.btn:disabled:hover{transform:none;box-shadow:none;}

.form-actions{
  display:flex;
  gap:12px;
  margin-top:32px;
  flex-wrap:wrap;
  align-items:center;
}
.form-actions .btn{
  min-width:180px;
}
.form-actions .hint{
  color:var(--text-secondary);
  font-weight:600;
}

/* ===== STATS GRID ===== */
.stats-grid{
  display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));
  gap:20px;margin-bottom:32px;
}
.stat-card{
  background:var(--card);border:1px solid var(--border);
  border-radius:var(--radius);padding:24px;text-align:center;
  box-shadow:var(--shadow-lg);transition:all .3s ease;
}
.stat-card:hover{
  transform:translateY(-4px);box-shadow:var(--shadow-xl);
}
.stat-value{
  font-size:2.5rem;font-weight:900;margin-bottom:8px;
  background:var(--gradient);-webkit-background-clip:text;
  -webkit-text-fill-color:transparent;
}
.stat-label{
  color:var(--text-secondary);font-weight:600;text-transform:uppercase;
  letter-spacing:.5px;font-size:.9rem;
}

/* ===== RENTAL HISTORY ===== */
.history-table{
  width:100%;border-collapse:collapse;background:transparent;
}
.history-table th,
.history-table td{
  padding:16px;text-align:left;border-bottom:1px solid var(--border);
}
.history-table th{
  color:var(--brand2);font-weight:800;text-transform:uppercase;
  letter-spacing:.5px;background:rgba(93,208,255,.05);
}
.history-table td{color:var(--text-primary);}
.history-table tr:hover{background:rgba(93,208,255,.05);}

/* ===== RESPONSIVE ===== */
@media (max-width:768px){
  .container{padding:20px;margin:60px auto;}
  .form-grid{grid-template-columns:1fr;}
  .stats-grid{grid-template-columns:repeat(2,1fr);}
  .mobile-actionbar{display:flex;}
  .container{padding-bottom:92px;}
  .section-modal{
    align-items:stretch;
    padding:8px;
  }
  .section-modal__dialog{
    width:calc(100vw - 16px);
    height:calc(100vh - 16px);
    height:calc(100dvh - 16px);
    max-height:calc(100vh - 16px);
    max-height:calc(100dvh - 16px);
    border-radius:16px;
  }
  body.modal-open .mobile-actionbar{display:none;}
}
</style>
</head>
<body>

<?php include __DIR__ . '/includes/user_navbar.php'; ?>

<div class="container">
  <h2>My Profile</h2>

  <?php if(!empty($flash)): ?>
    <div class="flash-message flash-success"><?= htmlspecialchars($flash) ?></div>
  <?php endif; ?>

  <?php if(!empty($flash_error)): ?>
    <div class="flash-message flash-error"><?= htmlspecialchars($flash_error) ?></div>
  <?php endif; ?>

  <div class="image-modal" id="imageModal" aria-hidden="true">
    <div class="image-modal-content">
      <div class="image-modal-header">
        <div class="image-modal-title" id="imageModalTitle">Preview</div>
        <button type="button" class="image-modal-close" id="imageModalClose">×</button>
      </div>
      <div class="image-modal-body">
        <img id="imageModalImg" src="" alt="Preview">
      </div>
    </div>
  </div>

  <div class="mobile-actionbar">
    <button type="button" class="btn btn-secondary" id="mobileSaveBtn">Save</button>
    <button type="button" class="btn btn-success" id="mobileSubmitBtn">Submit</button>
  </div>

  <!-- ===== VERIFICATION STATUS ===== -->
  <div class="verification-card">
    <div class="verification-header">
      <div class="verification-title">Verification Status</div>
    </div>
    <div style="color:var(--text-secondary);line-height:1.6;">
      <?php if($profile_status === 'incomplete'): ?>
        <strong>📝 Complete your profile</strong><br>
        Fill in your information and upload required documents to submit for verification.
      <?php elseif($profile_status === 'pending_approval'): ?>
        <strong>⏳ Under Review</strong><br>
        Your profile is being reviewed by our team. This usually takes 24-48 hours.
      <?php elseif($profile_status === 'approved'): ?>
        <strong>✅ Verified</strong><br>
        Your profile has been approved! You can now rent vehicles.
      <?php elseif($profile_status === 'rejected'): ?>
        <strong>❌ Verification Failed</strong><br>
        <?php if(!empty($user['rejection_reason'])): ?>
          Reason: <?= htmlspecialchars($user['rejection_reason']) ?><br>
        <?php endif; ?>
        Please update your information and resubmit.
      <?php endif; ?>
    </div>
  </div>

  <!-- ===== PROFILE PICTURE SECTION ===== -->
  <div class="profile-header">
    <div class="profile-avatar-section">
      <div class="profile-avatar">
        <?php if(!empty($user['profile_photo']) && is_file(__DIR__ . '/' . ltrim($user['profile_photo'], '/'))): ?>
          <img src="<?= htmlspecialchars($user['profile_photo']) ?>" alt="Profile Photo" class="avatar-image">
        <?php else: ?>
          <div class="avatar-placeholder">
            <?= strtoupper(substr($user['full_name'], 0, 2)) ?>
          </div>
        <?php endif; ?>
        <div class="avatar-overlay">
          <button type="button" class="avatar-edit-btn" onclick="document.getElementById('profile_photo').click()" title="Choose photo" aria-label="Choose profile photo">
            📷
          </button>
        </div>
      </div>
      <div class="profile-info">
        <h3><?= htmlspecialchars($user['full_name']) ?></h3>
        <p class="profile-email"><?= htmlspecialchars($user['email']) ?></p>
        <div class="profile-badges">
          <span class="profile-badge <?= $profile_status ?>"><?= ucfirst(str_replace('_',' ', $profile_status)) ?></span>
          <span class="profile-badge <?= $verification_status ?>"><?= ucfirst(str_replace('_',' ', $verification_status)) ?></span>
        </div>
        <form method="POST" enctype="multipart/form-data" id="avatarPhotoForm" class="avatar-save-wrap">
          <input type="hidden" name="action" value="save_profile_photo">
          <input type="file" name="profile_photo" accept="image/*" id="profile_photo" style="display:none;">
          <p class="avatar-save-hint" id="avatarSaveHint">New photo selected — click save to apply it.</p>
          <button type="submit" class="btn-save-photo" id="saveProfilePhotoBtn" disabled>
            Save Profile Picture
          </button>
        </form>
      </div>
    </div>
  </div>

  <!-- ===== PROFILE FORM ===== -->
  <form method="POST" enctype="multipart/form-data" id="profileForm">
    <input type="hidden" name="action" value="update_profile">

    <div class="section-cards">
      <button type="button" class="section-card" data-open-modal="modal-basic" aria-haspopup="dialog">
        <div class="section-card__title"><span class="section-card__icon">👤</span>Basic Information</div>
        <div style="display:flex;align-items:center;gap:12px;">
          <div class="section-card__meta">Tap to view</div>
          <div class="section-card__chevron">›</div>
        </div>
      </button>

      <button type="button" class="section-card" data-open-modal="modal-documents" aria-haspopup="dialog">
        <div class="section-card__title"><span class="section-card__icon">🪪</span>Documents</div>
        <div style="display:flex;align-items:center;gap:12px;">
          <div class="section-card__meta">Tap to view</div>
          <div class="section-card__chevron">›</div>
        </div>
      </button>

      <button type="button" class="section-card" data-open-modal="modal-rentals" aria-haspopup="dialog">
        <div class="section-card__title"><span class="section-card__icon">📊</span>Rentals</div>
        <div style="display:flex;align-items:center;gap:12px;">
          <div class="section-card__meta"><?= (int)($stats['total_rentals'] ?? 0) ?> total</div>
          <div class="section-card__chevron">›</div>
        </div>
      </button>
    </div>

    <!-- Basic Information Modal -->
    <div class="section-modal" id="modal-basic" aria-hidden="true">
      <div class="section-modal__backdrop" data-close-modal></div>
      <div class="section-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="modal-basic-title">
        <div class="section-modal__header">
          <div class="section-modal__title" id="modal-basic-title"><span class="section-card__icon">👤</span>Basic Information</div>
          <button type="button" class="section-modal__close" data-close-modal aria-label="Close">×</button>
        </div>
        <div class="section-modal__body">
          <div class="form-grid">
            <div class="form-group">
              <label>Full Name</label>
              <input type="text" name="full_name" value="<?= htmlspecialchars($user['full_name']) ?>" readonly>
            </div>
            
            <div class="form-group">
              <label>Email Address</label>
              <input type="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" required>
            </div>
            
            <div class="form-group">
              <label>Contact Number</label>
              <input type="tel" name="contact_no" value="<?= htmlspecialchars($user['contact_no']) ?>" required>
            </div>
            
            <div class="form-group">
              <label>City</label>
              <input type="text" name="city" value="<?= htmlspecialchars($user['city']) ?>" placeholder="Enter your city">
            </div>
            
            <div class="form-group" style="grid-column:1/-1;">
              <label>Address</label>
              <textarea name="address" placeholder="Enter your complete address"><?= htmlspecialchars($user['address']) ?></textarea>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Documents Modal -->
    <div class="section-modal" id="modal-documents" aria-hidden="true">
      <div class="section-modal__backdrop" data-close-modal></div>
      <div class="section-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="modal-documents-title">
        <div class="section-modal__header">
          <div class="section-modal__title" id="modal-documents-title"><span class="section-card__icon">🪪</span>Documents</div>
          <button type="button" class="section-modal__close" data-close-modal aria-label="Close">×</button>
        </div>
        <div class="section-modal__body">
            <div class="form-grid">
              <div class="form-group">
                <label>Driver's License Number</label>
                <input type="text" name="driver_license_no" value="<?= htmlspecialchars($user['driver_license_no']) ?>" placeholder="Enter license number">
              </div>
              
              <div class="form-group">
                <label>License Expiry Date</label>
                <input type="date" name="driver_license_expiry" value="<?= htmlspecialchars($user['driver_license_expiry']) ?>">
              </div>
              
              <div class="form-group">
                <label>ID Type</label>
                <select name="id_type">
                  <option value="">Select ID Type</option>
                  <option value="National ID" <?= ($user['id_type'] === 'National ID') ? 'selected' : '' ?>>National ID</option>
                  <option value="Passport" <?= ($user['id_type'] === 'Passport') ? 'selected' : '' ?>>Passport</option>
                  <option value="SSS ID" <?= ($user['id_type'] === 'SSS ID') ? 'selected' : '' ?>>SSS ID</option>
                  <option value="UMID" <?= ($user['id_type'] === 'UMID') ? 'selected' : '' ?>>UMID</option>
                  <option value="Voter's ID" <?= ($user['id_type'] === "Voter's ID") ? 'selected' : '' ?>>Voter's ID</option>
                </select>
              </div>
              
              <div class="form-group">
                <label>ID Number</label>
                <input type="text" name="id_number" value="<?= htmlspecialchars($user['id_number']) ?>" placeholder="Enter ID number">
              </div>
              
              <div class="form-group">
                <label>Emergency Contact Name</label>
                <input type="text" name="emergency_name" value="<?= htmlspecialchars($user['emergency_name']) ?>" placeholder="Emergency contact person">
              </div>
              
              <div class="form-group">
                <label>Emergency Contact Phone</label>
                <input type="tel" name="emergency_phone" value="<?= htmlspecialchars($user['emergency_phone']) ?>" placeholder="Emergency contact number">
              </div>
              
              <?php
                $importIcon = '<svg class="file-upload-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>';
                $licenseFront = !empty($userDocuments['License Front']) ? $userDocuments['License Front'] : ($user['license_photo'] ?? '');
                $licenseBack = $userDocuments['License Back'] ?? '';
                $idFront = !empty($userDocuments['ID Front']) ? $userDocuments['ID Front'] : ($user['valid_id_photo'] ?? '');
                $idBack = $userDocuments['ID Back'] ?? '';
                $licenseFrontName = $licenseFront !== '' ? basename($licenseFront) : '';
                $licenseBackName = $licenseBack !== '' ? basename($licenseBack) : '';
                $idFrontName = $idFront !== '' ? basename($idFront) : '';
                $idBackName = $idBack !== '' ? basename($idBack) : '';
              ?>
              <div class="form-group" style="grid-column:1/-1;">
                <label>Driver's License Photos</label>
                <div class="document-upload-grid">
                  <div class="document-upload-item">
                    <div class="file-upload">
                      <input type="file" name="license_photo_front" accept="image/*" id="license_photo_front">
                      <label for="license_photo_front" class="file-upload-label<?= $licenseFrontName !== '' ? ' has-file' : '' ?>">
                        <?= $importIcon ?>
                        <span class="file-upload-title">Attach File</span>
                        <span class="file-upload-side">Front Side</span>
                      </label>
                    </div>
                    <div class="file-name<?= $licenseFrontName === '' ? ' is-empty' : '' ?>" data-file-name><?= htmlspecialchars($licenseFrontName) ?></div>
                  </div>
                  
                  <div class="document-upload-item">
                    <div class="file-upload">
                      <input type="file" name="license_photo_back" accept="image/*" id="license_photo_back">
                      <label for="license_photo_back" class="file-upload-label<?= $licenseBackName !== '' ? ' has-file' : '' ?>">
                        <?= $importIcon ?>
                        <span class="file-upload-title">Attach File</span>
                        <span class="file-upload-side">Back Side</span>
                      </label>
                    </div>
                    <div class="file-name<?= $licenseBackName === '' ? ' is-empty' : '' ?>" data-file-name><?= htmlspecialchars($licenseBackName) ?></div>
                  </div>
                </div>
              </div>
              
              <div class="form-group" style="grid-column:1/-1;">
                <label>Valid ID Photos</label>
                <div class="document-upload-grid">
                  <div class="document-upload-item">
                    <div class="file-upload">
                      <input type="file" name="valid_id_photo_front" accept="image/*" id="valid_id_photo_front">
                      <label for="valid_id_photo_front" class="file-upload-label<?= $idFrontName !== '' ? ' has-file' : '' ?>">
                        <?= $importIcon ?>
                        <span class="file-upload-title">Attach File</span>
                        <span class="file-upload-side">Front Side</span>
                      </label>
                    </div>
                    <div class="file-name<?= $idFrontName === '' ? ' is-empty' : '' ?>" data-file-name><?= htmlspecialchars($idFrontName) ?></div>
                  </div>
                  
                  <div class="document-upload-item">
                    <div class="file-upload">
                      <input type="file" name="valid_id_photo_back" accept="image/*" id="valid_id_photo_back">
                      <label for="valid_id_photo_back" class="file-upload-label<?= $idBackName !== '' ? ' has-file' : '' ?>">
                        <?= $importIcon ?>
                        <span class="file-upload-title">Attach File</span>
                        <span class="file-upload-side">Back Side</span>
                      </label>
                    </div>
                    <div class="file-name<?= $idBackName === '' ? ' is-empty' : '' ?>" data-file-name><?= htmlspecialchars($idBackName) ?></div>
                  </div>
                </div>
              </div>
              
              <div class="form-group" style="grid-column:1/-1;">
                <label>Additional Notes (Optional)</label>
                <textarea name="notes" placeholder="Any additional information you'd like to provide"><?= htmlspecialchars($user['notes']) ?></textarea>
              </div>
            </div>

            <div class="form-actions">
              <button type="submit" class="btn">Save Changes</button>
              
              <?php if($profile_status === 'incomplete' || $profile_status === 'rejected'): ?>
                <button type="submit" name="action" value="submit_verification" class="btn btn-success" 
                        <?= !$can_submit ? 'disabled' : '' ?>>
                  📤 Submit for Verification
                </button>
                <?php if(!$can_submit): ?>
                  <small class="hint">
                    Complete all required fields to submit
                  </small>
                <?php endif; ?>
              <?php endif; ?>
            </div>
        </div>
      </div>
    </div>

    <!-- Rentals Modal -->
    <div class="section-modal" id="modal-rentals" aria-hidden="true">
      <div class="section-modal__backdrop" data-close-modal></div>
      <div class="section-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="modal-rentals-title">
        <div class="section-modal__header">
          <div class="section-modal__title" id="modal-rentals-title"><span class="section-card__icon">📊</span>Rentals</div>
          <button type="button" class="section-modal__close" data-close-modal aria-label="Close">×</button>
        </div>
        <div class="section-modal__body">
          <div class="stats-grid" style="margin-bottom:20px;">
            <div class="stat-card">
              <div class="stat-value"><?= $stats['total_rentals'] ?></div>
              <div class="stat-label">Total Rentals</div>
            </div>
            <div class="stat-card">
              <div class="stat-value"><?= $stats['completed_rentals'] ?></div>
              <div class="stat-label">Completed</div>
            </div>
            <div class="stat-card">
              <div class="stat-value">₱<?= number_format($stats['total_spent'], 0) ?></div>
              <div class="stat-label">Total Spent</div>
            </div>
            <div class="stat-card">
              <div class="stat-value"><?= $stats['current_rentals'] ?></div>
              <div class="stat-label">Current Rentals</div>
            </div>
          </div>

          <?php if (!empty($rentalHistory)): ?>
          <div class="profile-section" style="margin-bottom:0;">
            <h3 class="section-title">Recent Rental History</h3>
            <table class="history-table">
              <thead>
                <tr>
                  <th>Vehicle</th>
                  <th>Period</th>
                  <th>Status</th>
                  <th>Cost</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($rentalHistory as $rental): ?>
                <tr>
                  <td>
                    <div style="font-weight: 600;"><?= htmlspecialchars($rental['make_model']) ?></div>
                    <div style="font-size: 0.85rem; color: var(--text-secondary);"><?= htmlspecialchars($rental['plate_no']) ?></div>
                  </td>
                  <td>
                    <div><?= date('M j, Y', strtotime($rental['start_date'])) ?></div>
                    <div style="font-size: 0.85rem; color: var(--text-secondary);">to <?= date('M j, Y', strtotime($rental['end_date'])) ?></div>
                  </td>
                  <td>
                    <span style="color: <?= $rental['status'] === 'completed' ? 'var(--success)' : ($rental['status'] === 'ongoing' ? 'var(--brand)' : 'var(--warning)') ?>; font-weight: 700;">
                      <?= ucfirst($rental['status']) ?>
                    </span>
                  </td>
                  <td style="font-weight: 600; color: var(--brand2);">
                    ₱<?= number_format($rental['total_cost'], 0) ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php else: ?>
          <div style="color:var(--text-secondary);font-weight:700;">No rentals yet.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>

  </form>

</div>

<script>
// File upload: show filename only (no image preview for documents)
document.querySelectorAll('input[type="file"]').forEach(input => {
  input.addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (!file) return;

    // Profile photo still previews in the avatar
    if (input.id === 'profile_photo') {
      if (!file.type.startsWith('image/')) return;
      const reader = new FileReader();
      reader.onload = function(ev) {
        const avatarImage = document.querySelector('.avatar-image');
        const avatarPlaceholder = document.querySelector('.avatar-placeholder');
        
        if (avatarImage) {
          avatarImage.src = ev.target.result;
        } else if (avatarPlaceholder) {
          const newImg = document.createElement('img');
          newImg.src = ev.target.result;
          newImg.className = 'avatar-image';
          newImg.alt = 'Profile Photo';
          avatarPlaceholder.parentNode.replaceChild(newImg, avatarPlaceholder);
        }
        const saveBtn = document.getElementById('saveProfilePhotoBtn');
        const hint = document.getElementById('avatarSaveHint');
        if (saveBtn) {
          saveBtn.disabled = false;
          saveBtn.classList.add('is-visible');
        }
        if (hint) hint.classList.add('is-visible');
      };
      reader.readAsDataURL(file);
      return;
    }

    const item = input.closest('.document-upload-item');
    if (!item) return;

    const label = item.querySelector('.file-upload-label');
    const nameEl = item.querySelector('[data-file-name]');
    if (label) label.classList.add('has-file');
    if (nameEl) {
      nameEl.textContent = file.name;
      nameEl.classList.remove('is-empty');
    }
  });
});

const avatarPhotoForm = document.getElementById('avatarPhotoForm');
if (avatarPhotoForm) {
  avatarPhotoForm.addEventListener('submit', function() {
    const saveBtn = document.getElementById('saveProfilePhotoBtn');
    if (saveBtn) {
      saveBtn.disabled = true;
      saveBtn.textContent = 'Saving…';
    }
  });
}

function openImageModal(src, title) {
  const modal = document.getElementById('imageModal');
  const img = document.getElementById('imageModalImg');
  const t = document.getElementById('imageModalTitle');
  if (!modal || !img || !t) return;
  img.src = src;
  t.textContent = title || 'Preview';
  modal.classList.add('open');
  modal.setAttribute('aria-hidden', 'false');
}
function closeImageModal() {
  const modal = document.getElementById('imageModal');
  if (!modal) return;
  modal.classList.remove('open');
  modal.setAttribute('aria-hidden', 'true');
  const img = document.getElementById('imageModalImg');
  if (img) img.src = '';
}

document.addEventListener('click', function(e) {
  const target = e.target;
  if (target && target.id === 'imageModalClose') {
    closeImageModal();
    return;
  }
  const modal = document.getElementById('imageModal');
  if (modal && target === modal) {
    closeImageModal();
    return;
  }
  if (target && target.classList && target.classList.contains('avatar-image')) {
    openImageModal(target.src, 'Profile Photo');
  }
});

document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    closeImageModal();
    closeSectionModal();
  }
});

function openSectionModal(id) {
  const modal = document.getElementById(id);
  if (!modal) return;
  document.querySelectorAll('.section-modal.is-open').forEach(m => {
    if (m !== modal) closeSectionModal(m);
  });
  // Ensure modal is attached to <body> so it is not clipped by .container
  if (modal.parentElement !== document.body) {
    document.body.appendChild(modal);
  }
  modal.classList.remove('is-closing');
  modal.classList.add('is-open');
  modal.setAttribute('aria-hidden', 'false');
  document.body.classList.add('modal-open');
}

function closeSectionModal(target) {
  const modal = target && target.classList ? target : document.querySelector('.section-modal.is-open');
  if (!modal || !modal.classList.contains('is-open')) return;
  modal.classList.add('is-closing');
  window.setTimeout(() => {
    modal.classList.remove('is-open', 'is-closing');
    modal.setAttribute('aria-hidden', 'true');
    if (!document.querySelector('.section-modal.is-open')) {
      document.body.classList.remove('modal-open');
    }
  }, 220);
}

// Keep modal fields linked to the profile form after moving modals to <body>
(function bindSectionModalsToForm() {
  const form = document.getElementById('profileForm');
  if (!form) return;
  document.querySelectorAll('.section-modal').forEach(modal => {
    modal.querySelectorAll('input, select, textarea, button[type="submit"]').forEach(el => {
      if (!el.getAttribute('form')) el.setAttribute('form', 'profileForm');
    });
    document.body.appendChild(modal);
  });
})();

document.querySelectorAll('[data-open-modal]').forEach(btn => {
  btn.addEventListener('click', function() {
    openSectionModal(btn.getAttribute('data-open-modal'));
  });
});

document.querySelectorAll('[data-close-modal]').forEach(el => {
  el.addEventListener('click', function() {
    const modal = el.closest('.section-modal');
    closeSectionModal(modal);
  });
});

const mobileSaveBtn = document.getElementById('mobileSaveBtn');
if (mobileSaveBtn) {
  mobileSaveBtn.addEventListener('click', function() {
    const btn = document.querySelector('.form-actions button[type="submit"].btn');
    if (btn) btn.click();
  });
}
const mobileSubmitBtn = document.getElementById('mobileSubmitBtn');
if (mobileSubmitBtn) {
  mobileSubmitBtn.addEventListener('click', function() {
    const btn = document.querySelector('.form-actions button[name="action"][value="submit_verification"]');
    if (btn && !btn.disabled) {
      btn.click();
    }
  });
}

// ===== COMPREHENSIVE FIELD VALIDATION =====
document.addEventListener('DOMContentLoaded', function() {
  const form = document.querySelector('form');
  
  // Real-time validation feedback
  const addFieldValidation = (fieldName, validationRules) => {
    const field = form.querySelector(`[name="${fieldName}"]`);
    if (!field) return;
    
    field.addEventListener('blur', function() {
      validateField(this, validationRules);
    });
    
    field.addEventListener('input', function() {
      // Clear error on input
      const errorMsg = this.parentElement.querySelector('.field-error');
      if (errorMsg) errorMsg.remove();
      this.style.borderColor = '';
    });
  };
  
  const validateField = (field, rules) => {
    // Contact is shown as "09XX XXX XXXX"; validate digits so spacing does not fail a valid number.
    const value = (field.name === 'contact_no' || field.name === 'emergency_phone')
      ? field.value.replace(/\D/g, '')
      : field.value.trim();
    let errorMessage = '';
    
    // Remove existing error
    const existingError = field.parentElement.querySelector('.field-error');
    if (existingError) existingError.remove();
    
    // Apply validation rules
    rules.forEach(rule => {
      if (errorMessage) return; // Stop at first error
      
      if (rule.required && !value) {
        errorMessage = rule.message || 'This field is required';
      } else if (rule.pattern && value && !rule.pattern.test(value)) {
        errorMessage = rule.message || 'Invalid format';
      } else if (rule.minLength && value.length < rule.minLength) {
        errorMessage = rule.message || `Minimum ${rule.minLength} characters required`;
      } else if (rule.maxLength && value.length > rule.maxLength) {
        errorMessage = rule.message || `Maximum ${rule.maxLength} characters allowed`;
      } else if (rule.validator && value && !rule.validator(value)) {
        errorMessage = rule.message || 'Invalid value';
      }
    });
    
    // Show/hide error
    if (errorMessage) {
      field.style.borderColor = 'var(--error)';
      const errorDiv = document.createElement('div');
      errorDiv.className = 'field-error';
      errorDiv.style.cssText = 'color: var(--error); font-size: 0.85rem; margin-top: 4px;';
      errorDiv.textContent = errorMessage;
      field.parentElement.appendChild(errorDiv);
      return false;
    } else {
      field.style.borderColor = 'var(--success)';
      return true;
    }
  };
  
  // Define validation rules for each field
  const validationRules = {
    email: [
      { required: true, message: 'Email address is required' },
      { pattern: /^[^\s@]+@[^\s@]+\.[^\s@]+$/, message: 'Please enter a valid email address' }
    ],
    contact_no: [
      { required: true, message: 'Contact number is required' },
      { pattern: /^09\d{9}$/, message: 'Please enter a valid Philippine mobile number (09XX XXX XXXX)' }
    ],
    city: [
      { required: true, message: 'City is required' },
      { minLength: 2, message: 'City name must be at least 2 characters' }
    ],
    address: [
      { required: true, message: 'Address is required' },
      { minLength: 10, message: 'Please enter a complete address (at least 10 characters)' }
    ],
    driver_license_no: [
      { required: true, message: 'Driver\'s license number is required' },
      { minLength: 8, message: 'License number must be at least 8 characters' },
      { pattern: /^[A-Z0-9\s-]+$/i, message: 'License number can only contain letters, numbers, spaces, and hyphens' }
    ],
    driver_license_expiry: [
      { required: true, message: 'License expiry date is required' },
      { 
        validator: function(value) {
          const expiryDate = new Date(value);
          const today = new Date();
          return expiryDate > today;
        },
        message: 'License must not be expired'
      }
    ],
    id_type: [
      { required: true, message: 'Please select an ID type' }
    ],
    id_number: [
      { required: true, message: 'ID number is required' },
      { minLength: 5, message: 'ID number must be at least 5 characters' }
    ],
    emergency_name: [
      { required: true, message: 'Emergency contact name is required' },
      { minLength: 3, message: 'Name must be at least 3 characters' },
      { pattern: /^[a-zA-Z\s'-]+$/, message: 'Name can only contain letters, spaces, hyphens, and apostrophes' }
    ]
  };
  
  // Apply validation to all fields
  Object.keys(validationRules).forEach(fieldName => {
    addFieldValidation(fieldName, validationRules[fieldName]);
  });
  
  // Form submission validation
  form.addEventListener('submit', function(e) {
    const action = e.target.querySelector('input[name="action"]');
    const submitButton = e.target.querySelector('button[type="submit"]');
    
    // For profile update
    if (action && action.value === 'update_profile') {
      const requiredFields = ['email', 'contact_no'];
      const missingFields = [];
      
      requiredFields.forEach(fieldName => {
        const field = form.querySelector(`[name="${fieldName}"]`);
        if (!validateField(field, validationRules[fieldName] || [{ required: true }])) {
          missingFields.push(fieldName.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase()));
        }
      });
      
      if (missingFields.length > 0) {
        e.preventDefault();
        showValidationMessage('Please fix the errors in the following fields: ' + missingFields.join(', '), 'error');
        return;
      }
    }
    
    // For verification submission
    if (action && action.value === 'submit_verification') {
      const requiredVerificationFields = [
        'driver_license_no', 'driver_license_expiry', 'id_type', 'id_number',
        'emergency_name', 'city', 'address'
      ];
      const missingFields = [];
      
      // Validate all required fields
      requiredVerificationFields.forEach(fieldName => {
        const field = form.querySelector(`[name="${fieldName}"]`);
        if (!validateField(field, validationRules[fieldName] || [{ required: true }])) {
          missingFields.push(fieldName.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase()));
        }
      });
      
      // Check required photos
      const licenseFrontInput = form.querySelector('#license_photo_front');
      const idFrontInput = form.querySelector('#valid_id_photo_front');

      const hasLicenseFront = !!(licenseFrontInput && (licenseFrontInput.files && licenseFrontInput.files.length > 0)) ||
        !!(licenseFrontInput && licenseFrontInput.closest('.document-upload-item') && licenseFrontInput.closest('.document-upload-item').querySelector('[data-file-name]:not(.is-empty)'));

      const hasIdFront = !!(idFrontInput && (idFrontInput.files && idFrontInput.files.length > 0)) ||
        !!(idFrontInput && idFrontInput.closest('.document-upload-item') && idFrontInput.closest('.document-upload-item').querySelector('[data-file-name]:not(.is-empty)'));

      if (!hasLicenseFront) {
        missingFields.push('License Photo (Front)');
      }

      if (!hasIdFront) {
        missingFields.push('Valid ID Photo (Front)');
      }
      
      if (missingFields.length > 0) {
        e.preventDefault();
        showValidationMessage('Please complete the following required fields before submitting for verification:\n' + missingFields.join('\n'), 'error');
        return;
      }
      
      // Confirmation dialog
      if (!confirm('Are you sure you want to submit your profile for verification? Make sure all information is correct.')) {
        e.preventDefault();
        return;
      }
    }
  });
  
  // Validation message display
  function showValidationMessage(message, type = 'error') {
    // Remove existing validation messages
    const existingMsg = form.parentElement.querySelector('.validation-message');
    if (existingMsg) existingMsg.remove();
    
    const messageDiv = document.createElement('div');
    messageDiv.className = 'validation-message flash-' + type;
    messageDiv.style.cssText = 'margin-bottom: 20px; padding: 16px 24px; border-radius: 12px; text-align: center; font-weight: 600;';
    messageDiv.textContent = message;
    
    form.parentElement.insertBefore(messageDiv, form);
    
    // Auto-remove after 5 seconds
    setTimeout(() => {
      if (messageDiv.parentElement) {
        messageDiv.remove();
      }
    }, 5000);
  }
  
  // Phone number formatting (no validation, just formatting)
  const phoneFields = form.querySelectorAll('input[name="contact_no"], input[name="emergency_phone"]');
  phoneFields.forEach(field => {
    field.addEventListener('input', function(e) {
      let value = e.target.value.replace(/\D/g, '');
      value = value.substring(0, 11);

      if (value.length >= 8) {
        value = value.replace(/(\d{4})(\d{3})(\d{0,4}).*/, (m, a, b, c) => c ? `${a} ${b} ${c}` : `${a} ${b}`);
      } else if (value.length >= 5) {
        value = value.replace(/(\d{4})(\d{0,7}).*/, (m, a, b) => b ? `${a} ${b}` : a);
      }

      e.target.value = value;
    });
  });
});
</script>
</body>
</html>
