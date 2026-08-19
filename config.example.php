<?php
declare(strict_types=1);

return [
    // SQLite (default)
    'database_driver' => 'sqlite',
    'database' => __DIR__ . '/var/banquise.sqlite',

    // MariaDB alternative: set database_driver to 'mariadb' and configure these.
    // 'database_host' => '127.0.0.1',
    // 'database_port' => 3306,
    // 'database_name' => 'banquise',
    // 'database_user' => 'banquise',
    // 'database_password' => 'REPLACE_ME',
    // 'database_socket' => '',
    // Run bin/init.php once, then set this to false so the web account needs only DML privileges.
    // 'database_auto_initialize' => true,
    'catalog' => __DIR__ . '/public/catalog.json',
    'signing_key' => '/etc/banquise/catalog.key',
    'catalog_public_key' => '/etc/banquise/catalog.pub',
    'public_base_url' => 'https://banquise.example.com',
    'admin_user' => 'admin',
    // Generate with: php -r "echo password_hash('change-me', PASSWORD_ARGON2ID), PHP_EOL;"
    'admin_password_hash' => 'REPLACE_ME',
    // Generate with: openssl rand -hex 32
    'enrollment_token_hash' => 'sha256:REPLACE_ME_WITH_SHA256_OF_TOKEN',
    // Generate with: php -r "echo 'base64:', base64_encode(random_bytes(32)), PHP_EOL;"
    // Required for dedicated tokens; changing it makes existing available tokens unreadable.
    'enrollment_token_encryption_key' => 'base64:REPLACE_ME_WITH_BASE64_KEY',
    // Initial mode; the administrator can change and persist it from the web UI.
    'enrollment_mode' => 'shared',
    'session_name' => 'banquise_admin',
    // Keep this comfortably above the agents' poll interval.
    'online_threshold_seconds' => 180,
];
