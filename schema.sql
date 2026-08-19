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
