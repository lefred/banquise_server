PRAGMA foreign_keys = ON;
PRAGMA journal_mode = WAL;

CREATE TABLE IF NOT EXISTS agents (
  server_uid TEXT PRIMARY KEY,
  display_name TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT 'quarantine' CHECK(status IN ('quarantine','active','disabled')),
  credential_hash TEXT NOT NULL,
  mariadb_version TEXT NOT NULL,
  os TEXT NOT NULL,
  architecture TEXT NOT NULL,
  catalog_key_id TEXT NOT NULL,
  remote_address TEXT NOT NULL DEFAULT '',
  first_seen_at TEXT NOT NULL,
  last_seen_at TEXT NOT NULL,
  approved_at TEXT,
  last_error TEXT NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS agent_plugins (
  server_uid TEXT NOT NULL REFERENCES agents(server_uid) ON DELETE CASCADE,
  name TEXT NOT NULL,
  installed INTEGER NOT NULL DEFAULT 0,
  loaded INTEGER NOT NULL DEFAULT 0,
  managed INTEGER NOT NULL DEFAULT 0,
  installed_version TEXT NOT NULL DEFAULT '',
  observed_at TEXT NOT NULL,
  PRIMARY KEY(server_uid, name)
);

CREATE TABLE IF NOT EXISTS tasks (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  server_uid TEXT NOT NULL REFERENCES agents(server_uid) ON DELETE CASCADE,
  action TEXT NOT NULL CHECK(action IN ('install','update','uninstall','load')),
  plugin_name TEXT NOT NULL,
  state TEXT NOT NULL DEFAULT 'queued' CHECK(state IN ('queued','delivered','succeeded','failed','cancelled')),
  requested_at TEXT NOT NULL,
  delivered_at TEXT,
  completed_at TEXT,
  result TEXT NOT NULL DEFAULT ''
);

CREATE INDEX IF NOT EXISTS tasks_agent_state ON tasks(server_uid, state, id);

CREATE TABLE IF NOT EXISTS settings (
  name TEXT PRIMARY KEY,
  value TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS enrollment_tokens (
  id TEXT PRIMARY KEY,
  token_hash TEXT NOT NULL UNIQUE,
  encrypted_token TEXT NOT NULL,
  created_at TEXT NOT NULL
);

-- Staff accounts. password_hash stays NULL until the emailed setup link is used.
CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email TEXT NOT NULL UNIQUE,
  display_name TEXT NOT NULL DEFAULT '',
  password_hash TEXT,
  status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','disabled')),
  created_at TEXT NOT NULL
);

-- A user can hold several roles; each row grants one.
CREATE TABLE IF NOT EXISTS user_roles (
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  role TEXT NOT NULL CHECK(role IN ('administrator','fleet_manager','fleet_viewer','plugin_manager')),
  PRIMARY KEY(user_id, role)
);

-- Single-use, time-limited links that let a newly created user set their password.
CREATE TABLE IF NOT EXISTS password_setup_tokens (
  id TEXT PRIMARY KEY,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  token_hash TEXT NOT NULL UNIQUE,
  created_at TEXT NOT NULL,
  expires_at TEXT NOT NULL,
  consumed_at TEXT
);

-- Plugins proposed by the public for catalog inclusion.
CREATE TABLE IF NOT EXISTS plugin_submissions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL DEFAULT '',
  repository TEXT NOT NULL,
  description TEXT NOT NULL DEFAULT '',
  plugin_types TEXT NOT NULL DEFAULT '',
  license TEXT NOT NULL DEFAULT '',
  maturity TEXT NOT NULL DEFAULT '',
  dependencies TEXT NOT NULL DEFAULT '',
  message TEXT NOT NULL DEFAULT '',
  submitter_name TEXT NOT NULL DEFAULT '',
  submitter_email TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT 'new' CHECK(status IN ('new','in_review','reviewed_ok','denied','spam')),
  remote_address TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS submissions_status ON plugin_submissions(status, id);
CREATE INDEX IF NOT EXISTS submissions_remote_address ON plugin_submissions(remote_address, created_at);

-- Internal review notes, visible only to administrators and plugin managers.
CREATE TABLE IF NOT EXISTS submission_comments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  submission_id INTEGER NOT NULL REFERENCES plugin_submissions(id) ON DELETE CASCADE,
  user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  body TEXT NOT NULL,
  created_at TEXT NOT NULL
);

-- Result of the last "check for updates" pass: a catalog entry appears here
-- only while a newer GitHub release than its stored version is known: it is
-- removed once the entry is refreshed/edited past that version, or once a
-- later check finds it's no longer behind.
CREATE TABLE IF NOT EXISTS catalog_update_checks (
  entry_id TEXT PRIMARY KEY,
  available_version TEXT NOT NULL,
  checked_at TEXT NOT NULL
);

-- Result of the last authority-verification pass (local management mode
-- only): one row per entry the authority catalog has an opinion on —
-- 'verified' (present on both sides, same version/sha256), 'differs'
-- (present on both, different version/sha256), or 'missing' (the authority
-- carries it, the local catalog doesn't). entry_id is the local entry's id
-- for 'verified'/'differs', or the id computed the same way from the
-- authority's own entry for 'missing'. A local-only entry the authority
-- doesn't carry at all has no row here. authority_entry_json is the
-- authority's copy of that entry, kept so "sync from authority" doesn't
-- need to re-fetch.
CREATE TABLE IF NOT EXISTS catalog_authority_checks (
  entry_id TEXT PRIMARY KEY,
  status TEXT NOT NULL CHECK(status IN ('verified','differs','missing')),
  authority_entry_json TEXT NOT NULL,
  checked_at TEXT NOT NULL
);
