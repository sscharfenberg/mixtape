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

In the dev vhost ([`files/mixtape.dev.nginx.conf`](files/mixtape.dev.nginx.conf)), remove
`default_server` from **all four** listen lines:

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

### Media hand-off (`X-Accel-Redirect`)

The vhost ships two `internal;` locations, and they are the least obvious thing in it. Getting them
wrong breaks *every track* while leaving Laravel's log completely empty, so it is worth understanding
before you touch either half.

```nginx
location /internal-media/music/      { internal; alias /var/media/music/; }
location /internal-media/audiobooks/ { internal; alias /var/media/audiobooks/; }
```

**What it does.** `SongStreamController` answers the stream route two ways, chosen by
`MIXTAPE_STREAM_INTERNAL_PREFIX`. Empty or absent → PHP sends the file itself. Non-empty → it answers
with an **empty body** plus `X-Accel-Redirect: /internal-media/music/<path>`, and nginx serves the
bytes from the matching location.

**Why bother.** Streaming a large collection through php-fpm holds one worker for the entire length of
every song, and a pool has only so many workers — a handful of listeners can starve the site of the
workers it needs to render pages. On the hand-off path PHP is free the moment the header is written.
nginx also answers HTTP `Range` natively, which is what makes dragging the player's timeline work.

**Why `internal` is not optional.** It makes these locations unreachable from outside — a request
straight to `/internal-media/...` is a 404 — so the authorization check in the controller cannot be
bypassed by guessing the path. Drop the keyword and you have published your entire media library
without auth.

> ⚠️ **The order is load-bearing.** Install the locations and **reload nginx first**, then set the
> `.env` value. In between — prefix set, locations missing — every stream 500s.

```bash
sudo nginx -t && sudo systemctl reload nginx
# Prove `internal` works before flipping anything. This needs no session:
curl -o /dev/null -w '%{http_code}\n' https://<your-domain>/internal-media/music/   # expect 404
```

A `404` is the pass. A `500` means the keyword did not take and the request fell through to
`try_files` — fix that before going near the `.env`.

**Production caches its config**, so editing `.env` alone changes nothing. Either add the key *before*
a deploy — the deploy script runs `optimize:clear` then `config:cache` anyway, so it costs nothing — or
run those two by hand afterwards. This is the same trap that makes a mail-setting edit look ignored.

**Four ways to get it wrong, none of which looks like the others:**

| Symptom | Cause |
|---|---|
| **500** on every stream, **nothing** in Laravel's log | Prefix set, no matching `internal;` location (or a mistyped `alias`). nginx redirects to a URI nothing serves, `location /` catches it, `try_files` bounces it into `index.php`, and nginx refuses to redirect there twice: `rewrite or internal redirection cycle` in **nginx's** error log. No PHP exception is thrown, which is why the app log stays silent. |
| **200 with an empty body**, `Content-Type: audio/mpeg` | Prefix set with no nginx in front at all (`artisan serve`, `php -S`). Nothing interprets the header, so the browser is handed it literally. Leave the prefix empty off the real server. |
| A stream 500s the moment you *blank* the key | A blank `.env` line is an empty **string**, never `null`, so a `=== null` guard reads it as *configured*. Guard with `trim((string) config(…)) === ''`, as the library-area paths do — and use that idiom for any env-driven flag. |
| A path with `#`, `&` or an umlaut 404s while its neighbours play | nginx **URL-decodes** the redirect target, so every segment must be `rawurlencode`d on the way out. An unencoded `#` truncates the path at the fragment. The controller does this; a hand-built URI would not. |

**Which side actually served a track?** Read the `ETag`. PHP's is Symfony's `setAutoEtag()` content
hash; nginx's static handler writes `"<hex-mtime>-<hex-size>"` beside its own `Last-Modified`, and the
size decodes to the file's real byte count. `Content-Length` is **not** a discriminator — it is a real
byte count either way.

**The dev box wants this too**, even though performance there is irrelevant: it is the only machine
where the accelerated path can be rehearsed, since a workstation running `php -S` has no nginx to
interpret the header. [`files/mixtape.dev.nginx.conf`](files/mixtape.dev.nginx.conf) ships the same
block. Rehearsing it there is how these failure modes stop being production discoveries.

### Security headers

All `add_header` directives live in the `server{}` block on purpose. A single `add_header` inside any
`location{}` **drops every inherited header for that location** — nginx's header-inheritance trap, and
a very easy way to ship a site whose CSP silently does not apply to its own PHP responses.

The shipped CSP keeps `script-src 'unsafe-inline'` (required by the inline pre-paint theme script,
since nginx cannot emit per-request nonces) and `style-src 'unsafe-inline'` (Vue's `v-bind()` inline
styles). Both still block external-origin loads. Moving CSP into Laravel middleware with per-request
nonces would let you drop the former.

