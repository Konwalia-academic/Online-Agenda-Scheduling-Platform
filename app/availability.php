<?php
/*
 * Availability engine: working hours, free-slot generation and busy-period
 * lookup. All times stored/compared in UTC; working hours are local.
 */
declare(strict_types=1);

/** Working-hours config: weekday (0=Sun..6=Sat) => list of ['s'=>H:i,'e'=>H:i] blocks. */
function working_hours(): array
{
    $raw = setting('working_hours');
    if ($raw) {
        $d = json_decode((string)$raw, true);
        if (is_array($d)) {
            return $d;
        }
    }
    $default = [];
    for ($i = 0; $i < 7; $i++) {
        $default[$i] = ($i >= 1 && $i <= 5) ? [['s' => '09:00', 'e' => '18:00']] : [];
    }
    return $default;
}

/** Working blocks (local) for a given date 'Y-m-d'. */
function day_blocks_for(string $day): array
{
    $d = new DateTime($day . ' 12:00:00', app_timezone());
    $w = (int)$d->format('w');
    $blocks = working_hours();
    return $blocks[$w] ?? [];
}

function is_working_day(string $day): bool
{
    return count(day_blocks_for($day)) > 0;
}

/** Next $n days (starting today) as 'Y-m-d' list. */
function next_booking_days(int $n): array
{
    $days = [];
    $tz = app_timezone();
    $today = new DateTime('now', $tz);
    for ($i = 0; $i < $n; $i++) {
        $days[] = $today->format('Y-m-d');
        $today->modify('+1 day');
    }
    return $days;
}

function max_advance_days(): int
{
    return max(1, (int)setting('max_advance', 60));
}

function is_valid_booking_day(string $day): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
        return false;
    }
    $tz = app_timezone();
    $today = (new DateTime('now', $tz))->setTime(0, 0, 0);
    $target = new DateTime($day . ' 00:00:00', $tz);
    if ($target < $today) {
        return false;
    }
    $max = $today->modify('+' . max_advance_days() . ' days');
    return $target <= $max;
}

/**
 * Busy periods (UTC timestamps) overlapping [from,to] (UTC strings).
 * Includes cached external events (ignoring our own bookings that were pushed
 * to Outlook) plus pending/approved bookings. Buffer (min) is appended to the
 * end of each busy period so the next meeting starts after it.
 * @return array<int,array{0:int,1:int}> pairs of [startTs, endTs]
 */
function get_busy_timestamps(string $fromUtc, string $toUtc, int $bufferMin = 0): array
{
    $pdo = db();
    $sql = "SELECT start_utc AS s, end_utc AS e FROM events_cache
            WHERE end_utc > ? AND start_utc < ? AND uid NOT LIKE 'booking-%'
            UNION
            SELECT start_utc AS s, end_utc AS e FROM bookings
            WHERE status IN ('pending','approved') AND end_utc > ? AND start_utc < ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$fromUtc, $toUtc, $fromUtc, $toUtc]);
    $busy = [];
    foreach ($stmt->fetchAll() as $row) {
        $s = strtotime($row['s'] . ' UTC');
        $e = strtotime($row['e'] . ' UTC');
        if ($s === false || $e === false) {
            continue;
        }
        $busy[] = [$s, $e + $bufferMin * 60];
    }
    return $busy;
}

function slot_free_by_ts(array $busy, int $startTs, int $endTs): bool
{
    foreach ($busy as $b) {
        if ($b[0] < $endTs && $b[1] > $startTs) {
            return false;
        }
    }
    return true;
}

/** Available local start times 'H:i' on a given date for a duration (min). */
function available_start_times(string $day, int $duration): array
{
    if (!is_valid_booking_day($day)) {
        return [];
    }
    $tz = app_timezone();
    $step = max(1, (int)setting('slot_step', 15));
    $buffer = max(0, (int)setting('buffer_min', 0));

    $busyFrom = local_to_utc($day . ' 00:00', 'Y-m-d H:i');
    $busyTo = local_to_utc($day . ' 23:59', 'Y-m-d H:i');
    $busy = get_busy_timestamps($busyFrom, $busyTo, $buffer);

    $out = [];
    foreach (day_blocks_for($day) as $block) {
        $start = DateTime::createFromFormat('Y-m-d H:i', $day . ' ' . $block['s'], $tz);
        $limit = DateTime::createFromFormat('Y-m-d H:i', $day . ' ' . $block['e'], $tz);
        if ($start === false || $limit === false) {
            continue;
        }
        while (($start->getTimestamp() + $duration * 60) <= $limit->getTimestamp()) {
            $sTs = $start->getTimestamp();
            if (slot_free_by_ts($busy, $sTs, $sTs + $duration * 60)) {
                $out[] = $start->format('H:i');
            }
            $start->modify('+' . $step . ' minutes');
        }
    }
    return $out;
}

/** True if [startLocal,endLocal] ('Y-m-d H:i') has no conflicts. */
function is_slot_free_full(string $startLocal, string $endLocal): bool
{
    $startUtc = local_to_utc($startLocal, 'Y-m-d H:i');
    $endUtc = local_to_utc($endLocal, 'Y-m-d H:i');
    $day = substr($startLocal, 0, 10);
    $buffer = max(0, (int)setting('buffer_min', 0));
    $busy = get_busy_timestamps($startUtc, $endUtc, $buffer);
    return slot_free_by_ts($busy, strtotime($startUtc . ' UTC'), strtotime($endUtc . ' UTC'));
}

/**
 * Events for calendar display between two UTC strings.
 * @return array<int,array{start:string,end:string,title:string,location:string,type:string,all_day:bool}>
 */
function week_events(string $fromUtc, string $toUtc, bool $withDetails): array
{
    $pdo = db();
    $out = [];
    $stmt = $pdo->prepare(
        "SELECT 'calendar' AS type, start_utc AS s, end_utc AS e, title, location, all_day FROM events_cache
         WHERE end_utc > ? AND start_utc < ? AND uid NOT LIKE 'booking-%'
         UNION ALL
         SELECT 'booking' AS type, start_utc AS s, end_utc AS e, theme AS title, '' AS location, 0 AS all_day FROM bookings
         WHERE status IN ('pending','approved') AND end_utc > ? AND start_utc < ?"
    );
    $stmt->execute([$fromUtc, $toUtc, $fromUtc, $toUtc]);
    foreach ($stmt->fetchAll() as $row) {
        $out[] = [
            'start' => $row['s'],
            'end' => $row['e'],
            'title' => $withDetails && $row['title'] !== '' ? $row['title'] : t('cal_busy'),
            'location' => $withDetails ? $row['location'] : '',
            'type' => $row['type'],
            'all_day' => (bool)$row['all_day'],
        ];
    }
    return $out;
}

/** Duration options in minutes allowed for visitors (presets + range). */
function duration_options(): array
{
    $raw = setting('duration_options', '15,30,45,60,90,120');
    $presets = [];
    foreach (explode(',', (string)$raw) as $p) {
        $p = (int)trim($p);
        if ($p > 0) {
            $presets[$p] = true;
        }
    }
    $min = (int)setting('min_duration', 15);
    $max = (int)setting('max_duration', 360);
    $step = max(1, (int)setting('slot_step', 15));
    for ($d = $min; $d <= $max; $d += $step) {
        $presets[$d] = true;
    }
    $list = array_keys($presets);
    sort($list);
    return $list;
}
