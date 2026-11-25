# 4 — Going public

> Taking the instance from LAN-only to publicly reachable: a real domain, a port-forward, a trusted
> certificate, and working transactional mail. Do these in order.

## 🚫 Hard precondition — authentication before exposure

**Do not forward any port until real authentication is in force.** Invite-only registration and share
links must both work on the LAN first.

This is not a formality. The moment port 443 is forwarded, the entire collection is one
misconfiguration away from being world-readable, and scanners find new hosts within minutes. Verify
that a logged-out browser can reach nothing but the login page before continuing.

## Step 1 — Domain and DNS

The instance needs a **real domain**, for three separate reasons: a clean URL, a Let's Encrypt
certificate, and — the one people forget — the **SPF/DKIM/DMARC TXT records** mail requires. Your home
IP is almost certainly dynamic, so something has to keep the domain's address record current.

Host the domain's DNS at a provider with an API that both **ddclient** (the address) and **certbot**
(the certificate, Step 4) speak. Cloudflare's free plan is the common choice and the one shown here.
The registration can stay where it is; only the nameservers move.

- **Every record "DNS only"** (the grey cloud). Proxied, Cloudflare terminates TLS and sits inside
  every audio stream, and the server sees Cloudflare's addresses instead of visitors' — fail2ban, the
  rate limits and the WAF all key on the client address. A proxied CNAME also answers with
  Cloudflare's addresses instead of its target, which breaks DKIM and Return-Path records outright.
- An **A record for `<your-domain>`**, kept on your WAN address by ddclient on the server. The apex
  works because this is an A record; any other name (`www`, an older public name) is a CNAME to it.
- **Two API tokens**, each *Zone: Read + DNS: Edit* on this one zone: one for ddclient, one for
  certbot. Separate tokens can be rolled without touching the other job.

```conf
# /etc/ddclient.conf — root 600: it holds the token
ssl=yes
usev4=webv4, webv4=https://1.1.1.1/cdn-cgi/trace, webv4-skip='ip='
usev6=disabled

protocol=cloudflare, \
zone=<your-domain>, \
login=token, \
password='<ddclient token>' \
<your-domain>
```

```bash
sudo systemctl restart ddclient
sudo grep -o 'status-ipv4=[a-z]*' /var/cache/ddclient/ddclient.cache    # status-ipv4=good
```

> **Never run ddclient with `-verbose` or `-debug` where the output is logged** — both print the
> Authorization header, i.e. the token. And read `status-ipv4`, not `status`: the plain field says
> `noconnect` in ddclient 3.11 even when every update succeeds.

> **Moving the nameservers takes a day to settle.** The registry's delegation carries a short TTL,
> but the previous provider's own NS records typically carry 86400 s, and a resolver that cached those
> keeps asking the old zone until they expire. Give every name in use the same answer in both zones
> until then, and keep anything the old zone pointed at — a DynDNS name behind a CNAME — alive for
> that day too.

> **A CNAME at the apex is impossible** — the DNS specification forbids it, because the apex must
> carry SOA and NS records and a CNAME cannot coexist with other records. That is why a DynDNS
> hostname behind a CNAME only ever works for a subdomain, and why an apex on a dynamic IP needs a
> provider whose A record a client can update.

## Step 2 — Port-forward

- Forward **TCP 80 and 443** to the server's LAN address. Port 80 carries only the HTTP→HTTPS
  redirect: certificates are validated through DNS (Step 4), so renewal needs no inbound port.
- **Verify SSH (22), Samba (139/445), and the database are not forwarded.** Check this explicitly
  rather than assuming; some routers helpfully "assist" with UPnP.
- **Decide about IPv6.** If your ISP gives the host a public IPv6 address, it may be reachable
  *without* any forward, because IPv6 has no NAT — the router firewall is the only thing stopping it.
  Either open it deliberately for 80/443, or confirm it is closed. Do not leave this unexamined.

## Step 3 — Widen the firewall

