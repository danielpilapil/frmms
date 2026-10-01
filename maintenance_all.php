<?php
/* ============================================================
   maintenance_all.php — FleetGo Maintenance Logs + DSS Forecast
   - Log maintenance (type, vehicle, cost, receipt)
   - Tabs: Maintenance (DSS + due list) | History
   - DSS KPIs from odometer (updated on rental returns)
   ============================================================ */

if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
session_name('fleetgo_session_admin');
session_start();

require_once __DIR__ . '/includes/db.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
  header('Location: login.php');
    exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (!function_exists('h')) {
  function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
  }
}

function hnum($n, $d = 2) {
  return number_format((float)$n, $d);
}

/* ---- Schema: receipt path ---- */
try {
  $col = $conn->query("SHOW COLUMNS FROM maintenance LIKE 'receipt_path'");
  if (!$col || $col->num_rows === 0) {
    $conn->query("ALTER TABLE maintenance ADD COLUMN receipt_path VARCHAR(255) NULL DEFAULT NULL AFTER notes");
  }
} catch (Throwable $e) {
  // ignore if alter fails; upload still attempted into notes fallback
}

$RECEIPT_DIR = __DIR__ . '/uploads/maintenance_receipts';
$RECEIPT_URL = 'uploads/maintenance_receipts';
if (!is_dir($RECEIPT_DIR)) {
  @mkdir($RECEIPT_DIR, 0775, true);
}

/* ---- Service catalog (DSS intervals based on odometer) ---- */
$SERVICE_CATALOG = [
  'Change Oil' => [
    'interval_km' => 5000,
    'due_soon_km' => 500,
    'category' => 'preventive',
    'match' => ['oil'],
  ],
  'Change Tires' => [
    'interval_km' => 40000,
    'due_soon_km' => 2000,
    'category' => 'preventive',
    'match' => ['tire', 'tyre'],
  ],
  'Fixed Brakes' => [
    'interval_km' => 20000,
    'due_soon_km' => 1500,
    'category' => 'corrective',
    'match' => ['brake'],
  ],
  'Battery Replacement' => [
    'interval_km' => 30000,
    'due_soon_km' => 2000,
    'category' => 'corrective',
    'match' => ['battery'],
  ],
  'Transmission Service' => [
    'interval_km' => 40000,
    'due_soon_km' => 2500,
    'category' => 'preventive',
    'match' => ['transmission'],
  ],
  'Air Filter Replacement' => [
    'interval_km' => 15000,
    'due_soon_km' => 1000,
    'category' => 'preventive',
    'match' => ['air filter', 'filter'],
  ],
  'Car Wash / Detailing' => [
    'interval_km' => 3000,
    'due_soon_km' => 400,
    'category' => 'cleaning',
    'match' => ['wash', 'detail', 'cleaning'],
  ],
  'Other' => [
    'interval_km' => 10000,
    'due_soon_km' => 800,
    'category' => 'corrective',
    'match' => [],
  ],
];

function vehicle_mileage(array $v): float {
  if (isset($v['mileage'])) return (float)$v['mileage'];
  $a = (float)($v['current_odometer'] ?? 0);
  $b = (float)($v['odometer'] ?? 0);
  return max($a, $b);
}

function service_matches(string $description, array $needles): bool {
  $d = strtolower($description);
  foreach ($needles as $n) {
    if ($n !== '' && strpos($d, strtolower($n)) !== false) return true;
  }
  return false;
}

$flash = '';
$flashErr = '';
$activeTab = ($_GET['tab'] ?? 'maintenance') === 'history' ? 'history' : 'maintenance';

