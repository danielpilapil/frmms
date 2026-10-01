<?php
/* =========================================
   vehicles_availability.php — Availability with Photos + Live Status
   - Shows uploaded photos (vehicles.photo) with fallback images
   - If no rental/maintenance overlap, uses vehicles.current_status
   ========================================= */

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$DB_HOST   = "127.0.0.1";
$DB_PORT   = 3306;
$DB_USER   = "root";
$DB_PASS   = "";
$DB_NAME   = "fleet_rental_db";
$DB_SOCKET = ini_get('mysqli.default_socket') ?: null;

try {
  $conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT, $DB_SOCKET);
  $conn->set_charset('utf8mb4');
} catch (mysqli_sql_exception $e) {
  die("DB connection failed: " . $e->getMessage());
}

/* --- Ensure photo column exists (safe if already there) --- */
try {
  $conn->query("ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS photo VARCHAR(255) DEFAULT NULL");
} catch (Throwable $e) { /* MariaDB <10.3? ignore if already there */ }

/* helpers */
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function d($v){ return date('Y-m-d', strtotime($v)); }
function img_for_card($type, $photo){
  if (!empty($photo)) return 'assets/vehicles/'.h($photo); // stored relative path
  $t = strtolower((string)$type);
  if ($t==='suv') return 'assets/vehicles/suv.jpg';
  if ($t==='motorcycle') return 'assets/vehicles/motorcycle.jpg';
  if ($t==='mpv/van' || $t==='van' || $t==='mpv') return 'assets/vehicles/van.jpg';
  if ($t==='pickup') return 'assets/vehicles/pickup.jpg';
  return 'assets/vehicles/sedan.jpg';
}

/* inputs */
$today = date('Y-m-d');
$start = isset($_GET['start']) && $_GET['start']!=='' ? d($_GET['start']) : $today;
$end   = isset($_GET['end'])   && $_GET['end']  !=='' ? d($_GET['end'])   : date('Y-m-d', strtotime('+7 days'));
$q      = trim((string)($_GET['q'] ?? ''));
$type   = trim((string)($_GET['type'] ?? '')); 
$status = trim((string)($_GET['status'] ?? ''));

/* detect vehicle_type or infer (richer) */
$hasTypeCol = false;
try {
  $chk = $conn->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='vehicles' AND COLUMN_NAME='vehicle_type'");
  $hasTypeCol = (bool)$chk->fetch_row();
  $chk->free();
} catch (Throwable $e) {}
$CASE_INFER = "
  CASE
    WHEN LOWER(make_model) REGEXP '(motor|mio|scoot|scooter|bike)' THEN 'Motorcycle'
    WHEN LOWER(make_model) REGEXP '(pickup|ranger|hilux|navara|strada|d[- ]?max|l200|triton)' THEN 'Pickup'
    WHEN LOWER(make_model) REGEXP '(van|mpv|innova|avanza|odyssey|hiace|starex|urvan|venture|mpv)' THEN 'MPV/Van'
    WHEN LOWER(make_model) REGEXP '(suv|fortuner|montero|everest|trailblazer|crosstrek|outlander|cr[- ]?v|cx-?[0-9]|rav4|prado|patrol|xtrail|sequoia|seqouia)' THEN 'SUV'
    WHEN LOWER(make_model) REGEXP '(sedan|vios|city|civic|accent|mazda[[:space:]]*3|yaris|almera|elantra|corolla|lancer|focus)' THEN 'Sedan'
    ELSE 'Other'
  END
";
$typeExpr = $hasTypeCol ? "v.vehicle_type" : "($CASE_INFER)";

/* filters */
$where=[];$bt='';$bp=[];
if ($q!==''){ $where[]="(v.plate_no LIKE CONCAT('%', ?, '%') OR v.make_model LIKE CONCAT('%', ?, '%'))"; $bt.='ss'; array_push($bp,$q,$q); }
if ($type!==''){ $where[]="$typeExpr = ?"; $bt.='s'; $bp[]=$type; }
$vehWhere = $where ? 'WHERE '.implode(' AND ',$where) : '';

/* fetch vehicles (include photo) */
$sql="SELECT v.id,v.plate_no,v.make_model,v.year,v.odometer,v.current_status,$typeExpr AS vtype,v.photo
      FROM vehicles v $vehWhere
      ORDER BY vtype,make_model,plate_no";
