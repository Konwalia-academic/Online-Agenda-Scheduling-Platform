<?php
/*
 * RFC 5545 iCalendar parser + recurrence expansion.
 * Self-contained, no external dependencies. Handles common Google/Outlook
 * exports: single events, all-day events, and recurring events
 * (FREQ DAILY/WEEKLY/MONTHLY/YEARLY with INTERVAL, COUNT, UNTIL, BYDAY,
 *  BYMONTHDAY, BYMONTH; EXDATE/RDATE).
 */
declare(strict_types=1);

final class IcsEvent
{
    public string $uid = '';
    public string $summary = '';
    public string $location = '';
    public string $description = '';
    public DateTimeImmutable $start; // in $tzName
    public DateTimeImmutable $end;   // in $tzName
    public bool $allDay = false;
    public string $tzName = 'UTC';
    public string $rrule = '';
    /** @var DateTimeImmutable[] */
    public array $exdates = [];
    /** @var DateTimeImmutable[] */
    public array $rdates = [];

    public function timezone(): DateTimeZone
    {
        return new DateTimeZone($this->tzName);
    }

    /**
     * Expand this event into occurrences overlapping [winStart, winEnd] (UTC).
     * @return array<int, array{0:string,1:string}> list of [startUtc, endUtc]
     */
    public function expandInto(DateTimeImmutable $winStart, DateTimeImmutable $winEnd, int $max = 1000): array
    {
        $out = [];
        $tz = $this->timezone();
        $winStartTz = $winStart->setTimezone($tz);
        $winEndTz = $winEnd->setTimezone($tz);

        $starts = [];
        if ($this->rrule !== '') {
            $starts = $this->expandRecurring($tz, $winStartTz, $winEndTz, $max);
        } else {
            $starts[] = $this->start;
        }
        // add RDATEs
        foreach ($this->rdates as $rd) {
            $starts[] = $rd;
        }

        $ex = [];
        foreach ($this->exdates as $exd) {
            $ex[$exd->format('Y-m-d H:i')] = true;
        }

        $seen = [];
        sort($starts);
        foreach ($starts as $s) {
            $startTz = $s instanceof DateTimeImmutable ? $s->setTimezone($tz) : $s;
            $key = $startTz->format('Y-m-d H:i');
            if (isset($seen[$key])) {
                continue;
            }
            if (isset($ex[$key])) {
                continue;
            }
            if ($startTz < $winStartTz || $startTz > $winEndTz) {
                continue;
            }
            $seen[$key] = true;
            $dur = $this->end->getTimestamp() - $this->start->getTimestamp();
            $endTz = $startTz->modify('+' . max(0, $dur) . ' seconds');
            $out[] = [
                $startTz->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                $endTz->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            ];
            if (count($out) >= $max) {
                break;
            }
        }
        return $out;
    }

