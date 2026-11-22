# HarbourEdge store - one-click local setup for Windows + XAMPP.
# Run via setup-windows.bat (double-click). Safe to run again: it skips finished steps.
# Never touches XAMPP's own PHP and never wipes a database.

$ErrorActionPreference = 'Continue'
$ProgressPreference = 'SilentlyContinue'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

$Project  = Split-Path -Parent $PSScriptRoot
$Xampp    = 'C:\xampp'
$Php83Dir = Join-Path $Xampp 'php83'
$Database = 'harbouredge_store'

function Step($text) { Write-Host ""; Write-Host "==> $text" -ForegroundColor Cyan }
function Fail($text) { Write-Host ""; Write-Host "ERROR: $text" -ForegroundColor Red; Read-Host "Press Enter to close"; exit 1 }

Set-Location $Project
Write-Host "HarbourEdge store setup" -ForegroundColor Yellow
Write-Host "Project: $Project"

Step "Checking PHP (needs 8.3 or newer)"
$Php = $null
foreach ($candidate in @((Join-Path $Php83Dir 'php.exe'), (Join-Path $Xampp 'php\php.exe'))) {
    if (Test-Path $candidate) {
        $version = & $candidate -r "echo PHP_VERSION;" 2>$null
        Write-Host "Found PHP $version at $candidate"
        if ($version -and ([version]($version -replace '[^0-9.].*$', '')) -ge [version]'8.3') { $Php = $candidate; break }
    }
}

if (-not $Php) {
    Step "Downloading PHP 8.3 into $Php83Dir (XAMPP's own PHP is not changed)"
    $zip = Join-Path $env:TEMP 'php-8.3.zip'
    Invoke-WebRequest 'https://windows.php.net/downloads/releases/latest/php-8.3-nts-Win32-vs16-x64-latest.zip' -OutFile $zip -ErrorAction Stop
    New-Item -ItemType Directory -Force -Path $Php83Dir | Out-Null
    Expand-Archive -Path $zip -DestinationPath $Php83Dir -Force -ErrorAction Stop
    Remove-Item $zip
    $Php = Join-Path $Php83Dir 'php.exe'
}

$PhpDir = Split-Path -Parent $Php
$ini = Join-Path $PhpDir 'php.ini'
if ($PhpDir -eq $Php83Dir -and -not (Test-Path $ini)) {
    Step "Configuring php.ini"
    $content = Get-Content (Join-Path $PhpDir 'php.ini-development') -Raw
    $content = $content -replace '(?m)^;\s*extension_dir\s*=\s*"ext"', 'extension_dir = "ext"'
    foreach ($ext in 'curl','fileinfo','gd','intl','mbstring','mysqli','openssl','pdo_mysql','sodium','zip','exif') {
        $content = $content -replace "(?m)^;extension=$ext\s*$", "extension=$ext"
    }
    $content = $content -replace '(?m)^memory_limit\s*=.*$', 'memory_limit = 2G'
    $content = $content -replace '(?m)^max_execution_time\s*=.*$', 'max_execution_time = 0'
    Set-Content -Path $ini -Value $content -Encoding ASCII
}

$missing = @()
$loaded = & $Php -m
foreach ($ext in 'curl','fileinfo','gd','intl','mbstring','openssl','pdo_mysql','zip','bcmath') {
    if (-not ($loaded -contains $ext)) { $missing += $ext }
}
if ($missing.Count) { Fail "PHP at $Php is missing extensions: $($missing -join ', '). Enable them in $ini and run again." }
Write-Host "Using PHP: $Php" -ForegroundColor Green

Step "Getting Composer"
$composer = Join-Path $PhpDir 'composer.phar'
if (-not (Test-Path $composer)) {
    Invoke-WebRequest 'https://getcomposer.org/download/latest-stable/composer.phar' -OutFile $composer -ErrorAction Stop
}

Step "Installing libraries (composer install) - this can take 5-15 minutes"
if (-not (Test-Path (Join-Path $Project 'vendor\autoload.php'))) {
    & $Php $composer install --no-interaction --prefer-dist
    if ($LASTEXITCODE -ne 0) { Fail "composer install failed. Send a screenshot of the messages above." }
} else {
    Write-Host "vendor already installed, skipping."
}

Step "Checking MySQL (XAMPP)"
$mysql = Join-Path $Xampp 'mysql\bin\mysql.exe'
if (-not (Test-Path $mysql)) { Fail "Cannot find $mysql. Is XAMPP installed in C:\xampp?" }
& $mysql -u root -e "SELECT 1" 2>$null | Out-Null
if ($LASTEXITCODE -ne 0) { Fail "MySQL is not running. Open XAMPP Control Panel, click Start next to MySQL, then run this setup again." }
& $mysql -u root -e "CREATE DATABASE IF NOT EXISTS $Database CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
$tables = & $mysql -u root -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$Database'"
Write-Host "Database $Database ready ($tables tables)."

Step "Creating .env settings"
$envFile = Join-Path $Project '.env'
if (-not (Test-Path $envFile)) {
    $envText = Get-Content (Join-Path $Project '.env.example') -Raw
    $envText = $envText -replace '(?m)^APP_URL=.*$', 'APP_URL=http://localhost:8000'
    $envText = $envText -replace '(?m)^DB_HOST=.*$', 'DB_HOST=127.0.0.1'
    $envText = $envText -replace '(?m)^DB_PORT=.*$', 'DB_PORT=3306'
    $envText = $envText -replace '(?m)^DB_DATABASE=.*$', "DB_DATABASE=$Database"
    $envText = $envText -replace '(?m)^DB_USERNAME=.*$', 'DB_USERNAME=root'
    $envText = $envText -replace '(?m)^DB_PASSWORD=.*$', 'DB_PASSWORD='
    Set-Content -Path $envFile -Value $envText -Encoding UTF8
    & $Php artisan key:generate --force
} else {
    Write-Host ".env already exists, keeping it."
}

Step "Installing the store into $Database"
$loginFile = Join-Path $Project 'storage\app\admin-login.txt'
if (-not (Test-Path (Join-Path $Project 'storage\installed'))) {
    $password = -join ((48..57) + (65..90) + (97..122) | Get-Random -Count 14 | ForEach-Object { [char]$_ })
    & $Php artisan harbouredge:install --admin-email=admin@harboureg.com "--admin-password=$password"
    if ($LASTEXITCODE -ne 0) { Fail "Store install failed. Send a screenshot of the messages above." }
    Set-Content -Path $loginFile -Value "Admin URL: http://localhost:8000/admin`r`nEmail: admin@harboureg.com`r`nPassword: $password"
} else {
    Write-Host "Store already installed, skipping."
}

Write-Host ""
Write-Host "============================================================" -ForegroundColor Green
Write-Host " DONE. Store:  http://localhost:8000" -ForegroundColor Green
Write-Host "       Admin:  http://localhost:8000/admin" -ForegroundColor Green
if (Test-Path $loginFile) { Get-Content $loginFile | ForEach-Object { Write-Host "       $_" -ForegroundColor Green } }
Write-Host " Keep this window open while using the store." -ForegroundColor Green
Write-Host "============================================================" -ForegroundColor Green

Start-Process powershell -WindowStyle Hidden -ArgumentList '-NoProfile -Command "Start-Sleep 5; Start-Process http://localhost:8000"'
& $Php artisan serve --host=127.0.0.1 --port=8000
