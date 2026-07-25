<?php
declare(strict_types=1);

$pageTitle = 'Asesor IA';
$pageDescription = 'Recomendaciones académicas personalizadas basadas en reglas y listas para IA externa.';
$bodyClass = 'dashboard-page advisor-page';
$activeItem = 'asesor';

require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../helpers/advisor.php';
require_student();

$currentUser = auth_user();
$userId = (int) $currentUser['id'];
$generated = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!rate_limit_attempt('advisor_actions', 12, 900)) {
        flash('danger', 'Demasiadas acciones en el asesor. Espera unos minutos antes de intentar nuevamente.');
        header('Location: ' . url_to('advisor/index.php'));
        exit;
    }

    if (!verify_csrf_token($_POST['_csrf_token'] ?? null)) {
        flash('danger', 'La sesión expiró. Intenta nuevamente.');
        header('Location: ' . url_to('advisor/index.php'));
        exit;
    }

    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'generate') {
            $generated = advisor_generate($userId);
            flash('success', 'Recomendaciones generadas correctamente.');
        }

        if ($action === 'complete') {
            db()->prepare('UPDATE recommendations SET is_completed = 1 WHERE id = :id AND user_id = :user_id')
                ->execute(['id' => (int) ($_POST['recommendation_id'] ?? 0), 'user_id' => $userId]);
            flash('success', 'Recomendación marcada como completada.');
        }

        if ($action === 'restore') {
            db()->prepare('UPDATE recommendations SET is_completed = 0 WHERE id = :id AND user_id = :user_id')
                ->execute(['id' => (int) ($_POST['recommendation_id'] ?? 0), 'user_id' => $userId]);
            flash('success', 'Recomendación restaurada.');
        }
    } catch (Throwable $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }

        error_log('Error en asesor IA: ' . $exception->getMessage());
        flash('danger', 'No se pudieron procesar las recomendaciones.');
    }

    header('Location: ' . url_to('advisor/index.php'));
    exit;
}

$context = advisor_collect_context($userId);
$score = advisor_profile_strength($context);
$config = advisor_config();
$provider = strtolower((string) ($config['provider'] ?? 'rules'));

$recommendationsStatement = db()->prepare(
    'SELECT *
     FROM recommendations
     WHERE user_id = :user_id
     ORDER BY is_completed ASC,
              FIELD(priority, "alta", "media", "baja"),
              created_at DESC'
);
$recommendationsStatement->execute(['user_id' => $userId]);
$recommendations = $recommendationsStatement->fetchAll();

$activeRecommendations = array_values(array_filter($recommendations, static function ($item) {
    return (int) $item['is_completed'] === 0;
}));
$completedRecommendations = array_values(array_filter($recommendations, static function ($item) {
    return (int) $item['is_completed'] === 1;
}));

$categoryLinks = [
    'perfil' => url_to('profile/index.php'),
    'cv' => url_to('resume/index.php'),
    'habilidades' => url_to('skills/index.php'),
    'proyectos' => url_to('projects/index.php'),
    'becas' => url_to('certifications/index.php'),
    'carrera' => url_to('profile/index.php#educacion'),
];

