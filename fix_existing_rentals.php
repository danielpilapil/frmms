<?php
/**
 * Fix Existing Rentals - Correct pricing calculations for existing rentals
 * Run this script once to fix any existing rentals with incorrect pricing
 */

require_once 'includes/db.php';
require_once 'includes/pricing_validator.php';

echo "🔧 Fixing existing rental calculations...\n";

try {
    // Get all rentals that might have incorrect pricing
    $stmt = $conn->prepare("
        SELECT id, start_date, start_time, end_date, end_time, daily_rate, applied_rate, total_cost, promo_applied, promo_discount
        FROM rentals 
        WHERE status IN ('pending', 'reserved', 'ongoing')
        ORDER BY created_at DESC
    ");
    
    $stmt->execute();
    $rentals = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    $fixed_count = 0;
    $total_savings = 0;
    
    foreach ($rentals as $rental) {
        // Recalculate rental days using correct formula
        $start_datetime = strtotime($rental['start_date'] . ' ' . $rental['start_time']);
        $end_datetime = strtotime($rental['end_date'] . ' ' . $rental['end_time']);
        $correct_days = max(1, round(($end_datetime - $start_datetime) / 86400, 1));
        
        // Calculate correct total cost
        $base_rate = (float)$rental['daily_rate'];
        $applied_rate = (float)$rental['applied_rate'];
        
        // If applied_rate is 0 or null, use daily_rate
        if ($applied_rate <= 0) {
            $applied_rate = $base_rate;
        }
        
        $correct_total = $applied_rate * $correct_days;
        
        // Check if correction is needed
        $current_total = (float)$rental['total_cost'];
        $difference = abs($current_total - $correct_total);
        
        if ($difference > 0.01) { // Only update if there's a meaningful difference
            // Update the rental with correct pricing
            $update_stmt = $conn->prepare("
                UPDATE rentals 
                SET total_cost = ?, balance_due = total_cost - downpayment
                WHERE id = ?
            ");
            $update_stmt->bind_param("di", $correct_total, $rental['id']);
            
            if ($update_stmt->execute()) {
                $savings = $current_total - $correct_total;
                $total_savings += $savings;
                $fixed_count++;
                
                echo "✅ Fixed rental #{$rental['id']}: ";
                echo "₱" . number_format($current_total, 2) . " → ₱" . number_format($correct_total, 2);
                if ($savings > 0) {
                    echo " (Saved: ₱" . number_format($savings, 2) . ")";
                }
                echo "\n";
            } else {
                echo "❌ Failed to fix rental #{$rental['id']}: " . $update_stmt->error . "\n";
            }
            
            $update_stmt->close();
        }
    }
    
    echo "\n📊 Summary:\n";
    echo "Fixed rentals: $fixed_count\n";
    echo "Total savings: ₱" . number_format($total_savings, 2) . "\n";
    echo "✅ Pricing fix completed!\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}

$conn->close();
?>
