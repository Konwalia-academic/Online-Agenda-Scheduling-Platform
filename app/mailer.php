<?php
/*
 * Self-contained SMTP mailer using PHP streams. No external dependencies.
 * Supports: TLS/SSL/STARTTLS, AUTH LOGIN/PLAIN, HTML+text, attachments, BCC.
 */
declare(strict_types=1);

final class Mailer
{
    /** Read the SMTP settings stored in the settings table. */
    public static function config(): array
    {
        $pass = setting('smtp_pass', '');
        $plain = $pass === '' ? '' : (decrypt_value($pass) ?? '');
        return [
            'host' => (string)setting('smtp_host', ''),
            'port' => (int)(setting('smtp_port', 587)),
            'enc' => (string)setting('smtp_enc', 'tls'),
            'user' => (string)setting('smtp_user', ''),
            'pass' => $plain,
            'from' => (string)setting('smtp_from', ''),
            'from_name' => (string)setting('smtp_from_name', ''),
        ];
    }

    public static function configured(): bool
    {
        $c = self::config();
        return $c['host'] !== '' && $c['from'] !== '';
    }

    /**
     * Send an e-mail.
     * @param string|array $to  single address or list of addresses
     * @param string $subject
     * @param string $html  HTML body
     * @param array $opts  ['text'=>string, 'bcc'=>array, 'attachments'=>array<['name'=>, 'data'=>, 'mime'=>]>]
     * @return array [bool $ok, string $error]
     */
    public static function send(string|array $to, string $subject, string $html, array $opts = []): array
    {
        $cfg = self::config();
        if ($cfg['host'] === '') {
            return [false, t('mail_not_configured')];
        }

        $toList = is_array($to) ? array_values(array_filter($to, fn($x) => $x !== '')) : [$to];
        $bccList = array_values(array_filter($opts['bcc'] ?? [], fn($x) => $x !== ''));
        if (count($toList) === 0) {
            return [false, 'No recipient.'];
        }

        $from = $cfg['from'];
        $fromName = $cfg['from_name'] !== '' ? $cfg['from_name'] : $cfg['from'];
        $text = $opts['text'] ?? strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $html));

        $boundaryMixed = 'agenda_' . bin2hex(random_bytes(8)) . '_mixed';
        $boundaryAlt = 'agenda_' . bin2hex(random_bytes(8)) . '_alt';

        $attachments = $opts['attachments'] ?? [];
        $hasAttach = count($attachments) > 0;

        $headers = [];
        $headers[] = 'From: ' . self::encodeHeader($fromName) . ' <' . $from . '>';
        $headers[] = 'To: ' . self::encodeHeader(implode(', ', $toList));
        $headers[] = 'Subject: ' . self::encodeSubject($subject);
        $headers[] = 'Date: ' . date(DATE_RFC2822);
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'X-Mailer: Agenda Platform';
        if (count($bccList) > 0) {
            $headers[] = 'Bcc: ' . $bccList[0];
        }

        $textBody = self::encodeBody($text);
        $htmlBody = self::encodeBody($html);

        if ($hasAttach) {
            $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundaryMixed . '"';
            $body = "--{$boundaryMixed}\r\n";
            $body .= "Content-Type: multipart/alternative; boundary=\"{$boundaryAlt}\"\r\n\r\n";
        } else {
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundaryAlt . '"';
            $body = '';
        }

        $body .= "--{$boundaryAlt}\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= $textBody . "\r\n";

        $body .= "--{$boundaryAlt}\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= $htmlBody . "\r\n";
        $body .= "--{$boundaryAlt}--\r\n";

        if ($hasAttach) {
            foreach ($attachments as $att) {
                $body .= "--{$boundaryMixed}\r\n";
                $body .= 'Content-Type: ' . ($att['mime'] ?? 'application/octet-stream') . "; name=\"" . $att['name'] . "\"\r\n";
                $body .= 'Content-Disposition: attachment; filename="' . $att['name'] . "\"\r\n";
                $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
                $body .= chunk_split(base64_encode($att['data'] ?? ''), 76, "\r\n");
            }
            $body .= "--{$boundaryMixed}--\r\n";
        }

        $message = implode("\r\n", $headers) . "\r\n\r\n" . $body;

        return self::smtpSend($cfg, $from, array_merge($toList, $bccList), $message);
    }

    private static function smtpSend(array $cfg, string $from, array $recipients, string $message): array
    {
        $host = $cfg['host'];
        $port = $cfg['port'];
        $enc = $cfg['enc'];

        $remote = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false]]);
        $fp = @stream_socket_client($remote, $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            return [false, "Connection failed: $errstr"];
        }
        stream_set_timeout($fp, 30);

        if (!self::expect($fp, '220')) {
            return [false, 'Server greeting failed: ' . self::readLine($fp)];
        }

        $ehlo = self::cmd($fp, 'EHLO ' . (gethostname() ?: 'localhost'));
        if (!self::ok($ehlo)) {
            return [false, 'EHLO failed: ' . $ehlo];
        }

        $capabilities = strtoupper(implode(' ', $ehlo));

        if ($enc === 'tls') {
            if (!self::ok(self::cmd($fp, 'STARTTLS'))) {
                return [false, 'STARTTLS not supported by server.'];
            }
            $ok = stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if (!$ok) {
                return [false, 'TLS negotiation failed.'];
            }
            $ehlo = self::cmd($fp, 'EHLO ' . (gethostname() ?: 'localhost'));
            if (!self::ok($ehlo)) {
                return [false, 'EHLO (after TLS) failed.'];
            }
            $capabilities = strtoupper(implode(' ', $ehlo));
        }

        $authenticated = false;
        if ($cfg['user'] !== '') {
            if (str_contains($capabilities, 'AUTH') && !self::tryAuth($fp, $cfg['user'], $cfg['pass'], $capabilities)) {
                fclose($fp);
                return [false, 'SMTP authentication failed (check username / app password).'];
            }
            $authenticated = true;
        }

        if (!self::ok(self::cmd($fp, 'MAIL FROM:<' . $from . '>'))) {
            fclose($fp);
            return [false, 'MAIL FROM rejected by server.'];
        }

        $seen = [];
        foreach ($recipients as $rcpt) {
            $rcpt = strtolower(trim($rcpt));
            if ($rcpt === '' || isset($seen[$rcpt])) {
                continue;
            }
            if (!filter_var($rcpt, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $seen[$rcpt] = true;
            if (!self::ok(self::cmd($fp, 'RCPT TO:<' . $rcpt . '>'))) {
                fclose($fp);
                return [false, 'Recipient rejected: ' . $rcpt];
            }
        }
        if (count($seen) === 0) {
            fclose($fp);
            return [false, 'No valid recipients.'];
        }

        if (!self::ok(self::cmd($fp, 'DATA'))) {
            fclose($fp);
            return [false, 'DATA command rejected.'];
        }

        // dot-stuffing: lines starting with '.' get an extra '.'
        $data = preg_replace('/^\./m', '..', $message);
        if (substr($data, -2) !== "\r\n") {
            $data .= "\r\n";
        }
        $data .= ".\r\n";
        fwrite($fp, $data);

        if (!self::expect($fp, '250')) {
            $err = self::readLine($fp);
            self::cmd($fp, 'QUIT');
            fclose($fp);
            return [false, 'Server rejected message: ' . $err];
        }

        self::cmd($fp, 'QUIT');
        fclose($fp);
        return [true, ''];
    }

    private static function tryAuth($fp, string $user, string $pass, string $caps): bool
    {
        if (str_contains($caps, 'AUTH PLAIN') || str_contains($caps, 'AUTH LOGIN')) {
            $b64 = base64_encode("\0" . $user . "\0" . $pass);
            if (str_contains($caps, 'AUTH PLAIN')) {
                if (self::ok(self::cmd($fp, 'AUTH PLAIN ' . $b64))) {
                    return true;
                }
            } else {
                if (!self::ok(self::cmd($fp, 'AUTH LOGIN'))) {
                    return false;
                }
                if (!self::ok(self::cmd($fp, base64_encode($user)))) {
                    return false;
                }
                return self::ok(self::cmd($fp, base64_encode($pass)));
            }
            // fallback to LOGIN if PLAIN failed
            if (!self::ok(self::cmd($fp, 'AUTH LOGIN'))) {
                return false;
            }
            if (!self::ok(self::cmd($fp, base64_encode($user)))) {
                return false;
            }
            return self::ok(self::cmd($fp, base64_encode($pass)));
        }
        return false;
    }

    private static function cmd($fp, string $cmd): array
    {
        fwrite($fp, $cmd . "\r\n");
        $lines = [];
        do {
            $line = fgets($fp, 1024);
            if ($line === false) {
                break;
            }
            $lines[] = rtrim($line, "\r\n");
        } while (isset($line[3]) && $line[3] === '-' && strlen($line) > 3);
        return $lines;
    }

    private static function expect($fp, string $code): bool
    {
        $line = self::readLine($fp);
        return $line !== '' && str_starts_with($line, $code);
    }

    private static function readLine($fp): string
    {
        $line = fgets($fp, 1024);
        return $line === false ? '' : rtrim($line, "\r\n");
    }

    private static function ok(array $lines): bool
    {
        return count($lines) > 0 && str_starts_with($lines[0], '2');
    }

    private static function encodeSubject(string $subject): string
    {
        if (preg_match('/[^\x20-\x7E]/', $subject)) {
            return '=?UTF-8?B?' . base64_encode($subject) . '?=';
        }
        return $subject;
    }

    private static function encodeHeader(string $value): string
    {
        if (preg_match('/[^\x20-\x7E]/', $value)) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }
        return $value;
    }

    private static function encodeBody(string $text): string
    {
        return chunk_split(base64_encode($text), 76, "\r\n");
    }
}
