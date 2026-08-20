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
require dirname(__DIR__) . '/src/Auth.php';
require dirname(__DIR__) . '/src/Mailer.php';
require dirname(__DIR__) . '/src/Api.php';
$configFile = dirname(__DIR__) . '/config.php';
if (!is_file($configFile)) { http_response_code(503); exit('Copy config.example.php to config.php and configure Banquise.'); }
$app = new BanquiseApp(require $configFile);
$path = $requestPath;
if (str_starts_with($path, '/api/')) (new BanquiseApi($app))->dispatch($_SERVER['REQUEST_METHOD'], $path);
if ($path === '/catalog.pub') {
    // Public by design, like /catalog.json and /catalog.json.minisig: this is
    // the Minisign public key needed to verify the signed catalog, not a secret.
    $publicKeyFile = (string)($app->config['catalog_public_key'] ?? '');
    if ($publicKeyFile === '' || !is_readable($publicKeyFile)) { http_response_code(404); exit('Catalog public key is not available.'); }
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="catalog.pub"');
    header('Cache-Control: public, max-age=3600');
    readfile($publicKeyFile);
    exit;
}

session_name($app->config['session_name'] ?? 'banquise_admin');
session_set_cookie_params(['secure' => isset($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Strict']);
session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
$mailer = new BanquiseMailer($app->config);
$message = '';

function setupLinkFor(BanquiseApp $app, string $token): string
{
    return rtrim((string)($app->config['public_base_url'] ?? ''), '/') . '/?setup=' . urlencode($token);
}

function notifyUserOfSetupLink(BanquiseApp $app, BanquiseMailer $mailer, array $user, string $token): array
{
    $ttlHours = (int)round((int)($app->config['setup_token_ttl_seconds'] ?? 86400) / 3600);
    return $mailer->send([['email' => $user['email'], 'display_name' => $user['display_name']]],
        'Your Banquise account',
        "An administrator created a Banquise account for you (or reset your setup link).\n\n"
        . "Set your password to activate it:\n" . setupLinkFor($app, $token) . "\n\n"
        . "This link expires in {$ttlHours} hour(s).\n"
    );
}

// Unauthenticated: submit a plugin for review. Works regardless of session state.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'submit_plugin') {
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) { http_response_code(403); exit('Invalid CSRF token'); }
    if ((string)($_POST['website'] ?? '') !== '') {
        // Honeypot field caught a bot; pretend success without touching the database.
        $_SESSION['flash_message'] = 'Thanks! Your plugin was submitted for review.';
    } else {
        try {
            $app->createSubmission($_POST, (string)($_SERVER['REMOTE_ADDR'] ?? ''));
            $recipients = $app->submissionNotificationRecipients();
            if ($recipients) {
                $errors = $mailer->send($recipients, 'New plugin submission for review',
                    "A new plugin was submitted to Banquise for review.\n\n"
                    . 'Repository: ' . trim((string)($_POST['repository'] ?? '')) . "\n"
                    . 'Name: ' . trim((string)($_POST['name'] ?? '')) . "\n"
                    . 'Submitted by: ' . trim((string)($_POST['submitter_name'] ?? '')) . ' <' . trim((string)($_POST['submitter_email'] ?? '')) . ">\n\n"
                    . 'Review it at ' . rtrim((string)($app->config['public_base_url'] ?? ''), '/') . "/?page=submissions\n"
                );
                if ($errors) error_log('Banquise: submission notification errors: ' . implode('; ', $errors));
            }
            $_SESSION['flash_message'] = 'Thanks! Your plugin was submitted for review.';
        } catch (Throwable $e) {
            $_SESSION['flash_message'] = $e->getMessage();
        }
    }
    header('Location: /#catalog', true, 303);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'login') {
    $userId = $app->authenticateUser((string)($_POST['email'] ?? ''), (string)($_POST['password'] ?? ''));
    if ($userId !== null) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        header('Location: /'); exit;
    }
    $message = 'Invalid credentials.';
}

