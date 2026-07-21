# Architecture

## Frontend (Vite React)
- Entry: `src/main.jsx` bootstraps `App`.
- Layout: `App.jsx` splits authenticated view (profile + search left, list right) and guest view (MOTD panel).
- Components:
  - `BeerSearch` — search UI + personal log editor + photo upload + lightbox.
  - `MyBeers` — list with editable modal and photo management.
  - `UserProfile` — avatar/name/password management.
  - `Lightbox` — reusable image overlay.
  - `MOTD` — renders `public/MOTD.txt`.
  - Auth forms: `LoginForm`, `RegisterForm`.
- Styling: `src/App.css`, `src/index.css`.

## Backend (PHP + MySQL)
- Common bootstrap: `api/common.php` (CORS, JSON helpers, DB connection, env loader).
- Auth: `login.php`, `register.php`.
- Beer search: `beer.php` (consumes AI schema in `beer_schema.json`).
- User log: `user_beers.php` (GET list/single, POST upsert), stores tasting data + `photos_json`.
- Photos: `upload_user_beer_photo.php` (stores files under `FILE_LOCATION/<user_id>/`), `delete_user_beer_photo.php` (removes file + updates JSON).
- Profile: `user_profile.php` (fetch/update name, avatar, password).

## Data
- Schema: `data/schema.sql` — `users`, `beers`, `user_beers` tables. `user_beers.photos_json` holds string array of filenames.
- Uploads: served statically from `public/uploads` (or configured `FILE_LOCATION`). Filenames include UTC timestamp and random suffix to avoid collisions.
- Avatars: stored inline as data URIs (base64) in `users.avatar_base64`.

## Key Flows
- Auth → store user in App state → header pill shows avatar.
- Search → BeerSearch displays result → user edits personal log → POST to `user_beers.php` → MyBeers refresh via `refreshKey` bump.
- Photo upload → BeerSearch/MyBeers call upload endpoint → filenames stored → thumbnails pulled from `/uploads/<user>/<file>`; deletions update `photos_json` and UI.

## Security Considerations
- Demo-only MD5 password hashing; use bcrypt/argon2 for production.
- No auth tokens enforced server-side; APIs trust `user_id`. Add real sessions/JWT for production.
- CORS is permissive for development.
- Validate/serve uploads carefully in production (MIME checks already present; consider scanning, size limits are enforced).
