#!/usr/bin/env php
<?php
declare(strict_types=1);
$root = dirname(__DIR__);
if (!is_file("$root/config.php")) copy("$root/config.example.php", "$root/config.php");
require "$root/src/App.php";
require "$root/src/Auth.php";
$app = new BanquiseApp(require "$root/config.php");
$location = $app->databaseDriver === 'sqlite'
    ? (string)$app->config['database']
    : (string)($app->config['database_name'] ?? 'banquise');
echo ucfirst($app->databaseDriver) . " database initialized at {$location}\nEdit config.php before serving Banquise.\n";

if ($app->hasAnyUser()) {
    echo "An administrator account already exists; skipping bootstrap.\n";
    exit(0);
}

echo "\nNo users exist yet. Let's create the first administrator account.\n";
echo "This is the only account created without an emailed setup link, since no\n";
echo "mail transport can be trusted before an administrator exists.\n\n";

if (!stream_isatty(STDIN)) {
    fwrite(STDERR, "Standard input is not interactive; run `php bin/init.php` from a terminal to bootstrap the first administrator.\n");
    exit(1);
}

function prompt(string $label): string
{
    echo "$label: ";
    $line = fgets(STDIN);
    return $line === false ? '' : trim($line);
}

function promptPassword(string $label): string
{
    echo "$label: ";
    if (stripos(PHP_OS, 'WIN') === 0) {
        $line = fgets(STDIN);
        return $line === false ? '' : trim($line);
    }
    system('stty -echo');
    $line = fgets(STDIN);
    system('stty echo');
    echo "\n";
    return $line === false ? '' : trim($line);
}

$email = prompt('Administrator email');
$displayName = prompt('Display name');
while (true) {
    $password = promptPassword('Password (at least 12 characters)');
    $confirmation = promptPassword('Confirm password');
    if ($password !== $confirmation) {
        echo "Passwords did not match; try again.\n";
        continue;
    }
    try {
        $app->createBootstrapAdministrator($email, $displayName, $password);
        break;
    } catch (InvalidArgumentException $e) {
        echo $e->getMessage() . "\n";
    }
}

echo "Administrator account created for $email.\n";
