<?php
declare(strict_types=1);

if (!function_exists('system_setting')) {
    function system_setting(string $key, ?string $default = null): ?string
    {
        static $cache = [];

        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        try {
            if (!function_exists('db')) {
                require_once __DIR__ . '/../config/database.php';
            }

            $statement = db()->prepare('SELECT setting_value FROM system_settings WHERE setting_key = :key LIMIT 1');
            $statement->execute(['key' => $key]);
            $value = $statement->fetchColumn();
            $cache[$key] = $value === false ? $default : (string) $value;

            return $cache[$key];
        } catch (Throwable $exception) {
            error_log('No se pudo leer configuración del sistema: ' . $exception->getMessage());

            return $default;
        }
    }
}

if (!function_exists('is_maintenance_mode')) {
    function is_maintenance_mode(): bool
    {
        return system_setting('maintenance_mode', '0') === '1';
    }
}

if (!function_exists('is_public_registration_allowed')) {
    function is_public_registration_allowed(): bool
    {
        return system_setting('allow_public_registration', '1') !== '0';
    }
}

if (!function_exists('enforce_maintenance_mode')) {
    function enforce_maintenance_mode(): void
    {
        if (!is_maintenance_mode()) {
            return;
        }

        $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $allowedPatterns = [
            '/auth/login',
            '/auth/logout',
            '/admin/',
        ];

        foreach ($allowedPatterns as $pattern) {
            if (str_contains($requestPath, $pattern)) {
                return;
            }
        }

        if (!empty($_SESSION['user_id'])) {
            require_once __DIR__ . '/auth.php';
            $user = auth_user();
            if ($user && ($user['role_name'] ?? '') === 'administrador') {
                return;
            }
        }

        http_response_code(503);
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Mantenimiento</title><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet"><style>body{font-family:Inter,system-ui,sans-serif;display:grid;place-items:center;min-height:100vh;margin:0;background:#f8fafc;color:#111827}main{max-width:32rem;padding:2rem;text-align:center;border:1px solid #e5e7eb;border-radius:12px;background:#fff;box-shadow:0 12px 32px rgba(17,24,39,.08)}h1{margin:0 0 .75rem;font-size:1.75rem}a{color:#4f46e5;font-weight:700}</style></head><body><main><h1>Estamos en mantenimiento</h1><p>El portafolio académico no está disponible temporalmente. Vuelve a intentarlo en unos minutos.</p><p><a href="' . htmlspecialchars(url_to('auth/login.php'), ENT_QUOTES, 'UTF-8') . '">Ir a inicio de sesión</a></p></main></body></html>';
        exit;
    }
}