/* ---- Add maintenance log ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_log') {
  $vehicleId = (int)($_POST['vehicle_id'] ?? 0);
  $serviceType = trim((string)($_POST['service_type'] ?? ''));
  $customType = trim((string)($_POST['custom_type'] ?? ''));
  $totalCost = (float)($_POST['total_cost'] ?? 0);
  $notes = trim((string)($_POST['notes'] ?? ''));

  if ($serviceType === 'Other' && $customType !== '') {
    $serviceType = $customType;
  }

  $catalogKey = array_key_exists($serviceType, $SERVICE_CATALOG) ? $serviceType : 'Other';
  $meta = $SERVICE_CATALOG[$catalogKey];
  $category = $meta['category'];

  if ($vehicleId <= 0 || $serviceType === '' || $totalCost < 0) {
    $flashErr = 'Please select a vehicle, maintenance type, and a valid cost.';
  } else {
    try {
      $veh = null;
      try {
        $veh = $conn->query('SELECT id, make_model, plate_no, odometer, current_odometer, current_status FROM vehicles WHERE id = ' . $vehicleId)->fetch_assoc();
      } catch (Throwable $e) {
        $veh = $conn->query('SELECT id, make_model, plate_no, odometer, current_status FROM vehicles WHERE id = ' . $vehicleId)->fetch_assoc();
      }
      if (!$veh) throw new Exception('Vehicle not found.');

      $odo = vehicle_mileage($veh);
      $nextDue = $odo + (float)$meta['interval_km'];
      $today = date('Y-m-d');
      $receiptPath = null;

      if (!empty($_FILES['receipt']['name']) && (int)$_FILES['receipt']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['receipt']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf'];
        if (!in_array($ext, $allowed, true)) {
          throw new Exception('Receipt must be an image or PDF.');
        }
        if ((int)$_FILES['receipt']['size'] > 8 * 1024 * 1024) {
          throw new Exception('Receipt file is too large (max 8MB).');
        }
        $fname = 'maint_' . $vehicleId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $dest = $RECEIPT_DIR . '/' . $fname;
        if (!move_uploaded_file($_FILES['receipt']['tmp_name'], $dest)) {
          throw new Exception('Failed to upload receipt.');
        }
        $receiptPath = $RECEIPT_URL . '/' . $fname;
      }
      if ($receiptPath === null) $receiptPath = '';

      $desc = $serviceType;
      $fullNotes = $notes; // may be empty string
      $scheduleDate = $today;
      $completedDate = $today;
      $estCost = $totalCost;

      $hasReceiptCol = false;
      try {
        $c = $conn->query("SHOW COLUMNS FROM maintenance LIKE 'receipt_path'");
        $hasReceiptCol = $c && $c->num_rows > 0;
      } catch (Throwable $e) {}

      if ($hasReceiptCol) {
                $stmt = $conn->prepare("
          INSERT INTO maintenance
            (vehicle_id, maintenance_category, cost, schedule_date, completed_date, completed_at,
             description, priority_level, source_type, status, notes, estimated_cost,
             next_due_odometer, receipt_path, created_at)
          VALUES (?, ?, ?, ?, ?, NOW(), ?, 'medium', 'manual', 'completed', ?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param(
          'isdssssdds',
          $vehicleId,
          $category,
          $totalCost,
          $scheduleDate,
          $completedDate,
          $desc,
          $fullNotes,
          $estCost,
          $nextDue,
          $receiptPath
        );
      } else {
        $notesWithReceipt = $fullNotes;
        if ($receiptPath) {
          $notesWithReceipt = trim(($fullNotes !== '' ? $fullNotes . "\n" : '') . 'Receipt: ' . $receiptPath);
        }
                $stmt = $conn->prepare("
          INSERT INTO maintenance
            (vehicle_id, maintenance_category, cost, schedule_date, completed_date, completed_at,
             description, priority_level, source_type, status, notes, estimated_cost,
             next_due_odometer, created_at)
          VALUES (?, ?, ?, ?, ?, NOW(), ?, 'medium', 'manual', 'completed', ?, ?, ?, NOW())
        ");
        $stmt->bind_param(
          'isdssssdd',
          $vehicleId,
          $category,
          $totalCost,
          $scheduleDate,
          $completedDate,
          $desc,
          $notesWithReceipt,
          $estCost,
          $nextDue
        );
      }
                $stmt->execute();
      $stmt->close();

      // Sync oil-interval reference for forecast helper
      if (service_matches($desc, ['oil'])) {
        try {
          $exists = $conn->query('SELECT id FROM maintenance_rules WHERE vehicle_id = ' . $vehicleId . ' LIMIT 1')->fetch_assoc();
          if ($exists) {
            $u = $conn->prepare('UPDATE maintenance_rules SET last_ref_odometer = ?, last_ref_date = ? WHERE vehicle_id = ?');
            $odoInt = (int)round($odo);
            $u->bind_param('isi', $odoInt, $today, $vehicleId);
            $u->execute();
            $u->close();
          } else {
            $u = $conn->prepare('INSERT INTO maintenance_rules (vehicle_id, km_interval, months_interval, last_ref_odometer, last_ref_date) VALUES (?, 5000, 6, ?, ?)');
            $odoInt = (int)round($odo);
            $u->bind_param('iis', $vehicleId, $odoInt, $today);
            $u->execute();
            $u->close();
          }
        } catch (Throwable $e) {}
      }

      header('Location: maintenance_all.php?tab=history&saved=1');
      exit;
    } catch (Throwable $e) {
      $flashErr = $e->getMessage();
    }
  }
}

if (isset($_GET['saved'])) $flash = 'Maintenance log saved.';

/* ---- Load vehicles (same source as vehicles_all.php) ---- */
$vehicles = [];
try {
  // Prefer current_odometer (updated on rental return) with odometer fallback
  $res = $conn->query("
    SELECT
      v.id,
      v.make_model,
      v.plate_no,
      v.vehicle_type,
      v.photo,
      v.current_status,
      v.odometer,
      COALESCE(v.current_odometer, v.odometer, 0) AS mileage
    FROM vehicles v
    ORDER BY v.make_model ASC
  ");
  if ($res) $vehicles = $res->fetch_all(MYSQLI_ASSOC);
} catch (Throwable $e) {
  try {
    $res = $conn->query("
      SELECT
        v.id,
        v.make_model,
        v.plate_no,
        v.vehicle_type,
        v.photo,
        v.current_status,
        v.odometer,
        COALESCE(v.odometer, 0) AS mileage
      FROM vehicles v
      ORDER BY v.make_model ASC
    ");
    if ($res) $vehicles = $res->fetch_all(MYSQLI_ASSOC);
  } catch (Throwable $e2) {
    try {
      $res = $conn->query("SELECT * FROM vehicles ORDER BY make_model ASC");
      if ($res) {
        $vehicles = $res->fetch_all(MYSQLI_ASSOC);
        foreach ($vehicles as &$vv) {
          $vv['mileage'] = max((float)($vv['current_odometer'] ?? 0), (float)($vv['odometer'] ?? 0));
        }
        unset($vv);
      }
    } catch (Throwable $e3) {
      $vehicles = [];
      error_log('maintenance_all vehicles load failed: ' . $e3->getMessage());
    }
  }
}

/* ---- Completed history for last-service lookup ---- */
$historyRows = [];
try {
  $historyRows = $conn->query("
    SELECT m.*, v.make_model, v.plate_no, v.photo, v.vehicle_type
    FROM maintenance m
    JOIN vehicles v ON v.id = m.vehicle_id
    WHERE m.status = 'completed'
    ORDER BY COALESCE(m.completed_date, m.schedule_date, m.created_at) DESC, m.id DESC
  ")->fetch_all(MYSQLI_ASSOC);
} catch (Throwable $e) {
  $historyRows = [];
}

/* ---- Build DSS forecasts per vehicle × service type ---- */
$dssAlerts = []; // actionable overdue/due soon
$kpi = [
  'oil_overdue' => 0,
  'oil_due_soon' => 0,
  'tires_due' => 0,
  'brakes_due' => 0,
  'total_due' => 0,
  'fleet_ok' => 0,
];

foreach ($vehicles as $v) {
  $vid = (int)$v['id'];
  $mileage = vehicle_mileage($v);
  $vehicleHasIssue = false;

  foreach ($SERVICE_CATALOG as $typeName => $meta) {
    if ($typeName === 'Other' || $typeName === 'Car Wash / Detailing') continue;

    $lastServiceOdo = 0.0;
    foreach ($historyRows as $h) {
      if ((int)$h['vehicle_id'] !== $vid) continue;
      $desc = (string)($h['description'] ?? '');
      if (!empty($meta['match']) && !service_matches($desc, $meta['match'])) continue;
      // Prefer odometer implied by next_due - interval, else use vehicle mileage at log time unknown → 0 baseline
      if ($h['next_due_odometer'] !== null && $h['next_due_odometer'] !== '') {
        $lastServiceOdo = max($lastServiceOdo, (float)$h['next_due_odometer'] - (float)$meta['interval_km']);
      }
      break; // history is newest-first; first match is latest for this type
    }

    // Also consider maintenance_rules for oil
    if (service_matches($typeName, ['oil'])) {
      try {
        $rule = $conn->query('SELECT last_ref_odometer FROM maintenance_rules WHERE vehicle_id = ' . $vid . ' LIMIT 1')->fetch_assoc();
        if ($rule) $lastServiceOdo = max($lastServiceOdo, (float)$rule['last_ref_odometer']);
      } catch (Throwable $e) {}
    }

    $kmSince = max(0, $mileage - $lastServiceOdo);
    $kmLeft = (float)$meta['interval_km'] - $kmSince;
    $status = 'OK';
    if ($kmLeft <= 0) $status = 'OVERDUE';
    elseif ($kmLeft <= (float)$meta['due_soon_km']) $status = 'DUE SOON';

    if ($status === 'OK') continue;

    $vehicleHasIssue = true;
    $dssAlerts[] = [
      'vehicle_id' => $vid,
      'make_model' => $v['make_model'],
      'plate_no' => $v['plate_no'],
      'photo' => $v['photo'] ?? '',
      'service_type' => $typeName,
      'category' => $meta['category'],
      'current_mileage' => $mileage,
      'last_service_mileage' => $lastServiceOdo,
      'interval_km' => (int)$meta['interval_km'],
      'km_left' => (int)$kmLeft,
      'km_since' => (int)$kmSince,
      'status' => $status,
    ];

    if (service_matches($typeName, ['oil'])) {
      if ($status === 'OVERDUE') $kpi['oil_overdue']++;
      else $kpi['oil_due_soon']++;
    }
    if (service_matches($typeName, ['tire', 'tyre'])) $kpi['tires_due']++;
    if (service_matches($typeName, ['brake'])) $kpi['brakes_due']++;
  }

  if ($vehicleHasIssue) $kpi['total_due']++;
  else $kpi['fleet_ok']++;
}

$kpi['total_alerts'] = count($dssAlerts);

usort($dssAlerts, function ($a, $b) {
  $rank = ['OVERDUE' => 0, 'DUE SOON' => 1];
  $ra = $rank[$a['status']] ?? 9;
  $rb = $rank[$b['status']] ?? 9;
  if ($ra !== $rb) return $ra - $rb;
  return $a['km_left'] <=> $b['km_left'];
});

function veh_img($photo, $type = '') {
  if ($photo) return 'assets/vehicles/' . ltrim((string)$photo, '/');
  $t = strtolower((string)$type);
  if (strpos($t, 'motor') !== false) return 'assets/vehicles/motorcycle.jpg';
  if (strpos($t, 'suv') !== false) return 'assets/vehicles/suv.jpg';
  if (strpos($t, 'pickup') !== false) return 'assets/vehicles/pickup.jpg';
  return 'assets/vehicles/sedan.jpg';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>FleetGo Admin — Maintenance</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <style>
:root{
  --bg:#07090c;--text:#f2f6fa;--muted:#9aa6b3;
  --card:#0f1318;--line:#17202a;
  --brand:#5dd0ff;--brand2:#7cffc7;
  --ok:#7cffc7;--warn:#ffd166;--bad:#ff6b6b;
  --radius:16px;--shadow:0 18px 44px rgba(0,0,0,.45);
  --glass:rgba(16,20,25,.78);--glass-border:rgba(93,208,255,.12);
}
*{box-sizing:border-box}
body{
  margin:0;color:var(--text);font-family:Inter,system-ui,sans-serif;
  background:
    radial-gradient(1200px 600px at 20% 0%, rgba(93,208,255,.10), transparent 60%),
    radial-gradient(1000px 500px at 90% 10%, rgba(124,255,199,.08), transparent 55%),
    var(--bg);
}
a{color:inherit;text-decoration:none}
.wrap{max-width:1280px;margin:0 auto;padding:22px}

.page-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin:8px 0 18px}
.page-title h1{margin:0;font-size:2rem;font-weight:900;letter-spacing:.2px}
.page-title .sub{margin-top:6px;color:var(--muted);font-size:.9rem;line-height:1.35;max-width:520px}
.btn{
  display:inline-flex;align-items:center;justify-content:center;gap:8px;
  border:0;border-radius:12px;padding:10px 16px;font-weight:800;font-size:.88rem;cursor:pointer;
  transition:transform .15s ease, box-shadow .15s ease;
}
.btn:hover{transform:translateY(-1px)}
.btn-primary{background:linear-gradient(90deg,var(--brand),var(--brand2));color:#04121b;box-shadow:0 10px 26px rgba(93,208,255,.18)}
.btn-secondary{background:rgba(255,255,255,.08);color:var(--text);border:1px solid rgba(255,255,255,.14)}
.btn-ghost{background:transparent;color:var(--muted);border:1px solid transparent}

.flash{padding:12px 14px;border-radius:12px;margin-bottom:14px;font-weight:700;font-size:.9rem}
.flash.ok{background:rgba(124,255,199,.12);border:1px solid rgba(124,255,199,.28);color:#b7ffe4}
.flash.err{background:rgba(255,107,107,.12);border:1px solid rgba(255,107,107,.28);color:#ffc2c2}

.kpi-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:18px}
.kpi{
  background:var(--glass);border:1px solid var(--glass-border);border-radius:var(--radius);
  padding:14px 16px;box-shadow:var(--shadow);
}
.kpi .lbl{color:var(--muted);font-size:.75rem;font-weight:800;text-transform:uppercase;letter-spacing:.4px}
.kpi .val{font-size:1.7rem;font-weight:900;margin-top:4px;line-height:1.1}
.kpi .hint{color:var(--muted);font-size:.78rem;font-weight:600;margin-top:6px}
.kpi.bad .val{color:var(--bad)}
.kpi.warn .val{color:var(--warn)}
.kpi.ok .val{color:var(--ok)}

.tabs{display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap}
.tab{
  padding:10px 16px;border-radius:999px;font-weight:800;font-size:.88rem;
  background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.10);color:rgba(242,246,250,.75);cursor:pointer;
}
.tab.active{background:rgba(93,208,255,.16);border-color:rgba(93,208,255,.35);color:var(--brand)}

.panel{display:none}
.panel.active{display:block}

.card{
  background:var(--glass);backdrop-filter:blur(10px);
  border:1px solid var(--glass-border);border-radius:var(--radius);
  padding:18px;box-shadow:var(--shadow);margin-bottom:16px;
}
.card h2{margin:0 0 6px;font-size:1.1rem;font-weight:900}
.card .hint{color:var(--muted);font-size:.85rem;font-weight:600;margin-bottom:14px}

.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.form-grid .full{grid-column:1/-1}
.field label{display:block;font-size:.78rem;font-weight:800;color:var(--muted);margin-bottom:6px;text-transform:uppercase;letter-spacing:.4px}
.field input,.field select,.field textarea{
  width:100%;padding:11px 12px;border-radius:12px;
  border:1px solid rgba(255,255,255,.14);background:rgba(0,0,0,.22);color:var(--text);
  font:inherit;font-weight:600;
}
.field input:focus,.field select:focus,.field textarea:focus{
  outline:0;border-color:rgba(93,208,255,.55);box-shadow:0 0 0 3px rgba(93,208,255,.12);
}
.field select option{background:#111827;color:#e5e7eb}
.field .file-hint{margin-top:6px;color:var(--muted);font-size:.78rem;font-weight:600}
.file-upload{
  position:relative;
}
.file-upload input[type="file"]{
  position:absolute;width:1px;height:1px;padding:0;margin:-1px;
  overflow:hidden;clip:rect(0,0,0,0);border:0;
}
.file-upload-btn{
  display:flex;align-items:center;gap:10px;
  width:100%;min-height:44px;padding:11px 12px;
  border-radius:12px;cursor:pointer;
  border:1px solid rgba(255,255,255,.14);
  background:rgba(0,0,0,.22);color:var(--text);
  font:inherit;font-weight:600;font-size:1rem;
  text-transform:none;letter-spacing:normal;
  transition:background .15s ease, border-color .15s ease;
}
.file-upload-btn:hover{
  background:rgba(255,255,255,.06);
  border-color:rgba(255,255,255,.22);
}
.file-upload-btn svg{
  flex-shrink:0;width:18px;height:18px;color:rgba(242,246,250,.75);
}
.file-upload-name{
  color:rgba(242,246,250,.55);font-size:1rem;font-weight:600;
  line-height:1.2;text-transform:none;letter-spacing:normal;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0;
}
.file-upload-name.has-file{color:var(--text)}

.list{display:flex;flex-direction:column;gap:10px}
.alert-row,.hist-row{
  display:grid;grid-template-columns:72px 1.4fr 1fr .9fr .9fr auto;
  gap:12px;align-items:center;
  background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);
  border-radius:14px;padding:12px 14px;
}
.alert-row img,.hist-row img{
  width:56px;height:40px;object-fit:cover;border-radius:8px;border:1px solid rgba(255,255,255,.1);background:#0a0f14;
}
.primary{font-weight:800}
.muted{color:var(--muted);font-size:.8rem;font-weight:600}
.badge{
  display:inline-flex;align-items:center;padding:4px 10px;border-radius:999px;
  font-size:.7rem;font-weight:800;letter-spacing:.3px;text-transform:uppercase;border:1px solid;
}
.badge.overdue{background:rgba(255,107,107,.14);color:#ff9b9b;border-color:rgba(255,107,107,.3)}
.badge.duesoon{background:rgba(255,209,102,.14);color:#ffd166;border-color:rgba(255,209,102,.3)}
.badge.service{background:rgba(93,208,255,.12);color:#9edcff;border-color:rgba(93,208,255,.28)}
.empty{text-align:center;padding:36px 16px;color:var(--muted);font-weight:700}

.table-wrap{overflow:auto;border-radius:12px;border:1px solid rgba(255,255,255,.08)}
table.data{
  width:100%;border-collapse:collapse;min-width:780px;
}
table.data th{
  text-align:left;padding:12px 14px;font-size:.72rem;font-weight:800;
  text-transform:uppercase;letter-spacing:.45px;color:var(--muted);
  background:rgba(255,255,255,.03);border-bottom:1px solid rgba(255,255,255,.08);
  white-space:nowrap;
}
table.data td{
  padding:12px 14px;border-bottom:1px solid rgba(255,255,255,.06);
  vertical-align:middle;font-size:.9rem;font-weight:600;
}
table.data tr:last-child td{border-bottom:0}
table.data tr:hover td{background:rgba(93,208,255,.04)}
.veh-cell{display:flex;align-items:center;gap:10px;min-width:0}
.veh-cell img{
  width:48px;height:34px;object-fit:cover;border-radius:8px;
  border:1px solid rgba(255,255,255,.1);background:#0a0f14;flex-shrink:0;
}
.veh-cell .primary{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:220px}

.modal-bg{
  position:fixed;inset:0;background:rgba(5,10,15,.72);backdrop-filter:blur(8px);
  display:none;align-items:center;justify-content:center;z-index:1200;padding:18px;
}
.modal-bg.open{display:flex}
.modal-box{
  width:min(640px,96vw);max-height:90vh;overflow:auto;text-align:left;
  background:linear-gradient(180deg,#121820,#0c1016);
  border:1px solid rgba(255,255,255,.12);border-radius:18px;
  box-shadow:0 28px 70px rgba(0,0,0,.55);padding:20px 22px;
}
.modal-box h2{margin:0;font-size:1.15rem;font-weight:900}
.modal-box .modal-sub{color:var(--muted);font-size:.85rem;font-weight:600;margin:6px 0 16px}
.modal-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:8px;flex-wrap:wrap}
.modal-box.wide{width:min(820px,96vw)}
.view-log-layout{display:grid;grid-template-columns:1fr 1.1fr;gap:18px;align-items:start}
.view-log-details{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.view-log-card{
  background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);
  border-radius:12px;padding:12px 14px;
}
.view-log-card.full{grid-column:1/-1}
.view-log-card .k{color:var(--muted);font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.4px;margin:0 0 4px}
.view-log-card .v{color:var(--text);font-size:.95rem;font-weight:800;margin:0;word-break:break-word}
.view-log-receipt{
  background:rgba(0,0,0,.25);border:1px solid rgba(255,255,255,.1);
  border-radius:14px;padding:12px;min-height:220px;
  display:flex;align-items:center;justify-content:center;
}
.view-log-receipt img{
  max-width:100%;max-height:420px;object-fit:contain;border-radius:10px;
}
.view-log-receipt iframe{
  width:100%;height:420px;border:0;border-radius:10px;background:#fff;
}
.view-log-receipt .empty-receipt{color:var(--muted);font-weight:700;text-align:center;padding:24px}
@media (max-width:720px){
  .view-log-layout{grid-template-columns:1fr}
  .view-log-details{grid-template-columns:1fr}
}

@media (max-width:1100px){
  .kpi-grid{grid-template-columns:repeat(3,1fr)}
}
@media (max-width:720px){
  .kpi-grid{grid-template-columns:1fr 1fr}
  .form-grid{grid-template-columns:1fr}
}
</style>
</head>
<body>
<?php include __DIR__ . '/includes/navbar.php'; ?>

<div class="wrap">
  <div class="page-head">
    <div class="page-title">
      <h1>Fleet Maintenance</h1>
      <div class="sub">Log completed service work and watch DSS alerts driven by return odometer readings.</div>
            </div>
    <button class="btn btn-primary" type="button" onclick="openLogModal()">+ New Log</button>
        </div>

  <?php if ($flash): ?><div class="flash ok"><?= h($flash) ?></div><?php endif; ?>
  <?php if ($flashErr): ?><div class="flash err"><?= h($flashErr) ?></div><?php endif; ?>

  <div class="kpi-grid">
    <div class="kpi bad">
      <div class="lbl">Oil Overdue</div>
      <div class="val"><?= (int)$kpi['oil_overdue'] ?></div>
      <div class="hint">Past 5,000 km interval</div>
                    </div>
    <div class="kpi warn">
      <div class="lbl">Oil Due Soon</div>
      <div class="val"><?= (int)$kpi['oil_due_soon'] ?></div>
      <div class="hint">≤ 500 km remaining</div>
                    </div>
    <div class="kpi warn">
      <div class="lbl">Tires Due</div>
      <div class="val"><?= (int)$kpi['tires_due'] ?></div>
      <div class="hint">40,000 km interval</div>
                    </div>
    <div class="kpi warn">
      <div class="lbl">Brakes Due</div>
      <div class="val"><?= (int)$kpi['brakes_due'] ?></div>
      <div class="hint">20,000 km interval</div>
                    </div>
    <div class="kpi ok">
      <div class="lbl">Fleet OK</div>
      <div class="val"><?= (int)$kpi['fleet_ok'] ?></div>
      <div class="hint"><?= (int)$kpi['total_due'] ?> vehicle(s) need attention</div>
                    </div>
            </div>

  <div class="tabs">
    <a class="tab <?= $activeTab === 'maintenance' ? 'active' : '' ?>" href="maintenance_all.php?tab=maintenance">Maintenance</a>
    <a class="tab <?= $activeTab === 'history' ? 'active' : '' ?>" href="maintenance_all.php?tab=history">History</a>
                </div>

  <div class="panel <?= $activeTab === 'maintenance' ? 'active' : '' ?>" id="panel-maintenance">
    <div class="card">
      <h2>DSS Service Alerts</h2>
      <div class="hint">Predicted from vehicle odometer (updated when rentals are returned) vs last logged service of each type.</div>
      <?php if (!$dssAlerts): ?>
        <div class="empty">No overdue or due-soon services right now. Fleet looks healthy.</div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="data">
                    <thead>
                        <tr>
                            <th>Vehicle</th>
                <th>Service</th>
                <th>Odometer</th>
                <th>Since Service</th>
                <th>Interval</th>
                            <th>Status</th>
                <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
              <?php foreach ($dssAlerts as $a): ?>
                <tr>
                  <td>
                    <div class="veh-cell">
                      <img src="<?= h(veh_img($a['photo'] ?? '', '')) ?>" alt="" onerror="this.src='assets/vehicles/sedan.jpg'">
                      <div>
                        <div class="primary"><?= h($a['make_model']) ?></div>
                        <div class="muted"><?= h($a['plate_no']) ?></div>
                                        </div>
                                    </div>
                                </td>
                  <td><span class="badge service"><?= h($a['service_type']) ?></span></td>
                  <td><?= number_format((float)$a['current_mileage'], 0) ?> km</td>
                  <td><?= number_format((float)$a['km_since'], 0) ?> km</td>
                  <td><?= number_format((int)$a['interval_km']) ?> km</td>
                  <td>
                    <?php if ($a['status'] === 'OVERDUE'): ?>
                      <span class="badge overdue">Overdue</span>
                      <div class="muted" style="margin-top:4px"><?= abs((int)$a['km_left']) ?> km over</div>
                                    <?php else: ?>
                      <span class="badge duesoon">Due Soon</span>
                      <div class="muted" style="margin-top:4px"><?= (int)$a['km_left'] ?> km left</div>
                                    <?php endif; ?>
                                </td>
                                <td>
                    <button class="btn btn-secondary" type="button"
                      onclick="prefillLog(<?= (int)$a['vehicle_id'] ?>, '<?= h($a['service_type']) ?>')">Log Service</button>
                                </td>
                            </tr>
              <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
      <?php endif; ?>
        </div>
            </div>

  <div class="panel <?= $activeTab === 'history' ? 'active' : '' ?>" id="panel-history">
    <div class="card">
      <h2>Maintenance History</h2>
      <div class="hint"><?= count($historyRows) ?> completed log<?= count($historyRows) === 1 ? '' : 's' ?></div>
      <?php if (!$historyRows): ?>
        <div class="empty">No completed maintenance logs yet.</div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="data">
                    <thead>
                        <tr>
                            <th>Vehicle</th>
                <th>Type</th>
                <th>Date</th>
                <th>Cost</th>
                <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
              <?php foreach ($historyRows as $hrow):
                $receipt = $hrow['receipt_path'] ?? '';
                if (!$receipt && !empty($hrow['notes']) && preg_match('/Receipt:\s*(\S+)/', (string)$hrow['notes'], $m)) {
                  $receipt = $m[1];
                }
                $when = $hrow['completed_date'] ?: ($hrow['schedule_date'] ?: substr((string)$hrow['created_at'], 0, 10));
                $notesClean = (string)($hrow['notes'] ?? '');
                $notesClean = preg_replace('/\s*Receipt:\s*\S+/', '', $notesClean);
                $notesClean = trim($notesClean);
                $viewData = [
                  'id' => (int)$hrow['id'],
                  'vehicle' => (string)($hrow['make_model'] ?? ''),
                  'plate' => (string)($hrow['plate_no'] ?? ''),
                  'photo' => veh_img($hrow['photo'] ?? '', $hrow['vehicle_type'] ?? ''),
                  'type' => (string)($hrow['description'] ?: ucfirst((string)$hrow['maintenance_category'])),
                  'category' => ucfirst((string)($hrow['maintenance_category'] ?? '')),
                  'date' => date('M j, Y', strtotime($when)),
                  'cost' => '₱' . hnum($hrow['cost'] ?? $hrow['estimated_cost'] ?? 0),
                  'notes' => $notesClean,
                  'receipt' => (string)$receipt,
                ];
                $viewJson = htmlspecialchars(json_encode($viewData, JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
                        ?>
                            <tr>
                  <td>
                    <div class="veh-cell">
                      <img src="<?= h(veh_img($hrow['photo'] ?? '', $hrow['vehicle_type'] ?? '')) ?>" alt="" onerror="this.src='assets/vehicles/sedan.jpg'">
                      <div>
                        <div class="primary"><?= h($hrow['make_model']) ?></div>
                        <div class="muted"><?= h($hrow['plate_no']) ?></div>
                                        </div>
                                    </div>
                                </td>
                  <td>
                    <div class="primary"><?= h($hrow['description'] ?: ucfirst((string)$hrow['maintenance_category'])) ?></div>
                    <div class="muted"><?= h(ucfirst((string)$hrow['maintenance_category'])) ?></div>
                  </td>
                  <td><?= h(date('M j, Y', strtotime($when))) ?></td>
                  <td>₱<?= hnum($hrow['cost'] ?? $hrow['estimated_cost'] ?? 0) ?></td>
                  <td>
                    <button class="btn btn-secondary" type="button" data-log="<?= $viewJson ?>" onclick="openHistoryView(this)">View</button>
                                </td>
                            </tr>
              <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
      <?php endif; ?>
        </div>
    </div>
            </div>

<!-- New Log Modal -->
<div class="modal-bg" id="logModal" onclick="if(event.target===this)closeLogModal()">
  <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="logModalTitle">
    <h2 id="logModalTitle">Add Maintenance Log</h2>
    <div class="modal-sub">Record completed work: service type, vehicle, total cost, and receipt.</div>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="action" value="add_log">
                        <div class="form-grid">
        <div class="field">
          <label for="service_type">Type of Maintenance</label>
          <select name="service_type" id="service_type" required onchange="toggleCustomType(this.value)">
            <?php foreach ($SERVICE_CATALOG as $name => $_meta): ?>
              <option value="<?= h($name) ?>"><?= h($name) ?></option>
            <?php endforeach; ?>
                                </select>
                            </div>
        <div class="field" id="customTypeWrap" style="display:none">
          <label for="custom_type">Custom Type</label>
          <input type="text" name="custom_type" id="custom_type" placeholder="e.g. Spark plugs">
                            </div>
        <div class="field">
          <label for="vehicle_id">Select Car</label>
          <select name="vehicle_id" id="vehicle_id" required>
            <option value="">Choose vehicle…</option>
            <?php if (empty($vehicles)): ?>
              <option value="" disabled>No vehicles found — add cars on Vehicles page</option>
            <?php else: ?>
              <?php foreach ($vehicles as $v): ?>
                <option value="<?= (int)$v['id'] ?>">
                  <?= h($v['make_model'] ?? 'Vehicle') ?> · <?= h($v['plate_no'] ?? '') ?>
                  (<?= number_format(vehicle_mileage($v), 0) ?> km)
                                </option>
              <?php endforeach; ?>
            <?php endif; ?>
                        </select>
                    </div>
        <div class="field">
          <label for="total_cost">Total Cost (₱)</label>
          <input type="number" name="total_cost" id="total_cost" min="0" step="0.01" required placeholder="0.00">
                        </div>
        <div class="field">
          <label for="receipt">Add Receipt</label>
          <div class="file-upload">
            <input type="file" name="receipt" id="receipt" accept=".jpg,.jpeg,.png,.webp,.gif,.pdf,image/*,application/pdf" onchange="updateReceiptName(this)">
            <label class="file-upload-btn" for="receipt" title="Import receipt" aria-label="Import receipt">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                <polyline points="17 8 12 3 7 8"/>
                <line x1="12" y1="3" x2="12" y2="15"/>
              </svg>
              <span class="file-upload-name" id="receiptName">No file chosen</span>
            </label>
                        </div>
          <div class="file-hint">Image or PDF · max 8MB</div>
                    </div>
        <div class="field full">
          <label for="notes">Notes (optional)</label>
          <textarea name="notes" id="notes" rows="2" placeholder="Parts used, shop name, remarks…"></textarea>
                    </div>
        <div class="field full modal-actions">
          <button class="btn btn-secondary" type="button" onclick="closeLogModal()">Cancel</button>
          <button class="btn btn-primary" type="submit">Save Maintenance Log</button>
                </div>
                </div>
            </form>
        </div>
    </div>

<!-- History Detail Modal -->
<div class="modal-bg" id="historyModal" onclick="if(event.target===this)closeHistoryView()">
  <div class="modal-box wide" role="dialog" aria-modal="true" aria-labelledby="historyModalTitle">
    <h2 id="historyModalTitle">Maintenance Log</h2>
    <div class="modal-sub" id="historyModalSub">—</div>
    <div class="view-log-layout">
      <div>
        <div class="view-log-details" id="historyDetails"></div>
            </div>
      <div>
        <div class="view-log-card" style="margin-bottom:10px">
          <div class="k">Receipt</div>
            </div>
        <div class="view-log-receipt" id="historyReceipt"></div>
            </div>
        </div>
    <div class="modal-actions">
      <a class="btn btn-secondary" id="historyReceiptLink" href="#" target="_blank" rel="noopener" style="display:none">Open Receipt</a>
      <button class="btn btn-primary" type="button" onclick="closeHistoryView()">Close</button>
    </div>
        </div>
    </div>

    <script>
function updateReceiptName(input){
  const nameEl = document.getElementById('receiptName');
  if (!nameEl) return;
  const file = input?.files?.[0];
  if (file) {
    nameEl.textContent = file.name;
    nameEl.classList.add('has-file');
            } else {
    nameEl.textContent = 'No file chosen';
    nameEl.classList.remove('has-file');
  }
}
function toggleCustomType(val){
  const wrap = document.getElementById('customTypeWrap');
  if (!wrap) return;
  wrap.style.display = val === 'Other' ? '' : 'none';
}
function openLogModal(){
  document.getElementById('logModal')?.classList.add('open');
            document.body.style.overflow = 'hidden';
        }
function closeLogModal(){
  document.getElementById('logModal')?.classList.remove('open');
  if (!document.getElementById('historyModal')?.classList.contains('open')) {
            document.body.style.overflow = '';
        }
}
function openHistoryView(btn){
  let data = {};
  try { data = JSON.parse(btn.getAttribute('data-log') || '{}'); } catch(e) { data = {}; }

  document.getElementById('historyModalTitle').textContent = data.type || 'Maintenance Log';
  document.getElementById('historyModalSub').textContent =
    `${data.vehicle || '—'} · ${data.plate || '—'}`;

  const details = document.getElementById('historyDetails');
  const cards = [
    ['Vehicle', data.vehicle || '—'],
    ['Plate', data.plate || '—'],
    ['Service Type', data.type || '—'],
    ['Category', data.category || '—'],
    ['Date', data.date || '—'],
    ['Cost', data.cost || '—'],
  ];
  if (data.notes) cards.push(['Notes', data.notes]);

  details.innerHTML = cards.map(([k, v], i) => `
    <div class="view-log-card${k === 'Notes' ? ' full' : ''}">
      <div class="k">${k}</div>
      <div class="v">${String(v).replace(/</g,'&lt;').replace(/>/g,'&gt;')}</div>
                                </div>
  `).join('');

  const receiptBox = document.getElementById('historyReceipt');
  const receiptLink = document.getElementById('historyReceiptLink');
  const receipt = (data.receipt || '').trim();

  if (receipt) {
    const lower = receipt.toLowerCase();
    const isPdf = lower.endsWith('.pdf');
    if (isPdf) {
      receiptBox.innerHTML = `<iframe src="${receipt.replace(/"/g,'&quot;')}" title="Receipt PDF"></iframe>`;
    } else {
      receiptBox.innerHTML = `<img src="${receipt.replace(/"/g,'&quot;')}" alt="Receipt" onerror="this.parentNode.innerHTML='<div class=\\'empty-receipt\\'>Could not load receipt image.</div>'">`;
    }
    receiptLink.href = receipt;
    receiptLink.style.display = '';
  } else {
    receiptBox.innerHTML = `<div class="empty-receipt">No receipt uploaded for this log.</div>`;
    receiptLink.style.display = 'none';
  }

  document.getElementById('historyModal')?.classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeHistoryView(){
  document.getElementById('historyModal')?.classList.remove('open');
  if (!document.getElementById('logModal')?.classList.contains('open')) {
    document.body.style.overflow = '';
  }
}
function prefillLog(vehicleId, serviceType){
  const typeEl = document.getElementById('service_type');
  const vehEl = document.getElementById('vehicle_id');
  if (typeEl) {
    const opt = Array.from(typeEl.options).find(o => o.value === serviceType);
    if (opt) {
      typeEl.value = serviceType;
      toggleCustomType(serviceType);
            } else {
      typeEl.value = 'Other';
      toggleCustomType('Other');
      const custom = document.getElementById('custom_type');
      if (custom) custom.value = serviceType;
    }
  }
  if (vehEl) vehEl.value = String(vehicleId);
  openLogModal();
  setTimeout(() => document.getElementById('total_cost')?.focus(), 50);
}
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') {
    closeLogModal();
    closeHistoryView();
  }
});
toggleCustomType(document.getElementById('service_type')?.value || '');
<?php if ($flashErr): ?>
openLogModal();
<?php endif; ?>
    </script>
</body>
</html>
<?php $conn->close(); ?>
