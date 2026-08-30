# Deploying to Namecheap shared hosting (Stellar / cPanel)

This is the live setup for **robinscomputer.com**. It differs from
[shared-hosting.md](shared-hosting.md) — which was written for Hostinger — in
four ways that each cost an hour to discover.

Ongoing deploys are automated by
[`.github/workflows/deploy.yml`](../.github/workflows/deploy.yml). This document
is the one-time setup behind it, and the reference for when something breaks.

## The four Namecheap-specific rules

| | Namecheap | What the other doc says |
|---|---|---|
| **Cron floor** | 5 minutes minimum, 5 jobs maximum (acceptable-use policy) | `* * * * *` |
| **Document root** | Locked for the account's main domain — no field to edit | "point the domain at `public`" |
| **SSH** | Off by default, port **21098**, enabled *inside cPanel* | assumed available |
| **SSL** | AutoSSL is disabled; Namecheap's own plugin issues the certificate | Run AutoSSL |

## Production facts

| | |
|---|---|
| cPanel user | `robicxns` |
| Server | `server178.web-hosting.com`, IP `68.65.120.205` |
| App path | `/home/robicxns/robin-it` |
| Document root | `/home/robicxns/public_html` → symlink → `robin-it/public` |
| PHP | **8.4** (see below — 8.3 will not install) |
| Database | `robicxns_robinit` |
| SSH | `ssh -p 21098 robicxns@server178.web-hosting.com` |

## PHP must be 8.4, not 8.3

`composer.json` says `"php": "^8.3"`, but `composer.lock` pins Symfony 8.1,
which requires `php >=8.4.1`. **The lock is stricter than the manifest.** On 8.3
`composer install` stops at `Verifying lock file contents` and installs nothing.

Set it in *cPanel → Exclusive for Namecheap Customers → Select PHP Version*.

> **The extension list is per PHP version.** Switching 8.3 → 8.4 does not carry
> your extensions over; 8.4 starts from its own defaults. After any version
> change, re-enable:
>
> `bcmath` `ctype` `curl` `dom` `fileinfo` `json` `mbstring` `openssl` `pdo`
> `pdo_mysql` `session` `tokenizer` `xml` `zip`
>
> `pdo_mysql` will refuse to tick as "conflicting" — that is correct and needs
> no action. CloudLinux ships `nd_pdo_mysql` (mysqlnd-based), which registers
> itself as `pdo_mysql`. Only one of the pair can load. Verify with `php -m`,
> never with the checkboxes.

`intl` is not required: nothing in the app uses `Number::`, `NumberFormatter`
or `IntlDateFormatter`. Its absence only breaks `php artisan db:show`.

## The document root cannot be moved

cPanel refuses to change the document root of the account's **main** domain,
and robinscomputer.com is it — *Domains → Manage* shows
"No configuration options currently exist". There is nothing to click.

Replace `public_html` with a symlink instead:

```bash
cd ~
mv public_html public_html.bak
ln -s /home/robicxns/robin-it/public public_html
```

cPanel still believes the document root is `/public_html`; that path now
resolves through the link. AutoSSL-style `.well-known` validation still works,
because Laravel's `.htaccess` serves real files before rewriting to
`index.php`.

## The database engine must be forced

MariaDB here creates tables whose index prefix limit is **1000 bytes**.
`password_reset_tokens` makes `email varchar(255)` its primary key, which under
`utf8mb4` is 255 × 4 = 1020 bytes:

```
SQLSTATE[42000]: 1071 Specified key was too long; max key length is 1000 bytes
```

Worse, it fails *mid-migration*: `users` gets created, `password_reset_tokens`
does not, and the migration is never recorded — so the next run reports
`Table 'users' already exists` and the real cause is two errors back.

Fixed in [`config/database.php`](../config/database.php):

```php
'engine' => env('DB_ENGINE', 'InnoDB ROW_FORMAT=DYNAMIC'),
```

`DYNAMIC` raises the limit to 3072 bytes. Do not work around this with
`Schema::defaultStringLength(191)` unless a host makes the engine
unsettable — it narrows indexed columns application-wide.

## SSL: the Namecheap plugin, not AutoSSL

AutoSSL is disabled on all Namecheap shared servers. *SSL/TLS Certificates →
Wizard* reports "no SSL/TLS products available", and that is expected.

