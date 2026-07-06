<?php
/**
 * Maintenance Forecasting Helper (Rule-Based, No AI)
 *
 * This maintenance forecasting feature is system-generated based on historical mileage
 * and predefined service intervals. It does not use AI or machine learning, but applies
 * rule-based predictive logic for preventive maintenance.
 *
 * Forecast rule:
 * - Service interval: 5,000 km
 * - "DUE SOON" threshold: <= 500 km left
 *
 * Required computations per vehicle:
 *  km_since_service = current_mileage - last_service_mileage
 *  km_left         = 5000 - km_since_service
 *
 * Status classification:
 *  - km_left <= 0    => OVERDUE
 *  - km_left <= 500  => DUE SOON
 *  - else            => OK
 *
 * Edge cases:
 * - If vehicle has no service record yet: last_service_mileage = 0
 * - If current_mileage is NULL: treat as 0
 *
 * Outputs:
 * - $maintenanceForecastResults : array of per-vehicle forecast rows
 * - $maintenanceOverdueCount    : int
 * - $maintenanceDueSoonCount    : int
 */

if (!isset($conn) || !($conn instanceof mysqli)) {
    return;
}

// ---- Configurable rule constants (as required by the paper/spec) ----
$MAINTENANCE_INTERVAL_KM = 5000;
$DUE_SOON_THRESHOLD_KM   = 500;

/**
 * Check if a table exists in current DB.
 */
function fg_table_exists(mysqli $conn, string $table): bool {
    try {
        $table = $conn->real_escape_string($table);
        $res = $conn->query("SHOW TABLES LIKE '{$table}'");
        return $res && $res->num_rows > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Check if a column exists in a table.
 */
function fg_column_exists(mysqli $conn, string $table, string $column): bool {
    try {
        $tableEsc = $conn->real_escape_string($table);
        $colEsc   = $conn->real_escape_string($column);
        $res = $conn->query("SHOW COLUMNS FROM `{$tableEsc}` LIKE '{$colEsc}'");
        return $res && $res->num_rows > 0;
    } catch (Throwable $e) {
        return false;
    }
}

// ---- Detect actual column/table names (defense-friendly robustness) ----
$vehiclesTable = 'vehicles';
$maintenanceTable = 'maintenance';
$maintenanceRulesTable = 'maintenance_rules';

$vehicleIdCol = fg_column_exists($conn, $vehiclesTable, 'id') ? 'id'
              : (fg_column_exists($conn, $vehiclesTable, 'vehicle_id') ? 'vehicle_id' : 'id');

// Use odometer if present (your project uses it), otherwise fall back to likely mileage columns.
$vehicleMileageCol = fg_column_exists($conn, $vehiclesTable, 'odometer') ? 'odometer'
                   : (fg_column_exists($conn, $vehiclesTable, 'current_mileage') ? 'current_mileage'
                   : (fg_column_exists($conn, $vehiclesTable, 'mileage') ? 'mileage' : 'odometer'));

// Determine how to get last service mileage:
// 1) Prefer maintenance table mileage column if present (MAX of completed records).
// 2) Otherwise use maintenance_rules.last_ref_odometer (already maintained by your maintenance module).
$maintenanceMileageCol = null;
if (fg_table_exists($conn, $maintenanceTable)) {
    foreach (['mileage_at_service','odometer_at_service','service_odometer','odometer'] as $cand) {
        if (fg_column_exists($conn, $maintenanceTable, $cand)) { $maintenanceMileageCol = $cand; break; }
    }
}

$rulesHasLastRefOdo = fg_table_exists($conn, $maintenanceRulesTable) && fg_column_exists($conn, $maintenanceRulesTable, 'last_ref_odometer');

// ---- Build query ----
$select = "
    SELECT
        v.`{$vehicleIdCol}` AS vehicle_id,
        COALESCE(v.`{$vehicleMileageCol}`,0) AS current_mileage,
        v.plate_no,
        v.make_model
";

$joins = "";

if ($maintenanceMileageCol !== null && fg_column_exists($conn, $maintenanceTable, 'vehicle_id')) {
    // Use completed maintenance records when mileage at service exists.
    $joins .= "
        LEFT JOIN (
            SELECT vehicle_id, MAX(`{$maintenanceMileageCol}`) AS last_service_mileage
            FROM `{$maintenanceTable}`
            WHERE " . (fg_column_exists($conn, $maintenanceTable, 'status') ? "status='completed'" : "1=1") . "
            GROUP BY vehicle_id
        ) ms ON ms.vehicle_id = v.`{$vehicleIdCol}`
    ";
    $select .= ", COALESCE(ms.last_service_mileage, 0) AS last_service_mileage";
} elseif ($rulesHasLastRefOdo && fg_column_exists($conn, $maintenanceRulesTable, 'vehicle_id')) {
    // Fall back to maintenance_rules reference odometer.
    $joins .= " LEFT JOIN `{$maintenanceRulesTable}` mr ON mr.vehicle_id = v.`{$vehicleIdCol}` ";
    $select .= ", COALESCE(mr.last_ref_odometer, 0) AS last_service_mileage";
} else {
    // No service reference available; fall back to 0.
    $select .= ", 0 AS last_service_mileage";
}

$sql = $select . " FROM `{$vehiclesTable}` v " . $joins . " ORDER BY v.`{$vehicleIdCol}` ASC";

$maintenanceForecastResults = [];
$maintenanceOverdueCount = 0;
$maintenanceDueSoonCount = 0;

try {
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $vehicleId = (int)($row['vehicle_id'] ?? 0);
            $currentMileage = (int)($row['current_mileage'] ?? 0);
            $lastServiceMileage = (int)($row['last_service_mileage'] ?? 0);

            // Edge-safe: if last_service_mileage is greater than current, clamp delta to 0.
            $kmSinceService = max(0, $currentMileage - $lastServiceMileage);
            $kmLeft = $MAINTENANCE_INTERVAL_KM - $kmSinceService;

            if ($kmLeft <= 0) {
                $status = "OVERDUE";
                $maintenanceOverdueCount++;
            } elseif ($kmLeft <= $DUE_SOON_THRESHOLD_KM) {
                $status = "DUE SOON";
                $maintenanceDueSoonCount++;
            } else {
                $status = "OK";
            }

            $maintenanceForecastResults[] = [
                'vehicle_id' => $vehicleId,
                'plate_no' => $row['plate_no'] ?? '',
                'make_model' => $row['make_model'] ?? '',
                'current_mileage' => $currentMileage,
                'last_service_mileage' => $lastServiceMileage,
                'km_left' => (int)$kmLeft,
                'status' => $status,
                'days_until' => max(0, ceil($kmLeft / 100)), // Approximate days based on 100km/day
            ];
        }
        $res->free();
    }
} catch (Throwable $e) {
    // Fail closed: keep empty arrays and 0 counts.
    $maintenanceForecastResults = [];
    $maintenanceOverdueCount = 0;
    $maintenanceDueSoonCount = 0;
}

