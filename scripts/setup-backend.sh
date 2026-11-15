#!/usr/bin/env bash
# One-time backend bootstrap (Linux/macOS/WSL/Git-Bash). Requires PHP >= 8.2 and Composer 2 on PATH.
# The ZIP ships CertiTrack's own Laravel source (app/, routes/, database/, resources/, config/certitrack.php,
# bootstrap/app.php, composer.json ...). This script downloads the stock Laravel skeleton ONLY to fill in
# framework boilerplate (artisan, public/index.php, config/*.php ...) and never overwrites our files.
set -euo pipefail
cd "$(dirname "$0")/../backend"

command -v php >/dev/null      || { echo "PHP 8.2+ not found"; exit 1; }
command -v composer >/dev/null || { echo "Composer not found (https://getcomposer.org)"; exit 1; }
php -r 'exit(version_compare(PHP_VERSION, "8.2.0", ">=") ? 0 : 1);' || { echo "PHP 8.2+ required"; exit 1; }

if [ ! -f artisan ]; then
  echo ">> Fetching Laravel 12 skeleton (boilerplate only)..."
  composer create-project laravel/laravel:^12.0 .skeleton --no-install --no-scripts --no-interaction
  cp -rn .skeleton/. .          # -n: never overwrite CertiTrack files
  rm -rf .skeleton
fi
rm -f tests/Feature/ExampleTest.php tests/Unit/ExampleTest.php   # skeleton examples expect a different '/' route

echo ">> Installing PHP dependencies..."
composer install --no-interaction

[ -f .env ] || cp .env.example .env
php artisan key:generate --ansi

cat <<MSG

Backend files are ready. Next:
  1. Create the MySQL database/user (README section 8), then edit backend/.env (DB_PASSWORD).
  2. php artisan migrate --seed
  3. php artisan serve --host=127.0.0.1 --port=8000
  4. In another terminal:  php artisan schedule:work     (heartbeat timeouts, retention purge)
MSG
