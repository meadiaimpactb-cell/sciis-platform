#!/usr/bin/env bash
# Deploys SCIIS to Hostinger shared hosting (sciis.org) over SSH.
#   ./infra/hostinger/deploy.sh            # API + web
#   ./infra/hostinger/deploy.sh api        # Laravel only
#   ./infra/hostinger/deploy.sh web        # static Nuxt build only (re-run after content changes for SEO snapshots)
#
# Server layout (see README.md):
#   ~/domains/sciis.org/sciis-api/   Laravel app (outside the web root; .env lives ONLY on the server)
#   ~/domains/sciis.org/public_html/ static Nuxt site + laravel.php front controller + .htaccess + storage symlink
set -euo pipefail

TARGET="${1:-all}"
HOST="u153052345@185.166.36.143"
PORT=65002
KEY="${SCIIS_SSH_KEY:-$HOME/.ssh/hc_hostinger}"
PHP=/opt/alt/php83/usr/bin/php
REMOTE=domains/sciis.org
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

SSH=(ssh -i "$KEY" -o IdentitiesOnly=yes -o BatchMode=yes -p "$PORT" "$HOST")
SCP=(scp -q -i "$KEY" -o IdentitiesOnly=yes -o BatchMode=yes -P "$PORT")

deploy_api() {
  echo "==> Packaging API"
  (cd "$ROOT/api" && tar --force-local \
    --exclude=./vendor --exclude=./node_modules --exclude=./.env --exclude=./tests --exclude=./.phpunit.cache \
    --exclude='./storage/logs/*' --exclude='./storage/framework/cache/data/*' --exclude='./storage/framework/sessions/*' \
    --exclude='./storage/framework/views/*' --exclude='./storage/framework/testing' --exclude='./storage/app/*' \
    --exclude=./public/storage --exclude=./docker --exclude=./Dockerfile \
    -czf "$TMP/api.tgz" .)
  "${SCP[@]}" "$TMP/api.tgz" "$HOST:~/sciis-api.tgz"
  echo "==> Installing API on the server"
  # The trap brings the site back up even when a step fails (set -e would otherwise leave it in maintenance mode).
  # No view:cache: the API has no resources/views of its own (vendor mail views compile on first use).
  "${SSH[@]}" "set -e; cd ~/$REMOTE/sciis-api; $PHP artisan down --retry=30 || true; trap '$PHP artisan up' EXIT; \
    tar -xzf ~/sciis-api.tgz -C . && rm ~/sciis-api.tgz; \
    $PHP /usr/local/bin/composer install --no-dev --optimize-autoloader --no-interaction --no-progress; \
    $PHP artisan config:clear >/dev/null; $PHP artisan backup:run; \
    $PHP artisan migrate --force --no-interaction; \
    $PHP artisan config:cache && $PHP artisan route:cache"
  "${SCP[@]}" "$ROOT/infra/hostinger/laravel.php" "$HOST:~/$REMOTE/public_html/laravel.php"
  "${SCP[@]}" "$ROOT/infra/hostinger/public_html.htaccess" "$HOST:~/$REMOTE/public_html/.htaccess"
}

deploy_web() {
  # Build secret that exempts the prerender's API GETs from the per-IP rate limits (server .env SCIIS_PRERENDER_TOKEN,
  # created on first use). It only lives in the server .env and this build process — never in the generated files.
  local token
  token="$("${SSH[@]}" "cd ~/$REMOTE/sciis-api; if ! grep -q '^SCIIS_PRERENDER_TOKEN=' .env; then \
    printf '\nSCIIS_PRERENDER_TOKEN=%s\n' \"\$(head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n')\" >> .env; \
    $PHP artisan config:cache >/dev/null; fi; grep '^SCIIS_PRERENDER_TOKEN=' .env | cut -d= -f2-" 2>/dev/null)"

  echo "==> Building static site (prerendered public pages + SPA portal) against the live API"
  # .nuxt/cache holds Nitro's cached handlers (e.g. the sitemap) from earlier builds — start every build clean.
  (cd "$ROOT/web" && rm -rf .output .nuxt/cache && \
    SCIIS_STATIC=1 NODE_ENV=production NUXT_PUBLIC_API_BASE=https://sciis.org/api/v1 NUXT_PUBLIC_API_MOCK=false \
    NUXT_PUBLIC_SITE_URL=https://sciis.org NUXT_PRERENDER_TOKEN="$token" npx nuxi generate)
  if [ -n "$token" ] && grep -rqF "$token" "$ROOT/web/.output/public"; then
    echo "!! build secret found in the generated files — aborting publish" >&2
    exit 1
  fi
  sed -i 's|^Sitemap: .*|Sitemap: https://sciis.org/sitemap.xml|' "$ROOT/web/.output/public/robots.txt"
  (cd "$ROOT/web/.output/public" && tar --force-local --exclude='*.br' --exclude='*.gz' -czf "$TMP/web.tgz" .)
  "${SCP[@]}" "$TMP/web.tgz" "$HOST:~/sciis-web.tgz"
  echo "==> Publishing static site"
  # Keeps laravel.php, .htaccess, the storage symlink and anything else that is not part of the build.
  "${SSH[@]}" "set -e; cd ~/$REMOTE/public_html; rm -rf _nuxt en auth news events legal brand; \
    tar -xzf ~/sciis-web.tgz -C . && rm ~/sciis-web.tgz"
}

case "$TARGET" in
  api) deploy_api ;;
  web) deploy_web ;;
  all) deploy_api; deploy_web ;;
  *) echo "usage: $0 [all|api|web]"; exit 1 ;;
esac

echo "==> Health: $(curl -s https://sciis.org/api/v1/health)"
