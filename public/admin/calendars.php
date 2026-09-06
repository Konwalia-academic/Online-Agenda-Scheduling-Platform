<?php
require __DIR__ . '/../../app/bootstrap.php';

$flash = null;
$error = null;
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');

    switch ($action) {
        case 'add_upload':
            $name = trim((string)($_POST['name'] ?? ''));
            $file = $_FILES['ics_file'] ?? null;
            if ($name === '' || !$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $error = t('book_err_required');
                break;
            }
            $content = (string)file_get_contents($file['tmp_name']);
            if (count(IcsParser::parse($content)) === 0) {
                $error = t('cal_upload_bad');
                break;
            }
            ensure_dir(STORAGE . '/uploads');
            $fname = 'cal-' . random_token(8) . '.ics';
            file_put_contents(STORAGE . '/uploads/' . $fname, $content);
            $cfg = json_encode(['file' => $fname]);
            $ins = $pdo->prepare('INSERT INTO calendars (name, ctype, config, enabled, created_at) VALUES (?, ?, ?, 1, ?)');
            $ins->execute([$name, 'upload', $cfg, utc_now()]);
            $newId = (int)$pdo->lastInsertId();
            $res = sync_calendar_row(['id' => $newId, 'ctype' => 'upload', 'config' => $cfg]);
            $flash = $res['ok'] ? t('cal_synced', ['events' => $res['count']]) : t('cal_sync_failed', ['error' => $res['error']]);
            break;

        case 'add_url':
            $name = trim((string)($_POST['name'] ?? ''));
            $url = trim((string)($_POST['url'] ?? ''));
            if ($name === '' || !preg_match('#^https?://#i', $url)) {
                $error = t('book_err_required');
                break;
            }
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT => 'Agenda-Platform/1.0',
            ]);
            $content = curl_exec($ch);
            curl_close($ch);
            if ($content === false || count(IcsParser::parse((string)$content)) === 0) {
                $error = t('cal_url_bad');
                break;
            }
            $cfg = json_encode(['url' => $url]);
            $ins = $pdo->prepare('INSERT INTO calendars (name, ctype, config, enabled, created_at) VALUES (?, ?, ?, 1, ?)');
            $ins->execute([$name, 'url', $cfg, utc_now()]);
            $newId = (int)$pdo->lastInsertId();
            $res = sync_calendar_row(['id' => $newId, 'ctype' => 'url', 'config' => $cfg]);
            $flash = $res['ok'] ? t('cal_synced', ['events' => $res['count']]) : t('cal_sync_failed', ['error' => $res['error']]);
            break;

        case 'toggle':
            $id = (int)($_POST['id'] ?? 0);
            $enabled = (int)($_POST['enabled'] ?? 0) ? 1 : 0;
            $stmt = $pdo->prepare('UPDATE calendars SET enabled = ? WHERE id = ?');
            $stmt->execute([$enabled, $id]);
            break;

        case 'delete_calendar':
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare('SELECT * FROM calendars WHERE id = ?');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if ($row) {
                if ($row['ctype'] === 'upload') {
                    $cfg = json_decode((string)$row['config'], true) ?: [];
                    $f = STORAGE . '/uploads/' . basename((string)($cfg['file'] ?? ''));
                    if (is_file($f)) {
                        @unlink($f);
                    }
                }
                $pdo->prepare('DELETE FROM events_cache WHERE calendar_id = ?')->execute([$id]);
                $pdo->prepare('DELETE FROM calendars WHERE id = ?')->execute([$id]);
                $flash = t('cal_deleted');
            }
            break;

        case 'sync_one':
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare('SELECT * FROM calendars WHERE id = ?');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if ($row) {
                $res = sync_calendar_row($row);
                $flash = $res['ok'] ? t('cal_synced', ['events' => $res['count']]) : t('cal_sync_failed', ['error' => $res['error']]);
            }
            break;

        case 'save_interval':
            set_setting('sync_interval_min', max(1, (int)($_POST['sync_interval'] ?? 10)));
            $flash = t('saved');
            break;

        case 'graph_save':
            set_setting('graph_tenant', trim((string)($_POST['graph_tenant'] ?? 'common')));
            set_setting('graph_client_id', trim((string)($_POST['graph_client_id'] ?? '')));
            $secret = trim((string)($_POST['graph_secret'] ?? ''));
            if ($secret !== '') {
                set_setting('graph_secret', encrypt_value($secret));
            }
            $flash = t('saved');
            break;

        case 'graph_pick':
            $selected = $_POST['show_cals'] ?? [];
            $selected = array_map('strval', is_array($selected) ? $selected : []);
            set_setting('graph_calendars', json_encode($selected));
            set_setting('graph_booking_calendar', (string)($_POST['booking_cal'] ?? ''));
            $flash = t('cal_graph_saved');
            break;

        case 'graph_disconnect':
            graph()->disconnect();
            $flash = t('saved');
            break;

        case 'add_caldav':
            $name = trim((string)($_POST['name'] ?? ''));
            $server = trim((string)($_POST['caldav_server'] ?? ''));
            $username = trim((string)($_POST['caldav_username'] ?? ''));
            $password = (string)($_POST['caldav_password'] ?? '');
            if ($server === '' || $username === '' || $password === '') {
                $error = t('book_err_required');
                break;
            }
            try {
                $client = new CalDavClient($server, $username, $password);
                $discovered = $client->listCalendars();
                if (!$discovered['ok']) {
                    $error = t('cal_caldav_discover_err', ['error' => $discovered['error']]);
                    break;
                }
                if (count($discovered['cals']) === 0) {
                    $error = t('cal_caldav_no_cal');
                    break;
                }
                // Store for the confirmation step (kept in hidden fields).
                $pendingCaldav = [
                    'name' => $name,
                    'server' => $server,
                    'username' => $username,
                    'password' => $password,
                    'cals' => $discovered['cals'],
                ];
            } catch (Throwable $ex) {
                $error = t('cal_caldav_discover_err', ['error' => $ex->getMessage()]);
            }
            break;

        case 'add_caldav_confirm':
            $name = trim((string)($_POST['name'] ?? ''));
            $server = trim((string)($_POST['caldav_server'] ?? ''));
            $username = trim((string)($_POST['caldav_username'] ?? ''));
            $password = (string)($_POST['caldav_password'] ?? '');
            $selected = $_POST['cals'] ?? [];
            $selected = is_array($selected) ? array_values($selected) : [];
            if ($server === '' || $username === '' || $password === '' || count($selected) === 0) {
                $error = t('book_err_required');
                break;
            }
            $passEnc = encrypt_value($password);
            $added = 0;
            foreach ($selected as $key) {
                $key = base64_decode((string)$key, true);
                if ($key === false) {
                    continue;
                }
                [$href, $displayname] = explode("\x00", $key, 2) + [1 => ''];
                if ($href === '') {
                    continue;
                }
                $cfg = json_encode([
                    'server' => $server,
                    'username' => $username,
                    'password_enc' => $passEnc,
                    'calendar_href' => $href,
                    'displayname' => $displayname,
                ]);
                $calName = $name !== '' ? $name . ($displayname !== '' ? ' · ' . $displayname : '') : ($displayname !== '' ? $displayname : 'CalDAV');
                $ins = $pdo->prepare('INSERT INTO calendars (name, ctype, config, enabled, created_at) VALUES (?, ?, ?, 1, ?)');
                $ins->execute([$calName, 'caldav', $cfg, utc_now()]);
                $newId = (int)$pdo->lastInsertId();
                $res = sync_calendar_row(['id' => $newId, 'ctype' => 'caldav', 'config' => $cfg]);
                if ($res['ok']) {
                    $added++;
                } else {
                    $error = t('cal_sync_failed', ['error' => $res['error']]);
                }
            }
            $flash = $added > 0 ? t('cal_caldav_added') : ($error ?? t('error'));
            break;
    }
}

