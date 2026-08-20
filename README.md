# Banquise Server

![banquise_server](public/assets/banquise_server_logo.png)

Banquise Server is a small PHP 8.2+ control plane for managing signed MariaDB plugin catalogs and a fleet of `banquise_agent` instances. It uses SQLite (default/development) or MariaDB Server (preferred/production), a server-rendered administration interface, and a JSON HTTPS API with no PHP
framework or JavaScript build chain.

## What it does

- imports the latest matching Linux release assets from a GitHub repository;
- creates or updates an alphabetically sorted `catalog.json`;
- calculates SHA-256 itself, identifies the `.so`, and signs the final catalog
  with Minisign;
- registers new MariaDB servers in quarantine and requires admin approval;
- records heartbeat, MariaDB version, OS, architecture, and plugin state;
- queues typed install, update, load, and uninstall tasks per server;
- uses a unique revocable bearer credential for every enrolled server;
- supports multiple staff users with role-based access, and a public page   where anyone can browse the signed catalog and submit a plugin for review.

## How to use it

Banquise can be use in different ways. 

- Public usage
- Private usage

### Public Usage

For the public usage, Banquise Server will only served a signed catalog of plugins compatible with your MariaDB Server version and architecture.
The calatlog is browsable, searchable and allows you to submit a new plugin.

To use this catalog, you need to install the `banquise_lite` plugin on your server. The public signing key and you will be able to search all available plugins from the SQL interface, querying `INFORMATION_SCHEMA.BANQUISE_CATALOG` table.

You have dedicated function to install & load a plugin, uninstall or upgrade.

### Private Usage

The private usage is when you want to maintain and manager the Banquise Server in your environment. 

You then have the possibility to also manage your fleet of servers. 

On the MariaDB Servers you need to install the `banquise_agent` plugin. This plugin will create the same `INFORMATION_SCHEMA.BANQUISE_CATALOG` table but won't provide the manual functions.

You will decide the operation directly from the Banquise Server.

There is a mode to also avoid your Database Servers to download the plugins from the Internet but directly from your Banquise Server. The Banquise Server requires Internet access.


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
openssl rand -hex 32
php -r "echo 'base64:', base64_encode(random_bytes(32)), PHP_EOL;"
```

The first command is the raw enrollment token distributed to MariaDB hosts.

Store only its hash in config:

```sh
printf %s 'RAW_ENROLLMENT_TOKEN' | sha256sum
```

Configure the result as `sha256:HEX_DIGEST` in `enrollment_token_hash`. Put the
second command's result in `enrollment_token_encryption_key`; it encrypts unused dedicated tokens at rest and must remain stable. Set the public HTTPS URL and the offline-protected Minisign key paths, then initialize:

```sh
php bin/init.php
```

Run from an interactive terminal, this also creates the database schema and, if no user exists yet, prompts for the first administrator's email, display name, and password directly on the CLI — the only account created without an emailed setup link, since no mail transport can be trusted before an administrator exists. Every user created afterward, from the Admin > Users
panel, receives an emailed link to set their own password instead. Re-running `php bin/init.php` on an already-initialized installation only re-applies the (idempotent) schema and skips the bootstrap step.

### Database backend

SQLite remains the default and requires only:

```php
'database_driver' => 'sqlite',
'database' => __DIR__ . '/var/banquise.sqlite',
```

For MariaDB, first create a schema and application account as a database administrator. Replace the example password before running these statements:

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
`SELECT`, `INSERT`, `UPDATE`, and `DELETE`. The MariaDB DDL is available in `schema.mariadb.sql`. Existing SQLite data is not copied automatically when switching drivers.

Set `online_threshold_seconds` higher than the largest configured agent polling interval so healthy low-frequency agents are not shown as offline.

The PHP/web-server account needs write access to `var/`, `public/catalog.json`, `public/catalog.json.minisig`, and `public/artifacts/` (created automatically the first time private distribution mode mirrors a file), plus read access to
the signing key. For a higher-security production deployment, place signing behind a separate local service or hardware-backed signer instead of giving the web worker direct key access.

Example PHP development server (development only):

```sh
php -S 127.0.0.1:8080 -t public public/index.php
```

Production must use HTTPS. Configure Apache `mod_rewrite` or route missing paths to `public/index.php`; the included `.htaccess` supports Apache. Ensure both `/catalog.json` and `/catalog.json.minisig` are publicly readable.
`/catalog.pub` serves the configured `catalog_public_key` file itself, and the Catalog panel has a download icon for it — a convenience for an administrator who already trusts this Banquise instance, not a verification step: the file and any key ID Banquise might show both come from Banquise itself, so neither
can attest to the other's authenticity.

For testing purpose on a local machine the easiest is to use `Caddy`.

On Fedora, a practical setup is Caddy plus mkcert.

  1. Add a local hostname:

```sh
  echo '127.0.0.1 banquise.test' | sudo tee -a /etc/hosts
