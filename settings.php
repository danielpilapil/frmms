<?php
if (session_status() === PHP_SESSION_NONE) {
  session_name('fleetgo_session_admin');
  session_start();
}

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
  header('Location: login.php');
  exit;
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/payment_methods.php';

function h($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}

pm_ensure_table($conn);

$conn->query("CREATE TABLE IF NOT EXISTS system_settings (
  `key` VARCHAR(100) NOT NULL,
  `value` TEXT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

function get_setting(mysqli $conn, string $key, string $default = ''): string {
  $stmt = $conn->prepare("SELECT `value` FROM system_settings WHERE `key` = ? LIMIT 1");
  $stmt->bind_param('s', $key);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$row) return $default;
  return (string)($row['value'] ?? $default);
}

function set_setting(mysqli $conn, string $key, ?string $value): void {
  $stmt = $conn->prepare("INSERT INTO system_settings(`key`,`value`) VALUES(?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
  $stmt->bind_param('ss', $key, $value);
  $stmt->execute();
  $stmt->close();
}

$flash = '';
$flashError = '';
$flashMessages = [
  'pm_added'   => 'Payment method added.',
  'pm_toggled' => 'Payment method updated.',
  'pm_deleted' => 'Payment method removed.',
];
if (isset($_GET['msg'], $flashMessages[$_GET['msg']])) {
  $flash = $flashMessages[$_GET['msg']];
}

$pmAction = $_POST['pm_action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pmAction === 'add') {
  $code = strtoupper(trim((string)($_POST['provider_code'] ?? '')));
  $provider = pm_provider_lookup($code);
  $accountName = trim((string)($_POST['account_name'] ?? ''));
  $accountNumber = trim((string)($_POST['account_number'] ?? ''));
  $qrRel = null;

  if (!$provider) {
    $flashError = 'Please select a bank or e-wallet.';
  } elseif ($accountNumber === '' || strlen($accountNumber) > 60) {
    $flashError = 'Please enter a valid account number.';
  } elseif ($accountName === '' || strlen($accountName) > 120) {
    $flashError = 'Please enter the account name (max 120 characters).';
  } elseif (!empty($_FILES['qr_image']['name'])) {
    $file = $_FILES['qr_image'];
    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
      $flashError = 'QR code upload failed. Please try again.';
    } elseif (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true) || @getimagesize($file['tmp_name']) === false) {
      $flashError = 'QR code must be a JPG, PNG, WEBP, or GIF image.';
    } elseif (($file['size'] ?? 0) > 5 * 1024 * 1024) {
      $flashError = 'QR code image must be 5MB or smaller.';
    } else {
      $dir = __DIR__ . '/uploads/payment_qr/';
      if (!is_dir($dir)) mkdir($dir, 0777, true);
      $name = 'qr_' . strtolower($code) . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
      if (move_uploaded_file($file['tmp_name'], $dir . $name)) {
        $qrRel = 'uploads/payment_qr/' . $name;
      } else {
        $flashError = 'Could not save the QR code image.';
      }
    }
  }

  if ($flashError === '') {
    $stmt = $conn->prepare("INSERT INTO admin_payment_methods (provider_code, provider_name, provider_type, account_name, account_number, qr_image) VALUES (?,?,?,?,?,?)");
    $stmt->bind_param('ssssss', $code, $provider['name'], $provider['type'], $accountName, $accountNumber, $qrRel);
    $stmt->execute();
    $stmt->close();
    header('Location: settings.php?msg=pm_added#payment-methods');
    exit;
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($pmAction, ['toggle', 'delete'], true)) {
  $pmId = (int)($_POST['pm_id'] ?? 0);
  $row = $pmId > 0 ? pm_get($conn, $pmId, false) : null;
  if ($row) {
    if ($pmAction === 'toggle') {
      $stmt = $conn->prepare("UPDATE admin_payment_methods SET is_active = 1 - is_active WHERE id = ?");
      $stmt->bind_param('i', $pmId);
      $stmt->execute();
      $stmt->close();
    } else {
      $stmt = $conn->prepare("DELETE FROM admin_payment_methods WHERE id = ?");
      $stmt->bind_param('i', $pmId);
      $stmt->execute();
      $stmt->close();
      $qr = (string)($row['qr_image'] ?? '');
      if ($qr !== '' && str_starts_with($qr, 'uploads/payment_qr/')) {
        @unlink(__DIR__ . '/' . $qr);
      }
    }
  }
  header('Location: settings.php?msg=' . ($pmAction === 'toggle' ? 'pm_toggled' : 'pm_deleted') . '#payment-methods');
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pmAction === '') {
  $site_name = trim($_POST['site_name'] ?? 'FleetGo');
  $theme = trim($_POST['theme'] ?? 'dark');

  if ($site_name === '') $site_name = 'FleetGo';
  if (!in_array($theme, ['dark','light'], true)) $theme = 'dark';

  set_setting($conn, 'site_name', $site_name);

  setcookie('fleetgo_theme', $theme, [
    'expires' => time() + 60*60*24*365,
    'path' => '/',
    'secure' => false,
    'httponly' => false,
    'samesite' => 'Lax',
  ]);

  $flash = 'Settings saved.';
}

