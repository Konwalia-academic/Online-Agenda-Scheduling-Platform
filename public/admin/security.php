<?php
require __DIR__ . '/../app/bootstrap.php';

$flash = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $kind = (string)($_POST['kind'] ?? '');

    if ($kind === 'invite') {
        $code = trim((string)($_POST['invite_code'] ?? ''));
        if ($code === '') {
            $error = t('book_err_required');
        } else {
            set_setting('invite_code', mb_substr($code, 0, 64));
            $flash = t('sec_invite_saved');
        }
    } elseif ($kind === 'credentials') {
        $current = (string)($_POST['current_password'] ?? '');
        $username = trim((string)($_POST['username'] ?? ''));
        $pass = (string)($_POST['new_password'] ?? '');
        $pass2 = (string)($_POST['confirm_password'] ?? '');

        $stmt = db()->prepare('SELECT * FROM admin WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$_SESSION['admin_id']]);
        $admin = $stmt->fetch();
        if (!$admin || !password_verify($current, $admin['password_hash'])) {
            $error = t('sec_bad_current');
        } elseif ($username === '') {
            $error = t('book_err_required');
        } elseif ($pass !== $pass2) {
            $error = t('sec_pass_mismatch');
        } elseif (strlen($pass) < 8) {
            $error = t('sec_pass_short');
        } else {
            $dup = db()->prepare('SELECT id FROM admin WHERE username = ? AND id != ?');
            $dup->execute([$username, (int)$_SESSION['admin_id']]);
            if ($dup->fetch()) {
                $error = t('sec_username_taken');
            } else {
                $upd = db()->prepare('UPDATE admin SET username = ?, password_hash = ? WHERE id = ?');
                $upd->execute([$username, password_hash($pass, PASSWORD_DEFAULT), (int)$_SESSION['admin_id']]);
                $flash = t('sec_saved');
            }
        }
    }
}

$stmt = db()->prepare('SELECT * FROM admin WHERE id = ? LIMIT 1');
$stmt->execute([(int)$_SESSION['admin_id']]);
$admin = $stmt->fetch();

$pageTitle = t('admin_security');
$activeAdmin = 'security';
include __DIR__ . '/_top.php';
?>
<h1 class="page-title"><?= e(t('admin_security')) ?></h1>

<?php if ($flash): ?><div class="alert alert-ok"><?= e($flash) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-bad"><?= e($error) ?></div><?php endif; ?>

<div class="card mb">
  <h2 class="section-title" style="margin-top:0;"><?= e(t('sec_invite')) ?></h2>
  <form method="post" action="security.php" class="form-row">
    <?= csrf_field() ?>
    <input type="hidden" name="kind" value="invite">
    <div class="field" style="margin:0;flex:1;">
      <input type="text" name="invite_code" value="<?= e((string)setting('invite_code', '4310')) ?>" maxlength="64">
      <div class="hint"><?= e(t('set_invite_hint')) ?></div>
    </div>
    <button type="submit" class="btn"><?= e(t('save')) ?></button>
  </form>
</div>

<div class="card mb">
  <h2 class="section-title" style="margin-top:0;"><?= e(t('sec_admin_username')) ?> / <?= e(t('sec_new_password')) ?></h2>
  <form method="post" action="security.php">
    <?= csrf_field() ?>
    <input type="hidden" name="kind" value="credentials">
    <div class="form-row">
      <div class="field">
        <label><?= e(t('admin_login_username')) ?></label>
        <input type="text" name="username" value="<?= e($admin['username'] ?? '') ?>" autocomplete="username">
      </div>
      <div class="field">
        <label><?= e(t('sec_current_password')) ?></label>
        <input type="password" name="current_password" autocomplete="current-password">
      </div>
      <div class="field">
        <label><?= e(t('sec_new_password')) ?></label>
        <input type="password" name="new_password" autocomplete="new-password">
      </div>
      <div class="field">
        <label><?= e(t('sec_confirm_password')) ?></label>
        <input type="password" name="confirm_password" autocomplete="new-password">
      </div>
    </div>
    <button type="submit" class="btn"><?= e(t('save')) ?></button>
  </form>
</div>
<?php include __DIR__ . '/_bottom.php'; ?>
