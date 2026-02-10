<?php

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit;
}

$envFile = __DIR__ . '/.env';

if (!file_exists($envFile)) {
    throw new RuntimeException('.env file missing');
}

$lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

foreach ($lines as $line) {
    if (str_starts_with(trim($line), '#')) {
        continue;
    }

    if (!str_contains($line, '=')) {
        continue;
    }

    [$key, $value] = explode('=', $line, 2);
    $key = trim($key);
    $value = trim($value);

    putenv("$key=$value");
    $_ENV[$key] = $value;
}
