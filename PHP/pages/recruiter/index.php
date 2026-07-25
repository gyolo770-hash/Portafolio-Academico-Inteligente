<?php
declare(strict_types=1);

$pageTitle = 'Búsqueda de talento';
$pageDescription = 'Busca, compara y guarda candidatos con portafolios públicos.';
$bodyClass = 'dashboard-page recruiter-page';
$activeItem = 'reclutador';

require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../helpers/upload.php';
require_once __DIR__ . '/../../helpers/pagination.php';
require_once __DIR__ . '/../../helpers/portfolio.php';
require_once __DIR__ . '/../../helpers/schema.php';
require_role(['reclutador']);

$currentUser = auth_user();
$userId = (int) $currentUser['id'];

function ensure_recruiter_profile(array $user): array
{
    ensure_runtime_schema();

    $statement = db()->prepare('SELECT id, status, accept_student_messages FROM recruiters WHERE email = :email LIMIT 1');
    $statement->execute(['email' => $user['email']]);
    $recruiter = $statement->fetch();

    if ($recruiter) {
        return [
            'id' => (int) $recruiter['id'],
            'status' => $recruiter['status'],
            'accept_student_messages' => (int) ($recruiter['accept_student_messages'] ?? 1),
        ];
    }

    db()->prepare(
        'INSERT INTO recruiters (company_name, contact_name, email, status, accept_student_messages)
         VALUES (:company_name, :contact_name, :email, :status, :accept_student_messages)'
    )->execute([
        'company_name' => 'Reclutador independiente',
        'contact_name' => $user['full_name'],
        'email' => $user['email'],
        'status' => 'activo',
        'accept_student_messages' => 1,
    ]);

    return ['id' => (int) db()->lastInsertId(), 'status' => 'activo', 'accept_student_messages' => 1];
}

function recruiter_candidate_select_sql(): string
{
    return 'SELECT u.id AS user_id,
                   u.full_name,
                   u.email,
                   u.username,
                   u.avatar_path,
                   up.about_me,
                   up.career,
                   up.graduation_year,
                   up.languages,
                   up.location,
                   un.name AS university_name,
                   ps.public_slug,
                   (
                       SELECT COUNT(*)
                       FROM projects p
                       WHERE p.user_id = u.id AND p.visibility = "publico"
                   ) AS public_projects_count,
                   (
                       SELECT COUNT(*)
                       FROM certifications c
                       WHERE c.user_id = u.id AND c.visibility = "publico"
                   ) AS public_certifications_count,
                   (
                       SELECT GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR ", ")
                       FROM user_skills us
                       INNER JOIN skills s ON s.id = us.skill_id
                       WHERE us.user_id = u.id
                   ) AS skills_list
            FROM users u
            INNER JOIN portfolio_settings ps ON ps.user_id = u.id
            LEFT JOIN user_profiles up ON up.user_id = u.id
            LEFT JOIN universities un ON un.id = up.university_id';
}

