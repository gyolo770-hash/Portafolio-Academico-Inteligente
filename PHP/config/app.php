<?php
declare(strict_types=1);

date_default_timezone_set('America/Mexico_City');

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }

        return substr($haystack, -strlen($needle)) === $needle;
    }
}

if (!defined('APP_NAME')) {
    define('APP_NAME', 'Portafolio Académico Inteligente');
}

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__, 2));
}

$envFile = APP_ROOT . DIRECTORY_SEPARATOR . '.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = array_map('trim', explode('=', $line, 2));
        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

if (!function_exists('env_value')) {
    function env_value(string $key, string $default = ''): string
    {
        $value = getenv($key);
        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (isset($_ENV[$key]) && is_string($_ENV[$key]) && $_ENV[$key] !== '') {
            return $_ENV[$key];
        }

        return $default;
    }
}

if (!function_exists('http_curl_ssl_options')) {
    function http_curl_ssl_options(): array
    {
        $candidates = [
            env_value('CURL_CA_BUNDLE'),
            dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cacert.pem',
        ];

        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || $candidate === '') {
                continue;
            }

            if (is_file($candidate)) {
                return [
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_CAINFO => $candidate,
                ];
            }
        }

        return [];
    }
}

if (!defined('PUBLIC_ROOT')) {
    define('PUBLIC_ROOT', APP_ROOT . DIRECTORY_SEPARATOR . 'PHP' . DIRECTORY_SEPARATOR . 'pages');
}

if (!defined('PAGES_ROOT')) {
    define('PAGES_ROOT', APP_ROOT . DIRECTORY_SEPARATOR . 'PHP' . DIRECTORY_SEPARATOR . 'pages');
}

if (!defined('ASSET_ROOT')) {
    define('ASSET_ROOT', APP_ROOT);
}

if (!defined('BASE_URL')) {
    $projectFolder = basename(APP_ROOT);
    define('BASE_URL', getenv('APP_BASE_URL') ?: '/' . $projectFolder);
}

if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? null) === '443');

    session_name('portafolio_academico');
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'cookie_secure' => $isHttps,
        'use_strict_mode' => true,
    ]);
}

if (!function_exists('url_to')) {
    function url_to(string $path = ''): string
    {
        $base = rtrim(BASE_URL, '/');

        if ($path === '' || $path === 'index.php') {
            return $base . '/index.php';
        }

        return $base . '/index.php/' . ltrim($path, '/');
    }
}

if (!function_exists('asset_url')) {
    function asset_url(string $path): string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');

        if (str_starts_with($path, 'img/')) {
            $path = 'Images/' . substr($path, 4);
        }

        $legacyMap = [
            'css/' => 'CSS/',
            'js/' => 'JS/',
            'images/' => 'Images/',
        ];

        foreach ($legacyMap as $legacy => $modern) {
            if (str_starts_with(strtolower($path), $legacy)) {
                $path = $modern . substr($path, strlen($legacy));
                break;
            }
        }

        return rtrim(BASE_URL, '/') . '/' . $path;
    }
}

if (!function_exists('current_year')) {
    function current_year(): string
    {
        return date('Y');
    }
}

require_once __DIR__ . '/../helpers/system.php';
require_once __DIR__ . '/../helpers/schema.php';
enforce_maintenance_mode();
ensure_runtime_schema();
