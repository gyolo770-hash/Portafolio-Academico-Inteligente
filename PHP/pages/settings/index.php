<?php
declare(strict_types=1);

$pageTitle = 'Configuración';
$pageDescription = 'Gestiona preferencias de accesibilidad, privacidad y eliminación de cuenta.';
$bodyClass = 'dashboard-page settings-page';
$activeItem = 'configuracion';

require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../helpers/upload.php';
require_once __DIR__ . '/../../helpers/portfolio.php';
require_once __DIR__ . '/../../helpers/schema.php';
require_auth();

$currentUser = auth_user();
$userId = (int) $currentUser['id'];
$userRole = (string) ($currentUser['role_name'] ?? 'estudiante');
$isStudent = $userRole === 'estudiante';
$isRecruiter = $userRole === 'reclutador';
$isAdmin = $userRole === 'administrador';

function settings_ensure_records(int $userId, string $username): void
{
    $profileExists = db()->prepare('SELECT id FROM user_profiles WHERE user_id = :user_id LIMIT 1');
    $profileExists->execute(['user_id' => $userId]);

    if (!$profileExists->fetch()) {
        db()->prepare('INSERT INTO user_profiles (user_id, visibility) VALUES (:user_id, :visibility)')
            ->execute(['user_id' => $userId, 'visibility' => 'privado']);
    }

    $settingsExists = db()->prepare('SELECT id FROM portfolio_settings WHERE user_id = :user_id LIMIT 1');
    $settingsExists->execute(['user_id' => $userId]);

    if (!$settingsExists->fetch()) {
        db()->prepare(
            'INSERT INTO portfolio_settings (user_id, public_slug, theme_color, is_public, color_blind_mode, allow_contact)
             VALUES (:user_id, :public_slug, :theme_color, :is_public, :color_blind_mode, :allow_contact)'
        )->execute([
            'user_id' => $userId,
            'public_slug' => $username,
            'theme_color' => '#4F46E5',
            'is_public' => 0,
            'color_blind_mode' => 0,
            'allow_contact' => 1,
        ]);
    }
}

function settings_collect_upload_paths(int $userId): array
{
    return collect_user_upload_paths($userId);
}

function settings_delete_uploads(array $paths): void
{
    delete_upload_files($paths);
}

