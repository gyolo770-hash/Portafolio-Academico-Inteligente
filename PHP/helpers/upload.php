<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';

if (!function_exists('detect_upload_mime')) {
    function detect_upload_mime(string $tmpPath, ?string $clientName = null): string
    {
        if (is_file($tmpPath) && class_exists('finfo')) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($tmpPath);
            if (is_string($mimeType) && $mimeType !== '' && $mimeType !== 'application/octet-stream') {
                return $mimeType;
            }
        }

        if (is_file($tmpPath) && function_exists('mime_content_type')) {
            $mimeType = mime_content_type($tmpPath);
            if (is_string($mimeType) && $mimeType !== '' && $mimeType !== 'application/octet-stream') {
                return $mimeType;
            }
        }

        if (is_file($tmpPath)) {
            $handle = fopen($tmpPath, 'rb');
            if ($handle !== false) {
                $bytes = fread($handle, 16) ?: '';
                fclose($handle);

                if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
                    return 'image/jpeg';
                }

                if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
                    return 'image/png';
                }

                if (str_starts_with($bytes, 'RIFF') && str_contains($bytes, 'WEBP')) {
                    return 'image/webp';
                }

                if (str_starts_with($bytes, '%PDF')) {
                    return 'application/pdf';
                }
            }
        }

        if ($clientName !== null && $clientName !== '') {
            $extension = strtolower(pathinfo($clientName, PATHINFO_EXTENSION));
            $extensionMap = [
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'webp' => 'image/webp',
                'pdf' => 'application/pdf',
                'txt' => 'text/plain',
                'doc' => 'application/msword',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'ppt' => 'application/vnd.ms-powerpoint',
                'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            ];

            if (isset($extensionMap[$extension])) {
                return $extensionMap[$extension];
            }
        }

        return 'application/octet-stream';
    }
}

if (!function_exists('assert_image_upload')) {
    function assert_image_upload(string $tmpPath): void
    {
        if (!function_exists('getimagesize')) {
            return;
        }

        $imageInfo = @getimagesize($tmpPath);
        if ($imageInfo === false) {
            throw new RuntimeException('El archivo seleccionado no es una imagen válida.');
        }
    }
}

if (!function_exists('upload_avatar')) {
    function upload_avatar(array $file, int $userId)
    {
        if (empty($file['tmp_name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('No se pudo subir la imagen. Intenta nuevamente.');
        }

        if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
            throw new RuntimeException('La foto de perfil no debe superar 2 MB.');
        }

        $allowedTypes = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];

        $mimeType = detect_upload_mime($file['tmp_name'], $file['name'] ?? null);

        if (!isset($allowedTypes[$mimeType])) {
            throw new RuntimeException('Solo se permiten imágenes JPG, PNG o WebP.');
        }

        assert_image_upload($file['tmp_name']);

        $uploadDirectory = APP_ROOT . DIRECTORY_SEPARATOR . 'Images' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'avatars';

        if (!is_dir($uploadDirectory)) {
            mkdir($uploadDirectory, 0775, true);
        }

        $fileName = 'avatar_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $allowedTypes[$mimeType];
        $destination = $uploadDirectory . DIRECTORY_SEPARATOR . $fileName;

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new RuntimeException('No se pudo guardar la foto de perfil.');
        }

        return 'uploads/avatars/' . $fileName;
    }
}

if (!function_exists('public_upload_url')) {
    function public_upload_url(?string $path): string
    {
        if (empty($path)) {
            return '';
        }

        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }

        $normalizedPath = ltrim(str_replace('\\', '/', $path), '/');

        if (str_starts_with($normalizedPath, 'uploads/')) {
            $normalizedPath = 'Images/' . $normalizedPath;
        }

        return rtrim(BASE_URL, '/') . '/' . $normalizedPath;
    }
}

