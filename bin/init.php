#!/usr/bin/env php
<?php
declare(strict_types=1);
$root = dirname(__DIR__);
if (!is_file("$root/config.php")) copy("$root/config.example.php", "$root/config.php");
require "$root/src/App.php";
$app = new BanquiseApp(require "$root/config.php");
$location = $app->databaseDriver === 'sqlite'
    ? (string)$app->config['database']
    : (string)($app->config['database_name'] ?? 'banquise');
echo ucfirst($app->databaseDriver) . " database initialized at {$location}\nEdit config.php before serving Banquise.\n";
