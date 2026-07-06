<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/db.php';

$userID = (int)($_SESSION['user_id'] ?? 0);

/* --- Check if not logged in --- */
if ($userID <= 0) {
  header("Location: login.php");
  exit;
}

/* --- Check profile completeness and approval --- */
$stmt = $conn->prepare("
  SELECT status, verification_status, email, contact_no, address, license_photo, valid_id_photo, profile_status
  FROM users WHERE id = ?
");
$stmt->bind_param("i", $userID);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

/* --- Fetch user documents (new storage) --- */
$userDocuments = [];
$docStmt = $conn->prepare("SELECT doc_type, file_path FROM user_documents WHERE user_id = ?");
$docStmt->bind_param("i", $userID);
$docStmt->execute();
$documents = $docStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$docStmt->close();
foreach ($documents as $doc) {
  if (!empty($doc['doc_type'])) {
    $userDocuments[$doc['doc_type']] = $doc['file_path'] ?? '';
  }
}

/* --- Identify missing fields --- */
$missing = [];
foreach (['email','contact_no','address'] as $f) {
  if (empty(trim($user[$f] ?? ''))) {
    $missing[] = $f;
  }
}

$hasLicenseFront = !empty(trim($user['license_photo'] ?? '')) || !empty(trim($userDocuments['License Front'] ?? ''));
$hasIdFront = !empty(trim($user['valid_id_photo'] ?? '')) || !empty(trim($userDocuments['ID Front'] ?? ''));
if (!$hasLicenseFront) $missing[] = 'license_photo';
if (!$hasIdFront) $missing[] = 'valid_id_photo';

$profile_status = $user['profile_status'] ?? 'incomplete';
$verification_status = $user['verification_status'] ?? 'unverified';
$account_status = $user['status'] ?? '';

/* --- If incomplete, redirect to profile page --- */
if (!empty($missing)) {
  $_SESSION['flash_message'] = "⚠️ Please complete your profile before booking a car.";
  header("Location: userprofile.php");
  exit;
}

/* --- If not approved, redirect to profile page --- */
if ($profile_status !== 'approved') {
  $status_messages = [
    'incomplete' => 'Please complete your profile first.',
    'pending_approval' => 'Your profile is pending admin approval. Please wait for approval.',
    'rejected' => 'Your profile was rejected. Please update your information and resubmit for approval.'
  ];
  
  $_SESSION['flash_message'] = "⚠️ " . ($status_messages[$profile_status] ?? 'Your profile needs admin approval.');
  header("Location: userprofile.php");
  exit;
}

/* --- If not verified, redirect to profile page --- */
if ($verification_status !== 'verified') {
  $ver_messages = [
    'unverified' => 'Please submit your profile for verification first.',
    'pending' => 'Your verification is pending admin approval. Please wait for approval.',
    'pending_approval' => 'Your verification is pending admin approval. Please wait for approval.',
    'rejected' => 'Your verification was rejected. Please update your information and resubmit.'
  ];
  $_SESSION['flash_message'] = "⚠️ " . ($ver_messages[$verification_status] ?? 'Please complete your profile verification first.');
  header("Location: userprofile.php");
  exit;
}

/* --- If account inactive, redirect --- */
if ($account_status !== 'active') {
  $_SESSION['flash_message'] = "⚠️ Your account is not active. Please contact support.";
  header("Location: userprofile.php");
  exit;
}
?>
