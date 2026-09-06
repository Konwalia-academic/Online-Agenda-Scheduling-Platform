<?php
/*
 * General utilities: escaping, redirects, JSON output, settings access,
 * timezone conversion helpers, token/email helpers, encryption at rest.
 */
declare(strict_types=1);

function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function json_out(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Full site base URL (no trailing slash), from settings or request. */
function base_url(): string
{
    $site = setting('site_url', '');
    if ($site !== '') {
        return rtrim($site, '/');
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}

function setting(string $key, mixed $default = null): mixed
{
    if (!isset($GLOBALS['__settings_cache'])) {
        $GLOBALS['__settings_cache'] = [];
        try {
            $rows = db()->query('SELECT skey, svalue FROM settings')->fetchAll();
            foreach ($rows as $row) {
                $GLOBALS['__settings_cache'][$row['skey']] = $row['svalue'];
            }
        } catch (Throwable) {
            // settings table may not exist yet during install
        }
    }
    if (array_key_exists($key, $GLOBALS['__settings_cache'])) {
        return $GLOBALS['__settings_cache'][$key];
    }
    return $default;
}

function set_setting(string $key, mixed $value): void
{
    $value = is_scalar($value) || $value === null ? (string)$value : json_encode($value);
    $stmt = db()->prepare(
        'INSERT INTO settings (skey, svalue) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)'
    );
    $stmt->execute([$key, $value]);
    // refresh the shared in-memory cache used by setting()
    if (!isset($GLOBALS['__settings_cache'])) {
        $GLOBALS['__settings_cache'] = [];
    }
    $GLOBALS['__settings_cache'][$key] = $value;
}

function utc_now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function app_timezone(): DateTimeZone
{
    $tz = setting('timezone', 'UTC');
    try {
        return new DateTimeZone($tz);
    } catch (Throwable) {
        return new DateTimeZone('UTC');
    }
}

function utc_to_local(string $utcStr): DateTime
{
    $dt = new DateTime($utcStr, new DateTimeZone('UTC'));
    $dt->setTimezone(app_timezone());
    return $dt;
}

/** Parse a local date/time string ("Y-m-d H:i" or "Y-m-d") in the site timezone, return UTC string. */
function local_to_utc(string $local, string $format = 'Y-m-d H:i'): string
{
    $dt = DateTime::createFromFormat($format, $local, app_timezone());
    if ($dt === false) {
        throw new RuntimeException('Invalid local datetime: ' . $local);
    }
    $dt->setTimezone(new DateTimeZone('UTC'));
    return $dt->format('Y-m-d H:i:s');
}

function fmt_local(string $utcStr, string $format = 'Y-m-d H:i'): string
{
    return utc_to_local($utcStr)->format($format);
}

function random_token(int $len = 32): string
{
    return bin2hex(random_bytes((int)ceil($len / 2)));
}

function encrypt_value(string $plain): string
{
    $key = hash('sha256', APP_SECRET, true);
    $iv = random_bytes(12);
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return base64_encode($iv . $tag . $cipher);
}

function decrypt_value(string $stored): ?string
{
    try {
        $raw = base64_decode($stored, true);
        if ($raw === false || strlen($raw) < 29) {
            return null;
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $key = hash('sha256', APP_SECRET, true);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? null : $plain;
    } catch (Throwable) {
        return null;
    }
}

/** Extract a list of unique, valid e-mail addresses from free text. */
function parse_emails(string $text): array
{
    $out = [];
    preg_match_all('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $text, $m);
    foreach ($m[0] as $addr) {
        $addr = strtolower(trim($addr));
        if (filter_var($addr, FILTER_VALIDATE_EMAIL) && !in_array($addr, $out, true)) {
            $out[] = $addr;
        }
    }
    return $out;
}

function is_valid_email(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/** Minutes -> human readable, e.g. 90 -> "1h 30m". */
function human_minutes(int $min): string
{
    $h = intdiv($min, 60);
    $m = $min % 60;
    if ($h > 0 && $m > 0) {
        return "{$h}h {$m}m";
    }
    if ($h > 0) {
        return "{$h}h";
    }
    return "{$m}m";
}

/** Ensure a directory exists and is writable. */
function ensure_dir(string $dir): void
{
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create directory: ' . $dir);
    }
    if (!is_writable($dir)) {
        throw new RuntimeException('Directory not writable: ' . $dir);
    }
}
