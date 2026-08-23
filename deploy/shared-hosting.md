# Deploying to shared hosting (Hostinger and similar)

Yes, this runs on Hostinger shared hosting. Nothing in the application needs
`exec`, `proc_open` or a persistent process at runtime — session, cache and
queue all use MySQL, so there is no Redis to install.

The one thing that genuinely does not work is the **queue worker as a daemon**.
Shared hosts kill long-running processes, so `queue:work` under supervisor is
not available. The queue is drained from cron instead, which this codebase
supports directly.

## What your plan needs

| Requirement | Why |
|---|---|
| **PHP 8.3 or newer** | `composer.json` requires `^8.3`. Selectable in hPanel. |
| **MySQL** | Sessions, cache, queue and all application data. |
| **Cron** | Runs the scheduler, which drains the queue. |
| **SSH** | For `composer install` and `artisan`. Hostinger includes it on Premium and Business, not on Single. |

**Without SSH** you would have to upload `vendor/` and run migrations through
phpMyAdmin, and you could not run `artisan` at all — no cache clearing, no
storage link, no queue. Treat SSH as required rather than optional.

## Deploy

### 1. Build the frontend locally

`public/build` is gitignored, so a git-only deploy ships **no compiled CSS or
JavaScript** and every page renders unstyled. Node is not reliably available on
shared hosting, so build on your machine and upload the result:

```bash
npm ci
npm run build          # writes public/build, about 1.2 MB
```

Upload `public/build/` alongside the code. Repeat this on **every** deploy that
touches anything under `resources/js` or `resources/css`.

### 2. Put the code on the server

Upload to a directory **outside** the web root, for example:

```
/home/uXXXXXX/domains/yourdomain.com/robin-it
```

Then point the domain's document root at `robin-it/public` in hPanel
(*Websites → Manage → Website settings*).

If your plan will not let you change the document root, the fallback is to move
the contents of `public/` into `public_html` and edit the two `require` paths in
`public_html/index.php` to point up at the application directory. It works, but
it means re-doing that edit on every deploy — changing the document root is
much cleaner if the plan allows it.

### 3. Install and configure

```bash
cd ~/domains/yourdomain.com/robin-it

composer install --no-dev --optimize-autoloader

cp .env.example .env
php artisan key:generate
```

Edit `.env`:

```dotenv
APP_ENV=production
APP_DEBUG=false                  # never true in production
APP_URL=https://yourdomain.com

DB_CONNECTION=mysql
DB_HOST=localhost
DB_DATABASE=uXXXXXX_robinit
DB_USERNAME=uXXXXXX_robinit
DB_PASSWORD=...

# Shared hosting: cron drains the queue, because no daemon can survive here.
QUEUE_RUN_VIA_SCHEDULER=true
QUEUE_SCHEDULER_MAX_SECONDS=55
```

Then:

```bash
php artisan migrate --force
php artisan storage:link       # uploaded logos and product images need this
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

SMTP is configured in the admin (*Site Settings → Email & SMTP*), not in `.env`
— those settings override the file at runtime, and the password is encrypted at
rest.

### 4. The one cron entry

In hPanel, *Advanced → Cron Jobs*, every minute:

```
cd /home/uXXXXXX/domains/yourdomain.com/robin-it && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

Check the PHP path with `which php` — on Hostinger it is often version-specific,
such as `/usr/bin/php8.3`. Using the wrong binary is the most common reason
this silently does nothing.

That single entry runs everything: draining the queue, the worker health check,
and pruning old job records.

**If your plan restricts cron to longer intervals** — say every 15 minutes —
set the thresholds to match, or every tick will look like an outage:

```dotenv
QUEUE_SCHEDULER_MAX_SECONDS=800
QUEUE_STALLED_AFTER=1200
```

Customers would then wait up to 15 minutes for a confirmation email. That is
usually acceptable; if it is not, that is the point to move to a VPS.

### 5. Check it worked

```bash
php artisan queue:health
```

Expect `Queue is clear.` Place a test order and confirm the email arrives within
one cron interval. The admin dashboard shows a red banner if the queue stalls,
so you do not need to keep checking by hand.

## Redeploying

```bash
cd ~/domains/yourdomain.com/robin-it
git pull                            # or re-upload
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart
```

Plus re-upload `public/build` if any frontend file changed.

## Known limits on shared hosting

- **No daemon worker.** Mail is delivered on the next cron tick rather than
  within seconds. Everything else behaves identically.
- **Execution time caps.** `QUEUE_SCHEDULER_MAX_SECONDS` keeps each drain well
  inside the limit; do not raise it past the cron interval.
- **Entry-process limits.** A busy shop can hit the concurrent-process cap. If
  `queue:health` starts reporting a growing pending count that never clears,
  the host is the bottleneck and it is time for a VPS.
- **No Node at runtime.** Build locally; nothing on the server needs it.