$siteName = get_setting($conn, 'site_name', 'FleetGo');
$paymentMethods = pm_list($conn);
$pmProviders = pm_providers();
$theme = $_COOKIE['fleetgo_theme'] ?? 'dark';
if (!in_array($theme, ['dark','light'], true)) $theme = 'dark';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Settings • <?= h($siteName) ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
  <style>
    :root{
      --bg:#0b0d10;--card:#101419;--text:#f2f6fa;--muted:#9aa6b3;
      --brand:#5dd0ff;--brand2:#7cffc7;--radius:18px;--shadow:0 10px 28px rgba(0,0,0,.45);
    }
    :root[data-theme="light"]{
      --bg:#f6f8fb;--card:#ffffff;--text:#0b1620;--muted:#4e6373;
      --shadow:0 10px 28px rgba(15, 23, 42, .12);
    }
    *{box-sizing:border-box}
    body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,system-ui,sans-serif;}
    .wrap{max-width:1100px;margin:0 auto;padding:28px;}
    h1{margin:0 0 16px;font-weight:900;letter-spacing:.2px;background:linear-gradient(90deg,var(--brand),var(--brand2));-webkit-background-clip:text;-webkit-text-fill-color:transparent;}
    .sub{color:var(--muted);margin:0 0 22px;}

    .grid{display:grid;grid-template-columns:1.2fr .8fr;gap:18px;}
    @media (max-width: 920px){.grid{grid-template-columns:1fr;}}

    .card{background:var(--card);border:1px solid rgba(255,255,255,.08);border-radius:var(--radius);box-shadow:var(--shadow);padding:18px;}
    :root[data-theme="light"] .card{border-color:rgba(15, 23, 42, .10);}

    .card h2{margin:0 0 10px;font-size:1rem;font-weight:900;}
    .row{display:grid;gap:10px;margin-top:12px;}

    label{font-size:.85rem;color:var(--muted);font-weight:700;}
    .input, .select{width:100%;padding:12px 14px;border-radius:14px;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.04);color:var(--text);outline:none;}
    :root[data-theme="light"] .input, :root[data-theme="light"] .select{border-color:rgba(15,23,42,.15);background:rgba(15,23,42,.03);}

    .btnrow{display:flex;gap:10px;justify-content:flex-end;margin-top:14px;flex-wrap:wrap;}
    .btn{cursor:pointer;border:0;border-radius:14px;padding:12px 16px;font-weight:900;transition:.2s;}
    .btn-primary{background:linear-gradient(90deg,var(--brand),var(--brand2));color:#04121b;box-shadow:0 6px 18px rgba(93,208,255,.25);}
    .btn-primary:hover{transform:translateY(-1px);box-shadow:0 10px 26px rgba(124,255,199,.25);}
    .btn-dark{background:rgba(255,255,255,.08);color:var(--text);border:1px solid rgba(255,255,255,.14);}
    :root[data-theme="light"] .btn-dark{background:rgba(15,23,42,.06);border-color:rgba(15,23,42,.12);}

    .flash{margin:0 0 14px;padding:10px 12px;border-radius:14px;background:rgba(124,255,199,.10);border:1px solid rgba(124,255,199,.25);color:var(--text);font-weight:800;}

    .preview{display:grid;gap:10px;}
    .pill{display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border-radius:999px;background:rgba(93,208,255,.10);border:1px solid rgba(93,208,255,.25);font-weight:900;color:var(--text);}

    .mini{color:var(--muted);font-size:.85rem;line-height:1.5;}

    .flash-error{background:rgba(239,68,68,.10);border-color:rgba(239,68,68,.30);}
    .pm-card{margin-top:18px;scroll-margin-top:90px;}
    .pm-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;}
    .pm-head p{margin:4px 0 0;}
    .pm-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:14px;}
    .pm-form .full{grid-column:1 / -1;}
    @media (max-width: 720px){.pm-form{grid-template-columns:1fr;}}
    .pm-form .select optgroup{background:#101419;color:var(--text);}
    .pm-form .select option{background:#101419;color:var(--text);}
    .pm-file{display:flex;align-items:center;gap:14px;padding:12px 14px;border-radius:14px;border:1px dashed rgba(255,255,255,.18);background:rgba(255,255,255,.03);cursor:pointer;}
    .pm-file input{display:none;}
    .pm-file img{width:64px;height:64px;object-fit:contain;border-radius:10px;background:#fff;display:none;}
    .pm-file .pm-file-text{display:grid;gap:2px;font-size:.85rem;}
    .pm-file .pm-file-text strong{color:var(--text);}
    .pm-file .pm-file-text span{color:var(--muted);}

    .pm-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px;margin-top:16px;}
    .pm-item{display:flex;gap:12px;padding:14px;border-radius:14px;border:1px solid rgba(255,255,255,.10);background:rgba(255,255,255,.03);}
    .pm-item.is-off{opacity:.55;}
    .pm-qr{width:72px;height:72px;flex:0 0 auto;border-radius:10px;background:#fff;object-fit:contain;}
    .pm-qr--none{display:grid;place-items:center;background:rgba(255,255,255,.06);color:var(--muted);font-size:.7rem;font-weight:800;text-align:center;}
    .pm-info{min-width:0;flex:1;display:grid;gap:3px;}
    .pm-name{font-weight:900;font-size:.95rem;}
    .pm-type{font-size:.7rem;font-weight:900;text-transform:uppercase;letter-spacing:.4px;color:var(--brand);}
    .pm-acct{font-family:ui-monospace,Consolas,monospace;font-size:.9rem;word-break:break-all;}
    .pm-acct-name{color:var(--muted);font-size:.82rem;}
    .pm-actions{display:flex;gap:8px;margin-top:8px;}
    .pm-actions form{margin:0;}
    .btn-sm{padding:7px 12px;border-radius:10px;font-size:.78rem;}
    .btn-danger{background:rgba(239,68,68,.12);color:#fca5a5;border:1px solid rgba(239,68,68,.3);}
    .pm-empty{margin-top:16px;padding:20px;border-radius:14px;border:1px dashed rgba(255,255,255,.14);color:var(--muted);text-align:center;font-size:.9rem;}
  </style>
  <script>
    (function(){
      const m = document.cookie.match(/(?:^|; )fleetgo_theme=([^;]+)/);
      const theme = m ? decodeURIComponent(m[1]) : 'dark';
      if (theme === 'light') document.documentElement.setAttribute('data-theme','light');
      else document.documentElement.removeAttribute('data-theme');
    })();
  </script>
</head>
<body>
<?php if(file_exists(__DIR__.'/includes/navbar.php')) include __DIR__.'/includes/navbar.php'; ?>

<div class="wrap">
  <h1>Settings</h1>
  <p class="sub">Manage basic system preferences. Theme changes apply across the system.</p>

  <?php if($flash): ?>
    <div class="flash"><?= h($flash) ?></div>
  <?php endif; ?>
  <?php if($flashError): ?>
    <div class="flash flash-error"><?= h($flashError) ?></div>
  <?php endif; ?>

  <form method="post" class="grid">
    <div class="card">
      <h2>System</h2>
      <div class="row">
        <div>
          <label for="site_name">Site Name</label>
          <input class="input" id="site_name" name="site_name" value="<?= h($siteName) ?>" />
        </div>
        <div>
          <label for="theme">Theme</label>
          <select class="select" id="theme" name="theme">
            <option value="dark" <?= $theme==='dark'?'selected':'' ?>>Dark</option>
            <option value="light" <?= $theme==='light'?'selected':'' ?>>Light</option>
          </select>
        </div>
      </div>

      <div class="btnrow">
        <a class="btn btn-dark" href="dashboard.php">Back</a>
        <button class="btn btn-primary" type="submit">Save Settings</button>
      </div>
    </div>

    <div class="card">
      <h2>Preview</h2>
      <div class="preview">
        <div class="pill">Current Theme: <?= h(strtoupper($theme)) ?></div>
        <div class="mini">
          - Theme is stored in a cookie (`fleetgo_theme`).
          - Pages using the shared navbars will automatically apply the chosen theme.
        </div>
      </div>
    </div>
  </form>

  <div class="card pm-card" id="payment-methods">
    <div class="pm-head">
      <div>
        <h2>Payment Methods</h2>
        <p class="mini">Customers see these when paying their downpayment. Add the account number and upload the QR code from your bank or e-wallet app.</p>
      </div>
    </div>

    <form method="post" enctype="multipart/form-data" class="pm-form">
      <input type="hidden" name="pm_action" value="add">
      <div>
        <label for="provider_code">Bank / E-wallet</label>
        <select class="select" id="provider_code" name="provider_code" required>
          <option value="">Select bank or e-wallet</option>
          <optgroup label="E-wallets">
            <?php foreach ($pmProviders['ewallet'] as $code => $label): ?>
              <option value="<?= h($code) ?>" <?= (($_POST['provider_code'] ?? '') === $code && $flashError) ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </optgroup>
          <optgroup label="Banks">
            <?php foreach ($pmProviders['bank'] as $code => $label): ?>
              <option value="<?= h($code) ?>" <?= (($_POST['provider_code'] ?? '') === $code && $flashError) ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </optgroup>
        </select>
      </div>
      <div>
        <label for="account_number">Account Number</label>
        <input class="input" id="account_number" name="account_number" maxlength="60" required placeholder="e.g. 0917 123 4567 or 0012-3456-7890" value="<?= $flashError ? h($_POST['account_number'] ?? '') : '' ?>">
      </div>
      <div>
        <label for="account_name">Account Name</label>
        <input class="input" id="account_name" name="account_name" maxlength="120" required placeholder="e.g. FleetGo Rentals" value="<?= $flashError ? h($_POST['account_name'] ?? '') : '' ?>">
      </div>
      <div>
        <label for="qr_image">QR Code</label>
        <label class="pm-file" for="qr_image">
          <input type="file" id="qr_image" name="qr_image" accept="image/*">
          <img id="qrPreview" alt="QR preview">
          <span class="pm-file-text">
            <strong id="qrFileName">Import QR code image</strong>
            <span>JPG, PNG, WEBP, or GIF • Max 5MB</span>
          </span>
        </label>
      </div>
      <div class="btnrow full" style="margin-top:0">
        <button class="btn btn-primary" type="submit">Add Payment Method</button>
      </div>
    </form>

    <?php if ($paymentMethods): ?>
      <div class="pm-list">
        <?php foreach ($paymentMethods as $pm): ?>
          <div class="pm-item <?= $pm['is_active'] ? '' : 'is-off' ?>">
            <?php if (!empty($pm['qr_image'])): ?>
              <a href="<?= h($pm['qr_image']) ?>" target="_blank" rel="noopener"><img class="pm-qr" src="<?= h($pm['qr_image']) ?>" alt="<?= h($pm['provider_name']) ?> QR"></a>
            <?php else: ?>
              <div class="pm-qr pm-qr--none">No QR</div>
            <?php endif; ?>
            <div class="pm-info">
              <span class="pm-type"><?= $pm['provider_type'] === 'bank' ? 'Bank' : 'E-wallet' ?><?= $pm['is_active'] ? '' : ' · Hidden' ?></span>
              <span class="pm-name"><?= h($pm['provider_name']) ?></span>
              <span class="pm-acct"><?= h($pm['account_number']) ?></span>
              <?php if (!empty($pm['account_name'])): ?>
                <span class="pm-acct-name"><?= h($pm['account_name']) ?></span>
              <?php endif; ?>
              <div class="pm-actions">
                <form method="post">
                  <input type="hidden" name="pm_action" value="toggle">
                  <input type="hidden" name="pm_id" value="<?= (int)$pm['id'] ?>">
                  <button class="btn btn-dark btn-sm" type="submit"><?= $pm['is_active'] ? 'Hide' : 'Show' ?></button>
                </form>
                <form method="post" onsubmit="return confirm('Remove this payment method?');">
                  <input type="hidden" name="pm_action" value="delete">
                  <input type="hidden" name="pm_id" value="<?= (int)$pm['id'] ?>">
                  <button class="btn btn-danger btn-sm" type="submit">Remove</button>
                </form>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="pm-empty">No payment methods yet. Customers can't submit a downpayment until you add at least one.</div>
    <?php endif; ?>
  </div>
</div>
<script>
(function(){
  const input = document.getElementById('qr_image');
  const img = document.getElementById('qrPreview');
  const name = document.getElementById('qrFileName');
  if (!input) return;
  input.addEventListener('change', function(){
    const file = this.files && this.files[0];
    if (!file) { img.style.display = 'none'; name.textContent = 'Import QR code image'; return; }
    name.textContent = file.name;
    const reader = new FileReader();
    reader.onload = e => { img.src = e.target.result; img.style.display = 'block'; };
    reader.readAsDataURL(file);
  });
})();
</script>
</body>
</html>
<?php $conn->close(); ?>