> **The player needs no CSP widening.** It is a native `<audio>` whose `src` is a same-origin route,
> which the shipped `media-src 'self'` already allows; a `blob:` source would only be needed by a
> library that wraps audio in a MediaSource. Still exercise playback after any CSP change — it is the
> likeliest casualty, and the end-to-end suite has a spec that plays a track under this exact policy.

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

> ⚠️ **On a server, build with `npm run build-only`, never `npm run build`.** Both deploy scripts
> already do, for two independent reasons. `build` runs the linters with `--fix`, which MUTATES
> tracked source on the deployed copy. And it cannot finish anyway:
> `@vue/eslint-config-typescript` globs the entire project for `.vue` files — it has to read each
> one to learn whether its script block is TypeScript — so it walks into `storage`, which belongs
> to www-data, and the run dies with
> `EACCES: permission denied, scandir …/storage/inertia-devtools` before linting a single file.
>
> ESLint's own `ignores` cannot prevent that walk: the package resolves those patterns to
> absolute paths, and fast-glob matches its `ignore` list against entries relative to its cwd, so
> they never match. Lint on a workstation and in CI, where the whole tree is readable; the server
> only needs the compiled assets.

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

## Driving both sites from your workstation

Everything above assumes you are already logged into the server. In practice most artisan calls are
one-offs — put dev into maintenance, check `about` on prod, tail a log — and doing them by hand means
remembering which site runs as which user, then typing a different `ssh` line for each.

[`files/mt.sh`](files/mt.sh) is a thin wrapper for exactly that. Install it on the **workstation**,
not the server:

```bash
install -m 755 mt.sh ~/.local/bin/mt     # ensure ~/.local/bin is on $PATH
```

Then edit `HOST` at the top to your ssh alias. It refuses to run until you do.

```bash
mt artisan down --dev         # dev into maintenance
mt artisan up --dev
mt artisan migrate --prod     # prompts for your sudo password
mt artisan about --prod
mt logs -f --dev
mt logs --auth --prod       # the auth.log that feeds fail2ban
mt tinker --prod
mt shell --dev
```

The `--dev` / `--prod` flag may appear **anywhere** in the line and is stripped before the rest is
forwarded, so `mt artisan down --dev` and `mt --dev artisan down` are the same command and artisan
never sees the flag.

### The decisions worth knowing

**Dev is the default target.** Prod is only ever touched when you explicitly type `--prod`, so a
forgotten flag can only ever hit the throwaway site. This is the single most useful property of the
wrapper and the reason not to make the target a positional argument.

**The two targets run as different users**, mirroring the ownership model established above rather
than inventing a third convention:

| | Runs as | Why |
| --- | --- | --- |
| dev | you, directly | you own the tree (`<admin-user>:www-data`, 2775); artisan writing as you is correct |
| prod | `sudo -u www-data` | your account is not in `www-data` and cannot even traverse `2750` prod; this is the same invocation the deploy script uses, so everything artisan writes stays owned by the runtime user |

Running prod artisan as any other identity leaves files `www-data` cannot rewrite, and that surfaces
much later somewhere confusing — the same failure described under *Rebuilding the dev site*.

**The prod sudo hop prompts for your own password.** `mixtape-deploy`'s `NOPASSWD` rule belongs to
that account, not yours, so there is no way to make this passwordless without widening sudoers. Treat
the prompt as a feature: one more beat of friction between a typo and production.

**Destructive migrations against prod require typing `PRODUCTION` first.** `migrate:fresh`,
`migrate:refresh`, `migrate:reset`, `migrate:rollback` and `db:wipe` are one fumbled flag away from
their harmless dev equivalents. Laravel's own `--force` confirmation is no help here: artisan runs
non-interactively on the far side of an ssh pipe, where that prompt never fires. The wrapper therefore
asks on the *workstation* side, reading from `/dev/tty` so a piped stdin cannot answer for you.

**It deliberately does not deploy.** Deploys carry guards a passthrough wrapper has no business
duplicating — the unpushed-commit check, maintenance-mode handling, the dirty-tree refusal. Keep
calling `mixtape-prod-deploy` / `mixtape-dev-deploy` for those.

### Traps

- **`cd <site> && sudo -u www-data …` fails on prod with "Permission denied".** The `cd` runs as
  *your* login user, and only the `sudo` after it switches to `www-data` — so on the `2750`
  deploy-owned tree the command dies before sudo is ever reached. The fix is not to `cd` at all:
  artisan resolves its base path from its own location (`__DIR__`), not the working directory, so an
  absolute path behaves identically from any cwd. Confirm for yourself with
  `cd / && php /var/www/mixtape.dev/artisan about`. Where a `cd` genuinely is wanted — an interactive
  shell — it has to happen *inside* the sudo.
- **`sudo -u www-data -s` exits immediately.** `-s` runs the target user's login shell, and
  `www-data`'s is `/usr/sbin/nologin` by design, so you get "This account is currently not available"
  rather than a shell. Name the shell explicitly (`sudo -u www-data bash`), and give it `HOME=/tmp`
  so it is not trying to write history into `/var/www`.
