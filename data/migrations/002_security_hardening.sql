-- Password hashing upgrade, rate limiting and beer-name aliases.
-- Run once against a database created before this migration existed;
-- data/schema.sql already includes all of this for fresh installs.

-- Modern salted hashes (Argon2id/bcrypt) live in password_hash. Accounts that
-- still have a legacy MD5 hash are upgraded automatically on their next login,
-- which also NULLs password_md5. Once every account has logged in you can run:
--   ALTER TABLE users DROP COLUMN password_md5;
ALTER TABLE users
  ADD COLUMN password_hash VARCHAR(255) NULL AFTER email,
  MODIFY COLUMN password_md5 CHAR(32) NULL;

-- Hit log used by api/common.php rate limiting (login, registration, search, uploads).
CREATE TABLE IF NOT EXISTS rate_limits (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  bucket VARCHAR(190) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_rate_limits_bucket (bucket, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Maps what a user typed (e.g. a misspelling) to the canonical cached beer,
-- so repeat searches hit the cache instead of calling Gemini again.
CREATE TABLE IF NOT EXISTS beer_aliases (
  alias_beer VARCHAR(255) NOT NULL,
  alias_brewery VARCHAR(255) NOT NULL,
  beer_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (alias_beer, alias_brewery),
  CONSTRAINT fk_beer_aliases_beer FOREIGN KEY (beer_id) REFERENCES beers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
