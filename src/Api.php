<?php
declare(strict_types=1);

final class BanquiseApi
{
    public function __construct(private readonly BanquiseApp $app) {}

    private function json(int $status, array $body): never
    {
        http_response_code($status);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }

    private function body(): array
    {
        try { $body = json_decode(file_get_contents('php://input'), true, 32, JSON_THROW_ON_ERROR); }
        catch (Throwable) { $this->json(400, ['error' => 'invalid_json']); }
        if (!is_array($body)) $this->json(400, ['error' => 'invalid_body']);
        return $body;
    }

    private function bearer(): string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        return preg_match('/^Bearer ([A-Za-z0-9_-]{32,})$/', $header, $match) ? $match[1] : '';
    }

    private function authenticatedAgent(string $uid): array
    {
        $agent = $this->app->agent($uid);
        $token = $this->bearer();
        if (!$agent || $token === '' || !password_verify($token, $agent['credential_hash'])) {
            $this->json(401, ['error' => 'invalid_agent_credential']);
        }
        return $agent;
    }

    private function replaceAgentPlugins(string $uid, mixed $plugins, string $observedAt): void
    {
        if (!is_array($plugins)) return;
        $delete = $this->app->db->prepare('DELETE FROM agent_plugins WHERE server_uid=?');
        $delete->execute([$uid]);
        // Upsert rather than plain INSERT: a single report can legitimately list the same
        // plugin name more than once (e.g. a plugin registering several INFORMATION_SCHEMA
        // rows under one name), and the primary key is (server_uid, name).
        $sql = 'INSERT INTO agent_plugins(server_uid,name,installed,loaded,managed,installed_version,observed_at) VALUES(?,?,?,?,?,?,?) '
            . ($this->app->databaseDriver === 'mariadb'
                ? 'ON DUPLICATE KEY UPDATE installed=VALUES(installed), loaded=VALUES(loaded), managed=VALUES(managed), installed_version=VALUES(installed_version), observed_at=VALUES(observed_at)'
                : 'ON CONFLICT(server_uid,name) DO UPDATE SET installed=excluded.installed, loaded=excluded.loaded, managed=excluded.managed, installed_version=excluded.installed_version, observed_at=excluded.observed_at');
        $insert = $this->app->db->prepare($sql);
        foreach ($plugins as $plugin) {
            if (!is_array($plugin) || !preg_match('/^[A-Za-z0-9_-]{1,128}$/', (string)($plugin['name'] ?? ''))) continue;
            $insert->execute([$uid, $plugin['name'], !empty($plugin['installed']) ? 1 : 0,
                !empty($plugin['loaded']) ? 1 : 0, !empty($plugin['managed']) ? 1 : 0,
                substr((string)($plugin['installed_version'] ?? ''), 0, 64), $observedAt]);
        }
    }

    public function dispatch(string $method, string $path): never
    {
        if ($method === 'POST' && $path === '/api/v1/agents/register') $this->register();
        if ($method === 'POST' && preg_match('#^/api/v1/agents/([^/]+)/poll$#', $path, $m)) $this->poll(rawurldecode($m[1]));
        if ($method === 'POST' && preg_match('#^/api/v1/agents/([^/]+)/tasks/([0-9]+)/ack$#', $path, $m)) {
            $this->ack(rawurldecode($m[1]), (int)$m[2]);
        }
        $this->json(404, ['error' => 'not_found']);
    }

    private function register(): never
    {
        $provided = $_SERVER['HTTP_X_BANQUISE_ENROLLMENT'] ?? '';
        $mode = $this->app->enrollmentMode();
        $providedHash = hash('sha256', $provided);
        if ($mode === 'dedicated') {
            $check = $this->app->db->prepare('SELECT 1 FROM enrollment_tokens WHERE token_hash=?');
            $check->execute([$providedHash]);
            if ($provided === '' || !$check->fetchColumn()) $this->json(403, ['error' => 'enrollment_denied']);
        } else {
            $configured = $this->app->config['enrollment_token_hash'] ?? '';
            $actual = 'sha256:' . $providedHash;
            if ($provided === '' || !hash_equals($configured, $actual)) $this->json(403, ['error' => 'enrollment_denied']);
        }
        $b = $this->body();
        foreach (['server_uid','mariadb_version','os','architecture','catalog_key_id'] as $field) {
            if (!isset($b[$field]) || !is_string($b[$field]) || $b[$field] === '') $this->json(422, ['error' => "missing_$field"]);
        }
        if (!preg_match('/^[A-Za-z0-9_.:-]{8,128}$/', $b['server_uid'])) $this->json(422, ['error' => 'invalid_server_uid']);
        $expected = $this->app->expectedKeyId();
        if ($expected === '' || !hash_equals($expected, strtolower($b['catalog_key_id']))) {
            $this->json(403, ['error' => 'catalog_key_mismatch']);
        }
        if ($this->app->agent($b['server_uid'])) $this->json(409, ['error' => 'already_registered']);
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $now = BanquiseApp::now();
        $this->app->db->beginTransaction();
        try {
            if ($mode === 'dedicated') {
                $consume = $this->app->db->prepare('DELETE FROM enrollment_tokens WHERE token_hash=?');
                $consume->execute([$providedHash]);
                if (!$consume->rowCount()) {
                    $this->app->db->rollBack();
                    $this->json(403, ['error' => 'enrollment_denied']);
                }
            }
            $statement = $this->app->db->prepare(
                "INSERT INTO agents(server_uid,credential_hash,mariadb_version,os,architecture,catalog_key_id,remote_address,first_seen_at,last_seen_at)
                 VALUES(?,?,?,?,?,?,?,?,?)"
            );
            $statement->execute([$b['server_uid'], password_hash($token, PASSWORD_ARGON2ID), $b['mariadb_version'],
                $b['os'], $b['architecture'], strtolower($b['catalog_key_id']), $_SERVER['REMOTE_ADDR'] ?? '', $now, $now]);
            $this->app->db->commit();
        } catch (Throwable $e) {
            if ($this->app->db->inTransaction()) $this->app->db->rollBack();
            throw $e;
        }
        $this->json(201, ['status' => 'quarantine', 'agent_token' => $token]);
    }

    private function poll(string $uid): never
    {
        $agent = $this->authenticatedAgent($uid);
        $b = $this->body();
        $now = BanquiseApp::now();
        $statement = $this->app->db->prepare(
            'UPDATE agents SET mariadb_version=?,os=?,architecture=?,remote_address=?,last_seen_at=?,last_error=? WHERE server_uid=?'
        );
        $statement->execute([(string)($b['mariadb_version'] ?? ''), (string)($b['os'] ?? ''),
            (string)($b['architecture'] ?? ''), $_SERVER['REMOTE_ADDR'] ?? '', $now,
            substr((string)($b['last_error'] ?? ''), 0, 2048), $uid]);
        $this->app->db->beginTransaction();
        try {
            $this->replaceAgentPlugins($uid, $b['plugins'] ?? [], $now);
            $this->app->db->commit();
        } catch (Throwable $e) { $this->app->db->rollBack(); throw $e; }
        $base = rtrim($this->app->config['public_base_url'], '/');
        $catalogUrl = "$base/catalog.json";
        if ($agent['status'] !== 'active') {
            $this->json(200, ['status' => $agent['status'], 'catalog_url' => $catalogUrl, 'tasks' => []]);
        }
        $statement = $this->app->db->prepare(
            "SELECT id,action,plugin_name FROM tasks WHERE server_uid=? AND
             (state='queued' OR (state='delivered' AND delivered_at < ?)) ORDER BY id LIMIT 20"
        );
        $statement->execute([$uid, gmdate('Y-m-d\\TH:i:s\\Z', time() - 300)]); $tasks = $statement->fetchAll();
        if ($tasks) {
            $ids = array_column($tasks, 'id');
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $update = $this->app->db->prepare("UPDATE tasks SET state='delivered',delivered_at=? WHERE state IN ('queued','delivered') AND id IN ($marks)");
            $update->execute(array_merge([$now], $ids));
        }
        $this->json(200, ['status' => 'active', 'catalog_url' => $catalogUrl, 'tasks' => $tasks]);
    }

    private function ack(string $uid, int $id): never
    {
        $this->authenticatedAgent($uid); $b = $this->body();
        $state = ($b['success'] ?? false) ? 'succeeded' : 'failed';
        $now = BanquiseApp::now();
        $statement = $this->app->db->prepare(
            "UPDATE tasks SET state=?,completed_at=?,result=? WHERE id=? AND server_uid=? AND state IN ('delivered','queued')"
        );
        $this->app->db->beginTransaction();
        try {
            $statement->execute([$state, $now, substr((string)($b['result'] ?? ''), 0, 4096), $id, $uid]);
            $updated = $statement->rowCount();
            if ($updated) $this->replaceAgentPlugins($uid, $b['plugins'] ?? null, $now);
            $this->app->db->commit();
        } catch (Throwable $e) {
            $this->app->db->rollBack();
            throw $e;
        }
        $this->json($updated ? 200 : 409, ['status' => $state]);
    }
}
