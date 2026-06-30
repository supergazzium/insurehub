# InsureHub Deployment

Step-by-step server setup. The repo is a monorepo with `backend/` (Laravel 13)
and `frontend/` (Vue 3 + Vite). The Laravel app serves the API; the Vue SPA is
built once into static files and served by your web server (nginx/apache) or by
Laravel itself from `public/`.

> All paths below assume the repo is cloned at `/var/www/insurehub`. Adjust
> wherever you actually put it.

## Prerequisites on the server

- **PHP 8.3+** with extensions: `mbstring`, `xml`, `bcmath`, `intl`, `pdo_mysql`, `mysql`, `curl`, `gd`, `zip`
- **Composer 2.x**
- **Node 20+** + **npm 10+** (for the one-time frontend build)
- **MySQL 8** running with a writable user
- **nginx** or **apache** (or any reverse proxy in front of `php artisan serve`)
- **git**

Quick check on Ubuntu 22.04 / 24.04:

```bash
php -v && composer --version && node -v && npm -v && mysql --version && nginx -v
```

## 1. Clone

```bash
sudo mkdir -p /var/www && sudo chown -R "$USER":www-data /var/www
cd /var/www
git clone https://github.com/<owner>/insurehub.git
cd insurehub
```

## 2. Backend setup

```bash
cd /var/www/insurehub/backend

# PHP dependencies (production — no dev tools, optimized autoloader)
composer install --no-dev --optimize-autoloader

# .env from example, then edit
cp .env.example .env
php artisan key:generate

# Edit .env — at minimum:
#   APP_ENV=production
#   APP_DEBUG=false
#   APP_URL=https://your-domain.com
#   DB_DATABASE=insurehub
#   DB_USERNAME=<your mysql user>
#   DB_PASSWORD=<your mysql password>
#   FRONTEND_URLS=https://your-domain.com
#   (ZOHO_* values when you're ready to wire mail)
nano .env
```

## 3. Database

```bash
# Create the DB
mysql -u root -p -e "CREATE DATABASE insurehub CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# Migrate + seed (this loads 392 agents, 357 customers, 45 carriers, 894 products,
# 474 policies + events, 7460 Thai locations, 32,664 vehicles, the email
# templates, the carrier contact groups, etc.)
cd /var/www/insurehub/backend
php artisan migrate --force
php artisan db:seed --force
```

The seed step takes ~5 minutes (motor_vehicles is the slow one). After it
finishes, you have a working admin login: `admin@insurehub.co.th` /
`insurehub`. **Change it before going live:**

```bash
php artisan tinker
> \App\Models\User::where('email','admin@insurehub.co.th')->first()->update(['password' => \Hash::make('<new password>')]);
> exit
```

## 4. Storage symlink + permissions

```bash
cd /var/www/insurehub/backend
php artisan storage:link

# www-data needs write access to storage + bootstrap/cache
sudo chown -R "$USER":www-data storage bootstrap/cache
sudo chmod -R ug+rwx storage bootstrap/cache
```

## 5. Frontend build

```bash
cd /var/www/insurehub/frontend
cp .env.example .env
# Edit .env — point at the production API base URL:
#   VITE_API_BASE_URL=https://your-domain.com/api/v1
nano .env

npm ci
npm run build
```

This produces `frontend/dist/` — static HTML + assets. Two ways to serve them:

### Option A — Let nginx serve `frontend/dist/` directly

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /var/www/insurehub/frontend/dist;
    index index.html;

    # SPA fallback — every unknown URL goes to index.html
    location / {
        try_files $uri $uri/ /index.html;
    }

    # Proxy the API
    location /api/ {
        proxy_pass http://127.0.0.1:8000;
        proxy_set_header Host              $host;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

Then run Laravel on `127.0.0.1:8000` via `php artisan serve` (dev only) or
**php-fpm + a second server block** (production). For php-fpm-only setups,
adjust the API location to a `fastcgi_pass` block pointing at
`/var/www/insurehub/backend/public/index.php`.

### Option B — Serve everything via Laravel

Copy `frontend/dist/*` into `backend/public/` and let Laravel's
`public/index.html` serve the SPA. This is simpler but mixes assets. Avoid
unless you have a reason.

## 6. Production config caches (Laravel)

After every code pull / .env change:

```bash
cd /var/www/insurehub/backend
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

Clear them with `php artisan config:clear` / `route:clear` etc. during a fresh
deploy.

## 7. Mail polling (when Zoho is wired)

The poll worker runs every minute via Laravel's scheduler. Add **one cron entry**:

```bash
sudo crontab -e -u www-data
# Add this line:
* * * * * cd /var/www/insurehub/backend && php artisan schedule:run >> /dev/null 2>&1
```

The scheduler internally invokes `mail:poll` every minute. When Zoho env vars
aren't set yet, the command exits cleanly with a "not configured" notice — safe
to leave the cron enabled.

## 8. Update / redeploy

```bash
cd /var/www/insurehub
git pull

cd backend
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache

cd ../frontend
npm ci
npm run build
```

Then reload nginx (`sudo systemctl reload nginx`). If you changed any cron
entries, no restart needed — cron picks them up automatically.

## 9. SSL

Use Certbot for a free Let's Encrypt cert:

```bash
sudo apt install certbot python3-certbot-nginx
sudo certbot --nginx -d your-domain.com
```

It edits the nginx config in-place and sets up auto-renewal.

## 10. Sanity check

```bash
curl -sS http://127.0.0.1:8000/up
# expects {"status":"ok"} or HTTP 200

curl -sS -X POST http://127.0.0.1:8000/api/v1/auth/login \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"email":"admin@insurehub.co.th","password":"<your new pw>"}'
# expects a JSON {"token":"...","user":{...}}
```

Then visit `https://your-domain.com/insurehub/` in a browser, log in, and you
should see the seeded data.

## Common issues

| Symptom | Cause | Fix |
|---|---|---|
| `500` on every request after deploy | Forgot `composer install` or wrong storage permissions | `composer install` then re-run the chown/chmod from step 4 |
| `Vite manifest not found` | Frontend not built | `cd frontend && npm run build` |
| `SQLSTATE[HY000] [1045] Access denied` | Wrong DB creds in `.env` | Recheck `DB_USERNAME` / `DB_PASSWORD`, then `php artisan config:cache` |
| CORS error in browser | `FRONTEND_URLS` doesn't include the SPA origin | Add the full origin (scheme + host + port) to `FRONTEND_URLS` in `.env` |
| `/mail/send` returns 503 | Zoho env vars not set | Expected until you wire Zoho — see `backend/README.md` § Zoho Mail integration |
| Mail polling stops working | Cron not running | Check `sudo systemctl status cron` and the crontab entry from step 7 |