if (!function_exists('upload_absolute_path')) {
    function upload_absolute_path(string $path): ?string
    {
        $appRoot = realpath(APP_ROOT) ?: APP_ROOT;
        $normalizedPath = ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);

        if (str_starts_with(str_replace(DIRECTORY_SEPARATOR, '/', $normalizedPath), 'uploads/')) {
            $normalizedPath = 'Images' . DIRECTORY_SEPARATOR . $normalizedPath;
        }

        $fullPath = APP_ROOT . DIRECTORY_SEPARATOR . $normalizedPath;
        $realPath = realpath($fullPath);

        if ($realPath && is_file($realPath) && str_starts_with(strtolower($realPath), strtolower($appRoot))) {
            return $realPath;
        }

        return null;
    }
}

if (!function_exists('certificate_download_url')) {
    function certificate_download_url(int $certificationId): string
    {
        return url_to('certifications/download.php?id=' . $certificationId);
    }
}

if (!function_exists('serve_uploaded_file')) {
    function serve_uploaded_file(string $path, string $downloadName, bool $inline = true): void
    {
        $absolutePath = upload_absolute_path($path);

        if ($absolutePath === null) {
            http_response_code(404);
            echo 'Archivo no encontrado.';
            exit;
        }

        $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
        $mimeTypes = [
            'pdf' => 'application/pdf',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
        ];
        $mimeType = $mimeTypes[$extension] ?? 'application/octet-stream';
        $safeName = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $downloadName) ?: 'archivo.' . $extension;

        if (!headers_sent()) {
            header('Content-Type: ' . $mimeType);
            header('Content-Length: ' . (string) filesize($absolutePath));
            header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $safeName . '"');
            header('X-Content-Type-Options: nosniff');
        }

        readfile($absolutePath);
        exit;
    }
}

if (!function_exists('upload_project_screenshots')) {
    function upload_project_screenshots(array $files, int $userId): array
    {
        if (empty($files['name']) || !is_array($files['name'])) {
            return [];
        }

        $allowedTypes = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];
        $uploadDirectory = APP_ROOT . DIRECTORY_SEPARATOR . 'Images' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'projects';

        if (!is_dir($uploadDirectory)) {
            mkdir($uploadDirectory, 0775, true);
        }

        $savedPaths = [];

        foreach ($files['name'] as $index => $name) {
            $error = $files['error'][$index] ?? UPLOAD_ERR_NO_FILE;

            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            if ($error !== UPLOAD_ERR_OK) {
                throw new RuntimeException('No se pudo subir uno de los screenshots.');
            }

            if (($files['size'][$index] ?? 0) > 4 * 1024 * 1024) {
                throw new RuntimeException('Cada screenshot debe pesar máximo 4 MB.');
            }

            $tmpName = $files['tmp_name'][$index] ?? '';
            $mimeType = detect_upload_mime($tmpName, $name);

            if (!isset($allowedTypes[$mimeType])) {
                throw new RuntimeException('Los screenshots deben ser JPG, PNG o WebP.');
            }

            assert_image_upload($tmpName);

            $fileName = 'project_' . $userId . '_' . time() . '_' . $index . '_' . bin2hex(random_bytes(4)) . '.' . $allowedTypes[$mimeType];
            $destination = $uploadDirectory . DIRECTORY_SEPARATOR . $fileName;

            if (!move_uploaded_file($tmpName, $destination)) {
                throw new RuntimeException('No se pudo guardar uno de los screenshots.');
            }

            $savedPaths[] = 'uploads/projects/' . $fileName;
        }

        return $savedPaths;
    }
}

