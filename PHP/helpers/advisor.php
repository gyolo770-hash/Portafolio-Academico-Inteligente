<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/security.php';

if (!function_exists('advisor_config')) {
    function advisor_config(): array
    {
        return require __DIR__ . '/../config/ai.php';
    }
}

if (!function_exists('advisor_collect_context')) {
    function advisor_collect_context(int $userId): array
    {
        $profileStatement = db()->prepare(
            'SELECT u.id, u.full_name, u.email, u.avatar_path,
                    up.about_me, up.career, up.phone, up.location, up.languages,
                    up.github_url, up.linkedin_url, up.portfolio_url, up.instagram_url,
                    up.profile_completion, up.visibility,
                    un.name AS university_name,
                    ps.public_slug, ps.is_public
             FROM users u
             LEFT JOIN user_profiles up ON up.user_id = u.id
             LEFT JOIN universities un ON un.id = up.university_id
             LEFT JOIN portfolio_settings ps ON ps.user_id = u.id
             WHERE u.id = :user_id
             LIMIT 1'
        );
        $profileStatement->execute(['user_id' => $userId]);
        $profile = $profileStatement->fetch() ?: [];

        $counts = [];
        foreach ([
            'projects' => 'SELECT COUNT(*) FROM projects WHERE user_id = :user_id',
            'public_projects' => 'SELECT COUNT(*) FROM projects WHERE user_id = :user_id AND visibility = "publico"',
            'certifications' => 'SELECT COUNT(*) FROM certifications WHERE user_id = :user_id',
            'public_certifications' => 'SELECT COUNT(*) FROM certifications WHERE user_id = :user_id AND visibility = "publico"',
            'skills' => 'SELECT COUNT(*) FROM user_skills WHERE user_id = :user_id',
            'advanced_skills' => 'SELECT COUNT(*) FROM user_skills WHERE user_id = :user_id AND proficiency IN ("avanzado", "experto")',
            'education' => 'SELECT COUNT(*) FROM education WHERE user_id = :user_id',
            'resumes' => 'SELECT COUNT(*) FROM resumes WHERE user_id = :user_id',
            'generated_resumes' => 'SELECT COUNT(*) FROM resumes WHERE user_id = :user_id AND status IN ("generado", "publicado")',
            'portfolio_visits' => 'SELECT COUNT(*) FROM portfolio_visits WHERE user_id = :user_id',
        ] as $key => $sql) {
            $statement = db()->prepare($sql);
            $statement->execute(['user_id' => $userId]);
            $counts[$key] = (int) $statement->fetchColumn();
        }

        $skillStatement = db()->prepare(
            'SELECT s.name, us.proficiency, sc.type
             FROM user_skills us
             INNER JOIN skills s ON s.id = us.skill_id
             LEFT JOIN skill_categories sc ON sc.id = s.category_id
             WHERE us.user_id = :user_id
             ORDER BY us.proficiency DESC, s.name ASC'
        );
        $skillStatement->execute(['user_id' => $userId]);
        $skills = $skillStatement->fetchAll();

        $projectStatement = db()->prepare('SELECT title, category, status, repository_url, demo_url, visibility FROM projects WHERE user_id = :user_id ORDER BY updated_at DESC LIMIT 8');
        $projectStatement->execute(['user_id' => $userId]);

        $certificationStatement = db()->prepare('SELECT title, issuer, category, credential_url, certificate_path, visibility FROM certifications WHERE user_id = :user_id ORDER BY created_at DESC LIMIT 8');
        $certificationStatement->execute(['user_id' => $userId]);

        return [
            'profile' => $profile,
            'counts' => $counts,
            'skills' => $skills,
            'projects' => $projectStatement->fetchAll(),
            'certifications' => $certificationStatement->fetchAll(),
        ];
    }
}

if (!function_exists('advisor_profile_strength')) {
    function advisor_profile_strength(array $context): int
    {
        $profile = $context['profile'];
        $counts = $context['counts'];
        $checks = [
            !empty($profile['full_name']),
            !empty($profile['avatar_path']),
            !empty($profile['about_me']),
            !empty($profile['career']),
            !empty($profile['university_name']),
            !empty($profile['phone']) || !empty($profile['location']),
            !empty($profile['languages']),
            !empty($profile['github_url']) || !empty($profile['linkedin_url']) || !empty($profile['portfolio_url']),
            $counts['education'] > 0,
            $counts['projects'] >= 2,
            $counts['certifications'] >= 1,
            $counts['skills'] >= 5,
            $counts['generated_resumes'] >= 1,
            (int) ($profile['is_public'] ?? 0) === 1,
        ];

        $completed = 0;
        foreach ($checks as $check) {
            if ($check) {
                $completed++;
            }
        }

        return (int) round(($completed / count($checks)) * 100);
    }
}

