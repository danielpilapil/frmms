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

function h($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
</div>
</body>
</html>
<?php $conn->close(); ?>
