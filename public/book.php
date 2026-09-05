<?php
require __DIR__ . '/../app/bootstrap.php';

// ---- handle cancellation via emailed link ----
if (isset($_GET['cancel']) && $_GET['cancel'] !== '') {
    $b = fetch_booking_by_invite((string)$_GET['cancel']);
    if ($b && $b['status'] === 'pending') {
        set_booking_status((int)$b['id'], 'cancelled');
        notify_admin_cancelled($b);
    }
    $pageTitle = t('book_cancelled_title');
    $activeNav = 'book';
    include __DIR__ . '/_top.php';
    ?>
    <div class="card center" style="max-width:480px;margin:40px auto;">
      <h1 class="page-title"><?= e(t('book_cancelled_title')) ?></h1>
      <p><?= e(t('book_cancelled_msg')) ?></p>
      <a class="btn" href="<?= e(base_url()) ?>/book.php"><?= e(t('book')) ?></a>
    </div>
    <?php
    include __DIR__ . '/_bottom.php';
    exit;
}

// ---- handle submitted booking ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $err = null;
    try {
        $in = [
            'day' => trim((string)($_POST['day'] ?? '')),
            'time' => trim((string)($_POST['time'] ?? '')),
            'duration' => (int)($_POST['duration'] ?? 0),
            'name' => trim((string)($_POST['name'] ?? '')),
            'email' => trim((string)($_POST['email'] ?? '')),
            'phone' => trim((string)($_POST['phone'] ?? '')),
            'theme' => trim((string)($_POST['theme'] ?? '')),
            'location_type' => (string)($_POST['location_type'] ?? ''),
            'location_detail' => trim((string)($_POST['location_detail'] ?? '')),
            'attendees' => trim((string)($_POST['attendees'] ?? '')),
            'appendix' => trim((string)($_POST['appendix'] ?? '')),
        ];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['day']) || !is_valid_booking_day($in['day'])) {
            throw new RuntimeException(t('book_invalid_day'));
        }
        if (!preg_match('/^\d{2}:\d{2}$/', $in['time'])) {
            throw new RuntimeException(t('book_invalid_slot'));
        }
        $allowed = duration_options();
        if (!in_array($in['duration'], $allowed, true)) {
            throw new RuntimeException(t('book_err_duration'));
        }
        if ($in['name'] === '' || $in['theme'] === '') {
            throw new RuntimeException(t('book_err_required'));
        }
        if (!is_valid_email($in['email'])) {
            throw new RuntimeException(t('book_err_email'));
        }
        if ($in['phone'] === '') {
            throw new RuntimeException(t('book_err_phone'));
        }
        $inLoc = ['zoom', 'tencent', 'phone', 'inperson'];
        if (!in_array($in['location_type'], $inLoc, true)) {
            throw new RuntimeException(t('book_err_required'));
        }
        // validate optional attendees contain valid emails only
        if ($in['attendees'] !== '') {
            $bad = array_filter(explode(',', preg_replace('/[\s,;]+/', ',', $in['attendees'])), fn($a) => $a !== '' && !is_valid_email($a));
            if (count($bad) > 0) {
                throw new RuntimeException(t('book_err_email'));
            }
        }
        $res = create_booking($in);
        redirect(base_url() . '/book.php?done=' . $res['id']);
    } catch (Throwable $ex) {
        $err = $ex->getMessage();
    }
}

// ---- success view ----
if (isset($_GET['done'])) {
    $b = fetch_booking((int)$_GET['done']);
    $pageTitle = t('book_success_title');
    $activeNav = 'book';
    include __DIR__ . '/_top.php';
    ?>
    <div class="card center" style="max-width:520px;margin:40px auto;">
      <h1 class="page-title"><?= e(t('book_success_title')) ?></h1>
      <?php if ($err ?? null): ?>
        <div class="alert alert-bad"><?= e($err) ?></div>
      <?php endif; ?>
      <?php if ($b): ?>
        <p><?= t('book_success_msg', ['email' => e($b['email'])]) ?></p>
        <p class="muted small"><?= e(t('book_success_ref')) ?>: <span class="kbd">#<?= (int)$b['id'] ?></span></p>
        <p class="muted small"><?= e(fmt_local($b['start_utc'])) ?> · <?= e($b['theme']) ?></p>
        <?php if ($b['status'] === 'pending'): ?>
          <a class="btn btn-bad" href="<?= e(base_url()) ?>/book.php?cancel=<?= e($b['invite_token']) ?>"><?= e(t('book_cancel_link')) ?></a>
        <?php endif; ?>
      <?php endif; ?>
      <div class="mt"><a class="btn btn-ghost" href="<?= e(base_url()) ?>/"><?= e(t('back_to_home')) ?></a></div>
    </div>
    <?php
    include __DIR__ . '/_bottom.php';
    exit;
}

// ---- booking flow view ----
$pageTitle = t('book_title');
$activeNav = 'book';
$days = next_booking_days(max_advance_days());
$durationOptions = duration_options();
$selectedDay = isset($_GET['day']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['day']) ? (string)$_GET['day'] : '';
$selectedDur = (int)($_GET['dur'] ?? 0);
if ($selectedDur && !in_array($selectedDur, $durationOptions, true)) {
    $selectedDur = 0;
}

