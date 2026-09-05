<?php
/*
 * E-mail templates and .ics generation for all booking lifecycle messages.
 */
declare(strict_types=1);

/** Human-readable location string for a booking row. */
function location_label(array $b, string $lang = 'en'): string
{
    $labels = $lang === 'zh' ? [
        'zoom' => 'Zoom 会议',
        'tencent' => '腾讯会议',
        'phone' => '电话通话',
        'inperson' => '线下见面',
    ] : [
        'zoom' => 'Zoom Meeting',
        'tencent' => 'Tencent Meeting',
        'phone' => 'Phone Call',
        'inperson' => 'Meet in Person',
    ];
    $label = $labels[$b['location_type']] ?? $b['location_type'];
    if (!empty($b['location_detail'])) {
        $label .= ' — ' . $b['location_detail'];
    }
    return $label;
}

function fmt_email_time(string $utcStr): string
{
    return utc_to_local($utcStr)->format('Y-m-d H:i (D)');
}

/** Build an .ics attachment for a booking. */
function booking_ics(array $b, string $organizerEmail): string
{
    $start = new DateTime($b['start_utc'] . ' UTC');
    $end = new DateTime($b['end_utc'] . ' UTC');
    $summary = '[' . t('book') . '] ' . $b['theme'];
    $location = location_label($b);
    $descLines = [];
    $descLines[] = 'Name: ' . $b['name'];
    $descLines[] = 'Phone: ' . $b['phone'];
    if (!empty($b['attendees'])) {
        $descLines[] = 'Attendees: ' . $b['attendees'];
    }
    if (!empty($b['appendix'])) {
        $descLines[] = 'Notes: ' . $b['appendix'];
    }
    $desc = implode("\n", $descLines);
    return implode("\r\n", [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//Agenda Platform//CN',
        'METHOD:PUBLISH',
        'CALSCALE:GREGORIAN',
        'BEGIN:VEVENT',
        'UID:booking-' . $b['id'] . '@agenda',
        'DTSTAMP:' . gmdate('Ymd\\THis\\Z'),
        'DTSTART:' . $start->format('Ymd\\THis\\Z'),
        'DTEND:' . $end->format('Ymd\\THis\\Z'),
        'SUMMARY:' . $summary,
        'LOCATION:' . $location,
        'DESCRIPTION:' . str_replace(["\n", ','], ["\\n", '\\,'], $desc),
        'ORGANIZER;CN=Agenda:mailto:' . $organizerEmail,
        'ATTENDEE;CN=' . $b['name'] . ':mailto:' . $b['email'],
        'END:VEVENT',
        'END:VCALENDAR',
    ]);
}

function mail_shell(string $lang, string $title, string $innerHtml): string
{
    $brand = (string)setting('theme_color', '#3b82f6');
    $dir = $lang === 'zh' ? 'rtl' : 'ltr';
    return '<div dir="' . $dir . '" style="margin:0;padding:24px;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;">'
        . '<div style="max-width:640px;margin:0 auto;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e5e7eb;">'
        . '<div style="background:' . $brand . ';color:#fff;padding:18px 24px;font-size:18px;font-weight:bold;">' . e((string)setting('app_title', 'My Agenda')) . '</div>'
        . '<div style="padding:24px;color:#1f2937;font-size:14px;line-height:1.7;">'
        . '<h2 style="margin:0 0 16px;font-size:17px;">' . e($title) . '</h2>'
        . $innerHtml
        . '</div>'
        . '<div style="padding:14px 24px;background:#f9fafb;color:#6b7280;font-size:12px;border-top:1px solid #e5e7eb;">' . e((string)setting('app_title', 'My Agenda')) . ' · ' . base_url() . '</div>'
        . '</div></div>';
}

function mail_button(string $url, string $label, string $color = '#10b981'): string
{
    return '<a href="' . e($url) . '" style="display:inline-block;margin:6px 8px 6px 0;padding:10px 18px;border-radius:8px;color:#ffffff;background:' . $color . ';text-decoration:none;font-weight:bold;">' . e($label) . '</a>';
}

