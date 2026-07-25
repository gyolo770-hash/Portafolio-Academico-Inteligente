<?php
declare(strict_types=1);

require_once __DIR__ . '/app.php';

return [
    'from_email' => getenv('MAIL_FROM') ?: 'no-reply@portafolioacademico.local',
    'from_name' => getenv('MAIL_FROM_NAME') ?: APP_NAME,
    'enabled' => filter_var(getenv('MAIL_ENABLED') ?: false, FILTER_VALIDATE_BOOLEAN),
    'smtp_host' => getenv('SMTP_HOST') ?: null,
    'smtp_port' => (int) (getenv('SMTP_PORT') ?: 587),
    'smtp_user' => getenv('SMTP_USER') ?: null,
    'smtp_pass' => getenv('SMTP_PASS') ?: null,
    'smtp_secure' => getenv('SMTP_SECURE') ?: 'tls',
];
