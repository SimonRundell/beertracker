# BeerTracker

AI-assisted beer search with personal tasting log, photo uploads, and user profiles. Built with React (Vite) and a small PHP/MySQL backend.

## Features
- Search beers with AI-backed results (Gemini).
- Personal log: drank toggle, tasting date/location/notes, photo uploads (stored under `public/uploads/<user_id>`).
- Profile management: name, password, avatar (base64 PNG/JPG/GIF).
- My Beers list with editable modal and lightbox for photos.
- Message of the day rendered from `public/MOTD.txt`.

## Quick Start
1) Install deps:
	```bash
	npm install
	```
2) Configure the backend: copy `api/config.example.json` to `api/config.json` and fill in your DB credentials and Gemini API key.
3) Configure the frontend: copy `public/config.example.json` to `public/config.json` and set `apiBase` (e.g. `http://localhost/api`).
4) Create the database from `data/schema.sql` (includes the session-token columns). If upgrading an existing database, run `data/migrations/001_add_auth_token.sql` instead.
5) Ensure the upload directory (`file_location` in `api/config.json`) exists and is writable.
6) Start the Vite dev server:
	```bash
	npm run dev
	```
7) Point your PHP runtime (e.g. Laragon/Apache on `localhost:80`) at `api/` for the API endpoints.

## Project Structure
- `src/` React app
  - `src/api/` shared axios client, endpoint functions, and runtime config loader
- `api/` PHP endpoints
  - `api/common.php` shared bootstrap (config, DB, auth, JSON helpers)
  - `api/cors.php` shared CORS handling, required first by every endpoint
- `data/schema.sql` DB schema; `data/migrations/` incremental changes for existing databases
- `public/uploads` User-uploaded photos (served statically)

## Key Endpoints (see docs/API.md)
- `api/beer.php` – AI beer search
- `api/user_beers.php` – CRUD-ish for user logs
- `api/upload_user_beer_photo.php` – photo uploads
- `api/delete_user_beer_photo.php` – delete uploaded photo
- `api/user_profile.php` – profile fetch/update
- `api/login.php`, `api/register.php` – auth

## Notes
- Image uploads are limited to PNG/JPG/GIF up to 10MB. Files are renamed with UTC timestamp and random suffix per user folder.
- Avatars are stored as data URIs in the DB (base64); consider moving to file storage for production.
- Passwords are MD5 in this demo; use a stronger hash (bcrypt/argon2) for production.
- Session tokens are opaque, stored server-side with a 30-day expiry; every authenticated endpoint requires `Authorization: Bearer <token>`.

## Scripts
- `npm run dev` – start Vite dev server
- `npm run build` – production build
- `npm run preview` – preview build

## License
This project is released under [Creative Commons Attribution-NonCommercial-ShareAlike 4.0 International (CC BY-NC-SA 4.0)](https://creativecommons.org/licenses/by-nc-sa/4.0/).
