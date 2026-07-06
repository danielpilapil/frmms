<?php
/* =========================================
   notification_manager.php — FleetGo Notification Manager
   Prevents duplicate notifications
   ========================================= */

/**
 * Create notification with duplicate prevention
 * @param mysqli $conn Database connection
 * @param int $user_id User ID
 * @param int $vehicle_id Vehicle ID
 * @param string $message Notification message
 * @param bool $check_duplicate Whether to check for duplicates
 * @param int $duplicate_window_minutes Time window to check for duplicates (default: 5 minutes)
 * @return bool Success status
 */
function createNotificationIfNotExists($conn, $user_id, $vehicle_id, $message, $check_duplicate = true, $duplicate_window_minutes = 5) {
    try {
        if ($check_duplicate) {
            // Check if similar notification exists within the specified time window
            $stmt = $conn->prepare("
                SELECT COUNT(*) as count FROM notifications 
                WHERE user_id = ? AND vehicle_id = ? AND message = ? 
                AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
            ");
            $stmt->bind_param("iisi", $user_id, $vehicle_id, $message, $duplicate_window_minutes);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if ($result['count'] > 0) {
                error_log("Duplicate notification prevented for user $user_id: $message");
                return false; // Duplicate found, don't create
            }
        }
        
        // Create notification
        $stmt = $conn->prepare("
            INSERT INTO notifications (user_id, vehicle_id, message, created_at, is_read) 
            VALUES (?, ?, ?, NOW(), 0)
        ");
        $stmt->bind_param("iis", $user_id, $vehicle_id, $message);
        $result = $stmt->execute();
        $stmt->close();
        
        return $result;
    } catch (Exception $e) {
        error_log("Notification creation error: " . $e->getMessage());
        return false;
    }
}

/**
 * Clean up duplicate notifications (keep only the latest)
 * @param mysqli $conn Database connection
 * @return int Number of duplicates removed
 */
function cleanupDuplicateNotifications($conn) {
    try {
        $stmt = $conn->prepare("
            DELETE n1 FROM notifications n1
            INNER JOIN notifications n2 
            WHERE n1.id < n2.id 
            AND n1.user_id = n2.user_id 
            AND n1.vehicle_id = n2.vehicle_id 
            AND n1.message = n2.message 
            AND DATE(n1.created_at) = DATE(n2.created_at)
        ");
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        
        return $affected;
    } catch (Exception $e) {
        error_log("Cleanup error: " . $e->getMessage());
        return 0;
    }
}
?>
