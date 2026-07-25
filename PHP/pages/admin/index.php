<?php
declare(strict_types=1);

$pageTitle = 'Administración';
$pageDescription = 'Panel administrativo para usuarios, universidades, reportes, analíticas, anuncios, moderación y configuración.';
$bodyClass = 'dashboard-page admin-page';
$activeItem = 'admin';

require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../helpers/upload.php';
require_once __DIR__ . '/../../helpers/categories.php';
require_once __DIR__ . '/../../helpers/schema.php';
require_role(['administrador']);

ensure_runtime_schema();

$currentUser = auth_user();
$userId = (int) $currentUser['id'];

function admin_count(string $sql): int
{
    return (int) db()->query($sql)->fetchColumn();
}

function admin_audit(int $userId, string $action, ?string $tableName = null, ?int $recordId = null): void
{
    db()->prepare(
        'INSERT INTO audit_logs (user_id, action, table_name, record_id, ip_address, user_agent)
         VALUES (:user_id, :action, :table_name, :record_id, :ip_address, :user_agent)'
    )->execute([
        'user_id' => $userId,
        'action' => $action,
        'table_name' => $tableName,
        'record_id' => $recordId,
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
    ]);
}

function admin_action_label(string $action): string
{
    $labels = [
        'update_user' => 'Actualizar usuario',
        'delete_user' => 'Eliminar usuario',
        'create_university' => 'Crear universidad',
        'update_university' => 'Actualizar universidad',
        'delete_university' => 'Eliminar universidad',
        'verify_university' => 'Verificar universidad',
        'unverify_university' => 'Quitar verificación de universidad',
        'update_recruiter' => 'Actualizar reclutador',
        'delete_recruiter' => 'Eliminar reclutador',
        'save_skill_category' => 'Guardar categoría de habilidad',
        'delete_skill_category' => 'Eliminar categoría de habilidad',
        'save_certification_category' => 'Guardar categoría de certificación',
        'delete_certification_category' => 'Eliminar categoría de certificación',
        'create_announcement' => 'Crear anuncio',
        'toggle_announcement' => 'Cambiar estado de anuncio',
        'delete_announcement' => 'Eliminar anuncio',
        'create_moderation_flag' => 'Crear reporte de moderación',
        'review_moderation_flag' => 'Revisar reporte de moderación',
        'save_setting' => 'Guardar configuración del sistema',
    ];

    return $labels[$action] ?? str_replace('_', ' ', $action);
}

function admin_setting_label(string $key): string
{
    $labels = [
        'site_name' => 'Nombre del sitio',
        'maintenance_mode' => 'Modo mantenimiento',
        'allow_public_registration' => 'Registro público',
        'default_portfolio_visibility' => 'Visibilidad predeterminada del portafolio',
        'certification_categories' => 'Categorías de certificación',
    ];

    return $labels[$key] ?? ucwords(str_replace('_', ' ', $key));
}

