# Deploying Robin IT

## The queue worker is not optional

Order confirmation, order status update and welcome emails are all queued
(`ShouldQueue`). Checkout deliberately does not wait for mail to send — a slow
SMTP server must never fail a customer's order — which means:

**If no queue worker is running, orders still succeed and customers silently
receive nothing.** No error is raised anywhere. This has already happened once
in development: a welcome email sat in the `jobs` table for 14 hours.

Install one of the two supervisors below on every environment that takes real
orders.

> **On shared hosting** (Hostinger and similar) none of this works — those hosts
> kill long-running processes. The queue is drained from cron there instead.
> See [shared-hosting.md](shared-hosting.md), and set
> `QUEUE_RUN_VIA_SCHEDULER=true` rather than installing a supervisor.
>
> **robinscomputer.com runs on Namecheap Stellar** — see
> [namecheap.md](namecheap.md). Four of its rules contradict the Hostinger
> guide above: cron cannot run more often than every five minutes, the main
> domain's document root cannot be moved, PHP must be 8.4, and AutoSSL is
> unavailable. Deploys there are automated by
> [`.github/workflows/deploy.yml`](../.github/workflows/deploy.yml).

### Option A — supervisor

```bash
sudo cp deploy/supervisor/robin-it-worker.conf /etc/supervisor/conf.d/
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status robin-it-worker:*
```

### Option B — systemd

```bash
sudo cp deploy/systemd/robin-it-worker.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now robin-it-worker
sudo systemctl status robin-it-worker
```

Both configs assume the app lives at `/var/www/robin-it` and runs as
`www-data`. Change the paths and user to match the host.

## The scheduler

The scheduler runs the queue health check every five minutes and prunes old
job records. Add one cron entry:

```cron
* * * * * cd /var/www/robin-it && php artisan schedule:run >> /dev/null 2>&1
```

## Checking it works

```bash
php artisan queue:health
```

Healthy:

```
Queue is clear.
```

Broken:

```
1 job(s) have been waiting 14h with no worker picking them up.
Customer emails are not being delivered. Start the queue worker.
```

The command exits non-zero when unhealthy, so it can be used as a monitoring
probe directly. `--json` gives machine-readable output; `--quiet-when-healthy`
prints nothing unless something is wrong, which is what the scheduler uses.

The admin dashboard shows the same warning as a banner, so it is visible
without SSH access.

## After every deploy

Workers hold the old code in memory until they restart:

```bash
php artisan queue:restart
```

Run this **after** the new code is in place. Supervisor and systemd will start
fresh workers automatically.

## Handling failures

A job that fails three times lands in `failed_jobs`:

```bash
php artisan queue:failed          # list them
php artisan queue:retry all       # try again
php artisan queue:forget <id>     # give up on one
```

Failed jobs are pruned after seven days by the scheduler, which is long enough
to notice and retry one.
