#!/bin/bash
# ===================================================================
# juntraweb / แม่หมอจันทรา — Server-side Deploy Script
# Pattern adapted from Thaiprompt-Affiliate (xjanova/Thaiprompt-Affiliate)
# Run on the DirectAdmin server at /home/admin/domains/<domain>/public_html
# ===================================================================
set -uo pipefail
set -o errtrace

BRANCH="${1:-main}"
LOG=storage/logs/deployment.log
mkdir -p storage/logs
echo "" >> "$LOG"
echo "============================================================" >> "$LOG"
echo "[$(date -u +'%Y-%m-%d %H:%M:%S UTC')] Deploy start (branch: $BRANCH)" >> "$LOG"

log() { echo "$1" | tee -a "$LOG"; }
warn() { echo "⚠️ $1" | tee -a "$LOG"; }
fail() { echo "❌ $1" | tee -a "$LOG"; exit 1; }

# ---------- 0. Emergency disk space check ----------
AVAIL_KB=$(df . | awk 'NR==2 {print $4}')
if [ "${AVAIL_KB:-0}" -lt 102400 ]; then
  warn "Low disk space (${AVAIL_KB}KB) — running emergency cleanup"
  rm -rf storage/logs/*.log.* 2>/dev/null || true
  rm -rf storage/framework/cache/data/* 2>/dev/null || true
  rm -rf storage/framework/views/* 2>/dev/null || true
fi

# ---------- 1. Maintenance mode ----------
log "🔧 Enabling maintenance mode..."
php artisan down --render="errors::503" --secret="chantra-deploy" 2>/dev/null || true

# ---------- 2. Backup .env + uploads ----------
log "💾 Backing up .env and storage/app/public..."
mkdir -p .deploy-backup
cp .env .deploy-backup/.env.$(date +%s) 2>/dev/null || true
[ -d storage/app/public ] && tar -czf .deploy-backup/uploads-$(date +%s).tar.gz storage/app/public 2>/dev/null || true
ls -t .deploy-backup/.env.* 2>/dev/null | tail -n +6 | xargs rm -f 2>/dev/null || true
ls -t .deploy-backup/uploads-*.tar.gz 2>/dev/null | tail -n +4 | xargs rm -f 2>/dev/null || true

# ---------- 3. Git sync ----------
log "📥 Syncing code from origin/$BRANCH..."
git fetch --all --prune 2>&1 | tee -a "$LOG"
git stash -u 2>/dev/null || true
git reset --hard "origin/$BRANCH" 2>&1 | tee -a "$LOG" || fail "git reset failed"
git clean -fd -- 'storage/framework/' 'bootstrap/cache/' 2>/dev/null || true

# ---------- 4. Composer install ----------
log "📦 Installing composer dependencies..."
COMPOSER_PROCESS_TIMEOUT=1200 composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist 2>&1 | tee -a "$LOG"

# ---------- 5. NPM build ----------
if [ -f package.json ]; then
  log "🎨 Building frontend assets..."
  npm ci --no-audit --no-fund 2>&1 | tee -a "$LOG" || warn "npm ci failed"
  npm run build 2>&1 | tee -a "$LOG" || warn "npm build failed"
fi

# ---------- 6. App key + storage link ----------
if ! grep -q "^APP_KEY=base64:" .env 2>/dev/null; then
  log "🔑 Generating APP_KEY..."
  php artisan key:generate --force
fi
[ -L public/storage ] || php artisan storage:link 2>&1 | tee -a "$LOG"

# ---------- 7. Migrations ----------
log "🗄️ Running migrations..."
php artisan migrate --force 2>&1 | tee -a "$LOG" || warn "migration warnings"

# ---------- 8. Cache regeneration ----------
log "♻️ Regenerating caches..."
php artisan config:clear 2>&1 | tee -a "$LOG"
php artisan route:clear 2>&1 | tee -a "$LOG"
php artisan view:clear 2>&1 | tee -a "$LOG"
php artisan config:cache 2>&1 | tee -a "$LOG"
php artisan route:cache 2>&1 | tee -a "$LOG"
php artisan view:cache 2>&1 | tee -a "$LOG"

# ---------- 9. Filament asset publish ----------
php artisan filament:upgrade 2>&1 | tee -a "$LOG" || true

# ---------- 10. Seed safe data (idempotent updateOrCreate) ----------
log "🌱 Running idempotent seeders..."
php artisan db:seed --class=ZodiacSeeder --force 2>&1 | tee -a "$LOG" || warn "ZodiacSeeder skipped"
php artisan db:seed --class=TarotCardSeeder --force 2>&1 | tee -a "$LOG" || warn "TarotCardSeeder skipped"
php artisan db:seed --class=SettingSeeder --force 2>&1 | tee -a "$LOG" || warn "SettingSeeder skipped"
php artisan db:seed --class=TestimonialSeeder --force 2>&1 | tee -a "$LOG" || warn "TestimonialSeeder skipped"

# ---------- 11. Permissions ----------
log "🔐 Fixing permissions..."
chmod -R 755 storage bootstrap/cache 2>/dev/null || true
chmod -R 775 storage/logs storage/framework storage/app 2>/dev/null || true
find storage -type d -exec chmod 755 {} \; 2>/dev/null || true
find storage -type f -exec chmod 644 {} \; 2>/dev/null || true

# ---------- 12. Queue restart ----------
php artisan queue:restart 2>&1 | tee -a "$LOG" || true

# ---------- 13. Disable maintenance ----------
log "🌐 Disabling maintenance mode..."
php artisan up 2>&1 | tee -a "$LOG"

# ---------- 14. Health check ----------
APP_URL=$(grep -E "^APP_URL=" .env | cut -d= -f2- | tr -d '"')
if [ -n "$APP_URL" ]; then
  CODE=$(curl -s -o /dev/null -w "%{http_code}" -L "$APP_URL" --max-time 15 || echo "000")
  if [ "$CODE" = "200" ] || [ "$CODE" = "302" ]; then
    log "✅ Health check OK (HTTP $CODE)"
  else
    warn "Health check returned HTTP $CODE"
  fi
fi

log "🎉 Deployment finished successfully ($(date -u +'%Y-%m-%d %H:%M:%S UTC'))"
echo "Latest commit: $(git log -1 --pretty=format:'%h %s')" | tee -a "$LOG"
