<?php
/**
 * set_openrouter_model.php — Update OpenRouter model setting
 *
 * Usage:
 *   php scripts/set_openrouter_model.php z-ai/glm-5
 */

require_once __DIR__ . '/../includes/helpers.php';
cybokron_init();
ensureCliExecution();

$model = $argv[1] ?? '';
$model = trim((string) $model);

if ($model === '' || !preg_match('/^[a-zA-Z0-9._\/-]{3,120}$/', $model)) {
    fwrite(STDERR, "Invalid model id. Example: z-ai/glm-5\n");
    exit(1);
}

Database::upsert('settings', [
    'key' => 'openrouter_model',
    'value' => $model,
], ['value']);

echo "OpenRouter model updated to: {$model}\n";
