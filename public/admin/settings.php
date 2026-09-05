<?php
require __DIR__ . '/../app/bootstrap.php';

$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    set_setting('app_title', trim((string)($_POST['app_title'] ?? 'My Agenda')));
    $lang = (string)($_POST['default_lang'] ?? 'en');
    set_setting('default_lang', $lang === 'zh' ? 'zh' : 'en');
    set_setting('timezone', trim((string)($_POST['timezone'] ?? 'UTC')));
    set_setting('site_url', rtrim(trim((string)($_POST['site_url'] ?? '')), '/'));
    set_setting('admin_email', trim((string)($_POST['admin_email'] ?? '')));
    set_setting('slot_step', (int)($_POST['slot_step'] ?? 15));
    set_setting('min_duration', (int)($_POST['min_duration'] ?? 15));
    set_setting('max_duration', (int)($_POST['max_duration'] ?? 360));
    set_setting('buffer_min', (int)($_POST['buffer_min'] ?? 0));
    set_setting('max_advance', (int)($_POST['max_advance'] ?? 60));
    set_setting('duration_options', trim((string)($_POST['duration_options'] ?? '15,30,45,60,90,120')));

    $wh = [];
    for ($i = 0; $i < 7; $i++) {
        $wh[$i] = [];
        if (!empty($_POST['wd_enable'][$i])) {
            $s = trim((string)($_POST['wd_start'][$i] ?? '09:00'));
            $e = trim((string)($_POST['wd_end'][$i] ?? '18:00'));
            if (preg_match('/^\d{1,2}:\d{2}$/', $s) && preg_match('/^\d{1,2}:\d{2}$/', $e)) {
                $wh[$i][] = ['s' => $s, 'e' => $e];
            }
        }
    }
    set_setting('working_hours', json_encode($wh));
    $flash = t('saved');
}

$tzOptions = [
    'UTC' => 'UTC',
    'Asia/Shanghai' => 'Asia/Shanghai (UTC+8)',
    'Asia/Hong_Kong' => 'Asia/Hong_Kong',
    'Asia/Taipei' => 'Asia/Taipei',
    'Asia/Tokyo' => 'Asia/Tokyo',
    'Asia/Singapore' => 'Asia/Singapore',
    'Asia/Kolkata' => 'Asia/Kolkata',
    'Australia/Sydney' => 'Australia/Sydney',
    'Europe/London' => 'Europe/London',
    'Europe/Berlin' => 'Europe/Berlin',
    'Europe/Paris' => 'Europe/Paris',
    'America/New_York' => 'America/New_York',
    'America/Chicago' => 'America/Chicago',
    'America/Denver' => 'America/Denver',
    'America/Los_Angeles' => 'America/Los_Angeles',
];
$wh = working_hours();
$wdLabels = current_lang() === 'zh'
    ? ['周日', '周一', '周二', '周三', '周四', '周五', '周六']
    : ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

$pageTitle = t('admin_settings');
$activeAdmin = 'settings';
include __DIR__ . '/_top.php';
?>
<h1 class="page-title"><?= e(t('admin_settings')) ?></h1>
<?php if ($flash): ?><div class="alert alert-ok"><?= e($flash) ?></div><?php endif; ?>

<form method="post" action="settings.php">
  <?= csrf_field() ?>
  <div class="card mb">
    <h2 class="section-title" style="margin-top:0;"><?= e(t('set_general')) ?></h2>
    <div class="form-row">
      <div class="field">
        <label><?= e(t('set_site_title')) ?></label>
        <input type="text" name="app_title" value="<?= e((string)setting('app_title', 'My Agenda')) ?>" maxlength="100">
      </div>
      <div class="field">
        <label><?= e(t('set_default_lang')) ?></label>
        <select name="default_lang">
          <option value="en" <?= setting('default_lang', 'en') === 'en' ? 'selected' : '' ?>>English</option>
          <option value="zh" <?= setting('default_lang', 'en') === 'zh' ? 'selected' : '' ?>>简体中文</option>
        </select>
      </div>
    </div>
    <div class="form-row">
      <div class="field">
        <label><?= e(t('set_timezone')) ?></label>
        <select name="timezone">
          <?php foreach ($tzOptions as $val => $label): ?>
          <option value="<?= e($val) ?>" <?= setting('timezone', 'UTC') === $val ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label><?= e(t('set_site_url')) ?></label>
        <input type="url" name="site_url" value="<?= e((string)setting('site_url', '')) ?>" placeholder="https://agenda.example.com">
        <div class="hint"><?= e(t('set_site_url_hint')) ?></div>
      </div>
    </div>
    <div class="field">
      <label><?= e(t('set_email_after')) ?></label>
      <input type="email" name="admin_email" value="<?= e((string)setting('admin_email', '')) ?>">
    </div>
  </div>

  <div class="card mb">
    <h2 class="section-title" style="margin-top:0;"><?= e(t('set_working_hours')) ?></h2>
    <?php for ($i = 0; $i < 7; $i++):
        $blocks = $wh[$i] ?? [];
        $enabled = count($blocks) > 0;
        $s = $blocks[0]['s'] ?? '09:00';
        $e = $blocks[0]['e'] ?? '18:00';
    ?>
    <div class="form-row" style="grid-template-columns: 140px 70px 1fr 1fr;align-items:center;margin-bottom:10px;">
      <label style="font-weight:600;font-size:14px;margin:0;"><?= e($wdLabels[$i]) ?></label>
      <input type="checkbox" name="wd_enable[<?= $i ?>]" value="1" <?= $enabled ? 'checked' : '' ?>>
      <input type="time" name="wd_start[<?= $i ?>]" value="<?= e($s) ?>">
      <input type="time" name="wd_end[<?= $i ?>]" value="<?= e($e) ?>">
    </div>
    <?php endfor; ?>
  </div>

  <div class="card mb">
    <h2 class="section-title" style="margin-top:0;"><?= e(t('book')) ?> · <?= e(t('admin_settings')) ?></h2>
    <div class="form-row-3">
      <div class="field">
        <label><?= e(t('set_slot_step')) ?></label>
        <input type="number" name="slot_step" min="5" max="120" step="5" value="<?= (int)setting('slot_step', 15) ?>">
      </div>
      <div class="field">
        <label><?= e(t('set_min_duration')) ?></label>
        <input type="number" name="min_duration" min="15" max="360" step="15" value="<?= (int)setting('min_duration', 15) ?>">
      </div>
      <div class="field">
        <label><?= e(t('set_max_duration')) ?></label>
        <input type="number" name="max_duration" min="15" max="1440" step="15" value="<?= (int)setting('max_duration', 360) ?>">
      </div>
      <div class="field">
        <label><?= e(t('set_buffer')) ?></label>
        <input type="number" name="buffer_min" min="0" max="240" step="5" value="<?= (int)setting('buffer_min', 0) ?>">
      </div>
      <div class="field">
        <label><?= e(t('set_max_advance')) ?></label>
        <input type="number" name="max_advance" min="1" max="365" value="<?= (int)setting('max_advance', 60) ?>">
      </div>
      <div class="field">
        <label><?= e(t('set_duration_options')) ?></label>
        <input type="text" name="duration_options" value="<?= e((string)setting('duration_options', '15,30,45,60,90,120')) ?>">
        <div class="hint"><?= e(t('set_duration_options_hint')) ?></div>
      </div>
    </div>
  </div>

  <button type="submit" class="btn"><?= e(t('save')) ?></button>
</form>
<?php include __DIR__ . '/_bottom.php'; ?>
