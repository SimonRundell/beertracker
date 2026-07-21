# Architecture

## Frontend (Vite React)
- Entry: `src/main.jsx` loads `public/config.json` (via `src/api/config.js`) before rendering `App` — a config load failure shows an inline error instead of a broken app.
- Layout: `App.jsx` splits authenticated view (profile + search left, list right) and guest view (MOTD panel).
- API layer: `src/api/`
  - `config.js` — fetches and caches `public/config.json` at startup.
  - `client.js` — shared axios instance; a request interceptor injects `baseURL` and the `Authorization` header, a response interceptor normalizes error messages.
  - `endpoints.js` — thin per-endpoint functions (`login`, `searchBeer`, `listUserBeers`, etc.) used by components instead of raw `fetch`.
- Components:
  - `BeerSearch` — search UI + personal log editor + photo upload + lightbox; sanitizes AI-returned tasting notes with DOMPurify before rendering.
  - `MyBeers` — list with editable modal and photo management.
  - `UserProfile` — avatar/name/password management.
  - `Lightbox` — reusable image overlay.
  - `MOTD` — renders `public/MOTD.txt`.
  - Auth forms: `LoginForm`, `RegisterForm`.
- Styling: `src/App.css`, `src/index.css` (single hand-written stylesheet, no framework/inline styles).

## Backend (PHP + MySQL)
- Common bootstrap: `api/common.php` — loads `api/config.json`, exposes `config()` (dot-path lookup), `db()`, `respondJson()`, `readJsonBody()`, `hashPassword()`, and `requireAuth()`. Requires `cors.php` first.
- CORS: `api/cors.php` — shared by every endpoint via `common.php`; reflects `http(s)://localhost:<any port>` origins, handles `OPTIONS` preflight.
- Auth: `login.php`, `register.php` issue an opaque session token (`newSessionToken()` in `common.php`), stored on the `users` row with a 30-day expiry. `requireAuth()` verifies `Authorization: Bearer <token>` on every other endpoint and returns the authenticated user row.
- Beer search: `beer.php` (no auth required), two modes:
  - Details (default) — full lookup for one specific beer, schema in `beer_schema.json`.
  - Candidates (`mode:"candidates"`) — lists up to 5 possible beer+brewery matches for a bare beer name, prioritizing UK breweries when ambiguous, schema in `beer_candidates_schema.json`; drives the "Did you mean...?" picker in `BeerSearch` when the Brewery field is left blank.
  Response parsing explicitly handles empty/safety-blocked/truncated Gemini responses; `tastingnotes` HTML is sanitized (tags stripped, not rejected) rather than causing a hard failure.
- User log: `user_beers.php` (GET list/single, POST upsert), stores tasting data + `photos_json`.
- Photos: `upload_user_beer_photo.php` (stores files under `file_location/<user_id>/`), `delete_user_beer_photo.php` (removes file + updates JSON).
- Profile: `user_profile.php` (fetch/update name, avatar, password).

## Configuration
- Backend: `api/config.json` (git-ignored; template in `api/config.example.json`) — DB credentials, upload path, Gemini provider settings.
- Frontend: `public/config.json` (git-ignored; template in `public/config.example.json`) — currently just `apiBase`.

## Data
- Schema: `data/schema.sql` — `users` (incl. `token`/`token_expires_at`), `beers`, `user_beers` tables. `user_beers.photos_json` holds a string array of filenames. `data/migrations/` holds incremental changes for databases created before a given schema change.
- Uploads: served statically from `public/uploads` (or configured `file_location`). Filenames include UTC timestamp and random suffix to avoid collisions.
- Avatars: stored inline as data URIs (base64) in `users.avatar_base64`.

## Key Flows
- Auth → token stored in `user.token` (App state) and pushed into the axios client via `setAuthToken()` → every subsequent API call carries `Authorization: Bearer <token>` automatically.
- Search → `beer.php` checks the `beers` cache table, else calls Gemini, sanitizes/validates the result, caches it → BeerSearch displays result → user edits personal log → POST to `user_beers.php` → MyBeers refresh via `refreshKey` bump.
- Photo upload → BeerSearch/MyBeers call the upload endpoint → filenames stored → thumbnails pulled from `/uploads/<user>/<file>`; deletions update `photos_json` and UI.

## Security Considerations
- Demo-only MD5 password hashing; use bcrypt/argon2 for production.
- Session tokens are opaque random strings with a fixed 30-day expiry (no refresh/rotation) — adequate for this app's scale, not a full auth system.
- CORS reflects any `localhost:<port>` origin — fine for local dev, should be tightened (explicit allowlist) before any non-local deployment.
- Validate/serve uploads carefully in production (MIME checks already present; consider scanning, size limits are enforced).
