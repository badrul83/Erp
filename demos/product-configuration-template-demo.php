<?php
declare(strict_types=1);

$demo = __DIR__ . '/product-configuration-template-demo.html';
if (!is_file($demo)) {
    http_response_code(404);
    exit('Product configuration demo is missing.');
}

header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
readfile($demo);
