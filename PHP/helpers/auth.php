<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/jwt.php';

if (!function_exists('auth_user')) {
    function auth_user()
    {
        if (empty($_SESSION['user_id'])) {
            return null;
        }

        $statement = db()->prepare(
            'SELECT u.id, u.full_name, u.username, u.email, u.avatar_path, u.status, u.email_verified_at, r.name AS role_name
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE u.id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $_SESSION['user_id']]);
        $user = $statement->fetch();

        return $user ?: null;
    }
}

if (!function_exists('auth_check')) {
    function auth_check(): bool
    {
        if (empty($_SESSION['user_id']) || empty($_SESSION['jwt_token'])) {
            return false;
        }

        if (jwt_verify((string) $_SESSION['jwt_token']) === false) {
            return false;
        }

        $user = auth_user();

        if (!$user || ($user['status'] ?? '') !== 'activo') {
            auth_logout();

            return false;
        }

        $_SESSION['user_role'] = $user['role_name'];

        return true;
    }
}

if (!function_exists('auth_login')) {
    function auth_login(array $user, bool $remember = false): void
    {
        session_regenerate_id(true);

        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['user_name'] = $user['full_name'];
        $_SESSION['user_role'] = $user['role_name'];
        $_SESSION['jwt_token'] = jwt_create([
            'sub' => (int) $user['id'],
            'email' => $user['email'],
            'role' => $user['role_name'],
        ], $remember ? 60 * 60 * 24 * 30 : 60 * 60 * 2);

        db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id')->execute([
            'id' => $user['id'],
        ]);
    }
}

if (!function_exists('auth_logout')) {
    function auth_logout(): void
    {
        $preserveFlash = $_SESSION['_flash'] ?? [];

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }

        session_destroy();

        if ($preserveFlash !== []) {
            session_name('portafolio_academico');
            session_start([
                'cookie_httponly' => true,
                'cookie_samesite' => 'Lax',
                'use_strict_mode' => true,
            ]);
            $_SESSION['_flash'] = $preserveFlash;
        }
    }
}

if (!function_exists('find_user_by_email')) {
    function find_user_by_email(string $email)
    {
        $statement = db()->prepare(
            'SELECT u.*, r.name AS role_name
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE u.email = :email
             LIMIT 1'
        );
        $statement->execute(['email' => $email]);

        return $statement->fetch() ?: null;
    }
}

if (!function_exists('student_role_id')) {
    function student_role_id(): int
    {
        $statement = db()->prepare('SELECT id FROM roles WHERE name = :name LIMIT 1');
        $statement->execute(['name' => 'estudiante']);
        $role = $statement->fetch();

        if ($role) {
            return (int) $role['id'];
        }

        db()->prepare('INSERT INTO roles (name, display_name, description) VALUES (:name, :display_name, :description)')
            ->execute([
                'name' => 'estudiante',
                'display_name' => 'Estudiante',
                'description' => 'Usuario principal que construye su portafolio académico.',
            ]);

        return (int) db()->lastInsertId();
    }
}

if (!function_exists('create_secure_token')) {
    function create_secure_token(): string
    {
        return bin2hex(random_bytes(32));
    }
}

if (!function_exists('store_email_verification')) {
    function store_email_verification(int $userId, string $token): void
    {
        db()->prepare(
            'INSERT INTO email_verifications (user_id, token_hash, expires_at)
             VALUES (:user_id, :token_hash, DATE_ADD(NOW(), INTERVAL 24 HOUR))'
        )->execute([
            'user_id' => $userId,
            'token_hash' => hash('sha256', $token),
        ]);
    }
}

if (!function_exists('store_password_reset')) {
    function store_password_reset(int $userId, string $token): void
    {
        db()->prepare(
            'INSERT INTO password_resets (user_id, token_hash, expires_at)
             VALUES (:user_id, :token_hash, DATE_ADD(NOW(), INTERVAL 1 HOUR))'
        )->execute([
            'user_id' => $userId,
            'token_hash' => hash('sha256', $token),
        ]);
    }
}
