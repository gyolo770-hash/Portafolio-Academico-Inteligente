<?php
declare(strict_types=1);

$pageTitle = 'Panel principal';
$pageDescription = 'Vista base del panel del portafolio académico.';
$bodyClass = 'dashboard-page';
$activeItem = 'dashboard';

require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../helpers/portfolio.php';
require_auth();
$currentUser = auth_user();

if (($currentUser['role_name'] ?? '') === 'reclutador') {
    header('Location: ' . url_to('recruiter/index.php'));
    exit;
}

if (($currentUser['role_name'] ?? '') === 'administrador') {
    header('Location: ' . url_to('admin/index.php'));
    exit;
}

$userId = (int) $currentUser['id'];

function dashboard_count(string $sql, int $userId): int
{
    $statement = db()->prepare($sql);
    $statement->execute(['user_id' => $userId]);

    return (int) $statement->fetchColumn();
}

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

$stats = [
    [
        'label' => 'Proyectos',
        'value' => dashboard_count('SELECT COUNT(*) FROM projects WHERE user_id = :user_id', $userId),
        'icon' => 'bi-kanban',
        'tone' => 'primary',
    ],
    [
        'label' => 'Certificaciones',
        'value' => dashboard_count('SELECT COUNT(*) FROM certifications WHERE user_id = :user_id', $userId),
        'icon' => 'bi-award',
        'tone' => 'success',
    ],
    [
        'label' => 'Habilidades',
        'value' => dashboard_count('SELECT COUNT(*) FROM user_skills WHERE user_id = :user_id', $userId),
        'icon' => 'bi-stars',
        'tone' => 'info',
    ],
    [
        'label' => 'Visitas al portafolio',
        'value' => dashboard_count('SELECT COUNT(*) FROM portfolio_visits WHERE user_id = :user_id', $userId),
        'icon' => 'bi-graph-up-arrow',
        'tone' => 'warning',
    ],
];

$completionItems = [
    !empty($currentUser['full_name']),
    !empty($currentUser['email']),
    !empty($currentUser['username']),
    !empty($currentUser['avatar_path']),
    !empty($profile['career']),
    !empty($profile['university_name']),
    !empty($profile['about_me']),
    !empty($profile['phone']),
    !empty($profile['github_url']) || !empty($profile['linkedin_url']) || !empty($profile['portfolio_url']),
    $stats[0]['value'] > 0,
    $stats[1]['value'] > 0,
    $stats[2]['value'] > 0,
];
$completedItems = 0;
foreach ($completionItems as $itemCompleted) {
    if ($itemCompleted) {
        $completedItems++;
    }
}
$profileCompletion = (int) round(($completedItems / count($completionItems)) * 100);

$notificationsStatement = db()->prepare(
    'SELECT title, message, type, read_at, created_at
     FROM notifications
     WHERE user_id = :user_id
     ORDER BY read_at IS NOT NULL, created_at DESC
     LIMIT 4'
);
$notificationsStatement->execute(['user_id' => $userId]);
$notifications = $notificationsStatement->fetchAll();

$aiSuggestionsStatement = db()->prepare(
    'SELECT title, content, priority, category
     FROM recommendations
     WHERE user_id = :user_id AND is_completed = 0
     ORDER BY FIELD(priority, "alta", "media", "baja"), created_at DESC
     LIMIT 3'
);
$aiSuggestionsStatement->execute(['user_id' => $userId]);
$aiSuggestions = $aiSuggestionsStatement->fetchAll();

if (empty($notifications)) {
    $notifications = [
        [
            'title' => 'Completa tu perfil académico',
            'message' => 'Agrega tu descripción, contacto y redes profesionales para mejorar tu presencia pública.',
            'type' => 'info',
            'created_at' => date('Y-m-d H:i:s'),
        ],
        [
            'title' => 'Sube tu primer proyecto',
            'message' => 'Los proyectos ayudan a mostrar evidencia real de tus habilidades y experiencia.',
            'type' => 'exito',
            'created_at' => date('Y-m-d H:i:s'),
        ],
        [
            'title' => 'Prepara tus certificaciones',
            'message' => 'Guarda PDFs, credenciales y enlaces para fortalecer tu portafolio.',
            'type' => 'advertencia',
            'created_at' => date('Y-m-d H:i:s'),
        ],
    ];
}

