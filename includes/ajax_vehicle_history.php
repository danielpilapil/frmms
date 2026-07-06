<?php
/* =========================================================
   ajax_vehicle_history.php — AJAX endpoint for vehicle maintenance history
   ========================================================= */

require_once __DIR__ . '/db.php';
$id=(int)($_GET['id']??0);
$res=$conn->query("SELECT type,schedule_date,status,estimated_cost,notes FROM maintenance WHERE vehicle_id=$id ORDER BY schedule_date DESC");
if($res->num_rows===0){ echo "<p style='color:#9aa6b3'>No maintenance history found.</p>"; exit; }
echo "<table style='width:100%;border-collapse:collapse;color:#eafcff'>";
echo "<tr style='color:#7cffc7'><th>Type</th><th>Date</th><th>Status</th><th>Cost</th><th>Notes</th></tr>";
while($r=$res->fetch_assoc()){
  echo "<tr><td>{$r['type']}</td><td>{$r['schedule_date']}</td><td>".ucfirst($r['status'])."</td>
  <td>₱".number_format($r['estimated_cost']??0,2)."</td><td>".h($r['notes']??'—')."</td></tr>";
}
echo "</table>";
function h($v){return htmlspecialchars($v,ENT_QUOTES,'UTF-8');}
?>
