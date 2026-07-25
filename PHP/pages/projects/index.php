<?php
declare(strict_types=1);

$pageTitle = 'Mis proyectos';
$pageDescription = 'Gestiona proyectos académicos, screenshots, tecnologías, enlaces y estado.';
$bodyClass = 'dashboard-page projects-page';
$activeItem = 'proyectos';
$pageScript = 'auth.js';

require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../helpers/upload.php';
require_student();

$currentUser = auth_user();
$userId = (int) $currentUser['id'];

function project_slug(string $title): string
{
    $slug = strtolower(trim($title));
    $slug = preg_replace('/[^a-z0-9]+/i', '-', $slug);
    $slug = trim((string) $slug, '-');

    return $slug !== '' ? $slug : 'proyecto-' . time();
}

function unique_project_slug(string $title, int $userId, int $ignoreId = 0): string
{
    $baseSlug = project_slug($title);
    $slug = $baseSlug;
    $counter = 2;

    do {
        $statement = db()->prepare('SELECT id FROM projects WHERE user_id = :user_id AND slug = :slug AND id <> :id LIMIT 1');
        $statement->execute([
            'user_id' => $userId,
            'slug' => $slug,
            'id' => $ignoreId,
        ]);

        if (!$statement->fetch()) {
            return $slug;
        }

        $slug = $baseSlug . '-' . $counter;
        $counter++;
    } while (true);
}

function technology_names_for_project(int $projectId): array
{
    $statement = db()->prepare(
        'SELECT s.name
         FROM project_skills ps
         INNER JOIN skills s ON s.id = ps.skill_id
         WHERE ps.project_id = :project_id
         ORDER BY s.name ASC'
    );
    $statement->execute(['project_id' => $projectId]);

    return array_column($statement->fetchAll(), 'name');
}

function sync_project_technologies(int $projectId, int $userId, string $technologies): void
{
    $names = array_filter(array_map('trim', explode(',', $technologies)));
    $names = array_values(array_unique($names));

    db()->prepare('DELETE FROM project_skills WHERE project_id = :project_id')
        ->execute(['project_id' => $projectId]);

    if (empty($names)) {
        return;
    }

    $categoryStatement = db()->prepare('SELECT id FROM skill_categories WHERE name = :name LIMIT 1');
    $categoryStatement->execute(['name' => 'Herramientas digitales']);
    $category = $categoryStatement->fetch();
    $categoryId = $category ? (int) $category['id'] : null;

    if ($categoryId === null) {
        db()->prepare('INSERT INTO skill_categories (name, type) VALUES (:name, :type)')
            ->execute(['name' => 'Herramientas digitales', 'type' => 'herramienta']);
        $categoryId = (int) db()->lastInsertId();
    }

    foreach ($names as $name) {
        if (strlen($name) > 120) {
            continue;
        }

        $skillStatement = db()->prepare('SELECT id FROM skills WHERE name = :name LIMIT 1');
        $skillStatement->execute(['name' => $name]);
        $skill = $skillStatement->fetch();

        if ($skill) {
            $skillId = (int) $skill['id'];
        } else {
            db()->prepare('INSERT INTO skills (category_id, name) VALUES (:category_id, :name)')
                ->execute(['category_id' => $categoryId, 'name' => $name]);
            $skillId = (int) db()->lastInsertId();
        }

        db()->prepare('INSERT IGNORE INTO project_skills (project_id, skill_id) VALUES (:project_id, :skill_id)')
            ->execute(['project_id' => $projectId, 'skill_id' => $skillId]);

        db()->prepare('INSERT IGNORE INTO user_skills (user_id, skill_id, proficiency) VALUES (:user_id, :skill_id, :proficiency)')
            ->execute(['user_id' => $userId, 'skill_id' => $skillId, 'proficiency' => 'basico']);
    }
}

