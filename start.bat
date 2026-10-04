@echo off
REM ====================================================================
REM Demo NGO (DEMO) - One-Click Launcher (Windows VS Code)
REM Stack: Laravel 13 API + Vanilla HTML/CSS/JS SPA (Tailwind CLI)
REM ====================================================================

echo Starting Demo NGO Ecosystem (Harbor City HQ)...

start "DEMO Laravel API (Port 8000)" cmd /k "cd laravel-backend && (if not exist vendor composer install --no-interaction) && (if not exist .env copy .env.example .env) && (if not exist database\database.sqlite type nul> database\database.sqlite) && php artisan key:generate --force && php artisan migrate --force && php artisan db:seed --class=RoiSeeder --force && php artisan serve --host=127.0.0.1 --port=8000"

echo Building Tailwind + assembling SPA...
cd frontend
call npm install --no-audit --no-fund
call npm run build

echo Serving SPA on port 3000 (single-origin also available at http://127.0.0.1:8000/)...
npx --yes serve -l 3000 dist
