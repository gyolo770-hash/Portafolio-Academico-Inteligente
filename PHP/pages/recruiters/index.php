<?php
declare(strict_types=1);

$pageTitle = 'Directorio de reclutadores';
$pageDescription = 'Busca reclutadores activos y envíales un mensaje directo.';
$bodyClass = 'dashboard-page recruiters-page';
$activeItem = 'reclutadores';
$pageScript = 'auth.js';

require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../helpers/messages.php';
require_once __DIR__ . '/../../helpers/schema.php';
require_student();

ensure_runtime_schema();

$currentUser = auth_user();
$userId = (int) $currentUser['id'];
$query = trim((string) ($_GET['q'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['_csrf_token'] ?? null)) {
        flash('danger', 'La sesión expiró. Intenta enviar el mensaje nuevamente.');
        header('Location: ' . url_to('recruiters/index.php'));
        exit;
    }

    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'send_message') {
            $recruiterEmail = strtolower(trim((string) ($_POST['recruiter_email'] ?? '')));
            $subject = trim((string) ($_POST['subject'] ?? ''));
            $message = trim((string) ($_POST['message'] ?? ''));

            if ($subject === '' || $message === '') {
                flash('danger', 'El asunto y el mensaje son obligatorios.');
            } else {
                $recipientUserId = find_recruiter_user_id($recruiterEmail);

                if ($recipientUserId === null) {
                    flash('danger', 'No se encontró una cuenta activa de reclutador para ese contacto.');
                } else {
                    send_inbox_message($recipientUserId, $currentUser, $subject, $message);
                    flash('success', 'Mensaje enviado correctamente al reclutador.');
                }
            }
        }
    } catch (Throwable $exception) {
        error_log('Error al enviar mensaje a reclutador: ' . $exception->getMessage());
        flash('danger', $exception->getMessage() ?: 'No se pudo enviar el mensaje.');
    }

    header('Location: ' . url_to('recruiters/index.php' . ($query !== '' ? '?q=' . urlencode($query) : '')));
    exit;
}

$sql = 'SELECT r.id,
               r.company_name,
               r.contact_name,
               r.email,
               r.phone,
               r.website,
               r.status,
               u.id AS user_id
        FROM recruiters r
        INNER JOIN users u ON u.email = r.email
        INNER JOIN roles ro ON ro.id = u.role_id AND ro.name = "reclutador"
        WHERE r.status = "activo"
          AND u.status = "activo"
          AND r.accept_student_messages = 1';
$params = [];

if ($query !== '') {
    $sql .= ' AND (r.company_name LIKE :q_company OR r.contact_name LIKE :q_contact OR r.email LIKE :q_email)';
    $searchTerm = '%' . $query . '%';
    $params['q_company'] = $searchTerm;
    $params['q_contact'] = $searchTerm;
    $params['q_email'] = $searchTerm;
}

$sql .= ' ORDER BY r.company_name ASC, r.contact_name ASC LIMIT 60';

$statement = db()->prepare($sql);
$statement->execute($params);
$recruiters = $statement->fetchAll();

