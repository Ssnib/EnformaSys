<?php
/**
 * EnformaSys Lightweight Dependency-Free Gmail SMTP Mailer
 * Supports STARTTLS (port 587) and SMTPS/SSL (port 465) over native PHP sockets
 * for full compatibility with InfinityFree shared hosting, XAMPP, and cloud PHP.
 */

require_once __DIR__ . '/env.php';

if (!function_exists('enformasys_smtp_config')) {
    /**
     * Returns resolved SMTP configuration from includes/config.php or environment variables.
     *
     * @return array{host:string,port:int,encryption:string,user:string,pass:string,from_email:string,from_name:string}
     */
    function enformasys_smtp_config(): array
    {
        $host = trim((string)enformasys_env('SMTP_HOST', 'smtp.gmail.com'));
        $port = (int)enformasys_env('SMTP_PORT', '587');
        if ($port <= 0) {
            $port = 587;
        }
        $encryption = strtolower(trim((string)enformasys_env('SMTP_ENCRYPTION', $port === 465 ? 'ssl' : 'tls')));
        if (!in_array($encryption, ['tls', 'ssl', 'none'], true)) {
            $encryption = $port === 465 ? 'ssl' : 'tls';
        }
        $user = trim((string)enformasys_env('SMTP_USER', ''));
        $pass = (string)enformasys_env('SMTP_PASS', '');
        $fromEmail = trim((string)enformasys_env('SMTP_FROM_EMAIL', ''));
        if ($fromEmail === '') {
            $fromEmail = $user;
        }
        $fromName = trim((string)enformasys_env('SMTP_FROM_NAME', 'EnformaSys Seminary Portal'));
        if ($fromName === '') {
            $fromName = 'EnformaSys Seminary Portal';
        }

        return [
            'host' => $host,
            'port' => $port,
            'encryption' => $encryption,
            'user' => $user,
            'pass' => $pass,
            'from_email' => $fromEmail,
            'from_name' => $fromName,
        ];
    }
}

if (!function_exists('enformasys_smtp_is_configured')) {
    /**
     * Checks whether real SMTP credentials are configured (excluding template placeholders).
     */
    function enformasys_smtp_is_configured(): bool
    {
        $cfg = enformasys_smtp_config();
        if ($cfg['host'] === '' || $cfg['user'] === '' || trim($cfg['pass']) === '') {
            return false;
        }
        $uLower = strtolower($cfg['user']);
        $pLower = strtolower(trim($cfg['pass']));
        if (
            strpos($uLower, 'your_seminary_email') !== false
            || strpos($uLower, 'example.com') !== false
            || strpos($pLower, 'your_16_char') !== false
            || strpos($pLower, 'your_app_password') !== false
        ) {
            return false;
        }
        return filter_var($cfg['from_email'], FILTER_VALIDATE_EMAIL) !== false;
    }
}

if (!function_exists('enformasys_mask_email')) {
    /**
     * Masks an email address for safe UI display (e.g., cl***@gmail.com).
     */
    function enformasys_mask_email(string $email): string
    {
        $email = trim($email);
        $parts = explode('@', $email, 2);
        if (count($parts) !== 2) {
            return '***';
        }
        $local = $parts[0];
        $domain = $parts[1];
        $len = strlen($local);
        if ($len <= 1) {
            $visible = $local;
        } elseif ($len === 2) {
            $visible = substr($local, 0, 1);
        } else {
            $visible = substr($local, 0, 2);
        }
        return $visible . '***@' . $domain;
    }
}

if (!function_exists('enformasys_smtp_read_response')) {
    /**
     * Reads a complete single- or multi-line SMTP server response.
     *
     * @param resource $socket
     * @return array{code:int,raw:string}
     */
    function enformasys_smtp_read_response($socket): array
    {
        $raw = '';
        $code = 0;
        while (!feof($socket)) {
            $line = fgets($socket, 1024);
            if ($line === false) {
                break;
            }
            $raw .= $line;
            if (preg_match('/^(\d{3})([ \-])/', $line, $m)) {
                $code = (int)$m[1];
                if ($m[2] === ' ') {
                    break;
                }
            } else {
                break;
            }
        }
        return ['code' => $code, 'raw' => trim($raw)];
    }
}

if (!function_exists('enformasys_smtp_command')) {
    /**
     * Sends an SMTP command and verifies the response code against expected codes.
     *
     * @param resource $socket
     * @param int[] $expectedCodes
     * @return array{ok:bool,code:int,raw:string}
     */
    function enformasys_smtp_command($socket, string $command, array $expectedCodes): array
    {
        if (@fwrite($socket, $command . "\r\n") === false) {
            return ['ok' => false, 'code' => 0, 'raw' => 'Failed to write to SMTP socket.'];
        }
        $resp = enformasys_smtp_read_response($socket);
        return [
            'ok' => in_array($resp['code'], $expectedCodes, true),
            'code' => $resp['code'],
            'raw' => $resp['raw'],
        ];
    }
}

