<?php
if (session_status() === PHP_SESSION_NONE) {
  if (isset($_COOKIE['fleetgo_session_admin'])) session_name('fleetgo_session_admin');
  elseif (isset($_COOKIE['fleetgo_session_staff'])) session_name('fleetgo_session_staff');
  elseif (isset($_COOKIE['fleetgo_session_user'])) session_name('fleetgo_session_user');
  else session_name('fleetgo_session_guest');
  session_start();
}
require_once __DIR__ . '/db.php';

$userID = (int)($_SESSION['user_id'] ?? 0);
$userName = $_SESSION['user_name'] ?? 'Guest';

$profilePhoto = '';
$verificationStatus = '';
try {
  if ($userID > 0) {
    $stmt = $conn->prepare("SELECT COALESCE(profile_photo, photo, '') AS p, COALESCE(verification_status,'') AS v FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $userID);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $profilePhoto = trim((string)($row['p'] ?? ''));
    $verificationStatus = trim((string)($row['v'] ?? ''));
  }
} catch (Throwable $e) {
  $profilePhoto = '';
  $verificationStatus = '';
}

$initials = 'U';
try {
  $parts = preg_split('/\s+/', trim((string)$userName));
  $a = strtoupper(substr($parts[0] ?? 'U', 0, 1));
  $b = strtoupper(substr($parts[1] ?? '', 0, 1));
  $initials = $a . ($b !== '' ? $b : '');
} catch (Throwable $e) {
  $initials = 'U';
}

/* Count unread notifications */
$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM notifications WHERE user_id=? AND is_read=0");
$stmt->bind_param("i", $userID);
$stmt->execute();
$countRes = $stmt->get_result();
$unreadCount = $countRes ? ($countRes->fetch_assoc()['c'] ?? 0) : 0;
$stmt->close();
?>
<style>
:root{
  --fg-nav-bg: rgba(16,20,25,.92);
  --fg-nav-border: rgba(255,255,255,.08);
  --fg-nav-text: rgba(242,246,250,.92);
  --fg-nav-muted: rgba(156,163,175,.95);
  --fg-nav-card: rgba(16,20,25,.98);
  --fg-nav-shadow: 0 10px 30px rgba(0,0,0,.28);
  --fg-brand: #5dd0ff;
  --fg-brand2: #7cffc7;
  --fg-radius: 14px;
}

.fg-nav{
  position:sticky;top:0;z-index:9999;
  background:var(--fg-nav-bg);
  border-bottom:1px solid var(--fg-nav-border);
  backdrop-filter:blur(12px);
}
.fg-nav__inner{
  max-width:1280px;
  margin:0 auto;
  padding:12px 18px;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:16px;
}
.fg-brand{
  display:flex;align-items:center;gap:10px;
  text-decoration:none;
  color:var(--fg-nav-text);
  font-weight:900;
  letter-spacing:-.2px;
}
.fg-brand__mark{
  width:38px;height:38px;border-radius:12px;
  background:linear-gradient(135deg,var(--fg-brand),var(--fg-brand2));
  display:grid;place-items:center;
  color:#041b22;
  font-weight:900;
  font-size:.9rem;
}
.fg-nav__links{
  display:flex;align-items:center;gap:18px;
}
.fg-nav__link{
  color:var(--fg-nav-muted);
  text-decoration:none;
  font-weight:800;
  font-size:.95rem;
  padding:8px 10px;
  border-radius:10px;
  transition:background .15s ease,color .15s ease;
  position:relative;
}
.fg-nav__link:hover{background:rgba(255,255,255,.06);color:var(--fg-nav-text)}
.fg-nav__link.is-active{color:var(--fg-nav-text);}
.fg-nav__link.is-active::after{
  content:"";
  position:absolute;left:10px;right:10px;bottom:2px;height:2px;
  background:linear-gradient(90deg,var(--fg-brand),var(--fg-brand2));
  border-radius:999px;
}

.fg-nav__right{display:flex;align-items:center;gap:10px;}
.fg-iconbtn{
  border:1px solid rgba(255,255,255,.10);
  background:rgba(255,255,255,.03);
  color:var(--fg-nav-text);
  border-radius:12px;
  width:40px;height:40px;
  display:inline-flex;align-items:center;justify-content:center;
  cursor:pointer;
  transition:background .15s ease,border-color .15s ease,transform .15s ease;
  text-decoration:none;
  position:relative;
}
.fg-iconbtn:hover{background:rgba(255,255,255,.06);border-color:rgba(255,255,255,.16);transform:translateY(-1px)}
.fg-badge{
  position:absolute;top:-6px;right:-6px;
  min-width:18px;height:18px;padding:0 5px;
  border-radius:999px;
  background:#ef4444;color:#fff;
  display:inline-flex;align-items:center;justify-content:center;
  font-weight:900;font-size:11px;
  border:2px solid rgba(16,20,25,.92);
}

.fg-profile{
  position:relative;
}
.fg-profile__btn{
  display:flex;align-items:center;gap:10px;
  border:1px solid rgba(255,255,255,.10);
  background:rgba(255,255,255,.03);
  color:var(--fg-nav-text);
  border-radius:999px;
  padding:6px 10px 6px 6px;
  cursor:pointer;
  transition:background .15s ease,border-color .15s ease,transform .15s ease;
}
.fg-profile__btn:hover{background:rgba(255,255,255,.06);border-color:rgba(255,255,255,.16);transform:translateY(-1px)}
.fg-avatar{
  width:34px;height:34px;border-radius:999px;
  background:rgba(93,208,255,.15);
  border:1px solid rgba(93,208,255,.25);
  display:grid;place-items:center;
  color:var(--fg-brand2);
  font-weight:900;
  overflow:hidden;
  flex-shrink:0;
}
.fg-avatar img{width:100%;height:100%;object-fit:cover;display:block}
.fg-profile__name{font-weight:900;font-size:.92rem;max-width:140px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.fg-profile__chev{width:16px;height:16px;opacity:.85}

.fg-dropdown{
  position:absolute;right:0;top:calc(100% + 10px);
  width:260px;
  background:var(--fg-nav-card);
  border:1px solid rgba(255,255,255,.10);
  border-radius:16px;
  box-shadow:var(--fg-nav-shadow);
  display:none;
  overflow:hidden;
}
.fg-dropdown.is-open{display:block}
.fg-dropdown__head{padding:14px 14px 10px;border-bottom:1px solid rgba(255,255,255,.08)}
.fg-dropdown__title{font-weight:900;color:var(--fg-nav-text);font-size:.95rem}
.fg-dropdown__sub{margin-top:4px;color:var(--fg-nav-muted);font-weight:800;font-size:.8rem;text-transform:capitalize}
.fg-dropdown__list{padding:8px}
.fg-ddlink{
  display:flex;align-items:center;gap:10px;
  padding:10px 10px;
  border-radius:12px;
  text-decoration:none;
  color:var(--fg-nav-text);
  font-weight:850;
  font-size:.92rem;
  transition:background .15s ease;
}
.fg-ddlink:hover{background:rgba(255,255,255,.06)}
.fg-ddlink--danger{color:#fecaca}
.fg-ddicon{width:18px;height:18px;opacity:.9}

.fg-burger{
  display:none;
  border:1px solid rgba(255,255,255,.10);
  background:rgba(255,255,255,.03);
  color:var(--fg-nav-text);
  border-radius:12px;
  width:40px;height:40px;
  align-items:center;justify-content:center;
  cursor:pointer;
}

@media (max-width: 920px){
  .fg-nav__links{display:none;}
  .fg-burger{display:inline-flex;}
  .fg-nav__inner{gap:10px;}
  .fg-profile__name{display:none;}
}

.fg-mobile{
  display:none;
  border-top:1px solid rgba(255,255,255,.08);
  padding:10px 18px 14px;
}
.fg-mobile.is-open{display:block;}
.fg-mobile__links{
  display:grid;
  grid-template-columns:1fr;
  gap:8px;
}
.fg-mobile__links a{
  border:1px solid rgba(255,255,255,.10);
  background:rgba(255,255,255,.03);
}
</style>

<header class="fg-nav">
  <div class="fg-nav__inner">
    <a href="userpage.php" class="fg-brand" aria-label="FleetGo Home">
      <div class="fg-brand__mark">FG</div>
      <div>FleetGo</div>
    </a>

    <nav class="fg-nav__links" aria-label="Main navigation">
      <a class="fg-nav__link" href="userpage.php">Home</a>
      <a class="fg-nav__link" href="vehiclepage.php">Browse Cars</a>
      <a class="fg-nav__link" href="myrentals.php">My Rentals</a>
      <a class="fg-nav__link" href="about.php">About</a>
      <a class="fg-nav__link" href="contact.php">Contact</a>
    </nav>

    <div class="fg-nav__right">
      <button class="fg-burger" type="button" id="fgNavToggle" aria-label="Menu" aria-expanded="false">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M4 6h16"/>
          <path d="M4 12h16"/>
          <path d="M4 18h16"/>
        </svg>
      </button>

      <a class="fg-iconbtn" href="notif.php" aria-label="Notifications">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/>
          <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
        </svg>
        <?php if ((int)$unreadCount > 0): ?>
          <span class="fg-badge"><?= (int)$unreadCount ?></span>
        <?php endif; ?>
      </a>

      <div class="fg-profile" id="fgProfile">
        <button class="fg-profile__btn" type="button" id="fgProfileBtn" aria-haspopup="menu" aria-expanded="false">
          <div class="fg-avatar">
            <?php if ($profilePhoto !== ''): ?>
              <img src="<?= htmlspecialchars($profilePhoto, ENT_QUOTES, 'UTF-8') ?>" alt="Profile">
            <?php else: ?>
              <?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?>
            <?php endif; ?>
          </div>
          <div class="fg-profile__name"><?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?></div>
          <svg class="fg-profile__chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M6 9l6 6 6-6"/>
          </svg>
        </button>

        <div class="fg-dropdown" id="fgProfileMenu" role="menu">
          <div class="fg-dropdown__head">
            <div class="fg-dropdown__title">Account</div>
            <div class="fg-dropdown__sub"><?= htmlspecialchars($verificationStatus !== '' ? $verificationStatus : 'unverified', ENT_QUOTES, 'UTF-8') ?></div>
          </div>
          <div class="fg-dropdown__list">
            <a class="fg-ddlink" role="menuitem" href="userprofile.php">
              <svg class="fg-ddicon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
              My Profile
            </a>
            <a class="fg-ddlink" role="menuitem" href="userprofile.php">
              <svg class="fg-ddicon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 12l2 2 4-4"/><path d="M21 12c0 5-4 9-9 9s-9-4-9-9 4-9 9-9 9 4 9 9z"/></svg>
              Verification Status
            </a>
            <a class="fg-ddlink" role="menuitem" href="myrentals.php">
              <svg class="fg-ddicon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 6h13"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M3 6h.01"/><path d="M3 12h.01"/><path d="M3 18h.01"/></svg>
              Rental History
            </a>
            <a class="fg-ddlink fg-ddlink--danger" role="menuitem" href="logout.php">
              <svg class="fg-ddicon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/></svg>
              Logout
            </a>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="fg-mobile" id="fgMobileNav">
    <div class="fg-mobile__links">
      <a class="fg-nav__link" href="userpage.php">Home</a>
      <a class="fg-nav__link" href="vehiclepage.php">Browse Cars</a>
      <a class="fg-nav__link" href="myrentals.php">My Rentals</a>
      <a class="fg-nav__link" href="about.php">About</a>
      <a class="fg-nav__link" href="contact.php">Contact</a>
    </div>
  </div>
</header>

<script>
// Apply theme early
(function(){
  try{
    const m = document.cookie.match(/(?:^|; )fleetgo_theme=([^;]+)/);
    const theme = m ? decodeURIComponent(m[1]) : 'dark';
    if(theme === 'light') document.documentElement.setAttribute('data-theme','light');
    else document.documentElement.removeAttribute('data-theme');
  }catch(e){}
})();

/* Highlight active nav link */
const fgPath = window.location.pathname.split('/').pop();
document.querySelectorAll('.fg-nav__links a, .fg-mobile__links a').forEach(a => {
  const href = (a.getAttribute('href') || '').split('#')[0];
  if (href === fgPath) a.classList.add('is-active');
});

// Mobile toggle
const fgToggle = document.getElementById('fgNavToggle');
const fgMobile = document.getElementById('fgMobileNav');
if (fgToggle && fgMobile) {
  fgToggle.addEventListener('click', () => {
    const open = fgMobile.classList.toggle('is-open');
    fgToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
  });
}

// Profile dropdown
const fgProfileBtn = document.getElementById('fgProfileBtn');
const fgProfileMenu = document.getElementById('fgProfileMenu');
if (fgProfileBtn && fgProfileMenu) {
  fgProfileBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    const open = fgProfileMenu.classList.toggle('is-open');
    fgProfileBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
  });
  document.addEventListener('click', (e) => {
    if (!fgProfileMenu.contains(e.target) && !fgProfileBtn.contains(e.target)) {
      fgProfileMenu.classList.remove('is-open');
      fgProfileBtn.setAttribute('aria-expanded', 'false');
    }
  });
}
</script>
