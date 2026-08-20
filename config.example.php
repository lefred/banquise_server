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
    // Generate with: openssl rand -hex 32
    'enrollment_token_hash' => 'sha256:REPLACE_ME_WITH_SHA256_OF_TOKEN',
    // Generate with: php -r "echo 'base64:', base64_encode(random_bytes(32)), PHP_EOL;"
    // Required for dedicated tokens; changing it makes existing available tokens unreadable.
    'enrollment_token_encryption_key' => 'base64:REPLACE_ME_WITH_BASE64_KEY',
    // Initial mode; the administrator can change and persist it from the web UI.
    'enrollment_mode' => 'shared',
    // Initial mode, also changeable from the web UI. 'public': download_url
    // points at each plugin's original GitHub release asset. 'private':
    // Banquise downloads and mirrors every plugin file under
    // public/artifacts/ so MariaDB servers never need internet access,
    // fetching from this server's own public_base_url instead.
    'distribution_mode' => 'public',
    // Initial mode, also changeable from the web UI. 'local': this instance
    // curates its own catalog. 'remote': it mirrors another catalog's
    // catalog.json/.minisig verbatim instead (configured from the web UI,
    // not here — there's no config key for the URL/public key).
    'catalog_mode' => 'local',
    'session_name' => 'banquise_admin',
    // Keep this comfortably above the agents' poll interval.
    'online_threshold_seconds' => 180,

    // Staff accounts (administrator / fleet_manager / fleet_viewer / plugin_manager)
    // are managed from the Admin > Users panel after the first administrator is
    // created interactively by `php bin/init.php`. New users receive an emailed
    // setup link that expires after this many seconds.
    'setup_token_ttl_seconds' => 86400,

    // Minimum seconds between two public plugin submissions from the same address.
    'submission_rate_limit_seconds' => 120,

    // Outbound mail (account setup links, new-submission notifications to
    // administrators and plugin managers). Leave smtp_host empty to disable mail;
    // affected actions still succeed, they just skip sending.
    'smtp_host' => '',
    'smtp_port' => 587,
    'smtp_encryption' => 'starttls', // starttls | tls | none
    'smtp_user' => '',
    'smtp_password' => '',
    'mail_from_address' => 'banquise@example.com',
    'mail_from_name' => 'Banquise',
];
