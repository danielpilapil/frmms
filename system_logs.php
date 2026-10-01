<?php
/* =========================================
   system_logs.php — User/Staff View of System Logs
   ========================================= */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$DB_HOST="127.0.0.1"; $DB_USER="root"; $DB_PASS=""; $DB_NAME="fleet_rental_db";
$conn=new mysqli($DB_HOST,$DB_USER,$DB_PASS,$DB_NAME);
$conn->set_charset("utf8mb4");

function h($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}

/* --- Filters and pagination --- */
$q      = trim($_GET['q'] ?? '');
$page   = max(1,(int)($_GET['page'] ?? 1));
$limit  = 15;
$offset = ($page-1)*$limit;

$where  = [];
$params = []; $types='';

if($q!==''){
  $where[]="(l.action LIKE CONCAT('%',?,'%') OR l.entity LIKE CONCAT('%',?,'%') OR u.username LIKE CONCAT('%',?,'%'))";
  $params[]=$q; $params[]=$q; $params[]=$q;
  $types.='sss';
}
$whereSql = $where ? "WHERE ".implode(" AND ",$where) : "";

/* --- Total count --- */
$sqlCount="SELECT COUNT(*) c FROM system_logs l LEFT JOIN users u ON l.actor_user_id=u.id $whereSql";
$stmt=$conn->prepare($sqlCount);
if($types) $stmt->bind_param($types,...$params);
$stmt->execute(); $total=$stmt->get_result()->fetch_assoc()['c']; $stmt->close();

/* --- Fetch logs --- */
$sql="SELECT l.*, u.username
      FROM system_logs l
      LEFT JOIN users u ON l.actor_user_id=u.id
      $whereSql
      ORDER BY l.created_at DESC
      LIMIT ? OFFSET ?";
$stmt=$conn->prepare($sql);
if($types){
  $types2=$types.'ii';
  $params2=$params; $params2[]=$limit; $params2[]=$offset;
  $stmt->bind_param($types2,...$params2);
}else{
  $stmt->bind_param('ii',$limit,$offset);
}
$stmt->execute(); $logs=$stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>System Logs • Fleet Rental</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
<style>
:root{--bg:#0b0d10;--card:#101419;--text:#f2f6fa;--muted:#9aa6b3;
--brand:#5dd0ff;--brand-2:#7cffc7;--radius:12px;}
body{margin:0;font-family:Inter,system-ui,sans-serif;background:var(--bg);color:var(--text);}
.wrap{max-width:1100px;margin:0 auto;padding:20px}
h1{margin:0 0 16px}
.card{background:var(--card);padding:16px;border-radius:var(--radius);box-shadow:0 6px 18px rgba(0,0,0,.3);}
table{width:100%;border-collapse:collapse;margin-top:12px}
th,td{padding:.6rem;text-align:left;border-bottom:1px solid rgba(255,255,255,.1)}
th{color:var(--muted);font-weight:600}
.searchbar{margin-bottom:1rem}
.input{width:100%;padding:.6rem;border-radius:var(--radius);border:1px solid rgba(255,255,255,.12);background:#0d1116;color:var(--text);}
.pager{margin-top:1rem;display:flex;gap:.5rem;justify-content:flex-end}
.pager a{padding:.4rem .7rem;border-radius:8px;background:#0d1116;color:var(--text);text-decoration:none}
.pager .active{background:linear-gradient(90deg,var(--brand),var(--brand-2));color:#04121b;font-weight:700}
</style>
</head>
<body>
<?php if(file_exists(__DIR__.'/includes/navbar.php')) require __DIR__.'/includes/navbar.php'; ?>
<div class="wrap">
  <h1>System Logs</h1>
  <div class="card">
    <form method="get" class="searchbar">
      <input class="input" type="text" name="q" placeholder="Search by user, action, entity…" value="<?=h($q)?>">
    </form>

    <table>
      <thead>
        <tr>
          <th>Date/Time</th><th>User</th><th>Action</th><th>Entity</th><th>Entity ID</th><th>Details</th>
        </tr>
      </thead>
      <tbody>
        <?php if($logs->num_rows): while($r=$logs->fetch_assoc()): ?>
          <tr>
            <td><?=h($r['created_at'])?></td>
            <td><?=h($r['username'] ?? '—')?></td>
            <td><?=h($r['action'])?></td>
            <td><?=h($r['entity'])?></td>
            <td><?=h($r['entity_id'])?></td>
            <td><?=h($r['details'])?></td>
          </tr>
        <?php endwhile; else: ?>
          <tr><td colspan="6">No logs found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>

    <?php
      $lastPage=(int)ceil(max(1,$total)/$limit);
      if($lastPage>1):
    ?>
    <div class="pager">
      <?php for($i=1;$i<=$lastPage;$i++): ?>
        <a href="?<?=http_build_query(array_merge($_GET,['page'=>$i]))?>" class="<?=$i===$page?'active':''?>"><?=$i?></a>
      <?php endfor; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
<?php
if($logs instanceof mysqli_result) $logs->free();
$conn->close();
