<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../helpers/security.php';
require_once __DIR__ . '/../helpers/auth.php';

if (!function_exists('require_auth')) {
    function require_auth(): void
    {
        if (!auth_check()) {
            $message = empty($_SESSION['user_id'])
                ? 'Debes iniciar sesión para acceder a esta sección.'
                : 'Tu sesión expiró o tu cuenta ya no está activa. Inicia sesión nuevamente.';
            flash('warning', $message);
            header('Location: ' . url_to('auth/login.php'));
            exit;
        }
    }
}

if (!function_exists('require_role')) {
    function require_role(array $roles): void
    {
        require_auth();

        $user = auth_user();
        if (!$user || !in_array($user['role_name'], $roles, true)) {
            flash('warning', 'No tienes permisos para acceder a esta sección.');
            require_once __DIR__ . '/../helpers/navigation.php';
            header('Location: ' . nav_post_login_url($user['role_name'] ?? 'estudiante'));
            exit;
        }
    }
}

if (!function_exists('require_student')) {
    function require_student(): void
    {
        require_role(['estudiante']);
    }
}

if (!function_exists('require_admin')) {
    function require_admin(): void
    {
        require_role(['administrador']);
    }
}

if (!function_exists('require_recruiter')) {
    function require_recruiter(): void
    {
        require_role(['reclutador']);
    }
}
