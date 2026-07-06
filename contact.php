<?php
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
session_name('fleetgo_session_user');
session_start();

$sent = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $sent = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Contact — FleetGo</title>
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
  --success:#10b981;--warning:#f59e0b;
}
*{box-sizing:border-box}
a{text-decoration:none;color:inherit}

.wrap{max-width:1100px;margin:0 auto;padding:0 18px}
.hero{
  padding:70px 0 30px;
  background:linear-gradient(135deg,rgba(11,13,16,.96),rgba(16,20,25,.92));
  border-bottom:1px solid rgba(255,255,255,.06);
  position:relative;overflow:hidden;
}
.hero::before{
  content:"";position:absolute;inset:0;
  background:url('assets/herobanner1.jpg') center/cover;
  opacity:.16;z-index:-1;
}
.h-title{
  font-size:clamp(2rem,4.5vw,3rem);
  font-weight:900;letter-spacing:-.5px;margin:0;
  background:var(--gradient);
  -webkit-background-clip:text;-webkit-text-fill-color:transparent;
}
.h-sub{margin-top:12px;color:var(--muted);font-weight:650;max-width:720px;line-height:1.7}

.grid{display:grid;grid-template-columns: .9fr 1.1fr;gap:18px;padding:24px 0 0}
.card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);padding:22px}
.card h3{margin:0 0 12px;font-size:1.05rem;font-weight:900}
.card p{margin:0;color:var(--muted);font-weight:620;line-height:1.75}

.info-list{display:flex;flex-direction:column;gap:10px;margin-top:14px}
.info-item{display:flex;gap:12px;align-items:flex-start;padding:12px;border-radius:16px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08)}
.ico{width:38px;height:38px;border-radius:14px;background:linear-gradient(135deg,rgba(93,208,255,.18),rgba(124,255,199,.14));border:1px solid rgba(93,208,255,.22);display:grid;place-items:center;color:var(--brand2);flex-shrink:0}
.ico svg{width:18px;height:18px}
.item-title{font-weight:900;margin:0;color:var(--text)}
.item-sub{margin-top:2px;color:var(--muted);font-weight:650}

.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.field{display:flex;flex-direction:column;gap:8px}
.label{font-weight:850;color:var(--muted);font-size:.9rem}
.input, .textarea{
  width:100%;
  background:rgba(255,255,255,.03);
  border:1px solid rgba(255,255,255,.10);
  border-radius:14px;
  padding:12px 12px;
  color:var(--text);
  outline:none;
  transition:border-color .15s ease, box-shadow .15s ease;
}
.textarea{min-height:120px;resize:vertical}
.input:focus, .textarea:focus{border-color:rgba(93,208,255,.45);box-shadow:0 0 0 4px rgba(93,208,255,.10)}

.actions{display:flex;gap:12px;flex-wrap:wrap;align-items:center;justify-content:space-between;margin-top:14px}
.btn{
  display:inline-flex;align-items:center;justify-content:center;gap:10px;
  padding:12px 14px;border-radius:14px;
  border:1px solid rgba(255,255,255,.12);
  background:rgba(255,255,255,.04);
  font-weight:900;color:var(--text);
  cursor:pointer;
  transition:transform .2s ease,background .2s ease,border-color .2s ease;
}
.btn:hover{transform:translateY(-1px);background:rgba(255,255,255,.06);border-color:rgba(255,255,255,.18)}
.btn.primary{background:var(--gradient);border-color:transparent;color:#041b22}
.help{color:var(--muted2);font-weight:700;font-size:.9rem;line-height:1.6}

.alert{
  margin-top:14px;
  border-radius:16px;
  padding:12px 14px;
  border:1px solid rgba(16,185,129,.25);
  background:rgba(16,185,129,.10);
  color:#a7f3d0;
  font-weight:800;
}

.footer{padding:34px 0;margin-top:30px;border-top:1px solid rgba(255,255,255,.08);color:var(--muted)}

@media (max-width: 980px){
  .grid{grid-template-columns:1fr}
}
@media (max-width: 560px){
  .form-grid{grid-template-columns:1fr}
  .btn{width:100%}
}
</style>

<section class="hero">
  <div class="wrap">
    <h1 class="h-title">Contact</h1>
    <p class="h-sub">Questions about a booking, payments, or your trip? Send us a message and we’ll get back to you.</p>

    <div class="grid">
      <div class="card">
        <h3>Contact Information</h3>
        <p>Use any of the options below. For faster support, include your rental dates and vehicle name.</p>

        <div class="info-list">
          <div class="info-item">
            <div class="ico">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.79 19.79 0 0 1 2.08 4.18 2 2 0 0 1 4.06 2h3a2 2 0 0 1 2 1.72c.12.81.3 1.6.54 2.36a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.72-1.06a2 2 0 0 1 2.11-.45c.76.24 1.55.42 2.36.54A2 2 0 0 1 22 16.92z"/></svg>
            </div>
            <div>
              <p class="item-title">Phone</p>
              <div class="item-sub">+63 900 000 0000</div>
            </div>
          </div>

          <div class="info-item">
            <div class="ico">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16v16H4z"/><path d="M22 6l-10 7L2 6"/></svg>
            </div>
            <div>
              <p class="item-title">Email</p>
              <div class="item-sub">support@fleetgo.local</div>
            </div>
          </div>

          <div class="info-item">
            <div class="ico">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
            </div>
            <div>
              <p class="item-title">Office</p>
              <div class="item-sub">FleetGo Rentals — Your City, PH</div>
            </div>
          </div>
        </div>

        <div class="footer" style="margin-top:18px;padding-top:18px">
          <div class="help">Available hours: Mon–Sat, 9:00 AM – 6:00 PM</div>
        </div>
      </div>

      <div class="card">
        <h3>Send a Message</h3>
        <p>This form is currently UI-only (it does not send emails yet). If you want, I can wire it to email or store messages in a table—only if you approve.</p>

        <?php if ($sent): ?>
          <div class="alert">Message submitted (demo). Thank you — we’ll respond soon.</div>
        <?php endif; ?>

        <form method="post" style="margin-top:14px">
          <div class="form-grid">
            <div class="field">
              <div class="label">Full name</div>
              <input class="input" name="name" autocomplete="name" required>
            </div>
            <div class="field">
              <div class="label">Email</div>
              <input class="input" type="email" name="email" autocomplete="email" required>
            </div>
          </div>

          <div class="field" style="margin-top:12px">
            <div class="label">Subject</div>
            <input class="input" name="subject" required>
          </div>

          <div class="field" style="margin-top:12px">
            <div class="label">Message</div>
            <textarea class="textarea" name="message" required></textarea>
          </div>

          <div class="actions">
            <button class="btn primary" type="submit">Send Message</button>
            <div class="help">Tip: Include your rental ID / dates if the issue is booking-related.</div>
          </div>
        </form>
      </div>
    </div>

    <div class="footer">
      <div class="wrap">© <?php echo date('Y'); ?> FleetGo</div>
    </div>
  </div>
</section>

</body>
</html>
