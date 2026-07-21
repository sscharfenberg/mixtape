# Self-hosting MixTape

MixTape is a self-hosted web app that organises a personal mp3 / audiobook collection and plays it in
the browser. It is designed to run on **one always-on machine you own** — typically a home server —
and to be **reachable from the internet**, so you can send someone a link to a song without giving
them an account.

This guide is the whole path from bare hardware to a public, TLS-secured, invite-only instance.

> **Placeholders.** These docs use `<your-domain>`, `<server-lan-ip>`, and `<lan-subnet>` throughout.
> Substitute your own; nothing here is copy-pasteable without doing so.

## Who this is for

Someone comfortable with a Linux shell, `sudo`, and editing config files. You do not need prior
Laravel, nginx, or PostgreSQL experience — every command is given — but you should be willing to read
what a command does before running it, because some of them are destructive and a few are
security-relevant.

## The order

Do these in sequence. Each assumes the previous is done.

| | Document | What you get |
| --- | --- | --- |
| 1 | [`01-requirements.md`](01-requirements.md) | What hardware/OS/network you need, and the reasoning — read before buying or wiping anything |
| 2 | [`02-host-setup.md`](02-host-setup.md) | A hardened Debian host: LVM, packages, firewall, SSH, Samba, PostgreSQL, TLS on the LAN |
| 3 | [`03-production-deploy.md`](03-production-deploy.md) | The app running on that host, deployable with one command |
| 4 | [`04-going-public.md`](04-going-public.md) | A real domain, a port-forward, Let's Encrypt, working transactional mail |

**Nothing is reachable from the internet until step 4**, and step 4 has a hard precondition: real
authentication must be working first. Do not open a port before then.

## Two sites, one box

The recommended layout runs **two isolated sites** on the same machine:

| | Path | Database | Reachable | Purpose |
| --- | --- | --- | --- | --- |
| dev | `/var/www/mixtape.dev` | `mixtape_dev` | LAN only | where you break things |
| prod | `/var/www/mixtape.prod` | `mixtape_prod` | public | what people use |

They share the media library on disk (one collection) but nothing else — separate databases, php-fpm
pools, nginx vhosts, and log files. If you only want one site, build the prod one and skip dev.

## Gotchas index

Every one of these cost real debugging time. They are explained in context in the documents above;
this is a jump table for when something is already broken.

