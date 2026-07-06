<?php
/* ===================================================
   FleetGo — Secure Logout Script (Tab-Isolated, All Roles)
   Compatible with multi-session fix from login.php
=================================================== */

/* ---------- Identify Role Session ---------- */
session_start();

$currentRole = $_SESSION['role'] ?? 'guest';
$userID      = $_SESSION['user_id'] ?? null;
$userName    = $_SESSION['user_name'] ?? 'Unknown';

/* ---------- DB Connection ---------- */
$DB_HOST = "127.0.0.1";
$DB_USER = "root";
$DB_PASS = "";
$DB_NAME = "fleet_rental_db";

$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
$conn->set_charset("utf8mb4");
if ($conn->connect_errno) die("Database connection failed: " . $conn->connect_error);

/* ---------- Log the logout before destroying session ---------- */
if ($userID) {
  $stmt = $conn->prepare("INSERT INTO system_logs (actor_user_id, action, role, entity, details)
                          VALUES (?, 'logout', ?, 'users', ?)");
  $details = "User {$userName} logged out.";
  $stmt->bind_param("iss", $userID, $currentRole, $details);
  $stmt->execute();
}

/* ---------- Properly close and target correct session ---------- */
session_write_close(); // ensure existing session is saved

// Reopen the correct role-based session to fully clear it
session_name('fleetgo_session_' . $currentRole);
session_start();

/* ---------- Destroy Session ---------- */
$_SESSION = [];
if (ini_get("session.use_cookies")) {
  $params = session_get_cookie_params();
  setcookie(session_name(), '', time() - 42000,
    $params["path"], $params["domain"],
    $params["secure"], $params["httponly"]
  );
}
session_destroy();

/* ---------- Clear Remember Me cookies ---------- */
setcookie("fleetgo_email", "", time() - 3600, "/");
setcookie("fleetgo_pass", "", time() - 3600, "/");

/* ---------- Redirect ---------- */
header("Location: login.php");
exit;
?>
