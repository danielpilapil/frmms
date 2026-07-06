<?php
function notify($conn, $user_id, $vehicle_id, $message) {
    try {
        $stmt = $conn->prepare("
            INSERT INTO notifications (user_id, vehicle_id, message, created_at, is_read)
            VALUES (?, ?, ?, NOW(), 0)
        ");
        
        if (!$stmt) {
            error_log("Notification prepare failed: " . $conn->error);
            return false;
        }
        
        $stmt->bind_param("iis", $user_id, $vehicle_id, $message);
        $result = $stmt->execute();
        
        if (!$result) {
            error_log("Notification execute failed: " . $stmt->error);
            $stmt->close();
            return false;
        }
        
        $stmt->close();
        return true;
    } catch (Exception $e) {
        error_log("Notification error: " . $e->getMessage());
        return false;
    }
}
?>
