<?php
/*
 * Calendar sync engine: pulls events from .ics uploads, iCal URLs and the
 * connected Microsoft Graph account into the local events_cache table.
 * Used by scripts/sync.php (cron) and by "Sync now" in the admin panel.
 */
declare(strict_types=1);

function sync_window(): array
{
    $from = new DateTimeImmutable('-60 days', new DateTimeZone('UTC'));
    $to = new DateTimeImmutable('+180 days', new DateTimeZone('UTC'));
    return [$from, $to];
}

/** Replace the cached rows for one calendar. */
function replace_calendar_cache(int $calId, array $rows): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $del = $pdo->prepare('DELETE FROM events_cache WHERE calendar_id = ?');
        $del->execute([$calId]);
        $ins = $pdo->prepare(
            'INSERT INTO events_cache (calendar_id, uid, title, location, start_utc, end_utc, all_day)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($rows as $r) {
            $ins->execute([
                $calId,
                $r['uid'],
                mb_substr($r['title'], 0, 500),
                mb_substr($r['location'], 0, 500),
                $r['start'],
                $r['end'],
                $r['all_day'] ? 1 : 0,
            ]);
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $ex;
    }
}

/** Parse ICS text into cache rows within the sync window. */
function ics_text_to_rows(string $ics): array
{
    [$winStart, $winEnd] = sync_window();
    $events = IcsParser::parse($ics);
    $rows = [];
    foreach ($events as $ev) {
        foreach ($ev->expandInto($winStart, $winEnd) as $i => $occ) {
            $uid = $ev->uid . '#' . $occ[0];
            if ($i > 0) {
                $uid .= '#' . $i;
            }
            $rows[] = [
                'uid' => $uid,
                'title' => $ev->summary,
                'location' => $ev->location,
                'start' => $occ[0],
                'end' => $occ[1],
                'all_day' => $ev->allDay,
            ];
        }
    }
    return $rows;
}

/** Sync a single calendar row. Returns ['ok'=>bool,'count'=>int,'error'=>string]. */
function sync_calendar_row(array $row): array
{
    $result = ['ok' => false, 'count' => 0, 'error' => ''];
    try {
        $rows = [];
        switch ($row['ctype']) {
            case 'upload':
                $cfg = json_decode((string)$row['config'], true) ?: [];
                $file = STORAGE . '/uploads/' . basename((string)($cfg['file'] ?? ''));
                if (!is_file($file)) {
                    throw new RuntimeException('Uploaded file is missing.');
                }
                $rows = ics_text_to_rows((string)file_get_contents($file));
                break;

            case 'url':
                $cfg = json_decode((string)$row['config'], true) ?: [];
                $url = (string)($cfg['url'] ?? '');
                if ($url === '' || !preg_match('#^https?://#i', $url)) {
                    throw new RuntimeException('Invalid iCal URL.');
                }
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TIMEOUT => 60,
                    CURLOPT_CONNECTTIMEOUT => 15,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_USERAGENT => 'Agenda-Platform/1.0',
                ]);
                $content = curl_exec($ch);
                $err = curl_error($ch);
                $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                curl_close($ch);
                if ($content === false) {
                    throw new RuntimeException('Fetch failed: ' . $err);
                }
                if ($status >= 400) {
                    throw new RuntimeException('Fetch failed (HTTP ' . $status . ').');
                }
                $rows = ics_text_to_rows($content);
                break;

            case 'graph':
                $rows = graph_rows();
                break;

            case 'caldav':
                $rows = caldav_rows(json_decode((string)$row['config'], true) ?: []);
                break;

            default:
                throw new RuntimeException('Unknown calendar type.');
        }
        replace_calendar_cache((int)$row['id'], $rows);
        $result['ok'] = true;
        $result['count'] = count($rows);
    } catch (Throwable $ex) {
        $result['error'] = $ex->getMessage();
    }

    $pdo = db();
    $stmt = $pdo->prepare('UPDATE calendars SET last_sync_at = ?, last_sync_error = ? WHERE id = ?');
    $stmt->execute([utc_now(), $result['error'], (int)$row['id']]);
    return $result;
}

/** Fetch + cache rows for a CalDAV calendar (read-only). */
function caldav_rows(array $config): array
{
    $server = (string)($config['server'] ?? '');
    $username = (string)($config['username'] ?? '');
    $passEnc = (string)($config['password_enc'] ?? '');
    $href = (string)($config['calendar_href'] ?? '');
    if ($server === '' || $href === '' || $username === '') {
        throw new RuntimeException('CalDAV configuration is incomplete.');
    }
    $password = decrypt_value($passEnc) ?? '';
    if ($password === '') {
        throw new RuntimeException('CalDAV password is missing or could not be decrypted.');
    }
    $client = new CalDavClient($server, $username, $password);
    [$winStart, $winEnd] = sync_window();
    $ics = $client->fetchEvents($href, $winStart, $winEnd);
    return ics_text_to_rows($ics);
}