$setupToken = (string)($_GET['setup'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'complete_setup') {
    $setupToken = (string)($_POST['token'] ?? '');
    try {
        if ((string)($_POST['password'] ?? '') !== (string)($_POST['password_confirmation'] ?? '')) {
            throw new InvalidArgumentException('Passwords do not match.');
        }
        $app->consumePasswordSetupToken($setupToken, (string)($_POST['password'] ?? ''));
        $_SESSION['flash_message'] = 'Password set. You can now sign in.';
        header('Location: /?view=login', true, 303);
        exit;
    } catch (Throwable $e) {
        $message = $e->getMessage();
    }
}

if (isset($_GET['logout'])) { session_destroy(); header('Location: /'); exit; }

$sessionUserId = $_SESSION['user_id'] ?? null;
$auth = $app->currentUser($sessionUserId);
if ($sessionUserId !== null && !$auth->isAuthenticated()) unset($_SESSION['user_id']); // disabled/deleted since login

$canViewFleet = $auth->can('fleet.view');
$canManageFleet = $auth->can('fleet.manage');
$canWriteCatalog = $auth->can('catalog.write');
$canReviewSubmissions = $auth->can('submissions.review');
$canManageUsers = $auth->can('users.manage');
$canManageEnrollment = $auth->can('enrollment.manage');
$canManageDistribution = $auth->can('distribution.manage');

if ($auth->isAuthenticated() && $_SERVER['REQUEST_METHOD'] === 'POST'
    && !in_array((string)($_POST['form'] ?? ''), ['login', 'submit_plugin', 'complete_setup'], true)) {
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) { http_response_code(403); exit('Invalid CSRF token'); }
    try {
        switch ($_POST['form'] ?? '') {
            case 'agent_status':
                $auth->require('fleet.manage');
                $app->setAgentStatus((string)$_POST['uid'], (string)$_POST['status']); $message = 'Agent status updated.'; break;
            case 'agent_rename':
                $auth->require('fleet.manage');
                $app->renameAgent((string)$_POST['uid'], (string)($_POST['display_name'] ?? ''));
                $message = 'Server name updated.';
                break;
            case 'agent_delete':
                $auth->require('fleet.manage');
                if ((string)($_POST['confirmation'] ?? '') !== 'DELETE') throw new InvalidArgumentException('Type DELETE to confirm server removal.');
                $app->deleteAgent((string)$_POST['uid']);
                $message = 'Server deleted, including its observed plugin state and task history.';
                break;
            case 'task':
                $auth->require('fleet.manage');
                $app->queueTask((string)$_POST['uid'], (string)$_POST['action'], (string)$_POST['plugin']); $message = 'Task queued.'; break;
            case 'bulk_task':
                $auth->require('fleet.manage');
                $created = $app->queueTasks((string)$_POST['uid'], (string)$_POST['action'], is_array($_POST['plugins'] ?? null) ? $_POST['plugins'] : []);
                $message = $created ? "$created plugin task(s) queued." : 'All selected tasks were already pending.';
                break;
            case 'repository_preview':
                $auth->require('catalog.write');
                $entries = $app->previewRepository((string)$_POST['repository'], $_POST);
                $_SESSION['catalog_preview'] = [
                    'repository' => (string)$_POST['repository'],
                    'submission_id' => (string)($_POST['submission_id'] ?? ''),
                    'entries' => $entries,
                ];
                $message = count($entries) . ' matching release asset(s) found. Review below, then confirm to publish.';
                break;
            case 'repository_confirm':
                $auth->require('catalog.write');
                $submittedEntries = array_values(array_filter(
                    is_array($_POST['entries'] ?? null) ? $_POST['entries'] : [],
                    static fn($entry) => is_array($entry) && empty($entry['skip'])
                ));
                // Keep the session preview in sync with what was just submitted, so a failure
                // (e.g. a wrong signing password) reopens the dialog with those edits intact.
                $_SESSION['catalog_preview'] = [
                    'repository' => (string)($_SESSION['catalog_preview']['repository'] ?? ''),
                    'submission_id' => (string)($_SESSION['catalog_preview']['submission_id'] ?? ''),
                    'entries' => $submittedEntries,
                ];
                $message = $app->confirmRepositoryImport($submittedEntries, (string)($_POST['signing_password'] ?? ''));
                $pendingSubmissionId = (string)($_SESSION['catalog_preview']['submission_id'] ?? '');
                unset($_SESSION['catalog_preview']); // only reached once publishing actually succeeds
                if ($pendingSubmissionId !== '') {
                    try { $app->setSubmissionStatus((int)$pendingSubmissionId, 'reviewed_ok'); } catch (Throwable) { /* best-effort */ }
                }
                break;
            case 'repository_discard':
                $auth->require('catalog.write');
                unset($_SESSION['catalog_preview']);
                $message = 'Import preview discarded.';
                break;
            case 'catalog_edit':
                $auth->require('catalog.write');
                $app->editCatalogEntry((string)$_POST['entry_id'], $_POST, (string)($_POST['signing_password'] ?? ''));
                $message = 'Catalog entry updated, signed, and published.';
                break;
            case 'catalog_refresh':
                $auth->require('catalog.write');
                $message = $app->refreshCatalogEntry((string)$_POST['entry_id'], (string)($_POST['signing_password'] ?? ''));
                break;
            case 'catalog_delete':
                $auth->require('catalog.write');
                if ((string)($_POST['confirmation'] ?? '') !== 'DELETE') throw new InvalidArgumentException('Type DELETE to confirm removal.');
                $app->deleteCatalogEntry((string)$_POST['entry_id'], (string)($_POST['signing_password'] ?? ''));
                $message = 'Catalog entry deleted; the new catalog was signed and published.';
                break;
            case 'catalog_prune_duplicates':
                $auth->require('catalog.write');
                $message = $app->pruneLegacyCatalogDuplicates((string)($_POST['signing_password'] ?? ''));
                break;
            case 'catalog_check_updates':
                $auth->require('catalog.write');
                $result = $app->checkForPluginUpdates();
                $message = "Checked {$result['checked']} catalog entr" . ($result['checked'] === 1 ? 'y' : 'ies')
                    . ": {$result['updates']} update(s) available.";
                if ($result['errors']) {
                    $message .= "\nCould not check: " . implode('; ', $result['errors']);
                }
                break;
            case 'enrollment_mode':
                $auth->require('enrollment.manage');
                $app->setEnrollmentMode((string)($_POST['mode'] ?? ''));
                $message = 'Enrollment mode updated.';
                break;
            case 'distribution_mode':
                $auth->require('distribution.manage');
                $message = $app->setDistributionMode((string)($_POST['mode'] ?? ''), (string)($_POST['signing_password'] ?? ''));
                break;
            case 'enrollment_generate':
                $auth->require('enrollment.manage');
                $created = $app->generateEnrollmentTokens((int)($_POST['count'] ?? 0));
                $message = count($created) . ' dedicated enrollment token(s) generated.';
                break;
            case 'enrollment_revoke':
                $auth->require('enrollment.manage');
                $app->revokeEnrollmentToken((string)($_POST['token_id'] ?? ''));
                $message = 'Enrollment token revoked.';
                break;
            case 'user_create':
                $auth->require('users.manage');
                $created = $app->createUser((string)($_POST['email'] ?? ''), (string)($_POST['display_name'] ?? ''),
                    is_array($_POST['roles'] ?? null) ? $_POST['roles'] : []);
                $errors = notifyUserOfSetupLink($app, $mailer, $created, $created['token']);
                $message = "User {$created['email']} created." . ($errors ? ' Could not email the setup link: ' . implode('; ', $errors) : ' A setup link was emailed.');
                break;
            case 'user_roles':
                $auth->require('users.manage');
                $app->updateUserRoles((int)$_POST['user_id'], is_array($_POST['roles'] ?? null) ? $_POST['roles'] : []);
                $message = 'User roles updated.';
                break;
            case 'user_status':
                $auth->require('users.manage');
                $app->setUserStatus((int)$_POST['user_id'], (string)$_POST['status']);
                $message = 'User status updated.';
                break;
            case 'user_resend_setup':
                $auth->require('users.manage');
                $targetUser = $app->user((int)$_POST['user_id']);
                if (!$targetUser) throw new InvalidArgumentException('Unknown user.');
                $token = $app->reissuePasswordSetupToken((int)$_POST['user_id']);
                $errors = notifyUserOfSetupLink($app, $mailer, $targetUser, $token);
                $message = $errors ? ('Could not send the email: ' . implode('; ', $errors)) : 'Setup link re-sent.';
                break;
            case 'submission_status':
                $auth->require('submissions.review');
                $app->setSubmissionStatus((int)$_POST['submission_id'], (string)($_POST['status'] ?? ''));
                $message = 'Submission status updated.';
                break;
            case 'submission_comment':
                $auth->require('submissions.review');
                $app->addSubmissionComment((int)$_POST['submission_id'], (int)$auth->userId, (string)($_POST['body'] ?? ''));
                $message = 'Comment added.';
                break;
        }
    } catch (Throwable $e) { $message = $e->getMessage(); }
    $_SESSION['flash_message'] = $message;
    $form = (string)($_POST['form'] ?? '');
    $uid = (string)($_POST['uid'] ?? '');
    $submissionId = (string)($_POST['submission_id'] ?? '');
    if ($uid !== '' && $form !== 'agent_delete') {
        $redirect = '/?agent=' . rawurlencode($uid) . '#server-detail';
    } elseif (str_starts_with($form, 'catalog_') || str_starts_with($form, 'repository')) {
        $redirect = '/#catalog';
    } elseif (str_starts_with($form, 'enrollment_') || str_starts_with($form, 'user_') || $form === 'distribution_mode') {
        $redirect = '/?page=admin#admin';
    } elseif (str_starts_with($form, 'submission_') && $submissionId !== '') {
        $redirect = '/?page=submissions&submission=' . rawurlencode($submissionId) . '#submissions';
    } else {
        $redirect = '/#servers';
    }
    header('Location: ' . $redirect, true, 303);
    exit;
}
if ($canViewFleet && $_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['task_status'])) {
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
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_SESSION['flash_message'])) {
    $message = (string)$_SESSION['flash_message'];
    unset($_SESSION['flash_message']);
}

function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function csrf(): string { return h($_SESSION['csrf'] ?? ''); }
function online(array $agent, int $threshold = 180): bool { return strtotime($agent['last_seen_at']) >= time() - $threshold; }
function roleLabel(string $role): string
{
    return match ($role) {
        'administrator' => 'Administrator',
        'fleet_manager' => 'Fleet manager',
        'fleet_viewer' => 'Fleet viewer',
        'plugin_manager' => 'Plugin manager',
        default => ucfirst(str_replace('_', ' ', $role)),
    };
}
function submissionStatusLabel(string $status): string
{
    return match ($status) {
        'new' => 'New', 'in_review' => 'In review', 'reviewed_ok' => 'Reviewed OK',
        'denied' => 'Denied', 'spam' => 'Spam', default => ucfirst($status),
    };
}
/** "2 days", "1 year", "just now" — for the visible label; pair with fullDate() for the title. */
function relativeTime(?string $isoDate): string
{
    if (!$isoDate) return '';
    $timestamp = strtotime($isoDate);
    if ($timestamp === false) return '';
    $diff = time() - $timestamp;
    if ($diff < 60) return 'just now';
    foreach ([31536000 => 'year', 2592000 => 'month', 604800 => 'week', 86400 => 'day', 3600 => 'hour', 60 => 'minute'] as $seconds => $label) {
        if ($diff >= $seconds) {
            $count = intdiv($diff, $seconds);
            return $count . ' ' . $label . ($count === 1 ? '' : 's');
        }
    }
    return 'just now';
}
function fullDate(string $isoDate): string
{
    return str_replace(['T', 'Z'], [' ', ' UTC'], $isoDate);
}
$onlineThreshold = (int)($app->config['online_threshold_seconds'] ?? 180);

$page = (string)($_GET['page'] ?? 'dashboard');
$isAdminPage = $page === 'admin';
$isSubmissionsPage = $page === 'submissions';
$isDashboard = !$isAdminPage && !$isSubmissionsPage;
$viewLogin = (string)($_GET['view'] ?? '') === 'login';

