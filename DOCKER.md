# Docker deployment

InsureHub ships as two Docker images:

| Image | Path | Base | Runtime |
|---|---|---|---|
| Backend | `backend/Dockerfile` | `dunglas/frankenphp:1-php8.4-alpine` | FrankenPHP (Caddy + PHP 8.4 + JIT) on :80 |
| Frontend | `frontend/Dockerfile` | `nginx:1.27-alpine` (multi-stage from `node:22-alpine`) | nginx serving the Vite build on :80 |

The images are self-contained: no separate nginx/php-fpm to orchestrate, no
node in the frontend runtime.

## Local development

```bash
cp backend/.env.example backend/.env
docker compose run --rm backend php artisan key:generate --show
# paste the printed value into backend/.env as APP_KEY=…

docker compose up --build
```

- Backend at http://localhost:8080
- Frontend at http://localhost:5173/insurehub/
- MySQL at 127.0.0.1:3306 (`root/rootpw` or `insurehub/insurehub`)

Migrations run automatically at container start (see
`backend/docker/entrypoint.sh`).

## Coolify

Create **two Applications** in Coolify, both pointing at this repo:

### Backend

- **Base directory**: `backend`
- **Build pack**: Dockerfile
- **Dockerfile location**: `Dockerfile` (relative to base dir)
- **Port**: 80
- **Health check**: `/up` (Laravel's default)
- **Environment variables**:
  - `APP_KEY` — generate once with `php artisan key:generate --show`
  - `APP_ENV=production`
  - `APP_DEBUG=false`
  - `APP_URL=https://api.example.com` (whatever host Coolify assigns)
  - `DB_CONNECTION=mysql`, `DB_HOST=…`, `DB_PORT=3306`, `DB_DATABASE=…`,
    `DB_USERNAME=…`, `DB_PASSWORD=…`
  - `SESSION_DRIVER=database`, `CACHE_STORE=database`,
    `QUEUE_CONNECTION=database`
  - Any mail / third-party creds you use

### Frontend

- **Base directory**: `frontend`
- **Build pack**: Dockerfile
- **Dockerfile location**: `Dockerfile`
- **Port**: 80
- **Health check**: `/insurehub/`
- **Build args** (Configuration → Build Args, applied at `docker build` time):
  - `VITE_API_BASE_URL=https://api.example.com/api/v1`
  - `VITE_APP_URL=https://app.example.com`

**Important**: Vite bakes `VITE_*` variables into the JS bundle at build
time. Changing them requires rebuilding — a runtime env change won't help.

## Extensions / customization

The backend image ships with `pdo_mysql, mbstring, intl, gd, zip, opcache,
bcmath, exif, pcntl`. Additional extensions (e.g. `imagick` for higher-
fidelity PDF rendering) can be added to the `install-php-extensions` line
in `backend/Dockerfile`.

Queue worker and scheduler aren't run inside the web container — that would
compete with the request loop and lose jobs on restarts. Add them as
**separate Coolify Applications** pointing at the same Dockerfile, with:

- Queue worker: `CMD ["php", "artisan", "queue:work", "--tries=3", "--sleep=1"]`
- Scheduler: run a 1-min loop of `php artisan schedule:run` (Coolify has a
  "Scheduled Task" primitive for this — no image change needed).

## Vite base path

`vite.config.ts` sets `base: '/insurehub/'`, so the frontend serves at
`https://app.example.com/insurehub/`. If you want it at the root instead,
change the base to `'/'` in `vite.config.ts` AND update
`frontend/docker/nginx.conf` (drop the `/insurehub/` prefix from every
`location` block and copy `dist` → `/usr/share/nginx/html` instead of
`/usr/share/nginx/html/insurehub` in `frontend/Dockerfile`).