/** To visitor: request received. */
function email_visitor_request(array $b): array
{
    $lang = $b['lang'] === 'zh' ? 'zh' : 'en';
    $s = $lang === 'zh' ? '您的预约申请已收到' : 'Your booking request has been received';
    $cancelUrl = base_url() . '/book.php?cancel=' . $b['invite_token'];
    $html = mail_shell($lang, $s,
        '<p>' . ($lang === 'zh' ? '感谢您的预约。以下是申请详情，请您核对：' : 'Thank you for your booking. Here are the details:') . '</p>'
        . '<table style="width:100%;border-collapse:collapse;">'
        . '<tr><td style="padding:6px 0;color:#6b7280;width:120px;">' . ($lang === 'zh' ? '时间' : 'Time') . '</td><td><b>' . e(fmt_email_time($b['start_utc'])) . '</b></td></tr>'
        . '<tr><td style="padding:6px 0;color:#6b7280;">' . ($lang === 'zh' ? '时长' : 'Duration') . '</td><td>' . e(human_minutes((int)$b['duration_min'])) . '</td></tr>'
        . '<tr><td style="padding:6px 0;color:#6b7280;">' . ($lang === 'zh' ? '主题' : 'Topic') . '</td><td>' . e($b['theme']) . '</td></tr>'
        . '<tr><td style="padding:6px 0;color:#6b7280;">' . ($lang === 'zh' ? '地点' : 'Location') . '</td><td>' . e(location_label($b, $lang)) . '</td></tr>'
        . '</table>'
        . '<p>' . ($lang === 'zh' ? '您的申请已发送，等待确认。一旦确认或拒绝，您将收到通知。' : 'Your request has been sent and is awaiting confirmation. You will be notified once it is confirmed or declined.') . '</p>'
        . '<p style="font-size:12px;color:#9ca3af;">' . ($lang === 'zh' ? '如需取消，请点击：' : 'To cancel this request, click: ') . '</p>'
        . mail_button($cancelUrl, $lang === 'zh' ? '取消申请' : 'Cancel request', '#ef4444')
    );
    return [$lang === 'zh' ? '预约申请已收到（日程预约平台）' : 'Booking request received (Agenda Platform)', $html];
}

/** To admin: new request with approve/decline buttons. */
function email_admin_request(array $b, string $approveUrl, string $declineUrl, string $lang): array
{
    $s = $lang === 'zh' ? '新的预约申请：' . $b['theme'] : 'New booking request: ' . $b['theme'];
    $html = mail_shell($lang, $s,
        '<p>' . ($lang === 'zh' ? '收到一条新的预约申请：' : 'A new booking request has been received:') . '</p>'
        . '<table style="width:100%;border-collapse:collapse;">'
        . '<tr><td style="padding:6px 0;color:#6b7280;width:120px;">' . ($lang === 'zh' ? '姓名' : 'Name') . '</td><td>' . e($b['name']) . '</td></tr>'
        . '<tr><td style="padding:6px 0;color:#6b7280;">' . ($lang === 'zh' ? '邮箱' : 'E-mail') . '</td><td>' . e($b['email']) . '</td></tr>'
        . '<tr><td style="padding:6px 0;color:#6b7280;">' . ($lang === 'zh' ? '电话' : 'Phone') . '</td><td>' . e($b['phone']) . '</td></tr>'
        . '<tr><td style="padding:6px 0;color:#6b7280;">' . ($lang === 'zh' ? '主题' : 'Topic') . '</td><td><b>' . e($b['theme']) . '</b></td></tr>'
        . '<tr><td style="padding:6px 0;color:#6b7280;">' . ($lang === 'zh' ? '时间' : 'Time') . '</td><td><b>' . e(fmt_email_time($b['start_utc'])) . '</b></td></tr>'
        . '<tr><td style="padding:6px 0;color:#6b7280;">' . ($lang === 'zh' ? '时长' : 'Duration') . '</td><td>' . e(human_minutes((int)$b['duration_min'])) . '</td></tr>'
        . '<tr><td style="padding:6px 0;color:#6b7280;">' . ($lang === 'zh' ? '地点' : 'Location') . '</td><td>' . e(location_label($b, $lang)) . '</td></tr>'
        . '</table>'
        . ($b['attendees'] !== '' ? '<p>' . ($lang === 'zh' ? '其他参会人：' : 'Other attendees: ') . e($b['attendees']) . '</p>' : '')
        . ($b['appendix'] !== '' ? '<p>' . ($lang === 'zh' ? '附件/补充：' : 'Appendix: ') . e($b['appendix']) . '</p>' : '')
        . '<p style="margin-top:18px;">' . ($lang === 'zh' ? '请处理该申请：' : 'Please respond:') . '</p>'
        . mail_button($approveUrl, $lang === 'zh' ? '批准并创建事件' : 'Approve & create event', '#10b981')
        . mail_button($declineUrl, $lang === 'zh' ? '拒绝' : 'Decline', '#ef4444')
    );
    return [$lang === 'zh' ? '新预约申请（日程预约平台）' : 'New booking request (Agenda Platform)', $html];
}

