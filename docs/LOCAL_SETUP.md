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
```

Verify all containers are healthy:

```bash
warden env ps
```

Expected services: `php-fpm`, `php-debug`, `nginx`, `db` (MariaDB 11.4),
`opensearch` (2.19), `redis` (Valkey 8), `varnish`, `rabbitmq`.

---

## Step 3 — Install Magento + extensions via Composer

```bash
warden shell
```

Inside the container shell:

```bash
# 1. Download Magento Open Source 2.4.8 into a temp dir and install vendor
composer create-project \
  --repository-url=https://repo.magento.com/ \
  magento/project-community-edition=^2.4.8 \
  /tmp/m2src

# 2. Sync into the web root (preserves .warden/, docs/, etc. already there)
rsync -a /tmp/m2src/. .

# 3. Add the local-dev-only 2FA disabler as a --dev dependency.
#    Production runs `composer install --no-dev`, so this module is
#    never present on the server — no code, no risk.
composer require --dev markshust/magento2-module-disabletwofactorauth

# 4. Declare sample data packages (updates composer.json; needs vendor present)
bin/magento sampledata:deploy

# 5. Install the newly declared sample data packages
composer update
```

---

## Step 4 — Run setup:install

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
  --cache-backend="redis" \
  --cache-backend-redis-server="redis" \
  --cache-backend-redis-db="0" \
  \
  --session-save="redis" \
  --session-save-redis-host="redis" \
  --session-save-redis-db="2" \
  \
  --page-cache="varnish" \
  --page-cache-varnish-access-list="varnish" \
  --page-cache-varnish-backend="nginx" \
  --page-cache-varnish-backend-port="80" \
  --page-cache-varnish-port="6081" \
  --page-cache-varnish-ttl="604800" \
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

> These credentials are **local dev only**. Production credentials come from
> `.env.prod` (gitignored — see `docs/DEPLOY.md`).

---

## Step 5 — Enable 2FA disabler, run upgrades, build assets

```bash
# Enable the local-only 2FA disabler module.
# NOTE: this writes to app/etc/config.php. The production Dockerfile
# explicitly removes this entry before the image is built (see DEPLOY.md).
bin/magento module:enable MarkShust_DisableTwoFactorAuth

# Apply all pending schema/data scripts (Magento core + sample data + above module)
bin/magento setup:upgrade

bin/magento setup:di:compile
bin/magento setup:static-content:deploy -f en_US
bin/magento indexer:reindex
bin/magento cache:flush
```

Sample data indexing takes 2–5 minutes.

---

## Verify

| What | URL |
|------|-----|
| Storefront | https://legal.test/ |
| Admin (no 2FA) | https://legal.test/admin — `enrole` / `Enrole_12_nji` |
| RabbitMQ Management UI | http://rabbitmq.legal.test:15672 — `guest` / `guest` |
| OpenSearch | http://opensearch.legal.test:9200 |

---

## Day-to-day commands

```bash
warden env up                   # start all services
warden env down                 # stop all services
warden shell                    # php-fpm container shell
warden debug                    # php-debug shell (Xdebug enabled, port 9003)
warden env logs -f              # stream all container logs
warden env logs -f php-fpm      # php-fpm only
warden env logs -f db           # MariaDB only
```

---

## Xdebug

IDE server name is `legal.test` (set in `.warden/warden-env.yml`).
Listen on port **9003** in your IDE. Use `warden debug` to open a shell in the
Xdebug-enabled `php-debug` container.