function recruiter_fetch_candidates(array $filters): array
{
    $where = ['ps.is_public = 1', 'u.status = "activo"'];
    $params = [];

    if ($filters['q'] !== '') {
        $where[] = '(u.full_name LIKE :q_full OR u.username LIKE :q_user OR up.about_me LIKE :q_about OR up.career LIKE :q_career OR un.name LIKE :q_university)';
        $searchTerm = '%' . $filters['q'] . '%';
        $params['q_full'] = $searchTerm;
        $params['q_user'] = $searchTerm;
        $params['q_about'] = $searchTerm;
        $params['q_career'] = $searchTerm;
        $params['q_university'] = $searchTerm;
    }

    if ($filters['career'] !== '') {
        $where[] = 'up.career LIKE :career';
        $params['career'] = '%' . $filters['career'] . '%';
    }

    if ($filters['university'] !== '') {
        $where[] = 'un.name LIKE :university';
        $params['university'] = '%' . $filters['university'] . '%';
    }

    if ($filters['graduation_year'] !== '') {
        $where[] = 'up.graduation_year = :graduation_year';
        $params['graduation_year'] = $filters['graduation_year'];
    }

    if ($filters['skill'] !== '') {
        $where[] = 'EXISTS (
            SELECT 1
            FROM user_skills usf
            INNER JOIN skills sf ON sf.id = usf.skill_id
            WHERE usf.user_id = u.id AND sf.name LIKE :skill
        )';
        $params['skill'] = '%' . $filters['skill'] . '%';
    }

    if ($filters['technology'] !== '') {
        $where[] = 'EXISTS (
            SELECT 1
            FROM projects pf
            INNER JOIN project_skills psf ON psf.project_id = pf.id
            INNER JOIN skills sf2 ON sf2.id = psf.skill_id
            WHERE pf.user_id = u.id AND pf.visibility = "publico" AND sf2.name LIKE :technology
        )';
        $params['technology'] = '%' . $filters['technology'] . '%';
    }

    if ($filters['certification'] !== '') {
        $where[] = 'EXISTS (
            SELECT 1
            FROM certifications cf
            WHERE cf.user_id = u.id
              AND cf.visibility = "publico"
              AND (cf.title LIKE :cert_title OR cf.issuer LIKE :cert_issuer OR cf.category LIKE :cert_category)
        )';
        $certificationTerm = '%' . $filters['certification'] . '%';
        $params['cert_title'] = $certificationTerm;
        $params['cert_issuer'] = $certificationTerm;
        $params['cert_category'] = $certificationTerm;
    }

    $sql = recruiter_candidate_select_sql() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY u.updated_at DESC, u.full_name ASC LIMIT 240';
    $statement = db()->prepare($sql);
    $statement->execute($params);

    return $statement->fetchAll();
}

function recruiter_fetch_compare(array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (empty($ids)) {
        return [];
    }

    $ids = array_slice($ids, 0, 4);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = recruiter_candidate_select_sql() . ' WHERE ps.is_public = 1 AND u.status = "activo" AND u.id IN (' . $placeholders . ') ORDER BY u.full_name ASC';
    $statement = db()->prepare($sql);
    $statement->execute($ids);

    return $statement->fetchAll();
}

$recruiterProfile = ensure_recruiter_profile($currentUser);
$recruiterId = (int) $recruiterProfile['id'];

