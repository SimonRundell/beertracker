# Development Guide

## Prereqs
- Node 18+
- PHP 8+
- MySQL 8 (or compatible)

## Setup
1) Install frontend deps: `npm install`
2) Copy `api/config.example.json` to `api/config.json` and fill in DB credentials, `file_location`, and Gemini settings.
3) Copy `public/config.example.json` to `public/config.json` and set `apiBase` (e.g. `http://localhost/api`).
4) Create the DB from `data/schema.sql` (fresh install) or run `data/migrations/001_add_auth_token.sql` against an existing database created before session tokens existed.
5) Ensure the upload directory (`file_location`) exists and is writable.

## Configuration
- `api/config.json` (backend, git-ignored — template in `api/config.example.json`):
  - `db.host`, `db.port`, `db.name`, `db.user`, `db.pass` — database credentials.
  - `file_location` — path where uploaded images are stored (e.g., `../public/uploads` for local dev).
  - `gemini.provider` — `devapi` or `vertex`.
  - `gemini.api_key`, `gemini.model` — devapi provider.
  - `gemini.gcp_project_id`, `gemini.gcp_region`, `gemini.gcp_access_token` — vertex provider.
  - `tls.curl_ca_bundle` / `tls.ssl_cert_file` — optional CA bundle override.
- `public/config.json` (frontend, git-ignored — template in `public/config.example.json`):
  - `apiBase` — HTTP base for the PHP API (e.g., `http://localhost/api`).
- Never commit real `config.json` files — they hold live credentials/API keys.

## Running
- Frontend: `npm run dev` (Vite)
- Backend: serve `api/` via PHP (e.g., Laragon/Apache on `localhost:80`, or `php -S localhost:8000 -t api`). Update `apiBase` in `public/config.json` if hosting elsewhere.

## Testing Manual Flows
- Register/login
- Search a beer
- Save log (drank toggle, date, location, notes)
- Upload photo(s); delete a photo in modal; verify count updates
- Edit profile (name/avatar/password)
- MOTD renders when logged out
- Log out, then call an authenticated endpoint without a token (e.g. via devtools) and confirm it's rejected with 401

## Conventions
- Components documented with JSDoc for props/stateful behavior; PHP functions documented with PHPDoc.
- API calls go through `src/api/client.js` (axios) and `src/api/endpoints.js` — don't call `fetch` directly against `api/*.php` from components.
- Every PHP endpoint requires `api/common.php` first, which in turn requires `api/cors.php` — never set CORS headers ad hoc in an endpoint.
- Authenticated endpoints call `requireAuth()` and derive `user_id` from the returned row — never trust a client-supplied `user_id`.
- Image uploads: PNG/JPG/GIF ≤10MB; stored under `public/uploads/<user_id>/` with UTC timestamp filenames.
- Dates: ISO `YYYY-MM-DD`; date inputs call `showPicker` when supported.

## Deployment Notes
- Use an absolute path for `file_location` in production.
- Serve `public/` as web root so `/uploads/...` resolves.
- Tighten `api/cors.php` to an explicit origin allowlist (it currently reflects any `localhost:<port>`).
- Replace MD5 password hashing with bcrypt/argon2 before any real deployment.
