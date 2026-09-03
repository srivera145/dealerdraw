# Deployment

Single-server deployment. Nothing here needs a container orchestrator; DealerDraw is a PHP app, a
MySQL database, one queue worker and one cron entry.

---

## Server requirements

| Requirement | Notes |
| --- | --- |
| PHP 8.2+ CLI **and** FPM | Both. The queue worker and cron run under the CLI binary |
| PHP extensions | `pdo_mysql`, `mbstring`, `json`, `openssl`, `fileinfo`, `curl` |
| MySQL 8.0+ | Needs `JSON` columns. MariaDB is untested |
| nginx or Apache | Must serve existing files before falling through to `index.php` |
| Node 18+ | Build-time only, for `npm run build`. Not needed at runtime |
| Composer 2 | Build-time only |
| Outbound HTTPS | To `api.plivo.com` and `site.api.espn.com` |
| A supervisor | systemd or supervisord, to keep the queue worker alive |

**Timezone:** PHP's timezone drives the app, and `Database::connection()` pins MySQL's session
timezone to PHP's offset on connect. Set `date.timezone` in `php.ini` to the dealership-facing
timezone you want kickoff times interpreted in, and set it identically for CLI and FPM. If the two
disagree, boards lock at the wrong moment.

---

## Web root

Document root is `public_html/`. Everything above it must not be reachable.

nginx:

```nginx
server {
    listen 443 ssl http2;
    server_name dealerdraw.com;

    root /var/www/dealerdraw/public_html;
    index index.php;

    # Static files first, then the front controller. Without this, /assets/ 404s.
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    # Never serve dotfiles - .env lives one level up, but be explicit anyway.
    location ~ /\. { deny all; }

    client_max_body_size 12M;   # matches FILESYSTEM_MAX_UPLOAD_MB plus overhead
}
```

Permissions: `storage/` and `public_html/uploads/` must be writable by the web user. Nothing else
needs write access.

```bash
chown -R www-data:www-data storage public_html/uploads
chmod -R 775 storage public_html/uploads
```

---

## SSL

Required, not optional — Plivo will not post webhooks to plain HTTP, and the signature check derives
the signed URL from `APP_URL`, which must therefore be the `https://` form.

```bash
certbot --nginx -d dealerdraw.com -d www.dealerdraw.com
systemctl status certbot.timer     # confirm auto-renewal is armed
```

After issuing the certificate, set `APP_URL=https://dealerdraw.com` in `.env`. Getting this wrong is
the single most common cause of webhooks silently failing: signature verification fails closed, so
Plivo gets a 403 and delivery statuses and STOP replies stop being processed.

---

## First deploy

```bash
cd /var/www/dealerdraw
composer install --no-dev --optimize-autoloader
npm ci && npm run build
cp .env.example .env          # then edit it - see below
php database/migrate.php
```

Set at minimum in `.env`:

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://dealerdraw.com
DB_*                          # real credentials
MAIL_MAILER=smtp
MAIL_*                        # real SMTP credentials
PLIVO_AUTH_ID / PLIVO_AUTH_TOKEN / PLIVO_SRC_NUMBER
```

`APP_ENV=production` also blocks `scripts/seed-demo.php`, which writes fictional customer records.

---

## Queue worker

Winner notifications and score syncing both run through the queue. Without a worker, **no customer
is ever notified** — resolution still happens, the jobs just sit there.

`/etc/systemd/system/dealerdraw-worker.service`:

```ini
[Unit]
Description=DealerDraw queue worker
After=network.target mysql.service

[Service]
User=www-data
WorkingDirectory=/var/www/dealerdraw
ExecStart=/usr/bin/php /var/www/dealerdraw/database/queue-work.php
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

```bash
systemctl daemon-reload
systemctl enable --now dealerdraw-worker
systemctl status dealerdraw-worker
journalctl -u dealerdraw-worker -f      # jobs log failures here
```

One worker is enough. Jobs are reserved with `SELECT ... FOR UPDATE`, so a second worker is safe but
unnecessary at this scale. A job that fails five times moves to `failed_jobs` — check that table when
something did not arrive.

---

