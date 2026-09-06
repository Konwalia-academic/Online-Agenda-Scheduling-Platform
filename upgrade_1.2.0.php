<?php
/*
 * Agenda Platform — Upgrade to 1.2.0
 * -----------------------------------
 * Applies the 1.2.0 changes to a deployed/running installation:
 *
 *   1. Adds the 'caldav' value to the calendars.ctype ENUM so the new
 *      CalDAV feature can be configured (requires the new app/caldav.php,
 *      app/sync_lib.php, public/admin/calendars.php and i18n files to be
 *      uploaded too — see below).
 *   2. Records app_version = 1.2.0.
 *
 * Usage (either way is fine):
 *   - Browser:  https://your-domain/upgrade_run.php   (web runner in public/)
 *               or if you placed this file under public/: https://your-domain/upgrade_1.2.0.php
 *   - CLI:      php /path/to/project/upgrade_1.2.0.php
 *
 * IMPORTANT: This file has NO access token on purpose.
 *            DELETE THIS FILE right after the upgrade has run.
 *
 * This file auto-detects the project root (walks up until it finds app/config.php),
 * so it works whether placed at the project root, in public/, or anywhere inside
 * the project tree. It is self-contained and does NOT require app/bootstrap.php.
 */
declare(strict_types=1);

define('UPGRADE_TARGET', '1.2.0');

// Locate the project root by walking up to app/config.php (handles both the
// project-root location and placement under public/ where the web root lives).
function upgrade_find_root(string $start): string
{
    $dir = $start;
    for ($i = 0; $i < 8; $i++) {
        if (is_file($dir . '/app/config.php')) {
            return $dir;
        }
        $parent = dirname($dir);
        if ($parent === $dir) {
            break;
        }
        $dir = $parent;
    }
    return $start;
}

define('UPGRADE_ROOT', upgrade_find_root(__DIR__));

$isCli = PHP_SAPI === 'cli';

function u_line(string $text, string $class = ''): void
{
    global $isCli;
    if ($isCli) {
        echo $text . "\n";
    } else {
        echo '<div class="line' . ($class !== '' ? ' ' . $class : '') . '">' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</div>';
    }
}

function u_block(string $html, string $class = ''): void
{
    global $isCli;
    if ($isCli) {
        echo trim(strip_tags(str_replace(['<br>', '</p>', '</div>'], "\n", $html))) . "\n";
    } else {
        echo '<div class="' . $class . '">' . $html . '</div>';
    }
}

function u_section(string $title): void
{
    global $isCli;
    if ($isCli) {
        echo "\n" . strtoupper($title) . "\n" . str_repeat('-', strlen($title)) . "\n";
    } else {
        echo '<h2>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h2>';
    }
}

if (!$isCli) {
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html><html lang=\"en\"><head><meta charset=\"utf-8\">"
       . "<title>Agenda Platform Upgrade</title>"
       . "<style>body{font-family:-apple-system,Segoe UI,Roboto,'PingFang SC','Microsoft YaHei',sans-serif;"
       . "background:#f8fafc;color:#111827;margin:0;padding:32px 16px;}"
       . ".card{max-width:760px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px;}"
       . "h1{font-size:20px;margin:0 0 8px;}h2{font-size:15px;margin:20px 0 6px;}"
       . ".ok{color:#047857;}.err{color:#b91c1c;}.warn{color:#92400e;}"
       . "code{background:#f3f4f6;padding:1px 6px;border-radius:6px;font-size:13px;}"
       . ".line{padding:3px 0;font-size:13px;font-family:ui-monospace,Menlo,Consolas,monospace;}"
       . ".banner{background:#fef3c7;border:1px solid #fde68a;color:#92400e;border-radius:10px;padding:12px 16px;margin:16px 0;font-size:14px;}"
       . "</style></head><body><div class=\"card\">"
       . "<h1>Agenda Platform — Upgrade to " . UPGRADE_TARGET . "</h1>";
} else {
    echo "Agenda Platform — Upgrade to " . UPGRADE_TARGET . "\n";
    echo "-------------------------------------------\n";
}

// locate config
$configFile = UPGRADE_ROOT . '/app/config.php';
if (!is_file($configFile)) {
    $msg = 'app/config.php not found. The web installer must have been run first (visit /install/). Aborting.';
    if ($isCli) {
        fwrite(STDERR, $msg . "\n");
        exit(1);
    }
    u_block('<b>ERROR:</b> ' . htmlspecialchars($msg), 'err');
    u_finish(false);
}
require $configFile;

