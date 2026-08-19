# Banquise Server

Banquise Server is a small PHP 8.2+ control plane for managing signed MariaDB
plugin catalogs and a fleet of `banquise_agent` instances. It uses SQLite (default) or MariaDB Server (preferred), a
server-rendered administration interface, and a JSON HTTPS API with no PHP
framework or JavaScript build chain.

## What it does

- imports the latest matching Linux release assets from a GitHub repository;
- creates or updates an alphabetically sorted `catalog.json`;
- calculates SHA-256 itself, identifies the `.so`, and signs the final catalog
  with Minisign;
- registers new MariaDB servers in quarantine and requires admin approval;
- records heartbeat, MariaDB version, OS, architecture, and plugin state;
- queues typed install, update, load, and uninstall tasks per server;
- uses a unique revocable bearer credential for every enrolled server.

## Requirements

- PHP 8.2 or newer with PDO SQLite, PDO MariaDB and Sodium;
- `curl`, `jq`, `tar`, `sha256sum`, and `minisign`;
- an HTTPS web server whose document root is `public/`;
- PHP functions `proc_open` and `password_hash` enabled.

## Installation

```sh
cp config.example.php config.php
mkdir -p var
chmod 0750 var
php -r "echo password_hash('replace-this-admin-password', PASSWORD_ARGON2ID), PHP_EOL;"
openssl rand -hex 32
php -r "echo 'base64:', base64_encode(random_bytes(32)), PHP_EOL;"
```

Put the password hash in `admin_password_hash`. The second command is the raw
enrollment token distributed to MariaDB hosts. Store only its hash in config:

```sh
printf %s 'RAW_ENROLLMENT_TOKEN' | sha256sum
```

Configure the result as `sha256:HEX_DIGEST` in `enrollment_token_hash`. Put the
third command's result in `enrollment_token_encryption_key`; it encrypts unused
dedicated tokens at rest and must remain stable. Set the public HTTPS URL and
the offline-protected Minisign key paths, then initialize:

```sh
php bin/init.php
```

### Database backend

SQLite remains the default and requires only:

```php
'database_driver' => 'sqlite',
'database' => __DIR__ . '/var/banquise.sqlite',
```

For MariaDB, first create a schema and application account as a database
administrator. Replace the example password before running these statements:

```sql
CREATE SCHEMA IF NOT EXISTS banquise
  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin;
CREATE USER IF NOT EXISTS 'banquise'@'127.0.0.1'
  IDENTIFIED BY 'replace-with-a-long-random-password';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES
  ON banquise.* TO 'banquise'@'127.0.0.1';
```

Then configure:

```php
'database_driver' => 'mariadb',
'database_host' => '127.0.0.1',
'database_port' => 3306,
'database_name' => 'banquise',
'database_user' => 'banquise',
'database_password' => 'replace-with-a-long-random-password',
'database_socket' => '', // Set this instead of host/port for a Unix socket.
'database_auto_initialize' => true,
```

Run `php bin/init.php` once. After initialization, set
`database_auto_initialize` to `false`; the web account then needs only
`SELECT`, `INSERT`, `UPDATE`, and `DELETE`. The MariaDB DDL is available in
`schema.mariadb.sql`. Existing SQLite data is not copied automatically when
switching drivers.

Set `online_threshold_seconds` higher than the largest configured agent polling
interval so healthy low-frequency agents are not shown as offline.

The PHP/web-server account needs write access to `var/`, `public/catalog.json`,
and `public/catalog.json.minisig`, plus read access to the signing key. For a
higher-security production deployment, place signing behind a separate local
service or hardware-backed signer instead of giving the web worker direct key
access.

Example PHP development server (development only):

```sh
php -S 127.0.0.1:8080 -t public public/index.php
```

Production must use HTTPS. Configure Apache `mod_rewrite` or route missing
paths to `public/index.php`; the included `.htaccess` supports Apache. Ensure
both `/catalog.json` and `/catalog.json.minisig` are publicly readable.

## Enrollment lifecycle

1. In dedicated mode, an administrator generates up to ten available tokens
   and copies one to a MariaDB host. Tokens contain no server metadata. Shared
   mode continues to use the hash configured in `config.php`.
2. The agent pins the independently installed catalog public key and submits
   its key ID, server UID, MariaDB version, OS, and architecture with the
   enrollment token.
3. Banquise atomically consumes a dedicated token, creates the registration,
   and returns a unique random agent credential. A consumed token immediately
   disappears from the available-token list.
4. The server appears in quarantine. Polls update inventory but return no work.
5. An administrator approves it in the UI. Later polls may return queued typed
   tasks and the authenticated signed-catalog URL.
6. Completed tasks are acknowledged. Unacknowledged deliveries are leased and
   become eligible for redelivery after five minutes.

The enrollment token is used only for bootstrap. After registration the agent
uses its unique bearer credential from `banquise-agent.state`; removing the
local enrollment-token file does not affect an enrolled agent. Deleting an
agent in Banquise revokes that credential. If the state file is lost, delete
the incomplete registration and enroll again with a new dedicated token.

The repository-import dialog prompts for the encrypted Minisign key password on
every signing operation. PHP sends it to Minisign through a private standard-
input pipe; it is never stored, logged, included in command arguments, placed in
the environment, or retained in the session. Protect the encrypted signing key
with strict file ownership and isolate the PHP account. For hardware-backed
keys, configure `signing_key` as empty and integrate a separate signing service;
an unsigned catalog update will be rejected by all agents.

## GitHub catalog import

Use **Add GitHub repository** in the Catalog panel. The importer expects assets
named:

```text
NAME-vVERSION-mariadbMAJOR.MINOR-linux-ARCH.tar.gz
```

Set `GITHUB_TOKEN` in the PHP-FPM environment for private repositories or a
higher API rate limit. Imports are atomic and the resulting catalog is signed
before it is served to agents.

Auto-detection scans the source archive attached to the exact release tag. It
recognizes all server declaration constants: storage engine, full-text parser,
daemon, information schema, audit, replication, authentication, password
validation, encryption, data type, and function. Multiple declarations are
deduplicated in stable MariaDB enum order; for example a library containing
`MariaDB_DATA_TYPE_PLUGIN` and `MariaDB_FUNCTION_PLUGIN` becomes
`DATA TYPE, FUNCTION`.

Maturity is derived from `MariaDB_PLUGIN_MATURITY_UNKNOWN`, `EXPERIMENTAL`,
`ALPHA`, `BETA`, `GAMMA`, or `STABLE` in the release source. If several levels
are declared, Banquise reports the least mature one conservatively. Only when
no maturity declaration exists does it fall back to the GitHub prerelease and
major-version-zero heuristic. Explicit values entered in the import form still
override auto-detection.

Each catalog card also provides:

- **Edit** to change every catalog field manually;
- **Refresh from GitHub** to discover the latest release again, download and
  hash its assets, re-detect types/maturity/license/description, and replace
  matching build entries. This happens even when the release version is
  unchanged;
- **Delete** to remove one exact name/MariaDB-version/architecture/soname
  combination without uninstalling it from existing agents.

Every mutation requests the encrypted Minisign key password. Banquise writes a
staging catalog, signs it through a private stdin pipe, verifies the new
signature with the configured public key, and only then replaces the published
catalog and signature. Invalid passwords, malformed edits, and GitHub failures
leave the live pair untouched. Entries are alphabetically re-sorted after every
edit, refresh, import, or deletion.