if (!function_exists('enformasys_send_smtp_mail')) {
    /**
     * Sends an HTML + plain-text email via authenticated TLS/SSL SMTP.
     *
     * @return array{ok:bool,error:string}
     */
    function enformasys_send_smtp_mail(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $textBody = ''
    ): array {
        $toEmail = trim($toEmail);
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Invalid recipient email address.'];
        }

        if (!enformasys_smtp_is_configured()) {
            return ['ok' => false, 'error' => 'SMTP email delivery is not configured yet. Please configure SMTP_USER and SMTP_PASS in includes/config.php.'];
        }

        $cfg = enformasys_smtp_config();
        $host = $cfg['host'];
        $port = $cfg['port'];
        $enc = $cfg['encryption'];

        $remote = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $ctx = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'SNI_enabled' => true,
                'peer_name' => $host,
            ],
        ]);

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$socket) {
            return ['ok' => false, 'error' => "Could not connect to SMTP server ({$host}:{$port}): {$errstr}"];
        }

        stream_set_timeout($socket, 15);

        try {
            $banner = enformasys_smtp_read_response($socket);
            if ($banner['code'] !== 220) {
                return ['ok' => false, 'error' => 'SMTP server rejected connection: ' . $banner['raw']];
            }

            $ehloHost = preg_replace('/[^a-zA-Z0-9.\-]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
            if ($ehloHost === '') {
                $ehloHost = 'localhost';
            }

            $r = enformasys_smtp_command($socket, "EHLO {$ehloHost}", [250]);
            if (!$r['ok']) {
                return ['ok' => false, 'error' => 'SMTP EHLO failed: ' . $r['raw']];
            }

            if ($enc === 'tls') {
                $r = enformasys_smtp_command($socket, 'STARTTLS', [220]);
                if (!$r['ok']) {
                    return ['ok' => false, 'error' => 'SMTP STARTTLS failed: ' . $r['raw']];
                }
                $cryptoMethod = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                    $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
                }
                $tlsOk = @stream_socket_enable_crypto($socket, true, $cryptoMethod);
                if (!$tlsOk) {
                    $tlsOk = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                }
                if (!$tlsOk) {
                    return ['ok' => false, 'error' => 'TLS encryption handshake failed with SMTP server.'];
                }
                $r = enformasys_smtp_command($socket, "EHLO {$ehloHost}", [250]);
                if (!$r['ok']) {
                    return ['ok' => false, 'error' => 'SMTP post-TLS EHLO failed: ' . $r['raw']];
                }
            }

            // AUTH LOGIN
            $r = enformasys_smtp_command($socket, 'AUTH LOGIN', [334]);
            if (!$r['ok']) {
                return ['ok' => false, 'error' => 'SMTP AUTH LOGIN rejected: ' . $r['raw']];
            }
            $r = enformasys_smtp_command($socket, base64_encode($cfg['user']), [334]);
            if (!$r['ok']) {
                return ['ok' => false, 'error' => 'SMTP username rejected: ' . $r['raw']];
            }
            $r = enformasys_smtp_command($socket, base64_encode($cfg['pass']), [235]);
            if (!$r['ok']) {
                return ['ok' => false, 'error' => 'SMTP authentication failed. Check your Gmail App Password.'];
            }

            // MAIL FROM / RCPT TO / DATA
            $r = enformasys_smtp_command($socket, "MAIL FROM:<{$cfg['from_email']}>", [250]);
            if (!$r['ok']) {
                return ['ok' => false, 'error' => 'SMTP MAIL FROM failed: ' . $r['raw']];
            }
            $r = enformasys_smtp_command($socket, "RCPT TO:<{$toEmail}>", [250, 251]);
            if (!$r['ok']) {
                return ['ok' => false, 'error' => 'SMTP recipient rejected: ' . $r['raw']];
            }
            $r = enformasys_smtp_command($socket, 'DATA', [354]);
            if (!$r['ok']) {
                return ['ok' => false, 'error' => 'SMTP DATA rejected: ' . $r['raw']];
            }

            $boundary = 'enformasys_' . bin2hex(random_bytes(12));
            $safeFromName = str_replace(["\r", "\n"], '', $cfg['from_name']);
            $safeToName = str_replace(["\r", "\n"], '', $toName !== '' ? $toName : $toEmail);
            $safeSubject = str_replace(["\r", "\n"], '', $subject);
            $encodedSubject = '=?UTF-8?B?' . base64_encode($safeSubject) . '?=';
            $encodedFrom = '=?UTF-8?B?' . base64_encode($safeFromName) . '?= <' . $cfg['from_email'] . '>';
            $encodedTo = '=?UTF-8?B?' . base64_encode($safeToName) . '?= <' . $toEmail . '>';
            $msgIdDomain = substr(strrchr($cfg['from_email'], '@') ?: '@enformasys.local', 1);
            $messageId = '<' . bin2hex(random_bytes(10)) . '@' . $msgIdDomain . '>';

            if ($textBody === '') {
                $textBody = trim(preg_replace('/\s+/', ' ', strip_tags($htmlBody)));
            }

            $headers = [
                'Date: ' . gmdate('D, d M Y H:i:s +0000'),
                'Message-ID: ' . $messageId,
                'From: ' . $encodedFrom,
                'To: ' . $encodedTo,
                'Subject: ' . $encodedSubject,
                'MIME-Version: 1.0',
                'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            ];

            $mimeBody = "--{$boundary}\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: base64\r\n\r\n"
                . chunk_split(base64_encode($textBody)) . "\r\n"
                . "--{$boundary}\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: base64\r\n\r\n"
                . chunk_split(base64_encode($htmlBody)) . "\r\n"
                . "--{$boundary}--";

            $payload = implode("\r\n", $headers) . "\r\n\r\n" . $mimeBody;
            $payload = str_replace(["\r\n", "\r", "\n"], "\r\n", $payload);
            $payload = str_replace("\r\n.", "\r\n..", $payload);

            $r = enformasys_smtp_command($socket, $payload . "\r\n.", [250]);
            if (!$r['ok']) {
                return ['ok' => false, 'error' => 'SMTP message delivery failed: ' . $r['raw']];
            }

            @enformasys_smtp_command($socket, 'QUIT', [221]);
            return ['ok' => true, 'error' => ''];
        } finally {
            @fclose($socket);
        }
    }
}

