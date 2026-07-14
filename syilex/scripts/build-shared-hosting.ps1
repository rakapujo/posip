# Build POSIP installer zip for shared hosting (Windows / Laragon).
# Usage:
#   powershell -ExecutionPolicy Bypass -File C:\laragon\www\POSIP\syilex\scripts\build-shared-hosting.ps1
#
# Output: C:\laragon\www\POSIP\installer\posip-installer.zip
#   INSTALL.md, INSTALL.txt, posip/

$ErrorActionPreference = 'Stop'

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$ProjectDir = Split-Path -Parent $ScriptDir
$RepoRoot = Split-Path -Parent $ProjectDir
$FrontendDir = Join-Path $RepoRoot 'syilex-frontend'
$InstallerDir = Join-Path $RepoRoot 'installer'
$BuildDir = Join-Path $env:TEMP ('posip-build-' + [guid]::NewGuid().ToString('N').Substring(0, 8))
$PosipDir = Join-Path $BuildDir 'posip'
$Output = Join-Path $InstallerDir 'posip-installer.zip'

Write-Host '========================================='
Write-Host '  POSIP Shared Hosting Build (Windows)'
Write-Host '========================================='

New-Item -ItemType Directory -Force -Path $InstallerDir | Out-Null
if (Test-Path $BuildDir) { Remove-Item -Recurse -Force $BuildDir }
New-Item -ItemType Directory -Force -Path $PosipDir | Out-Null

Write-Host '[1/7] Sync frontend to public/...'
$distIndex = Join-Path $FrontendDir 'dist\index.html'
if (Test-Path $FrontendDir) {
    if (-not (Test-Path $distIndex)) {
        Write-Host '  npm run build...'
        Push-Location $FrontendDir
        npm run build
        if ($LASTEXITCODE -ne 0) { throw 'npm run build failed' }
        Pop-Location
    }
    $publicDir = Join-Path $ProjectDir 'public'
    $assetsDir = Join-Path $publicDir 'assets'
    if (Test-Path $assetsDir) { Remove-Item -Recurse -Force $assetsDir }
    Copy-Item -Path (Join-Path $FrontendDir 'dist\*') -Destination $publicDir -Recurse -Force
    Write-Host '  Frontend deployed to public/'
} else {
    Write-Host '  WARNING: syilex-frontend missing - packing existing public/'
}

Write-Host '[2/7] Copying project files...'
$items = @(
    'app', 'bootstrap', 'config', 'database', 'public', 'resources', 'routes', 'storage', 'vendor',
    '.env.example', '.env.production.example', 'artisan', 'composer.json', 'composer.lock',
    'DEPLOY.md', 'INSTALL-SHARED-HOSTING.md', 'README.md', 'package.json'
)
foreach ($item in $items) {
    $src = Join-Path $ProjectDir $item
    if (Test-Path $src) {
        Copy-Item -Path $src -Destination (Join-Path $PosipDir $item) -Recurse -Force
    }
}

$htaccessLines = @(
    '<IfModule mod_rewrite.c>',
    '    RewriteEngine On',
    '    RewriteBase /',
    '',
    '    DirectorySlash Off',
    '',
    '    RewriteCond %{REQUEST_URI} ^/public/ [OR]',
    '    RewriteCond %{REQUEST_URI} ^/public$',
    '    RewriteRule ^ - [L]',
    '',
    '    RewriteRule ^(.*)$ public/$1 [L]',
    '</IfModule>'
)
[System.IO.File]::WriteAllLines((Join-Path $PosipDir '.htaccess'), $htaccessLines)

$installed = Join-Path $PosipDir 'storage\installed'
if (Test-Path $installed) { Remove-Item -Force $installed }
Get-ChildItem (Join-Path $PosipDir 'storage\logs') -Filter '*.log' -ErrorAction SilentlyContinue | Remove-Item -Force
Get-ChildItem (Join-Path $PosipDir 'bootstrap\cache') -Filter '*.php' -ErrorAction SilentlyContinue | Remove-Item -Force
$fw = Join-Path $PosipDir 'storage\framework'
if (Test-Path $fw) {
    Get-ChildItem $fw -Recurse -File -ErrorAction SilentlyContinue |
        Where-Object { $_.Name -notin @('.gitkeep', '.gitignore') } |
        Remove-Item -Force -ErrorAction SilentlyContinue
}

