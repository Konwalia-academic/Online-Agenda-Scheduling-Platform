<?php
/*
 * Microsoft Graph client for Microsoft 365 / Outlook calendars.
 * Uses the OAuth2 authorization-code flow with PKCE and a stored refresh
 * token, so you sign in once with your own Microsoft account (delegated
 * access to /me/...). All HTTP via cURL.
 */
declare(strict_types=1);

final class GraphClient
{
    private const TOKEN_FILE = STORAGE . '/cache/graph_token.json';
    private const AUTH = 'https://login.microsoftonline.com/';
    private const GRAPH = 'https://graph.microsoft.com/v1.0';
    private const SCOPES = 'Calendars.ReadWrite offline_access User.Read';

    public function configured(): bool
    {
        return setting('graph_client_id', '') !== '' && setting('graph_secret', '') !== '';
    }

    public function tenant(): string
    {
        $t = setting('graph_tenant', 'common');
        return $t === '' ? 'common' : $t;
    }

    public function redirectUri(): string
    {
        // Azure only accepts HTTPS redirect URIs, so always send HTTPS even
        // if the configured site_url (or the detected scheme) is http.
        $base = base_url();
        if (preg_match('#^https?://#i', $base)) {
            $base = preg_replace('#^https?://#i', 'https://', $base);
        }
        return $base . '/admin/graph_callback.php';
    }

    public function needsReauth(): bool
    {
        return setting('graph_needs_reauth', '0') === '1';
    }

    public function isConnected(): bool
    {
        $tok = $this->loadToken();
        return $tok !== null && isset($tok['refresh_token']) && $tok['refresh_token'] !== '';
    }

