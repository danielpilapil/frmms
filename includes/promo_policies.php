<?php
/* =========================================
   promo_policies.php — FleetGo Built-in Promotional Policies
   Enhanced Version with Professor Features
   ========================================= */

/**
 * Get all built-in promotional policies
 * @return array Array of promotional policies
 */
function getPromotionalPolicies() {
    return [
        'first_time' => [
            'name' => 'Welcome Discount',
            'type' => 'First-Time',
            'discount_percent' => 7.0,
            'description' => '7% discount for first-time customers',
            'condition' => 'No previous bookings',
            'business_discount' => 3.5, // Half for business customers
            'icon' => '🎉'
        ],
        'loyalty' => [
            'name' => 'Loyalty Reward',
            'type' => 'Loyalty',
            'discount_percent' => 5.0,
            'description' => '5% discount for loyal customers',
            'condition' => '3 or more completed bookings',
            'business_discount' => 2.5, // Half for business customers
            'icon' => '⭐'
        ],
        'long_term' => [
            'name' => 'Long-Term Booking',
            'type' => 'Long-Term',
            'discount_percent' => 6.0,
            'description' => '6% discount for extended rentals',
            'condition' => '5 or more rental days',
            'business_discount' => 3.0, // Half for business customers
            'icon' => '📅'
        ],
        'seasonal' => [
            'name' => 'Seasonal Special',
            'type' => 'Seasonal',
            'discount_percent' => 4.0,
            'description' => '4% discount for seasonal bookings',
            'condition' => '3-7 rental days',
            'business_discount' => 2.0, // Half for business customers
            'icon' => '🌸'
        ]
    ];
}

/**
 * Get promotional policy statistics
 * @param mysqli $conn Database connection
 * @return array Policy usage statistics
 */
function getPromotionalStats($conn) {
    $stats = [];
    $policies = getPromotionalPolicies();
    
    foreach ($policies as $key => $policy) {
        $stmt = $conn->prepare("
            SELECT 
                COUNT(*) as usage_count,
                SUM(promo_discount) as total_discount,
                AVG(promo_discount) as avg_discount,
                SUM(total_cost) as total_revenue
            FROM rentals 
            WHERE promo_applied = ?
        ");
        $stmt->bind_param("s", $policy['name']);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        $stats[$key] = [
            'policy' => $policy,
            'usage_count' => (int)($result['usage_count'] ?? 0),
            'total_discount' => (float)($result['total_discount'] ?? 0),
            'avg_discount' => (float)($result['avg_discount'] ?? 0),
            'total_revenue' => (float)($result['total_revenue'] ?? 0)
        ];
    }
    
    return $stats;
}

/**
 * Display promotional policies in HTML format
 * @param array $stats Optional statistics array
 * @return string HTML content
 */
function displayPromotionalPolicies($stats = null) {
    $policies = getPromotionalPolicies();
    $html = '<div class="promo-policies">';
    
    foreach ($policies as $key => $policy) {
        $stat = $stats[$key] ?? null;
        $usage_count = $stat ? $stat['usage_count'] : 0;
        $total_discount = $stat ? $stat['total_discount'] : 0;
        
        $usedClass = $usage_count > 0 ? ' used' : '';
        $html .= '<div class="promo-policy-card' . $usedClass . '">';
        $html .= '<div class="promo-header">';
        $html .= '<span class="promo-icon">' . $policy['icon'] . '</span>';
        $html .= '<div class="promo-info">';
        $html .= '<h3>' . htmlspecialchars($policy['name']) . '</h3>';
        $html .= '<span class="promo-type">' . htmlspecialchars($policy['type']) . '</span>';
        $html .= '</div>';
        $html .= '<div class="promo-discount">' . $policy['discount_percent'] . '%</div>';
        $html .= '</div>';
        
        $html .= '<div class="promo-details">';
        $html .= '<p>' . htmlspecialchars($policy['description']) . '</p>';
        $html .= '<div class="promo-condition">';
        $html .= '<strong>Condition:</strong> ' . htmlspecialchars($policy['condition']);
        $html .= '</div>';
        
        if ($stat) {
            $html .= '<div class="promo-stats">';
            $html .= '<div class="stat-item">';
            $html .= '<span class="stat-label">Times Used:</span>';
            $html .= '<span class="stat-value">' . number_format($usage_count) . '</span>';
            $html .= '</div>';
            $html .= '<div class="stat-item">';
            $html .= '<span class="stat-label">Total Discount:</span>';
            $html .= '<span class="stat-value">₱' . number_format($total_discount, 2) . '</span>';
            $html .= '</div>';
            $html .= '</div>';
        }
        
        $html .= '</div>';
        $html .= '</div>';
    }
    
    $html .= '</div>';
    return $html;
}
?>

