<?php
declare(strict_types=1);
?>
<nav class="navbar navbar-expand-lg app-navbar" aria-label="Navegación principal">
    <div class="container-fluid px-3 px-lg-4">
        <a class="navbar-brand d-flex align-items-center gap-2" href="<?= e(url_to('')); ?>">
            <?php $logoVariant = 'compact'; require __DIR__ . '/logo-brand.php'; ?>
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavbar" aria-controls="mainNavbar" aria-expanded="false" aria-label="Abrir menú">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="mainNavbar">
            <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-2">
                <?php if (!empty($_SESSION['user_id'])): ?>
                    <li class="nav-item d-flex align-items-center">
                        <?php require __DIR__ . '/user-menu.php'; ?>
                    </li>
                <?php else: ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= e(url_to('auth/login.php')); ?>">Iniciar sesión</a>
                    </li>
                    <li class="nav-item">
                        <a class="btn btn-primary btn-sm px-3" href="<?= e(url_to('auth/register.php')); ?>">Crear cuenta</a>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>
