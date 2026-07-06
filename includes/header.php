<?php
// Global admin header: base HTML shell + shared CSS + top bar wrapper.
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= isset($pageTitle) ? htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') : 'FleetGo Admin'; ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/fleetgo-shared.css">
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="fg-body">
<?php include __DIR__ . '/navbar.php'; ?>
<div class="fg-layout">
