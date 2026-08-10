# syntax=docker/dockerfile:1.7

# ─── Stage 1: build the Vue app with Vite ────────────────────────────────
FROM node:22-alpine AS build

WORKDIR /app

# Copy manifest first — npm ci is deterministic and its layer survives
# code-only changes.
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

# Vite reads VITE_* env vars at build time. Pass them in via
# `--build-arg` from Coolify (Configuration → Build Args).
ARG VITE_API_BASE_URL
ARG VITE_APP_URL
ENV VITE_API_BASE_URL=${VITE_API_BASE_URL}
ENV VITE_APP_URL=${VITE_APP_URL}

COPY . .

# `npm run build` = vue-tsc -b && vite build. Typecheck runs in-container
# so a bad type slips out at build time, not at runtime.
RUN npm run build

# ─── Stage 1b (optional): dev server with HMR ────────────────────────────
# Selected by compose.dev.yaml. Reuses the `build` stage's node_modules
# layer so `docker compose --profile ... up` doesn't need to reinstall.
# The source tree is bind-mounted at runtime by compose.dev.yaml — nothing
# is baked into this stage's filesystem beyond node_modules.
#
# NOT used by Coolify: prod builds target the `runtime` stage below.
FROM node:22-alpine AS dev

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

# Bind mount from compose provides src/, index.html, vite.config.ts, etc.
# --host 0.0.0.0 so the container port is reachable from the host.
# --strictPort so we fail loudly if 5173 is already bound instead of
# silently switching to 5174 (which the host mapping wouldn't route to).
EXPOSE 5173
CMD ["npx", "vite", "--host", "0.0.0.0", "--port", "5173", "--strictPort"]

# ─── Stage 2: serve the built SPA with nginx ─────────────────────────────
FROM nginx:1.27-alpine AS runtime

# Bangkok timezone so access-log timestamps match the ops team's clock.
RUN apk add --no-cache tzdata \
    && cp /usr/share/zoneinfo/Asia/Bangkok /etc/localtime \
    && echo "Asia/Bangkok" > /etc/timezone \
    && apk del tzdata

# Nginx config — SPA fallback + gzip + long-cache for hashed assets.
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf

# Copy Vite's build output. The vite.config.ts has `base: '/insurehub/'`,
# so all asset URLs are prefixed with /insurehub/. We mirror that on
# disk so nginx serves them at the same path.
COPY --from=build /app/dist /usr/share/nginx/html/insurehub

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
    CMD wget -qO- http://127.0.0.1/insurehub/ >/dev/null || exit 1

CMD ["nginx", "-g", "daemon off;"]
