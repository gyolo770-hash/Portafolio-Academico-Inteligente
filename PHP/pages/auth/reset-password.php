<?php
declare(strict_types=1);

require_once __DIR__ . '/../../helpers/security.php';
require_once __DIR__ . '/../../helpers/auth.php';

$token = (string) ($_POST['token'] ?? ($_GET['token'] ?? ''));
$tokenIsValid = false;
$tokenMessage = '';

if ($token !== '') {
    $checkToken = db()->prepare(
        'SELECT pr.id
         FROM password_resets pr
         WHERE pr.token_hash = :token_hash
           AND pr.used_at IS NULL
           AND pr.expires_at > NOW()
         LIMIT 1'
    );
    $checkToken->execute(['token_hash' => hash('sha256', $token)]);
    $tokenIsValid = (bool) $checkToken->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['_csrf_token'] ?? null)) {
        flash('danger', 'La sesión expiró. Intenta restablecer tu contraseña nuevamente.');
        header('Location: ' . url_to('auth/password-recovery.php'));
        exit;
    }

    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

    if (!$tokenIsValid) {
        $tokenMessage = 'El enlace de recuperación no existe, ya fue usado o expiró.';
    } elseif (strlen($password) < 8) {
        $tokenMessage = 'La nueva contraseña debe tener al menos 8 caracteres.';
    } elseif ($password !== $passwordConfirm) {
        $tokenMessage = 'Las contraseñas no coinciden.';
    } else {
        $tokenHash = hash('sha256', $token);
        $statement = db()->prepare(
            'SELECT pr.id, pr.user_id
             FROM password_resets pr
             WHERE pr.token_hash = :token_hash
               AND pr.used_at IS NULL
               AND pr.expires_at > NOW()
             LIMIT 1'
        );
        $statement->execute(['token_hash' => $tokenHash]);
        $reset = $statement->fetch();

        if ($reset) {
            db()->beginTransaction();
            db()->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id')
                ->execute([
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'id' => $reset['user_id'],
                ]);
            db()->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = :id')
                ->execute(['id' => $reset['id']]);
            db()->commit();

            flash('success', 'Contraseña actualizada correctamente. Ya puedes iniciar sesión.');
            header('Location: ' . url_to('auth/login.php'));
            exit;
        }

        $tokenMessage = 'El enlace de recuperación no existe, ya fue usado o expiró.';
    }
}

$pageTitle = 'Restablecer contraseña';
$pageDescription = 'Define una nueva contraseña para tu cuenta.';
$bodyClass = 'auth-page';
$pageScript = 'auth.js';

require_once __DIR__ . '/../../../HTML/components/header.php';
require_once __DIR__ . '/../../../HTML/components/navbar.php';
?>

<main id="contenido-principal" class="auth-shell">
    <div class="container">
        <div class="auth-grid">
            <section class="auth-card auth-card-compact" aria-labelledby="resetTitle">
                <?php require __DIR__ . '/../../../HTML/components/flash.php'; ?>

                <span class="eyebrow">Nueva contraseña</span>
                <h1 id="resetTitle">Restablece tu acceso</h1>
                <p class="text-secondary">Crea una contraseña segura para proteger tus logros y documentos académicos.</p>

                <?php if ($tokenMessage !== ''): ?>
                    <div class="alert alert-warning" role="alert">
                        <?= e($tokenMessage); ?>
                    </div>
                <?php elseif (!$tokenIsValid): ?>
                    <div class="alert alert-warning" role="alert">
                        El enlace de recuperación no es válido o ya expiró. Solicita uno nuevo.
                    </div>
                <?php endif; ?>

                <form class="needs-validation auth-form" method="post" action="<?= e(url_to('auth/reset-password.php')); ?>" novalidate>
                    <?= csrf_field(); ?>
                    <input type="hidden" name="token" value="<?= e($token); ?>">

                    <div class="mb-3">
                        <label class="form-label" for="password">Nueva contraseña</label>
                        <div class="password-field">
                            <input class="form-control" type="password" id="password" name="password" placeholder="Mínimo 8 caracteres" autocomplete="new-password" required minlength="8">
                            <button class="password-toggle" type="button" aria-label="Mostrar contraseña" data-toggle-password="#password">
                                <i class="bi bi-eye" aria-hidden="true"></i>
                            </button>
                        </div>
                        <div class="invalid-feedback">La contraseña debe tener al menos 8 caracteres.</div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label" for="passwordConfirm">Confirmar contraseña</label>
                        <div class="password-field">
                            <input class="form-control" type="password" id="passwordConfirm" name="password_confirm" placeholder="Repite tu contraseña" autocomplete="new-password" required minlength="8">
                            <button class="password-toggle" type="button" aria-label="Mostrar contraseña" data-toggle-password="#passwordConfirm">
                                <i class="bi bi-eye" aria-hidden="true"></i>
                            </button>
                        </div>
                        <div class="invalid-feedback">Confirma tu nueva contraseña.</div>
                    </div>

                    <button class="btn btn-primary w-100" type="submit" <?= !$tokenIsValid ? 'disabled' : ''; ?>>Guardar nueva contraseña</button>
                </form>

                <p class="auth-switch"><a href="<?= e(url_to('auth/login.php')); ?>">Volver al inicio de sesión</a></p>
            </section>

            <?php require __DIR__ . '/../../../HTML/components/auth-visual.php'; ?>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/../../../HTML/components/footer.php'; ?>
