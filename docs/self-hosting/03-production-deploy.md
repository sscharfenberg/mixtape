# 3 — Production deploy

> Getting MixTape onto the host and deployable with one command. Everything here is **LAN-only** — no
> router change, no domain needed. At the end the site answers on the host's IP.
>
> Config files referenced as `$SRC/...` ship with the repo in [`files/`](files/). Once the code is
> cloned (step 4) they are on the box at `/var/www/mixtape.prod/docs/self-hosting/files`.

## The deploy model, in one paragraph

A dedicated **`mixtape-deploy`** system user owns and deploys the checkout. `composer install` and
`npm ci` execute a great deal of third-party install code — npm postinstall hooks especially — so they
run unprivileged: a compromised dependency gets a directory, not the machine. The web user `www-data`
can **read and execute** the code but never write it, so a compromised web process cannot rewrite the
app. Exactly two operations need privilege, and both are individually allowlisted in `sudoers`.

```
/var/www/mixtape.prod        mixtape-deploy:www-data   dirs 2750 / files 640
  storage/, bootstrap/cache  www-data:www-data         dirs 2770   (both write)
  .env                       mixtape-deploy:www-data   640
```

## 1. The deploy user

```bash
sudo adduser --system --group --shell /bin/bash --home /home/mixtape-deploy mixtape-deploy
sudo adduser mixtape-deploy www-data          # group-write on storage/
id mixtape-deploy
```

`--system` means no password and no aging, so the account cannot be logged into directly — you reach
it only through `sudo -u mixtape-deploy`. It gets no SSH key, so it is not reachable from the network
at all. (A CI runner would need one; that is a later problem.)

## 2. nvm + Node

nvm is **per-user**, so this must run as `mixtape-deploy`. Running `sudo nvm install` as root installs
into root's home, which the deploy script never looks at — a confusing failure, because `node -v`
works fine for you and not for the deploy.

```bash
# Check for a current nvm release tag first:
#   gh api repos/nvm-sh/nvm/releases/latest --jq .tag_name
sudo -u mixtape-deploy -H bash -c 'curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/<tag>/install.sh | bash'

# Install the Node version the repo pins in .nvmrc.
sudo -u mixtape-deploy -H bash -c 'export NVM_DIR=/home/mixtape-deploy/.nvm; . $NVM_DIR/nvm.sh; nvm install && nvm alias default node && node -v'
```

> Note the shape: `sudo -u mixtape-deploy -H bash -c '…'`, **not** `sudo -u mixtape-deploy nvm …`.
> nvm is a shell function, not a binary — it does not exist until `nvm.sh` is sourced — and `-H` is
> what points `$HOME` at the deploy user's home.

**Upgrading Node later** needs no change to the deploy script: bump `.nvmrc` and `engines.node` in the
repo, then re-run `nvm install` (no argument) as the deploy user from inside the checkout. Both
`nvm install` and the script's `nvm use` read `.nvmrc`, so the repo stays the single source of truth.

## 3. Database

Generate the password and create the role inside one root shell, so the secret never passes through
your shell history or shows up in `ps` output:

```bash
sudo -i bash <<'EOF'
set -e
umask 077
openssl rand -base64 32 | tr -d '\n' > /root/mixtape-prod-db.pw
chmod 600 /root/mixtape-prod-db.pw
PW=$(cat /root/mixtape-prod-db.pw)
su - postgres -c "psql -v ON_ERROR_STOP=1" <<SQL
CREATE ROLE mixtape_prod LOGIN PASSWORD '$PW';
CREATE DATABASE mixtape_prod OWNER mixtape_prod ENCODING 'UTF8';
SQL
EOF
```

The outer heredoc is quoted so nothing expands in *your* shell; the inner one is unquoted so `$PW`
expands inside the root shell. base64 output contains no quote characters, so it cannot break the SQL
literal.

Verify the role exists **and can log in over TCP** — the app connects to `127.0.0.1`, so a
`pg_hba.conf` permitting only peer authentication fails here rather than much later during `migrate`:

```bash
PGPASSWORD=$(sudo cat /root/mixtape-prod-db.pw) \
  psql -h 127.0.0.1 -U mixtape_prod -d mixtape_prod -c 'SELECT current_user, current_database()'
```

## 4. Clone the code

```bash
cd /tmp        # mixtape-deploy cannot read your home directory; running from
               # there makes git and nvm emit "failed to restore working directory"
sudo install -d -o mixtape-deploy -g www-data -m 2750 /var/www/mixtape.prod
sudo -u mixtape-deploy bash -c 'umask 027; git clone <repo-url> /var/www/mixtape.prod'
```