$selectedEmail = strtolower(trim((string) ($_GET['contact'] ?? '')));

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
                    <span class="eyebrow">Conexión profesional</span>
                    <h1>Buscar reclutadores</h1>
                    <p>Encuentra empresas y reclutadores activos, revisa su contacto y envíales un mensaje desde la plataforma.</p>
                </div>
            </div>

            <section class="dashboard-card recruiter-filter-card" aria-labelledby="recruiterSearchTitle">
                <div class="card-heading">
                    <div>
                        <span class="eyebrow">Búsqueda</span>
                        <h2 id="recruiterSearchTitle">Filtrar reclutadores</h2>
                    </div>
                    <span class="status-pill success"><?= e((string) count($recruiters)); ?> activos</span>
                </div>

                <form class="recruiter-filter-form" method="get" action="<?= e(url_to('recruiters/index.php')); ?>">
                    <div class="row g-3 align-items-end">
                        <div class="col-lg-9">
                            <label class="form-label" for="recruiterQuery">Buscar por empresa, contacto o correo</label>
                            <input class="form-control" id="recruiterQuery" name="q" value="<?= e($query); ?>" placeholder="Empresa Demo, reclutador, tecnología...">
                        </div>
                        <div class="col-lg-3 d-grid">
                            <button class="btn btn-primary" type="submit">Buscar</button>
                        </div>
                    </div>
                </form>
            </section>

            <div class="recruiters-layout">
                <section class="dashboard-card" aria-labelledby="recruiterListTitle">
                    <div class="card-heading">
                        <div>
                            <span class="eyebrow">Directorio</span>
                            <h2 id="recruiterListTitle">Reclutadores disponibles</h2>
                        </div>
                    </div>

                    <?php if (empty($recruiters)): ?>
                        <div class="empty-state">
                            <i class="bi bi-briefcase" aria-hidden="true"></i>
                            <h3>No hay reclutadores para esta búsqueda</h3>
                            <p>Prueba con otro término o vuelve más tarde.</p>
                        </div>
                    <?php else: ?>
                        <div class="recruiter-directory-list">
                            <?php foreach ($recruiters as $recruiter): ?>
                                <article class="recruiter-directory-card">
                                    <div>
                                        <span class="project-category"><?= e($recruiter['company_name']); ?></span>
                                        <h3><?= e($recruiter['contact_name']); ?></h3>
                                        <p class="mb-2 text-secondary"><?= e($recruiter['email']); ?><?= !empty($recruiter['phone']) ? ' · ' . e($recruiter['phone']) : ''; ?></p>
                                        <?php if (!empty($recruiter['website'])): ?>
                                            <a class="small fw-bold" href="<?= e($recruiter['website']); ?>" target="_blank" rel="noopener">Sitio web</a>
                                        <?php endif; ?>
                                    </div>
                                    <div class="project-links">
                                        <?php if (!empty($recruiter['user_id'])): ?>
                                            <a class="btn btn-primary btn-sm" href="<?= e(url_to('recruiters/index.php?contact=' . urlencode($recruiter['email']) . ($query !== '' ? '&q=' . urlencode($query) : '') . '#mensaje')); ?>">Enviar mensaje</a>
                                        <?php else: ?>
                                            <span class="status-pill warning">Sin cuenta</span>
                                        <?php endif; ?>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <aside id="mensaje" class="dashboard-card" aria-labelledby="messageFormTitle">
                    <div class="card-heading">
                        <div>
                            <span class="eyebrow">Mensajería</span>
                            <h2 id="messageFormTitle">Enviar mensaje</h2>
                        </div>
                    </div>

                    <form class="needs-validation" method="post" action="<?= e(url_to('recruiters/index.php')); ?>" novalidate>
                        <?= csrf_field(); ?>
                        <input type="hidden" name="action" value="send_message">

                        <div class="mb-3">
                            <label class="form-label" for="recruiterEmail">Correo del reclutador</label>
                            <input class="form-control" type="email" id="recruiterEmail" name="recruiter_email" value="<?= e($selectedEmail); ?>" list="recruiterEmails" required>
                            <datalist id="recruiterEmails">
                                <?php foreach ($recruiters as $recruiter): ?>
                                    <?php if (!empty($recruiter['user_id'])): ?>
                                        <option value="<?= e($recruiter['email']); ?>"><?= e($recruiter['company_name']); ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </datalist>
                            <div class="invalid-feedback">Selecciona o escribe el correo del reclutador.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="messageSubject">Asunto</label>
                            <input class="form-control" id="messageSubject" name="subject" value="Interés en oportunidades académicas" required>
                            <div class="invalid-feedback">Ingresa un asunto.</div>
                        </div>
                        <div class="mb-4">
                            <label class="form-label" for="messageBody">Mensaje</label>
                            <textarea class="form-control" id="messageBody" name="message" rows="5" required placeholder="Preséntate, menciona tu carrera, habilidades y el tipo de oportunidad que buscas."></textarea>
                            <div class="invalid-feedback">Escribe tu mensaje.</div>
                        </div>
                        <button class="btn btn-primary w-100" type="submit">Enviar mensaje</button>
                    </form>
                </aside>
            </div>

            <?php require __DIR__ . '/../../../HTML/components/mobile-nav.php'; ?>
        </section>
    </div>
</main>

<?php require_once __DIR__ . '/../../../HTML/components/footer.php'; ?>