$catalog = $isDashboard ? $app->catalog() : ['plugins' => []];
$agents = ($canViewFleet && $isDashboard) ? $app->agents() : [];
$pluginUpdates = $agents ? $app->pluginUpdatesByServer($catalog) : [];
$pluginCounts = $agents ? $app->pluginCountsByServer($catalog, $agents) : [];
$selectedUid = ($canViewFleet && $isDashboard) ? (string)($_GET['agent'] ?? '') : '';
$selected = $selectedUid !== '' ? $app->agent($selectedUid) : null;
$prefillSubmission = ($isDashboard && $canWriteCatalog && isset($_GET['submission_id']))
    ? $app->submission((int)$_GET['submission_id']) : null;
$reopenSubmissionId = ($isSubmissionsPage && $canReviewSubmissions && isset($_GET['submission']))
    ? (int)$_GET['submission'] : 0;
$catalogPreview = ($isDashboard && $canWriteCatalog) ? ($_SESSION['catalog_preview'] ?? null) : null;
$legacyDuplicates = ($isDashboard && $canWriteCatalog) ? $app->legacyDuplicateEntries() : [];
$newSubmissionCount = $canReviewSubmissions ? $app->newSubmissionCount() : 0;
$updateAvailability = ($isDashboard && $canWriteCatalog) ? $app->pluginUpdateAvailability() : [];
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Banquise</title><link rel="icon" href="/assets/favicon.svg" type="image/svg+xml" sizes="any"><link rel="stylesheet" href="/assets/style.css"></head><body>
<?php if ($setupToken !== '' && !$auth->isAuthenticated()): ?>
<main class="login"><section class="card login-card"><div class="brand-mark">B</div><h1>Set your password</h1><p>Choose a password to activate your Banquise account.</p>
<?php if ($message): ?><div class="alert"><?=h($message)?></div><?php endif; ?>
<form method="post"><input type="hidden" name="form" value="complete_setup"><input type="hidden" name="token" value="<?=h($setupToken)?>">
<label>Password (at least 12 characters)<input type="password" name="password" minlength="12" autocomplete="new-password" required autofocus></label>
<label>Confirm password<input type="password" name="password_confirmation" minlength="12" autocomplete="new-password" required></label>
<button>Set password and continue</button></form>
<p class="login-footnote"><a href="/">&larr; Browse the public plugin catalog</a></p></section></main>
<?php elseif (!$auth->isAuthenticated() && $viewLogin): ?>
<main class="login"><section class="card login-card"><div class="brand-mark">B</div><h1>Banquise</h1><p>MariaDB plugin fleet control</p>
<?php if ($message): ?><div class="alert"><?=h($message)?></div><?php endif; ?>
<form method="post"><input type="hidden" name="form" value="login"><label>Email<input type="email" name="email" autocomplete="username" required autofocus></label>
<label>Password<input type="password" name="password" autocomplete="current-password" required></label><button>Sign in</button></form>
<p class="login-footnote"><a href="/">&larr; Browse the public plugin catalog</a></p></section></main>
<?php else: ?>
<header><a class="brand" href="/"><span class="brand-mark">B</span><span>Banquise</span><img class="mariadb-seal" src="/assets/mariadb-foundation-seal.svg" alt="MariaDB Foundation"></a>
<nav><?php if (!$auth->isAuthenticated()): ?><a href="?view=login">Sign in</a>
<?php else: ?>
<?php if (!$isDashboard): ?><a href="/">Dashboard</a><?php else: ?><a href="#catalog">Catalog</a><?php if ($canViewFleet): ?><a href="#servers">Servers</a><?php endif; ?><?php endif; ?>
<?php if ($canReviewSubmissions): ?><a class="nav-link-with-badge" href="?page=submissions">Submissions<?php if ($newSubmissionCount): ?><span class="nav-badge"><?=h($newSubmissionCount)?></span><?php endif; ?></a><?php endif; ?>
<?php if ($canManageEnrollment || $canManageUsers): ?><a href="?page=admin">Admin</a><?php endif; ?>
<a href="?logout=1">Sign out</a>
<?php endif; ?></nav></header>
<main class="shell"><?php if ($message): ?><div class="alert"><?=nl2br(h($message))?></div><?php endif; ?>

<?php if ($isDashboard):
$heroMetrics = [];
if ($canViewFleet) {
    $heroMetrics[] = ['count' => count($agents), 'label' => 'servers'];
    $heroMetrics[] = ['count' => count(array_filter($agents, fn($a) => online($a, $onlineThreshold))), 'label' => 'online'];
}
$heroMetrics[] = ['count' => count($catalog['plugins'] ?? []), 'label' => 'catalog entries'];
$heroEyebrow = $auth->isAuthenticated() ? 'CONTROL PLANE' : 'MARIADB PLUGIN CATALOG';
$heroTitle = $auth->isAuthenticated() ? 'Your MariaDB plugin fleet,' : 'Signed MariaDB plugins,';
$heroSubtitle = $auth->isAuthenticated() ? 'under control.' : 'curated and verified.';
$heroText = $auth->isAuthenticated()
    ? 'Signed catalog publishing, quarantined enrollment, and explicit server-level operations.'
    : "Browse the signed plugin catalog, or submit your own plugin for the team's review.";
?>
<section class="hero"><div><span class="eyebrow"><?=h($heroEyebrow)?></span><h1><span class="hero-title-blue"><?=h($heroTitle)?></span><br><em><?=h($heroSubtitle)?></em></h1><p><?=h($heroText)?></p></div>
<div class="metrics<?=count($heroMetrics)<3?' metrics-'.count($heroMetrics):''?>"><?php foreach ($heroMetrics as $metric): ?><div><strong><?=h($metric['count'])?></strong><span><?=h($metric['label'])?></span></div><?php endforeach; ?></div></section>

<?php if ($canViewFleet): ?>
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
<section id="server-detail" class="detail card"<?=$hasPendingTasks?' data-task-watch-url="?task_status='.h(rawurlencode($selectedUid)).'" data-task-completion-token="'.h($taskCompletionToken).'"':''?>><div class="section-title"><div><span class="eyebrow">SERVER</span><div class="editable-server-name"><h2><?=h($selected['display_name'] ?: $selectedUid)?></h2><?php if ($canManageFleet): ?><button type="button" class="edit-name-button" data-dialog="rename-server-dialog" aria-label="Edit server name" title="Edit server name"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h4.2L19 9.2 14.8 5 4 15.8V20Zm12-16.2 4.2 4.2 1.1-1.1a1.5 1.5 0 0 0 0-2.1l-2.1-2.1a1.5 1.5 0 0 0-2.1 0L16 3.8Z"/></svg></button><?php endif; ?></div><div class="server-detail-uid">ID: <?=h($selectedUid)?></div></div>
<?php if ($canManageFleet): ?><div class="server-detail-actions"><form method="post"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="form" value="agent_status"><input type="hidden" name="uid" value="<?=h($selectedUid)?>">
<button name="status" value="<?=$selected['status']==='active'?'disabled':'active'?>" class="secondary"><?=$selected['status']==='active'?'Disable':'Approve agent'?></button></form><button type="button" class="danger" data-dialog="delete-server-dialog">Delete server</button></div><?php endif; ?></div>
<?php if ($canManageFleet): ?>
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
<?php else: ?>
<h3>Installed plugins</h3><table><thead><tr><th>Plugin</th><th>Catalog version</th><th>Installed</th><th>Loaded</th><th>Installed version</th></tr></thead><tbody>
<?php foreach($availablePlugins as $name=>$catalogPlugin): $state=$observedByName[$name]??null; $installed=!empty($state['installed']); ?><tr><td><strong><?=h($name)?></strong></td><td><?=h($catalogPlugin['version'])?></td><td><span class="state-mark <?=$installed?'yes':'no'?>"><?=$installed?'Installed':'Not installed'?></span></td><td><span class="state-mark <?=!empty($state['loaded'])?'yes':'no'?>"><?=!empty($state['loaded'])?'Loaded':'Not loaded'?></span></td><td><?=h(trim((string)($state['installed_version']??'')) ?: '—')?></td></tr><?php endforeach; ?>
<?php if(!$availablePlugins): ?><tr><td colspan="5" class="empty-row">No catalog plugins are compatible with this server.</td></tr><?php endif; ?></tbody></table>
<?php endif; ?>
<h3>Recent tasks</h3><table><thead><tr><th>ID</th><th>Action</th><th>Plugin</th><th>State</th><th>Result</th></tr></thead><tbody><?php foreach($tasks as $task): ?><tr><td><?=h($task['id'])?></td><td><?=h($task['action'])?></td><td><?=h($task['plugin_name'])?></td><td><span class="badge <?=h($task['state'])?>"><?=h($task['state'])?></span></td><td><?=h($task['result'])?></td></tr><?php endforeach; ?></tbody></table></section>
<?php if ($canManageFleet): ?>
<dialog id="rename-server-dialog"><form method="post" class="stack"><div class="dialog-title"><h2>Edit server name</h2><button type="button" class="icon" data-close>×</button></div><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="form" value="agent_rename"><input type="hidden" name="uid" value="<?=h($selectedUid)?>"><label>Display name<input name="display_name" value="<?=h($selected['display_name'] ?: 'MariaDB server')?>" maxlength="100" autocomplete="off" required autofocus></label><small>The name is only a friendly label. The immutable server ID remains <?=h($selectedUid)?>.</small><button>Save server name</button></form></dialog>
<dialog id="delete-server-dialog"><form method="post" class="stack"><div class="dialog-title"><h2>Delete <?=h($selected['display_name'] ?: $selectedUid)?></h2><button type="button" class="icon" data-close>×</button></div><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="form" value="agent_delete"><input type="hidden" name="uid" value="<?=h($selectedUid)?>"><p>This permanently removes the server registration, its observed plugin state, and all task history. If the agent still has valid credentials, its next request will be rejected; registering it again requires a new enrollment.</p><label>Type DELETE to confirm<input name="confirmation" pattern="DELETE" autocomplete="off" required></label><button class="danger">Delete server permanently</button></form></dialog>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>

