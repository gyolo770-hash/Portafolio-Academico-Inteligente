<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/security.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/upload.php';

$certificationId = (int) ($_GET['id'] ?? 0);

if ($certificationId <= 0) {
    http_response_code(404);
    echo 'Certificado no encontrado.';
    exit;
}

$statement = db()->prepare(
    'SELECT c.id, c.title, c.certificate_path, c.visibility, c.user_id, u.status AS user_status
     FROM certifications c
     INNER JOIN users u ON u.id = c.user_id
     WHERE c.id = :id
     LIMIT 1'
);
$statement->execute(['id' => $certificationId]);
$certification = $statement->fetch();

if (!$certification || empty($certification['certificate_path'])) {
    http_response_code(404);
    echo 'El PDF de esta certificación no está disponible.';
    exit;
}

$canDownload = false;

if (($certification['visibility'] ?? '') === 'publico' && ($certification['user_status'] ?? '') === 'activo') {
    $canDownload = true;
}

if (!$canDownload && auth_check()) {
    $viewerId = (int) ($_SESSION['user_id'] ?? 0);
    $viewer = auth_user();

    if ($viewerId === (int) $certification['user_id']) {
        $canDownload = true;
    }

    if (($viewer['role_name'] ?? '') === 'administrador') {
        $canDownload = true;
    }
}

if (!$canDownload) {
    http_response_code(403);
    echo 'No tienes permiso para ver este certificado.';
    exit;
}

serve_uploaded_file((string) $certification['certificate_path'], (string) $certification['title'] . '.pdf', true);