$calendars = $pdo->query('SELECT * FROM calendars ORDER BY id')->fetchAll();
$graphConfigured = graph()->configured();
$graphConnected = $graphConfigured && graph()->isConnected();
$graphNeedsReauth = graph()->needsReauth();

$pageTitle = t('admin_calendars');
$activeAdmin = 'calendars';
include __DIR__ . '/_top.php';
?>
<h1 class="page-title"><?= e(t('cal_manage_title')) ?></h1>

<?php if ($flash): ?><div class="alert alert-ok"><?= e($flash) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-bad"><?= e($error) ?></div><?php endif; ?>

<div class="card mb">
  <div class="form-row" style="align-items:end;">
    <div class="field" style="margin:0;flex:1;">
      <label><?= e(t('cal_sync_interval')) ?></label>
      <form method="post" action="calendars.php" class="form-row" style="grid-template-columns:1fr auto;gap:10px;margin:6px 0 0;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_interval">
        <input type="number" name="sync_interval" min="1" max="1440" value="<?= (int)setting('sync_interval_min', 10) ?>">
        <button type="submit" class="btn"><?= e(t('save')) ?></button>
      </form>
      <div class="hint"><?= e(t('cal_sync_interval_hint')) ?></div>
    </div>
    <button type="button" class="btn" id="syncAllBtn"><?= e(t('cal_sync_now')) ?></button>
  </div>
  <div id="syncAllResult" class="mt"></div>
