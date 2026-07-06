<?php
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
session_name('fleetgo_session_user');
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>About Us — FleetGo</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
  <link href="assets/css/fleetgo-shared.css" rel="stylesheet">
</head>
<body style="font-family:Inter,system-ui,sans-serif;background:#0b0d10;color:#f2f6fa;">

<?php include __DIR__.'/includes/user_navbar.php'; ?>

<style>
:root{
  --bg:#0b0d10;--card:#101419;--card2:#0f1318;--border:rgba(255,255,255,0.08);
  --text:#f2f6fa;--muted:#9ca3af;--muted2:#6b7280;
  --brand:#5dd0ff;--brand2:#7cffc7;
  --radius:18px;
  --shadow:0 10px 30px rgba(0,0,0,.28);
  --gradient:linear-gradient(135deg,var(--brand),var(--brand2));
}
*{box-sizing:border-box}
a{text-decoration:none;color:inherit}

.wrap{max-width:1100px;margin:0 auto;padding:0 18px}
.hero{
  padding:70px 0 36px;
  background:linear-gradient(135deg,rgba(11,13,16,.96),rgba(16,20,25,.92));
  border-bottom:1px solid rgba(255,255,255,.06);
  position:relative;overflow:hidden;
}
.hero::before{
  content:"";position:absolute;inset:0;
  background:url('assets/herobanner2.jpg') center/cover;
  opacity:.18;z-index:-1;
}
.h-title{
  font-size:clamp(2rem,4.5vw,3rem);
  font-weight:900;letter-spacing:-.5px;margin:0;
  background:var(--gradient);
  -webkit-background-clip:text;-webkit-text-fill-color:transparent;
}
.h-sub{margin-top:12px;color:var(--muted);font-weight:650;max-width:680px;line-height:1.7}

.grid{
  display:grid;grid-template-columns:1.2fr .8fr;gap:18px;
  padding:26px 0 0;
}
.card{
  background:var(--card);
  border:1px solid var(--border);
  border-radius:var(--radius);
  box-shadow:var(--shadow);
  padding:22px;
}
.card h3{margin:0 0 10px;font-size:1.05rem;font-weight:900}
.card p{margin:0;color:var(--muted);font-weight:620;line-height:1.75}

.kpis{display:grid;grid-template-columns:repeat(2,1fr);gap:12px}
.kpi{background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:16px;padding:14px}
.kpi .v{font-size:1.35rem;font-weight:900;color:var(--text)}
.kpi .k{margin-top:6px;color:var(--muted2);font-weight:750;font-size:.85rem;text-transform:uppercase;letter-spacing:.5px}

.values{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-top:12px}
.value{
  background:var(--card2);
  border:1px solid var(--border);
  border-radius:16px;
  padding:16px;
  transition:transform .2s ease,border-color .2s ease,background .2s ease;
}
.value:hover{transform:translateY(-2px);border-color:rgba(255,255,255,.14);background:rgba(255,255,255,.04)}
.value .t{font-weight:900;margin:0 0 6px}
.value .d{margin:0;color:var(--muted);font-weight:620;line-height:1.7}

.cta{
  margin-top:18px;
  display:flex;gap:12px;flex-wrap:wrap;align-items:center;justify-content:space-between;
  background:linear-gradient(135deg, rgba(93,208,255,.10), rgba(124,255,199,.07));
  border:1px solid rgba(93,208,255,.22);
  border-radius:18px;
  padding:16px;
}
.cta .txt{color:var(--muted);font-weight:700}
.btn{
  display:inline-flex;align-items:center;justify-content:center;gap:10px;
  padding:12px 14px;border-radius:14px;
  border:1px solid rgba(255,255,255,.12);
  background:rgba(255,255,255,.04);
  font-weight:900;color:var(--text);
  transition:transform .2s ease,background .2s ease,border-color .2s ease;
}
.btn:hover{transform:translateY(-1px);background:rgba(255,255,255,.06);border-color:rgba(255,255,255,.18)}
.btn.primary{background:var(--gradient);border-color:transparent;color:#041b22}

.section{padding:26px 0}
.section h2{margin:0 0 10px;font-size:1.3rem;font-weight:950;letter-spacing:-.2px}
.section .lead{margin:0;color:var(--muted);font-weight:650;max-width:820px;line-height:1.8}

.footer{
  padding:34px 0;margin-top:30px;
  border-top:1px solid rgba(255,255,255,.08);
  color:var(--muted);
}

@media (max-width: 980px){
  .grid{grid-template-columns:1fr}
  .values{grid-template-columns:1fr}
  .kpis{grid-template-columns:repeat(2,1fr)}
}
@media (max-width: 520px){
  .kpis{grid-template-columns:1fr}
  .cta{justify-content:flex-start}
  .btn{width:100%}
}
</style>

<section class="hero">
  <div class="wrap">
    <h1 class="h-title">About FleetGo</h1>
    <p class="h-sub">FleetGo is built to make vehicle rentals simple, transparent, and reliable — from browsing and booking to tracking your trip and managing your rental history.</p>

    <div class="grid">
      <div class="card">
        <h3>Our Mission</h3>
        <p>To help people move with confidence by providing a booking experience that feels modern, clear, and easy — backed by practical fleet management and real-world rental policies.</p>

        <div class="section" style="padding-bottom:0">
          <h2>What we focus on</h2>
          <p class="lead">A clean booking flow, clear status tracking, and an interface that feels like a real platform — not a cluttered spreadsheet.</p>
        </div>

        <div class="values">
          <div class="value">
            <p class="t">Transparency</p>
            <p class="d">Clear pricing and visible rental status so you always know what to expect.</p>
          </div>
          <div class="value">
            <p class="t">Safety</p>
            <p class="d">Policies and checks designed to protect both renters and the fleet.</p>
          </div>
          <div class="value">
            <p class="t">Convenience</p>
            <p class="d">Fast browsing, easy booking management, and responsive design for mobile users.</p>
          </div>
        </div>

        <div class="cta">
          <div class="txt">Ready to book your next trip?</div>
          <div style="display:flex;gap:10px;flex-wrap:wrap">
            <a class="btn primary" href="vehiclepage.php">Browse Vehicles</a>
            <a class="btn" href="myrentals.php">My Rentals</a>
          </div>
        </div>
      </div>

      <div class="card">
        <h3>Quick Facts</h3>
        <div class="kpis">
          <div class="kpi">
            <div class="v">Card UI</div>
            <div class="k">Booking-style layout</div>
          </div>
          <div class="kpi">
            <div class="v">Status Flow</div>
            <div class="k">Pending → Reserved → Ongoing → Completed</div>
          </div>
          <div class="kpi">
            <div class="v">Mobile</div>
            <div class="k">Responsive dashboard</div>
          </div>
          <div class="kpi">
            <div class="v">Secure</div>
            <div class="k">Session-based access</div>
          </div>
        </div>

        <div class="section">
          <h2>Contact</h2>
          <p class="lead">For support and inquiries, you can reach us through the Contact section on the dashboard.</p>
          <div style="margin-top:14px">
            <a class="btn" href="userpage.php#contact">Go to Contact</a>
          </div>
        </div>
      </div>
    </div>

    <div class="footer">
      <div class="wrap">© <?php echo date('Y'); ?> FleetGo</div>
    </div>
  </div>
</section>

</body>
</html>
