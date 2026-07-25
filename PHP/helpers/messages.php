<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/schema.php';

if (!function_exists('send_inbox_message')) {
    function send_inbox_message(int $recipientUserId, array $sender, string $subject, string $message): void
    {
        $subject = trim($subject);
        $message = trim($message);

        if ($recipientUserId <= 0 || $subject === '' || $message === '') {
            throw new InvalidArgumentException('Los datos del mensaje no son válidos.');
        }

        if ((int) ($sender['id'] ?? 0) === $recipientUserId) {
            throw new InvalidArgumentException('No puedes enviarte un mensaje a ti mismo.');
        }

        $recipientStatement = db()->prepare(
            'SELECT u.id, u.full_name, u.email, u.status, r.name AS role_name
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE u.id = :id
             LIMIT 1'
        );
        $recipientStatement->execute(['id' => $recipientUserId]);
        $recipient = $recipientStatement->fetch();

        if (!$recipient || ($recipient['status'] ?? '') !== 'activo') {
            throw new RuntimeException('El destinatario no está disponible.');
        }

        if (($recipient['role_name'] ?? '') === 'reclutador' && !recruiter_accepts_student_messages((string) $recipient['email'])) {
            throw new RuntimeException('Este reclutador no está recibiendo mensajes de estudiantes por ahora.');
        }

        db()->prepare(
            'INSERT INTO contact_messages (user_id, sender_name, sender_email, subject, message, status)
             VALUES (:user_id, :sender_name, :sender_email, :subject, :message, :status)'
        )->execute([
            'user_id' => $recipientUserId,
            'sender_name' => (string) ($sender['full_name'] ?? 'Usuario'),
            'sender_email' => (string) ($sender['email'] ?? ''),
            'subject' => $subject,
            'message' => $message,
            'status' => 'nuevo',
        ]);

        db()->prepare(
            'INSERT INTO notifications (user_id, title, message, type)
             VALUES (:user_id, :title, :message, :type)'
        )->execute([
            'user_id' => $recipientUserId,
            'title' => 'Nuevo mensaje de ' . ($sender['full_name'] ?? 'un usuario'),
            'message' => $subject,
            'type' => 'info',
        ]);
    }
}

if (!function_exists('find_recruiter_user_id')) {
    function find_recruiter_user_id(string $email): ?int
    {
        ensure_runtime_schema();

        $statement = db()->prepare(
            'SELECT u.id
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             INNER JOIN recruiters rec ON rec.email = u.email
             WHERE u.email = :email
               AND r.name = "reclutador"
               AND u.status = "activo"
               AND rec.status = "activo"
               AND rec.accept_student_messages = 1
             LIMIT 1'
        );
        $statement->execute(['email' => strtolower(trim($email))]);
        $userId = $statement->fetchColumn();

        return $userId ? (int) $userId : null;
    }
}
