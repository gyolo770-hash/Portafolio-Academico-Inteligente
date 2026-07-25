<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';

if (!function_exists('portfolio_public_url')) {
    function portfolio_public_url(?string $slug): string
    {
        $slug = trim((string) $slug);
        if ($slug === '') {
            return '';
        }

        $encodedSlug = rawurlencode($slug);

        return url_to('portfolio/index.php?slug=' . $encodedSlug);
    }
}

if (!function_exists('portfolio_pretty_url')) {
    function portfolio_pretty_url(?string $slug): string
    {
        $slug = trim((string) $slug);
        if ($slug === '') {
            return '';
        }

        return url_to('p/' . rawurlencode($slug));
    }
}

if (!function_exists('avatar_markup')) {
    function avatar_markup(?string $avatarPath, string $name, string $class = 'avatar-sm', string $size = '40'): string
    {
        $avatarUrl = function_exists('public_upload_url') ? public_upload_url($avatarPath) : '';

        if ($avatarUrl !== '') {
            return '<img class="' . e($class) . '" src="' . e($avatarUrl) . '" alt="Foto de ' . e($name) . '" loading="lazy" width="' . e($size) . '" height="' . e($size) . '">';
        }

        $initial = strtoupper(substr(trim($name) ?: 'E', 0, 1));

        return '<span class="' . e($class) . ' avatar-initial" aria-hidden="true">' . e($initial) . '</span>';
    }
}
