<?php
declare(strict_types=1);

$pageTitle = 'Mis habilidades';
$pageDescription = 'Gestiona habilidades técnicas y blandas con niveles y relaciones a proyectos/certificaciones.';
$bodyClass = 'dashboard-page skills-page';
$activeItem = 'habilidades';
$pageScript = 'auth.js';

require_once __DIR__ . '/../../middleware/auth.php';
require_student();

$currentUser = auth_user();
$userId = (int) $currentUser['id'];

$typeLabels = [
    'tecnica' => 'Técnica',
    'blanda' => 'Blanda',
    'idioma' => 'Idioma',
    'herramienta' => 'Herramienta',
];

$proficiencyLabels = [
    'basico' => 'Básico',
    'intermedio' => 'Intermedio',
    'avanzado' => 'Avanzado',
    'experto' => 'Experto',
];

$proficiencyScores = [
    'basico' => 25,
    'intermedio' => 50,
    'avanzado' => 75,
    'experto' => 100,
];

function find_or_create_skill_category(string $name, string $type): int
{
    $name = trim($name);
    $allowedTypes = ['tecnica', 'blanda', 'idioma', 'herramienta'];
    $type = in_array($type, $allowedTypes, true) ? $type : 'tecnica';

    if ($name === '') {
        $name = $type === 'blanda' ? 'Habilidades blandas' : 'Habilidades técnicas';
    }

    $statement = db()->prepare('SELECT id FROM skill_categories WHERE name = :name LIMIT 1');
    $statement->execute(['name' => $name]);
    $category = $statement->fetch();

    if ($category) {
        db()->prepare('UPDATE skill_categories SET type = :type WHERE id = :id')
            ->execute(['type' => $type, 'id' => $category['id']]);
        return (int) $category['id'];
    }

    db()->prepare('INSERT INTO skill_categories (name, type) VALUES (:name, :type)')
        ->execute(['name' => $name, 'type' => $type]);

    return (int) db()->lastInsertId();
}

function find_or_create_skill(string $name, int $categoryId): int
{
    $name = trim($name);
    $statement = db()->prepare('SELECT id FROM skills WHERE name = :name LIMIT 1');
    $statement->execute(['name' => $name]);
    $skill = $statement->fetch();

    if ($skill) {
        db()->prepare('UPDATE skills SET category_id = :category_id WHERE id = :id')
            ->execute(['category_id' => $categoryId, 'id' => $skill['id']]);
        return (int) $skill['id'];
    }

    db()->prepare('INSERT INTO skills (category_id, name) VALUES (:category_id, :name)')
        ->execute(['category_id' => $categoryId, 'name' => $name]);

    return (int) db()->lastInsertId();
}

function selected_ids(string $key): array
{
    $values = $_POST[$key] ?? [];
    if (!is_array($values)) {
        return [];
    }

    return array_values(array_unique(array_map('intval', $values)));
}

function sync_skill_relations(int $skillId, int $userId, array $projectIds, array $certificationIds): void
{
    db()->prepare(
        'DELETE ps
         FROM project_skills ps
         INNER JOIN projects p ON p.id = ps.project_id
         WHERE ps.skill_id = :skill_id AND p.user_id = :user_id'
    )->execute(['skill_id' => $skillId, 'user_id' => $userId]);

    db()->prepare(
        'DELETE cs
         FROM certification_skills cs
         INNER JOIN certifications c ON c.id = cs.certification_id
         WHERE cs.skill_id = :skill_id AND c.user_id = :user_id'
    )->execute(['skill_id' => $skillId, 'user_id' => $userId]);

    foreach ($projectIds as $projectId) {
        $owner = db()->prepare('SELECT id FROM projects WHERE id = :id AND user_id = :user_id LIMIT 1');
        $owner->execute(['id' => $projectId, 'user_id' => $userId]);
        if ($owner->fetch()) {
            db()->prepare('INSERT IGNORE INTO project_skills (project_id, skill_id) VALUES (:project_id, :skill_id)')
                ->execute(['project_id' => $projectId, 'skill_id' => $skillId]);
        }
    }

    foreach ($certificationIds as $certificationId) {
        $owner = db()->prepare('SELECT id FROM certifications WHERE id = :id AND user_id = :user_id LIMIT 1');
        $owner->execute(['id' => $certificationId, 'user_id' => $userId]);
        if ($owner->fetch()) {
            db()->prepare('INSERT IGNORE INTO certification_skills (certification_id, skill_id) VALUES (:certification_id, :skill_id)')
                ->execute(['certification_id' => $certificationId, 'skill_id' => $skillId]);
        }
    }
}