Write-Host '[3/7] Ensuring vendor/...'
if (-not (Test-Path (Join-Path $PosipDir 'vendor'))) {
    $vendorSrc = Join-Path $ProjectDir 'vendor'
    if (-not (Test-Path $vendorSrc)) { throw 'vendor/ missing. Run: composer install --no-dev' }
    Copy-Item -Path $vendorSrc -Destination (Join-Path $PosipDir 'vendor') -Recurse -Force
}

Write-Host '[4/7] Creating .env with APP_KEY...'
$envExample = Join-Path $PosipDir '.env.example'
$envFile = Join-Path $PosipDir '.env'
Copy-Item $envExample $envFile -Force
$bytes = New-Object byte[] 32
[System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
$appKey = 'base64:' + [Convert]::ToBase64String($bytes)
$envText = Get-Content $envFile -Raw
$envText = [regex]::Replace($envText, '(?m)^APP_KEY=.*$', "APP_KEY=$appKey")
$envText = [regex]::Replace($envText, '(?m)^APP_ENV=.*$', 'APP_ENV=production')
$envText = [regex]::Replace($envText, '(?m)^APP_DEBUG=.*$', 'APP_DEBUG=false')
[System.IO.File]::WriteAllText($envFile, $envText)

Write-Host '[5/7] Ensuring storage directories...'
@(
    'storage\app\public',
    'storage\framework\cache\data',
    'storage\framework\sessions',
    'storage\framework\views',
    'storage\logs',
    'bootstrap\cache'
) | ForEach-Object {
    New-Item -ItemType Directory -Force -Path (Join-Path $PosipDir $_) | Out-Null
}

Write-Host '[6/7] Writing INSTALL tutorial...'
Copy-Item (Join-Path $ProjectDir 'INSTALL-SHARED-HOSTING.md') (Join-Path $BuildDir 'INSTALL.md') -Force
$lines = New-Object System.Collections.Generic.List[string]
$lines.Add('INSTALASI POSIP - RINGKAS') | Out-Null
$lines.Add('=========================') | Out-Null
$lines.Add('1. Extract zip ini') | Out-Null
$lines.Add('2. Baca INSTALL.md (tutorial lengkap)') | Out-Null
$lines.Add('3. Upload folder posip/ ke hosting (isi ke public_html/ ATAU document root subdomain = posip/public)') | Out-Null
$lines.Add('4. Buat database MySQL di cPanel') | Out-Null
$lines.Add('5. Set permission storage/ dan bootstrap/cache/ ke 775') | Out-Null
$lines.Add('6. Buka http://domain-anda.com/install') | Out-Null
$lines.Add('7. Ikuti wizard 8 langkah lalu selesai') | Out-Null
$lines.Add('') | Out-Null
$lines.Add('Persyaratan: PHP 8.2+, MySQL/MariaDB, ekstensi pdo_mysql mbstring openssl tokenizer xml ctype json bcmath fileinfo gd') | Out-Null
[System.IO.File]::WriteAllLines((Join-Path $BuildDir 'INSTALL.txt'), $lines)

Write-Host "[7/7] Creating zip -> $Output ..."
if (Test-Path $Output) { Remove-Item -Force $Output }
Compress-Archive -Path (Join-Path $BuildDir '*') -DestinationPath $Output -Force

Remove-Item -Recurse -Force $BuildDir

$sizeMb = [math]::Round((Get-Item $Output).Length / 1MB, 1)
Write-Host ''
Write-Host '========================================='
Write-Host '  Build selesai!'
Write-Host "  Output: $Output"
Write-Host ("  Size:   {0} MB" -f $sizeMb)
Write-Host '  Isi:    INSTALL.md + INSTALL.txt + posip/'
Write-Host '========================================='
