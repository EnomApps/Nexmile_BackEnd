# Docker (local development) and deployment

## What runs where

| Environment | How it runs |
|---|---|
| Local development | Docker Compose — PHP 8.5, MySQL 8.4, Redis 7 |
| Production | PHP-FPM, Nginx, Redis directly on the EC2 instance; MySQL on RDS |

Production is **not** containerised. It is provisioned, serving traffic on TLS,
and rebuilding it as containers would mean redoing Nginx and the Certbot
certificate for no benefit this milestone. Revisit when the Python dispatch
service lands in EP7 — running Python and PHP side by side is where containers
start paying for themselves.

## Why bother locally

Without Docker, development runs on XAMPP: **PHP 8.2**, **MariaDB 10.4**, and no
Redis at all, against a server running **PHP 8.5**, **MySQL 8.4** and **Redis 7**.
That gap is where "works on my machine" bugs come from. The compose stack
removes it.

## First run

Docker Desktop on Windows 11 Home requires WSL2:

```powershell
wsl --install
# reboot, then install Docker Desktop
```

Then:

```bash
cd /c/xampp/htdocs/nexmile/Nexmile_BackEnd

cp .env .env.xampp          # keep your XAMPP settings
docker compose up -d --build
```

Point `.env` at the containers:

```env
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=nexmile
DB_USERNAME=root
DB_PASSWORD=secret

REDIS_CLIENT=phpredis
REDIS_HOST=redis
REDIS_PORT=6379
```

`DB_HOST=mysql` and `REDIS_HOST=redis` are the compose **service names** —
Docker's internal DNS resolves them. `127.0.0.1` inside a container means the
container itself, not the host.

Finish setting up:

```bash
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
docker compose exec app php artisan redis:health
```

Open **http://localhost:8080**

## Ports

Offset so the stack runs alongside XAMPP, which already holds 80 and 3306.

| Service | Host port |
|---|---|
| Application | 8080 |
| MySQL | 3307 |
| Redis | 6380 |

Connect a GUI client to MySQL on `127.0.0.1:3307`, user `root`, password `secret`.

## Everyday commands

```bash
docker compose up -d              # start
docker compose down               # stop
docker compose down -v            # stop and delete the database volume
docker compose logs -f app        # tail logs
docker compose exec app bash      # shell inside the container

docker compose exec app php artisan migrate
docker compose exec app php artisan test
```

Run artisan **inside** the container. Running it from Windows uses XAMPP's PHP
8.2 and the XAMPP `.env`, which is not what the containers are using.

## Switching back to XAMPP

```bash
docker compose down
cp .env.xampp .env
```

## Deployment

`deploy.sh` lives at the project root and runs on the server:

```bash
cd /var/www/nexmile && ./deploy.sh
```

It fetches master, installs dependencies, migrates, rebuilds caches, fixes
permissions and reloads PHP-FPM — then health-checks the site. **If the health
check fails it resets to the previous commit and reloads**, so a broken deploy
reverts itself rather than leaving the site down.

The queue worker is only restarted after the release passes its health check,
so in-flight jobs are not killed for a release that is about to be reverted.

First time only:

```bash
cd /var/www/nexmile
git pull origin master
chmod +x deploy.sh
```

## CI

`.github/workflows/ci.yml` runs on every push and pull request:

1. PHP 8.5 with the same extensions as the server
2. Migrations against **MySQL 8.4**, not SQLite — SQLite silently tolerates
   schema that MySQL rejects
3. A full `migrate:rollback`, so a broken `down()` fails the build
4. The test suite with a real Redis, so the live-state tests actually run
   instead of skipping

## Auto-deploy

`.github/workflows/deploy.yml` runs after CI passes on master, or manually from
the Actions tab.

### Repository secrets

**Settings → Secrets and variables → Actions**

| Secret | Value |
|---|---|
| `DEPLOY_HOST` | `3.0.119.102` |
| `DEPLOY_USER` | `ubuntu` |
| `DEPLOY_SSH_KEY` | private key contents (see below) |
| `DEPLOY_KNOWN_HOSTS` | output of `ssh-keyscan -H 3.0.119.102` |

Generate a deploy-only key rather than reusing your personal one:

```bash
# on the server
ssh-keygen -t ed25519 -f ~/.ssh/github_deploy -N ""
cat ~/.ssh/github_deploy.pub >> ~/.ssh/authorized_keys
cat ~/.ssh/github_deploy          # paste into DEPLOY_SSH_KEY
```

Delete the private key from the server once it is in GitHub — it only needs to
exist on the runner.

### The SSH access problem

Your security group allows SSH from **My IP** only. GitHub's runners use
rotating addresses, so auto-deploy will fail until this is resolved. Three ways
out, worst to best:

1. **Open port 22 to `0.0.0.0/0`.** Works immediately. Also invites every SSH
   scanner on the internet. Only acceptable with password auth disabled, and
   even then it is not a good habit.
2. **Allow GitHub's published ranges.** Fetch them from
   `https://api.github.com/meta` (the `actions` array). Tighter, but it is a
   long list that changes, so it needs periodic updating.
3. **Self-hosted runner on the EC2 instance.** The runner polls GitHub
   outbound, so **no inbound SSH is needed at all** and port 22 stays locked to
   your IP. Deployment becomes a local command. Best security, one more service
   to keep running.

Until one of these is in place, deploy manually with `./deploy.sh` — the
workflow will simply fail at the SSH step, and nothing else breaks.

The `deploy.sh` script and the workflow run the same code path, so a manual
deploy and an automated one behave identically.
