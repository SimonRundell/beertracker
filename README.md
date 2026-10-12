# BeerTracker

AI-assisted beer search with personal tasting log, photo uploads, and user profiles. Built with React (Vite) and a small PHP/MySQL backend.

## Features
- Search beers with AI-backed results (Gemini). When no brewery is given and the name is ambiguous, a "Did you mean...?" picker lists up to 5 candidate matches, prioritizing UK breweries, before running the full lookup.
- Personal log: drank toggle, tasting date/location/notes, photo uploads (stored under `public/uploads/<user_id>`).
- Profile management: name, password, avatar (base64 PNG/JPG/GIF), with a show/hide toggle on every password field.
- My Beers list with sort (beer name, brewery, date tasted, date edited) and filter (by name or brewery), editable modal, and lightbox for photos.
- Message of the day rendered from `public/MOTD.txt`.

## Recent Changes (0.3.0)
- Security: passwords now use Argon2id (or bcrypt) instead of MD5, upgraded automatically at each account's next login. Minimum length is 8.
- Security: every endpoint except login and register needs a session token, including `beer.php`. Login, registration, beer search, uploads and password changes are rate limited.
- Security: registration is closed by default (`allow_registration` in `api/.config.json`). Create accounts with `php data/create_user.php`.
- Security: CORS allows only localhost (any port) and `https://beertracker.codemonkey.design`.
- Security: config files are now `.config.json` (dotfiles, blocked from the web by `api/.htaccess`), and photos moved out of git and out of the public folder. They are served only to their owner by `api/photo.php`.
- Added: Logout (invalidates the token on the server), and the login now survives a page refresh.
- Added: clear (x) button and "New search" button that reset the search result so you can look up another beer.
- Added: when a beer can't be found you can add it manually (name, brewery, optional ABV and description). It is saved to your own log and found by future searches.
- Added: upload and remove toasts with progress, and a limit of 2 photos per beer.
- Added: `debug` flags in both config files, and friendlier server errors with a reference id to quote.
- Fixed: beer names containing " - " were split wrongly, repeat searches with slightly different spelling missed the cache, and "unknown" results were being cached.
- Fixed: My Beers list showed stale dates after editing in the modal.
- Efficiency: one database connection per request, a much lighter auth query, shared PHP and JS helpers.

## Quick Start
1) Install deps:
	```bash
	npm install
	```
2) Configure the backend: copy `api/.config.example.json` to `api/.config.json` and fill in your DB credentials and Gemini API key.
3) Configure the frontend: copy `public/.config.example.json` to `public/.config.json` and set `apiBase` (e.g. `http://localhost/api`).
4) Create the database from `data/schema.sql`. To upgrade an existing database run the files in `data/migrations/` in order (`001`, `002`, then `003`).
5) Create your account (registration is closed by default):
	```bash
	php data/create_user.php "Your Name" you@example.com "a-long-password"
	```
6) Start the Vite dev server:
	```bash
	npm run dev
	```
7) Point your PHP runtime (e.g. Laragon/Apache on `localhost:80`) at `api/` for the API endpoints.

## Project Structure
- `src/` React app
  - `src/api/` shared axios client, endpoint functions, and runtime config loader
- `api/` PHP endpoints
  - `api/common.php` shared bootstrap (config, DB, auth, passwords, rate limits, error handling, helpers)
  - `api/cors.php` shared CORS handling, required first by every endpoint
- `data/schema.sql` DB schema; `data/migrations/` incremental changes; `data/create_user.php` CLI account tool
- `storage/uploads` User-uploaded photos (git-ignored, blocked from the web, served only by `api/photo.php`)

## Key Endpoints (see docs/API.md)
- `api/beer.php` – AI beer search
- `api/user_beers.php` – CRUD-ish for user logs
- `api/upload_user_beer_photo.php` – photo uploads
- `api/delete_user_beer_photo.php` – delete uploaded photo
- `api/user_profile.php` – profile fetch/update
- `api/photo.php` – authenticated photo download
- `api/login.php`, `api/logout.php`, `api/register.php` – auth

## Notes
- Image uploads are limited to PNG/JPG/GIF up to 10MB, and 2 photos per beer. Files are renamed with a UTC timestamp and random suffix per user folder.
- Avatars are stored as data URIs in the DB (base64); consider moving to file storage for production.
- Passwords use Argon2id when PHP provides it, otherwise bcrypt (cost 12). Once every account has logged in since the 0.3.0 upgrade you can drop the legacy `users.password_md5` column.
- Session tokens are opaque, stored server-side with a 30-day expiry and invalidated on logout. A user has one active token, so logging in on a second device signs out the first.
- Rate limits (counted in the `rate_limits` table, per IP or user): 5 failed logins per 15 minutes per email, 20 per IP; 5 registrations per hour per IP; 30 Gemini lookups per hour per user; 60 uploads per hour per user; 5 failed password changes per 15 minutes.
- `public/.config.json` is downloaded by every visitor's browser, so it can only hold non-secret settings (`apiBase`, `debug`, `allowRegistration`). Secrets live in `api/.config.json`, which `api/.htaccess` blocks.
- Set `debug` to `false` in both config files in production. With it on, server errors include technical detail.
- The Gemini model is set to the `gemini-flash-latest` alias (in `api/.config.json`) rather than a dated model name, so Google retiring a specific model version won't break search.
- CORS allows localhost on any port plus the production origin; add new deployment origins to `$allowedOrigins` at the top of `api/cors.php`.

## Scripts
- `npm run dev` – start Vite dev server
- `npm run build` – production build
- `npm run preview` – preview build
- `npm test` – unit tests (Vitest)
- `npm run lint` – ESLint

## License
This project is released under [Creative Commons Attribution-NonCommercial-ShareAlike 4.0 International (CC BY-NC-SA 4.0)](https://creativecommons.org/licenses/by-nc-sa/4.0/).