$quickActions = [
    ['label' => 'Editar perfil', 'description' => 'Actualiza tus datos personales', 'icon' => 'bi-person-lines-fill', 'url' => url_to('profile/index.php')],
    ['label' => 'Nuevo proyecto', 'description' => 'Documenta una evidencia', 'icon' => 'bi-plus-square', 'url' => url_to('projects/index.php#formulario-proyecto')],
    ['label' => 'Agregar certificado', 'description' => 'Registra un logro académico', 'icon' => 'bi-patch-check', 'url' => url_to('certifications/index.php#formulario-certificacion')],
    ['label' => 'Crear CV', 'description' => 'Genera y exporta tu currículum', 'icon' => 'bi-file-earmark-text', 'url' => url_to('resume/index.php')],
    ['label' => 'Configuración', 'description' => 'Privacidad y accesibilidad', 'icon' => 'bi-gear', 'url' => url_to('settings/index.php')],
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
                <span class="eyebrow">Panel del estudiante</span>
                <h1>Hola, <?= e(explode(' ', trim($currentUser['full_name'] ?? 'Estudiante'))[0]); ?></h1>
                <p>Organiza tus logros, revisa tu progreso y continúa construyendo tu portafolio académico profesional.</p>
            </div>

            <div class="dashboard-user-card">
                <div class="avatar-circle" aria-hidden="true">
                    <?= e(strtoupper(substr($currentUser['full_name'] ?? 'E', 0, 1))); ?>
                </div>
                <div>
                    <strong><?= e($currentUser['full_name'] ?? 'Estudiante'); ?></strong>
                    <span><?= e($profile['career'] ?? 'Carrera por completar'); ?></span>
                </div>
            </div>
        </div>

        <div class="dashboard-grid">
            <section class="hero-card" aria-labelledby="portfolioProgressTitle">
                <div class="hero-card-content">
                    <span class="eyebrow text-white">Progreso del portafolio</span>
                    <h2 id="portfolioProgressTitle"><?= e((string) $profileCompletion); ?>% completo</h2>
                    <p>Completa tu perfil, agrega evidencias y mantén tu portafolio listo para becas, admisiones e internships.</p>

                    <div class="progress dashboard-progress" role="progressbar" aria-label="Completitud del perfil" aria-valuenow="<?= e((string) $profileCompletion); ?>" aria-valuemin="0" aria-valuemax="100">
                        <div class="progress-bar" style="width: <?= e((string) $profileCompletion); ?>%"></div>
                    </div>

                    <div class="hero-actions">
                        <a class="btn btn-light" href="<?= e(url_to('profile/index.php')); ?>">Completar perfil</a>
                        <a class="btn btn-outline-light" href="<?= e(portfolio_public_url($profile['public_slug'] ?? $currentUser['username'])); ?>" target="_blank" rel="noopener">Ver portafolio</a>
                    </div>
                </div>
            </section>

            <section class="profile-summary-card dashboard-card" aria-labelledby="profileSummaryTitle">
                <div class="card-heading">
                    <div>
                        <span class="eyebrow">Resumen</span>
                        <h2 id="profileSummaryTitle">Perfil académico</h2>
                    </div>
                    <span class="status-pill <?= empty($currentUser['email_verified_at']) ? 'warning' : 'success'; ?>">
                        <?= empty($currentUser['email_verified_at']) ? 'Correo pendiente' : 'Correo verificado'; ?>
                    </span>
                </div>

                <dl class="profile-list">
                    <div>
                        <dt>Escuela / Universidad</dt>
                        <dd><?= e($profile['university_name'] ?? 'Pendiente'); ?></dd>
                    </div>
                    <div>
                        <dt>Carrera</dt>
                        <dd><?= e($profile['career'] ?? 'Pendiente'); ?></dd>
                    </div>
                    <div>
                        <dt>Visibilidad</dt>
                        <dd><?= e(($profile['visibility'] ?? 'privado') === 'publico' ? 'Público' : 'Privado'); ?></dd>
                    </div>
                </dl>
            </section>
        </div>

        <section class="stats-grid" aria-label="Estadísticas del estudiante">
            <?php foreach ($stats as $stat): ?>
                <article class="stat-card">
                    <div class="stat-icon <?= e($stat['tone']); ?>">
                        <i class="bi <?= e($stat['icon']); ?>" aria-hidden="true"></i>
                    </div>
                    <div>
                        <span><?= e($stat['label']); ?></span>
                        <strong><?= e((string) $stat['value']); ?></strong>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>

        <div class="dashboard-columns">
            <section class="dashboard-card" aria-labelledby="quickActionsTitle">
                <div class="card-heading">
                    <div>
                        <span class="eyebrow">Acciones rápidas</span>
                        <h2 id="quickActionsTitle">Continúa avanzando</h2>
                    </div>
                </div>

                <div class="quick-actions-grid">
                    <?php foreach ($quickActions as $action): ?>
                        <a class="quick-action-card" href="<?= e($action['url']); ?>">
                            <span class="quick-action-icon">
                                <i class="bi <?= e($action['icon']); ?>" aria-hidden="true"></i>
                            </span>
                            <span>
                                <strong><?= e($action['label']); ?></strong>
                                <small><?= e($action['description']); ?></small>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="dashboard-card" aria-labelledby="notificationsTitle">
                <div class="card-heading">
                    <div>
                        <span class="eyebrow">Notificaciones</span>
                        <h2 id="notificationsTitle">Actividad reciente</h2>
                    </div>
                    <a class="small fw-bold" href="<?= e(url_to('notifications/index.php')); ?>">Ver todo</a>
                </div>

                <div class="notification-list">
                    <?php foreach ($notifications as $notification): ?>
                        <article class="notification-item <?= empty($notification['read_at']) ? 'unread' : ''; ?>">
                            <span class="notification-dot <?= e($notification['type']); ?>" aria-hidden="true"></span>
                            <div>
                                <h3><?= e($notification['title']); ?></h3>
                                <p><?= e($notification['message']); ?></p>
                                <time datetime="<?= e($notification['created_at']); ?>"><?= e(date('d/m/Y H:i', strtotime($notification['created_at']))); ?></time>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>

        <section class="dashboard-card mt-3" aria-labelledby="aiSuggestionsTitle">
            <div class="card-heading">
                <div>
                    <span class="eyebrow">Asesor IA</span>
                    <h2 id="aiSuggestionsTitle">Sugerencias inteligentes</h2>
                </div>
                <a class="small fw-bold" href="<?= e(url_to('advisor/index.php')); ?>">Abrir asesor</a>
            </div>

            <?php if (empty($aiSuggestions)): ?>
                <div class="empty-state">
                    <i class="bi bi-robot" aria-hidden="true"></i>
                    <h3>Genera tu primer plan</h3>
                    <p>El asesor IA analizará tu perfil, CV, proyectos, certificaciones y habilidades para darte recomendaciones.</p>
                    <a class="btn btn-primary mt-3" href="<?= e(url_to('advisor/index.php')); ?>">Generar recomendaciones</a>
                </div>
            <?php else: ?>
                <div class="recommendation-grid compact">
                    <?php foreach ($aiSuggestions as $suggestion): ?>
                        <article class="recommendation-card priority-<?= e($suggestion['priority']); ?>">
                            <span class="project-category"><?= e($suggestion['category']); ?> · <?= e($suggestion['priority']); ?></span>
                            <h3><?= e($suggestion['title']); ?></h3>
                            <p><?= e($suggestion['content']); ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <?php require __DIR__ . '/../../../HTML/components/mobile-nav.php'; ?>
    </section>
    </div>
</main>

<?php require_once __DIR__ . '/../../../HTML/components/footer.php'; ?>
