<?php
declare(strict_types=1);

$pageTitle = 'Acceso social';
$pageDescription = 'Configuración pendiente de proveedor social.';
$bodyClass = 'auth-page';

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../helpers/security.php';
require_once __DIR__ . '/../../helpers/oauth.php';

$oauthProviders = oauth_providers();
$providerKey = strtolower((string) ($_GET['provider'] ?? ''));
$provider = $oauthProviders[$providerKey] ?? null;
$providerName = $provider['name'] ?? 'Proveedor social';
$statusMessage = '';
$statusType = 'info';

if ($provider !== null && $provider['enabled'] && !isset($_GET['code'])) {
    header('Location: ' . oauth_authorize_url($providerKey, $provider));
    exit;
}

if ($provider !== null && $provider['enabled'] && isset($_GET['code'])) {
    $expectedState = $_SESSION['_oauth_state'][$providerKey] ?? '';
    unset($_SESSION['_oauth_state'][$providerKey]);

    if (!is_string($_GET['state'] ?? null) || !hash_equals((string) $expectedState, (string) $_GET['state'])) {
        $statusType = 'danger';
        $statusMessage = 'No se pudo validar la respuesta del proveedor. Intenta nuevamente.';
    } else {
        try {
            $identity = oauth_fetch_identity($providerKey, $provider, (string) $_GET['code']);
            oauth_login_identity($identity);
            flash('success', 'Sesión iniciada con ' . $providerName . '.');
            require_once __DIR__ . '/../../helpers/navigation.php';
            header('Location: ' . nav_post_login_url($_SESSION['user_role'] ?? 'estudiante'));
            exit;
        } catch (Throwable $exception) {
            $statusType = 'danger';
            $statusMessage = 'No se pudo completar OAuth: ' . $exception->getMessage();
        }
    }
}

require_once __DIR__ . '/../../../HTML/components/header.php';
require_once __DIR__ . '/../../../HTML/components/navbar.php';
?>

<main id="contenido-principal" class="auth-shell">
    <div class="container">
        <div class="auth-grid">
            <section class="auth-card auth-card-compact text-center" aria-labelledby="socialTitle">
                <div class="status-icon mx-auto mb-3">
                    <i class="bi bi-plug" aria-hidden="true"></i>
                </div>
                <span class="eyebrow">Integración preparada</span>
                <h1 id="socialTitle">Login con <?= e($providerName); ?></h1>

                <?php if ($provider === null): ?>
                    <p class="text-secondary">El proveedor solicitado no existe. Usa Google, Facebook o GitHub.</p>
                <?php elseif (!$provider['enabled']): ?>
                    <p class="text-secondary">Este proveedor está listo, pero falta configurar sus credenciales en variables de entorno.</p>
                    <p class="small text-muted mb-0">Define el client id y secret correspondiente para activar el flujo real.</p>
                <?php else: ?>
                    <p class="text-secondary"><?= e($statusMessage ?: 'Redirigiendo al proveedor de identidad.'); ?></p>
                <?php endif; ?>

                <?php if ($statusMessage !== '' && $statusType !== 'info'): ?>
                    <div class="alert alert-<?= e($statusType); ?> mt-3" role="alert"><?= e($statusMessage); ?></div>
                <?php endif; ?>

                <div class="d-grid gap-2 mt-4">
                    <a class="btn btn-primary" href="<?= e(url_to('auth/login.php')); ?>">Volver al login</a>
                    <a class="btn btn-outline-primary" href="<?= e(url_to('auth/register.php')); ?>">Crear cuenta con correo</a>
                </div>
            </section>

            <?php require __DIR__ . '/../../../HTML/components/auth-visual.php'; ?>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/../../../HTML/components/footer.php'; ?>
