<?php
declare(strict_types=1);

$pageTitle = 'Mi perfil';
$pageDescription = 'Gestiona tu perfil académico, educación, foto, idiomas y enlaces sociales.';
$bodyClass = 'dashboard-page profile-page';
$activeItem = 'perfil';
$pageScript = 'auth.js';

require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../helpers/upload.php';
require_student();

$currentUser = auth_user();
$userId = (int) $currentUser['id'];

function profile_completion_score(array $user, array $profile, int $educationCount): int
{
    $items = [
        !empty($user['full_name']),
        !empty($user['email']),
        !empty($user['username']),
        !empty($user['avatar_path']),
        !empty($profile['career']),
        !empty($profile['university_name']),
        !empty($profile['about_me']),
        !empty($profile['phone']),
        !empty($profile['languages']),
        !empty($profile['github_url']) || !empty($profile['linkedin_url']) || !empty($profile['portfolio_url']) || !empty($profile['instagram_url']),
        $educationCount > 0,
    ];

    $completed = 0;
    foreach ($items as $item) {
        if ($item) {
            $completed++;
        }
    }

    return (int) round(($completed / count($items)) * 100);
}

function find_or_create_university(?string $name)
{
    $name = trim((string) $name);
    if ($name === '') {
        return null;
    }

    $statement = db()->prepare('SELECT id FROM universities WHERE name = :name LIMIT 1');
    $statement->execute(['name' => $name]);
    $university = $statement->fetch();

    if ($university) {
        return (int) $university['id'];
    }

    db()->prepare('INSERT INTO universities (name, country) VALUES (:name, :country)')
        ->execute(['name' => $name, 'country' => 'México']);

    return (int) db()->lastInsertId();
}

function ensure_profile_records(int $userId, string $username): void
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
        db()->prepare('INSERT INTO portfolio_settings (user_id, public_slug, is_public) VALUES (:user_id, :public_slug, :is_public)')
            ->execute(['user_id' => $userId, 'public_slug' => $username, 'is_public' => 0]);
    }
}