Cloning **as the deploy user** is what makes the tree deploy-owned from the start — there is no
`chown -R` fixup anywhere in this runbook, by design. `umask 027` matches the deploy script, so the
checkout is group-readable by `www-data` but not group-writable.

```bash
SRC=/var/www/mixtape.prod/docs/self-hosting/files
```

## 5. `.env`

```bash
sudo install -m 640 -o mixtape-deploy -g www-data $SRC/env.prod.template /var/www/mixtape.prod/.env
sudo -u mixtape-deploy nano /var/www/mixtape.prod/.env      # fill the placeholders
```

Leave `APP_URL` as a placeholder for now — step 11 verifies over the IP, and
[`04-going-public.md`](04-going-public.md) swaps in the real host.

## 6. Writable directories

Everything else is read-only to the web user; only these two trees are not.

```bash
sudo chown -R www-data:www-data /var/www/mixtape.prod/storage /var/www/mixtape.prod/bootstrap/cache
sudo find /var/www/mixtape.prod/storage /var/www/mixtape.prod/bootstrap/cache -type d -exec chmod 2770 {} +
```

Mode `2770` is setgid + group-write **on the directories**: `www-data` writes logs, cache and compiled
views at runtime, and `mixtape-deploy` (a member of `www-data`) can create the icon sprite under
`storage/app/public/`. The setgid bit makes new entries inherit the `www-data` group.

> ⚠️ **The deploy script runs at `umask 027`, and this is load-bearing.** Files come out 640, dirs
> 750 — group-*readable*, never group-writable. At `umask 002` the checkout would be 664 with group
> `www-data`, meaning **the web user could rewrite production code**, which is the entire thing this
> model exists to prevent. If you change the umask, you have removed the protection.

## 7. sudoers

```bash
sudo visudo -c -f $SRC/mixtape-deploy.sudoers        # syntax-check BEFORE installing
sudo install -m 440 -o root -g root $SRC/mixtape-deploy.sudoers /etc/sudoers.d/mixtape-deploy
sudo -u mixtape-deploy sudo -l                       # confirm: exactly two rules
```

> ⚠️ **Always `visudo -c` first.** A malformed file in `/etc/sudoers.d/` can lock you out of `sudo`
> entirely, and recovering means a root shell you may not have.

The two allowlisted operations are reloading php-fpm and running artisan as `www-data`. Note that the
artisan rule permits `artisan tinker`, i.e. arbitrary code execution as the web user — that is not an
escalation, because `mixtape-deploy` already writes the code `www-data` executes. It is deliberately
not a path to root.

## 8. php-fpm pool

```bash
sudo install -m 644 $SRC/mixtape-prod.pool.conf /etc/php/8.4/fpm/pool.d/mixtape-prod.conf
sudo install -d -o www-data -g www-data -m 700 /var/lib/php/sessions/mixtape-prod
sudo install -d -o www-data -g www-data -m 750 /var/log/php
sudo php-fpm8.4 -t                        # config test
sudo systemctl restart php8.4-fpm
ls -l /run/php/mixtape-prod.sock          # must exist, www-data:www-data 0660
```

An isolated pool means a production worker crash or slow-log flood cannot affect the dev site, and
vice versa. Dev keeps the default `[www]` pool.

## 9. nginx vhost

```bash
sudo install -m 644 $SRC/mixtape-limits.conf /etc/nginx/conf.d/mixtape-limits.conf
sudo install -m 644 $SRC/mixtape.prod.nginx.conf /etc/nginx/sites-available/mixtape.prod
sudo nano /etc/nginx/sites-available/mixtape.prod    # replace <your-domain>
sudo ln -s /etc/nginx/sites-available/mixtape.prod /etc/nginx/sites-enabled/
```

The rate-limit zones **must** live in `conf.d/` (the `http` context) — `limit_req_zone` and
`limit_conn_zone` are invalid inside a `server{}` block. Defining a zone costs nothing until a server
block references it.

> ⚠️ **The `default_server` swap.** Production takes the `default_server` flag, so it must come **off
> the dev vhost in the same change**. Two `default_server` blocks on one port is a hard nginx error
> and `nginx -t` will refuse to reload — leaving you with neither site updated.

In the dev vhost, remove `default_server` from **all four** listen lines:

