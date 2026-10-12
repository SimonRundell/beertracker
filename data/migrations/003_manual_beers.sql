-- Manual beers: beers the AI search can't find, entered by the user.
-- Their ABV and description live on the user's own log row.
-- data/schema.sql already includes these columns for fresh installs.
ALTER TABLE user_beers
  ADD COLUMN is_manual TINYINT(1) NOT NULL DEFAULT 0 AFTER brewery,
  ADD COLUMN abv DECIMAL(5,2) NULL AFTER is_manual,
  ADD COLUMN description TEXT NULL AFTER abv;
