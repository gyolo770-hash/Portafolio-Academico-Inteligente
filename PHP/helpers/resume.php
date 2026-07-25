<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/upload.php';

if (!function_exists('resume_templates')) {
    function resume_templates(): array
    {
        return [
            'profesional' => [
                'name' => 'Profesional',
                'description' => 'Diseño limpio para becas, admisiones e internships.',
                'icon' => 'bi-briefcase',
            ],
            'moderno' => [
                'name' => 'Moderno',
                'description' => 'Layout visual con barra lateral y secciones compactas.',
                'icon' => 'bi-columns-gap',
            ],
            'creativo' => [
                'name' => 'Creativo',
                'description' => 'Presentación llamativa para proyectos y portafolios.',
                'icon' => 'bi-palette',
            ],
        ];
    }
}

if (!function_exists('resume_valid_template')) {
    function resume_valid_template(string $template): string
    {
        return array_key_exists($template, resume_templates()) ? $template : 'profesional';
    }
}

if (!function_exists('resume_valid_color')) {
    function resume_valid_color(string $color): string
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? strtoupper($color) : '#4F46E5';
    }
}

if (!function_exists('resume_collect_data')) {
    function resume_collect_data(int $userId, bool $publicOnly = false): array
    {
        $userStatement = db()->prepare(
            'SELECT u.*, up.about_me, up.career, up.graduation_year, up.phone, up.location,
                    up.github_url, up.linkedin_url, up.portfolio_url, up.instagram_url, up.languages,
                    un.name AS university_name
             FROM users u
             LEFT JOIN user_profiles up ON up.user_id = u.id
             LEFT JOIN universities un ON un.id = up.university_id
             WHERE u.id = :user_id
             LIMIT 1'
        );
        $userStatement->execute(['user_id' => $userId]);
        $user = $userStatement->fetch() ?: [];

        $educationStatement = db()->prepare(
            'SELECT *
             FROM education
             WHERE user_id = :user_id
             ORDER BY is_current DESC, COALESCE(end_date, start_date) DESC, created_at DESC'
        );
        $educationStatement->execute(['user_id' => $userId]);

        $experienceStatement = db()->prepare(
            'SELECT *
             FROM experiences
             WHERE user_id = :user_id
             ORDER BY is_current DESC, COALESCE(end_date, start_date) DESC, created_at DESC
             LIMIT 6'
        );
        $experienceStatement->execute(['user_id' => $userId]);

        $projectVisibilitySql = $publicOnly ? ' AND p.visibility = "publico"' : '';
        $projectsStatement = db()->prepare(
            'SELECT p.*,
                    (
                        SELECT GROUP_CONCAT(s.name ORDER BY s.name SEPARATOR ", ")
                        FROM project_skills ps
                        INNER JOIN skills s ON s.id = ps.skill_id
                        WHERE ps.project_id = p.id
                    ) AS technologies
             FROM projects p
             WHERE p.user_id = :user_id' . $projectVisibilitySql . '
             ORDER BY FIELD(p.status, "finalizado", "en_progreso", "idea", "pausado"), p.updated_at DESC
             LIMIT 6'
        );
        $projectsStatement->execute(['user_id' => $userId]);

        $certificationVisibilitySql = $publicOnly ? ' AND visibility = "publico"' : '';
        $certificationsStatement = db()->prepare(
            'SELECT *
             FROM certifications
             WHERE user_id = :user_id' . $certificationVisibilitySql . '
             ORDER BY COALESCE(issued_at, created_at) DESC
             LIMIT 8'
        );
        $certificationsStatement->execute(['user_id' => $userId]);

        $skillsStatement = db()->prepare(
            'SELECT s.name, us.proficiency, sc.type, sc.name AS category_name
             FROM user_skills us
             INNER JOIN skills s ON s.id = us.skill_id
             LEFT JOIN skill_categories sc ON sc.id = s.category_id
             WHERE us.user_id = :user_id
             ORDER BY sc.type ASC, FIELD(us.proficiency, "experto", "avanzado", "intermedio", "basico"), s.name ASC'
        );
        $skillsStatement->execute(['user_id' => $userId]);

        return [
            'user' => $user,
            'education' => $educationStatement->fetchAll(),
            'experiences' => $experienceStatement->fetchAll(),
            'projects' => $projectsStatement->fetchAll(),
            'certifications' => $certificationsStatement->fetchAll(),
            'skills' => $skillsStatement->fetchAll(),
        ];
    }
}

if (!function_exists('resume_date_range')) {
    function resume_date_range(?string $startDate, ?string $endDate, bool $current = false): string
    {
        $start = $startDate ? date('m/Y', strtotime($startDate)) : 'Inicio pendiente';
        $end = $current ? 'Actualidad' : ($endDate ? date('m/Y', strtotime($endDate)) : 'Sin fecha final');

        return $start . ' - ' . $end;
    }
}

