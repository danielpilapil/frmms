<?php
/* ============================================
   FleetGo Database Connection File
============================================ */

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$DB_HOST = "localhost";     // use localhost for XAMPP
$DB_USER = "root";
$DB_PASS = "";
$DB_NAME = "fleet_rental_db";
$DB_PORT = 3306;            // XAMPP MySQL port (change to 3307 if needed)

try {
    $conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);
    $conn->set_charset("utf8mb4");
} catch (mysqli_sql_exception $e) {
    die("Database connection failed: " . $e->getMessage());
}

/* --------------------------------------------
   Helper Function for Safe HTML Output
-------------------------------------------- */
if (!function_exists('h')) {
    function h($str) {
        return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
    }
}
?>