<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/security.php';
require_once __DIR__ . '/../../helpers/upload.php';
require_once __DIR__ . '/../../helpers/resume.php';
require_once __DIR__ . '/../../helpers/portfolio.php';
require_once __DIR__ . '/../../helpers/auth.php';

$slug = strtolower(trim((string) ($_GET['slug'] ?? ($_GET['u'] ?? ''))));
$pageTitle = 'Portafolio público';
$pageDescription = 'Portafolio académico público del estudiante.';
$bodyClass = 'public-portfolio-page';
$isOwnerPreview = false;

$portfolioStatement = db()->prepare(
    'SELECT u.id AS user_id, u.full_name, u.username, u.email, u.avatar_path,
            up.about_me, up.career, up.graduation_year, up.phone, up.location,
            up.github_url, up.linkedin_url, up.portfolio_url, up.instagram_url, up.languages,
            un.name AS university_name,
            ps.public_slug, ps.theme_color, ps.is_public, ps.allow_contact
     FROM portfolio_settings ps
     INNER JOIN users u ON u.id = ps.user_id
     LEFT JOIN user_profiles up ON up.user_id = u.id
     LEFT JOIN universities un ON un.id = up.university_id
     WHERE ps.public_slug = :slug
       AND ps.is_public = 1
       AND u.status = "activo"
     LIMIT 1'
);
$portfolioStatement->execute(['slug' => $slug]);
$portfolio = $portfolioStatement->fetch();

if (!$portfolio && $slug !== '' && auth_check()) {
    $ownerStatement = db()->prepare(
        'SELECT u.id AS user_id, u.full_name, u.username, u.email, u.avatar_path,
                up.about_me, up.career, up.graduation_year, up.phone, up.location,
                up.github_url, up.linkedin_url, up.portfolio_url, up.instagram_url, up.languages,
                un.name AS university_name,
                ps.public_slug, ps.theme_color, ps.is_public, ps.allow_contact
         FROM portfolio_settings ps
         INNER JOIN users u ON u.id = ps.user_id
         LEFT JOIN user_profiles up ON up.user_id = u.id
         LEFT JOIN universities un ON un.id = up.university_id
         WHERE ps.public_slug = :slug
           AND u.id = :user_id
           AND u.status = "activo"
         LIMIT 1'
    );
    $ownerStatement->execute([
        'slug' => $slug,
        'user_id' => (int) $_SESSION['user_id'],
    ]);
    $portfolio = $ownerStatement->fetch() ?: null;
    $isOwnerPreview = $portfolio !== null && (int) ($portfolio['is_public'] ?? 0) !== 1;
}

if ($portfolio) {
    $pageTitle = 'Portafolio de ' . $portfolio['full_name'];
    $pageDescription = 'Proyectos, certificaciones y habilidades de ' . $portfolio['full_name'];

    $visitorIp = $_SERVER['REMOTE_ADDR'] ?? null;
    $recentVisitStatement = db()->prepare(
        'SELECT id
         FROM portfolio_visits
         WHERE user_id = :user_id
           AND visitor_ip = :visitor_ip
           AND visited_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
         LIMIT 1'
    );
    $recentVisitStatement->execute([
        'user_id' => $portfolio['user_id'],
        'visitor_ip' => $visitorIp,
    ]);

    if (!$recentVisitStatement->fetch()) {
        db()->prepare(
            'INSERT INTO portfolio_visits (user_id, visitor_ip, visitor_agent, referrer)
             VALUES (:user_id, :visitor_ip, :visitor_agent, :referrer)'
        )->execute([
            'user_id' => $portfolio['user_id'],
            'visitor_ip' => $visitorIp,
            'visitor_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            'referrer' => substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 255),
        ]);
    }
}