ensure_profile_records($userId, (string) $currentUser['username']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['_csrf_token'] ?? null)) {
        flash('danger', 'La sesión expiró. Intenta guardar los cambios nuevamente.');
        header('Location: ' . url_to('profile/index.php'));
        exit;
    }

    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'update_profile') {
            $fullName = trim((string) ($_POST['full_name'] ?? ''));
            $career = trim((string) ($_POST['career'] ?? ''));
            $universityName = trim((string) ($_POST['university_name'] ?? ''));
            $graduationYear = trim((string) ($_POST['graduation_year'] ?? ''));
            $phone = trim((string) ($_POST['phone'] ?? ''));
            $location = trim((string) ($_POST['location'] ?? ''));
            $aboutMe = trim((string) ($_POST['about_me'] ?? ''));
            $languages = trim((string) ($_POST['languages'] ?? ''));
            $visibility = ($_POST['visibility'] ?? 'privado') === 'publico' ? 'publico' : 'privado';
            $publicSlug = strtolower(trim((string) ($_POST['public_slug'] ?? $currentUser['username'])));
            $githubUrl = trim((string) ($_POST['github_url'] ?? ''));
            $linkedinUrl = trim((string) ($_POST['linkedin_url'] ?? ''));
            $portfolioUrl = trim((string) ($_POST['portfolio_url'] ?? ''));
            $instagramUrl = trim((string) ($_POST['instagram_url'] ?? ''));

            $errors = [];
            if ($fullName === '') {
                $errors[] = 'El nombre completo es obligatorio.';
            }

            if (!preg_match('/^[a-z0-9_-]{4,80}$/', $publicSlug)) {
                $errors[] = 'La URL pública debe tener de 4 a 80 caracteres y solo usar letras, números, guion o guion bajo.';
            }

            $urlFields = [
                'GitHub' => $githubUrl,
                'LinkedIn' => $linkedinUrl,
                'Portafolio' => $portfolioUrl,
                'Instagram' => $instagramUrl,
            ];

            foreach ($urlFields as $label => $urlValue) {
                if ($urlValue !== '' && !filter_var($urlValue, FILTER_VALIDATE_URL)) {
                    $errors[] = 'El enlace de ' . $label . ' no es válido.';
                }
            }

            if ($graduationYear !== '' && (!ctype_digit($graduationYear) || (int) $graduationYear < 1950 || (int) $graduationYear > 2155)) {
                $errors[] = 'El año de graduación no es válido.';
            }

            $slugStatement = db()->prepare('SELECT user_id FROM portfolio_settings WHERE public_slug = :slug AND user_id <> :user_id LIMIT 1');
            $slugStatement->execute(['slug' => $publicSlug, 'user_id' => $userId]);
            if ($slugStatement->fetch()) {
                $errors[] = 'La URL pública ya está en uso por otra cuenta.';
            }

            if (!empty($errors)) {
                foreach ($errors as $error) {
                    flash('danger', $error);
                }

                header('Location: ' . url_to('profile/index.php'));
                exit;
            }

            $universityId = find_or_create_university($universityName);

            db()->beginTransaction();
            db()->prepare('UPDATE users SET full_name = :full_name WHERE id = :id')
                ->execute(['full_name' => $fullName, 'id' => $userId]);

            db()->prepare(
                'UPDATE user_profiles
                 SET university_id = :university_id,
                     about_me = :about_me,
                     career = :career,
                     graduation_year = :graduation_year,
                     phone = :phone,
                     location = :location,
                     github_url = :github_url,
                     linkedin_url = :linkedin_url,
                     portfolio_url = :portfolio_url,
                     instagram_url = :instagram_url,
                     languages = :languages,
                     visibility = :visibility
                 WHERE user_id = :user_id'
            )->execute([
                'university_id' => $universityId,
                'about_me' => $aboutMe !== '' ? $aboutMe : null,
                'career' => $career !== '' ? $career : null,
                'graduation_year' => $graduationYear !== '' ? $graduationYear : null,
                'phone' => $phone !== '' ? $phone : null,
                'location' => $location !== '' ? $location : null,
                'github_url' => $githubUrl !== '' ? $githubUrl : null,
                'linkedin_url' => $linkedinUrl !== '' ? $linkedinUrl : null,
                'portfolio_url' => $portfolioUrl !== '' ? $portfolioUrl : null,
                'instagram_url' => $instagramUrl !== '' ? $instagramUrl : null,
                'languages' => $languages !== '' ? $languages : null,
                'visibility' => $visibility,
                'user_id' => $userId,
            ]);

            db()->prepare('UPDATE portfolio_settings SET public_slug = :public_slug, is_public = :is_public WHERE user_id = :user_id')
                ->execute([
                    'public_slug' => $publicSlug,
                    'is_public' => $visibility === 'publico' ? 1 : 0,
                    'user_id' => $userId,
                ]);

            db()->commit();
            flash('success', 'Perfil actualizado correctamente.');
        }

        if ($action === 'upload_avatar') {
            $previousStatement = db()->prepare('SELECT avatar_path FROM users WHERE id = :id LIMIT 1');
            $previousStatement->execute(['id' => $userId]);
            $previousAvatar = $previousStatement->fetchColumn();

            $avatarPath = upload_avatar($_FILES['avatar'] ?? [], $userId);

            if ($avatarPath === null) {
                flash('warning', 'Selecciona una imagen para subir.');
            } else {
                db()->prepare('UPDATE users SET avatar_path = :avatar_path WHERE id = :id')
                    ->execute(['avatar_path' => $avatarPath, 'id' => $userId]);

                if ($previousAvatar && $previousAvatar !== $avatarPath) {
                    delete_upload_file((string) $previousAvatar);
                }

                flash('success', 'Foto de perfil actualizada correctamente.');
            }
        }

        if ($action === 'remove_avatar') {
            $previousStatement = db()->prepare('SELECT avatar_path FROM users WHERE id = :id LIMIT 1');
            $previousStatement->execute(['id' => $userId]);
            $previousAvatar = $previousStatement->fetchColumn();

            db()->prepare('UPDATE users SET avatar_path = NULL WHERE id = :id')->execute(['id' => $userId]);

            if ($previousAvatar) {
                delete_upload_file((string) $previousAvatar);
            }

            flash('success', 'Foto de perfil eliminada.');
        }

        if ($action === 'save_education') {
            $educationId = (int) ($_POST['education_id'] ?? 0);
            $institutionName = trim((string) ($_POST['institution_name'] ?? ''));
            $degree = trim((string) ($_POST['degree'] ?? ''));
            $fieldOfStudy = trim((string) ($_POST['field_of_study'] ?? ''));
            $startDate = trim((string) ($_POST['start_date'] ?? ''));
            $endDate = trim((string) ($_POST['end_date'] ?? ''));
            $isCurrent = isset($_POST['is_current']) ? 1 : 0;
            $description = trim((string) ($_POST['description'] ?? ''));

            if ($institutionName === '' || $degree === '') {
                flash('danger', 'La institución y el grado/programa son obligatorios.');
                header('Location: ' . url_to('profile/index.php#educacion'));
                exit;
            }

            $educationUniversityId = find_or_create_university($institutionName);

            if ($educationId > 0) {
                db()->prepare(
                    'UPDATE education
                     SET university_id = :university_id,
                         institution_name = :institution_name,
                         degree = :degree,
                         field_of_study = :field_of_study,
                         start_date = :start_date,
                         end_date = :end_date,
                         is_current = :is_current,
                         description = :description
                     WHERE id = :id AND user_id = :user_id'
                )->execute([
                    'university_id' => $educationUniversityId,
                    'institution_name' => $institutionName,
                    'degree' => $degree,
                    'field_of_study' => $fieldOfStudy !== '' ? $fieldOfStudy : null,
                    'start_date' => $startDate !== '' ? $startDate : null,
                    'end_date' => $isCurrent ? null : ($endDate !== '' ? $endDate : null),
                    'is_current' => $isCurrent,
                    'description' => $description !== '' ? $description : null,
                    'id' => $educationId,
                    'user_id' => $userId,
                ]);
                flash('success', 'Educación actualizada correctamente.');
            } else {
                db()->prepare(
                    'INSERT INTO education (user_id, university_id, institution_name, degree, field_of_study, start_date, end_date, is_current, description)
                     VALUES (:user_id, :university_id, :institution_name, :degree, :field_of_study, :start_date, :end_date, :is_current, :description)'
                )->execute([
                    'user_id' => $userId,
                    'university_id' => $educationUniversityId,
                    'institution_name' => $institutionName,
                    'degree' => $degree,
                    'field_of_study' => $fieldOfStudy !== '' ? $fieldOfStudy : null,
                    'start_date' => $startDate !== '' ? $startDate : null,
                    'end_date' => $isCurrent ? null : ($endDate !== '' ? $endDate : null),
                    'is_current' => $isCurrent,
                    'description' => $description !== '' ? $description : null,
                ]);
                flash('success', 'Educación agregada correctamente.');
            }
        }

        if ($action === 'delete_education') {
            $educationId = (int) ($_POST['education_id'] ?? 0);
            db()->prepare('DELETE FROM education WHERE id = :id AND user_id = :user_id')
                ->execute(['id' => $educationId, 'user_id' => $userId]);
            flash('success', 'Registro educativo eliminado.');
        }

        if ($action === 'save_experience') {
            $experienceId = (int) ($_POST['experience_id'] ?? 0);
            $title = trim((string) ($_POST['title'] ?? ''));
            $organization = trim((string) ($_POST['organization'] ?? ''));
            $type = (string) ($_POST['type'] ?? 'otro');
            $startDate = trim((string) ($_POST['experience_start_date'] ?? ''));
            $endDate = trim((string) ($_POST['experience_end_date'] ?? ''));
            $isCurrent = isset($_POST['experience_is_current']) ? 1 : 0;
            $description = trim((string) ($_POST['experience_description'] ?? ''));
            $validTypes = ['practica', 'empleo', 'voluntariado', 'actividad', 'otro'];

            if ($title === '' || $organization === '') {
                flash('danger', 'El puesto/actividad y la organización son obligatorios.');
                header('Location: ' . url_to('profile/index.php#experiencias'));
                exit;
            }

            if (!in_array($type, $validTypes, true)) {
                $type = 'otro';
            }

            if ($experienceId > 0) {
                db()->prepare(
                    'UPDATE experiences
                     SET title = :title,
                         organization = :organization,
                         type = :type,
                         start_date = :start_date,
                         end_date = :end_date,
                         is_current = :is_current,
                         description = :description
                     WHERE id = :id AND user_id = :user_id'
                )->execute([
                    'title' => $title,
                    'organization' => $organization,
                    'type' => $type,
                    'start_date' => $startDate !== '' ? $startDate : null,
                    'end_date' => $isCurrent ? null : ($endDate !== '' ? $endDate : null),
                    'is_current' => $isCurrent,
                    'description' => $description !== '' ? $description : null,
                    'id' => $experienceId,
                    'user_id' => $userId,
                ]);
                flash('success', 'Experiencia actualizada correctamente.');
            } else {
                db()->prepare(
                    'INSERT INTO experiences (user_id, title, organization, type, start_date, end_date, is_current, description)
                     VALUES (:user_id, :title, :organization, :type, :start_date, :end_date, :is_current, :description)'
                )->execute([
                    'user_id' => $userId,
                    'title' => $title,
                    'organization' => $organization,
                    'type' => $type,
                    'start_date' => $startDate !== '' ? $startDate : null,
                    'end_date' => $isCurrent ? null : ($endDate !== '' ? $endDate : null),
                    'is_current' => $isCurrent,
                    'description' => $description !== '' ? $description : null,
                ]);
                flash('success', 'Experiencia agregada correctamente.');
            }
        }

        if ($action === 'delete_experience') {
            $experienceId = (int) ($_POST['experience_id'] ?? 0);
            db()->prepare('DELETE FROM experiences WHERE id = :id AND user_id = :user_id')
                ->execute(['id' => $experienceId, 'user_id' => $userId]);
            flash('success', 'Experiencia eliminada.');
        }
    } catch (Throwable $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }

        error_log('Error en módulo de perfil: ' . $exception->getMessage());
        flash('danger', $exception->getMessage() ?: 'No se pudieron guardar los cambios del perfil.');
    }

    header('Location: ' . url_to('profile/index.php'));
    exit;
}

