<?php
/**
 * Pricing Validator - Ensures accurate rental calculations
 * FleetGo Rental System
 */

/**
 * Calculate accurate rental days
 * @param string $start_date Start date (Y-m-d)
 * @param string $start_time Start time (H:i:s)
 * @param string $end_date End date (Y-m-d)
 * @param string $end_time End time (H:i:s)
 * @return array [days, hours, total_hours]
 */
function calculateAccurateRentalPeriod($start_date, $start_time, $end_date, $end_time) {
    $start_datetime = strtotime($start_date . ' ' . $start_time);
    $end_datetime = strtotime($end_date . ' ' . $end_time);
    
    $total_hours = ($end_datetime - $start_datetime) / 3600;
    $rental_days = max(1, round($total_hours / 24, 1));
    $rental_hours = round($total_hours, 1);
    
    return [
        'days' => $rental_days,
        'hours' => $rental_hours,
        'total_hours' => $total_hours
    ];
}

/**
 * Validate pricing calculation
 * @param float $base_rate Base daily rate
 * @param int $rental_days Rental days
 * @param float $discount_percent Discount percentage
 * @return array [original_cost, discounted_cost, savings, final_cost]
 */
function validatePricing($base_rate, $rental_days, $discount_percent) {
    $original_cost = $base_rate * $rental_days;
    $discount_amount = $original_cost * ($discount_percent / 100);
    $discounted_cost = $original_cost - $discount_amount;
    $savings = $discount_amount;
    
    return [
        'original_cost' => round($original_cost, 2),
        'discounted_cost' => round($discounted_cost, 2),
        'savings' => round($savings, 2),
        'final_cost' => round($discounted_cost, 2)
    ];
}

/**
 * Fix rental days calculation (replaces ceil with round)
 * @param int $start_timestamp Start timestamp
 * @param int $end_timestamp End timestamp
 * @return int Corrected rental days
 */
function fixRentalDaysCalculation($start_timestamp, $end_timestamp) {
    // Calculate actual hours
    $actual_hours = ($end_timestamp - $start_timestamp) / 3600;
    
    // Minimum 1 day, maximum reasonable limit (365 days)
    $rental_days = max(1, min(365, round($actual_hours / 24, 1)));
    
    // If less than 24 hours, charge for 1 day minimum
    if ($actual_hours < 24) {
        return 1;
    }
    
    // For longer rentals, use exact calculation
    return max(1, round($actual_hours / 24, 1));
}
?>
