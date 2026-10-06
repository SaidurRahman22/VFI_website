#!/usr/bin/env bash
# =============================================================================
# VFI production deploy — the git-sync script run by cron on the app server.
#
# THE LIVE COPY IS /usr/local/bin/vfi-deploy.sh. This file is the record of it.
# Until Sep 2026 it existed ONLY on the server, which meant the one procedure
# that puts code into production was not in version control and not reviewable.
# Change this file, then copy it over:
#
#     sudo cp /var/www/vfi/deploy/vfi-deploy.sh /usr/local/bin/vfi-deploy.sh
#     sudo chmod 755 /usr/local/bin/vfi-deploy.sh
#     bash -n /usr/local/bin/vfi-deploy.sh     # syntax only, changes nothing
#
# There is no Node on the server, so the admin console is built locally and its
# output committed (see tools/build-admin-panel.mjs). This script never builds
# frontend assets.
# =============================================================================
set -e
APP=/var/www/vfi
LOG=/var/log/vfi-deploy.log
cd "$APP"

# Filament's published assets are TRACKED by git (41 files under
# backend/public), and `composer install` below re-publishes them every single
# deploy, because backend/composer.json runs `php artisan filament:upgrade` from
# post-autoload-dump. While Filament's version is unchanged the republished
# bytes are identical and git stays clean — but the first deploy after any
# filament/* version moves leaves the working tree DIRTY, and the next
# `git pull --ff-only` then refuses. With `set -e` above, that aborts the
# deploy, and it keeps aborting: the site silently stops receiving updates and
# nothing says why.
#
# Discarding them before the pull is safe in both directions. They are build
# output, never edited on the server; the pull restores whatever the commit
# holds, and composer install re-publishes them immediately afterwards.
git checkout -- backend/public 2>/dev/null || true

git fetch origin main --quiet
LOCAL=$(git rev-parse HEAD); REMOTE=$(git rev-parse origin/main)
[ "$LOCAL" = "$REMOTE" ] && exit 0
echo "$(date -Is) new commit $REMOTE — deploying" >> "$LOG"
git pull --ff-only origin main >> "$LOG" 2>&1
cd "$APP/backend"
export COMPOSER_ALLOW_SUPERUSER=1
composer install --no-dev --optimize-autoloader --no-interaction --quiet >> "$LOG" 2>&1
php artisan migrate --force >> "$LOG" 2>&1

# Uploaded images live on the `public` disk and are served at /storage/media/…,
# which only resolves through backend/public/storage. That symlink is gitignored
# (correctly — it points at an absolute path), so nothing in a fresh checkout
# creates it, and for as long as it was missing EVERY image an admin uploaded
# returned 201 and then 404 when the page tried to show it. --force makes this
# idempotent, so it is safe on every run.
php artisan storage:link --force >> "$LOG" 2>&1

# Drop every compiled cache BEFORE rebuilding it. This matters most when a
# deploy DELETES a class: Filament caches its discovered panel components to
# bootstrap/cache/filament/panels/admin.php, and on boot it restores the
# component list from that file and static-calls each class. A pull that removes
# a resource therefore takes down far more than the page it belonged to - route
# registration itself fails, so `route:cache` below dies and php-fpm serves 500s
# for the whole site. There is no such cache on the server today (checked), and
# this line is here so that stays true after any `php artisan optimize`.
php artisan optimize:clear >> "$LOG" 2>&1

php artisan config:cache >> "$LOG" 2>&1
php artisan route:cache >> "$LOG" 2>&1
chown -R www-data:www-data "$APP"
find "$APP/backend/storage" -type d -exec chmod 775 {} \; 2>/dev/null || true
chmod -R 775 "$APP/backend/bootstrap/cache"
systemctl reload php8.4-fpm >> "$LOG" 2>&1
php artisan queue:restart >> "$LOG" 2>&1 || true
echo "$(date -Is) deployed $REMOTE" >> "$LOG"
