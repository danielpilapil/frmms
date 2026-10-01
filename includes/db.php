<?php
/* ============================================
   FleetGo Database Connection File
============================================ */

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// FleetGo operates in the Philippines — keep promo windows / booking dates aligned
// with local admin browser time (avoids Europe/Berlin XAMPP default mismatches).
if (function_exists('date_default_timezone_set')) {
    date_default_timezone_set('Asia/Manila');
}

$DB_HOST = "localhost";     // use localhost for XAMPP
$DB_USER = "root";
$DB_PASS = "";
$DB_NAME = "fleet_rental_db";
$DB_PORT = 3306;            // XAMPP MySQL port (change to 3307 if needed)

try {
    $conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);
    $conn->set_charset("utf8mb4");
    // Align MySQL NOW()/CURDATE() with PHP Asia/Manila
    @$conn->query("SET time_zone = '+08:00'");
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

if (!function_exists('ensure_rental_waitlist_status')) {
    /**
     * Unpaid bookings use status waitlist. Submitting a receipt moves them to pending.
     * History columns are widened so the status trigger can store both values.
     */
    function ensure_rental_waitlist_status(mysqli $conn): void {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            $hist = $conn->query("SHOW COLUMNS FROM rental_status_history LIKE 'old_status'");
            $hrow = $hist ? $hist->fetch_assoc() : null;
            $htype = strtolower((string)($hrow['Type'] ?? ''));
            if ($htype !== '' && strpos($htype, 'enum(') === 0) {
                $conn->query("
                    ALTER TABLE rental_status_history
                      MODIFY COLUMN old_status VARCHAR(32) NULL DEFAULT NULL,
                      MODIFY COLUMN new_status VARCHAR(32) NOT NULL
                ");
            }

            $col = $conn->query("SHOW COLUMNS FROM rentals LIKE 'status'");
            $row = $col ? $col->fetch_assoc() : null;
            $type = strtolower((string)($row['Type'] ?? ''));
            if ($type !== '' && strpos($type, "'waitlist'") === false) {
                $conn->query("
                    ALTER TABLE rentals
                      MODIFY COLUMN status ENUM('waitlist','pending','reserved','ongoing','completed','cancelled','conflict_pending')
                      NOT NULL DEFAULT 'pending'
                ");
            }

            $conn->query("
                UPDATE rentals r
                SET r.status = 'waitlist'
                WHERE r.status = 'pending'
                  AND NOT EXISTS (
                    SELECT 1 FROM rental_payments p
                    WHERE p.rental_id = r.id
                      AND p.status IN ('PENDING','POSTED')
                  )
            ");
        } catch (Throwable $e) {
            error_log('ensure_rental_waitlist_status: ' . $e->getMessage());
        }
    }
}