- **`storage/logs/laravel.log` does not exist on prod.** The two sites run different log drivers: dev
  is `single`, which writes exactly that file, while prod's `.env` sets `LOG_CHANNEL=stack` and
  `LOG_STACK=daily`, and Laravel's **daily** driver writes `laravel-YYYY-MM-DD.log` instead. Anything
  that hardcodes `laravel.log` works on dev and fails on prod with a bare "No such file or directory".
  Resolve the newest `laravel*.log` at read time instead — that is right under either driver, and
  survives the date rolling over. Note the auth channel is a *separate* `single` file,
  `storage/logs/auth.log`, which is what the fail2ban jail reads.
- **`artisan tinker` on prod needs `HOME=/tmp`** — the psysh problem noted above. The wrapper applies
  it to both `mt tinker --prod` and `mt artisan tinker --prod`, since they are the same underlying
  command.
- **macOS ships bash 3.2**, where expanding an *empty* array under `set -u` is an "unbound variable"
  abort, not an empty expansion. `"${ARGS[@]}"` therefore breaks the commonest call of all —
  `mt tinker --dev`, which has no remaining arguments. Every such expansion needs the
  `${ARGS[@]+"${ARGS[@]}"}` guard.
- **A TTY mangles pipes.** `ssh -t` translates LF to CRLF, so `mt artisan route:list --dev | grep …`
  would silently see `\r` on every line. The wrapper allocates a TTY only when stdout is a terminal —
  except on prod, which must force one (`-tt`) so sudo can prompt. Piping prod output does therefore
  yield CRLF; that is the one accepted rough edge.

### Tab completion (zsh)

[`files/_mt`](files/_mt) completes the subcommands, the artisan commands, and the target flags.
Install it into any directory on `$fpath` — under oh-my-zsh, `$ZSH_CUSTOM/completions` is already
there:

```bash
cp _mt ~/.oh-my-zsh/custom/completions/_mt
rm -f ~/.zcompdump* && exec zsh          # see below — this line is not optional
```

The artisan list is **static on purpose**. Completing from the live `artisan list` would open an ssh
connection on every `<TAB>` — and on `--prod` block on a sudo password prompt with no terminal to
render it. The list drifts as the app grows; that is fine, because completion is a convenience and
never a whitelist. Anything absent still runs when typed in full.

Three things that made this harder than it looks, all of which fail *silently*:

- **oh-my-zsh caches completions** in `~/.zcompdump-<host>-<version>` and rebuilds it only when the
  OMZ revision or the **`fpath` string** changes. `$ZSH_CUSTOM/completions` is on `fpath` whether or
  not it contains anything, so *adding a file there does not invalidate the cache*. Restarting the
  shell is not enough; delete the dump.
- **`_describe` splits each entry on the first unescaped colon.** Artisan commands are full of colons,
  so every one must be written `\:`. Miss it and the entry half-works in a way that looks plausible in
  the list: `'config:show:…'` completes the value `config` with the description
  `show:show a resolved config value`, and inserts the wrong command.
- **Candidates starting with `-` are options**, and options are only displayed when the surrounding
  context has requested the `options` tag. Inside a nested `_arguments` state that negotiation does not
  happen, so `_describe` reports success while displaying nothing — `mt artisan down --<TAB>`, the
  commonest position of all, completes silently to nothing. Use `_wanted options expl … compadd`,
  which requests the tag explicitly.

## Copying the collection to a local disk

The other thing a workstation wants from the server is the media itself — filling a USB stick for a
car, or a phone. [`files/mts.sh`](files/mts.sh) does that one job, and it is deliberately a **separate
script rather than an `mt` subcommand.** Every `mt` command ends in `exec ssh HOST <remote-command>`,
and everything below its parser — the `printf %q` quoting for the remote shell, the TTY policy, the
`sudo` hop, resolving a target to a site directory — exists to run something *on the server*. A
transfer's destination is a path on the workstation and shares none of it. `mt`'s organising idea does
not survive the move either: the media library belongs to neither site, because both read the same
collection, so `mt transfer --prod` would parse cleanly, be stripped from the line like any other
target flag, and mean nothing. A command whose central flag is silently inert is worse than a second
command.

```bash
install -m 755 mts.sh ~/.local/bin/mts             # ensure ~/.local/bin is on $PATH
cp _mts ~/.oh-my-zsh/custom/completions/_mts
rm -f ~/.zcompdump* && exec zsh                    # the cache note above applies here too
```

Then edit `HOST` at the top, exactly as for `mt`. It refuses to run until you do.

```bash
mts music /Volumes/<usb-label>/              # add what is missing, update what changed
mts music /Volumes/<usb-label>/ --mirror     # …and delete what the library no longer has
mts music /Volumes/<usb-label>/ -n           # print the plan, write nothing
mts audiobooks /Volumes/<usb-label>/Books/
```

