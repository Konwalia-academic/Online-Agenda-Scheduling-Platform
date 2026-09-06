<?php
/*
 * Agenda Platform — Upgrade to 1.1.0
 * -----------------------------------
 * Applies the 1.1.0 changes to a deployed/running installation:
 *
 *   1. Fixes the admin bootstrap require path in public/admin/*.php
 *      ('/../app/bootstrap.php' -> '/../../app/bootstrap.php'), which broke
 *      the admin panel (Fatal: Failed opening required .../app/bootstrap.php).
 *   2. Fixes the admin AJAX endpoints (ajax.php -> ../ajax.php) so the
 *      "test mail", "sync now" and Graph calendar picker work from /admin/.
 *
 * Usage (either way is fine):
 *   - Browser:  https://your-domain/upgrade_1.1.0.php
 *   - CLI:      php /path/to/project/upgrade_1.1.0.php
 *
 * IMPORTANT: This file has NO access token on purpose (per design choice).
 *            DELETE THIS FILE right after the upgrade has run.
 *
 * It is fully self-contained: it does NOT require app/bootstrap.php, so it
 * works even when the running site is broken.
 *
 * This file lives at the project ROOT (next to public/ and app/).
 */
declare(strict_types=1);

define('UPGRADE_ROOT', __DIR__);
define('UPGRADE_TARGET', '1.1.0');

// ---------------------------------------------------------------------------
// output helpers (work for both CLI and browser)
// ---------------------------------------------------------------------------
$isCli = PHP_SAPI === 'cli';

/** Plain text line. */
function u_line(string $text, string $class = ''): void
{
    global $isCli;
    if ($isCli) {
        echo $text . "\n";
    } elseif ($class !== '') {
        echo '<div class="line ' . $class . '">' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</div>';
    } else {
        echo '<div class="line">' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</div>';
    }
}

