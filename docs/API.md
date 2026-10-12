# API Reference

Base path: `/api`

## Authentication
- `POST /login.php` — body `{ email, password }` → `{ id, name, email, status, token, avatar_base64 }`
- `POST /register.php` — body `{ name, email, password }` → `{ id, name, email, status, token, avatar_base64 }`.
  Returns 403 unless `allow_registration` is true in `api/.config.json`. Password minimum 8 characters.
- `POST /logout.php` → `{ status: "ok" }`. Invalidates the caller's token.

Every endpoint except `login.php` and `register.php` requires the token from login/register to be sent
as `Authorization: Bearer <token>` on every request. The server derives `user_id` from this token; it is
never read from the request body/query string.

Rate limited endpoints answer `429` with a `Retry-After` header. Unexpected server failures answer
`{ error, request_id }` (plus `detail` when `debug` is true in `api/.config.json`).

## Beer Search
- `POST /beer.php` — body `{ beer, brewery? }` → AI beer details (see `beer_schema.json`). Requires auth.
  Cache hits are free; lookups that reach Gemini are limited to 30 per hour per user.
- `POST /beer.php` — body `{ beer, mode: "candidates" }` → `{ candidates: [{ beer, brewery, country }] }`
  (see `beer_candidates_schema.json`). Lists up to 5 possible beer+brewery matches for a bare beer
  name, prioritizing UK breweries when ambiguous. Used to drive a "Did you mean...?" picker.

## Manual Beers
- `POST /manual_beer.php` — body `{ beer, brewery, abv?, description? }` → a beer.php-style result with `manual: true` and `description`.
  For beers the AI can't find. ABV is 0 to 100, description up to 1000 characters. Stored on the caller's own log row
  (shared AI cache untouched), leaving drank, notes and photos as they are. `beer.php` returns the caller's manual
  entry for the same beer before consulting the cache or Gemini.

## User Profile
- `GET /user_profile.php` → `{ id, name, email, status, avatar_base64 }` for the authenticated user
- `POST /user_profile.php` — body may include:
  - `name` (optional string)
  - `avatar_base64` (optional data URI; null to clear; PNG/JPG/GIF, <=10MB)
  - `current_password`, `new_password` (both required together to change password)

## User Beers (log)
- `GET /user_beers.php` → `{ items: [{ beer, brewery, drank, tasting_location, date_tasted, photos:[], updated_at }] }`
- `GET /user_beers.php?beer=...&brewery=...` → `{ exists, drank, manual, abv, description, user_notes, tasting_location, date_tasted, photos:[], updated_at }`
- `POST /user_beers.php` — body `{ beer, brewery, drank?, user_notes?, tasting_location?, date_tasted? }` → `{ status, date_tasted, updated_at }`

## Photos
Photos are stored outside the web root and are never served directly.
- Upload: `POST /upload_user_beer_photo.php`
  - FormData fields: `beer`, `brewery`, `photo` (file, PNG/JPG/GIF, <=10MB)
  - Maximum 2 photos per beer (400 beyond that). Stores under `file_location/<user_id>/<timestamp>_<rand>.<ext>` and updates `photos_json`.
  - Returns `{ status, filename, photos: [] }`.
- Delete: `POST /delete_user_beer_photo.php`
  - FormData: `beer`, `brewery`, `filename`
  - Updates `photos_json` and best-effort removes file.
- Download: `GET /photo.php?file=<filename>` returns the image bytes for the authenticated user's own photo.
  The frontend fetches these as blobs because an `<img>` tag cannot send the Authorization header.

## Configuration
Backend config lives in `api/.config.json` (see `api/.config.example.json`), blocked from the web by `api/.htaccess`:
- `debug` — include technical detail in server error responses (development only)
- `allow_registration` — allow `register.php` to create accounts (default false)
- `db.host`, `db.name`, `db.user`, `db.pass`, `db.port` — database connection
- `file_location` — filesystem path for uploaded photos; relative paths resolve from `api/` (default `../storage/uploads`)
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
