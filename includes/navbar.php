<?php
// Enhanced FleetGo Navbar - Modern & Responsive
$__msgUnread = 0;
try {
  if (isset($conn) && $conn instanceof mysqli) {
    require_once __DIR__ . '/chat.php';
    $__msgUnread = chat_admin_unread_count($conn);
  }
} catch (Throwable $e) {
  $__msgUnread = 0;
}
?>
<nav class="navbar">
  <div class="navbar-container">
    <div class="navbar-brand">
      <a href="dashboard.php" class="brand-link">
        <div class="brand-text">
          <span class="brand-name">FleetGo</span>
          <span class="brand-tagline">Fleet Management System</span>
        </div>
      </a>
    </div>

    <div class="navbar-menu">
      <div class="nav-item">
        <a href="dashboard.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>">
          Dashboard
        </a>
      </div>

      <div class="nav-item">
        <a href="vehicles_all.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'vehicles_all.php' ? 'active' : ''; ?>">
          Vehicles
        </a>
      </div>

      <div class="nav-item">
        <a href="customers_all.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'customers_all.php' ? 'active' : ''; ?>">
          Customers
        </a>
      </div>

      <div class="nav-item">
        <a href="rentals_all.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'rentals_all.php' ? 'active' : ''; ?>">
          Rentals
        </a>
      </div>

      <div class="nav-item">
        <a href="maintenance_all.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'maintenance_all.php' ? 'active' : ''; ?>">
          Maintenance
        </a>
      </div>

      <div class="nav-item">
        <a href="reports.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'reports.php' ? 'active' : ''; ?>">
          Reports
        </a>
      </div>

      <div class="nav-sep" aria-hidden="true">|</div>

      <div class="nav-item">
        <a href="messages.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'messages.php' ? 'active' : ''; ?>">
          Messages
          <span class="msg-nav-badge" id="adminMessagesBadge"<?= $__msgUnread > 0 ? '' : ' hidden' ?>><?= $__msgUnread > 99 ? '99+' : (int)$__msgUnread ?></span>
        </a>
      </div>
    </div>

    <div class="navbar-actions">
      <div class="notification-wrapper">
        <a href="admin_notif.php" class="notification-bell" aria-label="Notifications" title="Notifications">
          <svg class="notif-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M12 3.5c-3.1 0-5.5 2.3-5.5 5.2v2.1c0 .9-.3 1.8-.9 2.5l-.7.8c-.7.8-.2 2.1.9 2.1h12.4c1.1 0 1.6-1.3.9-2.1l-.7-.8c-.6-.7-.9-1.6-.9-2.5V8.7c0-2.9-2.4-5.2-5.5-5.2Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
            <path d="M9.6 18.8a2.5 2.5 0 0 0 4.8 0" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
          </svg>
          <span class="notification-badge" id="adminNotificationBadge">0</span>
        </a>
      </div>

      <div class="user-menu">
        <button class="user-menu-trigger" onclick="toggleUserMenu()" type="button" aria-haspopup="true" aria-expanded="false">
          <?php echo htmlspecialchars($_SESSION['name'] ?? 'Admin'); ?>
        </button>
        
        <div class="user-dropdown" id="userDropdown">
          <div class="dropdown-header">
            <span class="dropdown-title">Account</span>
          </div>
          <div class="dropdown-items">
            <a href="settings.php" class="dropdown-item">Profile</a>
            <a href="settings.php" class="dropdown-item">Settings</a>
            <div class="dropdown-divider"></div>
            <a href="login.php?logout=1" class="dropdown-item logout-item">Logout</a>
          </div>
        </div>
      </div>
    </div>
  </div>
</nav>

