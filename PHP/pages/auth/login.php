<?php
declare(strict_types=1);

require_once __DIR__ . '/../../helpers/security.php';
require_once __DIR__ . '/../../helpers/auth.php';

if (auth_check()) {
    require_once __DIR__ . '/../../helpers/navigation.php';
    header('Location: ' . nav_post_login_url($_SESSION['user_role'] ?? 'estudiante'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!rate_limit_attempt('login', 8, 900)) {
        flash('danger', 'Demasiados intentos. Espera unos minutos antes de intentar nuevamente.');
        header('Location: ' . url_to('auth/login.php'));
        exit;
    }

    if (!verify_csrf_token($_POST['_csrf_token'] ?? null)) {
        flash('danger', 'La sesión expiró. Intenta iniciar sesión nuevamente.');
        header('Location: ' . url_to('auth/login.php'));
        exit;
    }

    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $remember = isset($_POST['remember']);
    $user = filter_var($email, FILTER_VALIDATE_EMAIL) ? find_user_by_email($email) : null;

    if (!$user || empty($user['password_hash']) || !password_verify($password, $user['password_hash'])) {
        flash('danger', 'Correo o contraseña incorrectos.');
        header('Location: ' . url_to('auth/login.php'));
        exit;
    }

    if ($user['status'] === 'suspendido') {
        flash('warning', 'Tu cuenta está suspendida. Contacta al administrador para recibir ayuda.');
        header('Location: ' . url_to('auth/login.php'));
        exit;
    }

    if ($user['status'] === 'pendiente') {
        flash('warning', 'Tu cuenta aún está pendiente de activación. Contacta al administrador si necesitas acceso.');
        header('Location: ' . url_to('auth/login.php'));
        exit;
    }

    if (empty($user['email_verified_at'])) {
        $_SESSION['pending_verification_email'] = $user['email'];
        flash('warning', 'Antes de iniciar sesión debes verificar tu correo. Puedes solicitar un nuevo enlace de verificación.');
        header('Location: ' . url_to('auth/verify-email.php?email=' . urlencode($user['email'])));
        exit;
    }

    auth_login($user, $remember);
    flash('success', 'Sesión iniciada correctamente. Bienvenido a tu portafolio.');
    require_once __DIR__ . '/../../helpers/navigation.php';
    header('Location: ' . nav_post_login_url($user['role_name']));
    exit;
}

$pageTitle = 'Iniciar sesión';
$pageDescription = 'Accede a tu portafolio académico inteligente.';
$bodyClass = 'auth-page';
$pageScript = 'auth.js';

require_once __DIR__ . '/../../../HTML/components/header.php';
$oauthProviders = array_filter(
    require __DIR__ . '/../../config/oauth.php',
    static function (array $provider): bool {
        return !empty($provider['enabled']);
    }
);
require_once __DIR__ . '/../../../HTML/components/navbar.php';
?>

<main id="contenido-principal" class="auth-shell">
    <div class="container">
        <div class="auth-grid">
            <section class="auth-card" aria-labelledby="loginTitle">
                <?php require __DIR__ . '/../../../HTML/components/flash.php'; ?>

                <span class="eyebrow">Bienvenido de vuelta</span>
                <h1 id="loginTitle">Inicia sesión en tu cuenta</h1>
                <p class="text-secondary">Continúa construyendo tu perfil académico, CV y portafolio profesional.</p>

                <div class="social-grid" aria-label="Opciones de acceso social">
                    <?php foreach ($oauthProviders as $key => $provider): ?>
                        <a class="btn btn-social" href="<?= e(url_to('auth/social.php?provider=' . $key)); ?>" aria-label="Continuar con <?= e($provider['name']); ?>">
                            <i class="bi bi-<?= $key === 'google' ? 'google' : ($key === 'facebook' ? 'facebook' : 'github'); ?>" aria-hidden="true"></i>
                            <span><?= e($provider['name']); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>

                <div class="auth-divider"><span>o usa tu correo</span></div>

                <form class="needs-validation auth-form" method="post" action="<?= e(url_to('auth/login.php')); ?>" novalidate>
                    <?= csrf_field(); ?>

                    <div class="mb-3">
                        <label class="form-label" for="email">Correo electrónico</label>
                        <input class="form-control" type="email" id="email" name="email" placeholder="tu.correo@ejemplo.com" autocomplete="email" required>
                        <div class="invalid-feedback">Ingresa un correo electrónico válido.</div>
                    </div>

                    <div class="mb-3">
                        <div class="d-flex justify-content-between gap-3">
                            <label class="form-label" for="password">Contraseña</label>
                            <a class="form-link" href="<?= e(url_to('auth/password-recovery.php')); ?>">¿Olvidaste tu contraseña?</a>
                        </div>
                        <div class="password-field">
                            <input class="form-control" type="password" id="password" name="password" placeholder="Tu contraseña" autocomplete="current-password" required minlength="8">
                            <button class="password-toggle" type="button" aria-label="Mostrar contraseña" data-toggle-password="#password">
                                <i class="bi bi-eye" aria-hidden="true"></i>
                            </button>
                        </div>
                        <div class="invalid-feedback">La contraseña debe tener al menos 8 caracteres.</div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="remember" name="remember">
                            <label class="form-check-label" for="remember">Recordarme</label>
                        </div>
                    </div>

                    <button class="btn btn-primary w-100" type="submit">Iniciar sesión</button>
                </form>

                <p class="auth-switch">¿Aún no tienes cuenta? <a href="<?= e(url_to('auth/register.php')); ?>">Crear cuenta gratis</a></p>
                <p class="auth-switch mt-2"><a href="<?= e(url_to('auth/verify-email.php')); ?>">Verificar mi correo</a></p>
            </section>

            <?php require __DIR__ . '/../../../HTML/components/auth-visual.php'; ?>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/../../../HTML/components/footer.php'; ?>