    /** @return DateTimeImmutable[] */
    private function expandRecurring(DateTimeZone $tz, DateTimeImmutable $winStart, DateTimeImmutable $winEnd, int $max): array
    {
        $r = IcsParser::parseRRule($this->rrule);
        $freq = $r['freq'] ?? 'DAILY';
        $interval = max(1, (int)($r['interval'] ?? 1));
        $count = isset($r['count']) ? (int)$r['count'] : null;
        $until = $r['until'] ?? null; // DateTimeImmutable UTC
        $byday = $r['byday'] ?? [];
        $bymonthday = $r['bymonthday'] ?? [];
        $bymonth = $r['bymonth'] ?? [];

        $dtStart = $this->start;
        $h = (int)$dtStart->format('H');
        $i = (int)$dtStart->format('i');
        $s = (int)$dtStart->format('s');

        $candidates = [];
        $guard = 0;

        if ($freq === 'DAILY') {
            $cur = $dtStart;
            while ($guard++ < $max * 4) {
                $candidates[] = $cur;
                if (count($candidates) >= $max) break;
                $cur = $cur->modify('+' . $interval . ' days');
                if ($count !== null && count($candidates) >= $count) break;
                if ($until !== null && $cur > $until->setTimezone($tz)) break;
            }
        } elseif ($freq === 'WEEKLY') {
            $dowMap = ['MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7];
            $monday = self::mondayOf($dtStart);
            $days = [];
            if ($byday !== []) {
                foreach ($byday as $code) {
                    $code = strtoupper($code);
                    if (isset($dowMap[$code])) {
                        $days[] = $dowMap[$code];
                    }
                }
            }
            if ($days === []) {
                $days = [(int)$dtStart->format('N')];
            }
            sort($days);
            $untilTz = $until !== null ? $until->setTimezone($tz) : null;
            $weekIdx = 0;
            while ($guard++ < $max * 4) {
                $weekStart = $monday->modify('+' . ($weekIdx * $interval) . ' weeks');
                if ($weekStart > $winEnd) {
                    break;
                }
                foreach ($days as $dow) {
                    $cand = $weekStart->modify('+' . ($dow - 1) . ' days')->setTime($h, $i, $s);
                    if ($cand < $dtStart) {
                        continue;
                    }
                    if ($untilTz !== null && $cand > $untilTz) {
                        break 2;
                    }
                    $candidates[] = $cand;
                    if ($count !== null && count($candidates) >= $count) {
                        break 2;
                    }
                }
                $weekIdx++;
                if ($count !== null && count($candidates) >= $count) {
                    break;
                }
            }
        } elseif ($freq === 'MONTHLY') {
            $monthCursor = DateTimeImmutable::createFromFormat('Y-m-d', $dtStart->format('Y-m') . '-01', $tz);
            $monthIdx = 0;
            while ($guard++ < $max * 4) {
                $monthStart = $monthCursor->modify('+' . ($monthIdx * $interval) . ' months');
                if ($monthStart > $winEnd) break;
                $monthNo = (int)$monthStart->format('n');
                if ($bymonth !== [] && !in_array($monthNo, $bymonth, true)) {
                    $monthIdx++;
                    continue;
                }
                if ($bymonthday !== []) {
                    foreach ($bymonthday as $day) {
                        $cand = self::dateInMonth($monthStart, (int)$day, $h, $i, $s);
                        if ($cand !== null && $cand >= $dtStart) {
                            $candidates[] = $cand;
                        }
                    }
                } elseif ($byday !== []) {
                    foreach ($byday as $spec) {
                        $cand = self::nthWeekdayInMonth($monthStart, $spec, $h, $i, $s);
                        if ($cand !== null && $cand >= $dtStart) {
                            $candidates[] = $cand;
                        }
                    }
                } else {
                    $day = (int)$dtStart->format('j');
                    $cand = self::dateInMonth($monthStart, $day, $h, $i, $s);
                    if ($cand !== null && $cand >= $dtStart) {
                        $candidates[] = $cand;
                    }
                }
                if ($count !== null && count($candidates) >= $count) break;
                $monthIdx++;
            }
        } elseif ($freq === 'YEARLY') {
            $yearCursor = DateTimeImmutable::createFromFormat('Y-m-d', $dtStart->format('Y') . '-01-01', $tz);
            $yearIdx = 0;
            while ($guard++ < $max * 4) {
                $yearStart = $yearCursor->modify('+' . ($yearIdx * $interval) . ' years');
                if ($yearStart > $winEnd) break;
                $months = $bymonth !== [] ? $bymonth : [(int)$dtStart->format('n')];
                foreach ($months as $mo) {
                    if ($bymonthday !== []) {
                        foreach ($bymonthday as $day) {
                            $cand = self::dateInMonth($yearStart->modify('+' . ($mo - 1) . ' months'), (int)$day, $h, $i, $s);
                            if ($cand !== null && $cand >= $dtStart) {
                                $candidates[] = $cand;
                            }
                        }
                    } elseif ($byday !== []) {
                        foreach ($byday as $spec) {
                            $cand = self::nthWeekdayInMonth($yearStart->modify('+' . ($mo - 1) . ' months'), $spec, $h, $i, $s);
                            if ($cand !== null && $cand >= $dtStart) {
                                $candidates[] = $cand;
                            }
                        }
                    } else {
                        $cand = self::dateInMonth($yearStart->modify('+' . ($mo - 1) . ' months'), (int)$dtStart->format('j'), $h, $i, $s);
                        if ($cand !== null && $cand >= $dtStart) {
                            $candidates[] = $cand;
                        }
                    }
                }
                if ($count !== null && count($candidates) >= $count) break;
                $yearIdx++;
            }
        }

        // honour UNTIL for any frequency
        if ($until !== null) {
            $untilTz = $until->setTimezone($tz);
            $candidates = array_values(array_filter($candidates, fn($c) => $c <= $untilTz));
        }

        return $candidates;
    }

    private static function mondayOf(DateTimeImmutable $d): DateTimeImmutable
    {
        $n = (int)$d->format('N');
        return $d->modify('-' . ($n - 1) . ' days')->setTime(0, 0, 0);
    }

    private static function dateInMonth(DateTimeImmutable $monthStart, int $day, int $h, int $i, int $s): ?DateTimeImmutable
    {
        $max = (int)$monthStart->format('t');
        if ($day < 0) {
            $day = $max + $day + 1;
        }
        if ($day < 1 || $day > $max) {
            return null;
        }
        return $monthStart->modify('+' . ($day - 1) . ' days')->setTime($h, $i, $s);
    }

    private static function nthWeekdayInMonth(DateTimeImmutable $monthStart, string $spec, int $h, int $i, int $s): ?DateTimeImmutable
    {
        if (!preg_match('/^([+-]?\d+)(MO|TU|WE|TH|FR|SA|SU)$/i', $spec, $m)) {
            return null;
        }
        $n = (int)$m[1];
        $dowMap = ['MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7];
        $dow = $dowMap[strtoupper($m[2])];
        $max = (int)$monthStart->format('t');
        if ($n > 0) {
            $first = (int)$monthStart->format('N');
            $offset = ($dow - $first + 7) % 7;
            $day = 1 + $offset + ($n - 1) * 7;
            if ($day > $max) {
                return null;
            }
        } else {
            $lastDay = $monthStart->modify('+' . ($max - 1) . ' days');
            $lastDow = (int)$lastDay->format('N');
            $offset = ($lastDow - $dow + 7) % 7;
            $day = $max - $offset - (abs($n) - 1) * 7;
            if ($day < 1) {
                return null;
            }
        }
        return $monthStart->modify('+' . ($day - 1) . ' days')->setTime($h, $i, $s);
    }
}

final class IcsParser
{
    private const MS_TZ = [
        'China Standard Time' => 'Asia/Shanghai',
        'W. Europe Standard Time' => 'Europe/Berlin',
        'Central Europe Standard Time' => 'Europe/Budapest',
        'GMT Standard Time' => 'Europe/London',
        'Eastern Standard Time' => 'America/New_York',
        'Central Standard Time' => 'America/Chicago',
        'Mountain Standard Time' => 'America/Denver',
        'Pacific Standard Time' => 'America/Los_Angeles',
        'Tokyo Standard Time' => 'Asia/Tokyo',
        'Korea Standard Time' => 'Asia/Seoul',
        'Singapore Standard Time' => 'Asia/Singapore',
        'India Standard Time' => 'Asia/Kolkata',
        'Australian Eastern Standard Time' => 'Australia/Sydney',
        'UTC' => 'UTC',
        'GMT' => 'UTC',
        'Etc/UTC' => 'UTC',
    ];

    /**
     * Parse an iCalendar string into IcsEvent objects.
     * @return IcsEvent[]
     */
    public static function parse(string $ics): array
    {
        // detect & respect BOM
        if (str_starts_with($ics, "\xEF\xBB\xBF")) {
            $ics = substr($ics, 3);
        }
        $ics = str_replace(["\r\n", "\r"], "\n", $ics);
        // unfold lines
        $lines = [];
        foreach (explode("\n", $ics) as $line) {
            if (isset($line[0]) && ($line[0] === ' ' || $line[0] === "\t")) {
                $last = count($lines) - 1;
                if ($last >= 0) {
                    $lines[$last] .= substr($line, 1);
                }
                continue;
            }
            $lines[] = $line;
        }

        $events = [];
        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];
            if (strtoupper($line) === 'BEGIN:VEVENT') {
                $block = [];
                $i++;
                while ($i < $count && strtoupper($lines[$i]) !== 'END:VEVENT') {
                    $block[] = $lines[$i];
                    $i++;
                }
                $ev = self::parseEvent($block);
                if ($ev !== null) {
                    $events[] = $ev;
                }
            }
        }
        return $events;
    }