<style>
/* Modern Navbar Styles */
.navbar {
  background: linear-gradient(135deg, #0b0d10 0%, #101419 100%);
  backdrop-filter: blur(20px);
  border-bottom: 1px solid rgba(93, 208, 255, 0.1);
  box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
  position: sticky;
  top: 0;
  z-index: 1000;
  font-family: Inter, system-ui, sans-serif;
}

.navbar-container {
  max-width: 1400px;
  margin: 0 auto;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 24px;
  height: 70px;
}

/* Brand Section */
.navbar-brand {
  display: flex;
  align-items: center;
}

.brand-link {
  display: flex;
  align-items: center;
  gap: 12px;
  text-decoration: none;
  color: var(--text, #f2f6fa);
  transition: all 0.3s ease;
}

.brand-link:hover {
  transform: scale(1.05);
}

.brand-text {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.brand-name {
  font-size: 1.4rem;
  font-weight: 800;
  color: var(--text, #f2f6fa);
  letter-spacing: 0.02em;
}

.brand-tagline {
  font-size: 0.7rem;
  color: var(--muted, #9aa6b3);
  font-weight: 500;
  opacity: 0.8;
}

/* Navigation Menu */
.navbar-menu {
  display: flex;
  align-items: center;
  gap: 8px;
}

.nav-item {
  position: relative;
}

.nav-sep {
  color: rgba(242, 246, 250, 0.35);
  font-weight: 700;
  padding: 0 4px;
  user-select: none;
}

.nav-link {
  display: inline-flex;
  align-items: center;
  padding: 12px 16px;
  border-radius: 12px;
  text-decoration: none;
  color: var(--text, #f2f6fa);
  font-weight: 600;
  font-size: 0.9rem;
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  position: relative;
  overflow: hidden;
  border: 1px solid transparent;
}

.nav-link::before {
  content: '';
  position: absolute;
  top: 0;
  left: -100%;
  width: 100%;
  height: 100%;
  background: linear-gradient(90deg, transparent, rgba(93, 208, 255, 0.1), transparent);
  transition: left 0.5s ease;
}

.nav-link:hover::before {
  left: 100%;
}

.nav-link:hover {
  background: rgba(93, 208, 255, 0.1);
  border-color: rgba(93, 208, 255, 0.3);
  transform: translateY(-2px);
  box-shadow: 0 8px 25px rgba(93, 208, 255, 0.2);
}

.nav-link.active {
  background: linear-gradient(135deg, rgba(93, 208, 255, 0.2), rgba(124, 255, 199, 0.2));
  border-color: rgba(93, 208, 255, 0.4);
  color: var(--brand-2, #7cffc7);
}

.msg-nav-badge {
  position: absolute;
  top: 2px;
  right: 2px;
  min-width: 18px;
  height: 18px;
  padding: 0 5px;
  border-radius: 999px;
  background: #ef4444;
  color: #fff;
  font-size: 0.65rem;
  font-weight: 800;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  line-height: 1;
  border: 2px solid #0b0d10;
  box-shadow: 0 0 8px rgba(239, 68, 68, 0.45);
  z-index: 2;
}
.msg-nav-badge[hidden] {
  display: none !important;
}

/* Right Side Actions */
.navbar-actions {
  display: flex;
  align-items: center;
  gap: 20px;
}

/* Notifications */
.notification-wrapper {
  position: relative;
}

.notification-bell {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 40px;
  height: 40px;
  padding: 0;
  border-radius: 12px;
  text-decoration: none;
  color: var(--text, #f2f6fa);
  transition: all 0.3s ease;
  position: relative;
  border: 1px solid transparent;
}

.notification-bell:hover {
  background: rgba(93, 208, 255, 0.1);
  border-color: rgba(93, 208, 255, 0.3);
  color: var(--brand, #5dd0ff);
  transform: translateY(-2px);
  box-shadow: 0 8px 25px rgba(93, 208, 255, 0.2);
}

.notif-icon {
  display: block;
  opacity: 0.9;
}

.notification-badge {
  position: absolute;
  top: -6px;
  right: -6px;
  background: linear-gradient(135deg, #ff6b6b, #ff8e8e);
  color: white;
  border-radius: 50%;
  width: 20px;
  height: 20px;
  font-size: 0.7rem;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  min-width: 20px;
  border: 2px solid rgba(255, 255, 255, 0.3);
  animation: pulse 2s infinite;
  box-shadow: 0 0 10px rgba(255, 107, 107, 0.4);
}

.notification-badge:empty {
  display: none;
}

/* User Menu */
.user-menu {
  position: relative;
}

.user-menu-trigger {
  display: inline-flex;
  align-items: center;
  padding: 10px 16px;
  border-radius: 12px;
  background: rgba(255, 255, 255, 0.05);
  border: 1px solid rgba(255, 255, 255, 0.1);
  color: var(--text, #f2f6fa);
  cursor: pointer;
  transition: all 0.3s ease;
  font-size: 0.9rem;
  font-weight: 600;
  font-family: inherit;
}

.user-menu-trigger:hover {
  background: rgba(93, 208, 255, 0.1);
  border-color: rgba(93, 208, 255, 0.3);
  transform: translateY(-2px);
}

/* User Dropdown */
.user-dropdown {
  position: absolute;
  top: 100%;
  right: 0;
  margin-top: 8px;
  background: var(--card, #101419);
  backdrop-filter: blur(20px);
  border: 1px solid rgba(255, 255, 255, 0.1);
  border-radius: 16px;
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
  min-width: 200px;
  opacity: 0;
  visibility: hidden;
  transform: translateY(-10px);
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  z-index: 1001;
}

.user-dropdown.show {
  opacity: 1;
  visibility: visible;
  transform: translateY(0);
}

.dropdown-header {
  padding: 12px 16px;
  border-bottom: 1px solid rgba(255, 255, 255, 0.1);
  background: rgba(93, 208, 255, 0.05);
}

.dropdown-title {
  font-size: 0.8rem;
  font-weight: 700;
  color: var(--brand, #5dd0ff);
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.dropdown-items {
  padding: 8px 0;
}

.dropdown-item {
  display: block;
  padding: 12px 16px;
  text-decoration: none;
  color: var(--text, #f2f6fa);
  font-weight: 600;
  font-size: 0.9rem;
  transition: all 0.2s ease;
}

.dropdown-item:hover {
  background: rgba(93, 208, 255, 0.1);
  transform: translateX(4px);
}

.dropdown-item.logout-item {
  color: #ff6b6b;
}

.dropdown-item.logout-item:hover {
  background: rgba(255, 107, 107, 0.1);
}

.dropdown-divider {
  height: 1px;
  background: rgba(255, 255, 255, 0.1);
  margin: 8px 16px;
}

/* Animations */
@keyframes pulse {
  0%, 100% {
    transform: scale(1);
    opacity: 1;
  }
  50% {
    transform: scale(1.1);
    opacity: 0.8;
  }
}

/* Responsive Design */
@media (max-width: 768px) {
  .navbar-container {
    padding: 0 16px;
    height: auto;
    min-height: 70px;
    flex-wrap: wrap;
    gap: 16px;
  }
  
  .navbar-menu {
    order: 3;
    width: 100%;
    justify-content: center;
    gap: 4px;
    margin-top: 16px;
  }
  
  .nav-link {
    padding: 8px 12px;
    font-size: 0.85rem;
  }
  
  .navbar-actions {
    order: 2;
    gap: 12px;
  }
}

@media (max-width: 480px) {
  .navbar-container {
    padding: 0 12px;
    gap: 8px;
    min-height: 60px;
  }
  
  .brand-name {
    font-size: 1.2rem;
  }
  
  .brand-tagline {
    display: none;
  }
  
  .nav-link {
    padding: 6px 10px;
    font-size: 0.8rem;
  }

  .user-menu-trigger {
    font-size: 0.8rem;
    padding: 8px 12px;
  }
}
</style>

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

// Toggle user dropdown menu
function toggleUserMenu() {
  const dropdown = document.getElementById('userDropdown');
  dropdown.classList.toggle('show');
  
  // Close dropdown when clicking outside
  document.addEventListener('click', function closeDropdown(e) {
    if (!e.target.closest('.user-menu')) {
      dropdown.classList.remove('show');
      document.removeEventListener('click', closeDropdown);
    }
  });
}

// Update admin notification badge
function updateAdminNotificationBadge() {
  fetch('admin_notif.php?ajax=fetch&limit=0')
    .then(r => r.json())
    .then(data => {
      const badge = document.getElementById('adminNotificationBadge');
      if (badge) {
        badge.textContent = data.count || 0;
        badge.style.display = data.count > 0 ? 'flex' : 'none';
      }
    })
    .catch(err => console.error('Error updating admin notification badge:', err));
}

function setAdminMessagesBadge(count) {
  const badge = document.getElementById('adminMessagesBadge');
  if (!badge) return;
  const n = Math.max(0, Number(count) || 0);
  if (n > 0) {
    badge.textContent = n > 99 ? '99+' : String(n);
    badge.hidden = false;
    badge.removeAttribute('hidden');
  } else {
    badge.textContent = '0';
    badge.hidden = true;
  }
}
window.setAdminMessagesBadge = setAdminMessagesBadge;

function updateAdminMessagesBadge() {
  fetch('chat_unread.php', { credentials: 'same-origin', cache: 'no-store' })
    .then(r => r.json())
    .then(data => {
      if (data && data.ok) setAdminMessagesBadge(data.count);
    })
    .catch(() => {});
}
window.updateAdminMessagesBadge = updateAdminMessagesBadge;

// Update badge on page load
document.addEventListener('DOMContentLoaded', function() {
  updateAdminNotificationBadge();
  updateAdminMessagesBadge();
  
  // Update badge every 30 seconds
  setInterval(updateAdminNotificationBadge, 30000);
  setInterval(updateAdminMessagesBadge, 15000);
});

// Close dropdown on escape key
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    const dropdown = document.getElementById('userDropdown');
    if (dropdown) {
      dropdown.classList.remove('show');
    }
  }
});
</script>

<style>
/* Theme variables override (global) */
:root{
  --bg:#0b0d10;
  --card:#101419;
  --text:#f2f6fa;
  --muted:#9aa6b3;
  --brand:#5dd0ff;
  --brand2:#7cffc7;
}
:root[data-theme="light"]{
  --bg:#f6f8fb;
  --card:#ffffff;
  --text:#0b1620;
  --muted:#4e6373;
}
</style>
<?php
$geminiRole = 'admin';
include __DIR__ . '/gemini_widget.php';
?>