include __DIR__ . '/_top.php';
?>
<div class="hero">
  <h1 class="page-title"><?= e(t('book_title')) ?></h1>
  <p><?= e(t('book_subtitle')) ?></p>
</div>

<?php if (isset($err)): ?>
  <div class="alert alert-bad"><?= e($err) ?></div>
<?php endif; ?>

<div class="grid grid-2">
  <div>
    <h2 class="section-title"><?= e(t('book_pick_day')) ?></h2>
    <div class="day-strip" id="dayStrip">
      <?php
      $tz = app_timezone();
      $today = new DateTime('now', $tz);
      $dowShort = current_lang() === 'zh' ? ['日', '一', '二', '三', '四', '五', '六'] : ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
      foreach ($days as $day):
          $dt = new DateTime($day . ' 12:00:00', $tz);
          $isWork = is_working_day($day);
          $isSel = $day === $selectedDay;
          $isToday = $day === $today->format('Y-m-d');
      ?>
      <div class="day-card<?= !$isWork ? ' is-off' : '' ?><?= $isSel ? ' is-selected' : '' ?>"
           data-day="<?= e($day) ?>">
        <div class="dow"><?= $isToday ? e(t('cal_today')) : e($dowShort[(int)$dt->format('w')]) ?></div>
        <div class="dnum"><?= e($dt->format('d')) ?></div>
        <div class="dstate"><?= $isWork ? '·' : '' ?></div>
      </div>
      <?php endforeach; ?>
    </div>

    <h2 class="section-title"><?= e(t('book_pick_duration')) ?></h2>
    <div class="slot-grid" id="durationGrid">
      <?php foreach ($durationOptions as $d): ?>
        <button type="button" class="slot-btn<?= $d === $selectedDur ? ' is-selected' : '' ?>" data-dur="<?= (int)$d ?>"><?= e(human_minutes($d)) ?></button>
      <?php endforeach; ?>
    </div>

    <h2 class="section-title"><?= e(t('book_pick_time')) ?></h2>
    <div id="slotsArea">
      <p class="muted small"><?= e(t('loading')) ?></p>
    </div>
  </div>

  <div>
    <div class="card form-card" id="bookingFormCard">
      <form method="post" action="<?= e(base_url()) ?>/book.php" id="bookingForm" class="hidden">
        <?= csrf_field() ?>
        <h2 class="section-title" style="margin-top:0;"><?= e(t('book_fill_form')) ?></h2>
        <p class="small muted"><?= e(t('book_required_hint')) ?></p>

        <div id="selectedSlotBox" class="alert alert-info hidden">
          <b><?= e(t('book_selected')) ?>:</b>
          <span id="selectedSlotLabel"></span>
          <a href="#" id="changeTimeLink" class="small"><?= e(t('book_change_time')) ?></a>
        </div>

        <input type="hidden" name="day" id="f_day">
        <input type="hidden" name="time" id="f_time">
        <input type="hidden" name="duration" id="f_duration">

        <div class="field">
          <label><?= e(t('book_name')) ?> <span class="req">*</span></label>
          <input type="text" name="name" required maxlength="200">
        </div>
        <div class="form-row">
          <div class="field">
            <label><?= e(t('book_email')) ?> <span class="req">*</span></label>
            <input type="email" name="email" required maxlength="255">
          </div>
          <div class="field">
            <label><?= e(t('book_phone')) ?> <span class="req">*</span></label>
            <input type="text" name="phone" required maxlength="50">
          </div>
        </div>
        <div class="field">
          <label><?= e(t('book_theme')) ?> <span class="req">*</span></label>
          <input type="text" name="theme" required maxlength="500">
        </div>

        <div class="field">
          <label><?= e(t('book_location_type')) ?> <span class="req">*</span></label>
          <div class="radio-pills">
            <?php foreach (['zoom', 'tencent', 'phone', 'inperson'] as $lt): ?>
            <label class="radio-pill">
              <input type="radio" name="location_type" value="<?= e($lt) ?>" <?= $lt === 'zoom' ? 'checked' : '' ?>>
              <span class="pill"><?= e(t('loc_' . $lt)) ?></span>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="field">
          <label id="locDetailLabel"><?= e(t('loc_zoom_detail')) ?></label>
          <input type="text" name="location_detail" id="locDetail" maxlength="500">
        </div>

        <div class="field">
          <label><?= e(t('book_attendees')) ?> <span class="muted small">(<?= e(t('optional')) ?>)</span></label>
          <textarea name="attendees" placeholder="<?= e(t('book_attendees_hint')) ?>"></textarea>
        </div>
        <div class="field">
          <label><?= e(t('book_appendix')) ?> <span class="muted small">(<?= e(t('optional')) ?>)</span></label>
          <textarea name="appendix" placeholder="<?= e(t('book_appendix_hint')) ?>"></textarea>
        </div>

        <p class="small muted"><?= e(t('book_agree')) ?></p>
        <button type="submit" class="btn btn-block"><?= e(t('book_submit')) ?></button>
      </form>
    </div>
  </div>