</div>

<!-- ============ Microsoft Graph ============ -->
<div class="card mb">
  <h2 class="section-title" style="margin-top:0;"><?= e(t('cal_add_graph')) ?></h2>
  <p class="muted small"><?= e(t('cal_graph_intro')) ?></p>

  <?php if ($graphConnected): ?>
    <div class="alert alert-ok">
      <?= e(t('cal_graph_connected_as')) ?>: <b><?= e((string)setting('graph_user_email', '')) ?></b>
      <?php if ($exp = graph()->tokenExpiresAt()): ?>
        · <?= e(t('cal_graph_token_ok')) ?> <?= e(utc_to_local(gmdate('Y-m-d H:i:s', $exp))->format('Y-m-d H:i')) ?>
      <?php endif; ?>
    </div>

    <div class="inline-flex mb">
      <a class="btn" href="graph_auth.php"><?= e(t('cal_graph_reconnect')) ?></a>
      <form method="post" action="calendars.php" class="inline-flex" onsubmit="return confirm('<?= e(t('cal_graph_disconnect')) ?>?')">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="graph_disconnect">
        <button type="submit" class="btn btn-ghost"><?= e(t('cal_graph_disconnect')) ?></button>
      </form>
    </div>

    <h3 class="section-title"><?= e(t('cal_graph_pick')) ?></h3>
    <form method="post" action="calendars.php" id="graphPickForm">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="graph_pick">
      <div id="graphCals" class="mb"><?= e(t('loading')) ?></div>
      <div class="field mb">
        <label><?= e(t('cal_graph_booking_cal')) ?></label>
        <select name="booking_cal" id="graphBookingCal"></select>
      </div>
      <button type="submit" class="btn"><?= e(t('save')) ?></button>
    </form>

  <?php else: ?>
    <?php if ($graphNeedsReauth): ?>
      <div class="alert alert-bad"><?= e(t('cal_graph_token_expired')) ?></div>
    <?php endif; ?>
    <p class="muted small"><?= e(t('cal_graph_step1')) ?></p>
    <form method="post" action="calendars.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="graph_save">
      <div class="form-row">
        <div class="field">
          <label><?= e(t('cal_graph_tenant')) ?></label>
          <input type="text" name="graph_tenant" value="<?= e((string)setting('graph_tenant', 'common')) ?>" placeholder="common">
          <div class="hint"><?= e(t('cal_graph_tenant_hint')) ?></div>
        </div>
        <div class="field">
          <label><?= e(t('cal_graph_client')) ?></label>
          <input type="text" name="graph_client_id" value="<?= e((string)setting('graph_client_id', '')) ?>">
        </div>
      </div>
      <div class="form-row">
        <div class="field">
          <label><?= e(t('cal_graph_secret')) ?></label>
          <input type="password" name="graph_secret" value="" placeholder="••••••••" autocomplete="off">
        </div>
        <div class="field">
          <label><?= e(t('cal_graph_redirect')) ?></label>
          <input type="text" value="<?= e(graph()->redirectUri()) ?>" readonly class="kbd">
          <div class="hint" style="color:#b91c1c;"><?= e(t('cal_graph_redirect_hint')) ?></div>
        </div>
      </div>
      <div class="inline-flex">
        <button type="submit" class="btn btn-ghost"><?= e(t('save')) ?></button>
        <a class="btn btn-ok" href="graph_auth.php"><?= e(t('cal_graph_connect')) ?></a>
      </div>
    </form>
  <?php endif; ?>
</div>

