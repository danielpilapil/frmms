<?php
// Fixed left sidebar navigation for admin pages.
$current = basename($_SERVER['PHP_SELF'] ?? '');
function layout_is_active($file, $current) {
  return $file === $current ? 'fg-sidebar-link--active' : '';
}
?>
<aside class="fg-sidebar">
  <div class="fg-sidebar__brand">
    <span class="fg-sidebar__logo">FG</span>
    <span class="fg-sidebar__title">FleetGo Admin</span>
  </div>
  <nav class="fg-sidebar__nav">
    <a href="dashboard.php" class="fg-sidebar-link <?= layout_is_active('dashboard.php', $current) ?>">Dashboard</a>
    <a href="vehicles_all.php" class="fg-sidebar-link <?= layout_is_active('vehicles_all.php', $current) ?>">Vehicles</a>
    <a href="customers_all.php" class="fg-sidebar-link <?= layout_is_active('customers_all.php', $current) ?>">Customers</a>
    <a href="rentals_all.php" class="fg-sidebar-link <?= layout_is_active('rentals_all.php', $current) ?>">Rentals</a>
    <a href="maintenance_all.php" class="fg-sidebar-link <?= layout_is_active('maintenance_all.php', $current) ?>">Maintenance</a>
    <a href="reports.php" class="fg-sidebar-link <?= layout_is_active('reports.php', $current) ?>">Reports</a>
    <a href="admin_notif.php" class="fg-sidebar-link <?= layout_is_active('admin_notif.php', $current) ?>">Notifications</a>
  </nav>
  <div class="fg-sidebar__footer">
    <a href="login.php?logout=1" class="fg-sidebar-link fg-sidebar-link--danger">Logout</a>
  </div>
</aside>