<?php
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
$catalogPublicUrl=rtrim((string)($app->config['public_base_url']??''),'/').'/catalog.json';
$catalogKeyId=$app->expectedKeyId();

// Group build variants of the same plugin (name + repository) under one
// card instead of one card per OS/MariaDB-version/architecture combination.
// $catalog['plugins'] is already sorted with same-name entries adjacent, so
// grouping is a single pass, no re-sort needed.
$catalogGroups=[];
foreach(($catalog['plugins']??[]) as $plugin) {
    $groupKey=($plugin['name']??'')."\0".($plugin['repository']??'');
    $catalogGroups[$groupKey][]=$plugin;
}
?>

<section id="catalog" data-catalog-filter-root><div class="section-title"><div><span class="eyebrow">SIGNED ARTIFACTS</span><div class="catalog-title"><h2>Plugin catalog</h2><button type="button" class="catalog-copy-button" data-copy-catalog-url="<?=h($catalogPublicUrl)?>" title="Copy catalog URL" aria-label="Copy catalog URL"><svg class="copy-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 7V4.8C8 3.8 8.8 3 9.8 3h9.4c1 0 1.8.8 1.8 1.8v9.4c0 1-.8 1.8-1.8 1.8H17v2.2c0 1-.8 1.8-1.8 1.8H5.8c-1 0-1.8-.8-1.8-1.8V8.8C4 7.8 4.8 7 5.8 7H8Zm2 0h5.2c1 0 1.8.8 1.8 1.8V14h2V5h-9v2Zm-4 2v9h9V9H6Z"/></svg><svg class="copy-done-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m9.2 16.2-4.4-4.4 1.8-1.8 2.6 2.6 8.2-8.2 1.8 1.8-10 10Z"/></svg></button><?php if ($catalogKeyId !== ''): ?><a class="catalog-key-button" href="/catalog.pub" download="catalog.pub" title="Download catalog public key" aria-label="Download catalog public key"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M11 3h2v10.2l3.6-3.6 1.4 1.4-6 6-6-6 1.4-1.4L11 13.2V3ZM5 19h14v2H5v-2Z"/></svg></a><?php endif; ?></div></div>
<?php if ($canWriteCatalog): ?><div class="catalog-header-actions"><form method="post" data-spin-on-submit><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="form" value="catalog_check_updates"><button type="submit" class="catalog-refresh-button" title="Check every catalog plugin's repository for a newer GitHub release" aria-label="Check for plugin updates"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5V2L8 6l4 4V7c2.76 0 5 2.24 5 5 0 .65-.13 1.26-.35 1.83l1.46 1.46A6.93 6.93 0 0 0 19 12c0-3.87-3.13-7-7-7Zm0 12c-2.76 0-5-2.24-5-5 0-.65.13-1.26.35-1.83L5.89 8.71A6.93 6.93 0 0 0 5 12c0 3.87 3.13 7 7 7v3l4-4-4-4v3Z"/></svg></button></form><button type="button" data-dialog="repo-dialog">Add GitHub repository</button></div>
<?php elseif (!$auth->isAuthenticated()): ?><button type="button" data-dialog="submit-plugin-dialog">Submit a plugin</button><?php endif; ?></div>
<?php if ($legacyDuplicates): ?><div class="alert legacy-duplicates-alert">Found <?=count($legacyDuplicates)?> catalog entr<?=count($legacyDuplicates)===1?'y':'ies'?> that predate OS tracking and now duplicate a newer, OS-tagged entry for the same plugin — leftover from before Banquise could tell them apart. <button type="button" class="small secondary" data-dialog="prune-duplicates-dialog">Review and clean up</button></div><?php endif; ?>
<?php if($catalog['plugins']??[]): ?><div class="catalog-filters"><div class="catalog-filter-top">
<?php if($catalogTypes): ?><div class="filter-group"><span class="filter-label">Type</span><div class="filter-chips"><?php foreach($catalogTypes as $value=>$label): ?><button type="button" class="filter-chip" data-catalog-filter data-filter-group="type" data-filter-value="<?=h($value)?>" aria-pressed="false"><?=h($label)?></button><?php endforeach; ?></div></div><?php endif; ?>
<label class="catalog-search"><span>Search</span><input type="search" data-catalog-search placeholder="Search plugins…" autocomplete="off"></label></div>
<?php if($catalogMaturities): ?><div class="filter-group"><span class="filter-label">Maturity</span><div class="filter-chips"><?php foreach($catalogMaturities as $value=>$label): ?><button type="button" class="filter-chip" data-catalog-filter data-filter-group="maturity" data-filter-value="<?=h($value)?>" aria-pressed="false"><?=h($label)?></button><?php endforeach; ?></div></div><?php endif; ?>
<div class="filter-summary"><span><strong data-catalog-visible-count><?=count($catalogGroups)?></strong> of <?=count($catalogGroups)?> plugins</span><button type="button" class="filter-clear" data-catalog-filter-clear hidden>Clear filters</button></div></div><?php endif; ?>
<div class="catalog-list"><?php foreach($catalogGroups as $variants):
    $first=$variants[0];
    $isMulti=count($variants)>1;
    $groupEntryId=$app->catalogEntryId($first);
    $pluginServers=$installedServersByPlugin[$first['name']]??[];

    $filterTypes=[]; $searchParts=[];
    foreach($variants as $variant) {
        foreach(explode(',',(string)($variant['plugin_types']??'')) as $type) {
            $type=strtolower(trim($type)); if($type!=='') $filterTypes[$type]=true;
        }
        $searchParts[]=implode(' ',[$variant['name']??'',$variant['description']??'',$variant['plugin_types']??'',$variant['maturity']??'',$variant['license']??'',$variant['repository']??'',$variant['os']??'',$variant['author']??'']);
    }
    $filterTypes=array_keys($filterTypes);
    $filterMaturity=strtolower(trim((string)($first['maturity']??'unknown')) ?: 'unknown');
    $catalogSearchText=strtolower(implode(' ',$searchParts));

    $mariadbVersions=array_values(array_unique(array_map(static fn($v)=>(string)($v['mariadb_version']??''),$variants)));
    natsort($mariadbVersions);
    $osValues=array_values(array_unique(array_filter(array_map(static fn($v)=>(string)($v['os']??''),$variants))));
    sort($osValues,SORT_NATURAL|SORT_FLAG_CASE);
    $architectures=array_values(array_unique(array_map(static fn($v)=>(string)($v['architecture']??''),$variants)));
    sort($architectures,SORT_NATURAL|SORT_FLAG_CASE);
    $hasUpdate=false;
    foreach($variants as $variant) { if(isset($updateAvailability[$app->catalogEntryId($variant)])) { $hasUpdate=true; break; } }
