<?php
declare(strict_types=1);

require_once __DIR__ . '/../../helpers/security.php';
require_once __DIR__ . '/../../helpers/auth.php';

flash('success', 'Sesión cerrada correctamente.');
auth_logout();

header('Location: ' . url_to('auth/login.php'));
exit;
