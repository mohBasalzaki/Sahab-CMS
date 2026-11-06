<?php
declare(strict_types=1);

namespace Sahab;

/** Sends via SMTP when configured in settings, otherwise PHP mail(). Plugins can override via the "mail.send" filter. */
final class Mailer
{
    public static function send(string $to, string $subject, string $body, ?string $replyTo = null): bool
    {
        $k = Kernel::$i;
        $clean = static fn(string $s) => trim(str_replace(["\r", "\n", "\0"], ' ', $s));
        $to = $clean($to); $subject = $clean($subject); $replyTo = $replyTo ? $clean($replyTo) : null;
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
        if ($replyTo && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) $replyTo = null;

        $override = Hooks::filter('mail.send', null, $to, $subject, $body, $replyTo);
        if ($override !== null) return (bool)$override;

        $host = (string)$k->setting('smtp_host', '');
        $fromAddr = (string)$k->setting('mail_from', '') ?: 'no-reply@' . preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
        $fromName = $clean((string)$k->setting('site_title', 'Sahab'));
        $headers = [
            'From: =?UTF-8?B?' . base64_encode($fromName) . '?= <' . $clean($fromAddr) . '>',
            'MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8', 'Content-Transfer-Encoding: base64',
        ];
        if ($replyTo) $headers[] = 'Reply-To: ' . $replyTo;
        $subj = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $payload = chunk_split(base64_encode($body));

        if ($host === '') return @mail($to, $subj, $payload, implode("\r\n", $headers));
        return self::smtp($host, (int)$k->setting('smtp_port', 587), (string)$k->setting('smtp_secure', 'tls'),
            (string)$k->setting('smtp_user', ''), (string)$k->setting('smtp_pass', ''), $clean($fromAddr), $to,
            'To: ' . $to . "\r\nSubject: " . $subj . "\r\nDate: " . date('r') . "\r\n" . implode("\r\n", $headers) . "\r\n\r\n" . $payload);
    }

    private static function smtp(string $host, int $port, string $secure, string $user, string $pass, string $from, string $to, string $data): bool
    {
        $s = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port, $en, $es, 10);
        if (!$s) return false;
        stream_set_timeout($s, 10);
        $read = function () use ($s) { $r = ''; while (($l = fgets($s, 515)) !== false) { $r .= $l; if (strlen($l) < 4 || $l[3] === ' ') break; } return $r; };
        $cmd = function (string $c, string $ok) use ($s, $read) { if ($c !== '') fwrite($s, $c . "\r\n"); return str_starts_with($read(), $ok); };
        try {
            if (!$cmd('', '220')) return false;
            $ehlo = 'EHLO ' . preg_replace('/[^A-Za-z0-9.\-]/', '', $_SERVER['SERVER_NAME'] ?? 'localhost');
            if (!$cmd($ehlo, '250')) return false;
            if ($secure === 'tls') {
                if (!$cmd('STARTTLS', '220') || !stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) return false;
                if (!$cmd($ehlo, '250')) return false;
            }
            if ($user !== '') {
                if (!$cmd('AUTH LOGIN', '334') || !$cmd(base64_encode($user), '334') || !$cmd(base64_encode($pass), '235')) return false;
            }
            if (!$cmd("MAIL FROM:<$from>", '250') || !$cmd("RCPT TO:<$to>", '25') || !$cmd('DATA', '354')) return false;
            $data = preg_replace('/^\./m', '..', $data);
            fwrite($s, $data . "\r\n.\r\n");
            $ok = str_starts_with($read(), '250');
            $cmd('QUIT', '221');
            return $ok;
        } finally {
            fclose($s);
        }
    }
}
