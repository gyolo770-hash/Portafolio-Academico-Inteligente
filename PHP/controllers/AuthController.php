<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';

/**
 * Legacy placeholder kept for documentation compatibility.
 * Authentication is handled directly in PHP/pages/auth/*.php.
 */
final class AuthController
{
    public function login(): void
    {
        header('Location: ' . url_to('auth/login.php'));
        exit;
    }

    public function register(): void
    {
        header('Location: ' . url_to('auth/register.php'));
        exit;
    }

    public function recoverPassword(): void
    {
        header('Location: ' . url_to('auth/password-recovery.php'));
        exit;
    }
}