function admin_setting_value_display(string $key, ?string $value): string
{
    $value = (string) ($value ?? '');

    if ($key === 'maintenance_mode' || $key === 'allow_public_registration') {
        if ($value === '1') {
            return 'Activado';
        }

        if ($value === '0') {
            return 'Desactivado';
        }
    }

    if ($key === 'default_portfolio_visibility') {
        if ($value === 'privado') {
            return 'Privado';
        }

        if ($value === 'publico') {
            return 'Público';
        }
    }

    if ($key === 'certification_categories') {
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return implode(', ', array_map('strval', $decoded));
        }
    }

    return $value;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['_csrf_token'] ?? null)) {
        flash('danger', 'La sesión expiró. Intenta nuevamente.');
        header('Location: ' . url_to('admin/index.php'));
        exit;
    }

    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'update_user') {
            $targetUserId = (int) ($_POST['user_id'] ?? 0);
            $roleId = (int) ($_POST['role_id'] ?? 0);
            $status = in_array($_POST['status'] ?? '', ['activo', 'pendiente', 'suspendido'], true) ? $_POST['status'] : 'pendiente';

            $roleStatement = db()->prepare('SELECT id, name FROM roles WHERE id = :id LIMIT 1');
            $roleStatement->execute(['id' => $roleId]);
            $roleRow = $roleStatement->fetch();

            if (!$roleRow) {
                flash('danger', 'El rol seleccionado no es válido.');
            } else {
                $targetStatement = db()->prepare(
                    'SELECT u.id, r.name AS role_name
                     FROM users u
                     INNER JOIN roles r ON r.id = u.role_id
                     WHERE u.id = :id
                     LIMIT 1'
                );
                $targetStatement->execute(['id' => $targetUserId]);
                $targetUser = $targetStatement->fetch();

                $adminCount = (int) db()->query(
                    'SELECT COUNT(*)
                     FROM users u
                     INNER JOIN roles r ON r.id = u.role_id
                     WHERE r.name = "administrador" AND u.status = "activo"'
                )->fetchColumn();

                if ($targetUser
                    && $targetUser['role_name'] === 'administrador'
                    && $roleRow['name'] !== 'administrador'
                    && $adminCount <= 1) {
                    flash('danger', 'Debe permanecer al menos un administrador activo.');
                } else {
                    db()->prepare('UPDATE users SET role_id = :role_id, status = :status WHERE id = :id')
                        ->execute(['role_id' => $roleId, 'status' => $status, 'id' => $targetUserId]);
                    admin_audit($userId, 'update_user', 'users', $targetUserId);
                    flash('success', 'Usuario actualizado.');
                }
            }
        }

        if ($action === 'delete_user') {
            $targetUserId = (int) ($_POST['user_id'] ?? 0);
            if ($targetUserId === $userId) {
                flash('warning', 'No puedes eliminar tu propia cuenta desde administración.');
            } else {
                $targetStatement = db()->prepare(
                    'SELECT u.id, r.name AS role_name
                     FROM users u
                     INNER JOIN roles r ON r.id = u.role_id
                     WHERE u.id = :id
                     LIMIT 1'
                );
                $targetStatement->execute(['id' => $targetUserId]);
                $targetUser = $targetStatement->fetch();

                $adminCount = (int) db()->query(
                    'SELECT COUNT(*)
                     FROM users u
                     INNER JOIN roles r ON r.id = u.role_id
                     WHERE r.name = "administrador" AND u.status = "activo"'
                )->fetchColumn();

                if ($targetUser && $targetUser['role_name'] === 'administrador' && $adminCount <= 1) {
                    flash('danger', 'No puedes eliminar al último administrador activo.');
                } else {
                    $uploadPaths = collect_user_upload_paths($targetUserId);
                    db()->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $targetUserId]);
                    delete_upload_files($uploadPaths);
                    admin_audit($userId, 'delete_user', 'users', $targetUserId);
                    flash('success', 'Usuario eliminado.');
                }
            }
        }

        if ($action === 'save_university') {
            $universityId = (int) ($_POST['university_id'] ?? 0);
            $name = trim((string) ($_POST['name'] ?? ''));
            $city = trim((string) ($_POST['city'] ?? ''));
            $state = trim((string) ($_POST['state'] ?? ''));
            $country = trim((string) ($_POST['country'] ?? 'México'));
            $website = trim((string) ($_POST['website'] ?? ''));

            if ($name === '') {
                flash('danger', 'El nombre de la universidad es obligatorio.');
            } elseif ($universityId > 0) {
                db()->prepare('UPDATE universities SET name = :name, city = :city, state = :state, country = :country, website = :website WHERE id = :id')
                    ->execute([
                        'name' => $name,
                        'city' => $city ?: null,
                        'state' => $state ?: null,
                        'country' => $country ?: 'México',
                        'website' => $website ?: null,
                        'id' => $universityId,
                    ]);
                admin_audit($userId, 'update_university', 'universities', $universityId);
                flash('success', 'Universidad actualizada.');
            } else {
                db()->prepare('INSERT INTO universities (name, city, state, country, website) VALUES (:name, :city, :state, :country, :website)')
                    ->execute([
                        'name' => $name,
                        'city' => $city ?: null,
                        'state' => $state ?: null,
                        'country' => $country ?: 'México',
                        'website' => $website ?: null,
                    ]);
                admin_audit($userId, 'create_university', 'universities', (int) db()->lastInsertId());
                flash('success', 'Universidad creada.');
            }
        }

        if ($action === 'delete_university') {
            $universityId = (int) ($_POST['university_id'] ?? 0);
            db()->prepare('DELETE FROM universities WHERE id = :id')->execute(['id' => $universityId]);
            admin_audit($userId, 'delete_university', 'universities', $universityId);
            flash('success', 'Universidad eliminada.');
        }

        if ($action === 'verify_university') {
            $universityId = (int) ($_POST['university_id'] ?? 0);
            db()->prepare('UPDATE universities SET is_verified = 1 WHERE id = :id')->execute(['id' => $universityId]);
            admin_audit($userId, 'verify_university', 'universities', $universityId);
            flash('success', 'Universidad verificada como institución válida.');
        }

        if ($action === 'unverify_university') {
            $universityId = (int) ($_POST['university_id'] ?? 0);
            db()->prepare('UPDATE universities SET is_verified = 0 WHERE id = :id')->execute(['id' => $universityId]);
            admin_audit($userId, 'unverify_university', 'universities', $universityId);
            flash('success', 'Universidad marcada como no verificada.');
        }

        if ($action === 'update_recruiter') {
            $recruiterId = (int) ($_POST['recruiter_id'] ?? 0);
            $status = in_array($_POST['status'] ?? '', ['pendiente', 'activo', 'suspendido'], true) ? $_POST['status'] : 'pendiente';

            db()->prepare('UPDATE recruiters SET status = :status WHERE id = :id')
                ->execute(['status' => $status, 'id' => $recruiterId]);
            admin_audit($userId, 'update_recruiter', 'recruiters', $recruiterId);
            flash('success', 'Reclutador actualizado.');
        }

        if ($action === 'delete_recruiter') {
            $recruiterId = (int) ($_POST['recruiter_id'] ?? 0);
            db()->prepare('DELETE FROM recruiters WHERE id = :id')->execute(['id' => $recruiterId]);
            admin_audit($userId, 'delete_recruiter', 'recruiters', $recruiterId);
            flash('success', 'Reclutador eliminado.');
        }

        if ($action === 'save_skill_category') {
            $name = trim((string) ($_POST['category_name'] ?? ''));
            $type = in_array($_POST['category_type'] ?? '', ['tecnica', 'blanda', 'idioma', 'herramienta'], true) ? $_POST['category_type'] : 'tecnica';

            if ($name === '') {
                flash('danger', 'El nombre de la categoría es obligatorio.');
            } else {
                db()->prepare(
                    'INSERT INTO skill_categories (name, type)
                     VALUES (:name, :type)
                     ON DUPLICATE KEY UPDATE type = VALUES(type)'
                )->execute(['name' => $name, 'type' => $type]);
                admin_audit($userId, 'save_skill_category', 'skill_categories', null);
                flash('success', 'Categoría guardada.');
            }
        }

        if ($action === 'delete_skill_category') {
            $categoryId = (int) ($_POST['category_id'] ?? 0);
            db()->prepare('DELETE FROM skill_categories WHERE id = :id')->execute(['id' => $categoryId]);
            admin_audit($userId, 'delete_skill_category', 'skill_categories', $categoryId);
            flash('success', 'Categoría eliminada.');
        }

        if ($action === 'save_certification_category') {
            $name = trim((string) ($_POST['certification_category_name'] ?? ''));
            if ($name === '') {
                flash('danger', 'El nombre de la categoría de certificación es obligatorio.');
            } else {
                save_certification_category($name);
                admin_audit($userId, 'save_certification_category', 'system_settings', null);
                flash('success', 'Categoría de certificación guardada.');
            }
        }

        if ($action === 'delete_certification_category') {
            $name = trim((string) ($_POST['certification_category_name'] ?? ''));
            if ($name === '') {
                flash('danger', 'Selecciona una categoría válida para eliminar.');
            } else {
                delete_certification_category($name);
                admin_audit($userId, 'delete_certification_category', 'system_settings', null);
                flash('success', 'Categoría de certificación eliminada.');
            }
        }

        if ($action === 'save_announcement') {
            $title = trim((string) ($_POST['title'] ?? ''));
            $body = trim((string) ($_POST['body'] ?? ''));
            $type = in_array($_POST['type'] ?? '', ['info', 'exito', 'advertencia', 'error'], true) ? $_POST['type'] : 'info';
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            if ($title === '' || $body === '') {
                flash('danger', 'Título y mensaje del anuncio son obligatorios.');
            } else {
                db()->prepare(
                    'INSERT INTO announcements (created_by, title, body, type, is_active, visible_from, visible_until)
                     VALUES (:created_by, :title, :body, :type, :is_active, :visible_from, :visible_until)'
                )->execute([
                    'created_by' => $userId,
                    'title' => $title,
                    'body' => $body,
                    'type' => $type,
                    'is_active' => $isActive,
                    'visible_from' => $_POST['visible_from'] ?: null,
                    'visible_until' => $_POST['visible_until'] ?: null,
                ]);
                admin_audit($userId, 'create_announcement', 'announcements', (int) db()->lastInsertId());
                flash('success', 'Anuncio creado.');
            }
        }

        if ($action === 'toggle_announcement') {
            $announcementId = (int) ($_POST['announcement_id'] ?? 0);
            db()->prepare('UPDATE announcements SET is_active = IF(is_active = 1, 0, 1) WHERE id = :id')
                ->execute(['id' => $announcementId]);
            admin_audit($userId, 'toggle_announcement', 'announcements', $announcementId);
            flash('success', 'Estado del anuncio actualizado.');
        }

        if ($action === 'delete_announcement') {
            $announcementId = (int) ($_POST['announcement_id'] ?? 0);
            db()->prepare('DELETE FROM announcements WHERE id = :id')->execute(['id' => $announcementId]);
            admin_audit($userId, 'delete_announcement', 'announcements', $announcementId);
            flash('success', 'Anuncio eliminado.');
        }

        if ($action === 'create_flag') {
            $targetUserId = (int) ($_POST['target_user_id'] ?? 0);
            $contentType = in_array($_POST['content_type'] ?? '', ['perfil', 'proyecto', 'certificacion', 'mensaje', 'portafolio', 'otro'], true) ? $_POST['content_type'] : 'otro';
            $reason = trim((string) ($_POST['reason'] ?? ''));

            if ($reason === '') {
                flash('danger', 'La razón de moderación es obligatoria.');
            } else {
                db()->prepare('INSERT INTO moderation_flags (reporter_user_id, target_user_id, content_type, reason) VALUES (:reporter_user_id, :target_user_id, :content_type, :reason)')
                    ->execute([
                        'reporter_user_id' => $userId,
                        'target_user_id' => $targetUserId ?: null,
                        'content_type' => $contentType,
                        'reason' => $reason,
                    ]);
                admin_audit($userId, 'create_moderation_flag', 'moderation_flags', (int) db()->lastInsertId());
                flash('success', 'Reporte de moderación creado.');
            }
        }

        if ($action === 'review_flag') {
            $flagId = (int) ($_POST['flag_id'] ?? 0);
            $status = in_array($_POST['status'] ?? '', ['pendiente', 'revisado', 'descartado', 'accion_tomada'], true) ? $_POST['status'] : 'revisado';
            $actionTaken = trim((string) ($_POST['action_taken'] ?? ''));
            db()->prepare('UPDATE moderation_flags SET status = :status, action_taken = :action_taken, reviewed_by = :reviewed_by, reviewed_at = NOW() WHERE id = :id')
                ->execute([
                    'status' => $status,
                    'action_taken' => $actionTaken ?: null,
                    'reviewed_by' => $userId,
                    'id' => $flagId,
                ]);
            admin_audit($userId, 'review_moderation_flag', 'moderation_flags', $flagId);
            flash('success', 'Reporte de moderación actualizado.');
        }

        if ($action === 'save_setting') {
            $key = trim((string) ($_POST['setting_key'] ?? ''));
            $value = trim((string) ($_POST['setting_value'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));

            if (!preg_match('/^[a-z0-9_\\.\\-]{3,120}$/i', $key)) {
                flash('danger', 'La clave de configuración no es válida.');
            } else {
                db()->prepare(
                    'INSERT INTO system_settings (setting_key, setting_value, description, updated_by)
                     VALUES (:setting_key, :setting_value, :description, :updated_by)
                     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), description = VALUES(description), updated_by = VALUES(updated_by)'
                )->execute([
                    'setting_key' => $key,
                    'setting_value' => $value,
                    'description' => $description ?: null,
                    'updated_by' => $userId,
                ]);
                admin_audit($userId, 'save_setting', 'system_settings', null);
                flash('success', 'Configuración guardada.');
            }
        }
    } catch (Throwable $exception) {
        error_log('Error admin: ' . $exception->getMessage());
        flash('danger', 'No se pudo completar la acción administrativa.');
    }

    header('Location: ' . url_to('admin/index.php'));
    exit;
}

