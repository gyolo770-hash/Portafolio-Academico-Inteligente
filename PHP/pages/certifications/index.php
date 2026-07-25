<?php
declare(strict_types=1);

$pageTitle = 'Mis certificaciones';
$pageDescription = 'Gestiona certificaciones académicas, PDFs, credenciales y categorías.';
$bodyClass = 'dashboard-page certifications-page';
$activeItem = 'certificados';
$pageScript = 'auth.js';

require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../helpers/upload.php';
require_once __DIR__ . '/../../helpers/categories.php';
require_student();

$currentUser = auth_user();
$userId = (int) $currentUser['id'];

$categoryOptions = certification_category_options();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['_csrf_token'] ?? null)) {
        flash('danger', 'La sesión expiró. Intenta guardar la certificación nuevamente.');
        header('Location: ' . url_to('certifications/index.php'));
        exit;
    }

    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'save_certification') {
            $certificationId = (int) ($_POST['certification_id'] ?? 0);
            $title = trim((string) ($_POST['title'] ?? ''));
            $issuer = trim((string) ($_POST['issuer'] ?? ''));
            $category = trim((string) ($_POST['category'] ?? ''));
            $credentialId = trim((string) ($_POST['credential_id'] ?? ''));
            $credentialUrl = trim((string) ($_POST['credential_url'] ?? ''));
            $issuedAt = trim((string) ($_POST['issued_at'] ?? ''));
            $expiresAt = trim((string) ($_POST['expires_at'] ?? ''));
            $visibility = ($_POST['visibility'] ?? 'privado') === 'publico' ? 'publico' : 'privado';

            $errors = [];

            if ($title === '') {
                $errors[] = 'El nombre de la certificación es obligatorio.';
            }

            if ($issuer === '') {
                $errors[] = 'La institución emisora es obligatoria.';
            }

            if ($credentialUrl !== '' && !filter_var($credentialUrl, FILTER_VALIDATE_URL)) {
                $errors[] = 'La URL de credencial no es válida.';
            }

            if (!empty($errors)) {
                foreach ($errors as $error) {
                    flash('danger', $error);
                }

                header('Location: ' . url_to('certifications/index.php' . ($certificationId > 0 ? '?edit=' . $certificationId : '')));
                exit;
            }

            $certificatePath = upload_certificate_pdf($_FILES['certificate_pdf'] ?? [], $userId);

            if ($certificationId > 0) {
                $currentStatement = db()->prepare('SELECT certificate_path FROM certifications WHERE id = :id AND user_id = :user_id LIMIT 1');
                $currentStatement->execute(['id' => $certificationId, 'user_id' => $userId]);
                $currentCertification = $currentStatement->fetch();

                if (!$currentCertification) {
                    flash('danger', 'La certificación que intentas editar no existe.');
                    header('Location: ' . url_to('certifications/index.php'));
                    exit;
                }

                db()->prepare(
                    'UPDATE certifications
                     SET title = :title,
                         issuer = :issuer,
                         category = :category,
                         credential_id = :credential_id,
                         credential_url = :credential_url,
                         certificate_path = :certificate_path,
                         issued_at = :issued_at,
                         expires_at = :expires_at,
                         visibility = :visibility
                     WHERE id = :id AND user_id = :user_id'
                )->execute([
                    'title' => $title,
                    'issuer' => $issuer,
                    'category' => $category !== '' ? $category : null,
                    'credential_id' => $credentialId !== '' ? $credentialId : null,
                    'credential_url' => $credentialUrl !== '' ? $credentialUrl : null,
                    'certificate_path' => $certificatePath ?: $currentCertification['certificate_path'],
                    'issued_at' => $issuedAt !== '' ? $issuedAt : null,
                    'expires_at' => $expiresAt !== '' ? $expiresAt : null,
                    'visibility' => $visibility,
                    'id' => $certificationId,
                    'user_id' => $userId,
                ]);

                flash('success', 'Certificación actualizada correctamente.');
            } else {
                db()->prepare(
                    'INSERT INTO certifications (user_id, title, issuer, category, credential_id, credential_url, certificate_path, issued_at, expires_at, visibility)
                     VALUES (:user_id, :title, :issuer, :category, :credential_id, :credential_url, :certificate_path, :issued_at, :expires_at, :visibility)'
                )->execute([
                    'user_id' => $userId,
                    'title' => $title,
                    'issuer' => $issuer,
                    'category' => $category !== '' ? $category : null,
                    'credential_id' => $credentialId !== '' ? $credentialId : null,
                    'credential_url' => $credentialUrl !== '' ? $credentialUrl : null,
                    'certificate_path' => $certificatePath,
                    'issued_at' => $issuedAt !== '' ? $issuedAt : null,
                    'expires_at' => $expiresAt !== '' ? $expiresAt : null,
                    'visibility' => $visibility,
                ]);

                flash('success', 'Certificación agregada correctamente.');
            }
        }

        if ($action === 'delete_certification') {
            $certificationId = (int) ($_POST['certification_id'] ?? 0);
            $pathStatement = db()->prepare(
                'SELECT certificate_path FROM certifications WHERE id = :id AND user_id = :user_id LIMIT 1'
            );
            $pathStatement->execute(['id' => $certificationId, 'user_id' => $userId]);
            $certificatePath = $pathStatement->fetchColumn();

            db()->prepare('DELETE FROM certifications WHERE id = :id AND user_id = :user_id')
                ->execute(['id' => $certificationId, 'user_id' => $userId]);

            if ($certificatePath) {
                delete_upload_file((string) $certificatePath);
            }

            flash('success', 'Certificación eliminada correctamente.');
        }

        if ($action === 'remove_pdf') {
            $certificationId = (int) ($_POST['certification_id'] ?? 0);
            $pathStatement = db()->prepare(
                'SELECT certificate_path FROM certifications WHERE id = :id AND user_id = :user_id LIMIT 1'
            );
            $pathStatement->execute(['id' => $certificationId, 'user_id' => $userId]);
            $certificatePath = $pathStatement->fetchColumn();

            db()->prepare('UPDATE certifications SET certificate_path = NULL WHERE id = :id AND user_id = :user_id')
                ->execute(['id' => $certificationId, 'user_id' => $userId]);

            if ($certificatePath) {
                delete_upload_file((string) $certificatePath);
            }

            flash('success', 'PDF eliminado de la certificación.');
        }
    } catch (Throwable $exception) {
        error_log('Error en módulo de certificaciones: ' . $exception->getMessage());
        flash('danger', $exception->getMessage() ?: 'No se pudo guardar la certificación.');
    }

    header('Location: ' . url_to('certifications/index.php'));
    exit;
}

