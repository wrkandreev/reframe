#!/usr/bin/env bash
set -euo pipefail

# Timeweb shared hosting deploy script
# Usage:
#   bash scripts/deploy.sh
# Optional env:
#   APP_DIR=/home/USER/www/photo-gallery
#   REMOTE_NAME=origin
#   REMOTE_URL=git@github.com:wrkandreev/reframe.git
#   BRANCH=main
#   PHP_BIN=php

APP_DIR="${APP_DIR:-$(cd "$(dirname "$0")/.." && pwd)}"
REMOTE_NAME="${REMOTE_NAME:-origin}"
REMOTE_URL="${REMOTE_URL:-}"
BRANCH="${BRANCH:-main}"
PHP_BIN="${PHP_BIN:-php}"

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

if [ -n "$REMOTE_URL" ]; then
  if git remote get-url "$REMOTE_NAME" >/dev/null 2>&1; then
    git remote set-url "$REMOTE_NAME" "$REMOTE_URL"
  else
    git remote add "$REMOTE_NAME" "$REMOTE_URL"
  fi
fi

git fetch "$REMOTE_NAME" "$BRANCH" --prune
git reset --hard "$REMOTE_NAME/$BRANCH"

# Run DB migrations required by current code
"$PHP_BIN" scripts/migrate.php

# Make sure runtime files exist
[ -f data/last_indexed.txt ] || echo "0" > data/last_indexed.txt

echo "[deploy] done"