settings_ensure_records($userId, (string) $currentUser['username']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['_csrf_token'] ?? null)) {
        flash('danger', 'La sesión expiró. Intenta guardar la configuración nuevamente.');
        header('Location: ' . url_to('settings/index.php'));
        exit;
    }

    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'update_preferences' && $isStudent) {
            $colorBlindMode = isset($_POST['color_blind_mode']) ? 1 : 0;
            $allowContact = isset($_POST['allow_contact']) ? 1 : 0;
            $isPublic = isset($_POST['is_public']) ? 1 : 0;

            db()->beginTransaction();

            db()->prepare(
                'UPDATE portfolio_settings
                 SET color_blind_mode = :color_blind_mode,
                     allow_contact = :allow_contact,
                     is_public = :is_public
                 WHERE user_id = :user_id'
            )->execute([
                'color_blind_mode' => $colorBlindMode,
                'allow_contact' => $allowContact,
                'is_public' => $isPublic,
                'user_id' => $userId,
            ]);

            db()->prepare('UPDATE user_profiles SET visibility = :visibility WHERE user_id = :user_id')
                ->execute([
                    'visibility' => $isPublic ? 'publico' : 'privado',
                    'user_id' => $userId,
                ]);

            db()->commit();
            flash('success', 'Configuración actualizada correctamente.');
        }

        if ($action === 'update_accessibility') {
            settings_ensure_records($userId, (string) $currentUser['username']);
            $colorBlindMode = isset($_POST['color_blind_mode']) ? 1 : 0;

            db()->prepare(
                'UPDATE portfolio_settings
                 SET color_blind_mode = :color_blind_mode
                 WHERE user_id = :user_id'
            )->execute([
                'color_blind_mode' => $colorBlindMode,
                'user_id' => $userId,
            ]);

            flash('success', 'Modo Deuteranopia actualizado correctamente.');
        }

        if ($action === 'update_recruiter_messages' && $isRecruiter) {
            ensure_runtime_schema();
            $acceptMessages = isset($_POST['accept_student_messages']) ? 1 : 0;

            db()->prepare(
                'UPDATE recruiters
                 SET accept_student_messages = :accept_student_messages
                 WHERE email = :email'
            )->execute([
                'accept_student_messages' => $acceptMessages,
                'email' => $currentUser['email'],
            ]);

            flash('success', 'Preferencia de mensajes actualizada.');
        }

        if ($action === 'delete_account') {
            $password = (string) ($_POST['password'] ?? '');
            $confirmation = trim((string) ($_POST['confirmation'] ?? ''));

            $userStatement = db()->prepare('SELECT id, email, password_hash FROM users WHERE id = :id LIMIT 1');
            $userStatement->execute(['id' => $userId]);
            $user = $userStatement->fetch();

            if (!$user || $confirmation !== 'ELIMINAR') {
                flash('danger', 'Para eliminar tu cuenta escribe exactamente ELIMINAR.');
                header('Location: ' . url_to('settings/index.php#zona-peligro'));
                exit;
            }

            if (!empty($user['password_hash']) && !password_verify($password, $user['password_hash'])) {
                flash('danger', 'La contraseña no coincide con tu cuenta.');
                header('Location: ' . url_to('settings/index.php#zona-peligro'));
                exit;
            }

            $uploadPaths = $isStudent ? settings_collect_upload_paths($userId) : [];

            db()->beginTransaction();
            db()->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $userId]);
            db()->commit();

            settings_delete_uploads($uploadPaths);
            auth_logout();

            if (session_status() === PHP_SESSION_NONE) {
                session_name('portafolio_academico');
                session_start([
                    'cookie_httponly' => true,
                    'cookie_samesite' => 'Lax',
                    'use_strict_mode' => true,
                ]);
            }

            flash('success', 'Tu cuenta y datos asociados fueron eliminados correctamente.');
            header('Location: ' . url_to('auth/login.php'));
            exit;
        }
    } catch (Throwable $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }

        error_log('Error en configuración: ' . $exception->getMessage());
        flash('danger', 'No se pudo completar la acción de configuración.');
    }

    header('Location: ' . url_to('settings/index.php'));
    exit;
}

$recruiterAcceptsMessages = true;
if ($isRecruiter) {
    ensure_runtime_schema();
    $recruiterStatement = db()->prepare('SELECT accept_student_messages FROM recruiters WHERE email = :email LIMIT 1');
    $recruiterStatement->execute(['email' => $currentUser['email']]);
    $recruiterAcceptsMessages = (int) ($recruiterStatement->fetchColumn() ?: 1) === 1;
}

settings_ensure_records($userId, (string) $currentUser['username']);

$settingsStatement = db()->prepare(
    'SELECT ps.*, up.visibility, u.full_name, u.email, u.username
     FROM portfolio_settings ps
     INNER JOIN users u ON u.id = ps.user_id
     LEFT JOIN user_profiles up ON up.user_id = ps.user_id
     WHERE ps.user_id = :user_id
     LIMIT 1'
);
$settingsStatement->execute(['user_id' => $userId]);
$settings = $settingsStatement->fetch() ?: [];

$colorBlindMode = (int) ($settings['color_blind_mode'] ?? 0) === 1;