/** To visitor: approved. Optionally attach .ics. */
function email_visitor_approved(array $b, string $ics): array
{
    $lang = $b['lang'] === 'zh' ? 'zh' : 'en';
    $html = mail_shell($lang, $lang === 'zh' ? '您的预约已确认' : 'Your booking is confirmed',
        '<p>' . ($lang === 'zh' ? '好消息！您的预约申请已被批准：' : 'Good news! Your booking request has been approved:') . '</p>'
        . '<table style="width:100%;border-collapse:collapse;">'
        . '<tr><td style="padding:6px 0;color:#6b7280;width:120px;">' . ($lang === 'zh' ? '时间' : 'Time') . '</td><td><b>' . e(fmt_email_time($b['start_utc'])) . '</b></td></tr>'
        . '<tr><td style="padding:6px 0;color:#6b7280;">' . ($lang === 'zh' ? '时长' : 'Duration') . '</td><td>' . e(human_minutes((int)$b['duration_min'])) . '</td></tr>'
        . '<tr><td style="padding:6px 0;color:#6b7280;">' . ($lang === 'zh' ? '主题' : 'Topic') . '</td><td>' . e($b['theme']) . '</td></tr>'
        . '<tr><td style="padding:6px 0;color:#6b7280;">' . ($lang === 'zh' ? '地点' : 'Location') . '</td><td>' . e(location_label($b, $lang)) . '</td></tr>'
        . '</table>'
        . '<p>' . ($lang === 'zh' ? '附上日历文件，欢迎添加到您的日历中。期待与您会面！' : 'A calendar invite is attached — add it to your calendar. Looking forward to it!') . '</p>'
    );
    return [$lang === 'zh' ? '预约已确认（日程预约平台）' : 'Booking confirmed (Agenda Platform)', $html, ['attachments' => [['name' => 'invite.ics', 'data' => $ics, 'mime' => 'text/calendar']]]];
}

/** To attendees: approved invitation. */
function email_attendee_approved(array $b, string $ics, string $hostName): array
{
    $lang = $b['lang'] === 'zh' ? 'zh' : 'en';
    $html = mail_shell($lang, $lang === 'zh' ? '您受邀参加一项活动' : 'You are invited to an event',
        '<p>' . ($lang === 'zh' ? $hostName . ' 邀请您参加以下活动：' : $hostName . ' invites you to:') . '</p>'
        . '<table style="width:100%;border-collapse:collapse;">'
        . '<tr><td style="padding:6px 0;color:#6b7280;width:120px;">' . ($lang === 'zh' ? '主题' : 'Topic') . '</td><td><b>' . e($b['theme']) . '</b></td></tr>'
        . '<tr><td style="padding:6px 0;color:#6b7280;">' . ($lang === 'zh' ? '时间' : 'Time') . '</td><td>' . e(fmt_email_time($b['start_utc'])) . '</td></tr>'
        . '<tr><td style="padding:6px 0;color:#6b7280;">' . ($lang === 'zh' ? '地点' : 'Location') . '</td><td>' . e(location_label($b, $lang)) . '</td></tr>'
        . '</table>'
        . '<p>' . ($lang === 'zh' ? '详情见附件日历文件。' : 'Details are in the attached calendar file.') . '</p>'
    );
    return [$lang === 'zh' ? '活动邀请（日程预约平台）' : 'Event invitation (Agenda Platform)', $html, ['attachments' => [['name' => 'invite.ics', 'data' => $ics, 'mime' => 'text/calendar']]]];
}

