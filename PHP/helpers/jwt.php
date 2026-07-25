<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';

if (!function_exists('base64url_encode')) {
    function base64url_encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

if (!function_exists('base64url_decode')) {
    function base64url_decode(string $data): string
    {
        $padding = 4 - (strlen($data) % 4);
        if ($padding < 4) {
            $data .= str_repeat('=', $padding);
        }

        return base64_decode(strtr($data, '-_', '+/')) ?: '';
    }
}

if (!function_exists('jwt_secret')) {
    function jwt_secret(): string
    {
        $environmentSecret = getenv('JWT_SECRET');
        if (is_string($environmentSecret) && strlen($environmentSecret) >= 32) {
            return $environmentSecret;
        }

        $storageDirectory = APP_ROOT . DIRECTORY_SEPARATOR . 'PHP' . DIRECTORY_SEPARATOR . 'storage';
        $secretPath = $storageDirectory . DIRECTORY_SEPARATOR . 'jwt.secret';

        if (!is_dir($storageDirectory)) {
            mkdir($storageDirectory, 0775, true);
        }

        if (!is_file($secretPath)) {
            file_put_contents($secretPath, bin2hex(random_bytes(32)), LOCK_EX);
        }

        return trim((string) file_get_contents($secretPath));
    }
}

if (!function_exists('jwt_create')) {
    function jwt_create(array $payload, int $ttlSeconds = 7200): string
    {
        $issuedAt = time();
        $payload = array_merge($payload, [
            'iat' => $issuedAt,
            'exp' => $issuedAt + $ttlSeconds,
            'iss' => APP_NAME,
        ]);

        $header = ['typ' => 'JWT', 'alg' => 'HS256'];
        $segments = [
            base64url_encode(json_encode($header)),
            base64url_encode(json_encode($payload)),
        ];

        $signature = hash_hmac('sha256', implode('.', $segments), jwt_secret(), true);
        $segments[] = base64url_encode($signature);

        return implode('.', $segments);
    }
}

if (!function_exists('jwt_verify')) {
    function jwt_verify(string $token)
    {
        $segments = explode('.', $token);
        if (count($segments) !== 3) {
            return false;
        }

        [$header, $payload, $signature] = $segments;
        $expected = base64url_encode(hash_hmac('sha256', $header . '.' . $payload, jwt_secret(), true));

        if (!hash_equals($expected, $signature)) {
            return false;
        }

        $decoded = json_decode(base64url_decode($payload), true);
        if (!is_array($decoded) || !isset($decoded['exp']) || (int) $decoded['exp'] < time()) {
            return false;
        }

        return $decoded;
    }
}
