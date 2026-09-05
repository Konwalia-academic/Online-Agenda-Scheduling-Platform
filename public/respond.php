<?php
/*
 * Handles the Approve / Decline links inside the "new booking request"
 * e-mail sent to the administrator. The admin_token in the URL is the secret
 * that authorises the action, so no session login is required here.
 */
require __DIR__ . '/../app/bootstrap.php';

$token = (string)($_GET['token'] ?? '');
$action = (string)($_GET['action'] ?? '');

$pageTitle = t('admin_requests');
$activeNav = 'book';

if ($token === '' || !in_array($action, ['approve', 'decline'], true)) {
    http_response_code(404);
    include __DIR__ . '/_top.php';
    echo '<div class="card center" style="max-width:480px;margin:40px auto;"><p>404</p></div>';
    include __DIR__ . '/_bottom.php';
    exit;
}

$b = fetch_booking_by_admin_token($token);
if (!$b) {
    include __DIR__ . '/_top.php';
    ?>
    <div class="card center" style="max-width:480px;margin:40px auto;">
      <h1 class="page-title"><?= e(t('book_not_found')) ?></h1>
      <a class="btn" href="<?= e(base_url()) ?>/"><?= e(t('home')) ?></a>
    </div>
    <?php
    include __DIR__ . '/_bottom.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $reason = trim((string)($_POST['reason'] ?? ''));
    $result = set_booking_status((int)$b['id'], $action === 'approve' ? 'approved' : 'declined', $reason);
    $done = $result['ok'];
}

include __DIR__ . '/_top.php';
if (isset($done) && $done):
?>
<div class="card center" style="max-width:520px;margin:40px auto;">
  <div class="alert alert-ok"><?= e(t('b_respond_done')) ?></div>
  <p><b><?= e(fmt_local($b['start_utc'])) ?></b> · <?= e($b['theme']) ?></p>
  <a class="btn" href="<?= e(base_url()) ?>/"><?= e(t('home')) ?></a>
</div>
<?php else: ?>
<div class="card" style="max-width:520px;margin:40px auto;">
  <h1 class="page-title"><?= e(t($action === 'approve' ? 'approve' : 'decline')) ?> — <?= e($b['theme']) ?></h1>
  <table class="data" style="margin-bottom:16px;">
    <tr><th><?= e(t('b_time')) ?></th><td><?= e(fmt_local($b['start_utc'])) ?></td></tr>
    <tr><th><?= e(t('b_duration')) ?></th><td><?= e(human_minutes((int)$b['duration_min'])) ?></td></tr>
    <tr><th><?= e(t('b_name')) ?></th><td><?= e($b['name']) ?></td></tr>
    <tr><th><?= e(t('b_email')) ?></th><td><?= e($b['email']) ?></td></tr>
    <tr><th><?= e(t('b_phone')) ?></th><td><?= e($b['phone']) ?></td></tr>
    <tr><th><?= e(t('b_location')) ?></th><td><?= e(location_label($b, current_lang())) ?></td></tr>
  </table>
  <form method="post" action="<?= e(base_url()) ?>/respond.php?token=<?= e(urlencode($token)) ?>&action=<?= e($action) ?>">
    <?= csrf_field() ?>
    <?php if ($action === 'decline'): ?>
      <div class="field">
        <label><?= e(t('b_reason')) ?></label>
        <textarea name="reason"></textarea>
      </div>
    <?php endif; ?>
    <button type="submit" class="btn <?= $action === 'approve' ? 'btn-ok' : 'btn-bad' ?>"><?= e(t($action === 'approve' ? 'approve' : 'decline')) ?></button>
  </form>
</div>
<?php endif; ?>
<?php include __DIR__ . '/_bottom.php'; ?>