function store_project_screenshots(int $projectId, int $userId, array $files): void
{
    $paths = upload_project_screenshots($files, $userId);

    foreach ($paths as $index => $path) {
        db()->prepare(
            'INSERT INTO project_screenshots (project_id, image_path, alt_text, sort_order)
             VALUES (:project_id, :image_path, :alt_text, :sort_order)'
        )->execute([
            'project_id' => $projectId,
            'image_path' => $path,
            'alt_text' => 'Screenshot del proyecto',
            'sort_order' => $index,
        ]);
    }

    if (!empty($paths)) {
        $coverStatement = db()->prepare('SELECT image_path FROM projects WHERE id = :id AND user_id = :user_id LIMIT 1');
        $coverStatement->execute(['id' => $projectId, 'user_id' => $userId]);
        $cover = $coverStatement->fetch();

        if ($cover && empty($cover['image_path'])) {
            db()->prepare('UPDATE projects SET image_path = :image_path WHERE id = :id AND user_id = :user_id')
                ->execute(['image_path' => $paths[0], 'id' => $projectId, 'user_id' => $userId]);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['_csrf_token'] ?? null)) {
        flash('danger', 'La sesión expiró. Intenta guardar el proyecto nuevamente.');
        header('Location: ' . url_to('projects/index.php'));
        exit;
    }

    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'save_project') {
            $projectId = (int) ($_POST['project_id'] ?? 0);
            $title = trim((string) ($_POST['title'] ?? ''));
            $category = trim((string) ($_POST['category'] ?? ''));
            $summary = trim((string) ($_POST['summary'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            $repositoryUrl = trim((string) ($_POST['repository_url'] ?? ''));
            $demoUrl = trim((string) ($_POST['demo_url'] ?? ''));
            $documentationPath = upload_project_document($_FILES['documentation'] ?? [], $userId);
            $status = (string) ($_POST['status'] ?? 'idea');
            $visibility = ($_POST['visibility'] ?? 'privado') === 'publico' ? 'publico' : 'privado';
            $startedAt = trim((string) ($_POST['started_at'] ?? ''));
            $finishedAt = trim((string) ($_POST['finished_at'] ?? ''));
            $technologies = trim((string) ($_POST['technologies'] ?? ''));
            $allowedStatuses = ['idea', 'en_progreso', 'finalizado', 'pausado'];

            $errors = [];

            if ($title === '') {
                $errors[] = 'El título del proyecto es obligatorio.';
            }

            if (!in_array($status, $allowedStatuses, true)) {
                $errors[] = 'El estado del proyecto no es válido.';
            }

            if ($repositoryUrl !== '' && !filter_var($repositoryUrl, FILTER_VALIDATE_URL)) {
                $errors[] = 'El enlace de GitHub o repositorio no es válido.';
            }

            if ($demoUrl !== '' && !filter_var($demoUrl, FILTER_VALIDATE_URL)) {
                $errors[] = 'El enlace de demo no es válido.';
            }

            if (!empty($errors)) {
                foreach ($errors as $error) {
                    flash('danger', $error);
                }

                header('Location: ' . url_to('projects/index.php' . ($projectId > 0 ? '?edit=' . $projectId : '')));
                exit;
            }

            db()->beginTransaction();

            if ($projectId > 0) {
                $currentStatement = db()->prepare('SELECT documentation_path FROM projects WHERE id = :id AND user_id = :user_id LIMIT 1');
                $currentStatement->execute(['id' => $projectId, 'user_id' => $userId]);
                $currentProject = $currentStatement->fetch();

                if (!$currentProject) {
                    flash('danger', 'El proyecto que intentas editar no existe.');
                    header('Location: ' . url_to('projects/index.php'));
                    exit;
                }

                $slug = unique_project_slug($title, $userId, $projectId);
                db()->prepare(
                    'UPDATE projects
                     SET title = :title,
                         slug = :slug,
                         category = :category,
                         summary = :summary,
                         description = :description,
                         repository_url = :repository_url,
                         demo_url = :demo_url,
                         documentation_path = :documentation_path,
                         status = :status,
                         visibility = :visibility,
                         started_at = :started_at,
                         finished_at = :finished_at
                     WHERE id = :id AND user_id = :user_id'
                )->execute([
                    'title' => $title,
                    'slug' => $slug,
                    'category' => $category !== '' ? $category : null,
                    'summary' => $summary !== '' ? $summary : null,
                    'description' => $description !== '' ? $description : null,
                    'repository_url' => $repositoryUrl !== '' ? $repositoryUrl : null,
                    'demo_url' => $demoUrl !== '' ? $demoUrl : null,
                    'documentation_path' => $documentationPath ?: $currentProject['documentation_path'],
                    'status' => $status,
                    'visibility' => $visibility,
                    'started_at' => $startedAt !== '' ? $startedAt : null,
                    'finished_at' => $finishedAt !== '' ? $finishedAt : null,
                    'id' => $projectId,
                    'user_id' => $userId,
                ]);
                sync_project_technologies($projectId, $userId, $technologies);
                store_project_screenshots($projectId, $userId, $_FILES['screenshots'] ?? []);
                flash('success', 'Proyecto actualizado correctamente.');
            } else {
                $slug = unique_project_slug($title, $userId);
                db()->prepare(
                    'INSERT INTO projects (user_id, title, slug, category, summary, description, repository_url, demo_url, documentation_path, status, visibility, started_at, finished_at)
                     VALUES (:user_id, :title, :slug, :category, :summary, :description, :repository_url, :demo_url, :documentation_path, :status, :visibility, :started_at, :finished_at)'
                )->execute([
                    'user_id' => $userId,
                    'title' => $title,
                    'slug' => $slug,
                    'category' => $category !== '' ? $category : null,
                    'summary' => $summary !== '' ? $summary : null,
                    'description' => $description !== '' ? $description : null,
                    'repository_url' => $repositoryUrl !== '' ? $repositoryUrl : null,
                    'demo_url' => $demoUrl !== '' ? $demoUrl : null,
                    'documentation_path' => $documentationPath,
                    'status' => $status,
                    'visibility' => $visibility,
                    'started_at' => $startedAt !== '' ? $startedAt : null,
                    'finished_at' => $finishedAt !== '' ? $finishedAt : null,
                ]);
                $projectId = (int) db()->lastInsertId();
                sync_project_technologies($projectId, $userId, $technologies);
                store_project_screenshots($projectId, $userId, $_FILES['screenshots'] ?? []);
                flash('success', 'Proyecto creado correctamente.');
            }

            db()->commit();
        }

        if ($action === 'delete_project') {
            $projectId = (int) ($_POST['project_id'] ?? 0);
            $pathsStatement = db()->prepare(
                'SELECT image_path, documentation_path FROM projects WHERE id = :id AND user_id = :user_id LIMIT 1'
            );
            $pathsStatement->execute(['id' => $projectId, 'user_id' => $userId]);
            $projectPaths = $pathsStatement->fetch() ?: [];

            $screenshotStatement = db()->prepare(
                'SELECT ps.image_path
                 FROM project_screenshots ps
                 INNER JOIN projects p ON p.id = ps.project_id
                 WHERE p.id = :id AND p.user_id = :user_id'
            );
            $screenshotStatement->execute(['id' => $projectId, 'user_id' => $userId]);
            $uploadPaths = array_filter([
                $projectPaths['image_path'] ?? null,
                $projectPaths['documentation_path'] ?? null,
            ]);

            foreach ($screenshotStatement->fetchAll() as $screenshot) {
                if (!empty($screenshot['image_path'])) {
                    $uploadPaths[] = $screenshot['image_path'];
                }
            }

            db()->prepare('DELETE FROM projects WHERE id = :id AND user_id = :user_id')
                ->execute(['id' => $projectId, 'user_id' => $userId]);
            delete_upload_files($uploadPaths);
            flash('success', 'Proyecto eliminado correctamente.');
        }

        if ($action === 'delete_screenshot') {
            $screenshotId = (int) ($_POST['screenshot_id'] ?? 0);
            $screenshotStatement = db()->prepare(
                'SELECT ps.image_path
                 FROM project_screenshots ps
                 INNER JOIN projects p ON p.id = ps.project_id
                 WHERE ps.id = :id AND p.user_id = :user_id
                 LIMIT 1'
            );
            $screenshotStatement->execute(['id' => $screenshotId, 'user_id' => $userId]);
            $screenshotPath = $screenshotStatement->fetchColumn();

            db()->prepare(
                'DELETE ps
                 FROM project_screenshots ps
                 INNER JOIN projects p ON p.id = ps.project_id
                 WHERE ps.id = :id AND p.user_id = :user_id'
            )->execute(['id' => $screenshotId, 'user_id' => $userId]);

            if ($screenshotPath) {
                delete_upload_file((string) $screenshotPath);
            }

            flash('success', 'Screenshot eliminado.');
        }
    } catch (Throwable $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }

        error_log('Error en módulo de proyectos: ' . $exception->getMessage());
        flash('danger', $exception->getMessage() ?: 'No se pudo guardar el proyecto.');
    }

    header('Location: ' . url_to('projects/index.php'));
    exit;
}