    /** @param string[] $block */
    private static function parseEvent(array $block): ?IcsEvent
    {
        $fields = [];
        foreach ($block as $line) {
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $name = substr($line, 0, $pos);
            $value = substr($line, $pos + 1);
            $prop = strtoupper(explode(';', $name)[0]);
            // keep the raw "NAME;PARAMS:value" token for date parsing, and
            // concatenate repeated properties (e.g. multiple EXDATE lines)
            if (isset($fields[$prop])) {
                $fields[$prop] .= ',' . $value;
                $fields['raw:' . $prop] .= ',' . $value;
            } else {
                $fields[$prop] = $value;
                $fields['raw:' . $prop] = $name . ':' . $value;
            }
        }

        if (!isset($fields['DTSTART'])) {
            return null;
        }

        $ev = new IcsEvent();
        $ev->uid = self::decode($fields['UID'] ?? ('u' . bin2hex(random_bytes(8))));
        $ev->summary = self::decode($fields['SUMMARY'] ?? '');
        $ev->location = self::decode($fields['LOCATION'] ?? '');
        $ev->description = self::decode($fields['DESCRIPTION'] ?? '');
        $ev->rrule = $fields['RRULE'] ?? '';

        $start = self::parseDateToken($fields['raw:DTSTART']);
        $ev->start = $start['dt'];
        $ev->allDay = $start['allDay'];
        $ev->tzName = $start['tz'];

        if (isset($fields['DTEND'])) {
            $end = self::parseDateToken($fields['raw:DTEND']);
            $ev->end = $end['dt'];
            $ev->allDay = $ev->allDay || $end['allDay'];
            if ($end['tz'] !== $start['tz']) {
                $ev->end = $end['dt']->setTimezone(new DateTimeZone($ev->tzName));
            }
        } elseif (isset($fields['DURATION'])) {
            $ev->end = $ev->start->modify(self::parseDuration($fields['DURATION']));
        } else {
            $ev->end = $ev->allDay
                ? $ev->start->modify('+1 day')
                : $ev->start->modify('+1 hour');
        }

        foreach (self::splitList($fields['raw:EXDATE'] ?? '') as $tok) {
            $d = self::parseDateToken($tok, $ev->tzName);
            $ev->exdates[] = $d['dt']->setTimezone(new DateTimeZone($ev->tzName));
        }
        foreach (self::splitList($fields['raw:RDATE'] ?? '') as $tok) {
            $d = self::parseDateToken($tok, $ev->tzName);
            $ev->rdates[] = $d['dt']->setTimezone(new DateTimeZone($ev->tzName));
        }

        return $ev;
    }

