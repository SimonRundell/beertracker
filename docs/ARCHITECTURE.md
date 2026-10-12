# Architecture

## Frontend (Vite React)
- Entry: `src/main.jsx` loads `public/.config.json` (via `src/api/config.js`) before rendering `App` — a config load failure shows an inline error instead of a broken app.
- Layout: `App.jsx` splits authenticated view (profile + search left, list right) and guest view (MOTD panel).
- API layer: `src/api/`
  - `config.js` — fetches and caches `public/.config.json` at startup.
  - `session.js` — saves the login (token plus basic user info) in localStorage so a refresh keeps you signed in; `App` re-validates it on load.
  - `photos.js` — downloads photos through `photo.php` as blobs (they need the token) and caches the object URLs.
  - `client.js` — shared axios instance; a request interceptor injects `baseURL` and the `Authorization` header, a response interceptor normalizes error messages (adding the server's `request_id`, and `detail`/console logging when `debug` is on) and signs the user out on a 401.
  - `endpoints.js` — thin per-endpoint functions (`login`, `searchBeer`, `listUserBeers`, etc.) used by components instead of raw `fetch`.
- Components:
  - `BeerSearch` — search UI + personal log editor + photo upload + lightbox; sanitizes AI-returned tasting notes with DOMPurify before rendering.
  - `MyBeers` — list with editable modal and photo management.
  - `UserProfile` — avatar/name/password management.
  - `Lightbox` — reusable image overlay.
  - `PhotoThumb` — authenticated photo thumbnail.
  - Toasts: `src/toast/` provides `ToastProvider` and `useToast()`; used for upload and remove progress.
  - `src/utils/` holds shared formatting helpers (unit tested) and the photo upload routine.
  - `MOTD` — renders `public/MOTD.txt`.
  - Auth forms: `LoginForm`, `RegisterForm`.
- Styling: `src/App.css`, `src/index.css` (single hand-written stylesheet, no framework/inline styles).

## Backend (PHP + MySQL)
- Common bootstrap: `api/common.php` — loads `api/.config.json`, exposes `config()` (dot-path lookup), one shared `db()` connection, `respondJson()`, `respondServerError()` (logs, returns a generic message plus `request_id`), password helpers, `requireAuth()`, rate limiting, and shared beer/photo helpers. Requires `cors.php` first.
- CORS: `api/cors.php` — shared by every endpoint via `common.php`; reflects only `localhost:<any port>` and the listed production origins, refuses anything else, handles `OPTIONS` preflight.
- Auth: `login.php`, `register.php` issue an opaque session token (`newSessionToken()` in `common.php`), stored on the `users` row with a 30-day expiry. `logout.php` clears it. Passwords are Argon2id/bcrypt; legacy MD5 hashes are upgraded on login. Failed logins are rate limited via the `rate_limits` table. `requireAuth()` verifies `Authorization: Bearer <token>` on every other endpoint and returns the authenticated user row.
- Beer search: `beer.php` (auth required, 30 Gemini calls per hour per user), two modes:
  - Details (default) — full lookup for one specific beer, schema in `beer_schema.json`.
  - Candidates (`mode:"candidates"`) — lists up to 5 possible beer+brewery matches for a bare beer name, prioritizing UK breweries when ambiguous, schema in `beer_candidates_schema.json`; drives the "Did you mean...?" picker in `BeerSearch` when the Brewery field is left blank.
  The `beers` cache is keyed on normalized beer+brewery, with `beer_aliases` mapping whatever the user typed to the canonical row. "Unknown" results are never cached. Response parsing explicitly handles empty/safety-blocked/truncated Gemini responses; `tastingnotes` HTML is sanitized (tags stripped, not rejected) rather than causing a hard failure.
- Manual beers: `manual_beer.php` stores a hand-entered beer's ABV and description (`is_manual`, `abv`, `description`) on the user's `user_beers` row; `beer.php` checks these first for the same user.
- User log: `user_beers.php` (GET list/single, POST upsert), stores tasting data + `photos_json`.
- Photos: `upload_user_beer_photo.php` (max 2 per beer; stores files under `file_location/<user_id>/`, default `storage/uploads`), `delete_user_beer_photo.php` (removes file + updates JSON), `photo.php` (authenticated download of the caller's own photos).
- Profile: `user_profile.php` (fetch/update name, avatar, password).

## Configuration
- Backend: `api/.config.json` (git-ignored; template in `api/.config.example.json`) — DB credentials, upload path, Gemini provider settings.
- Frontend: `public/.config.json` (git-ignored; template in `public/.config.example.json`) — `apiBase`, `debug`, `allowRegistration`. Downloaded by every visitor, so no secrets.

## Data
- Schema: `data/schema.sql` — `users` (incl. `token`/`token_expires_at`), `beers`, `user_beers` tables. `user_beers.photos_json` holds a string array of filenames. `data/migrations/` holds incremental changes for databases created before a given schema change.
- Uploads: stored in `storage/uploads` (or configured `file_location`), outside the public folder and git-ignored. Filenames include UTC timestamp and random suffix to avoid collisions.
- Avatars: stored inline as data URIs (base64) in `users.avatar_base64`.

## Key Flows
- Auth → token stored in `user.token` (App state) and pushed into the axios client via `setAuthToken()` → every subsequent API call carries `Authorization: Bearer <token>` automatically.
- Search → `beer.php` checks the `beers` cache table, else calls Gemini, sanitizes/validates the result, caches it → BeerSearch displays result → user edits personal log → POST to `user_beers.php` → MyBeers refresh via `refreshKey` bump.
- Photo upload → BeerSearch/MyBeers call the upload endpoint → filenames stored → thumbnails fetched via `photo.php` with the token; deletions update `photos_json` and UI.

## Security Considerations
- Passwords: Argon2id (bcrypt fallback), salted, with a minimum length of 8.
- Session tokens are opaque random strings with a fixed 30-day expiry, stored in the browser's localStorage, and revoked by logout. One active token per user.
- Rate limits are per IP (`REMOTE_ADDR` only; behind a reverse proxy, make sure PHP sees the real client address) or per user.
- CORS is an explicit allowlist (localhost any port, production origin).
- `.htaccess` files block dotfiles, logs and SQL under `api/`, and everything under `storage/` and `data/`. Nginx deployments need equivalent rules.
- Uploads: MIME sniffed server-side, 10MB and 2-per-beer limits, random filenames, served only to their owner.