$currentUser = auth_user();

$profileStatement = db()->prepare(
    'SELECT up.*, un.name AS university_name, ps.public_slug, ps.is_public
     FROM user_profiles up
     LEFT JOIN universities un ON un.id = up.university_id
     LEFT JOIN portfolio_settings ps ON ps.user_id = up.user_id
     WHERE up.user_id = :user_id
     LIMIT 1'
);
$profileStatement->execute(['user_id' => $userId]);
$profile = $profileStatement->fetch() ?: [];

$educationStatement = db()->prepare(
    'SELECT *
     FROM education
     WHERE user_id = :user_id
     ORDER BY is_current DESC, COALESCE(end_date, start_date) DESC, created_at DESC'
);
$educationStatement->execute(['user_id' => $userId]);
$educationItems = $educationStatement->fetchAll();

$experienceStatement = db()->prepare(
    'SELECT *
     FROM experiences
     WHERE user_id = :user_id
     ORDER BY is_current DESC, COALESCE(end_date, start_date) DESC, created_at DESC'
);
$experienceStatement->execute(['user_id' => $userId]);
$experienceItems = $experienceStatement->fetchAll();

$editEducation = null;
if (isset($_GET['edit_education'])) {
    $editStatement = db()->prepare('SELECT * FROM education WHERE id = :id AND user_id = :user_id LIMIT 1');
    $editStatement->execute([
        'id' => (int) $_GET['edit_education'],
        'user_id' => $userId,
    ]);
    $editEducation = $editStatement->fetch() ?: null;
}

