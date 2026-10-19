# SCIIS web — Nuxt 3 (build context: repo root)
#
#   docker build -f infra/docker/web/Dockerfile --target dev  .
#   docker build -f infra/docker/web/Dockerfile --target prod .

# ───────────────────────── dev ─────────────────────────
FROM node:22-alpine AS dev
WORKDIR /app
ENV NUXT_HOST=0.0.0.0 NUXT_PORT=3000 NODE_ENV=development
# Source is bind-mounted by docker compose; node_modules lives in a named volume.
COPY infra/docker/web/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh
EXPOSE 3000
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["npm", "run", "dev"]

# ───────────────────────── build ─────────────────────────
FROM node:22-alpine AS build
WORKDIR /app
COPY web/package.json web/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY web/ .
# Public runtime config (NUXT_PUBLIC_*) is read at runtime by Nitro; nothing is baked here.
RUN npm run build

# ───────────────────────── prod ─────────────────────────
FROM node:22-alpine AS prod
WORKDIR /app
ENV NODE_ENV=production NITRO_HOST=0.0.0.0 NITRO_PORT=3000
RUN addgroup -S nuxt && adduser -S nuxt -G nuxt
COPY --from=build --chown=nuxt:nuxt /app/.output ./.output
USER nuxt
EXPOSE 3000
HEALTHCHECK --interval=15s --timeout=5s --start-period=20s --retries=5 \
  CMD wget -qO- http://127.0.0.1:3000/ >/dev/null || exit 1
CMD ["node", ".output/server/index.mjs"]