</div>

<script>
(function () {
  var A = window.Agenda;
  var selectedDay = <?= $selectedDay !== '' ? json_encode($selectedDay) : 'null' ?>;
  var selectedDur = <?= (int)$selectedDur ?>;
  var slotsArea = A.qs('#slotsArea');
  var form = A.qs('#bookingForm');
  var slotBox = A.qs('#selectedSlotBox');
  var slotLabel = A.qs('#selectedSlotLabel');
  var changeLink = A.qs('#changeTimeLink');
  var locDetail = A.qs('#locDetail');
  var locDetailLabel = A.qs('#locDetailLabel');

  var locLabels = {
    zoom: <?= json_encode(t('loc_zoom_detail')) ?>,
    tencent: <?= json_encode(t('loc_tencent_detail')) ?>,
    phone: <?= json_encode(t('loc_phone_detail')) ?>,
    inperson: <?= json_encode(t('loc_inperson_detail')) ?>
  };

  function pickDay(day) {
    A.qsa('.day-card').forEach(function (c) { c.classList.remove('is-selected'); });
    var card = A.qs('.day-card[data-day="' + day + '"]');
    if (card) card.classList.add('is-selected');
    selectedDay = day;
    loadSlots();
  }

  function pickDur(dur) {
    A.qsa('#durationGrid .slot-btn').forEach(function (b) { b.classList.remove('is-selected'); });
    var btn = A.qs('#durationGrid .slot-btn[data-dur="' + dur + '"]');
    if (btn) btn.classList.add('is-selected');
    selectedDur = dur;
    loadSlots();
  }

  function loadSlots() {
    form.classList.add('hidden');
    if (!selectedDay || !selectedDur) {
      slotsArea.innerHTML = '<p class="muted small"><?= e(t('book_no_times')) ?></p>';
      return;
    }
    slotsArea.innerHTML = '<p class="muted small"><?= e(t('loading')) ?></p>';
    A.getJson('ajax.php?action=slots&day=' + encodeURIComponent(selectedDay) + '&dur=' + selectedDur)
      .then(function (res) {
        if (!res.ok) { slotsArea.innerHTML = '<p class="alert alert-bad">' + A.escapeHtml(res.error || '<?= e(t('error')) ?>') + '</p>'; return; }
        var times = res.times || [];
        if (!times.length) {
          slotsArea.innerHTML = '<p class="muted small"><?= e(t('book_no_times')) ?></p>';
          return;
        }
        var html = '<div class="slot-grid">';
        times.forEach(function (tm) {
          html += '<button type="button" class="slot-btn" data-time="' + tm + '">' + tm + '</button>';
        });
        html += '</div>';
        slotsArea.innerHTML = html;
        A.qsa('#slotsArea .slot-btn').forEach(function (b) {
          b.addEventListener('click', function () { pickTime(b.getAttribute('data-time')); });
        });
      });
  }

  function pickTime(tm) {
    A.qsa('#slotsArea .slot-btn').forEach(function (b) { b.classList.remove('is-selected'); });
    var btn = A.qs('#slotsArea .slot-btn[data-time="' + tm + '"]');
    if (btn) btn.classList.add('is-selected');
    A.qs('#f_day').value = selectedDay;
    A.qs('#f_time').value = tm;
    A.qs('#f_duration').value = selectedDur;
    var d = new Date(selectedDay + 'T' + tm);
    var label = selectedDay + ' ' + tm + ' (' + humanMinutes(selectedDur) + ')';
    slotLabel.textContent = label;
    slotBox.classList.remove('hidden');
    form.classList.remove('hidden');
    form.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function humanMinutes(m) {
    var h = Math.floor(m / 60), mm = m % 60;
    return (h ? h + 'h' : '') + (mm ? ' ' + mm + 'm' : '');
  }

  changeLink.addEventListener('click', function (ev) {
    ev.preventDefault();
    slotBox.classList.add('hidden');
    form.classList.add('hidden');
  });

  A.qsa('.day-card').forEach(function (c) {
    if (c.classList.contains('is-off')) return;
    c.addEventListener('click', function () { pickDay(c.getAttribute('data-day')); });
  });
  A.qsa('#durationGrid .slot-btn').forEach(function (b) {
    b.addEventListener('click', function () { pickDur(parseInt(b.getAttribute('data-dur'), 10)); });
  });

  // location detail label
  A.qsa('input[name="location_type"]').forEach(function (r) {
    r.addEventListener('change', function () {
      locDetailLabel.textContent = locLabels[r.value];
      locDetail.placeholder = locLabels[r.value];
    });
  });
  locDetailLabel.textContent = locLabels.zoom;

  if (selectedDay && selectedDur) {
    pickDay(selectedDay);
    pickDur(selectedDur);
    loadSlots();
  } else {
    slotsArea.innerHTML = '<p class="muted small"><?= e(t('book_no_times')) ?></p>';
  }
})();
</script>
<?php include __DIR__ . '/_bottom.php'; ?>
