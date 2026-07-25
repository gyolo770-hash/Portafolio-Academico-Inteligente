<?php
declare(strict_types=1);

$pageTitle = 'Constructor de CV';
$pageDescription = 'Crea CVs con plantillas, colores personalizados, vista previa y exportación PDF.';
$bodyClass = 'dashboard-page resume-page';
$activeItem = 'cv';

require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../helpers/resume.php';
require_student();

$currentUser = auth_user();
$userId = (int) $currentUser['id'];
$templates = resume_templates();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['_csrf_token'] ?? null)) {
        flash('danger', 'La sesión expiró. Intenta guardar el CV nuevamente.');
        header('Location: ' . url_to('resume/index.php'));
        exit;
    }

    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'save_resume') {
            $resumeId = (int) ($_POST['resume_id'] ?? 0);
            $title = trim((string) ($_POST['title'] ?? ''));
            $templateName = resume_valid_template((string) ($_POST['template_name'] ?? 'profesional'));
            $accentColor = resume_valid_color((string) ($_POST['accent_color'] ?? '#4F46E5'));

            if ($title === '') {
                $title = 'CV de ' . ($currentUser['full_name'] ?? 'estudiante');
            }

            if ($resumeId > 0) {
                db()->prepare(
                    'UPDATE resumes
                     SET title = :title,
                         template_name = :template_name,
                         accent_color = :accent_color,
                         status = :status
                     WHERE id = :id AND user_id = :user_id'
                )->execute([
                    'title' => $title,
                    'template_name' => $templateName,
                    'accent_color' => $accentColor,
                    'status' => 'borrador',
                    'id' => $resumeId,
                    'user_id' => $userId,
                ]);
                flash('success', 'CV actualizado correctamente.');
            } else {
                db()->prepare(
                    'INSERT INTO resumes (user_id, title, template_name, accent_color, status)
                     VALUES (:user_id, :title, :template_name, :accent_color, :status)'
                )->execute([
                    'user_id' => $userId,
                    'title' => $title,
                    'template_name' => $templateName,
                    'accent_color' => $accentColor,
                    'status' => 'borrador',
                ]);
                $resumeId = (int) db()->lastInsertId();
                flash('success', 'CV creado correctamente.');
            }

            header('Location: ' . url_to('resume/index.php?resume_id=' . $resumeId));
            exit;
        }

        if ($action === 'delete_resume') {
            $resumeId = (int) ($_POST['resume_id'] ?? 0);
            db()->prepare('DELETE FROM resumes WHERE id = :id AND user_id = :user_id')
                ->execute(['id' => $resumeId, 'user_id' => $userId]);
            flash('success', 'CV eliminado correctamente.');
        }
    } catch (Throwable $exception) {
        error_log('Error en módulo de CV: ' . $exception->getMessage());
        flash('danger', 'No se pudo guardar el CV.');
    }

    header('Location: ' . url_to('resume/index.php'));
    exit;
}

$resumesStatement = db()->prepare('SELECT * FROM resumes WHERE user_id = :user_id ORDER BY updated_at DESC, created_at DESC');
$resumesStatement->execute(['user_id' => $userId]);
$resumes = $resumesStatement->fetchAll();

$selectedResume = null;
if (isset($_GET['resume_id'])) {
    $selectedStatement = db()->prepare('SELECT * FROM resumes WHERE id = :id AND user_id = :user_id LIMIT 1');
    $selectedStatement->execute(['id' => (int) $_GET['resume_id'], 'user_id' => $userId]);
    $selectedResume = $selectedStatement->fetch() ?: null;
}

if (!$selectedResume && !empty($resumes)) {
    $selectedResume = $resumes[0];
}