Once per disk, stop macOS indexing something a head unit has to read:

```bash
sudo mdutil -i off /Volumes/<usb-label>
sudo rm -rf /Volumes/<usb-label>/.Spotlight-V100
touch /Volumes/<usb-label>/.fseventsd/no_log
```

**Mirror music to the volume root, not into a subfolder.** At the root, every path on the disk is
exactly the area-relative path the database stores, so a playlist exported from the app with an
**empty** path prefix resolves on the disk verbatim — which is what `config/mixtape.php` means when it
says a prefix describes "the machine doing the listening". Put the `.m3u` files at the root, beside
the artist folders.

### The decisions worth knowing

**It carries audio and cover images, not the tree.** A library accumulates spreadsheets, helper
scripts and OS droppings that have no business on a car stick, so the filter is an allowlist —
`--include='*/'` to descend, the media globs, then `--exclude='*'` — with `--prune-empty-dirs` so a
folder that held nothing else is not created at all. `--all` turns it off. The audio extensions
mirror the app's `scan.extensions`; if the scanner learns a format, this is the other place to teach
it.

The globs are written as character classes — `*.[Jj][Pp][Gg]`, not `*.jpg` — because **rsync's
patterns are case-sensitive and the scanner's are not.** A library the app is perfectly happy with
can hold a `.JPG` among a thousand `.jpg`, and a plain lowercase glob leaves those albums on the disk
with no cover art while reporting nothing wrong. Measured here: exactly two files, which is precisely
the size of mistake that never gets noticed.

**`-rt`, never `-a`.** FAT32 and exFAT store no ownership, permissions or symlinks, so `-a` asks for
four things the filesystem cannot hold. Nothing needs subtracting afterwards, which is fortunate —
see the flag table below.

**`--modify-window=1` is the flag whose absence is silent and expensive.** FAT records modification
times to a two-second granularity. Without the window every file looks changed on every run, so a
"top up" re-sends the whole library, and nothing anywhere reports a problem.

**Resumability is retries, not a resume protocol.** rsync is already incremental, so the honest
answer to a dropped connection is to run it again — `--partial-dir` keeps the file that was in flight,
and the loop re-runs up to `--attempts` times with a backoff. What makes that work is `--timeout`: an
interrupted transfer has to *fail* before it can be retried, and without an I/O timeout a stalled
connection simply hangs. Exit codes that mean the invocation is wrong (`1`, `2`, `4`) are not retried,
because they will fail identically five times.

**Two destination guards, because the mistake is expensive.** The directory is never created — a
mistyped volume name does not exist, so the run fails instead of quietly filling the boot disk — and
its device number must differ from `/`'s. Device numbers rather than parsing `df`'s mount-point
column, which cannot be split safely when a volume name contains a space. `--mirror` adds a third:
it requires a destination on a local `/dev/…` disk, because the library's own Samba share is very
likely mounted on the same workstation, and `mts audiobooks /Volumes/<share>/music/ --mirror` would
otherwise faithfully delete the music collection off the server.

**Deleting from the disk is confirmed, but not with a typed word.** `mt` demands one for a production
migration because that data exists nowhere else. Here everything on the destination is a copy of
something the server still has, so a wrong answer costs a re-run — the question exists only so a
`--mirror` that would empty the disk cannot pass unnoticed, and it is asked only when the plan
actually contains deletions.

**The AppleDouble sweep is not optional.** macOS stamps a file it creates with an extended attribute,
and FAT cannot store one — so the volume driver spills each into a 4 KB `._<name>` sidecar beside the
real file. The library's own files carry no extended attributes at all; these are made on the way in,
which is why an `--exclude` cannot prevent them, and a ten-thousand-file library otherwise arrives
with ten thousand phantom files that a head unit lists as tracks. `dot_clean` is the sanctioned tool
and does not finish the job on FAT: measured on two files, it merged one sidecar, left the other and
the directory's own behind, and restored the extended attribute it had just merged, which spills
again. Deleting the sidecar and *then* clearing the attribute leaves nothing to regenerate. The sweep
runs only where sidecars actually exist, so a destination on a filesystem that stores extended
attributes natively is left alone.

It also runs **on interrupt**, not only on success. The sweep is the last thing a run does and an
abandoned run is the likeliest kind — stopping a copy that has an hour to go would otherwise leave
thousands of phantom files on a disk somebody is about to unplug. The partial directory is
deliberately *not* touched there: on an interrupt it holds the file that was in flight, which is the
whole reason for keeping it.

**Progress is counted, not measured.** The dry run that produces the plan also produces the file
count, so the real run is filtered through `awk` into one updating `[n/total pct%]` line — openrsync
has no `--info=progress2` to do it properly. The count is carried across attempts in a temporary
file, or the display would jump backwards after a dropped connection.

