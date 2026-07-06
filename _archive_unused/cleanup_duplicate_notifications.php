<?php
/* =========================================
   cleanup_duplicate_notifications.php — Clean up duplicate notifications
   ========================================= */

require_once 'includes/db.php';
require_once 'includes/notification_manager.php';

echo "🧹 Cleaning up duplicate notifications...\n";

// Clean up duplicates using the centralized function
$removed_count = cleanupDuplicateNotifications($conn);

echo "✅ Removed $removed_count duplicate notifications\n";

// Show current notification count
$total_notifications = $conn->query("SELECT COUNT(*) as count FROM notifications")->fetch_assoc()['count'];
echo "📊 Total notifications remaining: $total_notifications\n";

// Show recent notifications for verification
echo "\n📋 Recent notifications:\n";
$recent = $conn->query("
    SELECT n.id, n.user_id, n.vehicle_id, n.message, n.created_at, 
           v.make_model, v.plate_no
    FROM notifications n
    LEFT JOIN vehicles v ON v.id = n.vehicle_id
    ORDER BY n.created_at DESC 
    LIMIT 10
")->fetch_all(MYSQLI_ASSOC);

foreach($recent as $notif) {
    $user_name = 'User #' . $notif['user_id'];
    $vehicle_info = $notif['make_model'] ? $notif['make_model'] . ' (' . $notif['plate_no'] . ')' : 'Vehicle #' . $notif['vehicle_id'];
    echo "  • ID: {$notif['id']} | User: $user_name | Vehicle: $vehicle_info | {$notif['created_at']}\n";
    echo "    Message: " . substr($notif['message'], 0, 80) . "...\n\n";
}

echo "✅ Duplicate notification cleanup completed!\n";

$conn->close();
?>
