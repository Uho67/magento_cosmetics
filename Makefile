# Makefile — common tasks for the Magento / Warden dev environment
# Run `make help` to list all targets.

.DEFAULT_GOAL := help
.PHONY: help up down restart shell shell-root logs \
        install composer-install di-compile static-deploy \
        cache-flush cache-clean setup-upgrade \
        deploy rollback

# ── Warden helpers ────────────────────────────────────────────────────────────

up:                  ## Start Warden environment (warden env up)
	warden env up

down:                ## Stop Warden environment
	warden env down

restart:             ## Restart php-fpm (clears OPcache in dev)
	warden env restart php-fpm php-debug

shell:               ## Open a shell as www-data in php-fpm
	warden shell

shell-root:          ## Open a shell as root in php-fpm
	warden shell --root

logs:                ## Tail php-fpm logs (var/log/php-fpm/)
	tail -f var/log/php-fpm/php-errors.log 2>/dev/null || \
	  echo "No log yet — trigger a request first."

# ── Magento commands (run inside Warden php-fpm) ──────────────────────────────

install:             ## Run setup:install from scratch (dev, uses docs/LOCAL_SETUP.md values)
	@echo "⚠  This will wipe the Magento database. Press Ctrl-C to abort, Enter to continue."; read _
	warden env exec php-fpm php bin/magento setup:install \
	  --base-url="https://legal.test/" \
	  --cache-backend="valkey" \
	  --cache-backend-valkey-server="redis" \
	  --cache-backend-valkey-db="0" \
	  --page-cache="valkey" \
	  --page-cache-valkey-server="redis" \
	  --page-cache-valkey-db="1" \
	  --session-save="valkey" \
	  --session-save-valkey-host="redis" \
	  --session-save-valkey-db="2" \
	  --http-cache-hosts="varnish:6081" \
	  --db-host="db" \
	  --db-name="magento" \
	  --db-user="magento" \
	  --db-password="magento" \
	  --search-engine="opensearch" \
	  --opensearch-host="opensearch" \
	  --opensearch-port="9200" \
	  --amqp-host="rabbitmq" \
	  --amqp-port="5672" \
	  --amqp-user="magento" \
	  --amqp-password="magento" \
	  --admin-firstname="Admin" \
	  --admin-lastname="User" \
	  --admin-email="admin@example.com" \
	  --admin-user="enrole" \
	  --admin-password="Enrole_12_nji" \
	  --backend-frontname="admin" \
	  --language="en_US" \
	  --currency="USD" \
	  --timezone="Europe/Kyiv" \
	  --use-rewrites="1"

composer-install:    ## Run composer install inside the container
	warden env exec php-fpm composer install

di-compile:          ## Run setup:di:compile
	warden env exec php-fpm php bin/magento setup:di:compile

static-deploy:       ## Deploy static content for en_US
	warden env exec php-fpm php bin/magento setup:static-content:deploy -f en_US

cache-flush:         ## Flush all Magento caches
	warden env exec php-fpm php bin/magento cache:flush

cache-clean:         ## Clean expired caches
	warden env exec php-fpm php bin/magento cache:clean

setup-upgrade:       ## Run setup:upgrade (after module changes)
	warden env exec php-fpm php bin/magento setup:upgrade --keep-generated

# ── Deploy / rollback ─────────────────────────────────────────────────────────

deploy:              ## Build locally and deploy to production VPS
	./scripts/deploy.sh

rollback:            ## Revert production to the previous build
	./scripts/rollback.sh

# ── Help ──────────────────────────────────────────────────────────────────────

help:
	@awk 'BEGIN {FS = ":.*##"; printf "\nUsage:\n  make \033[36m<target>\033[0m\n\nTargets:\n"} \
	  /^[a-zA-Z_-]+:.*?##/ { printf "  \033[36m%-20s\033[0m %s\n", $$1, $$2 }' $(MAKEFILE_LIST)
	@echo ""
