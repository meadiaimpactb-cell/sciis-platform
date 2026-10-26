# Hostinger deployment (sciis.org)

Shared hosting has PHP 8.3 but no long-running Node.js, so production runs as:

| Part | Where | How |
|---|---|---|
| Laravel API | `~/domains/sciis.org/sciis-api/` (outside the web root) | PHP 8.3 via `AddHandler … php83` in `.htaccess` |
| API entry | `public_html/laravel.php` | `.htaccess` routes `/api/*`, `/sanctum/*`, `/up` to it |
| Website | `public_html/*.html`, `_nuxt/` | `SCIIS_STATIC=1 nuxi generate`: public pages prerendered (SEO), portal is a SPA (`200.html`) |
| Uploads | `public_html/storage` → `../sciis-api/storage/app/public` | symlink |
| Database | MySQL `u153052345_sciis` on `localhost` | credentials only in the server `.env` |

Content edited in the admin panel shows to visitors immediately: after load, `plugins/static-refresh.client.ts` refetches the
page data from the API. Search engines see the HTML from the last `web` deploy, so redeploy `web` after larger content changes.
News items published after the last build open through the SPA fallback until the next `web` deploy.

## Deploy

```bash
./infra/hostinger/deploy.sh        # API + web
./infra/hostinger/deploy.sh api    # Laravel only (migrations + caches)
./infra/hostinger/deploy.sh web    # rebuild and publish the static site
```

Requires the `~/.ssh/hc_hostinger` key (or `SCIIS_SSH_KEY`). The server `.env` is never overwritten.

## One-time settings in hPanel

- **Cron job** (Advanced → Cron Jobs), every minute. It runs reminders, retention, the daily backup and the
  scheduler heartbeat shown in admin → Settings → System status:
  `/opt/alt/php83/usr/bin/php /home/u153052345/domains/sciis.org/sciis-api/artisan schedule:run`
- Once the cron runs, `QUEUE_CONNECTION=database` may replace `sync` (the scheduler drains the queue every minute).
- **Before public launch** (server `.env`, then `php artisan config:cache`):
  `SCIIS_VERIFICATION_OPTIONAL=false` (done 2026-10-25), `SCIIS_2FA_ENFORCE_STAFF=true`, SMS provider
  (`SCIIS_SMS_DRIVER=unifonic` + credentials, then `SCIIS_PHONE_VERIFICATION=true`).
- Mail: Hostinger SMTP (`smtp.hostinger.com:465`, `MAIL_SCHEME=smtps`) — credentials only in the server `.env`.

## Backups

- `php artisan backup:run` writes a gzipped DB dump (and with `--files` an archive of `storage/app`) to
  `SCIIS_BACKUP_PATH` (`~/backups/sciis` on the server), keeping `SCIIS_BACKUP_KEEP_DAYS` days. The scheduler runs
  it daily at 02:30 Riyadh time (weekly with files); `deploy.sh api` runs it before every migration.
- Restore: `gunzip -c sciis-db-YYYYmmdd-HHMMSS.sql.gz | mysql --defaults-extra-file=<cnf> <database>`.
- The original `public_html` content is kept in `~/hc-backups/sciis.org-20261020-144939/`.
