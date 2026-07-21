# BeerTracker

AI-assisted beer search with personal tasting log, photo uploads, and user profiles. Built with React (Vite) and a small PHP/MySQL backend.

## Features
- Search beers with AI-backed results.
- Personal log: drank toggle, tasting date/location/notes, photo uploads (stored under `public/uploads/<user_id>`).
- Profile management: name, password, avatar (base64 PNG/JPG/GIF).
- My Beers list with editable modal and lightbox for photos.
- Message of the day rendered from `public/MOTD.txt`.

## Quick Start
1) Install deps:
	```bash
	npm install
	```
2) Configure backend environment in `api/.env`:
	```env
	DB_NAME=beertracker
	DB_USER=developer
	DB_PASS=yourpassword
	DB_PORT=3306
	FILE_LOCATION=../public/uploads  # absolute path recommended in production
	```
3) Create database from `data/schema.sql` and ensure `FILE_LOCATION` exists and is writable.
4) Start Vite dev server:
	```bash
	npm run dev
	```
5) Point your server/PHP runtime to `api/` for API endpoints.

## Project Structure
- `src/` React app
- `api/` PHP endpoints
- `data/schema.sql` DB schema
- `public/uploads` User-uploaded photos (served statically)

## Key Endpoints (see API.md)
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

## Scripts
- `npm run dev` – start Vite dev server
- `npm run build` – production build
- `npm run preview` – preview build

## License
MIT
