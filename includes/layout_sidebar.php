<?php
// Left sidebar navigation with icons + labels.
$current = basename($_SERVER['PHP_SELF'] ?? '');
function layout_is_active($file, $current) {
  return $file === $current ? 'app-sidebar__link--active' : '';
}
?>
<aside class="app-sidebar">
  <div class="app-sidebar__brand">
    <div class="app-sidebar__logo">FG</div>
    <div class="app-sidebar__title">FleetGo Admin</div>
  </div>
  <nav class="app-sidebar__nav">
    <a href="dashboard.php" class="app-sidebar__link <?= layout_is_active('dashboard.php', $current) ?>">
      <span class="fg-sidebar__logo">FG</span><span>Dashboard</span>
    </a>
    <a href="vehicles_all.php" class="app-sidebar__link <?= layout_is_active('vehicles_all.php', $current) ?>">
      <span class="app-sidebar__icon"></span><span>Vehicles</span>
    </a>
    <a href="customers_all.php" class="app-sidebar__link <?= layout_is_active('customers_all.php', $current) ?>">
      <span class="app-sidebar__icon">👥</span><span>Customers</span>
    </a>
    <a href="rentals_all.php" class="app-sidebar__link <?= layout_is_active('rentals_all.php', $current) ?>">
      <span class="app-sidebar__icon">🧾</span><span>Rentals</span>
    </a>
    <a href="maintenance_all.php" class="app-sidebar__link <?= layout_is_active('maintenance_all.php', $current) ?>">
      <span class="app-sidebar__icon">🔧</span><span>Maintenance</span>
    </a>
    <a href="reports.php" class="app-sidebar__link <?= layout_is_active('reports.php', $current) ?>">
      <span class="app-sidebar__icon">📈</span><span>Reports</span>
    </a>
    <a href="admin_notif.php" class="app-sidebar__link <?= layout_is_active('admin_notif.php', $current) ?>">
      <span class="app-sidebar__icon">🔔</span><span>Notifications</span>
    </a>
  </nav>
  <div class="app-sidebar__footer">
    <a href="login.php?logout=1" class="app-sidebar__link app-sidebar__link--danger">
      <span class="app-sidebar__icon">⏻</span><span>Logout</span>
    </a>
  </div>
</aside>

