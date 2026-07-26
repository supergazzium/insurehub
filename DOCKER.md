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

## CI/CD

Two GitHub Actions workflows run this repo:

- `.github/workflows/ci.yml` — on every PR and push to `main`, builds both
  Dockerfiles via `docker compose build`, boots the stack, and smoke-tests
  `GET /up` on the backend and `GET /insurehub/` on the frontend. The
  images are the exact ones Coolify will build, so a green CI run means
  "these Dockerfiles work end-to-end".
- `.github/workflows/deploy.yml` — fires only after CI succeeds on `main`.
  Calls Coolify's per-application deploy webhook for the backend and
  frontend in parallel.

### Wiring the deploy webhooks

1. In Coolify, open each Application (backend, frontend) → **Webhooks** →
   copy the **Deploy Webhook** URL. Each URL contains an auth token; treat
   it like a secret.
2. In GitHub → repo **Settings → Secrets and variables → Actions → New
   repository secret**, add:
   - `COOLIFY_BACKEND_WEBHOOK` = backend deploy URL
   - `COOLIFY_FRONTEND_WEBHOOK` = frontend deploy URL

That's it. Push to `main` → CI builds and smoke-tests → on success, both
Coolify apps redeploy. If CI fails, no deploy is triggered.

### Why not the GitHub-integration option in Coolify?

Coolify can also auto-deploy on `git push` directly, without CI. Skip that
mode: it will deploy broken builds because nothing has run the images
before Coolify tries to. The webhook-gated-by-CI setup here is one extra
config step and buys you a real safety net.

## Vite base path

`vite.config.ts` sets `base: '/insurehub/'`, so the frontend serves at
`https://app.example.com/insurehub/`. If you want it at the root instead,
change the base to `'/'` in `vite.config.ts` AND update
`frontend/docker/nginx.conf` (drop the `/insurehub/` prefix from every
`location` block and copy `dist` → `/usr/share/nginx/html` instead of
`/usr/share/nginx/html/insurehub` in `frontend/Dockerfile`).
