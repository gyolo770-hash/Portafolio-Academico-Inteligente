<?php
declare(strict_types=1);

require_once __DIR__ . '/app.php';

return [
    'google' => [
        'name' => 'Google',
        'enabled' => env_value('GOOGLE_CLIENT_ID') !== '' && env_value('GOOGLE_CLIENT_SECRET') !== '',
        'client_id' => env_value('GOOGLE_CLIENT_ID'),
        'client_secret' => env_value('GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => url_to('auth/social.php?provider=google'),
        'authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
        'token_url' => 'https://oauth2.googleapis.com/token',
        'user_url' => 'https://www.googleapis.com/oauth2/v2/userinfo',
        'scope' => 'openid email profile',
    ],
    'facebook' => [
        'name' => 'Facebook',
        'enabled' => env_value('FACEBOOK_CLIENT_ID') !== '' && env_value('FACEBOOK_CLIENT_SECRET') !== '',
        'client_id' => env_value('FACEBOOK_CLIENT_ID'),
        'client_secret' => env_value('FACEBOOK_CLIENT_SECRET'),
        'redirect_uri' => url_to('auth/social.php?provider=facebook'),
        'authorize_url' => 'https://www.facebook.com/v19.0/dialog/oauth',
        'token_url' => 'https://graph.facebook.com/v19.0/oauth/access_token',
        'user_url' => 'https://graph.facebook.com/me?fields=id,name,email,picture',
        'scope' => 'email,public_profile',
    ],
    'github' => [
        'name' => 'GitHub',
        'enabled' => env_value('GITHUB_CLIENT_ID') !== '' && env_value('GITHUB_CLIENT_SECRET') !== '',
        'client_id' => env_value('GITHUB_CLIENT_ID'),
        'client_secret' => env_value('GITHUB_CLIENT_SECRET'),
        'redirect_uri' => url_to('auth/social.php?provider=github'),
        'authorize_url' => 'https://github.com/login/oauth/authorize',
        'token_url' => 'https://github.com/login/oauth/access_token',
        'user_url' => 'https://api.github.com/user',
        'scope' => 'read:user user:email',
    ],
];
