<?php
require __DIR__ . '/../app/bootstrap.php';

$pageTitle = t('calendar_view');
$activeNav = 'calendar';

$tz = app_timezone();
$weekStartParam = $_GET['w'] ?? '';
if ($weekStartParam !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekStartParam)) {
    $monday = new DateTime($weekStartParam . ' 00:00:00', $tz);
    $monday->setISODate((int)$monday->format('o'), (int)$monday->format('W'), 1);
} else {
    $monday = new DateTime('now', $tz);
    $monday->setISODate((int)$monday->format('o'), (int)$monday->format('W'), 1);
    $monday->setTime(0, 0, 0);
}

$sunday = clone $monday;
$sunday->modify('+7 days');

$fromUtc = $monday->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$toUtc = $sunday->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

$unlocked = !empty($_SESSION['cal_unlocked']);
$events = week_events($fromUtc, $toUtc, $unlocked);

// index events by weekday (0=Mon..6=Sun relative to Monday)
$byDay = [];
foreach ($events as $ev) {
    $startLocal = utc_to_local($ev['start']);
    $endLocal = utc_to_local($ev['end']);
    // which local day column does it belong to
    $dowIdx = (int)$startLocal->format('N') - 1; // Mon=0
    if ($dowIdx < 0) {
        $dowIdx = 6;
    }
    $byDay[$dowIdx][] = [
        's' => $startLocal->format('H') + ($startLocal->format('i') / 60),
        'e' => min(24, ($endLocal->format('H') + ($endLocal->format('i') / 60))),
        'title' => $ev['title'],
        'location' => $ev['location'],
        'type' => $ev['type'],
        'all_day' => $ev['all_day'],
    ];
}

$prevWeek = (clone $monday)->modify('-7 days')->format('Y-m-d');
$nextWeek = (clone $monday)->modify('+7 days')->format('Y-m-d');
$todayStr = (new DateTime('now', $tz))->format('Y-m-d');

$dowNames = current_lang() === 'zh'
    ? ['周一', '周二', '周三', '周四', '周五', '周六', '周日']
    : ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

include __DIR__ . '/_top.php';
?>
<div class="hero" style="padding-top:10px;">
  <h1 class="page-title"><?= e(t('cal_title')) ?></h1>
  <p><?= e(t('cal_subtitle')) ?></p>
</div>

<div class="card">
  <div class="cal-nav">
    <a class="btn btn-ghost btn-sm" href="?w=<?= e($prevWeek) ?>">‹ <?= e(t('cal_prev')) ?></a>
    <a class="btn btn-ghost btn-sm" href="calendar.php"><?= e(t('cal_today')) ?></a>
    <a class="btn btn-ghost btn-sm" href="?w=<?= e($nextWeek) ?>"><?= e(t('cal_next')) ?> ›</a>
    <span class="spacer"></span>
    <span class="muted small"><?= e($monday->format('Y-m-d')) ?> — <?= e((clone $monday)->modify('+6 days')->format('Y-m-d')) ?></span>
    <?php if ($unlocked): ?>
      <button type="button" class="btn btn-sm" id="revealBtn"><?= e(t('cal_hide_details')) ?></button>
    <?php else: ?>
      <button type="button" class="btn btn-sm" id="revealBtn"><?= e(t('cal_show_details')) ?></button>
    <?php endif; ?>
  </div>

  <div id="inviteBox" class="hidden" style="max-width:420px;margin-bottom:14px;">
    <form class="form-row" id="inviteForm">
      <div class="field" style="margin:0;">
        <input type="text" id="inviteCode" placeholder="<?= e(t('cal_invite_code')) ?>" autocomplete="off">
      </div>
      <button type="submit" class="btn"><?= e(t('confirm')) ?></button>
    </form>
  </div>

  <div class="cal-wrap">
    <table class="cal-week" id="calTable" data-week="<?= e($monday->format('Y-m-d')) ?>">
      <thead>
        <tr>
          <th style="width:52px;"></th>
          <?php for ($i = 0; $i < 7; $i++):
              $d = (clone $monday)->modify("+{$i} days");
              $isToday = $d->format('Y-m-d') === $todayStr; ?>
          <th class="<?= $isToday ? 'today' : '' ?>"><?= e($dowNames[$i]) ?> <?= e($d->format('d')) ?></th>
          <?php endfor; ?>
        </tr>
      </thead>
      <tbody>
        <?php for ($h = 0; $h < 24; $h++): ?>
        <tr>
          <td class="hour-label"><?= sprintf('%02d:00', $h) ?></td>
          <?php for ($i = 0; $i < 7; $i++): ?>
          <td data-day="<?= $i ?>" data-hour="<?= $h ?>"></td>
          <?php endfor; ?>
        </tr>
        <?php endfor; ?>
      </tbody>
    </table>
  </div>

  <p class="muted small center mt"><?= e(t('cal_book')) ?> <a href="<?= e(base_url()) ?>/book.php"><?= e(t('book')) ?></a></p>
</div>

<script>
(function () {
  var A = window.Agenda;
  var unlocked = <?= $unlocked ? 'true' : 'false' ?>;
  var events = <?= json_encode($byDay, JSON_UNESCAPED_UNICODE) ?>;

  function hourY(hour, cellH) {
    var ROW_H = 44;
    var h0 = Math.floor(hour);
    var frac = hour - h0;
    return (h0 * ROW_H) + (frac * ROW_H) + 3;
  }

  function render(evs) {
    var table = A.qs('#calTable');
    A.qsa('#calTable td[data-day]').forEach(function (td) {
      td.innerHTML = '';
    });
    Object.keys(evs).forEach(function (dayIdx) {
      var list = evs[dayIdx];
      list.forEach(function (ev) {
        var td = A.qs('#calTable td[data-day="' + dayIdx + '"][data-hour="' + Math.floor(ev.s) + '"]');
        if (!td) return;
        var cls = 'cal-block';
        if (ev.type === 'booking') cls += ' booking';
        else if (unlocked) cls += ' has-detail';
        var txt = unlocked ? (ev.title) : '<?= e(t('cal_busy')) ?>';
        if (unlocked && ev.location) txt += ' · ' + ev.location;
        var div = document.createElement('div');
        div.className = cls;
        div.textContent = txt;
        div.style.position = 'absolute';
        var rowH = 44;
        var y = hourY(ev.s, rowH);
        var h = Math.max(18, Math.round((ev.e - ev.s) * rowH - 6));
        div.style.top = y + 'px';
        div.style.left = '3px';
        div.style.right = '3px';
        div.style.height = h + 'px';
        td.style.position = 'relative';
        td.appendChild(div);
      });
    });
  }

  var revealBtn = A.qs('#revealBtn');
  var inviteBox = A.qs('#inviteBox');

  revealBtn.addEventListener('click', function () {
    if (unlocked) {
      A.postJson('ajax.php?action=reveal', { lock: '1' }).then(function () {
        window.location.reload();
      });
      return;
    }
    inviteBox.classList.toggle('hidden');
  });

  A.qs('#inviteForm').addEventListener('submit', function (ev) {
    ev.preventDefault();
    var code = A.qs('#inviteCode').value;
    A.postJson('ajax.php?action=reveal', { code: code }).then(function (res) {
      if (res.ok) {
        window.location.reload();
      } else {
        alert(A.escapeHtml(res.error || '<?= e(t('cal_invite_bad')) ?>'));
      }
    });
  });

  render(events);
})();
</script>
<?php include __DIR__ . '/_bottom.php'; ?>