// ---- Optional notifications (only if notifications table exists & user session present) ----
// To avoid spamming, we use a larger duplicate window (1 day).
try {
    if (!empty($maintenanceForecastResults)
        && isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0
        && fg_table_exists($conn, 'notifications')
        && function_exists('createNotificationIfNotExists')
    ) {
        $uid = (int)$_SESSION['user_id'];

        foreach ($maintenanceForecastResults as $r) {
            if ($r['status'] !== 'OVERDUE' && $r['status'] !== 'DUE SOON') continue;

            $vehicleName = trim(($r['make_model'] ?? '') . ' ' . ($r['plate_no'] ? '(' . $r['plate_no'] . ')' : ''));
            $vehicleName = $vehicleName !== '' ? $vehicleName : ('Vehicle #' . (int)$r['vehicle_id']);

            $msg = $r['status'] === 'OVERDUE'
                ? "🚨 Maintenance OVERDUE for <b>{$vehicleName}</b> — exceeded {$MAINTENANCE_INTERVAL_KM} km interval."
                : "⚠️ Maintenance DUE SOON for <b>{$vehicleName}</b> — ~{$r['km_left']} km remaining before {$MAINTENANCE_INTERVAL_KM} km interval.";

            // 1440 minutes = 1 day duplicate window
            createNotificationIfNotExists($conn, $uid, (int)$r['vehicle_id'], $msg, true, 1440);
        }
    }
} catch (Throwable $e) {
    // Notifications are optional; ignore errors.
}

?>

