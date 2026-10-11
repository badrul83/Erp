<?php
declare(strict_types=1);

/* The browser UI remains the existing vanilla-JS UI. PHP assembles the former
 * Apps Script includes on the server, so a cPanel upload needs no build step. */
$template = file_get_contents(__DIR__ . '/Index.html');
if ($template === false) {
    http_response_code(500);
    exit('Application template is missing.');
}
$page = preg_replace_callback("/<\?!=\s*include\('([A-Za-z]+)'\);\s*\?>/", static function (array $match): string {
    $file = __DIR__ . '/' . $match[1] . '.html';
    return is_file($file) ? (string) file_get_contents($file) : '';
}, $template);
header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
echo $page;