if (!function_exists('enformasys_build_otp_email_html')) {
    /**
     * Builds the warm seminary-branded HTML email for 6-digit password reset OTP verification.
     */
    function enformasys_build_otp_email_html(string $recipientName, string $otpCode, int $expiresMinutes = 10): string
    {
        $nameEsc = htmlspecialchars($recipientName !== '' ? $recipientName : 'Seminarian', ENT_QUOTES, 'UTF-8');
        $otpEsc = htmlspecialchars($otpCode, ENT_QUOTES, 'UTF-8');
        $year = date('Y');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Password Reset Verification Code</title>
</head>
<body style="margin:0;padding:0;background-color:#f8f5f2;font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#3f2822;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#f8f5f2;padding:32px 16px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:520px;background-color:#ffffff;border-radius:20px;border:1px solid #e8d8d2;overflow:hidden;box-shadow:0 12px 30px rgba(92,58,49,0.08);">
          <tr>
            <td style="background:linear-gradient(135deg,#674238 0%,#5c3a31 55%,#462a23 100%);padding:26px 28px;text-align:center;">
              <div style="font-size:11px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:#e8c7c5;margin-bottom:6px;">Littleway College Seminary</div>
              <div style="font-size:22px;font-weight:800;color:#ffffff;letter-spacing:-0.3px;">EnformaSys Password Reset</div>
            </td>
          </tr>
          <tr>
            <td style="padding:30px 28px 24px 28px;">
              <p style="margin:0 0 12px 0;font-size:15px;font-weight:600;color:#3f2822;">Hello, {$nameEsc},</p>
              <p style="margin:0 0 22px 0;font-size:14px;line-height:1.6;color:#7b6158;">
                We received a request to reset the password for your EnformaSys account. Enter the 6-digit verification code below to continue:
              </p>
              <div style="background-color:#fcfaf8;border:1.5px solid #d6a6a6;border-radius:14px;padding:20px 16px;text-align:center;margin:0 0 22px 0;">
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1.6px;color:#8b7168;margin-bottom:8px;">Verification Code</div>
                <div style="font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:34px;font-weight:800;letter-spacing:10px;color:#5c3a31;padding-left:10px;">{$otpEsc}</div>
              </div>
              <p style="margin:0 0 10px 0;font-size:13px;line-height:1.55;color:#7b6158;">
                This code will expire in <strong style="color:#3f2822;">{$expiresMinutes} minutes</strong> and can only be used once.
              </p>
              <p style="margin:0;font-size:12px;line-height:1.55;color:#9a8178;">
                If you did not request a password reset, you can safely ignore this email—your account remains secure.
              </p>
            </td>
          </tr>
          <tr>
            <td style="background-color:#fcfaf8;border-top:1px solid #f0e4df;padding:16px 28px;text-align:center;font-size:11px;color:#8b7168;">
              &copy; {$year} EnformaSys &bull; Littleway College Seminary, Diocese of Kabankalan
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
    }
}
