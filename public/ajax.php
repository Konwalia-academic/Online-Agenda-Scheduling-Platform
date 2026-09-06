<?php
/*
 * AJAX endpoint used by the public pages and the admin panel.
 * All responses are JSON. We start an output buffer and json_out() discards it,
 * so PHP warnings/notices can never corrupt a JSON response.
 */
require __DIR__ . '/../app/bootstrap.php';

ob_start();

$action = $_GET['action'] ?? ($_POST['action'] ?? '');

try {
    switch ($action) {

    // ---- public: available start times for a day + duration ----
    case 'slots':
        $day = trim((string)($_GET['day'] ?? ''));
        $dur = (int)($_GET['dur'] ?? 0);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || !is_valid_booking_day($day)) {
            json_out(['ok' => false, 'error' => t('book_invalid_day')]);
        }
        $allowed = duration_options();
        if (!in_array($dur, $allowed, true)) {
            json_out(['ok' => false, 'error' => t('book_err_duration')]);
        }
        json_out(['ok' => true, 'times' => available_start_times($day, $dur)]);

    // ---- public: reveal / hide event details using the invitation code ----
    case 'reveal':
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            json_out(['ok' => false], 405);
        }
        if (isset($_POST['lock']) && $_POST['lock'] === '1') {
            unset($_SESSION['cal_unlocked']);
            json_out(['ok' => true]);
        }
        $code = trim((string)($_POST['code'] ?? ''));
        $invite = (string)setting('invite_code', '4310');
        if (hash_equals($invite, $code)) {
            $_SESSION['cal_unlocked'] = true;
            json_out(['ok' => true]);
        }
        json_out(['ok' => false, 'error' => t('cal_invite_bad')]);

    // ---- admin: test SMTP ----
    case 'test_email':
        require_admin();
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            json_out(['ok' => false], 405);
        }
        csrf_verify();
        $to = trim((string)($_POST['to'] ?? ''));
        if (!is_valid_email($to)) {
            json_out(['ok' => false, 'error' => t('book_err_email')]);
        }
        if (!Mailer::configured()) {
            json_out(['ok' => false, 'error' => t('mail_not_configured')]);
        }
        $app = (string)setting('app_title', 'My Agenda');
        $html = '<p>' . $app . ' — ' . t('mail_test_ok') . '</p>';
        [$ok, $err] = Mailer::send($to, '[' . $app . '] ' . t('test'), $html);
        if ($ok) {
            json_out(['ok' => true, 'message' => t('mail_test_ok')]);
        }
        json_out(['ok' => false, 'error' => t('mail_test_fail', ['error' => $err])]);

    // ---- admin: sync calendars now ----
    case 'sync':
        require_admin();
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            json_out(['ok' => false], 405);
        }
        csrf_verify();
        $summary = sync_all_calendars();
        $total = 0;
        $errs = [];
        foreach ($summary['calendars'] as $c) {
            $total += (int)$c['count'];
            if (!$c['ok']) {
                $errs[] = $c['name'] . ': ' . $c['error'];
            }
        }
        if (!$summary['ok']) {
            json_out(['ok' => false, 'error' => t('cal_sync_failed', ['error' => implode('; ', $errs)])]);
        }
        json_out(['ok' => true, 'message' => t('cal_synced', ['events' => $total])]);

    // ---- admin: Microsoft Graph connection status ----
    case 'graph_status':
        require_admin();
        $connected = graph()->configured() && graph()->isConnected();
        $needsReauth = graph()->needsReauth();
        $user = setting('graph_user_email', '');
        $exp = graph()->tokenExpiresAt();
        json_out([
            'ok' => true,
            'connected' => $connected,
            'needs_reauth' => $needsReauth,
            'user' => $user,
            'expires' => $exp ? date('Y-m-d H:i', $exp) : null,
        ]);

    // ---- admin: list Outlook sub-calendars for selection ----
    case 'graph_calendars':
        require_admin();
        if (!graph()->configured() || !graph()->isConnected()) {
            json_out(['ok' => false, 'error' => t('cal_graph_token_expired')]);
        }
        $detail = graph()->listCalendarsDetailed();
        $selected = json_decode((string)setting('graph_calendars', '[]'), true) ?: [];
        $booking = setting('graph_booking_calendar', '');
        json_out([
            'ok' => $detail['ok'],
            'cals' => $detail['cals'],
            'selected' => $selected,
            'booking' => $booking,
            'error' => $detail['error'],
        ]);

    default:
        json_out(['ok' => false, 'error' => 'Unknown action.'], 404);
    }
} catch (Throwable $ex) {
    json_out(['ok' => false, 'error' => $ex->getMessage()], 500);
}