$priorityLabels = [
    'alta' => 'Alta',
    'media' => 'Media',
    'baja' => 'Baja',
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
                <span class="eyebrow">Asesor IA académico</span>
                <h1>Recomendaciones personalizadas</h1>
                <p>Motor basado en reglas que analiza perfil, proyectos, certificaciones, habilidades, CV, educación y visibilidad del portafolio.</p>
            </div>
            <form method="post" action="<?= e(url_to('advisor/index.php')); ?>">
                <?= csrf_field(); ?>
                <input type="hidden" name="action" value="generate">
                <button class="btn btn-primary" type="submit">
                    <i class="bi bi-stars" aria-hidden="true"></i> Generar recomendaciones
                </button>
            </form>
        </div>

        <div class="advisor-hero-grid">
            <section class="advisor-score-card" aria-labelledby="scoreTitle">
                <span class="eyebrow text-white">Puntaje de perfil</span>
                <h2 id="scoreTitle"><?= e((string) $score); ?>%</h2>
                <p>Este puntaje estima qué tan preparado está tu portafolio para becas, admisiones, internships y oportunidades profesionales.</p>
                <div class="progress dashboard-progress" role="progressbar" aria-label="Puntaje de perfil" aria-valuenow="<?= e((string) $score); ?>" aria-valuemin="0" aria-valuemax="100">
                    <div class="progress-bar" style="width: <?= e((string) $score); ?>%"></div>
                </div>
            </section>

            <section class="dashboard-card">
                <div class="card-heading">
                    <div>
                        <span class="eyebrow">Motor actual</span>
                        <h2><?= e(strtoupper($provider)); ?></h2>
                    </div>
                    <span class="status-pill success">Extensible</span>
                </div>
                <p class="text-secondary">
                    <?php if ($provider === 'gemini'): ?>
                        Gemini está activo usando la clave configurada en <code>.env</code>.
                    <?php elseif ($provider === 'openai'): ?>
                        OpenAI está activo usando la clave configurada en <code>.env</code>.
                    <?php else: ?>
                        Motor local basado en reglas. Cambia <code>AI_PROVIDER</code> en <code>.env</code> para usar Gemini u OpenAI.
                    <?php endif; ?>
                </p>
                <dl class="profile-list">
                    <div><dt>Proyectos</dt><dd><?= e((string) $context['counts']['projects']); ?></dd></div>
                    <div><dt>Certificaciones</dt><dd><?= e((string) $context['counts']['certifications']); ?></dd></div>
                    <div><dt>Habilidades</dt><dd><?= e((string) $context['counts']['skills']); ?></dd></div>
                </dl>
            </section>
        </div>

        <section class="dashboard-card" aria-labelledby="recommendationsTitle">
            <div class="card-heading">
                <div>
                    <span class="eyebrow">Plan de acción</span>
                    <h2 id="recommendationsTitle">Recomendaciones activas</h2>
                </div>
                <span class="status-pill warning"><?= e((string) count($activeRecommendations)); ?> pendientes</span>
            </div>

            <?php if (empty($activeRecommendations)): ?>
                <div class="empty-state">
                    <i class="bi bi-robot" aria-hidden="true"></i>
                    <h3>Aún no hay recomendaciones activas</h3>
                    <p>Genera recomendaciones para recibir un plan de mejora personalizado.</p>
                </div>
            <?php else: ?>
                <div class="recommendation-grid">
                    <?php foreach ($activeRecommendations as $recommendation): ?>
                        <article class="recommendation-card priority-<?= e($recommendation['priority']); ?>">
                            <div class="recommendation-card-head">
                                <div>
                                    <span class="project-category"><?= e($recommendation['category']); ?> · Prioridad <?= e($priorityLabels[$recommendation['priority']] ?? $recommendation['priority']); ?></span>
                                    <h3><?= e($recommendation['title']); ?></h3>
                                </div>
                                <i class="bi bi-lightbulb" aria-hidden="true"></i>
                            </div>
                            <p><?= e($recommendation['content']); ?></p>
                            <div class="project-links">
                                <a class="btn btn-outline-primary btn-sm" href="<?= e($categoryLinks[$recommendation['category']] ?? url_to('dashboard/index.php')); ?>">Ir al módulo</a>
                                <form method="post" action="<?= e(url_to('advisor/index.php')); ?>">
                                    <?= csrf_field(); ?>
                                    <input type="hidden" name="action" value="complete">
                                    <input type="hidden" name="recommendation_id" value="<?= e((string) $recommendation['id']); ?>">
                                    <button class="btn btn-primary btn-sm" type="submit">Marcar completada</button>
                                </form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <?php if (!empty($completedRecommendations)): ?>
            <section class="dashboard-card mt-3" aria-labelledby="completedTitle">
                <div class="card-heading">
                    <div>
                        <span class="eyebrow">Historial</span>
                        <h2 id="completedTitle">Recomendaciones completadas</h2>
                    </div>
                    <span class="status-pill success"><?= e((string) count($completedRecommendations)); ?> completadas</span>
                </div>
                <div class="recommendation-grid compact">
                    <?php foreach ($completedRecommendations as $recommendation): ?>
                        <article class="recommendation-card completed">
                            <h3><?= e($recommendation['title']); ?></h3>
                            <p><?= e($recommendation['content']); ?></p>
                            <form method="post" action="<?= e(url_to('advisor/index.php')); ?>">
                                <?= csrf_field(); ?>
                                <input type="hidden" name="action" value="restore">
                                <input type="hidden" name="recommendation_id" value="<?= e((string) $recommendation['id']); ?>">
                                <button class="btn btn-outline-primary btn-sm" type="submit">Restaurar</button>
                            </form>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php require __DIR__ . '/../../../HTML/components/mobile-nav.php'; ?>
    </section>
    </div>
</main>

<?php require_once __DIR__ . '/../../../HTML/components/footer.php'; ?>
