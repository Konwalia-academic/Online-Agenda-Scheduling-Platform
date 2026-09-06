<?php
require __DIR__ . '/../../app/bootstrap.php';

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $color = strtolower(trim((string)($_POST['theme_color'] ?? '#3b82f6')));
    if (!preg_match('/^#[0-9a-f]{6}$/', $color)) {
        $color = '#3b82f6';
    }
    set_setting('theme_color', $color);
    $flash = t('app_theme_msg');
}

$current = (string)setting('theme_color', '#3b82f6');
$presets = ['#3b82f6', '#6366f1', '#8b5cf6', '#ec4899', '#f43f5e', '#f97316', '#f59e0b', '#10b981', '#14b8a6', '#0ea5e9', '#64748b', '#111827'];

$pageTitle = t('admin_appearance');
$activeAdmin = 'appearance';
include __DIR__ . '/_top.php';
?>
<h1 class="page-title"><?= e(t('admin_appearance')) ?></h1>
<?php if ($flash): ?><div class="alert alert-ok"><?= e($flash) ?></div><?php endif; ?>

<form method="post" action="appearance.php">
  <?= csrf_field() ?>
  <div class="card mb">
    <h2 class="section-title" style="margin-top:0;"><?= e(t('app_preset')) ?></h2>
    <div class="slot-grid" id="presetGrid" style="grid-template-columns:repeat(auto-fill,minmax(56px,1fr));">
      <?php foreach ($presets as $p): ?>
      <button type="button" class="preset" data-color="<?= e($p) ?>"
              style="height:48px;border-radius:10px;border:3px solid <?= e($p) === $current ? '#111827' : 'transparent' ?>;background:<?= e($p) ?>;cursor:pointer;"></button>
      <?php endforeach; ?>
    </div>
    <div class="field mt">
      <label><?= e(t('app_custom')) ?></label>
      <div class="form-row" style="grid-template-columns:1fr 120px;">
        <input type="color" name="theme_color" id="customColor" value="<?= e($current) ?>" style="height:44px;padding:4px;">
        <input type="text" id="customHex" value="<?= e($current) ?>" class="kbd">
      </div>
    </div>
  </div>

  <div class="card mb">
    <h2 class="section-title" style="margin-top:0;"><?= e(t('app_preview')) ?></h2>
    <p class="muted small"><?= e(t('app_preview_text')) ?></p>
    <p>
      <span class="btn"><?= e(t('save')) ?></span>
      <span class="btn btn-ok"><?= e(t('approve')) ?></span>
      <span class="btn btn-bad"><?= e(t('decline')) ?></span>
      <span class="badge badge-pending"><?= e(t('b_pending')) ?></span>
      <span class="badge badge-approved"><?= e(t('b_approved')) ?></span>
    </p>
  </div>

  <button type="submit" class="btn"><?= e(t('save')) ?></button>
</form>
<script>
(function () {
  var A = window.Agenda;
  var color = A.qs('#customColor');
  var hex = A.qs('#customHex');
  A.qsa('.preset').forEach(function (b) {
    b.addEventListener('click', function () {
      color.value = b.getAttribute('data-color');
      hex.value = b.getAttribute('data-color');
    });
  });
  color.addEventListener('input', function () { hex.value = color.value; });
})();
</script>
<?php include __DIR__ . '/_bottom.php'; ?>
