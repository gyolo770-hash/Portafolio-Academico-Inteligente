<?php
declare(strict_types=1);

require_once __DIR__ . '/../../middleware/auth.php';
require_role(['administrador']);

$type = (string) ($_GET['type'] ?? 'analytics');
$filename = 'reporte_' . $type . '_' . date('Ymd_His') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');
fwrite($output, "\xEF\xBB\xBF");

if ($type === 'users') {
    fputcsv($output, ['ID', 'Nombre', 'Correo', 'Usuario', 'Rol', 'Estado', 'Creado']);
    $rows = db()->query(
        'SELECT u.id, u.full_name, u.email, u.username, r.display_name, u.status, u.created_at
         FROM users u
         INNER JOIN roles r ON r.id = u.role_id
         ORDER BY u.created_at DESC'
    )->fetchAll();

    foreach ($rows as $row) {
        fputcsv($output, [
            $row['id'],
            $row['full_name'],
            $row['email'],
            $row['username'],
            $row['display_name'],
            $row['status'],
            $row['created_at'],
        ]);
    }
} elseif ($type === 'portfolios') {
    fputcsv($output, ['Estudiante', 'Slug', 'Visitas', 'Público']);
    $rows = db()->query(
        'SELECT u.full_name, ps.public_slug, COUNT(pv.id) AS visits, ps.is_public
         FROM portfolio_settings ps
         INNER JOIN users u ON u.id = ps.user_id
         LEFT JOIN portfolio_visits pv ON pv.user_id = u.id
         GROUP BY u.id, u.full_name, ps.public_slug, ps.is_public
         ORDER BY visits DESC'
    )->fetchAll();

    foreach ($rows as $row) {
        fputcsv($output, [
            $row['full_name'],
            $row['public_slug'],
            $row['visits'],
            (int) $row['is_public'] === 1 ? 'si' : 'no',
        ]);
    }
} else {
    fputcsv($output, ['Métrica', 'Total']);
    foreach ([
        'Usuarios' => 'SELECT COUNT(*) FROM users',
        'Portafolios públicos' => 'SELECT COUNT(*) FROM portfolio_settings WHERE is_public = 1',
        'Proyectos' => 'SELECT COUNT(*) FROM projects',
        'Certificaciones' => 'SELECT COUNT(*) FROM certifications',
        'Visitas' => 'SELECT COUNT(*) FROM portfolio_visits',
        'Mensajes' => 'SELECT COUNT(*) FROM contact_messages',
        'Recomendaciones IA' => 'SELECT COUNT(*) FROM recommendations',
    ] as $label => $sql) {
        fputcsv($output, [$label, (int) db()->query($sql)->fetchColumn()]);
    }
}

fclose($output);
exit;
