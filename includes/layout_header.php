<?php
// Global layout header: base HTML, head, and app shell wrapper.
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= isset($pageTitle) ? htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') : 'FleetGo Admin'; ?></title>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
  <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="app-body">
<div class="app-shell">
