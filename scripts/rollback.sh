#!/usr/bin/env bash
# rollback.sh — revert to the previous build on the VPS
#
# Usage:
#   ./scripts/rollback.sh
#   DEPLOY_HOST=1.2.3.4 ./scripts/rollback.sh

set -euo pipefail

DEPLOY_USER="${DEPLOY_USER:-deploy}"
DEPLOY_HOST="${DEPLOY_HOST:-}"
DEPLOY_PATH="${DEPLOY_PATH:-/srv/legal}"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; CYAN='\033[0;36m'; NC='\033[0m'
step() { echo -e "\n${CYAN}▶ $*${NC}"; }
ok()   { echo -e "${GREEN}  ✓ $*${NC}"; }
die()  { echo -e "${RED}  ✗ $*${NC}" >&2; exit 1; }

[[ -z "$DEPLOY_HOST" ]] && \
  die "DEPLOY_HOST is not set.\nUsage: DEPLOY_HOST=your-server.com ./scripts/rollback.sh"

echo -e "\n${YELLOW}╔═══════════════════════════════════════╗"
echo    "║  ROLLBACK — reverting to previous build"
echo -e "╚═══════════════════════════════════════╝${NC}"

COMPOSE_CMD="docker compose -f ${DEPLOY_PATH}/repo/deploy/docker-compose.prod.yml --env-file ${DEPLOY_PATH}/.env.prod"

ssh "${DEPLOY_USER}@${DEPLOY_HOST}" bash -s -- "$DEPLOY_PATH" "$COMPOSE_CMD" <<'REMOTE'
set -euo pipefail

DEPLOY_PATH="$1"
COMPOSE_CMD="$2"

CURRENT="$DEPLOY_PATH/builds/current"
PREVIOUS="$DEPLOY_PATH/builds/previous"
ROLLBACK_SAVE="$DEPLOY_PATH/builds/rollback-$(date +%Y%m%d_%H%M%S)"

# Resolve previous symlink to real path
PREV_REAL=$(readlink -f "$PREVIOUS" 2>/dev/null || echo "")

if [[ -z "$PREV_REAL" || ! -d "$PREV_REAL" ]]; then
  echo "No previous build available to roll back to."
  exit 1
fi

echo "--- Saving failed build as $ROLLBACK_SAVE ---"
mv "$CURRENT" "$ROLLBACK_SAVE"

echo "--- Restoring previous build ---"
cp -a "$PREV_REAL" "$CURRENT"

echo "--- Restarting PHP-FPM + Nginx + Cron ---"
eval "$COMPOSE_CMD" restart php-fpm nginx cron

echo "--- Flushing Magento cache ---"
eval "$COMPOSE_CMD" exec -T php-fpm php bin/magento cache:flush

echo "--- Rollback complete ---"
REMOTE

ok "Rollback complete. Site is now running the previous build."
echo -e "  ${YELLOW}Note: If the DB has migrations from the failed deploy, you may need"
echo -e "  to revert them manually. Magento has no automatic schema rollback.${NC}\n"
