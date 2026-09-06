<?php
/*
 * Bootstrap: loads configuration, session, and all application modules.
 * Every page (public, admin, installer, CLI sync) includes this file.
 */
declare(strict_types=1);

define('ROOT', dirname(__DIR__));
define('APP', ROOT . '/app');
define('PUBLIC_DIR', ROOT . '/public');
define('STORAGE', ROOT . '/storage');

$configFile = APP . '/config.php';
if (!is_file($configFile)) {
    if (PHP_SAPI !== 'cli') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        $base = $scheme . '://' . $host . $dir;
        header('Location: ' . $base . '/install/');
        exit;
    }
    fwrite(STDERR, "config.php not found. Run the web installer first.\n");
    exit(1);
}
require $configFile;

date_default_timezone_set('UTC');

if (PHP_SAPI !== 'cli') {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

require APP . '/i18n.php';
require APP . '/db.php';
require APP . '/util.php';
require APP . '/csrf.php';
require APP . '/auth.php';
require APP . '/mailer.php';
require APP . '/ics.php';
require APP . '/caldav.php';
require APP . '/graph.php';
require APP . '/availability.php';
require APP . '/mail_templates.php';
require APP . '/booking_ops.php';
require APP . '/sync_lib.php';
