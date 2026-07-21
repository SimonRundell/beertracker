# API Reference

Base path: `/api`

## Authentication
- `POST /login.php` — body `{ email, password }` → `{ id, name, email, status, token, avatar_base64 }`
- `POST /register.php` — body `{ name, email, password }` → `{ id, name, email, status, token, avatar_base64 }`

## Beer Search
- `POST /beer.php` — body `{ prompt }` → AI beer details (see `beer_schema.json`).

## User Profile
- `GET /user_profile.php?user_id=...` → `{ id, name, email, status, avatar_base64 }`
- `POST /user_profile.php` — body may include:
  - `user_id` (required)
  - `name` (optional string)
  - `avatar_base64` (optional data URI; null to clear; PNG/JPG/GIF, <=10MB)
  - `current_password`, `new_password` (both required together to change password)

## User Beers (log)
- `GET /user_beers.php?user_id=...` → `{ items: [{ beer, brewery, drank, tasting_location, date_tasted, photos:[], updated_at }] }`
- `GET /user_beers.php?user_id=...&beer=...&brewery=...` → `{ exists, drank, user_notes, tasting_location, date_tasted, photos:[], updated_at }`
- `POST /user_beers.php` — body `{ user_id, beer, brewery, drank?, user_notes?, tasting_location?, date_tasted? }`

## Photos
- Upload: `POST /upload_user_beer_photo.php`
  - FormData fields: `user_id`, `beer`, `brewery`, `photo` (file, PNG/JPG/GIF, <=10MB)
  - Stores under `FILE_LOCATION/<user_id>/<timestamp>_<rand>.<ext>` and updates `photos_json`.
  - Returns `{ status, filename, photos: [] }`.
- Delete: `POST /delete_user_beer_photo.php`
  - FormData: `user_id`, `beer`, `brewery`, `filename`
  - Updates `photos_json` and best-effort removes file.

## Environment
- `DB_NAME`, `DB_USER`, `DB_PASS`, `DB_PORT` — database config
- `FILE_LOCATION` — filesystem path for uploaded photos (e.g., `../public/uploads`)

## Validation Notes
- Dates must be `YYYY-MM-DD`.
- Images: PNG/JPG/GIF only, <=10MB.
- Beers are normalized to lowercase for uniqueness (`beer`, `brewery`).