if (!function_exists('advisor_rule_recommendations')) {
    function advisor_rule_recommendations(array $context): array
    {
        $profile = $context['profile'];
        $counts = $context['counts'];
        $skills = array_map(static function ($skill) {
            return strtolower((string) $skill['name']);
        }, $context['skills']);
        $recommendations = [];

        $add = static function (string $title, string $content, string $category, string $priority) use (&$recommendations): void {
            $recommendations[] = compact('title', 'content', 'category', 'priority');
        };

        if (empty($profile['about_me']) || empty($profile['career'])) {
            $add('Fortalece tu presentación profesional', 'Completa tu sección "Acerca de mí" con 3 partes: quién eres, qué sabes hacer y qué tipo de oportunidad buscas. Esto mejora admisiones, becas y reclutamiento.', 'perfil', 'alta');
        }

        if (empty($profile['avatar_path'])) {
            $add('Agrega una foto de perfil clara', 'Sube una fotografía profesional o académica. Una imagen consistente aumenta confianza y hace que tu portafolio se sienta completo.', 'perfil', 'media');
        }

        if ($counts['projects'] < 2) {
            $add('Publica al menos dos proyectos sólidos', 'Crea proyectos con problema, solución, tecnologías, screenshots y enlaces. Prioriza proyectos que demuestren habilidades reales, no solo ejercicios pequeños.', 'proyectos', 'alta');
        }

        if ($counts['public_projects'] < 1 && $counts['projects'] > 0) {
            $add('Haz público tu mejor proyecto', 'Selecciona tu proyecto más completo y cambia su visibilidad a público para que aparezca en tu portafolio compartible.', 'proyectos', 'media');
        }

        if ($counts['skills'] < 5) {
            $add('Construye un mapa de habilidades', 'Registra al menos 5 habilidades combinando técnicas y blandas. Relaciónalas con proyectos o certificaciones para que tengan evidencia.', 'habilidades', 'alta');
        }

        if ($counts['advanced_skills'] < 2 && $counts['skills'] >= 3) {
            $add('Define habilidades fuertes para posicionarte', 'Marca como avanzado o experto las habilidades que realmente puedes defender en entrevista y relaciónalas con proyectos específicos.', 'habilidades', 'media');
        }

        if (!in_array('git', $skills, true) && !in_array('github', $skills, true)) {
            $add('Aprende y evidencia Git/GitHub', 'Agrega Git o GitHub como habilidad y úsalo en tus proyectos. Es una señal clave para internships, software y colaboración profesional.', 'habilidades', 'media');
        }

        if ($counts['certifications'] < 1) {
            $add('Agrega una certificación verificable', 'Busca una certificación gratuita o accesible relacionada con tu carrera. Sube el PDF, URL de credencial e ID para respaldar tu avance.', 'becas', 'media');
        }

        if ($counts['generated_resumes'] < 1) {
            $add('Genera tu primer CV académico', 'Usa el constructor de CV, elige una plantilla y exporta una versión PDF. Revisa que incluya proyectos, educación, certificaciones y habilidades.', 'cv', 'alta');
        } else {
            $add('Optimiza tu CV para ATS', 'Usa palabras clave de tu carrera y tecnologías reales. Mantén secciones claras: educación, proyectos, habilidades, certificaciones y enlaces verificables.', 'cv', 'media');
        }

        if ((int) ($profile['is_public'] ?? 0) !== 1) {
            $add('Activa tu portafolio público', 'Cambia la visibilidad del portafolio a público cuando tengas perfil, proyectos y CV listos. Así podrás compartir tu URL en becas, admisiones y entrevistas.', 'perfil', 'alta');
        }

        if ($counts['education'] < 1) {
            $add('Registra tu formación académica', 'Agrega tu institución, programa, fechas y logros relevantes. Esto ayuda a contextualizar tu nivel académico ante universidades y reclutadores.', 'carrera', 'alta');
        }

        if ($counts['portfolio_visits'] > 0 && $counts['public_projects'] < 2) {
            $add('Convierte visitas en oportunidades', 'Ya tienes visitas en tu portafolio. Mejora la conversión mostrando más proyectos públicos y un formulario de contacto claro.', 'perfil', 'media');
        }

        $add('Prepárate para entrevista con evidencia', 'Elige 2 proyectos y practica explicar: problema, decisiones técnicas, retos, resultados y qué mejorarías. Esto conecta tu portafolio con entrevistas reales.', 'carrera', 'media');
        $add('Plan semanal de mejora', 'Esta semana completa una acción medible: actualizar perfil, publicar un proyecto, agregar una certificación o exportar tu CV. Mantén el avance visible.', 'perfil', 'baja');

        return $recommendations;
    }
}

