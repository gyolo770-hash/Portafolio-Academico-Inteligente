<?php
declare(strict_types=1);

if (!function_exists('pagination_state')) {
    function pagination_state(int $page, int $perPage, int $total): array
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $totalPages = max(1, (int) ceil($total / $perPage));

        if ($page > $totalPages) {
            $page = $totalPages;
        }

        return [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
            'offset' => ($page - 1) * $perPage,
            'limit' => $perPage,
        ];
    }
}

if (!function_exists('pagination_query')) {
    function pagination_query(array $overrides = []): string
    {
        $params = array_merge($_GET, $overrides);
        $params['page'] = max(1, (int) ($params['page'] ?? 1));
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';

        return $path . '?' . http_build_query($params);
    }
}
