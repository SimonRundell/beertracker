# Development Guide

## Prereqs
- Node 18+
- PHP 8+
- MySQL 8 (or compatible)

## Setup
1) Install frontend deps: `npm install`
2) Configure `api/.env` (DB + `FILE_LOCATION`). For local dev, `../public/uploads` works with Vite static serving.
3) Create DB from `data/schema.sql`.
4) Ensure upload directory exists and is writable.

## Environment Variables
- Root `.env` (frontend):
	- `GEMINI_API_KEY`: key for AI features.
	- `GEMINI_PROVIDER`: provider slug (e.g., `devapi`).
	- `GEMINI_MODEL`: model name (e.g., `gemini-2.0-flash`).
	- `VITE_API_BASE`: HTTP base for the PHP API (e.g., `http://localhost/api`).
- `api/.env` (backend):
	- `DB_HOST`, `DB_PORT`: database host/port.
	- `DB_NAME`, `DB_USER`, `DB_PASS`: credentials for the beertracker database.
	- `FILE_LOCATION`: absolute/relative path where uploaded images are stored (e.g., `../public/uploads` for local dev).
- These files are intentionally ignored by git. Create them locally with your secrets; never commit real keys or passwords.

## Running
- Frontend: `npm run dev` (Vite)
- Backend: serve `api/` via PHP (e.g., `php -S localhost:8000 -t api`). Update `VITE_API_BASE` in `.env` if hosting elsewhere.

## Testing Manual Flows
- Register/login
- Search a beer
- Save log (drank toggle, date, location, notes)
- Upload photo(s); delete a photo in modal; verify count updates
- Edit profile (name/avatar/password)
- MOTD renders when logged out

## Conventions
- Components documented with JSDoc for props/stateful behavior.
- Image uploads: PNG/JPG/GIF ≤10MB; stored under `public/uploads/<user_id>/` with UTC timestamp filenames.
- Dates: ISO `YYYY-MM-DD`; date inputs call `showPicker` when supported.

## Deployment Notes
- Use absolute path for `FILE_LOCATION` in production.
- Serve `public/` as web root so `/uploads/...` resolves.
- Replace MD5 hashing with bcrypt/argon2 and add real auth (sessions/JWT) before production use.
