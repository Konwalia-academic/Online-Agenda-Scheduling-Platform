<?php
require __DIR__ . '/../app/bootstrap.php';

$pageTitle = t('index_title');
$activeNav = '';
include __DIR__ . '/_top.php';
?>
<div class="hero">
  <h1><?= e(t('index_title')) ?></h1>
  <p><?= e(t('index_subtitle')) ?></p>
</div>

<div class="grid grid-3 mt">
  <a class="card entrance" href="<?= e(base_url()) ?>/book.php">
    <div class="icon">📅</div>
    <h2><?= e(t('book')) ?></h2>
    <p><?= e(t('index_book_desc')) ?></p>
  </a>
  <a class="card entrance" href="<?= e(base_url()) ?>/calendar.php">
    <div class="icon">🗓️</div>
    <h2><?= e(t('calendar_view')) ?></h2>
    <p><?= e(t('index_cal_desc')) ?></p>
  </a>
  <a class="card entrance" href="<?= e(base_url()) ?>/admin/login.php">
    <div class="icon">⚙️</div>
    <h2><?= e(t('admin')) ?></h2>
    <p><?= e(t('index_admin_desc')) ?></p>
  </a>
</div>
<?php include __DIR__ . '/_bottom.php'; ?>
