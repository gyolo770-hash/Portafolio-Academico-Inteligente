<?php
declare(strict_types=1);

require_once __DIR__ . '/../../PHP/helpers/navigation.php';

$activeItem = $activeItem ?? 'dashboard';
$mobileItems = nav_mobile_items();
?>
<div class="dashboard-mobile-nav" aria-label="Navegación rápida móvil">
    <?php foreach ($mobileItems as $key => $item): ?>
        <a href="<?= e($item['url']); ?>" <?= $activeItem === $key ? 'aria-current="page"' : ''; ?>>
            <i class="bi <?= e($item['icon']); ?>" aria-hidden="true"></i>
            <span><?= e($item['label']); ?></span>
        </a>
    <?php endforeach; ?>
    <a class="mobile-nav-logout" href="<?= e(url_to('auth/logout.php')); ?>">
        <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
        <span>Salir</span>
    </a>
</div>