?><article class="plugin card" data-catalog-entry data-search-text="<?=h($catalogSearchText)?>" data-filter-types="<?=h(json_encode($filterTypes,JSON_THROW_ON_ERROR))?>" data-filter-maturity="<?=h($filterMaturity)?>"><?php if($hasUpdate): ?><span class="plugin-update-badge">Update available</span><?php endif; ?><div><h3><a class="plugin-repository-link" href="<?=h($first['repository'])?>" target="_blank" rel="noopener noreferrer"><?=h($first['name'])?><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 4h6v6h-2V7.4l-7.3 7.3-1.4-1.4L16.6 6H14V4ZM5 6h6v2H7v9h9v-4h2v6H5V6Z"/></svg><span class="visually-hidden"> repository</span></a></h3><p><?=h($first['description']??'')?></p><?php if ($canWriteCatalog && !$isMulti): ?><div class="plugin-actions"><button type="button" class="small secondary" data-dialog="edit-<?=h($groupEntryId)?>">Edit</button><button type="button" class="small secondary" data-dialog="refresh-<?=h($groupEntryId)?>">Refresh from GitHub</button><button type="button" class="small danger" data-dialog="delete-<?=h($groupEntryId)?>">Delete</button></div><?php endif; ?></div>

<?php if ($isMulti): ?>
<dl><div><dt>MariaDB versions</dt><dd><?=h(implode(', ',$mariadbVersions))?></dd></div><?php if($osValues): ?><div><dt>OS builds</dt><dd><?=h(implode(', ',$osValues))?></dd></div><?php endif; ?><div><dt>Type</dt><dd><?=h($first['plugin_types']??'')?></dd></div><?php if(count($architectures)>1): ?><div><dt>Architectures</dt><dd><?=h(implode(', ',$architectures))?></dd></div><?php endif; ?><?php if(!empty($first['author'])): ?><div><dt>Author</dt><dd><a href="https://github.com/<?=h($first['author'])?>" target="_blank" rel="noopener noreferrer"><?=h($first['author'])?></a></dd></div><?php endif; ?><?php if ($auth->isAuthenticated()): ?><div><dt>Installed on</dt><dd><?php if ($canViewFleet): ?><button type="button" class="installation-count" <?=$pluginServers?'data-dialog="installed-'.$groupEntryId.'"':'disabled'?>><strong><?=count($pluginServers)?></strong> server<?=count($pluginServers)===1?'':'s'?></button><?php else: ?><?=count($pluginServers)?> server<?=count($pluginServers)===1?'':'s'?><?php endif; ?></dd></div><?php else: ?><div aria-hidden="true"></div><?php endif; ?><div><dt>Maturity</dt><dd><span class="badge neutral"><?=h($first['maturity']??'unknown')?></span></dd></div></dl>

<details class="plugin-variants"><summary><?=count($variants)?> build variants</summary>
<div class="variant-table-wrap"><table class="variant-table"><thead><tr><th>Version</th><th>MariaDB</th><th>Architecture</th><th>OS</th><th>Last release</th><th>Last commit</th><?php if($canWriteCatalog): ?><th></th><?php endif; ?></tr></thead><tbody>
<?php foreach($variants as $variant): $variantEntryId=$app->catalogEntryId($variant); ?><tr><td><?=h($variant['version'])?></td><td><?=h($variant['mariadb_version'])?></td><td><?=h($variant['architecture'])?></td><td><?=h($variant['os']??'—')?></td><td><?php if(!empty($variant['last_release_at'])): ?><span title="<?=h(fullDate($variant['last_release_at']))?>"><?=h(relativeTime($variant['last_release_at']))?></span><?php else: ?>—<?php endif; ?></td><td><?php if(!empty($variant['last_commit_at'])): ?><span title="<?=h(fullDate($variant['last_commit_at']))?>"><?=h(relativeTime($variant['last_commit_at']))?></span><?php else: ?>—<?php endif; ?></td><?php if($canWriteCatalog): ?><td><div class="variant-actions"><button type="button" class="small secondary" data-dialog="edit-<?=h($variantEntryId)?>">Edit</button><button type="button" class="small secondary" data-dialog="refresh-<?=h($variantEntryId)?>">Refresh</button><button type="button" class="small danger" data-dialog="delete-<?=h($variantEntryId)?>">Delete</button></div></td><?php endif; ?></tr><?php endforeach; ?>
</tbody></table></div></details>
<?php else: ?>
<dl><div><dt>Version</dt><dd><?=h($first['version'])?></dd></div><div><dt>MariaDB</dt><dd><?=h($first['mariadb_version'])?></dd></div><div><dt>Architecture</dt><dd><?=h($first['architecture'])?></dd></div><?php if(!empty($first['os'])): ?><div><dt>OS</dt><dd><?=h($first['os'])?></dd></div><?php endif; ?><div><dt>Type</dt><dd><?=h($first['plugin_types']??'')?></dd></div><?php if(!empty($first['author'])): ?><div><dt>Author</dt><dd><a href="https://github.com/<?=h($first['author'])?>" target="_blank" rel="noopener noreferrer"><?=h($first['author'])?></a></dd></div><?php endif; ?><?php if(!empty($first['last_release_at'])): ?><div><dt>Last release</dt><dd><span title="<?=h(fullDate($first['last_release_at']))?>"><?=h(relativeTime($first['last_release_at']))?></span></dd></div><?php endif; ?><?php if(!empty($first['last_commit_at'])): ?><div><dt>Last commit</dt><dd><span title="<?=h(fullDate($first['last_commit_at']))?>"><?=h(relativeTime($first['last_commit_at']))?></span></dd></div><?php endif; ?><?php if ($auth->isAuthenticated()): ?><div><dt>Installed on</dt><dd><?php if ($canViewFleet): ?><button type="button" class="installation-count" <?=$pluginServers?'data-dialog="installed-'.$groupEntryId.'"':'disabled'?>><strong><?=count($pluginServers)?></strong> server<?=count($pluginServers)===1?'':'s'?></button><?php else: ?><?=count($pluginServers)?> server<?=count($pluginServers)===1?'':'s'?><?php endif; ?></dd></div><?php else: ?><div aria-hidden="true"></div><?php endif; ?><div><dt>Maturity</dt><dd><span class="badge neutral"><?=h($first['maturity']??'unknown')?></span></dd></div></dl>
<?php endif; ?>
</article>

<?php if($canViewFleet && $pluginServers): ?><dialog id="installed-<?=h($groupEntryId)?>" class="installation-dialog"><div class="dialog-content"><div class="dialog-title"><div><span class="eyebrow">INSTALLATIONS</span><h2><?=h($first['name'])?></h2></div><button type="button" class="icon" data-close>×</button></div><p>Servers currently reporting this plugin as installed, regardless of installed version or which build variant.</p><div class="installation-list"><?php foreach($pluginServers as $pluginServer): ?><a href="?agent=<?=rawurlencode($pluginServer['server_uid'])?>#server-detail" class="installation-server"><span class="database-icon"><img src="/assets/database-node.svg" alt=""></span><span class="installation-server-name"><strong><?=h($pluginServer['display_name']?:$pluginServer['server_uid'])?></strong><small><?=h($pluginServer['server_uid'])?></small></span><span><small>Installed version</small><strong><?=h($pluginServer['installed_version']?:'Unknown')?></strong></span><span><small>Runtime</small><strong class="presence"><span class="dot <?=!empty($pluginServer['loaded'])?'up':'down'?>"></span><?=!empty($pluginServer['loaded'])?'Loaded':'Not loaded'?></strong></span><span class="badge <?=h($pluginServer['status'])?>"><?=h($pluginServer['status'])?></span></a><?php endforeach; ?></div></div></dialog><?php endif; ?>