    /**
     * Parse a date-time token (e.g. "DTSTART;TZID=Asia/Shanghai:20250101T090000")
     * into [dt, allDay, tz].
     */
    public static function parseDateToken(string $token, string $fallbackTz = 'UTC'): array
    {
        $tzid = '';
        $value = $token;
        // extract TZID and VALUE params (name may be DTSTART/DTEND/EXDATE/RDATE)
        $colon = strpos($token, ':');
        $namePart = $colon === false ? $token : substr($token, 0, $colon);
        $value = $colon === false ? '' : substr($token, $colon + 1);
        if (preg_match('/TZID=([^;:]+)/i', $namePart, $m)) {
            $tzid = $m[1];
        }
        $allDay = preg_match('/VALUE=DATE($|;)/i', $namePart) === 1 || preg_match('/^\d{8}$/', $value) === 1;

        if (!$allDay && preg_match('/TZID=([^;:]+)/i', $token, $m)) {
            $tzid = $m[1];
        }

        $tz = self::resolveTz($tzid !== '' ? $tzid : $fallbackTz);

        if ($allDay) {
            $dt = DateTimeImmutable::createFromFormat('!Ymd', $value, new DateTimeZone($tz));
            if ($dt === false) {
                $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone($tz));
            }
            return ['dt' => $dt ?: new DateTimeImmutable('now', new DateTimeZone($tz)), 'allDay' => true, 'tz' => $tz];
        }

