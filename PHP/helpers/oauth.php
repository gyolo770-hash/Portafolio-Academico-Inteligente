<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth.php';

if (!function_exists('oauth_providers')) {
    function oauth_providers(): array
    {
        return require __DIR__ . '/../config/oauth.php';
    }
}

if (!function_exists('oauth_authorize_url')) {
    function oauth_authorize_url(string $providerKey, array $provider): string
    {
        $state = bin2hex(random_bytes(16));
        $_SESSION['_oauth_state'][$providerKey] = $state;

        return $provider['authorize_url'] . '?' . http_build_query([
            'client_id' => $provider['client_id'],
            'redirect_uri' => $provider['redirect_uri'],
            'response_type' => 'code',
            'scope' => $provider['scope'],
            'state' => $state,
        ]);
    }
}

if (!function_exists('oauth_request_json')) {
    function oauth_request_json(string $url, array $payload = [], array $headers = []): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('La extensión cURL de PHP es necesaria para OAuth.');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_USERAGENT => APP_NAME,
            CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
        ] + http_curl_ssl_options());

        if ($payload !== []) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
        }

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $status >= 400) {
            throw new RuntimeException('OAuth no respondió correctamente: ' . ($error ?: 'HTTP ' . $status));
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            throw new RuntimeException('Respuesta OAuth inválida.');
        }

        return $data;
    }
}

if (!function_exists('oauth_fetch_identity')) {
    function oauth_fetch_identity(string $providerKey, array $provider, string $code): array
    {
        $token = oauth_request_json($provider['token_url'], [
            'client_id' => $provider['client_id'],
            'client_secret' => $provider['client_secret'],
            'redirect_uri' => $provider['redirect_uri'],
            'code' => $code,
            'grant_type' => 'authorization_code',
        ]);

        $accessToken = $token['access_token'] ?? null;
        if (!is_string($accessToken) || $accessToken === '') {
            throw new RuntimeException('El proveedor no devolvió access_token.');
        }

        $profile = oauth_request_json($provider['user_url'], [], ['Authorization: Bearer ' . $accessToken]);

        if ($providerKey === 'github' && empty($profile['email'])) {
            $emails = oauth_request_json('https://api.github.com/user/emails', [], ['Authorization: Bearer ' . $accessToken]);
            foreach ($emails as $emailRow) {
                if (!empty($emailRow['primary']) && !empty($emailRow['verified'])) {
                    $profile['email'] = $emailRow['email'];
                    break;
                }
            }
        }

        return [
            'email' => strtolower((string) ($profile['email'] ?? '')),
            'name' => (string) ($profile['name'] ?? $profile['login'] ?? 'Usuario OAuth'),
            'avatar' => (string) ($profile['picture'] ?? $profile['avatar_url'] ?? ''),
        ];
    }
}

if (!function_exists('oauth_username')) {
    function oauth_username(string $email, string $name): string
    {
        $base = preg_replace('/[^a-z0-9_]+/', '', strtolower(strstr($email, '@', true) ?: $name)) ?: 'usuario';
        $username = substr($base, 0, 24);
        $counter = 1;

        while (true) {
            $statement = db()->prepare('SELECT COUNT(*) FROM users WHERE username = :username');
            $statement->execute(['username' => $username]);
            if ((int) $statement->fetchColumn() === 0) {
                return $username;
            }

            $username = substr($base, 0, 20) . $counter;
            $counter++;
        }
    }
}

if (!function_exists('oauth_login_identity')) {
    function oauth_login_identity(array $identity): void
    {
        if (!filter_var($identity['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('El proveedor no compartió un correo válido.');
        }

        $user = find_user_by_email($identity['email']);
        if (!$user) {
            $roleId = student_role_id();
            $username = oauth_username($identity['email'], $identity['name']);

            db()->beginTransaction();
            db()->prepare(
                'INSERT INTO users (role_id, full_name, username, email, password_hash, avatar_path, status, email_verified_at)
                 VALUES (:role_id, :full_name, :username, :email, :password_hash, :avatar_path, "activo", NOW())'
            )->execute([
                'role_id' => $roleId,
                'full_name' => $identity['name'],
                'username' => $username,
                'email' => $identity['email'],
                'password_hash' => password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT),
                'avatar_path' => $identity['avatar'] ?: null,
            ]);

            $userId = (int) db()->lastInsertId();
            db()->prepare('INSERT INTO user_profiles (user_id, about_me) VALUES (:user_id, :about_me)')
                ->execute(['user_id' => $userId, 'about_me' => null]);
            db()->prepare('INSERT INTO portfolio_settings (user_id, public_slug) VALUES (:user_id, :public_slug)')
                ->execute(['user_id' => $userId, 'public_slug' => $username]);
            db()->commit();

            $user = find_user_by_email($identity['email']);
        }

        auth_login($user);
    }
}