## Cron

```cron
# Queue a score-sync run. Safe every minute: it queues nothing while a run is
# already pending, and the chain stops itself when no game window is open.
* * * * * cd /var/www/dealerdraw && /usr/bin/php database/dispatch-score-sync.php >> storage/logs/cron.log 2>&1

# Import next week's schedule. Adjust the day to suit the league.
0 6 * * TUE cd /var/www/dealerdraw && /usr/bin/php scripts/sync-games.php --league=nfl --week=$(date +\%V) >> storage/logs/cron.log 2>&1
```

The weekly import's `--week` needs to be the football week, not the ISO week — run it by hand with
the right number rather than trusting the `date` expression above at season boundaries.

---

## Plivo configuration

In the Plivo console, on the number in `PLIVO_SRC_NUMBER`:

| Setting | Value |
| --- | --- |
| Message status callback | `https://dealerdraw.com/webhooks/plivo/status` (method POST) |
| Inbound message URL | `https://dealerdraw.com/webhooks/plivo/inbound` (method POST) |

Both endpoints verify the Plivo V2 signature and **fail closed** — an unsigned or wrongly signed
request gets a 403. The signature is computed over the exact URL Plivo was configured with, rebuilt
server-side from `APP_URL`, so the two must match character for character including the scheme.

If `PLIVO_STATUS_CALLBACK_URL` is left blank the app sends `APP_URL + /webhooks/plivo/status` with
every message, which is usually what you want.

**10DLC registration must be complete before production texting.** Unregistered A2P traffic is
filtered by US carriers with no error returned — messages report as sent and simply never arrive.
See [COMPLIANCE.md](COMPLIANCE.md).

---

## Routine deploy

```bash
cd /var/www/dealerdraw
git fetch --all
git log --oneline HEAD..origin/main          # read what is about to land
php database/migrate.php --pretend           # see pending migrations first

git pull --ff-only origin main
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php database/migrate.php
systemctl restart dealerdraw-worker          # picks up new job classes
```

Restarting the worker matters: a long-running PHP process holds the old class definitions.

---

## Rollback

Code rolls back cleanly. Migrations do not — there are no down-migrations, by choice: this app holds
customer entries and issued redemption codes, and an automated reverse migration is a good way to
lose them.

**Code-only rollback** (the common case):

```bash
cd /var/www/dealerdraw
git log --oneline -10
git checkout <previous-good-sha>
composer install --no-dev --optimize-autoloader
npm ci && npm run build
systemctl restart dealerdraw-worker
```

Safe as long as the release you are rolling back to does not predate an applied migration that
removed or renamed a column it reads.

**If a migration has to come out**, do it deliberately:

1. Take a backup first — always, before anything else.
   ```bash
   mysqldump --single-transaction --routines dealerdraw > /var/backups/dealerdraw-$(date +%F-%H%M).sql
   ```
2. Stop the worker so nothing writes mid-repair: `systemctl stop dealerdraw-worker`.
3. Write the reverse SQL by hand and apply it.
4. Delete that migration's row so it is not considered applied:
   ```sql
   DELETE FROM migrations WHERE name = '0NN_the_migration.sql';
   ```
5. Roll the code back, then start the worker.

Take a `mysqldump` before every deploy that includes a migration. Restoring is
`mysql dealerdraw < backup.sql`.

---

## Health checks

```bash
curl -sS https://dealerdraw.com/up                    # app + database
systemctl is-active dealerdraw-worker                 # worker alive
```

Useful queries when something looks wrong:

```sql
-- Jobs piling up means the worker is down.
SELECT COUNT(*) FROM jobs;
SELECT job_class, failed_at, LEFT(exception, 200) FROM failed_jobs ORDER BY id DESC LIMIT 10;

-- Games the score feed has given up on. sync_alert is raised after three
-- consecutive failures; usually a wrong external_id.
SELECT id, home_team, away_team, sync_failure_count, last_sync_error
FROM games WHERE sync_alert = 1;

-- Winners resolved but never notified.
SELECT id, board_id, sms_status, email_status, notify_error
FROM wins WHERE notified_at IS NULL;
```
