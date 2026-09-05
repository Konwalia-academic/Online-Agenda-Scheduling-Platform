<?php
require __DIR__ . '/../app/bootstrap.php';

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    set_setting('smtp_host', trim((string)($_POST['smtp_host'] ?? '')));
    set_setting('smtp_port', (int)($_POST['smtp_port'] ?? 587));
    $enc = (string)($_POST['smtp_enc'] ?? 'tls');
    set_setting('smtp_enc', in_array($enc, ['none', 'ssl', 'tls'], true) ? $enc : 'tls');
    set_setting('smtp_user', trim((string)($_POST['smtp_user'] ?? '')));
    $pass = (string)($_POST['smtp_pass'] ?? '');
    if ($pass !== '') {
        set_setting('smtp_pass', encrypt_value($pass));
    }
    set_setting('smtp_from', trim((string)($_POST['smtp_from'] ?? '')));
    set_setting('smtp_from_name', trim((string)($_POST['smtp_from_name'] ?? '')));
    $flash = t('mail_saved');
}

$cfg = Mailer::config();
$pageTitle = t('admin_email');
$activeAdmin = 'email';
include __DIR__ . '/_top.php';
?>
<h1 class="page-title"><?= e(t('admin_email')) ?></h1>
<p class="muted"><?= e(t('mail_intro')) ?></p>

<?php if ($flash): ?><div class="alert alert-ok"><?= e($flash) ?></div><?php endif; ?>
<?php if (!Mailer::configured()): ?><div class="alert alert-info"><?= e(t('mail_not_configured')) ?></div><?php endif; ?>

<div class="card mb">
  <form method="post" action="email.php">
    <?= csrf_field() ?>
    <div class="form-row">
      <div class="field">
        <label><?= e(t('mail_host')) ?></label>
        <input type="text" name="smtp_host" value="<?= e($cfg['host']) ?>" placeholder="smtp.example.com">
      </div>
      <div class="field">
        <label><?= e(t('mail_port')) ?></label>
        <input type="number" name="smtp_port" value="<?= (int)$cfg['port'] ?>" min="1" max="65535">
      </div>
    </div>
    <div class="form-row">
      <div class="field">
        <label><?= e(t('mail_enc')) ?></label>
        <select name="smtp_enc">
          <option value="tls" <?= $cfg['enc'] === 'tls' ? 'selected' : '' ?>><?= e(t('mail_enc_tls')) ?> (587)</option>
          <option value="ssl" <?= $cfg['enc'] === 'ssl' ? 'selected' : '' ?>><?= e(t('mail_enc_ssl')) ?> (465)</option>
          <option value="none" <?= $cfg['enc'] === 'none' ? 'selected' : '' ?>><?= e(t('mail_enc_none')) ?> (25/587)</option>
        </select>
      </div>
      <div class="field">
        <label><?= e(t('mail_user')) ?></label>
        <input type="text" name="smtp_user" value="<?= e($cfg['user']) ?>" autocomplete="off">
      </div>
    </div>
    <div class="form-row">
      <div class="field">
        <label><?= e(t('mail_pass')) ?></label>
        <input type="password" name="smtp_pass" value="" placeholder="••••••••" autocomplete="new-password">
        <div class="hint"><?= e(t('mail_pass')) ?> <?= e(t('optional')) ?> — <?= e(t('mail_saved')) ?></div>
      </div>
      <div class="field">
        <label><?= e(t('mail_from')) ?></label>
        <input type="email" name="smtp_from" value="<?= e($cfg['from']) ?>">
      </div>
    </div>
    <div class="field">
      <label><?= e(t('mail_from_name')) ?></label>
      <input type="text" name="smtp_from_name" value="<?= e($cfg['from_name']) ?>">
    </div>
    <button type="submit" class="btn"><?= e(t('save')) ?></button>
  </form>
</div>

<div class="card">
  <h2 class="section-title" style="margin-top:0;"><?= e(t('test')) ?></h2>
  <div class="form-row">
    <div class="field" style="margin:0;flex:1;">
      <input type="email" id="testTo" value="<?= e((string)setting('admin_email', '')) ?>" placeholder="<?= e(t('mail_test_to')) ?>">
    </div>
    <button type="button" class="btn" id="testBtn"><?= e(t('send')) ?></button>
  </div>
  <div id="testResult" class="mt"></div>
</div>
<script>
(function () {
  var A = window.Agenda;
  A.qs('#testBtn').addEventListener('click', function () {
    var btn = A.qs('#testBtn');
    var box = A.qs('#testResult');
    btn.disabled = true;
    box.innerHTML = '<p class="muted small"><?= e(t('loading')) ?></p>';
    A.postJson('ajax.php?action=test_email', { to: A.qs('#testTo').value })
      .then(function (res) {
        btn.disabled = false;
        box.innerHTML = res.ok
          ? '<div class="alert alert-ok">' + A.escapeHtml(res.message) + '</div>'
          : '<div class="alert alert-bad">' + A.escapeHtml(res.error) + '</div>';
      });
  });
})();
</script>
<?php include __DIR__ . '/_bottom.php'; ?>
