#!/bin/bash
set -euo pipefail
REPO=/root/POSIP-DIST
WEB=/home/bisnis/web/pos.siapngeweb.com/public_html

cd "$REPO"
git fetch origin
git reset --hard origin/main

rsync -a --delete \
  --exclude=.env \
  --exclude=.env.backup \
  --exclude=storage/app/ \
  --exclude=storage/installed \
  --exclude=storage/logs/ \
  --exclude=storage/framework/cache/data/ \
  --exclude=storage/framework/sessions/ \
  --exclude=storage/framework/views/ \
  --exclude=storage/pail/ \
  --exclude=public/storage \
  --exclude=.git/ \
  "$REPO/" "$WEB/"

mkdir -p \
  "$WEB/storage/logs" \
  "$WEB/storage/framework/cache/data" \
  "$WEB/storage/framework/sessions" \
  "$WEB/storage/framework/views" \
  "$WEB/bootstrap/cache"

chown -R bisnis:bisnis "$WEB"
chmod -R ug+rwx "$WEB/storage" "$WEB/bootstrap/cache"

cd "$WEB"
sudo -u bisnis php artisan migrate --force
sudo -u bisnis php artisan optimize:clear
sudo -u bisnis php artisan config:cache
sudo -u bisnis php artisan route:cache

echo "Updated to $(git -C "$REPO" rev-parse --short HEAD)"