| Symptom | Cause | Where |
| --- | --- | --- |
| `nginx -t` refuses to reload after adding the prod vhost | Two `default_server` blocks on one port — it must be removed from the dev vhost in the same change | [03](03-production-deploy.md#9-nginx-vhost) |
| Locked out of `sudo` entirely | A malformed file in `/etc/sudoers.d/`. Always `visudo -c -f` **before** installing | [03](03-production-deploy.md#7-sudoers) |
| Web user can rewrite application code | Deploy ran at `umask 002`; it must be `027` | [03](03-production-deploy.md#6-writable-directories) |
| `nvm: command not found` during deploy | nvm is a shell function and per-user — must be installed *as* the deploy user, and sourced explicitly | [03](03-production-deploy.md#2-nvm--node) |
| Dev rebuild dies inside `composer install` / `package:discover` | php-fpm created `bootstrap/cache/*.php` as `www-data:www-data 644`; your user can't overwrite them. Normalize ownership first | [03](03-production-deploy.md#rebuilding-the-dev-site) |
| Icons all render empty | The sprite is gitignored **and** not produced by the Vite build; `npm run icons` is a separate step | [03](03-production-deploy.md#10-first-deploy) |
| Editing `.env` changes nothing | Prod runs from cached config — `config:cache` is mandatory after every `.env` edit | [04](04-going-public.md#step-5--app-production-config) |
| Mail "sends" successfully but never arrives | Stale config cache still on `MAIL_MAILER=log`, and the log mailer's debug write is discarded if `LOG_LEVEL` is above debug — silent on both ends | [04](04-going-public.md#step-6--transactional-mail) |
| First scheduled library scan is killed mid-run (~90s) | A `Type=oneshot` unit uses the default start timeout; the full-hash first scan needs `TimeoutStartSec` raised | [03](03-production-deploy.md#scheduled-library-scan) |
| `UnsupportedSchemeException` on send | `MAIL_SCHEME` set to something other than `smtp`/`smtps` — leave it unset | [04](04-going-public.md#step-6--transactional-mail) |
| SPF suddenly fails after adding a record | Two SPF TXT records on one domain = permerror. There must be exactly one, with merged includes | [04](04-going-public.md#dns-records) |
| 429 on a form that validates as you type | Precognition posts to the same route as the submit, so live validation eats the throttle budget | [03](03-production-deploy.md#rate-limiting-and-precognition) |
| `psysh` refuses to start under `artisan tinker` | www-data's home is deploy-owned by design; pass `HOME=/tmp` | [03](03-production-deploy.md#running-artisan-in-production) |
| `storage/logs/laravel.log` missing on prod but present on dev | Different drivers: dev is `single`, prod is `stack`+`daily`, and the daily driver writes `laravel-YYYY-MM-DD.log`. Resolve the newest `laravel*.log` instead of hardcoding | [03](03-production-deploy.md#traps-this-ran-into) |
| `cd /var/www/mixtape.prod && sudo …` → "Permission denied" | The `cd` runs as your login user, which cannot traverse the `2750` deploy-owned tree; only the `sudo` after it becomes `www-data`. Don't `cd` — artisan resolves its base path from `__DIR__` | [03](03-production-deploy.md#traps-this-ran-into) |
| `sudo -u www-data -s` exits instantly with "account is not available" | `-s` uses the target's login shell, and `www-data`'s is `/usr/sbin/nologin` by design. Name `bash` explicitly | [03](03-production-deploy.md#traps-this-ran-into) |
| A shell script works on Linux but aborts on macOS with "unbound variable" | macOS ships bash 3.2, where expanding an **empty** array under `set -u` is an error, not an empty expansion | [03](03-production-deploy.md#traps-this-ran-into) |
| Piping a remote command's output shows `\r` on every line | `ssh -t` allocates a TTY, which translates LF to CRLF. Allocate one only when stdout is a terminal | [03](03-production-deploy.md#traps-this-ran-into) |
| A new zsh completion is ignored even after restarting the shell | oh-my-zsh rebuilds its compdump only when the *fpath string* changes, and adding a file to a directory already on fpath does not change it. Delete `~/.zcompdump*` | [03](03-production-deploy.md#tab-completion-zsh) |
| A completion entry inserts the wrong command but looks right in the list | `_describe` splits on the first **unescaped** colon; colons inside the value must be written `\:` | [03](03-production-deploy.md#tab-completion-zsh) |
| Completing a `--flag` offers nothing, with no error | Candidates starting with `-` need the `options` tag requested; in a nested `_arguments` state `_describe` succeeds while displaying nothing. Use `_wanted options expl … compadd` | [03](03-production-deploy.md#tab-completion-zsh) |
| A custom php-fpm pool's logs grow forever | The stock logrotate entry covers only the master log, not per-pool `error_log`/`slowlog` | [04](04-going-public.md#log-rotation) |
| logrotate silently skips an entry | Its log directory is owned by a non-root user, so it needs an `su` line — the refusal is only reported in logrotate's own output | [04](04-going-public.md#log-rotation) |
| Log rotates once, then stays frozen at 0 bytes | `su` dropped to the service user, so the postrotate signal to a root-owned daemon failed with EPERM and it kept writing to the renamed inode. Use `su root <group>` | [04](04-going-public.md#log-rotation) |
| A fail2ban jail bans legitimate users | The nginx access log shows `POST /login → 302` for success *and* failure; match 429s or a dedicated app log channel instead | [04](04-going-public.md#step-7--login-hardening-and-logs) |
| A jail bans at half its configured `maxretry` | The listener is registered twice — Laravel auto-discovers any `handle*` method in `app/Listeners` on top of your explicit wiring | [04](04-going-public.md#a-dedicated-auth-failure-log) |
| Auth log stops feeding the jail after a config tweak | The channel's `level` was env-driven and `LOG_LEVEL` got raised; pin it to a literal | [04](04-going-public.md#a-dedicated-auth-failure-log) |
| A file-based jail reports healthy but never bans | `backend = systemd` inherited from `[DEFAULT]`, so fail2ban watches the journal instead of the log file | [04](04-going-public.md#the-jail) |
| `fail2ban-regex` hits the datepattern on every line but matches none | fail2ban strips the timestamp before applying `failregex`; a regex that includes the timestamp can never match | [04](04-going-public.md#the-jail) |
| fail2ban won't start after adding a jail | Its `logpath` doesn't exist yet — the app creates the auth log lazily, on the first failure | [04](04-going-public.md#the-jail) |
| A jail bans your whole household at once | LAN clients reaching the public URL are hairpinned by the router, so they all arrive as the WAN IP; a LAN CIDR in `ignoreip` never sees them | [04](04-going-public.md#the-jail) |
| Backup silently skips and never alerts | `ConditionPathIsMountPoint=` on the unit — a failed condition *skips* the unit and records success, so `OnFailure=` never fires | [04](04-going-public.md#four-decisions-worth-understanding) |
| Dead-man's-switch cries wolf on quiet weeks | A "nothing changed" run exited without pinging success; skipped runs are healthy runs and must still report | [04](04-going-public.md#four-decisions-worth-understanding) |
| Unmounting the backup drive doesn't trigger an alert | `RequiresMountsFor=` remounts it as a dependency, so the run succeeds. Test with a `/bin/false` drop-in instead | [04](04-going-public.md#verify-it) |

## What this guide does not cover

- **Restoring or migrating an existing collection.** Point `/var/media` at your files; the scan chain
  builds the database from them.
- **High availability, clustering, or containers.** This is a single-box design on purpose.
- **Anything specific to the author's server.** Host-specific notes are deliberately kept out of this
  repository.
