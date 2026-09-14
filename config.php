<?php

// Load .env file if it exists
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2) + [null, ''];
        $key = trim($key);
        $value = trim($value);
        if (!empty($key) && !isset($_ENV[$key]) && !isset($_SERVER[$key])) {
            putenv("{$key}={$value}");
        }
    }
}

return [
    'photo_prism_base_url' => getenv('PHOTO_PRISM_BASE_URL') ?: '',
    'photo_prism_access_token' => getenv('PHOTO_PRISM_ACCESS_TOKEN') ?: '',
    'mosaic_columns' => 12,
    'mosaic_rows' => 12,
    'mosaic_limit' => 12 * 12,
];
