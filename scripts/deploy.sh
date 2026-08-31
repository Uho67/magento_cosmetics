#!/usr/bin/env bash
# deploy.sh — build Magento locally and deploy to production VPS
#
# Usage:
#   ./scripts/deploy.sh
#   DEPLOY_HOST=1.2.3.4 LOCALES="en_US uk_UA" ./scripts/deploy.sh
#
# Requirements (local machine):
#   - Docker Desktop running
#   - rsync (built into macOS)
#   - SSH key configured for DEPLOY_HOST
#   - auth.json in project root

set -euo pipefail
IFS=$'\n\t'

# ── Paths ─────────────────────────────────────────────────────────────────────
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)

# ── Configuration ─────────────────────────────────────────────────────────────
# Sourced from scripts/deploy.conf (committed to git).
# Any env var set before running overrides the file — e.g.:
#   DEPLOY_HOST=staging ./scripts/deploy.sh
source "$SCRIPT_DIR/deploy.conf"

BUILD_IMAGE="wardenenv/php-fpm:8.4-magento2"

# ── Colours ───────────────────────────────────────────────────────────────────
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; CYAN='\033[0;36m'; NC='\033[0m'
step()  { echo -e "\n${CYAN}▶ $*${NC}"; }
ok()    { echo -e "${GREEN}  ✓ $*${NC}"; }
warn()  { echo -e "${YELLOW}  ⚠ $*${NC}"; }
die()   { echo -e "${RED}  ✗ $*${NC}" >&2; exit 1; }

# ── Pre-flight checks ─────────────────────────────────────────────────────────
[[ -z "$DEPLOY_HOST" ]] && \
  die "DEPLOY_HOST is not set.\nUsage: DEPLOY_HOST=your-server.com ./scripts/deploy.sh"

[[ ! -f "$PROJECT_ROOT/auth.json" ]] && \
  die "auth.json not found at $PROJECT_ROOT. Copy auth.json.example and fill in your Marketplace keys."

command -v docker  &>/dev/null || die "Docker not found"
command -v rsync   &>/dev/null || die "rsync not found"
command -v ssh     &>/dev/null || die "ssh not found"

echo -e "\n${GREEN}╔══════════════════════════════════════════════╗"
echo    "║  Magento deploy — build $TIMESTAMP"
echo    "║  Server  : ${DEPLOY_USER}@${DEPLOY_HOST}:${DEPLOY_PATH}"
echo    "║  Locales : $LOCALES"
echo -e "╚══════════════════════════════════════════════╝${NC}"

# ── Phase 1: Export clean source from git ─────────────────────────────────────
step "Phase 1/4 — Export clean source from git"

BUILD_DIR=$(mktemp -d)
# Ensure we clean up build dir on exit (even on error)
trap 'echo -e "\n${YELLOW}Cleaning up local build dir...${NC}"; rm -rf "$BUILD_DIR" "$ARCHIVE"' EXIT

git -C "$PROJECT_ROOT" archive HEAD | tar -x -C "$BUILD_DIR"
ok "Exported $(git -C "$PROJECT_ROOT" rev-parse --short HEAD) to $BUILD_DIR"

# ── Phase 2: Build inside isolated Docker container ───────────────────────────
step "Phase 2/4 — Build (composer install + di:compile + static deploy)"
warn "This runs inside a --platform linux/amd64 container. Takes 3-8 min on first run."

docker run --rm \
  --platform linux/amd64 \
  -v "$BUILD_DIR:/app" \
  -v "$HOME/.composer/cache:/root/.composer/cache" \
  -e COMPOSER_AUTH="$(cat "$PROJECT_ROOT/auth.json")" \
  -w /app \
  "$BUILD_IMAGE" bash -lc "
    set -euo pipefail

    echo '--- composer install --no-dev ---'
    composer install --no-dev --no-interaction --optimize-autoloader --prefer-dist

    echo '--- Enable 2FA (disabled by MarkShust in local dev config.php) ---'
    php bin/magento module:enable \
      Magento_TwoFactorAuth Magento_AdminAdobeImsTwoFactorAuth \
      --no-backup 2>/dev/null || true

    echo '--- Disable dev-only 2FA helper ---'
    php bin/magento module:disable \
      MarkShust_DisableTwoFactorAuth \
      --no-backup 2>/dev/null || true

    echo '--- setup:di:compile ---'
    php bin/magento setup:di:compile
  "
ok "Build complete"

# ── Phase 3: Create tarball ───────────────────────────────────────────────────
step "Phase 3/4 — Create and upload artifact"

ARCHIVE="/tmp/build-${TIMESTAMP}.tar.gz"
tar -czf "$ARCHIVE" \
  -C "$BUILD_DIR" \
  --exclude='./var' \
  --exclude='./pub/media' \
  --exclude='./app/etc/env.php' \
  --exclude='./.warden' \
  --exclude='./auth.json' \
  --exclude='./.env' \
  --exclude='./.env.prod' \
  --exclude='./.env.prod.example' \
  --exclude='./.git' \
  --exclude='./scripts' \
  --exclude='./docs' \
  --exclude='./dev/tests' \
  .