```nginx
listen 80 default_server;           ->  listen 80;
listen [::]:80 default_server;      ->  listen [::]:80;
listen 443 ssl default_server;      ->  listen 443 ssl;
listen [::]:443 ssl default_server; ->  listen [::]:443 ssl;
```

```bash
sudo nginx -t && sudo systemctl reload nginx
```

Consequence worth knowing: afterwards, an unmatched `Host` header or a bare-IP request lands on
**production**, not dev.

### Security headers

All `add_header` directives live in the `server{}` block on purpose. A single `add_header` inside any
`location{}` **drops every inherited header for that location** — nginx's header-inheritance trap, and
a very easy way to ship a site whose CSP silently does not apply to its own PHP responses.

The shipped CSP keeps `script-src 'unsafe-inline'` (required by the inline pre-paint theme script,
since nginx cannot emit per-request nonces) and `style-src 'unsafe-inline'` (Vue's `v-bind()` inline
styles). Both still block external-origin loads. Moving CSP into Laravel middleware with per-request
nonces would let you drop the former.

> When the audio player lands, `media-src` may need `blob:` — and possibly `worker-src 'self' blob:`.
> Exercise playback after any CSP change; it is the likeliest casualty.

## 10. First deploy

The deploy script is installed **outside** the git tree on purpose: it `git reset --hard`s the
checkout, and bash reads scripts incrementally, so a script that rewrites itself mid-execution can
jump to garbage.

Set `APP_KEY` first. `artisan key:generate` cannot run yet — it needs `vendor/`, which the deploy
creates — and deploying without a key fails at `migrate`. The key is just 32 random bytes, so set it
directly and skip the chicken-and-egg:

```bash
sudo -u mixtape-deploy bash -c 'sed -i "s|^APP_KEY=.*|APP_KEY=base64:$(openssl rand -base64 32)|" /var/www/mixtape.prod/.env'
sudo grep -c '^APP_KEY=base64:' /var/www/mixtape.prod/.env      # expect 1
```

(Written inside the subshell so the key never reaches your shell history.) Then:

```bash
sudo install -m 750 -o root -g mixtape-deploy $SRC/mixtape-prod-deploy.sh /usr/local/bin/mixtape-prod-deploy
sudo -u mixtape-deploy /usr/local/bin/mixtape-prod-deploy
```

This takes several minutes: composer, `npm ci`, type-check, Vite build, icon sprite, migrations.

> ⚠️ **The icon sprite is a separate build step.** It is gitignored *and* not produced by the Vite
> build. The deploy script runs `npm run icons` for exactly this reason — skip it and every icon in
> the app renders empty, with no error anywhere.

Create yourself an account (registration is invite-only):

```bash
sudo -u www-data /usr/bin/php /var/www/mixtape.prod/artisan app:invite
```

