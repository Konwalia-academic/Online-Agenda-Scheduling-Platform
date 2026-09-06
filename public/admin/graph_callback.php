<?php
/*
 * OAuth callback: Microsoft redirects here after the administrator signs in.
 * Exchanges the code (with PKCE verifier) for tokens and stores them.
 */
require __DIR__ . '/../../app/bootstrap.php';
require_admin();

$state = (string)($_GET['state'] ?? '');
$code = (string)($_GET['code'] ?? '');
$errorDesc = (string)($_GET['error_description'] ?? '');

$fail = null;
if ($errorDesc !== '') {
    $fail = $errorDesc;
} elseif ($state === '' || !hash_equals((string)($_SESSION['graph_state'] ?? ''), $state)) {
    $fail = 'Invalid OAuth state.';
} elseif ($code === '') {
    $fail = 'No authorization code returned.';
} else {
    $verifier = (string)($_SESSION['graph_verifier'] ?? '');
    unset($_SESSION['graph_state'], $_SESSION['graph_verifier']);
    $res = graph()->exchange($code, $verifier);
    if (!$res['ok']) {
        $fail = is_string($res['error'] ?? null) ? $res['error'] : 'Token exchange failed.';
    }
}

$pageTitle = t('admin_calendars');
$lang = current_lang();
$theme = (string)setting('theme_color', '#3b82f6');
$switchTo = $lang === 'zh' ? 'en' : 'zh';
?>
<!DOCTYPE html>
<html lang="<?= e($lang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?></title>
<link rel="stylesheet" href="<?= e(base_url()) ?>/assets/css/app.css">
<style>:root{--brand:<?= e($theme) ?>;--brand-soft:<?= e($theme) ?>22;}</style>
</head>
<body>
<header class="topbar"><div class="topbar-inner"><a class="brand" href="<?= e(base_url()) ?>/"><?= e((string)setting('app_title', 'My Agenda')) ?></a></div></header>
<main class="container">
  <div class="card" style="max-width:480px;margin:60px auto;">
    <?php if ($fail): ?>
      <div class="alert alert-bad"><?= e($fail) ?></div>
      <a class="btn" href="<?= e(base_url()) ?>/admin/calendars.php"><?= e(t('back')) ?></a>
    <?php else: ?>
      <p class="muted"><?= e(t('loading')) ?></p>
      <script>setTimeout(function(){ window.location.href = <?= json_encode(base_url() . '/admin/calendars.php') ?>; }, 400);</script>
      <noscript><a class="btn" href="<?= e(base_url()) ?>/admin/calendars.php"><?= e(t('back')) ?></a></noscript>
    <?php endif; ?>
  </div>
</main>
</body>
</html>
