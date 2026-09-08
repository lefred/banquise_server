<?php
declare(strict_types=1);

/**
 * JSON admin API for programmatic clients (dashboards, chatbots, other
 * integrations) that shouldn't need a browser session. Authenticated with a
 * per-user bearer API key (see BanquiseApp::authenticateApiKey()) rather than
 * a session cookie; a key simply acts as the user it was issued for, so
 * authorization reuses the same BanquiseAuth capabilities as the HTML admin
 * UI. Read-only for now - see README for the planned write endpoints.
 *
 * dispatch() returns normally (without exiting) when the path doesn't match
 * any route here, so the caller can fall through to BanquiseApi's agent
 * routes / its catch-all 404.
 */
final class BanquiseAdminApi
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

    private function authenticate(): BanquiseAuth
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $token = preg_match('/^Bearer\s+(\S+)$/', $header, $m) ? $m[1] : '';
        $auth = $this->app->authenticateApiKey($token);
        if (!$auth->isAuthenticated()) $this->json(401, ['error' => 'invalid_api_key']);
        return $auth;
    }

    private function requireCapability(BanquiseAuth $auth, string $capability): void
    {
        if (!$auth->can($capability)) $this->json(403, ['error' => 'forbidden']);
    }

    public function dispatch(string $method, string $path): void
    {
        if ($path === '/api/v1/servers') {
            if ($method !== 'GET') $this->json(405, ['error' => 'method_not_allowed']);
            $auth = $this->authenticate();
            $this->requireCapability($auth, 'fleet.view');
            $catalog = $this->app->catalog();
            $agents = $this->app->agents();
            $counts = $this->app->pluginCountsByServer($catalog, $agents);
            $this->json(200, ['servers' => array_map(
                fn(array $agent): array => $this->serverSummary($agent, $counts[$agent['server_uid']] ?? null),
                $agents
            )]);
        }

        if (preg_match('#^/api/v1/servers/([^/]+)/tasks$#', $path, $m)) {
            if ($method !== 'GET') $this->json(405, ['error' => 'method_not_allowed']);
            $auth = $this->authenticate();
            $this->requireCapability($auth, 'fleet.view');
            $uid = rawurldecode($m[1]);
            if (!$this->app->agent($uid)) $this->json(404, ['error' => 'server_not_found']);
            $this->json(200, ['tasks' => array_map($this->taskSummary(...), $this->app->tasks($uid))]);
        }

        if (preg_match('#^/api/v1/servers/([^/]+)/plugins/([^/]+)/(install|update|uninstall)$#', $path, $m)) {
            if ($method !== 'POST') $this->json(405, ['error' => 'method_not_allowed']);
            $auth = $this->authenticate();
            $this->requireCapability($auth, 'fleet.manage');
            $uid = rawurldecode($m[1]);
            $plugin = rawurldecode($m[2]);
            $action = $m[3];
            if (!$this->app->agent($uid)) $this->json(404, ['error' => 'server_not_found']);
            try {
                $body = json_decode(file_get_contents('php://input') ?: '{}', true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($body) || !is_string($body['catalog_name'] ?? '')) throw new InvalidArgumentException('Invalid catalog_name.');
                $created = $this->app->queueTasks($uid, $action, [$plugin], $body['catalog_name'] ?? '');
            } catch (InvalidArgumentException|JsonException $e) {
                $this->json(422, ['error' => 'invalid_request', 'message' => $e->getMessage()]);
            }
            $pending = null;
            foreach ($this->app->tasks($uid) as $task) {
                if ($task['action'] === $action && $task['plugin_name'] === $plugin
                    && in_array($task['state'], ['queued', 'delivered'], true)) { $pending = $task; break; }
            }
            $this->json(202, [
                'queued' => $created > 0,
                'task' => $pending ? $this->taskSummary($pending) : null,
            ]);
        }

        if (preg_match('#^/api/v1/servers/([^/]+)$#', $path, $m)) {
            if ($method !== 'GET') $this->json(405, ['error' => 'method_not_allowed']);
            $auth = $this->authenticate();
            $this->requireCapability($auth, 'fleet.view');
            $uid = rawurldecode($m[1]);
            $agent = $this->app->agent($uid);
            if (!$agent) $this->json(404, ['error' => 'server_not_found']);
            $summary = $this->serverSummary($agent, null);
            $summary['catalogs'] = $this->app->catalogDescriptors($uid);
            $summary['catalog_key_ids'] = $this->app->agentKeyIds($uid);
            $summary['plugins'] = array_map(static fn(array $p): array => [
                'name' => $p['name'],
                'catalog_name' => $p['catalog_name'],
                'installed' => (bool)$p['installed'],
                'loaded' => (bool)$p['loaded'],
                'managed' => (bool)$p['managed'],
                'installed_version' => $p['installed_version'],
                'observed_at' => $p['observed_at'],
            ], $this->app->agentPlugins($uid));
            $this->json(200, $summary);
        }

        if ($path === '/api/v1/catalog') {
            if ($method !== 'GET') $this->json(405, ['error' => 'method_not_allowed']);
            $auth = $this->authenticate();
            $this->requireCapability($auth, 'fleet.view');
            $uid = (string)($_GET['server_uid'] ?? '');
            if ($uid === '') $this->json(200, $this->app->catalog());
            if (!$this->app->agent($uid)) $this->json(404, ['error'=>'server_not_found']);
            $this->json(200, $this->app->agentCatalog($uid));
        }

        if ($path === '/api/v1/catalog/updates') {
            if ($method !== 'GET') $this->json(405, ['error' => 'method_not_allowed']);
            $auth = $this->authenticate();
            $this->requireCapability($auth, 'fleet.view');
            $this->json(200, ['updates_by_server' => $this->app->pluginUpdatesByServer($this->app->catalog())]);
        }

        if ($path === '/api/v1/submissions') {
            if ($method !== 'GET') $this->json(405, ['error' => 'method_not_allowed']);
            $auth = $this->authenticate();
            $this->requireCapability($auth, 'submissions.review');
            $allowed = ['new', 'in_review', 'reviewed_ok', 'denied', 'spam'];
            $status = (string)($_GET['status'] ?? '');
            $this->json(200, ['submissions' => $this->app->submissions(in_array($status, $allowed, true) ? $status : null)]);
        }
    }

    private function serverSummary(array $agent, ?array $counts): array
    {
        return [
            'server_uid' => $agent['server_uid'],
            'display_name' => $agent['display_name'],
            'status' => $agent['status'],
            'mariadb_version' => $agent['mariadb_version'],
            'os' => $agent['os'],
            'architecture' => $agent['architecture'],
            'last_seen_at' => $agent['last_seen_at'],
            'pending_tasks' => (int)($agent['pending_tasks'] ?? 0),
            'plugins_installed' => $counts['installed'] ?? null,
            'plugins_available' => $counts['available'] ?? null,
        ];
    }

    private function taskSummary(array $task): array
    {
        return [
            'id' => (int)$task['id'],
            'action' => $task['action'],
            'plugin_name' => $task['plugin_name'],
            'catalog_name' => $task['catalog_name'],
            'state' => $task['state'],
            'requested_at' => $task['requested_at'],
            'delivered_at' => $task['delivered_at'],
            'completed_at' => $task['completed_at'],
            'result' => $task['result'],
        ];
    }
}