    /** Build the sign-in URL. Returns [url, code_verifier]. */
    public function authorizeUrl(string $state): array
    {
        $verifier = self::base64Url(random_bytes(32));
        $challenge = self::base64Url(hash('sha256', $verifier, true));
        $params = http_build_query([
            'client_id' => setting('graph_client_id'),
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri(),
            'response_mode' => 'query',
            'scope' => self::SCOPES,
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);
        return [self::AUTH . $this->tenant() . '/oauth2/v2.0/authorize?' . $params, $verifier];
    }

    /** Exchange an authorization code (from callback) for tokens. */
    public function exchange(string $code, string $verifier): array
    {
        $res = $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'client_id' => setting('graph_client_id'),
            'client_secret' => decrypt_value((string)setting('graph_secret', '')),
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
            'code_verifier' => $verifier,
        ]);
        if (!$res['ok']) {
            return $res;
        }
        $this->saveToken($res['data']);
        $me = $this->me();
        set_setting('graph_user_email', $me['ok'] ? ($me['data']['mail'] ?? $me['data']['userPrincipalName'] ?? '') : '');
        set_setting('graph_needs_reauth', '0');
        return $res;
    }

    public function disconnect(): void
    {
        $this->clearToken();
        set_setting('graph_needs_reauth', '0');
        set_setting('graph_user_email', '');
    }

    /** Get a valid access token, refreshing if necessary. Returns null on failure. */
    public function getToken(): ?string
    {
        $tok = $this->loadToken();
        if ($tok === null) {
            return null;
        }
        $exp = (int)($tok['expires_at'] ?? 0);
        if ($exp > time() + 300 && isset($tok['access_token'])) {
            return $tok['access_token'];
        }
        if (empty($tok['refresh_token'])) {
            set_setting('graph_needs_reauth', '1');
            return null;
        }
        $res = $this->tokenRequest([
            'grant_type' => 'refresh_token',
            'client_id' => setting('graph_client_id'),
            'client_secret' => decrypt_value((string)setting('graph_secret', '')),
            'refresh_token' => $tok['refresh_token'],
            'scope' => self::SCOPES,
        ]);
        if (!$res['ok']) {
            set_setting('graph_needs_reauth', '1');
            return null;
        }
        $this->saveToken($res['data']);
        return $res['data']['access_token'] ?? null;
    }

    public function tokenExpiresAt(): ?int
    {
        $tok = $this->loadToken();
        return $tok ? (int)($tok['expires_at'] ?? 0) : null;
    }

    /** Generic Graph API call. Returns [ok, status, data]. */
    public function api(string $path, string $method = 'GET', ?array $body = null, int $retry = 1): array
    {
        $token = $this->getToken();
        if ($token === null) {
            return [false, 0, ['error' => ['message' => 'No valid Microsoft token. Reconnect in admin > Calendars.']]];
        }
        $url = self::GRAPH . $path;
        $headers = [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ];
        $opts = ['method' => $method, 'timeout' => 60];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $opts['body'] = json_encode($body);
        }
        [$status, $data, $raw] = self::httpJson($url, $headers, $opts);

        if ($status === 401 && $retry > 0) {
            // token likely expired server-side; force refresh and retry once
            $this->clearToken();
            return $this->api($path, $method, $body, 0);
        }
        return [$status >= 200 && $status < 300, $status, $data];
    }

    public function me(): array
    {
        return $this->api('/me');
    }

    /** List all sub-calendars of the connected account. */
    public function listCalendars(): array
    {
        $res = $this->api('/me/calendars?$select=id,name,canEdit,owner');
        $cals = [];
        if ($res['ok']) {
            foreach ($res['data']['value'] ?? [] as $c) {
                $cals[] = [
                    'id' => $c['id'],
                    'name' => $c['name'],
                    'canEdit' => $c['canEdit'] ?? false,
                ];
            }
            usort($cals, fn($a, $b) => strcmp((string)$a['name'], (string)$b['name']));
        }
        return $cals;
    }

    /** Like listCalendars() but reports errors so the UI can show why it failed. */
    public function listCalendarsDetailed(): array
    {
        $res = $this->api('/me/calendars?$select=id,name,canEdit,owner');
        $out = ['ok' => $res['ok'], 'cals' => [], 'error' => ''];
        if (!$res['ok']) {
            $out['error'] = $res['data']['error']['message']
                ?? ('Microsoft Graph error (HTTP ' . $res['status'] . ')');
            return $out;
        }
        foreach ($res['data']['value'] ?? [] as $c) {
            $out['cals'][] = [
                'id' => $c['id'],
                'name' => $c['name'],
                'canEdit' => $c['canEdit'] ?? false,
            ];
        }
        usort($out['cals'], fn($a, $b) => strcmp((string)$a['name'], (string)$b['name']));
        return $out;
    }

    /** Events in a calendar between two UTC datetimes (ISO8601). */
    public function calendarView(string $calId, string $startIso, string $endIso): array
    {
        $q = http_build_query([
            'startDateTime' => $startIso,
            'endDateTime' => $endIso,
            '$select' => 'id,iCalUId,subject,location,start,end,isAllDay,showAs',
        ]);
        return $this->api('/me/calendars/' . rawurlencode($calId) . '/calendarView?' . $q);
    }

    /** Create an event (write-back of an approved booking). */
    public function createEvent(string $calId, array $event): array
    {
        return $this->api('/me/calendars/' . rawurlencode($calId) . '/events', 'POST', $event);
    }

    public function updateEvent(string $calId, string $eventId, array $event): array
    {
        return $this->api('/me/calendars/' . rawurlencode($calId) . '/events/' . rawurlencode($eventId), 'PATCH', $event);
    }

    public function deleteEvent(string $calId, string $eventId): array
    {
        return $this->api('/me/calendars/' . rawurlencode($calId) . '/events/' . rawurlencode($eventId), 'DELETE');
    }

    // ---- token persistence ----

    private function loadToken(): ?array
    {
        if (!is_file(self::TOKEN_FILE)) {
            return null;
        }
        $data = @json_decode((string)@file_get_contents(self::TOKEN_FILE), true);
        return is_array($data) ? $data : null;
    }

    private function saveToken(array $token): void
    {
        ensure_dir(dirname(self::TOKEN_FILE));
        $exp = time() + (int)($token['expires_in'] ?? 3600) - 60;
        $stored = [
            'access_token' => $token['access_token'] ?? '',
            'refresh_token' => $token['refresh_token'] ?? '',
            'expires_at' => $exp,
        ];
        @file_put_contents(self::TOKEN_FILE, json_encode($stored), LOCK_EX);
        @chmod(self::TOKEN_FILE, 0600);
    }

    private function clearToken(): void
    {
        if (is_file(self::TOKEN_FILE)) {
            @unlink(self::TOKEN_FILE);
        }
    }

    private function tokenRequest(array $params): array
    {
        $url = self::AUTH . $this->tenant() . '/oauth2/v2.0/token';
        $headers = ['Content-Type: application/x-www-form-urlencoded'];
        $opts = ['method' => 'POST', 'body' => http_build_query($params), 'timeout' => 60];
        [$status, $data] = self::httpJson($url, $headers, $opts);
        if ($status >= 200 && $status < 300 && isset($data['access_token'])) {
            return ['ok' => true, 'data' => $data];
        }
        $msg = $data['error_description'] ?? ($data['error'] ?? ('HTTP ' . $status));
        return ['ok' => false, 'data' => null, 'error' => $msg];
    }

    private static function httpJson(string $url, array $headers, array $opts): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $opts['timeout'] ?? 60,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        if (($opts['method'] ?? 'GET') === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if (!empty($opts['body'])) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['body']);
            }
        } elseif (($opts['method'] ?? 'GET') !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $opts['method']);
            if (!empty($opts['body'])) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['body']);
            }
        }
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return [0, ['error' => ['message' => 'cURL error: ' . $err]], ''];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $data = ['error' => ['message' => 'Unexpected response: ' . substr($raw, 0, 300)]];
        }
        return [$status, $data, $raw];
    }

    private static function base64Url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
}

/** Shared singleton for convenience. */
function graph(): GraphClient
{
    static $g = null;
    if ($g === null) {
        $g = new GraphClient();
    }
    return $g;
}