if ($portfolio && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!rate_limit_attempt('portfolio_contact', 5, 900)) {
        flash('danger', 'Demasiados mensajes enviados. Espera unos minutos antes de intentar nuevamente.');
        header('Location: ' . url_to('portfolio/index.php?slug=' . urlencode($slug) . '#contacto'));
        exit;
    }

    if (!verify_csrf_token($_POST['_csrf_token'] ?? null)) {
        flash('danger', 'La sesión expiró. Intenta enviar el mensaje nuevamente.');
        header('Location: ' . url_to('portfolio/index.php?slug=' . urlencode($slug) . '#contacto'));
        exit;
    }

    $senderName = trim((string) ($_POST['sender_name'] ?? ''));
    $senderEmail = strtolower(trim((string) ($_POST['sender_email'] ?? '')));
    $subject = trim((string) ($_POST['subject'] ?? ''));
    $message = trim((string) ($_POST['message'] ?? ''));

    if ((int) $portfolio['allow_contact'] !== 1) {
        flash('warning', 'Este portafolio no acepta mensajes de contacto por ahora.');
    } elseif ($senderName === '' || !filter_var($senderEmail, FILTER_VALIDATE_EMAIL) || $subject === '' || $message === '') {
        flash('danger', 'Completa tu nombre, correo, asunto y mensaje.');
    } else {
        db()->prepare(
            'INSERT INTO contact_messages (user_id, sender_name, sender_email, subject, message)
             VALUES (:user_id, :sender_name, :sender_email, :subject, :message)'
        )->execute([
            'user_id' => $portfolio['user_id'],
            'sender_name' => $senderName,
            'sender_email' => $senderEmail,
            'subject' => $subject,
            'message' => $message,
        ]);

        db()->prepare(
            'INSERT INTO notifications (user_id, title, message, type)
             VALUES (:user_id, :title, :message, :type)'
        )->execute([
            'user_id' => $portfolio['user_id'],
            'title' => 'Nuevo mensaje desde tu portafolio',
            'message' => $senderName . ' te escribió sobre: ' . $subject,
            'type' => 'info',
        ]);

        flash('success', 'Mensaje enviado correctamente.');
    }

    header('Location: ' . url_to('portfolio/index.php?slug=' . urlencode($slug) . '#contacto'));
    exit;
}

$projects = [];
$certifications = [];
$skills = [];
$resume = null;
$education = [];
$experiences = [];

if ($portfolio) {
    $userId = (int) $portfolio['user_id'];

    $projectsStatement = db()->prepare(
        'SELECT p.*,
                (
                    SELECT GROUP_CONCAT(s.name ORDER BY s.name SEPARATOR ", ")
                    FROM project_skills ps
                    INNER JOIN skills s ON s.id = ps.skill_id
                    WHERE ps.project_id = p.id
                ) AS technologies
         FROM projects p
         WHERE p.user_id = :user_id AND p.visibility = "publico"
         ORDER BY FIELD(p.status, "finalizado", "en_progreso", "idea", "pausado"), p.updated_at DESC'
    );
    $projectsStatement->execute(['user_id' => $userId]);
    $projects = $projectsStatement->fetchAll();

    $certificationsStatement = db()->prepare(
        'SELECT *
         FROM certifications
         WHERE user_id = :user_id AND visibility = "publico"
         ORDER BY COALESCE(issued_at, created_at) DESC'
    );
    $certificationsStatement->execute(['user_id' => $userId]);
    $certifications = $certificationsStatement->fetchAll();

    $skillsStatement = db()->prepare(
        'SELECT s.name, us.proficiency, sc.type, sc.name AS category_name
         FROM user_skills us
         INNER JOIN skills s ON s.id = us.skill_id
         LEFT JOIN skill_categories sc ON sc.id = s.category_id
         WHERE us.user_id = :user_id
         ORDER BY sc.type ASC, FIELD(us.proficiency, "experto", "avanzado", "intermedio", "basico"), s.name ASC'
    );
    $skillsStatement->execute(['user_id' => $userId]);
    $skills = $skillsStatement->fetchAll();

    $educationStatement = db()->prepare(
        'SELECT *
         FROM education
         WHERE user_id = :user_id
         ORDER BY is_current DESC, COALESCE(end_date, start_date) DESC'
    );
    $educationStatement->execute(['user_id' => $userId]);
    $education = $educationStatement->fetchAll();

    $experienceStatement = db()->prepare(
        'SELECT *
         FROM experiences
         WHERE user_id = :user_id
         ORDER BY is_current DESC, COALESCE(end_date, start_date) DESC'
    );
    $experienceStatement->execute(['user_id' => $userId]);
    $experiences = $experienceStatement->fetchAll();

    $resumeStatement = db()->prepare(
        'SELECT *
         FROM resumes
         WHERE user_id = :user_id
         ORDER BY FIELD(status, "generado", "publicado", "borrador"), updated_at DESC, created_at DESC
         LIMIT 1'
    );
    $resumeStatement->execute(['user_id' => $userId]);
    $resume = $resumeStatement->fetch() ?: null;
}