$editExperience = null;
if (isset($_GET['edit_experience'])) {
    $editStatement = db()->prepare('SELECT * FROM experiences WHERE id = :id AND user_id = :user_id LIMIT 1');
    $editStatement->execute([
        'id' => (int) $_GET['edit_experience'],
        'user_id' => $userId,
    ]);
    $editExperience = $editStatement->fetch() ?: null;
}

$profileCompletion = profile_completion_score($currentUser, $profile, count($educationItems));
db()->prepare('UPDATE user_profiles SET profile_completion = :profile_completion WHERE user_id = :user_id')
    ->execute(['profile_completion' => $profileCompletion, 'user_id' => $userId]);
$avatarUrl = public_upload_url($currentUser['avatar_path'] ?? null);
$visibility = ($profile['visibility'] ?? 'privado') === 'publico' ? 'publico' : 'privado';

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
                <span class="eyebrow">Gestión de perfil</span>
                <h1>Mi perfil académico</h1>
                <p>Actualiza tu información pública, foto, enlaces, idiomas, educación y visibilidad del portafolio.</p>
            </div>

            <div class="dashboard-user-card">
                <?php if ($avatarUrl !== ''): ?>
                    <img class="profile-mini-avatar" src="<?= e($avatarUrl); ?>" alt="Foto de perfil de <?= e($currentUser['full_name']); ?>">
                <?php else: ?>
                    <div class="avatar-circle" aria-hidden="true"><?= e(strtoupper(substr($currentUser['full_name'] ?? 'E', 0, 1))); ?></div>
                <?php endif; ?>
                <div>
                    <strong><?= e($currentUser['full_name'] ?? 'Estudiante'); ?></strong>
                    <span><?= e($visibility === 'publico' ? 'Portafolio público' : 'Portafolio privado'); ?></span>
                </div>
            </div>
        </div>

        <div class="profile-layout">
            <aside class="profile-aside">
                <section class="dashboard-card text-center">
                    <div class="profile-avatar-wrap">
                        <img
                            id="avatarPreview"
                            class="profile-avatar<?= $avatarUrl === '' ? ' d-none' : ''; ?>"
                            src="<?= e($avatarUrl); ?>"
                            alt="Vista previa de foto de perfil"
                        >
                        <?php if ($avatarUrl === ''): ?>
                            <div id="avatarFallback" class="profile-avatar profile-avatar-placeholder" aria-hidden="true">
                                <?= e(strtoupper(substr($currentUser['full_name'] ?? 'E', 0, 1))); ?>
                            </div>
                        <?php else: ?>
                            <div id="avatarFallback" class="profile-avatar profile-avatar-placeholder d-none" aria-hidden="true">
                                <?= e(strtoupper(substr($currentUser['full_name'] ?? 'E', 0, 1))); ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <h2 class="profile-card-title"><?= e($currentUser['full_name'] ?? 'Estudiante'); ?></h2>
                    <p class="text-secondary mb-3"><?= e($profile['career'] ?? 'Carrera pendiente'); ?></p>

                    <div class="progress profile-progress" role="progressbar" aria-label="Completitud del perfil" aria-valuenow="<?= e((string) $profileCompletion); ?>" aria-valuemin="0" aria-valuemax="100">
                        <div class="progress-bar" style="width: <?= e((string) $profileCompletion); ?>%"></div>
                    </div>
                    <p class="small fw-bold text-secondary mt-2"><?= e((string) $profileCompletion); ?>% del perfil completo</p>

                    <form class="profile-upload-form" method="post" action="<?= e(url_to('profile/index.php')); ?>" enctype="multipart/form-data">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="action" value="upload_avatar">
                        <label class="form-label text-start w-100" for="avatar">Foto de perfil</label>
                        <input class="form-control" type="file" id="avatar" name="avatar" accept="image/jpeg,image/png,image/webp" data-avatar-preview="#avatarPreview" data-avatar-fallback="#avatarFallback">
                        <button class="btn btn-primary w-100 mt-3" type="submit">Subir foto</button>
                    </form>

                    <?php if ($avatarUrl !== ''): ?>
                        <form method="post" action="<?= e(url_to('profile/index.php')); ?>" class="mt-2">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="action" value="remove_avatar">
                            <button class="btn btn-outline-primary w-100" type="submit">Eliminar foto</button>
                        </form>
                    <?php endif; ?>
                </section>
            </aside>

            <div class="profile-main">
                <section class="dashboard-card" aria-labelledby="profileFormTitle">
                    <div class="card-heading">
                        <div>
                            <span class="eyebrow">Información principal</span>
                            <h2 id="profileFormTitle">Datos del perfil</h2>
                        </div>
                        <span class="status-pill <?= $visibility === 'publico' ? 'success' : 'warning'; ?>">
                            <?= e($visibility === 'publico' ? 'Visible públicamente' : 'Privado'); ?>
                        </span>
                    </div>

                    <form class="needs-validation profile-form" method="post" action="<?= e(url_to('profile/index.php')); ?>" novalidate>
                        <?= csrf_field(); ?>
                        <input type="hidden" name="action" value="update_profile">

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="fullName">Nombre completo</label>
                                <input class="form-control" type="text" id="fullName" name="full_name" value="<?= e($currentUser['full_name'] ?? ''); ?>" required>
                                <div class="invalid-feedback">Ingresa tu nombre completo.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="career">Carrera o área</label>
                                <input class="form-control" type="text" id="career" name="career" value="<?= e($profile['career'] ?? ''); ?>" placeholder="Ingeniería en Sistemas">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="universityName">Escuela / Universidad</label>
                                <input class="form-control" type="text" id="universityName" name="university_name" value="<?= e($profile['university_name'] ?? ''); ?>" placeholder="CECyTEM / Universidad">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="graduationYear">Año de graduación</label>
                                <input class="form-control" type="number" id="graduationYear" name="graduation_year" value="<?= e((string) ($profile['graduation_year'] ?? '')); ?>" min="1950" max="2155">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="visibility">Visibilidad</label>
                                <select class="form-select" id="visibility" name="visibility">
                                    <option value="privado" <?= $visibility === 'privado' ? 'selected' : ''; ?>>Privado</option>
                                    <option value="publico" <?= $visibility === 'publico' ? 'selected' : ''; ?>>Público</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="phone">Teléfono</label>
                                <input class="form-control" type="tel" id="phone" name="phone" value="<?= e($profile['phone'] ?? ''); ?>" placeholder="+52 555 000 0000">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="location">Ubicación</label>
                                <input class="form-control" type="text" id="location" name="location" value="<?= e($profile['location'] ?? ''); ?>" placeholder="Estado de México, México">
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="aboutMe">Acerca de mí</label>
                                <textarea class="form-control" id="aboutMe" name="about_me" rows="4" placeholder="Describe tus metas, fortalezas académicas y áreas de interés."><?= e($profile['about_me'] ?? ''); ?></textarea>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="languages">Idiomas</label>
                                <input class="form-control" type="text" id="languages" name="languages" value="<?= e($profile['languages'] ?? ''); ?>" placeholder="Español nativo, Inglés intermedio">
                                <div class="form-text">Separa cada idioma con coma.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="publicSlug">URL pública</label>
                                <div class="input-group">
                                    <span class="input-group-text">/p/</span>
                                    <input class="form-control" type="text" id="publicSlug" name="public_slug" value="<?= e($profile['public_slug'] ?? $currentUser['username']); ?>" pattern="[a-z0-9_-]{4,80}">
                                </div>
                            </div>
                        </div>

                        <hr class="my-4">

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="githubUrl">GitHub</label>
                                <input class="form-control" type="url" id="githubUrl" name="github_url" value="<?= e($profile['github_url'] ?? ''); ?>" placeholder="https://github.com/usuario">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="linkedinUrl">LinkedIn</label>
                                <input class="form-control" type="url" id="linkedinUrl" name="linkedin_url" value="<?= e($profile['linkedin_url'] ?? ''); ?>" placeholder="https://linkedin.com/in/usuario">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="portfolioUrl">Portafolio externo</label>
                                <input class="form-control" type="url" id="portfolioUrl" name="portfolio_url" value="<?= e($profile['portfolio_url'] ?? ''); ?>" placeholder="https://miportafolio.com">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="instagramUrl">Instagram</label>
                                <input class="form-control" type="url" id="instagramUrl" name="instagram_url" value="<?= e($profile['instagram_url'] ?? ''); ?>" placeholder="https://instagram.com/usuario">
                            </div>
                        </div>

                        <div class="d-flex flex-wrap gap-2 justify-content-end mt-4">
                            <a class="btn btn-outline-primary" href="<?= e(url_to('dashboard/index.php')); ?>">Volver al panel</a>
                            <button class="btn btn-primary" type="submit">Guardar perfil</button>
                        </div>
                    </form>
                </section>

                <section id="educacion" class="dashboard-card" aria-labelledby="educationFormTitle">
                    <div class="card-heading">
                        <div>
                            <span class="eyebrow">Educación</span>
                            <h2 id="educationFormTitle"><?= $editEducation ? 'Editar educación' : 'Agregar educación'; ?></h2>
                        </div>
                        <?php if ($editEducation): ?>
                            <a class="small fw-bold" href="<?= e(url_to('profile/index.php#educacion')); ?>">Cancelar edición</a>
                        <?php endif; ?>
                    </div>

                    <form class="needs-validation profile-form" method="post" action="<?= e(url_to('profile/index.php')); ?>" novalidate>
                        <?= csrf_field(); ?>
                        <input type="hidden" name="action" value="save_education">
                        <input type="hidden" name="education_id" value="<?= e((string) ($editEducation['id'] ?? 0)); ?>">

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="institutionName">Institución</label>
                                <input class="form-control" type="text" id="institutionName" name="institution_name" value="<?= e($editEducation['institution_name'] ?? ''); ?>" required>
                                <div class="invalid-feedback">Ingresa la institución.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="degree">Grado o programa</label>
                                <input class="form-control" type="text" id="degree" name="degree" value="<?= e($editEducation['degree'] ?? ''); ?>" placeholder="Bachillerato tecnológico, Licenciatura..." required>
                                <div class="invalid-feedback">Ingresa el grado o programa.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="fieldOfStudy">Área de estudio</label>
                                <input class="form-control" type="text" id="fieldOfStudy" name="field_of_study" value="<?= e($editEducation['field_of_study'] ?? ''); ?>" placeholder="Programación, informática, administración...">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="startDate">Inicio</label>
                                <input class="form-control" type="date" id="startDate" name="start_date" value="<?= e($editEducation['start_date'] ?? ''); ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="endDate">Fin</label>
                                <input class="form-control" type="date" id="endDate" name="end_date" value="<?= e($editEducation['end_date'] ?? ''); ?>">
                            </div>
                            <div class="col-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="isCurrent" name="is_current" <?= !empty($editEducation['is_current']) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="isCurrent">Actualmente estudio aquí</label>
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="educationDescription">Descripción</label>
                                <textarea class="form-control" id="educationDescription" name="description" rows="3" placeholder="Menciona logros, enfoque académico o actividades relevantes."><?= e($editEducation['description'] ?? ''); ?></textarea>
                            </div>
                        </div>

                        <div class="d-flex justify-content-end mt-4">
                            <button class="btn btn-primary" type="submit"><?= $editEducation ? 'Actualizar educación' : 'Agregar educación'; ?></button>
                        </div>
                    </form>
                </section>

                <section class="dashboard-card" aria-labelledby="educationListTitle">
                    <div class="card-heading">
                        <div>
                            <span class="eyebrow">Historial académico</span>
                            <h2 id="educationListTitle">Educación registrada</h2>
                        </div>
                        <span class="status-pill success"><?= e((string) count($educationItems)); ?> registros</span>
                    </div>

                    <?php if (empty($educationItems)): ?>
                        <div class="empty-state">
                            <i class="bi bi-mortarboard" aria-hidden="true"></i>
                            <h3>Aún no agregas educación</h3>
                            <p>Registra tu escuela, carrera y fechas para que tu portafolio tenga contexto académico.</p>
                        </div>
                    <?php else: ?>
                        <div class="education-list">
                            <?php foreach ($educationItems as $education): ?>
                                <article class="education-item">
                                    <div class="education-icon">
                                        <i class="bi bi-mortarboard" aria-hidden="true"></i>
                                    </div>
                                    <div>
                                        <h3><?= e($education['degree']); ?></h3>
                                        <p class="mb-1 fw-semibold"><?= e($education['institution_name']); ?></p>
                                        <p class="text-secondary mb-2">
                                            <?= e($education['field_of_study'] ?? 'Área no especificada'); ?>
                                            ·
                                            <?= e($education['start_date'] ? date('d/m/Y', strtotime($education['start_date'])) : 'Inicio pendiente'); ?>
                                            -
                                            <?= !empty($education['is_current']) ? 'Actualidad' : e($education['end_date'] ? date('d/m/Y', strtotime($education['end_date'])) : 'Fin pendiente'); ?>
                                        </p>
                                        <?php if (!empty($education['description'])): ?>
                                            <p class="mb-0"><?= e($education['description']); ?></p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="education-actions">
                                        <a class="btn btn-outline-primary btn-sm" href="<?= e(url_to('profile/index.php?edit_education=' . $education['id'] . '#educacion')); ?>">Editar</a>
                                        <form method="post" action="<?= e(url_to('profile/index.php')); ?>" onsubmit="return confirm('¿Eliminar este registro educativo?');">
                                            <?= csrf_field(); ?>
                                            <input type="hidden" name="action" value="delete_education">
                                            <input type="hidden" name="education_id" value="<?= e((string) $education['id']); ?>">
                                            <button class="btn btn-outline-danger btn-sm" type="submit">Eliminar</button>
                                        </form>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <section id="experiencias" class="dashboard-card" aria-labelledby="experienceFormTitle">
                    <div class="card-heading">
                        <div>
                            <span class="eyebrow">Experiencia</span>
                            <h2 id="experienceFormTitle"><?= $editExperience ? 'Editar experiencia' : 'Agregar experiencia'; ?></h2>
                        </div>
                        <?php if ($editExperience): ?>
                            <a class="small fw-bold" href="<?= e(url_to('profile/index.php#experiencias')); ?>">Cancelar edición</a>
                        <?php endif; ?>
                    </div>

                    <form class="needs-validation" method="post" action="<?= e(url_to('profile/index.php#experiencias')); ?>" novalidate>
                        <?= csrf_field(); ?>
                        <input type="hidden" name="action" value="save_experience">
                        <input type="hidden" name="experience_id" value="<?= e((string) ($editExperience['id'] ?? 0)); ?>">

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="experienceTitle">Puesto o actividad</label>
                                <input class="form-control" type="text" id="experienceTitle" name="title" value="<?= e($editExperience['title'] ?? ''); ?>" required>
                                <div class="invalid-feedback">Ingresa el puesto, práctica o actividad.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="experienceOrganization">Organización</label>
                                <input class="form-control" type="text" id="experienceOrganization" name="organization" value="<?= e($editExperience['organization'] ?? ''); ?>" required>
                                <div class="invalid-feedback">Ingresa la organización.</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="experienceType">Tipo</label>
                                <select class="form-select" id="experienceType" name="type">
                                    <?php foreach (['practica' => 'Práctica', 'empleo' => 'Empleo', 'voluntariado' => 'Voluntariado', 'actividad' => 'Actividad', 'otro' => 'Otro'] as $value => $label): ?>
                                        <option value="<?= e($value); ?>" <?= ($editExperience['type'] ?? 'otro') === $value ? 'selected' : ''; ?>><?= e($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="experienceStartDate">Inicio</label>
                                <input class="form-control" type="date" id="experienceStartDate" name="experience_start_date" value="<?= e($editExperience['start_date'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="experienceEndDate">Fin</label>
                                <input class="form-control" type="date" id="experienceEndDate" name="experience_end_date" value="<?= e($editExperience['end_date'] ?? ''); ?>">
                            </div>
                            <div class="col-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="experienceIsCurrent" name="experience_is_current" <?= !empty($editExperience['is_current']) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="experienceIsCurrent">Actualmente participo aquí</label>
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="experienceDescription">Descripción</label>
                                <textarea class="form-control" id="experienceDescription" name="experience_description" rows="3" placeholder="Describe responsabilidades, logros medibles o evidencias."><?= e($editExperience['description'] ?? ''); ?></textarea>
                            </div>
                        </div>

                        <div class="d-flex justify-content-end mt-4">
                            <button class="btn btn-primary" type="submit"><?= $editExperience ? 'Actualizar experiencia' : 'Agregar experiencia'; ?></button>
                        </div>
                    </form>
                </section>

                <section class="dashboard-card" aria-labelledby="experienceListTitle">
                    <div class="card-heading">
                        <div>
                            <span class="eyebrow">Trayectoria</span>
                            <h2 id="experienceListTitle">Experiencias registradas</h2>
                        </div>
                        <span class="status-pill info"><?= e((string) count($experienceItems)); ?> registros</span>
                    </div>

                    <?php if (empty($experienceItems)): ?>
                        <div class="empty-state">
                            <i class="bi bi-briefcase" aria-hidden="true"></i>
                            <h3>Aún no agregas experiencia</h3>
                            <p>Incluye prácticas, voluntariados, actividades académicas o empleos para mostrar trayectoria.</p>
                        </div>
                    <?php else: ?>
                        <div class="education-list">
                            <?php foreach ($experienceItems as $experience): ?>
                                <article class="education-item">
                                    <div class="education-icon">
                                        <i class="bi bi-briefcase" aria-hidden="true"></i>
                                    </div>
                                    <div>
                                        <h3><?= e($experience['title']); ?></h3>
                                        <p class="mb-1 fw-semibold"><?= e($experience['organization']); ?></p>
                                        <p class="text-secondary mb-2">
                                            <?= e(ucfirst((string) $experience['type'])); ?>
                                            ·
                                            <?= e($experience['start_date'] ? date('d/m/Y', strtotime($experience['start_date'])) : 'Inicio pendiente'); ?>
                                            -
                                            <?= !empty($experience['is_current']) ? 'Actualidad' : e($experience['end_date'] ? date('d/m/Y', strtotime($experience['end_date'])) : 'Fin pendiente'); ?>
                                        </p>
                                        <?php if (!empty($experience['description'])): ?>
                                            <p class="mb-0"><?= e($experience['description']); ?></p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="education-actions">
                                        <a class="btn btn-outline-primary btn-sm" href="<?= e(url_to('profile/index.php?edit_experience=' . $experience['id'] . '#experiencias')); ?>">Editar</a>
                                        <form method="post" action="<?= e(url_to('profile/index.php#experiencias')); ?>" onsubmit="return confirm('¿Eliminar esta experiencia?');">
                                            <?= csrf_field(); ?>
                                            <input type="hidden" name="action" value="delete_experience">
                                            <input type="hidden" name="experience_id" value="<?= e((string) $experience['id']); ?>">
                                            <button class="btn btn-outline-danger btn-sm" type="submit">Eliminar</button>
                                        </form>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
        </div>

        <?php require __DIR__ . '/../../../HTML/components/mobile-nav.php'; ?>
    </section>
    </div>
</main>

<?php require_once __DIR__ . '/../../../HTML/components/footer.php'; ?>
