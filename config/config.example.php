<?php
// Copy to config/config.php and set values for your environment. Never commit real secrets.
return [
    'app_name' => 'FoneFlex',
    'app_url' => 'http://localhost:8080',
    'app_env' => 'local',
    'app_debug' => true,
    'timezone' => 'Africa/Nairobi',
    'session_name' => 'foneflex_session',
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'foneflex',
        'username' => 'foneflex_user',
        'password' => 'CHANGE_THIS_PASSWORD',
        'charset' => 'utf8mb4',
    ],
    'security' => [
        'https_only' => false, // Set true in production after HTTPS is enabled.
        'data_encryption_key' => 'REPLACE_WITH_AT_LEAST_32_RANDOM_CHARACTERS', // Encrypts customer ID/passport numbers. Keep stable and backed up securely.
    ],
];
