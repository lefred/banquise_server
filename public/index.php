<?php
declare(strict_types=1);

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (PHP_SAPI === 'cli-server' && $requestPath !== '/') {
    $staticFile = realpath(__DIR__ . $requestPath);
    if ($staticFile !== false && str_starts_with($staticFile, __DIR__ . DIRECTORY_SEPARATOR) && is_file($staticFile)) {
        return false;
    }
}

require dirname(__DIR__) . '/src/App.php';
require dirname(__DIR__) . '/src/Api.php';
$configFile = dirname(__DIR__) . '/config.php';
if (!is_file($configFile)) { http_response_code(503); exit('Copy config.example.php to config.php and configure Banquise.'); }
$app = new BanquiseApp(require $configFile);
$path = $requestPath;
if (str_starts_with($path, '/api/')) (new BanquiseApi($app))->dispatch($_SERVER['REQUEST_METHOD'], $path);

session_name($app->config['session_name'] ?? 'banquise_admin');
session_set_cookie_params(['secure' => isset($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Strict']);
session_start();
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'login') {
    if (hash_equals($app->config['admin_user'], (string)($_POST['user'] ?? '')) &&
        password_verify((string)($_POST['password'] ?? ''), $app->config['admin_password_hash'])) {
        session_regenerate_id(true); $_SESSION['admin'] = true; $_SESSION['csrf'] = bin2hex(random_bytes(32));
        header('Location: /'); exit;
    }
    $message = 'Invalid credentials.';
}
if (isset($_GET['logout'])) { session_destroy(); header('Location: /'); exit; }
$authenticated = !empty($_SESSION['admin']);
if ($authenticated && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') !== 'login') {
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) { http_response_code(403); exit('Invalid CSRF token'); }
    try {
        switch ($_POST['form'] ?? '') {
            case 'agent_status': $app->setAgentStatus((string)$_POST['uid'], (string)$_POST['status']); $message = 'Agent status updated.'; break;
            case 'agent_rename':
                $app->renameAgent((string)$_POST['uid'], (string)($_POST['display_name'] ?? ''));
                $message = 'Server name updated.';
                break;
            case 'agent_delete':
                if ((string)($_POST['confirmation'] ?? '') !== 'DELETE') throw new InvalidArgumentException('Type DELETE to confirm server removal.');
                $app->deleteAgent((string)$_POST['uid']);
                $message = 'Server deleted, including its observed plugin state and task history.';
                break;
            case 'task': $app->queueTask((string)$_POST['uid'], (string)$_POST['action'], (string)$_POST['plugin']); $message = 'Task queued.'; break;
            case 'bulk_task':
                $created = $app->queueTasks((string)$_POST['uid'], (string)$_POST['action'], is_array($_POST['plugins'] ?? null) ? $_POST['plugins'] : []);
                $message = $created ? "$created plugin task(s) queued." : 'All selected tasks were already pending.';
                break;
            case 'repository': $message = $app->importRepository((string)$_POST['repository'], $_POST); break;
            case 'catalog_edit':
                $app->editCatalogEntry((string)$_POST['entry_id'], $_POST, (string)($_POST['signing_password'] ?? ''));
                $message = 'Catalog entry updated, signed, and published.';
                break;
            case 'catalog_refresh':
                $message = $app->refreshCatalogEntry((string)$_POST['entry_id'], (string)($_POST['signing_password'] ?? ''));
                break;
            case 'catalog_delete':
                if ((string)($_POST['confirmation'] ?? '') !== 'DELETE') throw new InvalidArgumentException('Type DELETE to confirm removal.');
                $app->deleteCatalogEntry((string)$_POST['entry_id'], (string)($_POST['signing_password'] ?? ''));
                $message = 'Catalog entry deleted; the new catalog was signed and published.';
                break;
            case 'enrollment_mode':
                $app->setEnrollmentMode((string)($_POST['mode'] ?? ''));
                $message = 'Enrollment mode updated.';
                break;
            case 'enrollment_generate':
                $created = $app->generateEnrollmentTokens((int)($_POST['count'] ?? 0));
                $message = count($created) . ' dedicated enrollment token(s) generated.';
                break;
            case 'enrollment_revoke':
                $app->revokeEnrollmentToken((string)($_POST['token_id'] ?? ''));
                $message = 'Enrollment token revoked.';
                break;
        }
    } catch (Throwable $e) { $message = $e->getMessage(); }
    $_SESSION['flash_message'] = $message;
    $form = (string)($_POST['form'] ?? '');
    $uid = (string)($_POST['uid'] ?? '');
    if ($uid !== '' && $form !== 'agent_delete') {
        $redirect = '/?agent=' . rawurlencode($uid) . '#server-detail';
    } elseif (str_starts_with($form, 'catalog_') || $form === 'repository') {
        $redirect = '/#catalog';
    } elseif (str_starts_with($form, 'enrollment_')) {
        $redirect = '/?page=admin#admin';
    } else {
        $redirect = '/#servers';
    }
    header('Location: ' . $redirect, true, 303);
    exit;
}
if ($authenticated && $_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['task_status'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    try {
        echo json_encode(['completion_token' => $app->taskCompletionToken((string)$_GET['task_status'])], JSON_THROW_ON_ERROR);
    } catch (InvalidArgumentException $e) {
        http_response_code(404);
        echo json_encode(['error' => $e->getMessage()], JSON_THROW_ON_ERROR);
    }
    exit;
}
if ($authenticated && $_SERVER['REQUEST_METHOD'] === 'GET' && isset($_SESSION['flash_message'])) {
    $message = (string)$_SESSION['flash_message'];
    unset($_SESSION['flash_message']);
}
$adminPage = $authenticated && (string)($_GET['page'] ?? '') === 'admin';
$catalog = $authenticated && !$adminPage ? $app->catalog() : ['plugins' => []];
$agents = $authenticated && !$adminPage ? $app->agents() : [];
$pluginUpdates = $authenticated && !$adminPage ? $app->pluginUpdatesByServer($catalog) : [];
$pluginCounts = $authenticated && !$adminPage ? $app->pluginCountsByServer($catalog, $agents) : [];
$selectedUid = $authenticated && !$adminPage ? (string)($_GET['agent'] ?? '') : '';
$selected = $selectedUid !== '' ? $app->agent($selectedUid) : null;
function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function csrf(): string { return h($_SESSION['csrf'] ?? ''); }
function online(array $agent, int $threshold = 180): bool { return strtotime($agent['last_seen_at']) >= time() - $threshold; }
$onlineThreshold = (int)($app->config['online_threshold_seconds'] ?? 180);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Banquise</title><link rel="icon" href="/assets/favicon.svg" type="image/svg+xml" sizes="any"><link rel="stylesheet" href="/assets/style.css"></head><body>
<?php if (!$authenticated): ?>
<main class="login"><section class="card login-card"><div class="brand-mark">B</div><h1>Banquise</h1><p>MariaDB plugin fleet control</p>
<?php if ($message): ?><div class="alert"><?=h($message)?></div><?php endif; ?>
<form method="post"><input type="hidden" name="form" value="login"><label>Username<input name="user" autocomplete="username" required autofocus></label>
<label>Password<input type="password" name="password" autocomplete="current-password" required></label><button>Sign in</button></form></section></main>
<?php else: ?>
<header><a class="brand" href="/"><span class="brand-mark">B</span><span>Banquise</span><img class="mariadb-seal" src="/assets/mariadb-foundation-seal.svg" alt="MariaDB Foundation"></a><nav><?php if($adminPage): ?><a href="/">Dashboard</a><?php else: ?><a href="#catalog">Catalog</a><a href="#servers">Servers</a><a href="?page=admin">Admin</a><?php endif; ?><a href="?logout=1">Sign out</a></nav></header>
<main class="shell"><?php if ($message): ?><div class="alert"><?=nl2br(h($message))?></div><?php endif; ?>
<?php if(!$adminPage): ?>
<section class="hero"><div><span class="eyebrow">CONTROL PLANE</span><h1><span class="hero-title-blue">Your MariaDB plugin fleet,</span><br><em>under control.</em></h1><p>Signed catalog publishing, quarantined enrollment, and explicit server-level operations.</p></div>
<div class="metrics"><div><strong><?=count($agents)?></strong><span>servers</span></div><div><strong><?=count(array_filter($agents,fn($a)=>online($a,$onlineThreshold)))?></strong><span>online</span></div><div><strong><?=count($catalog['plugins'] ?? [])?></strong><span>catalog entries</span></div></div></section>

<section id="servers"><div class="section-title"><div><span class="eyebrow">FLEET</span><h2>MariaDB servers</h2></div></div>
<div class="server-grid"><?php foreach ($agents as $agent): $serverUpdates=$pluginUpdates[$agent['server_uid']]??[]; $serverPluginCount=$pluginCounts[$agent['server_uid']]??['installed'=>0,'available'=>0]; ?><a class="server card" href="?agent=<?=urlencode($agent['server_uid'])?>#server-detail">
<div class="server-top"><div class="server-identity"><span class="database-icon"><img src="/assets/database-node.svg" alt=""></span><div><span class="server-kicker">DATABASE NODE</span><h3><?=h($agent['display_name'] ?: 'MariaDB server')?></h3></div></div><div class="server-badges"><?php if($serverUpdates): $updateTitle=implode("\n",array_map(static fn($update)=>$update['name'].' '.$update['installed_version'].' → '.$update['available_version'],$serverUpdates)); ?><span class="update-available" title="<?=h($updateTitle)?>" aria-label="<?=count($serverUpdates)?> plugin update<?=count($serverUpdates)===1?'':'s'?> available"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 6.5 8.5l1.8 1.8 2.4-2.4v7.4h2.6V7.9l2.4 2.4 1.8-1.8L12 3Zm-7 13v4h14v-4h-2.5v1.5h-9V16H5Z"/></svg><strong><?=count($serverUpdates)?></strong></span><?php endif; ?><span class="badge <?=h($agent['status'])?>"><?=h($agent['status'])?></span></div></div>
<div class="server-uid" title="<?=h($agent['server_uid'])?>"><?=h($agent['server_uid'])?></div>
<div class="server-specs"><div><span>MariaDB</span><strong><?=h($agent['mariadb_version'])?></strong></div><div><span>Operating system</span><strong><?=h($agent['os'])?></strong></div><div><span>Architecture</span><strong><?=h($agent['architecture'])?></strong></div><div><span>Plugins</span><strong class="plugin-count" title="<?=h($serverPluginCount['installed'])?> of <?=h($serverPluginCount['available'])?> compatible catalog plugins installed"><svg viewBox="0 0 26 26" aria-hidden="true"><path d="M19 13h-2V9h-4V7a2 2 0 1 0-4 0v2H5v4h2a2 2 0 1 1 0 4H5v4h6v-2a2 2 0 1 1 4 0v2h6v-6h-2a1 1 0 0 1 0-2Z"/></svg><span><?=h($serverPluginCount['installed'])?> / <?=h($serverPluginCount['available'])?> installed</span></strong></div></div>
<div class="server-footer"><span class="presence"><span class="dot <?=online($agent,$onlineThreshold)?'up':'down'?>"></span><?=online($agent,$onlineThreshold)?'Online':'Offline'?></span><span>Seen <?=h(str_replace(['T','Z'],[' ',' UTC'],$agent['last_seen_at']))?></span><span><strong><?=h($agent['pending_tasks'])?></strong> pending</span></div></a><?php endforeach; ?>
<?php if (!$agents): ?><div class="empty card">No agents have registered yet.</div><?php endif; ?></div></section>

<?php if ($selected):
$observed=$app->agentPlugins($selectedUid); $tasks=$app->tasks($selectedUid);
$taskCompletionToken=$app->taskCompletionToken($selectedUid);
$hasPendingTasks=(bool)array_filter($tasks,static fn($task)=>in_array($task['state'],['queued','delivered'],true));
$observedByName=[]; foreach($observed as $item) $observedByName[$item['name']]=$item;
$availablePlugins=[]; foreach(($catalog['plugins']??[]) as $item) {
    $versionMatch=$item['mariadb_version']==='any'||str_starts_with($selected['mariadb_version'],$item['mariadb_version']);
    $archMatch=$item['architecture']==='any'||$item['architecture']===$selected['architecture'];
    if($versionMatch&&$archMatch) $availablePlugins[$item['name']]=$item;
} ksort($availablePlugins,SORT_NATURAL|SORT_FLAG_CASE);
$operationTypes=[]; $operationMaturities=[];
foreach($availablePlugins as $availablePlugin) {
    foreach(explode(',',(string)($availablePlugin['plugin_types']??'')) as $type) {
        $type=trim($type); if($type!=='') $operationTypes[strtolower($type)]=$type;
    }
    $maturity=trim((string)($availablePlugin['maturity']??'unknown')) ?: 'unknown';
    $operationMaturities[strtolower($maturity)]=$maturity;
}
asort($operationTypes,SORT_NATURAL|SORT_FLAG_CASE); asort($operationMaturities,SORT_NATURAL|SORT_FLAG_CASE);
?>
<section id="server-detail" class="detail card"<?=$hasPendingTasks?' data-task-watch-url="?task_status='.h(rawurlencode($selectedUid)).'" data-task-completion-token="'.h($taskCompletionToken).'"':''?>><div class="section-title"><div><span class="eyebrow">SERVER</span><div class="editable-server-name"><h2><?=h($selected['display_name'] ?: $selectedUid)?></h2><button type="button" class="edit-name-button" data-dialog="rename-server-dialog" aria-label="Edit server name" title="Edit server name"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h4.2L19 9.2 14.8 5 4 15.8V20Zm12-16.2 4.2 4.2 1.1-1.1a1.5 1.5 0 0 0 0-2.1l-2.1-2.1a1.5 1.5 0 0 0-2.1 0L16 3.8Z"/></svg></button></div><div class="server-detail-uid">ID: <?=h($selectedUid)?></div></div>
<div class="server-detail-actions"><form method="post"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="form" value="agent_status"><input type="hidden" name="uid" value="<?=h($selectedUid)?>">
<button name="status" value="<?=$selected['status']==='active'?'disabled':'active'?>" class="secondary"><?=$selected['status']==='active'?'Disable':'Approve agent'?></button></form><button type="button" class="danger" data-dialog="delete-server-dialog">Delete server</button></div></div>
<form method="post" class="bulk-plugin-form"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="form" value="bulk_task"><input type="hidden" name="uid" value="<?=h($selectedUid)?>">
<div class="bulk-heading"><div><h3>Plugin operations</h3><p>Select one or more compatible catalog plugins, then apply a single action to the selection.</p></div><div class="bulk-heading-actions"><?php if($availablePlugins): ?><button type="button" class="operation-filter-toggle" data-operation-filter-toggle aria-expanded="false" aria-controls="operation-filters"><svg viewBox="0 0 12 12" aria-hidden="true"><path d="m3 4 3 3 3-3Z"/></svg><span>Search and filters</span></button><?php endif; ?><label class="select-all"><input type="checkbox" data-check-all> Select all</label></div></div>
<?php if($availablePlugins): ?><div id="operation-filters" class="operation-filters" hidden><label class="operation-search"><span>Search</span><input type="search" data-operation-search placeholder="Search plugins…" autocomplete="off"></label>
<?php if($operationTypes): ?><div class="filter-group"><span class="filter-label">Type</span><div class="filter-chips"><?php foreach($operationTypes as $value=>$label): ?><button type="button" class="filter-chip" data-operation-filter data-filter-group="type" data-filter-value="<?=h($value)?>" aria-pressed="false"><?=h($label)?></button><?php endforeach; ?></div></div><?php endif; ?>
<?php if($operationMaturities): ?><div class="filter-group"><span class="filter-label">Maturity</span><div class="filter-chips"><?php foreach($operationMaturities as $value=>$label): ?><button type="button" class="filter-chip" data-operation-filter data-filter-group="maturity" data-filter-value="<?=h($value)?>" aria-pressed="false"><?=h($label)?></button><?php endforeach; ?></div></div><?php endif; ?>
<div class="filter-summary"><span><strong data-operation-visible-count><?=count($availablePlugins)?></strong> of <?=count($availablePlugins)?> plugins</span><button type="button" class="filter-clear" data-operation-filter-clear hidden>Clear filters</button></div></div><?php endif; ?>
<div class="plugin-table-wrap"><table class="plugin-selection-table"><thead><tr><th class="check-column"></th><th>Plugin</th><th>Catalog version</th><th>Installed</th><th>Loaded</th><th>Installed version</th></tr></thead><tbody>
<?php foreach($availablePlugins as $name=>$catalogPlugin):
    $state=$observedByName[$name]??null;
    $installed=!empty($state['installed']);
    $installedVersion=trim((string)($state['installed_version']??''));
    $unmanaged=$installed&&empty($state['managed']);
    $updateAvailable=$installed&&$installedVersion!==''&&version_compare(ltrim($installedVersion,'vV'),ltrim((string)$catalogPlugin['version'],'vV'),'<');
    $rowTypes=array_values(array_filter(array_map(static fn($type)=>strtolower(trim($type)),explode(',',(string)($catalogPlugin['plugin_types']??'')))));
    $rowMaturity=strtolower(trim((string)($catalogPlugin['maturity']??'unknown')) ?: 'unknown');
?><tr data-operation-entry data-search-text="<?=h(strtolower($name.' '.($catalogPlugin['plugin_types']??'').' '.$rowMaturity))?>" data-filter-types="<?=h(json_encode($rowTypes,JSON_THROW_ON_ERROR))?>" data-filter-maturity="<?=h($rowMaturity)?>"><td><input type="checkbox" name="plugins[]" value="<?=h($name)?>" aria-label="Select <?=h($name)?>"></td><td><strong><?=h($name)?></strong><small><?=h($catalogPlugin['plugin_types']??'')?></small></td><td><span class="operation-version"><?=h($catalogPlugin['version'])?><?php if($updateAvailable): ?><span class="operation-update" title="Update available: <?=h($installedVersion)?> → <?=h($catalogPlugin['version'])?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 6.5 8.5l1.8 1.8 2.4-2.4v7.4h2.6V7.9l2.4 2.4 1.8-1.8L12 3Zm-7 13v4h14v-4h-2.5v1.5h-9V16H5Z"/></svg>Update</span><?php endif; ?></span></td><td><span class="state-mark <?=$unmanaged?'unmanaged':($installed?'yes':'no')?>"<?=$unmanaged?' title="Plugin file is present, but it was not installed by Banquise"':''?>><?=$unmanaged?'Unmanaged':($installed?'Installed':'Not installed')?></span></td><td><span class="state-mark <?=!empty($state['loaded'])?'yes':'no'?>"><?=!empty($state['loaded'])?'Loaded':'Not loaded'?></span></td><td><?=h($installedVersion!==''?$installedVersion:'—')?></td></tr><?php endforeach; ?>
<?php if($availablePlugins): ?><tr data-operation-no-matches hidden><td colspan="6" class="empty-row">No plugins match the current search and filters.</td></tr><?php endif; ?>
<?php if(!$availablePlugins): ?><tr><td colspan="6" class="empty-row">No catalog plugins are compatible with this server.</td></tr><?php endif; ?></tbody></table></div>
<div class="bulk-action-bar"><label>Action<select name="action"><option value="install">Install and load</option><option value="update">Update</option><option value="load">Load</option><option value="uninstall">Uninstall</option></select></label><div class="selection-count"><strong data-selection-count>0</strong> selected</div><button <?=!$availablePlugins?'disabled':''?>>Queue action for selected plugins</button></div></form>
<h3>Recent tasks</h3><table><thead><tr><th>ID</th><th>Action</th><th>Plugin</th><th>State</th><th>Result</th></tr></thead><tbody><?php foreach($tasks as $task): ?><tr><td><?=h($task['id'])?></td><td><?=h($task['action'])?></td><td><?=h($task['plugin_name'])?></td><td><span class="badge <?=h($task['state'])?>"><?=h($task['state'])?></span></td><td><?=h($task['result'])?></td></tr><?php endforeach; ?></tbody></table></section>
<dialog id="rename-server-dialog"><form method="post" class="stack"><div class="dialog-title"><h2>Edit server name</h2><button type="button" class="icon" data-close>×</button></div><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="form" value="agent_rename"><input type="hidden" name="uid" value="<?=h($selectedUid)?>"><label>Display name<input name="display_name" value="<?=h($selected['display_name'] ?: 'MariaDB server')?>" maxlength="100" autocomplete="off" required autofocus></label><small>The name is only a friendly label. The immutable server ID remains <?=h($selectedUid)?>.</small><button>Save server name</button></form></dialog>
<dialog id="delete-server-dialog"><form method="post" class="stack"><div class="dialog-title"><h2>Delete <?=h($selected['display_name'] ?: $selectedUid)?></h2><button type="button" class="icon" data-close>×</button></div><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="form" value="agent_delete"><input type="hidden" name="uid" value="<?=h($selectedUid)?>"><p>This permanently removes the server registration, its observed plugin state, and all task history. If the agent still has valid credentials, its next request will be rejected; registering it again requires a new enrollment.</p><label>Type DELETE to confirm<input name="confirmation" pattern="DELETE" autocomplete="off" required></label><button class="danger">Delete server permanently</button></form></dialog>
<?php endif;
$installedServersByPlugin=[];
foreach($app->installedPluginServers() as $installedPluginServer) {
    $installedServersByPlugin[$installedPluginServer['name']][]=$installedPluginServer;
}
$catalogTypes=[]; $catalogMaturities=[];
foreach(($catalog['plugins']??[]) as $catalogPlugin) {
    foreach(explode(',',(string)($catalogPlugin['plugin_types']??'')) as $type) {
        $type=trim($type); if($type!=='') $catalogTypes[strtolower($type)]=$type;
    }
    $maturity=trim((string)($catalogPlugin['maturity']??'unknown')) ?: 'unknown';
    $catalogMaturities[strtolower($maturity)]=$maturity;
}
asort($catalogTypes,SORT_NATURAL|SORT_FLAG_CASE); asort($catalogMaturities,SORT_NATURAL|SORT_FLAG_CASE);
?>

<?php $catalogPublicUrl=rtrim((string)($app->config['public_base_url']??''),'/').'/catalog.json'; ?>
<section id="catalog" data-catalog-filter-root><div class="section-title"><div><span class="eyebrow">SIGNED ARTIFACTS</span><div class="catalog-title"><h2>Plugin catalog</h2><button type="button" class="catalog-copy-button" data-copy-catalog-url="<?=h($catalogPublicUrl)?>" title="Copy catalog URL" aria-label="Copy catalog URL"><svg class="copy-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 7V4.8C8 3.8 8.8 3 9.8 3h9.4c1 0 1.8.8 1.8 1.8v9.4c0 1-.8 1.8-1.8 1.8H17v2.2c0 1-.8 1.8-1.8 1.8H5.8c-1 0-1.8-.8-1.8-1.8V8.8C4 7.8 4.8 7 5.8 7H8Zm2 0h5.2c1 0 1.8.8 1.8 1.8V14h2V5h-9v2Zm-4 2v9h9V9H6Z"/></svg><svg class="copy-done-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m9.2 16.2-4.4-4.4 1.8-1.8 2.6 2.6 8.2-8.2 1.8 1.8-10 10Z"/></svg></button></div></div><button type="button" data-dialog="repo-dialog">Add GitHub repository</button></div>
<?php if($catalog['plugins']??[]): ?><div class="catalog-filters"><div class="catalog-filter-top">
<?php if($catalogTypes): ?><div class="filter-group"><span class="filter-label">Type</span><div class="filter-chips"><?php foreach($catalogTypes as $value=>$label): ?><button type="button" class="filter-chip" data-catalog-filter data-filter-group="type" data-filter-value="<?=h($value)?>" aria-pressed="false"><?=h($label)?></button><?php endforeach; ?></div></div><?php endif; ?>
<label class="catalog-search"><span>Search</span><input type="search" data-catalog-search placeholder="Search plugins…" autocomplete="off"></label></div>
<?php if($catalogMaturities): ?><div class="filter-group"><span class="filter-label">Maturity</span><div class="filter-chips"><?php foreach($catalogMaturities as $value=>$label): ?><button type="button" class="filter-chip" data-catalog-filter data-filter-group="maturity" data-filter-value="<?=h($value)?>" aria-pressed="false"><?=h($label)?></button><?php endforeach; ?></div></div><?php endif; ?>
<div class="filter-summary"><span><strong data-catalog-visible-count><?=count($catalog['plugins']??[])?></strong> of <?=count($catalog['plugins']??[])?> entries</span><button type="button" class="filter-clear" data-catalog-filter-clear hidden>Clear filters</button></div></div><?php endif; ?>
<div class="catalog-list"><?php foreach($catalog['plugins'] as $plugin): $entryId=$app->catalogEntryId($plugin); $filterTypes=array_values(array_filter(array_map(static fn($type)=>strtolower(trim($type)),explode(',',(string)($plugin['plugin_types']??''))))); $filterMaturity=strtolower(trim((string)($plugin['maturity']??'unknown')) ?: 'unknown'); $pluginServers=$installedServersByPlugin[$plugin['name']]??[]; $catalogSearchText=strtolower(implode(' ',[$plugin['name']??'',$plugin['description']??'',$plugin['plugin_types']??'',$plugin['maturity']??'',$plugin['license']??'',$plugin['repository']??''])); ?><article class="plugin card" data-catalog-entry data-search-text="<?=h($catalogSearchText)?>" data-filter-types="<?=h(json_encode($filterTypes,JSON_THROW_ON_ERROR))?>" data-filter-maturity="<?=h($filterMaturity)?>"><div><h3><a class="plugin-repository-link" href="<?=h($plugin['repository'])?>" target="_blank" rel="noopener noreferrer"><?=h($plugin['name'])?><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 4h6v6h-2V7.4l-7.3 7.3-1.4-1.4L16.6 6H14V4ZM5 6h6v2H7v9h9v-4h2v6H5V6Z"/></svg><span class="visually-hidden"> repository</span></a></h3><p><?=h($plugin['description']??'')?></p><div class="plugin-actions"><button type="button" class="small secondary" data-dialog="edit-<?=h($entryId)?>">Edit</button><button type="button" class="small secondary" data-dialog="refresh-<?=h($entryId)?>">Refresh from GitHub</button><button type="button" class="small danger" data-dialog="delete-<?=h($entryId)?>">Delete</button></div></div><dl><div><dt>Version</dt><dd><?=h($plugin['version'])?></dd></div><div><dt>MariaDB</dt><dd><?=h($plugin['mariadb_version'])?></dd></div><div><dt>Architecture</dt><dd><?=h($plugin['architecture'])?></dd></div><div><dt>Type</dt><dd><?=h($plugin['plugin_types']??'')?></dd></div><div><dt>Installed on</dt><dd><button type="button" class="installation-count" <?=$pluginServers?'data-dialog="installed-'.$entryId.'"':'disabled'?>><strong><?=count($pluginServers)?></strong> server<?=count($pluginServers)===1?'':'s'?></button></dd></div><div><dt>Maturity</dt><dd><span class="badge neutral"><?=h($plugin['maturity']??'unknown')?></span></dd></div></dl></article>

<?php if($pluginServers): ?><dialog id="installed-<?=h($entryId)?>" class="installation-dialog"><div class="dialog-content"><div class="dialog-title"><div><span class="eyebrow">INSTALLATIONS</span><h2><?=h($plugin['name'])?></h2></div><button type="button" class="icon" data-close>×</button></div><p>Servers currently reporting this plugin as installed, regardless of installed version.</p><div class="installation-list"><?php foreach($pluginServers as $pluginServer): ?><a href="?agent=<?=rawurlencode($pluginServer['server_uid'])?>#server-detail" class="installation-server"><span class="database-icon"><img src="/assets/database-node.svg" alt=""></span><span class="installation-server-name"><strong><?=h($pluginServer['display_name']?:$pluginServer['server_uid'])?></strong><small><?=h($pluginServer['server_uid'])?></small></span><span><small>Installed version</small><strong><?=h($pluginServer['installed_version']?:'Unknown')?></strong></span><span><small>Runtime</small><strong class="presence"><span class="dot <?=!empty($pluginServer['loaded'])?'up':'down'?>"></span><?=!empty($pluginServer['loaded'])?'Loaded':'Not loaded'?></strong></span><span class="badge <?=h($pluginServer['status'])?>"><?=h($pluginServer['status'])?></span></a><?php endforeach; ?></div></div></dialog><?php endif; ?>

<dialog id="edit-<?=h($entryId)?>"><form method="post" class="stack catalog-editor"><div class="dialog-title"><h2>Edit <?=h($plugin['name'])?></h2><button type="button" class="icon" data-close>×</button></div><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="form" value="catalog_edit"><input type="hidden" name="entry_id" value="<?=h($entryId)?>">
<div class="form-grid"><label>Name<input name="name" value="<?=h($plugin['name'])?>" required></label><label>Version<input name="version" value="<?=h($plugin['version'])?>" required></label><label>MariaDB version<input name="mariadb_version" value="<?=h($plugin['mariadb_version'])?>" required></label><label>Architecture<input name="architecture" value="<?=h($plugin['architecture'])?>" required></label><label>Soname<input name="soname" value="<?=h($plugin['soname'])?>" required></label><label>Plugin types<input name="plugin_types" value="<?=h($plugin['plugin_types']??'')?>"></label><label>License<input name="license" value="<?=h($plugin['license']??'')?>"></label><label>Maturity<input name="maturity" value="<?=h($plugin['maturity']??'')?>"></label></div>
<label>Repository<input type="url" name="repository" value="<?=h($plugin['repository'])?>" required></label><label>Download URL<input type="url" name="download_url" value="<?=h($plugin['download_url'])?>" required></label><label>SHA-256<input name="sha256" value="<?=h($plugin['sha256'])?>" minlength="64" maxlength="64" required></label><div class="form-grid"><label>Archive type<input name="archive_type" value="<?=h($plugin['archive_type']??'')?>" placeholder="tar.gz"></label><label>Archive member<input name="archive_member" value="<?=h($plugin['archive_member']??'')?>"></label></div><label>Description<input name="description" value="<?=h($plugin['description']??'')?>"></label><label>Dependencies<input name="dependencies" value="<?=h($plugin['dependencies']??'')?>"></label><label>Install message<textarea name="message"><?=h($plugin['message']??'')?></textarea></label><label>Minisign key password<input type="password" name="signing_password" autocomplete="current-password" required><small>The live catalog changes only after signing and verification succeed.</small></label><button>Save, sign, and publish</button></form></dialog>

<dialog id="refresh-<?=h($entryId)?>"><form method="post" class="stack"><div class="dialog-title"><h2>Refresh <?=h($plugin['name'])?></h2><button type="button" class="icon" data-close>×</button></div><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="form" value="catalog_refresh"><input type="hidden" name="entry_id" value="<?=h($entryId)?>"><p>Fetch the latest GitHub release, recalculate its checksum and archive member, and re-detect plugin types, maturity, license, and description—even when the release version has not changed.</p><label>Minisign key password<input type="password" name="signing_password" autocomplete="current-password" required></label><button>Refresh, sign, and publish</button></form></dialog>

<dialog id="delete-<?=h($entryId)?>"><form method="post" class="stack"><div class="dialog-title"><h2>Delete <?=h($plugin['name'])?></h2><button type="button" class="icon" data-close>×</button></div><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="form" value="catalog_delete"><input type="hidden" name="entry_id" value="<?=h($entryId)?>"><p>This removes only this MariaDB version, architecture, and soname entry. Installed plugins on agents are not uninstalled automatically.</p><label>Type DELETE to confirm<input name="confirmation" pattern="DELETE" autocomplete="off" required></label><label>Minisign key password<input type="password" name="signing_password" autocomplete="current-password" required></label><button class="danger">Delete, sign, and publish</button></form></dialog>
<?php endforeach; ?><div class="catalog-no-matches card" data-catalog-no-matches hidden>No catalog entries match the selected filters.</div></div></section>
<?php else: ?>
<?php $enrollmentMode=$app->enrollmentMode(); $enrollmentTokens=$app->enrollmentTokens(); ?>
<section id="admin"><div class="section-title"><div><span class="eyebrow">SECURITY</span><h2>Administration</h2></div></div>
<div class="admin-grid"><article class="admin-panel card"><h3>Enrollment mode</h3><p>Enrollment tokens authorize only the initial registration. After registration, each agent authenticates with its own generated credential.</p><form method="post" class="stack"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="form" value="enrollment_mode"><div class="mode-options"><label class="mode-option"><input type="radio" name="mode" value="dedicated" <?=$enrollmentMode==='dedicated'?'checked':''?>><span><strong>Dedicated one-time tokens</strong><small>Each token registers one server and is then removed.</small></span></label><label class="mode-option"><input type="radio" name="mode" value="shared" <?=$enrollmentMode==='shared'?'checked':''?>><span><strong>Shared token</strong><small>Use the enrollment token hash configured in config.php.</small></span></label></div><button>Save enrollment mode</button></form></article>
<article class="admin-panel card"><div class="admin-panel-title"><div><h3>Available dedicated tokens</h3><p><?=count($enrollmentTokens)?> of 10 available</p></div><?php if($enrollmentMode==='dedicated'&&count($enrollmentTokens)<10): ?><form method="post" class="token-generator"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="form" value="enrollment_generate"><label>Generate<select name="count"><?php for($i=1;$i<=10-count($enrollmentTokens);++$i): ?><option value="<?=$i?>"><?=$i?></option><?php endfor; ?></select></label><button>Create</button></form><?php endif; ?></div>
<?php if($enrollmentMode==='shared'): ?><div class="admin-notice">Dedicated tokens are inactive while shared enrollment mode is selected.</div><?php endif; ?>
<div class="token-list"><?php foreach($enrollmentTokens as $enrollmentToken): ?><div class="token-row"><div><code><?=h($enrollmentToken['token'])?></code><small>Created <?=h(str_replace(['T','Z'],[' ',' UTC'],$enrollmentToken['created_at']))?></small></div><div class="token-actions"><button type="button" class="small secondary" data-copy-token="<?=h($enrollmentToken['token'])?>">Copy</button><form method="post"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="form" value="enrollment_revoke"><input type="hidden" name="token_id" value="<?=h($enrollmentToken['id'])?>"><button class="small danger">Revoke</button></form></div></div><?php endforeach; ?><?php if(!$enrollmentTokens): ?><div class="empty-token-list">No dedicated enrollment tokens are available.</div><?php endif; ?></div></article></div></section>
<?php endif; ?>
<footer class="site-credit"><span class="credit-mark"><img src="/assets/by_lefred.png" alt="by lefred"></span></footer>
</main>
<?php if(!$adminPage): ?>
<dialog id="repo-dialog"><form method="post" class="stack"><div class="dialog-title"><h2>Add GitHub repository</h2><button type="button" class="icon" data-close>×</button></div><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="form" value="repository">
<label>Repository URL<input type="url" name="repository" placeholder="https://github.com/lefred/mariadb-plugin-vmstat" required></label><div class="form-grid"><label>Plugin types<input name="plugin_types" placeholder="auto-detect"></label><label>License<input name="license" placeholder="auto-detect"></label><label>Maturity<input name="maturity" placeholder="auto-detect"></label><label>Name<input name="name" placeholder="auto-detect"></label></div>
<label>Description<input name="description" placeholder="GitHub description"></label><label>Dependencies<input name="dependencies"></label><label>Install message<textarea name="message"></textarea></label>
<?php if (($app->config['signing_key'] ?? '') !== ''): ?><label>Minisign key password<input type="password" name="signing_password" autocomplete="current-password" required><small>Used only for this signing operation; never stored.</small></label><?php endif; ?>
<button>Import, update and sign</button></form></dialog><?php endif; ?>
<script src="/assets/app.js"></script><?php endif; ?></body></html>
