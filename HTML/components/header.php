<?php
declare(strict_types=1);

require_once __DIR__ . '/../../PHP/config/app.php';
require_once __DIR__ . '/../../PHP/helpers/security.php';

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('X-XSS-Protection: 0');
}

$pageTitle = $pageTitle ?? APP_NAME;
$pageDescription = $pageDescription ?? 'Gestiona tus logros, proyectos y crecimiento académico en un portafolio profesional.';
$bodyClass = $bodyClass ?? '';

if (!empty($_SESSION['user_id']) && function_exists('db')) {
    try {
        $colorModeStatement = db()->prepare('SELECT color_blind_mode FROM portfolio_settings WHERE user_id = :user_id LIMIT 1');
        $colorModeStatement->execute(['user_id' => (int) $_SESSION['user_id']]);

        if ((int) $colorModeStatement->fetchColumn() === 1) {
            $bodyClass = trim($bodyClass . ' color-blind-mode deuteranopia-mode');
        }
    } catch (Throwable $exception) {
        error_log('No se pudo cargar preferencia de accesibilidad: ' . $exception->getMessage());
    }
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="<?= e($pageDescription); ?>">
    <title><?= e($pageTitle); ?> | <?= e(APP_NAME); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="icon" href="<?= e(asset_url('Images/favicon.png')); ?>" type="image/png">
    <link rel="apple-touch-icon" href="<?= e(asset_url('Images/favicon.png')); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Poppins:wght@600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= e(asset_url('css/styles.css')); ?>" rel="stylesheet">
</head>
<body class="<?= e($bodyClass); ?>">
<a class="skip-link" href="#contenido-principal">Saltar al contenido principal</a>
