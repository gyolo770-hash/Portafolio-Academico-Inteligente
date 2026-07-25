<?php
declare(strict_types=1);

$flashMessages = flash_messages();

$flashLabels = [
    'success' => 'Éxito',
    'danger' => 'Error',
    'warning' => 'Advertencia',
    'info' => 'Información',
    'primary' => 'Aviso',
    'light' => 'Mensaje',
];
?>
<?php if (!empty($flashMessages)): ?>
    <div class="flash-stack" aria-live="polite" aria-atomic="true">
        <?php foreach ($flashMessages as $message): ?>
            <?php $alertType = (string) ($message['type'] ?? 'info'); ?>
            <div class="alert alert-<?= e($alertType); ?> alert-dismissible fade show" role="alert" aria-label="<?= e($flashLabels[$alertType] ?? 'Mensaje'); ?>">
                <span class="visually-hidden"><?= e($flashLabels[$alertType] ?? 'Mensaje'); ?>:</span>
                <?= e($message['message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar mensaje"></button>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