<?php if ($canWriteCatalog): foreach($variants as $variant): $variantEntryId=$app->catalogEntryId($variant); ?>
<dialog id="edit-<?=h($variantEntryId)?>"><form method="post" class="stack catalog-editor"><div class="dialog-title"><h2>Edit <?=h($variant['name'])?></h2><button type="button" class="icon" data-close>×</button></div><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="form" value="catalog_edit"><input type="hidden" name="entry_id" value="<?=h($variantEntryId)?>">
<div class="form-grid"><label>Name<input name="name" value="<?=h($variant['name'])?>" required></label><label>Version<input name="version" value="<?=h($variant['version'])?>" required></label><label>MariaDB version<input name="mariadb_version" value="<?=h($variant['mariadb_version'])?>" required></label><label>Architecture<input name="architecture" value="<?=h($variant['architecture'])?>" required></label><label>OS<input name="os" value="<?=h($variant['os']??'')?>" placeholder="linux, el8, ubuntu24.04…"></label><label>Soname<input name="soname" value="<?=h($variant['soname'])?>" required></label><label>Plugin types<input name="plugin_types" value="<?=h($variant['plugin_types']??'')?>"></label><label>License<input name="license" value="<?=h($variant['license']??'')?>"></label><label>Maturity<input name="maturity" value="<?=h($variant['maturity']??'')?>"></label><label>Author<input name="author" value="<?=h($variant['author']??'')?>"></label></div>
<label>Repository<input type="url" name="repository" value="<?=h($variant['repository'])?>" required></label><label>Download URL<input type="url" name="download_url" value="<?=h($variant['download_url'])?>" required></label><label>SHA-256<input name="sha256" value="<?=h($variant['sha256'])?>" minlength="64" maxlength="64" required></label><div class="form-grid"><label>Archive type<input name="archive_type" value="<?=h($variant['archive_type']??'')?>" placeholder="tar.gz"></label><label>Archive member<input name="archive_member" value="<?=h($variant['archive_member']??'')?>"></label></div><label>Description<input name="description" value="<?=h($variant['description']??'')?>"></label><label>Dependencies<input name="dependencies" value="<?=h($variant['dependencies']??'')?>"></label><label>Install message<textarea name="message"><?=h($variant['message']??'')?></textarea></label><label>Minisign key password<input type="password" name="signing_password" autocomplete="current-password" required><small>The live catalog changes only after signing and verification succeed.</small></label><button>Save, sign, and publish</button></form></dialog>

<dialog id="refresh-<?=h($variantEntryId)?>"><form method="post" class="stack"><div class="dialog-title"><h2>Refresh <?=h($variant['name'])?></h2><button type="button" class="icon" data-close>×</button></div><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="form" value="catalog_refresh"><input type="hidden" name="entry_id" value="<?=h($variantEntryId)?>"><p>Fetch the latest GitHub release, recalculate its checksum and archive member, and re-detect plugin types, maturity, license, and description—even when the release version has not changed. If the release now has OS/architecture builds that weren't in the catalog before, they're added too, not just this entry.</p><label>Minisign key password<input type="password" name="signing_password" autocomplete="current-password" required></label><button>Refresh, sign, and publish</button></form></dialog>

<dialog id="delete-<?=h($variantEntryId)?>"><form method="post" class="stack"><div class="dialog-title"><h2>Delete <?=h($variant['name'])?></h2><button type="button" class="icon" data-close>×</button></div><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="form" value="catalog_delete"><input type="hidden" name="entry_id" value="<?=h($variantEntryId)?>"><p>This removes only this MariaDB version, architecture, and soname entry. Installed plugins on agents are not uninstalled automatically.</p><label>Type DELETE to confirm<input name="confirmation" pattern="DELETE" autocomplete="off" required></label><label>Minisign key password<input type="password" name="signing_password" autocomplete="current-password" required></label><button class="danger">Delete, sign, and publish</button></form></dialog>
<?php endforeach; endif; ?>
<?php endforeach; ?><div class="catalog-no-matches card" data-catalog-no-matches hidden>No catalog entries match the selected filters.</div></div></section>
<?php if (!$catalog['plugins']??[]): ?><div class="empty card">No catalog entries yet.</div><?php endif; ?>

<?php elseif ($isAdminPage): ?>
<?php if (!$canManageEnrollment && !$canManageUsers && !$canManageDistribution): ?>
<section><div class="empty card">You don't have access to this section.</div></section>
<?php else:
$enrollmentMode = $canManageEnrollment ? $app->enrollmentMode() : '';
$enrollmentTokens = $canManageEnrollment ? $app->enrollmentTokens() : [];
$users = $canManageUsers ? $app->users() : [];
$distributionMode = $canManageDistribution ? $app->distributionMode() : '';
?>
<section id="admin"><div class="section-title"><div><span class="eyebrow">SECURITY</span><h2>Administration</h2></div></div>
<div class="admin-grid">
<?php if ($canManageEnrollment): ?>
<article class="admin-panel card"><h3>Enrollment mode</h3><p>Enrollment tokens authorize only the initial registration. After registration, each agent authenticates with its own generated credential.</p><form method="post" class="stack"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="form" value="enrollment_mode"><div class="mode-options"><label class="mode-option"><input type="radio" name="mode" value="dedicated" <?=$enrollmentMode==='dedicated'?'checked':''?>><span><strong>Dedicated one-time tokens</strong><small>Each token registers one server and is then removed.</small></span></label><label class="mode-option"><input type="radio" name="mode" value="shared" <?=$enrollmentMode==='shared'?'checked':''?>><span><strong>Shared token</strong><small>Use the enrollment token hash configured in config.php.</small></span></label></div><button>Save enrollment mode</button></form></article>
<article class="admin-panel card"><div class="admin-panel-title"><div><h3>Available dedicated tokens</h3><p><?=count($enrollmentTokens)?> of 10 available</p></div><?php if($enrollmentMode==='dedicated'&&count($enrollmentTokens)<10): ?><form method="post" class="token-generator"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="form" value="enrollment_generate"><label>Generate<select name="count"><?php for($i=1;$i<=10-count($enrollmentTokens);++$i): ?><option value="<?=$i?>"><?=$i?></option><?php endfor; ?></select></label><button>Create</button></form><?php endif; ?></div>
<?php if($enrollmentMode==='shared'): ?><div class="admin-notice">Dedicated tokens are inactive while shared enrollment mode is selected.</div><?php endif; ?>
<div class="token-list"><?php foreach($enrollmentTokens as $enrollmentToken): ?><div class="token-row"><div><code><?=h($enrollmentToken['token'])?></code><small>Created <?=h(str_replace(['T','Z'],[' ',' UTC'],$enrollmentToken['created_at']))?></small></div><div class="token-actions"><button type="button" class="small secondary" data-copy-token="<?=h($enrollmentToken['token'])?>">Copy</button><form method="post"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="form" value="enrollment_revoke"><input type="hidden" name="token_id" value="<?=h($enrollmentToken['id'])?>"><button class="small danger">Revoke</button></form></div></div><?php endforeach; ?><?php if(!$enrollmentTokens): ?><div class="empty-token-list">No dedicated enrollment tokens are available.</div><?php endif; ?></div></article>
<?php endif; ?>
<?php if ($canManageDistribution): ?>
<article class="admin-panel card"><h3>Distribution mode</h3><p>How MariaDB servers fetch plugin files referenced by the catalog.</p><form method="post" class="stack"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="form" value="distribution_mode"><div class="mode-options"><label class="mode-option"><input type="radio" name="mode" value="public" <?=$distributionMode==='public'?'checked':''?>><span><strong>Public</strong><small>download_url points at each plugin's original GitHub release asset.</small></span></label><label class="mode-option"><input type="radio" name="mode" value="private" <?=$distributionMode==='private'?'checked':''?>><span><strong>Private</strong><small>Banquise downloads and mirrors every plugin file; servers fetch it from this Banquise instance instead of the internet.</small></span></label></div><label>Minisign key password<input type="password" name="signing_password" autocomplete="current-password"><small>Only needed if the catalog isn't empty — switching modes re-signs and republishes it so every entry matches immediately.</small></label><button>Save distribution mode</button></form></article>
<?php endif; ?>
<?php if ($canManageUsers): ?>
<article class="admin-panel card users-panel"><h3>Create a user</h3><p>New users receive an emailed setup link to choose their own password. A user can hold multiple roles.</p>
<form method="post" class="stack"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="form" value="user_create">
<div class="form-grid"><label>Email<input type="email" name="email" required></label><label>Display name<input name="display_name" required></label></div>
<div class="role-checkboxes"><?php foreach (BanquiseAuth::ROLES as $role): ?><label class="role-checkbox"><input type="checkbox" name="roles[]" value="<?=h($role)?>"><span><?=h(roleLabel($role))?></span></label><?php endforeach; ?></div>
<button>Create user and email a setup link</button></form></article>
<article class="admin-panel card users-panel"><h3>Users</h3><p><?=count($users)?> account(s).</p>
<div class="user-list"><?php foreach ($users as $user): ?><div class="user-row">
<div class="user-row-identity"><strong><?=h($user['display_name'] ?: $user['email'])?></strong><small><?=h($user['email'])?> &middot; <?=$user['has_password']?'active':'setup pending'?> &middot; <span class="badge <?=$user['status']==='active'?'active':'disabled'?>"><?=h($user['status'])?></span></small></div>
<form method="post" class="stack user-roles-form"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="form" value="user_roles"><input type="hidden" name="user_id" value="<?=h($user['id'])?>"><div class="role-checkboxes"><?php foreach (BanquiseAuth::ROLES as $role): ?><label class="role-checkbox"><input type="checkbox" name="roles[]" value="<?=h($role)?>" <?=in_array($role,$user['roles'],true)?'checked':''?>><span><?=h(roleLabel($role))?></span></label><?php endforeach; ?></div><button class="small secondary">Save roles</button></form>
<div class="user-row-actions">
<form method="post"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="form" value="user_status"><input type="hidden" name="user_id" value="<?=h($user['id'])?>"><input type="hidden" name="status" value="<?=$user['status']==='active'?'disabled':'active'?>"><button class="small secondary"><?=$user['status']==='active'?'Disable':'Enable'?></button></form>
<?php if (!$user['has_password']): ?><form method="post"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="form" value="user_resend_setup"><input type="hidden" name="user_id" value="<?=h($user['id'])?>"><button class="small secondary">Resend setup link</button></form><?php endif; ?>
</div></div><?php endforeach; ?><?php if (!$users): ?><div class="empty-token-list">No other users yet.</div><?php endif; ?></div></article>
<?php endif; ?>
</div></section>
<?php endif; ?>