$roles = db()->query('SELECT id, display_name FROM roles ORDER BY id ASC')->fetchAll();
require_once __DIR__ . '/../../helpers/pagination.php';
$userPage = max(1, (int) ($_GET['page'] ?? 1));
$userCount = (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
$userPagination = pagination_state($userPage, 25, $userCount);
$userStatement = db()->prepare(
    'SELECT u.id, u.full_name, u.username, u.email, u.status, u.created_at, r.display_name, r.id AS role_id
     FROM users u
     INNER JOIN roles r ON r.id = u.role_id
     ORDER BY u.created_at DESC
     LIMIT :limit OFFSET :offset'
);
$userStatement->bindValue('limit', $userPagination['limit'], PDO::PARAM_INT);
$userStatement->bindValue('offset', $userPagination['offset'], PDO::PARAM_INT);
$userStatement->execute();
$users = $userStatement->fetchAll();
$pagination = $userPagination;
$universities = db()->query('SELECT * FROM universities ORDER BY updated_at DESC, created_at DESC LIMIT 60')->fetchAll();
$recruiters = db()->query('SELECT * FROM recruiters ORDER BY FIELD(status, "pendiente", "activo", "suspendido"), created_at DESC LIMIT 60')->fetchAll();
$skillCategories = db()->query('SELECT sc.*, COUNT(s.id) AS skills_count FROM skill_categories sc LEFT JOIN skills s ON s.category_id = sc.id GROUP BY sc.id, sc.name, sc.type, sc.created_at ORDER BY sc.type ASC, sc.name ASC')->fetchAll();
$certificationCategories = array_map(
    static function ($category) {
        return ['category' => $category, 'total' => 0];
    },
    certification_category_options()
);
$usageStatement = db()->query('SELECT category, COUNT(*) AS total FROM certifications WHERE category IS NOT NULL AND category <> "" GROUP BY category');
foreach ($usageStatement->fetchAll() as $row) {
    foreach ($certificationCategories as &$categoryRow) {
        if ($categoryRow['category'] === $row['category']) {
            $categoryRow['total'] = (int) $row['total'];
        }
    }
    unset($categoryRow);
}
$announcements = db()->query('SELECT a.*, u.full_name AS author FROM announcements a LEFT JOIN users u ON u.id = a.created_by ORDER BY a.created_at DESC LIMIT 30')->fetchAll();
$flags = db()->query('SELECT mf.*, tu.full_name AS target_name, ru.full_name AS reporter_name FROM moderation_flags mf LEFT JOIN users tu ON tu.id = mf.target_user_id LEFT JOIN users ru ON ru.id = mf.reporter_user_id ORDER BY FIELD(mf.status, "pendiente", "accion_tomada", "revisado", "descartado"), mf.created_at DESC LIMIT 40')->fetchAll();
$settings = db()->query('SELECT * FROM system_settings ORDER BY setting_key ASC')->fetchAll();
$auditLogs = db()->query('SELECT al.*, u.full_name FROM audit_logs al LEFT JOIN users u ON u.id = al.user_id ORDER BY al.created_at DESC LIMIT 20')->fetchAll();

$analytics = [
    'Usuarios' => admin_count('SELECT COUNT(*) FROM users'),
    'Portafolios públicos' => admin_count('SELECT COUNT(*) FROM portfolio_settings WHERE is_public = 1'),
    'Proyectos' => admin_count('SELECT COUNT(*) FROM projects'),
    'Certificaciones' => admin_count('SELECT COUNT(*) FROM certifications'),
    'Visitas' => admin_count('SELECT COUNT(*) FROM portfolio_visits'),
    'Mensajes' => admin_count('SELECT COUNT(*) FROM contact_messages'),
    'Recomendaciones IA' => admin_count('SELECT COUNT(*) FROM recommendations'),
    'Candidatos guardados' => admin_count('SELECT COUNT(*) FROM saved_candidates'),
];

$topPortfolios = db()->query(
    'SELECT u.full_name, ps.public_slug, COUNT(pv.id) AS visits
     FROM portfolio_settings ps
     INNER JOIN users u ON u.id = ps.user_id
     LEFT JOIN portfolio_visits pv ON pv.user_id = u.id
     GROUP BY u.id, u.full_name, ps.public_slug
     ORDER BY visits DESC
     LIMIT 5'
)->fetchAll();

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
                <span class="eyebrow">Administración</span>
                <h1>Panel administrativo</h1>
                <p>Verifica universidades y reclutadores reales. Aprueba o suspende cuentas según corresponda.</p>
            </div>
            <a class="btn btn-outline-danger" href="<?= e(url_to('auth/logout.php')); ?>">Cerrar sesión</a>
        </div>

        <section class="stats-grid" aria-label="Analíticas generales">
            <?php foreach ($analytics as $label => $value): ?>
                <article class="stat-card">
                    <div class="stat-icon primary"><i class="bi bi-bar-chart" aria-hidden="true"></i></div>
                    <div><span><?= e($label); ?></span><strong><?= e((string) $value); ?></strong></div>
                </article>
            <?php endforeach; ?>
        </section>

        <div class="admin-layout">
            <section class="dashboard-card" id="usuarios">
                <div class="card-heading"><div><span class="eyebrow">Usuarios</span><h2>Gestión de usuarios</h2></div></div>
                <div class="admin-table-wrap">
                    <table class="admin-table">
                        <thead><tr><th>Usuario</th><th>Rol</th><th>Estado</th><th>Acciones</th></tr></thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                                <tr>
                                    <td><strong><?= e($user['full_name']); ?></strong><span><?= e($user['email']); ?></span></td>
                                    <td colspan="2">
                                        <form class="admin-inline-form" method="post" action="<?= e(url_to('admin/index.php')); ?>">
                                            <?= csrf_field(); ?>
                                            <input type="hidden" name="action" value="update_user">
                                            <input type="hidden" name="user_id" value="<?= e((string) $user['id']); ?>">
                                            <select class="form-select form-select-sm" name="role_id">
                                                <?php foreach ($roles as $role): ?>
                                                    <option value="<?= e((string) $role['id']); ?>" <?= (int) $role['id'] === (int) $user['role_id'] ? 'selected' : ''; ?>><?= e($role['display_name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <select class="form-select form-select-sm" name="status">
                                                <?php foreach (['activo', 'pendiente', 'suspendido'] as $status): ?>
                                                    <option value="<?= e($status); ?>" <?= $user['status'] === $status ? 'selected' : ''; ?>><?= e($status); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button class="btn btn-primary btn-sm" type="submit">Guardar</button>
                                        </form>
                                    </td>
                                    <td>
                                        <form method="post" action="<?= e(url_to('admin/index.php')); ?>" onsubmit="return confirm('¿Eliminar este usuario?');">
                                            <?= csrf_field(); ?>
                                            <input type="hidden" name="action" value="delete_user">
                                            <input type="hidden" name="user_id" value="<?= e((string) $user['id']); ?>">
                                            <button class="btn btn-outline-danger btn-sm" type="submit">Eliminar</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php require __DIR__ . '/../../../HTML/components/pagination.php'; ?>
            </section>

            <aside class="dashboard-card" id="reportes">
                <div class="card-heading">
                    <div><span class="eyebrow">Reportes</span><h2>Top portafolios</h2></div>
                    <div class="d-flex flex-wrap gap-2">
                        <a class="btn btn-outline-primary btn-sm" href="<?= e(url_to('admin/export.php?type=analytics')); ?>">Exportar analíticas</a>
                        <a class="btn btn-outline-primary btn-sm" href="<?= e(url_to('admin/export.php?type=users')); ?>">Exportar usuarios</a>
                        <a class="btn btn-outline-primary btn-sm" href="<?= e(url_to('admin/export.php?type=portfolios')); ?>">Exportar portafolios</a>
                    </div>
                </div>
                <div class="portfolio-list">
                    <?php foreach ($topPortfolios as $portfolio): ?>
                        <article>
                            <h3><?= e($portfolio['full_name']); ?></h3>
                            <p><?= e($portfolio['public_slug']); ?> · <?= e((string) $portfolio['visits']); ?> visitas</p>
                        </article>
                    <?php endforeach; ?>
                </div>
                <hr>
                <h3 class="resume-sidebar-title">Auditoría reciente</h3>
                <div class="saved-candidate-list">
                    <?php foreach ($auditLogs as $log): ?>
                        <article><strong><?= e(admin_action_label((string) $log['action'])); ?></strong><span><?= e($log['full_name'] ?? 'Sistema'); ?> · <?= e(date('d/m/Y H:i', strtotime($log['created_at']))); ?></span></article>
                    <?php endforeach; ?>
                </div>
            </aside>
        </div>

        <div class="admin-layout mt-3">
            <section class="dashboard-card" id="universidades">
                <div class="card-heading">
                    <div><span class="eyebrow">Universidades</span><h2>Verificar instituciones</h2></div>
                    <span class="status-pill info">Solo las verificadas son confiables</span>
                </div>
                <form class="row g-3" method="post" action="<?= e(url_to('admin/index.php')); ?>">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action" value="save_university">
                    <div class="col-md-4"><input class="form-control" name="name" placeholder="Nombre oficial" required></div>
                    <div class="col-md-2"><input class="form-control" name="city" placeholder="Ciudad"></div>
                    <div class="col-md-2"><input class="form-control" name="state" placeholder="Estado"></div>
                    <div class="col-md-2"><input class="form-control" name="country" value="México"></div>
                    <div class="col-md-2"><button class="btn btn-primary w-100" type="submit">Agregar</button></div>
                </form>
                <div class="admin-list mt-3">
                    <?php foreach ($universities as $university): ?>
                        <article>
                            <div>
                                <strong><?= e($university['name']); ?></strong>
                                <span><?= e(($university['city'] ?? '') . ' ' . ($university['state'] ?? '') . ' ' . ($university['country'] ?? '')); ?></span>
                                <span class="status-pill <?= !empty($university['is_verified']) ? 'success' : 'warning'; ?>">
                                    <?= !empty($university['is_verified']) ? 'Verificada' : 'Pendiente'; ?>
                                </span>
                            </div>
                            <div class="project-links">
                                <?php if (empty($university['is_verified'])): ?>
                                    <form method="post" action="<?= e(url_to('admin/index.php#universidades')); ?>">
                                        <?= csrf_field(); ?>
                                        <input type="hidden" name="action" value="verify_university">
                                        <input type="hidden" name="university_id" value="<?= e((string) $university['id']); ?>">
                                        <button class="btn btn-primary btn-sm" type="submit">Verificar</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" action="<?= e(url_to('admin/index.php#universidades')); ?>">
                                        <?= csrf_field(); ?>
                                        <input type="hidden" name="action" value="unverify_university">
                                        <input type="hidden" name="university_id" value="<?= e((string) $university['id']); ?>">
                                        <button class="btn btn-outline-primary btn-sm" type="submit">Quitar verificación</button>
                                    </form>
                                <?php endif; ?>
                                <form method="post" action="<?= e(url_to('admin/index.php#universidades')); ?>" onsubmit="return confirm('¿Eliminar universidad?');">
                                    <?= csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete_university">
                                    <input type="hidden" name="university_id" value="<?= e((string) $university['id']); ?>">
                                    <button class="btn btn-outline-danger btn-sm" type="submit">Eliminar</button>
                                </form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="dashboard-card" id="anuncios">
                <div class="card-heading"><div><span class="eyebrow">Anuncios</span><h2>Comunicados del sistema</h2></div></div>
                <form class="row g-3" method="post" action="<?= e(url_to('admin/index.php')); ?>">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action" value="save_announcement">
                    <div class="col-md-6"><input class="form-control" name="title" placeholder="Título" required></div>
                    <div class="col-md-3"><select class="form-select" name="type"><option value="info">Info</option><option value="exito">Éxito</option><option value="advertencia">Advertencia</option><option value="error">Error</option></select></div>
                    <div class="col-md-3"><label class="relation-check"><input type="checkbox" name="is_active" checked><span>Activo</span></label></div>
                    <div class="col-12"><textarea class="form-control" name="body" rows="3" placeholder="Mensaje" required></textarea></div>
                    <div class="col-md-6"><input class="form-control" type="datetime-local" name="visible_from"></div>
                    <div class="col-md-6"><input class="form-control" type="datetime-local" name="visible_until"></div>
                    <div class="col-12"><button class="btn btn-primary" type="submit">Publicar anuncio</button></div>
                </form>
                <div class="admin-list mt-3">
                    <?php foreach ($announcements as $announcement): ?>
                        <article>
                            <div><strong><?= e($announcement['title']); ?></strong><span><?= e($announcement['type']); ?> · <?= (int) $announcement['is_active'] === 1 ? 'Activo' : 'Inactivo'; ?></span></div>
                            <div class="project-links">
                                <form method="post" action="<?= e(url_to('admin/index.php')); ?>"><?= csrf_field(); ?><input type="hidden" name="action" value="toggle_announcement"><input type="hidden" name="announcement_id" value="<?= e((string) $announcement['id']); ?>"><button class="btn btn-outline-primary btn-sm" type="submit">Cambiar</button></form>
                                <form method="post" action="<?= e(url_to('admin/index.php')); ?>" onsubmit="return confirm('¿Eliminar anuncio?');"><?= csrf_field(); ?><input type="hidden" name="action" value="delete_announcement"><input type="hidden" name="announcement_id" value="<?= e((string) $announcement['id']); ?>"><button class="btn btn-outline-danger btn-sm" type="submit">Eliminar</button></form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>

        <div class="admin-layout mt-3">
            <section class="dashboard-card" id="reclutadores">
                <div class="card-heading">
                    <div><span class="eyebrow">Reclutadores</span><h2>Validar reclutadores</h2></div>
                    <span class="status-pill warning">Activa solo empresas reales</span>
                </div>
                <div class="admin-list">
                    <?php foreach ($recruiters as $recruiter): ?>
                        <article>
                            <div>
                                <strong><?= e($recruiter['company_name']); ?></strong>
                                <span><?= e($recruiter['contact_name']); ?> · <?= e($recruiter['email']); ?></span>
                                <span class="status-pill <?= $recruiter['status'] === 'activo' ? 'success' : ($recruiter['status'] === 'pendiente' ? 'warning' : 'danger'); ?>">
                                    <?= e($recruiter['status']); ?>
                                </span>
                            </div>
                            <div class="project-links">
                                <form class="admin-inline-form" method="post" action="<?= e(url_to('admin/index.php#reclutadores')); ?>">
                                    <?= csrf_field(); ?>
                                    <input type="hidden" name="action" value="update_recruiter">
                                    <input type="hidden" name="recruiter_id" value="<?= e((string) $recruiter['id']); ?>">
                                    <select class="form-select form-select-sm" name="status">
                                        <?php foreach (['pendiente', 'activo', 'suspendido'] as $status): ?>
                                            <option value="<?= e($status); ?>" <?= $recruiter['status'] === $status ? 'selected' : ''; ?>><?= e($status); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button class="btn btn-primary btn-sm" type="submit">Guardar</button>
                                </form>
                                <form method="post" action="<?= e(url_to('admin/index.php#reclutadores')); ?>" onsubmit="return confirm('¿Eliminar reclutador?');">
                                    <?= csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete_recruiter">
                                    <input type="hidden" name="recruiter_id" value="<?= e((string) $recruiter['id']); ?>">
                                    <button class="btn btn-outline-danger btn-sm" type="submit">Eliminar</button>
                                </form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                    <?php if (empty($recruiters)): ?><p class="text-secondary mb-0">No hay reclutadores registrados.</p><?php endif; ?>
                </div>
            </section>

            <section class="dashboard-card" id="categorias">
                <div class="card-heading"><div><span class="eyebrow">Categorías</span><h2>Skills y certificaciones</h2></div></div>
                <form class="row g-3" method="post" action="<?= e(url_to('admin/index.php#categorias')); ?>">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action" value="save_skill_category">
                    <div class="col-md-6"><input class="form-control" name="category_name" placeholder="Nombre de categoría" required></div>
                    <div class="col-md-3">
                        <select class="form-select" name="category_type">
                            <option value="tecnica">Técnica</option>
                            <option value="blanda">Blanda</option>
                            <option value="idioma">Idioma</option>
                            <option value="herramienta">Herramienta</option>
                        </select>
                    </div>
                    <div class="col-md-3"><button class="btn btn-primary w-100" type="submit">Guardar</button></div>
                </form>
                <div class="admin-list mt-3">
                    <?php foreach ($skillCategories as $category): ?>
                        <article>
                            <div><strong><?= e($category['name']); ?></strong><span><?= e($category['type']); ?> · <?= e((string) $category['skills_count']); ?> skills</span></div>
                            <form method="post" action="<?= e(url_to('admin/index.php#categorias')); ?>" onsubmit="return confirm('¿Eliminar categoría de habilidades? Las skills asociadas quedarán sin categoría.');">
                                <?= csrf_field(); ?>
                                <input type="hidden" name="action" value="delete_skill_category">
                                <input type="hidden" name="category_id" value="<?= e((string) $category['id']); ?>">
                                <button class="btn btn-outline-danger btn-sm" type="submit">Eliminar</button>
                            </form>
                        </article>
                    <?php endforeach; ?>
                </div>
                <hr>
                <h3 class="resume-sidebar-title">Categorías de certificación</h3>
                <form class="row g-3 mb-3" method="post" action="<?= e(url_to('admin/index.php#categorias')); ?>">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action" value="save_certification_category">
                    <div class="col-md-9"><input class="form-control" name="certification_category_name" placeholder="Nueva categoría de certificación" required></div>
                    <div class="col-md-3"><button class="btn btn-primary w-100" type="submit">Agregar</button></div>
                </form>
                <div class="admin-list">
                    <?php foreach ($certificationCategories as $category): ?>
                        <article>
                            <div><strong><?= e($category['category']); ?></strong><span><?= e((string) $category['total']); ?> certificaciones</span></div>
                            <form method="post" action="<?= e(url_to('admin/index.php#categorias')); ?>" onsubmit="return confirm('¿Eliminar esta categoría sugerida?');">
                                <?= csrf_field(); ?>
                                <input type="hidden" name="action" value="delete_certification_category">
                                <input type="hidden" name="certification_category_name" value="<?= e($category['category']); ?>">
                                <button class="btn btn-outline-danger btn-sm" type="submit">Eliminar</button>
                            </form>
                        </article>
                    <?php endforeach; ?>
                    <?php if (empty($certificationCategories)): ?><p class="text-secondary mb-0">Aún no hay categorías de certificación.</p><?php endif; ?>
                </div>
            </section>
        </div>

        <div class="admin-layout mt-3">
            <section class="dashboard-card" id="moderacion">
                <div class="card-heading"><div><span class="eyebrow">Moderación</span><h2>Reportes de contenido</h2></div></div>
                <form class="row g-3" method="post" action="<?= e(url_to('admin/index.php')); ?>">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action" value="create_flag">
                    <div class="col-md-4"><select class="form-select" name="target_user_id"><option value="">Usuario objetivo</option><?php foreach ($users as $user): ?><option value="<?= e((string) $user['id']); ?>"><?= e($user['full_name']); ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-3"><select class="form-select" name="content_type"><option value="perfil">Perfil</option><option value="proyecto">Proyecto</option><option value="certificacion">Certificación</option><option value="mensaje">Mensaje</option><option value="portafolio">Portafolio</option><option value="otro">Otro</option></select></div>
                    <div class="col-md-5"><input class="form-control" name="reason" placeholder="Razón del reporte" required></div>
                    <div class="col-12"><button class="btn btn-primary" type="submit">Crear reporte</button></div>
                </form>
                <div class="admin-list mt-3">
                    <?php foreach ($flags as $flag): ?>
                        <article>
                            <div><strong><?= e($flag['content_type']); ?> · <?= e($flag['status']); ?></strong><span><?= e($flag['reason']); ?> · <?= e($flag['target_name'] ?? 'Sin usuario'); ?></span></div>
                            <form class="admin-inline-form" method="post" action="<?= e(url_to('admin/index.php')); ?>">
                                <?= csrf_field(); ?>
                                <input type="hidden" name="action" value="review_flag">
                                <input type="hidden" name="flag_id" value="<?= e((string) $flag['id']); ?>">
                                <select class="form-select form-select-sm" name="status"><option value="revisado">Revisado</option><option value="descartado">Descartado</option><option value="accion_tomada">Acción tomada</option><option value="pendiente">Pendiente</option></select>
                                <input class="form-control form-control-sm" name="action_taken" placeholder="Acción tomada">
                                <button class="btn btn-primary btn-sm" type="submit">Actualizar</button>
                            </form>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="dashboard-card" id="configuracion-sistema">
                <div class="card-heading"><div><span class="eyebrow">Configuración</span><h2>Ajustes del sistema</h2></div></div>
                <form class="row g-3" method="post" action="<?= e(url_to('admin/index.php')); ?>">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action" value="save_setting">
                    <div class="col-md-4"><input class="form-control" name="setting_key" placeholder="Clave técnica (ej. modo_mantenimiento)" required></div>
                    <div class="col-md-4"><input class="form-control" name="setting_value" placeholder="Valor (ej. 0, 1, privado)"></div>
                    <div class="col-md-4"><input class="form-control" name="description" placeholder="Descripción en español"></div>
                    <div class="col-12"><button class="btn btn-primary" type="submit">Guardar configuración</button></div>
                </form>
                <div class="admin-list mt-3">
                    <?php foreach ($settings as $setting): ?>
                        <article>
                            <div>
                                <strong><?= e(admin_setting_label((string) $setting['setting_key'])); ?></strong>
                                <span><?= e($setting['description'] ?? ''); ?></span>
                            </div>
                            <code><?= e(admin_setting_value_display((string) $setting['setting_key'], $setting['setting_value'] ?? '')); ?></code>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>
        <?php require __DIR__ . '/../../../HTML/components/mobile-nav.php'; ?>
    </section>
    </div>
</main>

<?php require_once __DIR__ . '/../../../HTML/components/footer.php'; ?>