That `awk` runs under **`LC_ALL=C`**, which is not a detail. rsync's itemized output is not
guaranteed to be valid UTF-8: it escapes some non-ASCII bytes in a filename as `\#NNN` octal and
passes others through raw, so `Tír na mBan.mp3` arrives as a lone `0xC3` followed by the literal text
`\#255`. In a UTF-8 locale `awk` then prints `towc: multibyte conversion failure` once per such line.
It keeps going and the transfer is unaffected — but on a collection with any accented titles the
warnings bury the display they are printed over. Byte-oriented `awk` never attempts the conversion.

### macOS ships openrsync, and it is missing flags you will reach for

`/usr/bin/rsync` on a current macOS is **openrsync**, which announces itself as `rsync version 2.6.9
compatible` and is not rsync 3.x. Most of what a sync like this needs is there; the gaps are what
make a recipe copied from anywhere else fail on the first line.

| Flag | | Consequence |
| --- | --- | --- |
| `--modify-window`, `--partial-dir`, `--timeout`, `--contimeout`, `--include`, `--prune-empty-dirs`, `--delete`, `--bwlimit`, `--itemize-changes`, `--stats` | present | nothing to work around |
| `--no-perms`, `--no-owner`, `--no-group` | **missing** | nothing to subtract from `-a`, so build up from `-rt` instead |
| `--info=progress2` | **missing** | `--progress` reports per *file*; an overall figure has to be counted |
| `--dry-run` | **missing** | `-n` works; only the long spelling is absent |
| `--protect-args` | **missing** | a remote path is handed to a shell on the far side, so a space in it arrives re-split into two paths that do not exist. Escape it inside the quotes: `'<host>:/var/media/music/2\ Ohm/'` |
| `--rsh` | **missing** | `-e` accepts a command with options, which is where the ssh keepalives go |

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

### The nginx limits, and why there are three of them

nginx applies **two entirely different limits** here, and the first thing to know is how to tell them
apart — because they fail identically from the browser's side, as a 429.

#### `limit_conn` counts HTTP/2 streams — read this one first

This one takes whole pages down. The vhost runs `http2 on`, and under HTTP/2 a browser opens **one**
TCP connection and multiplexes every request over it — but nginx's `limit_conn` counts each **stream**
as a connection. So a page that asks for thirty cover thumbnails has thirty "connections" as far as
the directive is concerned.

The tempting limit is a low one, on the reasoning that "a browser opens ~6 connections per host".
That is true under HTTP/1.1 and false the moment http2 is on, and it puts the ceiling *below what a
single page load needs*: a queue page asking for a cover per row will see nginx refuse a hundred of
them, the audio stream, and `/favicon.ico`.

**That last one is the diagnostic.** A static file cannot be refused by `limit_req` — those live only
in `location ~ \.php$`. If something outside PHP is 429ing, it is `limit_conn`. Definitively, the
error log names the zone and the kind:

```bash
grep -o 'limiting \(requests\|connections\).* by zone "[a-z_]*"' /var/log/nginx/mixtape.prod.error.log \
  | sort | uniq -c
```

The fix is to bound streams explicitly and keep the connection limit above that ceiling:

```nginx
http2_max_concurrent_streams 64;   # excess streams are QUEUED by the client, not refused
limit_conn mx_conn 256;            # now genuinely about parallel connections
```

`http2_max_concurrent_streams` is the half that does the real work, and it is polite: a client that
wants a hundred thumbnails simply sends the next request as each finishes. Nothing errors.

#### Before you believe any of this took effect

**A shared-memory zone's key cannot be changed by a reload.** Rename `mx_pages`'s key and the master
refuses the entire config:

```
[emerg] limit_req "mx_pages" uses the "$mx_pages_key" key while previously it used the "$binary_remote_addr" key
```

It then keeps the **old workers running the old config** — and it fails silently in both the places
you would check. `nginx -t` *passes*, because it tests in a fresh process with no prior zone to
disagree with. `systemctl reload nginx` *exits 0 and logs "Reloaded"*, because systemd only sees the
signal being delivered, not the master's rejection of it. Two edits and two reloads can go by with
nothing whatsoever changing.

So after any nginx change here, check the **workers**, not the file:

```bash
ps -o pid,lstart -C nginx     # every worker must be newer than your reload
grep -i emerg /var/log/nginx/error.log | tail
```

If a zone's key really must change, rename the zone (a new name has no previous key to contradict)
or `systemctl restart nginx`, which drops in-flight requests but clears the shared memory outright.

#### `limit_req`, and why it is three zones

The rate limit targets scanners walking paths at machine speed rather than anything a reader does.
It is **three zones**, not one, and the reason is structural rather than incidental:

**Everything this app answers is a PHP request.** A page, a cover thumbnail and the audio stream all
arrive at `location ~ \.php$` after `try_files`, so a single zone puts them in one bucket per
visitor. That looks fine until someone presses play on a large artist: the queue fills, the panel
renders a row per track, every visible row fetches its cover — and the burst is gone before the
**stream** is even requested.