```

  2. Create a locally trusted certificate:

```sh
  sudo dnf install mkcert nss-tools caddy
  mkcert -install
  mkdir -p certs
  mkcert \
    -cert-file certs/banquise.test.pem \
    -key-file certs/banquise.test-key.pem \
    banquise.test localhost 127.0.0.1 ::1
```

Example of `Caddyfile`:
```
https://banquise.test:8443 {
      tls ./certs/banquise.test.pem ./certs/banquise.test-key.pem
      reverse_proxy 127.0.0.1:8080
}
```

Start Caddy:

```sh
ls
caddy run --confif Caddyfile
```

In Banquise Server's configuration set the `public_base_url` to point to Caddy:

```
  'public_base_url' => 'https://banquise.test:8443',
```

For the MariaDB plugins `banquise_agent` and `banquise_lite`, this is the configuration to point to your development Banquise Server:

__banquise_agent:__

```ini
  [mariadb]
  banquise_agent_controller_url=https://banquise.test:8443
  banquise_agent_trusted_key_file=/etc/banquise/catalog.pub
  banquise_agent_enrollment_token_file=/etc/banquise/enrollment.token
```

__banquise_lite:__

```ini
  [mariadb]
  banquise_lite_controller_url=https://banquise.test:8443
  banquise_lite_trusted_key_file=/etc/banquise/catalog.pub
