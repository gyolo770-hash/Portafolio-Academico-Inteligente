<?php
declare(strict_types=1);
?>
<header class="dashboard-header" aria-label="Barra superior del panel">
    <div class="dashboard-header-start">
        <a class="dashboard-header-brand" href="<?= e(url_to('index.php')); ?>">
            <?php $logoVariant = 'compact'; require __DIR__ . '/logo-brand.php'; ?>
        </a>
    </div>

    <div class="dashboard-header-actions">
        <a class="dashboard-header-action" href="<?= e(url_to('notifications/index.php')); ?>" aria-label="Notificaciones">
            <i class="bi bi-bell" aria-hidden="true"></i>
        </a>
        <?php require __DIR__ . '/user-menu.php'; ?>
    </div>
</header>
