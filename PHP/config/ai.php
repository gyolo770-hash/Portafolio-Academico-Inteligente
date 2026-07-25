<?php
declare(strict_types=1);

require_once __DIR__ . '/app.php';

return [
    /*
     * Proveedor disponible:
     * - rules: motor local basado en reglas, sin API externa.
     * - openai: integración con OpenAI.
     * - gemini: integración con Google Gemini.
     */
    'provider' => strtolower(env_value('AI_PROVIDER', 'gemini')),
    'openai' => [
        'api_key' => env_value('OPENAI_API_KEY'),
        'model' => env_value('OPENAI_MODEL', 'gpt-4o-mini'),
    ],
    'gemini' => [
        'api_key' => env_value('GEMINI_API_KEY'),
        'model' => env_value('GEMINI_MODEL', 'gemini-1.5-flash'),
    ],
];
