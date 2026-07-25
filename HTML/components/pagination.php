<?php
declare(strict_types=1);

require_once __DIR__ . '/../../PHP/helpers/pagination.php';

$pagination = $pagination ?? null;
if (!$pagination || (int) ($pagination['total_pages'] ?? 1) <= 1) {
    return;
}
?>
<nav class="pagination-nav" aria-label="Paginación">
    <ul class="pagination mb-0">
        <li class="page-item <?= $pagination['page'] <= 1 ? 'disabled' : ''; ?>">
            <a class="page-link" href="<?= e(pagination_query(['page' => max(1, $pagination['page'] - 1)])); ?>">Anterior</a>
        </li>
        <li class="page-item disabled">
            <span class="page-link">Página <?= e((string) $pagination['page']); ?> de <?= e((string) $pagination['total_pages']); ?></span>
        </li>
        <li class="page-item <?= $pagination['page'] >= $pagination['total_pages'] ? 'disabled' : ''; ?>">
            <a class="page-link" href="<?= e(pagination_query(['page' => min($pagination['total_pages'], $pagination['page'] + 1)])); ?>">Siguiente</a>
        </li>
    </ul>
</nav>
