<?php
/*
 * Download a booking as an .ics file (admin convenience).
 */
require __DIR__ . '/../app/bootstrap.php';
require_admin();

$id = (int)($_GET['id'] ?? 0);
$b = fetch_booking($id);
if (!$b) {
    http_response_code(404);
    exit('Not found');
}

$ics = booking_ics($b, (string)setting('admin_email', ''));
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="booking-' . $id . '.ics"');
echo $ics;
