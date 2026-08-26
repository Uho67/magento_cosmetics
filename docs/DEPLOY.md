# Production Deployment Runbook

## Architecture overview

```
Browser
  └─▶ Server nginx :443  (host machine, outside Docker — SSL termination, your certs)
        └─▶ Docker Varnish  127.0.0.1:6081  (full-page HTTP cache)
              └─▶ Docker Nginx :80  (static files + FastCGI proxy)
                    └─▶ Docker PHP-FPM :9000  (Magento application)

MariaDB, OpenSearch, Valkey, RabbitMQ — internal Docker network, no public ports.
```

**Multi-store / multi-domain:** each domain gets its own `server {}` block in the
host nginx, all proxying to the same `127.0.0.1:6081`. Magento routes by `Host`
header to the correct website/store based on its Base URL configuration.

## OS assumption: Ubuntu 24.04 LTS

---

## Part 1 — First-time server provisioning

### 1.1 Install nginx + Docker

```bash
# nginx (host-level reverse proxy)
apt-get update
apt-get install -y nginx

# Remove old Docker versions
apt-get remove -y docker docker-engine docker.io containerd runc 2>/dev/null || true

# Install Docker Engine
curl -fsSL https://get.docker.com | sh
systemctl enable --now docker

# Verify
nginx -v
docker --version
docker compose version    # must be 2.x (Compose plugin, not legacy docker-compose)
```

### 1.2 Configure firewall

```bash
ufw allow OpenSSH
ufw allow 80/tcp
ufw allow 443/tcp
ufw enable
ufw status
# Port 6081 (Varnish) is NOT opened — it binds to 127.0.0.1 only.
```

### 1.3 Create deploy user (recommended)

```bash
adduser deploy
usermod -aG docker deploy
# Add your SSH public key to /home/deploy/.ssh/authorized_keys
```

### 1.4 Install SSL certificates

Place your certificate files on the server. Example paths:
```
/etc/ssl/certs/yourdomain.com.crt
/etc/ssl/private/yourdomain.com.key
```

If using Certbot:
```bash
apt-get install -y certbot python3-certbot-nginx
certbot --nginx -d yourdomain.com -d www.yourdomain.com
```

### 1.5 Configure host nginx

```bash
# Install shared snippets (TLS settings + proxy block)
cp /srv/legal/deploy/nginx-host/snippets/*.snippet /etc/nginx/snippets/

# Create a vhost for each domain (copy and edit the example)
cp /srv/legal/deploy/nginx-host/sites-enabled/store.conf.example \
   /etc/nginx/sites-enabled/yourdomain.com.conf

# Edit: set server_name and ssl_certificate paths
nano /etc/nginx/sites-enabled/yourdomain.com.conf

# Disable the default vhost, test, reload
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx
```

> See `deploy/nginx-host/` for the full file layout and multi-domain instructions.

### 1.6 Clone the repository

```bash
git clone <repo-url> /srv/legal
cd /srv/legal
```

### 1.7 Create secrets files

```bash
# Magento Marketplace credentials (for Composer --secret during image build)
cp auth.json.example auth.json
nano auth.json   # fill in public/private Marketplace keys

# Production environment secrets
cp .env.prod.example .env.prod
nano .env.prod   # fill in DB passwords, RabbitMQ creds, domain, etc.
```

---

## Part 2 — First-time application deploy

### 2.1 Build images

```bash
cd /srv/legal

export DOCKER_BUILDKIT=1

docker build \
  --secret id=composer_auth,src=auth.json \
  --target php-runtime \
  -t legal/php:latest \
  -f deploy/Dockerfile .

docker build \
  --secret id=composer_auth,src=auth.json \
  --target nginx-runtime \
  -t legal/nginx:latest \
  -f deploy/Dockerfile .

docker build \
  -t legal/varnish:latest \
  deploy/varnish/
```

### 2.2 Start infrastructure services

```bash
docker compose -f deploy/docker-compose.prod.yml --env-file .env.prod \
  up -d db opensearch redis rabbitmq

# Wait for healthchecks to pass (~60s for OpenSearch)
docker compose -f deploy/docker-compose.prod.yml --env-file .env.prod \
  ps --format "table {{.Name}}\t{{.Status}}"
```

### 2.3 Run setup:install (first deploy only)

```bash
source .env.prod

docker compose -f deploy/docker-compose.prod.yml --env-file .env.prod \
  run --rm php-fpm php bin/magento setup:install \
    --base-url="https://${DOMAIN}/" \
    --base-url-secure="https://${DOMAIN}/" \
    --db-host="db" \
    --db-name="${DB_NAME}" \
    --db-user="${DB_USER}" \
    --db-password="${DB_PASSWORD}" \
    --search-engine="opensearch" \
    --opensearch-host="opensearch" \
    --opensearch-port="9200" \
    --opensearch-index-prefix="magento2" \
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
    --amqp-host="rabbitmq" \
    --amqp-port="5672" \
    --amqp-user="${RABBITMQ_USER}" \
    --amqp-password="${RABBITMQ_PASSWORD}" \
    --amqp-virtualhost="/" \
    --backend-frontname="admin" \
    --admin-user="${ADMIN_USER}" \
    --admin-password="${ADMIN_PASSWORD}" \
    --admin-email="${ADMIN_EMAIL}" \
    --admin-firstname="Admin" \
    --admin-lastname="User" \
    --language="en_US" \
    --currency="USD" \
    --timezone="Europe/Kyiv" \
    --use-rewrites="1" \
    --use-secure="1" \
    --use-secure-admin="1"
```

