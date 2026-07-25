<?php
declare(strict_types=1);

require_once __DIR__ . '/../../PHP/helpers/navigation.php';

$activeItem = $activeItem ?? 'dashboard';
$sidebarItems = nav_sidebar_items();
?>
<aside class="app-sidebar" aria-label="Navegación del panel">
    <div class="sidebar-brand">
        <?php $logoVariant = 'sidebar'; $showBrandText = true; require __DIR__ . '/logo-brand.php'; ?>
    </div>

    <nav class="sidebar-nav">
        <?php foreach ($sidebarItems as $key => $item): ?>
            <a class="sidebar-link <?= $activeItem === $key ? 'active' : ''; ?>" href="<?= e($item['url']); ?>" <?= $activeItem === $key ? 'aria-current="page"' : ''; ?>>
                <i class="bi <?= e($item['icon']); ?>" aria-hidden="true"></i>
                <span><?= e($item['label']); ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar-footer">
        <a class="sidebar-link sidebar-logout" href="<?= e(url_to('auth/logout.php')); ?>">
            <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
            <span>Cerrar sesión</span>
        </a>
    </div>
</aside>
