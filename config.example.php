<?php
declare(strict_types=1);

return [
    'db' => [
        'dsn' => 'mysql:host=localhost;dbname=YOUR_CPANEL_DATABASE;charset=utf8mb4',
        'user' => 'YOUR_CPANEL_DATABASE_USER',
        'password' => 'YOUR_DATABASE_PASSWORD',
    ],
    'app' => [
        'name' => 'Due Flow',
        'timezone' => 'Asia/Dhaka',
        'base_path' => '', // e.g. '/due-flow' when installed in a subdirectory
        'secure_cookies' => true,
    ],
    'sheets' => [
        'webhook_url' => '',
        'webhook_secret' => '',
    ],
];