if (!function_exists('resume_render')) {
    function resume_render(array $data, string $template, string $accentColor): string
    {
        $template = resume_valid_template($template);
        $accentColor = resume_valid_color($accentColor);
        $user = $data['user'];
        $avatarUrl = public_upload_url($user['avatar_path'] ?? null);
        $languages = array_filter(array_map('trim', explode(',', (string) ($user['languages'] ?? ''))));

        ob_start();
        ?>
        <article class="resume-document resume-template-<?= e($template); ?>" style="--resume-accent: <?= e($accentColor); ?>;">
            <header class="resume-header">
                <?php if ($avatarUrl !== ''): ?>
                    <img class="resume-avatar" src="<?= e($avatarUrl); ?>" alt="Foto de <?= e($user['full_name'] ?? 'Estudiante'); ?>">
                <?php endif; ?>
                <div>
                    <p class="resume-kicker"><?= e($user['career'] ?? 'Estudiante'); ?></p>
                    <h1><?= e($user['full_name'] ?? 'Estudiante'); ?></h1>
                    <p><?= e($user['about_me'] ?? 'Estudiante enfocado en construir un portafolio académico profesional con proyectos, habilidades y certificaciones.'); ?></p>
                    <ul class="resume-contact">
                        <li><?= e($user['email'] ?? ''); ?></li>
                        <?php if (!empty($user['phone'])): ?><li><?= e($user['phone']); ?></li><?php endif; ?>
                        <?php if (!empty($user['location'])): ?><li><?= e($user['location']); ?></li><?php endif; ?>
                        <?php if (!empty($user['github_url'])): ?><li><?= e($user['github_url']); ?></li><?php endif; ?>
                        <?php if (!empty($user['linkedin_url'])): ?><li><?= e($user['linkedin_url']); ?></li><?php endif; ?>
                    </ul>
                </div>
            </header>

            <div class="resume-body">
                <section>
                    <h2>Educación</h2>
                    <?php if (empty($data['education'])): ?>
                        <p class="resume-muted">Agrega tu educación para completar esta sección.</p>
                    <?php else: ?>
                        <?php foreach ($data['education'] as $education): ?>
                            <div class="resume-item">
                                <h3><?= e($education['degree']); ?></h3>
                                <p class="resume-meta"><?= e($education['institution_name']); ?> · <?= e(resume_date_range($education['start_date'], $education['end_date'], (bool) $education['is_current'])); ?></p>
                                <?php if (!empty($education['field_of_study'])): ?><p><?= e($education['field_of_study']); ?></p><?php endif; ?>
                                <?php if (!empty($education['description'])): ?><p><?= e($education['description']); ?></p><?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </section>

                <section>
                    <h2>Proyectos destacados</h2>
                    <?php if (empty($data['projects'])): ?>
                        <p class="resume-muted">Agrega proyectos para mostrar evidencias de tu trabajo.</p>
                    <?php else: ?>
                        <?php foreach ($data['projects'] as $project): ?>
                            <div class="resume-item">
                                <h3><?= e($project['title']); ?></h3>
                                <p class="resume-meta"><?= e($project['category'] ?? 'Proyecto académico'); ?> · <?= e($project['status']); ?></p>
                                <p><?= e($project['summary'] ?? $project['description'] ?? 'Proyecto documentado en el portafolio académico.'); ?></p>
                                <?php if (!empty($project['technologies'])): ?><p><strong>Tecnologías:</strong> <?= e($project['technologies']); ?></p><?php endif; ?>
                                <?php if (!empty($project['repository_url'])): ?><p><strong>Repositorio:</strong> <?= e($project['repository_url']); ?></p><?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </section>

                <section>
                    <h2>Experiencia</h2>
                    <?php if (empty($data['experiences'])): ?>
                        <p class="resume-muted">Agrega prácticas, voluntariados o actividades relevantes.</p>
                    <?php else: ?>
                        <?php foreach ($data['experiences'] as $experience): ?>
                            <div class="resume-item">
                                <h3><?= e($experience['title']); ?></h3>
                                <p class="resume-meta"><?= e($experience['organization']); ?> · <?= e(resume_date_range($experience['start_date'], $experience['end_date'], (bool) $experience['is_current'])); ?></p>
                                <?php if (!empty($experience['description'])): ?><p><?= e($experience['description']); ?></p><?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </section>

                <aside class="resume-side">
                    <section>
                        <h2>Habilidades</h2>
                        <?php if (empty($data['skills'])): ?>
                            <p class="resume-muted">Agrega habilidades para completar esta sección.</p>
                        <?php else: ?>
                            <div class="resume-tags">
                                <?php foreach ($data['skills'] as $skill): ?>
                                    <span><?= e($skill['name']); ?> · <?= e($skill['proficiency']); ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>

                    <section>
                        <h2>Certificaciones</h2>
                        <?php if (empty($data['certifications'])): ?>
                            <p class="resume-muted">Agrega certificaciones para respaldar tus competencias.</p>
                        <?php else: ?>
                            <?php foreach ($data['certifications'] as $certification): ?>
                                <div class="resume-item compact">
                                    <h3><?= e($certification['title']); ?></h3>
                                    <p class="resume-meta"><?= e($certification['issuer']); ?><?= $certification['issued_at'] ? ' · ' . e(date('m/Y', strtotime($certification['issued_at']))) : ''; ?></p>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </section>

                    <?php if (!empty($languages)): ?>
                        <section>
                            <h2>Idiomas</h2>
                            <div class="resume-tags">
                                <?php foreach ($languages as $language): ?>
                                    <span><?= e($language); ?></span>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    <?php endif; ?>
                </aside>
            </div>
        </article>
        <?php
        return (string) ob_get_clean();
    }
}