function clear_skill_relations_for_user(int $skillId, int $userId): void
{
    sync_skill_relations($skillId, $userId, [], []);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['_csrf_token'] ?? null)) {
        flash('danger', 'La sesión expiró. Intenta guardar la habilidad nuevamente.');
        header('Location: ' . url_to('skills/index.php'));
        exit;
    }

    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'save_skill') {
            $userSkillId = (int) ($_POST['user_skill_id'] ?? 0);
            $skillName = trim((string) ($_POST['skill_name'] ?? ''));
            $categoryName = trim((string) ($_POST['category_name'] ?? ''));
            $type = (string) ($_POST['type'] ?? 'tecnica');
            $proficiency = (string) ($_POST['proficiency'] ?? 'basico');
            $projectIds = selected_ids('project_ids');
            $certificationIds = selected_ids('certification_ids');

            if ($skillName === '') {
                flash('danger', 'El nombre de la habilidad es obligatorio.');
                header('Location: ' . url_to('skills/index.php'));
                exit;
            }

            if (!isset($proficiencyLabels[$proficiency])) {
                $proficiency = 'basico';
            }

            db()->beginTransaction();

            $oldSkillId = null;
            if ($userSkillId > 0) {
                $oldStatement = db()->prepare('SELECT skill_id FROM user_skills WHERE id = :id AND user_id = :user_id LIMIT 1');
                $oldStatement->execute(['id' => $userSkillId, 'user_id' => $userId]);
                $oldSkill = $oldStatement->fetch();
                $oldSkillId = $oldSkill ? (int) $oldSkill['skill_id'] : null;
            }

            $categoryId = find_or_create_skill_category($categoryName, $type);
            $skillId = find_or_create_skill($skillName, $categoryId);

            if ($userSkillId > 0 && $oldSkillId !== null) {
                db()->prepare('DELETE FROM user_skills WHERE id = :id AND user_id = :user_id')
                    ->execute(['id' => $userSkillId, 'user_id' => $userId]);

                if ($oldSkillId !== $skillId) {
                    clear_skill_relations_for_user($oldSkillId, $userId);
                }
            }

            db()->prepare('INSERT INTO user_skills (user_id, skill_id, proficiency) VALUES (:user_id, :skill_id, :proficiency) ON DUPLICATE KEY UPDATE proficiency = VALUES(proficiency)')
                ->execute([
                    'user_id' => $userId,
                    'skill_id' => $skillId,
                    'proficiency' => $proficiency,
                ]);

            sync_skill_relations($skillId, $userId, $projectIds, $certificationIds);

            db()->commit();
            flash('success', $userSkillId > 0 ? 'Habilidad actualizada correctamente.' : 'Habilidad agregada correctamente.');
        }

        if ($action === 'delete_skill') {
            $userSkillId = (int) ($_POST['user_skill_id'] ?? 0);
            $statement = db()->prepare('SELECT skill_id FROM user_skills WHERE id = :id AND user_id = :user_id LIMIT 1');
            $statement->execute(['id' => $userSkillId, 'user_id' => $userId]);
            $skill = $statement->fetch();

            if ($skill) {
                db()->beginTransaction();
                clear_skill_relations_for_user((int) $skill['skill_id'], $userId);
                db()->prepare('DELETE FROM user_skills WHERE id = :id AND user_id = :user_id')
                    ->execute(['id' => $userSkillId, 'user_id' => $userId]);
                db()->commit();
                flash('success', 'Habilidad eliminada correctamente.');
            }
        }
    } catch (Throwable $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }

        error_log('Error en módulo de habilidades: ' . $exception->getMessage());
        flash('danger', $exception->getMessage() ?: 'No se pudo guardar la habilidad.');
    }

    header('Location: ' . url_to('skills/index.php'));
    exit;
}

