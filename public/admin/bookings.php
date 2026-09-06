<?php
require __DIR__ . '/../../app/bootstrap.php';

$pdo = db();
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (($_POST['action'] ?? '') === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare('DELETE FROM bookings WHERE id = ?')->execute([$id]);
        $flash = t('delete') . ' OK';
    }
}

$statusFilter = isset($_GET['status']) && in_array((string)$_GET['status'], ['pending', 'approved', 'declined', 'cancelled'], true) ? (string)$_GET['status'] : '';
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

$pageTitle = t('admin_bookings');
$activeAdmin = 'bookings';
include __DIR__ . '/_top.php';
?>
<h1 class="page-title"><?= e(t('admin_bookings')) ?></h1>
<?php if ($flash): ?><div class="alert alert-ok"><?= e($flash) ?></div><?php endif; ?>

<div class="card mb">
  <form method="get" action="bookings.php" class="form-row-3">
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
  <?php foreach ($bookings as $b): ?>
  <tr>
    <td>#<?= (int)$b['id'] ?></td>
    <td><span class="badge badge-<?= e($b['status']) ?>"><?= e(t('b_' . $b['status'])) ?></span></td>
    <td><?= e(fmt_local($b['start_utc'], 'Y-m-d')) ?></td>
    <td><?= e(fmt_local($b['start_utc'], 'H:i')) ?></td>
    <td><?= e(human_minutes((int)$b['duration_min'])) ?></td>
    <td><?= e($b['name']) ?><br><span class="muted small"><?= e($b['email']) ?></span></td>
    <td><?= e($b['theme']) ?></td>
    <td><?= e(location_label($b, current_lang())) ?></td>
    <td class="inline-flex">
      <a class="btn btn-ghost btn-sm" href="ics.php?id=<?= (int)$b['id'] ?>"><?= e(t('b_download_ics')) ?></a>
      <form method="post" action="bookings.php" class="inline-flex" onsubmit="return confirm('<?= e(t('delete_booking')) ?>?')">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
        <button class="btn btn-bad btn-sm"><?= e(t('delete_booking')) ?></button>
      </form>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
<?php include __DIR__ . '/_bottom.php'; ?>
