<?php
declare(strict_types=1);

$pageTitle = 'Notificaciones';
$pageDescription = 'Centro de notificaciones y recordatorios académicos.';
$bodyClass = 'dashboard-page notifications-page';
$activeItem = 'notificaciones';
$pageScript = 'auth.js';

require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../helpers/pagination.php';
require_auth();

$currentUser = auth_user();
$userId = (int) $currentUser['id'];
$notificationPage = max(1, (int) ($_GET['page'] ?? 1));
$notificationsPerPage = 20;

function sync_due_reminders(int $userId): void
{
    $statement = db()->prepare(
        'SELECT id, title, due_at, priority
         FROM reminders
         WHERE user_id = :user_id
           AND status = "pendiente"
           AND last_notified_at IS NULL
           AND due_at <= DATE_ADD(NOW(), INTERVAL 24 HOUR)
         ORDER BY due_at ASC'
    );
    $statement->execute(['user_id' => $userId]);
    $reminders = $statement->fetchAll();

    foreach ($reminders as $reminder) {
        $isOverdue = strtotime($reminder['due_at']) < time();
        db()->prepare(
            'INSERT INTO notifications (user_id, title, message, type)
             VALUES (:user_id, :title, :message, :type)'
        )->execute([
            'user_id' => $userId,
            'title' => $isOverdue ? 'Recordatorio vencido' : 'Recordatorio próximo',
            'message' => $reminder['title'] . ' · vence el ' . date('d/m/Y H:i', strtotime($reminder['due_at'])),
            'type' => $isOverdue ? 'advertencia' : 'info',
        ]);

        db()->prepare('UPDATE reminders SET last_notified_at = NOW() WHERE id = :id AND user_id = :user_id')
            ->execute(['id' => $reminder['id'], 'user_id' => $userId]);
    }
}