$projectsStatement = db()->prepare('SELECT id, title FROM projects WHERE user_id = :user_id ORDER BY title ASC');
$projectsStatement->execute(['user_id' => $userId]);
$projects = $projectsStatement->fetchAll();

$certificationsStatement = db()->prepare('SELECT id, title FROM certifications WHERE user_id = :user_id ORDER BY title ASC');
$certificationsStatement->execute(['user_id' => $userId]);
$certifications = $certificationsStatement->fetchAll();

$categoriesStatement = db()->query('SELECT name, type FROM skill_categories ORDER BY type ASC, name ASC');
$categories = $categoriesStatement->fetchAll();

$editSkill = null;
$selectedProjectIds = [];
$selectedCertificationIds = [];

if (isset($_GET['edit'])) {
    $editStatement = db()->prepare(
        'SELECT us.id AS user_skill_id, us.proficiency, s.id AS skill_id, s.name AS skill_name, sc.name AS category_name, sc.type
         FROM user_skills us
         INNER JOIN skills s ON s.id = us.skill_id
         LEFT JOIN skill_categories sc ON sc.id = s.category_id
         WHERE us.id = :id AND us.user_id = :user_id
         LIMIT 1'
    );
    $editStatement->execute(['id' => (int) $_GET['edit'], 'user_id' => $userId]);
    $editSkill = $editStatement->fetch() ?: null;

    if ($editSkill) {
        $projectSelection = db()->prepare(
            'SELECT p.id
             FROM project_skills ps
             INNER JOIN projects p ON p.id = ps.project_id
             WHERE ps.skill_id = :skill_id AND p.user_id = :user_id'
        );
        $projectSelection->execute(['skill_id' => $editSkill['skill_id'], 'user_id' => $userId]);
        $selectedProjectIds = array_map('intval', array_column($projectSelection->fetchAll(), 'id'));

        $certificationSelection = db()->prepare(
            'SELECT c.id
             FROM certification_skills cs
             INNER JOIN certifications c ON c.id = cs.certification_id
             WHERE cs.skill_id = :skill_id AND c.user_id = :user_id'
        );
        $certificationSelection->execute(['skill_id' => $editSkill['skill_id'], 'user_id' => $userId]);
        $selectedCertificationIds = array_map('intval', array_column($certificationSelection->fetchAll(), 'id'));
    }
}

$skillsStatement = db()->prepare(
    'SELECT us.id AS user_skill_id,
            us.proficiency,
            s.id AS skill_id,
            s.name AS skill_name,
            sc.name AS category_name,
            sc.type,
            (
                SELECT GROUP_CONCAT(p.title ORDER BY p.title SEPARATOR ", ")
                FROM project_skills ps
                INNER JOIN projects p ON p.id = ps.project_id
                WHERE ps.skill_id = s.id AND p.user_id = us.user_id
            ) AS related_projects,
            (
                SELECT GROUP_CONCAT(c.title ORDER BY c.title SEPARATOR ", ")
                FROM certification_skills cs
                INNER JOIN certifications c ON c.id = cs.certification_id
                WHERE cs.skill_id = s.id AND c.user_id = us.user_id
            ) AS related_certifications
     FROM user_skills us
     INNER JOIN skills s ON s.id = us.skill_id
     LEFT JOIN skill_categories sc ON sc.id = s.category_id
     WHERE us.user_id = :user_id
     ORDER BY sc.type ASC, us.proficiency DESC, s.name ASC'
);
$skillsStatement->execute(['user_id' => $userId]);
$skills = $skillsStatement->fetchAll();

$stats = [
    'total' => count($skills),
    'tecnica' => 0,
    'blanda' => 0,
    'experto' => 0,
];