<!-- ============ Add upload / URL / CalDAV ============ -->
<h2 class="section-title"><?= e(t('cal_add')) ?></h2>
<div class="grid grid-3 mb">
  <div class="card">
    <h3 class="section-title" style="margin-top:0;"><?= e(t('cal_add_upload')) ?></h3>
    <form method="post" action="calendars.php" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_upload">
      <div class="field">
        <label><?= e(t('cal_name')) ?></label>
        <input type="text" name="name" required maxlength="200">
      </div>
      <div class="field">
        <label><?= e(t('cal_upload_file')) ?></label>
        <input type="file" name="ics_file" accept=".ics,text/calendar" required>
      </div>
      <button type="submit" class="btn"><?= e(t('cal_add')) ?></button>
    </form>
  </div>
  <div class="card">
    <h3 class="section-title" style="margin-top:0;"><?= e(t('cal_add_url')) ?></h3>
    <form method="post" action="calendars.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_url">
      <div class="field">
        <label><?= e(t('cal_name')) ?></label>
        <input type="text" name="name" required maxlength="200">
      </div>
      <div class="field">
        <label><?= e(t('cal_url')) ?></label>
        <input type="url" name="url" placeholder="https://calendar.google.com/calendar/ical/…/basic.ics" required>
      </div>
      <button type="submit" class="btn"><?= e(t('cal_add')) ?></button>
    </form>
  </div>
  <div class="card">
    <h3 class="section-title" style="margin-top:0;"><?= e(t('cal_add_caldav')) ?></h3>
    <p class="muted small"><?= e(t('cal_caldav_hint')) ?></p>
    <?php if (!empty($pendingCaldav)): ?>
      <form method="post" action="calendars.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_caldav_confirm">
        <input type="hidden" name="name" value="<?= e($pendingCaldav['name']) ?>">
        <input type="hidden" name="caldav_server" value="<?= e($pendingCaldav['server']) ?>">
        <input type="hidden" name="caldav_username" value="<?= e($pendingCaldav['username']) ?>">
        <input type="hidden" name="caldav_password" value="<?= e($pendingCaldav['password']) ?>">
        <label class="field"><b><?= e(t('cal_caldav_pick')) ?></b></label>
        <?php foreach ($pendingCaldav['cals'] as $c): ?>
          <?php $key = base64_encode($c['href'] . "\x00" . $c['displayname']); ?>
          <label style="display:block;margin-bottom:8px;">
            <input type="checkbox" name="cals[]" value="<?= e($key) ?>" checked>
            <?= e($c['displayname']) ?>
          </label>
        <?php endforeach; ?>
        <button type="submit" class="btn"><?= e(t('cal_add')) ?></button>
      </form>
    <?php else: ?>
      <form method="post" action="calendars.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_caldav">
        <div class="field">
          <label><?= e(t('cal_name')) ?></label>
          <input type="text" name="name" maxlength="200">
        </div>
        <div class="field">
          <label><?= e(t('cal_caldav_server')) ?></label>
          <input type="text" name="caldav_server" placeholder="https://nextcloud.example.com/remote.php/dav/" required>
        </div>
        <div class="field">
          <label><?= e(t('cal_caldav_username')) ?></label>
          <input type="text" name="caldav_username" required autocomplete="off">
        </div>
        <div class="field">
          <label><?= e(t('cal_caldav_password')) ?></label>
          <input type="password" name="caldav_password" required autocomplete="new-password">
          <div class="hint"><?= e(t('cal_caldav_password_hint')) ?></div>
        </div>
        <button type="submit" class="btn"><?= e(t('cal_caldav_connect')) ?></button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php if (count($calendars) === 0): ?>
  <div class="card"><p class="muted"><?= e(t('cal_none')) ?></p></div>