$editCertification = null;
if (isset($_GET['edit'])) {
    $editStatement = db()->prepare('SELECT * FROM certifications WHERE id = :id AND user_id = :user_id LIMIT 1');
    $editStatement->execute([
        'id' => (int) $_GET['edit'],
        'user_id' => $userId,
    ]);
    $editCertification = $editStatement->fetch() ?: null;
}

$certificationsStatement = db()->prepare(
    'SELECT *
     FROM certifications
     WHERE user_id = :user_id
     ORDER BY COALESCE(issued_at, created_at) DESC, created_at DESC'
);
$certificationsStatement->execute(['user_id' => $userId]);
$certifications = $certificationsStatement->fetchAll();

$stats = [
    'total' => count($certifications),
    'with_pdf' => 0,
    'publico' => 0,
    'vigentes' => 0,
];

foreach ($certifications as $certification) {
    if (!empty($certification['certificate_path'])) {
        $stats['with_pdf']++;
    }

    if ($certification['visibility'] === 'publico') {
        $stats['publico']++;
    }

    if (empty($certification['expires_at']) || strtotime($certification['expires_at']) >= strtotime(date('Y-m-d'))) {
        $stats['vigentes']++;
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
                <span class="eyebrow">Certificaciones</span>
                <h1>Mis certificaciones</h1>
                <p>Registra certificados, archivos PDF, credenciales verificables y categorías para fortalecer tu perfil académico.</p>
            </div>
            <a class="btn btn-primary" href="#formulario-certificacion">Agregar certificación</a>
        </div>

        <section class="stats-grid" aria-label="Resumen de certificaciones">
            <article class="stat-card">
                <div class="stat-icon primary"><i class="bi bi-award" aria-hidden="true"></i></div>
                <div><span>Total</span><strong><?= e((string) $stats['total']); ?></strong></div>
            </article>
            <article class="stat-card">
                <div class="stat-icon info"><i class="bi bi-filetype-pdf" aria-hidden="true"></i></div>
                <div><span>Con PDF</span><strong><?= e((string) $stats['with_pdf']); ?></strong></div>
            </article>
            <article class="stat-card">
                <div class="stat-icon success"><i class="bi bi-shield-check" aria-hidden="true"></i></div>
                <div><span>Vigentes</span><strong><?= e((string) $stats['vigentes']); ?></strong></div>
            </article>
            <article class="stat-card">
                <div class="stat-icon warning"><i class="bi bi-globe2" aria-hidden="true"></i></div>
                <div><span>Públicas</span><strong><?= e((string) $stats['publico']); ?></strong></div>
            </article>
        </section>

        <div class="certifications-layout">
            <section id="formulario-certificacion" class="dashboard-card" aria-labelledby="certificationFormTitle">
                <div class="card-heading">
                    <div>
                        <span class="eyebrow">Formulario</span>
                        <h2 id="certificationFormTitle"><?= $editCertification ? 'Editar certificación' : 'Agregar certificación'; ?></h2>
                    </div>
                    <?php if ($editCertification): ?>
                        <a class="small fw-bold" href="<?= e(url_to('certifications/index.php#formulario-certificacion')); ?>">Cancelar edición</a>
                    <?php endif; ?>
                </div>

                <form class="needs-validation certification-form" method="post" action="<?= e(url_to('certifications/index.php')); ?>" enctype="multipart/form-data" novalidate>
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action" value="save_certification">
                    <input type="hidden" name="certification_id" value="<?= e((string) ($editCertification['id'] ?? 0)); ?>">

                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label" for="title">Nombre de la certificación</label>
                            <input class="form-control" type="text" id="title" name="title" value="<?= e($editCertification['title'] ?? ''); ?>" required>
                            <div class="invalid-feedback">Ingresa el nombre de la certificación.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="issuer">Institución emisora</label>
                            <input class="form-control" type="text" id="issuer" name="issuer" value="<?= e($editCertification['issuer'] ?? ''); ?>" placeholder="Google, Cisco, Microsoft..." required>
                            <div class="invalid-feedback">Ingresa la institución emisora.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="category">Categoría</label>
                            <input class="form-control" list="categoryOptions" id="category" name="category" value="<?= e($editCertification['category'] ?? ''); ?>" placeholder="Selecciona o escribe una categoría">
                            <datalist id="categoryOptions">
                                <?php foreach ($categoryOptions as $category): ?>
                                    <option value="<?= e($category); ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="credentialId">ID de credencial</label>
                            <input class="form-control" type="text" id="credentialId" name="credential_id" value="<?= e($editCertification['credential_id'] ?? ''); ?>" placeholder="ABC-123-XYZ">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="credentialUrl">URL de verificación externa</label>
                            <input class="form-control" type="url" id="credentialUrl" name="credential_url" value="<?= e($editCertification['credential_url'] ?? ''); ?>" placeholder="https://credly.com/...">
                            <div class="form-text">Opcional. Enlace de la plataforma emisora (Credly, Google, Microsoft). El PDF subido se abre con el botón «Ver PDF».</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="issuedAt">Fecha de emisión</label>
                            <input class="form-control" type="date" id="issuedAt" name="issued_at" value="<?= e($editCertification['issued_at'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="expiresAt">Fecha de vencimiento</label>
                            <input class="form-control" type="date" id="expiresAt" name="expires_at" value="<?= e($editCertification['expires_at'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="visibility">Visibilidad</label>
                            <select class="form-select" id="visibility" name="visibility">
                                <option value="privado" <?= ($editCertification['visibility'] ?? 'privado') === 'privado' ? 'selected' : ''; ?>>Privado</option>
                                <option value="publico" <?= ($editCertification['visibility'] ?? '') === 'publico' ? 'selected' : ''; ?>>Público</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="certificatePdf">PDF del certificado</label>
                            <input class="form-control" type="file" id="certificatePdf" name="certificate_pdf" accept="application/pdf" data-file-preview="#certificateFileName">
                            <div id="certificateFileName" class="form-text text-muted">Sube un PDF de máximo 8 MB.</div>
                        </div>
                    </div>

                    <?php if (!empty($editCertification['certificate_path'])): ?>
                        <div class="certificate-current-file mt-4">
                            <i class="bi bi-file-earmark-pdf" aria-hidden="true"></i>
                            <div>
                                <strong>PDF actual</strong>
                                <a href="<?= e(certificate_download_url((int) $editCertification['id'])); ?>" target="_blank" rel="noopener">Abrir certificado PDF</a>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="d-flex flex-wrap gap-2 justify-content-end mt-4">
                        <button class="btn btn-primary" type="submit"><?= $editCertification ? 'Actualizar certificación' : 'Guardar certificación'; ?></button>
                    </div>
                </form>
            </section>

            <section class="dashboard-card" aria-labelledby="certificationListTitle">
                <div class="card-heading">
                    <div>
                        <span class="eyebrow">Evidencias</span>
                        <h2 id="certificationListTitle">Certificaciones registradas</h2>
                    </div>
                    <span class="status-pill success"><?= e((string) count($certifications)); ?> registros</span>
                </div>

                <?php if (empty($certifications)): ?>
                    <div class="empty-state">
                        <i class="bi bi-award" aria-hidden="true"></i>
                        <h3>Aún no agregas certificaciones</h3>
                        <p>Guarda tus certificados con PDF, URL de credencial e ID para respaldar tus habilidades.</p>
                    </div>
                <?php else: ?>
                    <div class="certification-list">
                        <?php foreach ($certifications as $certification): ?>
                            <?php
                            $isExpired = !empty($certification['expires_at']) && strtotime($certification['expires_at']) < strtotime(date('Y-m-d'));
                            ?>
                            <article class="certification-card">
                                <div class="certification-icon">
                                    <i class="bi bi-award" aria-hidden="true"></i>
                                </div>
                                <div class="certification-body">
                                    <div class="project-card-heading">
                                        <div>
                                            <span class="project-category"><?= e($certification['category'] ?? 'Sin categoría'); ?></span>
                                            <h3><?= e($certification['title']); ?></h3>
                                        </div>
                                        <span class="status-pill <?= $isExpired ? 'warning' : 'success'; ?>">
                                            <?= $isExpired ? 'Vencida' : 'Vigente'; ?>
                                        </span>
                                    </div>

                                    <p class="mb-2"><strong>Emisor:</strong> <?= e($certification['issuer']); ?></p>

                                    <div class="project-meta">
                                        <span><i class="bi bi-calendar-check" aria-hidden="true"></i> <?= e($certification['issued_at'] ? date('d/m/Y', strtotime($certification['issued_at'])) : 'Emisión pendiente'); ?></span>
                                        <span><i class="bi bi-calendar-x" aria-hidden="true"></i> <?= e($certification['expires_at'] ? date('d/m/Y', strtotime($certification['expires_at'])) : 'Sin vencimiento'); ?></span>
                                        <span><i class="bi bi-eye" aria-hidden="true"></i> <?= e($certification['visibility'] === 'publico' ? 'Pública' : 'Privada'); ?></span>
                                    </div>

                                    <?php if (!empty($certification['credential_id'])): ?>
                                        <p class="credential-code">ID: <?= e($certification['credential_id']); ?></p>
                                    <?php endif; ?>

                                    <div class="project-links">
                                        <?php if (!empty($certification['certificate_path'])): ?>
                                            <a class="btn btn-outline-primary btn-sm" href="<?= e(certificate_download_url((int) $certification['id'])); ?>" target="_blank" rel="noopener"><i class="bi bi-filetype-pdf" aria-hidden="true"></i> Ver PDF</a>
                                        <?php endif; ?>
                                        <?php if (!empty($certification['credential_url'])): ?>
                                            <a class="btn btn-outline-primary btn-sm" href="<?= e($certification['credential_url']); ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> Verificar en línea</a>
                                        <?php endif; ?>
                                        <a class="btn btn-primary btn-sm" href="<?= e(url_to('certifications/index.php?edit=' . $certification['id'] . '#formulario-certificacion')); ?>">Editar</a>
                                        <?php if (!empty($certification['certificate_path'])): ?>
                                            <form method="post" action="<?= e(url_to('certifications/index.php')); ?>" onsubmit="return confirm('¿Eliminar el PDF de esta certificación?');">
                                                <?= csrf_field(); ?>
                                                <input type="hidden" name="action" value="remove_pdf">
                                                <input type="hidden" name="certification_id" value="<?= e((string) $certification['id']); ?>">
                                                <button class="btn btn-outline-danger btn-sm" type="submit">Quitar PDF</button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="post" action="<?= e(url_to('certifications/index.php')); ?>" onsubmit="return confirm('¿Eliminar esta certificación?');">
                                            <?= csrf_field(); ?>
                                            <input type="hidden" name="action" value="delete_certification">
                                            <input type="hidden" name="certification_id" value="<?= e((string) $certification['id']); ?>">
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
