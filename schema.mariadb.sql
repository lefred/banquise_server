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

-- Restore the caller's original session settings.
SET SQL_MODE=@OLD_SQL_MODE, FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;
SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS, NOTE_VERBOSITY=@OLD_NOTE_VERBOSITY;
SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT, CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS;
SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION;
