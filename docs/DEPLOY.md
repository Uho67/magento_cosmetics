# Production Deployment Runbook

## Architecture overview

```
Browser
  └─▶ Host nginx :443        (SSL termination, your certs — outside Docker)
        └─▶ Docker Varnish 127.0.0.1:6081   (full-page HTTP cache)
              └─▶ Docker Nginx :80           (static files + FastCGI proxy)
                    └─▶ Docker PHP-FPM :9000 (Magento application)

MariaDB, OpenSearch, Valkey, RabbitMQ — internal Docker network, no public ports.

phpMyAdmin 127.0.0.1:8080 — internal only, access via SSH tunnel (see Part 9).
```

**Code is not baked into Docker images.** Containers mount the active build from
the host filesystem (`/srv/legal/builds/current/`). The deploy script runs
directly on the server: it pulls the latest code from git, builds inside a
Docker container, and swaps directories atomically.

**Multi-store / multi-domain:** each domain gets its own `server {}` block in
host nginx, all proxying to the same `127.0.0.1:6081`. Magento routes by `Host`
header to the correct website/store via its Base URL configuration.

---

## Directory layout on the VPS

```
/srv/legal/
├── repo/                          ← git clone of this repository
│   └── deploy/
│       ├── docker-compose.prod.yml
│       ├── varnish/default.vcl
│       ├── nginx/magento.conf
│       ├── php/php.ini
│       └── nginx-host/            ← copy these to /etc/nginx/ (one-time)
│
├── builds/
│   ├── current/                   ← active build (mounted by Docker)
│   ├── previous -> archive/…      ← symlink to last build (for rollback)
│   └── archive/                   ← last N builds (N = KEEP_BUILDS in deploy.conf)
│
├── shared/
│   ├── env.php                    ← copied into each build at deploy time
│   ├── media/                     ← persistent pub/media (mounted by Docker)
│   └── logs/                      ← persistent var/log  (mounted by Docker)
│
├── incoming/                      ← build tarballs land here during upload
├── .env.prod                      ← secrets (gitignored, never committed)
└── auth.json                      ← Composer Marketplace keys (gitignored)
```

---

## Part 1 — First-time server provisioning

Tested on Ubuntu 24.04 LTS.

### 1.1 Install nginx and Docker

```bash
apt-get update && apt-get install -y nginx curl

# Remove any old Docker packages
apt-get remove -y docker docker-engine docker.io containerd runc 2>/dev/null || true

# Install Docker Engine (Compose plugin included)
curl -fsSL https://get.docker.com | sh
systemctl enable --now docker

# Verify
nginx -v
docker --version
docker compose version    # must be 2.x — the Compose plugin, not legacy docker-compose
```

### 1.2 Configure firewall

```bash
ufw allow OpenSSH
ufw allow 80/tcp
ufw allow 443/tcp
ufw enable
ufw status
```

Port 6081 (Varnish) is **not** opened — it binds to `127.0.0.1` only and is
never directly reachable from outside the server.

### 1.3 Create a deploy user

```bash
adduser deploy
usermod -aG docker deploy

mkdir -p /home/deploy/.ssh
# Paste your SSH public key:
echo "ssh-ed25519 AAAA..." >> /home/deploy/.ssh/authorized_keys
chmod 700 /home/deploy/.ssh
chmod 600 /home/deploy/.ssh/authorized_keys
chown -R deploy:deploy /home/deploy/.ssh
```

### 1.4 Create the server directory structure

```bash
mkdir -p /srv/legal/{repo,builds,shared/{media,logs},incoming}
chown -R deploy:deploy /srv/legal
```

### 1.5 Clone the repository

```bash
su - deploy
git clone <repo-url> /srv/legal/repo
```

### 1.6 Install SSL certificates

Place your certificate and private key on the server:

```
/etc/ssl/certs/yourdomain.com.crt
/etc/ssl/private/yourdomain.com.key
```