$currentTitle = $selectedResume['title'] ?? 'CV de ' . ($currentUser['full_name'] ?? 'estudiante');
$currentTemplate = resume_valid_template($selectedResume['template_name'] ?? ($_GET['template'] ?? 'profesional'));
$currentColor = resume_valid_color($selectedResume['accent_color'] ?? ($_GET['color'] ?? '#4F46E5'));
$selectedResumeId = (int) ($selectedResume['id'] ?? 0);
$previewUrl = url_to('resume/preview.php?template=' . urlencode($currentTemplate) . '&color=' . urlencode($currentColor));
$exportUrl = url_to('resume/export.php?template=' . urlencode($currentTemplate) . '&color=' . urlencode($currentColor) . ($selectedResumeId > 0 ? '&resume_id=' . $selectedResumeId : ''));

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
                <span class="eyebrow">Constructor de CV</span>
                <h1>Plantillas de currículum</h1>
                <p>Elige una plantilla, personaliza el color, revisa la vista previa y exporta tu CV como PDF desde el navegador.</p>
            </div>
            <a class="btn btn-primary" href="<?= e($exportUrl); ?>" target="_blank" rel="noopener">
                <i class="bi bi-filetype-pdf" aria-hidden="true"></i> Exportar PDF
            </a>
        </div>

        <div class="resume-layout">
            <section class="dashboard-card" aria-labelledby="resumeSettingsTitle">
                <div class="card-heading">
                    <div>
                        <span class="eyebrow">Configuración</span>
                        <h2 id="resumeSettingsTitle">Personaliza tu CV</h2>
                    </div>
                </div>

                <form class="needs-validation resume-form" method="post" action="<?= e(url_to('resume/index.php')); ?>" novalidate>
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action" value="save_resume">
                    <input type="hidden" name="resume_id" value="<?= e((string) $selectedResumeId); ?>">

                    <div class="mb-3">
                        <label class="form-label" for="title">Nombre del CV</label>
                        <input class="form-control" type="text" id="title" name="title" value="<?= e($currentTitle); ?>" required>
                        <div class="invalid-feedback">Ingresa un nombre para tu CV.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="accentColor">Color principal</label>
                        <div class="resume-color-row">
                            <input class="form-control form-control-color" type="color" id="accentColor" name="accent_color" value="<?= e($currentColor); ?>">
                            <input class="form-control" type="text" value="<?= e($currentColor); ?>" aria-label="Color seleccionado" readonly>
                        </div>
                    </div>

                    <fieldset class="template-grid">
                        <legend class="form-label">Plantilla</legend>
                        <?php foreach ($templates as $key => $template): ?>
                            <label class="template-option">
                                <input type="radio" name="template_name" value="<?= e($key); ?>" <?= $currentTemplate === $key ? 'checked' : ''; ?>>
                                <span class="template-icon"><i class="bi <?= e($template['icon']); ?>" aria-hidden="true"></i></span>
                                <strong><?= e($template['name']); ?></strong>
                                <small><?= e($template['description']); ?></small>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>

                    <div class="d-grid gap-2 mt-4">
                        <button class="btn btn-primary" type="submit">Guardar configuración</button>
                        <a class="btn btn-outline-primary" href="<?= e($previewUrl); ?>" target="resumePreview">Actualizar vista previa</a>
                    </div>
                </form>

                <?php if (!empty($resumes)): ?>
                    <hr class="my-4">
                    <h3 class="resume-sidebar-title">CVs guardados</h3>
                    <div class="saved-resume-list">
                        <?php foreach ($resumes as $resume): ?>
                            <article class="saved-resume-item <?= (int) $resume['id'] === $selectedResumeId ? 'active' : ''; ?>">
                                <div>
                                    <strong><?= e($resume['title']); ?></strong>
                                    <span><?= e($templates[$resume['template_name']]['name'] ?? 'Profesional'); ?> · <?= e($resume['status']); ?></span>
                                </div>
                                <div class="project-links">
                                    <a class="btn btn-outline-primary btn-sm" href="<?= e(url_to('resume/index.php?resume_id=' . $resume['id'])); ?>">Editar</a>
                                    <form method="post" action="<?= e(url_to('resume/index.php')); ?>" onsubmit="return confirm('¿Eliminar este CV?');">
                                        <?= csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete_resume">
                                        <input type="hidden" name="resume_id" value="<?= e((string) $resume['id']); ?>">
                                        <button class="btn btn-outline-danger btn-sm" type="submit">Eliminar</button>
                                    </form>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="dashboard-card resume-preview-card" aria-labelledby="resumePreviewTitle">
                <div class="card-heading">
                    <div>
                        <span class="eyebrow">Vista previa</span>
                        <h2 id="resumePreviewTitle">Previsualización del CV</h2>
                    </div>
                    <a class="small fw-bold" href="<?= e($exportUrl); ?>" target="_blank" rel="noopener">Abrir exportación</a>
                </div>
                <iframe class="resume-preview-frame" name="resumePreview" title="Vista previa del currículum" src="<?= e($previewUrl); ?>"></iframe>
            </section>
        </div>

        <?php require __DIR__ . '/../../../HTML/components/mobile-nav.php'; ?>
    </section>
    </div>
</main>

<?php require_once __DIR__ . '/../../../HTML/components/footer.php'; ?>
