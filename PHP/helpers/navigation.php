<?php
declare(strict_types=1);

if (!function_exists('nav_current_role')) {
    function nav_current_role(): ?string
    {
        return $_SESSION['user_role'] ?? null;
    }
}

if (!function_exists('nav_sidebar_items')) {
    function nav_sidebar_items(): array
    {
        $role = nav_current_role();

        $studentItems = [
            'dashboard' => ['label' => 'Panel', 'icon' => 'bi-grid-1x2', 'url' => url_to('dashboard/index.php')],
            'perfil' => ['label' => 'Perfil', 'icon' => 'bi-person', 'url' => url_to('profile/index.php')],
            'proyectos' => ['label' => 'Proyectos', 'icon' => 'bi-kanban', 'url' => url_to('projects/index.php')],
            'certificados' => ['label' => 'Certificados', 'icon' => 'bi-award', 'url' => url_to('certifications/index.php')],
            'habilidades' => ['label' => 'Habilidades', 'icon' => 'bi-stars', 'url' => url_to('skills/index.php')],
            'cv' => ['label' => 'CV', 'icon' => 'bi-file-earmark-text', 'url' => url_to('resume/index.php')],
            'asesor' => ['label' => 'Asesor IA', 'icon' => 'bi-robot', 'url' => url_to('advisor/index.php')],
            'reclutadores' => ['label' => 'Reclutadores', 'icon' => 'bi-briefcase', 'url' => url_to('recruiters/index.php')],
            'notificaciones' => ['label' => 'Notificaciones', 'icon' => 'bi-bell', 'url' => url_to('notifications/index.php')],
            'configuracion' => ['label' => 'Configuración', 'icon' => 'bi-gear', 'url' => url_to('settings/index.php')],
        ];

        $recruiterItems = [
            'reclutador' => ['label' => 'Talento', 'icon' => 'bi-search', 'url' => url_to('recruiter/index.php')],
            'notificaciones' => ['label' => 'Notificaciones', 'icon' => 'bi-bell', 'url' => url_to('notifications/index.php')],
            'configuracion' => ['label' => 'Configuración', 'icon' => 'bi-gear', 'url' => url_to('settings/index.php')],
        ];

        $adminItems = [
            'admin' => ['label' => 'Administración', 'icon' => 'bi-shield-lock', 'url' => url_to('admin/index.php')],
            'notificaciones' => ['label' => 'Notificaciones', 'icon' => 'bi-bell', 'url' => url_to('notifications/index.php')],
            'configuracion' => ['label' => 'Configuración', 'icon' => 'bi-gear', 'url' => url_to('settings/index.php')],
        ];

        if ($role === 'administrador') {
            return $adminItems;
        }

        if ($role === 'reclutador') {
            return $recruiterItems;
        }

        return $studentItems;
    }
}

if (!function_exists('nav_mobile_items')) {
    function nav_mobile_items(): array
    {
        $role = nav_current_role();

        if ($role === 'reclutador') {
            return [
                'reclutador' => ['label' => 'Talento', 'icon' => 'bi-search', 'url' => url_to('recruiter/index.php')],
                'notificaciones' => ['label' => 'Avisos', 'icon' => 'bi-bell', 'url' => url_to('notifications/index.php')],
                'configuracion' => ['label' => 'Ajustes', 'icon' => 'bi-gear', 'url' => url_to('settings/index.php')],
            ];
        }

        if ($role === 'administrador') {
            return [
                'admin' => ['label' => 'Admin', 'icon' => 'bi-shield-lock', 'url' => url_to('admin/index.php')],
                'notificaciones' => ['label' => 'Avisos', 'icon' => 'bi-bell', 'url' => url_to('notifications/index.php')],
                'configuracion' => ['label' => 'Ajustes', 'icon' => 'bi-gear', 'url' => url_to('settings/index.php')],
            ];
        }

        return [
            'dashboard' => ['label' => 'Panel', 'icon' => 'bi-grid-1x2', 'url' => url_to('dashboard/index.php')],
            'reclutadores' => ['label' => 'Talento', 'icon' => 'bi-briefcase', 'url' => url_to('recruiters/index.php')],
            'notificaciones' => ['label' => 'Avisos', 'icon' => 'bi-bell', 'url' => url_to('notifications/index.php')],
            'configuracion' => ['label' => 'Ajustes', 'icon' => 'bi-gear', 'url' => url_to('settings/index.php')],
        ];
    }
}

if (!function_exists('nav_post_login_url')) {
    function nav_post_login_url(string $roleName): string
    {
        if ($roleName === 'reclutador') {
            return url_to('recruiter/index.php');
        }

        if ($roleName === 'administrador') {
            return url_to('admin/index.php');
        }

        return url_to('dashboard/index.php');
    }
}
