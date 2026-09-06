<?php
/*
 * Web runner for the upgrade script.
 *
 * The actual upgrade file (upgrade_1.2.0.php) lives at the project root, but
 * this site's web root is public/, so it cannot be reached directly by URL.
 * This small file is placed in public/ and simply loads the root upgrade file.
 *
 * Access:  https://your-domain/upgrade_run.php
 *
 * IMPORTANT: Delete BOTH this file and upgrade_1.2.0.php after the upgrade.
 */
declare(strict_types=1);

$upgradeFile = dirname(__DIR__) . '/upgrade_1.2.0.php';
if (!is_file($upgradeFile)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('upgrade_1.2.0.php not found at the project root.');
}
require $upgradeFile;
