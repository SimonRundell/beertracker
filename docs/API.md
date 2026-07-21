# API Reference

Base path: `/api`

## Authentication
- `POST /login.php` — body `{ email, password }` → `{ id, name, email, status, token, avatar_base64 }`
- `POST /register.php` — body `{ name, email, password }` → `{ id, name, email, status, token, avatar_base64 }`

Every endpoint below except `login.php`, `register.php`, and `beer.php` requires the token from
login/register to be sent as `Authorization: Bearer <token>` on every request. The server derives
`user_id` from this token — it is never read from the request body/query string.

## Beer Search
- `POST /beer.php` — body `{ prompt }` → AI beer details (see `beer_schema.json`). No auth required.

## User Profile
- `GET /user_profile.php` → `{ id, name, email, status, avatar_base64 }` for the authenticated user
- `POST /user_profile.php` — body may include:
  - `name` (optional string)
  - `avatar_base64` (optional data URI; null to clear; PNG/JPG/GIF, <=10MB)
  - `current_password`, `new_password` (both required together to change password)

## User Beers (log)
- `GET /user_beers.php` → `{ items: [{ beer, brewery, drank, tasting_location, date_tasted, photos:[], updated_at }] }`
- `GET /user_beers.php?beer=...&brewery=...` → `{ exists, drank, user_notes, tasting_location, date_tasted, photos:[], updated_at }`
- `POST /user_beers.php` — body `{ beer, brewery, drank?, user_notes?, tasting_location?, date_tasted? }`

## Photos
- Upload: `POST /upload_user_beer_photo.php`
  - FormData fields: `beer`, `brewery`, `photo` (file, PNG/JPG/GIF, <=10MB)
  - Stores under `file_location/<user_id>/<timestamp>_<rand>.<ext>` and updates `photos_json`.
  - Returns `{ status, filename, photos: [] }`.
- Delete: `POST /delete_user_beer_photo.php`
  - FormData: `beer`, `brewery`, `filename`
  - Updates `photos_json` and best-effort removes file.

## Configuration
Backend config lives in `api/config.json` (see `api/config.example.json`):
- `db.host`, `db.name`, `db.user`, `db.pass`, `db.port` — database connection
- `file_location` — filesystem path for uploaded photos (e.g., `../public/uploads`)
- `gemini.provider`, `gemini.api_key`, `gemini.model` — AI beer lookup (devapi provider)
- `gemini.gcp_project_id`, `gemini.gcp_region`, `gemini.gcp_access_token` — AI beer lookup (vertex provider)
- `tls.curl_ca_bundle` / `tls.ssl_cert_file` — optional CA bundle override for outbound HTTPS calls

## Validation Notes
- Dates must be `YYYY-MM-DD`.
- Images: PNG/JPG/GIF only, <=10MB.
- Beers are normalized to lowercase for uniqueness (`beer`, `brewery`).
- `beer.php`'s `tastingnotes` HTML is sanitized server-side (stripped, not rejected) to an allowlist
  of `h3, p, ul, li, strong, em, br` with no attributes; the client sanitizes again with DOMPurify
  before rendering as defense-in-depth.
