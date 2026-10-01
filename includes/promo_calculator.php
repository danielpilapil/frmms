<?php
/* =========================================
   promo_calculator.php — FleetGo Promotional Calculator
   Enhanced Version with Professor Features
   ========================================= */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/pricing_validator.php';

/**
 * Calculate promotional discounts for rentals using database promotions with conditional logic
 * @param int $customer_id Customer ID
 * @param int $vehicle_id Vehicle ID  
 * @param int $rental_days Number of rental days
 * @param string $rate_type 'CDO' or 'Outside-CDO'
 * @param float $base_rate Base daily rate
 * @return array [applied_rate, promo_applied, promo_discount, total_cost]
 */
function calculatePromotionalRate($customer_id, $vehicle_id, $rental_days, $rate_type, $base_rate) {
    global $conn;
    
    // Get vehicle information (including optional per-vehicle promo)
    $vehicle = null;
    $vehicle_type = '';
    $vehicle_promo_type = 'none';
    $vehicle_promo_value = 0.0;
    $vehicle_promo_starts = null;
    $vehicle_promo_ends = null;
    try {
        $stmt = $conn->prepare("SELECT vehicle_type, promo_discount_type, promo_discount_value, promo_starts_at, promo_ends_at FROM vehicles WHERE id = ?");
        $stmt->bind_param("i", $vehicle_id);
        $stmt->execute();
        $vehicle = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (Throwable $e) {
        try {
            $stmt = $conn->prepare("SELECT vehicle_type, promo_discount_type, promo_discount_value FROM vehicles WHERE id = ?");
            $stmt->bind_param("i", $vehicle_id);
            $stmt->execute();
            $vehicle = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        } catch (Throwable $e2) {
            $stmt = $conn->prepare("SELECT vehicle_type FROM vehicles WHERE id = ?");
            $stmt->bind_param("i", $vehicle_id);
            $stmt->execute();
            $vehicle = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
    }
    $vehicle_type = $vehicle['vehicle_type'] ?? '';
    $vehicle_promo_type = strtolower((string)($vehicle['promo_discount_type'] ?? 'none'));
    $vehicle_promo_value = (float)($vehicle['promo_discount_value'] ?? 0);
    $vehicle_promo_starts = $vehicle['promo_starts_at'] ?? null;
    $vehicle_promo_ends = $vehicle['promo_ends_at'] ?? null;
    
    // Get customer classification for business discounts
    // Note: Using users table since customers table doesn't have discount_rate column
    $stmt = $conn->prepare("SELECT role, loyalty_points FROM users WHERE id = ?");
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $customer_classification = 'Personal'; // Default to Personal for now
    $business_discount = 0; // No business discount system implemented yet
    
    // Get customer's booking count for loyalty promotions
    $stmt = $conn->prepare("SELECT COUNT(*) as booking_count FROM rentals WHERE customer_id = ? AND status = 'completed'");
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    $booking_count = $stmt->get_result()->fetch_assoc()['booking_count'];
    $stmt->close();
    
    // Find the best applicable promotion (table may be missing on older DBs)
    $promo = null;
    try {
        $stmt = $conn->prepare("
            SELECT * FROM promotions 
            WHERE is_active = 1 
            AND min_days <= ?
            AND min_bookings <= ?
            ORDER BY discount_percent DESC
            LIMIT 1
        ");
        $stmt->bind_param("ii", $rental_days, $booking_count);
        $stmt->execute();
        $promo = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (Throwable $e) {
        $promo = null;
    }
    
    // Initialize default values
    $promo_applied = null;
    $promo_discount = 0;
    $discount_percent = 0;
    
    $is_first_time = ($booking_count == 0);
    
    // Apply database promotion if conditions are met
    if ($promo && 
        $rental_days >= $promo['min_days'] &&
        $promo['is_active'] == 1) {
        
        $promo_applied = $promo['promo_name'];
        $discount_percent = (float)$promo['discount_percent'];
        
        // Apply business customer discount (half of promo rate)
        if ($customer_classification === 'Business') {
            $discount_percent = $discount_percent * 0.5;
        }
        
        // Apply business account discount
        if ($business_discount > 0) {
            $discount_percent += $business_discount;
        }
        
        // Cap maximum discount at 15%
        $discount_percent = min($discount_percent, 15.0);
        
        $promo_discount = ($base_rate * $discount_percent / 100) * $rental_days;
    } 
    // Apply welcome discount for first-time users (only if no database promotion or if welcome is better)
    elseif ($is_first_time) {
        $welcome_discount_percent = 5.0;
        
        // Apply business customer discount (half of welcome rate)
        if ($customer_classification === 'Business') {
            $welcome_discount_percent = 2.5;
        }
        
        // Apply business account discount
        if ($business_discount > 0) {
            $welcome_discount_percent += $business_discount;
        }
        
        // Cap maximum discount at 15%
        $welcome_discount_percent = min($welcome_discount_percent, 15.0);
        
        $promo_applied = 'Welcome Discount';
        $discount_percent = $welcome_discount_percent;
        $promo_discount = ($base_rate * $discount_percent / 100) * $rental_days;
    }
    else {
        // No valid promotion - no discount applied
        $promo_applied = null;
        $promo_discount = 0;
        $discount_percent = 0;
    }

    // Per-vehicle promo from admin Pricing section (percent or fixed ₱/day)
    // Active with schedule window, or always-on when no window is set.
    $vehicle_promo_active = false;
    if ($vehicle_promo_value > 0 && in_array($vehicle_promo_type, ['percent', 'fixed'], true)) {
        if (!function_exists('vehicle_promo_is_active_now')) {
            require_once __DIR__ . '/vehicle_promo.php';
        }
        $vehicle_promo_active = vehicle_promo_is_active_now(
            $vehicle_promo_starts ? (string)$vehicle_promo_starts : null,
            $vehicle_promo_ends ? (string)$vehicle_promo_ends : null
        );
    }

    if ($vehicle_promo_active && $rental_days > 0) {
        $veh_discount_percent = 0.0;
        $veh_promo_discount = 0.0;
        $veh_label = 'Vehicle Promo';

        if ($vehicle_promo_type === 'percent') {
            $veh_discount_percent = min(100.0, max(0.0, $vehicle_promo_value));
            $veh_promo_discount = ($base_rate * $veh_discount_percent / 100) * $rental_days;
            $veh_label = 'Vehicle Promo (' . rtrim(rtrim(number_format($veh_discount_percent, 2), '0'), '.') . '%)';
        } else {
            // Fixed peso off each day
            $per_day = min($base_rate, max(0.0, $vehicle_promo_value));
            $veh_promo_discount = $per_day * $rental_days;
            $veh_discount_percent = $base_rate > 0 ? ($per_day / $base_rate) * 100 : 0;
            $veh_label = 'Vehicle Promo (₱' . number_format($per_day, 2) . '/day)';
        }

        if ($veh_promo_discount > $promo_discount + 0.00001) {
            $promo_applied = $veh_label;
            $promo_discount = $veh_promo_discount;
            $discount_percent = $veh_discount_percent;
        }
    }
    
    // Calculate final rate
    $applied_rate = $base_rate - ($base_rate * $discount_percent / 100);
    $total_cost = $applied_rate * $rental_days;
    
    // Validate pricing calculation
    $pricing_validation = validatePricing($base_rate, $rental_days, $discount_percent);
    
    // Use validated pricing if there's a discrepancy
    if (abs($total_cost - $pricing_validation['final_cost']) > 0.01) {
        $total_cost = $pricing_validation['final_cost'];
        $applied_rate = $total_cost / $rental_days;
    }
    
    return [
        'applied_rate' => round($applied_rate, 2),
        'promo_applied' => $promo_applied,
        'promo_discount' => round($promo_discount, 2),
        'total_cost' => round($total_cost, 2),
        'discount_percent' => round($discount_percent, 2),
        'promo_type' => $promo_applied ? 'Database Promotion' : null
    ];
}

/**
 * Get vehicle rate based on location
 * @param int $vehicle_id Vehicle ID
 * @param string $rate_type 'CDO' or 'Outside-CDO'
 * @return float Daily rate
 */
function getVehicleRate($vehicle_id, $rate_type) {
    global $conn;
    
    $stmt = $conn->prepare("SELECT daily_rate, daily_rate_cdo, daily_rate_outside_cdo FROM vehicles WHERE id = ?");
    $stmt->bind_param("i", $vehicle_id);
    $stmt->execute();
    $vehicle = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$vehicle) {
        return 0.0;
    }

    $fallback = (float)($vehicle['daily_rate'] ?? 0);
    if ($rate_type === 'Outside-CDO') {
        $rate = (float)($vehicle['daily_rate_outside_cdo'] ?? 0);
        return $rate > 0 ? $rate : $fallback;
    }

    $rate = (float)($vehicle['daily_rate_cdo'] ?? 0);
    return $rate > 0 ? $rate : $fallback;
}

/**
 * Create promotional notifications - only when promotion is actually applied
 * @param int $customer_id Customer ID
 * @param int $vehicle_id Vehicle ID
 * @param array $promo_data Promotional calculation results
 */
function createPromoNotification($customer_id, $vehicle_id, $promo_data) {
    global $conn;
    
    // Only create notification if promotion was actually applied and discount > 0
    if ($promo_data['promo_applied'] && $promo_data['promo_discount'] > 0) {
        $message = "🎉 <b>Promotion Applied!</b> You received a {$promo_data['discount_percent']}% discount ({$promo_data['promo_applied']}). Total savings: ₱" . number_format($promo_data['promo_discount'], 2);
        
        // Notification creation is now handled in book_vehicle.php
        // This prevents duplicate notifications
    }
}
?>