if (!function_exists('advisor_prompt')) {
    function advisor_prompt(array $context): string
    {
        return 'Actua como asesor de portafolios academicos. Devuelve solo JSON con una clave "recommendations", arreglo de 5 objetos con title, content, category y priority. Contexto: '
            . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

if (!function_exists('advisor_parse_external_items')) {
    function advisor_parse_external_items(string $content): array
    {
        $content = trim($content);
        $content = preg_replace('/^```json\s*|\s*```$/', '', $content) ?? $content;
        $decoded = json_decode($content, true);
        $items = is_array($decoded) ? ($decoded['recommendations'] ?? $decoded) : [];

        if (!is_array($items)) {
            return [];
        }

        return array_values(array_filter(array_map(static function ($item) {
            if (!is_array($item) || empty($item['title']) || empty($item['content'])) {
                return null;
            }

            return [
                'title' => (string) $item['title'],
                'content' => (string) $item['content'],
                'category' => (string) ($item['category'] ?? 'perfil'),
                'priority' => in_array(($item['priority'] ?? 'media'), ['alta', 'media', 'baja'], true) ? (string) $item['priority'] : 'media',
            ];
        }, $items)));
    }
}

if (!function_exists('advisor_external_recommendations')) {
    function advisor_external_recommendations(array $context, string $provider, array $config): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('La extensión cURL de PHP es necesaria para proveedores IA externos.');
        }

        $prompt = advisor_prompt($context);

        if ($provider === 'openai') {
            $apiKey = (string) ($config['openai']['api_key'] ?? '');
            if ($apiKey === '') {
                throw new RuntimeException('OPENAI_API_KEY no está configurada.');
            }

            $url = 'https://api.openai.com/v1/chat/completions';
            $payload = [
                'model' => $config['openai']['model'] ?? 'gpt-4o-mini',
                'messages' => [
                    ['role' => 'system', 'content' => 'Eres un asesor academico preciso y accionable.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.35,
            ];
            $headers = ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json'];
            $contentPath = ['choices', 0, 'message', 'content'];
        } else {
            $apiKey = (string) ($config['gemini']['api_key'] ?? '');
            if ($apiKey === '') {
                throw new RuntimeException('GEMINI_API_KEY no está configurada.');
            }

            $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($config['gemini']['model'] ?? 'gemini-1.5-flash') . ':generateContent?key=' . rawurlencode($apiKey);
            $payload = ['contents' => [['parts' => [['text' => $prompt]]]]];
            $headers = ['Content-Type: application/json'];
            $contentPath = ['candidates', 0, 'content', 'parts', 0, 'text'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
        ] + http_curl_ssl_options());
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $status >= 400) {
            throw new RuntimeException('El proveedor IA no respondió correctamente: ' . ($error ?: 'HTTP ' . $status));
        }

        $data = json_decode((string) $response, true);
        foreach ($contentPath as $segment) {
            $data = $data[$segment] ?? null;
        }

        return is_string($data) ? advisor_parse_external_items($data) : [];
    }
}

if (!function_exists('advisor_generate')) {
    function advisor_generate(int $userId): array
    {
        $config = advisor_config();
        $provider = strtolower((string) ($config['provider'] ?? 'rules'));
        $context = advisor_collect_context($userId);
        $score = advisor_profile_strength($context);

        $items = advisor_rule_recommendations($context);

        if ($provider === 'openai' || $provider === 'gemini') {
            try {
                $externalItems = advisor_external_recommendations($context, $provider, $config);
                if (!empty($externalItems)) {
                    $items = $externalItems;
                }
            } catch (Throwable $exception) {
                error_log('Advisor external provider fallback: ' . $exception->getMessage());
            }
        }

        db()->beginTransaction();
        db()->prepare('DELETE FROM recommendations WHERE user_id = :user_id AND is_completed = 0')
            ->execute(['user_id' => $userId]);

        foreach ($items as $item) {
            db()->prepare(
                'INSERT INTO recommendations (user_id, title, content, category, priority)
                 VALUES (:user_id, :title, :content, :category, :priority)'
            )->execute([
                'user_id' => $userId,
                'title' => $item['title'],
                'content' => $item['content'],
                'category' => $item['category'],
                'priority' => $item['priority'],
            ]);
        }

        db()->prepare(
            'INSERT INTO notifications (user_id, title, message, type)
             VALUES (:user_id, :title, :message, :type)'
        )->execute([
            'user_id' => $userId,
            'title' => 'Recomendaciones actualizadas',
            'message' => 'Tu asesor IA generó nuevas recomendaciones. Puntaje actual: ' . $score . '%.',
            'type' => 'info',
        ]);

        db()->commit();

        return [
            'score' => $score,
            'provider' => $provider,
            'items' => $items,
        ];
    }
}
