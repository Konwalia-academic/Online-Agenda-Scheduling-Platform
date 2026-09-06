<?php
/*
 * Minimal CalDAV client (RFC 4791) — hand-rolled on cURL + DOM, no dependencies.
 *
 * Supports the "read-only client" use case: discover the calendar-home-set,
 * list calendars, and fetch VEVENTs for a time window. Reuses the ICS parser
 * (app/ics.php) so events flow into the same events_cache pipeline.
 *
 * Typical servers: Nextcloud (/remote.php/dav/), Baikal, Radicale, iCloud, Zimbra.
 *
 * This is intentionally read-only: approved bookings are confirmed by e-mail
 * only for CalDAV sources (no write-back), matching the .ics/URL sources.
 */
declare(strict_types=1);

final class CalDavClient
{
    private string $base;
    private string $username;
    private string $password;

    public function __construct(string $base, string $username, string $password)
    {
        $base = rtrim($base, '/');
        // If the user gave a bare origin, default to the standard CalDAV path.
        if (!preg_match('#^https?://[^/]+/.+#i', $base)) {
            $base .= '/remote.php/dav';
        }
        $this->base = $base;
        $this->username = $username;
        $this->password = $password;
    }

    /**
     * Discover the calendar-home-set and return a list of calendars.
     * @return array{ok:bool, cals:array<int,array{href:string,displayname:string}>, error:string}
     */
    public function listCalendars(): array
    {
        $out = ['ok' => false, 'cals' => [], 'error' => ''];
        try {
            // 1. Resolve the principal (via /.well-known or directly).
            $principal = $this->findPrincipal();
            if ($principal === null) {
                throw new RuntimeException('Could not determine the CalDAV principal (current-user-principal).');
            }

            // 2. Resolve the calendar-home-set from the principal.
            $home = $this->findCalendarHome($principal);
            if ($home === null) {
                throw new RuntimeException('Could not determine the calendar-home-set for this account.');
            }

            // 3. List calendars under the home set.
            $cals = $this->propfind($home, 1, ['displayname', 'resourcetype', 'calendar-color', 'current-user-privilege-set']);
            $list = [];
            foreach ($cals as $res) {
                $href = $res['href'] ?? '';
                $rt = strtolower((string)($res['props']['resourcetype'] ?? ''));
                $isCalendar = str_contains($rt, 'calendar');
                if ($href === '' || !$isCalendar) {
                    continue;
                }
                $list[] = [
                    'href' => $href,
                    'displayname' => (string)($res['props']['displayname'] ?? $href),
                ];
            }
            // If nothing matched the resourcetype (some servers omit it), fall back
            // to all direct children so the user can still pick.
            if (count($list) === 0) {
                foreach ($cals as $res) {
                    $href = $res['href'] ?? '';
                    if ($href !== '' && str_ends_with($href, '/')) {
                        $list[] = [
                            'href' => $href,
                            'displayname' => (string)($res['props']['displayname'] ?? $href),
                        ];
                    }
                }
            }
            usort($list, fn($a, $b) => strcmp((string)$a['displayname'], (string)$b['displayname']));
            $out['ok'] = true;
            $out['cals'] = $list;
        } catch (Throwable $ex) {
            $out['error'] = $ex->getMessage();
        }
        return $out;
    }

    /**
     * Fetch events from a calendar as a concatenated ICS string for the window.
     */
    public function fetchEvents(string $calendarHref, DateTimeImmutable $from, DateTimeImmutable $to): string
    {
        $url = $this->urlFor($calendarHref);
        $start = $from->format('Ymd\\THis\\Z');
        $end = $to->format('Ymd\\THis\\Z');

        $body = '<?xml version="1.0" encoding="utf-8"?>' . "\n"
            . '<C:calendar-query xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">' . "\n"
            . '  <D:prop><D:getetag/><C:calendar-data/></D:prop>' . "\n"
            . '  <C:filter>' . "\n"
            . '    <C:comp-filter name="VCALENDAR">' . "\n"
            . '      <C:comp-filter name="VEVENT">' . "\n"
            . '        <C:time-range start="' . $start . '" end="' . $end . '"/>' . "\n"
            . '      </C:comp-filter>' . "\n"
            . '    </C:comp-filter>' . "\n"
            . '  </C:filter>' . "\n"
            . '</C:calendar-query>';

        [$status, $response] = $this->request('REPORT', $url, $body, [
            'Depth: 1',
            'Content-Type: application/xml; charset=utf-8',
            'Accept: text/calendar, application/calendar+json, */*',
        ]);
        if ($status >= 400) {
            throw new RuntimeException('CalDAV REPORT failed (HTTP ' . $status . ').');
        }
        return $this->extractVcalendars((string)$response);
    }

    // ------------------------------------------------------------------
    // discovery helpers
    // ------------------------------------------------------------------

