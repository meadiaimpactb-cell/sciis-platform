#!/bin/sh
set -e
cd /app
if [ ! -d node_modules/.package-lock.json ] && [ ! -f node_modules/.package-lock.json ] && [ -f package.json ]; then
  echo "[entrypoint] npm ci (first run)"
  if [ -f package-lock.json ]; then npm ci --no-audit --no-fund; else npm install --no-audit --no-fund; fi
fi
exec "$@"