/** Rows for all selected Outlook sub-calendars (via Graph). */
function graph_rows(): array
{
    $selected = json_decode((string)setting('graph_calendars', '[]'), true);
    if (!is_array($selected) || count($selected) === 0) {
        return [];
    }
    [$winStart, $winEnd] = sync_window();
    $rows = [];
    foreach ($selected as $calId) {
        $res = graph()->calendarView(
            (string)$calId,
            $winStart->format('c'),
            $winEnd->format('c')
        );
        if (!$res['ok']) {
            $msg = $res['data']['error']['message'] ?? 'Graph error';
            throw new RuntimeException('Graph calendar sync failed: ' . $msg);
        }
        foreach ($res['data']['value'] ?? [] as $ev) {
            $rows[] = graph_event_to_row($ev);
        }
    }
    return $rows;
}

/** Convert a Graph event to a cache row. */
function graph_event_to_row(array $ev): array
{
    $uid = $ev['iCalUId'] ?? ($ev['id'] ?? bin2hex(random_bytes(8)));
    $title = (string)($ev['subject'] ?? '');
    // Detect events this platform created for approved bookings and map their
    // UID to "booking-<id>@agenda" so the availability engine's exclusion
    // (uid NOT LIKE 'booking-%') prevents double-counting after sync.
    if (preg_match('/\[[^\]]*#(\d+)\]/', $title, $m)) {
        $uid = 'booking-' . $m[1] . '@agenda';
        $title = trim((string)preg_replace('/\[[^\]]*#\d+\]\s*/', '', $title));
    }
    $isAllDay = !empty($ev['isAllDay']);
    $start = graph_dt_to_utc($ev['start'] ?? []);
    $end = graph_dt_to_utc($ev['end'] ?? []);
    if ($start === null || $end === null) {
        $start = new DateTime('now', new DateTimeZone('UTC'));
        $end = (clone $start)->modify('+1 hour');
    }
    if ($isAllDay) {
        // graph returns date-only; make end exclusive
        $s = DateTime::createFromFormat('Y-m-d', $start->format('Y-m-d'), new DateTimeZone('UTC'));
        $e = (clone $s)->modify('+1 day');
        $start = $s ?: $start;
        $end = $e ?: $end;
    }
    $loc = '';
    if (!empty($ev['location']['displayName'])) {
        $loc = (string)$ev['location']['displayName'];
    }
    return [
        'uid' => (string)$uid,
        'title' => $title,
        'location' => $loc,
        'start' => $start->format('Y-m-d H:i:s'),
        'end' => $end->format('Y-m-d H:i:s'),
        'all_day' => $isAllDay,
    ];
}

/** Parse Graph's dateTime+timeZone pair into a UTC DateTime. */
function graph_dt_to_utc(?array $dt): ?DateTime
{
    if (!$dt || empty($dt['dateTime'])) {
        return null;
    }
    $val = (string)$dt['dateTime'];
    $tz = (string)($dt['timeZone'] ?? 'UTC');
    // strip fractional seconds
    if (preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})\.\d+/', $val, $m)) {
        $val = $m[1];
    }
    // all-day events come back as date only
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
        $d = DateTime::createFromFormat('Y-m-d', $val, new DateTimeZone('UTC'));
        return $d ?: null;
    }
    try {
        if (str_ends_with($val, 'Z')) {
            $d = new DateTime($val, new DateTimeZone('UTC'));
        } else {
            $tzName = IcsParser::resolveTz($tz);
            $d = new DateTime($val, new DateTimeZone($tzName));
        }
        $d->setTimezone(new DateTimeZone('UTC'));
        return $d;
    } catch (Throwable) {
        return null;
    }
}

/** Sync every enabled calendar. Returns ['ok','calendars'=>[...]] summary. */
function sync_all_calendars(bool $verbose = false): array
{
    $summary = ['ok' => true, 'calendars' => []];
    $stmt = db()->query('SELECT * FROM calendars WHERE enabled = 1 ORDER BY id');
    $rows = $stmt->fetchAll();
    if (count($rows) === 0) {
        if ($verbose) {
            echo "[sync] No calendars configured.\n";
        }
        return $summary;
    }
    foreach ($rows as $row) {
        $res = sync_calendar_row($row);
        $summary['calendars'][] = [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'ok' => $res['ok'],
            'count' => $res['count'],
            'error' => $res['error'],
        ];
        if (!$res['ok']) {
            $summary['ok'] = false;
        }
        if ($verbose) {
            $status = $res['ok'] ? ('OK (' . $res['count'] . ' events)') : ('FAILED: ' . $res['error']);
            echo '[' . date('Y-m-d H:i:s') . "] " . $row['name'] . ": " . $status . "\n";
        }
    }
    // prune old cache rows outside the window
    [$winStart, $winEnd] = sync_window();
    $pdo = db();
    $del = $pdo->prepare('DELETE FROM events_cache WHERE start_utc < ? OR start_utc > ?');
    $del->execute([$winStart->format('Y-m-d H:i:s'), $winEnd->format('Y-m-d H:i:s')]);
    return $summary;
}
