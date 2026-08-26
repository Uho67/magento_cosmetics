# Production Deployment Runbook

## Architecture overview

```
Browser
  └─▶ Traefik :443 (TLS termination, Let's Encrypt)
        └─▶ Varnish :6081 (full-page HTTP cache)
              └─▶ Nginx :80 (static files + FastCGI proxy)
                    └─▶ PHP-FPM :9000 (Magento application)

All other services (MariaDB, OpenSearch, Valkey, RabbitMQ) are on an
internal Docker network with no public exposure.
```

## OS assumption: Ubuntu 24.04 LTS

---

## Part 1 — First-time server provisioning

### 1.1 Install Docker

```bash
# Remove old versions
apt-get remove -y docker docker-engine docker.io containerd runc 2>/dev/null || true

# Install Docker Engine
curl -fsSL https://get.docker.com | sh
systemctl enable --now docker

# Verify
docker --version
docker compose version    # needs 2.x (plugin, not legacy docker-compose)
```

### 1.2 Configure firewall

```bash
ufw allow OpenSSH
ufw allow 80/tcp
ufw allow 443/tcp
# Port 8080 (Traefik dashboard) should NOT be opened; use SSH tunnel instead:
#   ssh -L 8080:localhost:8080 user@your-vps
ufw enable
ufw status
```

### 1.3 Create deploy user (optional but recommended)

```bash
adduser deploy
usermod -aG docker deploy
# Add your SSH public key to /home/deploy/.ssh/authorized_keys
```

### 1.4 Clone the repository

```bash
git clone <repo-url> /srv/legal
cd /srv/legal
```

### 1.5 Create secrets files

```bash
# Magento Marketplace credentials (for Composer)
cp auth.json.example auth.json
# Edit auth.json with your public/private Marketplace keys

# Production environment secrets
cp .env.prod.example .env.prod
# Edit .env.prod with real passwords, domain, etc.
```

### 1.6 Edit Traefik config with your email

```bash
# Replace admin@example.com with a real address for Let's Encrypt notifications
nano deploy/traefik/traefik.yml
```

---

## Part 2 — First-time application deploy

### 2.1 Build images

```bash
cd /srv/legal

# Enable BuildKit (needed for --secret)
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

### 2.2 Start infrastructure services first

```bash
docker compose -f deploy/docker-compose.prod.yml --env-file .env.prod \
  up -d traefik db opensearch redis rabbitmq

# Wait for DB and OpenSearch to be healthy
docker compose -f deploy/docker-compose.prod.yml --env-file .env.prod \
  ps --format "table {{.Name}}\t{{.Status}}"
```

### 2.3 Run setup:install (first deploy only)

```bash
source .env.prod    # loads variables into shell

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

# After install, copy the generated crypt key into .env.prod for safekeeping
# (find it in app/etc/env.php inside the container)
docker compose -f deploy/docker-compose.prod.yml --env-file .env.prod \
  run --rm php-fpm grep crypt_key app/etc/env.php
```

> **Back up `app/etc/env.php`** — it contains the crypt key. If lost, all encrypted data
> (payment credentials, API tokens) is unrecoverable.

### 2.4 Start all services

```bash
docker compose -f deploy/docker-compose.prod.yml --env-file .env.prod up -d

# Confirm everything is running
docker compose -f deploy/docker-compose.prod.yml --env-file .env.prod ps
```

---

## Part 3 — Routine code deploys

Run this sequence for every code change. Steps are ordered to minimise downtime.

```bash
cd /srv/legal

# 1. Pull latest code
git pull origin main

# 2. Rebuild images (only changed layers are rebuilt)
export DOCKER_BUILDKIT=1
docker build --secret id=composer_auth,src=auth.json \
  --target php-runtime -t legal/php:latest -f deploy/Dockerfile .
docker build --secret id=composer_auth,src=auth.json \
  --target nginx-runtime -t legal/nginx:latest -f deploy/Dockerfile .

# 3. Run database migrations (setup:upgrade) before switching traffic
docker compose -f deploy/docker-compose.prod.yml --env-file .env.prod \
  run --rm php-fpm php bin/magento setup:upgrade --keep-generated

# 4. Recreate application containers with new images
docker compose -f deploy/docker-compose.prod.yml --env-file .env.prod \
  up -d --no-deps --force-recreate php-fpm nginx cron

# 5. Flush Magento cache + invalidate Varnish
docker compose -f deploy/docker-compose.prod.yml --env-file .env.prod \
  exec php-fpm php bin/magento cache:flush
```

> `--keep-generated` skips regenerating DI/interceptors during upgrade if you
> already compiled them in the image. Remove it if you have new plugins.

---

## Part 4 — Rollback

```bash
# 1. Identify the previous image tag (use date-tagged images in CI/CD)
docker images legal/php

# 2. Re-tag the previous image as latest
docker tag legal/php:<previous-tag> legal/php:latest
docker tag legal/nginx:<previous-tag> legal/nginx:latest

# 3. Recreate containers
docker compose -f deploy/docker-compose.prod.yml --env-file .env.prod \
  up -d --no-deps --force-recreate php-fpm nginx cron

# 4. Reverse any DB migrations if needed (manual — Magento has no auto-rollback)
# 5. Flush cache
docker compose -f deploy/docker-compose.prod.yml --env-file .env.prod \
  exec php-fpm php bin/magento cache:flush
```

---

## Part 5 — Useful operational commands

```bash
# Alias for brevity
alias dc='docker compose -f /srv/legal/deploy/docker-compose.prod.yml --env-file /srv/legal/.env.prod'

# Logs
dc logs -f php-fpm
dc logs -f nginx
dc logs -f varnish
dc logs -f traefik

# Shell into php-fpm
dc exec php-fpm bash

# Reindex
dc exec php-fpm php bin/magento indexer:reindex

# Cache status
dc exec php-fpm php bin/magento cache:status

# Varnish stats
dc exec varnish varnishstat -1

# MariaDB CLI
dc exec db mariadb -u magento -p magento

# RabbitMQ queue status
dc exec rabbitmq rabbitmqctl list_queues
```

---

## Part 6 — Notes on MarkShust_DisableTwoFactorAuth

This module is a **`--dev` Composer dependency**. The production Dockerfile runs
`composer install --no-dev`, so its PHP classes are never present on the server.

Additionally, the builder stage explicitly disables it:
```dockerfile
RUN php bin/magento module:disable MarkShust_DisableTwoFactorAuth --no-backup 2>/dev/null || true
```

This means `app/etc/config.php` (which is committed) will have the module listed
as `0 => disabled` on production, and `1 => enabled` in local dev — which is correct.

---

## Part 7 — Environment parity checklist

| Component | Local (Warden) | Production |
|-----------|---------------|------------|
| PHP | 8.4-magento2 (wardenenv) | 8.4-magento2 (wardenenv) |
| MariaDB | 11.4 | 11.4 |
| OpenSearch | 2.19 | 2.19 |
| Cache/session | Valkey 8 | Valkey 8 |
| HTTP cache | Varnish 7.7 | Varnish 7.7 |
| Queue | RabbitMQ 3.13 | RabbitMQ 3.13 |
| Magento mode | developer | production |
| OPcache timestamps | validate on every request | disabled (max performance) |
| 2FA | disabled (MarkShust module) | enabled (module not present) |