Replace the LAN-only web rule from [`02-host-setup.md`](02-host-setup.md#25-firewall-nftables):

```nft
# was:  ip saddr <lan-subnet> tcp dport { 80, 443 } accept
        tcp dport { 80, 443 } accept
```

```bash
sudo nft -c -f /etc/nftables.conf && sudo nft -f /etc/nftables.conf
sudo systemctl restart fail2ban     # re-adds its ban table after the reload
```

SSH and Samba stay LAN-scoped; the database stays localhost-only.

## Step 4 — Real TLS certificate

Validate through DNS — the **DNS-01** challenge — rather than over port 80. certbot writes a TXT
record through the provider's API, so issuance and renewal need no inbound port, and `certonly` never
edits the vhost.

```bash
sudo apt install -y certbot python3-certbot-dns-cloudflare
# The certbot token, without touching shell history — type the line, then Ctrl-D:
#   dns_cloudflare_api_token = <certbot token>
sudo sh -c 'umask 077; cat > /etc/letsencrypt/cloudflare.ini'

# Rehearse against the staging CA first; it costs no production rate limit.
sudo certbot certonly --dry-run --dns-cloudflare \
  --dns-cloudflare-credentials /etc/letsencrypt/cloudflare.ini \
  --dns-cloudflare-propagation-seconds 30 \
  --cert-name <your-domain> --key-type ecdsa -d <your-domain>

# Then the same without --dry-run, plus the hook that makes nginx load renewals:
sudo certbot certonly --dns-cloudflare \
  --dns-cloudflare-credentials /etc/letsencrypt/cloudflare.ini \
  --dns-cloudflare-propagation-seconds 30 \
  --cert-name <your-domain> --key-type ecdsa -d <your-domain> \
  --deploy-hook 'systemctl reload nginx'
```

Add one `-d` per extra name the vhost redirects (`www.`, an older public name). The vhost in
[`files/mixtape.prod.nginx.conf`](files/mixtape.prod.nginx.conf) already reads
`/etc/letsencrypt/live/<your-domain>/`. Debian's `python3-cloudflare` prints a large `!!` WARNING
banner about the package version on every run; it is harmless. Then confirm renewal actually works:

```bash
sudo certbot renew --dry-run
systemctl list-timers | grep certbot
```

A certificate that cannot auto-renew silently expires in 90 days.

> **Right after a nameserver move, the dry run is the test.** A Let's Encrypt resolver that still
> holds the previous provider's NS records asks the old zone, finds no challenge record and fails.
> It only holds them if it looked the domain up within their TTL, and each failed real attempt costs
> one of five per hostname per hour — so rehearse first rather than wait by the clock.

## Step 5 — App production config

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://<your-domain>
```

```bash
sudo -u www-data /usr/bin/php /var/www/mixtape.prod/artisan config:cache
sudo -u www-data /usr/bin/php /var/www/mixtape.prod/artisan route:cache
sudo -u www-data /usr/bin/php /var/www/mixtape.prod/artisan view:cache
sudo -u www-data /usr/bin/php /var/www/mixtape.prod/artisan event:cache
```

> ⚠️ **Production runs from cached config, so editing `.env` changes nothing on its own.** Every
> `.env` edit must be followed by `optimize:clear` + `config:cache`. Verify with `artisan about`,
> which prints what the app actually believes. The routine deploy script does this for you; manual
> edits do not.

nginx terminates TLS directly on the host — the router forward is plain NAT, not a reverse proxy — so
no `TrustProxies` or `X-Forwarded-*` handling is needed. If you *do* put a proxy in front later, that
changes.

> **Changing the canonical host later** is a vhost swap plus `APP_URL`, and the previous name stays
> a 301 (the optional block in the vhost) so share links already sent keep working. Three things
> come with it:
>
> - **Point the deploy script's `HEALTH_URL` at the new host first.** It does not follow redirects and
>   accepts only 200 or 503, so once the old name answers 301, every deploy fails — and a failed
>   deploy deliberately leaves production in maintenance mode.
> - **Everyone signs in once more.** With `SESSION_DOMAIN=null` the session and remember-me cookies
>   belong to the host that set them, and the new host never receives the old ones.
> - **Per-device player settings reset once** (theme, volume, speed, visualiser): `localStorage` is
>   per origin. The play queue is stored server-side and survives.
>
> The order of the vhost swap and the `APP_URL` change does not matter for pages and mail — inside a
> request Laravel builds URLs from the request's host — as long as no `trustHosts` middleware pins the
> old name. `APP_URL` is what console commands and the scheduler use.

## Step 6 — Transactional mail

The app sends password resets, email verification, and username reminders. **Email verification gates
login**, so until mail works, an invited person cannot finish signing up without someone reading the
link out of the logs for them.

Send through a **relay**. Never send directly from a residential IP: those ranges are blocklisted and
have no PTR record, so the mail is discarded — usually silently, which is the worst failure mode.

Any relay works (Mailtrap, Postmark, SES, Brevo…). The setup shape is the same.

> ⚠️ If your provider offers both a **sandbox/testing** inbox and a **sending** product, make sure you
> use the sending one. A sandbox captures mail and never delivers it, so a misconfiguration here looks
> exactly like success while nobody receives anything.

### DNS records

Your provider generates the exact values. Three things to know:

- **DKIM** — usually CNAMEs on selector subdomains. No special handling.
- **SPF** — ⚠️ **exactly one SPF TXT record per domain, ever.** If the domain already has one (a
  registrar's mail service often adds one), you must **merge** the includes into that single record:

  ```
  v=spf1 include:existing-provider include:new-provider ~all
  ```

  Two SPF records produce a permerror, and SPF fails *entirely* — strictly worse than having no SPF
  record at all. This is the single most common way to break mail while trying to fix it.

  Some providers avoid SPF changes altogether by giving you a **custom Return-Path** CNAME. Since SPF
  authenticates the envelope sender, that subdomain inherits the provider's SPF and still aligns with
  your domain. If you are offered this, take it — no merge, no lookup budget consumed.

- **DMARC** — a TXT record at `_dmarc`. Start at `p=none` (monitor only) and tighten to
  `quarantine`/`reject` once you have confirmed SPF and DKIM both pass *and align*.

  ```
  _dmarc    TXT    "v=DMARC1; p=none;"
  ```

  If your provider hands you a DMARC record with `rua`/`ruf` pointing at *their* address, consider
  writing your own instead: those are your reports, and `ruf` in particular ships the content of
  failing messages — your users' password-reset mails — to a third party. Add `rua` later, pointing
  at a mailbox you control. Note that a cross-domain `rua` requires the receiving domain to publish
  `<your-domain>._report._dmarc.<their-domain>` or the reports are silently dropped.

- **Inbound mail** is optional, and the relay does not provide it. Cloudflare's **Email Routing**
  forwards chosen addresses (`admin@<your-domain>`) to a mailbox you already read, for free — which
  also gives `rua=` reports a home on your own domain. Enabling it adds three MX records, a DKIM key of
  its own (`cf2024-1._domainkey`, beside the relay's selectors) and an SPF record at the apex. The
  one-SPF rule above applies to it like to any other. Leave its catch-all rule disabled: mail to
  `no-reply@` then bounces at once instead of landing in your inbox.

**Click/open tracking is offered by most relays; decline it for this app.** It rewrites every link to
route through the provider's domain, which for verification and password-reset mail means a third
party sits in the middle of your security-sensitive links — and rewritten links read as phishing to
both users and spam filters.

### App configuration

```ini
MAIL_MAILER=smtp
MAIL_HOST=<relay host>
MAIL_PORT=587
MAIL_USERNAME=<from the relay>
MAIL_PASSWORD=<from the relay>
MAIL_FROM_ADDRESS="no-reply@<your-domain>"
MAIL_FROM_NAME="${APP_NAME}"

LOG_LEVEL=warning
```

Two traps in that block:

> ⚠️ **Leave `MAIL_SCHEME` unset.** Laravel accepts only `smtp` or `smtps` and derives the right one
> from the port (465 → `smtps`, otherwise `smtp` with STARTTLS negotiated automatically). A plausible-
> looking value such as `tls` reaches Symfony's transport factory and throws
> `UnsupportedSchemeException`.

> ⚠️ **Raise `LOG_LEVEL` in the same edit.** While `MAIL_MAILER=log`, the level must be `debug`,
> because the log mailer writes rendered messages at debug level — anything higher discards them. Once
> real delivery works, leave it at `debug` and every email, including signed reset links, sits in your
> production logs indefinitely.
>
> The combination is what bites: a **stale config cache** still on `MAIL_MAILER=log` plus a raised
> `LOG_LEVEL` means mail "sends" successfully, writes nothing, and delivers nothing. Silent at every
> layer. `artisan about` is how you find it.

Then `optimize:clear` + `config:cache`, and confirm *Drivers → Mail* reads `smtp`.

### Verify

```bash
sudo -u www-data env HOME=/tmp /usr/bin/php /var/www/mixtape.prod/artisan tinker \
  --execute='Mail::raw("test", fn($m) => $m->to("you@example.com")->subject("test"));'
```

Then send one to [mail-tester.com](https://mail-tester.com) and check the **authentication** section —
ignore the overall score, which penalises a bare test message for thin content. You want SPF, DKIM and
DMARC each passing *and aligned* with your domain. (Alternatively, mail a Gmail account and use
**Show original**, which prints the same verdicts without involving another service.)

Finally exercise the real flow: request a password reset in the browser and confirm the link works
when clicked. That tests `APP_URL` and signed-URL generation, which a raw test message does not touch.

## Step 7 — Login hardening and logs

Now that a public login surface exists:

Application-level throttling already covers the login and mail routes (see
[`03-production-deploy.md`](03-production-deploy.md#rate-limiting-and-precognition)), so the jail below
is defence in depth rather than the primary gate. The `sshd` jail already covers SSH.

> **A jail on the nginx access log cannot see what you think it can.** The log records
> `POST /login → 302` whether the credentials were right or wrong — the framework redirects back to
> the form either way — so a naive `failregex` bans successful users. Match something that *is*
> unambiguous: either the **429s** your own throttle already emits (a client tripping a 5/min login
> limiter is misbehaving by definition), or a **dedicated application log channel** fed by the
> framework's authentication-failure event. The second is cleaner and worth the small app change.

### A dedicated auth-failure log

The app writes one line per authentication failure to its own channel
(`config/logging.php` → `auth`, filled by `App\Listeners\LogAuthenticationFailures`):

```
[2026-07-19 07:18:02] production.WARNING: login.failed ip=203.0.113.9 username="ada" user_id=- route="login.store" ua="curl/8.7.1"
```

**The submitted password is not there, on purpose.** It is the obvious field to want — it is how you
would spot one password sprayed across many accounts — but failed logins carry *working* credentials
far more often than intuition suggests: the user typoed the username, or picked the wrong entry out
of a password manager. Logging it builds a plaintext credential store that outlives the account,
survives the next password change, and is copied into every backup. A truncated hash is not a fix
either; a fast unsalted hash of a human password is a wordlist away from plaintext. `ua` answers the
same operational question — human or script — and holds no secret.

Three things about that channel are load-bearing rather than stylistic:

- **Its level is a literal, not `env('LOG_LEVEL')`.** Production runs at `warning`; the day someone
  raises that to `error`, an env-driven channel would stop feeding the jail and nothing would look
  broken.
- **`ip=` comes first, and everything after it is scrubbed and quoted.** The username is whatever the
  attacker typed. Left raw, a newline in it forges a complete log line — and since fail2ban bans the
  address it reads *out of the matched line*, that turns your own jail into a way to ban a third
  party. The listener strips control characters, bounds the length, and JSON-quotes the result.
- **Only failures are logged.** A successful login would otherwise write a valid username beside a
  valid IP into a file that outlives the session.

Because the format is parsed by a filter that fails silently, treat it as an interface: the app's
test suite keeps a mirror of the `failregex` and asserts real output against it, including that one
failure produces exactly *one* line.

> **Watch out for double registration.** Laravel discovers listeners in `app/Listeners` by matching
> any `handle*` method against its type-hint. A listener that is *also* wired explicitly gets
> registered twice, and every failure is logged twice — which silently halves the effective
> `maxretry` of any jail counting those lines. Either rely on discovery alone, or name the methods
> something other than `handle*`, as this app does.

### The jail

> **Check your `[DEFAULT]` block first.** A hardened `jail.local` typically sets `backend = systemd`
> so the `sshd` jail reads the journal. That is inherited by every jail — including a file-based one
> like this — which makes fail2ban watch the journal for a log that only exists on disk. The jail
> starts, reports itself healthy, and matches nothing. The shipped jail file overrides `backend`
> for exactly this reason; do not drop the line because it looks redundant.
>
> Inherit `ignoreip`, `banaction`, `bantime` and `findtime` from `[DEFAULT]` rather than repeating
> them, so the ban mechanism and the LAN exemption stay defined in one place.

**The log must exist before the jail starts.** fail2ban refuses to start a jail whose `logpath` is
missing, and the app only creates `auth.log` on the first authentication failure — so deploy, then
fail one login on purpose, then install the jail.

Install the filter and jail, then reload:

```sh
sudo install -m 0644 mixtape-auth.fail2ban-filter.conf /etc/fail2ban/filter.d/mixtape-auth.conf
sudo tee -a /etc/fail2ban/jail.local < mixtape-auth.fail2ban-jail.conf   # edit ignoreip first
sudo fail2ban-client reload
```

Verify against the real log before trusting it — `fail2ban-regex` reports how many lines matched, and
zero matches is the failure mode you will not otherwise notice:

```sh
sudo fail2ban-regex /var/www/mixtape.prod/storage/logs/auth.log \
  /etc/fail2ban/filter.d/mixtape-auth.conf
sudo fail2ban-client status mixtape-auth
```

Then fail a login on purpose and confirm the counter moves.

> **A LAN range in `ignoreip` does not protect your household.** When someone inside the LAN opens
> the *public* URL, the router hairpins the connection (NAT loopback) and the web server sees your
> **WAN** address — so every device in the house arrives as one external-looking IP that no LAN CIDR
> covers. Ten fumbled passwords from anyone at home then bans the whole household off the public
> link (the LAN hostname still works, which is exactly why this can go unnoticed until a guest
> complains).
>
> Verify rather than assume: fail a login from a LAN browser *via the public URL* and read the `ip=`
> field. If it matches `dig +short <your-domain>`, you are hairpinned. Fix by ignoring your own
> domain — ddclient keeps its A record on the WAN address, and fail2ban accepts hostnames and
> re-resolves them, so it tracks a dynamic IP:
>
> ```
> ignoreip = 127.0.0.1/8 ::1 <your-lan-cidr> <your-domain>
> ```
>
> Costs a DNS lookup per check, and leaves a brief window after an IP change where the house is
> bannable again. Both beat the alternative.

### Log rotation

Rotation is not automatic for anything you configured yourself, and an unrotated log on a
public-facing box is a slow-motion disk-full outage.

- **nginx** — the stock `/etc/logrotate.d/nginx` globs `/var/log/nginx/*.log`, so per-vhost logs are
  already covered. Nothing to do.
- **php-fpm** — the stock entry covers only the *master* log. A per-site pool with its own
  `error_log` / `slowlog` (as in [`files/mixtape-prod.pool.conf`](files/mixtape-prod.pool.conf)) is
  **not** rotated by anything. Install
  [`files/mixtape-php.logrotate`](files/mixtape-php.logrotate) as `/etc/logrotate.d/mixtape-php`; the
  file's header explains the non-obvious requirements (`su root <group>`, and signalling the fpm
  master).
- **the app's own logs** — `storage/logs/*.log` are not rotated by anything either. Install
  [`files/mixtape-auth-log.logrotate`](files/mixtape-auth-log.logrotate) for the auth log. It needs
  *no* postrotate signal, because the framework rebuilds its container per request and reopens the
  path on its own — which is also why its `su` can safely drop to the web user, unlike the php-fpm
  entry above.
- Verify rather than assume — `sudo logrotate --debug /etc/logrotate.d/mixtape-php` dry-runs the
  entry and prints what it *would* do, including the "insecure permissions, skipping" refusal.

> **A dry run does not exercise the postrotate script.** If nothing is due for rotation, logrotate
> prints `not running postrotate script, since no logs were rotated` and you learn nothing about the
> half most likely to be wrong. Note *which user* the debug output drops to (`switching euid from 0
> to …`) and ask whether that user can actually signal the daemon that owns the log — a slow log
> opened by a root-run master cannot be reopened by an unprivileged postrotate, and the helper exits
> 0 either way. The symptom, a week later, is a live log frozen at zero bytes next to a rotated file
> that is still growing.
>
> To actually settle it, force one rotation and check that the daemon *acted* on the signal. Seed a
> byte first — `notifempty` skips a zero-length file even under `-f`:
>
> ```sh
> sudo sh -c 'echo rotation-test >> /var/log/php/<pool>.slow.log'
> sudo logrotate -vf /etc/logrotate.d/mixtape-php
> sudo tail -5 /var/log/php8.4-fpm.log        # expect: NOTICE: error log file re-opened
> ```
>
> That notice, timestamped to the rotation, is the confirmation. Inspecting `/proc/<pid>/fd` is *not*
> a reliable substitute: fpm reopens the slow log by path per dump rather than holding a handle, so
> an empty result there proves nothing either way. Delete the seeded `.1` file afterwards so it
> isn't later mistaken for a real slow-request record.

Also revisit **verbosity** now that real traffic is arriving: `LOG_LEVEL=warning` in the production
`.env` keeps the application log to things you would actually act on.

> **Check what it is rather than what you meant it to be.** The `.env` template ships `LOG_LEVEL=debug`
> with a commented `warning` line to flip at go-live, alongside the `MAIL_MAILER` switch. Flipping one
> and not the other is easy, produces no symptom, and leaves production writing debug-level detail
> indefinitely. `grep -E '^LOG' .env` takes a second, and `artisan config:show logging` confirms the
> *cached* value — the one that actually applies.

Two things worth knowing before you tune anything on a freshly public site:

- **A large access log is mostly not your users.** Expect roughly half of it to be scanners probing
  for `/.env`, `/.npmrc`, `/.netrc` and friends. If your dotfile deny rule is working, those show up
  as 403s in the error log — so an "alarming" error log full of `access forbidden by rule` is the
  security config succeeding, not failing.
- **Resist adding a bot-search jail for it.** The probes are already refused cheaply, and the sources
  rotate across proxy ranges, so bans land on addresses that were not coming back. Every extra jail
  is another way to ban someone by mistake, and daily rotation with `rotate 14` already bounds the
  volume.

## Step 8 — Backups and alerting

The media is the only thing whose loss is permanent, and a backup drive in the same house as the server
shares its fate: a fire, a theft or a surge takes both. So the collection goes to two places — the
backup drive and off-site storage — in one nightly job that also takes the database dumps
([`03`](03-production-deploy.md#scheduled-database-backup)) and the server configuration this guide
builds. Then the job gets an alarm, because a backup that silently stops running is the failure that
actually happens.

None of this needs an open port, so it can be done at any point once [`03`](03-production-deploy.md)
is complete.

### restic, into two repositories

[restic](https://restic.net/) writes encrypted, deduplicated snapshots. One script,
[`files/mixtape-restic`](files/mixtape-restic), backs up into two repositories that share one password:

| Repository | Survives |
| --- | --- |
| `/mnt/usb/restic/mixtape`, on the backup drive | a dead system disk |
| `<bucket>/mixtape`, on Backblaze B2 | the house |

Three tags, each thinned on its own (7 daily, 5 weekly, 12 monthly snapshots):

- **`mixtape-media`**: `/var/media/music` and `/var/media/audiobooks`.
- **`mixtape-db`**: the dump directory. It keeps 30 days itself; the snapshots keep one dump a month for
  a year.
- **`mixtape-config`**: prod's `.env` — its `APP_KEY` is what decrypts the restored 2FA secrets — and
  the server configuration from this guide: nginx, the php-fpm pools, PostgreSQL's settings, the
  certificates, the units, sudoers, fail2ban, the firewall. It exists nowhere else, and rebuilding it
  from these documents takes days.

What the design decides:

- **Daily is cheap.** A run reads only files whose size or mtime changed, and stores only chunks the
  repository does not have yet, so a quiet night takes seconds. A re-tagged file is picked up through
  its mtime even when ID3 padding absorbed the edit and the size stayed the same.
- **The off-site key cannot delete.** It is limited to the `mixtape/` prefix, with `listBuckets`,
  `listFiles`, `readFiles` and `writeFiles` only. What restic removes is then merely *hidden*, and a
  lifecycle rule on the bucket deletes hidden versions after 90 days: a break-in on the server can hide
  the off-site copy, not destroy it, and you have 90 days to notice.
- **A missing drive does not cost the off-site copy.** The unit has no `RequiresMountsFor=`. The script
  checks the mountpoint itself, backs up media and config to B2 anyway, and then fails, so the alert
  fires.
- **Both repositories are encrypted**, which on an exFAT drive is the only protection there is: exFAT
  has no permissions.
- **On Sundays it reads a tenth of each repository back** (`check --read-data-subset=10%`), which
  catches a drive or a bucket that has started returning damaged data. B2 charges nothing for egress
  up to three times what you store.

### Set it up

**1. The bucket and the key**, on your workstation with the [b2 CLI](https://www.backblaze.com/docs/cloud-storage-command-line-tools)
(`brew install b2-tools`) and the account's **master key**, which is only needed for this:

```bash
b2 account authorize                         # the master key
b2 bucket create <bucket> allPrivate         # globally unique: add a random suffix
b2 key create --bucket <bucket> --name-prefix mixtape/ \
  mixtape-restic listBuckets,listFiles,readFiles,writeFiles
#   prints "<keyID> <applicationKey>": step 2's input
b2 account clear                             # no master key left on the workstation
```

Then, in the web UI, set the bucket's **Lifecycle Settings** to *Keep prior versions for this number
of days: 90*, and note its **endpoint** (`s3.<region>.backblazeb2.com`). restic reaches B2 through
that S3 endpoint, which is what Backblaze recommends for it. `b2 bucket get <bucket>` shows the rule as
`"daysFromHidingToDeleting": 90` with an empty `fileNamePrefix`, so it covers `mixtape/`.

Backblaze also caps each day's usage (**Caps & Alerts**). A full restore downloads the whole
collection, so set the download cap above that before you need it, or a restore stops at the cap for
the rest of the day.

**2. The secrets on the server.** Paste the key, then Enter and Ctrl-D:

```bash
sudo apt install -y restic
sudo install -d -m 700 /root/mixtape
sudo sh -c 'umask 077; cat > /root/mixtape/b2-key'      # "<keyID> <applicationKey>"
sudo sh -c 'umask 077; head -c 32 /dev/urandom | base64 > /root/mixtape/restic-password'
sudo cat /root/mixtape/restic-password
```

Put that password into a password manager **together with both repository URLs**. Without it, nothing
in either repository can be restored — and the copy on the server dies with the server.

**3. Install.** Fill in `<region>`, `<bucket>` and `<hostname>` at the top of the script first.
`CONFIG` names every file this guide installs, [`05`](05-hardening.md)'s included, and a path that
does not exist fails the run on purpose: that is how a renamed file is noticed instead of silently
dropping out of the backup. So leave out what your box does not have yet —
`/etc/logrotate.d/modsecurity` and the three `waf-report` entries until you have done `05`, the Samba
and ddclient files if you skipped those — and add them back when you do. The three `notify` entries are
the [alerting](#alerting) below: install that before the first run.

| File | Installs as | Role |
| --- | --- | --- |
| [`files/mixtape-restic`](files/mixtape-restic) | `/usr/local/sbin/…` (750 root) | the backup |
| [`files/mixtape-restic.service`](files/mixtape-restic.service) | `/etc/systemd/system/…` | the unit: `OnFailure=` and the success ping |
| [`files/mixtape-restic.timer`](files/mixtape-restic.timer) | `/etc/systemd/system/…` | nightly at 04:00, `Persistent=true` |

```bash
sudo install -m 750 -o root -g root mixtape-restic /usr/local/sbin/
sudo install -m 644 -o root -g root mixtape-restic.service mixtape-restic.timer /etc/systemd/system/
sudo systemctl daemon-reload
```

The alerting it reports through is installed [below](#alerting); the timer waits until both are in.

**4. Create both repositories:**

```bash
sudo bash -c '
  export RESTIC_PASSWORD_FILE=/root/mixtape/restic-password RESTIC_CACHE_DIR=/var/cache/restic
  read -r AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY < /root/mixtape/b2-key
  export AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY
  restic -r /mnt/usb/restic/mixtape init
  restic -r s3:https://s3.<region>.backblazeb2.com/<bucket>/mixtape init'
```

**5. The first run, in the evening.** It uploads the whole collection, so it runs as a **transient
unit**: a dropped SSH session does not stop it, and the service's 3-hour ceiling does not apply.
`B2_LIMIT_UPLOAD` (KiB/s) throttles the B2 half; about half your line's upload leaves the rest for
anyone streaming from the instance. The USB half goes first and runs at the drive's speed.

```bash
sudo systemd-run --unit=mixtape-restic-seed --setenv=B2_LIMIT_UPLOAD=<KiB/s> \
  -p Nice=10 -p IOSchedulingClass=idle /usr/local/sbin/mixtape-restic
journalctl -fu mixtape-restic-seed           # Ctrl-C leaves it running
```

It is done when the journal says `both repositories done`. Every run after it is incremental.

**6. The timer**, once the alerting below is in place:

```bash
sudo systemctl enable --now mixtape-restic.timer
```

### Alerting

The journal records a failed run, and nobody reads it. Worse, the failure that actually happens is the
*silent* one — the machine was off, the timer got disabled, the script hung — and a journal entry
cannot report an event that never occurred.

- **A dead-man's-switch** (healthchecks.io or similar) catches exactly that class. Give each backup a
  check of its own — period 1 day, grace 2 hours — and let its unit ping it after every successful
  run. One check for both would let a failed dump mark the restic run's period red and send you to
  the wrong job.
- **Push delivery** (ntfy or similar) gets it to your phone without needing a mail server. **Prove it
  notifies with the screen off**, not just that the message reaches the topic: on Android without
  Google Play Services, or with the app from F-Droid, a ntfy.sh subscription only fetches messages when
  the app is opened — the alert is there, and nobody is told. Turn on *Instant delivery* for the topic
  and set the app's battery use to *Unrestricted*.
- **Mail** through the app's own SMTP account, as a second channel that does not depend on the phone.
- A systemd `OnFailure=` hook reports runs that fail *or* die outright.

All of it goes through one script, [`files/notify`](files/notify), and one template unit: a unit opts
in with `OnFailure=notify-failure@%n.service` and pings its check with
`ExecStartPost=-/usr/local/sbin/notify --ok %n`. Both backups' units already carry those two lines.

| File | Installs as | Role |
| --- | --- | --- |
| [`files/notify`](files/notify) | `/usr/local/sbin/…` (750 root) | sends to each app's channels; pings and fails checks |
| [`files/notify-failure@.service`](files/notify-failure@.service) | `/etc/systemd/system/…` | what every `OnFailure=` starts |
| [`files/notify.apps.json`](files/notify.apps.json) | `/etc/notify/apps.json` (640 root) | each app's channels |
| [`files/notify.secrets.env`](files/notify.secrets.env) | `/etc/notify/secrets.env` (**0600 root**) | the ntfy topic and every check's ping URL |

```sh
sudo install -d -m 0750 /etc/notify
sudo install -m 0640 -o root -g root notify.apps.json /etc/notify/apps.json
sudo nano /etc/notify/apps.json                     # the mail recipient
sudo install -m 0600 -o root -g root notify.secrets.env /etc/notify/secrets.env
sudo nano /etc/notify/secrets.env                   # the topic + both ping URLs
sudo install -m 0750 -o root -g root notify /usr/local/sbin/
sudo install -m 0644 -o root -g root notify-failure@.service /etc/systemd/system/
sudo systemctl daemon-reload
echo "If you can read this, alerts work." | sudo notify --app mixtape --title "Test"
```

The last line prints one line per channel, `sent` or why not. A check's key in `secrets.env` is
`HC_` plus the unit name, upper-cased, `-` as `_` — `HC_MIXTAPE_RESTIC`, `HC_MIXTAPE_DB_BACKUP` — and
a key left out simply means that unit pings nothing. The mail channel reads the account from the
app's own `.env`, so its password exists once.

**A second app on the same box** gets an entry of its own in `apps.json`, named after the prefix of its
units (`<app>-backup.service` belongs to `<app>`), with whatever channels it reads: `discord` takes a
webhook from the app's `.env`, `mail` a `to_key` such as `APP_CONTACT`. A unit no app claims reports to
every app.

### Decisions worth understanding

**Failure reporting lives in the `OnFailure=` hook, not in the script.** A `trap` inside the script
cannot report a run that was OOM-killed, signalled, or that failed before the trap was installed.
systemd knows the unit failed even when the process never got to say so. The trade-off is that
running the script by hand does not alert — acceptable, because by hand you are watching the output.

**One template, one script, for every job.** Near-identical copies of an alarm are how one of them
quietly stops matching the others. The only thing that differs between jobs is the unit's name, so
that is the template's instance, and everything else — which channels, which check — follows from it.

**Do not put `ConditionPathIsMountPoint=` on a backup unit.** This one is a trap. A failed `Condition`
does not fail a unit — systemd *skips* it and records success. Guard the backup drive that way and
the most likely real failure, the drive being unplugged, silently skips the run, `OnFailure=` never
fires, and you hear nothing until the grace window lapses. Let the script test the mountpoint and exit
non-zero instead. Conditions express "not applicable", not "the thing I am protecting is broken".

**`OnFailure=` on a `Restart=` service fires on every crash.** With the default `RestartMode=`, each
automatic restart passes through the failed state, so a crash loop pushes once per `RestartSec=`. A
long-running service that should alert only when it cannot be kept up takes `RestartMode=direct` and a
start limit (`StartLimitIntervalSec=`, `StartLimitBurst=` in `[Unit]`): restarts stay quiet, and the
unit fails — once — when the limit is hit. The backups here are oneshots and need none of this.

**Alerting must never break the backup.** Missing config disables pinging rather than failing;
`notify --ok` always exits 0 and its line in the unit carries a `-` as well; the template's command is
`-` too, so an alert that could not be delivered is a journal line, not a second failed unit.
Otherwise a monitoring outage turns a good backup into a failed one, and you end up debugging the alarm
instead of the thing it watches.

### Verify it

Prove the success path *and* the failure path — a switch nobody has seen fire is a guess:

```sh
sudo systemctl start mixtape-restic.service                          # incremental; the check turns green
journalctl -u mixtape-restic -n 20 --no-pager
sudo systemctl start notify-failure@mixtape-restic.service.service   # a test alert: push + mail, check red
sudo systemctl start mixtape-restic.service                          # green again
```

Starting the reporter by hand proves the push, the mail and the `/fail` ping. The doubled
`.service.service` is right: the instance is the unit's full name. To prove the whole chain — unit
fails → `OnFailure=` → reporter — without touching the drive or the script, use a temporary override:

```sh
sudo systemctl edit mixtape-restic.service --drop-in=test-failure
```

```ini
[Service]
ExecStart=
ExecStart=/bin/false
```

The empty `ExecStart=` is required: it clears the inherited list, and without it systemd appends and
runs both commands.

```sh
sudo systemctl start mixtape-restic.service     # expect the job to fail
journalctl -u notify-failure@mixtape-restic.service.service -n 5 --no-pager
sudo systemctl revert mixtape-restic.service    # remove the drop-in
sudo systemctl daemon-reload
```

> **Unmounting the backup drive does not fail the database backup.** Its unit names the drive in
> `RequiresMountsFor=`, which pulls the mount in as a dependency, so starting the unit *remounts* it
> and the dump succeeds. That is desirable — a merely-unmounted drive self-heals — but it means only a
> physically absent drive fails that way. Check `systemctl show … -p Result` before concluding
> anything: `Result=success` with no push means nothing failed, not that alerting is broken. The same
> drop-in works on `mixtape-db-backup.service`.

### Restore, and rehearse it

A backup you have never restored is a hypothesis. Before relying on this one — and before deleting
any older copy — restore one album, the dumps and `.env` **from B2**, the copy you would need after a
fire, and compare them with the originals:

```bash
sudo bash -c '
  A="/var/media/music/<Artist>/<Album>"
  export RESTIC_PASSWORD_FILE=/root/mixtape/restic-password RESTIC_CACHE_DIR=/var/cache/restic
  read -r AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY < /root/mixtape/b2-key
  export AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY
  R=s3:https://s3.<region>.backblazeb2.com/<bucket>/mixtape
  restic -r $R snapshots
  P=${A//\[/\\[}; P=${P//\]/\\]}             # --include is a pattern: escape [ and ]
  restic -r $R restore latest --tag mixtape-media --include "$P" --target /tmp/restore
  restic -r $R restore latest --tag mixtape-db --target /tmp/restore
  restic -r $R restore latest --tag mixtape-config --include /var/www/mixtape.prod/.env --target /tmp/restore
  diff -r "/tmp/restore$A" "$A" && echo "album identical"
  cmp /tmp/restore/var/www/mixtape.prod/.env /var/www/mixtape.prod/.env && echo ".env identical"
  pg_restore --list "$(ls /tmp/restore/mnt/usb/db-backups/*.dump | tail -1)" | head
  rm -rf /tmp/restore'
```

**`--include` is a pattern, not a path.** A folder named `[2006] Album` holds a character class, so an
unescaped include matches nothing — and restic reports that only by restoring nothing, which `diff`
then calls a missing directory. The line that sets `P` escapes the brackets.

Then read the whole USB repository back once. It takes about as long as reading the collection from
the drive, so run it as a transient unit and read the result from its journal:

```bash
sudo systemd-run --unit=mixtape-restic-readcheck -p Nice=10 -p IOSchedulingClass=idle \
  --setenv=RESTIC_PASSWORD_FILE=/root/mixtape/restic-password --setenv=RESTIC_CACHE_DIR=/var/cache/restic \
  /usr/bin/restic -r /mnt/usb/restic/mixtape check --read-data
sudo journalctl -u mixtape-restic-readcheck --no-pager | tail -4    # want: no errors were found
```

The journal shows no progress while it runs — restic draws its progress bar only on a terminal —
and the unit is unloaded once it exits, so the journal is where the answer stays. Its "memory peak" is
the page cache of everything it read, charged to the unit, not memory restic held.

Two things that look wrong and are not:

- **`restic snapshots` lists two snapshots from one day.** While a history is shorter than the
  policy, `forget` also keeps the oldest snapshot as the first one of its period. Both thin out once
  there are more days than `--keep-daily`.
- **A dump goes back with `php artisan app:db-restore`**
  ([`03`](03-production-deploy.md#restoring)), not with restic: restic restores the *file*, the
  command restores the database from it. The library's own rows come back from the restored media
  with `php artisan app:update`.

## Step 9 — Final verification

- [ ] From **off the LAN** (phone on mobile data): the site loads with a **valid** certificate,
      HTTP redirects to HTTPS, and login is required.
- [ ] Security headers present on a real page response:
      `curl -sI https://<your-domain> | grep -iE 'content-security|x-frame|x-content|referrer'`
- [ ] **Audio playback works under the full CSP** — the likeliest casualty, so exercise it explicitly.
      The policy itself needs no widening (a native `<audio src>` on the same origin satisfies
      `media-src 'self'`, and an end-to-end spec plays a track under the shipped policy), so what is
      left to check here is the **hand-off**: with `MIXTAPE_STREAM_INTERNAL_PREFIX` set, the bytes
      must come from nginx's `internal;` locations. A missing location shows up as a **500 whose only
      trace is nginx's `rewrite or internal redirection cycle` — Laravel's log stays empty**. Confirm
      nginx is the one serving by reading the `ETag`: nginx writes `"<hex-mtime>-<hex-size>"` beside
      its own `Last-Modified`, where the PHP path sends a content hash. `Content-Length` is a real
      byte count on both paths, so it proves nothing here.
- [ ] **SSH, Samba, and the database are unreachable from the WAN** — over IPv4 **and** IPv6.
- [ ] **The dev site is unreachable from the WAN** even when asked for by name, and a **bare-IP**
      request gets nothing. The firewall cannot check either one: both are nginx's job (the LAN-only
      snippet and the catch-all). [`05-hardening.md`](05-hardening.md#5-verify) shows how to test
      both from inside the house.
- [ ] The invite flow works end to end, and a share link plays without login, **refuses both download
      routes**, and stops working once revoked or expired.
- [ ] A password reset arrives, and SPF/DKIM/DMARC pass.
- [ ] **The bootstrap logging window is closed** — the one step in this guide that is silent when
      skipped. `MAIL_MAILER=log` needs `LOG_LEVEL=debug` to work at all, which means every rendered
      email is written to `storage/logs`, signed reset links included; both flip together at Step 6
      and nothing complains if only one of them does. A password reset that arrives (above) proves
      the mailer moved. This proves the log level followed it:

      ```bash
      grep -E '^(LOG_LEVEL|MAIL_MAILER|LOG_DAILY_DAYS)=' /var/www/mixtape.prod/.env
      # want: LOG_LEVEL=warning, MAIL_MAILER=smtp, LOG_DAILY_DAYS set
      sudo grep -rl 'Subject:' /var/www/mixtape.prod/storage/logs || echo 'no mail bodies on disk'
      ```

      If the second command finds anything, those files hold reset links: rotate them out
      (`sudo rm`) once the level is raised, rather than leaving them to age.
- [ ] `certbot renew --dry-run` is clean and the renewal timer is active.
- [ ] A deliberately failed backup produces an actual notification, with the phone's screen off.
- [ ] One album, the dumps and `.env` have come back from B2 identical to the originals.

## Kill switch

**Removing the router's 80/443 forward instantly takes the instance offline** without touching the
server at all. That is the fastest recovery from anything alarming. If the pause will be long,
re-tighten the host firewall to LAN-only as well.

## Next

[`05-hardening.md`](05-hardening.md) — a web application firewall, a sandbox for php-fpm, kernel
settings, and keeping security updates in effect.
