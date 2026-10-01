<?php
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$DB_HOST="127.0.0.1"; $DB_USER="root"; $DB_PASS=""; $DB_NAME="fleet_rental_db";
$conn=new mysqli($DB_HOST,$DB_USER,$DB_PASS,$DB_NAME);
$conn->set_charset("utf8mb4");

$user_id=(int)($_GET['user_id']??0);
if($user_id<=0){echo "<p>Invalid user.</p>";exit;}

$sql="SELECT r.id,v.make_model,v.plate_no,r.start_date,r.end_date,r.status,r.base_amount
      FROM rentals r
      JOIN vehicles v ON v.id=r.vehicle_id
      WHERE r.customer_id=? ORDER BY r.start_date DESC";
$stmt=$conn->prepare($sql);
$stmt->bind_param("i",$user_id);
$stmt->execute();
$res=$stmt->get_result();

echo "<h2>User Rentals</h2>";
if(!$res->num_rows){echo "<p style='color:#9aa6b3'>No rentals found.</p>";exit;}
echo "<table class='sub'><tr><th>Vehicle</th><th>Plate</th><th>Start</th><th>End</th><th>Status</th><th>Total</th></tr>";
while($r=$res->fetch_assoc()){
  $color=($r['status']==='completed')?'#7cffc7':(($r['status']==='cancelled')?'#ff6b6b':'#ffd166');
  echo "<tr>
    <td>".htmlspecialchars($r['make_model'])."</td>
    <td>".htmlspecialchars($r['plate_no'])."</td>
    <td>".htmlspecialchars($r['start_date'])."</td>
    <td>".htmlspecialchars($r['end_date'])."</td>
    <td style='color:$color;font-weight:700;'>".ucfirst($r['status'])."</td>
    <td>₱".number_format($r['base_amount'],2)."</td>
  </tr>";
}
echo "</table>";
$stmt->close();$conn->close();
?>
