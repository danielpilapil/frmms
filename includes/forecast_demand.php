
<?php
/**
 * Rental Demand Forecast Helper
 *
 * This helper:
 * - Aggregates rentals per month for the last 12 months (based on start_date)
 * - Fills in months with 0 rentals so the sequence is continuous
 * - Computes a simple 3‑month moving average forecast for next month
 * - Identifies the peak month in the last 12 months (most recent in case of tie)
 *
 * This is a system-generated forecasting feature based on historical data
 * trends using a Moving Average formula. It is not AI-based prediction and
 * does not use any machine learning or external APIs. The logic is
 * intentionally simple and deterministic so it is easy to explain during
 * system defense.
 *
 * Exposed variables:
 * - $monthlyLabels   : array of "YYYY-MM" strings for the last 12 months
 * - $monthlyCounts   : array of integers, rental counts per month
 * - $forecastLabel   : string, "YYYY-MM" for the next month
 * - $forecastValue   : int, forecasted rentals next month
 * - $peakMonthLabel  : string, "YYYY-MM" of peak month or "N/A" if none
 * - $peakMonthCount  : int, rentals in the peak month (0 if none)
 */

if (!isset($conn) || !($conn instanceof mysqli)) {
    // Connection is required; if not present, do nothing to avoid fatal errors.
    return;
}

// Build the last 12 months (including current month) as continuous "YYYY-MM" labels.
// We rely on the server's PHP date configuration (XAMPP, Asia/Manila by default).
$monthlyLabels = [];
for ($i = 11; $i >= 0; $i--) {
    $ts = strtotime(date('Y-m-01') . " -$i month");
    $monthlyLabels[] = date('Y-m', $ts);
}

// Initialize all months with 0 rentals; we will overwrite those with actual counts.
$monthlyCountsMap = array_fill_keys($monthlyLabels, 0);

// Query MySQL for rentals per month using the rental start date.
// This uses CURDATE() so the window aligns with the current calendar date on the DB server.
$sql = "
    SELECT DATE_FORMAT(start_date, '%Y-%m') AS ym, COUNT(*) AS cnt
    FROM rentals
    WHERE start_date >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 11 MONTH), '%Y-%m-01')
    GROUP BY ym
    ORDER BY ym
";

try {
    if ($result = $conn->query($sql)) {
        while ($row = $result->fetch_assoc()) {
            $ym = $row['ym'];
            if (isset($monthlyCountsMap[$ym])) {
                $monthlyCountsMap[$ym] = (int)$row['cnt'];
            }
        }
        $result->free();
    }
} catch (Throwable $e) {
    // On query failure, fall back to zeros; forecast will simply be zero.
}

// Flatten the map to indexed arrays for easier use in PHP and JS.
$monthlyCounts = array_values($monthlyCountsMap);

// Default outputs for the "no data" edge case.
$forecastLabel  = date('Y-m', strtotime(date('Y-m-01') . ' +1 month'));
$forecastValue  = 0;
$peakMonthLabel = 'N/A';
$peakMonthCount = 0;

// If there are no rentals at all in the last 12 months, keep defaults.
if (array_sum($monthlyCounts) === 0) {
    // rentalDemandForecast array can still be useful to the view layer.
    $rentalDemandForecast = [
        'monthlyLabels'   => $monthlyLabels,
        'monthlyCounts'   => $monthlyCounts,
        'forecastLabel'   => $forecastLabel,
        'forecastValue'   => $forecastValue,
        'peakMonthLabel'  => $peakMonthLabel,
        'peakMonthCount'  => $peakMonthCount,
    ];
    return;
}

// ---- Moving Average Forecast Logic ----
// We use a simple 3‑month moving average:
//   forecast_next = round((count_m-1 + count_m-2 + count_m-3) / 3)
// If the system has fewer than 3 recorded months (e.g., newly deployed),
// we average only the available months.

$totalMonths = count($monthlyCounts);
if ($totalMonths > 0) {
    $lastIndex   = $totalMonths - 1;
    $lookback    = min(3, $totalMonths);
    $sumRecent   = 0;

    for ($i = 0; $i < $lookback; $i++) {
        $idx = $lastIndex - $i;
        if ($idx >= 0) {
            $sumRecent += $monthlyCounts[$idx];
        }
    }

    $forecastValue = (int)round($lookback > 0 ? $sumRecent / $lookback : 0);

    // Next month label is one month after the last label in the 12‑month window.
    $lastMonthLabel = $monthlyLabels[$lastIndex];
    $forecastLabel  = date('Y-m', strtotime($lastMonthLabel . '-01 +1 month'));
}

// ---- Peak Month Logic ----
// We scan the last 12 months and choose the month with the highest rental count.
// If there is a tie, we intentionally pick the most recent month,
// which we achieve by using ">=" instead of ">" in the comparison.

foreach ($monthlyCounts as $idx => $count) {
    if ($count === 0) {
        continue;
    }
    if ($count >= $peakMonthCount) {
        $peakMonthCount = $count;
        $peakMonthLabel = $monthlyLabels[$idx];
    }
}

// Package everything in an array as well (handy if the caller prefers a single structure).
$rentalDemandForecast = [
    'monthlyLabels'   => $monthlyLabels,
    'monthlyCounts'   => $monthlyCounts,
    'forecastLabel'   => $forecastLabel,
    'forecastValue'   => $forecastValue,
    'peakMonthLabel'  => $peakMonthLabel,
    'peakMonthCount'  => $peakMonthCount,
];

?>

