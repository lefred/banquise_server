<?php
declare(strict_types=1);

final class BanquiseApp
{
    public readonly PDO $db;
    public readonly string $databaseDriver;

    public function __construct(public readonly array $config)
    {
        $driver = strtolower((string)($config['database_driver'] ?? 'sqlite'));
        if (!in_array($driver, ['sqlite', 'mariadb', 'mysql'], true)) {
            throw new RuntimeException("Unsupported database driver: {$driver}");
        }
        $this->databaseDriver = in_array($driver, ['mariadb', 'mysql'], true) ? 'mariadb' : 'sqlite';
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        if ($this->databaseDriver === 'sqlite') {
            $database = (string)($config['database'] ?? '');
            if ($database === '') throw new RuntimeException('Configure the SQLite database path.');
            $directory = dirname($database);
            if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
                throw new RuntimeException("Cannot create $directory");
            }
            $this->db = new PDO('sqlite:' . $database, null, null, $options);
            $this->db->exec('PRAGMA foreign_keys=ON');
            $this->db->exec(file_get_contents(dirname(__DIR__) . '/schema.sql'));
            $this->migrateAgentPluginManagement();
            $this->migrateTaskUniqueness();
        } else {
            $host = (string)($config['database_host'] ?? '127.0.0.1');
            $port = (int)($config['database_port'] ?? 3306);
            $name = (string)($config['database_name'] ?? 'banquise');
            $socket = (string)($config['database_socket'] ?? '');
            if (!preg_match('/^[A-Za-z0-9_$-]{1,64}$/', $name)) throw new RuntimeException('Invalid MariaDB database name.');
            if ($port < 1 || $port > 65535 || str_contains($host, ';') || str_contains($socket, ';')) {
                throw new RuntimeException('Invalid MariaDB connection parameters.');
            }
            $dsn = $socket !== ''
                ? "mysql:unix_socket={$socket};dbname={$name};charset=utf8mb4"
                : "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
            $this->db = new PDO($dsn, (string)($config['database_user'] ?? ''),
                (string)($config['database_password'] ?? ''), $options);
            if (($config['database_auto_initialize'] ?? true) === true) {
                $this->db->exec(file_get_contents(dirname(__DIR__) . '/schema.mariadb.sql'));
                $this->migrateAgentPluginManagement();
            }
        }
    }

    private function migrateAgentPluginManagement(): void
    {
        if ($this->databaseDriver === 'sqlite') {
            $columns = $this->db->query('PRAGMA table_info(agent_plugins)')->fetchAll();
            if (array_filter($columns, static fn(array $column): bool => $column['name'] === 'managed')) return;
            $this->db->exec('ALTER TABLE agent_plugins ADD COLUMN managed INTEGER NOT NULL DEFAULT 0');
        } else {
            $statement = $this->db->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS '
                . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?'
            );
            $statement->execute(['agent_plugins', 'managed']);
            if ((int)$statement->fetchColumn() > 0) return;
            $this->db->exec('ALTER TABLE agent_plugins ADD COLUMN managed TINYINT(1) NOT NULL DEFAULT 0 AFTER loaded');
        }
        $this->db->exec("UPDATE agent_plugins SET managed=1 WHERE installed=1 AND installed_version<>''");
    }

    private function migrateTaskUniqueness(): void
    {
        $tableSql = (string)$this->db->query(
            "SELECT sql FROM sqlite_master WHERE type='table' AND name='tasks'"
        )->fetchColumn();
        if (preg_match('/UNIQUE\s*\(\s*server_uid\s*,\s*action\s*,\s*plugin_name\s*,\s*state\s*\)/i', $tableSql)) {
            $this->db->beginTransaction();
            try {
                $this->db->exec(
                    "CREATE TABLE tasks_without_state_unique (
                      id INTEGER PRIMARY KEY AUTOINCREMENT,
                      server_uid TEXT NOT NULL REFERENCES agents(server_uid) ON DELETE CASCADE,
                      action TEXT NOT NULL CHECK(action IN ('install','update','uninstall','load')),
                      plugin_name TEXT NOT NULL,
                      state TEXT NOT NULL DEFAULT 'queued' CHECK(state IN ('queued','delivered','succeeded','failed','cancelled')),
                      requested_at TEXT NOT NULL,
                      delivered_at TEXT,
                      completed_at TEXT,
                      result TEXT NOT NULL DEFAULT ''
                    )"
                );
                $this->db->exec(
                    'INSERT INTO tasks_without_state_unique '
                    . 'SELECT id,server_uid,action,plugin_name,state,requested_at,delivered_at,completed_at,result FROM tasks'
                );
                $this->db->exec('DROP TABLE tasks');
                $this->db->exec('ALTER TABLE tasks_without_state_unique RENAME TO tasks');
                $this->db->commit();
            } catch (Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }
        }

        // Keep the newest delivered task (or newest queued task) actionable and
        // retain any accidental older pending duplicates as cancelled history.
        $this->db->exec(
            "UPDATE tasks SET state='cancelled', completed_at=COALESCE(completed_at,'" . self::now() . "'),
                    result=CASE WHEN result='' THEN 'Superseded by a newer pending task during schema migration' ELSE result END
             WHERE id IN (
               SELECT id FROM (
                 SELECT id, ROW_NUMBER() OVER (
                   PARTITION BY server_uid,action,plugin_name
                   ORDER BY CASE state WHEN 'delivered' THEN 0 ELSE 1 END, id DESC
                 ) position
                 FROM tasks WHERE state IN ('queued','delivered')
               ) WHERE position > 1
             )"
        );
        $this->db->exec(
            "CREATE UNIQUE INDEX IF NOT EXISTS tasks_pending_unique
             ON tasks(server_uid,action,plugin_name)
             WHERE state IN ('queued','delivered')"
        );
        $this->db->exec('CREATE INDEX IF NOT EXISTS tasks_agent_state ON tasks(server_uid,state,id)');
    }

    public static function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    public function enrollmentMode(): string
    {
        $statement = $this->db->prepare("SELECT value FROM settings WHERE name='enrollment_mode'");
        $statement->execute();
        $mode = $statement->fetchColumn();
        if ($mode === false) $mode = $this->config['enrollment_mode'] ?? 'shared';
        return $mode === 'dedicated' ? 'dedicated' : 'shared';
    }

    public function setEnrollmentMode(string $mode): void
    {
        if (!in_array($mode, ['shared', 'dedicated'], true)) throw new InvalidArgumentException('Invalid enrollment mode.');
        if ($mode === 'dedicated') $this->enrollmentEncryptionKey();
        $sql = $this->databaseDriver === 'mariadb'
            ? "INSERT INTO settings(name,value) VALUES('enrollment_mode',?) ON DUPLICATE KEY UPDATE value=VALUES(value)"
            : "INSERT INTO settings(name,value) VALUES('enrollment_mode',?) ON CONFLICT(name) DO UPDATE SET value=excluded.value";
        $statement = $this->db->prepare($sql);
        $statement->execute([$mode]);
    }

    private function enrollmentEncryptionKey(): string
    {
        if (!extension_loaded('sodium')) throw new RuntimeException('PHP Sodium is required for dedicated enrollment tokens.');
        $configured = (string)($this->config['enrollment_token_encryption_key'] ?? '');
        if (str_starts_with($configured, 'base64:')) $configured = substr($configured, 7);
        $key = base64_decode($configured, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('Configure enrollment_token_encryption_key as a base64-encoded 32-byte key.');
        }
        return $key;
    }

    private function encryptEnrollmentToken(string $token): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($token, $nonce, $this->enrollmentEncryptionKey()));
    }

    private function decryptEnrollmentToken(string $encrypted): string
    {
        $payload = base64_decode($encrypted, true);
        if ($payload === false || strlen($payload) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Stored enrollment token is corrupted.');
        }
        $nonce = substr($payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $token = sodium_crypto_secretbox_open(substr($payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $this->enrollmentEncryptionKey());
        if ($token === false) throw new RuntimeException('Cannot decrypt an enrollment token; verify the configured encryption key.');
        return $token;
    }

    public function generateEnrollmentTokens(int $count): array
    {
        if ($this->enrollmentMode() !== 'dedicated') throw new RuntimeException('Select dedicated enrollment mode before generating tokens.');
        if ($count < 1 || $count > 10) throw new InvalidArgumentException('Generate between 1 and 10 tokens.');
        $available = (int)$this->db->query('SELECT COUNT(*) FROM enrollment_tokens')->fetchColumn();
        if ($available + $count > 10) throw new InvalidArgumentException('At most 10 dedicated enrollment tokens may be available.');
        $statement = $this->db->prepare(
            'INSERT INTO enrollment_tokens(id,token_hash,encrypted_token,created_at) VALUES(?,?,?,?)'
        );
        $created = [];
        $this->db->beginTransaction();
        try {
            for ($i = 0; $i < $count; ++$i) {
                $id = bin2hex(random_bytes(8));
                $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
                $token = "bq_enroll_{$id}_{$secret}";
                $statement->execute([$id, hash('sha256', $token), $this->encryptEnrollmentToken($token), self::now()]);
                $created[] = $token;
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $created;
    }

    public function enrollmentTokens(): array
    {
        $rows = $this->db->query('SELECT id,encrypted_token,created_at FROM enrollment_tokens ORDER BY created_at,id')->fetchAll();
        foreach ($rows as &$row) {
            $row['token'] = $this->decryptEnrollmentToken($row['encrypted_token']);
            unset($row['encrypted_token']);
        }
        unset($row);
        return $rows;
    }

    public function revokeEnrollmentToken(string $id): void
    {
        if (!preg_match('/^[a-f0-9]{16}$/', $id)) throw new InvalidArgumentException('Invalid enrollment token ID.');
        $statement = $this->db->prepare('DELETE FROM enrollment_tokens WHERE id=?');
        $statement->execute([$id]);
        if (!$statement->rowCount()) throw new InvalidArgumentException('Enrollment token is no longer available.');
    }

    public function catalog(): array
    {
        $file = $this->config['catalog'];
        if (!is_file($file)) return ['schema_version' => 1, 'plugins' => []];
        $catalog = json_decode(file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
        return is_array($catalog) ? $catalog : ['schema_version' => 1, 'plugins' => []];
    }

    public function agents(): array
    {
        return $this->db->query(
            "SELECT a.*,
                    (SELECT COUNT(*) FROM tasks t WHERE t.server_uid=a.server_uid AND t.state IN ('queued','delivered')) pending_tasks
             FROM agents a ORDER BY a.status,a.display_name,a.server_uid"
        )->fetchAll();
    }

    public function agent(string $uid): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM agents WHERE server_uid=?');
        $statement->execute([$uid]);
        $agent = $statement->fetch();
        return $agent ?: null;
    }

    public function agentPlugins(string $uid): array
    {
        $statement = $this->db->prepare('SELECT * FROM agent_plugins WHERE server_uid=? ORDER BY name');
        $statement->execute([$uid]);
        return $statement->fetchAll();
    }

    public function installedPluginServers(): array
    {
        return $this->db->query(
            "SELECT p.name,p.installed_version,p.loaded,p.observed_at,
                    a.server_uid,a.display_name,a.status,a.mariadb_version,
                    a.architecture,a.last_seen_at
             FROM agent_plugins p JOIN agents a ON a.server_uid=p.server_uid
             WHERE p.installed=1
             ORDER BY p.name,a.display_name,a.server_uid"
        )->fetchAll();
    }

    public function pluginUpdatesByServer(array $catalog): array
    {
        $installed = $this->db->query(
            "SELECT p.name,p.installed_version,a.server_uid,a.mariadb_version,a.architecture
             FROM agent_plugins p JOIN agents a ON a.server_uid=p.server_uid
             WHERE p.installed=1 AND p.installed_version<>''"
        )->fetchAll();
        $updates = [];
        foreach ($installed as $current) {
            $newest = null;
            foreach (($catalog['plugins'] ?? []) as $candidate) {
                if (($candidate['name'] ?? '') !== $current['name']) continue;
                $mariaDb = (string)($candidate['mariadb_version'] ?? '');
                $architecture = (string)($candidate['architecture'] ?? '');
                if ($mariaDb !== 'any' && !str_starts_with((string)$current['mariadb_version'], $mariaDb)) continue;
                if ($architecture !== 'any' && $architecture !== $current['architecture']) continue;
                $candidateVersion = ltrim((string)($candidate['version'] ?? ''), 'vV');
                if ($candidateVersion !== '' && ($newest === null || version_compare($candidateVersion, $newest, '>'))) {
                    $newest = $candidateVersion;
                }
            }
            $installedVersion = ltrim((string)$current['installed_version'], 'vV');
            if ($newest !== null && version_compare($newest, $installedVersion, '>')) {
                $updates[$current['server_uid']][] = [
                    'name' => $current['name'],
                    'installed_version' => $current['installed_version'],
                    'available_version' => $newest,
                ];
            }
        }
        foreach ($updates as &$items) usort($items, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
        unset($items);
        return $updates;
    }

    public function pluginCountsByServer(array $catalog, array $agents): array
    {
        $installedByServer = [];
        foreach ($this->db->query('SELECT server_uid,name FROM agent_plugins WHERE installed=1')->fetchAll() as $plugin) {
            $installedByServer[$plugin['server_uid']][(string)$plugin['name']] = true;
        }
        $counts = [];
        foreach ($agents as $agent) {
            $available = [];
            foreach (($catalog['plugins'] ?? []) as $candidate) {
                $mariaDb = (string)($candidate['mariadb_version'] ?? '');
                $architecture = (string)($candidate['architecture'] ?? '');
                if ($mariaDb !== 'any' && !str_starts_with((string)$agent['mariadb_version'], $mariaDb)) continue;
                if ($architecture !== 'any' && $architecture !== $agent['architecture']) continue;
                $name = (string)($candidate['name'] ?? '');
                if ($name !== '') $available[$name] = true;
            }
            $installed = array_intersect_key($installedByServer[$agent['server_uid']] ?? [], $available);
            $counts[$agent['server_uid']] = ['installed' => count($installed), 'available' => count($available)];
        }
        return $counts;
    }

    public function tasks(string $uid): array
    {
        $statement = $this->db->prepare('SELECT * FROM tasks WHERE server_uid=? ORDER BY id DESC LIMIT 100');
        $statement->execute([$uid]);
        return $statement->fetchAll();
    }

    public function taskCompletionToken(string $uid): string
    {
        if (!$this->agent($uid)) throw new InvalidArgumentException('Unknown agent');
        $statement = $this->db->prepare(
            "SELECT COUNT(*) completed, COALESCE(MAX(id),0) latest_id
             FROM tasks WHERE server_uid=? AND state IN ('succeeded','failed')"
        );
        $statement->execute([$uid]);
        $state = $statement->fetch();
        return $state['completed'] . ':' . $state['latest_id'];
    }

    public function setAgentStatus(string $uid, string $status): void
    {
        if (!in_array($status, ['active', 'disabled'], true)) throw new InvalidArgumentException('Invalid status');
        $sql = $status === 'active'
            ? "UPDATE agents SET status=?, approved_at=COALESCE(approved_at,?) WHERE server_uid=?"
            : 'UPDATE agents SET status=? WHERE server_uid=?';
        $statement = $this->db->prepare($sql);
        $statement->execute($status === 'active' ? [$status, self::now(), $uid] : [$status, $uid]);
    }

    public function renameAgent(string $uid, string $displayName): void
    {
        if (!$this->agent($uid)) throw new InvalidArgumentException('Unknown agent');
        $displayName = trim($displayName);
        if ($displayName === '') throw new InvalidArgumentException('Server name cannot be empty.');
        $length = function_exists('mb_strlen') ? mb_strlen($displayName, 'UTF-8') : strlen($displayName);
        if ($length > 100) throw new InvalidArgumentException('Server name cannot exceed 100 characters.');
        if (preg_match('/[\x00-\x1F\x7F]/u', $displayName)) throw new InvalidArgumentException('Server name contains control characters.');
        $statement = $this->db->prepare('UPDATE agents SET display_name=? WHERE server_uid=?');
        $statement->execute([$displayName, $uid]);
    }

    public function deleteAgent(string $uid): void
    {
        if (!$this->agent($uid)) throw new InvalidArgumentException('Unknown agent');
        $statement = $this->db->prepare('DELETE FROM agents WHERE server_uid=?');
        $statement->execute([$uid]);
    }

    public function queueTask(string $uid, string $action, string $plugin): void
    {
        $this->queueTasks($uid, $action, [$plugin]);
    }

    public function queueTasks(string $uid, string $action, array $plugins): int
    {
        if (!in_array($action, ['install', 'update', 'uninstall', 'load'], true)) {
            throw new InvalidArgumentException('Invalid action');
        }
        if (!$this->agent($uid)) throw new InvalidArgumentException('Unknown agent');
        $plugins = array_values(array_unique(array_map(static fn($value): string => trim((string)$value), $plugins)));
        if (!$plugins) throw new InvalidArgumentException('Select at least one plugin');
        $catalogNames = [];
        foreach ($this->catalog()['plugins'] ?? [] as $entry) $catalogNames[(string)$entry['name']] = true;
        foreach ($plugins as $plugin) {
            if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/', $plugin) || !isset($catalogNames[$plugin])) {
                throw new InvalidArgumentException("Unknown catalog plugin: $plugin");
            }
        }
        $insertSql = "INSERT INTO tasks(server_uid,action,plugin_name,state,requested_at) VALUES(?,?,?,'queued',?)";
        $created = 0;
        $this->db->beginTransaction();
        try {
            foreach ($plugins as $plugin) {
                try {
                    $statement = $this->db->prepare($insertSql);
                    $statement->execute([$uid, $action, $plugin, self::now()]);
                    $created += $statement->rowCount();
                } catch (PDOException $e) {
                    if (!$this->isDuplicateKey($e)) throw $e;
                }
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $created;
    }

    private function isDuplicateKey(PDOException $e): bool
    {
        $driverCode = (int)($e->errorInfo[1] ?? 0);
        return ($this->databaseDriver === 'mariadb' && $driverCode === 1062)
            || ($this->databaseDriver === 'sqlite' && in_array($driverCode, [19, 2067], true));
    }

    private const CATALOG_REQUIRED_FIELDS = ['name','repository','version','mariadb_version','architecture','soname','download_url','sha256'];
    private const CATALOG_OPTIONAL_FIELDS = ['archive_type','archive_member','plugin_types','license','maturity','description','author','os','dependencies','message'];

    private function repositoryOverrideMapping(): array
    {
        return [
            'name' => '--name', 'soname' => '--soname', 'architecture' => '--architecture',
            'plugin_types' => '--plugin-types', 'license' => '--license',
            'maturity' => '--maturity', 'description' => '--description',
            'author' => '--author',
            'dependencies' => '--dependencies', 'message' => '--message',
        ];
    }

    public function importRepository(string $repository, array $metadata): string
    {
        if (!preg_match('#^(https://github\.com/)?[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/?$#', $repository)) {
            throw new InvalidArgumentException('Invalid GitHub repository');
        }
        $lock = $this->catalogLock();
        $temporary = $this->temporaryCatalogPath();
        if (is_file($this->config['catalog']) && !copy($this->config['catalog'], $temporary)) {
            flock($lock, LOCK_UN); fclose($lock);
            throw new RuntimeException('Cannot stage the current catalog');
        }
        $command = [dirname(__DIR__) . '/bin/update-catalog.sh', '--catalog', $temporary];
        foreach ($this->repositoryOverrideMapping() as $field => $flag) {
            if (($metadata[$field] ?? '') !== '') array_push($command, $flag, trim((string)$metadata[$field]));
        }
        $command[] = $repository;
        try {
            [$code, $stdout, $stderr] = $this->runProcess($command, '');
            if ($code !== 0) throw new RuntimeException(trim($stderr ?: $stdout) ?: "Catalog updater exited $code");
            $this->signAndPublish($temporary, (string)($metadata['signing_password'] ?? ''));
            return trim($stderr . "\n" . $stdout . "\nCatalog signed and published.");
        } finally {
            if (is_file($temporary)) unlink($temporary);
            if (is_file($temporary . '.minisig')) unlink($temporary . '.minisig');
            flock($lock, LOCK_UN); fclose($lock);
        }
    }

    /**
     * Detects what "Add GitHub repository" would produce — one entry per
     * matching release asset — without touching the catalog file or signing
     * anything, so it can be reviewed and edited before publishing.
     */
    public function previewRepository(string $repository, array $metadata): array
    {
        if (!preg_match('#^(https://github\.com/)?[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/?$#', $repository)) {
            throw new InvalidArgumentException('Invalid GitHub repository');
        }
        $command = [dirname(__DIR__) . '/bin/update-catalog.sh', '--dry-run'];
        foreach ($this->repositoryOverrideMapping() as $field => $flag) {
            if (($metadata[$field] ?? '') !== '') array_push($command, $flag, trim((string)$metadata[$field]));
        }
        $command[] = $repository;
        [$code, $stdout, $stderr] = $this->runProcess($command, '');
        if ($code !== 0) throw new RuntimeException(trim($stderr ?: $stdout) ?: "Catalog updater exited $code");
        try {
            $entries = json_decode($stdout, true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException('Catalog updater produced unexpected output.');
        }
        if (!is_array($entries) || !$entries) throw new RuntimeException('No matching release assets were found.');
        return $entries;
    }

    /**
     * Publishes entries a user reviewed after previewRepository(), replacing
     * any existing entry with the same identity (name/MariaDB
     * version/architecture/OS/soname) and appending the rest. Runs once so
     * multiple entries from the same release are signed together.
     */
    public function confirmRepositoryImport(array $entries, string $password): string
    {
        if (!$entries) throw new InvalidArgumentException('No entries to import.');
        $catalog = $this->catalog();
        $count = 0;
        foreach ($entries as $input) {
            if (!is_array($input)) throw new InvalidArgumentException('Malformed entry.');
            $entry = $this->validateCatalogEntryFields($input, ['last_release_at', 'last_commit_at']);
            $replaced = false;
            foreach ($catalog['plugins'] as $index => $existing) {
                if ($this->catalogEntryUpgrades($existing, $entry)) {
                    $catalog['plugins'][$index] = $entry;
                    $replaced = true;
                    break;
                }
            }
            if (!$replaced) $catalog['plugins'][] = $entry;
            $count++;
        }
        $this->publishCatalogArray($catalog, $password);
        return "$count catalog entr" . ($count === 1 ? 'y' : 'ies') . ' imported, signed, and published.';
    }

    /**
     * Whether $entry should replace $existing in place: same name/MariaDB
     * version/architecture/soname, and either the same OS, or $existing
     * predates OS tracking (no OS recorded at all) and is being claimed by
     * the first OS-tagged entry that matches it — mirrors update-catalog.sh's
     * own merge rule so a legacy entry doesn't end up duplicated instead of
     * upgraded in place.
     */
    private function catalogEntryUpgrades(array $existing, array $entry): bool
    {
        foreach (['name', 'mariadb_version', 'architecture', 'soname'] as $field) {
            if ((string)($existing[$field] ?? '') !== (string)($entry[$field] ?? '')) return false;
        }
        $existingOs = (string)($existing['os'] ?? '');
        return $existingOs === '' || $existingOs === (string)($entry['os'] ?? '');
    }

    public function catalogEntryId(array $entry): string
    {
        return hash('sha256', implode("\0", [
            (string)($entry['name'] ?? ''), (string)($entry['mariadb_version'] ?? ''),
            (string)($entry['architecture'] ?? ''), (string)($entry['os'] ?? ''), (string)($entry['soname'] ?? ''),
        ]));
    }

    private function validateCatalogEntryFields(array $input, array $extraOptional = []): array
    {
        $required = self::CATALOG_REQUIRED_FIELDS;
        $optional = array_merge(self::CATALOG_OPTIONAL_FIELDS, $extraOptional);
        $entry = [];
        foreach (array_merge($required, $optional) as $field) $entry[$field] = trim((string)($input[$field] ?? ''));
        foreach ($required as $field) if ($entry[$field] === '') throw new InvalidArgumentException("$field is required");
        if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/', $entry['name'])) throw new InvalidArgumentException('Invalid plugin name');
        if (!preg_match('/^[A-Za-z0-9_.+-]+\.so$/', $entry['soname'])) throw new InvalidArgumentException('Invalid soname');
        if (!preg_match('/^[a-fA-F0-9]{64}$/', $entry['sha256'])) throw new InvalidArgumentException('SHA-256 must contain 64 hexadecimal characters');
        foreach (['repository','download_url'] as $field) {
            if (!str_starts_with($entry[$field], 'https://')) throw new InvalidArgumentException("$field must use HTTPS");
        }
        if ($entry['archive_type'] !== '' && $entry['archive_type'] !== 'tar.gz') throw new InvalidArgumentException('Only tar.gz archives are supported');
        if (($entry['archive_type'] === 'tar.gz') !== ($entry['archive_member'] !== '')) throw new InvalidArgumentException('archive_type and archive_member must be provided together');
        $entry['sha256'] = strtolower($entry['sha256']);
        foreach ($optional as $field) if ($entry[$field] === '') unset($entry[$field]);
        return $entry;
    }

    public function editCatalogEntry(string $id, array $input, string $password): void
    {
        $catalog = $this->catalog();
        $index = $this->findCatalogEntry($catalog, $id);
        $validated = $this->validateCatalogEntryFields($input);
        // Preserve fields the manual form doesn't cover (last_release_at, last_commit_at,
        // ...); fields the form does cover are fully replaced, including being cleared blank.
        $entry = $catalog['plugins'][$index];
        foreach (array_merge(self::CATALOG_REQUIRED_FIELDS, self::CATALOG_OPTIONAL_FIELDS) as $field) unset($entry[$field]);
        $entry = array_merge($entry, $validated);
        $catalog['plugins'][$index] = $entry;
        $this->publishCatalogArray($catalog, $password);
    }

    public function deleteCatalogEntry(string $id, string $password): void
    {
        $catalog = $this->catalog();
        $index = $this->findCatalogEntry($catalog, $id);
        array_splice($catalog['plugins'], $index, 1);
        $this->publishCatalogArray($catalog, $password);
    }

    public function refreshCatalogEntry(string $id, string $password): string
    {
        $catalog = $this->catalog();
        $entry = $catalog['plugins'][$this->findCatalogEntry($catalog, $id)];
        $repository = preg_replace('#/releases/?$#', '', (string)$entry['repository']);
        $metadata = [
            'name' => $entry['name'], 'soname' => $entry['soname'],
            'dependencies' => $entry['dependencies'] ?? '', 'message' => $entry['message'] ?? '',
            'signing_password' => $password,
        ];
        // Standalone .so assets don't encode architecture in their filename, so
        // it can't be re-detected; carry the current value forward instead of
        // letting it silently fall back to the default. Archive-based (tar.gz)
        // entries always re-detect architecture per asset from the filename.
        if ((string)($entry['archive_type'] ?? '') === '') {
            $metadata['architecture'] = $entry['architecture'];
        }
        return $this->importRepository($repository, $metadata);
    }

    /**
     * Checks every distinct repository in the catalog for a newer release
     * than what's currently published, without downloading assets, touching
     * the catalog, or signing anything. Repositories shared by several build
     * variants (e.g. different OS entries of the same plugin) are checked
     * once and the result applied to all of them. Results are stored so
     * every administrator/plugin manager sees the same "update available"
     * flags, not just whoever ran the check.
     */
    public function checkForPluginUpdates(): array
    {
        // A catalog with many distinct repositories makes one sequential GitHub
        // API call each; give this room to run past PHP's default web request
        // execution limit rather than being killed silently mid-way.
        if (function_exists('set_time_limit')) set_time_limit(180);

        $entries = $this->catalog()['plugins'] ?? [];
        $checked = 0;
        $updatesFound = 0;
        $errors = [];
        $byRepository = [];
        foreach ($entries as $entry) {
            $byRepository[(string)($entry['repository'] ?? '')][] = $entry;
        }
        $now = self::now();
        $repositoryCount = count($byRepository);
        $repositoriesTried = 0;
        foreach ($byRepository as $repository => $repoEntries) {
            $repositoriesTried++;
            $repositoryPath = preg_replace('#/releases/?$#', '', $repository);
            [$code, $stdout, $stderr] = $this->runProcess(
                [dirname(__DIR__) . '/bin/update-catalog.sh', '--latest-version-only', $repositoryPath], ''
            );
            $label = (string)($repoEntries[0]['name'] ?? $repositoryPath);
            if ($code !== 0) {
                $combined = trim($stderr ?: $stdout);
                // GitHub's rate limit applies across every repository this check
                // would still try, so one 403/429 means the rest will fail the
                // same way too: stop immediately with one clear message instead
                // of grinding through — and reporting — every remaining repository.
                if (preg_match('/\berror:\s*(403|429)\b/', $combined)) {
                    $remaining = $repositoryCount - $repositoriesTried + 1;
                    $errors[] = "GitHub API rate limit reached after $checked catalog entr" . ($checked === 1 ? 'y' : 'ies')
                        . " ($remaining repositor" . ($remaining === 1 ? 'y' : 'ies') . ' not checked)'
                        . '; set GITHUB_TOKEN in the PHP-FPM environment or try again later.';
                    break;
                }
                $errors[] = "$label: " . ($combined ?: "exited $code");
                continue;
            }
            $latestVersion = ltrim(trim($stdout), 'vV');
            if ($latestVersion === '') {
                $errors[] = "$label: no version returned";
                continue;
            }
            foreach ($repoEntries as $entry) {
                $checked++;
                $entryId = $this->catalogEntryId($entry);
                $currentVersion = ltrim((string)($entry['version'] ?? ''), 'vV');
                if (version_compare($latestVersion, $currentVersion, '>')) {
                    $sql = $this->databaseDriver === 'mariadb'
                        ? "INSERT INTO catalog_update_checks(entry_id,available_version,checked_at) VALUES(?,?,?)
                           ON DUPLICATE KEY UPDATE available_version=VALUES(available_version), checked_at=VALUES(checked_at)"
                        : "INSERT INTO catalog_update_checks(entry_id,available_version,checked_at) VALUES(?,?,?)
                           ON CONFLICT(entry_id) DO UPDATE SET available_version=excluded.available_version, checked_at=excluded.checked_at";
                    $this->db->prepare($sql)->execute([$entryId, $latestVersion, $now]);
                    $updatesFound++;
                } else {
                    $this->db->prepare('DELETE FROM catalog_update_checks WHERE entry_id=?')->execute([$entryId]);
                }
            }
        }
        return ['checked' => $checked, 'updates' => $updatesFound, 'errors' => $errors];
    }

    /** @return array<string,string> catalogEntryId() => available version, for entries with a known newer release. */
    public function pluginUpdateAvailability(): array
    {
        $map = [];
        foreach ($this->db->query('SELECT entry_id, available_version FROM catalog_update_checks') as $row) {
            $map[$row['entry_id']] = $row['available_version'];
        }
        return $map;
    }

    private function findCatalogEntry(array $catalog, string $id): int
    {
        foreach (($catalog['plugins'] ?? []) as $index => $entry) {
            if (hash_equals($this->catalogEntryId($entry), $id)) return $index;
        }
        throw new InvalidArgumentException('Catalog entry no longer exists');
    }

    /**
     * Entries that predate OS tracking (no OS recorded) whose name/MariaDB
     * version/architecture/soname is also carried by a newer, OS-tagged
     * entry — i.e. left behind by a refresh/import that ran before
     * catalogEntryUpgrades() existed to absorb them in place instead.
     */
    public function legacyDuplicateEntries(): array
    {
        $plugins = $this->catalog()['plugins'] ?? [];
        $groups = [];
        foreach ($plugins as $index => $entry) {
            $key = implode("\0", [
                (string)($entry['name'] ?? ''), (string)($entry['mariadb_version'] ?? ''),
                (string)($entry['architecture'] ?? ''), (string)($entry['soname'] ?? ''),
            ]);
            $groups[$key][] = $index;
        }
        $legacy = [];
        foreach ($groups as $indexes) {
            if (count($indexes) < 2) continue;
            $hasOsTagged = false;
            foreach ($indexes as $index) {
                if ((string)($plugins[$index]['os'] ?? '') !== '') { $hasOsTagged = true; break; }
            }
            if (!$hasOsTagged) continue;
            foreach ($indexes as $index) {
                if ((string)($plugins[$index]['os'] ?? '') === '') $legacy[] = $plugins[$index];
            }
        }
        return $legacy;
    }

    public function pruneLegacyCatalogDuplicates(string $password): string
    {
        $legacy = $this->legacyDuplicateEntries();
        if (!$legacy) throw new InvalidArgumentException('No legacy duplicate entries were found.');
        $legacyIds = array_map(fn(array $entry): string => $this->catalogEntryId($entry), $legacy);
        $catalog = $this->catalog();
        $catalog['plugins'] = array_values(array_filter(
            $catalog['plugins'],
            fn(array $entry): bool => !in_array($this->catalogEntryId($entry), $legacyIds, true)
        ));
        $this->publishCatalogArray($catalog, $password);
        return count($legacy) . ' legacy duplicate entr' . (count($legacy) === 1 ? 'y' : 'ies')
            . ' removed; the catalog was re-signed and published.';
    }

    private function publishCatalogArray(array $catalog, string $password): void
    {
        $plugins = array_values($catalog['plugins'] ?? []);
        usort($plugins, static fn(array $a, array $b): int => [
            strtolower((string)$a['name']), (string)$a['name'], (string)$a['mariadb_version'],
            (string)$a['architecture'], (string)($a['os'] ?? ''), (string)$a['soname'],
        ] <=> [
            strtolower((string)$b['name']), (string)$b['name'], (string)$b['mariadb_version'],
            (string)$b['architecture'], (string)($b['os'] ?? ''), (string)$b['soname'],
        ]);
        $catalog = ['schema_version' => 1, 'plugins' => $plugins];
        $lock = $this->catalogLock();
        $temporary = $this->temporaryCatalogPath();
        try {
            $json = json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json)) throw new RuntimeException('Cannot stage catalog');
            chmod($temporary, 0644);
            $this->signAndPublish($temporary, $password);
        } finally {
            if (is_file($temporary)) unlink($temporary);
            if (is_file($temporary . '.minisig')) unlink($temporary . '.minisig');
            flock($lock, LOCK_UN); fclose($lock);
        }
    }

    private function catalogLock()
    {
        $directory = dirname($this->config['catalog']);
        if (!is_dir($directory) || !is_writable($directory)) throw new RuntimeException('Catalog directory is not writable');
        $lock = fopen($this->config['catalog'] . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) throw new RuntimeException('Cannot lock catalog');
        return $lock;
    }

    private function temporaryCatalogPath(): string
    {
        return $this->config['catalog'] . '.update.' . bin2hex(random_bytes(8)) . '.json';
    }

    private function signAndPublish(string $temporary, string $password): void
    {
        // Rewrite download_url to match the active distribution mode before
        // signing, so the signature covers exactly what agents will fetch.
        $this->normalizeArtifactDistribution($temporary);
        $key = (string)($this->config['signing_key'] ?? '');
        $publicKey = (string)($this->config['catalog_public_key'] ?? '');
        if ($key === '' || !is_readable($key)) throw new RuntimeException('Catalog signing key is not readable');
        if ($publicKey === '' || !is_readable($publicKey)) throw new RuntimeException('Catalog public key is not readable');
        [$code, , $stderr] = $this->runProcess(['minisign','-S','-s',$key,'-m',$temporary,'-x',$temporary.'.minisig'], $password . "\n");
        if (function_exists('sodium_memzero')) sodium_memzero($password); else $password = '';
        if ($code !== 0) throw new RuntimeException(trim($stderr) ?: 'Catalog signing failed');
        [$verifyCode, , $verifyError] = $this->runProcess(['minisign','-V','-q','-p',$publicKey,'-m',$temporary,'-x',$temporary.'.minisig'], '');
        if ($verifyCode !== 0) throw new RuntimeException(trim($verifyError) ?: 'Catalog signature verification failed');
        $catalog = $this->config['catalog'];
        if (!rename($temporary . '.minisig', $catalog . '.minisig') || !rename($temporary, $catalog)) {
            throw new RuntimeException('Cannot atomically publish catalog and signature');
        }
        $this->pruneStaleUpdateChecks($this->catalog());
        $this->pruneOrphanedArtifacts();
    }

    // ------------------------------------------------------------------
    // Distribution mode: serve plugin files from GitHub directly ("public",
    // the default) or mirror them onto this server so agents never need
    // internet access ("private").
    // ------------------------------------------------------------------

    public function distributionMode(): string
    {
        $statement = $this->db->prepare("SELECT value FROM settings WHERE name='distribution_mode'");
        $statement->execute();
        $mode = $statement->fetchColumn();
        if ($mode === false) $mode = $this->config['distribution_mode'] ?? 'public';
        return $mode === 'private' ? 'private' : 'public';
    }

    /**
     * Persists the mode, then republishes immediately so every entry's
     * download_url matches it right away rather than waiting for the next
     * unrelated catalog change. A newly-private catalog that fails to mirror
     * some entries (e.g. a repository is unreachable) still publishes the
     * rest; the ones that failed keep serving from their original source
     * until a later publish succeeds, and are named in the returned message.
     */
    public function setDistributionMode(string $mode, string $password): string
    {
        if (!in_array($mode, ['public', 'private'], true)) throw new InvalidArgumentException('Invalid distribution mode.');
        $sql = $this->databaseDriver === 'mariadb'
            ? "INSERT INTO settings(name,value) VALUES('distribution_mode',?) ON DUPLICATE KEY UPDATE value=VALUES(value)"
            : "INSERT INTO settings(name,value) VALUES('distribution_mode',?) ON CONFLICT(name) DO UPDATE SET value=excluded.value";
        $this->db->prepare($sql)->execute([$mode]);
        $catalog = $this->catalog();
        if (!($catalog['plugins'] ?? [])) return "Distribution mode set to $mode.";
        $this->publishCatalogArray($catalog, $password);
        $message = "Distribution mode set to $mode; the catalog was re-signed and published to match.";
        if ($mode === 'private') {
            $base = rtrim((string)($this->config['public_base_url'] ?? ''), '/');
            $unmirrored = [];
            foreach ($this->catalog()['plugins'] ?? [] as $entry) {
                $url = (string)($entry['download_url'] ?? '');
                if ($base === '' || !str_starts_with($url, "$base/artifacts/")) $unmirrored[] = (string)($entry['name'] ?? '?');
            }
            if ($unmirrored) {
                $message .= ' Could not mirror: ' . implode(', ', array_unique($unmirrored))
                    . ' — still served from their original source; they will be retried on the next catalog change.';
            }
        }
        return $message;
    }

    private function artifactsDirectory(): string
    {
        return dirname((string)$this->config['catalog']) . '/artifacts';
    }

    /**
     * Rewrites every entry's download_url in the staged catalog file to match
     * the active distribution mode: mirrored-onto-this-server for "private",
     * restored to its original source for "public". A mirroring failure for
     * one entry is logged and that entry is left untouched — it never blocks
     * publishing the rest of the catalog.
     */
    private function normalizeArtifactDistribution(string $temporaryCatalogPath): void
    {
        $raw = file_get_contents($temporaryCatalogPath);
        if ($raw === false) return;
        $catalog = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($catalog) || !is_array($catalog['plugins'] ?? null)) return;
        $mode = $this->distributionMode();
        $changed = false;
        foreach ($catalog['plugins'] as &$entry) {
            if (!is_array($entry)) continue;
            $origin = (string)($entry['origin_download_url'] ?? $entry['download_url'] ?? '');
            if ($mode === 'private') {
                try {
                    $mirrored = $this->mirrorArtifact($origin, (string)($entry['sha256'] ?? ''));
                } catch (Throwable $e) {
                    error_log('Banquise: could not mirror artifact for ' . ($entry['name'] ?? '?') . ': ' . $e->getMessage());
                    $mirrored = null;
                }
                if ($mirrored !== null) {
                    if (($entry['download_url'] ?? '') !== $mirrored) { $entry['download_url'] = $mirrored; $changed = true; }
                    if (($entry['origin_download_url'] ?? '') !== $origin) { $entry['origin_download_url'] = $origin; $changed = true; }
                }
            } elseif (isset($entry['origin_download_url'])) {
                $entry['download_url'] = $entry['origin_download_url'];
                unset($entry['origin_download_url']);
                $changed = true;
            }
        }
        unset($entry);
        if ($changed) {
            $json = json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            file_put_contents($temporaryCatalogPath, $json, LOCK_EX);
        }
    }

    /** Downloads (or reuses a cached copy of) $url, verified against $sha256, onto this server. */
    private function mirrorArtifact(string $url, string $sha256): ?string
    {
        $base = rtrim((string)($this->config['public_base_url'] ?? ''), '/');
        if ($url === '' || $base === '') return null;
        if (str_starts_with($url, "$base/artifacts/")) return $url; // already mirrored
        if (!preg_match('/^[A-Fa-f0-9]{64}$/', $sha256)) return null; // can't verify integrity, leave alone
        if (function_exists('set_time_limit')) set_time_limit(300);
        $artifactsDir = $this->artifactsDirectory();
        if (!is_dir($artifactsDir) && !mkdir($artifactsDir, 0750, true) && !is_dir($artifactsDir)) {
            throw new RuntimeException("Cannot create $artifactsDir");
        }
        [$code, $stdout, $stderr] = $this->runProcess(
            [dirname(__DIR__) . '/bin/mirror-artifact.sh', '--dir', $artifactsDir, '--sha256', strtolower($sha256), $url], ''
        );
        if ($code !== 0) throw new RuntimeException(trim($stderr ?: $stdout) ?: "mirror-artifact.sh exited $code");
        $filename = trim($stdout);
        if ($filename === '') throw new RuntimeException('mirror-artifact.sh produced no output');
        return "$base/artifacts/$filename";
    }

    /** Deletes mirrored files no current catalog entry references anymore. */
    private function pruneOrphanedArtifacts(): void
    {
        $directory = $this->artifactsDirectory();
        if (!is_dir($directory)) return;
        $base = rtrim((string)($this->config['public_base_url'] ?? ''), '/');
        $referenced = [];
        foreach ($this->catalog()['plugins'] ?? [] as $entry) {
            $url = (string)($entry['download_url'] ?? '');
            if ($base !== '' && str_starts_with($url, "$base/artifacts/")) $referenced[basename($url)] = true;
        }
        foreach ((scandir($directory) ?: []) as $file) {
            if ($file[0] === '.' || isset($referenced[$file])) continue;
            @unlink("$directory/$file");
        }
    }

    /**
     * Drops "update available" flags that no longer apply: the entry is gone
     * (deleted, or its identity changed), or its stored version now meets or
     * exceeds the version that was flagged as available. Runs after every
     * successful catalog publish, whichever code path triggered it.
     */
    private function pruneStaleUpdateChecks(array $catalog): void
    {
        $versionById = [];
        foreach (($catalog['plugins'] ?? []) as $entry) {
            $versionById[$this->catalogEntryId($entry)] = ltrim((string)($entry['version'] ?? ''), 'vV');
        }
        $stale = [];
        foreach ($this->db->query('SELECT entry_id, available_version FROM catalog_update_checks') as $row) {
            $id = $row['entry_id'];
            if (!isset($versionById[$id]) || version_compare($versionById[$id], ltrim((string)$row['available_version'], 'vV'), '>=')) {
                $stale[] = $id;
            }
        }
        if (!$stale) return;
        $marks = implode(',', array_fill(0, count($stale), '?'));
        $this->db->prepare("DELETE FROM catalog_update_checks WHERE entry_id IN ($marks)")->execute($stale);
    }

    private function runProcess(array $command, string $stdin): array
    {
        $pipes = [];
        $process = proc_open($command, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, dirname(__DIR__), [
            'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
            'GITHUB_TOKEN' => getenv('GITHUB_TOKEN') ?: '',
        ]);
        if (!is_resource($process)) throw new RuntimeException('Could not start subprocess');
        fwrite($pipes[0], $stdin); fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
        return [proc_close($process), $stdout, $stderr];
    }

    public function expectedKeyId(): string
    {
        $publicKey = $this->config['catalog_public_key'] ?? '';
        if (!is_file($publicKey)) return '';
        $lines = file($publicKey, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (count($lines) < 2) return '';
        $packet = base64_decode($lines[1], true);
        return $packet !== false && strlen($packet) === 42 ? bin2hex(substr($packet, 2, 8)) : '';
    }

    // ------------------------------------------------------------------
    // Users, roles, and authentication.
    // ------------------------------------------------------------------

    public function hasAnyUser(): bool
    {
        return (int)$this->db->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
    }

    /**
     * Creates the very first administrator with a password set immediately,
     * bypassing the emailed setup-link flow. Only meant for bin/init.php,
     * before any mail transport can plausibly be configured or trusted.
     */
    public function createBootstrapAdministrator(string $email, string $displayName, string $password): void
    {
        if ($this->hasAnyUser()) throw new RuntimeException('Users already exist; bootstrap is only for a brand-new installation.');
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Enter a valid email address.');
        $displayName = trim($displayName) ?: 'Administrator';
        if (strlen($password) < 12) throw new InvalidArgumentException('Password must be at least 12 characters.');
        $now = self::now();
        $this->db->beginTransaction();
        try {
            $statement = $this->db->prepare(
                'INSERT INTO users(email,display_name,password_hash,status,created_at) VALUES(?,?,?,?,?)'
            );
            $statement->execute([$email, $displayName, password_hash($password, PASSWORD_ARGON2ID), 'active', $now]);
            $this->setUserRoles((int)$this->db->lastInsertId(), ['administrator']);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function currentUser(?int $userId): BanquiseAuth
    {
        if ($userId === null) return BanquiseAuth::guest();
        $statement = $this->db->prepare('SELECT id,email,display_name,status FROM users WHERE id=?');
        $statement->execute([$userId]);
        $user = $statement->fetch();
        if (!$user || $user['status'] !== 'active') return BanquiseAuth::guest();
        $roles = $this->db->prepare('SELECT role FROM user_roles WHERE user_id=?');
        $roles->execute([$userId]);
        return new BanquiseAuth((int)$user['id'], $user['email'], $user['display_name'], array_column($roles->fetchAll(), 'role'));
    }

    /**
     * Verifies email/password and returns the user id on success. Runs a
     * dummy hash verification on every failure path so a nonexistent
     * account, a disabled one, and a wrong password all take about the
     * same time.
     */
    public function authenticateUser(string $email, string $password): ?int
    {
        $statement = $this->db->prepare('SELECT id,password_hash,status FROM users WHERE email=?');
        $statement->execute([strtolower(trim($email))]);
        $user = $statement->fetch();
        $hash = ($user && $user['password_hash'] !== null)
            ? $user['password_hash']
            : '$2y$10$invalidinvalidinvalidinOinvalidinvalidinvalidinvalidi';
        $valid = password_verify($password, $hash);
        if (!$user || $user['status'] !== 'active' || $user['password_hash'] === null || !$valid) return null;
        return (int)$user['id'];
    }

    public function users(): array
    {
        $rows = $this->db->query(
            'SELECT id,email,display_name,status,created_at,(password_hash IS NOT NULL) AS has_password FROM users ORDER BY email'
        )->fetchAll();
        $rolesByUser = [];
        foreach ($this->db->query('SELECT user_id,role FROM user_roles') as $row) {
            $rolesByUser[$row['user_id']][] = $row['role'];
        }
        foreach ($rows as &$row) $row['roles'] = $rolesByUser[$row['id']] ?? [];
        unset($row);
        return $rows;
    }

    public function user(int $id): ?array
    {
        $statement = $this->db->prepare('SELECT id,email,display_name,status,created_at FROM users WHERE id=?');
        $statement->execute([$id]);
        $user = $statement->fetch();
        if (!$user) return null;
        $roles = $this->db->prepare('SELECT role FROM user_roles WHERE user_id=?');
        $roles->execute([$id]);
        $user['roles'] = array_column($roles->fetchAll(), 'role');
        return $user;
    }

    private function validateRoles(array $roles): array
    {
        $roles = array_values(array_unique(array_map('strval', $roles)));
        foreach ($roles as $role) {
            if (!in_array($role, BanquiseAuth::ROLES, true)) throw new InvalidArgumentException("Invalid role: $role");
        }
        if (!$roles) throw new InvalidArgumentException('Select at least one role.');
        return $roles;
    }

    private function administratorCount(): int
    {
        return (int)$this->db->query(
            "SELECT COUNT(DISTINCT u.id) FROM users u JOIN user_roles r ON r.user_id=u.id
             WHERE r.role='administrator' AND u.status='active'"
        )->fetchColumn();
    }

    /** Creates a disabled-password user, assigns roles, and returns a fresh setup token. */
    public function createUser(string $email, string $displayName, array $roles): array
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Enter a valid email address.');
        $displayName = trim($displayName);
        if ($displayName === '') throw new InvalidArgumentException('Display name is required.');
        $roles = $this->validateRoles($roles);
        $now = self::now();
        $this->db->beginTransaction();
        try {
            $statement = $this->db->prepare('INSERT INTO users(email,display_name,status,created_at) VALUES(?,?,?,?)');
            try {
                $statement->execute([$email, $displayName, 'active', $now]);
            } catch (PDOException $e) {
                if ($this->isDuplicateKey($e)) throw new InvalidArgumentException('A user with this email already exists.');
                throw $e;
            }
            $id = (int)$this->db->lastInsertId();
            $this->setUserRoles($id, $roles);
            $token = $this->issuePasswordSetupToken($id);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return ['id' => $id, 'email' => $email, 'display_name' => $displayName, 'token' => $token];
    }

    private function setUserRoles(int $userId, array $roles): void
    {
        $delete = $this->db->prepare('DELETE FROM user_roles WHERE user_id=?');
        $delete->execute([$userId]);
        $insert = $this->db->prepare('INSERT INTO user_roles(user_id,role) VALUES(?,?)');
        foreach ($roles as $role) $insert->execute([$userId, $role]);
    }

    public function updateUserRoles(int $userId, array $roles): void
    {
        $user = $this->user($userId);
        if (!$user) throw new InvalidArgumentException('Unknown user.');
        $roles = $this->validateRoles($roles);
        $losesAdministrator = in_array('administrator', $user['roles'], true) && !in_array('administrator', $roles, true);
        if ($losesAdministrator && $user['status'] === 'active' && $this->administratorCount() <= 1) {
            throw new InvalidArgumentException('At least one active administrator must remain.');
        }
        $this->setUserRoles($userId, $roles);
    }

    public function setUserStatus(int $userId, string $status): void
    {
        if (!in_array($status, ['active', 'disabled'], true)) throw new InvalidArgumentException('Invalid status.');
        $user = $this->user($userId);
        if (!$user) throw new InvalidArgumentException('Unknown user.');
        if ($status === 'disabled' && in_array('administrator', $user['roles'], true) && $this->administratorCount() <= 1) {
            throw new InvalidArgumentException('At least one active administrator must remain.');
        }
        $statement = $this->db->prepare('UPDATE users SET status=? WHERE id=?');
        $statement->execute([$status, $userId]);
    }

    private function passwordSetupTtlSeconds(): int
    {
        return (int)($this->config['setup_token_ttl_seconds'] ?? 86400);
    }

    public function issuePasswordSetupToken(int $userId): string
    {
        $id = bin2hex(random_bytes(8));
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $token = "bq_setup_{$id}_{$secret}";
        $now = self::now();
        $expires = gmdate('Y-m-d\TH:i:s\Z', time() + $this->passwordSetupTtlSeconds());
        $statement = $this->db->prepare(
            'INSERT INTO password_setup_tokens(id,user_id,token_hash,created_at,expires_at) VALUES(?,?,?,?,?)'
        );
        $statement->execute([$id, $userId, hash('sha256', $token), $now, $expires]);
        return $token;
    }

    /** Re-sends setup access to a user who never completed it (or lost the link). */
    public function reissuePasswordSetupToken(int $userId): string
    {
        $user = $this->user($userId);
        if (!$user) throw new InvalidArgumentException('Unknown user.');
        return $this->issuePasswordSetupToken($userId);
    }

    public function consumePasswordSetupToken(string $token, string $password): void
    {
        if (strlen($password) < 12) throw new InvalidArgumentException('Password must be at least 12 characters.');
        if (!preg_match('/^bq_setup_([a-f0-9]{16})_/', $token, $m)) throw new InvalidArgumentException('Invalid or expired setup link.');
        $statement = $this->db->prepare('SELECT * FROM password_setup_tokens WHERE id=?');
        $statement->execute([$m[1]]);
        $row = $statement->fetch();
        if (!$row || $row['consumed_at'] !== null || !hash_equals($row['token_hash'], hash('sha256', $token))
            || strtotime($row['expires_at']) < time()) {
            throw new InvalidArgumentException('Invalid or expired setup link.');
        }
        $now = self::now();
        $this->db->beginTransaction();
        try {
            $update = $this->db->prepare('UPDATE password_setup_tokens SET consumed_at=? WHERE id=? AND consumed_at IS NULL');
            $update->execute([$now, $row['id']]);
            if (!$update->rowCount()) throw new InvalidArgumentException('Invalid or expired setup link.');
            $set = $this->db->prepare('UPDATE users SET password_hash=? WHERE id=?');
            $set->execute([password_hash($password, PASSWORD_ARGON2ID), $row['user_id']]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // ------------------------------------------------------------------
    // Public plugin submissions.
    // ------------------------------------------------------------------

    private function submissionThrottleSeconds(): int
    {
        return (int)($this->config['submission_rate_limit_seconds'] ?? 120);
    }

    public function createSubmission(array $input, string $remoteAddress): int
    {
        $repository = trim((string)($input['repository'] ?? ''));
        if (!preg_match('#^(https://github\.com/)?[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/?$#', $repository)) {
            throw new InvalidArgumentException('Enter a valid GitHub repository URL.');
        }
        $fields = [];
        foreach (['name', 'description', 'plugin_types', 'license', 'maturity', 'dependencies', 'message', 'submitter_name', 'submitter_email'] as $field) {
            $fields[$field] = substr(trim((string)($input[$field] ?? '')), 0, 2000);
        }
        if ($fields['submitter_email'] !== '' && !filter_var($fields['submitter_email'], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Enter a valid contact email, or leave it blank.');
        }
        if ($remoteAddress !== '') {
            $recent = $this->db->prepare('SELECT COUNT(*) FROM plugin_submissions WHERE remote_address=? AND created_at > ?');
            $recent->execute([$remoteAddress, gmdate('Y-m-d\TH:i:s\Z', time() - $this->submissionThrottleSeconds())]);
            if ((int)$recent->fetchColumn() > 0) throw new InvalidArgumentException('Please wait a bit before submitting another plugin.');
        }
        $now = self::now();
        $statement = $this->db->prepare(
            'INSERT INTO plugin_submissions(name,repository,description,plugin_types,license,maturity,dependencies,message,submitter_name,submitter_email,status,remote_address,created_at,updated_at)
             VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $statement->execute([
            $fields['name'], $repository, $fields['description'], $fields['plugin_types'], $fields['license'],
            $fields['maturity'], $fields['dependencies'], $fields['message'], $fields['submitter_name'],
            $fields['submitter_email'], 'new', $remoteAddress, $now, $now,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function newSubmissionCount(): int
    {
        return (int)$this->db->query("SELECT COUNT(*) FROM plugin_submissions WHERE status='new'")->fetchColumn();
    }

    public function submissions(?string $status = null): array
    {
        $sql = 'SELECT s.*, (SELECT COUNT(*) FROM submission_comments c WHERE c.submission_id=s.id) comment_count FROM plugin_submissions s';
        $params = [];
        if ($status !== null) { $sql .= ' WHERE s.status=?'; $params[] = $status; }
        $sql .= ' ORDER BY s.id DESC';
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll();
    }

    public function submission(int $id): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM plugin_submissions WHERE id=?');
        $statement->execute([$id]);
        return $statement->fetch() ?: null;
    }

    public function submissionComments(int $submissionId): array
    {
        $statement = $this->db->prepare(
            'SELECT c.id,c.body,c.created_at,u.display_name,u.email FROM submission_comments c
             LEFT JOIN users u ON u.id=c.user_id WHERE c.submission_id=? ORDER BY c.id'
        );
        $statement->execute([$submissionId]);
        return $statement->fetchAll();
    }

    public function addSubmissionComment(int $submissionId, int $userId, string $body): void
    {
        if (!$this->submission($submissionId)) throw new InvalidArgumentException('Unknown submission.');
        $body = trim($body);
        if ($body === '') throw new InvalidArgumentException('Comment cannot be empty.');
        $length = function_exists('mb_strlen') ? mb_strlen($body, 'UTF-8') : strlen($body);
        if ($length > 4000) throw new InvalidArgumentException('Comment is too long.');
        $statement = $this->db->prepare('INSERT INTO submission_comments(submission_id,user_id,body,created_at) VALUES(?,?,?,?)');
        $statement->execute([$submissionId, $userId, $body, self::now()]);
    }

    public function setSubmissionStatus(int $submissionId, string $status): void
    {
        if (!in_array($status, ['new', 'in_review', 'reviewed_ok', 'denied', 'spam'], true)) {
            throw new InvalidArgumentException('Invalid status.');
        }
        if (!$this->submission($submissionId)) throw new InvalidArgumentException('Unknown submission.');
        $statement = $this->db->prepare('UPDATE plugin_submissions SET status=?,updated_at=? WHERE id=?');
        $statement->execute([$status, self::now(), $submissionId]);
    }

    /** Active administrators and plugin managers, for submission-notification fan-out. */
    public function submissionNotificationRecipients(): array
    {
        return $this->db->query(
            "SELECT DISTINCT u.email,u.display_name FROM users u JOIN user_roles r ON r.user_id=u.id
             WHERE u.status='active' AND r.role IN ('administrator','plugin_manager') ORDER BY u.email"
        )->fetchAll();
    }
}