Or use Certbot (Let's Encrypt):

```bash
apt-get install -y certbot python3-certbot-nginx
certbot certonly --standalone -d yourdomain.com -d www.yourdomain.com
```

Certbot stores certs at `/etc/letsencrypt/live/yourdomain.com/`. Reference those
paths in your nginx `server {}` block (see §1.7).

### 1.7 Configure host nginx

```bash
# Copy shared TLS and proxy snippets
cp /srv/legal/repo/deploy/nginx-host/snippets/*.snippet /etc/nginx/snippets/

# Create a vhost for your domain (copy and edit the example)
cp /srv/legal/repo/deploy/nginx-host/snippets/*.snippet /etc/nginx/snippets/
cp /srv/legal/repo/deploy/nginx-host/sites-enabled/store.conf.example \
   /etc/nginx/sites-available/yourdomain.com.conf

# Edit: set server_name and ssl_certificate / ssl_certificate_key
nano /etc/nginx/sites-available/yourdomain.com.conf

# Enable via symlink (Debian/Ubuntu convention)
ln -s /etc/nginx/sites-available/yourdomain.com.conf \
      /etc/nginx/sites-enabled/yourdomain.com.conf

# Disable the default vhost, test, reload
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx
```

The `store.conf.example` template:
- Redirects HTTP → HTTPS
- Terminates TLS with your cert
- Proxies all requests to `http://127.0.0.1:6081` (Docker Varnish)
- Passes `X-Forwarded-Proto: https` so Magento generates correct HTTPS URLs

For each additional store/domain: copy the example, change `server_name` and
cert paths, reload nginx. No Docker config changes needed.

### 1.8 Create the secrets files on the server

```bash
su - deploy

# Magento Marketplace credentials (Composer needs these to pull Magento packages)
cp /srv/legal/repo/auth.json.example /srv/legal/auth.json
nano /srv/legal/auth.json

# Production environment variables
cp /srv/legal/repo/.env.prod.example /srv/legal/.env.prod
nano /srv/legal/.env.prod    # fill in all passwords and your domain
```

Key variables in `.env.prod`:

| Variable | Description |
|---|---|
| `DOMAIN` | Your domain, e.g. `example.com` |
| `APP_ROOT` | `/srv/legal/builds/current` (do not change) |
| `SHARED_MEDIA` | `/srv/legal/shared/media` |
| `SHARED_LOGS` | `/srv/legal/shared/logs` |
| `DB_ROOT_PASSWORD` | MariaDB root password |
| `DB_NAME / DB_USER / DB_PASSWORD` | Magento DB credentials |
| `RABBITMQ_USER / RABBITMQ_PASSWORD` | RabbitMQ credentials |
| `CRYPT_KEY` | Magento crypt key — fill in after §2.2 |

### 1.9 Start infrastructure services (first time only)

```bash
COMPOSE="docker compose -f /srv/legal/repo/deploy/docker-compose.prod.yml --env-file /srv/legal/.env.prod"

# Pull images and start all background services
$COMPOSE pull
$COMPOSE up -d db opensearch redis rabbitmq

# Wait ~60 s for OpenSearch and MariaDB healthchecks to pass
$COMPOSE ps
```

### 1.10 Run setup:install (first deploy only)

```bash
source /srv/legal/.env.prod

$COMPOSE run --rm php-fpm php /var/www/html/bin/magento setup:install \
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

After install completes:

1. **Save `app/etc/env.php` to `shared/`** — this file holds the database
   connection and crypt key. It is never committed to git and must survive
   across deploys.

   ```bash
   cp /srv/legal/builds/current/app/etc/env.php /srv/legal/shared/env.php
   ```

2. **Copy the crypt key into `.env.prod`** — for reference and backup:

   ```bash
   grep crypt /srv/legal/shared/env.php
   # Copy the key value into CRYPT_KEY= in /srv/legal/.env.prod
   ```

   > **Back up `env.php` and its crypt key in a secure location (password
   > manager, encrypted storage).** Losing the crypt key makes all encrypted
   > data (payment credentials, API tokens) permanently unrecoverable.

### 1.11 Start all services

```bash
$COMPOSE up -d

# Confirm all 8 services are Up
$COMPOSE ps
```

Visit `https://yourdomain.com` — you should see the Magento storefront.

---

## Part 2 — Routine code deploys

All deploy steps run **on the server itself** — no Mac build required. SSH in
as the `deploy` user and run a single script.

### 2.1 SSH to the server

```bash
ssh deploy@uho.kharkiv.ua
```

### 2.2 Run a deploy

```bash
cd /srv/legal/repo
./scripts/deploy-server.sh
```

The script runs six steps automatically:

```
Step 1 — git pull (latest code from the main branch)
Step 2 — Export clean source via git archive HEAD → builds/new/
Step 3 — Build inside a Docker container (no network access to production DB):
           • composer install --no-dev --optimize-autoloader
           • module:enable Magento_TwoFactorAuth   (re-enable 2FA for prod)
           • module:disable MarkShust_DisableTwoFactorAuth
           • setup:di:compile
Step 4 — Copy shared/env.php into builds/new/app/etc/
Step 5 — Run against the live DB (connected to the internal Docker network):
           • setup:upgrade --keep-generated
           • setup:static-content:deploy (frontend + adminhtml)
Step 6 — Swap builds and restart:
           • mv builds/current → builds/archive/<timestamp>-prev
           • mv builds/new     → builds/current          (atomic)
           • ln -sfn …        → builds/previous          (for rollback)
           • docker compose restart php-fpm nginx cron   (clears OPcache)
           • cache:flush
           • Reload Varnish VCL (hot-reload, no restart)
           • Prune old archives (keep 3)
```

**Why `docker restart` instead of a rolling update?** Docker resolves bind
mounts at container start time. Changing `builds/current` via `mv` on the host
does not affect a running container — a restart is required to pick up the new
mount path. The restart takes 2–3 seconds; Varnish continues to serve cached
pages during that window.

**Locales:** the script deploys `en_US` by default. To deploy additional
locales, edit the `LOCALES` variable at the top of `deploy-server.sh` or
set it inline:

```bash
LOCALES="en_US uk_UA" ./scripts/deploy-server.sh
```

### 2.3 What triggers a deploy

- Any code change pushed to `main` (module updates, templates, config changes)
- Composer dependency changes (`composer.json` / `composer.lock`)
- Changes to `deploy/` config files (nginx, PHP, VCL) — note: Varnish and
  nginx containers must be separately restarted when their configs change (see §4)

### 2.4 What does NOT need a deploy

- Admin configuration changes (stored in DB)
- Content page / CMS changes (stored in DB)
- Media uploads (go into `shared/media/`, persisted across deploys)

---

## Part 3 — Rollback

If a deploy causes problems, revert to the previous build (run on the server):

```bash
ssh deploy@uho.kharkiv.ua
cd /srv/legal/repo
./scripts/rollback-server.sh
```

What the rollback script does:

1. Saves the failed build as `builds/rollback-<timestamp>/` (for investigation)
2. Copies `builds/previous/` back to `builds/current/`
3. Restarts `php-fpm`, `nginx`, `cron` (previous code takes effect)
4. Flushes Magento cache

> **Database caveat:** if the failed deploy ran `setup:upgrade` and applied
> schema changes, rolling back the code does not revert the database schema.
> Magento has no automatic schema rollback. If the schema change caused the
> problem, you will need to restore a database snapshot.

---

## Part 4 — Updating Docker service configs

Some changes are not part of the code deploy and require separate steps.

### Nginx or PHP config changes

Nginx and PHP configs are mounted from `repo/deploy/nginx/` and `repo/deploy/php/`:

```bash
# On the server — pull new config from git, then reload the affected service
cd /srv/legal/repo && git pull

COMPOSE="docker compose -f /srv/legal/repo/deploy/docker-compose.prod.yml --env-file /srv/legal/.env.prod"

# PHP config (php.ini):
$COMPOSE restart php-fpm cron

# Nginx config:
$COMPOSE exec nginx nginx -t          # test first
$COMPOSE restart nginx
```

### Varnish VCL changes

Varnish is a built image (VCL only, not application code). Rebuild it when
`deploy/varnish/default.vcl` changes:

```bash
cd /srv/legal/repo && git pull

COMPOSE="docker compose -f /srv/legal/repo/deploy/docker-compose.prod.yml --env-file /srv/legal/.env.prod"

$COMPOSE build varnish
$COMPOSE up -d --no-deps --force-recreate varnish
```

### Docker image updates (MariaDB, OpenSearch, etc.)

Pull new images and recreate only the affected service:

```bash
$COMPOSE pull db
$COMPOSE up -d --no-deps --force-recreate db
```

---

## Part 5 — Operational commands

Add this alias to `/home/deploy/.bashrc` on the server to save typing:

```bash
alias dc='docker compose -f /srv/legal/repo/deploy/docker-compose.prod.yml --env-file /srv/legal/.env.prod'
```

**Logs:**

```bash
dc logs -f php-fpm          # PHP application output
dc logs -f nginx            # nginx access + error
dc logs -f varnish          # cache hit/miss, ban log
tail -f /srv/legal/shared/logs/php-errors.log        # PHP error log (from ini)
tail -f /var/log/nginx/yourdomain.com.access.log     # host nginx access
tail -f /var/log/nginx/yourdomain.com.error.log      # host nginx errors
```

**Shells:**

```bash
dc exec php-fpm bash                           # PHP container
dc exec db mariadb -u magento -p              # MariaDB CLI
```

**Magento CLI:**

```bash
dc exec php-fpm php bin/magento indexer:reindex
dc exec php-fpm php bin/magento indexer:status
dc exec php-fpm php bin/magento cache:status
dc exec php-fpm php bin/magento cache:flush
dc exec php-fpm php bin/magento setup:upgrade --keep-generated
```

**Varnish:**

```bash
dc exec varnish varnishstat -1                    # hit rate, request counts
dc exec varnish varnishadm ban 'req.url ~ "."'   # ban everything (full purge)
```

**RabbitMQ:**

```bash
dc exec rabbitmq rabbitmqctl list_queues name messages consumers
```

**Disk usage:**

```bash
du -sh /srv/legal/builds/archive/*   # size of each archived build
du -sh /srv/legal/shared/media/      # media library size
```

---

## Part 6 — Multi-store / multi-domain setup

All domains proxy to the same Docker Varnish. Magento identifies the correct
store by the `Host` header passed through the proxy chain.

**For each additional domain:**

1. Point the domain's DNS A record to the VPS IP.

2. Install the SSL certificate for the new domain.

3. Copy the nginx vhost template:

   ```bash
   cp /srv/legal/repo/deploy/nginx-host/sites-enabled/store.conf.example \
      /etc/nginx/sites-available/newdomain.com.conf
   # Edit server_name and ssl_certificate paths
   ln -s /etc/nginx/sites-available/newdomain.com.conf \
         /etc/nginx/sites-enabled/newdomain.com.conf
   nginx -t && systemctl reload nginx
   ```

4. In Magento admin: **Stores → All Stores** — create the Website, Store, and
   Store View.

5. Set base URLs: **Stores → Configuration → Web → Base URLs** — set both
   `Base URL` and `Base Link URL` for the new store view to `https://newdomain.com/`.

6. Flush caches:

   ```bash
   dc exec php-fpm php bin/magento cache:flush
   ```

No Docker config changes are required. The new domain is immediately served by
the existing `php-fpm` and `varnish` containers.

---

## Part 7 — Environment parity

| Component | Local (Warden) | Production (VPS) |
|---|---|---|
| PHP | wardenenv/php-fpm:8.4-magento2 | wardenenv/php-fpm:8.4-magento2 |
| MariaDB | 11.4 | 11.4 |
| OpenSearch | 2.19 | 2.19 |
| Cache / Sessions | Valkey 8 | Valkey 8 |
| HTTP cache | Varnish 7.7 | Varnish 7.7 |
| Queue | RabbitMQ 3.13 | RabbitMQ 3.13 |
| TLS | Warden mkcert (auto) | Host nginx + your certs |
| Magento mode | developer | production |
| OPcache | validate every request | `validate_timestamps=0` (max perf) |
| 2FA | disabled (MarkShust module) | enabled |
| Build platform | native macOS | linux/amd64 Docker container |

---

## Part 8 — 2FA and the MarkShust module

`markshust/magento2-module-disabletwofactorauth` is installed as a `--dev`
Composer dependency. The production build step runs `composer install --no-dev`,
so the module's PHP classes are never present on the server.

Additionally, the deploy script explicitly re-enables 2FA before compiling:

```bash
php bin/magento module:enable Magento_TwoFactorAuth Magento_AdminAdobeImsTwoFactorAuth
php bin/magento module:disable MarkShust_DisableTwoFactorAuth
```

This overwrites the `Magento_TwoFactorAuth=0` entry that the module writes to
`app/etc/config.php` during local development, ensuring 2FA is **always active
in production** regardless of what is committed in `config.php`.

---

## Part 9 — phpMyAdmin (DB admin UI)

phpMyAdmin runs as a Docker service (`phpmyadmin`) but is **never exposed to the
public internet**. It binds to `127.0.0.1:8080` on the VPS only.

### 9.1 Access via SSH tunnel

Open a tunnel from your local Mac:

```bash
ssh -L 8080:127.0.0.1:8080 deploy@your-vps-ip
```

Then open `http://localhost:8080` in your browser.

Login:
- **Server:** `db` (pre-configured via `PMA_HOST`)
- **Username:** `root`
- **Password:** value of `DB_ROOT_PASSWORD` from `/srv/legal/.env.prod`

> The tunnel forwards your local port 8080 to the VPS's loopback port 8080.
> The connection is encrypted inside your SSH session — no additional TLS needed.

### 9.2 Start / stop the service

The service starts automatically with the rest of the stack. To manage it
independently:

```bash
COMPOSE="docker compose -f /srv/legal/repo/deploy/docker-compose.prod.yml --env-file /srv/legal/.env.prod"

$COMPOSE up -d phpmyadmin      # start
$COMPOSE stop phpmyadmin       # stop (data unaffected)
$COMPOSE logs -f phpmyadmin    # view logs
```

### 9.3 Security notes

- `127.0.0.1:8080` is **not** opened in `ufw` — the port is inaccessible without
  an SSH session.
- phpMyAdmin logs in as `root` and has full access to all databases. Do not share
  your SSH key or the tunnel with untrusted parties.
- If you don't need phpMyAdmin running continuously, stop it after use:
  `$COMPOSE stop phpmyadmin`.
