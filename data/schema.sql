-- Schema for beertracker database
CREATE DATABASE IF NOT EXISTS beertracker CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE beertracker;

-- Users table. password_hash holds an Argon2id/bcrypt hash; password_md5 is a legacy
-- column only populated for accounts not yet upgraded (see migrations/002).
CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NULL,
  password_md5 CHAR(32) NULL,
  avatar_base64 LONGTEXT NULL,
  status ENUM('admin','user') NOT NULL DEFAULT 'user',
  token CHAR(32) NULL,
  token_expires_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  last_login_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY idx_users_email (email),
  UNIQUE KEY idx_users_token (token),
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

-- Per-user beer log (drank switch + personal notes and location).
-- is_manual/abv/description hold details for beers the user added by hand.
CREATE TABLE IF NOT EXISTS user_beers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  beer VARCHAR(255) NOT NULL,
  brewery VARCHAR(255) NOT NULL,
  is_manual TINYINT(1) NOT NULL DEFAULT 0,
  abv DECIMAL(5,2) NULL,
  description TEXT NULL,
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

-- Hit log used by api/common.php rate limiting (login, registration, search, uploads)
CREATE TABLE IF NOT EXISTS rate_limits (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  bucket VARCHAR(190) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_rate_limits_bucket (bucket, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Maps what a user typed to the canonical cached beer, so repeat searches skip Gemini
CREATE TABLE IF NOT EXISTS beer_aliases (
  alias_beer VARCHAR(255) NOT NULL,
  alias_brewery VARCHAR(255) NOT NULL,
  beer_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (alias_beer, alias_brewery),
  CONSTRAINT fk_beer_aliases_beer FOREIGN KEY (beer_id) REFERENCES beers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
