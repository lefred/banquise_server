<?php
declare(strict_types=1);

/**
 * A deliberately small SMTP client: connect, optional STARTTLS, optional
 * AUTH LOGIN, one plain-text message per recipient. No queue, no HTML, no
 * attachments, no third-party dependency, matching the rest of Banquise.
 * A mail failure is reported to the caller but must never be allowed to
 * roll back the database change it was reporting on.
 */
final class BanquiseMailer
{
    public function __construct(private readonly array $config) {}

    public function enabled(): bool
    {
        return (string)($this->config['smtp_host'] ?? '') !== '';
    }

    /**
     * @param array<int,array{email:string,display_name?:string}> $recipients
     * @return string[] Human-readable errors, one per recipient that failed. Empty on full success.
     */
    public function send(array $recipients, string $subject, string $body): array
    {
        if (!$this->enabled()) return ['Mail is not configured (smtp_host is empty).'];
        $errors = [];
        foreach ($recipients as $recipient) {
            $to = (string)($recipient['email'] ?? '');
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) continue;
            try {
                $this->sendOne($to, $subject, $body);
            } catch (Throwable $e) {
                $errors[] = "$to: " . $e->getMessage();
            }
        }
        return $errors;
    }

    private function sendOne(string $to, string $subject, string $body): void
    {
        $host = (string)($this->config['smtp_host'] ?? '');
        $port = (int)($this->config['smtp_port'] ?? 587);
        $encryption = (string)($this->config['smtp_encryption'] ?? 'starttls');
        $from = (string)($this->config['mail_from_address'] ?? '');
        $fromName = (string)($this->config['mail_from_name'] ?? 'Banquise');
        $user = (string)($this->config['smtp_user'] ?? '');
        $password = (string)($this->config['smtp_password'] ?? '');
        if ($from === '') throw new RuntimeException('mail_from_address is not configured');
        $ehloHost = (string)(parse_url((string)($this->config['public_base_url'] ?? ''), PHP_URL_HOST) ?: 'localhost');

        $transport = $encryption === 'tls' ? "ssl://$host" : $host;
        $socket = @stream_socket_client("$transport:$port", $errno, $errstr, 10);
        if ($socket === false) throw new RuntimeException("cannot connect to $host:$port ($errstr)");
        try {
            $this->expect($socket, 220);
            $this->command($socket, "EHLO $ehloHost", 250);
            if ($encryption === 'starttls') {
                $this->command($socket, 'STARTTLS', 220);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('STARTTLS negotiation failed');
                }
                $this->command($socket, "EHLO $ehloHost", 250);
            }
            if ($user !== '') {
                $this->command($socket, 'AUTH LOGIN', 334);
                $this->command($socket, base64_encode($user), 334);
                $this->command($socket, base64_encode($password), 235);
            }
            $this->command($socket, "MAIL FROM:<$from>", 250);
            $this->command($socket, "RCPT TO:<$to>", 250);
            $this->command($socket, 'DATA', 354);
            // Header values are trusted (built from config/DB, not raw user input reaching
            // here unescaped), but CR/LF are stripped anyway as defense in depth.
            $safeSubject = str_replace(["\r", "\n"], ' ', $subject);
            $headers = "From: {$this->encodeHeader($fromName)} <$from>\r\n"
                . "To: <$to>\r\n"
                . "Subject: {$this->encodeHeader($safeSubject)}\r\n"
                . "Date: " . date('r') . "\r\n"
                . "MIME-Version: 1.0\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: 8bit\r\n";
            $payload = str_replace("\n.", "\n..", str_replace("\r\n", "\n", $body));
            fwrite($socket, $headers . "\r\n" . $payload . "\r\n.\r\n");
            $this->expect($socket, 250);
            $this->command($socket, 'QUIT', 221);
        } finally {
            fclose($socket);
        }
    }

    private function encodeHeader(string $value): string
    {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    /** @param resource $socket */
    private function command($socket, string $line, int $expectedCode): void
    {
        fwrite($socket, $line . "\r\n");
        $this->expect($socket, $expectedCode);
    }

    /** @param resource $socket */
    private function expect($socket, int $expectedCode): void
    {
        $line = '';
        do {
            $line = fgets($socket, 512);
            if ($line === false) throw new RuntimeException('SMTP connection closed unexpectedly');
        } while (isset($line[3]) && $line[3] === '-'); // multi-line response, keep reading
        if ((int)substr($line, 0, 3) !== $expectedCode) {
            throw new RuntimeException('unexpected SMTP response: ' . trim($line));
        }
    }
}
