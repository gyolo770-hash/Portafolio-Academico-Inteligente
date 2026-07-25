<?php
declare(strict_types=1);

require_once __DIR__ . '/../../helpers/security.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/mail.php';

$resetUrl = '';
$noticeMessage = '';
$noticeType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!rate_limit_attempt('password_recovery', 5, 900)) {
        flash('danger', 'Demasiadas solicitudes. Espera unos minutos antes de pedir otro enlace.');
        header('Location: ' . url_to('auth/password-recovery.php'));
        exit;
    }

    if (!verify_csrf_token($_POST['_csrf_token'] ?? null)) {
        flash('danger', 'La sesión expiró. Intenta solicitar la recuperación nuevamente.');
        header('Location: ' . url_to('auth/password-recovery.php'));
        exit;
    }

    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $user = filter_var($email, FILTER_VALIDATE_EMAIL) ? find_user_by_email($email) : null;

    if ($user) {
        $token = create_secure_token();
        store_password_reset((int) $user['id'], $token);
        $resetUrl = url_to('auth/reset-password.php?token=' . urlencode($token));
        send_password_reset_mail($user['email'], $resetUrl);
        $noticeType = 'success';
        $noticeMessage = mail_is_enabled()
            ? 'Te enviamos un enlace de recuperación por correo electrónico.'
            : 'Modo local: enlace generado y guardado en storage/mail.';
    } else {
        $noticeType = 'info';
        $noticeMessage = 'Si el correo está registrado, recibirás instrucciones para recuperar tu contraseña.';
    }
}

$pageTitle = 'Recuperar contraseña';
$pageDescription = 'Solicita un enlace para recuperar tu contraseña.';
$bodyClass = 'auth-page';
$pageScript = 'auth.js';

require_once __DIR__ . '/../../../HTML/components/header.php';
require_once __DIR__ . '/../../../HTML/components/navbar.php';
?>

<main id="contenido-principal" class="auth-shell">
    <div class="container">
        <div class="auth-grid">
            <section class="auth-card auth-card-compact" aria-labelledby="recoveryTitle">
                <?php require __DIR__ . '/../../../HTML/components/flash.php'; ?>

                <span class="eyebrow">Acceso seguro</span>
                <h1 id="recoveryTitle">Recupera tu contraseña</h1>
                <p class="text-secondary">Ingresa tu correo y te enviaremos instrucciones para restablecer el acceso a tu portafolio.</p>

                <?php if ($noticeMessage !== ''): ?>
                    <div class="alert alert-<?= e($noticeType); ?>" role="status">
                        <?= e($noticeMessage); ?>
                    </div>
                <?php endif; ?>

                <?php if ($resetUrl !== '' && !mail_is_enabled()): ?>
                    <div class="alert alert-light border">
                        <p class="fw-semibold mb-2">Enlace de recuperación generado:</p>
                        <a href="<?= e($resetUrl); ?>"><?= e($resetUrl); ?></a>
                    </div>
                <?php endif; ?>

                <form class="needs-validation auth-form" method="post" action="<?= e(url_to('auth/password-recovery.php')); ?>" novalidate>
                    <?= csrf_field(); ?>

                    <div class="mb-4">
                        <label class="form-label" for="email">Correo electrónico</label>
                        <input class="form-control" type="email" id="email" name="email" placeholder="tu.correo@ejemplo.com" autocomplete="email" required>
                        <div class="invalid-feedback">Ingresa el correo asociado a tu cuenta.</div>
                    </div>

                    <button class="btn btn-primary w-100" type="submit">Enviar enlace de recuperación</button>
                </form>

                <p class="auth-switch"><a href="<?= e(url_to('auth/login.php')); ?>">Volver al inicio de sesión</a></p>
            </section>

            <?php require __DIR__ . '/../../../HTML/components/auth-visual.php'; ?>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/../../../HTML/components/footer.php'; ?>
