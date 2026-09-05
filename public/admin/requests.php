<?php
require __DIR__ . '/../app/bootstrap.php';

$pdo = db();
$flash = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $reason = trim((string)($_POST['reason'] ?? ''));
    $b = fetch_booking($id);
    if ($b) {
        switch ($action) {
            case 'approve':
                $res = set_booking_status($id, 'approved');
                $flash[] = t('b_respond_done');
                break;
            case 'decline':
                $res = set_booking_status($id, 'declined', $reason);
                $flash[] = t('b_respond_done');
                break;
            case 'cancel':
                $res = set_booking_status($id, 'cancelled');
                $flash[] = t('b_respond_done');
                break;
            case 'delete':
                $del = $pdo->prepare('DELETE FROM bookings WHERE id = ?');
                $del->execute([$id]);
                $flash[] = t('delete') . ' OK';
                break;
        }
    }
}

$statusFilter = isset($_GET['status']) && in_array((string)$_GET['status'], ['pending', 'approved', 'declined', 'cancelled'], true) ? (string)$_GET['status'] : 'pending';
$q = trim((string)($_GET['q'] ?? ''));

$sql = 'SELECT * FROM bookings WHERE 1=1';
$params = [];
if ($statusFilter !== '') {
    $sql .= ' AND status = ?';
    $params[] = $statusFilter;
}
if ($q !== '') {
    $sql .= ' AND (name LIKE ? OR email LIKE ? OR theme LIKE ? OR phone LIKE ?)';
    $params[] = "%$q%";
    $params[] = "%$q%";
    $params[] = "%$q%";
    $params[] = "%$q%";
}
$sql .= ' ORDER BY start_utc DESC LIMIT 500';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$bookings = $stmt->fetchAll();

$pageTitle = t('admin_requests');
$activeAdmin = 'requests';
include __DIR__ . '/_top.php';
?>
<h1 class="page-title"><?= e(t('admin_requests')) ?></h1>

<?php foreach ($flash as $f): ?><div class="alert alert-ok"><?= e($f) ?></div><?php endforeach; ?>

<div class="card mb">
  <form method="get" action="requests.php" class="form-row-3">
    <div class="field" style="margin:0;">
      <select name="status">
        <option value=""><?= e(t('b_filter_all')) ?></option>
        <?php foreach (['pending', 'approved', 'declined', 'cancelled'] as $s): ?>
        <option value="<?= e($s) ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= e(t('b_' . $s)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field" style="margin:0;">
      <input type="text" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('b_search')) ?>">
    </div>
    <button type="submit" class="btn"><?= e(t('b_filter_status')) ?></button>
  </form>
</div>

<?php if (count($bookings) === 0): ?>
  <div class="card"><p class="muted"><?= e(t('b_none')) ?></p></div>
