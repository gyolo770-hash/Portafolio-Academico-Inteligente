<?php
declare(strict_types=1);

require_once __DIR__ . '/../../helpers/security.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/mail.php';
require_once __DIR__ . '/../../helpers/system.php';

if (auth_check()) {
    header('Location: ' . url_to('dashboard/index.php'));
    exit;
}

if (!is_public_registration_allowed()) {
    flash('warning', 'El registro público está deshabilitado temporalmente. Contacta al administrador.');
    header('Location: ' . url_to('auth/login.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_public_registration_allowed()) {
        flash('warning', 'El registro público está deshabilitado temporalmente. Contacta al administrador.');
        header('Location: ' . url_to('auth/login.php'));
        exit;
    }

    if (!verify_csrf_token($_POST['_csrf_token'] ?? null)) {
        flash('danger', 'La sesión expiró. Intenta crear tu cuenta nuevamente.');
        header('Location: ' . url_to('auth/register.php'));
        exit;
    }

    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $username = strtolower(trim((string) ($_POST['username'] ?? '')));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $career = trim((string) ($_POST['career'] ?? ''));
    $school = trim((string) ($_POST['school'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

    $errors = [];

    if ($fullName === '') {
        $errors[] = 'Ingresa tu nombre completo.';
    }

    if (!preg_match('/^[a-z0-9_-]{4,40}$/', $username)) {
        $errors[] = 'El usuario debe tener de 4 a 40 caracteres, solo letras minúsculas, números, guion o guion bajo (sin espacios).';
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Ingresa un correo electrónico válido.';
    }

    if ($career === '' || $school === '') {
        $errors[] = 'Completa tu carrera y escuela o universidad.';
    }

    if (strlen($password) < 8) {
        $errors[] = 'La contraseña debe tener al menos 8 caracteres.';
    }

    if ($password !== $passwordConfirm) {
        $errors[] = 'Las contraseñas no coinciden.';
    }

    if (!isset($_POST['terms'])) {
        $errors[] = 'Debes aceptar el uso de tus datos para continuar.';
    }

    if (empty($errors)) {
        $existing = db()->prepare('SELECT id FROM users WHERE email = :email OR username = :username LIMIT 1');
        $existing->execute(['email' => $email, 'username' => $username]);

        if ($existing->fetch()) {
            $errors[] = 'Ya existe una cuenta con ese correo o usuario.';
        }
    }

    if (!empty($errors)) {
        foreach ($errors as $error) {
            flash('danger', $error);
        }

        header('Location: ' . url_to('auth/register.php'));
        exit;
    }

    try {
        db()->beginTransaction();

        $universityId = null;
        $university = db()->prepare('SELECT id FROM universities WHERE name = :name LIMIT 1');
        $university->execute(['name' => $school]);
        $universityRow = $university->fetch();

        if ($universityRow) {
            $universityId = (int) $universityRow['id'];
        } else {
            $insertUniversity = db()->prepare('INSERT INTO universities (name, country) VALUES (:name, :country)');
            $insertUniversity->execute(['name' => $school, 'country' => 'México']);
            $universityId = (int) db()->lastInsertId();
        }

        $insertUser = db()->prepare(
            'INSERT INTO users (role_id, full_name, username, email, password_hash, status)
             VALUES (:role_id, :full_name, :username, :email, :password_hash, :status)'
        );
        $insertUser->execute([
            'role_id' => student_role_id(),
            'full_name' => $fullName,
            'username' => $username,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'status' => 'pendiente',
        ]);

        $userId = (int) db()->lastInsertId();

        db()->prepare(
            'INSERT INTO user_profiles (user_id, university_id, career, visibility)
             VALUES (:user_id, :university_id, :career, :visibility)'
        )->execute([
            'user_id' => $userId,
            'university_id' => $universityId,
            'career' => $career,
            'visibility' => 'privado',
        ]);

        db()->prepare(
            'INSERT INTO portfolio_settings (user_id, public_slug, is_public)
             VALUES (:user_id, :public_slug, :is_public)'
        )->execute([
            'user_id' => $userId,
            'public_slug' => $username,
            'is_public' => 0,
        ]);

        $verificationToken = create_secure_token();
        store_email_verification($userId, $verificationToken);

        if (!mail_is_enabled()) {
            db()->prepare('UPDATE users SET email_verified_at = NOW(), status = :status WHERE id = :id')
                ->execute(['status' => 'activo', 'id' => $userId]);
            db()->prepare('UPDATE email_verifications SET verified_at = NOW() WHERE user_id = :user_id AND verified_at IS NULL')
                ->execute(['user_id' => $userId]);
        }

        db()->commit();

        if (mail_is_enabled()) {
            $verificationUrl = url_to('auth/verify-email.php?token=' . urlencode($verificationToken));
            send_verification_mail($email, $verificationUrl);
            flash('success', 'Cuenta creada correctamente. Revisa tu correo para activar el acceso.');
            header('Location: ' . url_to('auth/verify-email.php?email=' . urlencode($email)));
            exit;
        }

        flash('success', 'Cuenta creada y activada. Ya puedes iniciar sesión.');
        header('Location: ' . url_to('auth/login.php'));
        exit;
    } catch (Throwable $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }

        error_log('Error al registrar usuario: ' . $exception->getMessage());
        $message = 'No se pudo crear la cuenta. ';
        if (str_contains($exception->getMessage(), 'Unknown database') || str_contains($exception->getMessage(), 'Base table')) {
            $message .= 'Importa PHP/database/database.sql en MySQL y revisa PHP/config/database.php.';
        } elseif (str_contains($exception->getMessage(), 'Access denied')) {
            $message .= 'Revisa el usuario y contraseña de MySQL en PHP/config/database.php o en tu archivo .env.';
        } else {
            $message .= 'Intenta nuevamente. Si el problema continúa, verifica que MySQL esté activo en AppServ.';
        }
        flash('danger', $message);
        header('Location: ' . url_to('auth/register.php'));
        exit;
    }
}

$pageTitle = 'Crear cuenta';
$pageDescription = 'Regístrate para crear tu portafolio académico inteligente.';
$bodyClass = 'auth-page';
$pageScript = 'auth.js';
$dbReady = true;
$dbSetupMessage = '';

try {
    db()->query('SELECT 1');
} catch (Throwable $exception) {
    $dbReady = false;
    $dbSetupMessage = 'MySQL no está disponible o falta importar PHP/database/database.sql. Revisa AppServ y PHP/config/database.php.';
    error_log('Registro: base de datos no disponible: ' . $exception->getMessage());
}

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
            <section class="auth-card" aria-labelledby="registerTitle">
                <?php require __DIR__ . '/../../../HTML/components/flash.php'; ?>

                <span class="eyebrow">Comienza tu portafolio</span>
                <h1 id="registerTitle">Crea tu cuenta académica</h1>
                <p class="text-secondary">Registra tus datos principales y prepara tu perfil para proyectos, certificados y CV.</p>

                <?php if (!$dbReady): ?>
                    <div class="alert alert-danger" role="alert"><?= e($dbSetupMessage); ?></div>
                <?php endif; ?>

                <div class="social-grid" aria-label="Opciones de registro social">
                    <?php foreach ($oauthProviders as $key => $provider): ?>
                        <a class="btn btn-social" href="<?= e(url_to('auth/social.php?provider=' . $key)); ?>" aria-label="Registrarse con <?= e($provider['name']); ?>">
                            <i class="bi bi-<?= $key === 'google' ? 'google' : ($key === 'facebook' ? 'facebook' : 'github'); ?>" aria-hidden="true"></i>
                            <span><?= e($provider['name']); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>

                <div class="auth-divider"><span>o crea una cuenta con correo</span></div>

                <form class="needs-validation auth-form" method="post" action="<?= e(url_to('auth/register.php')); ?>" novalidate>
                    <?= csrf_field(); ?>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="fullName">Nombre completo</label>
                            <input class="form-control" type="text" id="fullName" name="full_name" placeholder="María González" autocomplete="name" required>
                            <div class="invalid-feedback">Ingresa tu nombre completo.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="username">Usuario público</label>
                            <input class="form-control" type="text" id="username" name="username" placeholder="maria-gonzalez" autocomplete="username" required pattern="[a-zA-Z0-9_-]{4,40}">
                            <div class="form-text">Solo minúsculas, números, guion o guion bajo. Ejemplo: maria_gonzalez</div>
                            <div class="invalid-feedback">Usa de 4 a 40 caracteres, letras, números, guion o guion bajo.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="email">Correo electrónico</label>
                            <input class="form-control" type="email" id="email" name="email" placeholder="tu.correo@ejemplo.com" autocomplete="email" required>
                            <div class="invalid-feedback">Ingresa un correo electrónico válido.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="career">Carrera o área de interés</label>
                            <input class="form-control" type="text" id="career" name="career" placeholder="Ingeniería en Sistemas" required>
                            <div class="invalid-feedback">Indica tu carrera o área de interés.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="school">Escuela o universidad</label>
                            <input class="form-control" type="text" id="school" name="school" placeholder="CECyTEM / Universidad" required>
                            <div class="invalid-feedback">Indica tu institución académica.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="password">Contraseña</label>
                            <div class="password-field">
                                <input class="form-control" type="password" id="password" name="password" placeholder="Mínimo 8 caracteres" autocomplete="new-password" required minlength="8">
                                <button class="password-toggle" type="button" aria-label="Mostrar contraseña" data-toggle-password="#password">
                                    <i class="bi bi-eye" aria-hidden="true"></i>
                                </button>
                            </div>
                            <div class="invalid-feedback">La contraseña debe tener al menos 8 caracteres.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="passwordConfirm">Confirmar contraseña</label>
                            <div class="password-field">
                                <input class="form-control" type="password" id="passwordConfirm" name="password_confirm" placeholder="Repite tu contraseña" autocomplete="new-password" required minlength="8">
                                <button class="password-toggle" type="button" aria-label="Mostrar contraseña" data-toggle-password="#passwordConfirm">
                                    <i class="bi bi-eye" aria-hidden="true"></i>
                                </button>
                            </div>
                            <div class="invalid-feedback">Confirma tu contraseña.</div>
                        </div>
                    </div>

                    <div class="form-check my-4">
                        <input class="form-check-input" type="checkbox" id="terms" name="terms" required>
                        <label class="form-check-label" for="terms">Acepto el uso de mis datos para crear mi portafolio académico.</label>
                        <div class="invalid-feedback">Debes aceptar para continuar.</div>
                    </div>

                    <button class="btn btn-primary w-100" type="submit" <?= $dbReady ? '' : 'disabled'; ?>>Crear cuenta</button>
                </form>

                <p class="auth-switch">¿Ya tienes cuenta? <a href="<?= e(url_to('auth/login.php')); ?>">Iniciar sesión</a></p>
            </section>

            <?php require __DIR__ . '/../../../HTML/components/auth-visual.php'; ?>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/../../../HTML/components/footer.php'; ?>
