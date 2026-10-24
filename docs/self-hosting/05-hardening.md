# 5 — Hardening a public instance

> After [`04-going-public.md`](04-going-public.md) the instance is public and working. This document
> narrows what a request from the internet can reach, and what a request that gets too far can do
> next. Each section stands alone and can be applied to a live instance; the only interruption is
> one php-fpm restart.

Several protections are already in place from earlier steps, because they belong in the files they
change: the dev vhost refuses everything outside the LAN
([`02` §2.9](02-host-setup.md#29-tls-on-the-lan-self-signed)); a catch-all vhost answers a bare IP
with nothing; the prod vhost refuses multipart bodies; and the production pool switches off uploads,
upload progress and shell execution ([`03` §8–9](03-production-deploy.md#9-nginx-vhost)).
[§5](#5-verify) below checks all of them from outside.

The files referenced below are in [`files/`](files/). As in `03`:

```bash
SRC=/var/www/mixtape.prod/docs/self-hosting/files
```

## 1. Kernel and network settings

```bash
sudo install -m 644 $SRC/99-hardening.sysctl.conf /etc/sysctl.d/99-hardening.conf
sudo nano /etc/sysctl.d/99-hardening.conf     # replace <lan-interface> (see `ip -br link`)
sudo sysctl --system
```

The file explains each line. One trap in it is worth knowing before you trust any sysctl file,
including one you wrote yourself:

> **`net.ipv4.conf.all.accept_redirects = 0` does not turn redirects off.** On a host that does not
> forward packets, an interface accepts ICMP redirects while **either** `all` **or** its own value is
> 1, and its own value defaults to 1. A file that sets only `all` looks hardened and changes nothing.
> Check the interface, not the file:
>
> ```bash
> sysctl net.ipv4.conf.<lan-interface>.accept_redirects net.ipv6.conf.<lan-interface>.accept_redirects
> ```

`kernel.kexec_load_disabled` is one-way: once set, only a reboot clears it.

## 2. Sandbox php-fpm

php-fpm is the one process that runs code shaped by internet input, and the distribution's unit
gives it no sandbox at all. A systemd drop-in adds one:

```bash
systemd-analyze security php8.4-fpm | tail -1            # the score before
sudo install -d /etc/systemd/system/php8.4-fpm.service.d
sudo install -m 644 $SRC/php-fpm-hardening.conf /etc/systemd/system/php8.4-fpm.service.d/hardening.conf
sudo systemctl daemon-reload
sudo systemctl restart php8.4-fpm                        # a reload does not rebuild the sandbox
systemd-analyze security php8.4-fpm | tail -1            # and after
```

The line that matters most is **`ReadOnlyPaths=/var/media`**. The media library is owned by the web
user, because the library scan deletes junk files in it. Without the drop-in, code that gets
executed inside a php-fpm worker could delete the one thing on this box whose loss is permanent. The
web path only ever reads the library. The library scan runs in its own unit and is not affected.

Confirm the sandbox is real by reading a worker's own mount table. Look for `ro` in the mount options:

```bash
sudo grep ' /var/media ' /proc/$(pgrep -f 'php-fpm: pool mixtape-prod' | head -1)/mountinfo
```

Then exercise the app in a browser: play a track, open a page whose covers are not cached yet, log
in, and send a password reset. The reset is the important one, because it is the only path that
resolves a hostname (the mail relay's).

> **Do not add `RestrictSUIDSGID=yes`**, even though `systemd-analyze` suggests it. The cover cache
> directory is created with the setgid bit, so that the web user and an admin running artisan can
> both clean it up, and that option refuses the `chmod`. The app suppresses that error, so all you
> would see is a cache that only one of the two users can delete from.

## 3. A web application firewall

The app does its own input handling: FormRequests validate every write, every query is
parameterised, and every template escapes its output. A WAF is the layer for the day something
underneath fails anyway, such as a bug in a dependency or a request-parsing flaw in PHP itself. It
refuses the generic injection payloads that scanners spray at every host, before any of them reach
PHP. It is no substitute for the app being correct, and it is set up below so that it cannot make
the app wrong either.

ModSecurity v3 runs inside nginx, with the OWASP Core Rule Set (CRS) as its rules.

### Install

```bash
sudo apt install libnginx-mod-http-modsecurity modsecurity-crs
```

On Debian the module package loads the module itself (`modules-enabled/`), switches the WAF on
nowhere, and drops a sample site into `sites-available/` that is not enabled. Leave the sample there.
This app's configuration replaces it:

```bash
sudo install -d -m 755 /etc/nginx/modsec
sudo install -m 644 $SRC/modsec-main.conf       /etc/nginx/modsec/main.conf
sudo install -m 644 $SRC/modsec-crs-setup.conf  /etc/nginx/modsec/crs-setup.conf
sudo install -m 644 $SRC/modsec-before-crs.conf /etc/nginx/modsec/before-crs.conf
sudo install -m 644 $SRC/modsec-after-crs.conf  /etc/nginx/modsec/after-crs.conf
sudo install -m 644 $SRC/mixtape-modsecurity.conf /etc/nginx/conf.d/mixtape-modsecurity.conf

sudo install -d -o root -g adm -m 750 /var/log/modsecurity
sudo install -o root -g adm -m 640 /dev/null /var/log/modsecurity/audit.log
sudo install -m 644 $SRC/modsecurity.logrotate /etc/logrotate.d/modsecurity
```

When editing these files later, keep **one space** between a directive and its value. A
column-aligned line such as `SecAuditLog      /var/log/…` is silently dropped: `nginx -t` passes,
the rules run, and the audit log stays empty.

Two edits before the reload:

- **Check the session cookie's name** in `after-crs.conf` against the real one. It is derived from
  `APP_NAME` unless `SESSION_COOKIE` overrides it:
  `curl -sI https://<your-domain>/login | grep -i set-cookie`.
- **Uncomment `modsecurity on;`** inside `location ~ \.php$` in both vhosts, and nowhere else (see
  below for why).

```bash
sudo nginx -t && sudo systemctl reload nginx
ps -o pid,lstart,cmd -C nginx            # the worker start times must be new
```

That last check matters. A reload that nginx's master rejects still reports success to systemd
([`03`](03-production-deploy.md#before-you-believe-any-of-this-took-effect) has the story). New worker
start times are the only proof the WAF is loaded.

### Why only the PHP location

The connector Debian 13 ships (ModSecurity-nginx 1.0.3) keeps its per-request state in nginx's module
context, and nginx **clears that context on every internal redirect**. Two internal redirects sit on
this app's hot path: `try_files … /index.php` on every page, and `X-Accel-Redirect` on every track.
With `modsecurity on` for the whole server, each redirect starts a fresh transaction. Measured
by counting one rule's matches for a single probe request:

| Request | WAF in the PHP location only | WAF on server-wide |
| --- | --- | --- |
| A page (`try_files` → `index.php`) | evaluated once | evaluated **twice** |
| A track (`X-Accel-Redirect` → `internal`) | evaluated once | evaluated **three times** |

The extra passes cost CPU. They also cost the **audio its sendfile**: the last transaction belongs to
the `internal` media location, and the connector's header filter asks nginx to keep that response
in memory. Every track is then copied through userspace instead of going straight from disk to
socket.

Upstream fixed the context recovery in 1.0.4. On 1.0.3, enabling the WAF only in the PHP location
avoids both problems. Every request the app answers passes through that location exactly once, and
static files and the media hand-off never do. Neither of those runs application code, so nothing
they serve gains anything from the WAF.

**One blind spot follows from the same cause, and only in detection mode.** The audit log is
written at the end of a request, by whichever transaction still exists. On a route that answers
with `X-Accel-Redirect` (the streams, a single-song download), the redirect discards the
transaction first. A **match** on those routes is therefore evaluated but never logged while the
engine is `DetectionOnly`. A **refusal** is unaffected: a refused request never reaches PHP, so it
is never redirected, and it is logged like any other. Those routes take no input beyond a UUID in the
path, the cookies and a `Range` header, which is why the enforcing walk below plays, seeks and
downloads by hand.

### What the configuration decides

The files carry the reasoning; this is the map.

| Decision | Where | Without it |
| --- | --- | --- |
| PUT, PATCH, DELETE are allowed methods | `crs-setup.conf` | Renaming or deleting a playlist, renewing a share and the play-queue sync are all scored as attacks |
| Only JSON and urlencoded bodies are allowed | `crs-setup.conf` | Multipart (never sent by the app) is accepted by the WAF |
| Password fields are never matched | `after-crs.conf` | A password containing `--` or `<` is quoted into the audit log, and into nginx's error log once blocking |
| The raw body is never matched on the credential routes | `before-crs.conf` | The same leak, through the few rules that read `REQUEST_BODY` |
| The bulk id lists skip body inspection | `before-crs.conf` | A queue longer than 1,000 tracks is refused with 400, and every sync runs every rule over every id |
| No request bodies in the audit log | `main.conf` (`SecAuditLogParts`) | The module's sample config logs bodies for every 4xx, so failed logins are stored with their passwords |
| Audit only rule matches and 5xx responses | `main.conf` | Every 422, 429 and 419 is logged, and the real matches drown |
| Response bodies are not inspected | `main.conf` | Every page is buffered for leak rules that have nothing to find |
| Laravel's own cookies are not matched | `after-crs.conf` | Encrypted base64 values become a steady source of false positives |

The paranoia level stays at 1, the CRS default. Each level above it adds rules that fire on ordinary
free text, and this app is built out of free text: song titles, playlist names, search terms.

### Detection first

`main.conf` ships with **`SecRuleEngine DetectionOnly`**: every rule runs and every match is logged,
but nothing is refused. Prove it is running:

```bash
curl -s -o /dev/null -w '%{http_code}\n' 'https://<your-domain>/?q=%3Cscript%3Ealert(1)%3C%2Fscript%3E'   # 200
tail -n 1 /var/log/modsecurity/audit.log | python3 -m json.tool | grep -E '"ruleId"|"message"'
```

Then leave it in that mode **for about a week of real use**, so that every page, every form and a
few real searches have gone through it. The audit log is one JSON object per line and readable by
the `adm` group. This summarises it by rule, route and matched value, with UUIDs collapsed so that a
thousand cover requests count as one route. It reads the rotated files too, compressed ones
included: the log rotates daily, so after a week of detection today's file is a seventh of the
evidence. Save it once, run it whenever:

```bash
cat > ~/waf-summary.py <<'EOF'
import collections, glob, gzip, json, re
uuid = re.compile(r'[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}')
seen = collections.Counter()
for path in sorted(glob.glob('/var/log/modsecurity/audit.log*')):
    with (gzip.open if path.endswith('.gz') else open)(path, 'rt') as log:
        for line in log:
            t = json.loads(line)['transaction']
            route = uuid.sub('{id}', t['request']['uri'].split('?')[0])
            for m in t.get('messages', []):
                d = m.get('details', {})
                seen[(d.get('ruleId'), route, d.get('data', '')[:70])] += 1
for (rule, route, data), n in seen.most_common(50):
    print(f'{n:5}  {rule}  {route}  {data}')
EOF
python3 ~/waf-summary.py
```

Reading it:

- **A scanner probing `/.env`, `/wp-login.php` or `/cgi-bin/` is the WAF working.** Those are true
  positives. Leave them alone.
- **A match on a real user's request is a false positive** — a search for a band whose name reads
  like a shell command, say. Exclude **one rule from one argument** in `after-crs.conf`, never a
  whole rule from the whole site:

  ```apache
  # 932100 (Unix command injection) fired on a search term, ARGS:q, that only
  # looked like a shell command. The search box never reaches a shell.
  SecRuleUpdateTargetById 932100 "!ARGS:q"
  ```

- A match on a route listed in `before-crs.conf` means a route there has drifted from
  `routes/web.php`.

### Enforcing

When a week of real use produces nothing but scanners, set **`SecRuleEngine On`** in `main.conf`,
then `nginx -t`, reload, and check the workers as above. The probe above must now answer **403**.
Then walk the app the way a user does:

- log in;
- play a track and seek in it, then download a song (the routes detection mode cannot see; see
  above);
- queue a whole artist;
- create, rename, reorder and delete a playlist;
- share something and renew an expired share;
- change your password.

Each refusal leaves a `ModSecurity: Access denied` line in the vhost's error log, naming the rule.

**Kill switch:** `SecRuleEngine DetectionOnly` (keeps logging) or `Off`, then reload. One line, and
no vhost is touched.

### When the rules update

`modsecurity-crs` receives security updates like any other package, but nginx keeps the rules it
loaded until it is reloaded. A library update reaches nginx through the restart in §4; a rules-only
update does not. Reload after every package run:

```bash
echo 'DPkg::Post-Invoke { "if [ -e /run/nginx.pid ] && /usr/sbin/nginx -t -q 2>/dev/null; then /usr/bin/systemctl reload nginx; fi"; };' \
  | sudo tee /etc/apt/apt.conf.d/80reload-nginx-rules
```

The `nginx -t` guard means an update that ever broke the rules would leave the running nginx alone
rather than half-reloading it. Such a break would still stop nginx from **starting** at the next
boot. If nginx fails to start after a reboot, suspect the rules first: `sudo nginx -t` names the
file and line.

## 4. Patches that take effect

`unattended-upgrades` installs security updates, but only from the suites its config names, and
Debian's default leaves **`<codename>-updates`** commented out. That suite is where `tzdata` and
`ca-certificates` updates arrive, so they are never installed until somebody runs apt by hand. Add
it in a file of its own; apt appends the entry to the list rather than replacing it:

```bash
printf '%s\n' 'Unattended-Upgrade::Origins-Pattern {' \
              '        "origin=Debian,codename=${distro_codename}-updates";' '};' \
  | sudo tee /etc/apt/apt.conf.d/51unattended-upgrades-updates
```

Two kinds of the updates it does install have no effect until something else happens:

- **A library update does not reach a daemon that is already running.** When OpenSSL, libxml2 or
  libmodsecurity is patched, nginx, php-fpm and PostgreSQL keep the old copy mapped until they
  restart. `needrestart` finds those processes and, set to `a`, restarts them:

  ```bash
  sudo apt install needrestart
  echo "\$nrconf{restart} = 'a';" | sudo tee /etc/needrestart/conf.d/50-autorestart.conf
  sudo needrestart -b       # batch mode: lists anything still running old code
  ```

  It leaves alone services that are unsafe to restart under a login, such as `systemd-logind`,
  `dbus` and `getty`. A restart of PostgreSQL or php-fpm interrupts the requests in flight for about
  a second.

- **A kernel update needs a reboot.** Without one, the box runs the old kernel until somebody
  happens to notice `/run/reboot-required`, which can take months. `unattended-upgrades` can reboot
  by itself, at a time you choose:

  ```bash
  printf '%s\n' 'Unattended-Upgrade::Automatic-Reboot "true";' \
                'Unattended-Upgrade::Automatic-Reboot-Time "04:30";' \
    | sudo tee /etc/apt/apt.conf.d/52unattended-upgrades-reboot
  ```

  It reboots only when an update asked for one. Pick a time that misses your own timers
  (`systemctl list-timers`): a reboot in the middle of a backup is a failed backup. Before you rely
  on it, **reboot once by hand** and confirm that everything comes back on its own (sites, mounts,
  timers), because from then on nobody is watching when it reboots.

## 5. Verify

From **outside** the LAN — a phone on mobile data, or any outside host — or from inside through the
router's hairpin. A LAN machine that connects to your public IP arrives with the **WAN** address as
its source (see [`04`](04-going-public.md#the-jail)), which is exactly what an outsider looks like:

```bash
WAN=$(dig +short <your-dyndns-host> | tail -1)

# The dev site, asked for by name through the public IP: refused
curl -sk -o /dev/null -w '%{http_code}\n' --resolve <your-dev-host>:443:$WAN https://<your-dev-host>/   # 403

# A bare IP gets nothing on either port
curl -sk https://$WAN/ ; echo "exit $?"        # 35: handshake refused
curl -s  http://$WAN/  ; echo "exit $?"        # 52: empty reply

# Production refuses a multipart body, and the WAF refuses an obvious payload
curl -s -o /dev/null -w '%{http_code}\n' -F probe=1 https://<your-domain>/                              # 415
curl -s -o /dev/null -w '%{http_code}\n' 'https://<your-domain>/?q=%3Cscript%3Ealert(1)%3C%2Fscript%3E'  # 403 once On

# TLS 1.2 offers no CBC suites
echo | openssl s_client -connect <your-domain>:443 -tls1_2 -cipher ECDHE-ECDSA-AES128-SHA 2>&1 | grep -c 'Cipher is'   # 0
```

For a hairpinned test, confirm that it really went the way an outsider's would: the dev vhost's
access log must show your WAN address for that request, not a LAN one.

On the server:

```bash
sysctl net.ipv4.conf.<lan-interface>.accept_redirects kernel.yama.ptrace_scope   # 0, 1
systemd-analyze security php8.4-fpm | tail -1
sudo needrestart -b
```

## What is deliberately not here

- **An AppArmor profile for php-fpm.** The systemd sandbox in §2 provides the containment that
  matters, the read-only media library above all, for a fraction of the upkeep. A profile that is
  slightly wrong fails in ways that look like application bugs.
- **`open_basedir`.** It is enforced by PHP, which means the code execution it is supposed to contain
  can step around it. The part that matters is enforced by the kernel in §2.
- **A fail2ban jail on WAF matches.** The reasoning is the same as for the bot-search jail in
  [`04`](04-going-public.md#log-rotation): scanners rotate addresses, so bans land on hosts that were
  not coming back, and a mistaken ban locks out a real person. The WAF already refuses the request
  itself.
- **More SSH settings.** SSH is LAN-only at the firewall, key-only, and limited to one user
  ([`02` §2.4](02-host-setup.md#24-ssh-hardening)). Nothing left to tune there changes who can reach it.
