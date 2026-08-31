#!/usr/bin/env bash
# rollback-server.sh — revert to the previous build on the VPS
#
# Run as the deploy user on the server:
#   cd /srv/legal/repo && ./scripts/rollback-server.sh

set -euo pipefail

DEPLOY_PATH="/srv/legal"
REPO_DIR="$DEPLOY_PATH/repo"
COMPOSE=(docker compose -f "$REPO_DIR/deploy/docker-compose.prod.yml" --env-file "$DEPLOY_PATH/.env.prod")

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; CYAN='\033[0;36m'; NC='\033[0m'
ok()  { echo -e "${GREEN}  ✓ $*${NC}"; }
die() { echo -e "${RED}  ✗ $*${NC}" >&2; exit 1; }

CURRENT="$DEPLOY_PATH/builds/current"
PREVIOUS="$DEPLOY_PATH/builds/previous"
PREV_REAL=$(readlink -f "$PREVIOUS" 2>/dev/null || echo "")

[[ -z "$PREV_REAL" || ! -d "$PREV_REAL" ]] && die "No previous build to roll back to."

echo -e "\n${YELLOW}Rolling back to: $PREV_REAL${NC}"

ROLLBACK_SAVE="$DEPLOY_PATH/builds/rollback-$(date +%Y%m%d_%H%M%S)"
mv "$CURRENT" "$ROLLBACK_SAVE"
cp -a "$PREV_REAL" "$CURRENT"

"${COMPOSE[@]}" restart php-fpm nginx cron
ok "Containers restarted"

"${COMPOSE[@]}" exec -T php-fpm php bin/magento cache:flush
ok "Cache flushed"

echo -e "\n${GREEN}  Rollback complete.${NC}"
echo -e "  ${YELLOW}Note: DB schema changes from the failed deploy are NOT reverted.${NC}"
