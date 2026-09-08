<?php
declare(strict_types=1);

trait BanquiseFleetRepositories
{
    public function fleetRepositories(): array
    {
        return $this->db->query('SELECT * FROM fleet_repositories ORDER BY name')->fetchAll();
    }

    public static function publicKeyId(string $key): string
    {
        $lines = preg_split('/\r?\n/', trim($key));
        $packet = count($lines) === 2 ? base64_decode($lines[1], true) : false;
        if ($packet === false || strlen($packet) !== 42 || substr($packet, 0, 2) !== 'Ed') {
            throw new InvalidArgumentException('Provide a valid Minisign public key file.');
        }
        return bin2hex(substr($packet, 2, 8));
    }

    public function saveFleetRepository(string $name, string $url, string $key): void
    {
        if (!preg_match('/^[A-Za-z0-9_.-]{1,128}$/D', $name)) throw new InvalidArgumentException('Invalid repository name.');
        if (strlen($url) > 2048 || !filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https'
            || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_FRAGMENT) !== null) {
            throw new InvalidArgumentException('Use an HTTPS catalog URL without credentials or a fragment.');
        }
        $id = self::publicKeyId($key);
        $catalog = $this->fetchAuthorityCatalog($url, $key);
        foreach ($catalog['plugins'] as $entry) {
            if (!is_array($entry)) throw new InvalidArgumentException('Invalid catalog entry.');
            $this->validateCatalogEntryFields($entry);
        }
        $this->db->beginTransaction();
        try {
            // Updating a URL/key must never redirect an already queued operation.
            $this->cancelRepositoryTasks($name, null, 'Repository configuration refreshed; queue the operation again');
            $sql = 'INSERT INTO fleet_repositories(name,url,public_key,key_id,catalog_json,synced_at) VALUES(?,?,?,?,?,?) '
                . ($this->databaseDriver === 'mariadb'
                    ? 'ON DUPLICATE KEY UPDATE url=VALUES(url),public_key=VALUES(public_key),key_id=VALUES(key_id),catalog_json=VALUES(catalog_json),synced_at=VALUES(synced_at)'
                    : 'ON CONFLICT(name) DO UPDATE SET url=excluded.url,public_key=excluded.public_key,key_id=excluded.key_id,catalog_json=excluded.catalog_json,synced_at=excluded.synced_at');
            $this->db->prepare($sql)->execute([$name,$url,trim($key),$id,json_encode($catalog, JSON_THROW_ON_ERROR),self::now()]);
            $this->db->commit();
        } catch (Throwable $e) { $this->db->rollBack(); throw $e; }
    }

    private function cancelRepositoryTasks(string $name, ?string $uid, string $reason): void
    {
        $sql = "UPDATE tasks SET state='cancelled',completed_at=?,result=? WHERE state IN ('queued','delivered') "
            . 'AND id IN (SELECT task_id FROM task_repositories WHERE catalog_name=?)';
        $args = [self::now(),$reason,$name];
        if ($uid !== null) { $sql .= ' AND server_uid=?'; $args[] = $uid; }
        $this->db->prepare($sql)->execute($args);
    }

    public function deleteFleetRepository(string $name): void
    {
        $this->db->beginTransaction();
        try {
            $this->cancelRepositoryTasks($name, null, 'Repository removed');
            $this->db->prepare('DELETE FROM fleet_repositories WHERE name=?')->execute([$name]);
            $this->db->commit();
        } catch (Throwable $e) { $this->db->rollBack(); throw $e; }
    }

    public function agentKeyIds(string $uid): array
    {
        $q = $this->db->prepare('SELECT key_id FROM agent_trusted_keys WHERE server_uid=? ORDER BY key_id');
        $q->execute([$uid]);
        return $q->fetchAll(PDO::FETCH_COLUMN);
    }

    public function agentRepositories(string $uid): array
    {
        $q = $this->db->prepare('SELECT r.* FROM fleet_repositories r JOIN agent_repositories a ON a.catalog_name=r.name WHERE a.server_uid=? ORDER BY r.name');
        $q->execute([$uid]);
        return $q->fetchAll();
    }

    public function assignAgentRepositories(string $uid, array $names): void
    {
        if (!$this->agent($uid)) throw new InvalidArgumentException('Unknown agent.');
        $known = array_column($this->fleetRepositories(), null, 'name');
        $keys = $this->agentKeyIds($uid);
        $names = array_unique($names);
        if (count($names) > 128) throw new InvalidArgumentException("At most 128 catalogs may be assigned to one agent.");
        foreach ($names as $name) {
            if (!is_string($name) || !isset($known[$name])) throw new InvalidArgumentException('Unknown repository.');
            if (!in_array($known[$name]['key_id'], $keys, true)) throw new InvalidArgumentException("Agent does not trust the key for $name. Provision it locally first, then wait for a heartbeat.");
        }
        $this->db->beginTransaction();
        try {
            foreach ($this->agentRepositories($uid) as $repo) {
                if (!in_array($repo['name'], $names, true)) $this->cancelRepositoryTasks($repo['name'], $uid, 'Repository unassigned');
            }
            $this->db->prepare('DELETE FROM agent_repositories WHERE server_uid=?')->execute([$uid]);
            $insert = $this->db->prepare('INSERT INTO agent_repositories(server_uid,catalog_name) VALUES(?,?)');
            foreach ($names as $name) $insert->execute([$uid,$name]);
            $this->db->commit();
        } catch (Throwable $e) { $this->db->rollBack(); throw $e; }
    }

    public function agentCatalog(string $uid): array
    {
        $plugins = [];
        foreach ($this->agentRepositories($uid) as $repo) {
            $catalog = json_decode($repo['catalog_json'], true, 64, JSON_THROW_ON_ERROR);
            foreach ($catalog['plugins'] ?? [] as $entry) {
                $entry['catalog_name'] = $repo['name'];
                $plugins[] = $entry;
            }
        }
        return ['plugins' => $plugins];
    }

    public function catalogDescriptors(string $uid): array
    {
        return array_map(static fn($r) => ['name'=>$r['name'],'url'=>$r['url'],'key_id'=>$r['key_id']], array_values(array_filter($this->agentRepositories($uid), fn($r) => in_array($r['key_id'], $this->agentKeyIds($uid), true))));
    }

    public function recordAgentKeys(string $uid, array $keys): void
    {
        $this->db->prepare('DELETE FROM agent_trusted_keys WHERE server_uid=?')->execute([$uid]);
        $insert = $this->db->prepare('INSERT INTO agent_trusted_keys(server_uid,key_id) VALUES(?,?)');
        foreach (array_unique($keys) as $id) $insert->execute([$uid,$id]);
        foreach ($this->agentRepositories($uid) as $repo) {
            if (!in_array($repo['key_id'], $keys, true)) $this->cancelRepositoryTasks($repo['name'], $uid, 'Agent no longer trusts the repository key');
        }
    }
}