$editProject = null;
$editTechnologies = '';
$editScreenshots = [];

if (isset($_GET['edit'])) {
    $editStatement = db()->prepare('SELECT * FROM projects WHERE id = :id AND user_id = :user_id LIMIT 1');
    $editStatement->execute([
        'id' => (int) $_GET['edit'],
        'user_id' => $userId,
    ]);
    $editProject = $editStatement->fetch() ?: null;

    if ($editProject) {
        $editTechnologies = implode(', ', technology_names_for_project((int) $editProject['id']));
        $screenshotsStatement = db()->prepare('SELECT * FROM project_screenshots WHERE project_id = :project_id ORDER BY sort_order ASC, id ASC');
        $screenshotsStatement->execute(['project_id' => $editProject['id']]);
        $editScreenshots = $screenshotsStatement->fetchAll();
    }
}

$projectsStatement = db()->prepare(
    'SELECT p.*,
            (
                SELECT COUNT(*)
                FROM project_screenshots ps
                WHERE ps.project_id = p.id
            ) AS screenshots_count,
            (
                SELECT GROUP_CONCAT(s.name ORDER BY s.name SEPARATOR ", ")
                FROM project_skills psk
                INNER JOIN skills s ON s.id = psk.skill_id
                WHERE psk.project_id = p.id
            ) AS technologies
     FROM projects p
     WHERE p.user_id = :user_id
     ORDER BY p.updated_at DESC, p.created_at DESC'
);
$projectsStatement->execute(['user_id' => $userId]);
$projects = $projectsStatement->fetchAll();

