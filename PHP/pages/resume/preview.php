<?php
declare(strict_types=1);

require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../helpers/resume.php';
require_student();

$currentUser = auth_user();
$userId = (int) $currentUser['id'];
$template = resume_valid_template((string) ($_GET['template'] ?? 'profesional'));
$accentColor = resume_valid_color((string) ($_GET['color'] ?? '#4F46E5'));
$data = resume_collect_data($userId);
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Vista previa del CV</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= e(asset_url('css/styles.css')); ?>" rel="stylesheet">
</head>
<body class="resume-preview-body">
    <?= resume_render($data, $template, $accentColor); ?>
</body>
</html>
