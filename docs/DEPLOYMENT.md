# Deployment — Instance + name.com domain mapping

Replace `nexmile.in` with your actual name.com domain and `3.0.119.102` with your
instance's public IPv4 address throughout.

## 1. Subdomain plan

Decide this **before** creating DNS records — the backend and the portals are separate
apps and should not share an origin.

| Subdomain | Serves | Lives on |
|---|---|---|
| `api.nexmile.in` | Laravel API (this repo) | Your instance |
| `merchant.nexmile.in` | Merchant portal (React) | Same instance or Vercel/Netlify |
| `admin.nexmile.in` | Admin portal (React) | Same instance or Vercel/Netlify |
| `nexmile.in` | Marketing site | Anywhere |

The Flutter customer and rider apps also call `api.nexmile.in` — they need no subdomain.

## 2. Create the instance

Any Ubuntu 24.04 LTS instance works (AWS EC2 / DigitalOcean / Hetzner / Contabo).
Minimum viable for the pilot: **2 vCPU, 4 GB RAM, 40 GB SSD**.

Open these ports in the provider's firewall / security group:

| Port | Purpose |
|---|---|
| 22 | SSH — restrict to your IP |
| 80 | HTTP — needed for Let's Encrypt |
| 443 | HTTPS |

Do **not** expose 3306 (MySQL) or 6379 (Redis) publicly.

## 3. Point the domain at the instance (name.com)

In name.com: **My Domains → nexmile.in → Manage DNS → Add Record**.

| Type | Host | Answer | TTL |
|---|---|---|---|
| A | `api` | `3.0.119.102` | 300 |
| A | `merchant` | `3.0.119.102` | 300 |
| A | `admin` | `3.0.119.102` | 300 |
| A | `@` | `3.0.119.102` | 300 |

Use a low TTL (300s) while setting up, then raise it to 3600 once stable.

Verify propagation before requesting certificates — **this must return your IP**:

```bash
nslookup api.nexmile.in
dig +short api.nexmile.in
```

If it returns nothing, wait — Certbot will fail until DNS resolves.

## 4. Server packages

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y nginx mysql-server redis-server git unzip \
  php8.5-fpm php8.5-mysql php8.5-mbstring php8.5-xml php8.5-curl \
  php8.5-zip php8.5-bcmath php8.5-redis php8.5-intl

# Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

## 5. Database

```bash
sudo mysql_secure_installation
sudo mysql
```

```sql
CREATE DATABASE nexmile CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'nexmile'@'localhost' IDENTIFIED BY 'a-long-random-password';
GRANT ALL PRIVILEGES ON nexmile.* TO 'nexmile'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

Never use the MySQL `root` account for the app.

## 6. Deploy the code

```bash
sudo mkdir -p /var/www && cd /var/www
sudo git clone https://github.com/EnomApps/Nexmile_BackEnd.git nexmile
sudo chown -R $USER:www-data /var/www/nexmile
cd /var/www/nexmile

composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

Edit `.env` for production:

```env
APP_NAME=Nexmile
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.nexmile.in

CORS_ALLOWED_ORIGINS=https://merchant.nexmile.in,https://admin.nexmile.in

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=nexmile
DB_USERNAME=nexmile
DB_PASSWORD=a-long-random-password

CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
REDIS_HOST=127.0.0.1
```

`APP_DEBUG=false` is not optional — `true` in production leaks your `.env`
(including DB and Razorpay keys) on every error page.

```bash
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache

sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

## 7. Nginx

`/etc/nginx/sites-available/api.nexmile.in`:

```nginx
server {
    listen 80;
    server_name api.nexmile.in;

    # Laravel is served from public/, never the project root.
    root /var/www/nexmile/public;
    index index.php;

    charset utf-8;
    client_max_body_size 12M;   # KYC document uploads

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.5-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* { deny all; }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/api.nexmile.in /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

Pointing `root` at `/var/www/nexmile` instead of `/var/www/nexmile/public` would
expose `.env` over HTTP. Always include `/public`.

## 8. HTTPS

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d api.nexmile.in -d merchant.nexmile.in -d admin.nexmile.in
sudo systemctl status certbot.timer   # auto-renewal
```

The Flutter apps will refuse plain HTTP on both Android and iOS, so TLS is required,
not optional.

## 9. Queue worker

Needed once OTP SMS, notifications and settlements land.

`/etc/systemd/system/nexmile-worker.service`:

```ini
[Unit]
Description=Nexmile queue worker
After=network.target

[Service]
User=www-data
Restart=always
ExecStart=/usr/bin/php /var/www/nexmile/artisan queue:work --sleep=3 --tries=3 --max-time=3600

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl enable --now nexmile-worker
```

## 10. Verify

```bash
curl -i https://api.nexmile.in/up                      # health check -> 200
curl -s https://api.nexmile.in/api/v1/merchant/me \
     -H "Accept: application/json"                     # -> 401 Unauthenticated
```

A **401** here is the correct result — it proves routing, PHP-FPM and Sanctum are all wired up.

Confirm `.env` is not reachable — this must return **404**:

```bash
curl -i https://api.nexmile.in/.env
```

## 11. Redeploying

```bash
cd /var/www/nexmile
git pull origin master
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache
sudo systemctl restart php8.5-fpm nexmile-worker
```

Run `php artisan config:clear` first if a changed `.env` value seems to be ignored —
cached config does not re-read `.env`.