(The split is not what fixes the HTTP/2 problem above — those refusals are `limit_conn`'s. It exists
because the shared bucket is a real latent problem in its own right: it costs nothing, and it is what
stops thumbnails from starving audio once the connection ceiling stops hiding the difference.)

So [`files/mixtape-limits.conf`](files/mixtape-limits.conf) sorts requests into three zones with a
`map` on `$request_uri`, and the vhost applies all three:

| Zone | What it holds | Limit |
| --- | --- | --- |
| `mx_dynamic` | pages, forms, everything else | 30r/s, burst 60, `nodelay` |
| `mx_cover` | song and album cover art | 30r/s, burst 200, `delay=60` |
| `mx_stream` | the audio stream | 10r/s, burst 20, `nodelay` |

Four things about that which are easy to get wrong:

- **`$request_uri`, not `$uri`.** The limits are applied *after* `try_files` has internally rewritten
  the request, so `$uri` is `/index.php` for every one of them. `$request_uri` keeps the original.
- **An empty key is not accounted.** That is nginx's documented way to exempt a request from a zone,
  and it is what keeps the three from counting against each other. The `mx_dynamic` map is written as
  the *inverse* of the other two, so nothing is ever counted twice.
- **Covers get `delay=`, not `nodelay`.** Past the first 60, requests are queued and released at
  30r/s rather than refused. A thumbnail that arrives 200ms late is invisible; a 429 is a permanently
  broken image, because `<img>` does not retry. (Covers are cached 30 days with an ETag, so this only
  bites on a first visit.)
- **A `map` regex containing `{36}` must be quoted.** nginx requires quoting for any value containing
  `{` or `}`; unquoted, it is a parse error rather than a rule that quietly never matches.

The stream's rate looks low and is not: one request starts a track, and a few more arrive per *seek*,
since a Range request past what the browser has buffered re-enters PHP before nginx takes over the
bytes.

## Scheduled library scan

The library scan (`php artisan app:update` — see
[`../artisan-commands.md`](../artisan-commands.md)) runs nightly on a **dedicated
systemd timer**, not Laravel's scheduler. A single scheduled command doesn't need
the scheduler's per-minute `schedule:run` indirection, and — decisively for a home
server that sleeps at night — a systemd timer with `Persistent=true` runs a
*missed* scan shortly after the next boot, whereas a skipped `dailyAt()` is simply
lost. It runs as **www-data** (same user as php-fpm) so it reads the app's `.env`,
reaches the DB, and writes `storage/logs` exactly as a request would.

**Before enabling, `app:update` must actually run on the box.** Confirm each, as
www-data, from the app root:

1. **getID3 is installed.** It's a normal (non-dev) dependency, so a prod
   `composer install --no-dev` includes it — but a box last deployed before it was
   added needs a fresh install.