<?php elseif ($isSubmissionsPage): ?>
<?php if (!$canReviewSubmissions): ?>
<section><div class="empty card">You don't have access to this section.</div></section>
<?php else:
$allowedStatuses = ['new','in_review','reviewed_ok','denied','spam'];
$statusFilter = (string)($_GET['status'] ?? '');
$statusFilter = in_array($statusFilter, $allowedStatuses, true) ? $statusFilter : '';
$submissions = $app->submissions($statusFilter !== '' ? $statusFilter : null);
?>
<section id="submissions"><div class="section-title"><div><span class="eyebrow">COMMUNITY</span><h2>Plugin submissions</h2></div></div>
<nav class="status-filters"><a class="badge<?=$statusFilter===''?' active-filter':''?>" href="?page=submissions">All</a><?php foreach ($allowedStatuses as $statusOption): ?><a class="badge <?=h($statusOption)?><?=$statusFilter===$statusOption?' active-filter':''?>" href="?page=submissions&status=<?=urlencode($statusOption)?>"><?=h(submissionStatusLabel($statusOption))?></a><?php endforeach; ?></nav>
<table><thead><tr><th>Name</th><th>Repository</th><th>Submitted by</th><th>Status</th><th>Comments</th><th>Submitted</th><th></th></tr></thead><tbody>
<?php foreach ($submissions as $submission): ?><tr><td><strong><?=h($submission['name'] ?: '—')?></strong></td><td><a href="<?=h($submission['repository'])?>" target="_blank" rel="noopener noreferrer"><?=h($submission['repository'])?></a></td><td><?=h($submission['submitter_name'] ?: '—')?><?php if($submission['submitter_email']): ?><br><small><?=h($submission['submitter_email'])?></small><?php endif; ?></td><td><span class="badge <?=h($submission['status'])?>"><?=h(submissionStatusLabel($submission['status']))?></span></td><td><?=h($submission['comment_count'])?></td><td><?=h(str_replace(['T','Z'],[' ',' UTC'],$submission['created_at']))?></td><td><div class="submission-row-actions"><button type="button" class="small secondary" data-dialog="submission-<?=h($submission['id'])?>">Review</button><?php if ($canWriteCatalog && $submission['status'] === 'reviewed_ok'): ?><a href="?submission_id=<?=h($submission['id'])?>#catalog" class="as-button">Add to catalog</a><?php endif; ?></div></td></tr><?php endforeach; ?>
<?php if (!$submissions): ?><tr><td colspan="7" class="empty-row">No submissions<?=$statusFilter!==''?' with this status':''?>.</td></tr><?php endif; ?>
</tbody></table></section>

<?php foreach ($submissions as $submission): $comments = $app->submissionComments((int)$submission['id']); ?>
<dialog id="submission-<?=h($submission['id'])?>" class="submission-dialog"><div class="dialog-content"><div class="dialog-title"><div><span class="eyebrow">SUBMISSION #<?=h($submission['id'])?></span><h2><?=h($submission['name'] ?: 'Untitled plugin')?></h2></div><button type="button" class="icon" data-close>×</button></div>
<dl class="submission-fields"><div><dt>Repository</dt><dd><a href="<?=h($submission['repository'])?>" target="_blank" rel="noopener noreferrer"><?=h($submission['repository'])?></a></dd></div><div><dt>Description</dt><dd><?=h($submission['description'] ?: '—')?></dd></div><div><dt>Plugin types</dt><dd><?=h($submission['plugin_types'] ?: '—')?></dd></div><div><dt>License</dt><dd><?=h($submission['license'] ?: '—')?></dd></div><div><dt>Maturity</dt><dd><?=h($submission['maturity'] ?: '—')?></dd></div><div><dt>Dependencies</dt><dd><?=h($submission['dependencies'] ?: '—')?></dd></div><div><dt>Install message</dt><dd><?=h($submission['message'] ?: '—')?></dd></div><div><dt>Submitted by</dt><dd><?=h($submission['submitter_name'] ?: '—')?> <?php if($submission['submitter_email']): ?>&lt;<?=h($submission['submitter_email'])?>&gt;<?php endif; ?></dd></div><div><dt>From address</dt><dd><?=h($submission['remote_address'] ?: '—')?></dd></div></dl>
<form method="post" class="stack"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="form" value="submission_status"><input type="hidden" name="submission_id" value="<?=h($submission['id'])?>"><label>Status<select name="status"><?php foreach ($allowedStatuses as $statusOption): ?><option value="<?=h($statusOption)?>" <?=$submission['status']===$statusOption?'selected':''?>><?=h(submissionStatusLabel($statusOption))?></option><?php endforeach; ?></select></label><button class="small">Update status</button></form>
<h3>Internal comments</h3><div class="comment-thread"><?php foreach ($comments as $comment): ?><div class="comment"><div class="comment-meta"><strong><?=h($comment['display_name'] ?: $comment['email'] ?: 'Former user')?></strong><span><?=h(str_replace(['T','Z'],[' ',' UTC'],$comment['created_at']))?></span></div><p><?=nl2br(h($comment['body']))?></p></div><?php endforeach; ?><?php if (!$comments): ?><p class="empty-row">No comments yet.</p><?php endif; ?></div>
<form method="post" class="stack"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="form" value="submission_comment"><input type="hidden" name="submission_id" value="<?=h($submission['id'])?>"><label>Add a comment (visible to plugin managers and administrators only)<textarea name="body" required></textarea></label><button class="small">Post comment</button></form>
</div></dialog>
<?php endforeach; ?>
<?php endif; ?>
<?php endif; ?>

