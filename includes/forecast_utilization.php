<?php
/**
 * Vehicle Utilization Forecast / Insight Helper
 *
 * This utilization forecasting feature is system-generated from historical rental
 * duration data. It provides forecasting/insight using computed utilization rates
 * and simple trend averaging. It does not use AI or machine learning.
 *
 * Definitions (last 30 days window):
 *   period_days = 30
 *   rented_days = DATEDIFF(effective_end, effective_start) + 1
 *   utilization_rate = ROUND((total_rented_days_last30 / period_days) * 100)
 *
 * Classification thresholds:
 *   - OVERUSED  : utilization_rate >= 70
 *   - UNDERUSED : utilization_rate <= 20
 *   - NORMAL    : otherwise
 *
 * Forecast (next month utilization, system-generated):
 *   - Prefer: average of last 3 months' utilization rates per vehicle
 *   - Fallback: current utilization_rate (last 30 days) if no 3‑month history
 *
 * Exposed outputs:
 *   - $overusedCount
 *   - $underusedCount
 *   - $topOverused      (top 3 by utilization_rate desc)
 *   - $topUnderused     (top 3 by utilization_rate asc)
 *   - $utilizationTable (full table per vehicle with status + forecast)
 */

if (!isset($conn) || !($conn instanceof mysqli)) {
    return;
}

// ---- Constants for easy defense explanation ----
$UTIL_PERIOD_DAYS        = 30;
$UTIL_OVERUSED_THRESHOLD = 70;
$UTIL_UNDERUSED_THRESHOLD = 20;
$UTIL_MONTH_LOOKBACK     = 3;

// Last 30 days window (inclusive)
$utilWindowEnd   = date('Y-m-d');
$utilWindowStart = date('Y-m-d', strtotime($utilWindowEnd . ' -' . ($UTIL_PERIOD_DAYS - 1) . ' days'));

// ---- Helper: safe status filter for rentals ----
$validStatusesForUtil = ["reserved", "ongoing", "completed"]; // exclude cancelled/pending
$statusList = "'" . implode("','", array_map([$conn, 'real_escape_string'], $validStatusesForUtil)) . "'";

// ---- Step 1: Fetch all vehicles (id + identifier fields) ----
$vehicles = [];

try {
    $vehRes = $conn->query("SELECT id, make_model, plate_no, current_status FROM vehicles ORDER BY make_model ASC, plate_no ASC");
    if ($vehRes) {
        while ($v = $vehRes->fetch_assoc()) {
            $vid = (int)$v['id'];
            $labelParts = [];
            if (!empty($v['make_model'])) $labelParts[] = $v['make_model'];
            if (!empty($v['plate_no']))   $labelParts[] = '(' . $v['plate_no'] . ')';
            $identifier = implode(' ', $labelParts);
            if ($identifier === '') {
                $identifier = 'Vehicle #' . $vid;
            }

            $vehicles[$vid] = [
                'vehicle_id'      => $vid,
                'identifier'      => $identifier,
                'current_status'  => $v['current_status'] ?? '',
            ];
        }
        $vehRes->free();
    }
} catch (Throwable $e) {
    // If vehicles cannot be loaded, abort quietly.
    return;
}

if (empty($vehicles)) {
    // Nothing to compute.
    $overusedCount = 0;
    $underusedCount = 0;
    $topOverused = [];
    $topUnderused = [];
    $utilizationTable = [];
    return;
}

$vehicleIds = array_keys($vehicles);
$vehicleIdList = implode(',', array_map('intval', $vehicleIds));

// ---- Step 2: Rented days in last 30 days per vehicle ----
$rentedDaysLast30 = array_fill_keys($vehicleIds, 0);

try {
    $sql30 = "
        SELECT
            r.vehicle_id,
            SUM(
                GREATEST(
                    0,
                    DATEDIFF(
                        LEAST(COALESCE(r.end_date, r.start_date), '{$utilWindowEnd}'),
                        GREATEST(r.start_date, '{$utilWindowStart}')
                    ) + 1
                )
            ) AS rented_days
        FROM rentals r
        WHERE r.vehicle_id IN ($vehicleIdList)
          AND r.status IN ($statusList)
          AND r.start_date <= '{$utilWindowEnd}'
          AND COALESCE(r.end_date, r.start_date) >= '{$utilWindowStart}'
        GROUP BY r.vehicle_id
    ";
    $res30 = $conn->query($sql30);
    if ($res30) {
        while ($row = $res30->fetch_assoc()) {
            $vid = (int)$row['vehicle_id'];
            $rentedDaysLast30[$vid] = max(0, (int)$row['rented_days']);
        }
        $res30->free();
    }
} catch (Throwable $e) {
    // If this fails, all rentedDays will stay 0; underused by definition.
}