$stmt=$conn->prepare($sql);
if($bt) $stmt->bind_param($bt,...$bp);
$stmt->execute(); $vehRes=$stmt->get_result();
$vehicles=[];$vehIds=[];
while($r=$vehRes->fetch_assoc()){ $vehicles[]=$r; $vehIds[]=(int)$r['id']; }
$stmt->close();

/* rentals + maintenance overlap (within [start,end]) */
$rents=[];$maint=[];
foreach($vehIds as $id){$rents[$id]=[];$maint[$id]=[];}

if($vehIds){
  $ph=implode(',',array_fill(0,count($vehIds),'?'));

  /* Rentals that overlap the range */
  $btR=str_repeat('i',count($vehIds)).'ss';
  $sql="SELECT vehicle_id,start_date,end_date
        FROM rentals
        WHERE vehicle_id IN ($ph)
          AND status IN('reserved','ongoing')
          AND NOT(end_date<? OR start_date>?)";
  $stmt=$conn->prepare($sql); $params=$vehIds; $params[]=$start; $params[]=$end;
  $stmt->bind_param($btR,...$params); $stmt->execute(); $rs=$stmt->get_result();
  while($row=$rs->fetch_assoc()){ $rents[(int)$row['vehicle_id']][]=$row; }
  $stmt->close();

  /* Maintenance within the range */
  $btM=str_repeat('i',count($vehIds)).'ss';
  $sql="SELECT vehicle_id,schedule_date
        FROM maintenance
        WHERE vehicle_id IN ($ph)
          AND status IN('scheduled','in_progress')
          AND schedule_date BETWEEN ? AND ?";
  $stmt=$conn->prepare($sql); $params=$vehIds; $params[]=$start; $params[]=$end;
  $stmt->bind_param($btM,...$params); $stmt->execute(); $ms=$stmt->get_result();
  while($row=$ms->fetch_assoc()){ $maint[(int)$row['vehicle_id']][]=$row; }
  $stmt->close();
}

/* compute rows + KPIs
   - Priority: if any overlap => booked/maint (range view)
   - Else: if TODAY is inside [start,end], fall back to vehicles.current_status
*/
$rows=[];$kpi=['total'=>0,'available'=>0,'booked'=>0,'maint'=>0];
$todayInRange = ($today >= $start && $today <= $end);