if ($isStudent) {
    $portfolioUrl = portfolio_public_url((string) ($settings['public_slug'] ?? $currentUser['username']));
    $isPublic = (int) ($settings['is_public'] ?? 0) === 1;
    $allowContact = (int) ($settings['allow_contact'] ?? 1) === 1;

    $counts = [];
    foreach ([
        'Proyectos' => 'SELECT COUNT(*) FROM projects WHERE user_id = :user_id',
        'Certificaciones' => 'SELECT COUNT(*) FROM certifications WHERE user_id = :user_id',
        'Habilidades' => 'SELECT COUNT(*) FROM user_skills WHERE user_id = :user_id',
        'CV guardados' => 'SELECT COUNT(*) FROM resumes WHERE user_id = :user_id',
    ] as $label => $sql) {
        $statement = db()->prepare($sql);
        $statement->execute(['user_id' => $userId]);
        $counts[$label] = (int) $statement->fetchColumn();
    }
} else {
    $portfolioUrl = '';
    $isPublic = false;
    $allowContact = false;
    $counts = [];
}

require_once __DIR__ . '/../../../HTML/components/header.php';
?>

<main id="contenido-principal" class="dashboard-shell">
    <?php require __DIR__ . '/../../../HTML/components/sidebar.php'; ?>

    <div class="dashboard-main">
        <?php require __DIR__ . '/../../../HTML/components/dashboard-header.php'; ?>

        <section class="dashboard-content">
        <?php require __DIR__ . '/../../../HTML/components/flash.php'; ?>

        <div class="dashboard-topbar">
            <div>
                <span class="eyebrow">Configuración</span>
                <h1>Preferencias de cuenta</h1>
                <?php if ($isStudent): ?>
                    <p>Controla accesibilidad, privacidad del portafolio, contacto público y acciones sensibles de tu perfil académico.</p>
                <?php elseif ($isRecruiter): ?>
                    <p>Administra tu cuenta de reclutador y decide si quieres recibir mensajes de estudiantes.</p>
                <?php else: ?>
                    <p>Gestiona tu cuenta de administrador. Desde aquí validas universidades y reclutadores en el panel de administración.</p>
                <?php endif; ?>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <?php if ($isStudent && $portfolioUrl !== ''): ?>
                    <a class="btn btn-outline-primary" href="<?= e($portfolioUrl); ?>" target="_blank" rel="noopener">Ver portafolio</a>
                <?php endif; ?>
                <?php if ($isAdmin): ?>
                    <a class="btn btn-outline-primary" href="<?= e(url_to('admin/index.php')); ?>">Ir a administración</a>
                <?php endif; ?>
                <a class="btn btn-outline-danger" href="<?= e(url_to('auth/logout.php')); ?>">Cerrar sesión</a>
            </div>
        </div>

        <?php if ($isRecruiter): ?>
            <section class="dashboard-card" aria-labelledby="recruiterMessagesTitle">
                <div class="card-heading">
                    <div>
                        <span class="eyebrow">Mensajería</span>
                        <h2 id="recruiterMessagesTitle">Mensajes de estudiantes</h2>
                    </div>
                    <span class="status-pill <?= $recruiterAcceptsMessages ? 'success' : 'warning'; ?>">
                        <?= $recruiterAcceptsMessages ? 'Recibiendo mensajes' : 'No disponible'; ?>
                    </span>
                </div>

                <form class="settings-form" method="post" action="<?= e(url_to('settings/index.php')); ?>">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action" value="update_recruiter_messages">

                    <label class="settings-switch-card" for="acceptStudentMessages">
                        <span>
                            <strong>Recibir mensajes de alumnos</strong>
                            <small>Si lo desactivas, los estudiantes no podrán contactarte desde el directorio de reclutadores.</small>
                        </span>
                        <input class="form-check-input" type="checkbox" id="acceptStudentMessages" name="accept_student_messages" <?= $recruiterAcceptsMessages ? 'checked' : ''; ?>>
                    </label>

                    <div class="d-flex justify-content-end mt-4">
                        <button class="btn btn-primary" type="submit">Guardar preferencia</button>
                    </div>
                </form>
            </section>
        <?php endif; ?>

        <?php if ($isAdmin): ?>
            <section class="dashboard-card" aria-labelledby="adminAccountTitle">
                <div class="card-heading">
                    <div>
                        <span class="eyebrow">Cuenta</span>
                        <h2 id="adminAccountTitle">Administrador</h2>
                    </div>
                </div>
                <dl class="profile-list">
                    <div>
                        <dt>Nombre</dt>
                        <dd><?= e($settings['full_name'] ?? $currentUser['full_name']); ?></dd>
                    </div>
                    <div>
                        <dt>Correo</dt>
                        <dd><?= e($settings['email'] ?? $currentUser['email']); ?></dd>
                    </div>
                    <div>
                        <dt>Rol</dt>
                        <dd>Administrador del sistema</dd>
                    </div>
                </dl>
                <p class="mb-0 text-secondary">Tu trabajo principal es verificar que las universidades y los reclutadores registrados sean reales. Usa el panel de administración para aprobar o rechazar registros.</p>
            </section>
        <?php endif; ?>

        <?php if (!$isStudent): ?>
            <section class="dashboard-card" aria-labelledby="accessibilityTitle">
                <div class="card-heading">
                    <div>
                        <span class="eyebrow">Accesibilidad</span>
                        <h2 id="accessibilityTitle">Modo Deuteranopia</h2>
                    </div>
                    <span class="status-pill <?= $colorBlindMode ? 'info' : 'warning'; ?>">
                        <?= $colorBlindMode ? 'Activo' : 'Inactivo'; ?>
                    </span>
                </div>

                <form class="settings-form" method="post" action="<?= e(url_to('settings/index.php')); ?>">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action" value="update_accessibility">

                    <label class="settings-switch-card" for="colorBlindModeGlobal">
                        <span>
                            <strong>Activar modo Deuteranopia</strong>
                            <small>Cambia la paleta verde por azul oscuro, cian y marino en toda la interfaz. Los estados usan iconos y evitan combinaciones rojo/verde.</small>
                        </span>
                        <input class="form-check-input" type="checkbox" id="colorBlindModeGlobal" name="color_blind_mode" data-color-blind-toggle <?= $colorBlindMode ? 'checked' : ''; ?>>
                    </label>

                    <div class="d-flex justify-content-end mt-4">
                        <button class="btn btn-primary" type="submit">Guardar accesibilidad</button>
                    </div>
                </form>
            </section>
        <?php endif; ?>

        <?php if ($isStudent): ?>
        <div class="settings-layout">
            <section class="dashboard-card" aria-labelledby="preferencesTitle">
                <div class="card-heading">
                    <div>
                        <span class="eyebrow">Preferencias</span>
                        <h2 id="preferencesTitle">Accesibilidad y privacidad</h2>
                    </div>
                    <span class="status-pill <?= $isPublic ? 'success' : 'warning'; ?>"><?= $isPublic ? 'Público' : 'Privado'; ?></span>
                </div>

                <form class="settings-form" method="post" action="<?= e(url_to('settings/index.php')); ?>">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action" value="update_preferences">

                    <div class="settings-switch-list">
                        <label class="settings-switch-card" for="colorBlindMode">
                            <span>
                                <strong>Modo Deuteranopia</strong>
                                <small>Reemplaza los tonos verdes por azul oscuro, cian y marino. Los estados usan iconos y colores distinguibles sin combinar rojo y verde.</small>
                            </span>
                            <input class="form-check-input" type="checkbox" id="colorBlindMode" name="color_blind_mode" data-color-blind-toggle <?= $colorBlindMode ? 'checked' : ''; ?>>
                        </label>

                        <label class="settings-switch-card" for="isPublic">
                            <span>
                                <strong>Portafolio público</strong>
                                <small>Permite que reclutadores y visitantes vean tu perfil con la URL pública.</small>
                            </span>
                            <input class="form-check-input" type="checkbox" id="isPublic" name="is_public" <?= $isPublic ? 'checked' : ''; ?>>
                        </label>

                        <label class="settings-switch-card" for="allowContact">
                            <span>
                                <strong>Formulario de contacto</strong>
                                <small>Permite recibir mensajes desde tu portafolio público.</small>
                            </span>
                            <input class="form-check-input" type="checkbox" id="allowContact" name="allow_contact" <?= $allowContact ? 'checked' : ''; ?>>
                        </label>
                    </div>

                    <div class="d-flex flex-wrap gap-2 justify-content-end mt-4">
                        <button class="btn btn-primary" type="submit">Guardar configuración</button>
                    </div>
                </form>
            </section>

            <aside class="settings-aside">
                <section class="dashboard-card" aria-labelledby="accountSummaryTitle">
                    <div class="card-heading">
                        <div>
                            <span class="eyebrow">Cuenta</span>
                            <h2 id="accountSummaryTitle">Resumen</h2>
                        </div>
                    </div>

                    <dl class="profile-list">
                        <div>
                            <dt>Nombre</dt>
                            <dd><?= e($settings['full_name'] ?? $currentUser['full_name']); ?></dd>
                        </div>
                        <div>
                            <dt>Correo</dt>
                            <dd><?= e($settings['email'] ?? $currentUser['email']); ?></dd>
                        </div>
                        <div>
                            <dt>URL pública</dt>
                            <dd>/p/<?= e($settings['public_slug'] ?? $currentUser['username']); ?></dd>
                        </div>
                    </dl>
                </section>

                <section class="dashboard-card" aria-labelledby="dataSummaryTitle">
                    <div class="card-heading">
                        <div>
                            <span class="eyebrow">Datos guardados</span>
                            <h2 id="dataSummaryTitle">Contenido asociado</h2>
                        </div>
                    </div>

                    <div class="settings-count-grid">
                        <?php foreach ($counts as $label => $value): ?>
                            <article>
                                <strong><?= e((string) $value); ?></strong>
                                <span><?= e($label); ?></span>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            </aside>
        </div>
        <?php endif; ?>

        <?php if (!$isAdmin): ?>
        <section id="zona-peligro" class="dashboard-card danger-zone" aria-labelledby="dangerTitle">
            <div class="card-heading">
                <div>
                    <span class="eyebrow">Zona de riesgo</span>
                    <h2 id="dangerTitle">Eliminar perfil</h2>
                </div>
                <span class="status-pill warning">Acción irreversible</span>
            </div>

            <p><?= $isStudent
                ? 'Al eliminar tu perfil se borrarán tu cuenta, portafolio, proyectos, certificaciones, habilidades, CVs, recomendaciones, mensajes y archivos asociados. Esta acción no se puede deshacer.'
                : 'Al eliminar tu cuenta se borrarán tus datos asociados en la plataforma. Esta acción no se puede deshacer.'; ?></p>

            <form class="settings-delete-form" method="post" action="<?= e(url_to('settings/index.php#zona-peligro')); ?>">
                <?= csrf_field(); ?>
                <input type="hidden" name="action" value="delete_account">

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="deletePassword">Contraseña actual</label>
                        <input class="form-control" type="password" id="deletePassword" name="password" autocomplete="current-password" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="deleteConfirmation">Escribe ELIMINAR para confirmar</label>
                        <input class="form-control" type="text" id="deleteConfirmation" name="confirmation" pattern="ELIMINAR" required>
                    </div>
                </div>

                <div class="d-flex justify-content-end mt-4">
                    <button class="btn btn-outline-danger" type="submit">Eliminar mi perfil definitivamente</button>
                </div>
            </form>
        </section>
        <?php endif; ?>
        <?php require __DIR__ . '/../../../HTML/components/mobile-nav.php'; ?>
    </section>
    </div>
</main>

<?php require_once __DIR__ . '/../../../HTML/components/footer.php'; ?>
