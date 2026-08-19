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
        $mapping = [
            'name' => '--name', 'soname' => '--soname',
            'plugin_types' => '--plugin-types', 'license' => '--license',
            'maturity' => '--maturity', 'description' => '--description',
            'dependencies' => '--dependencies', 'message' => '--message',
        ];
        foreach ($mapping as $field => $flag) {
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

    public function catalogEntryId(array $entry): string
    {
        return hash('sha256', implode("\0", [
            (string)($entry['name'] ?? ''), (string)($entry['mariadb_version'] ?? ''),
            (string)($entry['architecture'] ?? ''), (string)($entry['soname'] ?? ''),
        ]));
    }

    public function editCatalogEntry(string $id, array $input, string $password): void
    {
        $catalog = $this->catalog();
        $index = $this->findCatalogEntry($catalog, $id);
        $entry = [];
        $required = ['name','repository','version','mariadb_version','architecture','soname','download_url','sha256'];
        $optional = ['archive_type','archive_member','plugin_types','license','maturity','description','dependencies','message'];
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
        return $this->importRepository($repository, [
            'name' => $entry['name'], 'soname' => $entry['soname'],
            'dependencies' => $entry['dependencies'] ?? '', 'message' => $entry['message'] ?? '',
            'signing_password' => $password,
        ]);
    }

    private function findCatalogEntry(array $catalog, string $id): int
    {
        foreach (($catalog['plugins'] ?? []) as $index => $entry) {
            if (hash_equals($this->catalogEntryId($entry), $id)) return $index;
        }
        throw new InvalidArgumentException('Catalog entry no longer exists');
    }

    private function publishCatalogArray(array $catalog, string $password): void
    {
        $plugins = array_values($catalog['plugins'] ?? []);
        usort($plugins, static fn(array $a, array $b): int => [
            strtolower((string)$a['name']), (string)$a['name'], (string)$a['mariadb_version'],
            (string)$a['architecture'], (string)$a['soname'],
        ] <=> [
            strtolower((string)$b['name']), (string)$b['name'], (string)$b['mariadb_version'],
            (string)$b['architecture'], (string)$b['soname'],
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
}
