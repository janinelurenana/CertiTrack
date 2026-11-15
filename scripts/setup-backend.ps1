# One-time backend bootstrap for Windows PowerShell (XAMPP/Laragon/Herd). Requires PHP >= 8.2 and Composer 2 on PATH.
$ErrorActionPreference = 'Stop'
Set-Location (Join-Path $PSScriptRoot '..\backend')
if (-not (Get-Command php -ErrorAction SilentlyContinue)) { throw 'PHP 8.2+ not found on PATH' }
if (-not (Get-Command composer -ErrorAction SilentlyContinue)) { throw 'Composer not found on PATH' }

if (-not (Test-Path artisan)) {
  Write-Host '>> Fetching Laravel 12 skeleton (boilerplate only)...'
  composer create-project laravel/laravel:^12.0 .skeleton --no-install --no-scripts --no-interaction
  # copy every skeleton file that does not already exist (never overwrite CertiTrack files)
  $root = (Resolve-Path .skeleton).Path
  Get-ChildItem -Recurse -Force -File .skeleton | ForEach-Object {
    $rel = $_.FullName.Substring($root.Length + 1)
    if (-not (Test-Path $rel)) { New-Item -ItemType Directory -Force -Path (Split-Path $rel -Parent | ForEach-Object { if ($_ -eq '') { '.' } else { $_ } }) | Out-Null; Copy-Item $_.FullName $rel }
  }
  Remove-Item -Recurse -Force .skeleton
}
Remove-Item -Force -ErrorAction SilentlyContinue tests\Feature\ExampleTest.php, tests\Unit\ExampleTest.php

composer install --no-interaction
if (-not (Test-Path .env)) { Copy-Item .env.example .env }
php artisan key:generate --ansi
Write-Host "`nNext: create the MySQL database (README section 8), edit backend\.env, then:`n  php artisan migrate --seed`n  php artisan serve --host=127.0.0.1 --port=8000`n  php artisan schedule:work   (separate terminal)"