Stellar includes 50 free 1-year PositiveSSL certificates through
*cPanel → Exclusive for Namecheap Customers → **Namecheap SSL***. Sign in with
the Namecheap account and it installs automatically, within about 30 minutes.

**It only issues once the domain already resolves to this server.** A domain
still pointing elsewhere leaves the certificate stuck at "In progress".

DNS is at the registrar, not here: *namecheap.com → Domain List → Advanced
DNS*. cPanel's **Zone Editor does nothing** — the nameservers are
`dns1/dns2.registrar-servers.com`, so this server's zone file is never
consulted.

| Type | Host | Value |
|---|---|---|
| A | `@` | `68.65.120.205` |
| CNAME | `www` | `robinscomputer.com.` |

Once the certificate exists, turn on *Domains → Force HTTPS Redirect* rather
than editing `.htaccess`.

## Cron: every five minutes, not every minute

```cron
*/5 * * * * cd /home/robicxns/robin-it && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
```

`everyMinute()` in [`routes/console.php`](../routes/console.php) still fires on
each tick, so the queue drains normally. Two `.env` values must match the wider
interval or **every tick reports a false queue outage** on the admin dashboard:

```dotenv
QUEUE_RUN_VIA_SCHEDULER=true
QUEUE_SCHEDULER_MAX_SECONDS=240   # must finish inside the 300s gap
QUEUE_STALLED_AFTER=900           # a job waiting for the next tick is not a dead worker
```

The cost: order confirmation emails arrive up to five minutes after checkout.
Orders themselves are never delayed.

`/usr/local/bin/php` follows the PHP Selector, so it stays correct across
version changes. A wrong binary here fails silently — the usual cause of "the
site works but no email ever arrives".

## Seeding a live shop

**Never run bare `php artisan db:seed`.** `DatabaseSeeder` creates
`admin@robinit.com` with the password `password`, a demo customer, and three
fake orders that land in the admin revenue figures. Those credentials are
committed to this repo.

Run the seeders individually:

```bash
php artisan db:seed --class=RoleSeeder --force           # required
php artisan db:seed --class=ContentPageSeeder --force    # required
php artisan db:seed --class=DynamicContentSeeder --force # banners, stores, SiteSetting
php artisan db:seed --class=CatalogSeeder --force        # optional demo catalogue
php artisan db:seed --class=CaseAndCoolerSeeder --force  # optional extra products
```

`DynamicContentSeeder` is **not** in `DatabaseSeeder`'s call list, so a full
`db:seed` still leaves the homepage with no banners. It also creates
`SiteSetting`, where SMTP credentials are stored — the admin's *Email & SMTP*
screen has nothing to write into until it has run.

Every section of it begins with `truncate()`. Run it **before** entering real
data, never after.

Then create a real administrator:

```bash
php artisan tinker <<'PHP'
$pass = Illuminate\Support\Str::password(20);
$u = App\Models\User::updateOrCreate(
    ['email' => 'you@robinscomputer.com'],
    ['name' => 'Your Name', 'phone' => '017XXXXXXXX',
     'password' => Illuminate\Support\Facades\Hash::make($pass)]
);
$u->assignRole(App\Models\User::ROLE_ADMIN)->save();
$u->forceFill(['email_verified_at' => now(), 'is_active' => true])->save();
echo "\n  {$u->email}\n  {$pass}\n";
PHP
```

## Composer is not preinstalled

```bash
mkdir -p ~/bin      # already on PATH
curl -sS https://getcomposer.org/installer | php -- --install-dir=$HOME/bin --filename=composer
```

The deploy workflow builds `vendor/` in CI and ships it, so this is only needed
for manual work on the server.

## Symptoms and causes

| What you see | Almost always |
|---|---|
| Unstyled page | `public/hot` reached production, or `public/build` did not |
| `1071 key too long` | `'engine' => null` — see above |
| `Table 'users' already exists` | leftovers from a migration that failed two errors ago |
| `1701 Cannot truncate` | a seeder's `truncate()` on a table an FK points at |
| Emails never arrive | cron using the wrong PHP binary, or no cron at all |
| Admin banner: queue stalled | `QUEUE_STALLED_AFTER` still 300 against a 5-minute cron |
| `.env` edit does nothing | config is cached — `php artisan config:cache` |
| Browser refuses to load, no bypass | HSTS cached from a previous host; clear it at `chrome://net-internals/#hsts` |
| Certificate names `*.web-hosting.com` | Namecheap SSL has not issued yet |
