<?php
declare(strict_types=1);

require_once __DIR__ . '/../../PHP/helpers/auth.php';
require_once __DIR__ . '/../../PHP/helpers/upload.php';
require_once __DIR__ . '/../../PHP/helpers/navigation.php';

$menuUser = auth_user();
if (!$menuUser) {
    return;
}

$menuAvatarUrl = public_upload_url($menuUser['avatar_path'] ?? null);
$menuRole = (string) ($menuUser['role_name'] ?? 'estudiante');
$menuSettingsUrl = url_to('settings/index.php');
$menuDashboardUrl = nav_post_login_url($menuRole);
?>
<div class="user-menu dropdown">
    <button
        class="user-menu-trigger dropdown-toggle"
        type="button"
        id="userMenuButton"
        data-bs-toggle="dropdown"
        aria-expanded="false"
        aria-haspopup="true"
        aria-label="Menú de usuario de <?= e($menuUser['full_name'] ?? 'Usuario'); ?>"
    >
        <?php if ($menuAvatarUrl !== ''): ?>
            <img class="user-menu-avatar" src="<?= e($menuAvatarUrl); ?>" alt="" width="36" height="36">
        <?php else: ?>
            <span class="user-menu-avatar user-menu-avatar-fallback" aria-hidden="true"><?= e(strtoupper(substr($menuUser['full_name'] ?? 'U', 0, 1))); ?></span>
        <?php endif; ?>
        <span class="user-menu-label">
            <strong><?= e($menuUser['full_name'] ?? 'Usuario'); ?></strong>
            <small><?= e(ucfirst($menuRole)); ?></small>
        </span>
    </button>

    <ul class="dropdown-menu dropdown-menu-end user-menu-panel" aria-labelledby="userMenuButton">
        <li class="dropdown-header">
            <span class="user-menu-panel-name"><?= e($menuUser['full_name'] ?? 'Usuario'); ?></span>
            <span class="user-menu-panel-email"><?= e($menuUser['email'] ?? ''); ?></span>
        </li>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item" href="<?= e($menuDashboardUrl); ?>"><i class="bi bi-grid-1x2" aria-hidden="true"></i> Mi panel</a></li>
        <?php if (in_array($menuRole, ['estudiante'], true)): ?>
            <li><a class="dropdown-item" href="<?= e(url_to('profile/index.php')); ?>"><i class="bi bi-person" aria-hidden="true"></i> Mi perfil</a></li>
        <?php endif; ?>
        <li><a class="dropdown-item" href="<?= e($menuSettingsUrl); ?>"><i class="bi bi-gear" aria-hidden="true"></i> Configuración</a></li>
        <li><hr class="dropdown-divider"></li>
        <li>
            <a class="dropdown-item user-menu-logout" href="<?= e(url_to('auth/logout.php')); ?>">
                <i class="bi bi-box-arrow-right" aria-hidden="true"></i> Cerrar sesión
            </a>
        </li>
    </ul>
</div>
