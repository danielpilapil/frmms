<?php
// Top bar with page title, optional search, and user info.
$pageTitleSafe = isset($pageTitle) ? htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') : 'Dashboard';
$userName = $_SESSION['user_name'] ?? 'Admin';
?>
<header class="app-topbar">
  <div class="app-topbar__left">
    <h1 class="app-topbar__title"><?= $pageTitleSafe ?></h1>
    <p class="app-topbar__subtitle">Fleet overview, performance metrics, and forecasting insights.</p>
  </div>
  <div class="app-topbar__right">
    <div class="app-topbar__search">
      <input type="text" placeholder="Search vehicles, rentals, customers..." />
    </div>
    <div class="app-topbar__user">
      <div class="app-topbar__avatar"><?= strtoupper(substr($userName, 0, 1)) ?></div>
      <span class="app-topbar__username"><?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?></span>
    </div>
  </div>
</header>
