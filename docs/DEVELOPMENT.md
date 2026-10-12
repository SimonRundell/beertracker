# Development Guide

## Prereqs
- Node 18+
- PHP 8+
- MySQL 8 (or compatible)

## Setup
1) Install frontend deps: `npm install`
2) Copy `api/.config.example.json` to `api/.config.json` and fill in DB credentials, `file_location`, and Gemini settings.
3) Copy `public/.config.example.json` to `public/.config.json` and set `apiBase` (e.g. `http://localhost/api`).
4) Create the DB from `data/schema.sql` (fresh install), or run the files in `data/migrations/` in order against an existing database.
5) Ensure the upload directory (`file_location`, default `storage/uploads`) exists and is writable.
6) Create your account: `php data/create_user.php "Name" you@example.com "password"` (registration is closed unless `allow_registration` is true).

## Configuration
- `api/.config.json` (backend, git-ignored — template in `api/.config.example.json`):
  - `debug` — include technical detail in 500 responses (development only).
  - `allow_registration` — open or close `register.php`.
  - `db.host`, `db.port`, `db.name`, `db.user`, `db.pass` — database credentials.
  - `file_location` — path where uploaded images are stored (default `../storage/uploads`, resolved from `api/`; keep it out of any publicly served folder).
  - `gemini.provider` — `devapi` or `vertex`.
  - `gemini.api_key`, `gemini.model` — devapi provider.
  - `gemini.gcp_project_id`, `gemini.gcp_region`, `gemini.gcp_access_token` — vertex provider.
  - `tls.curl_ca_bundle` / `tls.ssl_cert_file` — optional CA bundle override.
- `public/.config.json` (frontend, git-ignored, template in `public/.config.example.json`). Browsers download this file, so never put secrets in it:
  - `apiBase` — HTTP base for the PHP API (e.g., `http://localhost/api`).
  - `debug` — log API errors to the console and show server detail in messages.
  - `allowRegistration` — show the Create account button (the server decides independently).
- Never commit real `.config.json` files. They hold live credentials/API keys.

## Running
- Frontend: `npm run dev` (Vite)
- Backend: serve `api/` via PHP (e.g., Laragon/Apache on `localhost:80`, or `php -S localhost:8000 -t api`). Update `apiBase` in `public/.config.json` if hosting elsewhere.

## Testing Manual Flows
- Log in, refresh the page (should stay logged in), log out
- Search a beer
- Save log (drank toggle, date, location, notes)
- Upload photo(s) (toast shows progress; a third photo is refused); delete a photo in modal; verify count updates
- Search for a beer that doesn't exist and add it manually, then reopen it from My Beers
- Search a beer, then use the x or New search button to clear the result
- Edit profile (name/avatar/password)
- MOTD renders when logged out
- Log out, then call an authenticated endpoint without a token (e.g. via devtools) and confirm it's rejected with 401

## Conventions
- Components documented with JSDoc for props/stateful behavior; PHP functions documented with PHPDoc.
- API calls go through `src/api/client.js` (axios) and `src/api/endpoints.js` — don't call `fetch` directly against `api/*.php` from components.
- Every PHP endpoint requires `api/common.php` first, which in turn requires `api/cors.php` — never set CORS headers ad hoc in an endpoint.
- Authenticated endpoints call `requireAuth()` and derive `user_id` from the returned row — never trust a client-supplied `user_id`.
- Image uploads: PNG/JPG/GIF ≤10MB, 2 per beer; stored under `storage/uploads/<user_id>/` with UTC timestamp filenames and served only by `photo.php`.
- Unexpected PHP failures go through `respondServerError()` (never echo `$e->getMessage()` to clients).
- Shared PHP helpers (`normalizeName`, `decodePhotos`, rate limiting) live in `common.php`; shared JS helpers in `src/utils/`.
- Run `npm run lint` and `npm test` before committing.
- Dates: ISO `YYYY-MM-DD`; date inputs call `showPicker` when supported.

## Deployment Notes
- Set `debug` to `false` in both config files.
- Use an absolute path for `file_location` in production, outside the web root if you can. `storage/.htaccess` blocks direct access under Apache.
- After deploying 0.3.0, run `data/migrations/002_security_hardening.sql` and `003_manual_beers.sql`, then move existing photos from `public/uploads/` into `file_location`.
- Add new production origins to the `$allowedOrigins` allowlist at the top of `api/cors.php`.
- If authenticated requests 401 immediately after a successful login in production, check that the server actually forwards the `Authorization` header to PHP. PHP-FPM/FastCGI setups often strip it by default. `api/.htaccess` forces this under Apache; other setups (nginx, etc.) need their own equivalent (e.g. `fastcgi_param HTTP_AUTHORIZATION $http_authorization;`).
- Under nginx, `.htaccess` is ignored: add rules denying dotfiles and `*.log` under `api/`, and denying `storage/` and `data/`.
