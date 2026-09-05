<?php
/*
 * Shared head / sidebar for admin pages. Requires an active admin session.
 */
require_admin();
if (!isset($pageTitle)) {
    $pageTitle = t('admin_dashboard');
}
$lang = current_lang();
$theme = (string)setting('theme_color', '#3b82f6');
$activeAdmin = $activeAdmin ?? '';
$switchTo = $lang === 'zh' ? 'en' : 'zh';
$returnUrl = urlencode($_SERVER['REQUEST_URI'] ?? '');
?>
<!DOCTYPE html>
<html lang="<?= e($lang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> · <?= e((string)setting('app_title', 'My Agenda')) ?></title>
<link rel="stylesheet" href="<?= e(base_url()) ?>/assets/css/app.css">
<style>:root{--brand:<?= e($theme) ?>;--brand-soft:<?= e($theme) ?>22;}</style>
</head>
<body>
<header class="topbar">
  <div class="topbar-inner">
    <a class="brand" href="<?= e(base_url()) ?>/admin/index.php"><?= e((string)setting('app_title', 'My Agenda')) ?> · <?= t('admin') ?></a>
    <nav class="nav">
      <a class="nav-link" href="<?= e(base_url()) ?>/"><?= t('home') ?></a>
      <a class="nav-link" href="<?= e(base_url()) ?>/admin/logout.php"><?= t('admin_logout') ?></a>
    </nav>
    <div class="lang">
      <a class="lang-link" href="<?= e(base_url()) ?>/lang.php?lang=<?= e($switchTo) ?>&r=<?= e($returnUrl) ?>"><?= e(t('lang_switch')) ?></a>
    </div>
  </div>
</header>
<div class="admin-layout">
  <aside class="sidebar">
    <a class="side-link<?= $activeAdmin === 'dashboard' ? ' is-active' : '' ?>" href="<?= e(base_url()) ?>/admin/index.php"><?= t('admin_dashboard') ?></a>
    <a class="side-link<?= $activeAdmin === 'requests' ? ' is-active' : '' ?>" href="<?= e(base_url()) ?>/admin/requests.php"><?= t('admin_requests') ?></a>
    <a class="side-link<?= $activeAdmin === 'bookings' ? ' is-active' : '' ?>" href="<?= e(base_url()) ?>/admin/bookings.php"><?= t('admin_bookings') ?></a>
    <a class="side-link<?= $activeAdmin === 'calendars' ? ' is-active' : '' ?>" href="<?= e(base_url()) ?>/admin/calendars.php"><?= t('admin_calendars') ?></a>
    <a class="side-link<?= $activeAdmin === 'email' ? ' is-active' : '' ?>" href="<?= e(base_url()) ?>/admin/email.php"><?= t('admin_email') ?></a>
    <a class="side-link<?= $activeAdmin === 'settings' ? ' is-active' : '' ?>" href="<?= e(base_url()) ?>/admin/settings.php"><?= t('admin_settings') ?></a>
    <a class="side-link<?= $activeAdmin === 'appearance' ? ' is-active' : '' ?>" href="<?= e(base_url()) ?>/admin/appearance.php"><?= t('admin_appearance') ?></a>
    <a class="side-link<?= $activeAdmin === 'security' ? ' is-active' : '' ?>" href="<?= e(base_url()) ?>/admin/security.php"><?= t('admin_security') ?></a>
  </aside>
  <main class="admin-main">
