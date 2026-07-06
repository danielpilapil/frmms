<?php
/* =========================================
   cleanup_duplicate_maintenance.php — Clean up duplicate maintenance records
   ========================================= */

require_once 'includes/db.php';

echo "🧹 Cleaning up duplicate maintenance records...\n";

// Find and remove duplicate maintenance records
// Keep only the latest record for each vehicle/type/date combination
$duplicate_query = "
  DELETE m1 FROM maintenance m1
  INNER JOIN maintenance m2 
  WHERE m1.id < m2.id 
  AND m1.vehicle_id = m2.vehicle_id 
  AND m1.type = m2.type 
  AND m1.schedule_date = m2.schedule_date
  AND m1.status = m2.status
  AND m1.status IN ('scheduled', 'in_progress')
";

$result = $conn->query($duplicate_query);
$removed_count = $conn->affected_rows;

echo "✅ Removed $removed_count duplicate maintenance records\n";

// Show current maintenance count
$total_maintenance = $conn->query("SELECT COUNT(*) as count FROM maintenance")->fetch_assoc()['count'];
echo "📊 Total maintenance records remaining: $total_maintenance\n";

// Show recent maintenance records for verification
echo "\n📋 Recent maintenance records:\n";
$recent = $conn->query("
    SELECT m.id, m.vehicle_id, m.type, m.schedule_date, m.status, m.notes, 
           v.make_model, v.plate_no
    FROM maintenance m
    LEFT JOIN vehicles v ON v.id = m.vehicle_id
    ORDER BY m.created_at DESC 
    LIMIT 10
")->fetch_all(MYSQLI_ASSOC);

foreach($recent as $maintenance) {
    $vehicle_info = $maintenance['make_model'] ? $maintenance['make_model'] . ' (' . $maintenance['plate_no'] . ')' : 'Vehicle #' . $maintenance['vehicle_id'];
    echo "  • ID: {$maintenance['id']} | Vehicle: $vehicle_info | Type: {$maintenance['type']} | Status: {$maintenance['status']} | Date: {$maintenance['schedule_date']}\n";
    if ($maintenance['notes']) {
        echo "    Notes: " . substr($maintenance['notes'], 0, 60) . "...\n\n";
    } else {
        echo "\n";
    }
}

echo "✅ Duplicate maintenance cleanup completed!\n";

$conn->close();
?>
