-- ORBIT database installation
-- MariaDB 10.4+ / MySQL 8+
-- All timestamps are stored in UTC.
-- Run on an empty database. For local resets use: php backend/bin/migrate.php --fresh

CREATE DATABASE IF NOT EXISTS orbit
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE orbit;

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS schema_migrations (
  version VARCHAR(64) NOT NULL,
  applied_at DATETIME NOT NULL,
  PRIMARY KEY (version)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(255) NOT NULL,
  password_hash VARCHAR(255) NULL,
  full_name VARCHAR(120) NOT NULL,
  google_sub VARCHAR(255) NULL,
  status ENUM('active', 'suspended', 'deleted') NOT NULL DEFAULT 'active',
  timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
  theme ENUM('light', 'dark', 'system') NOT NULL DEFAULT 'system',
  avatar_path VARCHAR(255) NULL,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  UNIQUE KEY uq_users_google_sub (google_sub),
  KEY idx_users_status (status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_profiles (
  user_id BIGINT UNSIGNED NOT NULL,
  bio VARCHAR(500) NULL,
  organization VARCHAR(160) NULL,
  title VARCHAR(120) NULL,
  phone VARCHAR(40) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_profiles_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS platform_admins (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  role ENUM('super', 'moderator') NOT NULL DEFAULT 'super',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_platform_admins_user (user_id),
  CONSTRAINT fk_platform_admins_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS refresh_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  family_id CHAR(32) NOT NULL,
  device_name VARCHAR(120) NULL,
  ip VARCHAR(45) NULL,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  replaced_by_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_refresh_token_hash (token_hash),
  KEY idx_refresh_user (user_id, revoked_at),
  KEY idx_refresh_family (family_id),
  CONSTRAINT fk_refresh_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS password_resets (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_password_resets_hash (token_hash),
  KEY idx_password_resets_user (user_id),
  CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS teams (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  description VARCHAR(2000) NULL,
  category VARCHAR(80) NULL,
  avatar_path VARCHAR(255) NULL,
  leader_id BIGINT UNSIGNED NOT NULL,
  require_join_approval TINYINT(1) NOT NULL DEFAULT 0,
  require_task_verification TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('active', 'archived', 'deleted') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_teams_leader (leader_id),
  KEY idx_teams_status (status),
  KEY idx_teams_updated (updated_at),
  CONSTRAINT fk_teams_leader FOREIGN KEY (leader_id) REFERENCES users (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS team_members (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  team_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  role ENUM('leader', 'member') NOT NULL DEFAULT 'member',
  status ENUM('active', 'pending', 'removed', 'left') NOT NULL DEFAULT 'active',
  joined_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_team_member (team_id, user_id),
  KEY idx_team_members_user (user_id, status),
  KEY idx_team_members_updated (updated_at),
  CONSTRAINT fk_team_members_team FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE CASCADE,
  CONSTRAINT fk_team_members_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS team_invitations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  team_id BIGINT UNSIGNED NOT NULL,
  code VARCHAR(16) NOT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  expires_at DATETIME NULL,
  max_uses INT UNSIGNED NULL,
  use_count INT UNSIGNED NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_invitation_code (code),
  KEY idx_invitations_team (team_id, is_active),
  CONSTRAINT fk_invitations_team FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE CASCADE,
  CONSTRAINT fk_invitations_creator FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tasks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  team_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(160) NOT NULL,
  description TEXT NULL,
  instructions TEXT NULL,
  work_link VARCHAR(500) NULL,
  priority ENUM('low', 'medium', 'high', 'urgent') NOT NULL DEFAULT 'medium',
  status ENUM('todo', 'in_progress', 'pending', 'blocked', 'done') NOT NULL DEFAULT 'todo',
  review_status ENUM('none', 'awaiting', 'approved', 'rejected') NOT NULL DEFAULT 'none',
  category VARCHAR(80) NULL,
  start_date DATETIME NULL,
  deadline DATETIME NULL,
  requires_verification TINYINT(1) NOT NULL DEFAULT 0,
  is_template TINYINT(1) NOT NULL DEFAULT 0,
  recurrence ENUM('none', 'daily', 'weekly', 'monthly', 'custom') NOT NULL DEFAULT 'none',
  recurrence_interval SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  recurrence_unit ENUM('day', 'week', 'month') NULL,
  recurrence_until DATE NULL,
  next_occurrence DATE NULL,
  series_id BIGINT UNSIGNED NULL,
  occurrence_on DATE NULL,
  created_by BIGINT UNSIGNED NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  submitted_at DATETIME NULL,
  completed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_task_occurrence (series_id, occurrence_on),
  KEY idx_tasks_team (team_id, status, deleted_at),
  KEY idx_tasks_deadline (deadline, status),
  KEY idx_tasks_review (team_id, review_status),
  KEY idx_tasks_template (is_template, next_occurrence),
  KEY idx_tasks_updated (updated_at),
  CONSTRAINT fk_tasks_team FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE CASCADE,
  CONSTRAINT fk_tasks_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_tasks_series FOREIGN KEY (series_id) REFERENCES tasks (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS task_assignees (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  task_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  assigned_by BIGINT UNSIGNED NULL,
  assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_task_assignee (task_id, user_id),
  KEY idx_task_assignees_user (user_id),
  CONSTRAINT fk_task_assignees_task FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE CASCADE,
  CONSTRAINT fk_task_assignees_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_task_assignees_assigner FOREIGN KEY (assigned_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS task_checklists (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  task_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(120) NOT NULL DEFAULT 'Checklist',
  position INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_checklists_task (task_id),
  CONSTRAINT fk_checklists_task FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS task_checklist_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  checklist_id BIGINT UNSIGNED NOT NULL,
  content VARCHAR(300) NOT NULL,
  is_done TINYINT(1) NOT NULL DEFAULT 0,
  position INT UNSIGNED NOT NULL DEFAULT 0,
  completed_by BIGINT UNSIGNED NULL,
  completed_at DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_checklist_items_list (checklist_id, position),
  CONSTRAINT fk_checklist_items_list FOREIGN KEY (checklist_id) REFERENCES task_checklists (id) ON DELETE CASCADE,
  CONSTRAINT fk_checklist_items_user FOREIGN KEY (completed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS task_comments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  task_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  author_name VARCHAR(120) NOT NULL,
  body VARCHAR(4000) NOT NULL,
  comment_type ENUM('comment', 'progress') NOT NULL DEFAULT 'comment',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  edited_at DATETIME NULL,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_comments_task (task_id, created_at),
  KEY idx_comments_updated (updated_at),
  CONSTRAINT fk_comments_task FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE CASCADE,
  CONSTRAINT fk_comments_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS task_attachments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  task_id BIGINT UNSIGNED NOT NULL,
  uploaded_by BIGINT UNSIGNED NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_name VARCHAR(80) NOT NULL,
  mime_type VARCHAR(120) NOT NULL,
  size_bytes INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_attachments_task (task_id),
  CONSTRAINT fk_attachments_task FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE CASCADE,
  CONSTRAINT fk_attachments_user FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS task_activity_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  task_id BIGINT UNSIGNED NOT NULL,
  team_id BIGINT UNSIGNED NOT NULL,
  actor_id BIGINT UNSIGNED NULL,
  actor_name VARCHAR(120) NOT NULL,
  action VARCHAR(60) NOT NULL,
  summary VARCHAR(255) NOT NULL,
  meta LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_task_activity_task (task_id, id),
  KEY idx_task_activity_team (team_id, id),
  KEY idx_task_activity_actor (actor_id, id),
  CONSTRAINT fk_task_activity_task FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE CASCADE,
  CONSTRAINT fk_task_activity_team FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE CASCADE,
  CONSTRAINT fk_task_activity_actor FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS task_reviews (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  task_id BIGINT UNSIGNED NOT NULL,
  submitted_by BIGINT UNSIGNED NULL,
  reviewed_by BIGINT UNSIGNED NULL,
  submission_note VARCHAR(2000) NULL,
  decision ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
  feedback VARCHAR(2000) NULL,
  previous_status VARCHAR(32) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_task_reviews_task (task_id, id),
  CONSTRAINT fk_task_reviews_task FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE CASCADE,
  CONSTRAINT fk_task_reviews_submitter FOREIGN KEY (submitted_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_task_reviews_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS team_messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  team_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  author_name VARCHAR(120) NOT NULL,
  body VARCHAR(2000) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_team_messages_team (team_id, id),
  CONSTRAINT fk_team_messages_team FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE CASCADE,
  CONSTRAINT fk_team_messages_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS announcements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  team_id BIGINT UNSIGNED NOT NULL,
  author_id BIGINT UNSIGNED NULL,
  author_name VARCHAR(120) NOT NULL,
  title VARCHAR(160) NOT NULL,
  body VARCHAR(4000) NOT NULL,
  is_important TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_announcements_team (team_id, created_at),
  KEY idx_announcements_updated (updated_at),
  CONSTRAINT fk_announcements_team FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE CASCADE,
  CONSTRAINT fk_announcements_author FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  team_id BIGINT UNSIGNED NULL,
  type VARCHAR(60) NOT NULL,
  title VARCHAR(160) NOT NULL,
  body VARCHAR(300) NOT NULL,
  data_json LONGTEXT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  dedupe_key VARCHAR(190) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  read_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_notification_dedupe (user_id, dedupe_key),
  KEY idx_notifications_user (user_id, is_read, created_at),
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_notifications_team FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notification_preferences (
  user_id BIGINT UNSIGNED NOT NULL,
  in_app_enabled TINYINT(1) NOT NULL DEFAULT 1,
  push_enabled TINYINT(1) NOT NULL DEFAULT 1,
  task_assigned TINYINT(1) NOT NULL DEFAULT 1,
  task_updated TINYINT(1) NOT NULL DEFAULT 1,
  status_changed TINYINT(1) NOT NULL DEFAULT 1,
  comments TINYINT(1) NOT NULL DEFAULT 1,
  announcements TINYINT(1) NOT NULL DEFAULT 1,
  deadlines TINYINT(1) NOT NULL DEFAULT 1,
  reviews TINYINT(1) NOT NULL DEFAULT 1,
  membership TINYINT(1) NOT NULL DEFAULT 1,
  reminder_24h TINYINT(1) NOT NULL DEFAULT 1,
  reminder_1h TINYINT(1) NOT NULL DEFAULT 1,
  reminder_deadline TINYINT(1) NOT NULL DEFAULT 1,
  overdue_reminders TINYINT(1) NOT NULL DEFAULT 1,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_notification_prefs_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS device_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  token VARCHAR(512) NOT NULL,
  platform VARCHAR(32) NOT NULL DEFAULT 'android',
  device_name VARCHAR(120) NULL,
  last_seen_at DATETIME NULL,
  invalidated_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_device_token (token),
  KEY idx_device_tokens_user (user_id, invalidated_at),
  CONSTRAINT fk_device_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sync_operations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  client_op_id VARCHAR(64) NOT NULL,
  entity_type VARCHAR(40) NOT NULL,
  entity_id VARCHAR(64) NULL,
  operation VARCHAR(40) NOT NULL,
  result_json LONGTEXT NOT NULL,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sync_op (user_id, client_op_id),
  CONSTRAINT fk_sync_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  scope ENUM('platform', 'team') NOT NULL,
  team_id BIGINT UNSIGNED NULL,
  actor_id BIGINT UNSIGNED NULL,
  action VARCHAR(80) NOT NULL,
  summary VARCHAR(255) NOT NULL,
  entity_type VARCHAR(40) NULL,
  entity_id VARCHAR(64) NULL,
  ip VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  meta_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_scope (scope, id),
  KEY idx_audit_team (team_id, id),
  KEY idx_audit_actor (actor_id, id),
  CONSTRAINT fk_audit_team FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE CASCADE,
  CONSTRAINT fk_audit_actor FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rate_limits (
  rate_key VARCHAR(190) NOT NULL,
  window_start INT UNSIGNED NOT NULL,
  hits INT UNSIGNED NOT NULL,
  PRIMARY KEY (rate_key)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_reports (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  reporter_id BIGINT UNSIGNED NOT NULL,
  reported_user_id BIGINT UNSIGNED NOT NULL,
  reason VARCHAR(1000) NOT NULL,
  status ENUM('open', 'reviewed', 'dismissed') NOT NULL DEFAULT 'open',
  reviewed_by BIGINT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_user_reports_status (status, created_at),
  CONSTRAINT fk_user_reports_reporter FOREIGN KEY (reporter_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_user_reports_reported FOREIGN KEY (reported_user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_user_reports_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS contact_messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(255) NOT NULL,
  message VARCHAR(4000) NOT NULL,
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS app_releases (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  version VARCHAR(40) NOT NULL,
  changelog VARCHAR(4000) NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  file_size INT UNSIGNED NOT NULL,
  download_count INT UNSIGNED NOT NULL DEFAULT 0,
  is_published TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_release_version (version)
) ENGINE=InnoDB;
