<?php
/* Copy this file to config.php, then set the database credentials. Never upload
 * config.php to a public repository. cPanel users normally create these values
 * in cPanel > MySQL Databases first. */
return [
    'app_name' => 'Distribution Commission ERP',
    'version' => '2.0.0',
    'timezone' => 'Asia/Dhaka',
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'erp',
        'user' => 'root',
        'pass' => '',
        'charset' => 'utf8mb4',
    ],
];