ARCHIVE_SIZE=$(du -sh "$ARCHIVE" | cut -f1)
ok "Archive: build-${TIMESTAMP}.tar.gz ($ARCHIVE_SIZE)"

ssh "${DEPLOY_USER}@${DEPLOY_HOST}" "mkdir -p ${DEPLOY_PATH}/incoming ${DEPLOY_PATH}/builds ${DEPLOY_PATH}/shared/{media,logs}"
rsync -az --progress "$ARCHIVE" "${DEPLOY_USER}@${DEPLOY_HOST}:${DEPLOY_PATH}/incoming/"
ok "Uploaded to server"

# ── Phase 4: Remote deploy ────────────────────────────────────────────────────
step "Phase 4/4 — Remote deploy"

# Pass variables into the heredoc explicitly
ARCHIVE_NAME="build-${TIMESTAMP}.tar.gz"
COMPOSE_CMD="docker compose -f ${DEPLOY_PATH}/repo/deploy/docker-compose.prod.yml --env-file ${DEPLOY_PATH}/.env.prod"

ssh "${DEPLOY_USER}@${DEPLOY_HOST}" bash -s -- \
  "$DEPLOY_PATH" "$TIMESTAMP" "$ARCHIVE_NAME" "$COMPOSE_CMD" "$KEEP_BUILDS" "$LOCALES" \
  <<'REMOTE'
set -euo pipefail

DEPLOY_PATH="$1"
TIMESTAMP="$2"
ARCHIVE_NAME="$3"
COMPOSE_CMD="$4"
KEEP_BUILDS="$5"
LOCALES="$6"

NEW="$DEPLOY_PATH/builds/new"
CURRENT="$DEPLOY_PATH/builds/current"
PREVIOUS="$DEPLOY_PATH/builds/previous"
ARCHIVE_STORE="$DEPLOY_PATH/builds/archive"

echo "--- Extracting $ARCHIVE_NAME ---"
rm -rf "$NEW"
mkdir -p "$NEW"
tar -xzf "$DEPLOY_PATH/incoming/$ARCHIVE_NAME" -C "$NEW"
rm "$DEPLOY_PATH/incoming/$ARCHIVE_NAME"

echo "--- Linking shared files into new build ---"
mkdir -p "$NEW/app/etc" "$NEW/var"
cp "$DEPLOY_PATH/shared/env.php" "$NEW/app/etc/env.php"
# pub/media and var/log are mounted directly by Docker from shared/ — no symlink needed

echo "--- Running setup:upgrade against new build ---"
docker run --rm \
  --network legal_internal \
  --platform linux/amd64 \
  -v "$NEW:/var/www/html" \
  -v "$DEPLOY_PATH/shared/media:/var/www/html/pub/media" \
  -v "$DEPLOY_PATH/shared/logs:/var/www/html/var/log" \
  -e MAGE_MODE=production \
  wardenenv/php-fpm:8.4-magento2 \
  php /var/www/html/bin/magento setup:upgrade --keep-generated
echo "setup:upgrade OK"

echo "--- Running setup:static-content:deploy against new build (needs live DB) ---"
docker run --rm \
  --network legal_internal \
  --platform linux/amd64 \
  -v "$NEW:/var/www/html" \
  -v "$DEPLOY_PATH/shared/media:/var/www/html/pub/media" \
  -v "$DEPLOY_PATH/shared/logs:/var/www/html/var/log" \
  -e MAGE_MODE=production \
  wardenenv/php-fpm:8.4-magento2 \
  php /var/www/html/bin/magento setup:static-content:deploy -f $LOCALES --no-interaction
echo "static-content:deploy OK"

echo "--- Archiving current build (for rollback history) ---"
mkdir -p "$ARCHIVE_STORE"
if [ -d "$CURRENT" ]; then
  mv "$CURRENT" "$ARCHIVE_STORE/$TIMESTAMP-prev"
fi

echo "--- Swapping builds (mv = atomic on same filesystem) ---"
mv "$NEW" "$CURRENT"

# Keep PREVIOUS pointer for quick rollback
rm -f "$PREVIOUS"
ln -sfn "$ARCHIVE_STORE/$TIMESTAMP-prev" "$PREVIOUS" 2>/dev/null || true

echo "--- Restarting PHP-FPM + Nginx + Cron (clears OPcache) ---"
eval "$COMPOSE_CMD" restart php-fpm nginx cron

echo "--- Flushing Magento cache ---"
eval "$COMPOSE_CMD" exec -T php-fpm php bin/magento cache:flush

echo "--- Pruning old archive builds (keeping $KEEP_BUILDS) ---"
ls -dt "$ARCHIVE_STORE"/* 2>/dev/null | tail -n "+$((KEEP_BUILDS + 1))" | xargs rm -rf 2>/dev/null || true

echo "--- Deploy $TIMESTAMP complete ---"
REMOTE

ok "Deploy complete!"
echo -e "\n  Build  : $TIMESTAMP"
echo    "  Server : https://${DEPLOY_HOST}"
echo -e "  Rollback: ${YELLOW}./scripts/rollback.sh${NC}\n"
