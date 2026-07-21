-- Adds session-token columns to an existing beertracker database.
-- Safe to run once against a database created before this migration existed;
-- data/schema.sql already includes these columns for fresh installs.
ALTER TABLE users
  ADD COLUMN token CHAR(32) NULL AFTER status,
  ADD COLUMN token_expires_at TIMESTAMP NULL DEFAULT NULL AFTER token,
  ADD UNIQUE KEY idx_users_token (token);
