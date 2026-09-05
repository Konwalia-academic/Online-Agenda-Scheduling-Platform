-- Agenda Platform — MySQL schema (MySQL 5.7+ / MariaDB 10.3+)
-- This is what the web installer creates automatically; provided here for
-- manual setup or reference.
--
-- CREATE DATABASE agenda CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- USE agenda;
-- SOURCE schema.sql;

CREATE TABLE IF NOT EXISTS settings (
  skey VARCHAR(64) NOT NULL PRIMARY KEY,
  svalue TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(100) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  UNIQUE KEY uq_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS calendars (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(200) NOT NULL,
  ctype ENUM('graph','upload','url') NOT NULL DEFAULT 'url',
  config TEXT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  last_sync_at DATETIME NULL,
  last_sync_error TEXT NULL,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS events_cache (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  calendar_id INT NOT NULL,
  uid VARCHAR(255) NOT NULL,
  title VARCHAR(500) NOT NULL DEFAULT '',
  location VARCHAR(500) NOT NULL DEFAULT '',
  start_utc DATETIME NOT NULL,
  end_utc DATETIME NOT NULL,
  all_day TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_cal_uid (calendar_id, uid),
  KEY idx_range (start_utc, end_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bookings (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  status ENUM('pending','approved','declined','cancelled') NOT NULL DEFAULT 'pending',
  name VARCHAR(200) NOT NULL,
  email VARCHAR(255) NOT NULL,
  phone VARCHAR(50) NOT NULL,
  theme VARCHAR(500) NOT NULL,
  location_type ENUM('zoom','tencent','phone','inperson') NOT NULL,
  location_detail VARCHAR(500) NOT NULL DEFAULT '',
  attendees TEXT NULL,
  appendix TEXT NULL,
  start_utc DATETIME NOT NULL,
  end_utc DATETIME NOT NULL,
  duration_min INT NOT NULL,
  invite_token VARCHAR(64) NOT NULL,
  admin_token VARCHAR(64) NOT NULL,
  graph_event_id VARCHAR(255) NULL,
  graph_error TEXT NULL,
  lang CHAR(2) NOT NULL DEFAULT 'en',
  created_at DATETIME NOT NULL,
  responded_at DATETIME NULL,
  KEY idx_status (status),
  KEY idx_range (start_utc, end_utc),
  KEY idx_invite (invite_token),
  KEY idx_admin (admin_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
