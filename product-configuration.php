<?php
declare(strict_types=1);

$page = __DIR__ . '/product-configuration.html';
if (!is_file($page)) {
    http_response_code(404);
    exit('Product Configuration page is unavailable.');
}

header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
readfile($page);
