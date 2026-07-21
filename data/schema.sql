-- Schema for beertracker database
CREATE DATABASE IF NOT EXISTS beertracker CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE beertracker;

-- Users table (passwords stored as MD5 hashes; prefer stronger hashes in production)
CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_md5 CHAR(32) NOT NULL,
  avatar_base64 LONGTEXT NULL,
  status ENUM('admin','user') NOT NULL DEFAULT 'user',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  last_login_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY idx_users_email (email),
  KEY idx_users_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cached beers from AI responses
CREATE TABLE IF NOT EXISTS beers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  beer VARCHAR(255) NOT NULL,
  brewery VARCHAR(255) NOT NULL,
  brewery_details TEXT NOT NULL,
  abv DECIMAL(5,2) NOT NULL,
  tastingnotes MEDIUMTEXT NOT NULL,
  raw_json JSON NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY idx_beer_brewery (beer, brewery)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per-user beer log (drank switch + personal notes and location)
CREATE TABLE IF NOT EXISTS user_beers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  beer VARCHAR(255) NOT NULL,
  brewery VARCHAR(255) NOT NULL,
  drank TINYINT(1) NOT NULL DEFAULT 0,
  user_notes TEXT NULL,
  tasting_location VARCHAR(255) NULL,
  date_tasted DATE NULL,
  photos_json JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY idx_user_beer (user_id, beer, brewery),
  CONSTRAINT fk_user_beers_user FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
