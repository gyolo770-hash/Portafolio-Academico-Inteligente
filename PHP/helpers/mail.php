<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';

if (!function_exists('mail_config')) {
    function mail_config(): array
    {
        static $config = null;

        if ($config === null) {
            $config = require __DIR__ . '/../config/mail.php';
        }

        return $config;
    }
}

if (!function_exists('mail_is_enabled')) {
    function mail_is_enabled(): bool
    {
        $config = mail_config();

        return (bool) $config['enabled'];
    }
}

if (!function_exists('mail_uses_smtp')) {
    function mail_uses_smtp(): bool
    {
        $config = mail_config();

        return mail_is_enabled()
            && !empty($config['smtp_host'])
            && !empty($config['smtp_user'])
            && !empty($config['smtp_pass']);
    }
}

if (!function_exists('mail_smtp_send')) {
    function mail_smtp_send(string $to, string $subject, string $htmlBody, string $textBody, array $config): bool
    {
        $host = (string) $config['smtp_host'];
        $port = (int) ($config['smtp_port'] ?? 587);
        $secure = strtolower((string) ($config['smtp_secure'] ?? 'tls'));
        $transport = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $socket = @stream_socket_client($transport, $errno, $errstr, 20);

        if (!$socket) {
            throw new RuntimeException('No se pudo conectar al servidor SMTP: ' . $errstr);
        }

        stream_set_timeout($socket, 20);

        $read = static function () use ($socket): string {
            $response = '';
            while (($line = fgets($socket, 515)) !== false) {
                $response .= $line;
                if (isset($line[3]) && $line[3] === ' ') {
                    break;
                }
            }

            return $response;
        };

        $write = static function (string $command) use ($socket): void {
            fwrite($socket, $command . "\r\n");
        };

        $expect = static function (string $response, array $codes) use ($read): void {
            $code = (int) substr($response, 0, 3);
            if (!in_array($code, $codes, true)) {
                throw new RuntimeException('SMTP respondió inesperadamente: ' . trim($response));
            }
        };

        $expect($read(), [220]);

        $write('EHLO localhost');
        $expect($read(), [250]);

        if ($secure === 'tls') {
            $write('STARTTLS');
            $expect($read(), [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('No se pudo iniciar TLS con el servidor SMTP.');
            }
            $write('EHLO localhost');
            $expect($read(), [250]);
        }

        $write('AUTH LOGIN');
        $expect($read(), [334]);
        $write(base64_encode((string) $config['smtp_user']));
        $expect($read(), [334]);
        $write(base64_encode((string) $config['smtp_pass']));
        $expect($read(), [235]);

        $fromEmail = (string) $config['from_email'];
        $write('MAIL FROM:<' . $fromEmail . '>');
        $expect($read(), [250]);
        $write('RCPT TO:<' . $to . '>');
        $expect($read(), [250, 251]);
        $write('DATA');
        $expect($read(), [354]);

        $fromName = (string) $config['from_name'];
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $boundary = 'pa_' . bin2hex(random_bytes(8));
        $message = implode("\r\n", [
            'From: ' . sprintf('%s <%s>', $fromName, $fromEmail),
            'To: <' . $to . '>',
            'Subject: ' . $encodedSubject,
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            '',
            '--' . $boundary,
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            '',
            $textBody !== '' ? $textBody : strip_tags($htmlBody),
            '',
            '--' . $boundary,
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            '',
            $htmlBody,
            '',
            '--' . $boundary . '--',
            '.',
        ]);

        fwrite($socket, $message . "\r\n");
        $expect($read(), [250]);
        $write('QUIT');
        fclose($socket);

        return true;
    }
}

if (!function_exists('send_app_mail')) {
    function send_app_mail(string $to, string $subject, string $htmlBody, string $textBody = ''): bool
    {
        $config = mail_config();
        $from = sprintf('%s <%s>', $config['from_name'], $config['from_email']);

        if (mail_uses_smtp()) {
            return mail_smtp_send($to, $subject, $htmlBody, $textBody, $config);
        }

        if (mail_is_enabled()) {
            $headers = [
                'MIME-Version: 1.0',
                'Content-Type: text/html; charset=UTF-8',
                'From: ' . $from,
            ];

            return mail($to, $subject, $htmlBody, implode("\r\n", $headers));
        }

        $storageDirectory = APP_ROOT . DIRECTORY_SEPARATOR . 'PHP' . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'mail';
        if (!is_dir($storageDirectory)) {
            mkdir($storageDirectory, 0775, true);
        }

        $preview = [
            '<h1>' . htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') . '</h1>',
            '<p><strong>Para:</strong> ' . htmlspecialchars($to, ENT_QUOTES, 'UTF-8') . '</p>',
            $htmlBody,
            $textBody !== '' ? '<pre>' . htmlspecialchars($textBody, ENT_QUOTES, 'UTF-8') . '</pre>' : '',
        ];

        $filename = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.html';
        file_put_contents($storageDirectory . DIRECTORY_SEPARATOR . $filename, implode("\n", $preview), LOCK_EX);

        return true;
    }
}

if (!function_exists('send_password_reset_mail')) {
    function send_password_reset_mail(string $email, string $resetUrl): bool
    {
        $subject = 'Recupera tu contraseña';
        $body = '<p>Usa este enlace para restablecer tu contraseña:</p><p><a href="' . htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8') . '">Restablecer contraseña</a></p><p>El enlace expira en 1 hora.</p>';

        return send_app_mail($email, $subject, $body, $resetUrl);
    }
}

if (!function_exists('send_verification_mail')) {
    function send_verification_mail(string $email, string $verificationUrl): bool
    {
        $subject = 'Verifica tu correo';
        $body = '<p>Confirma tu cuenta para activar tu portafolio académico:</p><p><a href="' . htmlspecialchars($verificationUrl, ENT_QUOTES, 'UTF-8') . '">Verificar correo</a></p><p>El enlace expira en 24 horas.</p>';

        return send_app_mail($email, $subject, $body, $verificationUrl);
    }
}
