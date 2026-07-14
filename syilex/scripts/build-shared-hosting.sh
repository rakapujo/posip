#!/bin/bash
#
# Build POSIP Shared Hosting Distribution Package
# Works on Windows (Git Bash) and Linux
#
# Usage (from syilex/):
#   bash scripts/build-shared-hosting.sh
#
# Output:
#   ../installer/posip-installer.zip
#     ├── INSTALL.md          (tutorial lengkap)
#     ├── INSTALL.txt         (ringkas)
#     └── posip/              (aplikasi siap upload + wizard /install)
#

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
REPO_ROOT="$(dirname "$PROJECT_DIR")"
FRONTEND_DIR="$REPO_ROOT/syilex-frontend"
INSTALLER_DIR="$REPO_ROOT/installer"
BUILD_DIR="${TMPDIR:-/tmp}/posip-build-$$"
OUTPUT="$INSTALLER_DIR/posip-installer.zip"

echo "========================================="
echo "  POSIP Shared Hosting Build"
echo "========================================="

mkdir -p "$INSTALLER_DIR"
rm -rf "$BUILD_DIR"
mkdir -p "$BUILD_DIR/posip"

echo "[1/7] Sync frontend → public/ (npm build if needed)..."
if [ -d "$FRONTEND_DIR" ]; then
    if [ ! -f "$FRONTEND_DIR/dist/index.html" ]; then
        echo "  Building frontend..."
        (cd "$FRONTEND_DIR" && npm run build)
    fi
    rm -rf "$PROJECT_DIR/public/assets"
    cp -r "$FRONTEND_DIR/dist/"* "$PROJECT_DIR/public/"
    echo "  Frontend deployed to public/"
else
    echo "  WARNING: syilex-frontend not found — packing existing public/ as-is"
fi

echo "[2/7] Copying project files..."
cd "$PROJECT_DIR"
for item in app bootstrap config database public resources routes storage vendor \
    .env.example .env.production.example artisan composer.json composer.lock \
    DEPLOY.md INSTALL-SHARED-HOSTING.md README.md package.json; do
    if [ -e "$item" ]; then
        cp -r "$item" "$BUILD_DIR/posip/" 2>/dev/null || true
    fi
done

# Root .htaccess — route document root → public/ (shared hosting tanpa SSH)
cat > "$BUILD_DIR/posip/.htaccess" << 'HTACCESS'
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase /

    DirectorySlash Off

    RewriteCond %{REQUEST_URI} ^/public/ [OR]
    RewriteCond %{REQUEST_URI} ^/public$
    RewriteRule ^ - [L]

    RewriteRule ^(.*)$ public/$1 [L]
</IfModule>
HTACCESS

rm -rf "$BUILD_DIR/posip/.git"
rm -f "$BUILD_DIR/posip/storage/installed"
rm -f "$BUILD_DIR/posip/storage/logs/"*.log
find "$BUILD_DIR/posip/storage/framework" -type f -not -name '.gitkeep' -not -name '.gitignore' -delete 2>/dev/null || true
find "$BUILD_DIR/posip/bootstrap/cache" -name '*.php' -delete 2>/dev/null || true

echo "[3/7] Ensuring vendor/..."
if [ ! -d "$BUILD_DIR/posip/vendor" ]; then
    if [ -d "$PROJECT_DIR/vendor" ]; then
        cp -r "$PROJECT_DIR/vendor" "$BUILD_DIR/posip/vendor"
    else
        echo "ERROR: vendor/ missing. Run: composer install --no-dev"
        exit 1
    fi
fi

echo "[4/7] Creating .env (production defaults + APP_KEY)..."
cp "$BUILD_DIR/posip/.env.example" "$BUILD_DIR/posip/.env"
APP_KEY=$(php -r "echo 'base64:' . base64_encode(random_bytes(32));")
# Portable replace (Git Bash sed -i differs on Windows)
php -r '
$f = $argv[1]; $key = $argv[2];
$c = file_get_contents($f);
$c = preg_replace("/^APP_KEY=.*/m", "APP_KEY=".$key, $c);
$c = preg_replace("/^APP_ENV=.*/m", "APP_ENV=production", $c);
$c = preg_replace("/^APP_DEBUG=.*/m", "APP_DEBUG=false", $c);
file_put_contents($f, $c);
' "$BUILD_DIR/posip/.env" "$APP_KEY"

echo "[5/7] Ensuring storage directories..."
mkdir -p "$BUILD_DIR/posip/storage/app/public"
mkdir -p "$BUILD_DIR/posip/storage/framework/cache/data"
mkdir -p "$BUILD_DIR/posip/storage/framework/sessions"
mkdir -p "$BUILD_DIR/posip/storage/framework/views"
mkdir -p "$BUILD_DIR/posip/storage/logs"
mkdir -p "$BUILD_DIR/posip/bootstrap/cache"

echo "[6/7] Writing INSTALL tutorial..."
cp "$PROJECT_DIR/INSTALL-SHARED-HOSTING.md" "$BUILD_DIR/INSTALL.md"
cat > "$BUILD_DIR/INSTALL.txt" << 'GUIDE'
INSTALASI POSIP — RINGKAS
=========================
1. Extract zip ini
2. Baca INSTALL.md (tutorial lengkap)
3. Upload folder posip/ ke hosting (isi → public_html/ ATAU document root subdomain = posip/public)
4. Buat database MySQL di cPanel
5. Set permission storage/ & bootstrap/cache/ → 775
6. Buka http://domain-anda.com/install
7. Ikuti wizard (8 step) → selesai

Persyaratan: PHP 8.2+, MySQL/MariaDB, ekstensi pdo_mysql mbstring openssl tokenizer xml ctype json bcmath fileinfo gd
GUIDE

echo "[7/7] Creating zip → $OUTPUT ..."
cd "$BUILD_DIR"
rm -f "$OUTPUT"

if command -v cygpath &> /dev/null; then
    WIN_SRC=$(cygpath -w "$BUILD_DIR")
    WIN_OUT=$(cygpath -w "$OUTPUT")
    powershell.exe -NoProfile -Command "Compress-Archive -Path (Join-Path '$WIN_SRC' '*') -DestinationPath '$WIN_OUT' -Force"
elif command -v zip &> /dev/null; then
    zip -r -q "$OUTPUT" INSTALL.md INSTALL.txt posip/
else
    echo "ERROR: Cannot create zip (need zip or PowerShell)."
    exit 1
fi

rm -rf "$BUILD_DIR"

if [ -f "$OUTPUT" ]; then
    SIZE=$(du -h "$OUTPUT" | cut -f1)
    echo ""
    echo "========================================="
    echo "  Build selesai!"
    echo "  Output: $OUTPUT"
    echo "  Size:   $SIZE"
    echo "  Isi:    INSTALL.md + INSTALL.txt + posip/"
    echo "========================================="
else
    echo "ERROR: Zip file was not created."
    exit 1
fi
