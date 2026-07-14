#!/bin/bash
# Deploy frontend build to Laravel public folder
# Usage: bash deploy.sh

set -e

FRONTEND_DIR="$(cd "$(dirname "$0")" && pwd)"
BACKEND_PUBLIC="$FRONTEND_DIR/../syilex/public"

echo "Building frontend..."
cd "$FRONTEND_DIR"
npm run build

echo "Cleaning old assets..."
rm -rf "$BACKEND_PUBLIC/assets/"

echo "Deploying to $BACKEND_PUBLIC..."
cp -r "$FRONTEND_DIR/dist/"* "$BACKEND_PUBLIC/"

FILE_COUNT=$(ls "$BACKEND_PUBLIC/assets/"*.js 2>/dev/null | wc -l)
FOLDER_SIZE=$(du -sh "$BACKEND_PUBLIC/assets/" | cut -f1)
echo "Done! $FILE_COUNT JS files ($FOLDER_SIZE)"
