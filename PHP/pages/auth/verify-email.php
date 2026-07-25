<?php
declare(strict_types=1);

require_once __DIR__ . '/../../helpers/security.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/mail.php';

$pageTitle = 'Verificar correo';
$pageDescription = 'Verifica tu correo electrónico para activar tu cuenta.';
$bodyClass = 'auth-page';
$verificationUrl = '';
$statusMessage = '';
$statusType = 'info';

if (isset($_GET['token']) && $_GET['token'] !== '') {
    $tokenHash = hash('sha256', (string) $_GET['token']);
    $statement = db()->prepare(
        'SELECT ev.id, ev.user_id, u.email
         FROM email_verifications ev
         INNER JOIN users u ON u.id = ev.user_id
         WHERE ev.token_hash = :token_hash
           AND ev.verified_at IS NULL
           AND ev.expires_at > NOW()
         LIMIT 1'
    );
    $statement->execute(['token_hash' => $tokenHash]);
    $verification = $statement->fetch();

    if ($verification) {
        db()->beginTransaction();
        db()->prepare('UPDATE users SET email_verified_at = NOW(), status = :status WHERE id = :id')
            ->execute(['status' => 'activo', 'id' => $verification['user_id']]);
        db()->prepare('UPDATE email_verifications SET verified_at = NOW() WHERE id = :id')
            ->execute(['id' => $verification['id']]);
        db()->commit();

        flash('success', 'Correo verificado correctamente. Ya puedes iniciar sesión.');
        header('Location: ' . url_to('auth/login.php'));
        exit;
    }

    $statusType = 'warning';
    $statusMessage = 'El enlace de verificación no existe, ya fue usado o expiró.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!rate_limit_attempt('email_verification', 5, 900)) {
        flash('danger', 'Demasiadas solicitudes. Espera unos minutos antes de generar otro enlace.');
        header('Location: ' . url_to('auth/verify-email.php'));
        exit;
    }

    if (!verify_csrf_token($_POST['_csrf_token'] ?? null)) {
        flash('danger', 'La sesión expiró. Intenta generar otro enlace.');
        header('Location: ' . url_to('auth/verify-email.php'));
        exit;
    }

    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $user = filter_var($email, FILTER_VALIDATE_EMAIL) ? find_user_by_email($email) : null;

    if ($user && empty($user['email_verified_at'])) {
        $token = create_secure_token();
        store_email_verification((int) $user['id'], $token);
        $verificationUrl = url_to('auth/verify-email.php?token=' . urlencode($token));
        send_verification_mail($user['email'], $verificationUrl);
        $statusType = 'success';
        $statusMessage = mail_is_enabled()
            ? 'Te enviamos un enlace de verificación por correo.'
            : 'Modo local: enlace generado y guardado en storage/mail.';
    } else {
        $statusType = 'info';
        $statusMessage = 'Si el correo existe y falta verificarlo, se generará un enlace de verificación.';
    }
}

require_once __DIR__ . '/../../../HTML/components/header.php';
require_once __DIR__ . '/../../../HTML/components/navbar.php';
?>

<main id="contenido-principal" class="auth-shell">
    <div class="container">
        <div class="auth-grid">
            <section class="auth-card auth-card-compact" aria-labelledby="verifyTitle">
                <?php require __DIR__ . '/../../../HTML/components/flash.php'; ?>

                <span class="eyebrow">Verificación de correo</span>
                <h1 id="verifyTitle">Activa tu cuenta</h1>
                <p class="text-secondary">Recibe un enlace de verificación por correo. En modo local se guarda una vista previa en storage/mail.</p>

                <?php if ($statusMessage !== ''): ?>
                    <div class="alert alert-<?= e($statusType); ?>" role="status">
                        <?= e($statusMessage); ?>
                    </div>
                <?php endif; ?>

                <?php if ($verificationUrl !== '' && !mail_is_enabled()): ?>
                    <div class="alert alert-light border">
                        <p class="fw-semibold mb-2">Enlace de verificación generado:</p>
                        <a href="<?= e($verificationUrl); ?>"><?= e($verificationUrl); ?></a>
                    </div>
                <?php endif; ?>

                <form class="needs-validation auth-form" method="post" action="<?= e(url_to('auth/verify-email.php')); ?>" novalidate>
                    <?= csrf_field(); ?>
                    <div class="mb-4">
                        <label class="form-label" for="email">Correo electrónico</label>
                        <input class="form-control" type="email" id="email" name="email" value="<?= e($_GET['email'] ?? ($_SESSION['pending_verification_email'] ?? '')); ?>" placeholder="tu.correo@ejemplo.com" required>
                        <div class="invalid-feedback">Ingresa el correo que registraste.</div>
                    </div>
                    <button class="btn btn-primary w-100" type="submit">Generar enlace de verificación</button>
                </form>

                <p class="auth-switch"><a href="<?= e(url_to('auth/login.php')); ?>">Volver al inicio de sesión</a></p>
            </section>

            <?php require __DIR__ . '/../../../HTML/components/auth-visual.php'; ?>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/../../../HTML/components/footer.php'; ?>