sync_due_reminders($userId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['_csrf_token'] ?? null)) {
        flash('danger', 'La sesión expiró. Intenta nuevamente.');
        header('Location: ' . url_to('notifications/index.php'));
        exit;
    }

    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'mark_read') {
            db()->prepare('UPDATE notifications SET read_at = NOW() WHERE id = :id AND user_id = :user_id')
                ->execute(['id' => (int) ($_POST['notification_id'] ?? 0), 'user_id' => $userId]);
            flash('success', 'Notificación marcada como leída.');
        }

        if ($action === 'mark_all_read') {
            db()->prepare('UPDATE notifications SET read_at = NOW() WHERE user_id = :user_id AND read_at IS NULL')
                ->execute(['user_id' => $userId]);
            flash('success', 'Todas las notificaciones fueron marcadas como leídas.');
        }

        if ($action === 'delete_notification') {
            db()->prepare('DELETE FROM notifications WHERE id = :id AND user_id = :user_id')
                ->execute(['id' => (int) ($_POST['notification_id'] ?? 0), 'user_id' => $userId]);
            flash('success', 'Notificación eliminada.');
        }

        if ($action === 'mark_message_read') {
            db()->prepare('UPDATE contact_messages SET status = "leido" WHERE id = :id AND user_id = :user_id')
                ->execute(['id' => (int) ($_POST['message_id'] ?? 0), 'user_id' => $userId]);
            flash('success', 'Mensaje marcado como leído.');
        }

        if ($action === 'archive_message') {
            db()->prepare('UPDATE contact_messages SET status = "archivado" WHERE id = :id AND user_id = :user_id')
                ->execute(['id' => (int) ($_POST['message_id'] ?? 0), 'user_id' => $userId]);
            flash('success', 'Mensaje archivado.');
        }

        if ($action === 'delete_message') {
            db()->prepare('DELETE FROM contact_messages WHERE id = :id AND user_id = :user_id')
                ->execute(['id' => (int) ($_POST['message_id'] ?? 0), 'user_id' => $userId]);
            flash('success', 'Mensaje eliminado.');
        }

        if ($action === 'save_reminder') {
            $reminderId = (int) ($_POST['reminder_id'] ?? 0);
            $title = trim((string) ($_POST['title'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            $dueAt = trim((string) ($_POST['due_at'] ?? ''));
            $priority = in_array($_POST['priority'] ?? '', ['baja', 'media', 'alta'], true) ? $_POST['priority'] : 'media';
            $relatedUrl = trim((string) ($_POST['related_url'] ?? ''));

            if ($title === '' || $dueAt === '') {
                flash('danger', 'El título y la fecha del recordatorio son obligatorios.');
            } elseif ($reminderId > 0) {
                db()->prepare(
                    'UPDATE reminders
                     SET title = :title,
                         description = :description,
                         due_at = :due_at,
                         priority = :priority,
                         related_url = :related_url,
                         last_notified_at = NULL
                     WHERE id = :id AND user_id = :user_id'
                )->execute([
                    'title' => $title,
                    'description' => $description ?: null,
                    'due_at' => str_replace('T', ' ', $dueAt),
                    'priority' => $priority,
                    'related_url' => $relatedUrl ?: null,
                    'id' => $reminderId,
                    'user_id' => $userId,
                ]);
                flash('success', 'Recordatorio actualizado.');
            } else {
                db()->prepare(
                    'INSERT INTO reminders (user_id, title, description, due_at, priority, related_url)
                     VALUES (:user_id, :title, :description, :due_at, :priority, :related_url)'
                )->execute([
                    'user_id' => $userId,
                    'title' => $title,
                    'description' => $description ?: null,
                    'due_at' => str_replace('T', ' ', $dueAt),
                    'priority' => $priority,
                    'related_url' => $relatedUrl ?: null,
                ]);
                flash('success', 'Recordatorio creado.');
            }
        }

        if ($action === 'complete_reminder') {
            db()->prepare('UPDATE reminders SET status = "completado", completed_at = NOW() WHERE id = :id AND user_id = :user_id')
                ->execute(['id' => (int) ($_POST['reminder_id'] ?? 0), 'user_id' => $userId]);
            flash('success', 'Recordatorio completado.');
        }

        if ($action === 'restore_reminder') {
            db()->prepare('UPDATE reminders SET status = "pendiente", completed_at = NULL, last_notified_at = NULL WHERE id = :id AND user_id = :user_id')
                ->execute(['id' => (int) ($_POST['reminder_id'] ?? 0), 'user_id' => $userId]);
            flash('success', 'Recordatorio restaurado.');
        }

        if ($action === 'delete_reminder') {
            db()->prepare('DELETE FROM reminders WHERE id = :id AND user_id = :user_id')
                ->execute(['id' => (int) ($_POST['reminder_id'] ?? 0), 'user_id' => $userId]);
            flash('success', 'Recordatorio eliminado.');
        }
    } catch (Throwable $exception) {
        error_log('Error en notificaciones: ' . $exception->getMessage());
        flash('danger', 'No se pudo procesar la acción.');
    }

    header('Location: ' . url_to('notifications/index.php'));
    exit;
}

$editReminder = null;
if (isset($_GET['edit_reminder'])) {
    $editStatement = db()->prepare('SELECT * FROM reminders WHERE id = :id AND user_id = :user_id LIMIT 1');
    $editStatement->execute(['id' => (int) $_GET['edit_reminder'], 'user_id' => $userId]);
    $editReminder = $editStatement->fetch() ?: null;
}

$notificationCount = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :user_id');
$notificationCount->execute(['user_id' => $userId]);
$notificationPagination = pagination_state($notificationPage, $notificationsPerPage, (int) $notificationCount->fetchColumn());

$notificationsStatement = db()->prepare(
    'SELECT *
     FROM notifications
     WHERE user_id = :user_id
     ORDER BY read_at IS NOT NULL, created_at DESC
     LIMIT :limit OFFSET :offset'
);
$notificationsStatement->bindValue('user_id', $userId, PDO::PARAM_INT);
$notificationsStatement->bindValue('limit', $notificationPagination['limit'], PDO::PARAM_INT);
$notificationsStatement->bindValue('offset', $notificationPagination['offset'], PDO::PARAM_INT);
$notificationsStatement->execute();
$notifications = $notificationsStatement->fetchAll();

$remindersStatement = db()->prepare(
    'SELECT *
     FROM reminders
     WHERE user_id = :user_id
     ORDER BY FIELD(status, "pendiente", "completado", "cancelado"), due_at ASC'
);
$remindersStatement->execute(['user_id' => $userId]);
$reminders = $remindersStatement->fetchAll();

$messagesStatement = db()->prepare(
    'SELECT *
     FROM contact_messages
     WHERE user_id = :user_id
       AND status <> "archivado"
     ORDER BY FIELD(status, "nuevo", "leido", "respondido", "archivado"), created_at DESC
     LIMIT 30'
);
$messagesStatement->execute(['user_id' => $userId]);
$messages = $messagesStatement->fetchAll();

$announcements = db()->query(
    'SELECT *
     FROM announcements
     WHERE is_active = 1
       AND (visible_from IS NULL OR visible_from <= NOW())
       AND (visible_until IS NULL OR visible_until >= NOW())
     ORDER BY created_at DESC
     LIMIT 5'
)->fetchAll();

$unreadCount = 0;
foreach ($notifications as $notification) {
    if (empty($notification['read_at'])) {
        $unreadCount++;
    }
}

$unreadMessages = 0;
foreach ($messages as $message) {
    if (($message['status'] ?? '') === 'nuevo') {
        $unreadMessages++;
    }
}

$pendingCount = 0;
$overdueCount = 0;
foreach ($reminders as $reminder) {
    if ($reminder['status'] === 'pendiente') {
        $pendingCount++;
        if (strtotime($reminder['due_at']) < time()) {
            $overdueCount++;
        }
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
                <span class="eyebrow">Centro de actividad</span>
                <h1>Notificaciones y recordatorios</h1>
                <p>Revisa avisos importantes, anuncios del sistema y crea recordatorios para mantener tu portafolio actualizado.</p>
            </div>
            <form method="post" action="<?= e(url_to('notifications/index.php')); ?>">
                <?= csrf_field(); ?>
                <input type="hidden" name="action" value="mark_all_read">
                <button class="btn btn-outline-primary" type="submit">Marcar todo leído</button>
            </form>
        </div>

        <section class="stats-grid" aria-label="Resumen de notificaciones">
            <article class="stat-card"><div class="stat-icon primary"><i class="bi bi-bell" aria-hidden="true"></i></div><div><span>No leídas</span><strong><?= e((string) $unreadCount); ?></strong></div></article>
            <article class="stat-card"><div class="stat-icon success"><i class="bi bi-envelope" aria-hidden="true"></i></div><div><span>Mensajes</span><strong><?= e((string) $unreadMessages); ?></strong></div></article>
            <article class="stat-card"><div class="stat-icon info"><i class="bi bi-calendar-event" aria-hidden="true"></i></div><div><span>Recordatorios</span><strong><?= e((string) $pendingCount); ?></strong></div></article>
            <article class="stat-card"><div class="stat-icon warning"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i></div><div><span>Vencidos</span><strong><?= e((string) $overdueCount); ?></strong></div></article>
        </section>

        <?php if (!empty($announcements)): ?>
            <section class="dashboard-card mb-3" aria-labelledby="announcementsTitle">
                <div class="card-heading"><div><span class="eyebrow">Anuncios</span><h2 id="announcementsTitle">Comunicados del sistema</h2></div></div>
                <div class="announcement-strip">
                    <?php foreach ($announcements as $announcement): ?>
                        <article class="announcement-card <?= e($announcement['type']); ?>">
                            <strong><?= e($announcement['title']); ?></strong>
                            <p><?= e($announcement['body']); ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <section class="dashboard-card mb-3" aria-labelledby="messagesListTitle">
            <div class="card-heading">
                <div>
                    <span class="eyebrow">Mensajes recibidos</span>
                    <h2 id="messagesListTitle">Bandeja de mensajes</h2>
                </div>
                <span class="status-pill <?= $unreadMessages > 0 ? 'warning' : 'success'; ?>"><?= e((string) $unreadMessages); ?> nuevos</span>
            </div>

            <?php if (empty($messages)): ?>
                <div class="empty-state">
                    <i class="bi bi-envelope" aria-hidden="true"></i>
                    <h3>Sin mensajes</h3>
                    <p>Aquí verás mensajes de visitantes de tu portafolio o de otros usuarios de la plataforma.</p>
                    <?php if (($currentUser['role_name'] ?? '') === 'estudiante'): ?>
                        <a class="btn btn-primary mt-2" href="<?= e(url_to('recruiters/index.php')); ?>">Buscar reclutadores</a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="message-center-list">
                    <?php foreach ($messages as $message): ?>
                        <article class="message-center-item <?= ($message['status'] ?? '') === 'nuevo' ? 'unread' : ''; ?>">
                            <div class="message-center-meta">
                                <strong><?= e($message['sender_name']); ?></strong>
                                <span><?= e($message['sender_email']); ?></span>
                                <time datetime="<?= e($message['created_at']); ?>"><?= e(date('d/m/Y H:i', strtotime($message['created_at']))); ?></time>
                            </div>
                            <h3><?= e($message['subject']); ?></h3>
                            <p><?= nl2br(e($message['message'])); ?></p>
                            <div class="project-links">
                                <a class="btn btn-outline-primary btn-sm" href="mailto:<?= e($message['sender_email']); ?>?subject=<?= rawurlencode('Re: ' . $message['subject']); ?>">Responder</a>
                                <?php if (($message['status'] ?? '') === 'nuevo'): ?>
                                    <form method="post" action="<?= e(url_to('notifications/index.php')); ?>">
                                        <?= csrf_field(); ?>
                                        <input type="hidden" name="action" value="mark_message_read">
                                        <input type="hidden" name="message_id" value="<?= e((string) $message['id']); ?>">
                                        <button class="btn btn-primary btn-sm" type="submit">Marcar leído</button>
                                    </form>
                                <?php endif; ?>
                                <form method="post" action="<?= e(url_to('notifications/index.php')); ?>">
                                    <?= csrf_field(); ?>
                                    <input type="hidden" name="action" value="archive_message">
                                    <input type="hidden" name="message_id" value="<?= e((string) $message['id']); ?>">
                                    <button class="btn btn-outline-primary btn-sm" type="submit">Archivar</button>
                                </form>
                                <form method="post" action="<?= e(url_to('notifications/index.php')); ?>" onsubmit="return confirm('¿Eliminar este mensaje?');">
                                    <?= csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete_message">
                                    <input type="hidden" name="message_id" value="<?= e((string) $message['id']); ?>">
                                    <button class="btn btn-outline-danger btn-sm" type="submit">Eliminar</button>
                                </form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <div class="notifications-layout">
            <section class="dashboard-card" aria-labelledby="notificationsListTitle">
                <div class="card-heading">
                    <div><span class="eyebrow">Notificaciones</span><h2 id="notificationsListTitle">Bandeja de avisos</h2></div>
                    <span class="status-pill <?= $unreadCount > 0 ? 'warning' : 'success'; ?>"><?= e((string) $unreadCount); ?> sin leer</span>
                </div>

                <?php if (empty($notifications)): ?>
                    <div class="empty-state"><i class="bi bi-bell" aria-hidden="true"></i><h3>Sin notificaciones</h3><p>Cuando ocurra algo importante aparecerá aquí.</p></div>
                <?php else: ?>
                    <div class="notification-center-list">
                        <?php foreach ($notifications as $notification): ?>
                            <article class="notification-center-item <?= empty($notification['read_at']) ? 'unread' : ''; ?>">
                                <span class="notification-dot <?= e($notification['type']); ?>" aria-hidden="true"></span>
                                <div>
                                    <h3><?= e($notification['title']); ?></h3>
                                    <p><?= e($notification['message']); ?></p>
                                    <time datetime="<?= e($notification['created_at']); ?>"><?= e(date('d/m/Y H:i', strtotime($notification['created_at']))); ?></time>
                                </div>
                                <div class="project-links">
                                    <?php if (empty($notification['read_at'])): ?>
                                        <form method="post" action="<?= e(url_to('notifications/index.php')); ?>">
                                            <?= csrf_field(); ?>
                                            <input type="hidden" name="action" value="mark_read">
                                            <input type="hidden" name="notification_id" value="<?= e((string) $notification['id']); ?>">
                                            <button class="btn btn-outline-primary btn-sm" type="submit">Leída</button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="post" action="<?= e(url_to('notifications/index.php')); ?>" onsubmit="return confirm('¿Eliminar esta notificación?');">
                                        <?= csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete_notification">
                                        <input type="hidden" name="notification_id" value="<?= e((string) $notification['id']); ?>">
                                        <button class="btn btn-outline-danger btn-sm" type="submit">Eliminar</button>
                                    </form>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <?php $pagination = $notificationPagination; require __DIR__ . '/../../../HTML/components/pagination.php'; ?>
                <?php endif; ?>
            </section>

            <aside class="dashboard-card" aria-labelledby="reminderFormTitle">
                <div class="card-heading">
                    <div><span class="eyebrow">Recordatorios</span><h2 id="reminderFormTitle"><?= $editReminder ? 'Editar recordatorio' : 'Nuevo recordatorio'; ?></h2></div>
                    <?php if ($editReminder): ?><a class="small fw-bold" href="<?= e(url_to('notifications/index.php')); ?>">Cancelar</a><?php endif; ?>
                </div>

                <form class="needs-validation" method="post" action="<?= e(url_to('notifications/index.php')); ?>" novalidate>
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action" value="save_reminder">
                    <input type="hidden" name="reminder_id" value="<?= e((string) ($editReminder['id'] ?? 0)); ?>">

                    <div class="mb-3">
                        <label class="form-label" for="title">Título</label>
                        <input class="form-control" id="title" name="title" value="<?= e($editReminder['title'] ?? ''); ?>" required>
                        <div class="invalid-feedback">Ingresa un título.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="description">Descripción</label>
                        <textarea class="form-control" id="description" name="description" rows="3"><?= e($editReminder['description'] ?? ''); ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="dueAt">Fecha y hora</label>
                        <input class="form-control" type="datetime-local" id="dueAt" name="due_at" value="<?= e(!empty($editReminder['due_at']) ? date('Y-m-d\TH:i', strtotime($editReminder['due_at'])) : ''); ?>" required>
                        <div class="invalid-feedback">Selecciona fecha y hora.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="priority">Prioridad</label>
                        <select class="form-select" id="priority" name="priority">
                            <?php foreach (['baja' => 'Baja', 'media' => 'Media', 'alta' => 'Alta'] as $value => $label): ?>
                                <option value="<?= e($value); ?>" <?= ($editReminder['priority'] ?? 'media') === $value ? 'selected' : ''; ?>><?= e($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label class="form-label" for="relatedUrl">Enlace relacionado</label>
                        <input class="form-control" type="url" id="relatedUrl" name="related_url" value="<?= e($editReminder['related_url'] ?? ''); ?>" placeholder="https://...">
                    </div>
                    <button class="btn btn-primary w-100" type="submit"><?= $editReminder ? 'Actualizar recordatorio' : 'Crear recordatorio'; ?></button>
                </form>
            </aside>
        </div>

        <section class="dashboard-card mt-3" aria-labelledby="remindersTitle">
            <div class="card-heading"><div><span class="eyebrow">Agenda</span><h2 id="remindersTitle">Centro de recordatorios</h2></div><span class="status-pill warning"><?= e((string) $pendingCount); ?> pendientes</span></div>
            <?php if (empty($reminders)): ?>
                <div class="empty-state"><i class="bi bi-calendar-check" aria-hidden="true"></i><h3>Sin recordatorios</h3><p>Crea recordatorios para actualizar tu CV, portafolio, certificaciones o proyectos.</p></div>
            <?php else: ?>
                <div class="reminder-grid">
                    <?php foreach ($reminders as $reminder): ?>
                        <?php $isOverdue = $reminder['status'] === 'pendiente' && strtotime($reminder['due_at']) < time(); ?>
                        <article class="reminder-card priority-<?= e($reminder['priority']); ?> <?= $isOverdue ? 'overdue' : ''; ?> <?= $reminder['status'] === 'completado' ? 'completed' : ''; ?>">
                            <div class="reminder-card-head">
                                <div>
                                    <span class="project-category"><?= e($reminder['priority']); ?> · <?= e($reminder['status']); ?></span>
                                    <h3><?= e($reminder['title']); ?></h3>
                                </div>
                                <i class="bi <?= $isOverdue ? 'bi-exclamation-triangle' : 'bi-calendar-event'; ?>" aria-hidden="true"></i>
                            </div>
                            <?php if (!empty($reminder['description'])): ?><p><?= e($reminder['description']); ?></p><?php endif; ?>
                            <p class="reminder-date"><?= e(date('d/m/Y H:i', strtotime($reminder['due_at']))); ?></p>
                            <div class="project-links">
                                <?php if (!empty($reminder['related_url'])): ?><a class="btn btn-outline-primary btn-sm" href="<?= e($reminder['related_url']); ?>">Abrir enlace</a><?php endif; ?>
                                <a class="btn btn-outline-primary btn-sm" href="<?= e(url_to('notifications/index.php?edit_reminder=' . $reminder['id'])); ?>">Editar</a>
                                <form method="post" action="<?= e(url_to('notifications/index.php')); ?>">
                                    <?= csrf_field(); ?>
                                    <input type="hidden" name="reminder_id" value="<?= e((string) $reminder['id']); ?>">
                                    <?php if ($reminder['status'] === 'completado'): ?>
                                        <input type="hidden" name="action" value="restore_reminder">
                                        <button class="btn btn-outline-primary btn-sm" type="submit">Restaurar</button>
                                    <?php else: ?>
                                        <input type="hidden" name="action" value="complete_reminder">
                                        <button class="btn btn-primary btn-sm" type="submit">Completar</button>
                                    <?php endif; ?>
                                </form>
                                <form method="post" action="<?= e(url_to('notifications/index.php')); ?>" onsubmit="return confirm('¿Eliminar este recordatorio?');">
                                    <?= csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete_reminder">
                                    <input type="hidden" name="reminder_id" value="<?= e((string) $reminder['id']); ?>">
                                    <button class="btn btn-outline-danger btn-sm" type="submit">Eliminar</button>
                                </form>
                            </div>
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