foreach($vehicles as $v){
  $vid=(int)$v['id'];
  $overlapBooked = !empty($rents[$vid]);
  $overlapMaint  = !empty($maint[$vid]);

  if ($overlapMaint) {
    $rangeStatus = 'maint_in_range';
  } elseif ($overlapBooked) {
    $rangeStatus = 'booked_in_range';
  } else {
    // no overlap: if today is within the requested window, reflect current_status
    if ($todayInRange) {
      if ($v['current_status']==='maintenance') $rangeStatus = 'maint_in_range';
      elseif ($v['current_status']==='rented')   $rangeStatus = 'booked_in_range';
      else $rangeStatus = 'available_in_range';
    } else {
      // outside today-context: assume available (no scheduled overlap)
      $rangeStatus = 'available_in_range';
    }
  }

  // KPIs
  $kpi['total']++;
  if($rangeStatus==='available_in_range') $kpi['available']++;
  elseif($rangeStatus==='booked_in_range') $kpi['booked']++;
  else $kpi['maint']++;

  // page filter on "status"
  $include=true;
  if($status!==''){
    if(in_array($status,['available','rented','maintenance'], true)){
      // filter by CURRENT status value
      $include=(strtolower($v['current_status'])===$status);
    } else {
      // filter by RANGE status bucket
      $include=($rangeStatus===$status);
    }
  }
  if($include)$rows[]=['v'=>$v,'in_range_status'=>$rangeStatus];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Vehicles Availability • Fleet Rental</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--bg:#0b0d10;--card:#101419;--text:#f2f6fa;--muted:#9aa6b3;--brand:#5dd0ff;--brand-2:#7cffc7;
--success:#7cffc7;--warn:#ffd166;--danger:#ff6b6b;--radius:14px;--shadow:0 6px 18px rgba(0,0,0,.35);}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,system-ui,sans-serif}
.wrap{max-width:1280px;margin:0 auto;padding:24px}
h1{font-size:1.8rem;margin-bottom:.5rem}
.kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin:1rem 0}
.kpi{background:#0e1318;border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:14px;text-align:center}
.kpi small{color:var(--muted)}.kpi b{display:block;font-size:1.4rem;margin-top:.25rem}
.filters{display:grid;grid-template-columns:repeat(6,1fr);gap:.6rem;align-items:end;margin:1rem 0}
label{display:block;font-weight:700;margin-bottom:.25rem}
.input,.select,.btn{background:#0d1116;border:1px solid rgba(255,255,255,.12);color:var(--text);border-radius:10px;padding:.6rem .7rem;width:100%}
.btn{cursor:pointer;background:linear-gradient(90deg,var(--brand),var(--brand-2));color:#04121b;font-weight:700;border:0}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px}
.item{border:1px solid rgba(255,255,255,.08);background:var(--card);border-radius:14px;box-shadow:var(--shadow);padding:14px}
.thumb{width:100%;height:150px;object-fit:cover;border-radius:10px;margin-bottom:10px;background:#0d1116}
.row{display:flex;justify-content:space-between;align-items:center;margin-bottom:.25rem}
.pill{padding:.25rem .6rem;border-radius:999px;font-size:.75rem;font-weight:700}
.ok{background:rgba(124,255,199,.16);color:var(--success)}.warn{background:rgba(255,209,102,.16);color:var(--warn)}.bad{background:rgba(255,107,107,.16);color:var(--danger)}
.muted{color:#9aa6b3}
</style>
</head>
<body>
<?php if(file_exists(__DIR__.'/includes/navbar.php')) require __DIR__.'/includes/navbar.php'; ?>
<div class="wrap">
  <h1>Vehicles Availability</h1>
  <div class="kpis">
    <div class="kpi"><small>Available in range</small><b><?= (int)$kpi['available'] ?></b></div>
    <div class="kpi"><small>Booked in range</small><b><?= (int)$kpi['booked'] ?></b></div>
    <div class="kpi"><small>Maintenance in range</small><b><?= (int)$kpi['maint'] ?></b></div>
    <div class="kpi"><small>Total</small><b><?= (int)$kpi['total'] ?></b></div>
  </div>
  <form class="filters" method="get">
    <div><label>Start</label><input class="input" type="date" name="start" value="<?= h($start) ?>"></div>
    <div><label>End</label><input class="input" type="date" name="end" value="<?= h($end) ?>"></div>
    <div><label>Search</label><input class="input" type="text" name="q" placeholder="Plate or model…" value="<?= h($q) ?>"></div>
    <div><label>Type</label>
      <select class="select" name="type">
        <?php foreach(['','SUV','Sedan','Motorcycle','MPV/Van','Pickup','Other'] as $opt): ?>
          <option value="<?= h($opt) ?>" <?= $type===$opt?'selected':'' ?>><?= $opt===''?'All':$opt ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div><label>Show by</label>
      <select class="select" name="status" title="Choose range buckets or current status">
        <?php
          $opts = [
            ''=>'All',
            'available_in_range'=>'Available (Range)',
            'booked_in_range'=>'Booked (Range)',
            'maint_in_range'=>'Maintenance (Range)',
            'available'=>'Current=Available',
            'rented'=>'Current=Rented',
            'maintenance'=>'Current=Maintenance'
          ];
          foreach($opts as $val=>$lbl){
            $sel=$status===$val?'selected':''; echo "<option value='".h($val)."' $sel>".h($lbl)."</option>";
          }
        ?>
      </select>
    </div>
    <div><label>&nbsp;</label><button class="btn" type="submit">Apply</button></div>
  </form>

  <div class="grid">
    <?php if(count($rows)): foreach($rows as $row): $v=$row['v']; ?>
      <?php
        $range = $row['in_range_status'];
        $cls='ok'; $txt='Available';
        if($range==='booked_in_range'){ $cls='warn'; $txt='Booked'; }
        elseif($range==='maint_in_range'){ $cls='bad'; $txt='Maintenance'; }
        $img = img_for_card($v['vtype'], $v['photo'] ?? null);
      ?>
      <div class="item">
        <img class="thumb" src="<?= h($img) ?>" alt="Vehicle">
        <div class="row"><b><?= h($v['make_model']) ?></b><span class="pill <?= $cls ?>"><?= h($txt) ?></span></div>
        <div class="muted">Plate: <?= h($v['plate_no']) ?> • Year: <?= (int)$v['year'] ?> • Odo: <?= number_format((int)$v['odometer']) ?> km</div>
        <div style="margin-top:.5rem" class="muted">Current: <b><?= h(ucfirst($v['current_status'])) ?></b> • Type: <?= h($v['vtype']) ?></div>
      </div>
    <?php endforeach; else: ?>
      <div class="item"><div>No vehicles match your filters.</div></div>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
<?php $conn->close(); ?>
