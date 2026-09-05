<?php
/*
 * Shared page head / nav for public pages.
 * Expects: $pageTitle (optional), $activeNav (optional: 'book'|'calendar')
 */
if (!isset($pageTitle)) {
    $pageTitle = (string)setting('app_title', 'My Agenda');
}
$lang = current_lang();
$theme = (string)setting('theme_color', '#3b82f6');
$activeNav = $activeNav ?? '';
$switchTo = $lang === 'zh' ? 'en' : 'zh';
$returnUrl = urlencode($_SERVER['REQUEST_URI'] ?? '/');
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
    <a class="brand" href="<?= e(base_url()) ?>/"><?= e((string)setting('app_title', 'My Agenda')) ?></a>
    <nav class="nav">
      <a class="nav-link<?= $activeNav === 'book' ? ' is-active' : '' ?>" href="<?= e(base_url()) ?>/book.php"><?= t('book') ?></a>
      <a class="nav-link<?= $activeNav === 'calendar' ? ' is-active' : '' ?>" href="<?= e(base_url()) ?>/calendar.php"><?= t('calendar_view') ?></a>
      <a class="nav-link" href="<?= e(base_url()) ?>/admin/login.php"><?= t('admin') ?></a>
    </nav>
    <div class="lang">
      <a class="lang-link" href="<?= e(base_url()) ?>/lang.php?lang=<?= e($switchTo) ?>&r=<?= e($returnUrl) ?>" title="<?= e(t('lang_switch_title')) ?>"><?= e(t('lang_switch')) ?></a>
    </div>
  </div>
</header>
<main class="container">
