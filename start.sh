#!/usr/bin/env bash
# ====================================================================
# Reaching Out Initiative (ROI) - One-Click Fullstack Launcher (Mac/Linux)
# Stack: Laravel 13 API + Vanilla HTML/CSS/JS SPA (Tailwind CLI)
# ====================================================================

set -e

echo "🌍 Starting Reaching Out Initiative Ecosystem (Mombasa HQ)..."

# 1. Backend (Laravel) on port 8000
echo "🔧 Initializing Laravel API on port 8000..."
cd laravel-backend
[ -f composer.json ] && [ ! -d vendor ] && composer install --no-interaction
[ -f .env ] || cp .env.example .env
[ -f database/database.sqlite ] || touch database/database.sqlite
php artisan key:generate --force >/dev/null 2>&1 || true
php artisan migrate --force
php artisan db:seed --class=RoiSeeder --force 2>/dev/null || true

php artisan serve --host=127.0.0.1 --port=8000 &
BACKEND_PID=$!
cd ..

# 2. Frontend build + static preview on port 3000
#    (The same build is auto-deployed into laravel-backend/public, so you can
#     also just open http://127.0.0.1:8000/ for the single-origin experience.)
echo "🎨 Building Tailwind + assembling SPA..."
cd frontend
npm install --no-audit --no-fund
npm run build
echo "⚛️ Serving SPA on port 3000 (API proxied via ?apiBase or same-origin on :8000)..."
npx --yes serve -l 3000 dist

# Cleanup on exit
trap "kill $BACKEND_PID" EXIT
