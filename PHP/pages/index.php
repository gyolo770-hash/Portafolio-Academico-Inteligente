<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../helpers/auth.php';

if (auth_check()) {
    require_once __DIR__ . '/../helpers/navigation.php';
    header('Location: ' . nav_post_login_url($_SESSION['user_role'] ?? 'estudiante'));
    exit;
}

$pageTitle = 'Portafolio Académico Inteligente';
$pageDescription = 'Organiza proyectos, certificaciones, habilidades y CV en una plataforma profesional para estudiantes, reclutadores y universidades.';
$bodyClass = 'landing-page';

require_once __DIR__ . '/../../HTML/components/header.php';
require_once __DIR__ . '/../../HTML/components/navbar.php';
?>

<main id="contenido-principal">
    <section class="landing-hero">
        <div class="container">
            <div class="landing-hero-grid">
                <div class="landing-hero-copy">
                    <span class="eyebrow">Plataforma académica SaaS</span>
                    <h1>Construye tu futuro académico</h1>
                    <p>Organiza proyectos, certificaciones y habilidades en un portafolio profesional con diseño moderno y accesible, listo para universidades y reclutadores.</p>
                    <div class="landing-hero-actions">
                        <a class="btn btn-primary btn-lg" href="<?= e(url_to('auth/register.php')); ?>">Comenzar gratis</a>
                        <a class="btn btn-outline-primary btn-lg" href="<?= e(url_to('auth/login.php')); ?>">Iniciar sesión</a>
                    </div>
                </div>

                <div class="landing-hero-panel" aria-hidden="true">
                    <article class="landing-preview-card">
                        <div class="landing-preview-top">
                            <?php $logoVariant = 'compact'; $showBrandText = false; require __DIR__ . '/../../HTML/components/logo-brand.php'; ?>
                            <div>
                                <strong>Perfil académico</strong>
                                <span>92% completado</span>
                            </div>
                        </div>
                        <div class="landing-preview-stats">
                            <div><strong>12</strong><span>Proyectos</span></div>
                            <div><strong>8</strong><span>Certificados</span></div>
                            <div><strong>24</strong><span>Habilidades</span></div>
                        </div>
                        <div class="landing-preview-list">
                            <span class="skill-pill">PHP</span>
                            <span class="skill-pill">UX Research</span>
                            <span class="skill-pill">Liderazgo</span>
                        </div>
                    </article>
                </div>
            </div>
        </div>
    </section>

    <section class="landing-features container">
        <div class="landing-section-heading">
            <span class="eyebrow">Funcionalidades</span>
            <h2>Todo lo que necesitas para destacar académicamente</h2>
        </div>
        <div class="landing-feature-grid">
            <article class="landing-feature-card">
                <i class="bi bi-kanban" aria-hidden="true"></i>
                <h3>Proyectos con evidencia</h3>
                <p>Documenta resultados, tecnologías, screenshots y archivos de respaldo.</p>
            </article>
            <article class="landing-feature-card">
                <i class="bi bi-award" aria-hidden="true"></i>
                <h3>Certificaciones verificables</h3>
                <p>Sube PDFs, credenciales y URLs de validación para respaldar tu perfil.</p>
            </article>
            <article class="landing-feature-card">
                <i class="bi bi-file-earmark-text" aria-hidden="true"></i>
                <h3>Constructor de CV</h3>
                <p>Genera currículums con plantillas profesionales y vista previa instantánea.</p>
            </article>
            <article class="landing-feature-card">
                <i class="bi bi-robot" aria-hidden="true"></i>
                <h3>Asesor IA</h3>
                <p>Recibe recomendaciones personalizadas para mejorar tu portafolio académico.</p>
            </article>
            <article class="landing-feature-card">
                <i class="bi bi-search" aria-hidden="true"></i>
                <h3>Portal de reclutadores</h3>
                <p>Permite que empleadores descubran talento con portafolios públicos.</p>
            </article>
            <article class="landing-feature-card">
                <i class="bi bi-shield-lock" aria-hidden="true"></i>
                <h3>Seguridad y privacidad</h3>
                <p>Controla visibilidad, sesiones seguras, CSRF y validación de archivos.</p>
            </article>
        </div>
    </section>

    <section class="landing-cta">
        <div class="container">
            <div class="landing-cta-card">
                <div>
                    <span class="eyebrow">Empieza hoy</span>
                    <h2>Convierte tu trayectoria académica en oportunidades reales</h2>
                    <p>Regístrate en minutos y publica tu portafolio con una URL profesional.</p>
                </div>
                <a class="btn btn-primary btn-lg" href="<?= e(url_to('auth/register.php')); ?>">Crear mi portafolio</a>
            </div>
        </div>
    </section>
</main>

<?php require_once __DIR__ . '/../../HTML/components/footer.php'; ?>