if (($currentUser['role_name'] ?? '') !== 'administrador' && ($recruiterProfile['status'] ?? '') !== 'activo') {
    flash('warning', 'Tu perfil de reclutador está pendiente o suspendido.');
    header('Location: ' . url_to('dashboard/index.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['_csrf_token'] ?? null)) {
        flash('danger', 'La sesión expiró. Intenta nuevamente.');
        header('Location: ' . url_to('recruiter/index.php'));
        exit;
    }

    $action = (string) ($_POST['action'] ?? '');
    $candidateId = (int) ($_POST['candidate_id'] ?? 0);

    if ($action === 'save_candidate' && $candidateId > 0) {
        db()->prepare('INSERT IGNORE INTO saved_candidates (recruiter_id, user_id, notes) VALUES (:recruiter_id, :user_id, :notes)')
            ->execute([
                'recruiter_id' => $recruiterId,
                'user_id' => $candidateId,
                'notes' => trim((string) ($_POST['notes'] ?? '')),
            ]);
        flash('success', 'Candidato guardado correctamente.');
    }

    if ($action === 'unsave_candidate' && $candidateId > 0) {
        db()->prepare('DELETE FROM saved_candidates WHERE recruiter_id = :recruiter_id AND user_id = :user_id')
            ->execute(['recruiter_id' => $recruiterId, 'user_id' => $candidateId]);
        flash('success', 'Candidato eliminado de guardados.');
    }

    header('Location: ' . safe_internal_redirect($_POST['return_to'] ?? null, url_to('recruiter/index.php')));
    exit;
}

$filters = [
    'q' => trim((string) ($_GET['q'] ?? '')),
    'career' => trim((string) ($_GET['career'] ?? '')),
    'university' => trim((string) ($_GET['university'] ?? '')),
    'graduation_year' => trim((string) ($_GET['graduation_year'] ?? '')),
    'skill' => trim((string) ($_GET['skill'] ?? '')),
    'technology' => trim((string) ($_GET['technology'] ?? '')),
    'certification' => trim((string) ($_GET['certification'] ?? '')),
];

$candidates = recruiter_fetch_candidates($filters);
$candidatePage = max(1, (int) ($_GET['page'] ?? 1));
$candidateTotal = count($candidates);
$candidatePagination = pagination_state($candidatePage, 12, $candidateTotal);
$candidates = array_slice($candidates, $candidatePagination['offset'], $candidatePagination['limit']);
$pagination = $candidatePagination;
$compareIds = $_GET['compare'] ?? [];
if (!is_array($compareIds)) {
    $compareIds = [$compareIds];
}
$compareCandidates = recruiter_fetch_compare($compareIds);

$savedStatement = db()->prepare('SELECT user_id FROM saved_candidates WHERE recruiter_id = :recruiter_id');
$savedStatement->execute(['recruiter_id' => $recruiterId]);
$savedCandidateIds = array_map('intval', array_column($savedStatement->fetchAll(), 'user_id'));

$savedCandidatesStatement = db()->prepare(
    recruiter_candidate_select_sql() . '
     INNER JOIN saved_candidates sc ON sc.user_id = u.id
     WHERE sc.recruiter_id = :recruiter_id
       AND ps.is_public = 1
       AND u.status = "activo"
     ORDER BY sc.created_at DESC'
);
$savedCandidatesStatement->execute(['recruiter_id' => $recruiterId]);
$savedCandidates = $savedCandidatesStatement->fetchAll();

function recruiter_compare_url(int $candidateId): string
{
    $params = $_GET;
    $current = $params['compare'] ?? [];
    if (!is_array($current)) {
        $current = [$current];
    }

    $current[] = $candidateId;
    $params['compare'] = array_values(array_unique(array_map('intval', $current)));

    return url_to('recruiter/index.php?' . http_build_query($params));
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
                <span class="eyebrow">Portal de reclutador</span>
                <h1>Búsqueda de talento</h1>
                <p>Encuentra estudiantes con portafolios públicos, filtra por habilidades, carrera, universidad, certificaciones y tecnologías.</p>
            </div>
            <a class="btn btn-outline-primary" href="#guardados">Ver guardados</a>
        </div>

        <section class="dashboard-card recruiter-filter-card" aria-labelledby="filterTitle">
            <div class="card-heading">
                <div>
                    <span class="eyebrow">Filtros</span>
                    <h2 id="filterTitle">Buscar candidatos</h2>
                </div>
                <span class="status-pill success"><?= e((string) $candidateTotal); ?> resultados</span>
            </div>

            <form class="recruiter-filter-form" method="get" action="<?= e(url_to('recruiter/index.php')); ?>">
                <div class="row g-3">
                    <div class="col-lg-4">
                        <label class="form-label" for="q">Búsqueda general</label>
                        <input class="form-control" id="q" name="q" value="<?= e($filters['q']); ?>" placeholder="Nombre, carrera, universidad...">
                    </div>
                    <div class="col-lg-4">
                        <label class="form-label" for="skill">Habilidad</label>
                        <input class="form-control" id="skill" name="skill" value="<?= e($filters['skill']); ?>" placeholder="PHP, comunicación, liderazgo">
                    </div>
                    <div class="col-lg-4">
                        <label class="form-label" for="technology">Tecnología en proyectos</label>
                        <input class="form-control" id="technology" name="technology" value="<?= e($filters['technology']); ?>" placeholder="MySQL, Bootstrap, JavaScript">
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <label class="form-label" for="career">Carrera</label>
                        <input class="form-control" id="career" name="career" value="<?= e($filters['career']); ?>">
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <label class="form-label" for="university">Universidad</label>
                        <input class="form-control" id="university" name="university" value="<?= e($filters['university']); ?>">
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <label class="form-label" for="graduationYear">Año de graduación</label>
                        <input class="form-control" type="number" id="graduationYear" name="graduation_year" value="<?= e($filters['graduation_year']); ?>" min="1950" max="2155">
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <label class="form-label" for="certification">Certificación</label>
                        <input class="form-control" id="certification" name="certification" value="<?= e($filters['certification']); ?>">
                    </div>
                </div>
                <div class="d-flex flex-wrap gap-2 justify-content-end mt-4">
                    <a class="btn btn-outline-primary" href="<?= e(url_to('recruiter/index.php')); ?>">Limpiar</a>
                    <button class="btn btn-primary" type="submit">Aplicar filtros</button>
                </div>
            </form>
        </section>

        <?php if (!empty($compareCandidates)): ?>
            <section class="dashboard-card mt-3" aria-labelledby="compareTitle">
                <div class="card-heading">
                    <div>
                        <span class="eyebrow">Comparación</span>
                        <h2 id="compareTitle">Comparar candidatos</h2>
                    </div>
                    <a class="small fw-bold" href="<?= e(url_to('recruiter/index.php')); ?>">Limpiar comparación</a>
                </div>
                <div class="candidate-compare-grid">
                    <?php foreach ($compareCandidates as $candidate): ?>
                        <article class="candidate-compare-card">
                            <h3><?= e($candidate['full_name']); ?></h3>
                            <p><?= e($candidate['career'] ?? 'Carrera pendiente'); ?></p>
                            <dl class="profile-list">
                                <div><dt>Universidad</dt><dd><?= e($candidate['university_name'] ?? 'Pendiente'); ?></dd></div>
                                <div><dt>Graduación</dt><dd><?= e((string) ($candidate['graduation_year'] ?? 'Pendiente')); ?></dd></div>
                                <div><dt>Proyectos</dt><dd><?= e((string) $candidate['public_projects_count']); ?></dd></div>
                                <div><dt>Certificaciones</dt><dd><?= e((string) $candidate['public_certifications_count']); ?></dd></div>
                            </dl>
                            <div class="technology-list">
                                <?php foreach (array_filter(array_map('trim', explode(',', (string) $candidate['skills_list']))) as $skill): ?>
                                    <span><?= e($skill); ?></span>
                                <?php endforeach; ?>
                            </div>
                            <a class="btn btn-primary btn-sm" href="<?= e(url_to('portfolio/resume.php?slug=' . urlencode($candidate['public_slug']))); ?>" target="_blank" rel="noopener">Descargar CV</a>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <div class="recruiter-layout mt-3">
            <section class="dashboard-card" aria-labelledby="candidateTitle">
                <div class="card-heading">
                    <div>
                        <span class="eyebrow">Candidatos</span>
                        <h2 id="candidateTitle">Resultados de búsqueda</h2>
                    </div>
                </div>

                <?php if (empty($candidates)): ?>
                    <div class="empty-state">
                        <i class="bi bi-search" aria-hidden="true"></i>
                        <h3>No hay candidatos con esos filtros</h3>
                        <p>Prueba con una habilidad, tecnología o carrera más general.</p>
                    </div>
                <?php else: ?>
                    <div class="candidate-list">
                        <?php foreach ($candidates as $candidate): ?>
                            <?php $isSaved = in_array((int) $candidate['user_id'], $savedCandidateIds, true); ?>
                            <article class="candidate-card">
                                <div class="candidate-avatar">
                                    <?php $avatarUrl = public_upload_url($candidate['avatar_path'] ?? null); ?>
                                    <?php if ($avatarUrl !== ''): ?>
                                        <img src="<?= e($avatarUrl); ?>" alt="Foto de <?= e($candidate['full_name']); ?>" loading="lazy" width="64" height="64">
                                    <?php else: ?>
                                        <span><?= e(strtoupper(substr($candidate['full_name'], 0, 1))); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="candidate-body">
                                    <div class="project-card-heading">
                                        <div>
                                            <span class="project-category"><?= e($candidate['career'] ?? 'Estudiante'); ?></span>
                                            <h3><?= e($candidate['full_name']); ?></h3>
                                        </div>
                                        <span class="status-pill <?= $isSaved ? 'success' : 'warning'; ?>"><?= $isSaved ? 'Guardado' : 'Nuevo'; ?></span>
                                    </div>
                                    <p><?= e($candidate['about_me'] ?? 'Portafolio público disponible para revisión.'); ?></p>
                                    <div class="project-meta">
                                        <span><i class="bi bi-building" aria-hidden="true"></i> <?= e($candidate['university_name'] ?? 'Universidad pendiente'); ?></span>
                                        <span><i class="bi bi-kanban" aria-hidden="true"></i> <?= e((string) $candidate['public_projects_count']); ?> proyectos</span>
                                        <span><i class="bi bi-award" aria-hidden="true"></i> <?= e((string) $candidate['public_certifications_count']); ?> certificados</span>
                                    </div>
                                    <?php if (!empty($candidate['skills_list'])): ?>
                                        <div class="technology-list">
                                            <?php foreach (array_slice(array_filter(array_map('trim', explode(',', $candidate['skills_list']))), 0, 8) as $skill): ?>
                                                <span><?= e($skill); ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="project-links">
                                        <a class="btn btn-outline-primary btn-sm" href="<?= e(portfolio_public_url($candidate['public_slug'] ?? null)); ?>" target="_blank" rel="noopener">Ver portafolio</a>
                                        <a class="btn btn-outline-primary btn-sm" href="<?= e(url_to('portfolio/resume.php?slug=' . urlencode($candidate['public_slug']))); ?>" target="_blank" rel="noopener">Descargar CV</a>
                                        <a class="btn btn-primary btn-sm" href="<?= e(recruiter_compare_url((int) $candidate['user_id'])); ?>">Comparar</a>
                                        <form method="post" action="<?= e(url_to('recruiter/index.php')); ?>">
                                            <?= csrf_field(); ?>
                                            <input type="hidden" name="candidate_id" value="<?= e((string) $candidate['user_id']); ?>">
                                            <input type="hidden" name="return_to" value="<?= e($_SERVER['REQUEST_URI'] ?? url_to('recruiter/index.php')); ?>">
                                            <?php if ($isSaved): ?>
                                                <input type="hidden" name="action" value="unsave_candidate">
                                                <button class="btn btn-outline-danger btn-sm" type="submit">Quitar guardado</button>
                                            <?php else: ?>
                                                <input type="hidden" name="action" value="save_candidate">
                                                <button class="btn btn-outline-primary btn-sm" type="submit">Guardar</button>
                                            <?php endif; ?>
                                        </form>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <?php require __DIR__ . '/../../../HTML/components/pagination.php'; ?>
                <?php endif; ?>
            </section>

            <aside id="guardados" class="dashboard-card" aria-labelledby="savedTitle">
                <div class="card-heading">
                    <div>
                        <span class="eyebrow">Favoritos</span>
                        <h2 id="savedTitle">Candidatos guardados</h2>
                    </div>
                    <span class="status-pill success"><?= e((string) count($savedCandidates)); ?></span>
                </div>
                <?php if (empty($savedCandidates)): ?>
                    <p class="text-secondary">Aún no has guardado candidatos.</p>
                <?php else: ?>
                    <div class="saved-candidate-list">
                        <?php foreach ($savedCandidates as $candidate): ?>
                            <article>
                                <strong><?= e($candidate['full_name']); ?></strong>
                                <span><?= e($candidate['career'] ?? 'Carrera pendiente'); ?></span>
                                <div class="project-links">
                                    <a class="btn btn-outline-primary btn-sm" href="<?= e(portfolio_public_url($candidate['public_slug'] ?? null)); ?>" target="_blank" rel="noopener">Ver</a>
                                    <a class="btn btn-primary btn-sm" href="<?= e(url_to('portfolio/resume.php?slug=' . urlencode($candidate['public_slug']))); ?>" target="_blank" rel="noopener">CV</a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </aside>
        </div>

        <?php require __DIR__ . '/../../../HTML/components/mobile-nav.php'; ?>
    </section>
    </div>
</main>

<?php require_once __DIR__ . '/../../../HTML/components/footer.php'; ?>
