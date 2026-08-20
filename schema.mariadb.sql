-- Save session settings and disable checks while initializing the schema.
SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0;
SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0;
SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO,STRICT_TRANS_TABLES';
SET @OLD_NOTE_VERBOSITY=@@NOTE_VERBOSITY, NOTE_VERBOSITY=0;
SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT, @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS;
SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION;
SET NAMES utf8mb4;

-- Registered MariaDB agents and their current control-plane state.
CREATE TABLE IF NOT EXISTS agents (
  server_uid VARCHAR(128) NOT NULL PRIMARY KEY,
  display_name VARCHAR(100) NOT NULL DEFAULT '',
  status ENUM('quarantine','active','disabled') NOT NULL DEFAULT 'quarantine',
  credential_hash VARCHAR(255) NOT NULL,
  mariadb_version VARCHAR(128) NOT NULL,
  os VARCHAR(255) NOT NULL,
  architecture VARCHAR(64) NOT NULL,
  catalog_key_id VARCHAR(64) NOT NULL,
  remote_address VARCHAR(255) NOT NULL DEFAULT '',
  first_seen_at VARCHAR(20) NOT NULL,
  last_seen_at VARCHAR(20) NOT NULL,
  approved_at VARCHAR(20) NULL,
  last_error TEXT NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- Last plugin inventory reported by every registered agent.
CREATE TABLE IF NOT EXISTS agent_plugins (
  server_uid VARCHAR(128) NOT NULL,
  name VARCHAR(128) NOT NULL,
  installed TINYINT(1) NOT NULL DEFAULT 0,
  loaded TINYINT(1) NOT NULL DEFAULT 0,
  managed TINYINT(1) NOT NULL DEFAULT 0,
  installed_version VARCHAR(64) NOT NULL DEFAULT '',
  observed_at VARCHAR(20) NOT NULL,
  PRIMARY KEY(server_uid,name),
  CONSTRAINT agent_plugins_server_fk FOREIGN KEY(server_uid) REFERENCES agents(server_uid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- Requested plugin operations and their delivery/completion history.
CREATE TABLE IF NOT EXISTS tasks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  server_uid VARCHAR(128) NOT NULL,
  action ENUM('install','update','uninstall','load') NOT NULL,
  plugin_name VARCHAR(128) NOT NULL,
  state ENUM('queued','delivered','succeeded','failed','cancelled') NOT NULL DEFAULT 'queued',
  requested_at VARCHAR(20) NOT NULL,
  delivered_at VARCHAR(20) NULL,
  completed_at VARCHAR(20) NULL,
  result TEXT NOT NULL DEFAULT '',
  pending_state TINYINT AS (IF(state IN ('queued','delivered'),1,NULL)) PERSISTENT,
  UNIQUE KEY tasks_pending_unique(server_uid,action,plugin_name,pending_state),
  KEY tasks_agent_state(server_uid,state,id),
  CONSTRAINT tasks_server_fk FOREIGN KEY(server_uid) REFERENCES agents(server_uid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- Persisted administration settings that override configuration defaults.
CREATE TABLE IF NOT EXISTS settings (
  name VARCHAR(128) NOT NULL PRIMARY KEY,
  value TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- Encrypted, available one-time enrollment tokens.
CREATE TABLE IF NOT EXISTS enrollment_tokens (
  id CHAR(16) NOT NULL PRIMARY KEY,
  token_hash CHAR(64) NOT NULL UNIQUE,
  encrypted_token TEXT NOT NULL,
  created_at VARCHAR(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- Staff accounts. password_hash stays NULL until the emailed setup link is used.
CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(255) NOT NULL,
  display_name VARCHAR(100) NOT NULL DEFAULT '',
  password_hash VARCHAR(255) NULL,
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  created_at VARCHAR(20) NOT NULL,
  UNIQUE KEY users_email(email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- A user can hold several roles; each row grants one.
CREATE TABLE IF NOT EXISTS user_roles (
  user_id INT UNSIGNED NOT NULL,
  role ENUM('administrator','fleet_manager','fleet_viewer','plugin_manager') NOT NULL,
  PRIMARY KEY(user_id, role),
  CONSTRAINT user_roles_user_fk FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- Single-use, time-limited links that let a newly created user set their password.
CREATE TABLE IF NOT EXISTS password_setup_tokens (
  id CHAR(16) NOT NULL PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  created_at VARCHAR(20) NOT NULL,
  expires_at VARCHAR(20) NOT NULL,
  consumed_at VARCHAR(20) NULL,
  CONSTRAINT password_setup_tokens_user_fk FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- Plugins proposed by the public for catalog inclusion.
CREATE TABLE IF NOT EXISTS plugin_submissions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(128) NOT NULL DEFAULT '',
  repository VARCHAR(255) NOT NULL,
  description TEXT NOT NULL,
  plugin_types VARCHAR(255) NOT NULL DEFAULT '',
  license VARCHAR(64) NOT NULL DEFAULT '',
  maturity VARCHAR(32) NOT NULL DEFAULT '',
  dependencies VARCHAR(255) NOT NULL DEFAULT '',
  message TEXT NOT NULL,
  submitter_name VARCHAR(128) NOT NULL DEFAULT '',
  submitter_email VARCHAR(255) NOT NULL DEFAULT '',
  status ENUM('new','in_review','reviewed_ok','denied','spam') NOT NULL DEFAULT 'new',
  remote_address VARCHAR(255) NOT NULL DEFAULT '',
  created_at VARCHAR(20) NOT NULL,
  updated_at VARCHAR(20) NOT NULL,
  KEY submissions_status(status, id),
  KEY submissions_remote_address(remote_address, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- Internal review notes, visible only to administrators and plugin managers.
CREATE TABLE IF NOT EXISTS submission_comments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  submission_id BIGINT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  body TEXT NOT NULL,
  created_at VARCHAR(20) NOT NULL,
  CONSTRAINT submission_comments_submission_fk FOREIGN KEY(submission_id) REFERENCES plugin_submissions(id) ON DELETE CASCADE,
  CONSTRAINT submission_comments_user_fk FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- Result of the last "check for updates" pass: a catalog entry appears here
-- only while a newer GitHub release than its stored version is known: it is
-- removed once the entry is refreshed/edited past that version, or once a
-- later check finds it's no longer behind.
CREATE TABLE IF NOT EXISTS catalog_update_checks (
  entry_id CHAR(64) NOT NULL PRIMARY KEY,
  available_version VARCHAR(64) NOT NULL,
  checked_at VARCHAR(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

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
  entry_id CHAR(64) NOT NULL PRIMARY KEY,
  status ENUM('verified','differs','missing') NOT NULL,
  authority_entry_json MEDIUMTEXT NOT NULL,
  checked_at VARCHAR(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- Restore the caller's original session settings.
SET SQL_MODE=@OLD_SQL_MODE, FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;
SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS, NOTE_VERBOSITY=@OLD_NOTE_VERBOSITY;
SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT, CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS;
SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION;
