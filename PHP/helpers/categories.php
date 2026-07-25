<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

if (!function_exists('default_certification_categories')) {
    function default_certification_categories(): array
    {
        return [
            'Programación',
            'Diseño',
            'Idiomas',
            'Ciberseguridad',
            'Datos',
            'Nube',
            'Productividad',
            'Habilidades blandas',
            'Otro',
        ];
    }
}

if (!function_exists('certification_category_options')) {
    function certification_category_options(): array
    {
        $categories = default_certification_categories();

        try {
            $statement = db()->prepare('SELECT setting_value FROM system_settings WHERE setting_key = :key LIMIT 1');
            $statement->execute(['key' => 'certification_categories']);
            $value = $statement->fetchColumn();

            if (is_string($value) && $value !== '') {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $category) {
                        if (is_string($category) && $category !== '') {
                            $categories[] = $category;
                        }
                    }
                }
            }
        } catch (Throwable $exception) {
            error_log('No se pudieron cargar categorías de certificación: ' . $exception->getMessage());
        }

        $categories = array_values(array_unique(array_map(static function ($category) {
            return trim((string) $category);
        }, $categories)));

        return array_values(array_filter($categories));
    }
}

if (!function_exists('save_certification_category')) {
    function save_certification_category(string $name): void
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('El nombre de la categoría es obligatorio.');
        }

        $categories = certification_category_options();
        if (!in_array($name, $categories, true)) {
            $categories[] = $name;
        }

        db()->prepare(
            'INSERT INTO system_settings (setting_key, setting_value, description)
             VALUES (:setting_key, :setting_value, :description)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        )->execute([
            'setting_key' => 'certification_categories',
            'setting_value' => json_encode(array_values($categories), JSON_UNESCAPED_UNICODE),
            'description' => 'Categorías sugeridas para certificaciones.',
        ]);
    }
}

if (!function_exists('delete_certification_category')) {
    function delete_certification_category(string $name): void
    {
        $name = trim($name);
        $categories = array_values(array_filter(
            certification_category_options(),
            static function ($category) use ($name) {
                return $category !== $name;
            }
        ));

        db()->prepare(
            'INSERT INTO system_settings (setting_key, setting_value, description)
             VALUES (:setting_key, :setting_value, :description)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        )->execute([
            'setting_key' => 'certification_categories',
            'setting_value' => json_encode($categories, JSON_UNESCAPED_UNICODE),
            'description' => 'Categorías sugeridas para certificaciones.',
        ]);
    }
}