if (!function_exists('upload_certificate_pdf')) {
    function upload_certificate_pdf(array $file, int $userId)
    {
        if (empty($file['tmp_name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('No se pudo subir el certificado. Intenta nuevamente.');
        }

        if (($file['size'] ?? 0) > 8 * 1024 * 1024) {
            throw new RuntimeException('El PDF del certificado no debe superar 8 MB.');
        }

        $mimeType = detect_upload_mime($file['tmp_name'], $file['name'] ?? null);

        if ($mimeType !== 'application/pdf') {
            throw new RuntimeException('Solo se permiten certificados en formato PDF.');
        }

        $uploadDirectory = APP_ROOT . DIRECTORY_SEPARATOR . 'Images' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'certificates';

        if (!is_dir($uploadDirectory)) {
            mkdir($uploadDirectory, 0775, true);
        }

        $fileName = 'certificate_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.pdf';
        $destination = $uploadDirectory . DIRECTORY_SEPARATOR . $fileName;

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new RuntimeException('No se pudo guardar el PDF del certificado.');
        }

        return 'uploads/certificates/' . $fileName;
    }
}

if (!function_exists('upload_project_document')) {
    function upload_project_document(array $file, int $userId)
    {
        if (empty($file['tmp_name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('No se pudo subir la documentación. Intenta nuevamente.');
        }

        if (($file['size'] ?? 0) > 10 * 1024 * 1024) {
            throw new RuntimeException('La documentación no debe superar 10 MB.');
        }

        $allowedTypes = [
            'application/pdf' => 'pdf',
            'text/plain' => 'txt',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-powerpoint' => 'ppt',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        ];

        $mimeType = detect_upload_mime($file['tmp_name'], $file['name'] ?? null);

        if (!isset($allowedTypes[$mimeType])) {
            throw new RuntimeException('La documentación debe ser PDF, TXT, DOC, DOCX, PPT o PPTX.');
        }

        $uploadDirectory = APP_ROOT . DIRECTORY_SEPARATOR . 'Images' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'documents';

        if (!is_dir($uploadDirectory)) {
            mkdir($uploadDirectory, 0775, true);
        }

        $fileName = 'document_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $allowedTypes[$mimeType];
        $destination = $uploadDirectory . DIRECTORY_SEPARATOR . $fileName;

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new RuntimeException('No se pudo guardar la documentación del proyecto.');
        }

        return 'uploads/documents/' . $fileName;
    }
}

if (!function_exists('delete_upload_file')) {
    function delete_upload_file(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        $appRoot = realpath(APP_ROOT) ?: APP_ROOT;
        $normalizedPath = ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);

        if (str_starts_with(str_replace(DIRECTORY_SEPARATOR, '/', $normalizedPath), 'uploads/')) {
            $normalizedPath = 'Images' . DIRECTORY_SEPARATOR . $normalizedPath;
        }

        $fullPath = APP_ROOT . DIRECTORY_SEPARATOR . $normalizedPath;
        $realPath = realpath($fullPath);

        if ($realPath && is_file($realPath) && str_starts_with(strtolower($realPath), strtolower($appRoot))) {
            @unlink($realPath);
        }
    }
}

if (!function_exists('delete_upload_files')) {
    function delete_upload_files(array $paths): void
    {
        foreach ($paths as $path) {
            delete_upload_file(is_string($path) ? $path : null);
        }
    }
}

if (!function_exists('collect_user_upload_paths')) {
    function collect_user_upload_paths(int $userId): array
    {
        $paths = [];

        $avatarStatement = db()->prepare('SELECT avatar_path FROM users WHERE id = :user_id LIMIT 1');
        $avatarStatement->execute(['user_id' => $userId]);
        $avatarPath = $avatarStatement->fetchColumn();
        if ($avatarPath) {
            $paths[] = (string) $avatarPath;
        }

        foreach ([
            'SELECT image_path FROM projects WHERE user_id = :user_id AND image_path IS NOT NULL',
            'SELECT documentation_path FROM projects WHERE user_id = :user_id AND documentation_path IS NOT NULL',
            'SELECT ps.image_path
             FROM project_screenshots ps
             INNER JOIN projects p ON p.id = ps.project_id
             WHERE p.user_id = :user_id',
            'SELECT certificate_path FROM certifications WHERE user_id = :user_id AND certificate_path IS NOT NULL',
            'SELECT pdf_path FROM resumes WHERE user_id = :user_id AND pdf_path IS NOT NULL',
        ] as $sql) {
            $statement = db()->prepare($sql);
            $statement->execute(['user_id' => $userId]);

            foreach ($statement->fetchAll() as $row) {
                $path = reset($row);
                if ($path) {
                    $paths[] = (string) $path;
                }
            }
        }

        return array_values(array_unique($paths));
    }
}
