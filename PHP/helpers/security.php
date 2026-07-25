<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';

if (!function_exists('e')) {
    function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['_csrf_token'];
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="_csrf_token" value="' . e(csrf_token()) . '">';
    }
}

if (!function_exists('verify_csrf_token')) {
    function verify_csrf_token(?string $token): bool
    {
        return is_string($token)
            && isset($_SESSION['_csrf_token'])
            && hash_equals($_SESSION['_csrf_token'], $token);
    }
}

if (!function_exists('flash')) {
    function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][] = [
            'type' => $type,
            'message' => $message,
        ];
    }
}

if (!function_exists('flash_messages')) {
    function flash_messages(): array
    {
        $messages = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);

        return $messages;
    }
}

if (!function_exists('rate_limit_key')) {
    function rate_limit_key(string $action): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'local';

        return '_rate_limit_' . hash('sha256', $action . '|' . $ip);
    }
}

if (!function_exists('rate_limit_attempt')) {
    function rate_limit_attempt(string $action, int $maxAttempts = 8, int $windowSeconds = 900): bool
    {
        $key = rate_limit_key($action);
        $now = time();
        $bucket = $_SESSION[$key] ?? ['count' => 0, 'start' => $now];

        if (($now - (int) $bucket['start']) > $windowSeconds) {
            $bucket = ['count' => 0, 'start' => $now];
        }

        $bucket['count'] = (int) $bucket['count'] + 1;
        $_SESSION[$key] = $bucket;

        return $bucket['count'] <= $maxAttempts;
    }
}

if (!function_exists('safe_internal_redirect')) {
    function safe_internal_redirect(?string $target, string $fallback): string
    {
        if (!is_string($target) || $target === '') {
            return $fallback;
        }

        $target = trim($target);
        $basePath = parse_url(BASE_URL, PHP_URL_PATH) ?: '';
        $targetPath = parse_url($target, PHP_URL_PATH);

        if ($targetPath === null || $targetPath === false) {
            return $fallback;
        }

        if (preg_match('/^https?:\/\//i', $target) || str_starts_with($target, '//')) {
            return $fallback;
        }

        if ($target[0] === '/' && $basePath !== '' && !str_starts_with($targetPath, $basePath)) {
            return $fallback;
        }

        return $target;
    }
}
