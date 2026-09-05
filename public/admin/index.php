<?php
require __DIR__ . '/../app/bootstrap.php';

$pdo = db();
$now = utc_now();

$statPending = (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE status='pending'")->fetchColumn();
$statUpcoming = (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE status='approved' AND end_utc >= '$now'")->fetchColumn();
$statTotal = (int)$pdo->query("SELECT COUNT(*) FROM bookings")->fetchColumn();
$statToday = 0;
try {
    $tz = app_timezone();
    $todayStart = local_to_utc((new DateTime('now', $tz))->format('Y-m-d') . ' 00:00', 'Y-m-d H:i');
    $todayEnd = local_to_utc((new DateTime('now', $tz))->format('Y-m-d') . ' 23:59', 'Y-m-d H:i');
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM (
        SELECT 1 FROM events_cache WHERE end_utc > ? AND start_utc < ? AND uid NOT LIKE 'booking-%'
        UNION ALL
        SELECT 1 FROM bookings WHERE status IN ('pending','approved') AND end_utc > ? AND start_utc < ?
    ) t");
    $stmt->execute([$todayStart, $todayEnd, $todayStart, $todayEnd]);
    $statToday = (int)$stmt->fetchColumn();
} catch (Throwable) {
    $statToday = 0;
}

$pending = $pdo->query("SELECT * FROM bookings WHERE status='pending' ORDER BY start_utc ASC LIMIT 30")->fetchAll();

$pageTitle = t('admin_dashboard');
$activeAdmin = 'dashboard';
include __DIR__ . '/_top.php';
?>
<h1 class="page-title"><?= e(t('admin_welcome')) ?></h1>

<div class="grid grid-3 mb">
  <div class="card stat"><div class="num"><?= (int)$statPending ?></div><div class="lbl"><?= e(t('stat_pending')) ?></div></div>
  <div class="card stat"><div class="num"><?= (int)$statUpcoming ?></div><div class="lbl"><?= e(t('stat_upcoming')) ?></div></div>
  <div class="card stat"><div class="num"><?= (int)$statToday ?></div><div class="lbl"><?= e(t('stat_today')) ?></div></div>
  <div class="card stat"><div class="num"><?= (int)$statTotal ?></div><div class="lbl"><?= e(t('stat_total')) ?></div></div>
</div>

<h2 class="section-title"><?= e(t('pend_title')) ?></h2>
<?php if (count($pending) === 0): ?>
  <div class="card"><p class="muted"><?= e(t('pend_empty')) ?></p></div>
<?php else: ?>
<div class="table-wrap">
<table class="data">
  <thead><tr>
    <th><?= e(t('b_date')) ?></th><th><?= e(t('b_time')) ?></th><th><?= e(t('b_duration')) ?></th>
    <th><?= e(t('b_name')) ?></th><th><?= e(t('b_theme')) ?></th><th><?= e(t('b_location')) ?></th><th></th>
  </tr></thead>
  <tbody>
  <?php foreach ($pending as $b): ?>
    <tr>
      <td><?= e(fmt_local($b['start_utc'], 'Y-m-d')) ?></td>
      <td><?= e(fmt_local($b['start_utc'], 'H:i')) ?></td>
      <td><?= e(human_minutes((int)$b['duration_min'])) ?></td>
      <td><?= e($b['name']) ?><br><span class="muted small"><?= e($b['email']) ?></span></td>
      <td><?= e($b['theme']) ?></td>
      <td><?= e(location_label($b, current_lang())) ?></td>
      <td>
        <form method="post" action="requests.php" class="inline-flex" onsubmit="return confirm('<?= e(t('approve_q')) ?>')">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="approve">
          <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
          <button class="btn btn-ok btn-sm"><?= e(t('approve')) ?></button>
        </form>
        <form method="post" action="requests.php" class="inline-flex" onsubmit="return confirm('<?= e(t('decline_q')) ?>')">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="decline">
          <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
          <button class="btn btn-bad btn-sm"><?= e(t('decline')) ?></button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
<?php include __DIR__ . '/_bottom.php'; ?>