<footer class="site-credit"><span class="credit-mark"><img src="/assets/by_lefred.png" alt="by lefred"></span></footer>
</main>
<?php if ($isDashboard && $canWriteCatalog): ?>
<?php if ($legacyDuplicates): ?>
<dialog id="prune-duplicates-dialog"><form method="post" class="stack"><div class="dialog-title"><h2>Remove legacy duplicate entries</h2><button type="button" class="icon" data-close>×</button></div>
<input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="form" value="catalog_prune_duplicates">
<p>These entries were imported before Banquise tracked OS, and are now superseded by a newer entry with the same name, MariaDB version, architecture, and soname but a real OS value. Removing them does not uninstall anything from existing agents.</p>
<ul class="legacy-duplicate-list"><?php foreach ($legacyDuplicates as $legacyEntry): ?><li><strong><?=h($legacyEntry['name'])?></strong> <?=h($legacyEntry['version'])?> — MariaDB <?=h($legacyEntry['mariadb_version'])?>, <?=h($legacyEntry['architecture'])?></li><?php endforeach; ?></ul>
<label>Minisign key password<input type="password" name="signing_password" autocomplete="current-password" required></label>
<button class="danger">Remove and re-publish</button></form></dialog>
<?php endif; ?>
<dialog id="repo-dialog"><form method="post" class="stack"><div class="dialog-title"><h2>Add GitHub repository</h2><button type="button" class="icon" data-close>×</button></div><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="form" value="repository_preview">
<?php if ($prefillSubmission): ?><input type="hidden" name="submission_id" value="<?=h($prefillSubmission['id'])?>"><?php endif; ?>
<label>Repository URL<input type="url" name="repository" placeholder="https://github.com/lefred/mariadb-plugin-vmstat" value="<?=h($prefillSubmission['repository'] ?? '')?>" required></label><div class="form-grid"><label>Plugin types<input name="plugin_types" placeholder="auto-detect" value="<?=h($prefillSubmission['plugin_types'] ?? '')?>"></label><label>License<input name="license" placeholder="auto-detect" value="<?=h($prefillSubmission['license'] ?? '')?>"></label><label>Maturity<input name="maturity" placeholder="auto-detect" value="<?=h($prefillSubmission['maturity'] ?? '')?>"></label><label>Name<input name="name" placeholder="auto-detect" value="<?=h($prefillSubmission['name'] ?? '')?>"></label><label>Author<input name="author" placeholder="auto-detect (repository owner)"></label><label>Architecture<input name="architecture" placeholder="standalone .so only, default x86_64"></label></div>
<label>Description<input name="description" placeholder="GitHub description" value="<?=h($prefillSubmission['description'] ?? '')?>"></label><label>Dependencies<input name="dependencies" value="<?=h($prefillSubmission['dependencies'] ?? '')?>"></label><label>Install message<textarea name="message"><?=h($prefillSubmission['message'] ?? '')?></textarea></label>
<p class="form-note">Banquise fetches the latest release and shows what it found next, so you can review or change anything before it's signed and published.</p>
<button>Detect and review</button></form></dialog>
<?php if ($prefillSubmission): ?><script>document.addEventListener('DOMContentLoaded',()=>document.getElementById('repo-dialog')?.showModal());</script><?php endif; ?>

<?php if ($catalogPreview): ?>
<dialog id="review-import-dialog" class="review-import-dialog"><div class="dialog-content"><div class="dialog-title"><div><span class="eyebrow">REVIEW BEFORE PUBLISHING</span><h2>Import from <?=h($catalogPreview['repository'])?></h2></div><button type="button" class="icon" data-close>×</button></div>
<p>Detected <?=count($catalogPreview['entries'])?> release asset(s), one catalog entry each. Nothing is saved yet — review, edit, or skip any of them below, then confirm to sign and publish.</p>
<form method="post" class="stack">
<input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="form" value="repository_confirm">
<?php foreach ($catalogPreview['entries'] as $i => $entry): ?>
<fieldset class="review-entry"><legend><label><input type="checkbox" name="entries[<?=$i?>][skip]" value="1"> Skip this entry</label></legend>
<div class="form-grid">
<label>Name<input name="entries[<?=$i?>][name]" value="<?=h($entry['name'] ?? '')?>" required></label>
<label>Version<input name="entries[<?=$i?>][version]" value="<?=h($entry['version'] ?? '')?>" required></label>
<label>MariaDB version<input name="entries[<?=$i?>][mariadb_version]" value="<?=h($entry['mariadb_version'] ?? '')?>" required></label>
<label>Architecture<input name="entries[<?=$i?>][architecture]" value="<?=h($entry['architecture'] ?? '')?>" required></label>
<label>OS<input name="entries[<?=$i?>][os]" value="<?=h($entry['os'] ?? '')?>"></label>
<label>Soname<input name="entries[<?=$i?>][soname]" value="<?=h($entry['soname'] ?? '')?>" required></label>
<label>Plugin types<input name="entries[<?=$i?>][plugin_types]" value="<?=h($entry['plugin_types'] ?? '')?>"></label>
<label>License<input name="entries[<?=$i?>][license]" value="<?=h($entry['license'] ?? '')?>"></label>
<label>Maturity<input name="entries[<?=$i?>][maturity]" value="<?=h($entry['maturity'] ?? '')?>"></label>
<label>Author<input name="entries[<?=$i?>][author]" value="<?=h($entry['author'] ?? '')?>"></label>
</div>
<label>Repository<input type="url" name="entries[<?=$i?>][repository]" value="<?=h($entry['repository'] ?? '')?>" required></label>
<label>Download URL<input type="url" name="entries[<?=$i?>][download_url]" value="<?=h($entry['download_url'] ?? '')?>" required></label>
<label>SHA-256<input name="entries[<?=$i?>][sha256]" value="<?=h($entry['sha256'] ?? '')?>" minlength="64" maxlength="64" required></label>
<div class="form-grid">
<label>Archive type<input name="entries[<?=$i?>][archive_type]" value="<?=h($entry['archive_type'] ?? '')?>"></label>
<label>Archive member<input name="entries[<?=$i?>][archive_member]" value="<?=h($entry['archive_member'] ?? '')?>"></label>
</div>
<label>Description<input name="entries[<?=$i?>][description]" value="<?=h($entry['description'] ?? '')?>"></label>
<label>Dependencies<input name="entries[<?=$i?>][dependencies]" value="<?=h($entry['dependencies'] ?? '')?>"></label>
<label>Install message<textarea name="entries[<?=$i?>][message]"><?=h($entry['message'] ?? '')?></textarea></label>
<div class="form-grid">
<label>Last release<input name="entries[<?=$i?>][last_release_at]" value="<?=h($entry['last_release_at'] ?? '')?>"></label>
<label>Last commit<input name="entries[<?=$i?>][last_commit_at]" value="<?=h($entry['last_commit_at'] ?? '')?>"></label>
</div>
</fieldset>
<?php endforeach; ?>
<?php if (($app->config['signing_key'] ?? '') !== ''): ?><label>Minisign key password<input type="password" name="signing_password" autocomplete="current-password" required><small>The live catalog changes only after signing and verification succeed.</small></label><?php endif; ?>
<button>Confirm and publish</button>
</form>
<form method="post" class="discard-form"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="form" value="repository_discard"><button type="submit" class="small secondary">Discard, don't publish</button></form>
</div></dialog>
<script>document.addEventListener('DOMContentLoaded',()=>document.getElementById('review-import-dialog')?.showModal());</script>
<?php endif; ?>
<?php endif; ?>
<?php if ($isDashboard && !$auth->isAuthenticated()): ?>
<dialog id="submit-plugin-dialog"><form method="post" class="stack"><div class="dialog-title"><h2>Submit a plugin</h2><button type="button" class="icon" data-close>×</button></div>
<input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="form" value="submit_plugin">
<div class="honeypot" aria-hidden="true"><label>Leave this field blank<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
<label>GitHub repository URL<input type="url" name="repository" placeholder="https://github.com/owner/mariadb-plugin-example" required></label>
<div class="form-grid"><label>Plugin name<input name="name" placeholder="optional"></label><label>Your name<input name="submitter_name" placeholder="optional"></label></div>
<label>Contact email<input type="email" name="submitter_email" placeholder="optional, so we can follow up"></label>
<label>Description<textarea name="description" placeholder="optional"></textarea></label>
<p class="form-note">An administrator or plugin manager will review this submission before it's added to the signed catalog.</p>
<button>Submit for review</button></form></dialog>
<?php endif; ?>
<?php if ($reopenSubmissionId): ?><script>document.addEventListener('DOMContentLoaded',()=>document.getElementById('submission-<?=(int)$reopenSubmissionId?>')?.showModal());</script><?php endif; ?>
<script src="/assets/app.js"></script>
<?php endif; ?></body></html>