foreach ($skills as $skill) {
    if (($skill['type'] ?? '') === 'tecnica') {
        $stats['tecnica']++;
    }

    if (($skill['type'] ?? '') === 'blanda') {
        $stats['blanda']++;
    }

    if ($skill['proficiency'] === 'experto') {
        $stats['experto']++;
    }
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
                <span class="eyebrow">Habilidades</span>
                <h1>Mis habilidades</h1>
                <p>Organiza habilidades técnicas y blandas, define tu nivel y conéctalas con proyectos y certificaciones.</p>
            </div>
            <a class="btn btn-primary" href="#formulario-habilidad">Agregar habilidad</a>
        </div>

        <section class="stats-grid" aria-label="Resumen de habilidades">
            <article class="stat-card">
                <div class="stat-icon primary"><i class="bi bi-stars" aria-hidden="true"></i></div>
                <div><span>Total</span><strong><?= e((string) $stats['total']); ?></strong></div>
            </article>
            <article class="stat-card">
                <div class="stat-icon info"><i class="bi bi-code-slash" aria-hidden="true"></i></div>
                <div><span>Técnicas</span><strong><?= e((string) $stats['tecnica']); ?></strong></div>
            </article>
            <article class="stat-card">
                <div class="stat-icon success"><i class="bi bi-chat-heart" aria-hidden="true"></i></div>
                <div><span>Blandas</span><strong><?= e((string) $stats['blanda']); ?></strong></div>
            </article>
            <article class="stat-card">
                <div class="stat-icon warning"><i class="bi bi-trophy" aria-hidden="true"></i></div>
                <div><span>Expertas</span><strong><?= e((string) $stats['experto']); ?></strong></div>
            </article>
        </section>

        <div class="skills-layout">
            <section id="formulario-habilidad" class="dashboard-card" aria-labelledby="skillFormTitle">
                <div class="card-heading">
                    <div>
                        <span class="eyebrow">Formulario</span>
                        <h2 id="skillFormTitle"><?= $editSkill ? 'Editar habilidad' : 'Agregar habilidad'; ?></h2>
                    </div>
                    <?php if ($editSkill): ?>
                        <a class="small fw-bold" href="<?= e(url_to('skills/index.php#formulario-habilidad')); ?>">Cancelar edición</a>
                    <?php endif; ?>
                </div>

                <form class="needs-validation skill-form" method="post" action="<?= e(url_to('skills/index.php')); ?>" novalidate>
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action" value="save_skill">
                    <input type="hidden" name="user_skill_id" value="<?= e((string) ($editSkill['user_skill_id'] ?? 0)); ?>">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="skillName">Nombre de habilidad</label>
                            <input class="form-control" type="text" id="skillName" name="skill_name" value="<?= e($editSkill['skill_name'] ?? ''); ?>" placeholder="PHP, liderazgo, comunicación..." required>
                            <div class="invalid-feedback">Ingresa el nombre de la habilidad.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="categoryName">Categoría</label>
                            <input class="form-control" list="skillCategoryOptions" id="categoryName" name="category_name" value="<?= e($editSkill['category_name'] ?? ''); ?>" placeholder="Programación, Comunicación...">
                            <datalist id="skillCategoryOptions">
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?= e($category['name']); ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="type">Tipo</label>
                            <select class="form-select" id="type" name="type">
                                <?php foreach ($typeLabels as $value => $label): ?>
                                    <option value="<?= e($value); ?>" <?= ($editSkill['type'] ?? 'tecnica') === $value ? 'selected' : ''; ?>><?= e($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="proficiency">Nivel de dominio</label>
                            <select class="form-select" id="proficiency" name="proficiency">
                                <?php foreach ($proficiencyLabels as $value => $label): ?>
                                    <option value="<?= e($value); ?>" <?= ($editSkill['proficiency'] ?? 'basico') === $value ? 'selected' : ''; ?>><?= e($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="relation-picker mt-4">
                        <div>
                            <h3>Relacionar con proyectos</h3>
                            <?php if (empty($projects)): ?>
                                <p class="text-secondary mb-0">Aún no tienes proyectos registrados.</p>
                            <?php else: ?>
                                <div class="check-grid">
                                    <?php foreach ($projects as $project): ?>
                                        <label class="relation-check">
                                            <input type="checkbox" name="project_ids[]" value="<?= e((string) $project['id']); ?>" <?= in_array((int) $project['id'], $selectedProjectIds, true) ? 'checked' : ''; ?>>
                                            <span><?= e($project['title']); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div>
                            <h3>Relacionar con certificaciones</h3>
                            <?php if (empty($certifications)): ?>
                                <p class="text-secondary mb-0">Aún no tienes certificaciones registradas.</p>
                            <?php else: ?>
                                <div class="check-grid">
                                    <?php foreach ($certifications as $certification): ?>
                                        <label class="relation-check">
                                            <input type="checkbox" name="certification_ids[]" value="<?= e((string) $certification['id']); ?>" <?= in_array((int) $certification['id'], $selectedCertificationIds, true) ? 'checked' : ''; ?>>
                                            <span><?= e($certification['title']); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="d-flex flex-wrap gap-2 justify-content-end mt-4">
                        <button class="btn btn-primary" type="submit"><?= $editSkill ? 'Actualizar habilidad' : 'Guardar habilidad'; ?></button>
                    </div>
                </form>
            </section>

            <section class="dashboard-card" aria-labelledby="skillListTitle">
                <div class="card-heading">
                    <div>
                        <span class="eyebrow">Mapa de habilidades</span>
                        <h2 id="skillListTitle">Habilidades registradas</h2>
                    </div>
                    <span class="status-pill success"><?= e((string) count($skills)); ?> habilidades</span>
                </div>

                <?php if (empty($skills)): ?>
                    <div class="empty-state">
                        <i class="bi bi-stars" aria-hidden="true"></i>
                        <h3>Aún no agregas habilidades</h3>
                        <p>Registra habilidades técnicas y blandas para conectarlas con tus evidencias académicas.</p>
                    </div>
                <?php else: ?>
                    <div class="skill-list">
                        <?php foreach ($skills as $skill): ?>
                            <?php $score = $proficiencyScores[$skill['proficiency']] ?? 25; ?>
                            <article class="skill-card">
                                <div class="skill-card-head">
                                    <div>
                                        <span class="project-category"><?= e($typeLabels[$skill['type']] ?? 'Habilidad'); ?> · <?= e($skill['category_name'] ?? 'Sin categoría'); ?></span>
                                        <h3><?= e($skill['skill_name']); ?></h3>
                                    </div>
                                    <span class="status-pill <?= $skill['proficiency'] === 'experto' ? 'success' : 'warning'; ?>">
                                        <?= e($proficiencyLabels[$skill['proficiency']] ?? 'Básico'); ?>
                                    </span>
                                </div>

                                <div class="skill-meter" aria-label="Nivel <?= e($proficiencyLabels[$skill['proficiency']] ?? 'Básico'); ?>">
                                    <span style="width: <?= e((string) $score); ?>%"></span>
                                </div>

                                <div class="skill-relations">
                                    <div>
                                        <strong>Proyectos</strong>
                                        <p><?= e($skill['related_projects'] ?: 'Sin proyectos relacionados'); ?></p>
                                    </div>
                                    <div>
                                        <strong>Certificaciones</strong>
                                        <p><?= e($skill['related_certifications'] ?: 'Sin certificaciones relacionadas'); ?></p>
                                    </div>
                                </div>

                                <div class="project-links">
                                    <a class="btn btn-primary btn-sm" href="<?= e(url_to('skills/index.php?edit=' . $skill['user_skill_id'] . '#formulario-habilidad')); ?>">Editar</a>
                                    <form method="post" action="<?= e(url_to('skills/index.php')); ?>" onsubmit="return confirm('¿Eliminar esta habilidad y sus relaciones?');">
                                        <?= csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete_skill">
                                        <input type="hidden" name="user_skill_id" value="<?= e((string) $skill['user_skill_id']); ?>">
                                        <button class="btn btn-outline-danger btn-sm" type="submit">Eliminar</button>
                                    </form>
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
