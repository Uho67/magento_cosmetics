#!/usr/bin/env bash
# refresh-local-db.sh — pull a fresh DB dump from production and rebuild the
# local Warden database from it, then repoint all base URLs at legal.test.
#
# Usage:
#   ./scripts/refresh-local-db.sh
#   ./scripts/refresh-local-db.sh --yes          # skip the confirmation prompt
#   SSH_HOST=magento ./scripts/refresh-local-db.sh
#
# Requirements (local machine):
#   - `ssh magento` configured in ~/.ssh/config (root@ the production VPS)
#   - Warden running for this project (`warden env up`)
#
# What it does:
#   1. SSH to production, dump the live DB from inside the running `db`
#      container (mariadb-dump --single-transaction), stream it back
#      compressed over the same SSH connection — nothing is left on the server.
#   2. Drop and recreate the local database.
#   3. Import the downloaded dump via `warden db import`.
#   4. Rewrite every base_url / base_link_url row in core_config_data to
#      https://legal.test/ (all scopes — default, website, store view).
#   5. Enable "Add Store Code to Urls" (web/url/use_store), so individual
#      store views are reachable locally as https://legal.test/<store_code>/
#      (e.g. /pr_ua/) — production dumps come in with this off.
#   6. setup:upgrade, cache:flush, indexer:reindex.

set -euo pipefail
IFS=$'\n\t'

# ── Paths ─────────────────────────────────────────────────────────────────────
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)

# ── Configuration (override via env var) ─────────────────────────────────────
SSH_HOST="${SSH_HOST:-magento}"
REMOTE_REPO="${REMOTE_REPO:-/srv/legal/repo}"
REMOTE_ENV_FILE="${REMOTE_ENV_FILE:-/srv/legal/.env.prod}"
LOCAL_BASE_URL="${LOCAL_BASE_URL:-https://legal.test/}"
KEEP_BACKUPS="${KEEP_BACKUPS:-5}"

BACKUP_DIR="$PROJECT_ROOT/var/backups/db"
DUMP_FILE="$BACKUP_DIR/prod-${TIMESTAMP}.sql.gz"

AUTO_YES=0
[[ "${1:-}" == "--yes" || "${1:-}" == "-y" ]] && AUTO_YES=1

# ── Colours ───────────────────────────────────────────────────────────────────
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; CYAN='\033[0;36m'; NC='\033[0m'
step()  { echo -e "\n${CYAN}▶ $*${NC}"; }
ok()    { echo -e "${GREEN}  ✓ $*${NC}"; }
warn()  { echo -e "${YELLOW}  ⚠ $*${NC}"; }
die()   { echo -e "${RED}  ✗ $*${NC}" >&2; exit 1; }

# ── Pre-flight checks ─────────────────────────────────────────────────────────
command -v ssh    &>/dev/null || die "ssh not found"
command -v warden &>/dev/null || die "warden not found — run this from a machine with Warden installed"
command -v gzip   &>/dev/null || die "gzip not found"

[[ -f "$PROJECT_ROOT/app/etc/env.php" ]] || die "app/etc/env.php not found — is the local Magento installed?"

LOCAL_DB_NAME=$(sed -n "s/.*'dbname' => '\([^']*\)'.*/\1/p" "$PROJECT_ROOT/app/etc/env.php" | head -1)
[[ -n "$LOCAL_DB_NAME" ]] || die "Could not read local db name from app/etc/env.php"

mkdir -p "$BACKUP_DIR"

echo -e "\n${GREEN}╔══════════════════════════════════════════════╗"
echo    "║  Refresh local DB from production"
echo    "║  SSH host   : $SSH_HOST"
echo    "║  Local DB   : $LOCAL_DB_NAME (will be DROPPED and recreated)"
echo    "║  Dump file  : $DUMP_FILE"
echo    "║  Base URL   → $LOCAL_BASE_URL"
echo -e "╚══════════════════════════════════════════════╝${NC}"

if [[ "$AUTO_YES" -ne 1 ]]; then
  read -r -p "This will DESTROY the local '${LOCAL_DB_NAME}' database and replace it with a copy of production. Continue? [y/N] " REPLY
  [[ "$REPLY" =~ ^[Yy]$ ]] || die "Aborted."
fi

# ── Step 1: Dump the production DB and stream it back over SSH ───────────────
step "Step 1/5 — Dumping production database over SSH ($SSH_HOST)"

ssh "$SSH_HOST" bash -s -- "$REMOTE_REPO" "$REMOTE_ENV_FILE" <<'REMOTE' | gzip > "$DUMP_FILE"
set -euo pipefail
REMOTE_REPO="$1"
REMOTE_ENV_FILE="$2"

set -a
source "$REMOTE_ENV_FILE"
set +a

COMPOSE="docker compose -f ${REMOTE_REPO}/deploy/docker-compose.prod.yml --env-file ${REMOTE_ENV_FILE}"

$COMPOSE exec -T db mariadb-dump \
  -uroot -p"${DB_ROOT_PASSWORD}" \
  --single-transaction --quick --routines --triggers \
  "${DB_NAME}"
REMOTE

[[ -s "$DUMP_FILE" ]] || die "Dump is empty — check SSH access / docker compose state on $SSH_HOST"
ok "Dumped $(du -h "$DUMP_FILE" | cut -f1) → $DUMP_FILE"

# ── Step 2: Reset the local database ──────────────────────────────────────────
step "Step 2/5 — Dropping and recreating local database '$LOCAL_DB_NAME'"

warden db connect -e "DROP DATABASE IF EXISTS \`${LOCAL_DB_NAME}\`; CREATE DATABASE \`${LOCAL_DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
ok "Local database reset"

# ── Step 3: Import the dump ───────────────────────────────────────────────────
step "Step 3/5 — Importing dump into local database"

gunzip -c "$DUMP_FILE" | warden db import
ok "Import complete"

# ── Step 4: Point every store scope at the local domain ──────────────────────
step "Step 4/5 — Rewriting base URLs to $LOCAL_BASE_URL"

warden db connect -e "
  UPDATE core_config_data
  SET value = '${LOCAL_BASE_URL}'
  WHERE path IN (
    'web/unsecure/base_url',
    'web/secure/base_url',
    'web/unsecure/base_link_url',
    'web/secure/base_link_url'
  );
"
ok "Base URLs updated (all scopes)"

# ── Step 5: Enable store codes in URLs (default scope, applies to all stores) ─
step "Step 5/6 — Enabling 'Add Store Code to Urls' (web/url/use_store)"

warden db connect -e "
  INSERT INTO core_config_data (scope, scope_id, path, value)
  VALUES ('default', 0, 'web/url/use_store', '1')
  ON DUPLICATE KEY UPDATE value = '1';
"
ok "Store views reachable locally, e.g. https://legal.test/pr_ua/"

# ── Step 6: Bring the app up to date with the imported data ──────────────────
step "Step 6/6 — setup:upgrade, cache:flush, indexer:reindex"

warden env exec -T php-fpm bin/magento setup:upgrade --keep-generated
warden env exec -T php-fpm bin/magento cache:flush
warden env exec -T php-fpm bin/magento indexer:reindex
ok "Application refreshed"

# ── Prune old local backups ────────────────────────────────────────────────────
ls -dt "$BACKUP_DIR"/*.sql.gz 2>/dev/null | tail -n "+$((KEEP_BACKUPS + 1))" | xargs rm -f 2>/dev/null || true

echo -e "\n${GREEN}✓ Local DB refreshed from production. Visit https://legal.test/${NC}\n"