// connect — try the configured host first, then 127.0.0.1 / localhost.
// On Linux, PDO treats host "localhost" as a unix socket (pdo_mysql.default_socket),
// which the CLI often lacks; 127.0.0.1 forces TCP and works everywhere.
$pdo = null;
$lastErr = null;
$hosts = array_values(array_unique(array_filter([
    DB_HOST,
    '127.0.0.1',
    'localhost',
])));
foreach ($hosts as $host) {
    try {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, DB_PORT, DB_NAME);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        break;
    } catch (Throwable $ex) {
        $lastErr = $ex->getMessage();
    }
}
if ($pdo === null) {
    $msg = 'Database connection failed: ' . $lastErr;
    if ($isCli) {
        fwrite(STDERR, $msg . "\n");
        exit(1);
    }
    u_block('<b>ERROR:</b> ' . htmlspecialchars($msg), 'err');
    u_finish(false);
}

// current version
$current = '1.1.0';
try {
    $st = $pdo->prepare('SELECT svalue FROM settings WHERE skey = ?');
    $st->execute(['app_version']);
    $v = $st->fetchColumn();
    if ($v !== false && $v !== null && $v !== '') {
        $current = (string)$v;
    }
} catch (Throwable) {
    $current = '1.1.0';
}

u_line('Current app version: ' . $current);
if (version_compare($current, UPGRADE_TARGET, '>=')) {
    u_line('Already up to date (>= ' . UPGRADE_TARGET . '). Nothing to do.');
    u_finish(true);
}
u_line('Target version: ' . UPGRADE_TARGET);

$errors = [];

// STEP 1: add 'caldav' to calendars.ctype ENUM
u_section('Step 1.2.0 — extend calendars.ctype for CalDAV');
try {
    $pdo->exec("ALTER TABLE calendars MODIFY ctype ENUM('graph','upload','url','caldav') NOT NULL DEFAULT 'url'");
    u_line('calendars.ctype ENUM updated (added caldav).', 'ok');
} catch (Throwable $ex) {
    // Some MySQL versions dislike ALTER on ENUM if already there; check current definition.
    $errors[] = 'ALTER calendars.ctype failed: ' . $ex->getMessage();
    u_line('!! ALTER calendars.ctype failed: ' . $ex->getMessage(), 'err');
}

// STEP 2: record version
try {
    $stmt = $pdo->prepare('INSERT INTO settings (skey, svalue) VALUES (?, ?) '
        . 'ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)');
    $stmt->execute(['app_version', UPGRADE_TARGET]);
    u_line('Recorded app_version = ' . UPGRADE_TARGET, 'ok');
} catch (Throwable $ex) {
    $errors[] = 'Could not write app_version: ' . $ex->getMessage();
    u_line('!! could not write app_version: ' . $ex->getMessage(), 'err');
}

// log
try {
    $logDir = UPGRADE_ROOT . '/storage/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }
    $log = '[' . date('Y-m-d H:i:s') . '] upgraded from ' . $current . ' to ' . UPGRADE_TARGET
         . ' — errors=' . count($errors) . "\n";
    @file_put_contents($logDir . '/upgrade.log', $log, FILE_APPEND | LOCK_EX);
} catch (Throwable) {
    // best effort
}

if (count($errors) > 0) {
    u_line('');
    u_line('Upgrade finished WITH ERRORS. Review the messages above.', 'err');
    u_finish(false);
}

u_line('');
u_line('Upgrade to ' . UPGRADE_TARGET . ' completed successfully.', 'ok');
u_block('<b>Next steps:</b><br>'
    . '1. Upload the new CalDAV files (if not already done): <code>app/caldav.php</code>, updated '
    . '<code>app/sync_lib.php</code>, <code>app/bootstrap.php</code>, <code>public/admin/calendars.php</code>, '
    . '<code>i18n/en.php</code>, <code>i18n/zh.php</code>.<br>'
    . '2. If you were seeing "stuck at Loading" in the calendar picker, also upload the loading-fix files: '
    . '<code>public/assets/js/app.js</code>, <code>public/ajax.php</code>, <code>app/util.php</code>, '
    . '<code>app/graph.php</code>, <code>public/admin/email.php</code>.<br>'
    . '3. In Admin → Calendars, use "Add CalDAV calendar" to connect Nextcloud/Baikal/Radicale/iCloud.<br>'
    . '4. <b>Delete this file (<code>upgrade_1.2.0.php</code>) now</b> — it has no access token.',
    'banner');

u_finish(true);

function u_finish(bool $success): never
{
    global $isCli;
    if (!$isCli) {
        echo '</div></body></html>';
    }
    exit($success ? 0 : 1);
}
