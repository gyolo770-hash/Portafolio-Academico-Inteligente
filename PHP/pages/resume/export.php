<?php
declare(strict_types=1);

require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../helpers/resume.php';
require_student();

$currentUser = auth_user();
$userId = (int) $currentUser['id'];
$template = resume_valid_template((string) ($_GET['template'] ?? 'profesional'));
$accentColor = resume_valid_color((string) ($_GET['color'] ?? '#4F46E5'));
$resumeTitle = 'CV académico';

if (isset($_GET['resume_id'])) {
    $statement = db()->prepare('SELECT * FROM resumes WHERE id = :id AND user_id = :user_id LIMIT 1');
    $statement->execute(['id' => (int) $_GET['resume_id'], 'user_id' => $userId]);
    $resume = $statement->fetch();

    if ($resume) {
        $template = resume_valid_template($resume['template_name']);
        $accentColor = resume_valid_color($resume['accent_color']);
        $resumeTitle = $resume['title'];
        db()->prepare('UPDATE resumes SET status = :status WHERE id = :id AND user_id = :user_id')
            ->execute(['status' => 'generado', 'id' => $resume['id'], 'user_id' => $userId]);
    }
}

$data = resume_collect_data($userId);
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($resumeTitle); ?> - Exportar PDF</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= e(asset_url('css/styles.css')); ?>" rel="stylesheet">
</head>
<body class="resume-export-body">
    <div class="resume-print-toolbar">
        <a class="btn btn-outline-primary" href="<?= e(url_to('resume/index.php')); ?>">Volver al constructor</a>
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
