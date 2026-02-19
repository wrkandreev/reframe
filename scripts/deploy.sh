#!/usr/bin/env bash
set -euo pipefail

# Timeweb shared hosting deploy script
# Usage:
#   bash scripts/deploy.sh
# Optional env:
#   APP_DIR=/home/USER/www/photo-gallery
#   BRANCH=main

APP_DIR="${APP_DIR:-$(cd "$(dirname "$0")/.." && pwd)}"
BRANCH="${BRANCH:-main}"

cd "$APP_DIR"

echo "[deploy] dir: $APP_DIR"
echo "[deploy] branch: $BRANCH"

if [ ! -d .git ]; then
  echo "[deploy] ERROR: .git not found in $APP_DIR"
  exit 1
fi

# Keep runtime dirs
mkdir -p photos thumbs data

# Protect user-uploaded photos from direct HTTP access (Apache)
if [ -f photos/.htaccess ]; then
  :
else
  cat > photos/.htaccess <<'HTACCESS'
Require all denied
HTACCESS
fi

# Update code
current_branch="$(git rev-parse --abbrev-ref HEAD)"
if [ "$current_branch" != "$BRANCH" ]; then
  git checkout "$BRANCH"
fi

git fetch --all --prune
git reset --hard "origin/$BRANCH"

# Make sure runtime files exist
[ -f data/last_indexed.txt ] || echo "0" > data/last_indexed.txt

echo "[deploy] done"
