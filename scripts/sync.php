<?php
/*
 * Calendar sync job — run this from cron at the interval set in the admin
 * panel (default every 10 minutes). See deploy/crontab.example.
 *
 * The cron user must be able to read/write storage/ and app/config.php.
 * Typically run as www-data, or a dedicated user with matching permissions.
 */
require __DIR__ . '/../app/bootstrap.php';

$summary = sync_all_calendars(true);
exit($summary['ok'] ? 0 : 1);