    private function findPrincipal(): ?string
    {
        // Prefer /.well-known/caldav; fall back to the base itself.
        $candidates = [$this->base . '/.well-known/caldav', $this->base . '/'];
        $seen = [];
        foreach ($candidates as $url) {
            $key = strtolower($url);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $body = $this->propfindBody(['current-user-principal']);
            [$status, $response] = $this->request('PROPFIND', $url, $body, ['Depth: 0', 'Content-Type: application/xml; charset=utf-8']);
            if ($status < 400) {
                $parsed = $this->parseMultistatus($response);
                foreach ($parsed as $res) {
                    $p = $res['props']['current-user-principal'] ?? null;
                    if (is_string($p) && $p !== '') {
                        return $p;
                    }
                }
            }
        }
        return null;
    }

    private function findCalendarHome(string $principalHref): ?string
    {
        $url = $this->urlFor($principalHref);
        $body = $this->propfindBody(['calendar-home-set']);
        [$status, $response] = $this->request('PROPFIND', $url, $body, ['Depth: 0', 'Content-Type: application/xml; charset=utf-8']);
        if ($status >= 400) {
            return null;
        }
        $parsed = $this->parseMultistatus($response);
        foreach ($parsed as $res) {
            $h = $res['props']['calendar-home-set'] ?? null;
            if (is_string($h) && $h !== '') {
                return $h;
            }
        }
        return null;
    }

    /** PROPFIND a URL and return parsed multistatus responses. */
    private function propfind(string $url, int $depth, array $props): array
    {
        $body = $this->propfindBody($props);
        [$status, $response] = $this->request('PROPFIND', $this->urlFor($url), $body, [
            'Depth: ' . $depth,
            'Content-Type: application/xml; charset=utf-8',
        ]);
        if ($status >= 400) {
            throw new RuntimeException('CalDAV PROPFIND failed (HTTP ' . $status . ').');
        }
        return $this->parseMultistatus($response);
    }

    private function propfindBody(array $props): string
    {
        $inner = '';
        foreach ($props as $p) {
            $inner .= '      <D:' . $p . '/>' . "\n";
        }
        return '<?xml version="1.0" encoding="utf-8"?>' . "\n"
            . '<D:propfind xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav" '
            . 'xmlns:CS="http://calendarserver.org/ns/">' . "\n"
            . '  <D:prop>' . "\n" . $inner . '  </D:prop>' . "\n"
            . '</D:propfind>';
    }

    // ------------------------------------------------------------------
    // transport + XML parsing
    // ------------------------------------------------------------------

    /** Build a full URL from a possibly-relative href. */
    private function urlFor(string $href): string
    {
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        // absolute path (starts with /) -> prepend scheme+host[:port] of base
        if (str_starts_with($href, '/')) {
            $parsed = parse_url($this->base);
            $scheme = $parsed['scheme'] ?? 'https';
            $host = $parsed['host'] ?? '';
            $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
            return $scheme . '://' . $host . $port . $href;
        }
        return $this->base . '/' . ltrim($href, '/');
    }

    private function request(string $method, string $url, string $body = '', array $extraHeaders = []): array
    {
        $ch = curl_init($url);
        $headers = array_merge(['User-Agent: Agenda-Platform/1.0'], $extraHeaders);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERPWD => $this->username . ':' . $this->password,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        if ($body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($response === false) {
            throw new RuntimeException('CalDAV cURL error: ' . $err);
        }
        return [$status, (string)$response];
    }

    /** Parse a DAV multistatus XML body into a list of responses. */
    private function parseMultistatus(string $xml): array
    {
        $out = [];
        if ($xml === '' || strpos($xml, '<') === false) {
            return $out;
        }
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $loaded = $doc->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$loaded) {
            return $out;
        }
        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('d', 'DAV:');
        $xpath->registerNamespace('c', 'urn:ietf:params:xml:ns:caldav');
        $responses = $xpath->query('//d:multistatus/d:response');
        foreach ($responses as $resp) {
            $hrefNode = $xpath->query('d:href', $resp)->item(0);
            $href = $hrefNode ? $hrefNode->textContent : '';
            $props = [];
            // first propstat (usually 200 OK)
            $propstat = $xpath->query('d:propstat[1]', $resp)->item(0);
            if ($propstat) {
                foreach ($xpath->query('d:prop/*', $propstat) as $prop) {
                    $name = $prop->localName;
                    $value = trim($prop->textContent);
                    if ($name === 'resourcetype') {
                        // serialize child element names for calendar detection
                        $parts = [];
                        foreach ($prop->childNodes as $child) {
                            if ($child->nodeType === XML_ELEMENT_NODE) {
                                $parts[] = $child->localName;
                            }
                        }
                        $value = implode(' ', $parts);
                    }
                    $props[$name] = $value;
                }
            }
            $out[] = ['href' => $href, 'props' => $props];
        }
        return $out;
    }

    /** Extract every VCALENDAR block from a (possibly multipart) response body. */
    private function extractVcalendars(string $body): string
    {
        $found = [];
        if (preg_match_all('/BEGIN:VCALENDAR.*?END:VCALENDAR/si', $body, $m)) {
            foreach ($m[0] as $block) {
                $found[] = $block;
            }
        }
        if (count($found) === 0) {
            return '';
        }
        return implode("\n", $found);
    }
}