$projectStats = [
    'total' => count($projects),
    'en_progreso' => 0,
    'finalizado' => 0,
    'publico' => 0,
];

foreach ($projects as $project) {
    if ($project['status'] === 'en_progreso') {
        $projectStats['en_progreso']++;
    }

    if ($project['status'] === 'finalizado') {
        $projectStats['finalizado']++;
    }

    if ($project['visibility'] === 'publico') {
        $projectStats['publico']++;
    }
}

$statusLabels = [
    'idea' => 'Idea',
    'en_progreso' => 'En progreso',
    'finalizado' => 'Finalizado',
    'pausado' => 'Pausado',
];

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
                <span class="eyebrow">Gestión de proyectos</span>
                <h1>Mis proyectos académicos</h1>
                <p>Documenta evidencias, screenshots, tecnologías y enlaces para fortalecer tu portafolio profesional.</p>
            </div>
            <a class="btn btn-primary" href="#formulario-proyecto">Nuevo proyecto</a>
        </div>

        <section class="stats-grid" aria-label="Resumen de proyectos">
            <article class="stat-card">
                <div class="stat-icon primary"><i class="bi bi-kanban" aria-hidden="true"></i></div>
                <div><span>Total</span><strong><?= e((string) $projectStats['total']); ?></strong></div>
            </article>
            <article class="stat-card">
                <div class="stat-icon info"><i class="bi bi-hourglass-split" aria-hidden="true"></i></div>
                <div><span>En progreso</span><strong><?= e((string) $projectStats['en_progreso']); ?></strong></div>
            </article>
            <article class="stat-card">
                <div class="stat-icon success"><i class="bi bi-check2-circle" aria-hidden="true"></i></div>
                <div><span>Finalizados</span><strong><?= e((string) $projectStats['finalizado']); ?></strong></div>
            </article>
            <article class="stat-card">
                <div class="stat-icon warning"><i class="bi bi-globe2" aria-hidden="true"></i></div>
                <div><span>Públicos</span><strong><?= e((string) $projectStats['publico']); ?></strong></div>
            </article>
        </section>

        <div class="projects-layout">
            <section id="formulario-proyecto" class="dashboard-card" aria-labelledby="projectFormTitle">
                <div class="card-heading">
                    <div>
                        <span class="eyebrow">Formulario</span>
                        <h2 id="projectFormTitle"><?= $editProject ? 'Editar proyecto' : 'Crear proyecto'; ?></h2>
                    </div>
                    <?php if ($editProject): ?>
                        <a class="small fw-bold" href="<?= e(url_to('projects/index.php#formulario-proyecto')); ?>">Cancelar edición</a>
                    <?php endif; ?>
                </div>

                <form class="needs-validation project-form" method="post" action="<?= e(url_to('projects/index.php')); ?>" enctype="multipart/form-data" novalidate>
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action" value="save_project">
                    <input type="hidden" name="project_id" value="<?= e((string) ($editProject['id'] ?? 0)); ?>">

                    <div class="row g-3">
                        <div class="col-md-7">
                            <label class="form-label" for="title">Título del proyecto</label>
                            <input class="form-control" type="text" id="title" name="title" value="<?= e($editProject['title'] ?? ''); ?>" required>
                            <div class="invalid-feedback">Ingresa el título del proyecto.</div>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label" for="category">Categoría</label>
                            <input class="form-control" type="text" id="category" name="category" value="<?= e($editProject['category'] ?? ''); ?>" placeholder="Web, IA, diseño, investigación">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="status">Estado</label>
                            <select class="form-select" id="status" name="status">
                                <?php foreach ($statusLabels as $value => $label): ?>
                                    <option value="<?= e($value); ?>" <?= ($editProject['status'] ?? 'idea') === $value ? 'selected' : ''; ?>><?= e($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="visibility">Visibilidad</label>
                            <select class="form-select" id="visibility" name="visibility">
                                <option value="privado" <?= ($editProject['visibility'] ?? 'privado') === 'privado' ? 'selected' : ''; ?>>Privado</option>
                                <option value="publico" <?= ($editProject['visibility'] ?? '') === 'publico' ? 'selected' : ''; ?>>Público</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="summary">Resumen breve</label>
                            <input class="form-control" type="text" id="summary" name="summary" value="<?= e($editProject['summary'] ?? ''); ?>" maxlength="255" placeholder="Describe el resultado principal del proyecto.">
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="description">Descripción</label>
                            <textarea class="form-control" id="description" name="description" rows="4" placeholder="Explica objetivo, proceso, retos y resultados."><?= e($editProject['description'] ?? ''); ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="repositoryUrl">GitHub / Repositorio</label>
                            <input class="form-control" type="url" id="repositoryUrl" name="repository_url" value="<?= e($editProject['repository_url'] ?? ''); ?>" placeholder="https://github.com/usuario/proyecto">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="demoUrl">Demo en vivo</label>
                            <input class="form-control" type="url" id="demoUrl" name="demo_url" value="<?= e($editProject['demo_url'] ?? ''); ?>" placeholder="https://demo.com">
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="documentation">Documentación del proyecto</label>
                            <input class="form-control" type="file" id="documentation" name="documentation" accept="application/pdf,text/plain,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.ms-powerpoint,application/vnd.openxmlformats-officedocument.presentationml.presentation">
                            <div class="form-text">PDF, TXT, DOC, DOCX, PPT o PPTX. Máximo 10 MB.</div>
                            <?php if (!empty($editProject['documentation_path'])): ?>
                                <a class="small fw-bold" href="<?= e(public_upload_url($editProject['documentation_path'])); ?>" target="_blank" rel="noopener">Ver documentación actual</a>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="startedAt">Fecha de inicio</label>
                            <input class="form-control" type="date" id="startedAt" name="started_at" value="<?= e($editProject['started_at'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="finishedAt">Fecha de finalización</label>
                            <input class="form-control" type="date" id="finishedAt" name="finished_at" value="<?= e($editProject['finished_at'] ?? ''); ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="technologies">Tecnologías</label>
                            <input class="form-control" type="text" id="technologies" name="technologies" value="<?= e($editTechnologies); ?>" placeholder="PHP, MySQL, Bootstrap, JavaScript">
                            <div class="form-text">Separa cada tecnología con coma. También se guardarán como habilidades básicas.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="screenshots">Screenshots</label>
                            <input class="form-control" type="file" id="screenshots" name="screenshots[]" accept="image/jpeg,image/png,image/webp" multiple>
                            <div class="form-text">Puedes subir varios screenshots JPG, PNG o WebP. Máximo 4 MB por imagen.</div>
                        </div>
                    </div>

                    <?php if (!empty($editScreenshots)): ?>
                        <div class="project-screenshot-grid mt-4">
                            <?php foreach ($editScreenshots as $screenshot): ?>
                                <figure class="project-screenshot-item">
                                    <img src="<?= e(public_upload_url($screenshot['image_path'])); ?>" alt="<?= e($screenshot['alt_text'] ?? 'Screenshot del proyecto'); ?>" loading="lazy" width="320" height="180">
                                    <figcaption>
                                        <form method="post" action="<?= e(url_to('projects/index.php')); ?>" onsubmit="return confirm('¿Eliminar este screenshot?');">
                                            <?= csrf_field(); ?>
                                            <input type="hidden" name="action" value="delete_screenshot">
                                            <input type="hidden" name="screenshot_id" value="<?= e((string) $screenshot['id']); ?>">
                                            <button class="btn btn-outline-danger btn-sm" type="submit">Eliminar</button>
                                        </form>
                                    </figcaption>
                                </figure>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div class="d-flex flex-wrap gap-2 justify-content-end mt-4">
                        <button class="btn btn-primary" type="submit"><?= $editProject ? 'Actualizar proyecto' : 'Crear proyecto'; ?></button>
                    </div>
                </form>
            </section>

            <section class="dashboard-card" aria-labelledby="projectListTitle">
                <div class="card-heading">
                    <div>
                        <span class="eyebrow">Portafolio</span>
                        <h2 id="projectListTitle">Proyectos registrados</h2>
                    </div>
                    <span class="status-pill success"><?= e((string) count($projects)); ?> proyectos</span>
                </div>

                <?php if (empty($projects)): ?>
                    <div class="empty-state">
                        <i class="bi bi-kanban" aria-hidden="true"></i>
                        <h3>Aún no tienes proyectos</h3>
                        <p>Agrega tu primer proyecto con screenshots, tecnologías y enlaces para mostrar evidencia de tu trabajo.</p>
                    </div>
                <?php else: ?>
                    <div class="project-list">
                        <?php foreach ($projects as $project): ?>
                            <article class="project-card">
                                <?php if (!empty($project['image_path'])): ?>
                                    <img class="project-cover" src="<?= e(public_upload_url($project['image_path'])); ?>" alt="Portada de <?= e($project['title']); ?>" loading="lazy" width="640" height="360">
                                <?php else: ?>
                                    <div class="project-cover project-cover-placeholder">
                                        <i class="bi bi-image" aria-hidden="true"></i>
                                    </div>
                                <?php endif; ?>

                                <div class="project-card-body">
                                    <div class="project-card-heading">
                                        <div>
                                            <span class="project-category"><?= e($project['category'] ?? 'Sin categoría'); ?></span>
                                            <h3><?= e($project['title']); ?></h3>
                                        </div>
                                        <span class="status-pill <?= $project['status'] === 'finalizado' ? 'success' : 'warning'; ?>">
                                            <?= e($statusLabels[$project['status']] ?? 'Idea'); ?>
                                        </span>
                                    </div>

                                    <p><?= e($project['summary'] ?? 'Sin resumen todavía.'); ?></p>

                                    <?php if (!empty($project['technologies'])): ?>
                                        <div class="technology-list" aria-label="Tecnologías">
                                            <?php foreach (explode(', ', $project['technologies']) as $technology): ?>
                                                <span><?= e($technology); ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>

                                    <div class="project-meta">
                                        <span><i class="bi bi-images" aria-hidden="true"></i> <?= e((string) $project['screenshots_count']); ?> screenshots</span>
                                        <span><i class="bi bi-eye" aria-hidden="true"></i> <?= e($project['visibility'] === 'publico' ? 'Público' : 'Privado'); ?></span>
                                    </div>

                                    <div class="project-links">
                                        <?php if (!empty($project['repository_url'])): ?>
                                            <a class="btn btn-outline-primary btn-sm" href="<?= e($project['repository_url']); ?>" target="_blank" rel="noopener"><i class="bi bi-github" aria-hidden="true"></i> GitHub</a>
                                        <?php endif; ?>
                                        <?php if (!empty($project['demo_url'])): ?>
                                            <a class="btn btn-outline-primary btn-sm" href="<?= e($project['demo_url']); ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> Demo</a>
                                        <?php endif; ?>
                                        <?php if (!empty($project['documentation_path'])): ?>
                                            <a class="btn btn-outline-primary btn-sm" href="<?= e(public_upload_url($project['documentation_path'])); ?>" target="_blank" rel="noopener"><i class="bi bi-file-earmark-text" aria-hidden="true"></i> Docs</a>
                                        <?php endif; ?>
                                        <a class="btn btn-primary btn-sm" href="<?= e(url_to('projects/index.php?edit=' . $project['id'] . '#formulario-proyecto')); ?>">Editar</a>
                                        <form method="post" action="<?= e(url_to('projects/index.php')); ?>" onsubmit="return confirm('¿Eliminar este proyecto y sus screenshots?');">
                                            <?= csrf_field(); ?>
                                            <input type="hidden" name="action" value="delete_project">
                                            <input type="hidden" name="project_id" value="<?= e((string) $project['id']); ?>">
                                            <button class="btn btn-outline-danger btn-sm" type="submit">Eliminar</button>
                                        </form>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>

        <?php require __DIR__ . '/../../../HTML/components/mobile-nav.php'; ?>
    </section>
    </div>
</main>

<?php require_once __DIR__ . '/../../../HTML/components/footer.php'; ?>