```


## Enrollment lifecycle

1. In dedicated mode, an administrator generates up to ten available tokens and copies one to a MariaDB host. Tokens contain no server metadata. Shared mode continues to use the hash configured in `config.php`.
2. The agent pins the independently installed catalog public key and submits its key ID, server UID, MariaDB version, OS, and architecture with the enrollment token. That key must reach the agent host through a channel that doesn't depend on this Banquise instance — configuration management, a secrets store, a value recorded when the keypair was generated with `minisign -G`, and so on. Fetching it from `/catalog.pub` at enrollment time would make Banquise the trust anchor for the very key meant to keep it honest: a host serving a malicious catalog could serve a matching
   malicious key just as easily, so `/catalog.pub` is only a convenience for an administrator who already trusts the instance, never the source an agent pins from.
3. Banquise atomically consumes a dedicated token, creates the registration, and returns a unique random agent credential. A consumed token immediately disappears from the available-token list.
4. The server appears in quarantine. Polls update inventory but return no work.
5. An administrator approves it in the UI. Later polls may return queued typed tasks and the authenticated signed-catalog URL.
6. Completed tasks are acknowledged. Unacknowledged deliveries are leased and become eligible for redelivery after five minutes.

The enrollment token is used only for bootstrap. After registration the agent uses its unique bearer credential from `banquise-agent.state`; removing the local enrollment-token file does not affect an enrolled agent. Deleting an agent in Banquise revokes that credential. If the state file is lost, delete
the incomplete registration and enroll again with a new dedicated token.

The repository-import dialog prompts for the encrypted Minisign key password on every signing operation. PHP sends it to Minisign through a private standard-input pipe; it is never stored, logged, included in command arguments, placed in the environment, or retained in the session. Protect the encrypted signing key
with strict file ownership and isolate the PHP account. For hardware-backed keys, configure `signing_key` as empty and integrate a separate signing service; an unsigned catalog update will be rejected by all agents.

## Users and roles

Staff sign in with an email and password. A user can hold any combination of
four roles, assigned from the Admin > Users panel:

| Role | Can do |
|---|---|
| `administrator` | Everything, including user management, enrollment tokens, and catalog signing. |
| `fleet_manager` | View the fleet; approve, disable, rename, and delete servers; queue plugin install/update/load/uninstall tasks. |
| `fleet_viewer` | View the fleet and each server's observed plugin state. No actions. |
| `plugin_manager` | Add, edit, refresh, and delete catalog entries (signs with the Minisign password directly, same as an administrator); review plugin submissions. |

Enrollment-token administration (shared/dedicated mode, generating and revoking tokens) is deliberately kept administrator-only even though it's fleet-adjacent: it governs who can join the fleet at all, a different trust boundary from day-to-day server and plugin operations.

Creating a user (email, display name, one or more roles) emails them a single-use setup link that expires after `setup_token_ttl_seconds` (default 24 hours); use **Resend setup link** if it lapses before they use it. Users are disabled, not deleted, so task history and submission comments they
authored stay attributed. Banquise refuses to remove the `administrator` role from, or disable, the last remaining active administrator.

## Outbound mail

Configure `smtp_host` (and `smtp_port`/`smtp_encryption`/`smtp_user`/
`smtp_password`/`mail_from_address`/`mail_from_name`) to let Banquise send two
kinds of email: a setup link when a user is created (or re-sent), and a new-submission notice to every active administrator and plugin manager when someone submits a plugin through the public form. Banquise speaks SMTP directly (`STARTTLS` or implicit TLS, optional `AUTH LOGIN`) — no PHP `mail()` call and no third-party library. Leaving `smtp_host` empty disables mail entirely; the actions that would have sent it still succeed, they just report
that no email went out.

## Public plugin submissions

Anyone can browse the signed catalog and metadata without logging in — the per-server installation list stays visible only to signed-in users who can view the fleet, so the public view never exposes server identities. The public catalog page also has a **Submit a plugin** button (GitHub repository URL plus optional name/description/contact) that records the submission and
emails every administrator and plugin manager. A hidden honeypot field and a per-address submission throttle (`submission_rate_limit_seconds`, default 120) discourage bots without requiring a CAPTCHA dependency.

Administrators and plugin managers triage submissions from the **Submissions** page: filter by status (`new`, `in_review`, `reviewed_ok`, `denied`, `spam`),
leave internal comments visible only to that pair of roles, and use **Add to catalog** to open the same GitHub-import dialog described below, pre-filled from the submission. Importing marks the submission `reviewed_ok` automatically; the status can still be changed by hand at any time.

## Distribution mode

Administrators can switch how MariaDB servers fetch the plugin *files* the signed catalog points to (not the catalog itself, which is always served by Banquise) from Admin > Distribution mode:

- **Public** (default) — `download_url` is each plugin's original GitHub release asset. Servers need internet access to install anything.
- **Private** — Banquise downloads and verifies every plugin file itself and serves it from `public/artifacts/` under its own `public_base_url`, so a MariaDB server with no internet access — only a path to this Banquise instance — can still install and update plugins. Files are content-addressed by SHA-256 (`artifacts/<sha256>.tar.gz` or `.so`); a plugin already known
  under that hash is never re-downloaded, and files no longer referenced by any catalog entry are deleted the next time the catalog is published.

Switching modes re-signs and republishes the catalog immediately so every entry matches — this needs the Minisign password unless the catalog is currently empty. Going private re-downloads and hashes every plugin file in the catalog, which can take a while for a large one; switching a single repository's builds happens automatically on every future publish too (edit, refresh, import, delete), so a plugin added after the fact is mirrored without needing to flip the mode again. If one repository can't be reached, that entry keeps serving from its original source and is named in the result — it doesn't hold up publishing the rest of the catalog, and is retried automatically on the next catalog change.

The original GitHub URL is preserved internally (`origin_download_url`) for as long as an entry stays mirrored, so switching back to public restores it exactly rather than losing track of where a plugin actually came from.

## External catalog

Administrators can also point Banquise at another catalog instead of curating one locally, from Admin > Catalog source:

- **Local management** (default) — this instance's Admin > Catalog panel controls the catalog, signed with its own Minisign key, as described above.
- **External catalog** — Banquise fetches another catalog's `catalog.json` and `catalog.json.minisig` and verifies them against a Minisign public key uploaded here. Only once verification succeeds are the bytes copied into place, unmodified — the mirrored file keeps the *external* source's signature, so `/catalog.pub` also switches to serving that source's public key rather than this instance's own. Banquise never re-signs a mirrored catalog with its own key: it isn't vouching for entries it didn't curate, only relaying someone else's signed one.

While external catalog mode is on, adding, editing, deleting, and refreshing plugins, checking for updates, and distribution mode are all disabled — there is nothing local to change, and rewriting `download_url` for private distribution would invalidate the external source's signature anyway. Mirroring itself is manual: use **Sync now** in the same panel to re-fetch on demand (there's no background scheduler). A sync that fails signature verification — a bad key, a tampered or unreachable source — leaves the previously mirrored catalog and stored key completely untouched; nothing is overwritten until a fetch actually verifies.

Switching back to local management doesn't discard the external catalog URL/key or the last mirrored `catalog.json` — the mirrored entries stay visible (and installable) until a local add/edit/refresh re-signs the catalog with this instance's own key, and the URL/key are still there if external mode is turned back on later.

## Authority verification

While in local management, Admin > Authority verification adds an optional, non-destructive cross-check against another Banquise's catalog — unlike external catalog mode, nothing is ever adopted automatically. Enabling it takes the same URL + uploaded Minisign public key shape as external catalog mode, tested the same way before saving (a bad key never overwrites a good one).

With it enabled, a **Verify** icon appears next to the catalog's refresh icon. Clicking it fetches and verifies the authority's catalog, then compares it against the local one by entry identity (name/MariaDB version/architecture/OS/soname) and records, for every entry either side has an opinion on, whether it's a clean match (same version and sha256) or not:

- **Verified** (green) — a plugin card, or one row of its build-variant table, gets a small green "Verified" badge when the authority carries the exact same version/sha256.
- **Not valid** (red) — a red "Not valid" badge instead, plus a small sync icon next to Edit/Refresh/Delete that opens a one-click "sync from authority" dialog: it replaces just that entry with the authority's copy, then signs and publishes with this instance's own key like any other local edit.
- **Missing** — a plugin build the authority carries but the local catalog doesn't shows up in a banner above the catalog ("Review and add"), listing each with its own Add button.
- **Local** (orange) — a local-only plugin the authority doesn't carry at all; not a problem, just a plain fact the badge makes visible.

The Verify icon itself turns red if anything differs or is missing, green once everything matches. Verification is manual (no background scheduler), but a normal catalog edit — including "sync from authority" itself — updates the recorded status for that entry immediately using the authority snapshot from the last Verify, without waiting for (or requiring) another fetch.

## GitHub catalog import

Use **Add GitHub repository** in the Catalog panel. Two asset naming conventions are recognized; a release is scanned for the first, and only if nothing matches, for the second:

```text
NAME-vVERSION-mariadbMAJOR.MINOR-OS-ARCH.tar.gz   (a tar.gz archive)
NAME-VERSION-mariadbMAJOR.MINOR-OS.so             (a standalone .so file)
```

`OS` is a free-form target tag — `linux`, `el8`, `ubuntu24.04`, and so on. A release with several OS builds for the same MariaDB version and architecture (e.g. both `el8` and `ubuntu24.04` for `x86_64`) becomes one catalog entry per OS, not one that overwrites the other. The catalog page still shows one card
per plugin: when it has more than one build variant, a small disclosure ("N build variants") expands into a table of every version/MariaDB version/architecture/OS combination, each with its own Edit/Refresh/Delete;
a plugin with only one build renders exactly as before, with no disclosure.

The standalone-`.so` form is for repositories that publish the plugin binary itself as the release asset rather than a `tar.gz` archive containing one.
Since its filename has no architecture, Banquise defaults to `x86_64`; pass `--architecture` (or fill in the **Architecture** field in the import dialog) when a repository actually builds for something else. The installed soname defaults to `NAME.so` — override it with `--soname` if the plugin expects a
different filename. Refreshing an existing standalone-`.so` entry carries its current architecture forward rather than resetting it to the default, since a refresh can't re-detect what was never in the filename to begin with.

Each catalog card shows the OS, architecture, the plugin's author (the repository owner, from GitHub), and how long ago the release and the latest commit happened — hover either for the exact timestamp. Both are best-effort: entries imported before this field existed, or where GitHub doesn't provide the data, simply omit that row.

An entry imported before OS tracking existed (no OS recorded) is treated as still belonging to whichever OS-tagged build first matches it on refresh or re-import, and gets upgraded in place rather than left behind as a duplicate.
If your catalog already has both — from refreshing before this fix — a **Review and clean up** banner appears above the catalog list; it removes exactly the stale pre-OS entries, once you confirm with the Minisign password, without touching anything else.

The refresh icon to the left of **Add GitHub repository** (administrators and plugin managers only) checks every distinct repository in the catalog for a newer release than what's published, without downloading any assets or signing anything — one lightweight GitHub API call per repository, not per
build variant. A plugin whose repository has moved on gets an **Update available** banner on its card; it clears automatically once that entry is brought current, whether through **Refresh from GitHub**, editing its version by hand, or the review-before-publish import flow — not only after running the check again. Results are shared across everyone with catalog access,
not just whoever clicked the button.

Each repository costs one GitHub API call, and unauthenticated calls share a 60-per-hour limit across your whole network — easy to exhaust with more than a handful of catalog entries, especially while testing. Once GitHub starts answering 403, the check stops at the first one instead of repeating the same failure for every remaining repository, but it still can't check anything again until the limit resets. Set `GITHUB_TOKEN` in the PHP-FPM
environment (also used by GitHub import/refresh) to raise that ceiling to 5,000/hour.

**Add GitHub repository** is a two-step, review-before-publish flow. Submitting the dialog only *detects* — Banquise fetches the latest release and shows one editable block per matching asset (one release can produce several catalog entries, e.g. one per OS/architecture). Nothing is written to the catalog at this point. Review the detected fields, correct anything, or tick 
**Skip this entry** to leave one out, then enter the Minisign password once and **Confirm and publish** to sign and publish all of them together — or **Discard, don't publish** to drop the preview entirely. A pending preview is tied to your session: reloading the page reopens it, including any edits you'd already
made, until you confirm or discard it; if the password is wrong, nothing is published and the dialog reopens with those same edits so you can retry without re-detecting from GitHub.

Set `GITHUB_TOKEN` in the PHP-FPM environment for private repositories or a higher API rate limit. Publishing is atomic and the resulting catalog is signed before it is served to agents.

Auto-detection scans the source archive attached to the exact release tag. It recognizes all server declaration constants: storage engine, full-text parser, daemon, information schema, audit, replication, authentication, password validation, encryption, data type, and function. Multiple declarations are
deduplicated in stable MariaDB enum order; for example a library containing `MariaDB_DATA_TYPE_PLUGIN` and `MariaDB_FUNCTION_PLUGIN` becomes `DATA TYPE, FUNCTION`.

Maturity is derived from `MariaDB_PLUGIN_MATURITY_UNKNOWN`, `EXPERIMENTAL`, `ALPHA`, `BETA`, `GAMMA`, or `STABLE` in the release source. If several levels are declared, Banquise reports the least mature one conservatively. Only when no maturity declaration exists does it fall back to the GitHub prerelease and
major-version-zero heuristic. Explicit values entered in the import form still override auto-detection.

Each catalog card also provides:

- **Edit** to change every catalog field manually;
- **Refresh from GitHub** to discover the latest release again, download and hash its assets, re-detect types/maturity/license/description, and replace matching build entries. This happens even when the release version is unchanged. Refreshing looks at every asset in that release, not just this one entry's OS/architecture, so an OS/architecture build that's new since the last import gets added alongside it — refreshing one entry can add others;
- **Delete** to remove one exact name/MariaDB-version/architecture/OS/soname combination without uninstalling it from existing agents.

Every mutation requests the encrypted Minisign key password. Banquise writes a staging catalog, signs it through a private stdin pipe, verifies the new signature with the configured public key, and only then replaces the published catalog and signature. Invalid passwords, malformed edits, and GitHub failures
leave the live pair untouched. Entries are alphabetically re-sorted after every edit, refresh, import, or deletion.
