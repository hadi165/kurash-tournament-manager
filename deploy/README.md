# Deploying to a VPS

Written for a fresh Ubuntu 24.04 box. Roughly 30 minutes end to end.

## Why a VPS rather than shared DirectAdmin

Shared hosting is fine for the work that happens *before* competition day —
registration, weigh-in, draws. It cannot do four things this system wants on
the day itself:

| | Shared DirectAdmin | VPS |
| --- | --- | --- |
| Queue workers | per-minute cron, so a scoreboard push waits up to 60s | instant, via systemd |
| Redis | not available | cache, sessions and queue |
| WebSockets | not available | Laravel Reverb for live screens |
| CPU | shared with strangers | yours |

The trigger to move is your first event with public live screens.

## 1. Packages

```bash
sudo apt update && sudo apt install -y \
    nginx mariadb-server redis-server git unzip curl \
    php8.3-fpm php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl \
    php8.3-zip php8.3-gd php8.3-bcmath php8.3-intl php8.3-redis

curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash - && sudo apt install -y nodejs
curl -sS https://getcomposer.org/installer | php && sudo mv composer.phar /usr/local/bin/composer
```

`php8.3-gd` is not optional if you later swap CSV exports for real `.xlsx`;
PhpSpreadsheet requires it.

## 2. Database

```bash
sudo mysql_secure_installation

sudo mariadb -e "
CREATE DATABASE kurash CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'kurash'@'localhost' IDENTIFIED BY 'PUT-A-REAL-PASSWORD-HERE';
GRANT ALL PRIVILEGES ON kurash.* TO 'kurash'@'localhost';
FLUSH PRIVILEGES;"
```

`utf8mb4` matters: athlete names are Persian, Cyrillic and Turkish, and the
older `utf8` is a three-byte subset that silently truncates.

## 3. Application user and layout

```bash
sudo adduser --system --group --home /var/www/kurash kurash
sudo mkdir -p /var/www/kurash/{releases,shared/storage}
sudo chown -R kurash:kurash /var/www/kurash
```

Releases are built in `releases/<timestamp>/` and published by moving the
`current` symlink, so the site is never serving a half-installed tree.
`shared/` holds the two things that must survive a release: `.env` and
`storage/`.

## 4. Configuration

```bash
sudo -u kurash cp deploy/.env.production.example /var/www/kurash/shared/.env
sudo -u kurash nano /var/www/kurash/shared/.env      # fill in every REPLACE_ME
sudo -u kurash php artisan key:generate              # run from a checkout
sudo chmod 600 /var/www/kurash/shared/.env
```

`SCOREBOARD_WEBHOOK_SECRET` has no default and no fallback. Leave it unset and
the webhook returns 503 to everything — that is deliberate, so a forgotten
secret fails closed rather than leaving the endpoint open.

## 5. PHP-FPM pool

Give the app its own pool so a runaway request cannot starve anything else:

```bash
sudo cp /etc/php/8.3/fpm/pool.d/www.conf /etc/php/8.3/fpm/pool.d/kurash.conf
sudo sed -i 's/^\[www\]/[kurash]/; s|^user = .*|user = kurash|; s|^group = .*|group = kurash|; \
  s|^listen = .*|listen = /run/php/php8.3-fpm-kurash.sock|' /etc/php/8.3/fpm/pool.d/kurash.conf
sudo systemctl restart php8.3-fpm
```

## 6. Web server, worker and scheduler

```bash
sudo cp deploy/nginx.conf /etc/nginx/sites-available/kurash
sudo ln -s /etc/nginx/sites-available/kurash /etc/nginx/sites-enabled/
sudo sed -i 's/kurash.example.org/YOUR-DOMAIN/' /etc/nginx/sites-available/kurash
sudo nginx -t && sudo systemctl reload nginx

sudo cp deploy/kurash-worker.service deploy/kurash-scheduler.service deploy/kurash-scheduler.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now kurash-worker kurash-scheduler.timer
```

## 7. First release

```bash
sudo cp deploy/deploy.sh /var/www/kurash/deploy.sh
sudo chmod +x /var/www/kurash/deploy.sh
sudo -u kurash /var/www/kurash/deploy.sh
```

Then create the first account — deliberately from the command line, so it is
not whoever reaches the sign-up page first:

```bash
cd /var/www/kurash/current
sudo -u kurash php artisan kurash:create-admin --email=you@example.org
```

The password is generated and printed once.

## 8. TLS

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d YOUR-DOMAIN
```

Certbot rewrites the nginx file to add the TLS block and installs its own
renewal timer.

## Day-to-day

| Task | Command |
| --- | --- |
| Deploy | `sudo -u kurash /var/www/kurash/deploy.sh` |
| Roll back | `sudo -u kurash /var/www/kurash/deploy.sh --rollback` |
| Backup now | `php artisan kurash:backup --label=before-session-2` |
| Watch the worker | `journalctl -u kurash-worker -f` |
| Watch the app | `php artisan pail` |
| Check the timer | `systemctl list-timers kurash-scheduler.timer` |

Take a labelled backup **before and after every competition session**. The
nightly one at 03:00 is the floor, not the plan — competition data changes in
bursts over a weekend and then not at all for weeks.

Backups land in `shared/storage/app/backups` and are pruned to the most recent
30. They are on the same machine as the database, which protects you from a
bad migration but not from losing the machine: copy them off with

```bash
rsync -az kurash@server:/var/www/kurash/shared/storage/app/backups/ ./backups/
```

Restoring is a plain gzip stream:

```bash
zcat kurash-…-before-session-2.sql.gz | mysql -u kurash -p kurash
```

## Once you outgrow this

Switch `CACHE_STORE`, `SESSION_DRIVER` and `QUEUE_CONNECTION` to `redis` — it
is already installed above, and the change is three lines in `.env`. Add
Laravel Reverb when you want live screens to be pushed rather than polled.