require_once __DIR__ . '/../../../HTML/components/header.php';
require_once __DIR__ . '/../../../HTML/components/navbar.php';
?>

<main id="contenido-principal">
    <?php if (!$portfolio): ?>
        <section class="public-empty">
            <div class="container">
                <div class="empty-state">
                    <i class="bi bi-globe2" aria-hidden="true"></i>
                    <h1>Portafolio no disponible</h1>
                    <p>El enlace no existe, el estudiante aún no hizo público su portafolio o el slug no coincide con la URL configurada.</p>
                    <?php if (auth_check()): ?>
                        <a class="btn btn-outline-primary" href="<?= e(url_to('settings/index.php')); ?>">Revisar configuración</a>
                    <?php endif; ?>
                    <a class="btn btn-primary" href="<?= e(url_to('auth/login.php')); ?>">Iniciar sesión</a>
                </div>
            </div>
        </section>
    <?php else: ?>
        <?php
        $accentColor = resume_valid_color($portfolio['theme_color'] ?? '#4F46E5');
        $avatarUrl = public_upload_url($portfolio['avatar_path'] ?? null);
        $publicUrl = portfolio_public_url($portfolio['public_slug'] ?? null);
        $resumeUrl = url_to('portfolio/resume.php?slug=' . urlencode($portfolio['public_slug']));
        ?>
        <div class="public-portfolio-themed" style="--portfolio-accent: <?= e($accentColor); ?>;">
        <?php if ($isOwnerPreview): ?>
            <div class="container pt-3">
                <div class="alert alert-warning mb-0" role="status">
                    Vista previa privada: tu portafolio aún no es público. Actívalo en <a href="<?= e(url_to('settings/index.php')); ?>">Configuración</a> o en tu perfil.
                </div>
            </div>
        <?php endif; ?>
        <section class="portfolio-hero">
            <div class="container">
                <div class="portfolio-hero-grid">
                    <div>
                        <span class="eyebrow text-white">Portafolio académico</span>
                        <h1><?= e($portfolio['full_name']); ?></h1>
                        <p><?= e($portfolio['about_me'] ?? 'Estudiante con portafolio académico profesional, proyectos, certificaciones y habilidades.'); ?></p>
                        <div class="portfolio-hero-actions">
                            <a class="btn btn-light" href="#proyectos">Ver proyectos</a>
                            <a class="btn btn-outline-light" href="<?= e($resumeUrl); ?>" target="_blank" rel="noopener">Descargar CV</a>
                        </div>
                    </div>
                    <aside class="portfolio-profile-card">
                        <?php if ($avatarUrl !== ''): ?>
                            <img src="<?= e($avatarUrl); ?>" alt="Foto de <?= e($portfolio['full_name']); ?>" loading="lazy" width="120" height="120">
                        <?php else: ?>
                            <div class="profile-avatar profile-avatar-placeholder" aria-hidden="true"><?= e(strtoupper(substr($portfolio['full_name'], 0, 1))); ?></div>
                        <?php endif; ?>
                        <h2><?= e($portfolio['career'] ?? 'Estudiante'); ?></h2>
                        <p><?= e($portfolio['university_name'] ?? 'Institución pendiente'); ?></p>
                        <p class="small mb-0"><?= e($publicUrl); ?></p>
                    </aside>
                </div>
            </div>
        </section>

        <section class="portfolio-section">
            <div class="container">
                <div class="portfolio-summary-grid">
                    <article><strong><?= e((string) count($projects)); ?></strong><span>Proyectos públicos</span></article>
                    <article><strong><?= e((string) count($certifications)); ?></strong><span>Certificaciones</span></article>
                    <article><strong><?= e((string) count($skills)); ?></strong><span>Habilidades</span></article>
                    <article><strong><?= e((string) (count($education) + count($experiences))); ?></strong><span>Formación y experiencia</span></article>
                </div>
            </div>
        </section>

        <section id="proyectos" class="portfolio-section">
            <div class="container">
                <div class="section-heading">
                    <span class="eyebrow">Proyectos</span>
                    <h2>Proyectos destacados</h2>
                </div>
                <?php if (empty($projects)): ?>
                    <div class="empty-state"><i class="bi bi-kanban" aria-hidden="true"></i><h3>Sin proyectos públicos</h3><p>Este estudiante aún no publica proyectos.</p></div>
                <?php else: ?>
                    <div class="public-card-grid">
                        <?php foreach ($projects as $project): ?>
                            <article class="public-project-card">
                                <?php if (!empty($project['image_path'])): ?>
                                    <img src="<?= e(public_upload_url($project['image_path'])); ?>" alt="Portada de <?= e($project['title']); ?>" loading="lazy" width="640" height="360">
                                <?php endif; ?>
                                <div>
                                    <span class="project-category"><?= e($project['category'] ?? 'Proyecto'); ?></span>
                                    <h3><?= e($project['title']); ?></h3>
                                    <p><?= e($project['summary'] ?? $project['description'] ?? 'Proyecto académico documentado.'); ?></p>
                                    <?php if (!empty($project['technologies'])): ?>
                                        <div class="technology-list">
                                            <?php foreach (explode(', ', $project['technologies']) as $technology): ?>
                                                <span><?= e($technology); ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="project-links">
                                        <?php if (!empty($project['repository_url'])): ?><a class="btn btn-outline-primary btn-sm" href="<?= e($project['repository_url']); ?>" target="_blank" rel="noopener">GitHub</a><?php endif; ?>
                                        <?php if (!empty($project['demo_url'])): ?><a class="btn btn-primary btn-sm" href="<?= e($project['demo_url']); ?>" target="_blank" rel="noopener">Demo</a><?php endif; ?>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="portfolio-section portfolio-section-soft">
            <div class="container portfolio-two-column">
                <section>
                    <div class="section-heading">
                        <span class="eyebrow">Trayectoria</span>
                        <h2>Educación y experiencia</h2>
                    </div>
                    <div class="portfolio-list">
                        <?php foreach ($education as $item): ?>
                            <article>
                                <h3><?= e($item['degree']); ?></h3>
                                <p><?= e($item['institution_name']); ?> · <?= !empty($item['is_current']) ? 'Actualidad' : e($item['end_date'] ? date('m/Y', strtotime($item['end_date'])) : 'En curso'); ?></p>
                            </article>
                        <?php endforeach; ?>
                        <?php foreach ($experiences as $item): ?>
                            <article>
                                <h3><?= e($item['title']); ?></h3>
                                <p><?= e($item['organization']); ?> · <?= !empty($item['is_current']) ? 'Actualidad' : e($item['end_date'] ? date('m/Y', strtotime($item['end_date'])) : ucfirst((string) $item['type'])); ?></p>
                                <?php if (!empty($item['description'])): ?><p><?= e($item['description']); ?></p><?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                        <?php if (empty($education) && empty($experiences)): ?><p class="text-secondary">No hay trayectoria pública todavía.</p><?php endif; ?>
                    </div>
                </section>

                <section>
                    <div class="section-heading">
                        <span class="eyebrow">Certificaciones</span>
                        <h2>Logros verificables</h2>
                    </div>
                    <div class="portfolio-list">
                        <?php foreach ($certifications as $certification): ?>
                            <article>
                                <h3><?= e($certification['title']); ?></h3>
                                <p><?= e($certification['issuer']); ?><?= $certification['issued_at'] ? ' · ' . e(date('m/Y', strtotime($certification['issued_at']))) : ''; ?></p>
                                <div class="project-links">
                                    <?php if (!empty($certification['certificate_path'])): ?>
                                        <a class="btn btn-outline-primary btn-sm" href="<?= e(certificate_download_url((int) $certification['id'])); ?>" target="_blank" rel="noopener"><i class="bi bi-filetype-pdf" aria-hidden="true"></i> Ver PDF</a>
                                    <?php endif; ?>
                                    <?php if (!empty($certification['credential_url'])): ?>
                                        <a class="btn btn-outline-primary btn-sm" href="<?= e($certification['credential_url']); ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> Verificar en línea</a>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                        <?php if (empty($certifications)): ?><p class="text-secondary">No hay certificaciones públicas todavía.</p><?php endif; ?>
                    </div>
                </section>

                <section>
                    <div class="section-heading">
                        <span class="eyebrow">Habilidades</span>
                        <h2>Competencias</h2>
                    </div>
                    <?php if (empty($skills)): ?>
                        <p class="text-secondary">No hay habilidades registradas todavía.</p>
                    <?php else: ?>
                        <div class="portfolio-skill-cloud">
                            <?php foreach ($skills as $skill): ?>
                                <span><?= e($skill['name']); ?> · <?= e($skill['proficiency']); ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
        </section>

        <section id="contacto" class="portfolio-section">
            <div class="container portfolio-two-column">
                <section>
                    <div class="section-heading">
                        <span class="eyebrow">Contacto</span>
                        <h2>Conecta con <?= e(explode(' ', $portfolio['full_name'])[0]); ?></h2>
                    </div>
                    <ul class="portfolio-contact-list">
                        <li><i class="bi bi-envelope" aria-hidden="true"></i> <?= e($portfolio['email']); ?></li>
                        <?php if (!empty($portfolio['location'])): ?><li><i class="bi bi-geo-alt" aria-hidden="true"></i> <?= e($portfolio['location']); ?></li><?php endif; ?>
                        <?php if (!empty($portfolio['github_url'])): ?><li><i class="bi bi-github" aria-hidden="true"></i> <a href="<?= e($portfolio['github_url']); ?>" target="_blank" rel="noopener">GitHub</a></li><?php endif; ?>
                        <?php if (!empty($portfolio['linkedin_url'])): ?><li><i class="bi bi-linkedin" aria-hidden="true"></i> <a href="<?= e($portfolio['linkedin_url']); ?>" target="_blank" rel="noopener">LinkedIn</a></li><?php endif; ?>
                        <?php if (!empty($portfolio['instagram_url'])): ?><li><i class="bi bi-instagram" aria-hidden="true"></i> <a href="<?= e($portfolio['instagram_url']); ?>" target="_blank" rel="noopener">Instagram</a></li><?php endif; ?>
                    </ul>
                </section>

                <section class="dashboard-card">
                    <?php require __DIR__ . '/../../../HTML/components/flash.php'; ?>
                    <?php if ((int) $portfolio['allow_contact'] === 1): ?>
                        <form class="needs-validation" method="post" action="<?= e($publicUrl . '#contacto'); ?>" novalidate>
                            <?= csrf_field(); ?>
                            <div class="mb-3">
                                <label class="form-label" for="senderName">Tu nombre</label>
                                <input class="form-control" id="senderName" name="sender_name" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="senderEmail">Tu correo</label>
                                <input class="form-control" type="email" id="senderEmail" name="sender_email" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="subject">Asunto</label>
                                <input class="form-control" id="subject" name="subject" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="message">Mensaje</label>
                                <textarea class="form-control" id="message" name="message" rows="4" required></textarea>
                            </div>
                            <button class="btn btn-primary w-100" type="submit">Enviar mensaje</button>
                        </form>
                    <?php else: ?>
                        <p class="text-secondary mb-0">El contacto por formulario está desactivado.</p>
                    <?php endif; ?>
                </section>
            </div>
        </section>
        </div>
    <?php endif; ?>
</main>

<?php require_once __DIR__ . '/../../../HTML/components/footer.php'; ?>