2. **`.env` has the scanner block** (`MIXTAPE_MUSIC_PATH`, `MIXTAPE_AUDIOBOOKS_PATH`,
   `MIXTAPE_SCAN_ALERT_EMAIL` — see
   [`files/env.prod.template`](files/env.prod.template)). There are **no code
   defaults**: an unset path disables that area. After editing `.env`, re-cache:
   `php artisan config:cache` (a stale cached config is the classic "my new env var
   is ignored" trap).
3. **Migrations are applied** (`php artisan migrate --force`) — the scan needs the
   `tracks` / `collections` / taxonomy tables.
4. **www-data can read (and, for cleanup, write) the media** under
   `/var/media/{music,audiobooks}`.
5. **Run one scan by hand first** — `sudo -u www-data /usr/bin/php artisan app:update`
   — to seed the collection and surface any of the above interactively. The first
   run hashes the whole library (minutes); watch `storage/logs/library.log`.

Then install the units (as root):

| File | Destination | Purpose |
| --- | --- | --- |
| [`files/mixtape-library-scan.service`](files/mixtape-library-scan.service) | `/etc/systemd/system/…` | the oneshot unit (www-data, `TimeoutStartSec=1800`) |
| [`files/mixtape-library-scan.timer`](files/mixtape-library-scan.timer) | `/etc/systemd/system/…` | daily `05:30`, `Persistent=true` |

> **Keep this timer clear of the media backup.** The scan's first act is to delete junk files
> inside `/var/media`, and GNU tar exits non-zero when a file disappears from under it — which
> aborts the backup, fires its failure alert, and leaves that Sunday with no snapshot. The backup
> starts at 03:00–03:15 and reads the whole collection twice, so on a slow drive it is still
> running long after 04:00.

```bash
sudo install -m 0644 -o root -g root mixtape-library-scan.service mixtape-library-scan.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now mixtape-library-scan.timer
```

Verify: `systemctl list-timers mixtape-library-scan.timer` shows the next run;
`journalctl -u mixtape-library-scan.service` and `storage/logs/library.log` show
what happened. `app:update` alerts on its own (fatal / empty-area / skipped-files
→ e-mail + non-zero exit), so no `OnFailure=` reporter is wired to the unit.

> **Adjust the cadence** in `mixtape-library-scan.timer` (`OnCalendar=`). Nightly
> suits a collection that changes occasionally; steady-state re-scans are
> near-instant, so a tighter cadence is cheap if you add media often.

## Scheduled share-link prune

Share links expire after seven days, and a dead row is kept for a while after that
on purpose — the owner's list at `/dashboard/shared` shows it, so a link a friend
opens on day nine can be re-sent in one press rather than having vanished. What
sweeps them eventually is a **weekly** timer running Laravel's own prune command
against one model:

```
php artisan model:prune --model="App\Models\Share"
```

The window is `App\Models\Share::PRUNE_AFTER_DAYS` (30) past `valid_until`, so the
command is idempotent and a missed week costs nothing — the next run deletes
exactly what this one would have. **Weekly rather than daily for that reason**: a
30-day window swept daily deletes the same set six more times for nothing.

A dedicated timer rather than Laravel's scheduler, for the reason spelled out
above the library scan: a home server asleep at the scheduled hour needs
`Persistent=true`, and a skipped `dailyAt()` is never caught up.

**Check it before enabling.** `--pretend` counts without deleting, which is the
honest way to find out that a fresh instance has nothing to sweep — or that a
long-running one has more than expected:

```bash
sudo -u www-data /usr/bin/php /var/www/mixtape.prod/artisan model:prune --model="App\Models\Share" --pretend
```

| File | Destination | Purpose |
| --- | --- | --- |
| [`files/mixtape-share-prune.service`](files/mixtape-share-prune.service) | `/etc/systemd/system/…` | the oneshot unit (www-data) |
| [`files/mixtape-share-prune.timer`](files/mixtape-share-prune.timer) | `/etc/systemd/system/…` | Mondays `05:30`, `Persistent=true` |

```bash
sudo install -m 0644 -o root -g root mixtape-share-prune.service mixtape-share-prune.timer /etc/systemd/system/
sudo systemctl daemon-reload
```

**Check what systemd parsed, before running anything.** This unit's `ExecStart` carries a
class name full of backslashes (`--model="App\\Models\\Share"`), and systemd does its own
unescaping — so what the *file* says and what PHP actually receives are two different
questions. `systemctl cat` answers the first. Only this answers the second:

```bash
systemctl show mixtape-share-prune.service -p ExecStart
```

```
ExecStart={ path=/usr/bin/php ; argv[]=/usr/bin/php /var/www/mixtape.prod/artisan model:prune --model=App\Models\Share ; … }
```

Single backslashes in `argv[]` is the answer you want. It is read-only and needs no run, which
makes it the cheapest way to settle any unit whose command line contains escaping, quoting or
`%` specifiers — not just this one.

**Then run it once by hand** and read the journal, before arming the timer:

```bash
sudo systemctl start mixtape-share-prune.service
systemctl status mixtape-share-prune.service --no-pager
```

`Type=oneshot`, so `start` blocks until it finishes. What proves the install is
`status=0/SUCCESS` together with artisan's own line — `No prunable [App\Models\Share] records
found.` is a *success*: it means the class resolved and the database answered. A fresh instance
has nothing old enough to sweep for weeks, so zero is the expected count, and the run is still
worth making: it is the only check that the unit executes as **www-data under systemd**, whose
environment is not the one `sudo -u www-data` from your shell gives it.

```bash
sudo systemctl enable --now mixtape-share-prune.timer
```

> `enable --now` starts the **timer**, not the service — `LAST` stays `-` in `list-timers` until
> the first scheduled run. That is why the hand-run above is a separate step rather than
> something to leave to Monday.

Verify with `systemctl list-timers mixtape-share-prune.timer` and
`journalctl -u mixtape-share-prune.service`. There is no `OnFailure=` reporter: a
failure here leaves a few stale rows in one table, not a broken instance or a lost
backup, and the next week's run picks them up.

> **It cannot delete a live link.** The window is a column comparison against a
> fixed grace period and `--model` pins the command to one model, so the blast
> radius is one table and one `WHERE` clause. `PruneSharesTest` asserts both edges
> of that window and that a live link survives.

## Scheduled database backup

The media library is the only thing whose loss is permanent — but it is not the only
thing that cannot be rebuilt. The database splits cleanly in two:

- **Derived**, and free to lose: tracks, albums, artists, genres, authors, narrators.
  `app:update` reconstructs all of it from the files in well under a minute.
- **Not derived, and gone forever**: accounts and their 2FA enrolment, invites,
  playlists, listening history, player state, audiobook bookmarks, and **share links**.

The last of those is the sharpest. A share id *is* the capability, so a link already
sent to somebody cannot be reissued — restoring an account is an inconvenience, but
losing the `shares` table means links in other people's chat windows stop working and
there is no way to tell them.

A **daily** timer dumps the database to the same drive the media snapshots go to:

```
php artisan app:db-backup
```

**Daily rather than weekly**, unlike the media backup, because the two cost different
things. A media snapshot is most of a terabyte and only changes when somebody rips a
record; a database dump is megabytes and changes every time anyone plays a song. Daily
also sets the worst case for a restore: one day of listening history.

**It writes to the backup drive, not to `storage/`.** A dump that only exists on the
system disk is no use against the failure it is there for. The unit names the mount in
`RequiresMountsFor=` and the command refuses if the parent directory is missing —
belt and braces, because the failure mode here is a *green light*: `mkdir -p` on an
unmounted path cheerfully creates the directory on the root filesystem, and the backup
then succeeds for months onto exactly the disk it was supposed to survive.

**The dump is verified, not just written.** `pg_dump --format=custom` writes to a
`.partial` name, `pg_restore --list` then reads its table of contents back, and only
then is it renamed into place. So a name in that directory means "this one restored",
not "this one finished writing" — the same distinction the media backup's hash check
makes, and the reason a dump interrupted by a full disk or a pulled cable is discarded
rather than kept.

Retention is 30 days (`MIXTAPE_DB_BACKUP_RETENTION_DAYS`), pruned by the date in the
filename rather than by mtime — a file copied or restored carries a new mtime, where
the name still carries the date of the data.

| File | Destination | Purpose |
| --- | --- | --- |
| [`files/mixtape-db-backup.service`](files/mixtape-db-backup.service) | `/etc/systemd/system/…` | the oneshot unit (www-data) |
| [`files/mixtape-db-backup.timer`](files/mixtape-db-backup.timer) | `/etc/systemd/system/…` | daily `02:30`, `Persistent=true` |
| [`files/mixtape-db-backup-failed.service`](files/mixtape-db-backup-failed.service) | `/etc/systemd/system/…` | the `OnFailure=` reporter |

```bash
sudo systemctl enable --now mixtape-db-backup.timer
systemctl list-timers mixtape-db-backup.timer
```

**Check it by hand first**, which costs one dump:

```bash
sudo -u www-data /usr/bin/php /var/www/mixtape.prod/artisan app:db-backup
ls -lh /mnt/usb/db-backups
```

Unlike the library scan and the share prune, this one **does** have an `OnFailure=`
reporter, for the same reason the media backup does: a failed scan leaves stale rows
and the next run fixes them, where a failed backup leaves nothing and you find out when
you need it. It reuses `mixtape-backup-alert.sh`, told which job it is reporting, so the
push names the database and marks that job's own dead-man's switch rather than the
media backup's.

**It runs as root**, unlike the library scan and the share prune — forced by the
drive, not chosen. The backup disk is exFAT, which carries no Unix permissions and is
therefore mounted with fixed ownership (`uid=1000,gid=1000,umask=022`), so `www-data`
cannot write to it at all. `Environment=LOG_CHANNEL=stderr` is what makes that safe:
Laravel's `daily` channel *creates* `storage/logs/laravel-YYYY-MM-DD.log`, and a job
at 02:30 is often the first thing to log on a new day — as root that would leave a
root-owned log file php-fpm cannot write, and the app would silently stop logging.
Sending this unit's output to stderr opens no file at all and puts everything in the
journal.

**It is a full dead-man's switch** if you set `HC_DB_PING_URL` in
`/etc/mixtape/backup-alerts.env`: `OnFailure=` reports a run that ran and failed,
while the success ping is what catches a timer that never fired at all. Give it a
check of its **own** — sharing the media backup's URL means a failed dump marks that
period red and sends you to the wrong drive. Both are optional; unset, they skip.

### Restoring

```bash
sudo -u www-data /usr/bin/php /var/www/mixtape.prod/artisan app:db-restore
```

Interactive: it lists the dumps newest-first, verifies the one chosen **before**
anything is dropped, and then asks you to **type the database name** — not to answer
yes. The mistake worth guarding against is not doubt, it is restoring the right dump
into the wrong database, and a yes/no question never asks which.

`--force` skips the typed confirmation and is **refused outright when
`APP_ENV=production`**. It exists so a development box can be reset from a script; on
the live instance an unattended restore is precisely the thing that should not be
possible.

> **Put the site in maintenance first** (`mt artisan down --prod`). Not enforced — a
> restore is also how you recover an instance that is already down — but `pg_restore
> --clean` drops each object before recreating it, and it will fight open connections
> over the objects it is dropping.

Everything written since the chosen dump is gone when this finishes, share links
included. Run `app:update` afterwards if the media library has changed since, since the
derived half will describe files as they were.

## Not needed yet

- **Queue worker** — nothing implements `ShouldQueue`; mail is sent synchronously. Add a systemd unit
  when that changes.

## Next

[`04-going-public.md`](04-going-public.md) — domain, exposure, TLS, and mail.