/** To visitor: declined. */
function email_visitor_declined(array $b, string $reason): array
{
    $lang = $b['lang'] === 'zh' ? 'zh' : 'en';
    $html = mail_shell($lang, $lang === 'zh' ? '您的预约申请未被批准' : 'Your booking request was declined',
        '<p>' . ($lang === 'zh' ? '很抱歉，您的以下预约申请未获批准：' : 'Unfortunately, your booking request was not approved:') . '</p>'
        . '<p><b>' . e(fmt_email_time($b['start_utc'])) . '</b> · ' . e($b['theme']) . '</p>'
        . ($reason !== '' ? '<p>' . ($lang === 'zh' ? '原因：' : 'Reason: ') . e($reason) . '</p>' : '')
        . '<p>' . ($lang === 'zh' ? '您可以重新选择其他空闲时间再次预约。' : 'You are welcome to book another free time slot.') . '</p>'
    );
    return [$lang === 'zh' ? '预约未获批准（日程预约平台）' : 'Booking not approved (Agenda Platform)', $html];
}

/** To attendee: event cancelled. */
function email_attendee_declined(array $b): array
{
    $lang = $b['lang'] === 'zh' ? 'zh' : 'en';
    $html = mail_shell($lang, $lang === 'zh' ? '活动已取消' : 'Event cancelled',
        '<p>' . ($lang === 'zh' ? '原计划于 ' . e(fmt_email_time($b['start_utc'])) . ' 的活动已取消：' . e($b['theme']) : 'An event you were invited to has been cancelled: ' . e($b['theme']) . ' on ' . e(fmt_email_time($b['start_utc']))) . '</p>'
    );
    return [$lang === 'zh' ? '活动已取消（日程预约平台）' : 'Event cancelled (Agenda Platform)', $html];
}

/** To visitor: their request was cancelled (by admin or by themselves). */
function email_visitor_cancelled(array $b): array
{
    $lang = $b['lang'] === 'zh' ? 'zh' : 'en';
    $html = mail_shell($lang, $lang === 'zh' ? '预约申请已取消' : 'Booking request cancelled',
        '<p>' . ($lang === 'zh' ? '您的预约申请已取消：' : 'Your booking request has been cancelled:') . '</p>'
        . '<p><b>' . e(fmt_email_time($b['start_utc'])) . '</b> · ' . e($b['theme']) . '</p>'
    );
    return [$lang === 'zh' ? '预约已取消（日程预约平台）' : 'Booking cancelled (Agenda Platform)', $html];
}

/** To admin: a request was cancelled by the visitor. */
function email_admin_cancelled(array $b, string $lang): array
{
    $html = mail_shell($lang, $lang === 'zh' ? '访客取消了预约申请' : 'A visitor cancelled their booking request',
        '<p>' . ($lang === 'zh' ? '以下预约申请已被访客取消：' : 'The following request was cancelled by the visitor:') . '</p>'
        . '<p><b>' . e(fmt_email_time($b['start_utc'])) . '</b> · ' . e($b['theme']) . ' · ' . e($b['name']) . ' &lt;' . e($b['email']) . '&gt;</p>'
    );
    return [$lang === 'zh' ? '预约申请被取消（日程预约平台）' : 'Booking request cancelled (Agenda Platform)', $html];
}
