<?php
require __DIR__ . '/../app/bootstrap.php';

if (is_admin()) {
    redirect(base_url() . '/admin/index.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if (admin_login($username, $password)) {
        redirect(base_url() . '/admin/index.php');
    }
    $error = t('admin_login_bad');
}

$pageTitle = t('admin_login_title');
$lang = current_lang();
$theme = (string)setting('theme_color', '#3b82f6');
$switchTo = $lang === 'zh' ? 'en' : 'zh';
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
<body class="center-page">
<header class="topbar">
  <div class="topbar-inner">
    <a class="brand" href="<?= e(base_url()) ?>/"><?= e((string)setting('app_title', 'My Agenda')) ?></a>
    <nav class="nav"></nav>
    <div class="lang"><a class="lang-link" href="<?= e(base_url()) ?>/lang.php?lang=<?= e($switchTo) ?>&r=<?= e(urlencode('/admin/login.php')) ?>"><?= e(t('lang_switch')) ?></a></div>
  </div>
</header>
<main class="container">
  <div class="card" style="max-width:400px;margin:60px auto;">
    <h1 class="page-title center"><?= e(t('admin_login_title')) ?></h1>
    <?php if ($error): ?>
      <div class="alert alert-bad"><?= e($error) ?></div>
    <?php endif; ?>
    <form method="post" action="<?= e(base_url()) ?>/admin/login.php">
      <?= csrf_field() ?>
      <div class="field">
        <label><?= e(t('admin_login_username')) ?></label>
        <input type="text" name="username" required autofocus autocomplete="username">
      </div>
      <div class="field">
        <label><?= e(t('admin_login_password')) ?></label>
        <input type="password" name="password" required autocomplete="current-password">
      </div>
      <button type="submit" class="btn btn-block"><?= e(t('admin_login_btn')) ?></button>
    </form>
  </div>
</main>
</body>
</html>