> **Do not seed production.** `db:seed` creates a known test account with a published password, which
> must never exist on an internet-facing box. Use a real invite.
>
> Until mail is configured ([`04-going-public.md`](04-going-public.md#step-6--transactional-mail)),
> the invite link lands in `storage/logs/laravel-*.log` rather than an inbox. Read it from there.

## 11. Verify on the LAN — before any router change

```bash
curl -skI https://<server-lan-ip>/ | head -1                    # 200
curl -skI http://<server-lan-ip>/  | head -1                    # 301 -> https
curl -skI https://<server-lan-ip>/ | grep -iE 'content-security|x-frame|x-content|referrer'
sudo tail -n 40 /var/log/nginx/mixtape.prod.error.log
sudo tail -n 40 /var/log/php/mixtape-prod.error.log
```

Confirm the ownership model actually holds — this is the check that catches a wrong umask:

```bash
sudo -u www-data test -w /var/www/mixtape.prod/public/index.php \
  && echo "BAD: web user can write code" || echo "OK: code read-only to www-data"
sudo -u www-data test -w /var/www/mixtape.prod/storage \
  && echo "OK: storage writable" || echo "BAD: storage not writable"
```

Then in a browser (accept the self-signed warning): log in with your invite, switch language, toggle
the theme, open the dashboard. **Watch the devtools console for CSP violations** — this is the first
time the full policy runs against the app. Fix anything blocked here, not after exposure.

## Routine deploys

```bash
sudo -u mixtape-deploy /usr/local/bin/mixtape-prod-deploy
```

That is the whole loop: push to `main`, run that on the server. Pass a commit SHA as an argument to
roll back to a known-good commit.

The script puts the site into maintenance mode first and **deliberately leaves it there if the deploy
fails** — serving new code against a half-applied migration is worse than showing a maintenance page.
It also refuses to run if the working tree is dirty, on the grounds that someone hand-patched the box
and `git reset --hard` would silently destroy their work.

## Rebuilding the dev site

The dev site works nothing like the above, and conflating the two is the main way to waste an
afternoon. It is **not a git checkout** — source arrives by SFTP from the workstation IDE — so there
is nothing to fetch. [`files/mixtape-deploy-dev.sh`](files/mixtape-deploy-dev.sh) rebuilds whatever is
already on disk:

```bash
mixtape-dev-deploy            # rebuild + migrate
mixtape-dev-deploy --fresh    # rebuild + migrate:fresh --seed
```

Install it as `/usr/local/bin/mixtape-dev-deploy` (755 root:root) and edit `HEALTH_URL` at the top.
Run it as your own admin user, never root, and never while an upload is still in flight — it cannot
detect a half-uploaded tree.

Four ways it deliberately differs from the prod script:

- **It does not cache config.** Prod ends with `config:cache`/`route:cache`; dev ends with
  `optimize:clear` and nothing else. Caching on dev would reintroduce the "editing `.env` changes
  nothing" trap on the box where you iterate most.
- **It installs dev dependencies.** No `--no-dev`; tests and debug tooling are the point of dev.
- **It runs at `umask 002`, not `027`.** Prod's mask exists so `www-data` can never rewrite prod code.
  Dev inverts that: the box is LAN-only and both you and the runtime write freely.
- **It normalizes `storage/` and `bootstrap/cache` ownership first.** This is the non-obvious one.
  php-fpm runs as `www-data` with its own umask, so files it creates at runtime
  (`storage/logs/laravel.log`, `bootstrap/cache/*.php`) come out `www-data:www-data 644` — not
  group-writable. The next rebuild runs as *you*, cannot overwrite them, and fails somewhere
  unhelpful: `composer install` dies inside `package:discover` because it cannot rewrite
  `bootstrap/cache/packages.php`. Re-normalizing each run makes it self-healing rather than a slow
  slide into a broken tree.

> **A note on both scripts' `HEALTH_URL`.** Write it quoted. Unquoted, the angle brackets in a
> `https://<placeholder>/` template are shell *redirections*, so an unedited copy fails with a
> confusing "No such file or directory" rather than saying what is wrong. The dev script quotes it
> and guards on the placeholder still being there.

## Running artisan in production

```bash
sudo -u www-data /usr/bin/php /var/www/mixtape.prod/artisan <command>
```

Always as `www-data`, so anything artisan writes is owned by the runtime user.

> `artisan tinker` fails with `Writing to directory /var/www/.config/psysh is not allowed` — www-data's
> home is deploy-owned by design. Give it a writable home for the one command:
>
> ```bash
> sudo -u www-data env HOME=/tmp /usr/bin/php /var/www/mixtape.prod/artisan tinker --execute='...'
> ```

`artisan about` is the fastest way to see what the application actually believes about its
configuration — particularly the mail and database drivers, which is where a stale config cache shows
up.

## Rate limiting and Precognition

Worth understanding before you tune any throttle, because the interaction is not obvious.

The auth forms use **Inertia Precognition** for live validation, which posts to the *same route* as
the real submit. Under a flat `throttle:6,1`, validation traffic and the actual submission share one
budget: typing consumes the allowance the submit needs, and an honest user gets a 429 partway through
a password reset.

Two different fixes, depending on what the route does:

- **Routes that only change state** (e.g. consuming a password-reset token) can simply have a generous
  limit — they are gated by the token itself, so a tight throttle buys little.
- **Routes that send mail** (forgot-password, resend-verification) must keep a tight *send* budget,
  because that limit is what stops someone flooding a victim's inbox. Split on
  `$request->isPrecognitive()` instead: a high limit for the no-op validation requests, the original
  low limit for real submissions. See `auth-mail` in `app/Providers/FortifyServiceProvider.php`.

There is also an nginx-level `limit_req` on dynamic requests (30r/s, burst 60) — that one targets
scanners walking paths at machine speed, and a real page load never approaches it.

## Not needed yet

- **Queue worker** — nothing implements `ShouldQueue`; mail is sent synchronously. Add a systemd unit
  when that changes.
- **Scheduler cron** — no scheduled tasks are registered. Add `* * * * * php artisan schedule:run`
  when the library scan is scheduled.

## Next

[`04-going-public.md`](04-going-public.md) — domain, exposure, TLS, and mail.
