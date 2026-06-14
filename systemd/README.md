# Vormox systemd

Two ways to run the PHP frontend under systemd. Pick one.

---

## Option A — `php -S` behind systemd (quick, what your dev box was already doing)

This is what `vormox.service` in this folder does. Good for:
- Dev / staging
- Low-traffic internal admin tools
- When you don't want to install php-fpm yet

**Bad for production traffic** — PHP's built-in server is single-threaded.
One slow request blocks every other request. A fatal error kills the process
until systemd restarts it (~3 sec downtime per crash).

### Install

```bash
# 1. Make sure the project lives where the unit file expects it
ls /root/vormox/index.php           # adjust WorkingDirectory= if not here

# 2. Drop the unit
sudo cp /root/vormox/systemd/vormox.service /etc/systemd/system/
sudo systemctl daemon-reload

# 3. Start it
sudo systemctl enable --now vormox
sudo systemctl status vormox        # should say "active (running)"

# 4. Tail the logs
sudo journalctl -u vormox -f
```

### Day-to-day

```bash
sudo systemctl restart vormox       # after `git pull`
sudo systemctl stop vormox          # take it offline
sudo journalctl -u vormox -n 200    # last 200 log lines
sudo journalctl -u vormox --since "10 min ago"
```

### Hooking up nginx (your existing reverse proxy)

Nothing changes — your `/etc/nginx/sites-available/vormox.com` already does
`proxy_pass http://10.0.0.30:8000;` which is exactly what this service exposes.

---

## Option B — nginx + php-fpm (recommended for production)

`php -S` is fundamentally a dev tool. For real traffic, run **php-fpm** behind
nginx on the SAME host (no reverse-proxy hop), or behind nginx on a separate
host using TCP.

### One-time setup on the PHP host

```bash
sudo apt update
sudo apt install -y nginx php-fpm php-mysql php-curl php-ssh2 php-mbstring php-xml

# Confirm the fpm socket
ls /run/php/php*-fpm.sock
# → typically /run/php/php8.2-fpm.sock
```

### nginx site (same host as PHP)

Replace your current `proxy_pass http://10.0.0.30:8000;` block with:

```nginx
server {
    listen 80;
    server_name vormox.com www.vormox.com;
    root  /root/vormox;
    index index.php;

    client_max_body_size 50M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;   # adjust to your version
    }

    # Don't serve .env / .git / migrations / cron / includes as static
    location ~ /\.(?:git|env)|/(migrations|cron|includes)/ {
        deny all;
        return 404;
    }
}
```

`reload` nginx, then **don't** run `vormox.service` — php-fpm handles requests now:

```bash
sudo systemctl disable --now vormox
sudo systemctl reload nginx
sudo systemctl restart php8.2-fpm
```

php-fpm is multi-process, restart-safe per worker, and doesn't go down for
3 seconds every time someone hits a fatal — only that one worker dies.

---

## Cron jobs

The three cron scripts in `cron/` can run from either traditional crontab or
systemd timers. Both work; systemd timers integrate with `journalctl` which is
nicer.

### Traditional crontab (simplest)

```cron
# Every 5 min — fire lifecycle on every active panel
*/5 * * * * /usr/bin/php /root/vormox/cron/lifecycle_trigger.php   >> /var/log/vormox-cron.log 2>&1

# Every 5 min — promote pending panels once DNS points at rp_server_ip
*/5 * * * * /usr/bin/php /root/vormox/cron/dns_check_pending.php   >> /var/log/vormox-cron.log 2>&1

# Hourly — renewal reminders + suspension sweep
0 * * * *   /usr/bin/php /root/vormox/cron/suspend_expired.php      >> /var/log/vormox-cron.log 2>&1

# Daily 03:00 — auto-renew paid panels / generate renewal invoices
0 3 * * *   /usr/bin/php /root/vormox/cron/auto_renew_invoices.php  >> /var/log/vormox-cron.log 2>&1

# Twice daily 02:00 & 14:00 — per-panel MySQL backup → S3 (+ email copy).
# Needs the S3_* keys in .env; 12h apart, offset from the 03:00 renew job.
0 2,14 * * * /usr/bin/php /root/vormox/cron/db_backup.php           >> /var/log/vormox-cron.log 2>&1
```

### Systemd timers (if you prefer)

Drop these next to `vormox.service`:

```ini
# /etc/systemd/system/vormox-lifecycle.service
[Unit]
Description=Vormox lifecycle trigger
After=network-online.target

[Service]
Type=oneshot
WorkingDirectory=/root/vormox
ExecStart=/usr/bin/php /root/vormox/cron/lifecycle_trigger.php
```

```ini
# /etc/systemd/system/vormox-lifecycle.timer
[Unit]
Description=Run Vormox lifecycle trigger every 5 minutes

[Timer]
OnBootSec=2min
OnUnitActiveSec=5min
AccuracySec=10s
Unit=vormox-lifecycle.service

[Install]
WantedBy=timers.target
```

Same pattern for `dns_check_pending` (5 min) and `suspend_expired` (1 hour —
use `OnUnitActiveSec=1h` and `OnCalendar=hourly` if you prefer wall-clock).

Enable:
```bash
sudo systemctl daemon-reload
sudo systemctl enable --now vormox-lifecycle.timer
systemctl list-timers vormox-\*
```

---

## Hardening before you go to prod

The provided unit already includes:
- `NoNewPrivileges=true`
- `PrivateTmp=true`
- `ProtectSystem=full`
- `ProtectHome=read-only`
- `MemoryMax=512M`
- `Restart=always` with `StartLimitBurst=5` (no infinite crash loops)

Recommended next steps once you switch off the dev box:

1. **Run as a non-root user.** Create `vormox` and own the project files:
   ```bash
   sudo useradd --system --home /var/www/vormox --shell /usr/sbin/nologin vormox
   sudo chown -R vormox:vormox /root/vormox       # or move to /var/www/vormox
   ```
   Then change `User=` / `Group=` / `WorkingDirectory=` in the unit. `php -S`
   doesn't need root unless you bind to a port < 1024 (you don't — you're on
   8000), so this is a free win.

2. **Bind to localhost only**, let nginx be the only public listener:
   ```ini
   ExecStart=/usr/bin/php -S 127.0.0.1:8000 -t /root/vormox
   ```
   That way nobody can hit `:8000` directly even if your firewall is wrong.

3. **Switch to php-fpm** when you start seeing real load. The unit can stay —
   `systemctl disable --now vormox` and let nginx + fpm take over.

4. **Rotate the .env secrets** before deploying — the keys committed in
   earlier versions of `config.php` are public knowledge.