<?php else: ?>
<div class="table-wrap">
<table class="data">
  <thead><tr>
    <th>#</th><th><?= e(t('b_status')) ?></th><th><?= e(t('b_date')) ?></th><th><?= e(t('b_time')) ?></th>
    <th><?= e(t('b_duration')) ?></th><th><?= e(t('b_name')) ?></th><th><?= e(t('b_theme')) ?></th>
    <th><?= e(t('b_location')) ?></th><th></th>
  </tr></thead>
  <tbody>
  <?php foreach ($bookings as $b):
      $badge = 'badge-' . $b['status']; ?>
  <tr>
    <td>#<?= (int)$b['id'] ?></td>
    <td><span class="badge <?= e($badge) ?>"><?= e(t('b_' . $b['status'])) ?></span></td>
    <td><?= e(fmt_local($b['start_utc'], 'Y-m-d')) ?></td>
    <td><?= e(fmt_local($b['start_utc'], 'H:i')) ?></td>
    <td><?= e(human_minutes((int)$b['duration_min'])) ?></td>
    <td><?= e($b['name']) ?><br><span class="muted small"><?= e($b['email']) ?></span></td>
    <td><?= e($b['theme']) ?></td>
    <td><?= e(location_label($b, current_lang())) ?></td>
    <td>
      <button type="button" class="btn btn-ghost btn-sm" data-toggle="detail-<?= (int)$b['id'] ?>"><?= e(t('details')) ?></button>
      <?php if ($b['status'] === 'pending'): ?>
        <form method="post" action="requests.php" class="inline-flex" onsubmit="return confirm('<?= e(t('approve_q')) ?>')">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
          <button class="btn btn-ok btn-sm"><?= e(t('approve')) ?></button>
        </form>
        <button type="button" class="btn btn-bad btn-sm" data-toggle="decline-<?= (int)$b['id'] ?>"><?= e(t('decline')) ?></button>
        <form method="post" action="requests.php" class="inline-flex" onsubmit="return confirm('<?= e(t('cancel_q')) ?>')">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
          <button class="btn btn-warn btn-sm"><?= e(t('cancel_booking')) ?></button>
        </form>
      <?php elseif ($b['status'] === 'approved'): ?>
        <form method="post" action="requests.php" class="inline-flex" onsubmit="return confirm('<?= e(t('cancel_q')) ?>')">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
          <button class="btn btn-warn btn-sm"><?= e(t('cancel_booking')) ?></button>
        </form>
      <?php endif; ?>
      <form method="post" action="requests.php" class="inline-flex" onsubmit="return confirm('<?= e(t('delete_booking')) ?>?')">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
        <button class="btn btn-ghost btn-sm"><?= e(t('delete_booking')) ?></button>
      </form>
      <a class="btn btn-ghost btn-sm" href="ics.php?id=<?= (int)$b['id'] ?>"><?= e(t('b_download_ics')) ?></a>
    </td>
  </tr>
  <tr id="detail-<?= (int)$b['id'] ?>" class="hidden">
    <td colspan="9">
      <div class="grid grid-2">
        <div>
          <p class="small"><b><?= e(t('b_phone')) ?>:</b> <?= e($b['phone']) ?></p>
          <p class="small"><b><?= e(t('b_attendees')) ?>:</b> <?= $b['attendees'] !== '' ? nl2br(e($b['attendees'])) : '<span class="muted">—</span>' ?></p>
          <p class="small"><b><?= e(t('b_appendix')) ?>:</b> <?= $b['appendix'] !== '' ? nl2br(e($b['appendix'])) : '<span class="muted">—</span>' ?></p>
        </div>
        <div>
          <p class="small"><b><?= e(t('b_created')) ?>:</b> <?= e(fmt_local($b['created_at'])) ?></p>
          <?php if ($b['responded_at']): ?><p class="small"><b><?= e(t('b_responded')) ?>:</b> <?= e(fmt_local($b['responded_at'])) ?></p><?php endif; ?>
          <?php if ($b['graph_event_id']): ?><p class="small"><b>Graph ID:</b> <span class="kbd"><?= e($b['graph_event_id']) ?></span></p><?php endif; ?>
          <?php if ($b['graph_error']): ?><p class="small" style="color:#b91c1c;"><b><?= e(t('error')) ?>:</b> <?= e($b['graph_error']) ?></p><?php endif; ?>
        </div>
      </div>
    </td>
  </tr>
  <tr id="decline-<?= (int)$b['id'] ?>" class="hidden">
    <td colspan="9">
      <form method="post" action="requests.php" class="form-row">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="decline"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
        <div class="field" style="margin:0;flex:1;">
          <input type="text" name="reason" placeholder="<?= e(t('b_reason')) ?>">
        </div>
        <button type="submit" class="btn btn-bad"><?= e(t('decline')) ?></button>
      </form>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
<script>
(function () {
  var A = window.Agenda;
  A.qsa('[data-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var target = A.qs('#' + btn.getAttribute('data-toggle'));
      if (target) target.classList.toggle('hidden');
    });
  });
})();
</script>
<?php include __DIR__ . '/_bottom.php'; ?>
