# Local Setup (Warden)

## Prerequisites

| Tool | Min version | Install |
|------|-------------|---------|
| Docker Desktop | 4.x | https://docs.docker.com/desktop/mac/ |
| Warden | latest | `brew install wardenenv/warden/warden` |
| Composer | 2.9+ | `brew install composer` |

---

## First-time machine bootstrap (once per machine)

```bash
warden svc up
warden sign-certificate legal.test
```

`sign-certificate` registers the TLS cert with your system keychain via mkcert
so `https://legal.test` is trusted by the browser without warnings.

---

## Step 1 — Clone and configure auth

```bash
git clone <repo-url> legal
cd legal

# Copy the auth template and fill in your Magento Marketplace keys.
# Keys: https://commercemarketplace.adobe.com/customer/accessKeys/
cp auth.json.example auth.json
# Edit auth.json → public key = "username", private key = "password"
```

> `auth.json` is gitignored and must never be committed.

---

## Step 2 — Start the Warden environment

```bash
warden env up
warden env ps     # all 8 services should show Running
```

Expected services: `php-fpm` (PHP 8.4), `php-debug` (PHP 8.4 + Xdebug 3),
`nginx`, `db` (MariaDB 11.4), `opensearch` (2.19), `redis` (Valkey 8),
`varnish`, `rabbitmq`.

---

## Step 3 — Install Magento + extensions via Composer

```bash
warden shell
```

Inside the container:

```bash
# Install Composer auth credentials globally
mkdir -p ~/.composer
cp /var/www/html/auth.json ~/.composer/auth.json

# Download Magento Open Source into a temp dir (vendor already installed)
composer create-project \
  --repository-url=https://repo.magento.com/ \
  magento/project-community-edition=^2.4.8 \
  /tmp/m2src

# Sync into web root (preserves .warden/, docs/, etc.)
rsync -a --exclude='.warden' --exclude='auth.json*' \
         --exclude='docs' --exclude='.git' \
         --exclude='.env' --exclude='.gitignore' \
         /tmp/m2src/. /var/www/html/

# Add 2FA disabler as --dev dependency (excluded from prod via composer install --no-dev)
composer require --dev markshust/magento2-module-disabletwofactorauth

# Declare sample data packages, then install them
bin/magento sampledata:deploy
composer update
```

---

## Step 4 — Run setup:install

> **Magento 2.4.9 notes:**
> - `--page-cache=varnish` was removed; Varnish is now wired via `--http-cache-hosts`
> - Native `--session-save=valkey` and `--cache-backend=valkey` flags are used
>   (Magento 2.4.9 has first-class Valkey support)

Still inside `warden shell`:

```bash
bin/magento setup:install \
  --base-url="https://legal.test/" \
  --base-url-secure="https://legal.test/" \
  \
  --db-host="db" \
  --db-name="magento" \
  --db-user="magento" \
  --db-password="magento" \
  \
  --search-engine="opensearch" \
  --opensearch-host="opensearch" \
  --opensearch-port="9200" \
  --opensearch-index-prefix="magento2" \
  \
  --cache-backend="valkey" \
  --cache-backend-valkey-server="redis" \
  --cache-backend-valkey-db="0" \
  \
  --page-cache="valkey" \
  --page-cache-valkey-server="redis" \
  --page-cache-valkey-db="1" \
  \
  --session-save="valkey" \
  --session-save-valkey-host="redis" \
  --session-save-valkey-db="2" \
  \
  --http-cache-hosts="varnish:6081" \
  \
  --amqp-host="rabbitmq" \
  --amqp-port="5672" \
  --amqp-user="guest" \
  --amqp-password="guest" \
  --amqp-virtualhost="/" \
  \
  --backend-frontname="admin" \
  --admin-firstname="Admin" \
  --admin-lastname="User" \
  --admin-email="admin@legal.test" \
  --admin-user="enrole" \
  --admin-password="Enrole_12_nji" \
  \
  --language="en_US" \
  --currency="USD" \
  --timezone="Europe/Kyiv" \
  --use-rewrites="1" \
  --use-secure="1" \
  --use-secure-admin="1"
```

---

## Step 5 — Enable 2FA disabler, run upgrades, build assets

```bash
bin/magento module:enable MarkShust_DisableTwoFactorAuth
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy -f en_US
bin/magento indexer:reindex
bin/magento cache:flush
```

> **Production note:** The production Dockerfile disables `MarkShust_DisableTwoFactorAuth`
> after `composer install --no-dev` so it never reaches the server (see `docs/DEPLOY.md`).

---

## Verify

| What | URL |
|------|-----|
| Storefront | https://legal.test/ |
| Admin (no 2FA) | https://legal.test/admin — `enrole` / `Enrole_12_nji` |
| RabbitMQ UI | http://rabbitmq.legal.test:15672 — `guest` / `guest` |
| OpenSearch | http://opensearch.legal.test:9200 |

---

## Logs

PHP and Magento logs are bind-mounted to your host:

| Container | Host path | Contents |
|-----------|-----------|----------|
| `php-fpm` | `var/log/php-fpm/` | `exception.log`, `system.log`, `php-errors.log` |
| `php-debug` | `var/log/php-debug/` | same files from Xdebug sessions |

Stream live:
```bash
tail -f var/log/php-fpm/exception.log
tail -f var/log/php-fpm/system.log
```

---

## Day-to-day commands

```bash
warden env up                   # start all services
warden env down                 # stop all services
warden shell                    # php-fpm container shell
warden debug                    # php-debug shell (Xdebug port 9003)
warden env logs -f php-fpm      # stream php-fpm container logs
warden env logs -f db           # stream MariaDB logs
```

---

## Xdebug

IDE server name: `legal.test` (configured in `.warden/warden-env.yml`).
Listen on port **9003**. Use `warden debug` to open a shell in the Xdebug container.
