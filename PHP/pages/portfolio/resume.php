<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../helpers/resume.php';

$slug = strtolower(trim((string) ($_GET['slug'] ?? ($_GET['u'] ?? ''))));

$portfolioStatement = db()->prepare(
    'SELECT ps.user_id, ps.public_slug
     FROM portfolio_settings ps
     INNER JOIN users u ON u.id = ps.user_id
     WHERE ps.public_slug = :slug
       AND ps.is_public = 1
       AND u.status = "activo"
     LIMIT 1'
);
$portfolioStatement->execute(['slug' => $slug]);
$portfolio = $portfolioStatement->fetch();

if (!$portfolio) {
    http_response_code(404);
    exit('CV no disponible.');
}

$resumeStatement = db()->prepare(
    'SELECT *
     FROM resumes
     WHERE user_id = :user_id
     ORDER BY FIELD(status, "generado", "publicado", "borrador"), updated_at DESC, created_at DESC
     LIMIT 1'
);
$resumeStatement->execute(['user_id' => $portfolio['user_id']]);
$resume = $resumeStatement->fetch();

$template = resume_valid_template($resume['template_name'] ?? 'profesional');
$accentColor = resume_valid_color($resume['accent_color'] ?? '#4F46E5');
$resumeTitle = $resume['title'] ?? 'CV académico';
$data = resume_collect_data((int) $portfolio['user_id'], true);
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($resumeTitle); ?> - PDF</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= e(asset_url('css/styles.css')); ?>" rel="stylesheet">
</head>
<body class="resume-export-body">
    <div class="resume-print-toolbar">
        <a class="btn btn-outline-primary" href="<?= e(url_to('portfolio/index.php?slug=' . urlencode($portfolio['public_slug']))); ?>">Volver al portafolio</a>
        <button class="btn btn-primary" type="button" onclick="window.print()">Guardar como PDF</button>
    </div>

    <?= resume_render($data, $template, $accentColor); ?>

    <script>
        window.addEventListener("load", () => {
            window.setTimeout(() => window.print(), 500);
        });
    </script>
</body>
</html>
