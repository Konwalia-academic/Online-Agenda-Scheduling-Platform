<?php
/*
 * Booking operations: create a booking, send lifecycle e-mails, and change
 * status (approve / decline / cancel) — including Graph event write-back.
 */
declare(strict_types=1);

function fetch_booking(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM bookings WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function fetch_booking_by_invite(string $token): ?array
{
    $stmt = db()->prepare('SELECT * FROM bookings WHERE invite_token = ? LIMIT 1');
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function fetch_booking_by_admin_token(string $token): ?array
{
    $stmt = db()->prepare('SELECT * FROM bookings WHERE admin_token = ? LIMIT 1');
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Create a pending booking from validated input.
 * @return array{id:int, emails:array<int,string>} list of e-mail send errors
 */
function create_booking(array $in): array
{
    $startLocal = $in['day'] . ' ' . $in['time'];
    $startDt = DateTime::createFromFormat('Y-m-d H:i', $startLocal, app_timezone());
    $startDt->modify('+' . (int)$in['duration'] . ' minutes');
    $endLocal = $startDt->format('Y-m-d H:i');

    if (!is_slot_free_full($startLocal, $endLocal)) {
        throw new RuntimeException(t('book_invalid_slot'));
    }

    $pdo = db();
    $stmt = $pdo->prepare(
        'INSERT INTO bookings
         (status, name, email, phone, theme, location_type, location_detail,
          attendees, appendix, start_utc, end_utc, duration_min,
          invite_token, admin_token, lang, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $now = utc_now();
    $stmt->execute([
        'pending',
        mb_substr($in['name'], 0, 200),
        mb_substr($in['email'], 0, 255),
        mb_substr($in['phone'], 0, 50),
        mb_substr($in['theme'], 0, 500),
        $in['location_type'],
        mb_substr($in['location_detail'], 0, 500),
        mb_substr($in['attendees'], 0, 5000),
        mb_substr($in['appendix'], 0, 5000),
        local_to_utc($startLocal, 'Y-m-d H:i'),
        local_to_utc($endLocal, 'Y-m-d H:i'),
        (int)$in['duration'],
        random_token(32),
        random_token(32),
        current_lang(),
        $now,
    ]);
    $id = (int)$pdo->lastInsertId();

    $emails = send_booking_emails($id);
    return ['id' => $id, 'emails' => $emails];
}

/** Send the "request received" e-mail to the visitor and the "new request" e-mail to the admin. */
function send_booking_emails(int $id): array
{
    $errors = [];
    $b = fetch_booking($id);
    if (!$b) {
        return $errors;
    }
    if (!Mailer::configured()) {
        return ['SMTP not configured'];
    }

    // visitor: request received
    [$subj, $html] = email_visitor_request($b);
    [$ok, $err] = Mailer::send($b['email'], $subj, $html);
    if (!$ok) {
        $errors[] = $err;
    }

    // admin: new request with approve/decline buttons
    $adminEmail = (string)setting('admin_email', '');
    if ($adminEmail !== '') {
        $approve = base_url() . '/respond.php?token=' . urlencode($b['admin_token']) . '&action=approve';
        $decline = base_url() . '/respond.php?token=' . urlencode($b['admin_token']) . '&action=decline';
        [$subj2, $html2] = email_admin_request($b, $approve, $decline, current_lang());
        [$ok2, $err2] = Mailer::send($adminEmail, $subj2, $html2);
        if (!$ok2) {
            $errors[] = $err2;
        }
    }
    return $errors;
}

/** Admin e-mail addresses parsed from settings. */
function admin_emails(): array
{
    $raw = (string)setting('admin_email', '');
    if ($raw === '') {
        return [];
    }
    $list = parse_emails($raw);
    return $list ?: [$raw];
}

/** Build the Graph event payload for an approved booking. */
function booking_graph_event(array $b): array
{
    $start = new DateTime($b['start_utc'] . ' UTC');
    $end = new DateTime($b['end_utc'] . ' UTC');
    $lines = [];
    $lines[] = 'Organizer: ' . (string)setting('app_title', 'My Agenda');
    $lines[] = 'Booked by: ' . $b['name'] . ' <' . $b['email'] . '>';
    $lines[] = 'Phone: ' . $b['phone'];
    if (!empty($b['attendees'])) {
        $lines[] = 'Attendees: ' . $b['attendees'];
    }
    if (!empty($b['appendix'])) {
        $lines[] = 'Notes: ' . $b['appendix'];
    }
    $attendees = array_unique(array_merge([$b['email']], parse_emails((string)$b['attendees'])));
    return [
        'subject' => '[' . (string)setting('app_title', 'My Agenda') . ' #' . $b['id'] . '] ' . $b['theme'],
        'start' => ['dateTime' => $start->format('c'), 'timeZone' => 'UTC'],
        'end' => ['dateTime' => $end->format('c'), 'timeZone' => 'UTC'],
        'location' => ['displayName' => location_label($b)],
        'body' => ['contentType' => 'text', 'content' => implode("\n", $lines)],
        'attendees' => array_map(fn($em) => ['emailAddress' => ['address' => $em, 'name' => '']], $attendees),
        'responseRequested' => false,
        'allowNewTimeProposals' => false,
        'isReminderOn' => true,
        'reminderMinutesBeforeStart' => 30,
    ];
}

/** The Outlook calendar id used for write-back, or null. */
function booking_graph_calendar(): ?string
{
    $cal = (string)setting('graph_booking_calendar', '');
    return $cal !== '' ? $cal : null;
}

/**
 * Change a booking's status and run side-effects (e-mails, Graph).
 * @return array{ok:bool, errors:array<int,string>}
 */
function set_booking_status(int $id, string $status, string $reason = ''): array
{
    $errors = [];
    $b = fetch_booking($id);
    if (!$b) {
        return ['ok' => false, 'errors' => ['Booking not found.']];
    }
    $valid = ['pending', 'approved', 'declined', 'cancelled'];
    if (!in_array($status, $valid, true)) {
        return ['ok' => false, 'errors' => ['Invalid status.']];
    }
    if ($b['status'] === $status) {
        return ['ok' => true, 'errors' => []];
    }

    $pdo = db();
    $prevStatus = $b['status'];

    // If we are leaving "approved", remove the event we created in Outlook.
    if ($prevStatus === 'approved' && !empty($b['graph_event_id'])) {
        $calId = booking_graph_calendar();
        if ($calId !== null) {
            $res = graph()->deleteEvent($calId, $b['graph_event_id']);
            if (!$res['ok']) {
                $errors[] = 'Graph event cleanup failed: ' . ($res['data']['error']['message'] ?? 'unknown');
            }
        }
    }

    // If becoming approved, create the event in Outlook.
    $graphEventId = $b['graph_event_id'];
    if ($status === 'approved') {
        $calId = booking_graph_calendar();
        $isGraphReady = graph()->configured() && graph()->isConnected() && $calId !== null;
        if ($isGraphReady) {
            $res = graph()->createEvent($calId, booking_graph_event($b));
            if ($res['ok']) {
                $graphEventId = $res['data']['id'] ?? null;
            } else {
                $msg = $res['data']['error']['message'] ?? 'unknown error';
                $errors[] = 'Graph event creation failed: ' . $msg;
            }
        }
    }

    $stmt = $pdo->prepare(
        'UPDATE bookings SET status = ?, responded_at = ?, graph_event_id = ?, graph_error = ? WHERE id = ?'
    );
    $stmt->execute([
        $status,
        utc_now(),
        $graphEventId,
        implode(' | ', $errors),
        $id,
    ]);
    $b = fetch_booking($id);

    // E-mails
    if (Mailer::configured()) {
        $emailErrors = send_status_emails($b, $status, $reason);
        $errors = array_merge($errors, $emailErrors);
    }
    return ['ok' => true, 'errors' => $errors];
}

/** Send status-change e-mails to visitor and attendees. */
function send_status_emails(array $b, string $status, string $reason): array
{
    $errors = [];
    $attendees = parse_emails((string)$b['attendees']);
    $attendees = array_values(array_filter($attendees, fn($a) => $a !== strtolower($b['email'])));

    switch ($status) {
        case 'approved':
            $ics = booking_ics($b, (string)setting('admin_email', ''));
            [$s, $h, $o] = email_visitor_approved($b, $ics);
            [$ok, $err] = Mailer::send($b['email'], $s, $h, $o);
            if (!$ok) {
                $errors[] = $err;
            }
            $hostName = (string)setting('app_title', 'My Agenda');
            foreach ($attendees as $a) {
                [$s2, $h2, $o2] = email_attendee_approved($b, $ics, $hostName);
                [$ok2, $err2] = Mailer::send($a, $s2, $h2, $o2);
                if (!$ok2) {
                    $errors[] = $err2;
                }
            }
            break;

        case 'declined':
            [$s, $h] = email_visitor_declined($b, $reason);
            [$ok, $err] = Mailer::send($b['email'], $s, $h);
            if (!$ok) {
                $errors[] = $err;
            }
            foreach ($attendees as $a) {
                [$s2, $h2] = email_attendee_declined($b);
                [$ok2, $err2] = Mailer::send($a, $s2, $h2);
                if (!$ok2) {
                    $errors[] = $err2;
                }
            }
            break;

        case 'cancelled':
            [$s, $h] = email_visitor_cancelled($b);
            [$ok, $err] = Mailer::send($b['email'], $s, $h);
            if (!$ok) {
                $errors[] = $err;
            }
            if ($b['graph_event_id']) {
                foreach ($attendees as $a) {
                    [$s2, $h2] = email_attendee_declined($b);
                    [$ok2, $err2] = Mailer::send($a, $s2, $h2);
                    if (!$ok2) {
                        $errors[] = $err2;
                    }
                }
            }
            break;
    }
    return $errors;
}

/** Notify the admin that a visitor cancelled their request. */
function notify_admin_cancelled(array $b): void
{
    if (!Mailer::configured()) {
        return;
    }
    $adminEmail = (string)setting('admin_email', '');
    if ($adminEmail === '') {
        return;
    }
    [$s, $h] = email_admin_cancelled($b, current_lang());
    Mailer::send($adminEmail, $s, $h);
}