// ---- Step 3: Monthly utilization for last 3 months (simple DATEDIFF-based) ----
$monthlyUtil = []; // [vehicle_id => [util1, util2, ...]]

for ($i = 0; $i < $UTIL_MONTH_LOOKBACK; $i++) {
    $monthStart = date('Y-m-01', strtotime("first day of -$i month"));
    $monthEnd   = date('Y-m-t', strtotime($monthStart));
    $daysInMonth = (int)date('t', strtotime($monthStart));

    try {
        $sqlMonth = "
            SELECT
                r.vehicle_id,
                SUM(
                    GREATEST(
                        0,
                        DATEDIFF(
                            LEAST(COALESCE(r.end_date, r.start_date), '{$monthEnd}'),
                            GREATEST(r.start_date, '{$monthStart}')
                        ) + 1
                    )
                ) AS rented_days
            FROM rentals r
            WHERE r.vehicle_id IN ($vehicleIdList)
              AND r.status IN ($statusList)
              AND r.start_date <= '{$monthEnd}'
              AND COALESCE(r.end_date, r.start_date) >= '{$monthStart}'
            GROUP BY r.vehicle_id
        ";
        $resMonth = $conn->query($sqlMonth);
        if ($resMonth) {
            while ($row = $resMonth->fetch_assoc()) {
                $vid = (int)$row['vehicle_id'];
                $days = max(0, (int)$row['rented_days']);
                $rate = $daysInMonth > 0 ? round(($days / $daysInMonth) * 100) : 0;
                $monthlyUtil[$vid][] = $rate;
            }
            $resMonth->free();
        }
    } catch (Throwable $e) {
        // Skip this month if error.
    }
}

// ---- Step 4: Build utilization table + classification + forecast ----
$utilizationTable = [];
$overusedCount = 0;
$underusedCount = 0;

foreach ($vehicles as $vid => $veh) {
    $days30 = $rentedDaysLast30[$vid] ?? 0;
    $utilRate = $UTIL_PERIOD_DAYS > 0 ? round(($days30 / $UTIL_PERIOD_DAYS) * 100) : 0;

    // Classification
    if ($utilRate >= $UTIL_OVERUSED_THRESHOLD) {
        $statusLabel = 'OVERUSED';
        $overusedCount++;
    } elseif ($utilRate <= $UTIL_UNDERUSED_THRESHOLD) {
        $statusLabel = 'UNDERUSED';
        $underusedCount++;
    } else {
        $statusLabel = 'NORMAL';
    }

    // Forecast next utilization: average of monthlyUtil if available; else current
    $forecastNext = $utilRate;
    if (!empty($monthlyUtil[$vid])) {
        $forecastNext = (int)round(array_sum($monthlyUtil[$vid]) / count($monthlyUtil[$vid]));
    }

    $utilizationTable[$vid] = [
        'vehicle_id'               => $vid,
        'identifier'               => $veh['identifier'],
        'current_status'           => $veh['current_status'],
        'total_rented_days_last30' => $days30,
        'utilization_rate'         => $utilRate,
        'forecast_utilization_next'=> $forecastNext,
        'status_label'             => $statusLabel,
    ];
}

// ---- Step 5: Build top lists (top 3 overused & underused) ----
$rows = array_values($utilizationTable);

// Overused: sort by utilization desc
$sortedOver = $rows;
usort($sortedOver, function($a, $b){
    return $b['utilization_rate'] <=> $a['utilization_rate'];
});
$topOverused = array_slice($sortedOver, 0, 3);

// Underused: sort by utilization asc
$sortedUnder = $rows;
usort($sortedUnder, function($a, $b){
    return $a['utilization_rate'] <=> $b['utilization_rate'];
});
$topUnderused = array_slice($sortedUnder, 0, 3);

?>

