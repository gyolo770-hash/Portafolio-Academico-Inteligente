<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

if (!function_exists('ensure_runtime_schema')) {
    function ensure_runtime_schema(): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }

        $checked = true;

        try {
            $universityColumn = db()->query("SHOW COLUMNS FROM universities LIKE 'is_verified'")->fetch();
            if (!$universityColumn) {
                db()->exec('ALTER TABLE universities ADD COLUMN is_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER website');
            }

            $recruiterMessagesColumn = db()->query("SHOW COLUMNS FROM recruiters LIKE 'accept_student_messages'")->fetch();
            if (!$recruiterMessagesColumn) {
                db()->exec('ALTER TABLE recruiters ADD COLUMN accept_student_messages TINYINT(1) NOT NULL DEFAULT 1 AFTER status');
            }
        } catch (Throwable $exception) {
            error_log('No se pudo actualizar el esquema en tiempo de ejecución: ' . $exception->getMessage());
        }
    }
}

if (!function_exists('recruiter_accepts_student_messages')) {
    function recruiter_accepts_student_messages(string $email): bool
    {
        ensure_runtime_schema();

        $statement = db()->prepare(
            'SELECT accept_student_messages
             FROM recruiters
             WHERE email = :email
               AND status = "activo"
             LIMIT 1'
        );
        $statement->execute(['email' => strtolower(trim($email))]);
        $value = $statement->fetchColumn();

        return $value === false || (int) $value === 1;
    }
}
