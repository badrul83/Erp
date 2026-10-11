<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, private');

try {
    $configFile = __DIR__ . '/config.php';
    if (!is_file($configFile)) {
        throw new RuntimeException('SERVER_SETUP_REQUIRED: Copy config.example.php to config.php and set the MySQL credentials.');
    }
    $config = require $configFile;
    require_once __DIR__ . '/php/App.php';
    $input = json_decode((string) file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($input) || !isset($input['method'])) {
        throw new InvalidArgumentException('Invalid API request.');
    }
    $app = new DepoApp($config);
    echo json_encode(['ok' => true, 'result' => $app->dispatch((string) $input['method'], (array) ($input['args'] ?? []))], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
} catch (Throwable $error) {
    http_response_code($error instanceof DepoAuthException ? 401 : 400);
    echo json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_UNESCAPED_UNICODE);
}