        if (str_ends_with($value, 'Z')) {
            $dt = DateTimeImmutable::createFromFormat('Ymd\\THis', substr($value, 0, -1), new DateTimeZone('UTC'));
            $tzOut = 'UTC';
        } else {
            $dt = DateTimeImmutable::createFromFormat('Ymd\\THis', $value, new DateTimeZone($tz));
            $tzOut = $tz;
        }
        if ($dt === false) {
            $dt = DateTimeImmutable::createFromFormat('Y-m-d\\TH:i:s', $value, new DateTimeZone($tzOut));
        }
        return ['dt' => $dt ?: new DateTimeImmutable('now', new DateTimeZone($tzOut)), 'allDay' => false, 'tz' => $tzOut];
    }

    public static function resolveTz(string $tzid): string
    {
        $t = trim($tzid);
        if ($t === '') {
            return 'UTC';
        }
        $all = DateTimeZone::listIdentifiers();
        if (in_array($t, $all, true)) {
            return $t;
        }
        if (isset(self::MS_TZ[$t])) {
            return self::MS_TZ[$t];
        }
        $upper = strtoupper($t);
        foreach ($all as $id) {
            if (strtoupper($id) === $upper) {
                return $id;
            }
        }
        return 'UTC';
    }

    /** Parse "PT1H30M", "P1D", "PT45M" into a DateInterval-friendly modify string. */
    public static function parseDuration(string $dur): string
    {
        if (!preg_match('/^P(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/i', $dur, $m)) {
            return '+1 hour';
        }
        $w = (int)($m[1] ?? 0);
        $d = (int)($m[2] ?? 0);
        $h = (int)($m[3] ?? 0);
        $min = (int)($m[4] ?? 0);
        $sec = (int)($m[5] ?? 0);
        $total = $w * 7 * 86400 + $d * 86400 + $h * 3600 + $min * 60 + $sec;
        if ($total <= 0) {
            return '+1 hour';
        }
        return '+' . $total . ' seconds';
    }

    public static function parseRRule(string $rrule): array
    {
        $out = ['freq' => null, 'interval' => null, 'count' => null, 'until' => null, 'byday' => [], 'bymonthday' => [], 'bymonth' => []];
        if ($rrule === '') {
            return $out;
        }
        foreach (explode(';', $rrule) as $part) {
            $pos = strpos($part, '=');
            if ($pos === false) {
                continue;
            }
            $k = strtoupper(substr($part, 0, $pos));
            $v = substr($part, $pos + 1);
            switch ($k) {
                case 'FREQ':
                    $out['freq'] = strtoupper($v);
                    break;
                case 'INTERVAL':
                    $out['interval'] = (int)$v;
                    break;
                case 'COUNT':
                    $out['count'] = (int)$v;
                    break;
                case 'UNTIL':
                    $out['until'] = self::parseUntil($v);
                    break;
                case 'BYDAY':
                    $out['byday'] = array_values(array_filter(explode(',', strtoupper($v))));
                    break;
                case 'BYMONTHDAY':
                    $out['bymonthday'] = array_map('intval', explode(',', $v));
                    break;
                case 'BYMONTH':
                    $out['bymonth'] = array_map('intval', explode(',', $v));
                    break;
            }
        }
        return $out;
    }

    private static function parseUntil(string $v): ?DateTimeImmutable
    {
        if (str_ends_with($v, 'Z')) {
            return DateTimeImmutable::createFromFormat('Ymd\\THis\\Z', $v, new DateTimeZone('UTC')) ?: null;
        }
        return DateTimeImmutable::createFromFormat('!Ymd', $v, new DateTimeZone('UTC')) ?: null;
    }

    /** EXDATE may appear multiple times, comma separated. */
    private static function splitList(string $value): array
    {
        $out = [];
        foreach (explode(',', $value) as $v) {
            $v = trim($v);
            if ($v !== '') {
                $out[] = $v;
            }
        }
        return $out;
    }

    private static function decode(string $s): string
    {
        if (str_starts_with($s, '=?')) {
            $decoded = mb_decode_mimeheader($s);
            if ($decoded !== false && $decoded !== '') {
                return $decoded;
            }
        }
        return $s;
    }
}