After install, save the generated crypt key somewhere safe:
```bash
docker compose -f deploy/docker-compose.prod.yml --env-file .env.prod \
  run --rm php-fpm grep -A2 "crypt" app/etc/env.php
```

> **Back up `app/etc/env.php`** — it contains the crypt key. If lost, all
> encrypted data (payment credentials, API tokens) is unrecoverable.

### 2.4 Start all services

```bash
docker compose -f deploy/docker-compose.prod.yml --env-file .env.prod up -d

# Confirm all 7 services are running
docker compose -f deploy/docker-compose.prod.yml --env-file .env.prod ps
```

---

## Part 3 — Routine code deploys

```bash
cd /srv/legal

# 1. Pull latest code
git pull origin main

# 2. Rebuild only changed images
export DOCKER_BUILDKIT=1
docker build --secret id=composer_auth,src=auth.json \
  --target php-runtime  -t legal/php:latest   -f deploy/Dockerfile .
docker build --secret id=composer_auth,src=auth.json \
  --target nginx-runtime -t legal/nginx:latest -f deploy/Dockerfile .

# 3. Run DB migrations before switching traffic
docker compose -f deploy/docker-compose.prod.yml --env-file .env.prod \
  run --rm php-fpm php bin/magento setup:upgrade --keep-generated

# 4. Swap containers (zero-downtime: Varnish keeps serving cached pages during swap)
docker compose -f deploy/docker-compose.prod.yml --env-file .env.prod \
  up -d --no-deps --force-recreate php-fpm nginx cron

# 5. Flush caches
docker compose -f deploy/docker-compose.prod.yml --env-file .env.prod \
  exec php-fpm php bin/magento cache:flush
```

---

## Part 4 — Rollback

```bash
# Re-tag a previous image as latest
docker tag legal/php:<previous-tag>   legal/php:latest
docker tag legal/nginx:<previous-tag> legal/nginx:latest

# Recreate containers from old images
docker compose -f deploy/docker-compose.prod.yml --env-file .env.prod \
  up -d --no-deps --force-recreate php-fpm nginx cron

docker compose -f deploy/docker-compose.prod.yml --env-file .env.prod \
  exec php-fpm php bin/magento cache:flush
```

---

## Part 5 — Useful operational commands

```bash
# Shorthand alias (add to ~/.bashrc on the server)
alias dc='docker compose -f /srv/legal/deploy/docker-compose.prod.yml --env-file /srv/legal/.env.prod'

dc logs -f php-fpm          # stream application logs
dc logs -f nginx            # stream nginx access/error logs
dc logs -f varnish          # stream varnish logs
dc exec php-fpm bash        # shell into PHP container
dc exec db mariadb -u magento -p magento

dc exec php-fpm php bin/magento indexer:reindex
dc exec php-fpm php bin/magento cache:status
dc exec varnish varnishstat -1
dc exec rabbitmq rabbitmqctl list_queues

# View host nginx logs
tail -f /var/log/nginx/yourdomain.com.access.log
tail -f /var/log/nginx/yourdomain.com.error.log
```

---

## Part 6 — Multi-store / multi-domain setup

For each additional store/website:

1. Add a new `server {}` block in `/etc/nginx/sites-enabled/` (copy `store.conf.example`).
2. Point the new domain's DNS A record to the VPS IP.
3. Install the SSL cert for the new domain.
4. In Magento admin: Stores → All Stores → create the new website/store/store view.
5. Set the Base URLs for the new store (Stores → Config → Web → Base URLs).
6. All domains proxy to the same `127.0.0.1:6081` — Magento routes by `Host` header.

---

## Part 7 — MarkShust_DisableTwoFactorAuth in production

This module is a `--dev` Composer dependency. The production Dockerfile runs
`composer install --no-dev` so its PHP classes are never present on the server.
The builder stage also explicitly disables it:

```dockerfile
RUN php bin/magento module:disable MarkShust_DisableTwoFactorAuth --no-backup 2>/dev/null || true
```

Result: 2FA is **active** in production and **disabled** only in local dev.

---

## Part 8 — Environment parity

| Component | Local (Warden) | Production (VPS) |
|-----------|---------------|------------------|
| PHP | wardenenv/php-fpm:8.4-magento2 | wardenenv/php-fpm:8.4-magento2 |
| MariaDB | 11.4 | 11.4 |
| OpenSearch | 2.19 | 2.19 |
| Cache/session | Valkey 8 | Valkey 8 |
| HTTP cache | Varnish 7.7 | Varnish 7.7 |
| Queue | RabbitMQ 3.13 | RabbitMQ 3.13 |
| TLS | Warden mkcert | Server nginx + your certs |
| Magento mode | developer | production |
| OPcache | validate every request | disabled (max perf) |
| 2FA | disabled | enabled |