<?php else: ?>
<h2 class="section-title"><?= e(t('cal_add')) ?> · <?= e(t('cal_manage_title')) ?></h2>
<div class="table-wrap">
<table class="data">
  <thead><tr>
    <th><?= e(t('cal_name')) ?></th><th><?= e(t('cal_type')) ?></th><th><?= e(t('cal_last_sync')) ?></th>
    <th><?= e(t('cal_show')) ?></th><th></th>
  </tr></thead>
  <tbody>
  <?php foreach ($calendars as $c): ?>
    <tr>
      <td><?= e($c['name']) ?></td>
      <td><span class="badge badge-type"><?= e(t('cal_type_' . $c['ctype'])) ?></span></td>
      <td>
        <?= $c['last_sync_at'] ? e(fmt_local($c['last_sync_at'])) : '<span class="muted">—</span>' ?>
        <?php if ($c['last_sync_error']): ?><br><span class="small" style="color:#b91c1c;"><?= e($c['last_sync_error']) ?></span><?php endif; ?>
      </td>
      <td>
        <form method="post" action="calendars.php" class="inline-flex">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="toggle">
          <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
          <input type="hidden" name="enabled" value="<?= $c['enabled'] ? 0 : 1 ?>">
          <button type="submit" class="btn btn-sm <?= $c['enabled'] ? 'btn-ok' : 'btn-ghost' ?>"><?= e(t($c['enabled'] ? 'enabled' : 'disabled')) ?></button>
        </form>
      </td>
      <td class="inline-flex">
        <form method="post" action="calendars.php" class="inline-flex">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="sync_one">
          <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
          <button type="submit" class="btn btn-sm"><?= e(t('cal_sync_now')) ?></button>
        </form>
        <form method="post" action="calendars.php" class="inline-flex" onsubmit="return confirm('<?= e(t('cal_deleted')) ?>?')">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete_calendar">
          <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
          <button type="submit" class="btn btn-bad btn-sm"><?= e(t('delete')) ?></button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<?php if ($graphConnected): ?>
<div class="card mt">
  <p class="small muted"><?= e(t('cal_warn_no_write')) ?></p>
</div>
<?php else: ?>
<div class="card mt">
  <p class="small muted"><?= e(t('cal_warn_readonly')) ?></p>
</div>
<?php endif; ?>

<script>
(function () {
  var A = window.Agenda;

  // sync all
  A.qs('#syncAllBtn').addEventListener('click', function () {
    var btn = A.qs('#syncAllBtn');
    var box = A.qs('#syncAllResult');
    btn.disabled = true;
    box.innerHTML = '<p class="muted small"><?= e(t('loading')) ?></p>';
    A.postJson('../ajax.php?action=sync', {}).then(function (res) {
      btn.disabled = false;
      box.innerHTML = res.ok
        ? '<div class="alert alert-ok">' + A.escapeHtml(res.message) + '</div>'
        : '<div class="alert alert-bad">' + A.escapeHtml(res.error || '<?= e(t('error')) ?>') + '</div>';
    }).catch(function (err) {
      btn.disabled = false;
      box.innerHTML = '<div class="alert alert-bad"><?= e(t('error')) ?>: ' + A.escapeHtml(err) + '</div>';
    });
  });

  // load graph calendars into picker
  var pickForm = A.qs('#graphPickForm');
  if (pickForm) {
    var box = A.qs('#graphCals');
    A.getJson('../ajax.php?action=graph_calendars').then(function (res) {
      if (!res.ok) {
        box.innerHTML = '<div class="alert alert-bad">' + A.escapeHtml(res.error || '<?= e(t('error')) ?>') + '</div>';
        return;
      }
      if (res.error) {
        box.innerHTML = '<div class="alert alert-bad">' + A.escapeHtml(res.error) + '</div>';
        return;
      }
      var bookingSel = A.qs('#graphBookingCal');
      var html = '';
      if (!res.cals.length) {
        box.innerHTML = '<p class="muted"><?= e(t('cal_graph_no_cal')) ?></p>';
        return;
      }
      res.cals.forEach(function (c) {
        var checked = (res.selected || []).indexOf(c.id) !== -1 ? ' checked' : '';
        var editable = c.canEdit ? '' : ' (' + <?= json_encode(t('disabled')) ?> + ')';
        html += '<label style="display:block;margin-bottom:8px;"><input type="checkbox" name="show_cals[]" value="' + A.escapeHtml(c.id) + '"' + checked + '> '
              + A.escapeHtml(c.name + editable) + '</label>';
      });
      box.innerHTML = html;
      bookingSel.innerHTML = '<option value="">—</option>';
      res.cals.forEach(function (c) {
        var o = document.createElement('option');
        o.value = c.id;
        o.textContent = c.name;
        if (c.id === res.booking) o.selected = true;
        bookingSel.appendChild(o);
      });
    }).catch(function (err) {
      box.innerHTML = '<div class="alert alert-bad"><?= e(t('error')) ?>: ' + A.escapeHtml(err) + '</div>';
    });
  }
})();
</script>
<?php include __DIR__ . '/_bottom.php'; ?>