/** Raw HTML block (browser) / plain text (CLI). */
function u_block(string $html, string $class = ''): void
{
    global $isCli;
    if ($isCli) {
        echo trim(strip_tags(str_replace(['<br>', '</p>', '</div>'], "\n", $html))) . "\n";
    } else {
        if ($class !== '') {
            echo '<div class="' . $class . '">' . $html . '</div>';
        } else {
            echo $html;
        }
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

// ---------------------------------------------------------------------------
// locate config
// ---------------------------------------------------------------------------
$configFile = UPGRADE_ROOT . '/app/config.php';
if (!is_file($configFile)) {
    $msg = 'app/config.php not found. The web installer must have been run first '
         . '(visit /install/). Aborting.';
    if ($isCli) {
        fwrite(STDERR, $msg . "\n");
        exit(1);
    }
    u_block('<b>ERROR:</b> ' . htmlspecialchars($msg), 'err');
    u_finish(false);
}
require $configFile;

// ---------------------------------------------------------------------------
// connect to database
// ---------------------------------------------------------------------------
$pdo = null;
try {
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $ex) {
    $msg = 'Database connection failed: ' . $ex->getMessage();
    if ($isCli) {
        fwrite(STDERR, $msg . "\n");
        exit(1);
    }
    u_block('<b>ERROR:</b> ' . htmlspecialchars($msg), 'err');
    u_finish(false);
}

// ---------------------------------------------------------------------------
// read current app version
// ---------------------------------------------------------------------------
$current = '1.0.0';
try {
    $st = $pdo->prepare('SELECT svalue FROM settings WHERE skey = ?');
    $st->execute(['app_version']);
    $v = $st->fetchColumn();
    if ($v !== false && $v !== null && $v !== '') {
        $current = (string)$v;
    }
} catch (Throwable) {
    // settings table may not exist -> treat as 1.0.0 (or pre-versioning)
    $current = '1.0.0';
}

u_line('Current app version: ' . $current);

if (version_compare($current, UPGRADE_TARGET, '>=')) {
    u_line('Already up to date (>= ' . UPGRADE_TARGET . '). Nothing to do.');
    u_finish(true);
}

u_line('Target version: ' . UPGRADE_TARGET);

// ---------------------------------------------------------------------------
// STEP 1.1.0 — path fixes
// ---------------------------------------------------------------------------
$changed = 0;
$okCount = 0;
$errors = [];

// backup directory
$backupDir = UPGRADE_ROOT . '/storage/tmp/upgrade_backup_' . date('Ymd_His');
if (!is_dir($backupDir) && !@mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
    $errors[] = 'Cannot create backup directory: ' . $backupDir;
    u_line('Cannot create backup directory: ' . $backupDir, 'err');
    u_finish(false);
}

/** Rewrite a file: backup original, apply replacements, report. */
function u_patch_file(string $path, array $replaces): void
{
    global $backupDir, $changed, $okCount, $errors;

    if (!is_file($path)) {
        $errors[] = 'Missing file: ' . $path;
        u_line('!! ' . $path . ' — file not found', 'err');
        return;
    }
    $content = (string)file_get_contents($path);
    $original = $content;
    $applied = [];
    foreach ($replaces as $from => $to) {
        $count = substr_count($content, $from);
        if ($count > 0) {
            $content = str_replace($from, $to, $content);
            $applied[] = basename($path) . ': ' . $count . 'x  ' . $from . '  ->  ' . $to;
        }
    }
    if ($content === $original) {
        $okCount++;
        u_line('== ' . $path . ' — already up to date', 'ok');
        return;
    }
    // backup the original
    $bakPath = $backupDir . '/' . basename($path) . '.bak';
    if (!file_put_contents($bakPath, $original, LOCK_EX)) {
        $errors[] = 'Cannot write backup: ' . $bakPath;
        u_line('!! ' . $path . ' — backup FAILED, skipping write', 'err');
        return;
    }
    if (!file_put_contents($path, $content, LOCK_EX)) {
        $errors[] = 'Cannot write file: ' . $path;
        u_line('!! ' . $path . ' — write FAILED', 'err');
        return;
    }
    $changed++;
    foreach ($applied as $a) {
        u_line('+ ' . $a, 'ok');
    }
}

u_section('Step 1.1.0 — patch admin files');

$adminDir = UPGRADE_ROOT . '/public/admin';
$adminFiles = [
    'appearance.php', 'bookings.php', 'calendars.php', 'email.php',
    'graph_auth.php', 'graph_callback.php', 'ics.php', 'index.php',
    'login.php', 'logout.php', 'requests.php', 'security.php', 'settings.php',
];

$bootstrapReplace = [
    "require __DIR__ . '/../app/bootstrap.php';" => "require __DIR__ . '/../../app/bootstrap.php';",
];

$ajaxReplace = [
    "A.postJson('ajax.php?action=test_email'" => "A.postJson('../ajax.php?action=test_email'",
    "A.postJson('ajax.php?action=sync'" => "A.postJson('../ajax.php?action=sync'",
    "A.getJson('ajax.php?action=graph_calendars'" => "A.getJson('../ajax.php?action=graph_calendars'",
];

foreach ($adminFiles as $f) {
    u_patch_file($adminDir . '/' . $f, $bootstrapReplace);
}

// AJAX fixes only apply to email.php and calendars.php
u_patch_file($adminDir . '/email.php', $ajaxReplace);
u_patch_file($adminDir . '/calendars.php', $ajaxReplace);

u_line('files changed: ' . $changed . ' / already ok: ' . $okCount);
if (count($errors) > 0) {
    u_line('errors: ' . count($errors), 'err');
    foreach ($errors as $er) {
        u_line('!! ' . $er, 'err');
    }
}

// ---------------------------------------------------------------------------
// record version
// ---------------------------------------------------------------------------
try {
    $stmt = $pdo->prepare('INSERT INTO settings (skey, svalue) VALUES (?, ?) '
        . 'ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)');
    $stmt->execute(['app_version', UPGRADE_TARGET]);
    u_line('Recorded app_version = ' . UPGRADE_TARGET, 'ok');
} catch (Throwable $ex) {
    $errors[] = 'Could not write app_version: ' . $ex->getMessage();
    u_line('!! could not write app_version: ' . $ex->getMessage(), 'err');
}

// ---------------------------------------------------------------------------
// write upgrade log
// ---------------------------------------------------------------------------
try {
    $logDir = UPGRADE_ROOT . '/storage/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }
    $log = '[' . date('Y-m-d H:i:s') . '] upgraded from ' . $current . ' to ' . UPGRADE_TARGET
         . ' — changed=' . $changed . ' ok=' . $okCount . ' errors=' . count($errors) . "\n";
    @file_put_contents($logDir . '/upgrade.log', $log, FILE_APPEND | LOCK_EX);
} catch (Throwable) {
    // log best-effort only
}

// ---------------------------------------------------------------------------
// summary
// ---------------------------------------------------------------------------
if (count($errors) > 0) {
    u_line('');
    u_line('Upgrade finished WITH ERRORS. Review the messages above.', 'err');
    u_finish(false);
}

u_line('');
u_line('Upgrade to ' . UPGRADE_TARGET . ' completed successfully.', 'ok');
u_line('Backup of original admin files saved in: ' . $backupDir);
u_block('<b>Next steps:</b><br>'
    . '1. Reload the admin panel: <code>/admin/</code> — it should now load.<br>'
    . '2. <b>Delete this file (<code>upgrade_1.1.0.php</code>) from the server now</b> — it has no access token.<br>'
    . '3. If anything looks wrong, restore the <code>.bak</code> files from <code>' . htmlspecialchars($backupDir) . '</code>.',
    'banner');

u_finish(true);

/** Common tail: close HTML and exit. */
function u_finish(bool $success): never
{
    global $isCli;
    if (!$isCli) {
        echo '</div></body></html>';
    }
    exit($success ? 0 : 1);
}
